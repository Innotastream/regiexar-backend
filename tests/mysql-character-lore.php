<?php
declare(strict_types=1);
// Disposable loopback schema only. Never import production configuration.
if (getenv('XAR_TEST_MYSQL_ALLOW_LOCAL') !== '1') { echo "MySQL lore test skipped: explicit local opt-in required.\n"; exit(0); }
$dsn = (string) getenv('XAR_TEST_MYSQL_DSN');
if (preg_match('/^mysql:host=127\.0\.0\.1;port=([1-9][0-9]{3,4});charset=utf8mb4$/D', $dsn, $match) !== 1 || (int) $match[1] > 65535) throw new RuntimeException('Local DSN required.');
require_once __DIR__ . '/../api/v1/online.php';
require_once __DIR__ . '/../api/v1/domains.php';
function sendError(int $status, string $message, string $code = ''): never { throw new RuntimeException($code . ': ' . $message); }
function loreSqlCheck(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); echo "PASS $message\n"; }
function loreSqlConnection(string $dsn, ?string $schema = null): PDO {
    $db = new PDO($dsn, (string) (getenv('XAR_TEST_MYSQL_USER') ?: 'root'), (string) getenv('XAR_TEST_MYSQL_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $db->exec("SET time_zone='+00:00', SESSION innodb_lock_wait_timeout=8");
    if ($schema !== null) {
        if (preg_match('/^regie_lore_audit_[a-f0-9]{16}$/D', $schema) !== 1) throw new RuntimeException('Invalid disposable schema.');
        $db->exec('USE `' . $schema . '`');
    }
    return $db;
}
if (($argv[1] ?? '') === 'worker') {
    $db = loreSqlConnection($dsn, (string) getenv('XAR_LORE_TEST_SCHEMA'));
    echo "READY\n"; flush(); fgets(STDIN);
    try { echo json_encode(importAdaOriginLoreOnRead($db)) . "\n"; } catch (Throwable $error) { echo json_encode(['error' => $error->getMessage()]) . "\n"; }
    exit(0);
}
$schema = 'regie_lore_audit_' . bin2hex(random_bytes(8)); $admin = loreSqlConnection($dsn); $workers = []; $db = null;
try {
    $admin->exec('CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4'); $db = loreSqlConnection($dsn, $schema);
    $db->exec('CREATE TABLE accounts (id VARCHAR(128) PRIMARY KEY, username VARCHAR(100), display_name VARCHAR(100), revoked_at DATETIME NULL) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domain_clock (singleton_id INT PRIMARY KEY, global_revision BIGINT, state_schema_version INT, domain_schema_version INT, legacy_revision BIGINT NULL, initialized_at DATETIME(3)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domains (domain_key VARCHAR(200) PRIMARY KEY, schema_version INT, revision BIGINT, payload JSON, updated_by_account_id VARCHAR(128) NULL, updated_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domain_history (history_id BIGINT AUTO_INCREMENT PRIMARY KEY, domain_key VARCHAR(200), domain_revision BIGINT, global_revision BIGINT, operation VARCHAR(10), payload JSON, changed_by_account_id VARCHAR(128) NULL) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domain_changes (global_revision BIGINT, domain_key VARCHAR(200), domain_revision BIGINT, operation VARCHAR(10), PRIMARY KEY(global_revision,domain_key)) ENGINE=InnoDB');
    ensureCharacterLoreImportTable($db); ensureCharacterLoreImportTable($db);
    $db->exec("INSERT INTO accounts VALUES ('fixture-owner','ada','Ada',NULL)");
    $db->exec("INSERT INTO application_domain_clock VALUES (1,100,19,1,NULL,UTC_TIMESTAMP(3))");
    $before = ['id' => 'fixture-ada', 'name' => 'Ada', 'ownerPlayerId' => 'fixture-owner', 'visionDistance' => 8, 'darkVision' => 'none',
        'resources' => ['hp' => 13, 'maxHp' => 20, 'mana' => 7, 'maxMana' => 10], 'fatigue' => ['current' => 12, 'max' => 150],
        'stats' => ['force' => 20], 'secret' => ['notes' => 'synthetic secret'], 'lore' => 'Ancien récit', '_updatedAt' => 42];
    $insert = $db->prepare('INSERT INTO application_domains VALUES (\'character:fixture-ada\',1,17,:payload,NULL,UTC_TIMESTAMP(3))');
    $insert->execute([':payload' => json_encode($before)]);
    $db->exec("CREATE TRIGGER lore_failure BEFORE INSERT ON character_lore_imports FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic backup failure'");
    $failed = false; try { importAdaOriginLoreOnRead($db); } catch (Throwable) { $failed = true; }
    $record = applicationDomainRecords($db)['character:fixture-ada'];
    loreSqlCheck($failed && $record['payload'] === $before && $record['revision'] === 17 && domainClockRecord($db)['globalRevision'] === 100,
        'Backup failure rolls back lore, domain revision and global revision together.');
    $db->exec('DROP TRIGGER lore_failure');
    $db->beginTransaction(); domainClockRecord($db, true);
    $before['resources']['hp'] = 5;
    $update = $db->prepare("UPDATE application_domains SET payload=:payload, revision=18 WHERE domain_key='character:fixture-ada'");
    $update->execute([':payload' => json_encode($before)]);
    $db->exec('UPDATE application_domain_clock SET global_revision=101 WHERE singleton_id=1');
    for ($i = 0; $i < 2; $i++) {
        $env = getenv(); $env['XAR_LORE_TEST_SCHEMA'] = $schema;
        $process = proc_open([PHP_BINARY, __FILE__, 'worker'], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, __DIR__, $env);
        if (!is_resource($process)) throw new RuntimeException('Worker unavailable.');
        stream_set_timeout($pipes[1], 12);
        if (trim((string) fgets($pipes[1])) !== 'READY') throw new RuntimeException('Worker not ready.');
        $workers[] = [$process, $pipes]; fwrite($pipes[0], "GO\n"); fflush($pipes[0]);
    }
    $observer = loreSqlConnection($dsn, $schema); $deadline = microtime(true) + 5; $waiting = false;
    do {
        $waiting = (int) $observer->query("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE INFO LIKE '%application_domain_clock%FOR UPDATE%' AND STATE IN ('Statistics','Updating')")->fetchColumn() > 0
            || (int) $observer->query("SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_state='LOCK WAIT'")->fetchColumn() > 0;
        if (!$waiting) usleep(20000);
    } while (!$waiting && microtime(true) < $deadline);
    loreSqlCheck($waiting, 'Competing imports wait behind the actual domain clock lock.');
    $db->commit(); $statuses = [];
    foreach ($workers as [$process,$pipes]) {
        $result = json_decode((string) fgets($pipes[1]), true); $statuses[] = $result['status'] ?? 'error';
        foreach ($pipes as $pipe) fclose($pipe); proc_close($process);
    }
    $workers = []; sort($statuses);
    loreSqlCheck($statuses === ['already_applied','applied'], 'Two concurrent imports commit exactly one import and one backup.');
    $saved = applicationDomainRecords($db)['character:fixture-ada'];
    $backup = $db->query('SELECT * FROM character_lore_imports')->fetch();
    loreSqlCheck($saved['payload']['resources']['hp'] === 5 && json_decode($backup['before_payload'], true) === $before
        && (int) $backup['source_domain_revision'] === 18 && (int) $backup['committed_global_revision'] === 102,
        'The import preserves concurrent resources and backs up the latest real revision.');
    $story = require __DIR__ . '/../api/v1/data/ada-origin.php';
    loreSqlCheck($saved['payload']['lore'] === $story && $saved['revision'] === 19 && domainClockRecord($db)['globalRevision'] === 102,
        'The complete narrative is persisted through the real domain writer.');
    $modified = $saved['payload']; $modified['lore'] = 'Modification ultérieure autorisée';
    $db->prepare("UPDATE application_domains SET payload=:payload, revision=20 WHERE domain_key='character:fixture-ada'")->execute([':payload' => json_encode($modified)]);
    loreSqlCheck(importAdaOriginLoreOnRead($db)['status'] === 'already_applied'
        && applicationDomainRecords($db)['character:fixture-ada']['payload']['lore'] === $modified['lore']
        && (int) $db->query('SELECT COUNT(*) FROM character_lore_imports')->fetchColumn() === 1,
        'Later user edits survive every repeated import request.');
    echo "Local MySQL character lore integration passed.\n";
} finally {
    if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
    foreach ($workers as [$process,$pipes]) { proc_terminate($process); foreach ($pipes as $pipe) fclose($pipe); proc_close($process); }
    $admin->exec('DROP DATABASE IF EXISTS `' . $schema . '`');
}
