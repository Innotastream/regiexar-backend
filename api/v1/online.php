<?php

declare(strict_types=1);

const XAR_CONNECTION_SECONDS = 45;
const XAR_MEDIA_MAXIMUM_BYTES = 300 * 1024 * 1024;
const XAR_MEDIA_MAINTENANCE_CANDIDATES = 5;
const XAR_SSE_MINIMUM_POLL_MICROSECONDS = 250000;
const XAR_SSE_MAXIMUM_POLL_MICROSECONDS = 1500000;
const XAR_IDENTITY_OWNERSHIP_REPAIR_VERSION = 3;

function requireIdentity(PDO $connection): array
{
    $identity = resolveSession($connection, requestSessionToken());
    if (!is_array($identity)) {
        sendError(401, 'Connexion requise.', 'authentication_required');
    }
    return $identity;
}

function jsonColumn(mixed $value, array $fallback = []): array
{
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value) || $value === '') {
        return $fallback;
    }
    try {
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : $fallback;
    } catch (JsonException) {
        return $fallback;
    }
}

function decodeSettingsEncryptionKey(mixed $value): string
{
    $encoded = trim((string) $value);
    if (preg_match('/^[a-fA-F0-9]{64}$/D', $encoded) === 1) {
        $decoded = hex2bin($encoded);
        return is_string($decoded) ? $decoded : '';
    }
    $decoded = base64_decode($encoded, true);
    return is_string($decoded) && strlen($decoded) === 32 ? $decoded : '';
}

function configuredSettingsEncryptionKey(array $configuration): string
{
    $configured = decodeSettingsEncryptionKey($configuration['security']['settingsEncryptionKey'] ?? '');
    if (strlen($configured) === 32) {
        return $configured;
    }
    $environment = decodeSettingsEncryptionKey(getenv('XAR_REGIE_SETTINGS_ENCRYPTION_KEY'));
    if (strlen($environment) === 32) {
        return $environment;
    }
    $path = settingsEncryptionKeyFilePath();
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return '';
    }
    $persisted = decodeSettingsEncryptionKey(file_get_contents($path));
    return strlen($persisted) === 32 ? $persisted : '';
}

function settingsEncryptionKeyFilePath(): string
{
    $configPath = privateConfigPath();
    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($configPath === null || $documentRoot === false) {
        return '';
    }
    $privateDirectory = realpath(dirname($configPath));
    if ($privateDirectory === false) {
        return '';
    }
    $documentPrefix = rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $privatePrefix = rtrim($privateDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($privateDirectory === $documentRoot || str_starts_with($privatePrefix, $documentPrefix)) {
        return '';
    }
    return $privateDirectory . DIRECTORY_SEPARATOR . 'settings-encryption.key';
}

function createPrivateSettingsEncryptionKey(array $configuration): string
{
    $existing = configuredSettingsEncryptionKey($configuration);
    if (strlen($existing) === 32) {
        return $existing;
    }
    $path = settingsEncryptionKeyFilePath();
    if ($path === '') {
        return '';
    }
    $lock = fopen($path . '.lock', 'c');
    if ($lock === false) {
        return '';
    }
    try {
        @chmod($path . '.lock', 0600);
        if (!flock($lock, LOCK_EX)) {
            return '';
        }
        $persisted = is_file($path) && is_readable($path)
            ? decodeSettingsEncryptionKey(file_get_contents($path))
            : '';
        if (strlen($persisted) === 32) {
            return $persisted;
        }
        $key = random_bytes(32);
        $temporary = tempnam(dirname($path), 'xar-settings-key-');
        if (!is_string($temporary)) {
            return '';
        }
        $written = file_put_contents($temporary, base64_encode($key) . "\n", LOCK_EX);
        @chmod($temporary, 0600);
        if ($written === false || !rename($temporary, $path)) {
            @unlink($temporary);
            return '';
        }
        @chmod($path, 0600);
        return $key;
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function requireWritableSettingsEncryption(array $configuration): void
{
    if (strlen(createPrivateSettingsEncryptionKey($configuration)) !== 32) {
        sendError(
            503,
            'La clé privée de chiffrement des réglages n’a pas pu être créée hors du dossier public.',
            'settings_encryption_key_required'
        );
    }
}

function previousSettingsEncryptionKeys(array $configuration): array
{
    $values = $configuration['security']['previousSettingsEncryptionKeys'] ?? [];
    if (!is_array($values)) {
        return [];
    }
    return array_values(array_filter(
        array_map(static fn (mixed $value): string => decodeSettingsEncryptionKey($value), array_slice($values, 0, 4)),
        static fn (string $key): bool => strlen($key) === 32
    ));
}

function legacySettingsEncryptionKey(array $configuration): string
{
    $password = (string) ($configuration['database']['password'] ?? '');
    return $password === '' ? '' : hash_hkdf('sha256', $password, 32, 'xar-tsaroth-regie-settings-v1');
}

function decryptSettingsSecrets(array $configuration, ?array $record): array
{
    if (!is_array($record) || empty($record['encrypted_secrets'])) {
        return [];
    }
    $keys = array_values(array_unique(array_filter([
        configuredSettingsEncryptionKey($configuration),
        ...previousSettingsEncryptionKeys($configuration),
        legacySettingsEncryptionKey($configuration),
    ], static fn (string $key): bool => strlen($key) === 32)));
    foreach ($keys as $key) {
        $plain = openssl_decrypt(
            (string) $record['encrypted_secrets'],
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            (string) ($record['secret_nonce'] ?? ''),
            (string) ($record['secret_tag'] ?? '')
        );
        if (is_string($plain)) {
            return jsonColumn($plain);
        }
    }
    throw new RuntimeException('settings_decryption_failed');
}

function encryptSettingsSecrets(array $configuration, array $secrets): array
{
    $nonce = random_bytes(12);
    $tag = '';
    $key = configuredSettingsEncryptionKey($configuration);
    if (strlen($key) !== 32) {
        throw new RuntimeException('settings_encryption_key_required');
    }
    $plain = json_encode($secrets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $ciphertext = openssl_encrypt(
        $plain,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag
    );
    if (!is_string($ciphertext) || strlen($tag) !== 16) {
        throw new RuntimeException('settings_encryption_failed');
    }
    return ['ciphertext' => $ciphertext, 'nonce' => $nonce, 'tag' => $tag];
}

function settingsRecord(PDO $connection, bool $forUpdate = false): ?array
{
    $statement = $connection->query(
        'SELECT revision, public_payload, encrypted_secrets, secret_nonce, secret_tag '
        . 'FROM regie_settings WHERE singleton_id = 1' . ($forUpdate ? ' FOR UPDATE' : '')
    );
    $record = $statement === false ? false : $statement->fetch();
    return is_array($record) ? $record : null;
}

function writeSettingsRecord(
    PDO $connection,
    array $configuration,
    array $record,
    array $public,
    array $secrets,
    string $accountId
): int {
    $encrypted = encryptSettingsSecrets($configuration, $secrets);
    $nextRevision = (int) ($record['revision'] ?? 0) + 1;
    $statement = $connection->prepare(
        'INSERT INTO regie_settings '
        . '(singleton_id, revision, public_payload, encrypted_secrets, secret_nonce, secret_tag, updated_by_account_id) '
        . 'VALUES (1, :revision, :public_payload, :encrypted_secrets, :secret_nonce, :secret_tag, :updated_by) '
        . 'ON DUPLICATE KEY UPDATE revision = VALUES(revision), public_payload = VALUES(public_payload), '
        . 'encrypted_secrets = VALUES(encrypted_secrets), secret_nonce = VALUES(secret_nonce), '
        . 'secret_tag = VALUES(secret_tag), updated_by_account_id = VALUES(updated_by_account_id)'
    );
    $statement->bindValue(':revision', $nextRevision, PDO::PARAM_INT);
    $statement->bindValue(':public_payload', json_encode($public, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $statement->bindValue(':encrypted_secrets', $encrypted['ciphertext'], PDO::PARAM_LOB);
    $statement->bindValue(':secret_nonce', $encrypted['nonce'], PDO::PARAM_LOB);
    $statement->bindValue(':secret_tag', $encrypted['tag'], PDO::PARAM_LOB);
    $statement->bindValue(':updated_by', $accountId);
    $statement->execute();
    return $nextRevision;
}

function validDiscordWebhook(string $value): bool
{
    if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00\r\n]/', $value) === 1) {
        return false;
    }
    $parts = parse_url($value);
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = (string) ($parts['path'] ?? '');
    return ($parts['scheme'] ?? '') === 'https'
        && in_array($host, ['discord.com', 'www.discord.com', 'discordapp.com'], true)
        && preg_match('#^/api/webhooks/[^/]+/[^/]+/?$#', $path) === 1;
}

function publicSettings(array $record, array $secrets): array
{
    $payload = jsonColumn($record['public_payload'] ?? null, [
        'discord' => [
            'images' => ['enabled' => false],
            'dice' => ['enabled' => false],
            'journal' => ['enabled' => false],
        ],
    ]);
    foreach (['images', 'dice', 'journal'] as $target) {
        $payload['discord'][$target] = [
            'enabled' => (bool) ($payload['discord'][$target]['enabled'] ?? false),
            'configured' => isset($secrets['discord'][$target]) && $secrets['discord'][$target] !== '',
        ];
    }
    return [
        'revision' => (int) ($record['revision'] ?? 0),
        'discord' => $payload['discord'],
        'storage' => ['authority' => 'online', 'origin' => 'https://regie-xar-tsaroth.fr'],
    ];
}

function readOnlineSettings(PDO $connection, array $configuration): array
{
    $record = settingsRecord($connection) ?? ['revision' => 0, 'public_payload' => '{}'];
    return publicSettings($record, decryptSettingsSecrets($configuration, $record));
}

function readOnlineDiscordStatus(PDO $connection, array $configuration, bool $headOnly = false): never
{
    requireGmIdentity($connection);
    $settings = readOnlineSettings($connection, $configuration);
    sendJson(200, ['ok' => true, 'discord' => $settings['discord']], $headOnly);
}

function updateOnlineSettings(PDO $connection, array $configuration): never
{
    $identity = requireAdministratorIdentity($connection);
    requireWritableSettingsEncryption($configuration);
    $payload = readJsonBody(65536);
    $connection->beginTransaction();
    try {
        $record = settingsRecord($connection, true) ?? ['revision' => 0, 'public_payload' => '{}'];
        $public = jsonColumn($record['public_payload'] ?? null);
        $secrets = decryptSettingsSecrets($configuration, $record);
        $public['discord'] ??= [];
        $secrets['discord'] ??= [];
        foreach (['images', 'dice', 'journal'] as $target) {
            $entry = $payload['discord'][$target] ?? [];
            if (!is_array($entry)) {
                throw new InvalidArgumentException('Réglage Discord invalide.');
            }
            $enabled = ($entry['enabled'] ?? false) === true;
            $value = trim((string) ($entry['value'] ?? ''));
            if ($value !== '' && !validDiscordWebhook($value)) {
                throw new InvalidArgumentException('Le webhook Discord est invalide.');
            }
            if ($value !== '') {
                $secrets['discord'][$target] = $value;
            }
            $public['discord'][$target] = ['enabled' => $enabled];
        }
        writeSettingsRecord($connection, $configuration, $record, $public, $secrets, (string) $identity['id']);
        $connection->commit();
    } catch (InvalidArgumentException $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        sendError(400, $error->getMessage(), 'invalid_settings');
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
    sendJson(200, ['ok' => true, 'settings' => readOnlineSettings($connection, $configuration)]);
}

function normalizedBridgeConversationUrl(mixed $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }
    $parts = parse_url($raw);
    $path = (string) ($parts['path'] ?? '');
    if (($parts['scheme'] ?? '') !== 'https' || strtolower((string) ($parts['host'] ?? '')) !== 'chatgpt.com'
        || preg_match('#(?:^|/)c/[A-Za-z0-9_-]+#', $path, $match, PREG_OFFSET_CAPTURE) !== 1) {
        throw new InvalidArgumentException('Une conversation ChatGPT est invalide.');
    }
    $matched = (string) $match[0][0];
    $offset = (int) $match[0][1];
    return 'https://chatgpt.com' . substr($path, 0, $offset + strlen($matched));
}

function cleanBridgeConversations(mixed $value): array
{
    $fallback = [
        'characters' => ['id' => 'characters', 'label' => 'Personnages & portraits', 'url' => ''],
        'scenes' => ['id' => 'scenes', 'label' => 'Scènes, décors & cartes', 'url' => ''],
    ];
    $incoming = [];
    if (is_array($value)) {
        foreach ($value as $entry) {
            if (is_array($entry) && isset($fallback[(string) ($entry['id'] ?? '')])) {
                $incoming[(string) $entry['id']] = $entry;
            }
        }
    }
    foreach ($fallback as $id => &$entry) {
        $candidate = $incoming[$id] ?? [];
        $entry['label'] = substr(trim((string) ($candidate['label'] ?? $entry['label'])), 0, 80) ?: $entry['label'];
        $entry['url'] = normalizedBridgeConversationUrl($candidate['url'] ?? '');
    }
    unset($entry);
    return array_values($fallback);
}

function bridgeSettingsPayload(array $record, array $secrets): array
{
    $public = jsonColumn($record['public_payload'] ?? null);
    $pairingToken = (string) ($secrets['chatgptBridge']['pairingToken'] ?? '');
    return [
        'pairingToken' => $pairingToken,
        'conversations' => cleanBridgeConversations($public['chatgptBridge']['conversations'] ?? []),
    ];
}

function readBridgeSettings(PDO $connection, array $configuration, array $identity): array
{
    $record = settingsRecord($connection) ?? ['revision' => 0, 'public_payload' => '{}'];
    $secrets = decryptSettingsSecrets($configuration, $record);
    $current = bridgeSettingsPayload($record, $secrets);
    if (preg_match('/^[a-f0-9]{64}$/', $current['pairingToken']) === 1) {
        return $current;
    }
    requireWritableSettingsEncryption($configuration);
    $connection->beginTransaction();
    try {
        $record = settingsRecord($connection, true) ?? ['revision' => 0, 'public_payload' => '{}'];
        $public = jsonColumn($record['public_payload'] ?? null);
        $secrets = decryptSettingsSecrets($configuration, $record);
        $secrets['chatgptBridge']['pairingToken'] ??= bin2hex(random_bytes(32));
        $public['chatgptBridge']['conversations'] = cleanBridgeConversations($public['chatgptBridge']['conversations'] ?? []);
        writeSettingsRecord($connection, $configuration, $record, $public, $secrets, (string) $identity['id']);
        $connection->commit();
        return bridgeSettingsPayload(['public_payload' => $public], $secrets);
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
}

function updateBridgeSettings(PDO $connection, array $configuration): never
{
    $identity = requireGmIdentity($connection);
    requireWritableSettingsEncryption($configuration);
    $payload = readJsonBody(65536);
    try {
        $conversations = cleanBridgeConversations($payload['conversations'] ?? []);
        $pairingToken = strtolower(trim((string) ($payload['pairingToken'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $pairingToken) !== 1) {
            throw new InvalidArgumentException('Le code d’appairage du pont est invalide.');
        }
    } catch (InvalidArgumentException $error) {
        sendError(400, $error->getMessage(), 'invalid_bridge_settings');
    }
    $connection->beginTransaction();
    try {
        $record = settingsRecord($connection, true) ?? ['revision' => 0, 'public_payload' => '{}'];
        $public = jsonColumn($record['public_payload'] ?? null);
        $secrets = decryptSettingsSecrets($configuration, $record);
        $public['chatgptBridge']['conversations'] = $conversations;
        $secrets['chatgptBridge']['pairingToken'] = $pairingToken;
        writeSettingsRecord($connection, $configuration, $record, $public, $secrets, (string) $identity['id']);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
    sendJson(200, ['ok' => true, 'bridge' => bridgeSettingsPayload(['public_payload' => $public], $secrets)]);
}

function legacyApplicationStateRecord(PDO $connection, bool $forUpdate = false): ?array
{
    $statement = $connection->query(
        'SELECT schema_version, revision, payload FROM application_state WHERE singleton_id = 1'
        . ($forUpdate ? ' FOR UPDATE' : '')
    );
    $record = $statement === false ? false : $statement->fetch();
    if (!is_array($record)) {
        return null;
    }
    return [
        'schemaVersion' => (int) $record['schema_version'],
        'revision' => (int) $record['revision'],
        'state' => jsonColumn($record['payload'] ?? null),
    ];
}

function stateContainsForbiddenSecret(mixed $value, int $depth = 0): bool
{
    if ($depth > 64) {
        return true;
    }
    if (!is_array($value)) {
        return false;
    }
    foreach ($value as $key => $child) {
        if (is_string($key) && preg_match('/^(?:password|passwordVerifier|sessionToken|accessToken|refreshToken|pairingToken|authenticationToken|webhook(?:Url)?|apiKey|clientSecret|secretKey|privateKey)$/i', $key) === 1) {
            return true;
        }
        if (stateContainsForbiddenSecret($child, $depth + 1)) {
            return true;
        }
    }
    return false;
}

function stripForbiddenPlayerData(mixed $value, int $depth = 0): mixed
{
    if ($depth > 64) {
        return null;
    }
    if (!is_array($value)) {
        return $value;
    }
    $clean = [];
    foreach ($value as $key => $child) {
        if (is_string($key) && preg_match('/^(?:secret|gmNotes|password|passwordVerifier|sessionToken|accessToken|refreshToken|pairingToken|authenticationToken|webhook(?:Url)?|apiKey|clientSecret|secretKey|privateKey)$/i', $key) === 1) {
            continue;
        }
        $clean[$key] = stripForbiddenPlayerData($child, $depth + 1);
    }
    return $clean;
}

function normalizePersistedImageReference(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    $reference = trim((string) $value);
    if ($reference === '' || strlen($reference) > 2_000_000 || preg_match('/[\x00\r\n]/', $reference) === 1) {
        return null;
    }
    if (preg_match('#^/media/[A-Za-z0-9_-]{24}$#', $reference) === 1) {
        return $reference;
    }
    if (preg_match('#^data:image/(?:png|jpeg|webp|gif);base64,[A-Za-z0-9+/]+={0,2}$#i', $reference) === 1) {
        return $reference;
    }
    if (strlen($reference) > 2048) {
        return null;
    }
    $parts = parse_url($reference);
    if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || strtolower((string) ($parts['host'] ?? '')) !== 'regie-xar-tsaroth.fr' || isset($parts['port'])
        || isset($parts['user']) || isset($parts['pass'])) {
        return null;
    }
    return $reference;
}

function sanitizeStateImageReferences(mixed $value, ?string $field = null, int $depth = 0): mixed
{
    if ($depth > 64) {
        return null;
    }
    if ($field === 'portrait' || $field === 'image' || $field === 'background') {
        return normalizePersistedImageReference($value);
    }
    if (!is_array($value)) {
        return $value;
    }
    $sanitized = [];
    foreach ($value as $key => $child) {
        $sanitized[$key] = sanitizeStateImageReferences($child, is_string($key) ? $key : null, $depth + 1);
    }
    return $sanitized;
}

function visibleCharacter(array $character): array
{
    $visible = stripForbiddenPlayerData($character);
    return is_array($visible) ? $visible : [];
}

function liveOnlinePresence(PDO $connection): array
{
    $statement = $connection->query(
        'SELECT a.id AS account_id, a.display_name, s.effective_mode, '
        . 'MIN(c.opened_at) AS connected_at, COUNT(*) AS connection_count '
        . 'FROM live_connections c '
        . 'JOIN auth_sessions s ON s.token_hash = c.session_token_hash '
        . 'JOIN accounts a ON a.id = s.account_id '
        . 'WHERE c.expires_at > UTC_TIMESTAMP(3) AND s.expires_at > UTC_TIMESTAMP(3) '
        . 'AND a.revoked_at IS NULL AND a.auth_revision = s.auth_revision '
        . 'GROUP BY a.id, a.display_name, s.effective_mode '
        . 'ORDER BY a.display_name, s.effective_mode'
    );
    $rows = $statement === false ? [] : $statement->fetchAll();
    return array_map(static fn (array $row): array => [
        'accountId' => (string) $row['account_id'],
        'displayName' => (string) $row['display_name'],
        'mode' => (string) $row['effective_mode'],
        'connectedAt' => (string) $row['connected_at'],
        'connections' => (int) $row['connection_count'],
    ], $rows);
}

function normalizeOnlineTokenFrameVariant(mixed $value, bool $playerControlled = false): string
{
    $variant = is_string($value) ? strtolower(trim($value)) : '';
    return in_array($variant, ['player', 'creature', 'elite', 'boss', 'apostle'], true)
        ? $variant
        : ($playerControlled ? 'player' : 'creature');
}

function publicPlayerState(array $fullState, array $identity, array $presence): array
{
    $accountId = (string) $identity['id'];
    $storedPreferences = is_array($fullState['playerPreferences'][$accountId] ?? null)
        ? $fullState['playerPreferences'][$accountId]
        : [];
    $preferences = [
        'musicMuted' => ($storedPreferences['musicMuted'] ?? false) === true,
        'ambienceMuted' => ($storedPreferences['ambienceMuted'] ?? false) === true,
        'activePage' => ($storedPreferences['activePage'] ?? '') === 'characters' ? 'characters' : 'map',
        'activeCharacterId' => is_string($storedPreferences['activeCharacterId'] ?? null)
            ? (string) $storedPreferences['activeCharacterId'] : null,
    ];
    $paused = (bool) ($fullState['tacticalSync']['paused'] ?? false);
    $map = $paused && is_array($fullState['tacticalSync']['publishedMap'] ?? null)
        ? $fullState['tacticalSync']['publishedMap']
        : ($fullState['map'] ?? []);
    $initiative = $paused && is_array($fullState['tacticalSync']['publishedInitiative'] ?? null)
        ? $fullState['tacticalSync']['publishedInitiative']
        : ($fullState['initiative'] ?? []);
    $active = (bool) ($initiative['active'] ?? false);
    $order = is_array($initiative['order'] ?? null) ? $initiative['order'] : [];
    $activeTokenId = $active ? ($order[(int) ($initiative['currentIndex'] ?? 0)] ?? null) : null;
    $movementOverrides = is_array($initiative['movementOverrides'] ?? null) ? $initiative['movementOverrides'] : [];
    $tokens = [];
    foreach (($map['tokens'] ?? []) as $token) {
        if (!is_array($token) || ($token['hidden'] ?? false) === true) {
            continue;
        }
        $owned = ($token['controllerPlayerId'] ?? null) === $accountId;
        $allied = is_string($token['controllerPlayerId'] ?? null) && (string) $token['controllerPlayerId'] !== '';
        $details = $allied || ($token['revealDetailsToPlayers'] ?? false) === true;
        $temporaryMovementAllowed = $active && ($movementOverrides[(string) ($token['id'] ?? '')] ?? false) === true;
        $visible = [
            'id' => $token['id'] ?? null,
            'characterId' => $token['characterId'] ?? null,
            'name' => $token['name'] ?? 'Token',
            'image' => $token['image'] ?? null,
            'color' => $token['color'] ?? '#8d72cb',
            'frameVariant' => normalizeOnlineTokenFrameVariant($token['frameVariant'] ?? null, $allied),
            'x' => (float) ($token['x'] ?? 50),
            'y' => (float) ($token['y'] ?? 50),
            'size' => (float) ($token['size'] ?? 30),
            'initiative' => $token['initiative'] ?? null,
            'condition' => substr((string) ($token['condition'] ?? ''), 0, 200),
            'detailsVisible' => $details,
            'ownedByYou' => $owned,
            'playerControlled' => $allied,
            'temporaryMovementAllowed' => $temporaryMovementAllowed,
            'controllable' => $owned && !$paused && (!$active || ($token['id'] ?? null) === $activeTokenId || $temporaryMovementAllowed),
        ];
        if ($details) {
            foreach (['hp', 'maxHp', 'mana', 'maxMana', 'damageDice', 'hitThreshold', 'armor', 'speed', 'stats', 'abilities', 'initiativeBonus', 'bonuses', 'penalties', 'notes', 'resourcePulse'] as $key) {
                if (array_key_exists($key, $token)) {
                    $visible[$key] = $token[$key];
                }
            }
        }
        $tokens[] = $visible;
    }
    $visibleIds = array_flip(array_map(static fn (array $token): string => (string) $token['id'], $tokens));
    $visibleOrder = array_values(array_filter($order, static fn (mixed $id): bool => isset($visibleIds[(string) $id])));
    $characters = is_array($fullState['characters'] ?? null) ? $fullState['characters'] : [];
    $myCharacters = [];
    $party = [];
    foreach ($characters as $character) {
        if (!is_array($character)) {
            continue;
        }
        $party[] = [
            'id' => $character['id'] ?? null,
            'ownerPlayerId' => $character['ownerPlayerId'] ?? null,
            'name' => $character['name'] ?? 'Personnage',
            'portrait' => $character['portrait'] ?? null,
            'color' => $character['color'] ?? '#8d72cb',
        ];
        if (($character['ownerPlayerId'] ?? null) === $accountId) {
            $myCharacters[] = visibleCharacter($character);
        }
    }
    $rolls = array_values(array_filter($fullState['rolls'] ?? [], static fn (mixed $roll): bool =>
        is_array($roll) && (($roll['visibility'] ?? '') === 'public' || ($roll['revealed'] ?? false) === true)
    ));
    $map = stripForbiddenPlayerData($map);
    $initiative = stripForbiddenPlayerData($initiative);
    $map = is_array($map) ? $map : [];
    $initiative = is_array($initiative) ? $initiative : [];
    unset($initiative['movementOverrides']);
    $map['tokens'] = $tokens;
    $initiative['order'] = $visibleOrder;
    $initiative['currentTokenId'] = in_array($activeTokenId, $visibleOrder, true) ? $activeTokenId : null;
    $initiative['currentIndex'] = $initiative['currentTokenId'] === null ? 0 : array_search($activeTokenId, $visibleOrder, true);
    $visibleSceneId = '';
    if ($paused) {
        $publishedScene = $fullState['tacticalSync']['publishedActiveScene'] ?? null;
        if (is_array($publishedScene) && is_string($publishedScene['id'] ?? null)) {
            $visibleSceneId = (string) $publishedScene['id'];
        }
    } elseif (is_string($fullState['activeSceneId'] ?? null)) {
        $visibleSceneId = (string) $fullState['activeSceneId'];
    } elseif (is_array($fullState['activeScene'] ?? null) && is_string($fullState['activeScene']['id'] ?? null)) {
        $visibleSceneId = (string) $fullState['activeScene']['id'];
    }
    $visibleActionTimers = [];
    foreach (is_array($fullState['actionTimers'] ?? null) ? $fullState['actionTimers'] : [] as $timer) {
        if (!is_array($timer) || (string) ($timer['sceneId'] ?? '') !== $visibleSceneId) {
            continue;
        }
        $owned = ($timer['ownerPlayerId'] ?? null) === $accountId;
        if (($timer['visibility'] ?? '') !== 'public' && !$owned) {
            continue;
        }
        $visibleActionTimers[] = [
            'id' => $timer['id'] ?? null,
            'label' => $timer['label'] ?? 'Action',
            'cooldown' => (int) ($timer['cooldown'] ?? 1),
            'usedRound' => (int) ($timer['usedRound'] ?? 1),
            'readyRound' => (int) ($timer['readyRound'] ?? 1),
            'ownerLabel' => $timer['ownerLabel'] ?? 'Personnage',
            'visibility' => ($timer['visibility'] ?? '') === 'public' ? 'public' : 'private',
            'ownedByYou' => $owned,
        ];
    }
    $nowMilliseconds = (int) floor(microtime(true) * 1000);
    $visibleMapPings = [];
    foreach (is_array($fullState['mapPings'] ?? null) ? $fullState['mapPings'] : [] as $ping) {
        if (!is_array($ping)
            || (string) ($ping['sceneId'] ?? '') !== $visibleSceneId
            || (int) ($ping['expiresAt'] ?? 0) <= $nowMilliseconds) {
            continue;
        }
        $visibleMapPings[] = [
            'id' => $ping['id'] ?? null,
            'x' => (float) ($ping['x'] ?? 0),
            'y' => (float) ($ping['y'] ?? 0),
            'author' => $ping['author'] ?? 'MJ',
            'color' => $ping['color'] ?? '#8d72cb',
            'expiresAt' => (int) ($ping['expiresAt'] ?? 0),
        ];
    }
    return [
        'schemaVersion' => $fullState['schemaVersion'] ?? 1,
        'revision' => $fullState['revision'] ?? 0,
        'updatedAt' => $fullState['updatedAt'] ?? null,
        'session' => stripForbiddenPlayerData($fullState['session'] ?? []),
        'party' => $party,
        'presence' => $presence,
        'myPlayer' => ['id' => $accountId, 'name' => (string) $identity['display_name'], 'role' => 'player'],
        'preferences' => $preferences,
        'myCharacters' => $myCharacters,
        'characterTombstones' => array_values(array_filter(
            is_array($fullState['characterTombstones'] ?? null) ? $fullState['characterTombstones'] : [],
            static fn (mixed $entry): bool => is_array($entry)
                && (!isset($entry['ownerPlayerId']) || (string) $entry['ownerPlayerId'] === $accountId)
        )),
        'tacticalLocked' => $paused,
        'playerMovementMode' => $active ? 'combat' : 'free',
        'map' => $map,
        'initiative' => $initiative,
        'activeScene' => stripForbiddenPlayerData($paused
            ? ($fullState['tacticalSync']['publishedActiveScene'] ?? null)
            : ($fullState['activeScene'] ?? null)),
        'rolls' => stripForbiddenPlayerData(array_slice($rolls, 0, 30)),
        'actionTimers' => $visibleActionTimers,
        'mapPings' => $visibleMapPings,
        'tracks' => stripForbiddenPlayerData($fullState['tracks'] ?? []),
        'audio' => stripForbiddenPlayerData($fullState['audio'] ?? null),
    ];
}

function requestedOnlineStateRevision(): ?int
{
    if (!array_key_exists('since', $_GET)) {
        return null;
    }
    $value = $_GET['since'];
    if (!is_string($value) && !is_int($value)) {
        sendError(400, 'Révision conditionnelle invalide.', 'invalid_since_revision');
    }
    $raw = trim((string) $value);
    if (preg_match('/^(?:0|[1-9][0-9]{0,18})$/D', $raw) !== 1) {
        sendError(400, 'Révision conditionnelle invalide.', 'invalid_since_revision');
    }
    $revision = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($revision === false) {
        sendError(400, 'Révision conditionnelle invalide.', 'invalid_since_revision');
    }
    return (int) $revision;
}

function readOnlineState(PDO $connection, bool $headOnly = false): never
{
    $identity = requireIdentity($connection);
    // Certains comptes ont été ajoutés une seconde fois au roster avant que
    // l'ancien propriétaire player-... ne soit rapproché du compte authentifié.
    // La réparation doit précéder le chemin `since`, sinon un joueur déjà
    // connecté peut recevoir "unchanged" indéfiniment et ne jamais revoir ses
    // fiches. Une lecture HEAD reste strictement sans effet de bord.
    if (!$headOnly) {
        repairOnlineRosterOwnershipsOnRead($connection, $identity);
    }
    $since = requestedOnlineStateRevision();
    if ($since !== null) {
        $clock = domainClockRecord($connection);
        if ($clock['initializedAt'] === null) {
            ensureDomainStoreInitialized($connection);
            $clock = domainClockRecord($connection);
        }
        if ((int) $clock['globalRevision'] <= $since) {
            sendJson(200, [
                'ok' => true,
                'unchanged' => true,
                'state' => null,
                'revision' => (int) $clock['globalRevision'],
            ], $headOnly);
        }
    }
    $record = (string) $identity['effective_mode'] === 'gm'
        ? domainApplicationStateRecord($connection)
        : playerApplicationStateRecord($connection);
    $presence = liveOnlinePresence($connection);
    if (!is_array($record) || $record['state'] === []) {
        sendJson(200, ['ok' => true, 'state' => null, 'revision' => 0, 'presence' => $presence], $headOnly);
    }
    $state = sanitizeStateImageReferences($record['state']);
    $state['revision'] = $record['revision'];
    $visible = (string) $identity['effective_mode'] === 'gm'
        ? $state
        : publicPlayerState($state, $identity, $presence);
    sendJson(200, ['ok' => true, 'state' => $visible, 'revision' => $record['revision'], 'presence' => $presence], $headOnly);
}

function rejectLegacyOnlineState(PDO $connection): never
{
    requireGmIdentity($connection);
    sendJson(426, [
        'ok' => false,
        'error' => 'Cette version utilise encore l’ancien état global. Installez une version compatible avec les documents révisionnés depuis Microsoft Store dès qu’elle y est disponible.',
        'code' => 'domain_client_required',
        'minimumVersion' => '1.16.0',
        'latestVersion' => '1.16.0',
        'storeId' => '9N5N5M67N704',
    ]);
}

function findEntryIndex(array $entries, string $id): int
{
    foreach ($entries as $index => $entry) {
        if (is_array($entry) && (string) ($entry['id'] ?? '') === $id) {
            return (int) $index;
        }
    }
    return -1;
}

function rosterAliases(array $identity): array
{
    $normalize = static fn (mixed $value): string => strtolower(trim((string) $value));
    $aliases = array_filter([
        $normalize($identity['display_name'] ?? ''),
        $normalize($identity['username'] ?? ''),
    ]);
    if ($normalize($identity['username'] ?? '') === 'innota') {
        $aliases[] = 'inho';
    }
    return array_values(array_unique($aliases));
}

function rosterRepairAliases(array $identity): array
{
    return rosterAliases($identity);
}

function rosterLegacyIdForAlias(string $alias): string
{
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($alias))), '-');
    return $slug === '' ? '' : 'player-' . $slug;
}

function rosterMigrationCandidateIndex(
    array $players,
    string $accountId,
    array $identity,
    bool $accountAlreadyPresent
): int {
    $aliases = $accountAlreadyPresent ? rosterRepairAliases($identity) : rosterAliases($identity);
    $candidates = [];
    foreach ($players as $index => $player) {
        if (!is_array($player)) {
            continue;
        }
        $name = strtolower(trim((string) ($player['name'] ?? '')));
        $id = strtolower(trim((string) ($player['id'] ?? '')));
        if ($id === strtolower($accountId)) {
            continue;
        }
        foreach ($aliases as $alias) {
            $legacyId = rosterLegacyIdForAlias($alias);
            $matchesDeterministicId = $legacyId !== '' && $id === $legacyId;
            $matchesUniqueName = $name === $alias;
            if ($matchesDeterministicId || $matchesUniqueName) {
                $candidates[(int) $index] = true;
                break;
            }
        }
    }
    return count($candidates) === 1 ? (int) array_key_first($candidates) : -1;
}

function onlineRosterOwnershipRepairVersion(array $roster): int
{
    return max(0, (int) ($roster['_ownershipRepairVersion'] ?? 0));
}

function onlineIdentityLegacyOwnerId(array $records, string $accountId, array $identity): string
{
    $roster = applicationDomainPayload($records, 'roster');
    $players = is_array($roster['players'] ?? null) ? $roster['players'] : [];
    $aliases = rosterRepairAliases($identity);
    $knownPlayerIds = [];
    foreach ($players as $player) {
        if (is_array($player) && (string) ($player['id'] ?? '') !== '') {
            $knownPlayerIds[(string) $player['id']] = true;
        }
    }

    $ownerCharacterCounts = [];
    $characterNameOwners = [];
    foreach ($records as $key => $record) {
        if (!str_starts_with($key, 'character:')) {
            continue;
        }
        $character = applicationDomainPayload($records, $key);
        $ownerPlayerId = (string) ($character['ownerPlayerId'] ?? '');
        if ($ownerPlayerId === '' || $ownerPlayerId === $accountId) {
            continue;
        }
        $ownerCharacterCounts[$ownerPlayerId] = ($ownerCharacterCounts[$ownerPlayerId] ?? 0) + 1;
        $name = strtolower(trim((string) ($character['name'] ?? '')));
        if (in_array($name, $aliases, true)) {
            $characterNameOwners[$ownerPlayerId] = true;
        }
    }

    $candidates = [];
    $rosterIndex = rosterMigrationCandidateIndex($players, $accountId, $identity, true);
    if ($rosterIndex >= 0) {
        $rosterOwnerId = (string) ($players[$rosterIndex]['id'] ?? '');
        if (($ownerCharacterCounts[$rosterOwnerId] ?? 0) > 0) {
            $candidates[$rosterOwnerId] = true;
        }
    }
    foreach (array_keys($characterNameOwners) as $ownerPlayerId) {
        if (isset($knownPlayerIds[$ownerPlayerId])) {
            $candidates[$ownerPlayerId] = true;
        }
    }
    return count($candidates) === 1 ? (string) array_key_first($candidates) : '';
}

function onlineRosterOwnershipProposals(array $records, array $accounts): array
{
    $accountIds = [];
    foreach ($accounts as $account) {
        if (is_array($account) && (string) ($account['id'] ?? '') !== '') {
            $accountIds[(string) $account['id']] = true;
        }
    }

    $characterCounts = [];
    foreach ($records as $key => $record) {
        if (!str_starts_with($key, 'character:')) {
            continue;
        }
        $ownerPlayerId = (string) (applicationDomainPayload($records, $key)['ownerPlayerId'] ?? '');
        if ($ownerPlayerId !== '') {
            $characterCounts[$ownerPlayerId] = ($characterCounts[$ownerPlayerId] ?? 0) + 1;
        }
    }

    $proposals = [];
    $claims = [];
    foreach ($accounts as $account) {
        if (!is_array($account)) {
            continue;
        }
        $accountId = (string) ($account['id'] ?? '');
        if ($accountId === '' || ($characterCounts[$accountId] ?? 0) > 0) {
            continue;
        }
        $candidateIdentity = [
            'id' => $accountId,
            'username' => (string) ($account['username'] ?? ''),
            'display_name' => (string) ($account['display_name'] ?? ''),
        ];
        $oldId = onlineIdentityLegacyOwnerId($records, $accountId, $candidateIdentity);
        if ($oldId === '' || $oldId === $accountId || isset($accountIds[$oldId])
            || ($characterCounts[$oldId] ?? 0) === 0) {
            continue;
        }
        $proposals[$accountId] = ['oldId' => $oldId, 'identity' => $candidateIdentity];
        $claims[$oldId][] = $accountId;
    }
    foreach ($proposals as $accountId => $proposal) {
        if (count($claims[(string) $proposal['oldId']] ?? []) !== 1) {
            unset($proposals[$accountId]);
        }
    }
    return $proposals;
}

function queueOnlinePlayerOwnershipRepair(
    array &$pending,
    array $records,
    array &$roster,
    string $oldId,
    string $accountId,
    int $now
): void {
    $preferences = is_array($roster['playerPreferences'] ?? null) ? $roster['playerPreferences'] : [];
    if (is_array($preferences[$oldId] ?? null)) {
        $legacyPreferences = $preferences[$oldId];
        $accountPreferences = is_array($preferences[$accountId] ?? null) ? $preferences[$accountId] : [];
        $preferences[$accountId] = array_replace($legacyPreferences, $accountPreferences);
    }
    unset($preferences[$oldId]);
    $roster['playerPreferences'] = $preferences;
    foreach (['playerTombstones', 'characterTombstones'] as $listKey) {
        if (!is_array($roster[$listKey] ?? null)) {
            continue;
        }
        foreach ($roster[$listKey] as &$entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['ownerPlayerId'] ?? null) === $oldId) {
                $entry['ownerPlayerId'] = $accountId;
            }
            if ($listKey === 'playerTombstones' && ($entry['id'] ?? null) === $oldId) {
                $entry['id'] = $accountId;
            }
        }
        unset($entry);
    }
    foreach ($records as $key => $record) {
        if (str_starts_with($key, 'character:')) {
            $payload = applicationDomainPayload($records, $key);
            if (($payload['ownerPlayerId'] ?? null) === $oldId) {
                $payload['ownerPlayerId'] = $accountId;
                $payload['_updatedAt'] = $now;
                queueOnlineDomainUpsert($pending, $records, $key, $payload);
            }
        } elseif (str_starts_with($key, 'token:')) {
            $payload = applicationDomainPayload($records, $key);
            if (($payload['controllerPlayerId'] ?? null) === $oldId) {
                $payload['controllerPlayerId'] = $accountId;
                $payload['_updatedAt'] = $now;
                queueOnlineDomainUpsert($pending, $records, $key, $payload);
            }
        } elseif (str_starts_with($key, 'presentation:') || $key === 'detached-combat') {
            $payload = applicationDomainPayload($records, $key);
            if (!is_array($payload['map']['tokens'] ?? null)) {
                continue;
            }
            $changed = false;
            foreach ($payload['map']['tokens'] as &$token) {
                if (is_array($token) && ($token['controllerPlayerId'] ?? null) === $oldId) {
                    $token['controllerPlayerId'] = $accountId;
                    $token['_updatedAt'] = $now;
                    $changed = true;
                }
            }
            unset($token);
            if ($changed) {
                queueOnlineDomainUpsert($pending, $records, $key, $payload);
            }
        }
    }
    $activity = applicationDomainPayload($records, 'activity');
    $activityChanged = false;
    foreach (is_array($activity['actionTimers'] ?? null) ? $activity['actionTimers'] : [] as $index => $timer) {
        if (is_array($timer) && ($timer['ownerPlayerId'] ?? null) === $oldId) {
            $activity['actionTimers'][$index]['ownerPlayerId'] = $accountId;
            $activity['actionTimers'][$index]['updatedAt'] = gmdate('c');
            $activityChanged = true;
        }
    }
    if ($activityChanged) {
        queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
    }
}

function repairOnlineRosterOwnershipsOnRead(PDO $connection, array $identity): int
{
    // Une seule réconciliation globale par version. Le marqueur reste dans le
    // roster et n'impose ni migration SQL ni révocation des sessions.
    ensureDomainStoreInitialized($connection);
    $rosterRecords = applicationDomainRecords($connection, ['roster']);
    $roster = applicationDomainPayload($rosterRecords, 'roster');
    if (onlineRosterOwnershipRepairVersion($roster) >= XAR_IDENTITY_OWNERSHIP_REPAIR_VERSION) {
        return 0;
    }

    $repaired = 0;
    $changed = false;
    $connection->beginTransaction();
    try {
        $clock = domainClockRecord($connection, true);
        $records = applicationDomainRecords($connection);
        $roster = applicationDomainPayload($records, 'roster', [
            'players' => [], 'characterOrder' => [], 'playerPreferences' => [],
            'playerTombstones' => [], 'characterTombstones' => [],
        ]);
        if (onlineRosterOwnershipRepairVersion($roster) < XAR_IDENTITY_OWNERSHIP_REPAIR_VERSION) {
            $accountsStatement = $connection->query(
                'SELECT id, username, display_name FROM accounts WHERE revoked_at IS NULL ORDER BY id'
            );
            $accounts = $accountsStatement === false ? [] : $accountsStatement->fetchAll();
            // Préparer toutes les propositions avant d'écrire empêche deux
            // comptes homonymes de réclamer le même propriétaire historique.
            $proposals = onlineRosterOwnershipProposals($records, $accounts);
            $pending = [];
            $players = is_array($roster['players'] ?? null) ? $roster['players'] : [];
            $now = (int) floor(microtime(true) * 1000);
            foreach ($proposals as $accountId => $proposal) {
                $oldId = (string) $proposal['oldId'];
                $pendingIndex = findEntryIndex($players, $oldId);
                if ($pendingIndex < 0) {
                    continue;
                }
                $accountIndex = findEntryIndex($players, (string) $accountId);
                $displayName = (string) ($proposal['identity']['display_name'] ?? 'Compte relié');
                if ($accountIndex < 0) {
                    $players[$pendingIndex]['id'] = (string) $accountId;
                    $players[$pendingIndex]['name'] = $displayName;
                    $players[$pendingIndex]['_updatedAt'] = $now;
                } else {
                    $players[$accountIndex]['name'] = $displayName;
                    $players[$accountIndex]['_updatedAt'] = $now;
                    array_splice($players, $pendingIndex, 1);
                }
                queueOnlinePlayerOwnershipRepair($pending, $records, $roster, $oldId, (string) $accountId, $now);
                $repaired++;
            }

            $roster['players'] = $players;
            $roster['_ownershipRepairVersion'] = XAR_IDENTITY_OWNERSHIP_REPAIR_VERSION;
            queueOnlineDomainUpsert($pending, $records, 'roster', $roster);
            if ($pending !== []) {
                persistDomainChangesInTransaction($connection, $identity, $clock, array_values($pending));
                $changed = true;
            }
        }
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
    if ($changed) {
        cleanupApplicationDomainHistory($connection);
    }
    return $repaired;
}

function cleanPlayerCharacter(array $character, string $accountId): array
{
    $character = playerCharacterPatch([], $character);
    $id = (string) ($character['id'] ?? '');
    if (preg_match('/^character-[A-Za-z0-9_-]{8,150}$/', $id) !== 1) {
        $id = 'character-' . randomToken(12);
    }
    $character['id'] = $id;
    $character['ownerPlayerId'] = $accountId;
    $character['_updatedAt'] = (int) floor(microtime(true) * 1000);
    return $character;
}

function validOnlineRollFormula(string $formula): bool
{
    $normalized = strtolower(str_replace(' ', '', $formula));
    if (strlen($normalized) > 100) {
        return false;
    }
    if ($normalized === '' || preg_match('/^[+-]?((\d*)d\d+|\d+)([+-]((\d*)d\d+|\d+))*$/D', $normalized) !== 1) {
        return false;
    }
    preg_match_all('/[+-]?[^+-]+/', $normalized, $matches);
    if (count($matches[0]) > 30) {
        return false;
    }
    foreach ($matches[0] as $term) {
        $clean = ltrim($term, '+-');
        if (str_contains($clean, 'd')) {
            [$countText, $sidesText] = explode('d', $clean, 2);
            $count = $countText === '' ? 1 : (int) $countText;
            $sides = (int) $sidesText;
            if ($count < 1 || $count > 100 || $sides < 2 || $sides > 1000) {
                return false;
            }
        } elseif (strlen($clean) > 10 || (int) $clean > 1000000000) {
            return false;
        }
    }
    return true;
}

function normalizeOnlineAbilities(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    $normalized = [];
    $seen = [];
    foreach (array_slice($value, 0, 200) as $index => $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $fallback = 'ability-' . ((int) $index + 1);
        $id = preg_replace('/[^A-Za-z0-9_-]+/', '-', substr((string) ($entry['id'] ?? $fallback), 0, 120)) ?: $fallback;
        if (isset($seen[$id])) {
            $id = substr($id, 0, 108) . '-' . ((int) $index + 1);
        }
        $seen[$id] = true;
        $name = substr(trim((string) ($entry['name'] ?? $entry['label'] ?? 'Nouvelle capacité')), 0, 120);
        $formula = strtolower(str_replace(' ', '', substr((string) ($entry['formula'] ?? $entry['damageFormula'] ?? '1d6'), 0, 100)));
        if (!validOnlineRollFormula($formula)) {
            $formula = '1d6';
        }
        $normalized[] = [
            'id' => $id,
            'name' => $name !== '' ? $name : 'Nouvelle capacité',
            'formula' => $formula,
            'description' => substr((string) ($entry['description'] ?? ''), 0, 2000),
        ];
    }
    return $normalized;
}

function normalizeOnlineD100Difficulty(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    return is_numeric($value) ? max(0, min(100, (int) $value)) : null;
}

function playerCharacterPatch(array $current, array $patch): array
{
    $allowed = [
        'name', 'surname', 'givenName', 'race', 'age', 'className', 'advancedClass', 'profession',
        'previousProfession', 'pronouns', 'portrait', 'color', 'resources', 'stats', 'fatigue', 'morale',
        'armor', 'speed', 'initiativeBonus', 'conditions', 'publicNotes', 'armorText', 'hitThreshold', 'weaponText',
        'passives', 'skills', 'specialSkills', 'languages', 'inventory', 'personalAdvantageStock',
        'shortcuts', 'abilities', 'linkedTokens',
    ];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $patch)) {
            if ($key === 'portrait') {
                $current[$key] = normalizePersistedImageReference($patch[$key]);
            } elseif ($key === 'abilities') {
                $current[$key] = normalizeOnlineAbilities($patch[$key]);
            } elseif ($key === 'hitThreshold') {
                $current[$key] = normalizeOnlineD100Difficulty($patch[$key]);
            } elseif ($key === 'linkedTokens') {
                $linked = stripForbiddenPlayerData($patch[$key]);
                $current[$key] = is_array($linked) ? array_map(static function (mixed $entry): mixed {
                    if (!is_array($entry)) {
                        return $entry;
                    }
                    $entry['image'] = normalizePersistedImageReference($entry['image'] ?? null);
                    return $entry;
                }, array_slice($linked, 0, 200)) : [];
            } else {
                $current[$key] = stripForbiddenPlayerData($patch[$key]);
            }
        }
    }
    $current['characterSchema'] = 'xar-tsaroth.character-sheet';
    $current['characterSchemaVersion'] = 3;
    $current['_updatedAt'] = (int) floor(microtime(true) * 1000);
    return $current;
}

function legacyWholePlayerCharacterPatch(array $patch): bool
{
    $signature = [
        'name', 'color', 'portrait', 'resources', 'stats', 'fatigue', 'armor', 'speed',
        'initiativeBonus', 'conditions', 'armorText', 'hitThreshold', 'weaponText',
        'inventory', 'shortcuts', 'abilities', 'linkedTokens',
    ];
    return count($patch) >= 24
        && count(array_intersect($signature, array_keys($patch))) === count($signature);
}

function playerCharacterPatchChangesCurrent(array $current, array $patch): bool
{
    $candidate = playerCharacterPatch($current, $patch);
    foreach (array_keys($patch) as $key) {
        $candidateValue = array_key_exists($key, $candidate) ? $candidate[$key] : null;
        $currentValue = array_key_exists($key, $current) ? $current[$key] : null;
        if (canonicalApplicationDomainValue($candidateValue) !== canonicalApplicationDomainValue($currentValue)) {
            return true;
        }
    }
    return false;
}

function onlineRollFormula(string $formula): array
{
    $normalized = strtolower(str_replace(' ', '', $formula));
    if (!validOnlineRollFormula($normalized)) {
        throw new InvalidArgumentException('Formule de jet invalide.');
    }
    preg_match_all('/[+-]?[^+-]+/', $normalized, $matches);
    $total = 0;
    $parts = [];
    $dice = [];
    foreach ($matches[0] as $term) {
        $sign = str_starts_with($term, '-') ? -1 : 1;
        $clean = ltrim($term, '+-');
        if (str_contains($clean, 'd')) {
            [$countText, $sidesText] = explode('d', $clean, 2);
            $count = $countText === '' ? 1 : (int) $countText;
            $sides = (int) $sidesText;
            if ($count < 1 || $count > 100 || $sides < 2 || $sides > 1000) {
                throw new InvalidArgumentException('Dés hors limites.');
            }
            $rolls = [];
            for ($index = 0; $index < $count; $index += 1) {
                $rolls[] = random_int(1, $sides);
            }
            $total += array_sum($rolls) * $sign;
            $parts[] = ($sign < 0 ? '−' : ($parts !== [] ? '+' : '')) . '[' . implode(', ', $rolls) . ']';
            $dice[] = ['count' => $count, 'sides' => $sides, 'sign' => $sign, 'results' => $rolls];
        } else {
            $value = (int) $clean * $sign;
            $total += $value;
            $parts[] = ($value >= 0 && $parts !== [] ? '+' : '') . (string) $value;
        }
    }
    $rawD100 = count($dice) === 1 && $dice[0]['count'] === 1 && $dice[0]['sides'] === 100 && $dice[0]['sign'] === 1
        ? (int) $dice[0]['results'][0]
        : null;
    return ['total' => $total, 'breakdown' => implode(' ', $parts), 'formula' => $normalized, 'rawD100' => $rawD100];
}

function normalizeOnlineRollMode(mixed $value): string
{
    $mode = (string) $value;
    return in_array($mode, ['advantage', 'disadvantage'], true) ? $mode : 'normal';
}

function onlineOutcomeDesirability(array $rolled, ?int $threshold, int $modifier): int
{
    $outcome = classifyOnlineD100Outcome($rolled['rawD100'] ?? null, $threshold, $modifier);
    return match ($outcome['code'] ?? '') {
        'critical-success' => 5,
        'special-success' => 4,
        'success' => 3,
        'failure' => 1,
        'critical-failure' => 0,
        default => 2,
    };
}

function onlineRollFormulaWithMode(string $formula, mixed $mode, ?int $threshold = null, int $modifier = 0): array
{
    $rollMode = normalizeOnlineRollMode($mode);
    $attempts = [onlineRollFormula($formula)];
    if ($rollMode !== 'normal') {
        $attempts[] = onlineRollFormula($formula);
    }
    $selectedIndex = 0;
    if (count($attempts) === 2) {
        $first = $attempts[0];
        $second = $attempts[1];
        if (is_int($first['rawD100'] ?? null) && is_int($second['rawD100'] ?? null)) {
            $firstRank = onlineOutcomeDesirability($first, $threshold, $modifier);
            $secondRank = onlineOutcomeDesirability($second, $threshold, $modifier);
            if ($firstRank !== $secondRank) {
                $selectedIndex = $rollMode === 'advantage'
                    ? ($secondRank > $firstRank ? 1 : 0)
                    : ($secondRank < $firstRank ? 1 : 0);
            } else {
                $selectedIndex = $rollMode === 'advantage'
                    ? ((int) $second['rawD100'] < (int) $first['rawD100'] ? 1 : 0)
                    : ((int) $second['rawD100'] > (int) $first['rawD100'] ? 1 : 0);
            }
        } else {
            $selectedIndex = $rollMode === 'advantage'
                ? ((int) $second['total'] > (int) $first['total'] ? 1 : 0)
                : ((int) $second['total'] < (int) $first['total'] ? 1 : 0);
        }
    }
    $selected = $attempts[$selectedIndex];
    $selected['rollMode'] = $rollMode;
    $selected['selectedIndex'] = $selectedIndex;
    $selected['attempts'] = array_map(static fn (array $attempt): array => [
        'total' => (int) $attempt['total'],
        'breakdown' => (string) $attempt['breakdown'],
        'rawD100' => $attempt['rawD100'] ?? null,
    ], $attempts);
    return $selected;
}

function rejectOnlineCommand(PDO $connection, int $status, string $message, string $code): never
{
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    sendError($status, $message, $code);
}

function queueOnlineDomainUpsert(array &$pending, array $records, string $key, mixed $payload): void
{
    $prepared = prepareApplicationDomainUpsert($key, $payload, $records[$key] ?? null);
    if ($prepared !== null) {
        $pending[$key] = $prepared;
    }
}

function queueOnlineDomainDelete(array &$pending, array $records, string $key): void
{
    $prepared = prepareApplicationDomainDelete($key, $records[$key] ?? null);
    if ($prepared !== null) {
        $pending[$key] = $prepared;
    }
}

function onlineActiveSceneId(array $table): string
{
    $sceneId = (string) ($table['activeSceneId'] ?? '');
    return validApplicationDomainKey('scene:' . $sceneId) ? $sceneId : '';
}

function onlineTokenDomainKey(string $sceneId, mixed $tokenId): string
{
    $key = 'token:' . $sceneId . ':' . trim((string) $tokenId);
    return validApplicationDomainKey($key) ? $key : '';
}

function onlineSceneTokenRecords(PDO $connection, string $sceneId, array $records = []): array
{
    $indexKey = 'token-index:' . $sceneId;
    if (!isset($records[$indexKey])) {
        $records = array_replace($records, applicationDomainRecords($connection, [$indexKey]));
    }
    $index = applicationDomainPayload($records, $indexKey, ['order' => []]);
    $keys = [];
    foreach (is_array($index['order'] ?? null) ? $index['order'] : [] as $tokenId) {
        $key = onlineTokenDomainKey($sceneId, $tokenId);
        if ($key !== '' && !isset($records[$key])) {
            $keys[] = $key;
        }
    }
    return $keys === [] ? $records : array_replace($records, applicationDomainRecords($connection, $keys));
}

function removeOnlineTokenIdsFromInitiative(array $initiative, array $tokenIds): array
{
    $removed = array_fill_keys(array_map('strval', $tokenIds), true);
    if ($removed === []) {
        return $initiative;
    }
    $order = is_array($initiative['order'] ?? null) ? array_values($initiative['order']) : [];
    $currentIndex = max(0, (int) ($initiative['currentIndex'] ?? 0));
    $currentId = isset($order[$currentIndex]) ? (string) $order[$currentIndex] : '';
    $nextOrder = array_values(array_filter(
        $order,
        static fn (mixed $id): bool => !isset($removed[(string) $id])
    ));
    if ($nextOrder === $order) {
        return $initiative;
    }
    $initiative['order'] = $nextOrder;
    if ($nextOrder === []) {
        $initiative['currentIndex'] = 0;
        $initiative['active'] = false;
        $initiative['turnsStarted'] = false;
    } else {
        $survivingIndex = $currentId !== '' ? array_search($currentId, $nextOrder, true) : false;
        $initiative['currentIndex'] = $survivingIndex === false
            ? min($currentIndex, count($nextOrder) - 1)
            : (int) $survivingIndex;
    }
    $initiative['_updatedAt'] = (int) floor(microtime(true) * 1000);
    return $initiative;
}

function removeOnlineCharacterFromCombat(array $combat, string $characterId, array $knownTokenIds = []): array
{
    $map = is_array($combat['map'] ?? null) ? $combat['map'] : [];
    $tokens = is_array($map['tokens'] ?? null) ? $map['tokens'] : [];
    $removed = array_fill_keys(array_map('strval', $knownTokenIds), true);
    $retained = [];
    foreach ($tokens as $token) {
        if (!is_array($token)) {
            continue;
        }
        $tokenId = (string) ($token['id'] ?? '');
        if ((string) ($token['characterId'] ?? '') === $characterId || isset($removed[$tokenId])) {
            if ($tokenId !== '') {
                $removed[$tokenId] = true;
            }
            continue;
        }
        $retained[] = $token;
    }
    if ($retained !== $tokens) {
        $map['tokens'] = $retained;
        $combat['map'] = $map;
    }
    if (is_array($combat['initiative'] ?? null) && $removed !== []) {
        $combat['initiative'] = removeOnlineTokenIdsFromInitiative($combat['initiative'], array_keys($removed));
    }
    return $combat;
}

function sortedOnlineInitiativeOrder(array $order, array $records, string $sceneId): array
{
    $prepared = [];
    foreach ($order as $tokenId) {
        $id = (string) $tokenId;
        $key = onlineTokenDomainKey($sceneId, $id);
        if ($key === '' || !isset($records[$key]) || isset($prepared[$id])) {
            continue;
        }
        $prepared[$id] = count($prepared);
    }
    $ids = array_keys($prepared);
    usort($ids, static function (string $leftId, string $rightId) use ($records, $sceneId, $prepared): int {
        $left = applicationDomainPayload($records, onlineTokenDomainKey($sceneId, $leftId));
        $right = applicationDomainPayload($records, onlineTokenDomainKey($sceneId, $rightId));
        $initiative = (int) ($right['initiative'] ?? -1) <=> (int) ($left['initiative'] ?? -1);
        return $initiative !== 0 ? $initiative : $prepared[$leftId] <=> $prepared[$rightId];
    });
    return $ids;
}

function synchronizeOnlineCharacterToken(array $token, array $character): array
{
    $updatedAt = (int) ($character['_updatedAt'] ?? floor(microtime(true) * 1000));
    $linkedId = (string) ($token['linkedTokenId'] ?? '');
    if ($linkedId !== '') {
        $linked = null;
        foreach (is_array($character['linkedTokens'] ?? null) ? $character['linkedTokens'] : [] as $entry) {
            if (is_array($entry) && (string) ($entry['id'] ?? '') === $linkedId) {
                $linked = $entry;
                break;
            }
        }
        if ($linked === null) {
            return $token;
        }
        foreach (['name', 'color', 'image', 'size'] as $key) {
            if (array_key_exists($key, $linked)) {
                $token[$key] = $linked[$key];
            }
        }
        $token['controllerPlayerId'] = $character['ownerPlayerId'] ?? null;
        $token['_updatedAt'] = $updatedAt;
        return $token;
    }
    if (($token['followCharacter'] ?? true) === false) {
        return $token;
    }
    if (trim((string) ($character['name'] ?? '')) !== '') {
        $token['name'] = (string) $character['name'];
    }
    if (trim((string) ($character['color'] ?? '')) !== '') {
        $token['color'] = (string) $character['color'];
    }
    if (($character['portrait'] ?? null) !== null) {
        $token['image'] = $character['portrait'];
    }
    $resources = is_array($character['resources'] ?? null) ? $character['resources'] : [];
    foreach (['hp', 'maxHp', 'mana', 'maxMana'] as $key) {
        if (is_numeric($resources[$key] ?? null)) {
            $token[$key] = (float) $resources[$key];
        }
    }
    foreach (['armor', 'speed', 'initiativeBonus'] as $key) {
        if (is_numeric($character[$key] ?? null)) {
            $token[$key] = (float) $character[$key];
        }
    }
    if (is_array($character['stats'] ?? null)) {
        $labels = [
            'force' => 'Force', 'dexterity' => 'Dextérité', 'agility' => 'Agilité',
            'spiritSocial' => 'Esprit / Social', 'intelligence' => 'Intelligence',
            'instinct' => 'Instinct / Perception',
        ];
        $token['stats'] = [];
        foreach ($character['stats'] as $key => $value) {
            $token['stats'][] = [
                'id' => 'character-stat-' . (string) $key,
                'label' => $labels[(string) $key] ?? (string) $key,
                'value' => (string) $value,
            ];
        }
    }
    $token['hitThreshold'] = normalizeOnlineD100Difficulty($character['hitThreshold'] ?? null);
    $token['abilities'] = normalizeOnlineAbilities($character['abilities'] ?? []);
    $token['_updatedAt'] = $updatedAt;
    return $token;
}

function normalizeOnlineD100Modifier(mixed $value): int
{
    return max(-100, min(100, is_numeric($value) ? (int) $value : 0));
}

function classifyOnlineD100Outcome(mixed $rawValue, mixed $threshold = null, mixed $modifier = 0): ?array
{
    if (!is_numeric($rawValue)) {
        return null;
    }
    $raw = (int) $rawValue;
    if ($raw < 1 || $raw > 100) {
        return null;
    }
    $baseThreshold = $threshold === null ? null : max(0, min(100, (int) $threshold));
    $appliedModifier = normalizeOnlineD100Modifier($modifier);
    $effectiveThreshold = $baseThreshold === null ? null : max(0, min(100, $baseThreshold + $appliedModifier));
    $common = [
        'raw' => $raw,
        'baseThreshold' => $baseThreshold,
        'modifier' => $appliedModifier,
        'threshold' => $effectiveThreshold,
    ];
    if (in_array($raw, [1, 11, 22, 33, 44], true)) {
        return [...$common, 'code' => 'critical-success', 'label' => 'RÉUSSITE CRITIQUE', 'success' => true, 'effect' => true];
    }
    if ($raw === 55) {
        return [...$common, 'code' => 'special-success', 'label' => 'RÉUSSITE SPÉCIALE', 'success' => true, 'effect' => true];
    }
    if (in_array($raw, [10, 66, 77, 88, 99], true)) {
        return [...$common, 'code' => 'critical-failure', 'label' => 'ÉCHEC CRITIQUE', 'success' => false, 'effect' => true];
    }
    if ($effectiveThreshold === null) {
        return null;
    }
    $success = $raw <= $effectiveThreshold;
    return [...$common, 'code' => $success ? 'success' : 'failure', 'label' => $success ? 'RÉUSSITE' : 'ÉCHEC', 'success' => $success, 'effect' => false];
}

function onlineRollEntry(array $identity, array $rolled, string $label, string $characterName, ?array $outcome = null): array
{
    $entry = [
        'id' => randomToken(12),
        'label' => substr($label, 0, 120),
        'characterName' => substr($characterName, 0, 120),
        'formula' => $rolled['formula'],
        'total' => $rolled['total'],
        'breakdown' => $rolled['breakdown'],
        'visibility' => 'public',
        'revealed' => true,
        'rollerName' => (string) $identity['display_name'],
        'rollerRole' => 'player',
        'createdAt' => gmdate('c'),
        'rollMode' => normalizeOnlineRollMode($rolled['rollMode'] ?? 'normal'),
        'selectedIndex' => max(0, min(1, (int) ($rolled['selectedIndex'] ?? 0))),
    ];
    if (($entry['rollMode'] ?? 'normal') !== 'normal') {
        $entry['attempts'] = $rolled['attempts'] ?? [];
    }
    if ($outcome !== null) {
        $entry['outcome'] = $outcome;
    }
    return $entry;
}

function safeOnlineDiscordLabel(mixed $value): string
{
    $singleLine = preg_replace('/[\r\n]+/', ' ', (string) $value) ?? 'Jet';
    return substr(preg_replace('/([*_`~|\\\\])/', '\\\\$1', $singleLine) ?? 'Jet', 0, 180);
}

function onlineDiscordRollContent(array $roll): string
{
    $actor = safeOnlineDiscordLabel($roll['rollerName'] ?? 'Joueur');
    $label = safeOnlineDiscordLabel(($roll['characterName'] ?? '') !== ''
        ? (string) $roll['characterName'] . ' · ' . (string) ($roll['label'] ?? 'Jet')
        : (string) ($roll['label'] ?? 'Jet'));
    $content = '🎲 **' . $actor . ' · ' . $label . '** — `' . (string) ($roll['formula'] ?? '') . '`'
        . "\nRésultat : **" . (string) ($roll['total'] ?? 0) . '** · ' . (string) ($roll['breakdown'] ?? '');
    $mode = normalizeOnlineRollMode($roll['rollMode'] ?? 'normal');
    $attempts = is_array($roll['attempts'] ?? null) ? $roll['attempts'] : [];
    if ($mode !== 'normal' && count($attempts) === 2) {
        $labelMode = $mode === 'advantage' ? 'Avantage' : 'Désavantage';
        $selectedIndex = max(0, min(1, (int) ($roll['selectedIndex'] ?? 0)));
        $values = [];
        foreach ($attempts as $index => $attempt) {
            $values[] = ($index === $selectedIndex ? 'retenu ' : '') . (string) ($attempt['total'] ?? 0);
        }
        $content .= "\n" . $labelMode . ' · ' . implode(' / ', $values);
    }
    $outcome = is_array($roll['outcome'] ?? null) ? $roll['outcome'] : null;
    if ($outcome !== null) {
        $content .= "\n**" . safeOnlineDiscordLabel($outcome['label'] ?? '') . '** · d100 brut **'
            . (string) ($outcome['raw'] ?? '') . '**';
        if (($outcome['threshold'] ?? null) !== null) {
            $modifier = (int) ($outcome['modifier'] ?? 0);
            $content .= $modifier !== 0
                ? ' · seuil ajusté **' . (string) $outcome['threshold'] . '** (base **'
                    . (string) ($outcome['baseThreshold'] ?? '') . '**, **' . ($modifier > 0 ? '+' : '') . (string) $modifier . '**)'
                : ' · seuil **' . (string) $outcome['threshold'] . '**';
        }
    }
    return substr($content, 0, 1900);
}

function tryPostOnlineDiscordText(PDO $connection, array $configuration, string $target, string $content): array
{
    try {
        $record = settingsRecord($connection);
        $public = jsonColumn($record['public_payload'] ?? null);
        $secrets = decryptSettingsSecrets($configuration, $record);
        $webhook = trim((string) ($secrets['discord'][$target] ?? ''));
        if (($public['discord'][$target]['enabled'] ?? false) !== true || !validDiscordWebhook($webhook)) {
            return ['posted' => false, 'error' => ''];
        }
        if (!function_exists('curl_init')) {
            return ['posted' => false, 'error' => 'Discord indisponible sur le serveur.'];
        }
        $request = curl_init($webhook . (str_contains($webhook, '?') ? '&' : '?') . 'wait=true');
        if ($request === false) {
            return ['posted' => false, 'error' => 'Connexion Discord indisponible.'];
        }
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'content' => substr(trim($content), 0, 1900),
                'allowed_mentions' => ['parse' => []],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        curl_setopt_array($request, $options);
        $response = curl_exec($request);
        $status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
        $failed = $response === false;
        curl_close($request);
        if ($failed || $status < 200 || $status >= 300) {
            return ['posted' => false, 'error' => 'Discord a refusé ou interrompu l’envoi.'];
        }
        return ['posted' => true, 'error' => ''];
    } catch (Throwable) {
        return ['posted' => false, 'error' => 'Publication Discord indisponible.'];
    }
}

function commandOnlineState(PDO $connection, array $configuration): never
{
    $identity = requireIdentity($connection);
    $body = readJsonBody(2 * 1024 * 1024);
    $command = trim((string) ($body['command'] ?? ''));
    $arguments = is_array($body['payload'] ?? null) ? $body['payload'] : [];
    $accountId = (string) $identity['id'];
    $isGm = (string) $identity['effective_mode'] === 'gm' && (string) $identity['permanent_role'] === 'gm';
    ensureDomainStoreInitialized($connection);
    $connection->beginTransaction();
    try {
        $clock = domainClockRecord($connection, true);
        $records = applicationDomainRecords($connection, ['table']);
        if (!isset($records['table'])) {
            rejectOnlineCommand($connection, 409, 'Aucune table en ligne n’a encore été ouverte par un MJ.', 'state_missing');
        }
        $table = applicationDomainPayload($records, 'table');
        $sceneId = onlineActiveSceneId($table);
        $pending = [];
        $result = [];

        if ($isGm && !in_array(
            $command,
            ['ensure-player', 'admin.character.delete', 'token.move', 'token.resource.adjust', 'ping'],
            true
        )) {
            rejectOnlineCommand($connection, 403, 'Cette commande est réservée au mode Joueur.', 'player_mode_required');
        }

        if ($command === 'ensure-player') {
            $records = applicationDomainRecords($connection);
            $roster = applicationDomainPayload($records, 'roster', [
                'players' => [], 'characterOrder' => [], 'playerPreferences' => [],
                'playerTombstones' => [], 'characterTombstones' => [],
            ]);
            $players = is_array($roster['players'] ?? null) ? $roster['players'] : [];
            $accountIndex = findEntryIndex($players, $accountId);
            // Un compte déjà présent peut aussi absorber une ancienne entrée
            // de même nom uniquement si celle-ci est unique. La réparation à
            // la lecture ajoute en plus les gardes de propriété des fiches.
            $pendingIndex = rosterMigrationCandidateIndex(
                $players,
                $accountId,
                $identity,
                $accountIndex >= 0
            );
            if ($accountIndex >= 0 && $pendingIndex >= 0) {
                $candidateId = (string) ($players[$pendingIndex]['id'] ?? '');
                $accountCharacters = 0;
                $candidateCharacters = 0;
                foreach ($records as $key => $record) {
                    if (!str_starts_with($key, 'character:')) {
                        continue;
                    }
                    $ownerPlayerId = (string) (applicationDomainPayload($records, $key)['ownerPlayerId'] ?? '');
                    $accountCharacters += $ownerPlayerId === $accountId ? 1 : 0;
                    $candidateCharacters += $ownerPlayerId === $candidateId ? 1 : 0;
                }
                if ($accountCharacters !== 0 || $candidateCharacters === 0) {
                    $pendingIndex = -1;
                }
            }
            $now = (int) floor(microtime(true) * 1000);
            $oldId = '';
            $rosterChanged = false;
            if ($accountIndex < 0 && $pendingIndex < 0) {
                if (count($players) >= 1000) {
                    rejectOnlineCommand($connection, 409, 'La table a atteint sa limite de joueurs.', 'player_limit');
                }
                $players[] = ['id' => $accountId, 'name' => (string) $identity['display_name'], '_updatedAt' => $now];
                $rosterChanged = true;
            } elseif ($accountIndex < 0) {
                $oldId = (string) ($players[$pendingIndex]['id'] ?? '');
                $players[$pendingIndex]['id'] = $accountId;
                $players[$pendingIndex]['name'] = (string) $identity['display_name'];
                $players[$pendingIndex]['_updatedAt'] = $now;
                $rosterChanged = true;
            } else {
                if ((string) ($players[$accountIndex]['name'] ?? '') !== (string) $identity['display_name']) {
                    $players[$accountIndex]['name'] = (string) $identity['display_name'];
                    $players[$accountIndex]['_updatedAt'] = $now;
                    $rosterChanged = true;
                }
                if ($pendingIndex >= 0) {
                    $oldId = (string) ($players[$pendingIndex]['id'] ?? '');
                    array_splice($players, $pendingIndex, 1);
                    $rosterChanged = true;
                }
            }
            if ($oldId !== '' && $oldId !== $accountId) {
                $preferences = is_array($roster['playerPreferences'] ?? null) ? $roster['playerPreferences'] : [];
                if (isset($preferences[$oldId]) && !isset($preferences[$accountId])) {
                    $preferences[$accountId] = $preferences[$oldId];
                }
                unset($preferences[$oldId]);
                $roster['playerPreferences'] = $preferences;
                foreach (['playerTombstones', 'characterTombstones'] as $listKey) {
                    if (!is_array($roster[$listKey] ?? null)) {
                        continue;
                    }
                    foreach ($roster[$listKey] as &$entry) {
                        if (!is_array($entry)) {
                            continue;
                        }
                        if (($entry['ownerPlayerId'] ?? null) === $oldId) {
                            $entry['ownerPlayerId'] = $accountId;
                        }
                        if ($listKey === 'playerTombstones' && ($entry['id'] ?? null) === $oldId) {
                            $entry['id'] = $accountId;
                        }
                    }
                    unset($entry);
                }
                foreach ($records as $key => $record) {
                    if (str_starts_with($key, 'character:')) {
                        $payload = applicationDomainPayload($records, $key);
                        if (($payload['ownerPlayerId'] ?? null) === $oldId) {
                            $payload['ownerPlayerId'] = $accountId;
                            $payload['_updatedAt'] = $now;
                            queueOnlineDomainUpsert($pending, $records, $key, $payload);
                        }
                    } elseif (str_starts_with($key, 'token:')) {
                        $payload = applicationDomainPayload($records, $key);
                        if (($payload['controllerPlayerId'] ?? null) === $oldId) {
                            $payload['controllerPlayerId'] = $accountId;
                            $payload['_updatedAt'] = $now;
                            queueOnlineDomainUpsert($pending, $records, $key, $payload);
                        }
                    } elseif (str_starts_with($key, 'presentation:') || $key === 'detached-combat') {
                        $payload = applicationDomainPayload($records, $key);
                        if (!is_array($payload['map']['tokens'] ?? null)) {
                            continue;
                        }
                        $changed = false;
                        foreach ($payload['map']['tokens'] as &$token) {
                            if (is_array($token) && ($token['controllerPlayerId'] ?? null) === $oldId) {
                                $token['controllerPlayerId'] = $accountId;
                                $token['_updatedAt'] = $now;
                                $changed = true;
                            }
                        }
                        unset($token);
                        if ($changed) {
                            queueOnlineDomainUpsert($pending, $records, $key, $payload);
                        }
                    }
                }
                $activity = applicationDomainPayload($records, 'activity');
                $activityChanged = false;
                foreach (is_array($activity['actionTimers'] ?? null) ? $activity['actionTimers'] : [] as $index => $timer) {
                    if (is_array($timer) && ($timer['ownerPlayerId'] ?? null) === $oldId) {
                        $activity['actionTimers'][$index]['ownerPlayerId'] = $accountId;
                        $activity['actionTimers'][$index]['updatedAt'] = gmdate('c');
                        $activityChanged = true;
                    }
                }
                if ($activityChanged) {
                    queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
                }
            }
            if ($rosterChanged) {
                $roster['players'] = $players;
                queueOnlineDomainUpsert($pending, $records, 'roster', $roster);
            }
        } elseif ($command === 'preferences.update') {
            $records = array_replace($records, applicationDomainRecords($connection, ['roster']));
            $roster = applicationDomainPayload($records, 'roster');
            $preferences = is_array($roster['playerPreferences'][$accountId] ?? null)
                ? $roster['playerPreferences'][$accountId]
                : [];
            foreach (['musicMuted', 'ambienceMuted'] as $key) {
                if (array_key_exists($key, $arguments) && is_bool($arguments[$key])) {
                    $preferences[$key] = $arguments[$key];
                }
            }
            if (array_key_exists('activePage', $arguments)) {
                $preferences['activePage'] = $arguments['activePage'] === 'characters' ? 'characters' : 'map';
            }
            if (array_key_exists('activeCharacterId', $arguments)) {
                $activeCharacterId = trim((string) ($arguments['activeCharacterId'] ?? ''));
                if ($activeCharacterId !== '') {
                    $characterKey = 'character:' . $activeCharacterId;
                    if (!validApplicationDomainKey($characterKey)) {
                        rejectOnlineCommand($connection, 400, 'Personnage actif invalide.', 'invalid_active_character');
                    }
                    $records = array_replace($records, applicationDomainRecords($connection, [$characterKey]));
                    $activeCharacter = applicationDomainPayload($records, $characterKey);
                    if ($activeCharacter === [] || ($activeCharacter['ownerPlayerId'] ?? null) !== $accountId) {
                        rejectOnlineCommand($connection, 403, 'Ce personnage ne vous appartient pas.', 'character_forbidden');
                    }
                    $preferences['activeCharacterId'] = $activeCharacterId;
                } else {
                    $preferences['activeCharacterId'] = null;
                }
            }
            $preferences = [
                'musicMuted' => ($preferences['musicMuted'] ?? false) === true,
                'ambienceMuted' => ($preferences['ambienceMuted'] ?? false) === true,
                'activePage' => ($preferences['activePage'] ?? '') === 'characters' ? 'characters' : 'map',
                'activeCharacterId' => is_string($preferences['activeCharacterId'] ?? null)
                    ? (string) $preferences['activeCharacterId'] : null,
            ];
            $roster['playerPreferences'] = is_array($roster['playerPreferences'] ?? null) ? $roster['playerPreferences'] : [];
            $roster['playerPreferences'][$accountId] = $preferences;
            queueOnlineDomainUpsert($pending, $records, 'roster', $roster);
            $result['preferences'] = $preferences;
        } elseif ($command === 'character.create') {
            $character = cleanPlayerCharacter(is_array($arguments['character'] ?? null) ? $arguments['character'] : [], $accountId);
            $characterKey = 'character:' . (string) $character['id'];
            if (!validApplicationDomainKey($characterKey)) {
                rejectOnlineCommand($connection, 400, 'Identifiant de fiche invalide.', 'invalid_character');
            }
            $records = array_replace($records, applicationDomainRecords($connection, ['roster', $characterKey]));
            $roster = applicationDomainPayload($records, 'roster');
            foreach (is_array($roster['characterTombstones'] ?? null) ? $roster['characterTombstones'] : [] as $entry) {
                if (is_array($entry) && (string) ($entry['id'] ?? '') === (string) $character['id']) {
                    rejectOnlineCommand($connection, 409, 'Cette fiche a été supprimée par un administrateur et ne peut pas être recréée automatiquement.', 'character_deleted');
                }
            }
            if (isset($records[$characterKey])) {
                rejectOnlineCommand($connection, 409, 'Cette fiche existe déjà.', 'character_exists');
            }
            $order = is_array($roster['characterOrder'] ?? null) ? $roster['characterOrder'] : [];
            if (!in_array($character['id'], $order, true)) {
                if (count($order) >= 1000) {
                    rejectOnlineCommand($connection, 409, 'Ce compte a atteint la limite de fiches de la table.', 'character_limit');
                }
                $order[] = $character['id'];
            }
            $roster['characterOrder'] = $order;
            queueOnlineDomainUpsert($pending, $records, 'roster', $roster);
            queueOnlineDomainUpsert($pending, $records, $characterKey, $character);
            $result['character'] = visibleCharacter($character);
        } elseif ($command === 'character.patch') {
            $characterId = trim((string) ($arguments['characterId'] ?? ''));
            $characterKey = 'character:' . $characterId;
            if (!validApplicationDomainKey($characterKey)) {
                rejectOnlineCommand($connection, 403, 'Cette fiche ne vous appartient pas.', 'character_forbidden');
            }
            $records = array_replace($records, applicationDomainRecords($connection, [$characterKey]));
            $character = applicationDomainPayload($records, $characterKey);
            if ($character === [] || ($character['ownerPlayerId'] ?? null) !== $accountId) {
                rejectOnlineCommand($connection, 403, 'Cette fiche ne vous appartient pas.', 'character_forbidden');
            }
            $patch = is_array($arguments['patch'] ?? null) ? $arguments['patch'] : [];
            $legacyWholePatch = legacyWholePlayerCharacterPatch($patch);
            if ($legacyWholePatch && playerCharacterPatchChangesCurrent($character, $patch)) {
                rejectOnlineCommand(
                    $connection,
                    409,
                    'Une ancienne copie complète a été empêchée d’écraser la fiche en ligne. Modifiez puis enregistrez uniquement les champs voulus.',
                    'stale_full_character_patch'
                );
            }
            if (!$legacyWholePatch) {
                $character = playerCharacterPatch($character, $patch);
            }
            queueOnlineDomainUpsert($pending, $records, $characterKey, $character);
            $synchronizedFields = ['name', 'portrait', 'color', 'resources', 'armor', 'speed', 'stats', 'hitThreshold', 'abilities', 'initiativeBonus', 'linkedTokens'];
            if (array_intersect(array_keys($patch), $synchronizedFields) !== []) {
                $tokenRecords = applicationCharacterTokenDomainRecords($connection, $characterId);
                $records = array_replace($records, $tokenRecords);
                foreach ($tokenRecords as $tokenKey => $record) {
                    $token = synchronizeOnlineCharacterToken(applicationDomainPayload($records, $tokenKey), $character);
                    queueOnlineDomainUpsert($pending, $records, $tokenKey, $token);
                }
            }
            $result['character'] = visibleCharacter($character);
        } elseif ($command === 'token.move') {
            if (($table['tacticalSync']['paused'] ?? false) === true) {
                rejectOnlineCommand($connection, 423, 'La table est temporairement verrouillée.', 'table_locked');
            }
            if ($sceneId === '') {
                rejectOnlineCommand($connection, 409, 'Aucune scène de combat active.', 'combat_required');
            }
            $tokenKey = onlineTokenDomainKey($sceneId, $arguments['tokenId'] ?? '');
            $initiativeKey = 'initiative:' . $sceneId;
            $records = array_replace($records, applicationDomainRecords($connection, [$tokenKey, $initiativeKey]));
            $token = $tokenKey === '' ? [] : applicationDomainPayload($records, $tokenKey);
            if ($token === [] || (!$isGm
                && (($token['controllerPlayerId'] ?? null) !== $accountId || ($token['hidden'] ?? false) === true))) {
                rejectOnlineCommand($connection, 403, 'Déplacement refusé.', 'token_forbidden');
            }
            $initiative = applicationDomainPayload($records, $initiativeKey);
            if (!$isGm && ($initiative['active'] ?? false) === true) {
                $order = is_array($initiative['order'] ?? null) ? $initiative['order'] : [];
                $activeId = $order[(int) ($initiative['currentIndex'] ?? 0)] ?? null;
                $movementOverrides = is_array($initiative['movementOverrides'] ?? null) ? $initiative['movementOverrides'] : [];
                $movementOverride = ($movementOverrides[(string) ($token['id'] ?? '')] ?? false) === true;
                if ($activeId !== ($token['id'] ?? null) && !$movementOverride) {
                    rejectOnlineCommand($connection, 403, 'Ce n’est pas le tour de ce token.', 'turn_required');
                }
            }
            $x = is_numeric($arguments['x'] ?? null) ? (float) $arguments['x'] : (float) ($token['x'] ?? 50);
            $y = is_numeric($arguments['y'] ?? null) ? (float) $arguments['y'] : (float) ($token['y'] ?? 50);
            $token['x'] = max(0.0, min(100.0, is_finite($x) ? $x : 50.0));
            $token['y'] = max(0.0, min(100.0, is_finite($y) ? $y : 50.0));
            $token['_movedAt'] = (int) floor(microtime(true) * 1000);
            queueOnlineDomainUpsert($pending, $records, $tokenKey, $token);
            $result['token'] = $token;
        } elseif ($command === 'token.resource.adjust') {
            if (!$isGm && ($table['tacticalSync']['paused'] ?? false) === true) {
                rejectOnlineCommand($connection, 423, 'Les ressources sont verrouillées pendant la préparation du MJ.', 'table_locked');
            }
            $resourceSceneId = $isGm ? trim((string) ($arguments['sceneId'] ?? '')) : $sceneId;
            if ($resourceSceneId === '' || !validApplicationDomainKey('scene:' . $resourceSceneId)) {
                rejectOnlineCommand($connection, 409, 'Aucune scène de combat active.', 'combat_required');
            }
            $resource = (string) ($arguments['resource'] ?? '');
            $requestedDelta = is_numeric($arguments['delta'] ?? null) ? (int) $arguments['delta'] : 0;
            $requestedDelta = max(-1000000000, min(1000000000, $requestedDelta));
            if (!in_array($resource, ['hp', 'mana'], true) || $requestedDelta === 0) {
                rejectOnlineCommand($connection, 400, 'Indiquez une ressource et une variation numérique non nulle.', 'invalid_resource_delta');
            }
            $tokenKey = onlineTokenDomainKey($resourceSceneId, $arguments['tokenId'] ?? '');
            $records = array_replace($records, applicationDomainRecords($connection, $tokenKey !== '' ? [$tokenKey] : []));
            $token = $tokenKey === '' ? [] : applicationDomainPayload($records, $tokenKey);
            if ($token === []) {
                rejectOnlineCommand($connection, 404, 'Token introuvable.', 'token_missing');
            }
            if (!$isGm && (($token['controllerPlayerId'] ?? null) !== $accountId || ($token['hidden'] ?? false) === true)) {
                rejectOnlineCommand($connection, 403, 'Vous ne pouvez pas modifier les ressources de ce token.', 'token_forbidden');
            }
            $maximumKey = $resource === 'mana' ? 'maxMana' : 'maxHp';
            $character = null;
            $characterKey = '';
            $characterId = trim((string) ($token['characterId'] ?? ''));
            $followsCharacter = ($token['followCharacter'] ?? true) !== false && trim((string) ($token['linkedTokenId'] ?? '')) === '';
            if ($followsCharacter && $characterId !== '') {
                $characterKey = 'character:' . $characterId;
                if (validApplicationDomainKey($characterKey)) {
                    $records = array_replace($records, applicationDomainRecords($connection, [$characterKey]));
                    $candidate = applicationDomainPayload($records, $characterKey);
                    if ($candidate !== []) {
                        if (!$isGm && ($candidate['ownerPlayerId'] ?? null) !== $accountId) {
                            rejectOnlineCommand($connection, 403, 'Cette fiche ne vous appartient pas.', 'character_forbidden');
                        }
                        $character = $candidate;
                        $resources = is_array($character['resources'] ?? null) ? $character['resources'] : [];
                        $token[$resource] = $resources[$resource] ?? ($token[$resource] ?? 0);
                        $token[$maximumKey] = $resources[$maximumKey] ?? ($token[$maximumKey] ?? 0);
                    }
                }
            }
            $maximum = max(0, min(1000000000, is_numeric($token[$maximumKey] ?? null) ? (int) $token[$maximumKey] : 0));
            if ($maximum <= 0) {
                rejectOnlineCommand($connection, 409, 'Ce token ne possède pas de maximum pour cette ressource.', 'resource_missing');
            }
            $previous = max(0, min($maximum, is_numeric($token[$resource] ?? null) ? (int) $token[$resource] : 0));
            $current = max(0, min($maximum, $previous + $requestedDelta));
            $appliedDelta = $current - $previous;
            if ($appliedDelta === 0) {
                rejectOnlineCommand($connection, 409, 'La ressource est déjà à sa limite.', 'resource_limit');
            }
            $now = (int) floor(microtime(true) * 1000);
            $pulse = [
                'id' => 'resource-' . randomToken(12),
                'resource' => $resource,
                'delta' => $appliedDelta,
                'at' => $now,
            ];
            $token[$resource] = $current;
            $token['resourcePulse'] = $pulse;
            $token['_updatedAt'] = $now;
            if (is_array($character)) {
                $resources = is_array($character['resources'] ?? null) ? $character['resources'] : [];
                $resources[$resource] = $current;
                $character['resources'] = $resources;
                $character['_updatedAt'] = $now;
                queueOnlineDomainUpsert($pending, $records, $characterKey, $character);
                $characterTokenRecords = applicationCharacterTokenDomainRecords($connection, $characterId);
                $records = array_replace($records, $characterTokenRecords);
                foreach ($characterTokenRecords as $relatedTokenKey => $record) {
                    $relatedToken = applicationDomainPayload($records, $relatedTokenKey);
                    if (($relatedToken['followCharacter'] ?? true) === false || trim((string) ($relatedToken['linkedTokenId'] ?? '')) !== '') {
                        continue;
                    }
                    $relatedToken[$resource] = $current;
                    $relatedToken[$maximumKey] = $maximum;
                    $relatedToken['_updatedAt'] = $now;
                    if ($relatedTokenKey === $tokenKey) {
                        $relatedToken['resourcePulse'] = $pulse;
                        $relatedToken['_updatedAt'] = $now;
                        $token = $relatedToken;
                    }
                    queueOnlineDomainUpsert($pending, $records, $relatedTokenKey, $relatedToken);
                }
            } else {
                queueOnlineDomainUpsert($pending, $records, $tokenKey, $token);
            }
            $result['token'] = [
                'id' => $token['id'] ?? null,
                'hp' => $token['hp'] ?? null,
                'maxHp' => $token['maxHp'] ?? null,
                'mana' => $token['mana'] ?? null,
                'maxMana' => $token['maxMana'] ?? null,
                'resourcePulse' => $pulse,
            ];
            $result['appliedDelta'] = $appliedDelta;
            $result['current'] = $current;
            $result['maximum'] = $maximum;
        } elseif ($command === 'ping') {
            $records = array_replace($records, applicationDomainRecords($connection, ['activity']));
            $activity = applicationDomainPayload($records, 'activity');
            $now = (int) floor(microtime(true) * 1000);
            $requestId = trim((string) ($arguments['requestId'] ?? ''));
            if ($requestId !== '' && preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $requestId) !== 1) {
                rejectOnlineCommand($connection, 400, 'Référence de signal invalide.', 'invalid_ping_request');
            }
            $receipts = array_values(array_filter(
                is_array($activity['pingReceipts'] ?? null) ? $activity['pingReceipts'] : [],
                static fn (mixed $entry): bool => is_array($entry) && (int) ($entry['expiresAt'] ?? 0) > $now
            ));
            $receipt = null;
            if ($requestId !== '') {
                foreach ($receipts as $entry) {
                    if ((string) ($entry['requestId'] ?? '') === $requestId
                        && (string) ($entry['accountId'] ?? '') === $accountId
                        && is_array($entry['ping'] ?? null)) {
                        $receipt = $entry;
                        break;
                    }
                }
            }
            $ping = is_array($receipt['ping'] ?? null) ? $receipt['ping'] : [
                'id' => 'ping-' . randomToken(9),
                'x' => max(0.0, min(100.0, (float) ($arguments['x'] ?? 50))),
                'y' => max(0.0, min(100.0, (float) ($arguments['y'] ?? 50))),
                'sceneId' => $sceneId !== '' ? $sceneId : null,
                'createdAt' => $now,
                'expiresAt' => $now + 4200,
                'author' => $isGm ? 'MJ' : (string) $identity['display_name'],
                'color' => $isGm ? '#ffd782' : '#8d72cb',
                'requestId' => $requestId,
            ];
            $pings = array_values(array_filter(
                is_array($activity['mapPings'] ?? null) ? $activity['mapPings'] : [],
                static fn (mixed $entry): bool => is_array($entry) && (int) ($entry['expiresAt'] ?? 0) > $now
            ));
            if ($receipt === null) {
                $activity['mapPings'] = [...array_slice($pings, -19), $ping];
                if ($requestId !== '') {
                    $receipts[] = [
                        'requestId' => $requestId,
                        'accountId' => $accountId,
                        'expiresAt' => $now + 30_000,
                        'ping' => $ping,
                    ];
                }
            } else {
                $activity['mapPings'] = $pings;
                $result['deduplicated'] = true;
            }
            $activity['pingReceipts'] = array_slice($receipts, -256);
            queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
            $result['ping'] = $ping;
        } elseif ($command === 'token.roll') {
            $tokenId = trim((string) ($arguments['tokenId'] ?? ''));
            $characterId = trim((string) ($arguments['characterId'] ?? ''));
            $tokenKey = '';
            $initiativeKey = 'initiative:' . $sceneId;
            $indexKey = 'token-index:' . $sceneId;
            if ($tokenId !== '') {
                if ($sceneId === '') {
                    rejectOnlineCommand($connection, 409, 'Aucune scène de combat active.', 'combat_required');
                }
                $tokenKey = onlineTokenDomainKey($sceneId, $tokenId);
                $records = array_replace($records, applicationDomainRecords($connection, [$tokenKey, $initiativeKey, $indexKey, 'activity']));
                $token = $tokenKey === '' ? [] : applicationDomainPayload($records, $tokenKey);
                if ($token === [] || ($token['controllerPlayerId'] ?? null) !== $accountId || ($token['hidden'] ?? false) === true) {
                    rejectOnlineCommand($connection, 403, 'Ce token ne vous appartient pas.', 'token_forbidden');
                }
            } else {
                $characterKey = 'character:' . $characterId;
                if ($characterId === '' || !validApplicationDomainKey($characterKey)) {
                    rejectOnlineCommand($connection, 403, 'Ce personnage ne vous appartient pas.', 'character_forbidden');
                }
                $records = array_replace($records, applicationDomainRecords($connection, [$characterKey, 'activity']));
                $character = applicationDomainPayload($records, $characterKey);
                if ($character === [] || ($character['ownerPlayerId'] ?? null) !== $accountId) {
                    rejectOnlineCommand($connection, 403, 'Ce personnage ne vous appartient pas.', 'character_forbidden');
                }
                $token = synchronizeOnlineCharacterToken([], $character);
                $token['characterId'] = $characterId;
                $token['controllerPlayerId'] = $accountId;
                $token['name'] = trim((string) ($character['name'] ?? '')) !== ''
                    ? (string) $character['name'] : 'Personnage';
                $token['damageDice'] = '';
            }
            $kind = in_array(($arguments['kind'] ?? ''), ['luck', 'stat', 'hit', 'initiative', 'damage', 'ability', 'custom'], true)
                ? (string) $arguments['kind'] : 'custom';
            $label = 'Test personnalisé';
            $formula = '1d100';
            $threshold = null;
            $modifier = 0;
            $rollMode = $kind === 'luck' ? 'normal' : normalizeOnlineRollMode($arguments['rollMode'] ?? 'normal');
            if ($kind === 'luck') {
                $label = 'Chance';
                $formula = '1d100';
            } elseif ($kind === 'stat') {
                $stats = is_array($token['stats'] ?? null) ? $token['stats'] : [];
                $statIndex = findEntryIndex($stats, (string) ($arguments['statId'] ?? ''));
                if ($statIndex < 0 || !is_numeric($stats[$statIndex]['value'] ?? null)) {
                    rejectOnlineCommand($connection, 404, 'Cette statistique n’existe plus sur le token.', 'token_stat_missing');
                }
                $threshold = max(0, min(100, (int) $stats[$statIndex]['value']));
                $modifier = normalizeOnlineD100Modifier($arguments['modifier'] ?? 0);
                $label = substr(trim((string) ($stats[$statIndex]['label'] ?? 'Statistique')), 0, 120);
                $formula = '1d100';
            } elseif ($kind === 'hit') {
                $threshold = normalizeOnlineD100Difficulty($token['hitThreshold'] ?? null);
                if ($threshold === null) {
                    rejectOnlineCommand($connection, 400, 'La difficulté de Touché n’est pas renseignée sur ce personnage.', 'token_hit_missing');
                }
                $modifier = normalizeOnlineD100Modifier($arguments['modifier'] ?? 0);
                $label = 'Touché';
                $formula = '1d100';
            } elseif ($kind === 'initiative') {
                $bonus = is_numeric($token['initiativeBonus'] ?? null) ? (int) $token['initiativeBonus'] : 0;
                $label = 'Initiative';
                $formula = '1d100' . ($bonus >= 0 ? '+' : '') . $bonus;
            } elseif ($kind === 'damage') {
                $label = 'Dégâts';
                $formula = substr(str_replace(' ', '', (string) ($token['damageDice'] ?? '')), 0, 80);
                if ($formula === '') {
                    rejectOnlineCommand($connection, 400, 'Les dégâts de ce token ne sont pas renseignés.', 'token_damage_missing');
                }
            } elseif ($kind === 'ability') {
                $abilities = normalizeOnlineAbilities($token['abilities'] ?? []);
                $abilityIndex = findEntryIndex($abilities, (string) ($arguments['abilityId'] ?? ''));
                if ($abilityIndex < 0) {
                    rejectOnlineCommand($connection, 404, 'Cette capacité n’existe plus sur le token.', 'token_ability_missing');
                }
                $modifier = normalizeOnlineD100Modifier($arguments['modifier'] ?? 0);
                $label = (string) $abilities[$abilityIndex]['name'];
                $formula = (string) $abilities[$abilityIndex]['formula']
                    . ($modifier !== 0 ? ($modifier > 0 ? '+' : '') . (string) $modifier : '');
                if (strlen($formula) > 100) {
                    rejectOnlineCommand($connection, 400, 'La formule modifiée dépasse la limite autorisée.', 'invalid_roll');
                }
            } else {
                $label = substr(trim((string) ($arguments['label'] ?? 'Test personnalisé')), 0, 120);
                $formula = substr(str_replace(' ', '', (string) ($arguments['formula'] ?? '1d100')), 0, 80);
                if ($label === '') {
                    rejectOnlineCommand($connection, 400, 'Donnez un intitulé au jet.', 'invalid_roll_label');
                }
            }
            try {
                $rolled = onlineRollFormulaWithMode(
                    $formula,
                    $rollMode,
                    in_array($kind, ['stat', 'hit'], true) ? $threshold : null,
                    in_array($kind, ['stat', 'hit'], true) ? $modifier : 0
                );
            } catch (InvalidArgumentException $error) {
                rejectOnlineCommand($connection, 400, $error->getMessage(), 'invalid_roll');
            }
            $outcome = in_array($kind, ['stat', 'hit'], true)
                ? classifyOnlineD100Outcome($rolled['rawD100'] ?? null, $threshold, $modifier)
                : classifyOnlineD100Outcome($rolled['rawD100'] ?? null);
            $roll = onlineRollEntry($identity, $rolled, $label, (string) ($token['name'] ?? 'Token'), $outcome);
            $activity = applicationDomainPayload($records, 'activity');
            $rolls = is_array($activity['rolls'] ?? null) ? $activity['rolls'] : [];
            array_unshift($rolls, $roll);
            $activity['rolls'] = array_slice($rolls, 0, 100);
            queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
            $initiativeUpdated = false;
            if ($tokenKey !== '' && $kind === 'initiative' && ($table['tacticalSync']['paused'] ?? false) !== true) {
                $records = onlineSceneTokenRecords($connection, $sceneId, $records);
                $token['initiative'] = $rolled['total'];
                $token['_updatedAt'] = (int) floor(microtime(true) * 1000);
                $recordsForSort = $records;
                $recordsForSort[$tokenKey] = [...($records[$tokenKey] ?? []), 'payload' => $token];
                $initiative = applicationDomainPayload($records, $initiativeKey);
                $order = is_array($initiative['order'] ?? null) ? $initiative['order'] : [];
                $currentTokenId = ($initiative['active'] ?? false) === true
                    ? ($order[(int) ($initiative['currentIndex'] ?? 0)] ?? null) : null;
                if (!in_array($token['id'], $order, true)) {
                    $order[] = $token['id'];
                }
                $order = sortedOnlineInitiativeOrder($order, $recordsForSort, $sceneId);
                $initiative['order'] = $order;
                $initiative['currentIndex'] = $currentTokenId !== null && in_array($currentTokenId, $order, true)
                    ? (int) array_search($currentTokenId, $order, true) : 0;
                $initiative['_updatedAt'] = (int) floor(microtime(true) * 1000);
                queueOnlineDomainUpsert($pending, $records, $tokenKey, $token);
                queueOnlineDomainUpsert($pending, $records, $initiativeKey, $initiative);
                $initiativeUpdated = true;
            }
            $result['roll'] = $roll;
            $result['initiativeUpdated'] = $initiativeUpdated;
        } elseif ($command === 'roll') {
            $characterId = trim((string) ($arguments['characterId'] ?? ''));
            $characterKey = 'character:' . $characterId;
            if (!validApplicationDomainKey($characterKey)) {
                rejectOnlineCommand($connection, 403, 'Ce personnage ne vous appartient pas.', 'character_forbidden');
            }
            $records = array_replace($records, applicationDomainRecords($connection, [$characterKey, 'activity']));
            $character = applicationDomainPayload($records, $characterKey);
            if ($character === [] || ($character['ownerPlayerId'] ?? null) !== $accountId) {
                rejectOnlineCommand($connection, 403, 'Ce personnage ne vous appartient pas.', 'character_forbidden');
            }
            $shortcuts = is_array($character['shortcuts'] ?? null) ? $character['shortcuts'] : [];
            $shortcutIndex = findEntryIndex($shortcuts, (string) ($arguments['shortcutId'] ?? ''));
            if ($shortcutIndex < 0) {
                rejectOnlineCommand($connection, 404, 'Raccourci de jet introuvable.', 'shortcut_missing');
            }
            $shortcut = $shortcuts[$shortcutIndex];
            $kind = (string) ($shortcut['kind'] ?? 'roll');
            $formula = (string) ($shortcut['formula'] ?? '1d100');
            $rollMode = normalizeOnlineRollMode($arguments['rollMode'] ?? 'normal');
            if ($kind === 'initiative') {
                $formula = preg_replace('/(?:\d*)d\d+/i', '1d100', str_replace(' ', '', $formula), 1) ?? '1d100';
                if (!str_contains(strtolower($formula), 'd')) {
                    $formula = '1d100' . ($formula !== '' ? (preg_match('/^[+-]/', $formula) === 1 ? '' : '+') . $formula : '');
                }
            }
            try {
                $rolled = onlineRollFormulaWithMode($formula, $rollMode);
            } catch (InvalidArgumentException $error) {
                rejectOnlineCommand($connection, 400, $error->getMessage(), 'invalid_roll');
            }
            $outcome = classifyOnlineD100Outcome($rolled['rawD100'] ?? null);
            $roll = onlineRollEntry(
                $identity,
                $rolled,
                (string) ($shortcut['label'] ?? 'Jet'),
                (string) ($character['name'] ?? 'Personnage'),
                $outcome
            );
            $activity = applicationDomainPayload($records, 'activity');
            $rolls = is_array($activity['rolls'] ?? null) ? $activity['rolls'] : [];
            array_unshift($rolls, $roll);
            $activity['rolls'] = array_slice($rolls, 0, 100);
            queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
            $initiativeUpdated = false;
            if ($kind === 'initiative' && $sceneId !== '' && ($table['tacticalSync']['paused'] ?? false) !== true) {
                $initiativeKey = 'initiative:' . $sceneId;
                $indexKey = 'token-index:' . $sceneId;
                $records = array_replace($records, applicationDomainRecords($connection, [$initiativeKey, $indexKey]));
                $records = onlineSceneTokenRecords($connection, $sceneId, $records);
                $controlledTokenKey = '';
                $controlledToken = [];
                foreach ($records as $key => $record) {
                    if (!str_starts_with($key, 'token:' . $sceneId . ':')) {
                        continue;
                    }
                    $candidate = applicationDomainPayload($records, $key);
                    if (($candidate['characterId'] ?? null) === $characterId
                        && ($candidate['controllerPlayerId'] ?? null) === $accountId
                        && ($candidate['hidden'] ?? false) !== true) {
                        $controlledTokenKey = $key;
                        $controlledToken = $candidate;
                        break;
                    }
                }
                if ($controlledTokenKey !== '') {
                    $controlledToken['initiative'] = $rolled['total'];
                    $controlledToken['_updatedAt'] = (int) floor(microtime(true) * 1000);
                    $recordsForSort = $records;
                    $recordsForSort[$controlledTokenKey] = [...$records[$controlledTokenKey], 'payload' => $controlledToken];
                    $initiative = applicationDomainPayload($records, $initiativeKey);
                    $order = is_array($initiative['order'] ?? null) ? $initiative['order'] : [];
                    $currentTokenId = ($initiative['active'] ?? false) === true
                        ? ($order[(int) ($initiative['currentIndex'] ?? 0)] ?? null) : null;
                    if (!in_array($controlledToken['id'], $order, true)) {
                        $order[] = $controlledToken['id'];
                    }
                    $order = sortedOnlineInitiativeOrder($order, $recordsForSort, $sceneId);
                    $initiative['order'] = $order;
                    $initiative['currentIndex'] = $currentTokenId !== null && in_array($currentTokenId, $order, true)
                        ? (int) array_search($currentTokenId, $order, true) : 0;
                    $initiative['_updatedAt'] = (int) floor(microtime(true) * 1000);
                    queueOnlineDomainUpsert($pending, $records, $controlledTokenKey, $controlledToken);
                    queueOnlineDomainUpsert($pending, $records, $initiativeKey, $initiative);
                    $initiativeUpdated = true;
                }
            }
            $result['roll'] = $roll;
            $result['initiativeUpdated'] = $initiativeUpdated;
        } elseif ($command === 'timer.create') {
            if (($table['tacticalSync']['paused'] ?? false) === true) {
                rejectOnlineCommand($connection, 423, 'Les minuteurs sont verrouillés pendant la préparation du MJ.', 'table_locked');
            }
            if ($sceneId === '') {
                rejectOnlineCommand($connection, 409, 'Commencez un combat avant d’ajouter une recharge.', 'combat_required');
            }
            $characterId = trim((string) ($arguments['characterId'] ?? ''));
            $characterKey = 'character:' . $characterId;
            $initiativeKey = 'initiative:' . $sceneId;
            $records = array_replace($records, applicationDomainRecords($connection, [$characterKey, $initiativeKey, 'activity']));
            $initiative = applicationDomainPayload($records, $initiativeKey);
            if (($initiative['active'] ?? false) !== true) {
                rejectOnlineCommand($connection, 409, 'Commencez un combat avant d’ajouter une recharge.', 'combat_required');
            }
            $character = validApplicationDomainKey($characterKey) ? applicationDomainPayload($records, $characterKey) : [];
            if ($character === [] || ($character['ownerPlayerId'] ?? null) !== $accountId) {
                rejectOnlineCommand($connection, 403, 'Ce personnage ne vous appartient pas.', 'character_forbidden');
            }
            $label = substr(trim((string) ($arguments['label'] ?? '')), 0, 120);
            if ($label === '') {
                rejectOnlineCommand($connection, 400, 'Donnez un nom à l’action.', 'invalid_timer');
            }
            $round = max(1, (int) ($initiative['round'] ?? 1));
            $cooldown = max(1, min(999, (int) ($arguments['cooldown'] ?? 1)));
            $timer = [
                'id' => 'timer-' . randomToken(9), 'sceneId' => $sceneId, 'label' => $label,
                'cooldown' => $cooldown, 'usedRound' => $round, 'readyRound' => $round + $cooldown,
                'ownerPlayerId' => $accountId, 'characterId' => $character['id'],
                'ownerLabel' => $character['name'] ?? (string) $identity['display_name'],
                'visibility' => ($arguments['visibility'] ?? '') === 'public' ? 'public' : 'private',
                'createdAt' => gmdate('c'), 'updatedAt' => gmdate('c'),
            ];
            $activity = applicationDomainPayload($records, 'activity');
            $timers = is_array($activity['actionTimers'] ?? null) ? $activity['actionTimers'] : [];
            if (count($timers) >= 300) {
                rejectOnlineCommand($connection, 409, 'La table a atteint sa limite de minuteurs.', 'timer_limit');
            }
            array_unshift($timers, $timer);
            $activity['actionTimers'] = $timers;
            queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
            $result['timer'] = $timer + ['ownedByYou' => true];
        } elseif ($command === 'timer.update' || $command === 'timer.delete') {
            if (($table['tacticalSync']['paused'] ?? false) === true) {
                rejectOnlineCommand($connection, 423, 'Les minuteurs sont verrouillés pendant la préparation du MJ.', 'table_locked');
            }
            $keys = ['activity'];
            if ($sceneId !== '') {
                $keys[] = 'initiative:' . $sceneId;
            }
            $records = array_replace($records, applicationDomainRecords($connection, $keys));
            $initiative = $sceneId === '' ? [] : applicationDomainPayload($records, 'initiative:' . $sceneId);
            if ($command === 'timer.update' && ($sceneId === '' || ($initiative['active'] ?? false) !== true)) {
                rejectOnlineCommand($connection, 409, 'Commencez un combat avant de réutiliser une recharge.', 'combat_required');
            }
            $activity = applicationDomainPayload($records, 'activity');
            $timers = is_array($activity['actionTimers'] ?? null) ? $activity['actionTimers'] : [];
            $index = findEntryIndex($timers, (string) ($arguments['timerId'] ?? ''));
            if ($index < 0 || ($timers[$index]['ownerPlayerId'] ?? null) !== $accountId
                || ($command === 'timer.update' && (string) ($timers[$index]['sceneId'] ?? '') !== $sceneId)) {
                rejectOnlineCommand($connection, 403, 'Ce rappel ne vous appartient pas.', 'timer_forbidden');
            }
            if ($command === 'timer.delete') {
                $deleted = $timers[$index];
                array_splice($timers, $index, 1);
                $result['timer'] = ['id' => $deleted['id']];
                $deletedTimerId = (string) ($deleted['id'] ?? '');
                $timerTombstones = array_values(array_filter(
                    is_array($activity['actionTimerTombstones'] ?? null) ? $activity['actionTimerTombstones'] : [],
                    static fn (mixed $entry): bool => !is_array($entry)
                        || (string) ($entry['id'] ?? '') !== $deletedTimerId
                ));
                if ($deletedTimerId !== '') {
                    $timerTombstones[] = ['id' => $deletedTimerId, 'deletedAt' => gmdate('c')];
                }
                $activity['actionTimerTombstones'] = array_slice($timerTombstones, -1000);
            } else {
                $round = max(1, (int) ($initiative['round'] ?? 1));
                $timers[$index]['usedRound'] = $round;
                $timers[$index]['readyRound'] = $round + max(1, (int) ($timers[$index]['cooldown'] ?? 1));
                $timers[$index]['updatedAt'] = gmdate('c');
                $result['timer'] = $timers[$index] + ['ownedByYou' => true];
            }
            $activity['actionTimers'] = $timers;
            queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
        } elseif (($command === 'character.delete' && !$isGm)
            || ($command === 'admin.character.delete' && $isGm && (bool) ($identity['can_administrate'] ?? false))) {
            $selfDelete = $command === 'character.delete';
            $characterId = trim((string) ($arguments['characterId'] ?? ''));
            $ownerPlayerId = $selfDelete
                ? $accountId
                : trim((string) ($arguments['ownerPlayerId'] ?? ''));
            $characterKey = 'character:' . $characterId;
            if (!validApplicationDomainKey($characterKey)
                || preg_match('/^[A-Za-z0-9_-]{8,128}$/D', $ownerPlayerId) !== 1) {
                rejectOnlineCommand($connection, 404, 'Fiche introuvable.', 'character_missing');
            }
            $records = array_replace($records, applicationDomainRecords($connection, ['roster', 'activity', 'detached-combat', $characterKey]));
            $character = applicationDomainPayload($records, $characterKey);
            if ($character === []) {
                rejectOnlineCommand($connection, 404, 'Fiche introuvable.', 'character_missing');
            }
            if ((string) ($character['ownerPlayerId'] ?? '') !== $ownerPlayerId) {
                rejectOnlineCommand($connection, 409, 'La fiche n’appartient plus au profil sélectionné.', 'character_owner_changed');
            }
            $characterTokenRecords = applicationCharacterTokenDomainRecords($connection, $characterId);
            $records = array_replace($records, $characterTokenRecords);
            $tokenIdsByScene = [];
            foreach ($characterTokenRecords as $tokenKey => $tokenRecord) {
                $segments = explode(':', (string) $tokenKey, 3);
                if (count($segments) !== 3) {
                    continue;
                }
                $tokenIdsByScene[$segments[1]] ??= [];
                $tokenIdsByScene[$segments[1]][] = $segments[2];
            }
            $combatKeys = [];
            foreach (array_keys($tokenIdsByScene) as $affectedSceneId) {
                $combatKeys[] = 'token-index:' . $affectedSceneId;
                $combatKeys[] = 'initiative:' . $affectedSceneId;
                $combatKeys[] = 'presentation:' . $affectedSceneId;
            }
            if ($combatKeys !== []) {
                $records = array_replace($records, applicationDomainRecords($connection, $combatKeys));
            }
            $roster = applicationDomainPayload($records, 'roster');
            $roster['characterOrder'] = array_values(array_filter(
                is_array($roster['characterOrder'] ?? null) ? $roster['characterOrder'] : [],
                static fn (mixed $id): bool => (string) $id !== $characterId
            ));
            $tombstones = array_values(array_filter(
                is_array($roster['characterTombstones'] ?? null) ? $roster['characterTombstones'] : [],
                static fn (mixed $entry): bool => !is_array($entry) || (string) ($entry['id'] ?? '') !== $characterId
            ));
            $deletedAt = gmdate('c');
            $tombstones[] = [
                'id' => $characterId,
                'ownerPlayerId' => (string) ($character['ownerPlayerId'] ?? ''),
                'deletedAt' => $deletedAt,
            ];
            $roster['characterTombstones'] = array_slice($tombstones, -2000);
            queueOnlineDomainUpsert($pending, $records, 'roster', $roster);
            $removedTokenCount = 0;
            foreach ($tokenIdsByScene as $affectedSceneId => $tokenIds) {
                $removedTokenCount += count($tokenIds);
                $indexKey = 'token-index:' . $affectedSceneId;
                if (isset($records[$indexKey])) {
                    $index = applicationDomainPayload($records, $indexKey, ['order' => []]);
                    $removedIds = array_fill_keys(array_map('strval', $tokenIds), true);
                    $index['order'] = array_values(array_filter(
                        is_array($index['order'] ?? null) ? $index['order'] : [],
                        static fn (mixed $id): bool => !isset($removedIds[(string) $id])
                    ));
                    queueOnlineDomainUpsert($pending, $records, $indexKey, $index);
                }
                $initiativeKey = 'initiative:' . $affectedSceneId;
                if (isset($records[$initiativeKey])) {
                    queueOnlineDomainUpsert(
                        $pending,
                        $records,
                        $initiativeKey,
                        removeOnlineTokenIdsFromInitiative(applicationDomainPayload($records, $initiativeKey), $tokenIds)
                    );
                }
                $presentationKey = 'presentation:' . $affectedSceneId;
                if (isset($records[$presentationKey])) {
                    queueOnlineDomainUpsert(
                        $pending,
                        $records,
                        $presentationKey,
                        removeOnlineCharacterFromCombat(applicationDomainPayload($records, $presentationKey), $characterId, $tokenIds)
                    );
                }
            }
            if (isset($records['detached-combat'])) {
                queueOnlineDomainUpsert(
                    $pending,
                    $records,
                    'detached-combat',
                    removeOnlineCharacterFromCombat(applicationDomainPayload($records, 'detached-combat'), $characterId)
                );
            }
            foreach (array_keys($characterTokenRecords) as $tokenKey) {
                queueOnlineDomainDelete($pending, $records, $tokenKey);
            }
            $removedTimerCount = 0;
            if (isset($records['activity'])) {
                $activity = applicationDomainPayload($records, 'activity');
                $timers = is_array($activity['actionTimers'] ?? null) ? $activity['actionTimers'] : [];
                $removedTimers = array_values(array_filter(
                    $timers,
                    static fn (mixed $timer): bool => is_array($timer) && (string) ($timer['characterId'] ?? '') === $characterId
                ));
                if ($removedTimers !== []) {
                    $removedTimerCount = count($removedTimers);
                    $removedTimerIds = array_fill_keys(array_map(
                        static fn (array $timer): string => (string) ($timer['id'] ?? ''),
                        $removedTimers
                    ), true);
                    unset($removedTimerIds['']);
                    $activity['actionTimers'] = array_values(array_filter(
                        $timers,
                        static fn (mixed $timer): bool => !is_array($timer)
                            || (string) ($timer['characterId'] ?? '') !== $characterId
                    ));
                    $timerTombstones = array_values(array_filter(
                        is_array($activity['actionTimerTombstones'] ?? null) ? $activity['actionTimerTombstones'] : [],
                        static fn (mixed $entry): bool => !is_array($entry)
                            || !isset($removedTimerIds[(string) ($entry['id'] ?? '')])
                    ));
                    foreach (array_keys($removedTimerIds) as $timerId) {
                        $timerTombstones[] = ['id' => $timerId, 'deletedAt' => $deletedAt];
                    }
                    $activity['actionTimerTombstones'] = array_slice($timerTombstones, -1000);
                    queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
                }
            }
            queueOnlineDomainDelete($pending, $records, $characterKey);
            $result['character'] = [
                'id' => $characterId,
                'name' => $character['name'] ?? 'Fiche supprimée',
                'ownerPlayerId' => $ownerPlayerId,
                'deletedAt' => $deletedAt,
                'removedTokens' => $removedTokenCount,
                'removedTimers' => $removedTimerCount,
            ];
        } else {
            rejectOnlineCommand($connection, 400, 'Commande d’état inconnue ou refusée.', 'command_rejected');
        }

        $revision = $pending === []
            ? (int) $clock['globalRevision']
            : persistDomainChangesInTransaction($connection, $identity, $clock, array_values($pending));
        $connection->commit();
        cleanupApplicationDomainHistory($connection);
        if (in_array($command, ['roll', 'token.roll'], true) && is_array($result['roll'] ?? null)) {
            $discord = tryPostOnlineDiscordText($connection, $configuration, 'dice', onlineDiscordRollContent($result['roll']));
            $result['discordPosted'] = $discord['posted'];
            $result['discordError'] = $discord['error'];
        }
        sendJson(200, ['ok' => true, 'revision' => $revision, ...$result]);
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
}

function openOnlineConnection(PDO $connection): never
{
    requireMethod(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'POST')), ['POST']);
    $identity = requireIdentity($connection);
    $token = requestSessionToken();
    $rawId = random_bytes(16);
    $connectionId = rtrim(strtr(base64_encode($rawId), '+/', '-_'), '=');
    $statement = $connection->prepare(
        'INSERT INTO live_connections (connection_id, session_token_hash, expires_at) '
        . 'VALUES (:connection_id, :token_hash, :expires_at)'
    );
    $statement->bindValue(':connection_id', $rawId, PDO::PARAM_LOB);
    $statement->bindValue(':token_hash', tokenHash($token), PDO::PARAM_LOB);
    $statement->bindValue(':expires_at', utcAfter(XAR_CONNECTION_SECONDS));
    $statement->execute();
    sendJson(201, [
        'ok' => true,
        'connectionId' => $connectionId,
        'mode' => (string) $identity['effective_mode'],
        'heartbeatAfterSeconds' => 15,
    ]);
}

function connectionRawId(string $encoded): string
{
    if (preg_match('/^[A-Za-z0-9_-]{22}$/', $encoded) !== 1) {
        sendError(400, 'Connexion invalide.', 'invalid_connection');
    }
    $decoded = base64_decode(strtr($encoded . '==', '-_', '+/'), true);
    if (!is_string($decoded) || strlen($decoded) !== 16) {
        sendError(400, 'Connexion invalide.', 'invalid_connection');
    }
    return $decoded;
}

function touchOnlineConnection(PDO $connection, bool $delete = false): never
{
    requireIdentity($connection);
    $payload = readJsonBody(4096);
    $rawId = connectionRawId(trim((string) ($payload['connectionId'] ?? '')));
    $tokenHash = tokenHash(requestSessionToken());
    if ($delete) {
        $statement = $connection->prepare(
            'DELETE FROM live_connections WHERE connection_id = :connection_id AND session_token_hash = :token_hash'
        );
    } else {
        $statement = $connection->prepare(
            'UPDATE live_connections SET last_seen_at = UTC_TIMESTAMP(3), expires_at = :expires_at '
            . 'WHERE connection_id = :connection_id AND session_token_hash = :token_hash'
        );
        $statement->bindValue(':expires_at', utcAfter(XAR_CONNECTION_SECONDS));
    }
    $statement->bindValue(':connection_id', $rawId, PDO::PARAM_LOB);
    $statement->bindValue(':token_hash', $tokenHash, PDO::PARAM_LOB);
    $statement->execute();
    if (!$delete && $statement->rowCount() !== 1) {
        sendError(404, 'Connexion expirée.', 'connection_expired');
    }
    sendJson(200, ['ok' => true]);
}

function onlineEvents(PDO $connection, bool $headOnly = false): never
{
    $identity = requireIdentity($connection);
    ensureDomainStoreInitialized($connection);
    $clock = domainClockRecord($connection);
    $takeoverAt = dateTimestamp($identity['takeover_requested_at'] ?? null);
    $backendHandoff = backendSessionNeedsHandoff($identity);
    $includePresence = (string) ($_GET['presence'] ?? '1') !== '0';
    sendJson(200, [
        'ok' => true,
        'revision' => (int) $clock['globalRevision'],
        ...($includePresence ? ['presence' => liveOnlinePresence($connection)] : []),
        'takeoverRequested' => $backendHandoff || $takeoverAt >= time() - 30,
        ...($backendHandoff ? ['takeoverReason' => 'backend-update'] : []),
    ], $headOnly);
}

function onlineEventPollDelayMicroseconds(int $idleChecks): int
{
    if ($idleChecks <= 1) {
        return XAR_SSE_MINIMUM_POLL_MICROSECONDS;
    }
    if ($idleChecks <= 4) {
        return 500000;
    }
    if ($idleChecks <= 10) {
        return 750000;
    }
    if ($idleChecks <= 20) {
        return 1000000;
    }
    return XAR_SSE_MAXIMUM_POLL_MICROSECONDS;
}

function onlineEventReconnectDelayMilliseconds(string $connectionId): int
{
    $bytes = unpack('Nvalue', substr(hash('sha256', $connectionId, true), 0, 4));
    $value = is_array($bytes) ? (int) ($bytes['value'] ?? 0) : 0;
    return 250 + ($value % 651);
}

function writeOnlineEvent(string $event, array $payload, ?int $id = null): void
{
    if ($id !== null) {
        echo 'id: ' . max(0, $id) . "\n";
    }
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n\n";
    @flush();
}

function refreshOnlineConnectionRecord(PDO $connection, string $rawId, string $tokenHash): bool
{
    $statement = $connection->prepare(
        'UPDATE live_connections SET last_seen_at = UTC_TIMESTAMP(3), expires_at = :expires_at '
        . 'WHERE connection_id = :connection_id AND session_token_hash = :token_hash'
    );
    $statement->bindValue(':expires_at', utcAfter(XAR_CONNECTION_SECONDS));
    $statement->bindValue(':connection_id', $rawId, PDO::PARAM_LOB);
    $statement->bindValue(':token_hash', $tokenHash, PDO::PARAM_LOB);
    $statement->execute();
    if ($statement->rowCount() === 1) {
        return true;
    }
    // MySQL peut annoncer zéro ligne modifiée lorsque deux touches tombent dans
    // la même milliseconde. Vérifier alors l’existence évite un faux 404 au
    // moment où le client ouvre immédiatement son flux après la connexion.
    $exists = $connection->prepare(
        'SELECT 1 FROM live_connections WHERE connection_id = :connection_id '
        . 'AND session_token_hash = :token_hash LIMIT 1'
    );
    $exists->bindValue(':connection_id', $rawId, PDO::PARAM_LOB);
    $exists->bindValue(':token_hash', $tokenHash, PDO::PARAM_LOB);
    $exists->execute();
    return $exists->fetchColumn() !== false;
}

function streamOnlineEvents(PDO $connection): never
{
    requireMethod(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET']);
    $token = requestSessionToken();
    $identity = resolveSession($connection, $token);
    if (!is_array($identity)) {
        sendError(401, 'Connexion requise.', 'authentication_required');
    }
    $connectionId = trim((string) ($_GET['connectionId'] ?? ''));
    $rawId = connectionRawId($connectionId);
    $tokenHash = tokenHash($token);
    if (!refreshOnlineConnectionRecord($connection, $rawId, $tokenHash)) {
        sendError(404, 'Connexion expirée.', 'connection_expired');
    }
    $revisionHint = $_GET['revision'] ?? $_SERVER['HTTP_LAST_EVENT_ID'] ?? 0;
    $knownRevision = max(0, is_numeric($revisionHint) ? (int) $revisionHint : 0);
    ensureDomainStoreInitialized($connection);
    $currentRevision = (int) domainClockRecord($connection)['globalRevision'];
    $presence = liveOnlinePresence($connection);
    $presenceFingerprint = hash('sha256', json_encode($presence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    @ini_set('zlib.output_compression', '0');
    @set_time_limit(30);
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, no-transform');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');

    if ($currentRevision !== $knownRevision) {
        writeOnlineEvent('revision', ['revision' => $currentRevision], $currentRevision);
    }
    writeOnlineEvent('presence', ['presence' => $presence]);
    $takeoverAt = dateTimestamp($identity['takeover_requested_at'] ?? null);
    if (backendSessionNeedsHandoff($identity)) {
        writeOnlineEvent('session-takeover', ['reason' => 'backend-update']);
    } elseif ($takeoverAt >= time() - 30) {
        writeOnlineEvent('session-takeover', ['reason' => 'new-login']);
    }
    writeOnlineEvent('heartbeat', ['at' => (int) floor(microtime(true) * 1000)]);
    $knownRevision = $currentRevision;

    $startedAt = microtime(true);
    $nextPresenceAt = $startedAt + 6.0;
    $nextIdentityAt = $startedAt + 6.0;
    $nextConnectionTouchAt = $startedAt + 12.0;
    $nextHeartbeatAt = $startedAt + 10.0;
    $idleChecks = 0;
    $streamLifetime = 20.0 + (onlineEventReconnectDelayMilliseconds($connectionId) / 1000.0);
    $revisionStatement = $connection->prepare('SELECT global_revision FROM application_domain_clock WHERE singleton_id = 1');
    while (!connection_aborted() && microtime(true) - $startedAt < $streamLifetime) {
        usleep(onlineEventPollDelayMicroseconds($idleChecks));
        $now = microtime(true);
        $revisionStatement->execute();
        $nextRevision = (int) ($revisionStatement->fetchColumn() ?: 0);
        if ($nextRevision !== $knownRevision) {
            $knownRevision = $nextRevision;
            $idleChecks = 0;
            writeOnlineEvent('revision', ['revision' => $knownRevision], $knownRevision);
        } else {
            $idleChecks++;
        }
        if ($now >= $nextPresenceAt) {
            $nextPresenceAt = $now + 6.0;
            $nextPresence = liveOnlinePresence($connection);
            $nextFingerprint = hash('sha256', json_encode($nextPresence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            if (!hash_equals($presenceFingerprint, $nextFingerprint)) {
                $presenceFingerprint = $nextFingerprint;
                writeOnlineEvent('presence', ['presence' => $nextPresence]);
            }
        }
        if ($now >= $nextIdentityAt) {
            $nextIdentityAt = $now + 6.0;
            $identity = resolveSession($connection, $token, false);
            if (!is_array($identity)) {
                writeOnlineEvent('session-replaced', ['reason' => 'session-revoked']);
                break;
            }
            $takeoverAt = dateTimestamp($identity['takeover_requested_at'] ?? null);
            if (backendSessionNeedsHandoff($identity)) {
                writeOnlineEvent('session-takeover', ['reason' => 'backend-update']);
                break;
            }
            if ($takeoverAt >= time() - 30) {
                writeOnlineEvent('session-takeover', ['reason' => 'new-login']);
                break;
            }
        }
        if ($now >= $nextConnectionTouchAt) {
            $nextConnectionTouchAt = $now + 12.0;
            if (!refreshOnlineConnectionRecord($connection, $rawId, $tokenHash)) {
                writeOnlineEvent('session-replaced', ['reason' => 'connection-expired']);
                break;
            }
        }
        if ($now >= $nextHeartbeatAt) {
            $nextHeartbeatAt = $now + 10.0;
            writeOnlineEvent('heartbeat', ['at' => (int) floor($now * 1000)]);
        }
    }
    writeOnlineEvent('reconnect', ['afterMilliseconds' => onlineEventReconnectDelayMilliseconds($connectionId)]);
    exit;
}

function privateMediaDirectory(): string
{
    $configPath = privateConfigPath();
    if (!is_string($configPath) || $configPath === '') {
        throw new RuntimeException('media_directory_unavailable');
    }
    $directory = dirname($configPath) . DIRECTORY_SEPARATOR . 'media';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('media_directory_unavailable');
    }
    return $directory;
}

function cleanMediaFilename(string $value): string
{
    $value = trim(basename(str_replace('\\', '/', $value)));
    $value = preg_replace('/[^\p{L}\p{N}._ -]+/u', '-', $value) ?? '';
    $value = $value !== '' ? $value : 'media.bin';
    return function_exists('mb_substr') ? mb_substr($value, 0, 180, 'UTF-8') : substr($value, 0, 180);
}

function mediaExtension(string $contentType): string
{
    return match ($contentType) {
        'audio/mpeg' => '.mp3',
        'audio/ogg' => '.ogg',
        'audio/wav', 'audio/x-wav' => '.wav',
        'audio/mp4', 'audio/x-m4a' => '.m4a',
        'audio/aac' => '.aac',
        'audio/flac' => '.flac',
        'image/png' => '.png',
        'image/jpeg' => '.jpg',
        'image/webp' => '.webp',
        'image/gif' => '.gif',
        default => '',
    };
}

function storedAudioMatchesContentType(string $path, string $contentType): bool
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }
    $prefix = fread($handle, 16);
    fclose($handle);
    if (!is_string($prefix)) {
        return false;
    }
    $length = strlen($prefix);
    $mpegFrame = $length >= 2
        && ord($prefix[0]) === 0xff
        && (ord($prefix[1]) & 0xe0) === 0xe0
        && (ord($prefix[1]) & 0x06) !== 0;
    $aacFrame = $length >= 2
        && ord($prefix[0]) === 0xff
        && (ord($prefix[1]) & 0xf6) === 0xf0;
    return match ($contentType) {
        'audio/mpeg' => str_starts_with($prefix, 'ID3') || $mpegFrame,
        'audio/ogg' => str_starts_with($prefix, 'OggS'),
        'audio/wav', 'audio/x-wav' => str_starts_with($prefix, 'RIFF') && substr($prefix, 8, 4) === 'WAVE',
        'audio/mp4', 'audio/x-m4a' => substr($prefix, 4, 4) === 'ftyp',
        'audio/aac' => str_starts_with($prefix, 'ADIF') || $aacFrame,
        'audio/flac' => str_starts_with($prefix, 'fLaC'),
        default => false,
    };
}

function storedMediaMatchesContentType(string $path, string $contentType): bool
{
    if (str_starts_with($contentType, 'audio/')) {
        return storedAudioMatchesContentType($path, $contentType);
    }
    if (!str_starts_with($contentType, 'image/')) {
        return false;
    }
    $image = @getimagesize($path);
    if (!is_array($image)) {
        return false;
    }
    $width = (int) ($image[0] ?? 0);
    $height = (int) ($image[1] ?? 0);
    $detectedType = strtolower((string) ($image['mime'] ?? ''));
    return $detectedType === $contentType
        && $width > 0
        && $height > 0
        && $width <= 32768
        && $height <= 32768
        && $width * $height <= 120000000;
}

function mediaStorageLimits(array $configuration): array
{
    $gibibyte = 1024 * 1024 * 1024;
    $maximum = 200 * $gibibyte;
    $active = (int) ($configuration['media']['maximumTotalBytes'] ?? 20 * $gibibyte);
    $active = max($gibibyte, min($maximum, $active));
    $retainedDefault = min($maximum, $active + max(2 * $gibibyte, (int) floor($active / 4)));
    $retained = (int) ($configuration['media']['maximumRetainedBytes'] ?? $retainedDefault);
    return ['active' => $active, 'retained' => max($active, min($maximum, $retained))];
}

function mediaStorageUsage(PDO $connection): array
{
    $statement = $connection->query(
        'SELECT COALESCE(SUM(CASE WHEN pending_delete_at IS NULL THEN byte_size ELSE 0 END), 0) AS active_bytes, '
        . 'COALESCE(SUM(byte_size), 0) AS retained_bytes FROM media_objects'
    );
    $usage = $statement === false ? false : $statement->fetch();
    return [
        'active' => is_array($usage) ? (int) $usage['active_bytes'] : 0,
        'retained' => is_array($usage) ? (int) $usage['retained_bytes'] : 0,
    ];
}

function mediaQuotaViolation(array $usage, array $limits, int $additionalBytes): ?array
{
    if ((int) $usage['active'] + $additionalBytes > (int) $limits['active']) {
        return [
            'code' => 'media_storage_quota',
            'message' => 'L’espace média actif de la Régie est plein. Retirez les médias inutilisés avant de réessayer.',
        ];
    }
    if ((int) $usage['retained'] + $additionalBytes > (int) $limits['retained']) {
        return [
            'code' => 'media_retention_quota',
            'message' => 'Le plafond physique incluant la rétention de sécurité est atteint. Attendez la purge ou augmentez ce plafond privé.',
        ];
    }
    return null;
}

function uploadOnlineMedia(PDO $connection, array $configuration): never
{
    $identity = requireIdentity($connection);
    @set_time_limit(900);
    $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? 'application/octet-stream'))[0]));
    $extension = mediaExtension($contentType);
    if ($extension === '' || (!str_starts_with($contentType, 'audio/') && !str_starts_with($contentType, 'image/'))) {
        sendError(415, 'Type de média refusé.', 'media_type_rejected');
    }
    if (str_starts_with($contentType, 'audio/')
        && ((string) $identity['effective_mode'] !== 'gm' || (string) $identity['permanent_role'] !== 'gm')) {
        sendError(403, 'L’import audio est réservé à une session MJ.', 'gm_required');
    }
    $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($declared <= 0 || $declared > XAR_MEDIA_MAXIMUM_BYTES) {
        sendError(413, 'Le média est vide ou dépasse 300 Mo.', 'media_too_large');
    }
    $limits = mediaStorageLimits($configuration);
    $violation = mediaQuotaViolation(mediaStorageUsage($connection), $limits, $declared);
    if ($violation !== null) {
        sendError(507, $violation['message'], $violation['code']);
    }
    $directory = privateMediaDirectory();
    $id = randomToken(18);
    $storedName = $id . $extension;
    $temporary = $directory . DIRECTORY_SEPARATOR . $storedName . '.partial';
    $destination = $directory . DIRECTORY_SEPARATOR . $storedName;
    $input = fopen('php://input', 'rb');
    $output = fopen($temporary, 'xb');
    if ($input === false || $output === false) {
        if (is_resource($input)) {
            fclose($input);
        }
        if (is_resource($output)) {
            fclose($output);
        }
        @unlink($temporary);
        sendError(503, 'Stockage média indisponible.', 'media_unavailable');
    }
    $hash = hash_init('sha256');
    $size = 0;
    try {
        while (!feof($input)) {
            $chunk = fread($input, 1024 * 1024);
            if (!is_string($chunk) || $chunk === '') {
                break;
            }
            $size += strlen($chunk);
            if ($size > XAR_MEDIA_MAXIMUM_BYTES) {
                throw new LengthException('media_too_large');
            }
            hash_update($hash, $chunk);
            if (fwrite($output, $chunk) !== strlen($chunk)) {
                throw new RuntimeException('media_write_failed');
            }
        }
    } catch (Throwable $error) {
        fclose($input);
        fclose($output);
        @unlink($temporary);
        if ($error instanceof LengthException) {
            sendError(413, 'Le média dépasse 300 Mo.', 'media_too_large');
        }
        throw $error;
    }
    fclose($input);
    fclose($output);
    if ($size <= 0 || $size !== $declared) {
        @unlink($temporary);
        sendError(400, 'Le média reçu est incomplet.', 'media_upload_incomplete');
    }
    if (!rename($temporary, $destination)) {
        @unlink($temporary);
        sendError(503, 'Enregistrement du média impossible.', 'media_unavailable');
    }
    @chmod($destination, 0600);
    if (!storedMediaMatchesContentType($destination, $contentType)) {
        @unlink($destination);
        sendError(415, 'Le contenu du média ne correspond pas au format annoncé.', 'media_signature_mismatch');
    }
    $quotaLocked = false;
    $finalViolation = null;
    $uploadError = null;
    try {
        $lock = $connection->prepare("SELECT GET_LOCK('xar-regie-media-quota', 12)");
        $lock->execute();
        $quotaLocked = (int) $lock->fetchColumn() === 1;
        if (!$quotaLocked) {
            throw new RuntimeException('media_quota_lock_unavailable');
        }
        $finalViolation = mediaQuotaViolation(mediaStorageUsage($connection), $limits, $size);
        if ($finalViolation === null) {
            $insert = $connection->prepare(
                'INSERT INTO media_objects '
                . '(id, stored_name, original_name, content_type, byte_size, sha256, uploaded_by_account_id) '
                . 'VALUES (:id, :stored_name, :original_name, :content_type, :byte_size, :sha256, :uploaded_by)'
            );
            $insert->bindValue(':id', $id);
            $insert->bindValue(':stored_name', $storedName);
            $insert->bindValue(':original_name', cleanMediaFilename((string) ($_SERVER['HTTP_X_XAR_FILENAME'] ?? 'media' . $extension)));
            $insert->bindValue(':content_type', $contentType);
            $insert->bindValue(':byte_size', $size, PDO::PARAM_INT);
            $insert->bindValue(':sha256', hash_final($hash, true), PDO::PARAM_LOB);
            $insert->bindValue(':uploaded_by', (string) $identity['id']);
            $insert->execute();
        }
    } catch (Throwable $error) {
        $uploadError = $error;
    } finally {
        if ($quotaLocked) {
            try {
                $connection->query("SELECT RELEASE_LOCK('xar-regie-media-quota')");
            } catch (Throwable) {
            }
        }
    }
    if ($finalViolation !== null) {
        @unlink($destination);
        sendError(507, $finalViolation['message'], $finalViolation['code']);
    }
    if ($uploadError !== null) {
        @unlink($destination);
        throw $uploadError;
    }
    sendJson(201, ['ok' => true, 'mediaId' => $id, 'url' => '/media/' . $id, 'contentType' => $contentType, 'size' => $size]);
}

function mediaRecord(PDO $connection, string $id): ?array
{
    if (preg_match('/^[A-Za-z0-9_-]{24}$/', $id) !== 1) {
        return null;
    }
    $statement = $connection->prepare(
        'SELECT id, stored_name, original_name, content_type, byte_size, public_slug, published_at, pending_delete_at '
        . 'FROM media_objects WHERE id = :id LIMIT 1'
    );
    $statement->execute([':id' => $id]);
    $record = $statement->fetch();
    return is_array($record) ? $record : null;
}

function mediaDomainReferenceCount(PDO $connection, string $id, bool $includeHistory = false): int
{
    $reference = '%/media/' . $id . '%';
    $current = $connection->prepare(
        'SELECT COUNT(*) FROM application_domains WHERE CAST(payload AS CHAR) LIKE :reference'
    );
    $current->execute([':reference' => $reference]);
    $count = (int) $current->fetchColumn();
    $studio = $connection->prepare(
        'SELECT COUNT(*) FROM image_studio_messages '
        . 'WHERE media_id = :media_id AND owner_hidden_at IS NULL'
    );
    $studio->execute([':media_id' => $id]);
    $count += (int) $studio->fetchColumn();
    $studioReference = $connection->prepare(
        'SELECT COUNT(*) FROM image_studio_messages '
        . "WHERE JSON_CONTAINS(references_json, JSON_OBJECT('mediaId', :reference_media_id), '$') = 1"
    );
    $studioReference->execute([':reference_media_id' => $id]);
    $count += (int) $studioReference->fetchColumn();
    $catalog = $connection->prepare(
        'SELECT COUNT(*) FROM image_reference_catalog WHERE media_id = :media_id AND active = 1'
    );
    $catalog->execute([':media_id' => $id]);
    $count += (int) $catalog->fetchColumn();
    if (!$includeHistory) {
        return $count;
    }
    $history = $connection->prepare(
        'SELECT COUNT(*) FROM application_domain_history WHERE payload IS NOT NULL '
        . 'AND CAST(payload AS CHAR) LIKE :reference'
    );
    $history->execute([':reference' => $reference]);
    return $count + (int) $history->fetchColumn();
}

function cleanupExpiredMediaRetention(PDO $connection): void
{
    $orphanCandidates = $connection->query(
        'SELECT id FROM media_objects WHERE pending_delete_at IS NULL AND public_slug IS NULL '
        . 'AND created_at < DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 DAY) ORDER BY created_at LIMIT '
        . XAR_MEDIA_MAINTENANCE_CANDIDATES
    );
    foreach ($orphanCandidates === false ? [] : $orphanCandidates->fetchAll() as $candidate) {
        $candidateId = (string) ($candidate['id'] ?? '');
        if (preg_match('/^[A-Za-z0-9_-]{24}$/D', $candidateId) !== 1
            || mediaDomainReferenceCount($connection, $candidateId) > 0) {
            continue;
        }
        $mark = $connection->prepare(
            'UPDATE media_objects SET pending_delete_at = UTC_TIMESTAMP(3) '
            . 'WHERE id = :id AND pending_delete_at IS NULL AND public_slug IS NULL'
        );
        $mark->execute([':id' => $candidateId]);
    }
    $statement = $connection->query(
        'SELECT id, stored_name FROM media_objects WHERE pending_delete_at IS NOT NULL '
        . 'AND pending_delete_at < DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 30 DAY) '
        . 'ORDER BY pending_delete_at LIMIT ' . XAR_MEDIA_MAINTENANCE_CANDIDATES
    );
    foreach ($statement === false ? [] : $statement->fetchAll() as $record) {
        $id = (string) ($record['id'] ?? '');
        if (preg_match('/^[A-Za-z0-9_-]{24}$/D', $id) !== 1 || mediaDomainReferenceCount($connection, $id, true) > 0) {
            continue;
        }
        $delete = $connection->prepare(
            'DELETE FROM media_objects WHERE id = :id AND pending_delete_at IS NOT NULL '
            . 'AND pending_delete_at < DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 30 DAY)'
        );
        $delete->execute([':id' => $id]);
        if ($delete->rowCount() === 1) {
            @unlink(privateMediaDirectory() . DIRECTORY_SEPARATOR . basename((string) ($record['stored_name'] ?? '')));
        }
    }
}

function streamOnlineMedia(PDO $connection, string $id, bool $headOnly = false): never
{
    $identity = requireIdentity($connection);
    $studioOwner = imageStudioMediaOwner($connection, $id);
    if (is_array($studioOwner)) {
        assertImageStudioMediaAccess($connection, $identity, $id);
    }
    $record = mediaRecord($connection, $id);
    $path = is_array($record) ? privateMediaDirectory() . DIRECTORY_SEPARATOR . basename((string) $record['stored_name']) : '';
    if (!is_array($record) || $record['pending_delete_at'] !== null || !is_file($path)) {
        sendError(404, 'Média introuvable.', 'media_missing');
    }
    $size = (int) $record['byte_size'];
    $start = 0;
    $end = $size - 1;
    $status = 200;
    $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
    if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $match) === 1) {
        if ($match[1] === '' && $match[2] !== '') {
            $suffix = (int) $match[2];
            $start = max(0, $size - $suffix);
        } else {
            $start = $match[1] !== '' ? (int) $match[1] : 0;
            $end = $match[2] !== '' ? min((int) $match[2], $size - 1) : $size - 1;
        }
        if ($start < 0 || $end < $start || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        $status = 206;
    }
    http_response_code($status);
    header('Content-Type: ' . (string) $record['content_type']);
    header('Content-Length: ' . ($end - $start + 1));
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    if ($status === 206) {
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    if ($headOnly) {
        exit;
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        exit;
    }
    fseek($handle, $start);
    $remaining = $end - $start + 1;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, min(1024 * 1024, $remaining));
        if (!is_string($chunk) || $chunk === '') {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
        flush();
    }
    fclose($handle);
    exit;
}

function deleteOnlineMedia(PDO $connection, string $id): never
{
    $identity = requireGmIdentity($connection);
    $record = mediaRecord($connection, $id);
    if (!is_array($record)) {
        sendJson(200, ['ok' => true]);
    }
    if ($record['public_slug'] !== null && !(bool) ($identity['can_administrate'] ?? false)) {
        sendError(403, 'Seul un administrateur peut supprimer une image publiée sur le web.', 'administrator_required');
    }
    $studioOwner = imageStudioMediaOwner($connection, $id);
    if (is_array($studioOwner)
        && (string) $studioOwner['author_account_id'] !== (string) $identity['id']
        && !(bool) ($identity['can_administrate'] ?? false)) {
        sendError(403, 'Cette image appartient à un autre MJ.', 'media_forbidden');
    }
    ensureDomainStoreInitialized($connection);
    if (mediaDomainReferenceCount($connection, $id) > 0) {
        sendError(
            409,
            'Ce média est encore utilisé par la table. Retirez d’abord sa référence.',
            'media_still_referenced'
        );
    }
    $statement = $connection->prepare(
        'UPDATE media_objects SET pending_delete_at = UTC_TIMESTAMP(3), public_slug = NULL, published_at = NULL WHERE id = :id'
    );
    $statement->execute([':id' => $id]);
    sendJson(200, ['ok' => true, 'retainedUntil' => gmdate('c', time() + 30 * 86400)]);
}

function publishOnlineMedia(PDO $connection, string $id): never
{
    $identity = requireGmIdentity($connection);
    $record = mediaRecord($connection, $id);
    if (!is_array($record) || !str_starts_with((string) $record['content_type'], 'image/')) {
        sendError(404, 'Image introuvable.', 'media_missing');
    }
    $studioOwner = imageStudioMediaOwner($connection, $id);
    if (is_array($studioOwner)
        && (string) $studioOwner['author_account_id'] !== (string) $identity['id']
        && !(bool) ($identity['can_administrate'] ?? false)) {
        sendError(403, 'Cette image appartient à un autre MJ.', 'media_forbidden');
    }
    $slug = (string) ($record['public_slug'] ?? '');
    if ($slug === '') {
        for ($attempt = 0; $attempt < 4; $attempt += 1) {
            $slug = randomToken(16);
            try {
                $statement = $connection->prepare(
                    'UPDATE media_objects SET public_slug = :public_slug, published_at = UTC_TIMESTAMP(3), pending_delete_at = NULL '
                    . 'WHERE id = :id AND public_slug IS NULL'
                );
                $statement->execute([':public_slug' => $slug, ':id' => $id]);
                if ($statement->rowCount() === 1) {
                    break;
                }
                $record = mediaRecord($connection, $id);
                $slug = (string) ($record['public_slug'] ?? '');
                if ($slug !== '') {
                    break;
                }
            } catch (PDOException $error) {
                if ((string) $error->getCode() !== '23000' || $attempt === 3) {
                    throw $error;
                }
            }
        }
    }
    if (preg_match('/^[A-Za-z0-9_-]{22}$/', $slug) !== 1) {
        throw new RuntimeException('public_share_creation_failed');
    }
    sendJson(200, [
        'ok' => true,
        'mediaId' => $id,
        'shareUrl' => 'https://regie-xar-tsaroth.fr/share/' . $slug,
    ]);
}

function listPublishedMedia(PDO $connection, bool $headOnly = false): never
{
    requireAdministratorIdentity($connection);
    $statement = $connection->query(
        "SELECT id, original_name, content_type, byte_size, public_slug, published_at "
        . "FROM media_objects WHERE public_slug IS NOT NULL AND content_type LIKE 'image/%' "
        . 'ORDER BY published_at DESC, created_at DESC LIMIT 1000'
    );
    $rows = $statement === false ? [] : $statement->fetchAll();
    $media = array_map(static fn (array $row): array => [
        'mediaId' => (string) $row['id'],
        'originalName' => (string) $row['original_name'],
        'contentType' => (string) $row['content_type'],
        'size' => (int) $row['byte_size'],
        'publishedAt' => (string) $row['published_at'],
        'shareUrl' => 'https://regie-xar-tsaroth.fr/share/' . (string) $row['public_slug'],
    ], $rows);
    sendJson(200, ['ok' => true, 'media' => $media], $headOnly);
}

function publicMediaBySlug(PDO $connection, string $slug): ?array
{
    if (preg_match('/^[A-Za-z0-9_-]{22}$/', $slug) !== 1) {
        return null;
    }
    $statement = $connection->prepare(
        "SELECT id, stored_name, original_name, content_type, byte_size, public_slug, published_at "
        . "FROM media_objects WHERE public_slug = :public_slug AND content_type LIKE 'image/%' LIMIT 1"
    );
    $statement->execute([':public_slug' => $slug]);
    $record = $statement->fetch();
    return is_array($record) ? $record : null;
}

function streamPublicMedia(PDO $connection, string $slug, bool $headOnly): never
{
    $record = publicMediaBySlug($connection, $slug);
    $path = is_array($record) ? privateMediaDirectory() . DIRECTORY_SEPARATOR . basename((string) $record['stored_name']) : '';
    if (!is_array($record) || !is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo $headOnly ? '' : 'Image introuvable';
        exit;
    }
    header('Content-Type: ' . (string) $record['content_type']);
    header('Content-Length: ' . (string) $record['byte_size']);
    header('Cache-Control: public, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: default-src \'none\'');
    if (!$headOnly) {
        readfile($path);
    }
    exit;
}

function renderPublicMediaPage(PDO $connection, string $slug, bool $headOnly): never
{
    $record = publicMediaBySlug($connection, $slug);
    if (!is_array($record)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo $headOnly ? '' : 'Image introuvable';
        exit;
    }
    $title = htmlspecialchars((string) $record['original_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $imageUrl = '/share/' . rawurlencode($slug) . '/image';
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
    if ($headOnly) {
        exit;
    }
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><title>' . $title . ' — Xar Tsaroth</title>'
        . '<style>:root{color-scheme:dark;font-family:system-ui,sans-serif;background:#090812;color:#eee8da}*{box-sizing:border-box}'
        . 'body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:radial-gradient(circle at top,#2a1637,#090812 62%)}'
        . 'main{width:min(1200px,100%);text-align:center}h1{font:600 clamp(1.2rem,3vw,2rem) Georgia,serif;color:#e0b961}'
        . 'img{display:block;max-width:100%;max-height:78vh;margin:20px auto;border-radius:10px;box-shadow:0 20px 70px #000b}'
        . 'a{display:inline-block;padding:11px 16px;border:1px solid #a78043;border-radius:8px;color:#f0d79d;text-decoration:none}</style>'
        . '</head><body><main><h1>' . $title . '</h1><img src="' . $imageUrl . '" alt="' . $title . '">'
        . '<a href="' . $imageUrl . '">Ouvrir l’image seule</a></main></body></html>';
    exit;
}

function postOnlineDiscord(PDO $connection, array $configuration): never
{
    requireGmIdentity($connection);
    $payload = readJsonBody(40 * 1024 * 1024);
    $target = in_array(($payload['target'] ?? ''), ['images', 'dice', 'journal'], true)
        ? (string) $payload['target']
        : 'images';
    $record = settingsRecord($connection);
    $secrets = decryptSettingsSecrets($configuration, $record);
    $webhook = trim((string) ($secrets['discord'][$target] ?? ''));
    $public = jsonColumn($record['public_payload'] ?? null);
    if (($public['discord'][$target]['enabled'] ?? false) !== true || !validDiscordWebhook($webhook)) {
        sendError(409, 'Ce webhook Discord n’est pas configuré ou activé.', 'discord_not_configured');
    }
    if (!function_exists('curl_init')) {
        sendError(503, 'L’extension HTTPS requise pour Discord est indisponible.', 'discord_unavailable');
    }
    $content = substr(trim((string) ($payload['content'] ?? '')), 0, 1900);
    $imageDataUrl = (string) ($payload['imageDataUrl'] ?? '');
    $mediaId = trim((string) ($payload['mediaId'] ?? ''));
    $temporary = null;
    $attachmentPath = null;
    $attachmentType = '';
    $deleteAttachment = false;
    $headers = [];
    if ($mediaId !== '') {
        $media = mediaRecord($connection, $mediaId);
        $attachmentPath = is_array($media)
            ? privateMediaDirectory() . DIRECTORY_SEPARATOR . basename((string) $media['stored_name'])
            : null;
        $attachmentType = is_array($media) ? (string) $media['content_type'] : '';
        if (!is_array($media) || $media['pending_delete_at'] !== null
            || !str_starts_with($attachmentType, 'image/') || !is_file((string) $attachmentPath)) {
            sendError(404, 'L’image Discord est introuvable.', 'media_missing');
        }
        if ((int) $media['byte_size'] <= 0 || (int) $media['byte_size'] > 24 * 1024 * 1024) {
            sendError(413, 'L’image Discord est vide ou dépasse 24 Mo.', 'discord_image_too_large');
        }
    } elseif ($imageDataUrl !== '') {
        if (preg_match('#^data:(image/(?:png|jpeg|webp|gif));base64,([A-Za-z0-9+/=]+)$#s', $imageDataUrl, $match) !== 1) {
            sendError(400, 'L’image Discord est invalide.', 'invalid_discord_image');
        }
        $bytes = base64_decode($match[2], true);
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 24 * 1024 * 1024) {
            sendError(413, 'L’image Discord est vide ou dépasse 24 Mo.', 'discord_image_too_large');
        }
        $temporary = tempnam(sys_get_temp_dir(), 'xar-discord-');
        if (!is_string($temporary) || file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) {
            if (is_string($temporary)) {
                @unlink($temporary);
            }
            sendError(503, 'Préparation de l’image Discord impossible.', 'discord_unavailable');
        }
        $attachmentPath = $temporary;
        $attachmentType = $match[1];
        $deleteAttachment = true;
    }
    if (is_string($attachmentPath)) {
        $postFields = [
            'payload_json' => json_encode(['content' => $content, 'allowed_mentions' => ['parse' => []]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'files[0]' => new CURLFile($attachmentPath, $attachmentType, cleanMediaFilename((string) ($payload['filename'] ?? 'xar-tsaroth.png'))),
        ];
    } else {
        if ($content === '') {
            sendError(400, 'Le message Discord est vide.', 'invalid_discord_message');
        }
        $postFields = json_encode(['content' => $content, 'allowed_mentions' => ['parse' => []]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
    }
    $request = curl_init($webhook . (str_contains($webhook, '?') ? '&' : '?') . 'wait=true');
    if ($request === false) {
        if ($deleteAttachment && is_string($temporary)) {
            @unlink($temporary);
        }
        sendError(503, 'Connexion Discord indisponible.', 'discord_unavailable');
    }
    $options = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    }
    curl_setopt_array($request, $options);
    $response = curl_exec($request);
    $status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    $failed = $response === false;
    curl_close($request);
    if ($deleteAttachment && is_string($temporary)) {
        @unlink($temporary);
    }
    if ($failed || $status < 200 || $status >= 300) {
        sendError(502, 'Discord a refusé ou interrompu l’envoi.', 'discord_rejected');
    }
    $message = null;
    if (is_string($response) && $response !== '') {
        try {
            $decoded = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
            $message = is_array($decoded) ? $decoded : null;
        } catch (JsonException) {
            $message = null;
        }
    }
    sendJson(200, ['ok' => true, 'message' => $message]);
}

function handleOnlineRoute(PDO $connection, array $configuration, string $route, string $method, bool $headOnly): bool
{
    if (handleHealthOverlayManagementRoute($connection, $route, $method, $headOnly)) {
        return true;
    }
    if (handleImageStudioRoute($connection, $route, $method, $headOnly)) {
        return true;
    }
    if (handleDomainRoute($connection, $route, $method, $headOnly)) {
        return true;
    }
    if ($route === '/api/v1/settings') {
        if ($method === 'GET' || $method === 'HEAD') {
            requireAdministratorIdentity($connection);
            sendJson(200, ['ok' => true, 'settings' => readOnlineSettings($connection, $configuration)], $headOnly);
        }
        if ($method === 'PUT') {
            updateOnlineSettings($connection, $configuration);
        }
        requireMethod($method, ['GET', 'HEAD', 'PUT']);
    }
    if ($route === '/api/v1/bridge-settings') {
        if ($method === 'GET' || $method === 'HEAD') {
            $identity = requireGmIdentity($connection);
            sendJson(200, ['ok' => true, 'bridge' => readBridgeSettings($connection, $configuration, $identity)], $headOnly);
        }
        if ($method === 'PUT') {
            updateBridgeSettings($connection, $configuration);
        }
        requireMethod($method, ['GET', 'HEAD', 'PUT']);
    }
    if ($route === '/api/v1/integrations/discord') {
        if ($method === 'GET' || $method === 'HEAD') {
            readOnlineDiscordStatus($connection, $configuration, $headOnly);
        }
        if ($method === 'POST') {
            postOnlineDiscord($connection, $configuration);
        }
        requireMethod($method, ['GET', 'HEAD', 'POST']);
    }
    if ($route === '/api/v1/state') {
        if ($method === 'GET' || $method === 'HEAD') {
            readOnlineState($connection, $headOnly);
        }
        if ($method === 'PUT') {
            rejectLegacyOnlineState($connection);
        }
        requireMethod($method, ['GET', 'HEAD', 'PUT']);
    }
    if ($route === '/api/v1/state/command') {
        requireMethod($method, ['POST']);
        commandOnlineState($connection, $configuration);
    }
    if ($route === '/api/v1/connections') {
        if ($method === 'POST') {
            openOnlineConnection($connection);
        }
        if ($method === 'PATCH') {
            touchOnlineConnection($connection, false);
        }
        if ($method === 'DELETE') {
            touchOnlineConnection($connection, true);
        }
        requireMethod($method, ['POST', 'PATCH', 'DELETE']);
    }
    if ($route === '/api/v1/events') {
        requireMethod($method, ['GET', 'HEAD']);
        onlineEvents($connection, $headOnly);
    }
    if ($route === '/api/v1/events/stream') {
        streamOnlineEvents($connection);
    }
    if ($route === '/api/v1/media') {
        if ($method === 'POST') {
            uploadOnlineMedia($connection, $configuration);
        }
        requireMethod($method, ['POST']);
    }
    if ($route === '/api/v1/shared-media') {
        requireMethod($method, ['GET', 'HEAD']);
        listPublishedMedia($connection, $headOnly);
    }
    if (preg_match('#^/api/v1/media/([A-Za-z0-9_-]{24})/publish$#', $route, $match) === 1) {
        requireMethod($method, ['POST']);
        publishOnlineMedia($connection, $match[1]);
    }
    if (preg_match('#^/api/v1/media/([A-Za-z0-9_-]{24})$#', $route, $match) === 1) {
        if ($method === 'GET' || $method === 'HEAD') {
            streamOnlineMedia($connection, $match[1], $headOnly);
        }
        if ($method === 'DELETE') {
            deleteOnlineMedia($connection, $match[1]);
        }
        requireMethod($method, ['GET', 'HEAD', 'DELETE']);
    }
    if (preg_match('#^/share/([A-Za-z0-9_-]{22})/image$#', $route, $match) === 1) {
        requireMethod($method, ['GET', 'HEAD']);
        streamPublicMedia($connection, $match[1], $headOnly);
    }
    if (preg_match('#^/share/([A-Za-z0-9_-]{22})$#', $route, $match) === 1) {
        requireMethod($method, ['GET', 'HEAD']);
        renderPublicMediaPage($connection, $match[1], $headOnly);
    }
    return false;
}
