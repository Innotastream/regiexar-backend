<?php
declare(strict_types=1);

// Disposable, explicitly opted-in loopback database. Never load production config.
if (getenv('XAR_TEST_MYSQL_ALLOW_LOCAL') !== '1') {
    echo "MySQL history retention test skipped: explicit loopback opt-in required.\n";
    exit(0);
}
$dsn = (string) getenv('XAR_TEST_MYSQL_DSN');
if (preg_match('/^mysql:host=127\\.0\\.0\\.1;port=([1-9][0-9]{3,4});charset=utf8mb4$/D', $dsn, $port) !== 1
    || (int) $port[1] > 65535) throw new RuntimeException('Only an explicit loopback test DSN is allowed.');

require_once __DIR__ . '/../api/v1/domains.php';
$index = (string) file_get_contents(__DIR__ . '/../api/v1/index.php');
$start = strpos($index, 'function acquireMaintenanceLock');
$end = strpos($index, 'function ensureCurrentSchema', $start === false ? 0 : $start);
if ($start === false || $end === false) throw new RuntimeException('Maintenance helpers not found.');
eval(substr($index, $start, $end - $start));
function cleanupExpiredMediaRetention(PDO $connection): void {}
function historyCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo 'PASS ' . $message . "\n";
}
function historyConnection(string $dsn, ?string $schema = null): PDO {
    $db = new PDO($dsn, (string) (getenv('XAR_TEST_MYSQL_USER') ?: 'root'),
        (string) getenv('XAR_TEST_MYSQL_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $db->exec("SET time_zone='+00:00', timestamp=1800000000, SESSION innodb_lock_wait_timeout=3");
    if ($schema !== null) {
        if (preg_match('/^regie_history_audit_[a-f0-9]{16}$/D', $schema) !== 1) throw new RuntimeException('Invalid test schema.');
        $db->exec('USE `' . $schema . '`');
    }
    return $db;
}

$schema = 'regie_history_audit_' . bin2hex(random_bytes(8));
$admin = historyConnection($dsn);
try {
    $admin->exec('CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4');
    $db = historyConnection($dsn, $schema);
    $observer = historyConnection($dsn, $schema);
    $db->exec('CREATE TABLE application_domain_clock (singleton_id INT PRIMARY KEY, global_revision BIGINT) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domain_changes (global_revision BIGINT, domain_key VARCHAR(100), domain_revision BIGINT, operation VARCHAR(10), PRIMARY KEY (global_revision, domain_key)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domain_history (history_id BIGINT AUTO_INCREMENT PRIMARY KEY, domain_key VARCHAR(100), payload JSON, created_at DATETIME(3), KEY idx_created (created_at)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domains (domain_key VARCHAR(100) PRIMARY KEY, revision BIGINT, payload JSON) ENGINE=InnoDB');
    $db->exec("INSERT INTO application_domain_clock VALUES (1,5000)");
    $db->exec("INSERT INTO application_domains VALUES ('character:synthetic',42,'{\"name\":\"Synthetic\",\"hp\":17}')");
    $current = $db->query('SELECT * FROM application_domains')->fetchAll();
    $insert = $db->prepare("INSERT INTO application_domain_history (domain_key,payload,created_at) VALUES ('character:synthetic',?,DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 31 DAY))");
    $change = $db->prepare("INSERT INTO application_domain_changes VALUES (?,'character:synthetic',1,'upsert')");
    for ($i = 0; $i < 502; $i++) {
        $insert->execute([json_encode(['old' => $i])]);
        $change->execute([1000 + $i]);
    }
    $db->exec("INSERT INTO application_domain_history (domain_key,payload,created_at) VALUES
        ('character:synthetic','{\"keep\":\"boundary\"}',DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 30 DAY)),
        ('character:synthetic','{\"keep\":\"recent\"}',DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 DAY)),
        ('character:synthetic','{\"keep\":\"future\"}',DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 DAY))");
    $db->exec("INSERT INTO application_domain_changes VALUES (3000,'character:synthetic',41,'upsert'),(5000,'character:synthetic',42,'upsert')");
    $protected = $db->query("SELECT history_id,payload,created_at FROM application_domain_history WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 30 DAY) ORDER BY history_id")->fetchAll();

    historyCheck(acquireMaintenanceLock($observer, 'xar-regie-domain-cleanup'), 'A competing connection owns the cleanup lock.');
    cleanupApplicationDomainHistory($db);
    historyCheck((int) $db->query('SELECT COUNT(*) FROM application_domain_history')->fetchColumn() === 505,
        'A competing maintenance lock skips cleanup without waiting or deleting.');
    releaseMaintenanceLock($observer, 'xar-regie-domain-cleanup');

    $db->beginTransaction();
    cleanupApplicationDomainHistory($db);
    historyCheck((int) $db->query('SELECT COUNT(*) FROM application_domain_history')->fetchColumn() === 505,
        'An open business transaction never runs cleanup.');
    $db->rollBack();
    cleanupApplicationDomainHistory($db);
    historyCheck((int) $db->query('SELECT COUNT(*) FROM application_domain_history')->fetchColumn() === 5,
        'The first eligible invocation deterministically removes exactly one bounded batch of 500.');
    historyCheck((int) $db->query('SELECT COUNT(*) FROM application_domain_changes')->fetchColumn() === 4,
        'Delta cleanup is also bounded to 500 rows.');
    cleanupApplicationDomainHistory($db);
    cleanupApplicationDomainHistory($db);
    historyCheck($db->query('SELECT history_id,payload,created_at FROM application_domain_history ORDER BY history_id')->fetchAll() === $protected,
        'The exact 30-day boundary, recent revisions and future revisions remain byte-for-byte unchanged.');
    historyCheck($db->query('SELECT * FROM application_domains')->fetchAll() === $current
        && (int) $db->query('SELECT global_revision FROM application_domain_clock')->fetchColumn() === 5000,
        'Current documents and their authoritative clock remain unchanged.');
    historyCheck(array_map('intval', $db->query('SELECT global_revision FROM application_domain_changes ORDER BY global_revision')->fetchAll(PDO::FETCH_COLUMN)) === [3000,5000],
        'The exact delta cutoff and current revision remain available.');

    $db->exec('RENAME TABLE application_domain_history TO retained_history');
    cleanupApplicationDomainHistory($db);
    historyCheck(acquireMaintenanceLock($observer, 'xar-regie-domain-cleanup'), 'A SQL failure releases the maintenance lock and does not propagate into gameplay.');
    releaseMaintenanceLock($observer, 'xar-regie-domain-cleanup');
    echo "Local MySQL history retention integration passed.\n";
} finally {
    $db = null;
    $observer = null;
    $admin->exec('DROP DATABASE IF EXISTS `' . $schema . '`');
}
