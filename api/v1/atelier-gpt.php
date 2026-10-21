<?php

declare(strict_types=1);

// Atelier identities stay separate from Régie accounts. Only the existing,
// authenticated Régie owner worker receives the deliberately small image job.
function atelierGptSchemaStatements(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS atelier_gpt_conversations ("
        . "id VARCHAR(22) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY, "
        . "owner_user_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, "
        . "title VARCHAR(160) NOT NULL, client_request_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, "
        . "created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3), updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3), "
        . "UNIQUE KEY atelier_gpt_conversation_receipt (owner_user_id, client_request_id), "
        . "KEY atelier_gpt_conversation_owner (owner_user_id, updated_at), "
        . "FOREIGN KEY (owner_user_id) REFERENCES atelier_users(id) ON DELETE CASCADE"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS atelier_gpt_messages ("
        . "id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY, "
        . "conversation_id VARCHAR(22) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, "
        . "author_user_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, "
        . "client_request_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, "
        . "request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, "
        . "prompt MEDIUMTEXT NOT NULL, operation VARCHAR(16) NOT NULL, aspect VARCHAR(16) NOT NULL, references_json JSON NOT NULL, "
        . "parent_message_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL, status VARCHAR(16) NOT NULL DEFAULT 'queued', "
        . "media_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, width INT UNSIGNED NULL, height INT UNSIGNED NULL, "
        . "worker_account_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL, worker_lease_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, "
        . "worker_attempts INT UNSIGNED NOT NULL DEFAULT 0, worker_lease_expires_at DATETIME(3) NULL, "
        . "error_code VARCHAR(64) NULL, error_detail VARCHAR(500) NULL, "
        . "created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3), started_at DATETIME(3) NULL, completed_at DATETIME(3) NULL, "
        . "updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3), "
        . "UNIQUE KEY atelier_gpt_message_receipt (author_user_id, client_request_id), "
        . "KEY atelier_gpt_queue (status, created_at, id), KEY atelier_gpt_author (author_user_id, status), "
        . "KEY atelier_gpt_conversation_messages (conversation_id, created_at, id), "
        . "FOREIGN KEY (conversation_id) REFERENCES atelier_gpt_conversations(id) ON DELETE CASCADE, "
        . "FOREIGN KEY (author_user_id) REFERENCES atelier_users(id) ON DELETE CASCADE, "
        . "FOREIGN KEY (worker_account_id) REFERENCES accounts(id) ON DELETE SET NULL"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

function atelierGptText(mixed $value, int $maximumBytes, string $label): string
{
    $text = trim(str_replace(["\r\n", "\r"], "\n", is_scalar($value) ? (string) $value : ''));
    if ($text === '' || strlen($text) > $maximumBytes || str_contains($text, "\0") || preg_match('//u', $text) !== 1) {
        sendError(400, $label . ' invalide.', 'invalid_atelier_gpt_input');
    }
    return $text;
}

function atelierGptRequestId(mixed $value, bool $required = true): string
{
    $id = is_string($value) ? trim($value) : '';
    if ($id === '' && !$required) return randomToken(18);
    if (preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $id) !== 1) {
        sendError(400, 'Référence de demande invalide.', 'invalid_client_request');
    }
    return $id;
}

function atelierGptConversationRecord(PDO $connection, string $id): ?array
{
    if (preg_match('/^[A-Za-z0-9_-]{22}$/D', $id) !== 1) return null;
    $query = $connection->prepare('SELECT * FROM atelier_gpt_conversations WHERE id = :id LIMIT 1');
    $query->execute([':id' => $id]);
    $row = $query->fetch();
    return is_array($row) ? $row : null;
}

function atelierGptMessageRecord(PDO $connection, string $id, bool $forUpdate = false): ?array
{
    if (preg_match('/^[A-Za-z0-9_-]{24}$/D', $id) !== 1) return null;
    $query = $connection->prepare('SELECT *' . ($forUpdate ? ', (worker_lease_expires_at >= UTC_TIMESTAMP(3)) AS worker_lease_live' : '')
        . ' FROM atelier_gpt_messages WHERE id = :id LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
    $query->execute([':id' => $id]);
    $row = $query->fetch();
    return is_array($row) ? $row : null;
}

function assertAtelierGptConversationAccess(PDO $connection, array $identity, ?array $conversation, bool $administratorRead = true): array
{
    if (!is_array($conversation)) sendError(404, 'Conversation introuvable.', 'conversation_missing');
    if ((string) $conversation['owner_user_id'] !== (string) $identity['id']
        && !($administratorRead && atelierIsOwner($connection, $identity))) {
        sendError(403, 'Cette conversation appartient à un autre compte.', 'conversation_forbidden');
    }
    return $conversation;
}

function atelierGptConversationPayload(array $row): array
{
    return ['id' => (string) $row['id'], 'title' => (string) $row['title'], 'ownerUserId' => (string) $row['owner_user_id'],
        'createdAt' => (string) $row['created_at'], 'updatedAt' => (string) $row['updated_at']];
}

function atelierGptMessagePayload(array $row): array
{
    $code = (string) ($row['error_code'] ?? '');
    $mediaId = (string) ($row['media_id'] ?? '');
    return [
        'id' => (string) $row['id'], 'conversationId' => (string) $row['conversation_id'],
        'prompt' => (string) $row['prompt'], 'operation' => (string) $row['operation'], 'aspect' => (string) $row['aspect'],
        'references' => json_decode((string) ($row['references_json'] ?? '[]'), true, 32, JSON_THROW_ON_ERROR),
        'status' => (string) $row['status'], 'parentMessageId' => $row['parent_message_id'] ?? null,
        'mediaId' => $mediaId !== '' ? $mediaId : null,
        'imageUrl' => $mediaId !== '' ? '/api/v1/image-studio/atelier/media/' . $mediaId : null,
        'width' => isset($row['width']) ? (int) $row['width'] : null, 'height' => isset($row['height']) ? (int) $row['height'] : null,
        'qualityRequested' => 'high', 'qualityApplied' => null,
        'error' => $code === '' ? null : ['code' => $code, 'message' => (string) ($row['error_detail'] ?? ''),
            'httpStatus' => in_array($code, ['image_quota_exhausted', 'usage_limit_exceeded', 'rate_limit_exceeded'], true) ? 429 : null],
        'createdAt' => $row['created_at'], 'startedAt' => $row['started_at'] ?? null, 'completedAt' => $row['completed_at'] ?? null,
    ];
}

function atelierGptReferences(PDO $connection, array $identity, mixed $value): array
{
    if (!is_array($value) || !array_is_list($value) || count($value) > 5) sendError(413, 'Au maximum cinq références sont acceptées.', 'reference_limit');
    $references = [];
    foreach ($value as $entry) {
        if (!is_array($entry) || preg_match('/^[A-Za-z0-9_-]{24}$/D', (string) ($entry['mediaId'] ?? '')) !== 1) {
            sendError(400, 'Image de référence invalide.', 'invalid_reference');
        }
        if (isset($entry['sourceUrl']) || isset($entry['path']) || isset($entry['dataUrl'])) sendError(400, 'Importez la référence dans votre Atelier.', 'invalid_reference');
        $mediaId = (string) $entry['mediaId'];
        assertAtelierMediaAccess($connection, $identity, $mediaId);
        $references[] = ['kind' => 'upload', 'label' => atelierGptText($entry['label'] ?? 'Référence', 120, 'Nom de référence'), 'mediaId' => $mediaId];
    }
    return $references;
}

function createAtelierGptConversation(PDO $connection, array $identity, array $payload): array
{
    $title = atelierGptText($payload['title'] ?? 'Nouvelle création', 160, 'Titre');
    $requestId = atelierGptRequestId($payload['clientRequestId'] ?? '', false);
    $connection->beginTransaction();
    try {
        // A per-user lock also closes the absent-receipt race on first creation.
        atelierLockUser($connection, $identity);
        $find = $connection->prepare('SELECT * FROM atelier_gpt_conversations WHERE owner_user_id = :user_id AND client_request_id = :request_id LIMIT 1');
        $find->execute([':user_id' => (string) $identity['id'], ':request_id' => $requestId]);
        $existing = $find->fetch();
        if (is_array($existing)) {
            if ((string) $existing['title'] !== $title) sendError(409, 'Cette référence désigne une autre création.', 'client_request_conflict');
            $connection->commit();
            return ['ok' => true, 'conversation' => atelierGptConversationPayload($existing), 'deduplicated' => true];
        }
        $id = randomToken(16);
        $insert = $connection->prepare('INSERT INTO atelier_gpt_conversations (id, owner_user_id, title, client_request_id) VALUES (:id, :user_id, :title, :request_id)');
        $insert->execute([':id' => $id, ':user_id' => (string) $identity['id'], ':title' => $title, ':request_id' => $requestId]);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $error;
    }
    return ['ok' => true, 'conversation' => atelierGptConversationPayload(atelierGptConversationRecord($connection, $id)), 'deduplicated' => false];
}

function createAtelierGptMessage(PDO $connection, array $identity, string $conversationId, array $payload): array
{
    assertAtelierGptConversationAccess($connection, $identity, atelierGptConversationRecord($connection, $conversationId), false);
    $prompt = atelierGptText($payload['prompt'] ?? '', 50000, 'Description');
    $operation = (string) ($payload['operation'] ?? 'generate');
    $aspect = (string) ($payload['aspect'] ?? 'landscape');
    if (!in_array($operation, ['generate', 'edit', 'regenerate'], true) || !in_array($aspect, ['landscape', 'portrait', 'square'], true)) {
        sendError(400, 'Opération ou format invalide.', 'invalid_atelier_gpt_input');
    }
    $requestId = atelierGptRequestId($payload['clientRequestId'] ?? '');
    $references = atelierGptReferences($connection, $identity, $payload['references'] ?? []);
    $parentId = trim((string) ($payload['parentMessageId'] ?? ''));
    if ($parentId !== '') {
        $parent = atelierGptMessageRecord($connection, $parentId);
        if (!is_array($parent) || (string) $parent['conversation_id'] !== $conversationId || (string) $parent['status'] !== 'succeeded' || !$parent['media_id']) {
            sendError(400, 'La source doit être une image réussie de cette conversation.', 'invalid_parent_message');
        }
        if ($operation === 'edit') {
            $references = array_values(array_filter($references, static fn(array $reference): bool => $reference['mediaId'] !== (string) $parent['media_id']));
            array_unshift($references, ['kind' => 'upload', 'label' => 'Image source', 'mediaId' => (string) $parent['media_id']]);
        }
    }
    if (count($references) > 5) sendError(413, 'La source et les références ne peuvent pas dépasser cinq images.', 'reference_limit');
    if ($operation === 'edit' && $references === []) sendError(400, 'Ajoutez une image source à modifier.', 'source_image_required');
    $hash = hash('sha256', json_encode([$conversationId, $prompt, $operation, $aspect, $references, $parentId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $connection->beginTransaction();
    try {
        // Keep the same lock order as claim/complete: service, then user/job.
        // The service row makes pause and enqueue atomic without a second relay.
        $service = imageStudioRegieServiceRecord($connection, true);
        atelierLockUser($connection, $identity);
        $find = $connection->prepare('SELECT * FROM atelier_gpt_messages WHERE author_user_id = :user_id AND client_request_id = :request_id LIMIT 1');
        $find->execute([':user_id' => (string) $identity['id'], ':request_id' => $requestId]);
        $existing = $find->fetch();
        if (is_array($existing)) {
            if (!hash_equals((string) $existing['request_hash'], $hash)) sendError(409, 'Cette référence de demande a déjà un autre contenu.', 'client_request_conflict');
            $connection->commit();
            return ['ok' => true, 'message' => atelierGptMessagePayload($existing), 'deduplicated' => true];
        }
        if ((bool) $service['paused']) sendError(423, 'Le compte de génération est en pause.', 'regie_codex_paused');
        $active = $connection->prepare("SELECT id FROM atelier_gpt_messages WHERE author_user_id = :user_id AND status IN ('queued', 'generating') LIMIT 1");
        $active->execute([':user_id' => (string) $identity['id']]);
        if ($active->fetchColumn() !== false) sendError(409, 'Une demande est déjà en cours pour votre compte.', 'generation_already_active');
        foreach (array_unique(array_column($references, 'mediaId')) as $mediaId) assertAtelierMediaAccess($connection, $identity, (string) $mediaId);
        $id = randomToken(18);
        $insert = $connection->prepare('INSERT INTO atelier_gpt_messages (id, conversation_id, author_user_id, client_request_id, request_hash, prompt, operation, aspect, references_json, parent_message_id) '
            . 'VALUES (:id, :conversation_id, :user_id, :request_id, :request_hash, :prompt, :operation, :aspect, :references, :parent_id)');
        $insert->execute([':id' => $id, ':conversation_id' => $conversationId, ':user_id' => (string) $identity['id'], ':request_id' => $requestId,
            ':request_hash' => $hash, ':prompt' => $prompt, ':operation' => $operation, ':aspect' => $aspect,
            ':references' => json_encode($references, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), ':parent_id' => $parentId !== '' ? $parentId : null]);
        $touch = $connection->prepare('UPDATE atelier_gpt_conversations SET updated_at = UTC_TIMESTAMP(3) WHERE id = :id');
        $touch->execute([':id' => $conversationId]);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $error;
    }
    return ['ok' => true, 'message' => atelierGptMessagePayload(atelierGptMessageRecord($connection, $id)), 'deduplicated' => false];
}

function atelierGptStatus(PDO $connection, array $identity): array
{
    $service = imageStudioRegieServiceRecord($connection);
    $query = $connection->prepare("SELECT SUM(status = 'queued') AS queued_count, SUM(status = 'generating') AS active_count FROM atelier_gpt_messages WHERE author_user_id = :user_id");
    $query->execute([':user_id' => (string) $identity['id']]);
    $counts = $query->fetch() ?: [];
    return ['ok' => true, 'service' => ['paused' => (bool) $service['paused'], 'acceptingRequests' => !(bool) $service['paused'],
        'workerOnline' => (bool) $service['worker_online'], 'workerImageOnline' => (bool) $service['worker_online'] && (bool) $service['worker_image_ready'],
        'queuedCount' => (int) ($counts['queued_count'] ?? 0), 'activeCount' => (int) ($counts['active_count'] ?? 0)],
        'quota' => ['source' => 'owner-codex-account', 'remainingPercent' => null, 'state' => 'not-exposed-by-worker'], 'maximumReferences' => 5];
}

function handleAtelierGptRoute(PDO $connection, string $fullRoute, string $method, bool $headOnly): bool
{
    $prefix = XAR_ATELIER_PREFIX . '/gpt';
    if ($fullRoute !== $prefix && !str_starts_with($fullRoute, $prefix . '/')) return false;
    $route = explode('/', ltrim(substr($fullRoute, strlen(XAR_ATELIER_PREFIX)), '/'));
    if (($route[0] ?? '') !== 'gpt') return false;
    $identity = requireAtelierIdentity($connection);
    if (!in_array($method, ['GET', 'HEAD'], true)) atelierWriteGuard();
    if ($route === ['gpt', 'status']) {
        requireMethod($method, ['GET', 'HEAD']);
        sendJson(200, atelierGptStatus($connection, $identity), $headOnly);
    }
    if ($route === ['gpt', 'conversations']) {
        requireMethod($method, ['GET', 'HEAD', 'POST']);
        if ($method === 'POST') {
            $result = createAtelierGptConversation($connection, $identity, atelierJsonBody(8192));
            sendJson($result['deduplicated'] ? 200 : 201, $result);
        }
        $all = ($_GET['scope'] ?? '') === 'all';
        if ($all && !atelierIsOwner($connection, $identity)) sendError(403, 'Lecture administrative refusée.', 'administrator_required');
        $query = $connection->prepare('SELECT * FROM atelier_gpt_conversations' . ($all ? '' : ' WHERE owner_user_id = :user_id') . ' ORDER BY updated_at DESC, id DESC LIMIT 200');
        $query->execute($all ? [] : [':user_id' => (string) $identity['id']]);
        sendJson(200, ['ok' => true, 'conversations' => array_map('atelierGptConversationPayload', $query->fetchAll())], $headOnly);
    }
    if (count($route) === 4 && $route[1] === 'conversations' && $route[3] === 'messages') {
        requireMethod($method, ['GET', 'HEAD', 'POST']);
        if ($method === 'POST') {
            $result = createAtelierGptMessage($connection, $identity, (string) $route[2], atelierJsonBody(196608));
            sendJson($result['deduplicated'] ? 200 : 202, $result);
        }
        $conversation = assertAtelierGptConversationAccess($connection, $identity, atelierGptConversationRecord($connection, (string) $route[2]));
        $query = $connection->prepare('SELECT * FROM atelier_gpt_messages WHERE conversation_id = :id ORDER BY created_at, id LIMIT 1000');
        $query->execute([':id' => (string) $route[2]]);
        sendJson(200, ['ok' => true, 'conversation' => atelierGptConversationPayload($conversation), 'messages' => array_map('atelierGptMessagePayload', $query->fetchAll())], $headOnly);
    }
    sendError(404, 'Route de génération inconnue.', 'route_missing');
}

// All following helpers run under the existing service singleton transaction.
// The Store client still sees jobType=image and uses its existing endpoints.
function recoverAtelierGptJobs(PDO $connection, ?string $replacementLeaseId = null): void
{
    $condition = $replacementLeaseId === null ? 'worker_lease_expires_at < UTC_TIMESTAMP(3)' : '(worker_lease_id IS NULL OR worker_lease_id <> :worker_lease_id)';
    $params = [':maximum_attempts' => XAR_IMAGE_STUDIO_WORKER_MAX_ATTEMPTS];
    if ($replacementLeaseId !== null) $params[':worker_lease_id'] = $replacementLeaseId;
    $fail = $connection->prepare("UPDATE atelier_gpt_messages SET status = 'failed', error_code = 'worker_interrupted', error_detail = 'Le relais a été interrompu à plusieurs reprises.', "
        . 'worker_account_id = NULL, worker_lease_id = NULL, worker_lease_expires_at = NULL, completed_at = UTC_TIMESTAMP(3) '
        . "WHERE status = 'generating' AND " . $condition . ' AND worker_attempts >= :maximum_attempts');
    $fail->execute($params);
    $retry = $connection->prepare("UPDATE atelier_gpt_messages SET status = 'queued', worker_account_id = NULL, worker_lease_id = NULL, worker_lease_expires_at = NULL, "
        . "started_at = NULL, error_code = NULL, error_detail = NULL WHERE status = 'generating' AND " . $condition . ' AND worker_attempts < :maximum_attempts');
    $retry->execute($params);
    $connection->exec("UPDATE atelier_gpt_messages m JOIN atelier_users u ON u.id = m.author_user_id SET m.status = 'cancelled', m.error_code = 'account_revoked', "
        . "m.error_detail = 'Le compte a été révoqué.', m.worker_account_id = NULL, m.worker_lease_id = NULL, m.worker_lease_expires_at = NULL, m.completed_at = UTC_TIMESTAMP(3) "
        . "WHERE u.revoked_at IS NOT NULL AND m.status IN ('queued', 'generating')");
}

function renewAtelierGptJob(PDO $connection, string $id, string $accountId, string $leaseId): void
{
    $query = $connection->prepare('UPDATE atelier_gpt_messages SET worker_lease_expires_at = DATE_ADD(UTC_TIMESTAMP(3), INTERVAL '
        . XAR_IMAGE_STUDIO_WORKER_LEASE_SECONDS . " SECOND) WHERE id = :id AND status = 'generating' AND worker_account_id = :account_id AND worker_lease_id = :worker_lease_id");
    $query->execute([':id' => $id, ':account_id' => $accountId, ':worker_lease_id' => $leaseId]);
}

function activeAtelierGptJob(PDO $connection): bool
{
    $query = $connection->query("SELECT id FROM atelier_gpt_messages WHERE status = 'generating' ORDER BY started_at, id LIMIT 1 FOR UPDATE");
    return $query !== false && $query->fetchColumn() !== false;
}

function nextAtelierGptJob(PDO $connection): array|false
{
    $query = $connection->query("SELECT m.id, m.created_at FROM atelier_gpt_messages m JOIN atelier_users u ON u.id = m.author_user_id WHERE m.status = 'queued' AND u.revoked_at IS NULL ORDER BY m.created_at, m.id LIMIT 1 FOR UPDATE");
    return $query === false ? false : $query->fetch();
}

function claimAtelierGptJob(PDO $connection, string $id, string $accountId, string $leaseId): bool
{
    $query = $connection->prepare("UPDATE atelier_gpt_messages SET status = 'generating', worker_account_id = :account_id, worker_lease_id = :worker_lease_id, "
        . 'worker_attempts = worker_attempts + 1, started_at = UTC_TIMESTAMP(3), worker_lease_expires_at = DATE_ADD(UTC_TIMESTAMP(3), INTERVAL '
        . XAR_IMAGE_STUDIO_WORKER_LEASE_SECONDS . " SECOND) WHERE id = :id AND status = 'queued'");
    $query->execute([':id' => $id, ':account_id' => $accountId, ':worker_lease_id' => $leaseId]);
    return $query->rowCount() === 1;
}

function atelierGptWorkerJobPayload(array $row): array
{
    return ['id' => (string) $row['id'], 'conversationId' => (string) $row['conversation_id'], 'jobType' => 'image',
        'prompt' => (string) $row['prompt'], 'aspect' => (string) $row['aspect'], 'operation' => (string) $row['operation'],
        'references' => json_decode((string) $row['references_json'], true, 32, JSON_THROW_ON_ERROR)];
}

function assertAtelierGptWorkerAuthority(PDO $connection, array $identity, array $service, string $leaseId): void
{
    $settings = $connection->query('SELECT regie_owner_account_id FROM atelier_settings WHERE singleton_id = 1')->fetch();
    if (!isRegieCodexOwner($identity) || !is_array($settings) || (string) ($settings['regie_owner_account_id'] ?? '') !== (string) $identity['id']) {
        sendError(403, 'Ce relais ne pilote pas cet Atelier.', 'regie_codex_owner_required');
    }
    if (!hash_equals((string) ($service['worker_lease_id'] ?? ''), $leaseId)) sendError(409, 'Ce relais a été remplacé.', 'worker_lease_replaced');
}

function finishAtelierGptWorkerJob(PDO $connection, array $identity, string $id, string $leaseId, array $payload, bool $success): array
{
    $connection->beginTransaction();
    try {
        $service = imageStudioRegieServiceRecord($connection, true);
        assertAtelierGptWorkerAuthority($connection, $identity, $service, $leaseId);
        $message = atelierGptMessageRecord($connection, $id, true);
        if (!is_array($message)) sendError(404, 'Travail Atelier introuvable.', 'message_missing');
        if ($success && (string) $message['status'] === 'succeeded' && (string) ($message['media_id'] ?? '') === (string) ($payload['mediaId'] ?? '')) {
            $connection->commit();
            return ['ok' => true, 'message' => atelierGptMessagePayload($message)];
        }
        if (!$success && in_array((string) $message['status'], ['succeeded', 'failed', 'rejected', 'cancelled'], true)) {
            $connection->commit();
            return ['ok' => true, 'message' => atelierGptMessagePayload($message)];
        }
        if ((string) $message['status'] !== 'generating' || (string) ($message['worker_account_id'] ?? '') !== (string) $identity['id']
            || !hash_equals((string) ($message['worker_lease_id'] ?? ''), $leaseId)) sendError(409, 'Travail non attribué à ce relais.', 'worker_job_not_claimed');
        if (!(bool) ($message['worker_lease_live'] ?? false)) sendError(409, 'Le bail de ce travail a expiré.', 'worker_job_expired');
        $author = $connection->prepare('SELECT id FROM atelier_users WHERE id = :id AND revoked_at IS NULL FOR UPDATE');
        $author->execute([':id' => (string) $message['author_user_id']]);
        if ($author->fetchColumn() === false) sendError(409, 'Le compte demandeur a été révoqué.', 'account_revoked');
        if ($success) {
            $mediaId = (string) ($payload['mediaId'] ?? '');
            if (preg_match('/^[A-Za-z0-9_-]{24}$/D', $mediaId) !== 1) sendError(400, 'Résultat média invalide.', 'invalid_media');
            $media = $connection->prepare('SELECT * FROM media_objects WHERE id = :id LIMIT 1 FOR UPDATE');
            $media->execute([':id' => $mediaId]);
            $row = $media->fetch();
            if (!is_array($row) || !in_array((string) $row['content_type'], ['image/png', 'image/jpeg', 'image/webp'], true)
                || (string) $row['uploaded_by_account_id'] !== (string) $identity['id'] || $row['pending_delete_at'] !== null || $row['public_slug'] !== null) {
                sendError(403, 'Le résultat doit être une nouvelle image privée du relais.', 'media_ownership_required');
            }
            $link = $connection->prepare('SELECT user_id FROM atelier_media WHERE media_id = :id LIMIT 1 FOR UPDATE');
            $link->execute([':id' => $mediaId]);
            if ($link->fetchColumn() !== false) sendError(409, 'Cette image est déjà utilisée par l’Atelier.', 'media_already_linked');
            $width = max(1, min(8192, (int) ($payload['width'] ?? 1)));
            $height = max(1, min(8192, (int) ($payload['height'] ?? 1)));
            $insert = $connection->prepare('INSERT INTO atelier_media (media_id, user_id, width, height) VALUES (:media_id, :user_id, :width, :height)');
            $insert->execute([':media_id' => $mediaId, ':user_id' => (string) $message['author_user_id'], ':width' => $width, ':height' => $height]);
            $update = $connection->prepare("UPDATE atelier_gpt_messages SET status = 'succeeded', media_id = :media_id, width = :width, height = :height, error_code = NULL, error_detail = NULL, "
                . 'worker_account_id = NULL, worker_lease_id = NULL, worker_lease_expires_at = NULL, completed_at = UTC_TIMESTAMP(3) WHERE id = :id');
            $update->execute([':media_id' => $mediaId, ':width' => $width, ':height' => $height, ':id' => $id]);
        } else {
            $code = (string) ($payload['code'] ?? 'generation_failed');
            if (preg_match('/^[a-z0-9_]{3,64}$/D', $code) !== 1) $code = 'generation_failed';
            $detail = atelierGptText($payload['message'] ?? 'La génération n’a pas abouti.', 500, 'Erreur');
            $update = $connection->prepare('UPDATE atelier_gpt_messages SET status = :status, error_code = :code, error_detail = :detail, '
                . 'worker_account_id = NULL, worker_lease_id = NULL, worker_lease_expires_at = NULL, completed_at = UTC_TIMESTAMP(3) WHERE id = :id');
            $update->execute([':status' => $code === 'request_rejected' ? 'rejected' : 'failed', ':code' => $code, ':detail' => $detail, ':id' => $id]);
        }
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $error;
    }
    return ['ok' => true, 'message' => atelierGptMessagePayload(atelierGptMessageRecord($connection, $id))];
}
