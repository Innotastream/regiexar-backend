<?php
declare(strict_types=1);

require_once __DIR__ . '/fixtures/atelier-gpt-worker.php';

// This suite creates and drops only its own random schema on an explicitly
// opted-in loopback server. It never calls Codex, HTTP or a production account.
if (getenv('XAR_TEST_MYSQL_ALLOW_LOCAL') !== '1') { echo "Atelier GPT SQL skipped: explicit local opt-in required.\n"; exit(0); }
$dsn = (string) getenv('XAR_TEST_MYSQL_DSN');
if (preg_match('/^mysql:host=127\.0\.0\.1;port=([1-9][0-9]{3,4});charset=utf8mb4$/D', $dsn, $port) !== 1 || (int) $port[1] > 65535) throw new RuntimeException('Explicit loopback DSN required.');
require_once __DIR__ . '/../api/v1/online.php';
require_once __DIR__ . '/../api/v1/ability-assistant.php';
require_once __DIR__ . '/../api/v1/image-studio.php';
require_once __DIR__ . '/../api/v1/atelier.php';
require_once __DIR__ . '/../api/v1/atelier-gpt.php';

final class AtelierGptTestResponse extends RuntimeException {
    public function __construct(public int $status, public array $body) { parent::__construct((string) ($body['code'] ?? 'response'), $status); }
}
function sendJson(int $status, array $body, bool $headOnly = false, array $extraHeaders = []): never { throw new AtelierGptTestResponse($status, $body); }
function sendError(int $status, string $message, string $code = ''): never { sendJson($status, ['error' => $message, 'code' => $code]); }
function requireMethod(string $actual, array $allowed): void { if (!in_array($actual, $allowed, true)) sendError(405, 'Method refused', 'method_not_allowed'); }
function readJsonBody(int $maximumBytes = 16384): array { return $GLOBALS['testBody'] ?? []; }
function randomToken(int $bytes = 32): string { return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '='); }
function requestSessionToken(): string { return 'synthetic-session'; }
function resolveSession(PDO $connection, string $token, bool $touch = true): ?array { return $GLOBALS['regieIdentity']; }
function requireAdministratorIdentity(PDO $connection): array { return $GLOBALS['regieIdentity']; }
function cleanText(mixed $value, int $maximum, string $label): string { return substr(trim((string) $value), 0, $maximum); }

$GLOBALS['regieIdentity'] = ['id' => 'regie-owner-fixture', 'username' => 'Innota', 'display_name' => 'Synthetic owner', 'permanent_role' => 'gm', 'effective_mode' => 'gm', 'can_administrate' => 1];
function gptSqlDb(?string $schema = null): PDO {
    $db = new PDO((string) getenv('XAR_TEST_MYSQL_DSN'), (string) getenv('XAR_TEST_MYSQL_USER'), (string) getenv('XAR_TEST_MYSQL_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $db->exec("SET time_zone='+00:00', SESSION innodb_lock_wait_timeout=10");
    if ($schema !== null) { if (preg_match('/^atelier_gpt_audit_[a-f0-9]{16}$/D', $schema) !== 1) throw new RuntimeException('Unsafe fixture name.'); $db->exec('USE `' . $schema . '`'); }
    return $db;
}
function gptCheck(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException($label);
    $GLOBALS['checks'] = ($GLOBALS['checks'] ?? 0) + 1;
    echo "PASS $label\n";
}
function gptResponse(callable $call): AtelierGptTestResponse {
    try { $call(); } catch (AtelierGptTestResponse $response) { return $response; }
    throw new RuntimeException('Expected a route response.');
}
function gptRejected(callable $call, int $status, string $code): void {
    $response = gptResponse($call);
    gptCheck($response->status === $status && ($response->body['code'] ?? '') === $code, $code . ' HTTP ' . $status);
}
function gptClaim(PDO $db, string $lease): array {
    $GLOBALS['testBody'] = ['workerLeaseId' => $lease, 'imageGenerationAvailable' => true];
    $response = gptResponse(fn() => claimImageStudioRegieJob($db));
    if ($response->status !== 200) throw new RuntimeException('Claim failed: ' . json_encode($response->body));
    return $response->body;
}
if (($argv[1] ?? '') === 'claim-worker') {
    $db = gptSqlDb((string) $argv[2]);
    echo "READY\n"; flush();
    if (trim((string) fgets(STDIN)) !== 'GO') throw new RuntimeException('Missing fixture barrier.');
    echo json_encode(gptClaim($db, (string) $argv[3]), JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}
function gptStartClaimWorker(string $schema, string $lease): array {
    return gptFixtureStartWorker(gptFixturePhpCommand([__FILE__, 'claim-worker', $schema, $lease]), __DIR__, gptFixtureWorkerEnvironment());
}
function gptFinishClaimWorker(array $worker): array {
    [$process, $pipes] = $worker;
    $result = json_decode((string) fgets($pipes[1]), true, 32, JSON_THROW_ON_ERROR);
    $stderr = stream_get_contents($pipes[2]);
    foreach ($pipes as $pipe) fclose($pipe);
    $exit = proc_close($process);
    if ($exit !== 0 || $stderr !== '') throw new RuntimeException('Fixture worker failure: ' . $stderr);
    return $result;
}
function gptFixtureMedia(PDO $db, string $userId, bool $linked = true): string {
    $id = randomToken(18);
    $db->prepare("INSERT INTO media_objects(id,stored_name,original_name,content_type,byte_size,uploaded_by_account_id) VALUES(?,?,?,'image/png',1024,'regie-owner-fixture')")
        ->execute([$id, $id . '.png', 'Synthetic image']);
    if ($linked) $db->prepare('INSERT INTO atelier_media(media_id,user_id,width,height) VALUES(?,?,64,64)')->execute([$id, $userId]);
    return $id;
}

$schema = 'atelier_gpt_audit_' . bin2hex(random_bytes(8));
$admin = gptSqlDb();
try {
    $admin->exec('CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $db = gptSqlDb($schema);
    $db->exec('CREATE TABLE accounts (id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY) ENGINE=InnoDB');
    $db->exec("INSERT INTO accounts VALUES('regie-owner-fixture')");
    $db->exec("CREATE TABLE media_objects(id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,stored_name VARCHAR(96),original_name VARCHAR(180),content_type VARCHAR(64),byte_size INT,uploaded_by_account_id VARCHAR(128),public_slug VARCHAR(22) NULL,pending_delete_at DATETIME(3) NULL) ENGINE=InnoDB");
    foreach ([...atelierSchemaStatements(), ...atelierGptSchemaStatements()] as $sql) $db->exec($sql);
    // Real existing dispatcher SQL is exercised against minimal legacy queues.
    $common = "id VARCHAR(24) PRIMARY KEY,author_account_id VARCHAR(128),conversation_id VARCHAR(22),execution_mode VARCHAR(12) DEFAULT 'regie',status VARCHAR(16) DEFAULT 'queued',worker_account_id VARCHAR(128) NULL,worker_lease_id VARCHAR(64) NULL,worker_attempts INT DEFAULT 0,worker_lease_expires_at DATETIME(3) NULL,error_code VARCHAR(64) NULL,error_detail VARCHAR(500) NULL,started_at DATETIME(3) NULL,completed_at DATETIME(3) NULL,created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),updated_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),media_id VARCHAR(64) NULL,operation VARCHAR(16) DEFAULT 'generate',prompt TEXT,aspect VARCHAR(16) DEFAULT 'landscape',references_json JSON NULL,parent_message_id VARCHAR(24) NULL,revised_prompt TEXT NULL,owner_hidden_at DATETIME(3) NULL,width INT NULL,height INT NULL";
    $db->exec('CREATE TABLE image_studio_messages (' . $common . ') ENGINE=InnoDB');
    $db->exec('CREATE TABLE ability_assistant_messages (' . $common . ') ENGINE=InnoDB');
    $db->exec("CREATE TABLE image_studio_regie_service(singleton_id TINYINT PRIMARY KEY,paused TINYINT DEFAULT 0,worker_ready TINYINT DEFAULT 1,worker_image_ready TINYINT DEFAULT 1,worker_account_id VARCHAR(128),worker_lease_id VARCHAR(64),worker_last_seen_at DATETIME(3),updated_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),updated_by_account_id VARCHAR(128) NULL) ENGINE=InnoDB");
    $lease = randomToken(18);
    $db->prepare('INSERT INTO image_studio_regie_service(singleton_id,worker_account_id,worker_lease_id,worker_last_seen_at) VALUES(1,?,?,UTC_TIMESTAMP(3))')->execute(['regie-owner-fixture', $lease]);
    foreach (['owner', 'alice', 'bob'] as $name) $db->prepare('INSERT INTO atelier_users(id,username,username_key,display_name,password_verifier,is_administrator) VALUES(?,?,?,?,?,?)')
        ->execute([$name, $name, $name, 'Synthetic ' . $name, 'synthetic-not-a-password', $name === 'owner' ? 1 : 0]);
    $db->exec("INSERT INTO atelier_settings VALUES(1,'owner','regie-owner-fixture')");
    $identities = [];
    foreach (['owner', 'alice', 'bob'] as $name) { $query = $db->prepare('SELECT * FROM atelier_users WHERE id=?'); $query->execute([$name]); $identities[$name] = $query->fetch(); }
    $alice = $identities['alice']; $bob = $identities['bob']; $owner = $identities['owner'];

    $conversation = createAtelierGptConversation($db, $alice, ['title' => 'Synthetic Alice', 'clientRequestId' => 'conversation-receipt-0001']);
    $conversationId = $conversation['conversation']['id'];
    gptRejected(fn() => createAtelierGptConversation($db, [...$alice, 'auth_revision' => 0], ['title' => 'Stale session']), 401, 'atelier_authentication_required');
    gptCheck(strlen($conversationId) === 22, 'conversation IDs match unchanged worker contract');
    $replay = createAtelierGptConversation($db, $alice, ['title' => 'Synthetic Alice', 'clientRequestId' => 'conversation-receipt-0001']);
    gptCheck($replay['deduplicated'] && $replay['conversation']['id'] === $conversationId, 'conversation creation receipt is durable');
    gptRejected(fn() => createAtelierGptConversation($db, $alice, ['title' => 'Changed', 'clientRequestId' => 'conversation-receipt-0001']), 409, 'client_request_conflict');
    gptRejected(fn() => assertAtelierGptConversationAccess($db, $bob, atelierGptConversationRecord($db, $conversationId)), 403, 'conversation_forbidden');
    gptCheck(assertAtelierGptConversationAccess($db, $owner, atelierGptConversationRecord($db, $conversationId))['id'] === $conversationId, 'owner can review private conversation');
    gptRejected(fn() => assertAtelierGptConversationAccess($db, [...$bob, 'is_administrator' => 1], atelierGptConversationRecord($db, $conversationId)), 403, 'conversation_forbidden');
    $bobCookie = randomToken(32);
    $db->prepare('INSERT INTO atelier_sessions(token_hash,user_id,auth_revision,expires_at) VALUES(?,?,1,DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR))')->execute([hash('sha256', $bobCookie, true), 'bob']);
    $_COOKIE['xar_atelier_session'] = $bobCookie;
    gptRejected(fn() => handleAtelierGptRoute($db, XAR_ATELIER_PREFIX . '/gpt/conversations/' . $conversationId . '/messages', 'GET', false), 403, 'conversation_forbidden');
    $listing = gptResponse(fn() => handleAtelierGptRoute($db, XAR_ATELIER_PREFIX . '/gpt/conversations', 'GET', false));
    gptCheck($listing->body['conversations'] === [], 'real cookie-authenticated route lists only its own conversations');
    unset($_COOKIE['xar_atelier_session']);
    gptRejected(fn() => handleAtelierGptRoute($db, XAR_ATELIER_PREFIX . '/gpt/status', 'GET', false), 401, 'atelier_authentication_required');
    $aliceMedia = gptFixtureMedia($db, 'alice');
    $bobMedia = gptFixtureMedia($db, 'bob');
    gptRejected(fn() => atelierGptReferences($db, $alice, [['mediaId' => $bobMedia]]), 404, 'atelier_media_missing');
    gptRejected(fn() => atelierGptReferences($db, $alice, [['mediaId' => $aliceMedia, 'sourceUrl' => 'https://invalid.example/image.png']]), 400, 'invalid_reference');
    gptRejected(fn() => atelierGptReferences($db, $alice, array_fill(0, 6, ['mediaId' => $aliceMedia])), 413, 'reference_limit');
    gptCheck(count(atelierGptReferences($db, $alice, array_fill(0, 5, ['mediaId' => $aliceMedia]))) === 5, 'five authorized references are accepted');

    $body = ['prompt' => 'Two clothed adults holding hands, synthetic test.', 'references' => [], 'clientRequestId' => 'generation-receipt-0001'];
    gptRejected(fn() => createAtelierGptMessage($db, [...$alice, 'auth_revision' => 0], $conversationId, $body), 401, 'atelier_authentication_required');
    $created = createAtelierGptMessage($db, $alice, $conversationId, $body);
    gptCheck(strlen($created['message']['id']) === 24 && $created['message']['references'] === [], 'text-only request uses unchanged worker IDs and zero references');
    $replay = createAtelierGptMessage($db, $alice, $conversationId, $body);
    gptCheck($replay['deduplicated'] && $replay['message']['id'] === $created['message']['id'], 'message retry creates no duplicate job');
    gptRejected(fn() => createAtelierGptMessage($db, $alice, $conversationId, [...$body, 'prompt' => 'Changed']), 409, 'client_request_conflict');
    gptRejected(fn() => createAtelierGptMessage($db, $alice, $conversationId, [...$body, 'clientRequestId' => 'generation-receipt-0002']), 409, 'generation_already_active');
    gptRejected(fn() => createAtelierGptMessage($db, $bob, $conversationId, $body), 403, 'conversation_forbidden');
    $bobConversation = createAtelierGptConversation($db, $bob, ['title' => 'Synthetic Bob']);
    $bobJob = createAtelierGptMessage($db, $bob, $bobConversation['conversation']['id'], [...$body, 'references' => [['mediaId' => $bobMedia, 'label' => 'Bob reference']]]);
    $GLOBALS['testBody'] = ['workerLeaseId' => $lease, 'imageGenerationAvailable' => false];
    gptCheck(gptResponse(fn() => claimImageStudioRegieJob($db))->body['job'] === null, 'worker without image capability cannot claim Atelier image');
    $legacyId = randomToken(18);
    $db->prepare("INSERT INTO image_studio_messages(id,author_account_id,conversation_id,status,prompt,references_json,created_at) VALUES(?,'regie-owner-fixture',?,'queued','Legacy synthetic','[]','2025-01-01 00:00:00.000')")->execute([$legacyId, randomToken(16)]);
    gptCheck(gptClaim($db, $lease)['job']['id'] === $legacyId, 'older existing Studio job keeps priority in shared FIFO');
    gptCheck(gptClaim($db, $lease)['job'] === null, 'active existing Studio job blocks a second Atelier execution');
    $db->exec('DELETE FROM image_studio_messages');
    $db->prepare("INSERT INTO ability_assistant_messages(id,status,worker_account_id,worker_lease_id,worker_lease_expires_at) VALUES(?,'generating','regie-owner-fixture',?,DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 5 MINUTE))")->execute([randomToken(18), $lease]);
    gptCheck(gptClaim($db, $lease)['job'] === null, 'active ability assistant also blocks Atelier execution');
    $db->exec('DELETE FROM ability_assistant_messages');
    $db->prepare("UPDATE atelier_gpt_messages SET created_at='2026-01-01 00:00:00.000' WHERE id=?")->execute([$created['message']['id']]);
    $workers = [gptStartClaimWorker($schema, $lease), gptStartClaimWorker($schema, $lease)];
    foreach ($workers as $worker) { fwrite($worker[1][0], "GO\n"); fflush($worker[1][0]); }
    $claims = array_map('gptFinishClaimWorker', $workers);
    $jobs = array_values(array_filter(array_column($claims, 'job'), 'is_array'));
    gptCheck(count($jobs) === 1 && $jobs[0]['id'] === $created['message']['id'], 'two simultaneous real dispatcher claims yield exactly one oldest job');
    gptCheck($jobs[0]['jobType'] === 'image' && $jobs[0]['conversationId'] === $conversationId && $jobs[0]['references'] === [], 'Store image worker receives exact compatible minimal job');
    gptCheck(!array_key_exists('password_verifier', $jobs[0]) && !array_key_exists('author_user_id', $jobs[0]), 'worker payload omits Atelier account data');

    $db->prepare('UPDATE atelier_gpt_messages SET worker_lease_expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE id=?')->execute([$jobs[0]['id']]);
    $GLOBALS['testBody'] = ['workerLeaseId' => $lease, 'mediaId' => gptFixtureMedia($db, 'alice', false)];
    gptRejected(fn() => completeImageStudioRegieJob($db, $jobs[0]['id']), 409, 'worker_job_expired');
    $GLOBALS['testBody'] = ['workerLeaseId' => $lease, 'ready' => true, 'imageGenerationAvailable' => true, 'activeMessageId' => $jobs[0]['id'], 'activeJobType' => 'image'];
    $heartbeat = gptResponse(fn() => heartbeatImageStudioRegieWorker($db));
    gptCheck($heartbeat->status === 200 && strtotime(atelierGptMessageRecord($db, $jobs[0]['id'])['worker_lease_expires_at']) > time(), 'unchanged image heartbeat renews Atelier lease');
    $resultMedia = gptFixtureMedia($db, 'alice', false);
    $newLease = randomToken(18);
    $db->prepare('UPDATE image_studio_regie_service SET worker_lease_id=? WHERE singleton_id=1')->execute([$newLease]);
    $GLOBALS['testBody'] = ['workerLeaseId' => $lease, 'mediaId' => $resultMedia, 'width' => 1024, 'height' => 1024];
    gptRejected(fn() => completeImageStudioRegieJob($db, $jobs[0]['id']), 409, 'worker_lease_replaced');
    gptCheck(atelierGptMessageRecord($db, $jobs[0]['id'])['status'] === 'generating' && atelierMediaReferenceCount($db, $resultMedia) === 0, 'stale completion changes neither message nor media ownership');
    $db->prepare('UPDATE image_studio_regie_service SET worker_lease_id=? WHERE singleton_id=1')->execute([$lease]);
    $completed = gptResponse(fn() => completeImageStudioRegieJob($db, $jobs[0]['id']));
    gptCheck($completed->status === 200 && $completed->body['message']['status'] === 'succeeded', 'unchanged completion endpoint dispatches Atelier result');
    gptCheck($completed->body['message']['imageUrl'] === XAR_ATELIER_PREFIX . '/media/' . $resultMedia, 'result URL stays behind Atelier authentication');
    gptCheck(assertAtelierMediaAccess($db, $alice, $resultMedia)['user_id'] === 'alice', 'result linked only to requesting Atelier identity');
    gptRejected(fn() => assertAtelierMediaAccess($db, $bob, $resultMedia), 404, 'atelier_media_missing');
    gptRejected(fn() => assertAtelierRegieMediaAccess($db, ['id' => 'another-regie-gm', 'username' => 'Synthetic', 'permanent_role' => 'gm', 'effective_mode' => 'gm', 'can_administrate' => 1], $resultMedia), 404, 'media_missing');
    $GLOBALS['testBody'] = ['label' => 'Synthetic private result', 'mediaId' => $resultMedia, 'aliases' => []];
    gptRejected(fn() => writeImageReferenceCatalog($db), 403, 'atelier_media_private');
    $GLOBALS['testBody'] = ['workerLeaseId' => $lease, 'mediaId' => $resultMedia, 'width' => 1024, 'height' => 1024];
    gptCheck(gptResponse(fn() => completeImageStudioRegieJob($db, $jobs[0]['id']))->body['message']['id'] === $jobs[0]['id'], 'completion is idempotent');

    $second = gptClaim($db, $lease)['job'];
    gptCheck($second['id'] === $bobJob['message']['id'] && $second['references'][0]['kind'] === 'upload', 'next job carries private upload reference for existing generic media resolver');
    $GLOBALS['testBody'] = ['workerLeaseId' => $lease, 'code' => 'image_quota_exhausted', 'message' => 'Synthetic quota limit; retry after reset.'];
    $failed = gptResponse(fn() => failImageStudioRegieJob($db, $second['id']));
    gptCheck($failed->body['message']['error']['code'] === 'image_quota_exhausted' && $failed->body['message']['error']['httpStatus'] === 429, 'actual worker quota error remains a structured 429 state');
    $status = atelierGptStatus($db, $bob);
    gptCheck($status['quota']['remainingPercent'] === null, 'no quota percentage is fabricated');

    $edit = createAtelierGptMessage($db, $alice, $conversationId, ['prompt' => 'Change only synthetic background.', 'operation' => 'edit',
        'parentMessageId' => $jobs[0]['id'], 'references' => [['mediaId' => $aliceMedia], ['mediaId' => $resultMedia]], 'clientRequestId' => 'generation-edit-receipt-0001']);
    gptCheck(count($edit['message']['references']) === 2 && $edit['message']['references'][0]['mediaId'] === $resultMedia, 'edit source is moved first without duplicating a reference');
    $editClaim = gptClaim($db, $lease)['job'];
    gptCheck($editClaim['id'] === $edit['message']['id'], 'subsequent edit reaches existing image worker');
    $db->prepare('UPDATE atelier_gpt_messages SET worker_lease_expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE id=?')->execute([$editClaim['id']]);
    gptCheck(gptClaim($db, $lease)['job']['id'] === $editClaim['id'], 'expired first attempt is reclaimed under the same service lock');
    $db->prepare('UPDATE atelier_gpt_messages SET worker_lease_expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE id=?')->execute([$editClaim['id']]);
    gptCheck(gptClaim($db, $lease)['job'] === null && atelierGptMessageRecord($db, $editClaim['id'])['error_code'] === 'worker_interrupted', 'maximum interrupted attempts are bounded');
    $db->exec('UPDATE image_studio_regie_service SET paused=1');
    gptRejected(fn() => createAtelierGptMessage($db, $alice, $conversationId, [...$body, 'clientRequestId' => 'generation-paused-0001']), 423, 'regie_codex_paused');
    gptCheck(createAtelierGptMessage($db, $alice, $conversationId, $body)['deduplicated'], 'a successful original receipt can still be replayed during pause');
    $db->exec('UPDATE image_studio_regie_service SET paused=0');

    $revoked = createAtelierGptMessage($db, $bob, $bobConversation['conversation']['id'], [...$body, 'clientRequestId' => 'generation-receipt-0003']);
    $db->exec("UPDATE atelier_users SET revoked_at=UTC_TIMESTAMP(3) WHERE id='bob'");
    gptCheck(gptClaim($db, $lease)['job'] === null && atelierGptMessageRecord($db, $revoked['message']['id'])['status'] === 'cancelled', 'revoked account jobs are never assigned');
    echo 'Atelier GPT SQL checks: ' . $GLOBALS['checks'] . " passed.\n";
} finally {
    if (preg_match('/^atelier_gpt_audit_[a-f0-9]{16}$/D', $schema) !== 1) throw new RuntimeException('Unsafe cleanup schema.');
    $admin->exec('DROP DATABASE IF EXISTS `' . $schema . '`');
}
