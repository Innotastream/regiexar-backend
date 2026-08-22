<?php

declare(strict_types=1);

const XAR_CONNECTION_SECONDS = 45;
const XAR_STATE_MAXIMUM_BYTES = 64 * 1024 * 1024;
const XAR_MEDIA_MAXIMUM_BYTES = 300 * 1024 * 1024;

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

function settingsEncryptionKey(array $configuration): string
{
    $password = (string) ($configuration['database']['password'] ?? '');
    if ($password === '') {
        throw new RuntimeException('settings_key_unavailable');
    }
    return hash_hkdf('sha256', $password, 32, 'xar-tsaroth-regie-settings-v1');
}

function decryptSettingsSecrets(array $configuration, ?array $record): array
{
    if (!is_array($record) || empty($record['encrypted_secrets'])) {
        return [];
    }
    $plain = openssl_decrypt(
        (string) $record['encrypted_secrets'],
        'aes-256-gcm',
        settingsEncryptionKey($configuration),
        OPENSSL_RAW_DATA,
        (string) ($record['secret_nonce'] ?? ''),
        (string) ($record['secret_tag'] ?? '')
    );
    if (!is_string($plain)) {
        throw new RuntimeException('settings_decryption_failed');
    }
    return jsonColumn($plain);
}

function encryptSettingsSecrets(array $configuration, array $secrets): array
{
    $nonce = random_bytes(12);
    $tag = '';
    $plain = json_encode($secrets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $ciphertext = openssl_encrypt(
        $plain,
        'aes-256-gcm',
        settingsEncryptionKey($configuration),
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

function updateOnlineSettings(PDO $connection, array $configuration): never
{
    $identity = requireAdministratorIdentity($connection);
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

function applicationStateRecord(PDO $connection, bool $forUpdate = false): ?array
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
        if (is_string($key) && preg_match('/^(?:password|passwordVerifier|sessionToken|accessToken|token|webhook|apiKey|secretKey|privateKey)$/i', $key) === 1) {
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
        if (is_string($key) && preg_match('/^(?:secret|gmNotes|password|passwordVerifier|sessionToken|accessToken|token|webhook|apiKey|secretKey|privateKey)$/i', $key) === 1) {
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

function storeApplicationState(PDO $connection, array $identity, array $state, ?int $expectedRevision = null): array
{
    if (stateContainsForbiddenSecret($state)) {
        sendError(400, 'L’état partagé contient une donnée secrète interdite.', 'secret_in_state');
    }
    $state = sanitizeStateImageReferences($state);
    $schemaVersion = max(1, (int) ($state['schemaVersion'] ?? 1));
    $connection->beginTransaction();
    try {
        $current = applicationStateRecord($connection, true);
        $currentRevision = (int) ($current['revision'] ?? 0);
        $currentSchemaVersion = (int) ($current['schemaVersion'] ?? 0);
        if ($currentSchemaVersion > 0 && $schemaVersion < $currentSchemaVersion) {
            $connection->rollBack();
            sendJson(409, [
                'ok' => false,
                'error' => 'Cette table utilise un schéma plus récent. Mettez la Régie à jour avant de la modifier.',
                'code' => 'schema_downgrade',
                'requiredSchemaVersion' => $currentSchemaVersion,
            ]);
        }
        if ($expectedRevision !== null && $expectedRevision !== $currentRevision) {
            $connection->rollBack();
            sendJson(409, [
                'ok' => false,
                'error' => 'L’état en ligne a changé. Rechargez la table avant de renvoyer cette modification.',
                'code' => 'revision_conflict',
                'revision' => $currentRevision,
            ]);
        }
        $nextRevision = $currentRevision + 1;
        $state['schemaVersion'] = $schemaVersion;
        $state['revision'] = $nextRevision;
        $state['updatedAt'] = gmdate('c');
        $encoded = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > XAR_STATE_MAXIMUM_BYTES) {
            $connection->rollBack();
            sendError(413, 'L’état partagé dépasse 64 Mo. Importez les médias séparément.', 'state_too_large');
        }
        $statement = $connection->prepare(
            'INSERT INTO application_state '
            . '(singleton_id, schema_version, revision, payload, updated_by_account_id) '
            . 'VALUES (1, :schema_version, :revision, :payload, :updated_by) '
            . 'ON DUPLICATE KEY UPDATE schema_version = VALUES(schema_version), revision = VALUES(revision), '
            . 'payload = VALUES(payload), updated_by_account_id = VALUES(updated_by_account_id)'
        );
        $statement->execute([
            ':schema_version' => $schemaVersion,
            ':revision' => $nextRevision,
            ':payload' => $encoded,
            ':updated_by' => (string) $identity['id'],
        ]);
        $connection->commit();
        return $state;
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
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
    $tokens = [];
    foreach (($map['tokens'] ?? []) as $token) {
        if (!is_array($token) || ($token['hidden'] ?? false) === true) {
            continue;
        }
        $owned = ($token['controllerPlayerId'] ?? null) === $accountId;
        $details = $owned || ($token['revealDetailsToPlayers'] ?? false) === true;
        $visible = [
            'id' => $token['id'] ?? null,
            'characterId' => $token['characterId'] ?? null,
            'name' => $token['name'] ?? 'Token',
            'image' => $token['image'] ?? null,
            'color' => $token['color'] ?? '#8d72cb',
            'x' => (float) ($token['x'] ?? 50),
            'y' => (float) ($token['y'] ?? 50),
            'size' => (float) ($token['size'] ?? 30),
            'initiative' => $token['initiative'] ?? null,
            'detailsVisible' => $details,
            'ownedByYou' => $owned,
            'controllable' => $owned && !$paused && (!$active || ($token['id'] ?? null) === $activeTokenId),
        ];
        if ($details) {
            foreach (['hp', 'maxHp', 'mana', 'maxMana', 'damageDice', 'armor', 'speed', 'stats', 'initiativeBonus', 'condition', 'notes'] as $key) {
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
    $map['tokens'] = $tokens;
    $initiative['order'] = $visibleOrder;
    $initiative['currentTokenId'] = in_array($activeTokenId, $visibleOrder, true) ? $activeTokenId : null;
    $initiative['currentIndex'] = $initiative['currentTokenId'] === null ? 0 : array_search($activeTokenId, $visibleOrder, true);
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
        'tacticalLocked' => $paused,
        'playerMovementMode' => $active ? 'combat' : 'free',
        'map' => $map,
        'initiative' => $initiative,
        'activeScene' => stripForbiddenPlayerData($paused
            ? ($fullState['tacticalSync']['publishedActiveScene'] ?? null)
            : ($fullState['activeScene'] ?? null)),
        'rolls' => stripForbiddenPlayerData(array_slice($rolls, 0, 30)),
        'actionTimers' => stripForbiddenPlayerData(array_values(array_filter($fullState['actionTimers'] ?? [], static fn (mixed $timer): bool =>
            is_array($timer) && (($timer['visibility'] ?? '') === 'public' || ($timer['ownerPlayerId'] ?? null) === $accountId)
        ))),
        'mapPings' => array_values(array_filter($fullState['mapPings'] ?? [], static fn (mixed $ping): bool =>
            is_array($ping) && (int) ($ping['expiresAt'] ?? 0) > (int) floor(microtime(true) * 1000)
        )),
        'tracks' => stripForbiddenPlayerData($fullState['tracks'] ?? []),
        'audio' => stripForbiddenPlayerData($fullState['audio'] ?? null),
    ];
}

function readOnlineState(PDO $connection, bool $headOnly = false): never
{
    $identity = requireIdentity($connection);
    $record = applicationStateRecord($connection);
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

function replaceOnlineState(PDO $connection): never
{
    $identity = requireGmIdentity($connection);
    $payload = readJsonBody(XAR_STATE_MAXIMUM_BYTES);
    $state = $payload['state'] ?? null;
    if (!is_array($state)) {
        sendError(400, 'État partagé invalide.', 'invalid_state');
    }
    $expected = array_key_exists('expectedRevision', $payload) ? (int) $payload['expectedRevision'] : null;
    $stored = storeApplicationState($connection, $identity, $state, $expected);
    $compact = (string) ($_GET['compact'] ?? '') === '1';
    sendJson(200, [
        'ok' => true,
        ...(!$compact ? ['state' => $stored] : []),
        'revision' => (int) $stored['revision'],
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

function ensureOnlinePlayerIdentity(array &$state, array $identity): void
{
    $accountId = (string) $identity['id'];
    $state['players'] = is_array($state['players'] ?? null) ? $state['players'] : [];
    if (findEntryIndex($state['players'], $accountId) >= 0) {
        return;
    }
    $aliases = rosterAliases($identity);
    $pendingIndex = -1;
    foreach ($state['players'] as $index => $player) {
        if (!is_array($player)) {
            continue;
        }
        $name = strtolower(trim((string) ($player['name'] ?? '')));
        $id = strtolower(trim((string) ($player['id'] ?? '')));
        foreach ($aliases as $alias) {
            if ($name === $alias || $id === 'player-' . preg_replace('/[^a-z0-9]+/', '-', $alias)) {
                $pendingIndex = (int) $index;
                break 2;
            }
        }
    }
    if ($pendingIndex < 0) {
        $state['players'][] = [
            'id' => $accountId,
            'name' => (string) $identity['display_name'],
            '_updatedAt' => (int) floor(microtime(true) * 1000),
        ];
        return;
    }

    $oldId = (string) ($state['players'][$pendingIndex]['id'] ?? '');
    $state['players'][$pendingIndex]['id'] = $accountId;
    $state['players'][$pendingIndex]['_updatedAt'] = (int) floor(microtime(true) * 1000);
    if (is_array($state['characters'] ?? null)) {
        foreach ($state['characters'] as &$character) {
            if (is_array($character) && ($character['ownerPlayerId'] ?? null) === $oldId) {
                $character['ownerPlayerId'] = $accountId;
                $character['_updatedAt'] = (int) floor(microtime(true) * 1000);
            }
        }
        unset($character);
    }
    if (is_array($state['actionTimers'] ?? null)) {
        foreach ($state['actionTimers'] as &$timer) {
            if (is_array($timer) && ($timer['ownerPlayerId'] ?? null) === $oldId) {
                $timer['ownerPlayerId'] = $accountId;
                $timer['updatedAt'] = gmdate('c');
            }
        }
        unset($timer);
    }
    $rekeyMap = static function (mixed &$map) use ($oldId, $accountId): void {
        if (!is_array($map) || !is_array($map['tokens'] ?? null)) {
            return;
        }
        foreach ($map['tokens'] as &$token) {
            if (is_array($token) && ($token['controllerPlayerId'] ?? null) === $oldId) {
                $token['controllerPlayerId'] = $accountId;
                $token['_updatedAt'] = (int) floor(microtime(true) * 1000);
            }
        }
        unset($token);
    };
    if (array_key_exists('map', $state)) {
        $rekeyMap($state['map']);
    }
    if (is_array($state['tacticalSync'] ?? null) && array_key_exists('publishedMap', $state['tacticalSync'])) {
        $rekeyMap($state['tacticalSync']['publishedMap']);
    }
    if (is_array($state['scenes'] ?? null)) {
        foreach ($state['scenes'] as &$scene) {
            if (is_array($scene) && is_array($scene['combat'] ?? null) && array_key_exists('map', $scene['combat'])) {
                $rekeyMap($scene['combat']['map']);
            }
        }
        unset($scene);
    }
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

function playerCharacterPatch(array $current, array $patch): array
{
    $allowed = [
        'name', 'surname', 'givenName', 'race', 'age', 'className', 'advancedClass', 'profession',
        'previousProfession', 'pronouns', 'portrait', 'color', 'resources', 'stats', 'fatigue', 'morale',
        'armor', 'speed', 'initiativeBonus', 'conditions', 'publicNotes', 'armorText', 'weaponText',
        'passives', 'skills', 'specialSkills', 'languages', 'inventory', 'personalAdvantageStock',
        'shortcuts', 'linkedTokens', 'characterSchema', 'characterSchemaVersion',
    ];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $patch)) {
            if ($key === 'portrait') {
                $current[$key] = normalizePersistedImageReference($patch[$key]);
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
    $current['_updatedAt'] = (int) floor(microtime(true) * 1000);
    return $current;
}

function onlineRollFormula(string $formula): array
{
    $normalized = strtolower(str_replace(' ', '', substr($formula, 0, 100)));
    if ($normalized === '' || preg_match('/^[+-]?((\d*)d\d+|\d+)([+-]((\d*)d\d+|\d+))*$/', $normalized) !== 1) {
        throw new InvalidArgumentException('Formule de jet invalide.');
    }
    preg_match_all('/[+-]?[^+-]+/', $normalized, $matches);
    $total = 0;
    $parts = [];
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
        } else {
            $value = (int) $clean * $sign;
            $total += $value;
            $parts[] = ($value >= 0 && $parts !== [] ? '+' : '') . (string) $value;
        }
    }
    return ['total' => $total, 'breakdown' => implode(' ', $parts), 'formula' => $normalized];
}

function commandOnlineState(PDO $connection): never
{
    $identity = requireIdentity($connection);
    $payload = readJsonBody(10 * 1024 * 1024);
    $command = (string) ($payload['command'] ?? '');
    $arguments = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
    $accountId = (string) $identity['id'];
    $isGm = (string) $identity['effective_mode'] === 'gm' && (string) $identity['permanent_role'] === 'gm';
    $connection->beginTransaction();
    try {
        $record = applicationStateRecord($connection, true);
        if (!is_array($record) || $record['state'] === []) {
            $connection->rollBack();
            sendError(409, 'Aucune table en ligne n’a encore été ouverte par un MJ.', 'state_missing');
        }
        $state = $record['state'];
        $result = [];
        if ($command === 'ensure-player') {
            ensureOnlinePlayerIdentity($state, $identity);
        } elseif ($command === 'preferences.update') {
            $state['playerPreferences'] = is_array($state['playerPreferences'] ?? null) ? $state['playerPreferences'] : [];
            $currentPreferences = is_array($state['playerPreferences'][$accountId] ?? null)
                ? $state['playerPreferences'][$accountId]
                : [];
            foreach (['musicMuted', 'ambienceMuted'] as $key) {
                if (array_key_exists($key, $arguments) && is_bool($arguments[$key])) {
                    $currentPreferences[$key] = $arguments[$key];
                }
            }
            if (array_key_exists('activePage', $arguments)) {
                $currentPreferences['activePage'] = $arguments['activePage'] === 'characters' ? 'characters' : 'map';
            }
            $state['playerPreferences'][$accountId] = [
                'musicMuted' => ($currentPreferences['musicMuted'] ?? false) === true,
                'ambienceMuted' => ($currentPreferences['ambienceMuted'] ?? false) === true,
                'activePage' => ($currentPreferences['activePage'] ?? '') === 'characters' ? 'characters' : 'map',
            ];
            $result['preferences'] = $state['playerPreferences'][$accountId];
        } elseif ($command === 'character.create') {
            $state['characters'] = is_array($state['characters'] ?? null) ? $state['characters'] : [];
            $character = cleanPlayerCharacter(is_array($arguments['character'] ?? null) ? $arguments['character'] : [], $accountId);
            if (findEntryIndex($state['characters'], (string) $character['id']) >= 0) {
                $connection->rollBack();
                sendError(409, 'Cette fiche existe déjà.', 'character_exists');
            }
            $state['characters'][] = $character;
            $result['character'] = visibleCharacter($character);
        } elseif ($command === 'character.patch') {
            $state['characters'] = is_array($state['characters'] ?? null) ? $state['characters'] : [];
            $index = findEntryIndex($state['characters'], (string) ($arguments['characterId'] ?? ''));
            if ($index < 0 || ($state['characters'][$index]['ownerPlayerId'] ?? null) !== $accountId) {
                $connection->rollBack();
                sendError(403, 'Cette fiche ne vous appartient pas.', 'character_forbidden');
            }
            $state['characters'][$index] = playerCharacterPatch($state['characters'][$index], is_array($arguments['patch'] ?? null) ? $arguments['patch'] : []);
            $result['character'] = visibleCharacter($state['characters'][$index]);
        } elseif ($command === 'token.move') {
            $tokens = is_array($state['map']['tokens'] ?? null) ? $state['map']['tokens'] : [];
            $index = findEntryIndex($tokens, (string) ($arguments['tokenId'] ?? ''));
            if ($index < 0 || ($tokens[$index]['controllerPlayerId'] ?? null) !== $accountId || ($tokens[$index]['hidden'] ?? false) === true) {
                $connection->rollBack();
                sendError(403, 'Déplacement refusé.', 'token_forbidden');
            }
            if (($state['tacticalSync']['paused'] ?? false) === true) {
                $connection->rollBack();
                sendError(423, 'La table est temporairement verrouillée.', 'table_locked');
            }
            if (($state['initiative']['active'] ?? false) === true) {
                $order = $state['initiative']['order'] ?? [];
                $activeId = $order[(int) ($state['initiative']['currentIndex'] ?? 0)] ?? null;
                if ($activeId !== ($tokens[$index]['id'] ?? null)) {
                    $connection->rollBack();
                    sendError(403, 'Ce n’est pas le tour de ce token.', 'turn_required');
                }
            }
            $tokens[$index]['x'] = max(0.0, min(100.0, (float) ($arguments['x'] ?? $tokens[$index]['x'] ?? 50)));
            $tokens[$index]['y'] = max(0.0, min(100.0, (float) ($arguments['y'] ?? $tokens[$index]['y'] ?? 50)));
            $tokens[$index]['_movedAt'] = (int) floor(microtime(true) * 1000);
            $state['map']['tokens'] = $tokens;
            $result['token'] = $tokens[$index];
        } elseif ($command === 'ping') {
            $now = (int) floor(microtime(true) * 1000);
            $ping = [
                'id' => 'ping-' . randomToken(9),
                'x' => max(0.0, min(100.0, (float) ($arguments['x'] ?? 50))),
                'y' => max(0.0, min(100.0, (float) ($arguments['y'] ?? 50))),
                'sceneId' => $state['activeSceneId'] ?? null,
                'createdAt' => $now,
                'expiresAt' => $now + 4200,
                'author' => (string) $identity['display_name'],
                'color' => '#8d72cb',
            ];
            $state['mapPings'] = array_slice(array_values(array_filter($state['mapPings'] ?? [], static fn (mixed $entry): bool => is_array($entry) && (int) ($entry['expiresAt'] ?? 0) > $now)), -19);
            $state['mapPings'][] = $ping;
            $result['ping'] = $ping;
        } elseif ($command === 'token.roll') {
            $tokens = is_array($state['map']['tokens'] ?? null) ? $state['map']['tokens'] : [];
            $tokenIndex = findEntryIndex($tokens, (string) ($arguments['tokenId'] ?? ''));
            if ($tokenIndex < 0 || ($tokens[$tokenIndex]['controllerPlayerId'] ?? null) !== $accountId
                || ($tokens[$tokenIndex]['hidden'] ?? false) === true) {
                $connection->rollBack();
                sendError(403, 'Ce token ne vous appartient pas.', 'token_forbidden');
            }
            $token = $tokens[$tokenIndex];
            $kind = in_array(($arguments['kind'] ?? ''), ['stat', 'initiative', 'damage', 'custom'], true)
                ? (string) $arguments['kind']
                : 'custom';
            $label = 'Test personnalisé';
            $formula = '1d100';
            if ($kind === 'stat') {
                $stats = is_array($token['stats'] ?? null) ? $token['stats'] : [];
                $statIndex = findEntryIndex($stats, (string) ($arguments['statId'] ?? ''));
                if ($statIndex < 0 || !is_numeric($stats[$statIndex]['value'] ?? null)) {
                    $connection->rollBack();
                    sendError(404, 'Cette statistique n’existe plus sur le token.', 'token_stat_missing');
                }
                $value = (int) $stats[$statIndex]['value'];
                $label = substr(trim((string) ($stats[$statIndex]['label'] ?? 'Statistique')), 0, 120);
                $formula = '1d100' . ($value >= 0 ? '+' : '') . $value;
            } elseif ($kind === 'initiative') {
                $bonus = is_numeric($token['initiativeBonus'] ?? null) ? (int) $token['initiativeBonus'] : 0;
                $label = 'Initiative';
                $formula = '1d100' . ($bonus >= 0 ? '+' : '') . $bonus;
            } elseif ($kind === 'damage') {
                $label = 'Dégâts';
                $formula = substr(str_replace(' ', '', (string) ($token['damageDice'] ?? '')), 0, 80);
                if ($formula === '') {
                    $connection->rollBack();
                    sendError(400, 'Les dégâts de ce token ne sont pas renseignés.', 'token_damage_missing');
                }
            } else {
                $label = substr(trim((string) ($arguments['label'] ?? 'Test personnalisé')), 0, 120);
                $formula = substr(str_replace(' ', '', (string) ($arguments['formula'] ?? '1d100')), 0, 80);
                if ($label === '') {
                    $connection->rollBack();
                    sendError(400, 'Donnez un intitulé au jet.', 'invalid_roll_label');
                }
            }
            try {
                $rolled = onlineRollFormula($formula);
            } catch (InvalidArgumentException $error) {
                $connection->rollBack();
                sendError(400, $error->getMessage(), 'invalid_roll');
            }
            $roll = [
                'id' => randomToken(12),
                'label' => $label,
                'characterName' => substr((string) ($token['name'] ?? 'Token'), 0, 120),
                'formula' => $rolled['formula'],
                'total' => $rolled['total'],
                'breakdown' => $rolled['breakdown'],
                'visibility' => 'public',
                'revealed' => true,
                'rollerName' => (string) $identity['display_name'],
                'rollerRole' => 'player',
                'createdAt' => gmdate('c'),
            ];
            $state['rolls'] = is_array($state['rolls'] ?? null) ? $state['rolls'] : [];
            array_unshift($state['rolls'], $roll);
            $state['rolls'] = array_slice($state['rolls'], 0, 100);
            $initiativeUpdated = false;
            if ($kind === 'initiative' && ($state['tacticalSync']['paused'] ?? false) !== true) {
                $tokens[$tokenIndex]['initiative'] = $rolled['total'];
                $tokens[$tokenIndex]['_updatedAt'] = (int) floor(microtime(true) * 1000);
                $state['map']['tokens'] = $tokens;
                $initiative = is_array($state['initiative'] ?? null) ? $state['initiative'] : [];
                $order = is_array($initiative['order'] ?? null) ? $initiative['order'] : [];
                $currentTokenId = ($initiative['active'] ?? false) === true
                    ? ($order[(int) ($initiative['currentIndex'] ?? 0)] ?? null)
                    : null;
                $order = array_values(array_filter($order, static fn (mixed $tokenId): bool =>
                    findEntryIndex($tokens, (string) $tokenId) >= 0
                ));
                if (!in_array($token['id'], $order, true)) {
                    $order[] = $token['id'];
                }
                usort($order, static function (mixed $leftId, mixed $rightId) use ($tokens): int {
                    $leftIndex = findEntryIndex($tokens, (string) $leftId);
                    $rightIndex = findEntryIndex($tokens, (string) $rightId);
                    $left = $leftIndex >= 0 ? ($tokens[$leftIndex]['initiative'] ?? -1) : -1;
                    $right = $rightIndex >= 0 ? ($tokens[$rightIndex]['initiative'] ?? -1) : -1;
                    return (int) $right <=> (int) $left;
                });
                $initiative['order'] = $order;
                $initiative['currentIndex'] = $currentTokenId !== null && in_array($currentTokenId, $order, true)
                    ? (int) array_search($currentTokenId, $order, true)
                    : 0;
                $initiative['_updatedAt'] = (int) floor(microtime(true) * 1000);
                $state['initiative'] = $initiative;
                $initiativeUpdated = true;
            }
            $result['roll'] = $roll;
            $result['initiativeUpdated'] = $initiativeUpdated;
        } elseif ($command === 'roll') {
            $characters = is_array($state['characters'] ?? null) ? $state['characters'] : [];
            $characterIndex = findEntryIndex($characters, (string) ($arguments['characterId'] ?? ''));
            if ($characterIndex < 0 || ($characters[$characterIndex]['ownerPlayerId'] ?? null) !== $accountId) {
                $connection->rollBack();
                sendError(403, 'Ce personnage ne vous appartient pas.', 'character_forbidden');
            }
            $shortcutIndex = findEntryIndex(
                is_array($characters[$characterIndex]['shortcuts'] ?? null) ? $characters[$characterIndex]['shortcuts'] : [],
                (string) ($arguments['shortcutId'] ?? '')
            );
            if ($shortcutIndex < 0) {
                $connection->rollBack();
                sendError(404, 'Raccourci de jet introuvable.', 'shortcut_missing');
            }
            $shortcut = $characters[$characterIndex]['shortcuts'][$shortcutIndex];
            $kind = (string) ($shortcut['kind'] ?? 'roll');
            $formula = (string) ($shortcut['formula'] ?? '1d100');
            if ($kind !== 'damage' && preg_match('/d[eé]g[aâ]ts?|dommages?|damage/i', (string) ($shortcut['label'] ?? '')) !== 1) {
                $formula = preg_replace('/(?:\d*)d\d+/i', '1d100', str_replace(' ', '', $formula), 1) ?? '1d100';
                if (!str_contains(strtolower($formula), 'd')) {
                    $formula = '1d100' . ($formula !== '' ? (preg_match('/^[+-]/', $formula) === 1 ? '' : '+') . $formula : '');
                }
            }
            try {
                $rolled = onlineRollFormula($formula);
            } catch (InvalidArgumentException $error) {
                $connection->rollBack();
                sendError(400, $error->getMessage(), 'invalid_roll');
            }
            $roll = [
                'id' => randomToken(12),
                'label' => substr((string) ($shortcut['label'] ?? 'Jet'), 0, 120),
                'characterName' => substr((string) ($characters[$characterIndex]['name'] ?? 'Personnage'), 0, 120),
                'formula' => $rolled['formula'],
                'total' => $rolled['total'],
                'breakdown' => $rolled['breakdown'],
                'visibility' => 'public',
                'revealed' => true,
                'rollerName' => (string) $identity['display_name'],
                'rollerRole' => 'player',
                'createdAt' => gmdate('c'),
            ];
            $state['rolls'] = is_array($state['rolls'] ?? null) ? $state['rolls'] : [];
            array_unshift($state['rolls'], $roll);
            $state['rolls'] = array_slice($state['rolls'], 0, 100);
            $initiativeUpdated = false;
            if ($kind === 'initiative' && ($state['tacticalSync']['paused'] ?? false) !== true) {
                $tokens = is_array($state['map']['tokens'] ?? null) ? $state['map']['tokens'] : [];
                foreach ($tokens as $tokenIndex => $token) {
                    if (($token['characterId'] ?? null) !== ($characters[$characterIndex]['id'] ?? null)
                        || ($token['controllerPlayerId'] ?? null) !== $accountId || ($token['hidden'] ?? false) === true) {
                        continue;
                    }
                    $tokens[$tokenIndex]['initiative'] = $rolled['total'];
                    $tokens[$tokenIndex]['_updatedAt'] = (int) floor(microtime(true) * 1000);
                    $state['map']['tokens'] = $tokens;
                    $state['initiative']['order'] = is_array($state['initiative']['order'] ?? null) ? $state['initiative']['order'] : [];
                    if (!in_array($token['id'], $state['initiative']['order'], true)) {
                        $state['initiative']['order'][] = $token['id'];
                    }
                    usort($state['initiative']['order'], static function (mixed $leftId, mixed $rightId) use ($tokens): int {
                        $left = $tokens[findEntryIndex($tokens, (string) $leftId)]['initiative'] ?? -1;
                        $right = $tokens[findEntryIndex($tokens, (string) $rightId)]['initiative'] ?? -1;
                        return (int) $right <=> (int) $left;
                    });
                    $initiativeUpdated = true;
                    break;
                }
            }
            $result['roll'] = $roll;
            $result['initiativeUpdated'] = $initiativeUpdated;
        } elseif ($command === 'timer.create') {
            if (($state['tacticalSync']['paused'] ?? false) === true) {
                $connection->rollBack();
                sendError(423, 'Les minuteurs sont verrouillés pendant la préparation du MJ.', 'table_locked');
            }
            if (($state['initiative']['active'] ?? false) !== true) {
                $connection->rollBack();
                sendError(409, 'Commencez un combat avant d’ajouter une recharge.', 'combat_required');
            }
            $characters = $state['characters'] ?? [];
            $index = findEntryIndex($characters, (string) ($arguments['characterId'] ?? ''));
            if ($index < 0 || ($characters[$index]['ownerPlayerId'] ?? null) !== $accountId) {
                $connection->rollBack();
                sendError(403, 'Ce personnage ne vous appartient pas.', 'character_forbidden');
            }
            $round = max(1, (int) ($state['initiative']['round'] ?? 1));
            $cooldown = max(1, min(999, (int) ($arguments['cooldown'] ?? 1)));
            $label = substr(trim((string) ($arguments['label'] ?? '')), 0, 120);
            if ($label === '') {
                $connection->rollBack();
                sendError(400, 'Donnez un nom à l’action.', 'invalid_timer');
            }
            $timer = [
                'id' => 'timer-' . randomToken(9),
                'sceneId' => $state['activeSceneId'] ?? null,
                'label' => $label,
                'cooldown' => $cooldown,
                'usedRound' => $round,
                'readyRound' => $round + $cooldown,
                'ownerPlayerId' => $accountId,
                'characterId' => $characters[$index]['id'],
                'ownerLabel' => $characters[$index]['name'] ?? (string) $identity['display_name'],
                'visibility' => ($arguments['visibility'] ?? '') === 'public' ? 'public' : 'private',
                'createdAt' => gmdate('c'),
                'updatedAt' => gmdate('c'),
            ];
            $state['actionTimers'] = is_array($state['actionTimers'] ?? null) ? $state['actionTimers'] : [];
            array_unshift($state['actionTimers'], $timer);
            $state['actionTimers'] = array_slice($state['actionTimers'], 0, 300);
            $result['timer'] = $timer + ['ownedByYou' => true];
        } elseif ($command === 'timer.update' || $command === 'timer.delete') {
            if (($state['tacticalSync']['paused'] ?? false) === true) {
                $connection->rollBack();
                sendError(423, 'Les minuteurs sont verrouillés pendant la préparation du MJ.', 'table_locked');
            }
            $timers = is_array($state['actionTimers'] ?? null) ? $state['actionTimers'] : [];
            $index = findEntryIndex($timers, (string) ($arguments['timerId'] ?? ''));
            if ($index < 0 || ($timers[$index]['ownerPlayerId'] ?? null) !== $accountId) {
                $connection->rollBack();
                sendError(403, 'Ce rappel ne vous appartient pas.', 'timer_forbidden');
            }
            if ($command === 'timer.delete') {
                $deleted = $timers[$index];
                array_splice($timers, $index, 1);
                $result['timer'] = ['id' => $deleted['id']];
            } else {
                $round = max(1, (int) ($state['initiative']['round'] ?? 1));
                $timers[$index]['usedRound'] = $round;
                $timers[$index]['readyRound'] = $round + max(1, (int) ($timers[$index]['cooldown'] ?? 1));
                $timers[$index]['updatedAt'] = gmdate('c');
                $result['timer'] = $timers[$index] + ['ownedByYou' => true];
            }
            $state['actionTimers'] = $timers;
        } elseif ($command === 'admin.character.delete' && $isGm && (bool) ($identity['can_administrate'] ?? false)) {
            $characters = is_array($state['characters'] ?? null) ? $state['characters'] : [];
            $index = findEntryIndex($characters, (string) ($arguments['characterId'] ?? ''));
            if ($index < 0) {
                $connection->rollBack();
                sendError(404, 'Fiche introuvable.', 'character_missing');
            }
            $result['character'] = ['id' => $characters[$index]['id'], 'name' => $characters[$index]['name'] ?? 'Fiche supprimée'];
            array_splice($characters, $index, 1);
            $state['characters'] = $characters;
        } else {
            $connection->rollBack();
            sendError(400, 'Commande d’état inconnue ou refusée.', 'command_rejected');
        }
        if (stateContainsForbiddenSecret($state)) {
            $connection->rollBack();
            sendError(400, 'La modification contient une donnée secrète interdite.', 'secret_in_state');
        }
        $state = sanitizeStateImageReferences($state);
        $nextRevision = (int) $record['revision'] + 1;
        $state['revision'] = $nextRevision;
        $state['updatedAt'] = gmdate('c');
        $encoded = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > XAR_STATE_MAXIMUM_BYTES) {
            $connection->rollBack();
            sendError(413, 'L’état partagé dépasse 64 Mo.', 'state_too_large');
        }
        $update = $connection->prepare(
            'UPDATE application_state SET schema_version = :schema_version, revision = :revision, payload = :payload, '
            . 'updated_by_account_id = :updated_by WHERE singleton_id = 1'
        );
        $update->execute([
            ':schema_version' => max(1, (int) ($state['schemaVersion'] ?? 1)),
            ':revision' => $nextRevision,
            ':payload' => $encoded,
            ':updated_by' => $accountId,
        ]);
        $connection->commit();
        sendJson(200, ['ok' => true, 'revision' => $nextRevision, ...$result]);
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
    $record = applicationStateRecord($connection);
    $takeoverAt = dateTimestamp($identity['takeover_requested_at'] ?? null);
    $includePresence = (string) ($_GET['presence'] ?? '1') !== '0';
    sendJson(200, [
        'ok' => true,
        'revision' => (int) ($record['revision'] ?? 0),
        ...($includePresence ? ['presence' => liveOnlinePresence($connection)] : []),
        'takeoverRequested' => $takeoverAt >= time() - 30,
    ], $headOnly);
}

function writeOnlineEvent(string $event, array $payload): void
{
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
    $knownRevision = max(0, (int) ($_GET['revision'] ?? 0));
    $record = applicationStateRecord($connection);
    $currentRevision = (int) ($record['revision'] ?? 0);
    $presence = liveOnlinePresence($connection);
    $presenceFingerprint = hash('sha256', json_encode($presence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    @ini_set('zlib.output_compression', '0');
    @set_time_limit(25);
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, no-transform');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');

    writeOnlineEvent('revision', ['revision' => $currentRevision]);
    writeOnlineEvent('presence', ['presence' => $presence]);
    $takeoverAt = dateTimestamp($identity['takeover_requested_at'] ?? null);
    if ($takeoverAt >= time() - 30) {
        writeOnlineEvent('session-takeover', ['reason' => 'new-login']);
    }
    writeOnlineEvent('heartbeat', ['at' => (int) floor(microtime(true) * 1000)]);
    $knownRevision = $currentRevision;

    $startedAt = microtime(true);
    $nextPresenceAt = $startedAt + 4.0;
    $nextIdentityAt = $startedAt + 4.0;
    $nextConnectionTouchAt = $startedAt + 10.0;
    $nextHeartbeatAt = $startedAt + 10.0;
    $revisionStatement = $connection->prepare('SELECT revision FROM application_state WHERE singleton_id = 1');
    while (!connection_aborted() && microtime(true) - $startedAt < 20.0) {
        usleep(500000);
        $now = microtime(true);
        $revisionStatement->execute();
        $nextRevision = (int) ($revisionStatement->fetchColumn() ?: 0);
        if ($nextRevision !== $knownRevision) {
            $knownRevision = $nextRevision;
            writeOnlineEvent('revision', ['revision' => $knownRevision]);
        }
        if ($now >= $nextPresenceAt) {
            $nextPresenceAt = $now + 4.0;
            $nextPresence = liveOnlinePresence($connection);
            $nextFingerprint = hash('sha256', json_encode($nextPresence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            if (!hash_equals($presenceFingerprint, $nextFingerprint)) {
                $presenceFingerprint = $nextFingerprint;
                writeOnlineEvent('presence', ['presence' => $nextPresence]);
            }
        }
        if ($now >= $nextIdentityAt) {
            $nextIdentityAt = $now + 4.0;
            $identity = resolveSession($connection, $token, false);
            if (!is_array($identity)) {
                writeOnlineEvent('session-replaced', ['reason' => 'new-login']);
                break;
            }
            $takeoverAt = dateTimestamp($identity['takeover_requested_at'] ?? null);
            if ($takeoverAt >= time() - 30) {
                writeOnlineEvent('session-takeover', ['reason' => 'new-login']);
                break;
            }
        }
        if ($now >= $nextConnectionTouchAt) {
            $nextConnectionTouchAt = $now + 10.0;
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
    writeOnlineEvent('reconnect', ['afterMilliseconds' => 100]);
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

function uploadOnlineMedia(PDO $connection): never
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
    $directory = privateMediaDirectory();
    $id = randomToken(18);
    $storedName = $id . $extension;
    $temporary = $directory . DIRECTORY_SEPARATOR . $storedName . '.partial';
    $destination = $directory . DIRECTORY_SEPARATOR . $storedName;
    $input = fopen('php://input', 'rb');
    $output = fopen($temporary, 'xb');
    if ($input === false || $output === false) {
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
    if ($size <= 0 || !rename($temporary, $destination)) {
        @unlink($temporary);
        sendError(503, 'Enregistrement du média impossible.', 'media_unavailable');
    }
    @chmod($destination, 0600);
    try {
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
    } catch (Throwable $error) {
        @unlink($destination);
        throw $error;
    }
    sendJson(201, ['ok' => true, 'mediaId' => $id, 'url' => '/media/' . $id, 'contentType' => $contentType, 'size' => $size]);
}

function mediaRecord(PDO $connection, string $id): ?array
{
    if (preg_match('/^[A-Za-z0-9_-]{24}$/', $id) !== 1) {
        return null;
    }
    $statement = $connection->prepare(
        'SELECT id, stored_name, original_name, content_type, byte_size, public_slug, published_at '
        . 'FROM media_objects WHERE id = :id LIMIT 1'
    );
    $statement->execute([':id' => $id]);
    $record = $statement->fetch();
    return is_array($record) ? $record : null;
}

function streamOnlineMedia(PDO $connection, string $id, bool $headOnly = false): never
{
    requireIdentity($connection);
    $record = mediaRecord($connection, $id);
    $path = is_array($record) ? privateMediaDirectory() . DIRECTORY_SEPARATOR . basename((string) $record['stored_name']) : '';
    if (!is_array($record) || !is_file($path)) {
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
    $statement = $connection->prepare('DELETE FROM media_objects WHERE id = :id');
    $statement->execute([':id' => $id]);
    @unlink(privateMediaDirectory() . DIRECTORY_SEPARATOR . basename((string) $record['stored_name']));
    sendJson(200, ['ok' => true]);
}

function publishOnlineMedia(PDO $connection, string $id): never
{
    requireGmIdentity($connection);
    $record = mediaRecord($connection, $id);
    if (!is_array($record) || !str_starts_with((string) $record['content_type'], 'image/')) {
        sendError(404, 'Image introuvable.', 'media_missing');
    }
    $slug = (string) ($record['public_slug'] ?? '');
    if ($slug === '') {
        for ($attempt = 0; $attempt < 4; $attempt += 1) {
            $slug = randomToken(16);
            try {
                $statement = $connection->prepare(
                    'UPDATE media_objects SET public_slug = :public_slug, published_at = UTC_TIMESTAMP(3) '
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
    $temporary = null;
    $headers = [];
    if ($imageDataUrl !== '') {
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
        $postFields = [
            'payload_json' => json_encode(['content' => $content, 'allowed_mentions' => ['parse' => []]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'files[0]' => new CURLFile($temporary, $match[1], cleanMediaFilename((string) ($payload['filename'] ?? 'xar-tsaroth.png'))),
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
        if (is_string($temporary)) {
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
    if (is_string($temporary)) {
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
        requireMethod($method, ['POST']);
        postOnlineDiscord($connection, $configuration);
    }
    if ($route === '/api/v1/state') {
        if ($method === 'GET' || $method === 'HEAD') {
            readOnlineState($connection, $headOnly);
        }
        if ($method === 'PUT') {
            replaceOnlineState($connection);
        }
        requireMethod($method, ['GET', 'HEAD', 'PUT']);
    }
    if ($route === '/api/v1/state/command') {
        requireMethod($method, ['POST']);
        commandOnlineState($connection);
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
            uploadOnlineMedia($connection);
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
