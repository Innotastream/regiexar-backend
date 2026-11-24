<?php
declare(strict_types=1);

if (getenv('XAR_TEST_MYSQL_ALLOW_LOCAL') !== '1') {
    echo "MySQL resource receipt test skipped: explicit loopback opt-in required.\n";
    exit(0);
}
$dsn = (string) getenv('XAR_TEST_MYSQL_DSN');
if (preg_match('/^mysql:host=127\.0\.0\.1;port=([1-9][0-9]{3,4});charset=utf8mb4$/D', $dsn, $port) !== 1
    || (int) $port[1] > 65535) throw new RuntimeException('Explicit loopback DSN required.');

require_once __DIR__ . '/../api/v1/online.php';
require_once __DIR__ . '/../api/v1/domains.php';

function randomToken(int $bytes = 32): string { return bin2hex(random_bytes($bytes)); }
function sendError(int $status, string $message, string $code = ''): never { throw new RuntimeException($code ?: $message, $status); }
function receiptDb(?string $schema = null): PDO
{
    $db = new PDO((string) getenv('XAR_TEST_MYSQL_DSN'), (string) (getenv('XAR_TEST_MYSQL_USER') ?: 'root'),
        (string) getenv('XAR_TEST_MYSQL_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    if ($schema !== null) {
        if (preg_match('/^regie_receipts_[a-f0-9]{16}$/D', $schema) !== 1) throw new RuntimeException('Disposable schema only.');
        $db->exec('USE `' . $schema . '`');
    }
    return $db;
}
function receiptCheck(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
    echo "PASS $label\n";
}
function receiptTransaction(PDO $db, array $actor, callable $command, bool $commit = true): array
{
    $db->beginTransaction();
    try {
        $clock = domainClockRecord($db, true);
        $records = applicationDomainRecords($db);
        $pending = [];
        $result = $command($records, $pending);
        if ($pending !== []) persistDomainChangesInTransaction($db, $actor, $clock, array_values($pending));
        if ($commit) $db->commit();
        else $db->rollBack();
        return $result;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}
function receiptRoll(PDO $db, array $actor, array $args, bool $commit = true): array
{
    return receiptTransaction($db, $actor, static function (array $records, array &$pending) use ($db, $actor, $args) {
        return onlineGmTacticalRoll($db, $records, $pending, applicationDomainPayload($records, 'table'), $actor, $args);
    }, $commit);
}

$admin = receiptDb();
$schema = 'regie_receipts_' . bin2hex(random_bytes(8));
$db = null;
try {
    $admin->exec('CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4');
    $db = receiptDb($schema);
    $db->exec('CREATE TABLE schema_migrations (version INT PRIMARY KEY,name VARCHAR(200),checksum CHAR(64)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domain_clock (singleton_id INT PRIMARY KEY,global_revision BIGINT,state_schema_version INT,domain_schema_version INT,legacy_revision BIGINT NULL,initialized_at DATETIME(3)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domains (domain_key VARCHAR(200) PRIMARY KEY,schema_version INT,revision BIGINT,payload JSON,updated_by_account_id VARCHAR(128) NULL,updated_at DATETIME(3)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domain_history (history_id BIGINT AUTO_INCREMENT PRIMARY KEY,domain_key VARCHAR(200),domain_revision BIGINT,global_revision BIGINT,operation VARCHAR(10),payload JSON,changed_by_account_id VARCHAR(128) NULL,created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domain_changes (global_revision BIGINT,domain_key VARCHAR(200),domain_revision BIGINT,operation VARCHAR(10),PRIMARY KEY(global_revision,domain_key)) ENGINE=InnoDB');
    $db->exec('INSERT INTO application_domain_clock VALUES(1,1,' . XAR_SESSION_SCHEMA_VERSION . ',1,NULL,UTC_TIMESTAMP(3))');

    $gm = ['id'=>'receipt-gm','display_name'=>'MJ de recette','permanent_role'=>'gm','effective_mode'=>'gm'];
    $other = [...$gm, 'id'=>'receipt-other'];
    $args = ['characterId'=>'receipt-hero','kind'=>'custom','label'=>'Test SQL','formula'=>'1d6+4','visibility'=>'gm','requestId'=>'receipt-roll-1025'];
    $firstArgs = [...$args, 'requestId'=>'receipt-legacy-0000'];
    $signature = applicationTacticalRollSignature('', $firstArgs);
    $now = applicationResourceReceiptClock();
    $legacy = [];
    for ($index = 0; $index < 1024; $index++) {
        $legacy[] = [
            'requestId' => sprintf('receipt-legacy-%04d', $index), 'kind' => 'token-roll',
            'accountId'=>$gm['id'], 'actionId'=>'action-legacy-' . $index, 'sourceKey'=>'receipt-hero',
            'requestSignature'=>$signature,'expiresAt'=>$now + 600000,
            'result'=>['roll'=>['id'=>'historic-roll-' . $index,'total'=>7],'initiativeUpdated'=>false],
        ];
    }
    $activity = ['actionTimers'=>[], 'actionTimerTombstones'=>[], 'mapPings'=>[], 'shortcuts'=>[],
        'rolls'=>[], 'playerActions'=>[], 'resourceReceipts'=>$legacy];
    $heroKey = 'character:receipt-hero';
    $hero = ['id'=>'receipt-hero','name'=>'Personnage fictif','stats'=>[], 'resources'=>['hp'=>90,'maxHp'=>100,'mana'=>20,'maxMana'=>50]];
    upsertApplicationDomain($db, 'table', ['tacticalSync'=>['paused'=>false]], 1, null);
    upsertApplicationDomain($db, $heroKey, $hero, 1, null);
    upsertApplicationDomain($db, 'activity', $activity, 1, null);
    $blocked = null;
    try { receiptRoll($db, $gm, $args); }
    catch (RuntimeException $error) { $blocked = $error; }
    receiptCheck($blocked?->getCode() === 429 && $blocked?->getMessage() === 'tactical_roll_receipt_capacity',
        'La 1 025e commande est refusée avant migration.');

    migrateApplicationResourceReceiptLedger($db);
    receiptCheck((int) $db->query('SELECT COUNT(*) FROM application_resource_receipts')->fetchColumn() === 1024
        && (int) $db->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn() === 26,
        'La migration atomique préserve les 1 024 reçus légitimes.');
    $response = receiptRoll($db, $gm, $args);
    receiptCheck(($response['deduplicated'] ?? null) === false && isset($response['roll']['id'])
        && (int) $db->query('SELECT COUNT(*) FROM application_resource_receipts')->fetchColumn() === 1025,
        'La même commande 1 025 aboutit et est archivée.');

    $observer = receiptDb($schema);
    applicationResourceReceiptLedgerEnabled($observer, true);
    $revision = domainClockRecord($observer)['globalRevision'];
    $again = receiptRoll($observer, $gm, $args);
    $first = receiptRoll($observer, $gm, $firstArgs);
    receiptCheck($again['deduplicated'] === true && $again['roll']['id'] === $response['roll']['id']
        && $first['deduplicated'] === true && $first['roll']['id'] === 'historic-roll-0'
        && domainClockRecord($observer)['globalRevision'] === $revision,
        'Une nouvelle connexion rejoue le premier ancien jet et le dernier sans mutation ni nouveau dé.');

    foreach ([[$other,$args,403],[$gm,[...$args,'formula'=>'2d6+4'],409]] as [$who,$replay,$expected]) {
        $status = 0;
        try { receiptRoll($observer, $who, $replay); }
        catch (RuntimeException $error) { $status = $error->getCode(); }
        receiptCheck($status === $expected, 'Le propriétaire et le contenu restent liés au reçu. HTTP ' . $expected);
    }
    $sync = applicationDomainReadResponse($observer, 0, '');
    $syncedActivity = array_column($sync['domains'], 'payload', 'key')['activity'];
    receiptCheck($syncedActivity['resourceReceipts'] === []
        && !str_contains(json_encode($sync, JSON_THROW_ON_ERROR), 'receipt-legacy-0000'),
        'Une lecture de synchronisation ne charge pas et ne renvoie pas les archives.');

    $rolledBack = [...$args, 'requestId'=>'receipt-rollback-0001'];
    receiptRoll($db, $gm, $rolledBack, false);
    receiptCheck(applicationReadResourceReceiptLedger($observer, $rolledBack['requestId'], $now) === null
        && domainClockRecord($observer)['globalRevision'] === $revision,
        'Le rollback retire ensemble le reçu, le journal et la révision.');

    $castRequest = 'receipt-cast-undo-01';
    $actionId = 'action-receipt-test-01';
    receiptTransaction($db, $gm, static function (array $records, array &$pending) use ($gm,$castRequest,$actionId,$now,$heroKey) {
        $before = [];
        foreach ($records as $key => $record) $before[$key] = $record['payload'];
        $after = $before;
        $after[$heroKey]['resources']['mana'] = 15;
        $after['activity']['playerActions'][] = ['id'=>$actionId,'kind'=>'ability','summary'=>'Soin SQL','sceneId'=>'scene'];
        $after['activity']['resourceReceipts'][] = ['requestId'=>$castRequest,'kind'=>'ability-cast','accountId'=>$gm['id'],
            'actionId'=>$actionId,'sourceKey'=>'receipt-hero','requestSignature'=>'test','expiresAt'=>$now+600000,
            'result'=>['castSucceeded'=>true,'effect'=>'healing']];
        combatUndoRecord($before, $after, 'ability.use', ['requestId'=>$castRequest,'sceneId'=>'scene'], ['effect'=>'healing']);
        queueOnlineDomainUpsert($pending, $records, 'activity', $after['activity']);
        queueOnlineDomainUpsert($pending, $records, $heroKey, $after[$heroKey]);
        return [];
    });
    receiptCheck(applicationReadResourceReceiptLedger($observer, $castRequest, $now)['result']['castSucceeded'] === true,
        'Un reçu de compétence terminé quitte le domaine et reste disponible.');
    receiptTransaction($db, $gm, static function (array $records, array &$pending) use ($db,$gm,$actionId) {
        return onlineUndoCombatAction($db, $records, $pending, $gm, ['actionId'=>$actionId,'scope'=>'action',
            'requestId'=>'receipt-undo-action-01']);
    });
    $updated = applicationReadResourceReceiptLedger($observer, $castRequest, $now);
    receiptCheck($updated['result']['actionUndone'] === true && $updated['result']['castSucceeded'] === false
        && applicationDomainRecords($observer, [$heroKey])[$heroKey]['payload']['resources']['mana'] === 20,
        'L’annulation rembourse le mana et met à jour le reçu archivé dans la même transaction.');

    $waitRequest = 'receipt-wait-0001';
    receiptTransaction($db, $gm, static function (array $records, array &$pending) use ($gm,$waitRequest,$now) {
        $state = $records['activity']['payload'];
        $state['resourceReceipts'][] = ['requestId'=>$waitRequest,'expiresAt'=>$now-5000,'accountId'=>$gm['id']];
        $state['pendingAttacks'][] = ['id'=>'attack-test','requestId'=>$waitRequest,'status'=>'awaiting-opposition'];
        queueOnlineDomainUpsert($pending, $records, 'activity', $state);
        return [];
    });
    $waitingActivity = applicationDomainRecords($observer, ['activity'])['activity']['payload'];
    receiptCheck($waitingActivity['resourceReceipts'][0]['requestId'] === $waitRequest
        && $waitingActivity['resourceReceipts'][0]['expiresAt'] > $now + 86400000
        && applicationReadResourceReceiptLedger($observer, $waitRequest, $now) === null,
        'Un reçu dépassant son délai reste vivant pendant une opposition réellement en attente.');
    receiptTransaction($db, $gm, static function (array $records, array &$pending) {
        $state = $records['activity']['payload'];
        $state['pendingAttacks'] = [];
        queueOnlineDomainUpsert($pending, $records, 'activity', $state);
        return [];
    });
    $finished = applicationReadResourceReceiptLedger($observer, $waitRequest, $now);
    receiptCheck($finished !== null && abs($finished['expiresAt'] - ($now + 86400000)) < 15000,
        'La résolution de l’opposition ouvre une conservation terminale de 24 heures.');

    $db->beginTransaction();
    applicationWriteResourceReceiptLedger($db, [
        ['kind'=>'combat-effect','requestId'=>'guard-removal-0001','actionId'=>'action-guard-0001',
            'expiresAt'=>$now+600000,'result'=>['kind'=>'guard','effectId'=>'guard-removed','decision'=>'remove']],
        ['requestId'=>'receipt-expired-001','expiresAt'=>$now-1],
    ]);
    $db->commit();
    $retained = applicationProtectPrivateResourceReceipts($observer, [
        'resourceReceipts'=>[['requestId'=>'client-invented-001']],
        'nextAttackGuards'=>[['id'=>'guard-removed'],['id'=>'guard-still-present']],
    ], []);
    receiptCheck($retained['resourceReceipts'] === [] && $retained['nextAttackGuards'] === [['id'=>'guard-still-present']],
        'Une sauvegarde ancienne ne réintroduit ni un reçu ni une protection retirée.');
    receiptCheck(applicationReadResourceReceiptLedger($observer, 'receipt-expired-001', $now) === null,
        'Un reçu expiré n’est déjà plus rejouable avant nettoyage.');
    applicationPruneResourceReceiptLedger($db, $now);
    receiptCheck((int) $observer->query("SELECT COUNT(*) FROM application_resource_receipts WHERE request_id='receipt-expired-001'")->fetchColumn() === 0
        && applicationReadResourceReceiptLedger($observer, $args['requestId'], $now) !== null,
        'Le nettoyage ne supprime que le reçu expiré et laisse les reçus valides disponibles.');
    echo "Local MySQL resource receipt integration passed.\n";
} finally {
    if ($db?->inTransaction()) $db->rollBack();
    if (preg_match('/^regie_receipts_[a-f0-9]{16}$/D', $schema) !== 1) throw new RuntimeException('Unsafe fixture cleanup.');
    $admin->exec('DROP DATABASE IF EXISTS `' . $schema . '`');
}
