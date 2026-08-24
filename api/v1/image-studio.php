<?php

declare(strict_types=1);

const XAR_IMAGE_STUDIO_SESSION_SECONDS = 43200;
const XAR_IMAGE_STUDIO_MAX_REFERENCES = 5;
const XAR_IMAGE_STUDIO_MAX_PROMPT_BYTES = 50000;
const XAR_IMAGE_STUDIO_WORKER_ONLINE_SECONDS = 45;
const XAR_IMAGE_STUDIO_WORKER_LEASE_SECONDS = 900;
const XAR_IMAGE_STUDIO_WORKER_MAX_ATTEMPTS = 2;

function cleanImageStudioSingleLineText(
    mixed $value,
    int $maximumCharacters,
    string $label,
    string $code
): string {
    try {
        return cleanText($value, $maximumCharacters, $label);
    } catch (InvalidArgumentException $error) {
        sendError(400, $error->getMessage(), $code);
    }
}

function cleanImageStudioMultilineText(
    mixed $value,
    int $maximumBytes,
    string $label,
    string $code
): string {
    $text = trim(str_replace(["\r\n", "\r"], "\n", (string) $value));
    if ($text === ''
        || strlen($text) > $maximumBytes
        || str_contains($text, "\0")
        || preg_match('//u', $text) !== 1) {
        sendError(400, $label . ' invalide.', $code);
    }
    return $text;
}

function imageStudioSessionCookie(string $token, int $maximumAge = XAR_IMAGE_STUDIO_SESSION_SECONDS): string
{
    return 'xar_studio_session=' . rawurlencode($token)
        . '; Path=/api/v1/image-studio; Max-Age=' . max(0, $maximumAge)
        . '; Secure; HttpOnly; SameSite=Strict';
}

function requestImageStudioSessionToken(): string
{
    $token = trim((string) ($_COOKIE['xar_studio_session'] ?? ''));
    return preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) === 1 ? $token : '';
}

function cleanupImageStudioSessions(PDO $connection): void
{
    $connection->exec('DELETE FROM image_studio_sessions WHERE expires_at <= UTC_TIMESTAMP(3)');
}

function resolveImageStudioIdentity(PDO $connection, bool $touch = true): ?array
{
    $applicationIdentity = resolveSession($connection, requestSessionToken(), $touch);
    if (is_array($applicationIdentity)
        && (string) $applicationIdentity['effective_mode'] === 'gm'
        && (string) $applicationIdentity['permanent_role'] === 'gm') {
        $applicationIdentity['image_studio_session'] = false;
        return $applicationIdentity;
    }

    $token = requestImageStudioSessionToken();
    if ($token === '') {
        return null;
    }
    $statement = $connection->prepare(
        'SELECT a.id, a.username, a.display_name, a.permanent_role, a.can_administrate, '
        . 'a.auth_revision, a.revoked_at, s.auth_revision AS session_auth_revision '
        . 'FROM image_studio_sessions s JOIN accounts a ON a.id = s.account_id '
        . 'WHERE s.token_hash = :token_hash AND s.expires_at > UTC_TIMESTAMP(3) LIMIT 1'
    );
    $statement->bindValue(':token_hash', tokenHash($token), PDO::PARAM_LOB);
    $statement->execute();
    $identity = $statement->fetch();
    if (!is_array($identity)
        || $identity['revoked_at'] !== null
        || (string) $identity['permanent_role'] !== 'gm'
        || (int) $identity['auth_revision'] !== (int) $identity['session_auth_revision']) {
        $delete = $connection->prepare('DELETE FROM image_studio_sessions WHERE token_hash = :token_hash');
        $delete->bindValue(':token_hash', tokenHash($token), PDO::PARAM_LOB);
        $delete->execute();
        return null;
    }
    if ($touch) {
        $update = $connection->prepare(
            'UPDATE image_studio_sessions SET last_seen_at = UTC_TIMESTAMP(3), expires_at = :expires_at '
            . 'WHERE token_hash = :token_hash'
        );
        $update->bindValue(':expires_at', utcAfter(XAR_IMAGE_STUDIO_SESSION_SECONDS));
        $update->bindValue(':token_hash', tokenHash($token), PDO::PARAM_LOB);
        $update->execute();
    }
    $identity['effective_mode'] = 'gm';
    $identity['image_studio_session'] = true;
    return $identity;
}

function requireImageStudioIdentity(PDO $connection): array
{
    $identity = resolveImageStudioIdentity($connection);
    if (!is_array($identity)) {
        sendError(401, 'Connexion MJ requise.', 'authentication_required');
    }
    return $identity;
}

function imageStudioPublicIdentity(array $identity): array
{
    return [
        'id' => (string) $identity['id'],
        'username' => (string) $identity['username'],
        'displayName' => (string) $identity['display_name'],
        'role' => 'gm',
        'canAdministrate' => (bool) ($identity['can_administrate'] ?? false),
        'limitedGallerySession' => (bool) ($identity['image_studio_session'] ?? false),
    ];
}

function loginImageStudio(PDO $connection): never
{
    $payload = readJsonBody(16384);
    $account = authenticateAccount(
        $connection,
        $payload['username'] ?? '',
        $payload['password'] ?? '',
        'gm'
    );
    if ((string) $account['permanent_role'] !== 'gm') {
        sendError(403, 'Le studio web est réservé aux MJ.', 'gm_required');
    }
    $token = randomToken();
    $connection->beginTransaction();
    try {
        $delete = $connection->prepare('DELETE FROM image_studio_sessions WHERE account_id = :account_id');
        $delete->execute([':account_id' => (string) $account['id']]);
        $insert = $connection->prepare(
            'INSERT INTO image_studio_sessions '
            . '(token_hash, account_id, auth_revision, expires_at) '
            . 'VALUES (:token_hash, :account_id, :auth_revision, :expires_at)'
        );
        $insert->bindValue(':token_hash', tokenHash($token), PDO::PARAM_LOB);
        $insert->bindValue(':account_id', (string) $account['id']);
        $insert->bindValue(':auth_revision', (int) $account['auth_revision'], PDO::PARAM_INT);
        $insert->bindValue(':expires_at', utcAfter(XAR_IMAGE_STUDIO_SESSION_SECONDS));
        $insert->execute();
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
    $identity = [
        ...$account,
        'effective_mode' => 'gm',
        'image_studio_session' => true,
    ];
    sendJson(200, [
        'ok' => true,
        'account' => imageStudioPublicIdentity($identity),
        'expiresInSeconds' => XAR_IMAGE_STUDIO_SESSION_SECONDS,
    ], false, ['Set-Cookie' => imageStudioSessionCookie($token)]);
}

function logoutImageStudio(PDO $connection): never
{
    $token = requestImageStudioSessionToken();
    if ($token !== '') {
        $statement = $connection->prepare('DELETE FROM image_studio_sessions WHERE token_hash = :token_hash');
        $statement->bindValue(':token_hash', tokenHash($token), PDO::PARAM_LOB);
        $statement->execute();
    }
    sendJson(200, ['ok' => true], false, ['Set-Cookie' => imageStudioSessionCookie('', 0)]);
}

function validImageStudioConversationId(string $id): bool
{
    return preg_match('/^[A-Za-z0-9_-]{22}$/D', $id) === 1;
}

function validImageStudioMessageId(string $id): bool
{
    return preg_match('/^[A-Za-z0-9_-]{24}$/D', $id) === 1;
}

function imageStudioConversationRecord(PDO $connection, string $id): ?array
{
    if (!validImageStudioConversationId($id)) {
        return null;
    }
    $statement = $connection->prepare(
        'SELECT c.id, c.owner_account_id, c.title, c.owner_archived_at, c.created_at, c.updated_at, '
        . 'a.username, a.display_name, a.can_administrate '
        . 'FROM image_studio_conversations c JOIN accounts a ON a.id = c.owner_account_id '
        . 'WHERE c.id = :id LIMIT 1'
    );
    $statement->execute([':id' => $id]);
    $record = $statement->fetch();
    return is_array($record) ? $record : null;
}

function assertImageStudioConversationAccess(array $identity, ?array $conversation, bool $allowAdministrator = true): array
{
    if (!is_array($conversation)) {
        sendError(404, 'Conversation introuvable.', 'conversation_missing');
    }
    if ((string) $conversation['owner_account_id'] !== (string) $identity['id']
        && (!$allowAdministrator || !(bool) ($identity['can_administrate'] ?? false))) {
        sendError(403, 'Cette conversation appartient à un autre MJ.', 'conversation_forbidden');
    }
    return $conversation;
}

function imageStudioConversationPayload(array $row): array
{
    return [
        'id' => (string) $row['id'],
        'title' => (string) $row['title'],
        'owner' => [
            'id' => (string) $row['owner_account_id'],
            'username' => (string) $row['username'],
            'displayName' => (string) $row['display_name'],
        ],
        'archived' => $row['owner_archived_at'] !== null,
        'messageCount' => (int) ($row['message_count'] ?? 0),
        'createdAt' => (string) $row['created_at'],
        'updatedAt' => (string) $row['updated_at'],
    ];
}

function listImageStudioConversations(PDO $connection, bool $headOnly): never
{
    $identity = requireImageStudioIdentity($connection);
    $scopeAll = ($_GET['scope'] ?? '') === 'all' && (bool) ($identity['can_administrate'] ?? false);
    $includeArchived = ($_GET['archived'] ?? '') === '1' || $scopeAll;
    $where = $scopeAll ? '1 = 1' : 'c.owner_account_id = :owner_account_id';
    if (!$includeArchived) {
        $where .= ' AND c.owner_archived_at IS NULL';
    }
    $statement = $connection->prepare(
        'SELECT c.id, c.owner_account_id, c.title, c.owner_archived_at, c.created_at, c.updated_at, '
        . 'a.username, a.display_name, a.can_administrate, COUNT(m.id) AS message_count '
        . 'FROM image_studio_conversations c JOIN accounts a ON a.id = c.owner_account_id '
        . 'LEFT JOIN image_studio_messages m ON m.conversation_id = c.id '
        . 'WHERE ' . $where . ' GROUP BY c.id, c.owner_account_id, c.title, c.owner_archived_at, '
        . 'c.created_at, c.updated_at, a.username, a.display_name, a.can_administrate '
        . 'ORDER BY c.updated_at DESC, c.id DESC LIMIT 500'
    );
    if (!$scopeAll) {
        $statement->bindValue(':owner_account_id', (string) $identity['id']);
    }
    $statement->execute();
    $rows = $statement->fetchAll();
    sendJson(200, [
        'ok' => true,
        'scope' => $scopeAll ? 'all' : 'mine',
        'conversations' => array_map('imageStudioConversationPayload', $rows),
    ], $headOnly);
}

function createImageStudioConversation(PDO $connection): never
{
    $identity = requireImageStudioIdentity($connection);
    $payload = readJsonBody(8192);
    $title = trim((string) ($payload['title'] ?? ''));
    if ($title === '') {
        $title = 'Nouvelle vision';
    }
    $title = cleanImageStudioSingleLineText($title, 180, 'Titre', 'invalid_conversation_title');
    $id = randomToken(16);
    $statement = $connection->prepare(
        'INSERT INTO image_studio_conversations (id, owner_account_id, title) '
        . 'VALUES (:id, :owner_account_id, :title)'
    );
    $statement->execute([
        ':id' => $id,
        ':owner_account_id' => (string) $identity['id'],
        ':title' => $title,
    ]);
    $conversation = imageStudioConversationRecord($connection, $id);
    sendJson(201, ['ok' => true, 'conversation' => imageStudioConversationPayload($conversation)]);
}

function updateImageStudioConversation(PDO $connection, string $id): never
{
    $identity = requireImageStudioIdentity($connection);
    $conversation = assertImageStudioConversationAccess(
        $identity,
        imageStudioConversationRecord($connection, $id),
        false
    );
    $payload = readJsonBody(8192);
    $fields = [];
    $values = [':id' => $id];
    if (array_key_exists('title', $payload)) {
        $fields[] = 'title = :title';
        $values[':title'] = cleanImageStudioSingleLineText(
            $payload['title'],
            180,
            'Titre',
            'invalid_conversation_title'
        );
    }
    if (array_key_exists('archived', $payload)) {
        if (!is_bool($payload['archived'])) {
            sendError(400, 'État d’archivage invalide.', 'invalid_archive_state');
        }
        $fields[] = $payload['archived'] ? 'owner_archived_at = UTC_TIMESTAMP(3)' : 'owner_archived_at = NULL';
    }
    if ($fields === []) {
        sendError(400, 'Aucune modification reconnue.', 'empty_update');
    }
    $statement = $connection->prepare(
        'UPDATE image_studio_conversations SET ' . implode(', ', $fields) . ' WHERE id = :id'
    );
    $statement->execute($values);
    $updated = imageStudioConversationRecord($connection, (string) $conversation['id']);
    sendJson(200, ['ok' => true, 'conversation' => imageStudioConversationPayload($updated)]);
}

function normalizedImageStudioReferences(mixed $value): array
{
    if (!is_array($value) || count($value) > XAR_IMAGE_STUDIO_MAX_REFERENCES) {
        sendError(413, 'La génération accepte au maximum cinq références.', 'reference_limit');
    }
    $normalized = [];
    foreach ($value as $reference) {
        if (!is_array($reference)) {
            sendError(400, 'Référence d’image invalide.', 'invalid_reference');
        }
        $kind = in_array(($reference['kind'] ?? ''), ['catalog', 'character', 'upload', 'result'], true)
            ? (string) $reference['kind']
            : 'upload';
        $label = cleanImageStudioSingleLineText(
            $reference['label'] ?? 'Référence',
            120,
            'Nom de référence',
            'invalid_reference'
        );
        $id = trim((string) ($reference['id'] ?? ''));
        $mediaId = trim((string) ($reference['mediaId'] ?? ''));
        $sourceUrl = trim((string) ($reference['sourceUrl'] ?? ''));
        if ($id !== '' && preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $id) !== 1) {
            sendError(400, 'Identifiant de référence invalide.', 'invalid_reference');
        }
        if ($mediaId !== '' && preg_match('/^[A-Za-z0-9_-]{24}$/D', $mediaId) !== 1) {
            sendError(400, 'Média de référence invalide.', 'invalid_reference');
        }
        if ($sourceUrl !== '') {
            $parts = parse_url($sourceUrl);
            $validSource = is_array($parts)
                && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
                && in_array(strtolower((string) ($parts['host'] ?? '')), ['xar-tsaroth.fr', 'www.xar-tsaroth.fr'], true)
                && preg_match('#^/media/personnages/[A-Za-z0-9_-]+\.webp$#D', (string) ($parts['path'] ?? '')) === 1
                && !isset($parts['port']) && !isset($parts['user']) && !isset($parts['pass'])
                && !isset($parts['query']) && !isset($parts['fragment']);
            if (!$validSource) {
                sendError(400, 'Source de référence invalide.', 'invalid_reference');
            }
            $sourceUrl = 'https://www.xar-tsaroth.fr' . (string) $parts['path'];
        }
        $normalized[] = [
            'kind' => $kind,
            'label' => $label,
            ...($id !== '' ? ['id' => $id] : []),
            ...($mediaId !== '' ? ['mediaId' => $mediaId] : []),
            ...($sourceUrl !== '' ? ['sourceUrl' => $sourceUrl] : []),
        ];
    }
    return $normalized;
}

function imageStudioMessagePayload(array $row, bool $administratorView = false): array
{
    $references = jsonColumn($row['references_json'] ?? '[]');
    $shareSlug = (string) ($row['public_slug'] ?? '');
    return [
        'id' => (string) $row['id'],
        'conversationId' => (string) $row['conversation_id'],
        'operation' => (string) $row['operation'],
        'prompt' => (string) $row['prompt'],
        'revisedPrompt' => $row['revised_prompt'] === null ? null : (string) $row['revised_prompt'],
        // « high » est la qualité demandée par la Régie. Le chemin OAuth Codex
        // peut appliquer son réglage automatique et ne renvoie pas toujours le
        // niveau effectivement utilisé.
        'quality' => 'high',
        'qualityRequested' => 'high',
        'qualityApplied' => null,
        'aspect' => (string) $row['aspect'],
        'executionMode' => (string) ($row['execution_mode'] ?? 'local'),
        'references' => $references,
        'status' => (string) $row['status'],
        'parentMessageId' => $row['parent_message_id'] === null ? null : (string) $row['parent_message_id'],
        'mediaId' => $row['media_id'] === null ? null : (string) $row['media_id'],
        'contentType' => $row['media_content_type'] === null ? null : (string) $row['media_content_type'],
        'imageUrl' => $row['media_id'] === null ? null : '/api/v1/image-studio/media/' . (string) $row['media_id'],
        'shareUrl' => preg_match('/^[A-Za-z0-9_-]{22}$/D', $shareSlug) === 1
            ? 'https://regie-xar-tsaroth.fr/share/' . $shareSlug
            : null,
        'width' => $row['width'] === null ? null : (int) $row['width'],
        'height' => $row['height'] === null ? null : (int) $row['height'],
        'error' => $row['error_code'] === null ? null : [
            'code' => (string) $row['error_code'],
            'message' => (string) ($row['error_detail'] ?? ''),
        ],
        'hidden' => $row['owner_hidden_at'] !== null,
        'createdAt' => (string) $row['created_at'],
        'startedAt' => $row['started_at'] === null ? null : (string) $row['started_at'],
        'completedAt' => $row['completed_at'] === null ? null : (string) $row['completed_at'],
        ...($administratorView ? ['authorAccountId' => (string) $row['author_account_id']] : []),
    ];
}

function imageStudioMessageRecord(PDO $connection, string $id): ?array
{
    if (!validImageStudioMessageId($id)) {
        return null;
    }
    $statement = $connection->prepare(
        'SELECT m.*, mo.public_slug, mo.pending_delete_at, mo.content_type AS media_content_type '
        . 'FROM image_studio_messages m LEFT JOIN media_objects mo ON mo.id = m.media_id '
        . 'WHERE m.id = :id LIMIT 1'
    );
    $statement->execute([':id' => $id]);
    $record = $statement->fetch();
    return is_array($record) ? $record : null;
}

function requireOwnedImageStudioMessage(PDO $connection, array $identity, string $id): array
{
    $message = imageStudioMessageRecord($connection, $id);
    if (!is_array($message)) {
        sendError(404, 'Demande de génération introuvable.', 'message_missing');
    }
    if ((string) $message['author_account_id'] !== (string) $identity['id']) {
        sendError(403, 'Cette génération appartient à un autre MJ.', 'message_forbidden');
    }
    return $message;
}

function listImageStudioMessages(PDO $connection, string $conversationId, bool $headOnly): never
{
    $identity = requireImageStudioIdentity($connection);
    $conversation = assertImageStudioConversationAccess(
        $identity,
        imageStudioConversationRecord($connection, $conversationId)
    );
    $administratorView = ((string) $conversation['owner_account_id'] !== (string) $identity['id'])
        || (($_GET['audit'] ?? '') === '1' && (bool) ($identity['can_administrate'] ?? false));
    $statement = $connection->prepare(
        'SELECT m.*, mo.public_slug, mo.pending_delete_at, mo.content_type AS media_content_type '
        . 'FROM image_studio_messages m LEFT JOIN media_objects mo ON mo.id = m.media_id '
        . 'WHERE m.conversation_id = :conversation_id '
        . ($administratorView ? '' : 'AND m.owner_hidden_at IS NULL ')
        . 'ORDER BY m.created_at, m.id LIMIT 1000'
    );
    $statement->execute([':conversation_id' => $conversationId]);
    $messages = array_map(
        static fn (array $row): array => imageStudioMessagePayload($row, $administratorView),
        $statement->fetchAll()
    );
    sendJson(200, [
        'ok' => true,
        'conversation' => imageStudioConversationPayload($conversation),
        'messages' => $messages,
        'retentionNotice' => 'Retirer une conversation ou une image la masque au MJ ; son journal reste accessible à l’administrateur.',
    ], $headOnly);
}

function isRegieCodexOwner(array $identity): bool
{
    return (bool) ($identity['can_administrate'] ?? false)
        && (string) ($identity['permanent_role'] ?? '') === 'gm'
        && strcasecmp(trim((string) ($identity['username'] ?? '')), 'Innota') === 0;
}

function requireRegieCodexOwner(PDO $connection): array
{
    $identity = requireImageStudioIdentity($connection);
    if (!isRegieCodexOwner($identity)) {
        sendError(403, 'Le Compte de la Régie est piloté uniquement par Innota.', 'regie_codex_owner_required');
    }
    return $identity;
}

function imageStudioRegieServiceRecord(PDO $connection, bool $forUpdate = false): array
{
    $statement = $connection->query(
        'SELECT singleton_id, paused, worker_ready, worker_account_id, worker_last_seen_at, updated_at, '
        . '(worker_ready = 1 AND worker_last_seen_at >= DATE_SUB(UTC_TIMESTAMP(3), INTERVAL '
        . XAR_IMAGE_STUDIO_WORKER_ONLINE_SECONDS . ' SECOND)) AS worker_online '
        . 'FROM image_studio_regie_service WHERE singleton_id = 1'
        . ($forUpdate ? ' FOR UPDATE' : '')
    );
    $record = $statement === false ? false : $statement->fetch();
    if (!is_array($record)) {
        throw new RuntimeException('regie_codex_service_missing');
    }
    return $record;
}

function imageStudioRegieServicePayload(PDO $connection, array $identity): array
{
    $service = imageStudioRegieServiceRecord($connection);
    $accountId = (string) $identity['id'];
    $counts = $connection->prepare(
        "SELECT SUM(execution_mode = 'regie' AND status = 'queued') AS queued_count, "
        . "SUM(execution_mode = 'regie' AND status = 'generating') AS active_count, "
        . "SUM(execution_mode = 'regie' AND author_account_id = :account_id "
        . "AND status IN ('queued', 'generating')) AS own_shared_active_count FROM image_studio_messages"
    );
    $counts->execute([':account_id' => $accountId]);
    $queue = $counts->fetch();
    $paused = (bool) $service['paused'];
    return [
        'paused' => $paused,
        'acceptingRequests' => !$paused,
        'workerOnline' => (bool) $service['worker_online'],
        'queuedCount' => (int) ($queue['queued_count'] ?? 0),
        'activeCount' => (int) ($queue['active_count'] ?? 0),
        'ownSharedActiveCount' => (int) ($queue['own_shared_active_count'] ?? 0),
        'canControl' => isRegieCodexOwner($identity),
        'workerLastSeenAt' => $service['worker_last_seen_at'] === null ? null : (string) $service['worker_last_seen_at'],
        'updatedAt' => (string) $service['updated_at'],
        'pausePolicy' => 'La pause refuse les nouvelles demandes et les nouvelles prises de travail ; une génération déjà commencée peut se terminer.',
    ];
}

function readImageStudioRegieService(PDO $connection, bool $headOnly): never
{
    $identity = requireImageStudioIdentity($connection);
    sendJson(200, [
        'ok' => true,
        'service' => imageStudioRegieServicePayload($connection, $identity),
    ], $headOnly);
}

function updateImageStudioRegieAccess(PDO $connection): never
{
    $identity = requireRegieCodexOwner($connection);
    $payload = readJsonBody(8192);
    if (!array_key_exists('paused', $payload) || !is_bool($payload['paused'])) {
        sendError(400, 'État de pause invalide.', 'invalid_pause_state');
    }
    $lock = $connection->query("SELECT GET_LOCK('xar-regie-codex-access', 12)");
    if ($lock === false || (int) $lock->fetchColumn() !== 1) {
        sendError(503, 'Le contrôle du Compte de la Régie est occupé.', 'regie_codex_lock_unavailable');
    }
    try {
        $statement = $connection->prepare(
            'UPDATE image_studio_regie_service SET paused = :paused, updated_by_account_id = :account_id '
            . 'WHERE singleton_id = 1'
        );
        $statement->execute([
            ':paused' => $payload['paused'] ? 1 : 0,
            ':account_id' => (string) $identity['id'],
        ]);
        $service = imageStudioRegieServicePayload($connection, $identity);
    } finally {
        try {
            $connection->query("SELECT RELEASE_LOCK('xar-regie-codex-access')");
        } catch (Throwable) {
        }
    }
    sendJson(200, [
        'ok' => true,
        'service' => $service,
    ]);
}

function heartbeatImageStudioRegieWorker(PDO $connection): never
{
    $identity = requireRegieCodexOwner($connection);
    $payload = readJsonBody(8192);
    if (!array_key_exists('ready', $payload) || !is_bool($payload['ready'])) {
        sendError(400, 'État du worker invalide.', 'invalid_worker_state');
    }
    $activeMessageId = trim((string) ($payload['activeMessageId'] ?? ''));
    if ($activeMessageId !== '' && !validImageStudioMessageId($activeMessageId)) {
        sendError(400, 'Travail actif du worker invalide.', 'invalid_worker_job');
    }
    $statement = $connection->prepare(
        'UPDATE image_studio_regie_service SET worker_ready = :ready, worker_account_id = :account_id, '
        . 'worker_last_seen_at = UTC_TIMESTAMP(3) WHERE singleton_id = 1'
    );
    $statement->execute([
        ':ready' => $payload['ready'] ? 1 : 0,
        ':account_id' => (string) $identity['id'],
    ]);
    if ($payload['ready'] && $activeMessageId !== '') {
        $renew = $connection->prepare(
            'UPDATE image_studio_messages SET worker_lease_expires_at = DATE_ADD(UTC_TIMESTAMP(3), INTERVAL '
            . XAR_IMAGE_STUDIO_WORKER_LEASE_SECONDS . ' SECOND) '
            . "WHERE id = :id AND execution_mode = 'regie' AND status = 'generating' "
            . 'AND worker_account_id = :account_id'
        );
        $renew->execute([
            ':id' => $activeMessageId,
            ':account_id' => (string) $identity['id'],
        ]);
    }
    sendJson(200, [
        'ok' => true,
        'service' => imageStudioRegieServicePayload($connection, $identity),
    ]);
}

function recoverExpiredImageStudioRegieJobs(PDO $connection): void
{
    $fail = $connection->prepare(
        "UPDATE image_studio_messages SET status = 'failed', error_code = 'worker_interrupted', "
        . "error_detail = 'Le worker de la Régie a été interrompu à plusieurs reprises.', "
        . 'worker_account_id = NULL, worker_lease_expires_at = NULL, completed_at = UTC_TIMESTAMP(3) '
        . "WHERE execution_mode = 'regie' AND status = 'generating' "
        . 'AND worker_lease_expires_at < UTC_TIMESTAMP(3) AND worker_attempts >= :maximum_attempts'
    );
    $fail->execute([':maximum_attempts' => XAR_IMAGE_STUDIO_WORKER_MAX_ATTEMPTS]);
    $retry = $connection->prepare(
        "UPDATE image_studio_messages SET status = 'queued', worker_account_id = NULL, "
        . 'worker_lease_expires_at = NULL, started_at = NULL, error_code = NULL, error_detail = NULL '
        . "WHERE execution_mode = 'regie' AND status = 'generating' "
        . 'AND worker_lease_expires_at < UTC_TIMESTAMP(3) AND worker_attempts < :maximum_attempts'
    );
    $retry->execute([':maximum_attempts' => XAR_IMAGE_STUDIO_WORKER_MAX_ATTEMPTS]);
}

function claimImageStudioRegieJob(PDO $connection): never
{
    $identity = requireRegieCodexOwner($connection);
    $messageId = null;
    $connection->beginTransaction();
    try {
        $service = imageStudioRegieServiceRecord($connection, true);
        recoverExpiredImageStudioRegieJobs($connection);
        if (!(bool) $service['paused'] && (bool) $service['worker_online']) {
            $active = $connection->query(
                "SELECT id FROM image_studio_messages WHERE execution_mode = 'regie' "
                . "AND status = 'generating' ORDER BY started_at, id LIMIT 1 FOR UPDATE"
            );
            $activeId = $active === false ? false : $active->fetchColumn();
            if ($activeId === false) {
                $next = $connection->query(
                    "SELECT id FROM image_studio_messages WHERE execution_mode = 'regie' "
                    . "AND status = 'queued' ORDER BY created_at, id LIMIT 1 FOR UPDATE"
                );
                $candidate = $next === false ? false : $next->fetchColumn();
                if (is_string($candidate) && validImageStudioMessageId($candidate)) {
                    $claim = $connection->prepare(
                        "UPDATE image_studio_messages SET status = 'generating', worker_account_id = :account_id, "
                        . 'worker_attempts = worker_attempts + 1, started_at = UTC_TIMESTAMP(3), '
                        . 'worker_lease_expires_at = DATE_ADD(UTC_TIMESTAMP(3), INTERVAL '
                        . XAR_IMAGE_STUDIO_WORKER_LEASE_SECONDS . ' SECOND) '
                        . "WHERE id = :id AND execution_mode = 'regie' AND status = 'queued'"
                    );
                    $claim->execute([
                        ':account_id' => (string) $identity['id'],
                        ':id' => $candidate,
                    ]);
                    if ($claim->rowCount() === 1) {
                        $messageId = $candidate;
                    }
                }
            }
        }
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
    $message = $messageId === null ? null : imageStudioMessageRecord($connection, $messageId);
    sendJson(200, [
        'ok' => true,
        'job' => is_array($message) ? imageStudioMessagePayload($message, true) : null,
        'service' => imageStudioRegieServicePayload($connection, $identity),
    ]);
}

function createImageStudioMessage(PDO $connection, string $conversationId): never
{
    $identity = requireImageStudioIdentity($connection);
    assertImageStudioConversationAccess(
        $identity,
        imageStudioConversationRecord($connection, $conversationId),
        false
    );
    $payload = readJsonBody(196608);
    $prompt = cleanImageStudioMultilineText(
        $payload['prompt'] ?? '',
        XAR_IMAGE_STUDIO_MAX_PROMPT_BYTES,
        'Description',
        'invalid_prompt'
    );
    $operation = in_array(($payload['operation'] ?? ''), ['generate', 'edit', 'regenerate'], true)
        ? (string) $payload['operation']
        : 'generate';
    $aspect = in_array(($payload['aspect'] ?? ''), ['landscape', 'portrait', 'square'], true)
        ? (string) $payload['aspect']
        : 'landscape';
    $executionMode = ($payload['executionMode'] ?? '') === 'regie' ? 'regie' : 'local';
    $clientRequestId = trim((string) ($payload['clientRequestId'] ?? ''));
    if ($clientRequestId !== '' && preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $clientRequestId) !== 1) {
        sendError(400, 'Identifiant de demande invalide.', 'invalid_client_request');
    }
    $references = normalizedImageStudioReferences($payload['references'] ?? []);
    foreach ($references as $reference) {
        if (isset($reference['mediaId'])) {
            assertImageStudioReferenceMediaAccess($connection, $identity, (string) $reference['mediaId']);
        }
    }
    if ($executionMode === 'regie') {
        foreach ($references as $reference) {
            if (!isset($reference['mediaId']) && !isset($reference['sourceUrl'])) {
                sendError(400, 'Une référence partagée ne contient aucune image persistante.', 'invalid_reference');
            }
        }
    }
    $parentMessageId = trim((string) ($payload['parentMessageId'] ?? ''));
    if ($parentMessageId !== '') {
        $parent = imageStudioMessageRecord($connection, $parentMessageId);
        if (!is_array($parent) || (string) $parent['conversation_id'] !== $conversationId) {
            sendError(400, 'La génération source n’appartient pas à cette conversation.', 'invalid_parent_message');
        }
    }

    $accountId = (string) $identity['id'];
    if ($clientRequestId !== '') {
        $duplicate = $connection->prepare(
            'SELECT id FROM image_studio_messages WHERE author_account_id = :account_id '
            . 'AND client_request_id = :client_request_id LIMIT 1'
        );
        $duplicate->execute([
            ':account_id' => $accountId,
            ':client_request_id' => $clientRequestId,
        ]);
        $duplicateId = $duplicate->fetchColumn();
        if (is_string($duplicateId) && validImageStudioMessageId($duplicateId)) {
            sendJson(200, [
                'ok' => true,
                'deduplicated' => true,
                'message' => imageStudioMessagePayload(imageStudioMessageRecord($connection, $duplicateId)),
            ]);
        }
    }
    $lockName = 'xar-image-generation-' . substr(hash('sha256', $accountId), 0, 38);
    $lock = $connection->prepare('SELECT GET_LOCK(:lock_name, 12)');
    $lock->execute([':lock_name' => $lockName]);
    if ((int) $lock->fetchColumn() !== 1) {
        sendError(503, 'La file de génération est occupée.', 'generation_lock_unavailable');
    }
    $regieAccessLockHeld = false;
    try {
        if ($executionMode === 'regie') {
            $accessLock = $connection->query("SELECT GET_LOCK('xar-regie-codex-access', 12)");
            if ($accessLock === false || (int) $accessLock->fetchColumn() !== 1) {
                sendError(503, 'Le contrôle du Compte de la Régie est occupé.', 'regie_codex_lock_unavailable');
            }
            $regieAccessLockHeld = true;
            if ((bool) imageStudioRegieServiceRecord($connection)['paused']) {
                sendError(423, 'Le Compte de la Régie est actuellement en pause.', 'regie_codex_paused');
            }
        }
        if ($clientRequestId !== '') {
            $duplicate = $connection->prepare(
                'SELECT id FROM image_studio_messages WHERE author_account_id = :account_id '
                . 'AND client_request_id = :client_request_id LIMIT 1'
            );
            $duplicate->execute([
                ':account_id' => $accountId,
                ':client_request_id' => $clientRequestId,
            ]);
            $duplicateId = $duplicate->fetchColumn();
            if (is_string($duplicateId) && validImageStudioMessageId($duplicateId)) {
                sendJson(200, [
                    'ok' => true,
                    'deduplicated' => true,
                    'message' => imageStudioMessagePayload(imageStudioMessageRecord($connection, $duplicateId)),
                ]);
            }
        }
        $stale = $connection->prepare(
            "UPDATE image_studio_messages SET status = 'failed', error_code = 'interrupted', "
            . "error_detail = 'La Régie a été interrompue avant la fin de la génération.', completed_at = UTC_TIMESTAMP(3) "
            . "WHERE author_account_id = :account_id AND execution_mode = 'local' "
            . "AND status IN ('queued', 'generating') "
            . 'AND updated_at < DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 30 MINUTE)'
        );
        $stale->execute([':account_id' => $accountId]);
        $active = $connection->prepare(
            "SELECT COUNT(*) FROM image_studio_messages WHERE author_account_id = :account_id "
            . "AND execution_mode = :execution_mode AND status IN ('queued', 'generating')"
        );
        $active->execute([
            ':account_id' => $accountId,
            ':execution_mode' => $executionMode,
        ]);
        if ((int) $active->fetchColumn() > 0) {
            sendError(
                409,
                $executionMode === 'regie'
                    ? 'Une demande utilise déjà le Compte de la Régie pour ce compte MJ.'
                    : 'Une génération personnelle est déjà en cours pour ce compte MJ.',
                'generation_already_active'
            );
        }
        $id = randomToken(18);
        $insert = $connection->prepare(
            'INSERT INTO image_studio_messages '
            . '(id, conversation_id, author_account_id, operation, prompt, quality, aspect, execution_mode, '
            . 'client_request_id, references_json, parent_message_id) '
            . "VALUES (:id, :conversation_id, :author_account_id, :operation, :prompt, 'high', :aspect, "
            . ':execution_mode, :client_request_id, :references_json, :parent_message_id)'
        );
        $insert->execute([
            ':id' => $id,
            ':conversation_id' => $conversationId,
            ':author_account_id' => $accountId,
            ':operation' => $operation,
            ':prompt' => $prompt,
            ':aspect' => $aspect,
            ':execution_mode' => $executionMode,
            ':client_request_id' => $clientRequestId !== '' ? $clientRequestId : null,
            ':references_json' => json_encode($references, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':parent_message_id' => $parentMessageId !== '' ? $parentMessageId : null,
        ]);
        $touch = $connection->prepare('UPDATE image_studio_conversations SET updated_at = UTC_TIMESTAMP(3) WHERE id = :id');
        $touch->execute([':id' => $conversationId]);
    } finally {
        if ($regieAccessLockHeld) {
            try {
                $connection->query("SELECT RELEASE_LOCK('xar-regie-codex-access')");
            } catch (Throwable) {
            }
        }
        try {
            $release = $connection->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute([':lock_name' => $lockName]);
        } catch (Throwable) {
        }
    }
    $message = imageStudioMessageRecord($connection, $id);
    sendJson(201, ['ok' => true, 'message' => imageStudioMessagePayload($message)]);
}

function startImageStudioMessage(PDO $connection, string $id): never
{
    $identity = requireImageStudioIdentity($connection);
    $message = requireOwnedImageStudioMessage($connection, $identity, $id);
    if ((string) ($message['execution_mode'] ?? 'local') !== 'local') {
        sendError(403, 'Cette demande doit être prise par le worker du Compte de la Régie.', 'regie_codex_worker_required');
    }
    if ((string) $message['status'] === 'generating') {
        sendJson(200, ['ok' => true, 'message' => imageStudioMessagePayload($message)]);
    }
    if ((string) $message['status'] !== 'queued') {
        sendError(409, 'Cette génération ne peut plus être démarrée.', 'invalid_generation_state');
    }
    $statement = $connection->prepare(
        "UPDATE image_studio_messages SET status = 'generating', started_at = UTC_TIMESTAMP(3) "
        . "WHERE id = :id AND status = 'queued'"
    );
    $statement->execute([':id' => $id]);
    sendJson(200, ['ok' => true, 'message' => imageStudioMessagePayload(imageStudioMessageRecord($connection, $id))]);
}

function completeImageStudioMessage(PDO $connection, string $id): never
{
    $identity = requireImageStudioIdentity($connection);
    $message = requireOwnedImageStudioMessage($connection, $identity, $id);
    if ((string) ($message['execution_mode'] ?? 'local') !== 'local') {
        sendError(403, 'Cette demande doit être terminée par le worker du Compte de la Régie.', 'regie_codex_worker_required');
    }
    $payload = readJsonBody(65536);
    $mediaId = trim((string) ($payload['mediaId'] ?? ''));
    if (preg_match('/^[A-Za-z0-9_-]{24}$/D', $mediaId) !== 1) {
        sendError(400, 'Résultat média invalide.', 'invalid_media');
    }
    $media = $connection->prepare(
        'SELECT id, content_type, uploaded_by_account_id, pending_delete_at FROM media_objects WHERE id = :id LIMIT 1'
    );
    $media->execute([':id' => $mediaId]);
    $record = $media->fetch();
    if (!is_array($record)
        || !str_starts_with((string) $record['content_type'], 'image/')
        || (string) $record['uploaded_by_account_id'] !== (string) $identity['id']
        || $record['pending_delete_at'] !== null) {
        sendError(403, 'Le résultat doit être une image privée envoyée par ce compte.', 'media_ownership_required');
    }
    if ((string) $message['status'] === 'succeeded' && (string) $message['media_id'] === $mediaId) {
        sendJson(200, ['ok' => true, 'message' => imageStudioMessagePayload($message)]);
    }
    if (!in_array((string) $message['status'], ['queued', 'generating'], true)) {
        sendError(409, 'Cette génération ne peut plus recevoir de résultat.', 'invalid_generation_state');
    }
    $revisedPrompt = trim((string) ($payload['revisedPrompt'] ?? ''));
    if ($revisedPrompt !== '') {
        $revisedPrompt = cleanImageStudioMultilineText(
            $revisedPrompt,
            XAR_IMAGE_STUDIO_MAX_PROMPT_BYTES,
            'Prompt révisé',
            'invalid_revised_prompt'
        );
    }
    $width = max(1, min(8192, (int) ($payload['width'] ?? 1)));
    $height = max(1, min(8192, (int) ($payload['height'] ?? 1)));
    $statement = $connection->prepare(
        "UPDATE image_studio_messages SET status = 'succeeded', media_id = :media_id, revised_prompt = :revised_prompt, "
        . 'width = :width, height = :height, error_code = NULL, error_detail = NULL, completed_at = UTC_TIMESTAMP(3) '
        . "WHERE id = :id AND status IN ('queued', 'generating')"
    );
    $statement->execute([
        ':media_id' => $mediaId,
        ':revised_prompt' => $revisedPrompt !== '' ? $revisedPrompt : null,
        ':width' => $width,
        ':height' => $height,
        ':id' => $id,
    ]);
    sendJson(200, ['ok' => true, 'message' => imageStudioMessagePayload(imageStudioMessageRecord($connection, $id))]);
}

function failImageStudioMessage(PDO $connection, string $id): never
{
    $identity = requireImageStudioIdentity($connection);
    $message = requireOwnedImageStudioMessage($connection, $identity, $id);
    if ((string) ($message['execution_mode'] ?? 'local') !== 'local') {
        sendError(403, 'Cette demande doit être clôturée par le worker du Compte de la Régie.', 'regie_codex_worker_required');
    }
    if (!in_array((string) $message['status'], ['queued', 'generating'], true)) {
        sendJson(200, ['ok' => true, 'message' => imageStudioMessagePayload($message)]);
    }
    $payload = readJsonBody(8192);
    $code = strtolower(trim((string) ($payload['code'] ?? 'generation_failed')));
    if (preg_match('/^[a-z0-9_]{3,64}$/D', $code) !== 1) {
        $code = 'generation_failed';
    }
    $detail = cleanImageStudioMultilineText(
        $payload['message'] ?? 'La génération n’a pas abouti.',
        500,
        'Erreur',
        'invalid_error_detail'
    );
    $status = $code === 'request_rejected' ? 'rejected' : 'failed';
    $statement = $connection->prepare(
        'UPDATE image_studio_messages SET status = :status, error_code = :error_code, error_detail = :error_detail, '
        . "completed_at = UTC_TIMESTAMP(3) WHERE id = :id AND status IN ('queued', 'generating')"
    );
    $statement->execute([
        ':status' => $status,
        ':error_code' => $code,
        ':error_detail' => $detail,
        ':id' => $id,
    ]);
    sendJson(200, ['ok' => true, 'message' => imageStudioMessagePayload(imageStudioMessageRecord($connection, $id))]);
}

function completeImageStudioRegieJob(PDO $connection, string $id): never
{
    $identity = requireRegieCodexOwner($connection);
    $payload = readJsonBody(65536);
    $mediaId = trim((string) ($payload['mediaId'] ?? ''));
    if (preg_match('/^[A-Za-z0-9_-]{24}$/D', $mediaId) !== 1) {
        sendError(400, 'Résultat média invalide.', 'invalid_media');
    }
    $revisedPrompt = trim((string) ($payload['revisedPrompt'] ?? ''));
    if ($revisedPrompt !== '') {
        $revisedPrompt = cleanImageStudioMultilineText(
            $revisedPrompt,
            XAR_IMAGE_STUDIO_MAX_PROMPT_BYTES,
            'Prompt révisé',
            'invalid_revised_prompt'
        );
    }
    $width = max(1, min(8192, (int) ($payload['width'] ?? 1)));
    $height = max(1, min(8192, (int) ($payload['height'] ?? 1)));

    $connection->beginTransaction();
    try {
        $selectMessage = $connection->prepare('SELECT * FROM image_studio_messages WHERE id = :id LIMIT 1 FOR UPDATE');
        $selectMessage->execute([':id' => $id]);
        $message = $selectMessage->fetch();
        if (!is_array($message) || (string) ($message['execution_mode'] ?? '') !== 'regie') {
            sendError(404, 'Travail du Compte de la Régie introuvable.', 'message_missing');
        }
        if ((string) $message['status'] === 'succeeded' && (string) $message['media_id'] === $mediaId) {
            $connection->commit();
            sendJson(200, ['ok' => true, 'message' => imageStudioMessagePayload(imageStudioMessageRecord($connection, $id))]);
        }
        if ((string) $message['status'] === 'cancelled') {
            $connection->commit();
            sendError(409, 'Cette demande a été annulée par son auteur.', 'generation_cancelled');
        }
        if ((string) $message['status'] !== 'generating'
            || (string) ($message['worker_account_id'] ?? '') !== (string) $identity['id']) {
            sendError(409, 'Ce travail n’est pas attribué à ce worker.', 'worker_job_not_claimed');
        }
        $selectMedia = $connection->prepare(
            'SELECT id, content_type, uploaded_by_account_id, pending_delete_at, public_slug '
            . 'FROM media_objects WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $selectMedia->execute([':id' => $mediaId]);
        $media = $selectMedia->fetch();
        if (!is_array($media)
            || !str_starts_with((string) $media['content_type'], 'image/')
            || (string) $media['uploaded_by_account_id'] !== (string) $identity['id']
            || $media['pending_delete_at'] !== null
            || $media['public_slug'] !== null) {
            sendError(403, 'Le résultat doit être une nouvelle image privée envoyée par le worker.', 'media_ownership_required');
        }
        $transfer = $connection->prepare(
            'UPDATE media_objects SET uploaded_by_account_id = :author_account_id WHERE id = :id'
        );
        $transfer->execute([
            ':author_account_id' => (string) $message['author_account_id'],
            ':id' => $mediaId,
        ]);
        $complete = $connection->prepare(
            "UPDATE image_studio_messages SET status = 'succeeded', media_id = :media_id, "
            . 'revised_prompt = :revised_prompt, width = :width, height = :height, '
            . 'error_code = NULL, error_detail = NULL, worker_account_id = NULL, '
            . 'worker_lease_expires_at = NULL, completed_at = UTC_TIMESTAMP(3) '
            . "WHERE id = :id AND status = 'generating'"
        );
        $complete->execute([
            ':media_id' => $mediaId,
            ':revised_prompt' => $revisedPrompt !== '' ? $revisedPrompt : null,
            ':width' => $width,
            ':height' => $height,
            ':id' => $id,
        ]);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
    sendJson(200, ['ok' => true, 'message' => imageStudioMessagePayload(imageStudioMessageRecord($connection, $id))]);
}

function failImageStudioRegieJob(PDO $connection, string $id): never
{
    $identity = requireRegieCodexOwner($connection);
    $message = imageStudioMessageRecord($connection, $id);
    if (!is_array($message) || (string) ($message['execution_mode'] ?? '') !== 'regie') {
        sendError(404, 'Travail du Compte de la Régie introuvable.', 'message_missing');
    }
    if (in_array((string) $message['status'], ['succeeded', 'failed', 'rejected', 'cancelled'], true)) {
        sendJson(200, ['ok' => true, 'message' => imageStudioMessagePayload($message)]);
    }
    if ((string) $message['status'] !== 'generating'
        || (string) ($message['worker_account_id'] ?? '') !== (string) $identity['id']) {
        sendError(409, 'Ce travail n’est pas attribué à ce worker.', 'worker_job_not_claimed');
    }
    $payload = readJsonBody(8192);
    $code = strtolower(trim((string) ($payload['code'] ?? 'generation_failed')));
    if (preg_match('/^[a-z0-9_]{3,64}$/D', $code) !== 1) {
        $code = 'generation_failed';
    }
    $detail = cleanImageStudioMultilineText(
        $payload['message'] ?? 'La génération n’a pas abouti.',
        500,
        'Erreur',
        'invalid_error_detail'
    );
    $status = $code === 'request_rejected' ? 'rejected' : 'failed';
    $statement = $connection->prepare(
        'UPDATE image_studio_messages SET status = :status, error_code = :error_code, '
        . 'error_detail = :error_detail, worker_account_id = NULL, worker_lease_expires_at = NULL, '
        . "completed_at = UTC_TIMESTAMP(3) WHERE id = :id AND status = 'generating' "
        . 'AND worker_account_id = :account_id'
    );
    $statement->execute([
        ':status' => $status,
        ':error_code' => $code,
        ':error_detail' => $detail,
        ':id' => $id,
        ':account_id' => (string) $identity['id'],
    ]);
    sendJson(200, ['ok' => true, 'message' => imageStudioMessagePayload(imageStudioMessageRecord($connection, $id))]);
}

function hideImageStudioMessage(PDO $connection, string $id): never
{
    $identity = requireImageStudioIdentity($connection);
    $message = requireOwnedImageStudioMessage($connection, $identity, $id);
    $cancelled = in_array((string) $message['status'], ['queued', 'generating'], true);
    $statement = $connection->prepare(
        "UPDATE image_studio_messages SET owner_hidden_at = COALESCE(owner_hidden_at, UTC_TIMESTAMP(3)), "
        . "error_code = CASE WHEN status IN ('queued', 'generating') THEN 'cancelled_by_author' ELSE error_code END, "
        . "error_detail = CASE WHEN status IN ('queued', 'generating') "
        . "THEN 'La demande a été annulée par son auteur.' ELSE error_detail END, "
        . "completed_at = CASE WHEN status IN ('queued', 'generating') THEN UTC_TIMESTAMP(3) ELSE completed_at END, "
        . "status = CASE WHEN status IN ('queued', 'generating') THEN 'cancelled' ELSE status END, "
        . 'worker_account_id = NULL, worker_lease_expires_at = NULL WHERE id = :id'
    );
    $statement->execute([':id' => $id]);
    $mediaScheduled = false;
    $mediaId = (string) ($message['media_id'] ?? '');
    if ($mediaId !== ''
        && $message['public_slug'] === null
        && mediaDomainReferenceCount($connection, $mediaId) === 0
        && !imageStudioMediaUsedByCatalog($connection, $mediaId)) {
        $mark = $connection->prepare(
            'UPDATE media_objects SET pending_delete_at = UTC_TIMESTAMP(3) '
            . 'WHERE id = :id AND public_slug IS NULL AND pending_delete_at IS NULL'
        );
        $mark->execute([':id' => $mediaId]);
        $mediaScheduled = $mark->rowCount() === 1;
    }
    sendJson(200, [
        'ok' => true,
        'hidden' => true,
        'cancelled' => $cancelled,
        'historyRetainedForAdministrator' => true,
        'mediaScheduledForDeletion' => $mediaScheduled,
        ...($mediaScheduled ? ['retainedUntil' => gmdate('c', time() + 30 * 86400)] : []),
    ]);
}

function listImageStudioGallery(PDO $connection, bool $headOnly): never
{
    $identity = requireImageStudioIdentity($connection);
    $scopeAll = ($_GET['scope'] ?? '') === 'all' && (bool) ($identity['can_administrate'] ?? false);
    $where = $scopeAll
        ? "m.status = 'succeeded'"
        : "m.status = 'succeeded' AND m.author_account_id = :account_id AND m.owner_hidden_at IS NULL";
    $statement = $connection->prepare(
        'SELECT m.*, mo.public_slug, mo.pending_delete_at, mo.content_type AS media_content_type, c.title AS conversation_title, '
        . 'a.username, a.display_name FROM image_studio_messages m '
        . 'JOIN image_studio_conversations c ON c.id = m.conversation_id '
        . 'JOIN accounts a ON a.id = m.author_account_id '
        . 'JOIN media_objects mo ON mo.id = m.media_id '
        . 'WHERE ' . $where . ' AND mo.pending_delete_at IS NULL '
        . 'ORDER BY m.completed_at DESC, m.id DESC LIMIT 500'
    );
    if (!$scopeAll) {
        $statement->bindValue(':account_id', (string) $identity['id']);
    }
    $statement->execute();
    $images = array_map(static function (array $row) use ($scopeAll): array {
        return [
            ...imageStudioMessagePayload($row, $scopeAll),
            'conversationTitle' => (string) $row['conversation_title'],
            'author' => [
                'id' => (string) $row['author_account_id'],
                'username' => (string) $row['username'],
                'displayName' => (string) $row['display_name'],
            ],
        ];
    }, $statement->fetchAll());
    sendJson(200, [
        'ok' => true,
        'scope' => $scopeAll ? 'all' : 'mine',
        'images' => $images,
        'retentionNotice' => 'Une image retirée disparaît de la collection du MJ, mais son journal reste accessible à l’administrateur.',
    ], $headOnly);
}

function imageStudioMediaOwner(PDO $connection, string $mediaId): ?array
{
    if (preg_match('/^[A-Za-z0-9_-]{24}$/D', $mediaId) !== 1) {
        return null;
    }
    $statement = $connection->prepare(
        'SELECT m.author_account_id, m.owner_hidden_at, m.id AS message_id '
        . 'FROM image_studio_messages m WHERE m.media_id = :media_id '
        . 'ORDER BY m.created_at DESC LIMIT 1'
    );
    $statement->execute([':media_id' => $mediaId]);
    $record = $statement->fetch();
    return is_array($record) ? $record : null;
}

function imageStudioMediaUsedByCurrentDomain(PDO $connection, string $mediaId): bool
{
    if (preg_match('/^[A-Za-z0-9_-]{24}$/D', $mediaId) !== 1) {
        return false;
    }
    $statement = $connection->prepare(
        'SELECT COUNT(*) FROM application_domains WHERE CAST(payload AS CHAR) LIKE :reference'
    );
    $statement->execute([':reference' => '%/media/' . $mediaId . '%']);
    return (int) $statement->fetchColumn() > 0;
}

function imageStudioMediaUsedByCatalog(PDO $connection, string $mediaId): bool
{
    if (preg_match('/^[A-Za-z0-9_-]{24}$/D', $mediaId) !== 1) {
        return false;
    }
    $statement = $connection->prepare(
        'SELECT COUNT(*) FROM image_reference_catalog WHERE media_id = :media_id AND active = 1'
    );
    $statement->execute([':media_id' => $mediaId]);
    return (int) $statement->fetchColumn() > 0;
}

function assertImageStudioReferenceMediaAccess(PDO $connection, array $identity, string $mediaId): void
{
    if (preg_match('/^[A-Za-z0-9_-]{24}$/D', $mediaId) !== 1) {
        sendError(400, 'Média de référence invalide.', 'invalid_media');
    }
    $statement = $connection->prepare(
        'SELECT id, content_type, uploaded_by_account_id, pending_delete_at '
        . 'FROM media_objects WHERE id = :id LIMIT 1'
    );
    $statement->execute([':id' => $mediaId]);
    $media = $statement->fetch();
    if (!is_array($media)
        || !str_starts_with((string) $media['content_type'], 'image/')
        || $media['pending_delete_at'] !== null) {
        sendError(404, 'Image de référence introuvable.', 'media_missing');
    }
    if (imageStudioMediaUsedByCatalog($connection, $mediaId)) {
        return;
    }
    $studioOwner = imageStudioMediaOwner($connection, $mediaId);
    if (is_array($studioOwner)) {
        assertImageStudioMediaAccess($connection, $identity, $mediaId);
        return;
    }
    if ((string) ($media['uploaded_by_account_id'] ?? '') !== (string) $identity['id']) {
        sendError(403, 'Cette référence appartient à un autre MJ.', 'media_forbidden');
    }
}

function assertImageStudioMediaAccess(PDO $connection, array $identity, string $mediaId): void
{
    $owner = imageStudioMediaOwner($connection, $mediaId);
    $catalogued = imageStudioMediaUsedByCatalog($connection, $mediaId);
    if (!is_array($owner)) {
        if ($catalogued) return;
        sendError(404, 'Image du studio introuvable.', 'media_missing');
    }
    $administrator = (bool) ($identity['can_administrate'] ?? false);
    if ((string) $owner['author_account_id'] !== (string) $identity['id'] && !$administrator) {
        // Une image privée devient visible par les utilisateurs authentifiés uniquement
        // lorsqu'un domaine actif (par exemple la map courante) la référence.
        if ($catalogued || imageStudioMediaUsedByCurrentDomain($connection, $mediaId)) {
            return;
        }
        sendError(403, 'Cette image appartient à un autre MJ.', 'media_forbidden');
    }
    if ($owner['owner_hidden_at'] !== null && !$administrator) {
        if ($catalogued || imageStudioMediaUsedByCurrentDomain($connection, $mediaId)) {
            return;
        }
        sendError(404, 'Image retirée de votre collection.', 'media_missing');
    }
}

function streamImageStudioMedia(PDO $connection, string $mediaId, bool $headOnly): never
{
    $identity = requireImageStudioIdentity($connection);
    assertImageStudioMediaAccess($connection, $identity, $mediaId);
    $record = mediaRecord($connection, $mediaId);
    $path = is_array($record) ? privateMediaDirectory() . DIRECTORY_SEPARATOR . basename((string) $record['stored_name']) : '';
    if (!is_array($record) || $record['pending_delete_at'] !== null || !is_file($path)) {
        sendError(404, 'Image introuvable.', 'media_missing');
    }
    $size = (int) $record['byte_size'];
    $start = 0;
    $end = $size - 1;
    $status = 200;
    $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
    if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $match) === 1) {
        if ($match[1] === '' && $match[2] !== '') {
            $start = max(0, $size - (int) $match[2]);
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
    header('Cache-Control: private, max-age=900');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'");
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

function xarTsarothReferenceViewRank(string $file): int
{
    if (str_contains($file, '_front')) {
        return 0;
    }
    if (str_contains($file, 'portrait')) {
        return 1;
    }
    if (str_contains($file, '_side') || str_contains($file, 'expressive') || str_contains($file, 'nohelmet')) {
        return 2;
    }
    if (str_contains($file, '_back')) {
        return 3;
    }
    return 4;
}

function xarTsarothSiteReferenceCatalog(): array
{
    $characters = [
        ['gohachu', 'Gohachu', ['gohachu_back', 'gohachu_front', 'gohachu_side']],
        ['hira', 'Hira', ['hira_back', 'hira_front', 'hira_side']],
        ['inho', 'Inho', ['inho_back', 'inho_front', 'inho_side'], ['Innota']],
        ['ada', 'Ada', ['ada_side', 'ada_front', 'ada_expressive']],
        ['kokaku', 'Kokaku', ['kokaku_back', 'kokaku_front', 'kokaku_side']],
        ['arvin', 'Arvin', ['arvin_back', 'arvin_front', 'arvin_side']],
        ['varska', 'Varska', ['varska_back', 'varska_front', 'varska_side']],
        ['krael', 'Krael', ['krael_back', 'krael_front', 'krael_side']],
        ['eiko-perso', 'Eiko', ['eiko_back', 'eiko_front', 'eiko_side']],
        ['killgert', 'Killgert', ['killgert_back', 'killgert_front', 'killgert_side']],
        ['torrent', 'Torrent', ['torrent_back', 'torrent_front', 'torrent_side', 'torrent_side_nohelmet']],
        ['magnan', 'Magnan', ['magnan_front']],
        ['azala', 'Azala', ['azala_front']],
        ['nekarion', 'Nekarion', ['nekarion_front']],
        ['ponfeus', 'Ponféus', ['ponfeus_front'], ['Ponfeus']],
        ['fabio', 'Fabio', ['fabio_front']],
        ['decembre', 'Décembre', ['decembre_front'], ['Decembre']],
        ['finn', 'Finn', ['finn_front']],
        ['francisco', 'Francisco', ['francisco_front_7297eed5'], ['Père Francisco']],
        ['sterling', 'Sterling', ['sterling_front']],
        ['basavetch', 'Basavetch', ['basavetch_front']],
        ['junta', 'Junta', ['junta_front']],
        ['heir', 'Mr Heir', ['heir_front'], ['Heir', 'Monsieur Heir']],
        ['vermicrass', 'Vermicrass', ['vermicrass_front_46f62e90']],
        ['kuun-adamas', 'Kuun Adamas', ['kuun-adamas_front_9600878f'], ['Kuun']],
        ['kodian', 'Kodian', ['kodian_front']],
        ['ora', 'Ora', ['ora_front']],
        ['lukianto', 'Lukianto', ['lukianto_front']],
        ['biron', 'Biron', ['biron_front']],
        ['yuhra', 'Yuhra', ['yuhra_front']],
        ['aline', 'Aline', ['aline_front']],
        ['edward', 'Edward', ['edward_front']],
        ['marcel', 'Marcel', ['marcel_front']],
        ['miguel', 'Miguel', ['miguel_front']],
        ['miranda', 'Miranda', ['miranda_front']],
        ['dalamund', 'Dalamund', ['dalamund_front', 'dalamund_side', 'dalamund_back']],
        ['darth', 'Darth', ['darth_front', 'darth_side', 'darth_back']],
        ['zyun', 'Zyun', ['zyun_front', 'zyun_side', 'zyun_back']],
        ['raedolas', 'Ra-Edolas', ['raedolas_front', 'raedolas_side', 'raedolas_back'], ['Ra Edolas', 'RaEdolas']],
        ['jack', 'Jack', ['jack_front', 'jack_side', 'jack_back']],
        ['almendra', 'Almendra', ['almendra_front', 'almendra_side', 'almendra_back']],
        ['luros', 'Luros', ['luros_front', 'luros_side', 'luros_back']],
        ['nedrezar', 'Nedrezar', ['nedrezar_back_7b872d3d', 'nedrezar_front_353e238d', 'nedrezar_side_b4edb406']],
        ['sanzu', 'Sanzu', ['sanzu_scene', 'sanzu_front', 'sanzu_portrait']],
        ['shadow', 'Shadow', ['shadow_front']],
        ['yme', 'Yme', ['yme_portrait']],
        ['kobalt', 'Kobalt', ['kobalt_front']],
    ];
    $catalog = [];
    $priority = 0;
    foreach ($characters as $character) {
        [$characterId, $name, $files] = $character;
        usort($files, static function (string $left, string $right): int {
            $rank = xarTsarothReferenceViewRank($left) <=> xarTsarothReferenceViewRank($right);
            return $rank !== 0 ? $rank : strcmp($left, $right);
        });
        $aliases = array_values(array_unique([$name, ...($character[3] ?? [])]));
        foreach ($files as $file) {
            if (isset($catalog[$file])) {
                $catalog[$file]['aliases'] = array_values(array_unique([...$catalog[$file]['aliases'], ...$aliases]));
                continue;
            }
            $view = str_contains($file, '_front') ? 'Face'
                : (str_contains($file, '_side') || str_contains($file, 'expressive') ? 'Profil'
                    : (str_contains($file, '_back') ? 'Dos'
                        : (str_contains($file, 'portrait') ? 'Portrait'
                            : (str_contains($file, 'scene') ? 'Scène' : 'Référence'))));
            $traits = [];
            if (str_contains($file, 'nohelmet') || str_contains($file, 'sans_casque')) {
                $traits[] = 'Sans casque';
            }
            if (preg_match('/(?:^|_)(?:unarmed|no_?weapons?|without_?weapons?|sans_?armes?)(?:_|$)/', $file) === 1) {
                $traits[] = 'Sans armes';
            } elseif (preg_match('/(?:^|_)(?:armed|with_?weapons?|weapons?|avec_?armes?)(?:_|$)/', $file) === 1) {
                $traits[] = 'Avec armes';
            }
            $variant = implode(' · ', [$view, ...$traits]);
            $catalog[$file] = [
                'id' => 'site-' . $characterId . '-' . substr(hash('sha256', $file), 0, 10),
                'subjectId' => 'site-' . $characterId,
                'label' => $name . ' — ' . $variant,
                'aliases' => $aliases,
                'mediaId' => null,
                'sourceUrl' => 'https://www.xar-tsaroth.fr/media/personnages/' . rawurlencode($file) . '.webp',
                'imageUrl' => 'https://www.xar-tsaroth.fr/media/personnages/' . rawurlencode($file) . '.webp',
                'active' => true,
                'priority' => $priority++,
                'source' => 'site',
                'updatedAt' => null,
            ];
        }
    }
    return array_values($catalog);
}

function imageReferencePayload(array $row): array
{
    return [
        'id' => (string) $row['id'],
        'label' => (string) $row['label'],
        'aliases' => jsonColumn($row['aliases_json'] ?? '[]'),
        'mediaId' => (string) $row['media_id'],
        'imageUrl' => '/api/v1/image-studio/media/' . (string) $row['media_id'],
        'active' => (bool) $row['active'],
        'priority' => (int) $row['priority'],
        'updatedAt' => (string) $row['updated_at'],
    ];
}

function listImageReferenceCatalog(PDO $connection, bool $headOnly): never
{
    $identity = requireImageStudioIdentity($connection);
    $includeInactive = ($_GET['all'] ?? '') === '1' && (bool) ($identity['can_administrate'] ?? false);
    $statement = $connection->query(
        'SELECT id, label, aliases_json, media_id, active, priority, updated_at '
        . 'FROM image_reference_catalog ' . ($includeInactive ? '' : 'WHERE active = 1 ')
        . 'ORDER BY priority, label LIMIT 1000'
    );
    $rows = $statement === false ? [] : $statement->fetchAll();
    $references = [...xarTsarothSiteReferenceCatalog(), ...array_map('imageReferencePayload', $rows)];
    sendJson(200, ['ok' => true, 'references' => $references], $headOnly);
}

function normalizedImageReferenceAliases(mixed $value): array
{
    if (!is_array($value) || count($value) > 20) {
        sendError(400, 'Une référence accepte au maximum vingt alias.', 'invalid_aliases');
    }
    $aliases = [];
    foreach ($value as $alias) {
        $clean = cleanImageStudioSingleLineText($alias, 80, 'Alias', 'invalid_reference_alias');
        $key = mb_strtolower($clean, 'UTF-8');
        $aliases[$key] = $clean;
    }
    return array_values($aliases);
}

function writeImageReferenceCatalog(PDO $connection, ?string $id = null): never
{
    $identity = requireAdministratorIdentity($connection);
    $payload = readJsonBody(32768);
    $existing = $id === null ? null : (function () use ($connection, $id): ?array {
        if (!validImageStudioMessageId($id)) {
            return null;
        }
        $statement = $connection->prepare('SELECT * FROM image_reference_catalog WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    })();
    if ($id !== null && !is_array($existing)) {
        sendError(404, 'Référence de catalogue introuvable.', 'reference_missing');
    }
    $label = array_key_exists('label', $payload)
        ? cleanImageStudioSingleLineText(
            $payload['label'],
            120,
            'Nom de référence',
            'invalid_reference_label'
        )
        : (string) ($existing['label'] ?? '');
    $aliases = array_key_exists('aliases', $payload)
        ? normalizedImageReferenceAliases($payload['aliases'])
        : jsonColumn($existing['aliases_json'] ?? '[]');
    $mediaId = array_key_exists('mediaId', $payload)
        ? trim((string) $payload['mediaId'])
        : (string) ($existing['media_id'] ?? '');
    if (preg_match('/^[A-Za-z0-9_-]{24}$/D', $mediaId) !== 1) {
        sendError(400, 'Média de référence invalide.', 'invalid_media');
    }
    $media = $connection->prepare(
        "SELECT id FROM media_objects WHERE id = :id AND content_type LIKE 'image/%' AND pending_delete_at IS NULL LIMIT 1"
    );
    $media->execute([':id' => $mediaId]);
    if ($media->fetchColumn() === false) {
        sendError(404, 'Image de référence introuvable.', 'media_missing');
    }
    $active = array_key_exists('active', $payload) ? $payload['active'] === true : (bool) ($existing['active'] ?? true);
    $priority = array_key_exists('priority', $payload)
        ? max(0, min(65535, (int) $payload['priority']))
        : (int) ($existing['priority'] ?? 100);
    $encodedAliases = json_encode($aliases, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if ($id === null) {
        $id = randomToken(18);
        $statement = $connection->prepare(
            'INSERT INTO image_reference_catalog '
            . '(id, label, aliases_json, media_id, active, priority, created_by_account_id) '
            . 'VALUES (:id, :label, :aliases_json, :media_id, :active, :priority, :account_id)'
        );
        $statement->execute([
            ':id' => $id,
            ':label' => $label,
            ':aliases_json' => $encodedAliases,
            ':media_id' => $mediaId,
            ':active' => $active ? 1 : 0,
            ':priority' => $priority,
            ':account_id' => (string) $identity['id'],
        ]);
        $status = 201;
    } else {
        $statement = $connection->prepare(
            'UPDATE image_reference_catalog SET label = :label, aliases_json = :aliases_json, '
            . 'media_id = :media_id, active = :active, priority = :priority WHERE id = :id'
        );
        $statement->execute([
            ':id' => $id,
            ':label' => $label,
            ':aliases_json' => $encodedAliases,
            ':media_id' => $mediaId,
            ':active' => $active ? 1 : 0,
            ':priority' => $priority,
        ]);
        $status = 200;
    }
    $select = $connection->prepare(
        'SELECT id, label, aliases_json, media_id, active, priority, updated_at '
        . 'FROM image_reference_catalog WHERE id = :id LIMIT 1'
    );
    $select->execute([':id' => $id]);
    sendJson($status, ['ok' => true, 'reference' => imageReferencePayload($select->fetch())]);
}

function handleImageStudioRoute(PDO $connection, string $route, string $method, bool $headOnly): bool
{
    if (!str_starts_with($route, '/api/v1/image-studio')) {
        return false;
    }
    cleanupImageStudioSessions($connection);
    if ($route === '/api/v1/image-studio/auth/login') {
        requireMethod($method, ['POST']);
        loginImageStudio($connection);
    }
    if ($route === '/api/v1/image-studio/auth/logout') {
        requireMethod($method, ['POST']);
        logoutImageStudio($connection);
    }
    if ($route === '/api/v1/image-studio/auth/me') {
        requireMethod($method, ['GET', 'HEAD']);
        $identity = requireImageStudioIdentity($connection);
        sendJson(200, ['ok' => true, 'account' => imageStudioPublicIdentity($identity)], $headOnly);
    }
    if ($route === '/api/v1/image-studio/regie/status') {
        requireMethod($method, ['GET', 'HEAD']);
        readImageStudioRegieService($connection, $headOnly);
    }
    if ($route === '/api/v1/image-studio/regie/access') {
        requireMethod($method, ['POST']);
        updateImageStudioRegieAccess($connection);
    }
    if ($route === '/api/v1/image-studio/regie/worker/heartbeat') {
        requireMethod($method, ['POST']);
        heartbeatImageStudioRegieWorker($connection);
    }
    if ($route === '/api/v1/image-studio/regie/jobs/claim') {
        requireMethod($method, ['POST']);
        claimImageStudioRegieJob($connection);
    }
    if (preg_match('#^/api/v1/image-studio/regie/jobs/([A-Za-z0-9_-]{24})/complete$#', $route, $match) === 1) {
        requireMethod($method, ['POST']);
        completeImageStudioRegieJob($connection, $match[1]);
    }
    if (preg_match('#^/api/v1/image-studio/regie/jobs/([A-Za-z0-9_-]{24})/fail$#', $route, $match) === 1) {
        requireMethod($method, ['POST']);
        failImageStudioRegieJob($connection, $match[1]);
    }
    if ($route === '/api/v1/image-studio/conversations') {
        if ($method === 'GET' || $method === 'HEAD') {
            listImageStudioConversations($connection, $headOnly);
        }
        if ($method === 'POST') {
            createImageStudioConversation($connection);
        }
        requireMethod($method, ['GET', 'HEAD', 'POST']);
    }
    if ($route === '/api/v1/image-studio/gallery') {
        requireMethod($method, ['GET', 'HEAD']);
        listImageStudioGallery($connection, $headOnly);
    }
    if ($route === '/api/v1/image-studio/references') {
        if ($method === 'GET' || $method === 'HEAD') {
            listImageReferenceCatalog($connection, $headOnly);
        }
        if ($method === 'POST') {
            writeImageReferenceCatalog($connection);
        }
        requireMethod($method, ['GET', 'HEAD', 'POST']);
    }
    if (preg_match('#^/api/v1/image-studio/references/([A-Za-z0-9_-]{24})$#', $route, $match) === 1) {
        requireMethod($method, ['PATCH']);
        writeImageReferenceCatalog($connection, $match[1]);
    }
    if (preg_match('#^/api/v1/image-studio/conversations/([A-Za-z0-9_-]{22})$#', $route, $match) === 1) {
        requireMethod($method, ['PATCH']);
        updateImageStudioConversation($connection, $match[1]);
    }
    if (preg_match('#^/api/v1/image-studio/conversations/([A-Za-z0-9_-]{22})/messages$#', $route, $match) === 1) {
        if ($method === 'GET' || $method === 'HEAD') {
            listImageStudioMessages($connection, $match[1], $headOnly);
        }
        if ($method === 'POST') {
            createImageStudioMessage($connection, $match[1]);
        }
        requireMethod($method, ['GET', 'HEAD', 'POST']);
    }
    if (preg_match('#^/api/v1/image-studio/messages/([A-Za-z0-9_-]{24})/start$#', $route, $match) === 1) {
        requireMethod($method, ['POST']);
        startImageStudioMessage($connection, $match[1]);
    }
    if (preg_match('#^/api/v1/image-studio/messages/([A-Za-z0-9_-]{24})/complete$#', $route, $match) === 1) {
        requireMethod($method, ['POST']);
        completeImageStudioMessage($connection, $match[1]);
    }
    if (preg_match('#^/api/v1/image-studio/messages/([A-Za-z0-9_-]{24})/fail$#', $route, $match) === 1) {
        requireMethod($method, ['POST']);
        failImageStudioMessage($connection, $match[1]);
    }
    if (preg_match('#^/api/v1/image-studio/messages/([A-Za-z0-9_-]{24})$#', $route, $match) === 1) {
        requireMethod($method, ['DELETE']);
        hideImageStudioMessage($connection, $match[1]);
    }
    if (preg_match('#^/api/v1/image-studio/media/([A-Za-z0-9_-]{24})$#', $route, $match) === 1) {
        requireMethod($method, ['GET', 'HEAD']);
        streamImageStudioMedia($connection, $match[1], $headOnly);
    }
    sendError(404, 'Route du studio d’images inconnue.', 'not_found');
}
