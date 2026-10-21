<?php
declare(strict_types=1);

// This harness can only connect to an explicitly opted-in, disposable loopback database.
if (getenv('XAR_TEST_MYSQL_ALLOW_LOCAL') !== '1'
    || preg_match('/^mysql:host=127\.0\.0\.1;port=([1-9][0-9]{3,4});charset=utf8mb4$/D', (string) getenv('XAR_TEST_MYSQL_DSN')) !== 1
    || preg_match('/^atelier_contract_[a-f0-9]{16}$/D', (string) getenv('XAR_ATELIER_TEST_SCHEMA')) !== 1) {
    throw new RuntimeException('Explicit disposable loopback SQL fixture required.');
}
$source = file_get_contents(__DIR__ . '/../api/v1/index.php');
$end = strpos($source, "require_once __DIR__ . '/diagnostics.php';");
if ($end === false) throw new RuntimeException('Index helper boundary not found.');
eval(substr($source, strlen('<?php'), $end - strlen('<?php')));
require_once __DIR__ . '/../api/v1/online.php';
require_once __DIR__ . '/../api/v1/image-studio.php';
require_once __DIR__ . '/../api/v1/atelier.php';
require_once __DIR__ . '/../api/v1/atelier-gpt.php';
$db = new PDO((string) getenv('XAR_TEST_MYSQL_DSN'), (string) getenv('XAR_TEST_MYSQL_USER'), (string) getenv('XAR_TEST_MYSQL_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$schema = (string) getenv('XAR_ATELIER_TEST_SCHEMA');
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'initialize') {
    $db->exec('CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$db->exec('USE `' . $schema . '`');
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'initialize') {
    $db->exec('CREATE TABLE accounts(id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,username VARCHAR(64),username_key VARCHAR(128),display_name VARCHAR(120),
        permanent_role VARCHAR(16),can_administrate TINYINT,password_verifier VARCHAR(255),auth_revision BIGINT DEFAULT 1,revoked_at DATETIME(3) NULL) ENGINE=InnoDB');
    $db->exec('CREATE TABLE schema_migrations(version INT PRIMARY KEY,name VARCHAR(120),checksum CHAR(64)) ENGINE=InnoDB');
    $db->exec("INSERT INTO schema_migrations VALUES(24,'fixture','fixture')");
    $db->exec('CREATE TABLE image_studio_sessions(token_hash BINARY(32) PRIMARY KEY,account_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin,
        auth_revision BIGINT,expires_at DATETIME(3),last_seen_at DATETIME(3)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE auth_rate_limits(bucket_hash BINARY(32) PRIMARY KEY,attempts INT,window_started_at DATETIME(3),locked_until DATETIME(3) NULL) ENGINE=InnoDB');
    $db->exec('CREATE TABLE auth_sessions(token_hash BINARY(32) PRIMARY KEY,account_id VARCHAR(128),fixture_marker VARCHAR(80)) ENGINE=InnoDB');
    $db->prepare('INSERT INTO auth_sessions VALUES(?,?,?)')->execute([hash('sha256', 'fake-regie-session', true), 'fixture-regie-owner', 'regie-session-must-survive']);
    $db->exec('CREATE TABLE media_objects(id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,stored_name VARCHAR(96) UNIQUE,
        original_name VARCHAR(180),content_type VARCHAR(96),byte_size BIGINT UNSIGNED,sha256 BINARY(32),uploaded_by_account_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin,
        public_slug VARCHAR(64) NULL,published_at DATETIME(3) NULL,pending_delete_at DATETIME(3) NULL,created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domains(payload JSON) ENGINE=InnoDB');
    $db->exec('CREATE TABLE image_studio_messages(media_id VARCHAR(64),references_json JSON) ENGINE=InnoDB');
    $db->exec('CREATE TABLE image_reference_catalog(media_id VARCHAR(64),active TINYINT) ENGINE=InnoDB');
    $verifier = hashPassword('Fixture-Only-Password-2026');
    $db->prepare("INSERT INTO accounts(id,username,display_name,permanent_role,can_administrate,password_verifier) VALUES('fixture-regie-owner','Innota','Propriétaire fictif','gm',1,?)")->execute([$verifier]);
    $db->prepare("INSERT INTO accounts(id,username,username_key,display_name,permanent_role,can_administrate,password_verifier) VALUES('fixture-regie-invalid','invalid','invalid','Compte canari fictif','gm',0,?)")
        ->execute([hashPassword('xxxxxxxxxx')]);
    $db->prepare("INSERT INTO image_studio_sessions VALUES(?,'fixture-regie-owner',1,DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR),UTC_TIMESTAMP(3))")
        ->execute([hash('sha256', str_repeat('S', 43), true)]);
    migrateAtelierSchema($db);
    migrateAtelierSchema($db);
    echo json_encode(['ok' => true, 'schema' => 25]);
    exit;
}
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'invariants') {
    $regie = $db->query('SELECT HEX(token_hash) AS token,account_id,fixture_marker FROM auth_sessions ORDER BY account_id')->fetchAll();
    $studio = $db->query('SELECT HEX(token_hash) AS token,account_id,auth_revision,expires_at,last_seen_at FROM image_studio_sessions ORDER BY account_id')->fetchAll();
    echo json_encode(['regie' => $regie, 'studio' => $studio]);
    exit;
}
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'inject-failure') {
    $db->exec("CREATE TRIGGER atelier_injected_failure BEFORE INSERT ON atelier_request_receipts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_rollback_after_file_write'");
    echo 'Injected synthetic receipt failure.';
    exit;
}
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'remove-failure') {
    $db->exec('DROP TRIGGER atelier_injected_failure');
    echo 'Removed synthetic receipt failure.';
    exit;
}
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'cleanup') {
    $db->exec('DROP DATABASE `' . $schema . '`');
    echo "Fixture schema removed.\n";
    exit;
}
