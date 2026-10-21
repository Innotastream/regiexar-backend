<?php
declare(strict_types=1);

const XAR_ATELIER_PREFIX = '/api/v1/image-studio/atelier';
const XAR_ATELIER_SESSION_SECONDS = 43200;
const XAR_ATELIER_FILE_BYTES = 15728640;
const XAR_ATELIER_BATCH_BYTES = 41943040;

function atelierSchemaStatements(): array
{
    $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $account = 'VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin';
    $id = 'VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin';
    return [
        "CREATE TABLE IF NOT EXISTS atelier_users (id $account NOT NULL PRIMARY KEY, username VARCHAR(64) NOT NULL,
            username_key VARCHAR(128) NOT NULL UNIQUE, display_name VARCHAR(120) NOT NULL, password_verifier VARCHAR(255) NOT NULL,
            is_administrator TINYINT(1) NOT NULL DEFAULT 0, auth_revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
            revoked_at DATETIME(3) NULL, created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3))$tail",
        "CREATE TABLE IF NOT EXISTS atelier_settings (singleton_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            owner_user_id $account NOT NULL, regie_owner_account_id $account NOT NULL,
            FOREIGN KEY(owner_user_id) REFERENCES atelier_users(id), FOREIGN KEY(regie_owner_account_id) REFERENCES accounts(id))$tail",
        "CREATE TABLE IF NOT EXISTS atelier_sessions (token_hash BINARY(32) NOT NULL PRIMARY KEY, user_id $account NOT NULL UNIQUE,
            auth_revision BIGINT UNSIGNED NOT NULL, expires_at DATETIME(3) NOT NULL, KEY idx_atelier_session_expiry(expires_at),
            FOREIGN KEY(user_id) REFERENCES atelier_users(id) ON DELETE CASCADE)$tail",
        "CREATE TABLE IF NOT EXISTS atelier_media (media_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
            user_id $account NOT NULL, width INT UNSIGNED NOT NULL, height INT UNSIGNED NOT NULL,
            FOREIGN KEY(media_id) REFERENCES media_objects(id), FOREIGN KEY(user_id) REFERENCES atelier_users(id))$tail",
        "CREATE TABLE IF NOT EXISTS atelier_local_tickets (id $id NOT NULL PRIMARY KEY, author_user_id $account NOT NULL,
            source_prompt MEDIUMTEXT NOT NULL, status ENUM('waiting','processing','completed','rejected','cancelled') NOT NULL DEFAULT 'waiting',
            revision BIGINT UNSIGNED NOT NULL DEFAULT 1, created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            KEY idx_atelier_ticket_author(author_user_id,created_at), KEY idx_atelier_ticket_queue(status,created_at),
            FOREIGN KEY(author_user_id) REFERENCES atelier_users(id))$tail",
        "CREATE TABLE IF NOT EXISTS atelier_ticket_messages (id $id NOT NULL PRIMARY KEY, ticket_id $id NOT NULL,
            author_user_id $account NOT NULL, kind ENUM('request','status','reply') NOT NULL, text MEDIUMTEXT NOT NULL,
            created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3), KEY idx_atelier_ticket_message(ticket_id,created_at),
            FOREIGN KEY(ticket_id) REFERENCES atelier_local_tickets(id), FOREIGN KEY(author_user_id) REFERENCES atelier_users(id))$tail",
        "CREATE TABLE IF NOT EXISTS atelier_ticket_media (id $id NOT NULL PRIMARY KEY, ticket_id $id NOT NULL,
            kind ENUM('reference','result') NOT NULL, stored_name VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
            original_name VARCHAR(180) NOT NULL, content_type VARCHAR(32) NOT NULL, byte_size BIGINT UNSIGNED NOT NULL,
            sha256 BINARY(32) NOT NULL, width INT UNSIGNED NOT NULL, height INT UNSIGNED NOT NULL,
            created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3), KEY idx_atelier_ticket_media(ticket_id,kind),
            FOREIGN KEY(ticket_id) REFERENCES atelier_local_tickets(id))$tail",
        "CREATE TABLE IF NOT EXISTS atelier_request_receipts (user_id $account NOT NULL, operation VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            client_request_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, request_hash BINARY(32) NOT NULL,
            resource_id $id NOT NULL, created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            PRIMARY KEY(user_id,operation,client_request_id), FOREIGN KEY(user_id) REFERENCES atelier_users(id))$tail",
    ];
}

function migrateAtelierSchema(PDO $connection): void
{
    // DDL is restartable in MySQL; the marker is written only after every table exists.
    foreach ([...atelierSchemaStatements(), ...atelierGptSchemaStatements()] as $sql) $connection->exec($sql);
    $connection->prepare('INSERT IGNORE INTO schema_migrations(version,name,checksum) VALUES(25,:name,:checksum)')
        ->execute([':name' => 'atelier_independent_accounts_private_tickets', ':checksum' => hash('sha256', implode("\n", [...atelierSchemaStatements(), ...atelierGptSchemaStatements()]))]);
}

function atelierWriteGuard(): void
{
    $origin = rtrim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''), '/');
    if (($origin !== '' && $origin !== 'https://' . XAR_API_HOST)
        || strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')) === 'cross-site') {
        sendError(403, 'Origine de la demande refusée.', 'atelier_origin_rejected');
    }
}

function atelierJsonBody(int $maximum = 65536): array
{
    atelierWriteGuard();
    if (strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0])) !== 'application/json') {
        sendError(415, 'Une demande JSON est requise.', 'atelier_json_required');
    }
    return readJsonBody($maximum);
}

function atelierSettings(PDO $connection): ?array
{
    $row = $connection->query('SELECT owner_user_id,regie_owner_account_id FROM atelier_settings WHERE singleton_id=1')->fetch();
    return is_array($row) ? $row : null;
}

function atelierPublicIdentity(array $row): array
{
    return ['id' => (string) $row['id'], 'username' => (string) $row['username'],
        'displayName' => (string) $row['display_name'], 'isAdministrator' => (bool) $row['is_administrator']];
}

function atelierSessionCookie(string $token, int $seconds = XAR_ATELIER_SESSION_SECONDS): string
{
    return 'xar_atelier_session=' . rawurlencode($token) . '; Path=' . XAR_ATELIER_PREFIX
        . '; Max-Age=' . $seconds . '; Secure; HttpOnly; SameSite=Strict';
}

function requireAtelierIdentity(PDO $connection): array
{
    $token = (string) ($_COOKIE['xar_atelier_session'] ?? '');
    if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) sendError(401, 'Connectez-vous à l’Atelier.', 'atelier_authentication_required');
    $statement = $connection->prepare('SELECT u.* FROM atelier_sessions s JOIN atelier_users u ON u.id=s.user_id
        WHERE s.token_hash=:hash AND s.expires_at>UTC_TIMESTAMP(3) AND s.auth_revision=u.auth_revision AND u.revoked_at IS NULL LIMIT 1');
    $statement->bindValue(':hash', hash('sha256', $token, true), PDO::PARAM_LOB);
    $statement->execute();
    $row = $statement->fetch();
    if (!is_array($row)) sendError(401, 'La connexion à l’Atelier a expiré.', 'atelier_authentication_required');
    return $row;
}

function atelierIsOwner(PDO $connection, array $identity): bool
{
    $settings = atelierSettings($connection);
    return is_array($settings) && (bool) ($identity['is_administrator'] ?? false)
        && hash_equals((string) $settings['owner_user_id'], (string) $identity['id']);
}

function requireAtelierOwner(PDO $connection): array
{
    $identity = requireAtelierIdentity($connection);
    if (!atelierIsOwner($connection, $identity)) sendError(403, 'Cette action est réservée au propriétaire de l’Atelier.', 'atelier_owner_required');
    return $identity;
}

function atelierCredentials(array $payload): array
{
    try {
        [$username, $key] = normalizeUsername($payload['username'] ?? '');
        $password = validatedPassword($payload['password'] ?? '');
        $displayName = cleanText($payload['displayName'] ?? $username, 120, 'Nom affiché');
    } catch (InvalidArgumentException $error) {
        sendError(400, $error->getMessage(), 'atelier_invalid_account');
    }
    return [$username, $key, $displayName, $password];
}

function atelierInsertUser(PDO $connection, array $credentials, bool $owner = false): array
{
    [$username, $key, $displayName, $password] = $credentials;
    $id = randomToken(18);
    $statement = $connection->prepare('INSERT INTO atelier_users(id,username,username_key,display_name,password_verifier,is_administrator)
        VALUES(:id,:username,:key,:display_name,:verifier,:administrator)');
    try {
        $statement->execute([':id' => $id, ':username' => $username, ':key' => $key, ':display_name' => $displayName,
            ':verifier' => hashPassword($password), ':administrator' => $owner ? 1 : 0]);
    } catch (PDOException $error) {
        if ($error->getCode() === '23000') sendError(409, 'Cet identifiant Atelier existe déjà.', 'atelier_username_conflict');
        throw $error;
    }
    return ['id' => $id, 'username' => $username, 'display_name' => $displayName, 'is_administrator' => $owner, 'auth_revision' => 1];
}

function atelierIssueSession(PDO $connection, array $user): never
{
    $token = randomToken();
    $statement = $connection->prepare('INSERT INTO atelier_sessions(token_hash,user_id,auth_revision,expires_at)
        SELECT :hash,u.id,u.auth_revision,DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 43200 SECOND) FROM atelier_users u
        WHERE u.id=:id AND u.auth_revision=:verified_revision AND u.revoked_at IS NULL
        ON DUPLICATE KEY UPDATE token_hash=VALUES(token_hash),auth_revision=VALUES(auth_revision),expires_at=VALUES(expires_at)');
    $statement->bindValue(':hash', hash('sha256', $token, true), PDO::PARAM_LOB);
    $statement->bindValue(':id', $user['id']);
    $statement->bindValue(':verified_revision', (int) ($user['auth_revision'] ?? 0), PDO::PARAM_INT);
    $statement->execute();
    if ($statement->rowCount() === 0) sendError(401, 'Le compte a été modifié. Reconnectez-vous.', 'atelier_authentication_required');
    sendJson(200, ['ok' => true, 'account' => atelierPublicIdentity($user), 'expiresInSeconds' => XAR_ATELIER_SESSION_SECONDS],
        false, ['Set-Cookie' => atelierSessionCookie($token)]);
}

function setupAtelierOwner(PDO $connection): never
{
    // An existing authenticated owner must prove authority; there is no anonymous first-user claim.
    $regieOwner = requireRegieCodexOwner($connection);
    $credentials = atelierCredentials(atelierJsonBody());
    $lock = $connection->query("SELECT GET_LOCK('xar-atelier-initial-setup',12)");
    if ($lock === false || (int) $lock->fetchColumn() !== 1) sendError(503, 'Initialisation occupée.', 'atelier_setup_busy');
    try {
        $connection->beginTransaction();
        if (atelierSettings($connection) !== null) {
            $connection->rollBack();
            sendError(409, 'L’Atelier est déjà initialisé.', 'atelier_already_initialized');
        }
        $user = atelierInsertUser($connection, $credentials, true);
        $connection->prepare('INSERT INTO atelier_settings(singleton_id,owner_user_id,regie_owner_account_id) VALUES(1,?,?)')
            ->execute([$user['id'], $regieOwner['id']]);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $error;
    } finally {
        $connection->query("SELECT RELEASE_LOCK('xar-atelier-initial-setup')");
    }
    atelierIssueSession($connection, $user);
}

function loginAtelier(PDO $connection): never
{
    $payload = atelierJsonBody();
    $inputValid = true;
    try { [, $key] = normalizeUsername($payload['username'] ?? ''); $password = validatedPassword($payload['password'] ?? ''); }
    catch (InvalidArgumentException) { $inputValid = false; $key = 'invalid'; $password = str_repeat('x', 10); }
    $bucket = rateBucket('atelier-login:' . $key);
    assertRateAvailable($connection, $bucket);
    $statement = $connection->prepare('SELECT * FROM atelier_users WHERE username_key=? LIMIT 1');
    $statement->execute([$key]);
    $user = $statement->fetch();
    $verifier = is_array($user) ? (string) $user['password_verifier'] : hashPassword(randomToken());
    $matches = password_verify($password, $verifier);
    if (!$inputValid || !$matches || !is_array($user) || $user['revoked_at'] !== null) {
        recordRateFailure($connection, $bucket);
        sendError(403, 'Identifiant ou mot de passe Atelier incorrect.', 'invalid_credentials');
    }
    clearRateFailures($connection, $bucket);
    atelierIssueSession($connection, $user);
}

function atelierRequestId(array $payload): string
{
    $id = $payload['clientRequestId'] ?? null;
    if (!is_string($id) || preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $id) !== 1) {
        sendError(400, 'Identifiant de demande manquant ou invalide.', 'invalid_client_request_id');
    }
    return $id;
}

function changeAtelierPassword(PDO $connection, array $identity, array $payload): never
{
    if (!is_string($payload['currentPassword'] ?? null) || !password_verify($payload['currentPassword'], $identity['password_verifier'])) {
        sendError(403, 'Le mot de passe actuel est incorrect.', 'invalid_current_password');
    }
    try { $password = validatedPassword($payload['newPassword'] ?? ''); }
    catch (InvalidArgumentException $error) { sendError(400, $error->getMessage(), 'atelier_invalid_account'); }
    $statement = $connection->prepare('UPDATE atelier_users SET password_verifier=?,auth_revision=auth_revision+1 WHERE id=? AND auth_revision=? AND revoked_at IS NULL');
    $statement->execute([hashPassword($password), $identity['id'], $identity['auth_revision']]);
    if ($statement->rowCount() !== 1) sendError(401, 'Le compte a été modifié. Reconnectez-vous.', 'atelier_authentication_required');
    atelierIssueSession($connection, [...$identity, 'auth_revision' => (int) $identity['auth_revision'] + 1]);
}

function atelierExpectedRevision(array $payload): int
{
    if (!is_int($payload['expectedRevision'] ?? null) || $payload['expectedRevision'] < 1) {
        sendError(400, 'Révision attendue invalide.', 'invalid_expected_revision');
    }
    return $payload['expectedRevision'];
}

function atelierText(mixed $value, int $maximum, bool $required = true): string
{
    if (!is_string($value) || preg_match('//u', $value) !== 1 || strlen($value) > $maximum || str_contains($value, "\0")) {
        sendError(400, 'Texte invalide ou trop long.', 'atelier_invalid_text');
    }
    $text = trim(str_replace(["\r\n", "\r"], "\n", $value));
    if ($required && $text === '') sendError(400, 'La description ne peut pas être vide.', 'atelier_invalid_text');
    return $text;
}

function atelierMultipartMetadata(bool $required = true): array
{
    atelierWriteGuard();
    if (!str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data;')) {
        sendError(415, 'Un envoi de fichiers est requis.', 'atelier_multipart_required');
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > XAR_ATELIER_BATCH_BYTES + 262144) {
        sendError(413, 'L’envoi dépasse 40 Mo.', 'atelier_batch_too_large');
    }
    if (!$required && !isset($_POST['metadata'])) return [];
    $raw = $_POST['metadata'] ?? '';
    if (!is_string($raw) || strlen($raw) > 65536) sendError(400, 'Métadonnées invalides.', 'atelier_invalid_metadata');
    try { $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException) { sendError(400, 'Métadonnées JSON invalides.', 'atelier_invalid_metadata'); }
    if (!is_array($payload) || array_is_list($payload)) sendError(400, 'Métadonnées invalides.', 'atelier_invalid_metadata');
    return $payload;
}

function atelierValidatedImage(string $path, string $filename, int $declaredSize): array
{
    $size = is_file($path) ? filesize($path) : false;
    if ($size === false || $size < 1 || $size > XAR_ATELIER_FILE_BYTES || $size !== $declaredSize) {
        sendError(413, 'Une image est vide, incomplète ou dépasse 15 Mo.', 'atelier_image_too_large');
    }
    $image = @getimagesize($path);
    $mime = is_array($image) ? strtolower((string) ($image['mime'] ?? '')) : '';
    $extensions = ['image/png' => '.png', 'image/jpeg' => '.jpg', 'image/webp' => '.webp'];
    if (!isset($extensions[$mime]) || (int) $image[0] < 1 || (int) $image[1] < 1
        || (int) $image[0] > 16384 || (int) $image[1] > 16384 || (int) $image[0] * (int) $image[1] > 67108864) {
        sendError(415, 'Choisissez une image PNG, JPEG ou WebP lisible.', 'atelier_image_type_rejected');
    }
    return ['path' => $path, 'filename' => cleanMediaFilename($filename), 'contentType' => $mime, 'byteSize' => $size,
        'extension' => $extensions[$mime], 'width' => (int) $image[0], 'height' => (int) $image[1], 'sha256' => hash_file('sha256', $path)];
}

function atelierUploadedFiles(int $maximum): array
{
    if ($_FILES === []) return [];
    if (count($_FILES) !== 1 || !isset($_FILES['files']) || !is_array($_FILES['files'])) {
        sendError(400, 'Utilisez le champ fichiers prévu.', 'atelier_invalid_files');
    }
    $upload = $_FILES['files'];
    if (!is_array($upload['name'] ?? null) || !array_is_list($upload['name']) || count($upload['name']) > $maximum) {
        sendError(400, 'Trop de pièces jointes.', 'atelier_attachment_limit');
    }
    $files = []; $total = 0;
    foreach ($upload['name'] as $i => $name) {
        if (($upload['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_string($name) || !is_string($upload['tmp_name'][$i] ?? null)
            || !is_uploaded_file($upload['tmp_name'][$i])) {
            sendError(400, 'Une pièce jointe n’a pas été reçue complètement.', 'atelier_upload_incomplete');
        }
        $file = atelierValidatedImage($upload['tmp_name'][$i], $name, (int) ($upload['size'][$i] ?? 0));
        $total += $file['byteSize'];
        if ($total > XAR_ATELIER_BATCH_BYTES) sendError(413, 'Les images dépassent 40 Mo au total.', 'atelier_batch_too_large');
        $files[] = $file;
    }
    return $files;
}

function atelierFilesSignature(array $files): array
{
    return array_map(static fn(array $f): array => ['filename' => $f['filename'], 'sha256' => $f['sha256'], 'byteSize' => $f['byteSize']], $files);
}

function atelierRequestHash(array $payload): string
{
    return hash('sha256', json_encode(onlineCanonicalRequestValue($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), true);
}

function atelierReceipt(PDO $connection, array $identity, string $operation, string $requestId, string $hash): ?string
{
    $statement = $connection->prepare('SELECT request_hash,resource_id FROM atelier_request_receipts WHERE user_id=? AND operation=? AND client_request_id=?');
    $statement->execute([$identity['id'], $operation, $requestId]);
    $row = $statement->fetch();
    if (!is_array($row)) return null;
    if (!hash_equals((string) $row['request_hash'], $hash)) sendError(409, 'Cet identifiant correspond à une autre demande.', 'atelier_request_mismatch');
    return (string) $row['resource_id'];
}

function atelierSaveReceipt(PDO $connection, array $identity, string $operation, string $requestId, string $hash, string $resourceId): void
{
    $statement = $connection->prepare('INSERT INTO atelier_request_receipts(user_id,operation,client_request_id,request_hash,resource_id) VALUES(?,?,?,?,?)');
    $statement->execute([$identity['id'], $operation, $requestId, $hash, $resourceId]);
}

function atelierLockUser(PDO $connection, array $identity): void
{
    $statement = $connection->prepare('SELECT id,auth_revision,revoked_at FROM atelier_users WHERE id=? FOR UPDATE');
    $statement->execute([$identity['id']]);
    $current = $statement->fetch();
    if (!is_array($current) || $current['revoked_at'] !== null
        || (int) $current['auth_revision'] !== (int) ($identity['auth_revision'] ?? 0)) {
        sendError(401, 'La connexion à l’Atelier a été révoquée.', 'atelier_authentication_required');
    }
}

function atelierTicketRecord(PDO $connection, string $id, bool $lock = false): ?array
{
    $statement = $connection->prepare('SELECT t.*,u.username,u.display_name FROM atelier_local_tickets t JOIN atelier_users u ON u.id=t.author_user_id WHERE t.id=?' . ($lock ? ' FOR UPDATE' : ''));
    $statement->execute([$id]);
    $row = $statement->fetch();
    return is_array($row) ? $row : null;
}

function assertAtelierTicketAccess(PDO $connection, array $identity, ?array $ticket): void
{
    if (!is_array($ticket) || ((string) $ticket['author_user_id'] !== (string) $identity['id'] && !atelierIsOwner($connection, $identity))) {
        // Conceal even the existence of another author's ticket.
        sendError(404, 'Demande locale introuvable.', 'atelier_ticket_missing');
    }
}

function atelierTicketMediaPayload(array $row): array
{
    return ['id' => (string) $row['id'], 'filename' => (string) $row['original_name'], 'contentType' => (string) $row['content_type'],
        'byteSize' => (int) $row['byte_size'], 'width' => (int) $row['width'], 'height' => (int) $row['height'],
        'url' => XAR_ATELIER_PREFIX . '/local-ticket-media/' . $row['id']];
}

function atelierTicketPayload(PDO $connection, array $ticket): array
{
    $media = $connection->prepare('SELECT * FROM atelier_ticket_media WHERE ticket_id=? ORDER BY created_at,id');
    $media->execute([$ticket['id']]);
    $references = []; $results = [];
    foreach ($media->fetchAll() as $file) {
        if ($file['kind'] === 'reference') $references[] = atelierTicketMediaPayload($file);
        else $results[] = atelierTicketMediaPayload($file);
    }
    $messages = $connection->prepare('SELECT m.*,u.username,u.display_name FROM atelier_ticket_messages m JOIN atelier_users u ON u.id=m.author_user_id WHERE m.ticket_id=? ORDER BY m.created_at,m.id');
    $messages->execute([$ticket['id']]);
    return ['id' => (string) $ticket['id'], 'sourcePrompt' => (string) $ticket['source_prompt'], 'status' => (string) $ticket['status'],
        'revision' => (int) $ticket['revision'], 'author' => ['id' => (string) $ticket['author_user_id'], 'username' => (string) $ticket['username'], 'displayName' => (string) $ticket['display_name']],
        'references' => $references, 'resultAttachments' => $results, 'messages' => array_map(static fn(array $m): array => [
            'id' => (string) $m['id'], 'kind' => (string) $m['kind'], 'text' => (string) $m['text'], 'createdAt' => (string) $m['created_at'],
            'author' => ['id' => (string) $m['author_user_id'], 'username' => (string) $m['username'], 'displayName' => (string) $m['display_name']]], $messages->fetchAll()),
        'createdAt' => (string) $ticket['created_at'], 'updatedAt' => (string) $ticket['updated_at']];
}

function atelierTicketMessage(PDO $connection, string $ticketId, array $identity, string $kind, string $text): void
{
    $connection->prepare('INSERT INTO atelier_ticket_messages(id,ticket_id,author_user_id,kind,text) VALUES(?,?,?,?,?)')
        ->execute([randomToken(18), $ticketId, $identity['id'], $kind, $text]);
}

function atelierPersistTicketFiles(PDO $connection, string $ticketId, array $files, string $kind, array &$written): void
{
    foreach ($files as $file) {
        $id = randomToken(18); $storedName = 'atelier-ticket-' . $id . $file['extension'];
        $destination = privateMediaDirectory() . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file($file['path'], $destination)) throw new RuntimeException('atelier_media_write_failed');
        $written[] = $destination;
        @chmod($destination, 0600);
        $connection->prepare('INSERT INTO atelier_ticket_media(id,ticket_id,kind,stored_name,original_name,content_type,byte_size,sha256,width,height) VALUES(?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $ticketId, $kind, $storedName, $file['filename'], $file['contentType'], $file['byteSize'], hex2bin($file['sha256']), $file['width'], $file['height']]);
    }
}

function atelierTicketTransitionAllowed(string $current, string $next, bool $owner): bool
{
    if (!$owner) return in_array($current, ['waiting', 'processing'], true) && $next === 'cancelled';
    return match ($current) {
        'waiting' => in_array($next, ['processing', 'rejected', 'cancelled'], true),
        'processing' => in_array($next, ['waiting', 'rejected', 'cancelled'], true),
        default => false,
    };
}

function mutateAtelierTicket(PDO $connection, string $operation, ?string $id = null, array $configuration = []): never
{
    $identity = $operation === 'deliver' ? requireAtelierOwner($connection) : requireAtelierIdentity($connection);
    $payload = $operation === 'status' ? atelierJsonBody() : atelierMultipartMetadata();
    $files = $operation === 'status' ? [] : atelierUploadedFiles(4);
    $requestId = atelierRequestId($payload);
    $revision = $operation === 'create' ? null : atelierExpectedRevision($payload);
    $text = $operation === 'create' ? atelierText($payload['sourcePrompt'] ?? '', 50000)
        : atelierText($payload[$operation === 'deliver' ? 'reply' : 'note'] ?? '', 50000, false);
    $next = $operation === 'create' ? 'waiting' : ($operation === 'deliver' ? 'completed' : (string) ($payload['status'] ?? ''));
    if ($operation === 'deliver' && $files === []) sendError(400, 'Ajoutez au moins une image au résultat.', 'atelier_result_required');
    $receiptOperation = 'ticket-' . $operation . ($id === null ? '' : ':' . $id);
    $hash = atelierRequestHash(['operation' => $operation, 'ticketId' => $id, 'expectedRevision' => $revision, 'text' => $text, 'status' => $next, 'files' => atelierFilesSignature($files)]);
    $written = []; $deduplicated = false;
    $quotaLocked = false;
    if ($files !== []) {
        $lock = $connection->query("SELECT GET_LOCK('xar-regie-media-quota',12)");
        $quotaLocked = $lock !== false && (int) $lock->fetchColumn() === 1;
        if (!$quotaLocked) sendError(503, 'Stockage occupé.', 'atelier_media_busy');
    }
    $connection->beginTransaction();
    try {
        atelierLockUser($connection, $identity);
        $replayedId = atelierReceipt($connection, $identity, $receiptOperation, $requestId, $hash);
        if ($replayedId !== null) { $id = $replayedId; $deduplicated = true; }
        else {
            if ($files !== []) {
                $violation = mediaQuotaViolation(mediaStorageUsage($connection), mediaStorageLimits($configuration), array_sum(array_column($files, 'byteSize')));
                if ($violation !== null) sendError(507, $violation['message'], $violation['code']);
            }
            if ($operation === 'create') {
                $id = randomToken(18);
                $connection->prepare('INSERT INTO atelier_local_tickets(id,author_user_id,source_prompt) VALUES(?,?,?)')->execute([$id, $identity['id'], $text]);
            } else {
                $ticket = atelierTicketRecord($connection, (string) $id, true);
                assertAtelierTicketAccess($connection, $identity, $ticket);
                if ((int) $ticket['revision'] !== $revision) sendError(409, 'Cette demande a été modifiée. Actualisez-la.', 'revision_conflict');
                $owner = atelierIsOwner($connection, $identity);
                $allowed = $operation === 'deliver' ? in_array($ticket['status'], ['waiting', 'processing'], true)
                    : atelierTicketTransitionAllowed((string) $ticket['status'], $next, $owner);
                if (!$allowed) sendError(409, 'Ce changement d’état n’est plus possible.', 'atelier_invalid_transition');
                $connection->prepare('UPDATE atelier_local_tickets SET status=?,revision=revision+1,updated_at=UTC_TIMESTAMP(3) WHERE id=?')->execute([$next, $id]);
            }
            atelierTicketMessage($connection, (string) $id, $identity, $operation === 'create' ? 'request' : ($operation === 'deliver' ? 'reply' : 'status'), $text !== '' ? $text : $next);
            atelierPersistTicketFiles($connection, (string) $id, $files, $operation === 'create' ? 'reference' : 'result', $written);
            atelierSaveReceipt($connection, $identity, $receiptOperation, $requestId, $hash, (string) $id);
        }
        $ticket = atelierTicketRecord($connection, (string) $id);
        assertAtelierTicketAccess($connection, $identity, $ticket);
        $result = atelierTicketPayload($connection, $ticket);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) $connection->rollBack();
        foreach ($written as $path) @unlink($path);
        throw $error;
    } finally {
        if ($quotaLocked) $connection->query("SELECT RELEASE_LOCK('xar-regie-media-quota')");
    }
    sendJson($operation === 'create' && !$deduplicated ? 201 : 200, ['ok' => true, 'ticket' => $result, 'deduplicated' => $deduplicated]);
}

function assertAtelierMediaAccess(PDO $connection, array $identity, string $id): array
{
    $statement = $connection->prepare('SELECT m.*,a.user_id,a.width,a.height FROM atelier_media a JOIN media_objects m ON m.id=a.media_id WHERE a.media_id=? AND m.pending_delete_at IS NULL');
    $statement->execute([$id]);
    $row = $statement->fetch();
    if (!is_array($row) || ((string) $row['user_id'] !== (string) $identity['id'] && !atelierIsOwner($connection, $identity))) {
        sendError(404, 'Image Atelier introuvable.', 'atelier_media_missing');
    }
    return $row;
}

function atelierMediaReferenceCount(PDO $connection, string $id): int
{
    $statement = $connection->prepare('SELECT COUNT(*) FROM atelier_media WHERE media_id=?');
    $statement->execute([$id]);
    return (int) $statement->fetchColumn();
}

function atelierTicketStorageBytes(PDO $connection): int
{
    return (int) $connection->query('SELECT COALESCE(SUM(byte_size),0) FROM atelier_ticket_media')->fetchColumn();
}

function assertAtelierRegieMediaAccess(PDO $connection, array $identity, string $id): void
{
    if (atelierMediaReferenceCount($connection, $id) === 0) return;
    $settings = atelierSettings($connection);
    if (!isRegieCodexOwner($identity) || (string) ($settings['regie_owner_account_id'] ?? '') !== (string) ($identity['id'] ?? '')) {
        sendError(404, 'Média introuvable.', 'media_missing');
    }
}

function atelierMediaPayload(array $row): array
{
    return ['kind' => 'upload', 'label' => (string) $row['original_name'], 'mediaId' => (string) $row['id'],
        'imageUrl' => XAR_ATELIER_PREFIX . '/media/' . $row['id'], 'contentType' => (string) $row['content_type'],
        'byteSize' => (int) $row['byte_size'], 'width' => (int) $row['width'], 'height' => (int) $row['height']];
}

function uploadAtelierReferences(PDO $connection, array $configuration): never
{
    $identity = requireAtelierIdentity($connection);
    atelierMultipartMetadata(false);
    $files = atelierUploadedFiles(5);
    if ($files === []) sendError(400, 'Ajoutez au moins une image.', 'atelier_reference_required');
    $settings = atelierSettings($connection);
    $lock = $connection->query("SELECT GET_LOCK('xar-regie-media-quota',12)");
    if ($lock === false || (int) $lock->fetchColumn() !== 1) sendError(503, 'Stockage occupé.', 'atelier_media_busy');
    $written = []; $references = [];
    try {
        $violation = mediaQuotaViolation(mediaStorageUsage($connection), mediaStorageLimits($configuration), array_sum(array_column($files, 'byteSize')));
        if ($violation !== null) sendError(507, $violation['message'], $violation['code']);
        $connection->beginTransaction();
        atelierLockUser($connection, $identity);
        foreach ($files as $file) {
            $id = randomToken(18); $storedName = $id . $file['extension'];
            $path = privateMediaDirectory() . DIRECTORY_SEPARATOR . $storedName;
            if (!move_uploaded_file($file['path'], $path)) throw new RuntimeException('atelier_media_write_failed');
            $written[] = $path; @chmod($path, 0600);
            $connection->prepare('INSERT INTO media_objects(id,stored_name,original_name,content_type,byte_size,sha256,uploaded_by_account_id) VALUES(?,?,?,?,?,?,?)')
                ->execute([$id, $storedName, $file['filename'], $file['contentType'], $file['byteSize'], hex2bin($file['sha256']), $settings['regie_owner_account_id']]);
            $connection->prepare('INSERT INTO atelier_media(media_id,user_id,width,height) VALUES(?,?,?,?)')->execute([$id, $identity['id'], $file['width'], $file['height']]);
            $references[] = atelierMediaPayload(['id' => $id, 'original_name' => $file['filename'], 'content_type' => $file['contentType'], 'byte_size' => $file['byteSize'], 'width' => $file['width'], 'height' => $file['height']]);
        }
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) $connection->rollBack();
        foreach ($written as $path) @unlink($path);
        throw $error;
    } finally { $connection->query("SELECT RELEASE_LOCK('xar-regie-media-quota')"); }
    sendJson(201, ['ok' => true, 'references' => $references]);
}

function streamAtelierFile(array $row, bool $headOnly): never
{
    $path = privateMediaDirectory() . DIRECTORY_SEPARATOR . basename((string) $row['stored_name']);
    if (!is_file($path)) sendError(404, 'Image introuvable.', 'atelier_media_missing');
    header('Content-Type: ' . $row['content_type']);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'");
    if (!$headOnly) readfile($path);
    exit;
}

function handleAtelierRoute(PDO $connection, array $configuration, string $route, string $method, bool $headOnly): bool
{
    if ($route !== XAR_ATELIER_PREFIX && !str_starts_with($route, XAR_ATELIER_PREFIX . '/')) return false;
    $path = substr($route, strlen(XAR_ATELIER_PREFIX));
    if ($path === '/auth/setup') {
        requireMethod($method, ['GET', 'HEAD', 'POST']);
        if ($method === 'POST') setupAtelierOwner($connection);
        sendJson(200, ['ok' => true, 'setupRequired' => atelierSettings($connection) === null], $headOnly);
    }
    if ($path === '/auth/login') { requireMethod($method, ['POST']); loginAtelier($connection); }
    if ($path === '/auth/logout') {
        requireMethod($method, ['POST']); atelierWriteGuard();
        $identity = requireAtelierIdentity($connection);
        $connection->prepare('DELETE FROM atelier_sessions WHERE user_id=?')->execute([$identity['id']]);
        sendJson(200, ['ok' => true], false, ['Set-Cookie' => atelierSessionCookie('', 0)]);
    }
    if ($path === '/auth/me') {
        requireMethod($method, ['GET', 'HEAD']);
        sendJson(200, ['ok' => true, 'account' => atelierPublicIdentity(requireAtelierIdentity($connection))], $headOnly);
    }
    if ($path === '/capabilities') {
        requireMethod($method, ['GET', 'HEAD']); $identity = requireAtelierIdentity($connection); $owner = atelierIsOwner($connection, $identity);
        sendJson(200, ['ok' => true, 'canManageLocalTickets' => $owner, 'canOpenLocalEngine' => $owner,
            'gptViaRegie' => true, 'maxReferences' => 5, 'ticketsMaxAttachments' => 4], $headOnly);
    }
    if ($path === '/users') {
        requireMethod($method, ['GET', 'HEAD', 'POST']); requireAtelierOwner($connection);
        if ($method === 'POST') {
            $user = atelierInsertUser($connection, atelierCredentials(atelierJsonBody()));
            sendJson(201, ['ok' => true, 'account' => atelierPublicIdentity($user)]);
        }
        $users = $connection->query('SELECT id,username,display_name,is_administrator,revoked_at FROM atelier_users ORDER BY created_at,id')->fetchAll();
        sendJson(200, ['ok' => true, 'users' => array_map(static fn(array $u): array => [...atelierPublicIdentity($u), 'revoked' => $u['revoked_at'] !== null], $users)], $headOnly);
    }
    if (preg_match('#^/users/([A-Za-z0-9_-]{24})$#D', $path, $match) === 1) {
        requireMethod($method, ['PATCH']); $identity = requireAtelierOwner($connection); $payload = atelierJsonBody();
        if ($match[1] === $identity['id']) sendError(409, 'Utilisez les paramètres de votre compte pour changer votre mot de passe.', 'atelier_owner_protected');
        $statement = $connection->prepare('SELECT * FROM atelier_users WHERE id=?'); $statement->execute([$match[1]]); $user = $statement->fetch();
        if (!is_array($user)) sendError(404, 'Compte Atelier introuvable.', 'atelier_user_missing');
        $assignments = ['auth_revision=auth_revision+1']; $values = [];
        if (array_key_exists('password', $payload)) { try { $values[] = hashPassword(validatedPassword($payload['password'])); $assignments[] = 'password_verifier=?'; }
            catch (InvalidArgumentException $error) { sendError(400, $error->getMessage(), 'atelier_invalid_account'); } }
        if (array_key_exists('revoked', $payload) && !is_bool($payload['revoked'])) sendError(400, 'État du compte invalide.', 'atelier_invalid_account');
        if (array_key_exists('revoked', $payload)) { $assignments[] = 'revoked_at=CASE WHEN ?=1 THEN UTC_TIMESTAMP(3) ELSE NULL END'; $values[] = $payload['revoked'] ? 1 : 0; }
        if (count($assignments) === 1) sendError(400, 'Aucun changement demandé.', 'atelier_invalid_account');
        $values[] = $match[1];
        $connection->prepare('UPDATE atelier_users SET ' . implode(',', $assignments) . ' WHERE id=?')->execute($values);
        sendJson(200, ['ok' => true]);
    }
    if ($path === '/auth/password') {
        requireMethod($method, ['POST']); $identity = requireAtelierIdentity($connection); $payload = atelierJsonBody();
        changeAtelierPassword($connection, $identity, $payload);
    }
    if ($path === '/references') { requireMethod($method, ['POST']); uploadAtelierReferences($connection, $configuration); }
    if (preg_match('#^/media/([A-Za-z0-9_-]{24})$#D', $path, $match) === 1) {
        requireMethod($method, ['GET', 'HEAD']);
        streamAtelierFile(assertAtelierMediaAccess($connection, requireAtelierIdentity($connection), $match[1]), $headOnly);
    }
    if ($path === '/local-tickets') {
        requireMethod($method, ['GET', 'HEAD', 'POST']);
        if ($method === 'POST') mutateAtelierTicket($connection, 'create', null, $configuration);
        $identity = requireAtelierIdentity($connection); $owner = atelierIsOwner($connection, $identity);
        $statement = $connection->prepare('SELECT t.*,u.username,u.display_name FROM atelier_local_tickets t JOIN atelier_users u ON u.id=t.author_user_id'
            . ($owner ? '' : ' WHERE t.author_user_id=?') . ' ORDER BY t.updated_at DESC,t.id DESC LIMIT 100');
        $statement->execute($owner ? [] : [$identity['id']]);
        sendJson(200, ['ok' => true, 'tickets' => array_map(static fn(array $t): array => atelierTicketPayload($connection, $t), $statement->fetchAll())], $headOnly);
    }
    if (preg_match('#^/local-tickets/([A-Za-z0-9_-]{24})(?:/(status|deliver))?$#D', $path, $match) === 1) {
        if (isset($match[2])) { requireMethod($method, ['POST']); mutateAtelierTicket($connection, $match[2], $match[1], $configuration); }
        requireMethod($method, ['GET', 'HEAD']); $identity = requireAtelierIdentity($connection); $ticket = atelierTicketRecord($connection, $match[1]);
        assertAtelierTicketAccess($connection, $identity, $ticket);
        sendJson(200, ['ok' => true, 'ticket' => atelierTicketPayload($connection, $ticket)], $headOnly);
    }
    if (preg_match('#^/local-ticket-media/([A-Za-z0-9_-]{24})$#D', $path, $match) === 1) {
        requireMethod($method, ['GET', 'HEAD']); $identity = requireAtelierIdentity($connection);
        $statement = $connection->prepare('SELECT * FROM atelier_ticket_media WHERE id=?'); $statement->execute([$match[1]]); $file = $statement->fetch();
        assertAtelierTicketAccess($connection, $identity, is_array($file) ? atelierTicketRecord($connection, $file['ticket_id']) : null);
        streamAtelierFile($file, $headOnly);
    }
    if (handleAtelierGptRoute($connection, $route, $method, $headOnly)) return true;
    sendError(404, 'Route Atelier inconnue.', 'not_found');
}
