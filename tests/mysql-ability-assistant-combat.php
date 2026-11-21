<?php

declare(strict_types=1);
if (getenv('XAR_TEST_MYSQL_ALLOW_LOCAL') !== '1') exit(0);
$dsn = (string) getenv('XAR_TEST_MYSQL_DSN');
if (preg_match('/^mysql:host=127\.0\.0\.1;port=([1-9][0-9]{3,4});charset=utf8mb4$/D', $dsn, $port) !== 1 || (int) $port[1] > 65535) {
    throw new RuntimeException('Explicit loopback DSN required.');
}
require_once __DIR__ . '/../api/v1/domains.php';
require_once __DIR__ . '/../api/v1/online.php';
require_once __DIR__ . '/../api/v1/ability-assistant.php';

final class AssistantCombatFixtureResponse extends RuntimeException
{
    public function __construct(int $status, public array $body) { parent::__construct('', $status); }
}
function sendJson(int $status, array $body): never { throw new AssistantCombatFixtureResponse($status, $body); }
function sendError(int $status, string $message, string $code = ''): never { sendJson($status, ['message'=>$message, 'code'=>$code]); }
function requestSessionToken(): string { return 'fixture-session'; }
function resolveSession(PDO $db, string $token): ?array { return $GLOBALS['fixtureActor']; }
function readJsonBody(int $limit): array { return $GLOBALS['fixtureBody']; }
function randomToken(int $bytes = 32): string { return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '='); }
function runCombatAssistantRequest(PDO $db, string $conversation, array $identity, string $prompt, string $request): array
{
    $GLOBALS['fixtureActor'] = $identity;
    $GLOBALS['fixtureBody'] = ['prompt'=>$prompt, 'clientRequestId'=>$request];
    try { createAbilityAssistantMessage($db, $conversation); }
    catch (AssistantCombatFixtureResponse $response) { return [$response->getCode(), $response->body]; }
}
function expectCombatAssistant(bool $value, string $label): void { if (!$value) throw new RuntimeException($label); }

$db = new PDO($dsn, (string) getenv('XAR_TEST_MYSQL_USER'), (string) getenv('XAR_TEST_MYSQL_PASSWORD'), [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);
$schema = 'regie_assistant_combat_' . bin2hex(random_bytes(8));
$db->exec("CREATE DATABASE `$schema` CHARACTER SET utf8mb4");
try {
    $db->exec("USE `$schema`");
    $db->exec('CREATE TABLE application_domain_clock (singleton_id INT PRIMARY KEY,global_revision BIGINT,state_schema_version INT,domain_schema_version INT,legacy_revision BIGINT NULL,initialized_at DATETIME(3)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domains (domain_key VARCHAR(200) PRIMARY KEY,schema_version INT,revision BIGINT,payload JSON,updated_by_account_id VARCHAR(128) NULL,updated_at DATETIME(3)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domain_history (history_id BIGINT AUTO_INCREMENT PRIMARY KEY,domain_key VARCHAR(200),domain_revision BIGINT,global_revision BIGINT,operation VARCHAR(10),payload JSON,changed_by_account_id VARCHAR(128) NULL,created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domain_changes (global_revision BIGINT,domain_key VARCHAR(200),domain_revision BIGINT,operation VARCHAR(10),PRIMARY KEY(global_revision,domain_key)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE accounts (id VARCHAR(128) PRIMARY KEY,username VARCHAR(128),display_name VARCHAR(128)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE ability_assistant_conversations (id VARCHAR(22) PRIMARY KEY,owner_account_id VARCHAR(128),character_id VARCHAR(120),context_json JSON,updated_at DATETIME(3)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE ability_assistant_messages (id VARCHAR(24) PRIMARY KEY,conversation_id VARCHAR(22),author_account_id VARCHAR(128),prompt TEXT,client_request_id VARCHAR(80),status VARCHAR(30),response_text TEXT,assistant_status VARCHAR(30),draft_json JSON,error_code VARCHAR(120),error_detail TEXT,created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),completed_at DATETIME(3), UNIQUE(author_account_id,client_request_id)) ENGINE=InnoDB');
    $db->exec("INSERT INTO application_domain_clock VALUES(1,1,21,1,NULL,UTC_TIMESTAMP(3)); INSERT INTO accounts VALUES('fixture-gm','fixture','MJ fictif')");
    $gm = ['id'=>'fixture-gm', 'permanent_role'=>'gm', 'effective_mode'=>'gm'];
    $conversation = randomToken(16);
    $db->prepare('INSERT INTO ability_assistant_conversations VALUES (?,?,?, ?,UTC_TIMESTAMP(3))')->execute([$conversation, $gm['id'], '', '{"mode":"help","role":"gm"}']);
    $missing = ['id'=>'attack-missing', 'sceneId'=>'demo', 'status'=>'pending', 'sourceTokenId'=>'hero', 'targetTokenId'=>'deleted'];
    $keep = [...$missing, 'id'=>'attack-live', 'targetTokenId'=>'hero'];
    $other = [...$missing, 'id'=>'attack-elsewhere', 'sceneId'=>'other'];
    $otherFinished = [...$other, 'id'=>'attack-elsewhere-finished', 'status'=>'applied'];
    $initial = [
        'pendingAttacks'=>[$missing, $keep, $other, $otherFinished],
        'attackReceipts'=>[['requestId'=>'test-request', 'attack'=>$missing, 'expiresAt'=>PHP_INT_MAX]],
        'rolls'=>[['id'=>'jet-existant', 'total'=>42]], 'actionTimers'=>[], 'actionTimerTombstones'=>[], 'mapPings'=>[], 'shortcuts'=>[],
    ];
    $initialToken = ['id'=>'hero', 'hp'=>25, 'maxHp'=>30, 'mana'=>12, 'name'=>'Fictif', 'x'=>50, 'y'=>50, 'size'=>50];
    foreach (['table'=>['gmContexts'=>[$gm['id']=>['workingSceneId'=>'demo']]], 'scene:demo'=>['metadata'=>['id'=>'demo','name'=>'Démo technique']], 'token:demo:hero'=>$initialToken, 'activity'=>$initial] as $key=>$payload) {
        upsertApplicationDomain($db, $key, $payload, 1, null);
    }
    $diagnose = 'Diagnostique les validations d’attaque de cette scène';
    $repair = 'Débloque les validations d’attaque de la scène Démo technique';
    [$status, $response] = runCombatAssistantRequest($db, $conversation, $gm, $diagnose, randomToken(16));
    expectCombatAssistant($status === 202 && ($response['message']['status'] ?? null) === 'succeeded' && str_contains($response['message']['response'], '1 orpheline'), 'Diagnostic reports the fixture without a worker');
    expectCombatAssistant((int) $db->query('SELECT global_revision FROM application_domain_clock')->fetchColumn() === 1, 'Diagnostic causes no domain update');
    [$status] = runCombatAssistantRequest($db, $conversation, [...$gm, 'effective_mode'=>'player'], $repair, randomToken(16));
    expectCombatAssistant($status === 403, 'A player-mode session is refused by the routed API');

    $storedActivity = applicationDomainRecords($db, ['activity'])['activity']['payload'];
    $db->exec("CREATE TRIGGER fixture_assistant_failure BEFORE INSERT ON ability_assistant_messages FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture rollback'");
    try {
        $unexpected = runCombatAssistantRequest($db, $conversation, $gm, $repair, randomToken(16));
        throw new RuntimeException('Failure injection did not fire: ' . json_encode($unexpected, JSON_THROW_ON_ERROR));
    } catch (PDOException $expected) {
        expectCombatAssistant(str_contains($expected->getMessage(), 'fixture rollback'), 'Receipt failure injection fired');
    }
    expectCombatAssistant(applicationDomainRecords($db, ['activity'])['activity']['payload'] === $storedActivity, 'Receipt failure rolls back cleanup');
    $db->exec('DROP TRIGGER fixture_assistant_failure');
    $request = randomToken(16);
    [$status, $response] = runCombatAssistantRequest($db, $conversation, $gm, $repair, $request);
    expectCombatAssistant($status === 202 && str_contains($response['message']['response'], '1 fiche(s) retirée(s)'), 'Repair confirms one verified orphan');
    $records = applicationDomainRecords($db, ['activity', 'token:demo:hero']);
    $activity = $records['activity']['payload'];
    expectCombatAssistant($activity['pendingAttacks'] == [$keep, $other, $otherFinished] && ($activity['attackReceipts'][0]['attack']['status'] ?? '') === 'cancelled', 'A live decision and another scene are left alone; orphan replay is cancelled');
    expectCombatAssistant(applicationDomainPayload($records, 'token:demo:hero') == $initialToken && $activity['rolls'] == $initial['rolls'], 'Pions, PV, mana and rolls remain unchanged');
    [$status, $replay] = runCombatAssistantRequest($db, $conversation, $gm, $repair, $request);
    expectCombatAssistant($status === 200 && $replay['deduplicated'] && $replay['message']['id'] === $response['message']['id'] && (int) $db->query('SELECT global_revision FROM application_domain_clock')->fetchColumn() === 2, 'Replaying the request returns the same answer and does not mutate again');
} finally {
    $db->exec("DROP DATABASE `$schema`");
}
echo "Local MySQL assistant combat integration passed.\n";
