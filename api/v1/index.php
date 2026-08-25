<?php

declare(strict_types=1);

const XAR_API_HOST = 'regie-xar-tsaroth.fr';
const XAR_BACKEND_VERSION = '0.11.0';
const XAR_SESSION_SECONDS = 43200;
const XAR_LOGIN_MAX_ATTEMPTS = 8;
const XAR_LOGIN_WINDOW_SECONDS = 900;
const XAR_LOGIN_LOCK_SECONDS = 60;
const XAR_LOGIN_TAKEOVER_WAIT_MICROSECONDS = 10000000;

date_default_timezone_set('UTC');

function sendJson(int $status, array $payload, bool $headOnly = false, array $extraHeaders = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Content-Security-Policy: default-src \'none\'; frame-ancestors \'none\'');
    foreach ($extraHeaders as $name => $value) {
        header((string) $name . ': ' . (string) $value, true);
    }
    if (!$headOnly) {
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    exit;
}

function sendError(int $status, string $message, string $code = ''): never
{
    sendJson($status, [
        'ok' => false,
        'error' => $message,
        ...($code !== '' ? ['code' => $code] : []),
    ]);
}

function requireMethod(string $actual, array $allowed): void
{
    if (in_array($actual, $allowed, true)) {
        return;
    }
    header('Allow: ' . implode(', ', $allowed));
    sendError(405, 'Méthode refusée.', 'method_not_allowed');
}

function requestIsSecure(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off') {
        return true;
    }
    $forwarded = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    return $forwarded === 'https';
}

function requestHost(): string
{
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    return preg_replace('/:\d+$/', '', $host) ?? '';
}

function requestRoute(): string
{
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    return rtrim($path, '/') ?: '/';
}

function privateConfigPath(): ?string
{
    $override = trim((string) getenv('XAR_REGIE_CONFIG'));
    if ($override !== '') {
        return $override;
    }

    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($documentRoot === false) {
        return null;
    }

    $candidate = dirname($documentRoot) . DIRECTORY_SEPARATOR . 'regie-private' . DIRECTORY_SEPARATOR . 'config.php';
    $documentPrefix = rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (str_starts_with($candidate, $documentPrefix)) {
        return null;
    }
    return $candidate;
}

function privateConfig(): ?array
{
    $path = privateConfigPath();
    if ($path !== null && is_file($path) && is_readable($path)) {
        $configuration = require $path;
        if (is_array($configuration)) {
            return $configuration;
        }
    }

    $dsn = trim((string) getenv('XAR_REGIE_DB_DSN'));
    $username = trim((string) getenv('XAR_REGIE_DB_USER'));
    $password = (string) getenv('XAR_REGIE_DB_PASSWORD');
    if ($dsn === '' && $username === '' && $password === '') {
        return null;
    }

    return [
        'database' => [
            'dsn' => $dsn,
            'username' => $username,
            'password' => $password,
        ],
    ];
}

function clientPolicy(array $configuration): array
{
    $configured = $configuration['client'] ?? [];
    $configured = is_array($configured) ? $configured : [];
    $minimumVersion = trim((string) ($configured['minimumVersion'] ?? ''));
    $latestVersion = trim((string) ($configured['latestVersion'] ?? $minimumVersion));
    $validVersion = static fn (string $value): bool => preg_match('/^\d+\.\d+\.\d+$/', $value) === 1;
    $enforce = ($configured['enforce'] ?? false) === true;
    if ($enforce && (!$validVersion($minimumVersion) || ($latestVersion !== '' && !$validVersion($latestVersion)))) {
        sendError(503, 'La politique de version cliente est invalide.', 'client_policy_invalid');
    }
    return [
        'enforce' => $enforce && $validVersion($minimumVersion),
        'minimumVersion' => $validVersion($minimumVersion) ? $minimumVersion : '',
        'latestVersion' => $validVersion($latestVersion) ? $latestVersion : $minimumVersion,
        'storeId' => '9N5N5M67N704',
    ];
}

function requireSupportedClient(array $configuration): void
{
    $policy = clientPolicy($configuration);
    if ($policy['enforce'] !== true) {
        return;
    }
    $provided = trim((string) ($_SERVER['HTTP_X_XAR_CLIENT_VERSION'] ?? ''));
    if (preg_match('/^\d+\.\d+\.\d+$/', $provided) === 1
        && version_compare($provided, (string) $policy['minimumVersion'], '>=')) {
        return;
    }
    sendJson(426, [
        'ok' => false,
        'error' => 'Cette version de Xar-Tsaroth Régie n’est plus compatible. Installez la mise à jour depuis le Microsoft Store.',
        'code' => 'client_update_required',
        'minimumVersion' => $policy['minimumVersion'],
        'latestVersion' => $policy['latestVersion'],
        'storeId' => $policy['storeId'],
    ]);
}

function databaseConnection(array $configuration): PDO
{
    $database = $configuration['database'] ?? null;
    if (!is_array($database)) {
        throw new RuntimeException('configuration_required');
    }

    $dsn = trim((string) ($database['dsn'] ?? ''));
    $username = trim((string) ($database['username'] ?? ''));
    $password = (string) ($database['password'] ?? '');
    if ($dsn === '' || $username === '' || $password === '' || $password === 'REMPLACER_LOCALEMENT') {
        throw new RuntimeException('configuration_required');
    }

    $connection = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $connection->exec("SET time_zone = '+00:00'");
    return $connection;
}

function schemaIndexExists(PDO $connection, string $table, string $index, bool $uniqueOnly = false): bool
{
    $statement = $connection->prepare(
        'SELECT COUNT(*) FROM information_schema.statistics '
        . 'WHERE table_schema = DATABASE() AND table_name = :table_name AND index_name = :index_name'
        . ($uniqueOnly ? ' AND non_unique = 0' : '')
    );
    $statement->execute([':table_name' => $table, ':index_name' => $index]);
    return (int) $statement->fetchColumn() > 0;
}

function schemaColumnExists(PDO $connection, string $table, string $column): bool
{
    $statement = $connection->prepare(
        'SELECT COUNT(*) FROM information_schema.columns '
        . 'WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name'
    );
    $statement->execute([':table_name' => $table, ':column_name' => $column]);
    return (int) $statement->fetchColumn() > 0;
}

function ensureCurrentSchema(PDO $connection): void
{
    $lock = $connection->prepare("SELECT GET_LOCK('xar-regie-schema-v9', 15)");
    $lock->execute();
    if ((int) $lock->fetchColumn() !== 1) {
        throw new RuntimeException('schema_lock_unavailable');
    }

    try {
        $version = (int) $connection->query('SELECT COALESCE(MAX(version), 0) FROM schema_migrations')->fetchColumn();
        if ($version < 3) {
            throw new RuntimeException('schema_initialization_required');
        }

        if ($version < 4) {
            if (!schemaColumnExists($connection, 'accounts', 'can_administrate')) {
                $connection->exec(
                    'ALTER TABLE accounts ADD COLUMN '
                    . 'can_administrate TINYINT(1) NOT NULL DEFAULT 0 AFTER permanent_role'
                );
            }
            $connection->exec(
                "UPDATE accounts SET can_administrate = 1 WHERE id = ("
                . "SELECT founder.id FROM (SELECT id FROM accounts WHERE permanent_role = 'gm' "
                . 'AND revoked_at IS NULL ORDER BY created_at, id LIMIT 1) AS founder) '
                . 'AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM accounts '
                . 'WHERE can_administrate = 1 AND revoked_at IS NULL LIMIT 1) AS existing_administrator)'
            );
            $connection->exec(
                'CREATE TABLE IF NOT EXISTS regie_settings ('
                . 'singleton_id TINYINT UNSIGNED NOT NULL DEFAULT 1, revision BIGINT UNSIGNED NOT NULL DEFAULT 0, '
                . 'public_payload JSON NOT NULL, encrypted_secrets MEDIUMBLOB NULL, secret_nonce VARBINARY(12) NULL, '
                . 'secret_tag VARBINARY(16) NULL, updated_by_account_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL, '
                . 'created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3), '
                . 'updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3), '
                . 'PRIMARY KEY (singleton_id), CONSTRAINT fk_regie_settings_account FOREIGN KEY (updated_by_account_id) '
                . 'REFERENCES accounts (id) ON DELETE SET NULL, CONSTRAINT chk_regie_settings_singleton CHECK (singleton_id = 1)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $connection->exec(
                "INSERT INTO regie_settings (singleton_id, revision, public_payload) "
                . "SELECT 1, 0, JSON_OBJECT('discord', JSON_OBJECT('images', JSON_OBJECT('enabled', FALSE), "
                . "'dice', JSON_OBJECT('enabled', FALSE), 'journal', JSON_OBJECT('enabled', FALSE))) "
                . 'WHERE NOT EXISTS (SELECT 1 FROM regie_settings WHERE singleton_id = 1)'
            );
            $connection->exec(
                'CREATE TABLE IF NOT EXISTS media_objects ('
                . 'id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'stored_name VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, original_name VARCHAR(180) NOT NULL, '
                . 'content_type VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, byte_size BIGINT UNSIGNED NOT NULL, '
                . 'sha256 BINARY(32) NOT NULL, uploaded_by_account_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL, '
                . 'public_slug VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, published_at DATETIME(3) NULL, '
                . 'created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3), PRIMARY KEY (id), '
                . 'UNIQUE KEY uq_media_objects_stored_name (stored_name), UNIQUE KEY uq_media_objects_public_slug (public_slug), '
                . 'KEY idx_media_objects_created (created_at), CONSTRAINT fk_media_objects_account '
                . 'FOREIGN KEY (uploaded_by_account_id) REFERENCES accounts (id) ON DELETE SET NULL'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $connection->exec(
                "INSERT IGNORE INTO schema_migrations (version, name, checksum) VALUES "
                . "(4, 'online_application_authority', 'ece289ba6248debb317c61093162961784f892886eb677b7353d136f9c3258f2')"
            );
            $version = 4;
        }

        if ($version < 5) {
            if (!schemaColumnExists($connection, 'accounts', 'takeover_requested_at')) {
                $connection->exec(
                    'ALTER TABLE accounts ADD COLUMN takeover_requested_at DATETIME(3) NULL AFTER revoked_at'
                );
            }
            if (!schemaColumnExists($connection, 'accounts', 'takeover_request_id')) {
                $connection->exec(
                    'ALTER TABLE accounts ADD COLUMN takeover_request_id BINARY(16) NULL AFTER takeover_requested_at'
                );
            }
            $connection->exec(
                'DELETE older FROM auth_sessions AS older JOIN auth_sessions AS newer '
                . 'ON newer.account_id = older.account_id AND (newer.created_at > older.created_at '
                . 'OR (newer.created_at = older.created_at AND HEX(newer.token_hash) > HEX(older.token_hash)))'
            );
            if (!schemaIndexExists($connection, 'auth_sessions', 'uq_auth_sessions_account', true)) {
                $connection->exec('ALTER TABLE auth_sessions ADD UNIQUE KEY uq_auth_sessions_account (account_id)');
            }
            if (schemaIndexExists($connection, 'auth_sessions', 'idx_auth_sessions_account')) {
                $connection->exec('ALTER TABLE auth_sessions DROP INDEX idx_auth_sessions_account');
            }
            $connection->exec(
                "INSERT IGNORE INTO schema_migrations (version, name, checksum) VALUES "
                . "(5, 'single_active_session_handoff', 'f591dc307331c40a92a4f1f678b700e95499670dd64df68cbd1ee5decd4791d8')"
            );
            $version = 5;
        }

        if ($version < 6) {
            $connection->exec(
                'CREATE TABLE IF NOT EXISTS account_recovery_tokens ('
                . 'token_hash BINARY(32) NOT NULL, '
                . 'account_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'expires_at DATETIME(3) NOT NULL, consumed_at DATETIME(3) NULL, '
                . 'created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3), '
                . 'PRIMARY KEY (token_hash), KEY idx_account_recovery_tokens_account (account_id), '
                . 'CONSTRAINT fk_account_recovery_tokens_account FOREIGN KEY (account_id) '
                . 'REFERENCES accounts (id) ON DELETE CASCADE'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $connection->exec(
                "INSERT IGNORE INTO schema_migrations (version, name, checksum) VALUES "
                . "(6, 'account_recovery_tokens', '7e0d690de56fc83527ec061fea9fc67f0e50cd66732dab10b2ab4c501c61bdba')"
            );
            $version = 6;
        }

        if ($version < 7) {
            if (!schemaColumnExists($connection, 'media_objects', 'pending_delete_at')) {
                $connection->exec(
                    'ALTER TABLE media_objects ADD COLUMN pending_delete_at DATETIME(3) NULL AFTER published_at'
                );
            }
            if (!schemaIndexExists($connection, 'media_objects', 'idx_media_objects_pending_delete')) {
                $connection->exec(
                    'ALTER TABLE media_objects ADD KEY idx_media_objects_pending_delete (pending_delete_at)'
                );
            }
            $connection->exec(
                'CREATE TABLE IF NOT EXISTS application_domain_clock ('
                . 'singleton_id TINYINT UNSIGNED NOT NULL DEFAULT 1, '
                . 'global_revision BIGINT UNSIGNED NOT NULL DEFAULT 0, '
                . 'state_schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 11, '
                . 'domain_schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1, '
                . 'legacy_revision BIGINT UNSIGNED NULL, initialized_at DATETIME(3) NULL, '
                . 'updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3), '
                . 'PRIMARY KEY (singleton_id), CONSTRAINT chk_application_domain_clock_singleton CHECK (singleton_id = 1)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $connection->exec(
                'CREATE TABLE IF NOT EXISTS application_domains ('
                . 'domain_key VARCHAR(192) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1, revision BIGINT UNSIGNED NOT NULL DEFAULT 1, '
                . 'payload JSON NOT NULL, updated_by_account_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL, '
                . 'created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3), '
                . 'updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3), '
                . 'PRIMARY KEY (domain_key), CONSTRAINT fk_application_domains_account '
                . 'FOREIGN KEY (updated_by_account_id) REFERENCES accounts (id) ON DELETE SET NULL'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $connection->exec(
                'CREATE TABLE IF NOT EXISTS application_domain_changes ('
                . 'global_revision BIGINT UNSIGNED NOT NULL, '
                . 'domain_key VARCHAR(192) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'domain_revision BIGINT UNSIGNED NOT NULL, operation ENUM(\'upsert\', \'delete\') NOT NULL, '
                . 'changed_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3), '
                . 'PRIMARY KEY (global_revision, domain_key), KEY idx_application_domain_changes_key (domain_key, global_revision)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $connection->exec(
                'CREATE TABLE IF NOT EXISTS application_domain_history ('
                . 'history_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
                . 'domain_key VARCHAR(192) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'domain_revision BIGINT UNSIGNED NOT NULL, global_revision BIGINT UNSIGNED NOT NULL, '
                . 'operation ENUM(\'upsert\', \'delete\') NOT NULL, payload JSON NULL, '
                . 'changed_by_account_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL, '
                . 'created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3), '
                . 'PRIMARY KEY (history_id), KEY idx_application_domain_history_key (domain_key, history_id), '
                . 'KEY idx_application_domain_history_created (created_at), '
                . 'CONSTRAINT fk_application_domain_history_account FOREIGN KEY (changed_by_account_id) '
                . 'REFERENCES accounts (id) ON DELETE SET NULL'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $connection->exec(
                'INSERT INTO application_domain_clock '
                . '(singleton_id, global_revision, state_schema_version, domain_schema_version) VALUES (1, 0, 11, 1) '
                . 'ON DUPLICATE KEY UPDATE singleton_id = VALUES(singleton_id)'
            );
            $connection->exec(
                "INSERT IGNORE INTO schema_migrations (version, name, checksum) VALUES "
                . "(7, 'revisioned_domains_and_media_retention', 'f67ea6b167c2313869a9c72def9c49d913fe06696791c43b9413eb16c6df498a')"
            );
            $version = 7;
        }

        if ($version < 8) {
            $connection->exec(
                'CREATE TABLE IF NOT EXISTS image_studio_sessions ('
                . 'token_hash BINARY(32) NOT NULL, '
                . 'account_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'auth_revision BIGINT UNSIGNED NOT NULL, expires_at DATETIME(3) NOT NULL, '
                . 'created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3), '
                . 'last_seen_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3), '
                . 'PRIMARY KEY (token_hash), UNIQUE KEY uq_image_studio_sessions_account (account_id), '
                . 'KEY idx_image_studio_sessions_expiry (expires_at), '
                . 'CONSTRAINT fk_image_studio_sessions_account FOREIGN KEY (account_id) '
                . 'REFERENCES accounts (id) ON DELETE CASCADE'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $connection->exec(
                'CREATE TABLE IF NOT EXISTS image_studio_conversations ('
                . 'id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'owner_account_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'title VARCHAR(180) NOT NULL, owner_archived_at DATETIME(3) NULL, '
                . 'created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3), '
                . 'updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3), '
                . 'PRIMARY KEY (id), KEY idx_image_studio_conversations_owner (owner_account_id, owner_archived_at, updated_at), '
                . 'CONSTRAINT fk_image_studio_conversations_owner FOREIGN KEY (owner_account_id) '
                . 'REFERENCES accounts (id) ON DELETE RESTRICT'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $connection->exec(
                'CREATE TABLE IF NOT EXISTS image_studio_messages ('
                . 'id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'conversation_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'author_account_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . "operation ENUM('generate', 'edit', 'regenerate') NOT NULL DEFAULT 'generate', "
                . 'prompt MEDIUMTEXT NOT NULL, revised_prompt MEDIUMTEXT NULL, '
                . "quality ENUM('high') NOT NULL DEFAULT 'high', "
                . "aspect ENUM('landscape', 'portrait', 'square') NOT NULL DEFAULT 'landscape', "
                . 'references_json JSON NOT NULL, '
                . "status ENUM('queued', 'generating', 'succeeded', 'failed', 'rejected') NOT NULL DEFAULT 'queued', "
                . 'parent_message_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, '
                . 'media_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, '
                . 'width SMALLINT UNSIGNED NULL, height SMALLINT UNSIGNED NULL, '
                . 'error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, error_detail VARCHAR(500) NULL, '
                . 'owner_hidden_at DATETIME(3) NULL, started_at DATETIME(3) NULL, completed_at DATETIME(3) NULL, '
                . 'created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3), '
                . 'updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3), '
                . 'PRIMARY KEY (id), KEY idx_image_studio_messages_conversation (conversation_id, created_at), '
                . 'KEY idx_image_studio_messages_author_status (author_account_id, status, created_at), '
                . 'KEY idx_image_studio_messages_media (media_id), '
                . 'CONSTRAINT fk_image_studio_messages_conversation FOREIGN KEY (conversation_id) '
                . 'REFERENCES image_studio_conversations (id) ON DELETE RESTRICT, '
                . 'CONSTRAINT fk_image_studio_messages_author FOREIGN KEY (author_account_id) '
                . 'REFERENCES accounts (id) ON DELETE RESTRICT, '
                . 'CONSTRAINT fk_image_studio_messages_parent FOREIGN KEY (parent_message_id) '
                . 'REFERENCES image_studio_messages (id) ON DELETE SET NULL, '
                . 'CONSTRAINT fk_image_studio_messages_media FOREIGN KEY (media_id) '
                . 'REFERENCES media_objects (id) ON DELETE SET NULL'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $connection->exec(
                'CREATE TABLE IF NOT EXISTS image_reference_catalog ('
                . 'id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, label VARCHAR(120) NOT NULL, '
                . 'aliases_json JSON NOT NULL, media_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'active TINYINT(1) NOT NULL DEFAULT 1, priority SMALLINT UNSIGNED NOT NULL DEFAULT 100, '
                . 'created_by_account_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3), '
                . 'updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3), '
                . 'PRIMARY KEY (id), KEY idx_image_reference_catalog_active (active, priority, label), '
                . 'KEY idx_image_reference_catalog_media (media_id), '
                . 'CONSTRAINT fk_image_reference_catalog_media FOREIGN KEY (media_id) '
                . 'REFERENCES media_objects (id) ON DELETE RESTRICT, '
                . 'CONSTRAINT fk_image_reference_catalog_account FOREIGN KEY (created_by_account_id) '
                . 'REFERENCES accounts (id) ON DELETE RESTRICT'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $connection->exec(
                "INSERT IGNORE INTO schema_migrations (version, name, checksum) VALUES "
                . "(8, 'private_codex_image_studio', '2756d095310647900133b669980030d27bed6480540d7007b81032c137e3bd58')"
            );
            $version = 8;
        }

        if ($version < 9) {
            if (!schemaColumnExists($connection, 'image_studio_messages', 'execution_mode')) {
                $connection->exec(
                    "ALTER TABLE image_studio_messages ADD COLUMN execution_mode "
                    . "ENUM('local', 'regie') NOT NULL DEFAULT 'local' AFTER aspect"
                );
            }
            if (!schemaColumnExists($connection, 'image_studio_messages', 'client_request_id')) {
                $connection->exec(
                    'ALTER TABLE image_studio_messages ADD COLUMN client_request_id '
                    . 'VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER execution_mode'
                );
            }
            if (!schemaColumnExists($connection, 'image_studio_messages', 'worker_account_id')) {
                $connection->exec(
                    'ALTER TABLE image_studio_messages ADD COLUMN worker_account_id '
                    . 'VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER client_request_id'
                );
            }
            if (!schemaColumnExists($connection, 'image_studio_messages', 'worker_attempts')) {
                $connection->exec(
                    'ALTER TABLE image_studio_messages ADD COLUMN worker_attempts '
                    . 'TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER worker_account_id'
                );
            }
            if (!schemaColumnExists($connection, 'image_studio_messages', 'worker_lease_expires_at')) {
                $connection->exec(
                    'ALTER TABLE image_studio_messages ADD COLUMN worker_lease_expires_at '
                    . 'DATETIME(3) NULL AFTER worker_attempts'
                );
            }
            $connection->exec(
                "ALTER TABLE image_studio_messages MODIFY COLUMN status "
                . "ENUM('queued', 'generating', 'succeeded', 'failed', 'rejected', 'cancelled') "
                . "NOT NULL DEFAULT 'queued'"
            );
            if (!schemaIndexExists($connection, 'image_studio_messages', 'uq_image_studio_messages_request', true)) {
                $connection->exec(
                    'ALTER TABLE image_studio_messages ADD UNIQUE KEY uq_image_studio_messages_request '
                    . '(author_account_id, client_request_id)'
                );
            }
            if (!schemaIndexExists($connection, 'image_studio_messages', 'idx_image_studio_messages_regie_queue')) {
                $connection->exec(
                    'ALTER TABLE image_studio_messages ADD KEY idx_image_studio_messages_regie_queue '
                    . '(execution_mode, status, created_at)'
                );
            }
            $connection->exec(
                'CREATE TABLE IF NOT EXISTS image_studio_regie_service ('
                . 'singleton_id TINYINT UNSIGNED NOT NULL DEFAULT 1, '
                . 'paused TINYINT(1) NOT NULL DEFAULT 1, worker_ready TINYINT(1) NOT NULL DEFAULT 0, '
                . 'worker_account_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL, '
                . 'worker_last_seen_at DATETIME(3) NULL, '
                . 'updated_by_account_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL, '
                . 'created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3), '
                . 'updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3), '
                . 'PRIMARY KEY (singleton_id), '
                . 'CONSTRAINT chk_image_studio_regie_service_singleton CHECK (singleton_id = 1)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $connection->exec(
                'INSERT INTO image_studio_regie_service (singleton_id, paused, worker_ready) VALUES (1, 1, 0) '
                . 'ON DUPLICATE KEY UPDATE singleton_id = VALUES(singleton_id)'
            );
            $connection->exec(
                "INSERT IGNORE INTO schema_migrations (version, name, checksum) VALUES "
                . "(9, 'shared_regie_codex_queue', '2fe9c195ca43c1d7565d050a409cf44217e779160d183bdb9531cc974c6a391d')"
            );
        }
    } finally {
        try {
            $connection->query("SELECT RELEASE_LOCK('xar-regie-schema-v9')");
        } catch (Throwable) {
            // La fermeture de la connexion libère aussi ce verrou de maintenance.
        }
    }
}

function readJsonBody(int $maximumBytes = 16384): array
{
    $contentEncoding = strtolower(trim((string) ($_SERVER['HTTP_CONTENT_ENCODING'] ?? $_SERVER['CONTENT_ENCODING'] ?? 'identity')));
    if ($contentEncoding === '') {
        $contentEncoding = 'identity';
    }
    if (!in_array($contentEncoding, ['identity', 'gzip'], true)) {
        sendError(415, 'Encodage de requête non pris en charge.', 'unsupported_content_encoding');
    }
    $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($declared > $maximumBytes) {
        sendError(413, 'Requête trop volumineuse.', 'payload_too_large');
    }
    $raw = file_get_contents('php://input', false, null, 0, $maximumBytes + 1);
    if ($raw === false) {
        sendError(400, 'Requête illisible.', 'invalid_request');
    }
    if (strlen($raw) > $maximumBytes) {
        sendError(413, 'Requête trop volumineuse.', 'payload_too_large');
    }
    if ($contentEncoding === 'gzip') {
        if (!function_exists('gzdecode')) {
            sendError(415, 'Compression gzip indisponible sur ce serveur.', 'gzip_unavailable');
        }
        $decoded = gzdecode($raw, $maximumBytes + 1);
        if ($decoded === false) {
            sendError(400, 'Corps gzip invalide.', 'invalid_gzip');
        }
        if (strlen($decoded) > $maximumBytes) {
            sendError(413, 'Requête décompressée trop volumineuse.', 'payload_too_large');
        }
        $raw = $decoded;
    }
    if ($raw === '') {
        return [];
    }
    try {
        $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        sendError(400, 'JSON invalide.', 'invalid_json');
    }
    if (!is_array($payload)) {
        sendError(400, 'Requête invalide.', 'invalid_request');
    }
    return $payload;
}

function textLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function cleanText(mixed $value, int $maximum, string $label): string
{
    $text = trim((string) $value);
    if ($text === '' || textLength($text) > $maximum || preg_match('/[\x00\r\n]/u', $text) === 1) {
        throw new InvalidArgumentException($label . ' invalide.');
    }
    return $text;
}

function normalizeUsername(mixed $value): array
{
    $username = cleanText($value, 64, 'Identifiant');
    if (preg_match('/^[\p{L}\p{N}][\p{L}\p{N}._-]{2,63}$/u', $username) !== 1) {
        throw new InvalidArgumentException('L’identifiant doit contenir 3 à 64 lettres, chiffres, points, tirets ou tirets bas.');
    }
    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($username, Normalizer::FORM_KC);
        if (is_string($normalized)) {
            $username = $normalized;
        }
    }
    $key = function_exists('mb_strtolower') ? mb_strtolower($username, 'UTF-8') : strtolower($username);
    return [$username, $key];
}

function validatedPassword(mixed $value): string
{
    $password = (string) $value;
    $length = textLength($password);
    if ($length < 10 || $length > 256 || preg_match('/[\x00\r\n]/u', $password) === 1) {
        throw new InvalidArgumentException('Le mot de passe doit contenir entre 10 et 256 caractères.');
    }
    return $password;
}

function hashPassword(string $password): string
{
    $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    $options = $algorithm === PASSWORD_ARGON2ID
        ? ['memory_cost' => 32768, 'time_cost' => 3, 'threads' => 1]
        : ['cost' => 12];
    $verifier = password_hash($password, $algorithm, $options);
    if (!is_string($verifier) || $verifier === '') {
        throw new RuntimeException('password_hash_failed');
    }
    return $verifier;
}

function randomToken(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function utcAfter(int $seconds): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('+' . $seconds . ' seconds')
        ->format('Y-m-d H:i:s.v');
}

function sessionCookie(string $token, int $maximumAge = XAR_SESSION_SECONDS): string
{
    return 'xar_session=' . rawurlencode($token)
        . '; Path=/api/v1; Max-Age=' . max(0, $maximumAge)
        . '; Secure; HttpOnly; SameSite=Strict';
}

function requestSessionToken(): string
{
    $token = trim((string) ($_SERVER['HTTP_X_XAR_SESSION'] ?? ''));
    if ($token === '') {
        $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        if (preg_match('/^Bearer\s+([A-Za-z0-9_-]+)$/i', $authorization, $match) === 1) {
            $token = $match[1];
        }
    }
    if ($token === '') {
        $token = trim((string) ($_COOKIE['xar_session'] ?? ''));
    }
    return preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1 ? $token : '';
}

function tokenHash(string $token): string
{
    return hash('sha256', $token, true);
}

function publicAccount(array $account, string $effectiveMode): array
{
    return [
        'id' => (string) $account['id'],
        'username' => (string) $account['username'],
        'displayName' => (string) $account['display_name'],
        'role' => $effectiveMode,
        'permanentRole' => (string) $account['permanent_role'],
        'canAdministrate' => (bool) ($account['can_administrate'] ?? false),
    ];
}

function createSession(PDO $connection, array $account, string $effectiveMode): array
{
    $mode = $effectiveMode === 'gm' ? 'gm' : 'player';
    if ($mode === 'gm' && (string) $account['permanent_role'] !== 'gm') {
        throw new RuntimeException('gm_role_required');
    }
    $token = randomToken();
    $statement = $connection->prepare(
        'INSERT INTO auth_sessions '
        . '(token_hash, account_id, effective_mode, auth_revision, expires_at) '
        . 'VALUES (:token_hash, :account_id, :effective_mode, :auth_revision, :expires_at)'
    );
    $statement->bindValue(':token_hash', tokenHash($token), PDO::PARAM_LOB);
    $statement->bindValue(':account_id', (string) $account['id']);
    $statement->bindValue(':effective_mode', $mode);
    $statement->bindValue(':auth_revision', (int) $account['auth_revision'], PDO::PARAM_INT);
    $statement->bindValue(':expires_at', utcAfter(XAR_SESSION_SECONDS));
    $statement->execute();
    return ['token' => $token, 'mode' => $mode];
}

function createExclusiveSession(PDO $connection, array $account, string $effectiveMode): array
{
    $mode = $effectiveMode === 'gm' ? 'gm' : 'player';
    if ($mode === 'gm' && (string) $account['permanent_role'] !== 'gm') {
        throw new RuntimeException('gm_role_required');
    }
    $accountId = (string) $account['id'];
    $lockName = 'xar-login-' . substr(hash('sha256', $accountId), 0, 48);
    $lock = $connection->prepare('SELECT GET_LOCK(:lock_name, 12)');
    $lock->execute([':lock_name' => $lockName]);
    if ((int) $lock->fetchColumn() !== 1) {
        throw new RuntimeException('login_lock_timeout');
    }

    try {
        $active = $connection->prepare(
            'SELECT COUNT(*) FROM auth_sessions WHERE account_id = :account_id '
            . 'AND expires_at > UTC_TIMESTAMP(3)'
        );
        $active->execute([':account_id' => $accountId]);
        if ((int) $active->fetchColumn() > 0) {
            $takeover = $connection->prepare(
                'UPDATE accounts SET takeover_requested_at = UTC_TIMESTAMP(3), takeover_request_id = :request_id '
                . 'WHERE id = :id'
            );
            $takeover->bindValue(':request_id', random_bytes(16), PDO::PARAM_LOB);
            $takeover->bindValue(':id', $accountId);
            $takeover->execute();

            $deadline = hrtime(true) + (XAR_LOGIN_TAKEOVER_WAIT_MICROSECONDS * 1000);
            do {
                usleep(250000);
                $active->execute([':account_id' => $accountId]);
                if ((int) $active->fetchColumn() === 0) {
                    break;
                }
            } while (hrtime(true) < $deadline);
        }

        $connection->beginTransaction();
        try {
            $select = $connection->prepare(
                'SELECT id, username, display_name, permanent_role, can_administrate, password_verifier, '
                . 'auth_revision, revoked_at FROM accounts WHERE id = :id FOR UPDATE'
            );
            $select->execute([':id' => $accountId]);
            $current = $select->fetch();
            if (!is_array($current) || $current['revoked_at'] !== null
                || (int) $current['auth_revision'] !== (int) $account['auth_revision']) {
                throw new RuntimeException('account_changed_during_login');
            }
            $delete = $connection->prepare('DELETE FROM auth_sessions WHERE account_id = :account_id');
            $delete->execute([':account_id' => $accountId]);
            $clear = $connection->prepare(
                'UPDATE accounts SET takeover_requested_at = NULL, takeover_request_id = NULL WHERE id = :id'
            );
            $clear->execute([':id' => $accountId]);
            $session = createSession($connection, $current, $mode);
            $connection->commit();
            return ['account' => $current, 'session' => $session];
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
    } finally {
        try {
            $release = $connection->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute([':lock_name' => $lockName]);
        } catch (Throwable) {
        }
    }
}

function deleteSession(PDO $connection, string $token): void
{
    if ($token === '') {
        return;
    }
    $statement = $connection->prepare('DELETE FROM auth_sessions WHERE token_hash = :token_hash');
    $statement->bindValue(':token_hash', tokenHash($token), PDO::PARAM_LOB);
    $statement->execute();
}

function resolveSession(PDO $connection, string $token, bool $touch = true): ?array
{
    if ($token === '') {
        return null;
    }
    $statement = $connection->prepare(
        'SELECT a.id, a.username, a.display_name, a.permanent_role, a.can_administrate, a.auth_revision, a.revoked_at, '
        . 'a.takeover_requested_at, a.takeover_request_id, '
        . 's.effective_mode, s.auth_revision AS session_auth_revision '
        . 'FROM auth_sessions s JOIN accounts a ON a.id = s.account_id '
        . 'WHERE s.token_hash = :token_hash AND s.expires_at > UTC_TIMESTAMP(3) LIMIT 1'
    );
    $statement->bindValue(':token_hash', tokenHash($token), PDO::PARAM_LOB);
    $statement->execute();
    $identity = $statement->fetch();
    if (!is_array($identity)) {
        return null;
    }
    $valid = $identity['revoked_at'] === null
        && (int) $identity['auth_revision'] === (int) $identity['session_auth_revision']
        && ((string) $identity['effective_mode'] !== 'gm' || (string) $identity['permanent_role'] === 'gm');
    if (!$valid) {
        deleteSession($connection, $token);
        return null;
    }
    if ($touch) {
        $update = $connection->prepare(
            'UPDATE auth_sessions SET last_seen_at = UTC_TIMESTAMP(3), expires_at = :expires_at '
            . 'WHERE token_hash = :token_hash'
        );
        $update->bindValue(':expires_at', utcAfter(XAR_SESSION_SECONDS));
        $update->bindValue(':token_hash', tokenHash($token), PDO::PARAM_LOB);
        $update->execute();
    }
    return $identity;
}

function rateBucket(string $usernameKey): string
{
    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    return hash('sha256', $remote . "\0" . $usernameKey, true);
}

function dateTimestamp(mixed $value): int
{
    if (!is_string($value) || $value === '') {
        return 0;
    }
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp();
    } catch (Throwable) {
        return 0;
    }
}

function assertRateAvailable(PDO $connection, string $bucket): void
{
    $statement = $connection->prepare('SELECT locked_until FROM auth_rate_limits WHERE bucket_hash = :bucket_hash');
    $statement->bindValue(':bucket_hash', $bucket, PDO::PARAM_LOB);
    $statement->execute();
    $row = $statement->fetch();
    $lockedUntil = is_array($row) ? dateTimestamp($row['locked_until'] ?? null) : 0;
    if ($lockedUntil > time()) {
        $seconds = max(1, $lockedUntil - time());
        sendJson(429, [
            'ok' => false,
            'error' => 'Trop de tentatives. Réessayez dans quelques instants.',
            'code' => 'rate_limited',
        ], false, ['Retry-After' => (string) $seconds]);
    }
}

function recordRateFailure(PDO $connection, string $bucket): void
{
    $connection->beginTransaction();
    try {
        $select = $connection->prepare(
            'SELECT attempts, window_started_at FROM auth_rate_limits '
            . 'WHERE bucket_hash = :bucket_hash FOR UPDATE'
        );
        $select->bindValue(':bucket_hash', $bucket, PDO::PARAM_LOB);
        $select->execute();
        $row = $select->fetch();
        $windowExpired = !is_array($row)
            || dateTimestamp($row['window_started_at'] ?? null) < time() - XAR_LOGIN_WINDOW_SECONDS;
        $attempts = $windowExpired ? 1 : ((int) $row['attempts'] + 1);
        $lockedUntil = null;
        if ($attempts >= XAR_LOGIN_MAX_ATTEMPTS) {
            $attempts = 0;
            $lockedUntil = utcAfter(XAR_LOGIN_LOCK_SECONDS);
        }

        if (!is_array($row)) {
            $insert = $connection->prepare(
                'INSERT INTO auth_rate_limits '
                . '(bucket_hash, attempts, window_started_at, locked_until) '
                . 'VALUES (:bucket_hash, :attempts, UTC_TIMESTAMP(3), :locked_until)'
            );
            $insert->bindValue(':bucket_hash', $bucket, PDO::PARAM_LOB);
            $insert->bindValue(':attempts', $attempts, PDO::PARAM_INT);
            $insert->bindValue(':locked_until', $lockedUntil);
            $insert->execute();
        } else {
            $update = $connection->prepare(
                'UPDATE auth_rate_limits SET attempts = :attempts, '
                . 'window_started_at = IF(:window_expired = 1, UTC_TIMESTAMP(3), window_started_at), '
                . 'locked_until = :locked_until WHERE bucket_hash = :bucket_hash'
            );
            $update->bindValue(':attempts', $attempts, PDO::PARAM_INT);
            $update->bindValue(':window_expired', $windowExpired ? 1 : 0, PDO::PARAM_INT);
            $update->bindValue(':locked_until', $lockedUntil);
            $update->bindValue(':bucket_hash', $bucket, PDO::PARAM_LOB);
            $update->execute();
        }
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
}

function clearRateFailures(PDO $connection, string $bucket): void
{
    $statement = $connection->prepare('DELETE FROM auth_rate_limits WHERE bucket_hash = :bucket_hash');
    $statement->bindValue(':bucket_hash', $bucket, PDO::PARAM_LOB);
    $statement->execute();
}

function findAccount(PDO $connection, string $usernameKey): ?array
{
    $statement = $connection->prepare(
        'SELECT id, username, display_name, permanent_role, can_administrate, password_verifier, auth_revision, revoked_at '
        . 'FROM accounts WHERE username_key = :username_key LIMIT 1'
    );
    $statement->execute([':username_key' => $usernameKey]);
    $account = $statement->fetch();
    return is_array($account) ? $account : null;
}

function managedAccount(array $account): array
{
    return [
        'id' => (string) $account['id'],
        'username' => (string) $account['username'],
        'displayName' => (string) $account['display_name'],
        'role' => (string) $account['permanent_role'],
        'canAdministrate' => (bool) ($account['can_administrate'] ?? false),
        'revoked' => $account['revoked_at'] !== null,
    ];
}

function requireGmIdentity(PDO $connection): array
{
    $identity = resolveSession($connection, requestSessionToken());
    if (!is_array($identity)) {
        sendError(401, 'Connexion requise.', 'authentication_required');
    }
    if ((string) $identity['effective_mode'] !== 'gm' || (string) $identity['permanent_role'] !== 'gm') {
        sendError(403, 'Cette action est réservée à une session MJ.', 'gm_required');
    }
    return $identity;
}

function requireAdministratorIdentity(PDO $connection): array
{
    $identity = requireGmIdentity($connection);
    if (!(bool) ($identity['can_administrate'] ?? false)) {
        sendError(403, 'Cette action est réservée à un administrateur de la Régie.', 'administrator_required');
    }
    return $identity;
}

function listManagedAccounts(PDO $connection): array
{
    $statement = $connection->query(
        'SELECT id, username, display_name, permanent_role, can_administrate, revoked_at '
        . 'FROM accounts ORDER BY display_name, username'
    );
    $accounts = $statement === false ? [] : $statement->fetchAll();
    return array_map(static fn (array $account): array => managedAccount($account), $accounts);
}

function createManagedAccount(PDO $connection): never
{
    requireAdministratorIdentity($connection);
    $payload = readJsonBody();
    try {
        [$username, $usernameKey] = normalizeUsername($payload['username'] ?? '');
        $displayName = cleanText($payload['displayName'] ?? $username, 96, 'Nom affiché');
        $password = validatedPassword($payload['password'] ?? '');
    } catch (InvalidArgumentException $error) {
        sendError(400, $error->getMessage(), 'invalid_account');
    }
    $requestedRole = $payload['role'] ?? 'player';
    if (!in_array($requestedRole, ['player', 'gm'], true)) {
        sendError(400, 'Niveau de compte invalide.', 'invalid_account');
    }
    $role = (string) $requestedRole;
    $canAdministrate = ($payload['canAdministrate'] ?? false) === true;
    if ($canAdministrate && $role !== 'gm') {
        sendError(400, 'Un administrateur doit posséder le niveau MJ.', 'invalid_account');
    }
    if (findAccount($connection, $usernameKey) !== null) {
        sendError(409, 'Cet identifiant existe déjà.', 'username_exists');
    }

    $accountId = 'usr_' . randomToken(12);
    try {
        $insert = $connection->prepare(
            'INSERT INTO accounts '
            . '(id, username, username_key, display_name, permanent_role, can_administrate, password_verifier) '
            . 'VALUES (:id, :username, :username_key, :display_name, :permanent_role, :can_administrate, :password_verifier)'
        );
        $insert->execute([
            ':id' => $accountId,
            ':username' => $username,
            ':username_key' => $usernameKey,
            ':display_name' => $displayName,
            ':permanent_role' => $role,
            ':can_administrate' => $canAdministrate ? 1 : 0,
            ':password_verifier' => hashPassword($password),
        ]);
    } catch (PDOException $error) {
        if ((string) $error->getCode() === '23000') {
            sendError(409, 'Cet identifiant existe déjà.', 'username_exists');
        }
        throw $error;
    }
    $created = findAccount($connection, $usernameKey);
    if (!is_array($created)) {
        throw new RuntimeException('created_account_missing');
    }
    sendJson(201, ['ok' => true, 'account' => managedAccount($created)]);
}

function updateManagedAccount(PDO $connection): never
{
    $manager = requireAdministratorIdentity($connection);
    $payload = readJsonBody();
    $accountId = trim((string) ($payload['id'] ?? ''));
    if (preg_match('/^[A-Za-z0-9_-]{8,128}$/', $accountId) !== 1) {
        sendError(400, 'Référence de compte invalide.', 'invalid_account');
    }

    $connection->beginTransaction();
    try {
        $select = $connection->prepare(
            'SELECT id, username, display_name, permanent_role, can_administrate, password_verifier, auth_revision, revoked_at '
            . 'FROM accounts WHERE id = :id FOR UPDATE'
        );
        $select->execute([':id' => $accountId]);
        $account = $select->fetch();
        if (!is_array($account)) {
            $connection->rollBack();
            sendError(404, 'Compte introuvable.', 'account_not_found');
        }

        $displayName = (string) $account['display_name'];
        $role = (string) $account['permanent_role'];
        $canAdministrate = (bool) $account['can_administrate'];
        $revoked = $account['revoked_at'] !== null;
        $passwordVerifier = (string) $account['password_verifier'];
        try {
            if (array_key_exists('displayName', $payload)) {
                $displayName = cleanText($payload['displayName'], 96, 'Nom affiché');
            }
            if (array_key_exists('role', $payload)) {
                if (!in_array($payload['role'], ['player', 'gm'], true)) {
                    throw new InvalidArgumentException('Niveau de compte invalide.');
                }
                $role = (string) $payload['role'];
            }
            if (array_key_exists('revoked', $payload)) {
                if (!is_bool($payload['revoked'])) {
                    throw new InvalidArgumentException('État de révocation invalide.');
                }
                $revoked = $payload['revoked'];
            }
            if (array_key_exists('canAdministrate', $payload)) {
                if (!is_bool($payload['canAdministrate'])) {
                    throw new InvalidArgumentException('Droit d’administration invalide.');
                }
                $canAdministrate = $payload['canAdministrate'];
            }
            if (array_key_exists('password', $payload) && (string) $payload['password'] !== '') {
                $passwordVerifier = hashPassword(validatedPassword($payload['password']));
            }
        } catch (InvalidArgumentException $error) {
            $connection->rollBack();
            sendError(400, $error->getMessage(), 'invalid_account');
        }

        $isSelf = (string) $manager['id'] === $accountId;
        if ($canAdministrate && $role !== 'gm') {
            $connection->rollBack();
            sendError(400, 'Un administrateur doit posséder le niveau MJ.', 'invalid_account');
        }
        if ($isSelf && ($role !== 'gm' || !$canAdministrate || $revoked)) {
            $connection->rollBack();
            sendError(409, 'L’administrateur connecté ne peut pas se retirer son propre accès.', 'self_lockout');
        }
        $removesActiveGm = (string) $account['permanent_role'] === 'gm'
            && $account['revoked_at'] === null
            && ($role !== 'gm' || $revoked);
        if ($removesActiveGm) {
            $activeGms = $connection->query(
                "SELECT id FROM accounts WHERE permanent_role = 'gm' AND revoked_at IS NULL FOR UPDATE"
            )->fetchAll();
            if (count($activeGms) <= 1) {
                $connection->rollBack();
                sendError(409, 'La Régie doit conserver au moins un compte MJ actif.', 'last_gm_required');
            }
        }

        $removesActiveAdministrator = (bool) $account['can_administrate']
            && $account['revoked_at'] === null
            && (!$canAdministrate || $revoked || $role !== 'gm');
        if ($removesActiveAdministrator) {
            $administrators = $connection->query(
                "SELECT id FROM accounts WHERE permanent_role = 'gm' AND can_administrate = 1 AND revoked_at IS NULL FOR UPDATE"
            )->fetchAll();
            if (count($administrators) <= 1) {
                $connection->rollBack();
                sendError(409, 'La Régie doit conserver au moins un administrateur actif.', 'last_administrator_required');
            }
        }

        $update = $connection->prepare(
            'UPDATE accounts SET display_name = :display_name, permanent_role = :permanent_role, can_administrate = :can_administrate, '
            . 'password_verifier = :password_verifier, revoked_at = :revoked_at, '
            . 'auth_revision = auth_revision + 1 WHERE id = :id'
        );
        $update->execute([
            ':display_name' => $displayName,
            ':permanent_role' => $role,
            ':can_administrate' => $canAdministrate ? 1 : 0,
            ':password_verifier' => $passwordVerifier,
            ':revoked_at' => $revoked
                ? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v')
                : null,
            ':id' => $accountId,
        ]);
        $deleteSessions = $connection->prepare('DELETE FROM auth_sessions WHERE account_id = :account_id');
        $deleteSessions->execute([':account_id' => $accountId]);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }

    $updated = $connection->prepare(
        'SELECT id, username, display_name, permanent_role, can_administrate, revoked_at FROM accounts WHERE id = :id'
    );
    $updated->execute([':id' => $accountId]);
    $account = $updated->fetch();
    if (!is_array($account)) {
        throw new RuntimeException('updated_account_missing');
    }
    sendJson(200, ['ok' => true, 'account' => managedAccount($account)]);
}

function authenticateAccount(PDO $connection, mixed $username, mixed $password, string $scope): array
{
    try {
        [, $usernameKey] = normalizeUsername($username);
        $passwordText = validatedPassword($password);
    } catch (InvalidArgumentException) {
        $usernameKey = 'invalid';
        $passwordText = str_repeat('x', 10);
    }

    $bucket = rateBucket($usernameKey);
    assertRateAvailable($connection, $bucket);
    $account = findAccount($connection, $usernameKey);
    $verifier = is_array($account)
        ? (string) $account['password_verifier']
        : hashPassword(randomToken());
    $passwordMatches = password_verify($passwordText, $verifier);
    $allowed = is_array($account)
        && $account['revoked_at'] === null
        && $passwordMatches
        && ($scope !== 'gm' || (string) $account['permanent_role'] === 'gm');
    if (!$allowed) {
        recordRateFailure($connection, $bucket);
        sendError(403, 'Identifiant ou mot de passe incorrect.', 'invalid_credentials');
    }
    clearRateFailures($connection, $bucket);
    return $account;
}

function cleanupAuthentication(PDO $connection): void
{
    try {
        if (random_int(1, 50) !== 1) {
            return;
        }
        $connection->exec('DELETE FROM live_connections WHERE expires_at <= UTC_TIMESTAMP(3)');
        $connection->exec('DELETE FROM auth_sessions WHERE expires_at <= UTC_TIMESTAMP(3)');
        $connection->exec(
            'DELETE FROM auth_rate_limits WHERE updated_at < DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 DAY)'
        );
        $connection->exec(
            'DELETE FROM bootstrap_tokens WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 DAY) '
            . 'OR consumed_at < DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 DAY)'
        );
        $connection->exec(
            'DELETE FROM account_recovery_tokens '
            . 'WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 DAY) '
            . 'OR consumed_at < DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 DAY)'
        );
    } catch (Throwable $error) {
        error_log('[xar-regie-api] authentication cleanup failed: ' . get_class($error));
    }
}

function bootstrapFirstAccount(PDO $connection): never
{
    $payload = readJsonBody();
    $providedToken = trim((string) ($payload['bootstrapToken'] ?? ''));
    $bucket = rateBucket('bootstrap');
    assertRateAvailable($connection, $bucket);
    if (preg_match('/^[A-Za-z0-9_-]{43}$/', $providedToken) !== 1) {
        recordRateFailure($connection, $bucket);
        sendError(403, 'Initialisation refusée.', 'bootstrap_refused');
    }
    $providedHash = tokenHash($providedToken);
    $tokenLookup = $connection->prepare(
        'SELECT expires_at FROM bootstrap_tokens WHERE token_hash = :token_hash '
        . 'AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP(3) LIMIT 1'
    );
    $tokenLookup->bindValue(':token_hash', $providedHash, PDO::PARAM_LOB);
    $tokenLookup->execute();
    if (!is_array($tokenLookup->fetch())) {
        recordRateFailure($connection, $bucket);
        sendError(403, 'Initialisation refusée.', 'bootstrap_refused');
    }
    clearRateFailures($connection, $bucket);

    try {
        [$username, $usernameKey] = normalizeUsername($payload['username'] ?? '');
        $displayName = cleanText($payload['displayName'] ?? $username, 96, 'Nom affiché');
        $password = validatedPassword($payload['password'] ?? '');
    } catch (InvalidArgumentException $error) {
        sendError(400, $error->getMessage(), 'invalid_account');
    }

    $connection->beginTransaction();
    try {
        $tokenLock = $connection->prepare(
            'SELECT expires_at, consumed_at FROM bootstrap_tokens '
            . 'WHERE token_hash = :token_hash FOR UPDATE'
        );
        $tokenLock->bindValue(':token_hash', $providedHash, PDO::PARAM_LOB);
        $tokenLock->execute();
        $tokenRow = $tokenLock->fetch();
        if (!is_array($tokenRow) || $tokenRow['consumed_at'] !== null
            || dateTimestamp($tokenRow['expires_at'] ?? null) <= time()) {
            $connection->rollBack();
            sendError(403, 'Initialisation refusée.', 'bootstrap_refused');
        }
        $existing = $connection->query('SELECT id FROM accounts LIMIT 1 FOR UPDATE')->fetch();
        if (is_array($existing)) {
            $connection->rollBack();
            sendError(409, 'La Régie possède déjà un compte.', 'already_initialized');
        }
        $accountId = 'usr_' . randomToken(12);
        $insert = $connection->prepare(
            'INSERT INTO accounts '
            . '(id, username, username_key, display_name, permanent_role, can_administrate, password_verifier) '
            . 'VALUES (:id, :username, :username_key, :display_name, \'gm\', 1, :password_verifier)'
        );
        $insert->execute([
            ':id' => $accountId,
            ':username' => $username,
            ':username_key' => $usernameKey,
            ':display_name' => $displayName,
            ':password_verifier' => hashPassword($password),
        ]);
        $consume = $connection->prepare(
            'UPDATE bootstrap_tokens SET consumed_at = UTC_TIMESTAMP(3) WHERE token_hash = :token_hash'
        );
        $consume->bindValue(':token_hash', $providedHash, PDO::PARAM_LOB);
        $consume->execute();
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }

    $account = findAccount($connection, $usernameKey);
    if (!is_array($account)) {
        throw new RuntimeException('bootstrap_account_missing');
    }
    $session = createSession($connection, $account, 'gm');
    sendJson(201, [
        'ok' => true,
        'account' => publicAccount($account, 'gm'),
        'sessionToken' => $session['token'],
        'expiresInSeconds' => XAR_SESSION_SECONDS,
    ], false, ['Set-Cookie' => sessionCookie($session['token'])]);
}

function recoverAdministratorAccount(PDO $connection): never
{
    $payload = readJsonBody();
    $providedToken = trim((string) ($payload['recoveryToken'] ?? ''));
    $bucket = rateBucket('account-recovery');
    assertRateAvailable($connection, $bucket);
    if (preg_match('/^[A-Za-z0-9_-]{43}$/', $providedToken) !== 1) {
        recordRateFailure($connection, $bucket);
        sendError(403, 'Récupération refusée.', 'recovery_refused');
    }

    try {
        $password = validatedPassword($payload['password'] ?? '');
    } catch (InvalidArgumentException $error) {
        sendError(400, $error->getMessage(), 'invalid_password');
    }

    $providedHash = tokenHash($providedToken);
    $passwordVerifier = hashPassword($password);
    $recovered = false;
    $connection->beginTransaction();
    try {
        $tokenLock = $connection->prepare(
            'SELECT account_id, expires_at, consumed_at FROM account_recovery_tokens '
            . 'WHERE token_hash = :token_hash FOR UPDATE'
        );
        $tokenLock->bindValue(':token_hash', $providedHash, PDO::PARAM_LOB);
        $tokenLock->execute();
        $tokenRow = $tokenLock->fetch();
        if (is_array($tokenRow) && $tokenRow['consumed_at'] === null
            && dateTimestamp($tokenRow['expires_at'] ?? null) > time()) {
            $accountLock = $connection->prepare(
                "SELECT id FROM accounts WHERE id = :id AND permanent_role = 'gm' "
                . 'AND can_administrate = 1 AND revoked_at IS NULL LIMIT 1 FOR UPDATE'
            );
            $accountLock->execute([':id' => (string) $tokenRow['account_id']]);
            $account = $accountLock->fetch();
            if (is_array($account)) {
                $accountId = (string) $account['id'];
                $update = $connection->prepare(
                    'UPDATE accounts SET password_verifier = :password_verifier, '
                    . 'auth_revision = auth_revision + 1, takeover_requested_at = NULL, '
                    . 'takeover_request_id = NULL WHERE id = :id'
                );
                $update->execute([
                    ':password_verifier' => $passwordVerifier,
                    ':id' => $accountId,
                ]);
                $deleteSessions = $connection->prepare(
                    'DELETE FROM auth_sessions WHERE account_id = :account_id'
                );
                $deleteSessions->execute([':account_id' => $accountId]);
                $consume = $connection->prepare(
                    'UPDATE account_recovery_tokens SET consumed_at = UTC_TIMESTAMP(3) '
                    . 'WHERE token_hash = :token_hash AND consumed_at IS NULL'
                );
                $consume->bindValue(':token_hash', $providedHash, PDO::PARAM_LOB);
                $consume->execute();
                $recovered = $consume->rowCount() === 1;
            }
        }

        if ($recovered) {
            $connection->commit();
        } else {
            $connection->rollBack();
        }
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }

    if (!$recovered) {
        recordRateFailure($connection, $bucket);
        sendError(403, 'Récupération refusée.', 'recovery_refused');
    }
    clearRateFailures($connection, $bucket);
    sendJson(200, ['ok' => true]);
}

require_once __DIR__ . '/online.php';
require_once __DIR__ . '/domains.php';
require_once __DIR__ . '/image-studio.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$headOnly = $method === 'HEAD';

if (!requestIsSecure()) {
    sendJson(426, ['ok' => false, 'error' => 'HTTPS requis.', 'code' => 'https_required'], $headOnly);
}
if (requestHost() !== XAR_API_HOST) {
    sendJson(421, ['ok' => false, 'error' => 'Hôte refusé.', 'code' => 'host_rejected'], $headOnly);
}

$route = requestRoute();
if ($route === '/api/v1') {
    requireMethod($method, ['GET', 'HEAD']);
    sendJson(200, [
        'status' => 'ok',
        'service' => 'xar-tsaroth-regie',
        'api' => 'v1',
        'version' => XAR_BACKEND_VERSION,
    ], $headOnly);
}

$configuration = privateConfig();
if ($configuration === null) {
    sendJson(503, [
        'ok' => false,
        'status' => 'unavailable',
        'code' => 'configuration_required',
    ], $headOnly);
}

try {
    $connection = databaseConnection($configuration);
    ensureCurrentSchema($connection);
} catch (Throwable $error) {
    error_log('[xar-regie-api] database connection failed: ' . get_class($error));
    $code = $error instanceof RuntimeException && $error->getMessage() === 'configuration_required'
        ? 'configuration_required'
        : 'database_unreachable';
    sendJson(503, ['ok' => false, 'status' => 'unavailable', 'code' => $code], $headOnly);
}

if ($route === '/api/v1/health') {
    requireMethod($method, ['GET', 'HEAD']);
    try {
        $statement = $connection->query('SELECT 1');
        if ($statement === false || (int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException('database_unreachable');
        }
        sendJson(200, [
            'status' => 'ok',
            'service' => 'xar-tsaroth-regie',
            'api' => 'v1',
            'version' => XAR_BACKEND_VERSION,
            'clientPolicy' => clientPolicy($configuration),
        ], $headOnly);
    } catch (Throwable $error) {
        error_log('[xar-regie-api] database health check failed: ' . get_class($error));
        sendJson(503, ['ok' => false, 'status' => 'unavailable', 'code' => 'database_unreachable'], $headOnly);
    }
}

try {
    if (!in_array($route, ['/api/v1/auth/logout', '/api/v1/auth/bootstrap', '/api/v1/auth/recover'], true)
        && !str_starts_with($route, '/api/v1/image-studio')) {
        requireSupportedClient($configuration);
    }
    cleanupAuthentication($connection);

    if ($route === '/api/v1/auth/bootstrap') {
        requireMethod($method, ['POST']);
        bootstrapFirstAccount($connection);
    }

    if ($route === '/api/v1/auth/recover') {
        requireMethod($method, ['POST']);
        recoverAdministratorAccount($connection);
    }

    if ($route === '/api/v1/auth/login') {
        requireMethod($method, ['POST']);
        $payload = readJsonBody();
        $scope = ($payload['scope'] ?? '') === 'gm' ? 'gm' : 'player';
        $account = authenticateAccount(
            $connection,
            $payload['username'] ?? '',
            $payload['password'] ?? '',
            $scope
        );
        $exclusive = createExclusiveSession($connection, $account, $scope);
        $account = $exclusive['account'];
        $session = $exclusive['session'];
        sendJson(200, [
            'ok' => true,
            'account' => publicAccount($account, $scope),
            'sessionToken' => $session['token'],
            'expiresInSeconds' => XAR_SESSION_SECONDS,
        ], false, ['Set-Cookie' => sessionCookie($session['token'])]);
    }

    if ($route === '/api/v1/auth/me') {
        requireMethod($method, ['GET', 'HEAD']);
        $token = requestSessionToken();
        $identity = resolveSession($connection, $token);
        if (!is_array($identity)) {
            sendJson(401, ['ok' => false, 'error' => 'Connexion requise.', 'code' => 'authentication_required'], $headOnly);
        }
        sendJson(200, [
            'ok' => true,
            'account' => publicAccount($identity, (string) $identity['effective_mode']),
        ], $headOnly);
    }

    if ($route === '/api/v1/auth/logout') {
        requireMethod($method, ['POST']);
        deleteSession($connection, requestSessionToken());
        sendJson(200, ['ok' => true], false, ['Set-Cookie' => sessionCookie('', 0)]);
    }

    if ($route === '/api/v1/auth/password') {
        requireMethod($method, ['POST']);
        $token = requestSessionToken();
        $identity = resolveSession($connection, $token, false);
        if (!is_array($identity)) {
            sendError(401, 'Connexion requise.', 'authentication_required');
        }
        $payload = readJsonBody();
        try {
            $currentPassword = validatedPassword($payload['currentPassword'] ?? '');
            $newPassword = validatedPassword($payload['newPassword'] ?? '');
        } catch (InvalidArgumentException $error) {
            sendError(400, $error->getMessage(), 'invalid_password');
        }

        $connection->beginTransaction();
        try {
            $select = $connection->prepare(
                'SELECT id, username, display_name, permanent_role, can_administrate, password_verifier, auth_revision, revoked_at '
                . 'FROM accounts WHERE id = :id FOR UPDATE'
            );
            $select->execute([':id' => (string) $identity['id']]);
            $account = $select->fetch();
            if (!is_array($account) || $account['revoked_at'] !== null
                || !password_verify($currentPassword, (string) $account['password_verifier'])) {
                $connection->rollBack();
                sendError(403, 'Le mot de passe actuel est incorrect.', 'invalid_current_password');
            }
            if (password_verify($newPassword, (string) $account['password_verifier'])) {
                $connection->rollBack();
                sendError(400, 'Le nouveau mot de passe doit être différent de l’ancien.', 'password_unchanged');
            }
            $update = $connection->prepare(
                'UPDATE accounts SET password_verifier = :password_verifier, '
                . 'auth_revision = auth_revision + 1 WHERE id = :id'
            );
            $update->execute([
                ':password_verifier' => hashPassword($newPassword),
                ':id' => (string) $account['id'],
            ]);
            $deleteSessions = $connection->prepare('DELETE FROM auth_sessions WHERE account_id = :account_id');
            $deleteSessions->execute([':account_id' => (string) $account['id']]);
            $connection->commit();
        } catch (Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }

        [, $updatedUsernameKey] = normalizeUsername($account['username']);
        $updated = findAccount($connection, $updatedUsernameKey);
        if (!is_array($updated)) {
            throw new RuntimeException('updated_account_missing');
        }
        $mode = (string) $identity['effective_mode'];
        $exclusive = createExclusiveSession($connection, $updated, $mode);
        $updated = $exclusive['account'];
        $session = $exclusive['session'];
        sendJson(200, [
            'ok' => true,
            'account' => publicAccount($updated, $mode),
            'sessionToken' => $session['token'],
            'expiresInSeconds' => XAR_SESSION_SECONDS,
        ], false, ['Set-Cookie' => sessionCookie($session['token'])]);
    }

    if ($route === '/api/v1/accounts') {
        if ($method === 'GET' || $method === 'HEAD') {
            requireAdministratorIdentity($connection);
            sendJson(200, ['ok' => true, 'accounts' => listManagedAccounts($connection)], $headOnly);
        }
        if ($method === 'POST') {
            createManagedAccount($connection);
        }
        if ($method === 'PATCH') {
            updateManagedAccount($connection);
        }
        requireMethod($method, ['GET', 'HEAD', 'POST', 'PATCH']);
    }

    handleOnlineRoute($connection, $configuration, $route, $method, $headOnly);
} catch (Throwable $error) {
    error_log('[xar-regie-api] authentication request failed: ' . get_class($error));
    sendError(503, 'Service momentanément indisponible.', 'service_unavailable');
}

sendJson(404, ['ok' => false, 'error' => 'Route inconnue.', 'code' => 'not_found'], $headOnly);
