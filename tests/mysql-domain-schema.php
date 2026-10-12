<?php
declare(strict_types=1);

// Only disposable schemas on the explicitly opted-in loopback SQL server.
if (getenv('XAR_TEST_MYSQL_ALLOW_LOCAL') !== '1') {
    echo "MySQL domain schema test skipped: explicit local opt-in required.\n";
    exit(0);
}
$dsn = (string) getenv('XAR_TEST_MYSQL_DSN');
if (preg_match('/^mysql:host=127\.0\.0\.1;port=([1-9][0-9]{3,4});charset=utf8mb4$/D', $dsn, $port) !== 1
    || (int) $port[1] > 65535) {
    throw new RuntimeException('Explicit loopback DSN required.');
}
require_once __DIR__ . '/../api/v1/domains.php';
require_once __DIR__ . '/../api/v1/online.php';

final class CapturedDomainSnapshot extends RuntimeException
{
    public function __construct(public array $payload) { parent::__construct('Captured test response'); }
}
function requireGmIdentity(PDO $connection): array
{
    return ['id' => 'fixture-gm', 'permanent_role' => 'gm', 'effective_mode' => 'gm'];
}
function sendJson(int $status, array $payload, bool $headOnly = false): never
{
    if ($status !== 200) throw new RuntimeException('Unexpected response status.');
    throw new CapturedDomainSnapshot($payload);
}
function sendError(int $status, string $message, string $code = ''): never
{
    throw new RuntimeException($code, $status);
}
function domainSchemaCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "PASS $message\n";
}
function capturedSnapshot(PDO $db, int $since = 0, string $prefix = ''): array
{
    $_GET = ['since' => (string) $since, 'prefix' => $prefix, 'presence' => '0'];
    try { readApplicationDomains($db); }
    catch (CapturedDomainSnapshot $response) { return $response->payload; }
}
function persistedDomainRows(PDO $db): array
{
    return [
        $db->query('SELECT * FROM application_domain_clock ORDER BY singleton_id')->fetchAll(),
        $db->query('SELECT * FROM application_domains ORDER BY domain_key')->fetchAll(),
        $db->query('SELECT * FROM application_domain_changes ORDER BY global_revision, domain_key')->fetchAll(),
    ];
}

$schema = 'regie_domain_schema_' . bin2hex(random_bytes(8));
$db = new PDO($dsn, (string) getenv('XAR_TEST_MYSQL_USER'), (string) getenv('XAR_TEST_MYSQL_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
try {
    $db->exec('CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4');
    $db->exec('USE `' . $schema . '`');
    $db->exec('CREATE TABLE application_domain_clock (singleton_id INT PRIMARY KEY, global_revision BIGINT,
        state_schema_version INT, domain_schema_version INT, legacy_revision BIGINT NULL, initialized_at DATETIME(3)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domains (domain_key VARCHAR(200) PRIMARY KEY, schema_version INT,
        revision BIGINT, payload JSON, updated_at DATETIME(3)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domain_changes (global_revision BIGINT, domain_key VARCHAR(200),
        domain_revision BIGINT, operation VARCHAR(10), PRIMARY KEY(global_revision, domain_key)) ENGINE=InnoDB');
    $db->exec("INSERT INTO application_domain_clock VALUES (1, 73, 19, 1, 8, '2026-10-06 12:00:00.000')");
    $character = ['id' => 'fixture-character', 'name' => 'Personnage fictif', 'ownerPlayerId' => 'fixture-player',
        'fatigue' => ['current' => 7, 'max' => 150], 'lore' => ['text' => 'Récit fictif à conserver'],
        'resources' => ['hp' => 19, 'mana' => 12.5], 'legacyOptionalField' => null,
        'abilities' => [['id' => 'fixture-ability', 'name' => 'Compétence conservée']]];
    $documents = ['character:fixture-character' => $character,
        'items' => ['templates' => [], 'instances' => []], 'table' => ['activeSceneId' => 'fixture-scene']];
    $insert = $db->prepare('INSERT INTO application_domains VALUES (?, 1, ?, ?, ?)');
    foreach ($documents as $key => $payload) {
        $insert->execute([$key, 11, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), '2026-10-06 12:00:00.000']);
    }
    foreach ([19, 20, 12, XAR_SESSION_SCHEMA_VERSION] as $storedVersion) {
        $db->exec('UPDATE application_domain_clock SET state_schema_version = ' . $storedVersion);
        $before = persistedDomainRows($db);
        $snapshot = capturedSnapshot($db);
        domainSchemaCheck($snapshot['stateSchemaVersion'] === XAR_SESSION_SCHEMA_VERSION
            && $snapshot['domainSchemaVersion'] === XAR_DOMAIN_SCHEMA_VERSION
            && is_array($snapshot['domains']) && is_array($snapshot['deleted']),
            "A session last written with schema $storedVersion satisfies the current client contract.");
        domainSchemaCheck($snapshot['revision'] === 73 && $snapshot['reset'] === true
            && count($snapshot['domains']) === 3, 'The complete read preserves the real SQL revision and document set.');
        $returnedCharacters = array_values(array_filter($snapshot['domains'], static fn (array $entry): bool => $entry['key'] === 'character:fixture-character'));
        domainSchemaCheck(json_encode($returnedCharacters[0]['payload'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            === json_encode($character, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) && $returnedCharacters[0]['revision'] === 11,
            'The character retains its lore, abilities, owner, optional null, fractional mana and revision.');
        $delta = capturedSnapshot($db, 73);
        $selected = capturedSnapshot($db, 0, 'character:');
        domainSchemaCheck($delta['stateSchemaVersion'] === XAR_SESSION_SCHEMA_VERSION && $delta['reset'] === false
            && $delta['domains'] === [] && $selected['stateSchemaVersion'] === XAR_SESSION_SCHEMA_VERSION
            && count($selected['domains']) === 1, 'Empty deltas and selected documents advertise the same supported contract.');
        domainSchemaCheck(persistedDomainRows($db) === $before, 'Reading writes no clock, payload, revision or change receipt.');
    }
    foreach (['state_schema_version' => XAR_SESSION_SCHEMA_VERSION + 1,
        'domain_schema_version' => XAR_DOMAIN_SCHEMA_VERSION + 1] as $field => $futureVersion) {
        $db->exec('UPDATE application_domain_clock SET state_schema_version = ' . XAR_SESSION_SCHEMA_VERSION
            . ', domain_schema_version = ' . XAR_DOMAIN_SCHEMA_VERSION . ', ' . $field . ' = ' . $futureVersion);
        $before = persistedDomainRows($db);
        $refused = false;
        try { capturedSnapshot($db); }
        catch (RuntimeException $error) { $refused = $error->getMessage() === 'future_domain_schema'; }
        domainSchemaCheck($refused && persistedDomainRows($db) === $before, "A future $field is refused before any write.");
    }
    echo "Local MySQL domain schema integration passed.\n";
} finally {
    if (preg_match('/^regie_domain_schema_[a-f0-9]{16}$/D', $schema) !== 1) throw new RuntimeException('Unsafe fixture cleanup target.');
    $db->exec('DROP DATABASE IF EXISTS `' . $schema . '`');
}
