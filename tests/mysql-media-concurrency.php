<?php
declare(strict_types=1);

// Optional integration test. It only creates a randomly named disposable schema
// on an explicitly opted-in loopback server; no application config is loaded.
if (getenv('XAR_TEST_MYSQL_ALLOW_LOCAL') !== '1') {
    echo "MySQL concurrency test skipped: set XAR_TEST_MYSQL_ALLOW_LOCAL=1 and a loopback DSN.\n";
    exit(0);
}
$dsn = (string) getenv('XAR_TEST_MYSQL_DSN');
if (preg_match('/^mysql:host=127\.0\.0\.1;port=([1-9][0-9]{3,4});charset=utf8mb4$/D', $dsn, $portMatch) !== 1
    || (int) $portMatch[1] > 65535) throw new RuntimeException('Only an explicit 127.0.0.1 test DSN with port >= 1000 is allowed.');

require_once __DIR__ . '/../api/v1/domains.php';
require_once __DIR__ . '/../api/v1/online.php';
require_once __DIR__ . '/../api/v1/image-studio.php';

final class MysqlTestHttpError extends RuntimeException {
    public function __construct(public int $status, public string $errorCode) { parent::__construct($errorCode); }
}
function sendError(int $status, string $message, string $code = ''): never { throw new MysqlTestHttpError($status, $code); }
function privateConfigPath(): string { return (string) getenv('XAR_TEST_MYSQL_DIRECTORY') . '/config.php'; }
function utcAfter(int $seconds): string { return gmdate('Y-m-d H:i:s', 1800000000 + $seconds) . '.000'; }
function mysqlTestConnection(?string $schema = null): PDO {
    $db = new PDO((string) getenv('XAR_TEST_MYSQL_DSN'), (string) (getenv('XAR_TEST_MYSQL_USER') ?: 'root'),
        (string) getenv('XAR_TEST_MYSQL_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $db->exec("SET time_zone='+00:00', SESSION innodb_lock_wait_timeout=8");
    if ($schema !== null) {
        if (preg_match('/^regie_mysql_audit_[a-f0-9]{16}$/D', $schema) !== 1) throw new RuntimeException('Unexpected disposable schema name.');
        $db->exec('USE `' . $schema . '`');
    }
    return $db;
}
function mysqlTestCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo 'PASS ' . $message . "\n";
}

$workerMode = $argv[1] ?? '';
if ($workerMode !== '') {
    $schema = (string) getenv('XAR_TEST_MYSQL_SCHEMA');
    $db = mysqlTestConnection($schema);
    $id = (string) getenv('XAR_TEST_MYSQL_MEDIA_ID');
    if (preg_match('/^[A-Za-z0-9_-]{24}$/D', $id) !== 1) throw new RuntimeException('Invalid synthetic media id.');
    echo json_encode(['ready' => (int) $db->query('SELECT CONNECTION_ID()')->fetchColumn()]) . "\n"; flush();
    if (trim((string) fgets(STDIN)) !== 'go') throw new RuntimeException('Worker barrier was not released.');
    try {
        $result = match ($workerMode) {
            'retire' => scheduleUnusedOnlineMediaDeletion($db, $id),
            'purge' => (function () use ($db): string { cleanupExpiredMediaRetention($db); return 'purged'; })(),
            'attach' => (function () use ($db, $id): string {
                $db->beginTransaction(); domainClockRecord($db, true);
                upsertApplicationDomain($db, 'character:synthetic', ['portrait' => '/media/' . $id], 1, null);
                $db->commit(); return 'attached';
            })(),
            'studio-reference' => (function () use ($db, $id): string {
                $db->beginTransaction();
                assertImageStudioReferenceMediaAccess($db, ['id' => 'synthetic-gm', 'permanent_role' => 'gm', 'effective_mode' => 'gm'], $id, true);
                $insert = $db->prepare('INSERT INTO image_studio_messages (id, references_json) VALUES (:id, :refs)');
                $insert->execute([':id' => 'reference-worker', ':refs' => json_encode([['mediaId' => $id]])]);
                $db->commit(); return 'referenced';
            })(),
            default => throw new RuntimeException('Unknown worker action.'),
        };
        echo json_encode(['result' => $result]) . "\n";
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        echo json_encode(['error' => $error->getMessage(), 'status' => $error instanceof MysqlTestHttpError ? $error->status : 0]) . "\n";
    }
    exit(0);
}

function mysqlTestStartWorker(string $mode, string $id): array {
    $environment = getenv(); $environment['XAR_TEST_MYSQL_MEDIA_ID'] = $id;
    $process = proc_open([(string) (getenv('XAR_TEST_MYSQL_PHP') ?: PHP_BINARY), __FILE__, $mode],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__, $environment);
    if (!is_resource($process)) throw new RuntimeException('Could not start a test worker.');
    stream_set_timeout($pipes[1], 10);
    $ready = json_decode((string) fgets($pipes[1]), true);
    if (!is_int($ready['ready'] ?? null)) {
        $error = stream_get_contents($pipes[2]); proc_terminate($process); proc_close($process);
        throw new RuntimeException('Worker did not connect: ' . $error);
    }
    fwrite($pipes[0], "go\n"); fflush($pipes[0]);
    $worker = ['process' => $process, 'pipes' => $pipes, 'connectionId' => $ready['ready']];
    $GLOBALS['mysqlTestWorkers'][] = $worker;
    return $worker;
}
function mysqlTestWaitForLock(PDO $observer, array $worker): void {
    $statement = $observer->prepare("SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = :id AND trx_state = 'LOCK WAIT'");
    $process = $observer->prepare('SELECT COMMAND, STATE, INFO FROM information_schema.PROCESSLIST WHERE ID = :id');
    $started = microtime(true);
    $deadline = microtime(true) + 5;
    do {
        $statement->execute([':id' => $worker['connectionId']]);
        if ((int) $statement->fetchColumn() === 1) return;
        // MariaDB can perform a primary-key const-table locking read during
        // optimization (STATE Statistics), before registering INNODB_TRX.
        // Observe that exact FOR UPDATE still blocked on the parent's held
        // row for at least 200 ms; no result is accepted before the release.
        $process->execute([':id' => $worker['connectionId']]);
        $row = $process->fetch();
        if (microtime(true) - $started >= 0.2 && is_array($row)
            && ($row['STATE'] ?? '') === 'Statistics'
            && preg_match('/^SELECT .* (?:application_domain_clock|media_objects) .* FOR UPDATE$/sD', (string) ($row['INFO'] ?? '')) === 1) return;
        usleep(20000);
    } while (microtime(true) < $deadline);
    stream_set_blocking($worker['pipes'][1], false);
    stream_set_blocking($worker['pipes'][2], false);
    throw new RuntimeException('The competing SQL operation never reached an observable InnoDB lock wait: '
        . stream_get_contents($worker['pipes'][1]) . stream_get_contents($worker['pipes'][2])
        . json_encode($observer->query('SELECT ID, COMMAND, STATE, INFO FROM information_schema.PROCESSLIST')->fetchAll())
        . json_encode($observer->query('SELECT trx_mysql_thread_id, trx_state FROM information_schema.INNODB_TRX')->fetchAll()));
}
function mysqlTestFinishWorker(array $worker): array {
    $result = json_decode((string) fgets($worker['pipes'][1]), true);
    fclose($worker['pipes'][0]); fclose($worker['pipes'][1]);
    $stderr = stream_get_contents($worker['pipes'][2]); fclose($worker['pipes'][2]);
    $exit = proc_close($worker['process']);
    if ($exit !== 0 || !is_array($result)) throw new RuntimeException('Worker failed: ' . $stderr);
    return $result;
}
function mysqlTestReset(PDO $db, string $id, bool $expired = false): string {
    foreach (['image_studio_messages', 'image_reference_catalog', 'application_domains', 'application_domain_history', 'media_objects'] as $table) $db->exec('DELETE FROM ' . $table);
    $filename = onlineMediaStorageName($id, '.png', ['id' => 'synthetic-player', 'effective_mode' => 'player']);
    $insert = $db->prepare('INSERT INTO media_objects (id, stored_name, original_name, content_type, uploaded_by_account_id, pending_delete_at) VALUES (:id,:name,:original,:type,:owner,' . ($expired ? 'DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 35 DAY)' : 'NULL') . ')');
    $insert->execute([':id' => $id, ':name' => $filename, ':original' => 'synthetic.png', ':type' => 'image/png', ':owner' => 'synthetic-gm']);
    $path = privateMediaDirectory() . '/' . $filename; file_put_contents($path, 'synthetic-file-for-concurrency'); return $path;
}

$schema = 'regie_mysql_audit_' . bin2hex(random_bytes(8));
putenv('XAR_TEST_MYSQL_SCHEMA=' . $schema);
$testRoot = (string) (getenv('XAR_TEST_MYSQL_ROOT') ?: sys_get_temp_dir());
if (!is_dir($testRoot)) throw new RuntimeException('Missing scratch root for test media.');
$directory = rtrim($testRoot, '/') . '/' . $schema;
if (!mkdir($directory, 0700)) throw new RuntimeException('Could not create disposable test directory.');
putenv('XAR_TEST_MYSQL_DIRECTORY=' . $directory);
$admin = mysqlTestConnection(); $db = null;
try {
    $admin->exec('CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
    $db = mysqlTestConnection($schema); $observer = mysqlTestConnection($schema);
    echo 'Engine ' . $db->query('SELECT VERSION()')->fetchColumn() . "\n";
    foreach ([
        'CREATE TABLE application_domain_clock (singleton_id INT PRIMARY KEY, global_revision BIGINT, state_schema_version INT, domain_schema_version INT, legacy_revision BIGINT NULL, initialized_at DATETIME(3)) ENGINE=InnoDB',
        'CREATE TABLE media_objects (id VARCHAR(24) PRIMARY KEY, stored_name VARCHAR(200), original_name VARCHAR(200), content_type VARCHAR(80), byte_size BIGINT DEFAULT 1, public_slug VARCHAR(22) NULL, published_at DATETIME(3) NULL, pending_delete_at DATETIME(3) NULL, uploaded_by_account_id VARCHAR(80), created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3)) ENGINE=InnoDB',
        'CREATE TABLE application_domains (domain_key VARCHAR(200) PRIMARY KEY, schema_version INT, revision BIGINT, payload JSON, updated_by_account_id VARCHAR(80) NULL, updated_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3)) ENGINE=InnoDB',
        'CREATE TABLE application_domain_history (id INT AUTO_INCREMENT PRIMARY KEY, payload JSON) ENGINE=InnoDB',
        'CREATE TABLE image_studio_messages (id VARCHAR(80) PRIMARY KEY, media_id VARCHAR(24) NULL, references_json JSON DEFAULT NULL, author_account_id VARCHAR(80), owner_hidden_at DATETIME(3) NULL, created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3), FOREIGN KEY (media_id) REFERENCES media_objects(id) ON DELETE SET NULL) ENGINE=InnoDB',
        'CREATE TABLE image_reference_catalog (id VARCHAR(80) PRIMARY KEY, media_id VARCHAR(24), active TINYINT DEFAULT 1, FOREIGN KEY (media_id) REFERENCES media_objects(id) ON DELETE RESTRICT) ENGINE=InnoDB',
        'CREATE TABLE live_connections (connection_id BINARY(16) PRIMARY KEY, session_token_hash BINARY(32), last_seen_at DATETIME(3), expires_at DATETIME(3)) ENGINE=InnoDB',
    ] as $sql) $db->exec($sql);
    $db->exec('INSERT INTO application_domain_clock VALUES (1,1,' . XAR_SESSION_SCHEMA_VERSION . ',' . XAR_DOMAIN_SCHEMA_VERSION . ',NULL,UTC_TIMESTAMP(3))');
    $db->query('ANALYZE TABLE application_domain_clock, media_objects, application_domains, application_domain_history, image_studio_messages, image_reference_catalog, live_connections')->fetchAll();
    $id = str_repeat('m', 24);

    mysqlTestReset($db, $id);
    $db->beginTransaction(); domainClockRecord($db, true);
    $worker = mysqlTestStartWorker('retire', $id); mysqlTestWaitForLock($observer, $worker);
    upsertApplicationDomain($db, 'character:synthetic', ['portrait' => '/media/' . $id], 1, null); $db->commit();
    mysqlTestCheck((mysqlTestFinishWorker($worker)['result'] ?? '') === 'referenced' && mediaRecord($db, $id)['pending_delete_at'] === null,
        'A domain attachment committed while retirement waits preserves the live media.');

    foreach (['studio-message', 'studio-reference', 'catalog'] as $kind) {
        mysqlTestReset($db, $id); $db->beginTransaction(); mediaRecord($db, $id, true);
        $worker = mysqlTestStartWorker('retire', $id); mysqlTestWaitForLock($observer, $worker);
        if ($kind === 'studio-message') $db->prepare('INSERT INTO image_studio_messages (id, media_id) VALUES (?, ?)')->execute([$kind, $id]);
        elseif ($kind === 'studio-reference') $db->prepare('INSERT INTO image_studio_messages (id, references_json) VALUES (?, ?)')->execute([$kind, json_encode([['mediaId' => $id]])]);
        else $db->prepare('INSERT INTO image_reference_catalog (id, media_id) VALUES (?, ?)')->execute([$kind, $id]);
        $db->commit();
        mysqlTestCheck((mysqlTestFinishWorker($worker)['result'] ?? '') === 'referenced', 'Committed ' . $kind . ' is seen after the media-row lock wait.');
    }

    mysqlTestReset($db, $id); $db->beginTransaction(); domainClockRecord($db, true); mediaRecord($db, $id, true);
    $worker = mysqlTestStartWorker('studio-reference', $id); mysqlTestWaitForLock($observer, $worker);
    $db->prepare('UPDATE media_objects SET pending_delete_at=UTC_TIMESTAMP(3) WHERE id=?')->execute([$id]); $db->commit();
    $result = mysqlTestFinishWorker($worker);
    mysqlTestCheck(($result['status'] ?? 0) === 404 && ($result['error'] ?? '') === 'media_missing', 'A Studio reference waiting behind retirement refuses the retired image.');

    mysqlTestReset($db, $id);
    mysqlTestCheck(scheduleUnusedOnlineMediaDeletion($db, $id) === 'scheduled', 'The production retirement helper commits an unused media.');
    $db->beginTransaction(); domainClockRecord($db, true); upsertApplicationDomain($db, 'character:synthetic', ['portrait' => '/media/' . $id], 1, null); $db->commit();
    mysqlTestCheck(mediaRecord($db, $id)['pending_delete_at'] === null, 'A later legitimate domain attachment reactivates a retained media.');

    $path = mysqlTestReset($db, $id, true); $db->beginTransaction(); domainClockRecord($db, true);
    $worker = mysqlTestStartWorker('purge', $id); mysqlTestWaitForLock($observer, $worker);
    upsertApplicationDomain($db, 'character:synthetic', ['portrait' => '/media/' . $id], 1, null); $db->commit();
    mysqlTestCheck((mysqlTestFinishWorker($worker)['result'] ?? '') === 'purged' && is_array(mediaRecord($db, $id)) && is_file($path),
        'A purge candidate reattached before its clock lock keeps both row and file.');

    $path = mysqlTestReset($db, $id, true); $db->prepare('INSERT INTO application_domain_history (payload) VALUES (?)')->execute([json_encode(['portrait' => '/media/' . $id], JSON_UNESCAPED_SLASHES)]);
    cleanupExpiredMediaRetention($db);
    mysqlTestCheck(is_array(mediaRecord($db, $id)) && is_file($path), 'Retained history protects an expired media from physical deletion.');
    $path = mysqlTestReset($db, $id, true); cleanupExpiredMediaRetention($db);
    mysqlTestCheck(mediaRecord($db, $id) === null && !is_file($path), 'An unused expired Player-named media is physically purged.');

    $path = mysqlTestReset($db, $id, true); $db->beginTransaction(); domainClockRecord($db, true); mediaRecord($db, $id, true);
    $worker = mysqlTestStartWorker('attach', $id); mysqlTestWaitForLock($observer, $worker);
    $db->prepare('DELETE FROM media_objects WHERE id=?')->execute([$id]); $db->commit();
    $result = mysqlTestFinishWorker($worker);
    mysqlTestCheck(($result['status'] ?? 0) === 409 && ($result['error'] ?? '') === 'media_missing'
        && (int) $db->query('SELECT COUNT(*) FROM application_domains')->fetchColumn() === 0,
        'An attachment waiting behind permanent deletion must fail without a dangling domain reference.');

    $db->exec('SET timestamp=1800000000');
    $raw = str_repeat('c', 16); $hash = str_repeat('h', 32);
    $db->prepare('INSERT INTO live_connections VALUES (?, ?, UTC_TIMESTAMP(3), ?)')->execute([$raw, $hash, utcAfter(XAR_CONNECTION_SECONDS)]);
    $touch = $db->prepare('UPDATE live_connections SET last_seen_at=UTC_TIMESTAMP(3), expires_at=? WHERE connection_id=? AND session_token_hash=?');
    $touch->execute([utcAfter(XAR_CONNECTION_SECONDS), $raw, $hash]);
    mysqlTestCheck($touch->rowCount() === 0, 'Native MySQL reports zero affected rows for an identical millisecond heartbeat.');
    mysqlTestCheck(refreshOnlineConnectionRecord($db, $raw, $hash), 'The production heartbeat helper accepts the existing unchanged connection.');
    mysqlTestCheck(!refreshOnlineConnectionRecord($db, $raw, str_repeat('x', 32)), 'The production heartbeat helper rejects a different session owner.');
    echo "Local MySQL concurrency integration passed.\n";
} finally {
    if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
    foreach ($GLOBALS['mysqlTestWorkers'] ?? [] as $worker) {
        if (is_resource($worker['process'])) { proc_terminate($worker['process']); proc_close($worker['process']); }
    }
    $admin->exec('DROP DATABASE IF EXISTS `' . $schema . '`');
    foreach (glob($directory . '/media/*') ?: [] as $path) if (is_file($path)) unlink($path);
    if (is_dir($directory . '/media')) rmdir($directory . '/media');
    rmdir($directory);
}
