<?php

declare(strict_types=1);

// Exercise the real Studio route and the real persistent-receipt authority. Only
// transport, authentication and SQL storage are replaced by deterministic memory
// fixtures; this test never opens a network connection or touches a real account.
require_once __DIR__ . '/../api/v1/domains.php';
require_once __DIR__ . '/../api/v1/online.php';
require_once __DIR__ . '/../api/v1/image-studio.php';

final class ImageStudioTestResponse extends RuntimeException
{
    public function __construct(public int $status, public array $body)
    {
        parent::__construct(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}

function sendJson(int $status, array $body, bool $headOnly = false, array $extraHeaders = []): never
{
    throw new ImageStudioTestResponse($status, $body);
}

function sendError(int $status, string $message, string $code = ''): never
{
    sendJson($status, ['error' => $message, 'code' => $code]);
}

function requireMethod(string $actual, array $allowed): void
{
    if (!in_array($actual, $allowed, true)) {
        sendError(405, 'Méthode non autorisée.', 'method_not_allowed');
    }
}

function readJsonBody(int $maximumBytes = 16384): array
{
    return $GLOBALS['imageStudioTestBody'];
}

function cleanText(mixed $value, int $maximum, string $label): string
{
    $text = trim((string) $value);
    if ($text === '' || str_contains($text, "\0")
        || preg_match('/^.{1,' . $maximum . '}$/usD', $text) !== 1) {
        throw new InvalidArgumentException($label . ' invalide.');
    }
    return $text;
}

function randomToken(int $bytes = 32): string
{
    static $index = 0;
    return str_pad('studio' . (++$index), 22, 'x');
}

function requestSessionToken(): string
{
    return 'studio-test-session';
}

function resolveSession(PDO $connection, string $token, bool $touch = true): ?array
{
    return $GLOBALS['imageStudioTestIdentity'];
}

function acquireMaintenanceLock(PDO $connection, string $name): bool
{
    return false;
}

final class ImageStudioMemoryStatement extends PDOStatement
{
    private array $rows = [];

    public function __construct(private ImageStudioMemoryConnection $database, private string $sql) {}

    public function execute(?array $params = null): bool
    {
        $this->rows = $this->database->executeSql($this->sql, $params ?? []);
        return true;
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        return array_shift($this->rows) ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->fetch();
        return is_array($row) ? (array_values($row)[$column] ?? false) : false;
    }
}

final class ImageStudioMemoryConnection extends PDO
{
    public array $conversations = [];
    public array $domains = [];
    public int $revision = 1;
    public bool $failNextDomainPersist = false;
    private ?array $snapshot = null;

    public function __construct()
    {
        $this->putDomain('activity', [
            'actionTimers' => [],
            'actionTimerTombstones' => [],
            'mapPings' => [],
            'shortcuts' => [],
            'rolls' => [],
            'playerActions' => [],
            'pendingAttacks' => [],
            'attackReceipts' => [],
            'resourceReceipts' => [],
        ]);
    }

    public function putDomain(string $key, array $payload, int $revision = 1): void
    {
        $this->domains[$key] = [
            'domain_key' => $key,
            'schema_version' => 1,
            'revision' => $revision,
            'payload' => $payload,
            'updated_at' => '2026-09-12T00:00:00Z',
        ];
    }

    public function activity(): array
    {
        return $this->domains['activity']['payload'] ?? [];
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new ImageStudioMemoryStatement($this, $query);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $statement = $this->prepare($query);
        $statement->execute();
        return $statement;
    }

    public function exec(string $statement): int|false
    {
        // Session/domain-history cleanup is intentionally inert in this isolated fixture.
        return 0;
    }

    public function beginTransaction(): bool
    {
        if ($this->snapshot !== null) {
            throw new RuntimeException('nested_test_transaction');
        }
        $this->snapshot = [$this->conversations, $this->domains, $this->revision];
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->snapshot !== null;
    }

    public function commit(): bool
    {
        $this->snapshot = null;
        return true;
    }

    public function rollBack(): bool
    {
        if ($this->snapshot === null) {
            return false;
        }
        [$this->conversations, $this->domains, $this->revision] = $this->snapshot;
        $this->snapshot = null;
        return true;
    }

    public function executeSql(string $sql, array $params): array
    {
        if (str_starts_with($sql, 'SELECT global_revision, state_schema_version')) {
            return [[
                'global_revision' => $this->revision,
                'state_schema_version' => XAR_SESSION_SCHEMA_VERSION,
                'domain_schema_version' => XAR_DOMAIN_SCHEMA_VERSION,
                'legacy_revision' => null,
                'initialized_at' => '2026-09-12 00:00:00.000',
            ]];
        }
        if (str_starts_with($sql, 'SELECT domain_key, schema_version')) {
            return array_values(array_filter(
                $this->domains,
                static fn (array $record): bool => $params === []
                    || in_array($record['domain_key'], $params, true)
            ));
        }
        if (str_starts_with($sql, 'INSERT INTO image_studio_conversations ')) {
            $id = (string) $params[':id'];
            $this->conversations[$id] = [
                'id' => $id,
                'owner_account_id' => (string) $params[':owner_account_id'],
                'title' => (string) $params[':title'],
                'owner_archived_at' => null,
                'created_at' => '2026-09-12 00:00:00.000',
                'updated_at' => '2026-09-12 00:00:00.000',
                'username' => 'mj-test',
                'display_name' => 'MJ test',
                'message_count' => 0,
            ];
            return [];
        }
        if (str_contains($sql, 'FROM image_studio_conversations c')
            && str_contains($sql, 'WHERE c.id = :id')) {
            $row = $this->conversations[(string) ($params[':id'] ?? '')] ?? null;
            return is_array($row) ? [$row] : [];
        }
        if (str_starts_with($sql, 'INSERT INTO application_domain_history ')
            || str_starts_with($sql, 'INSERT INTO application_domain_changes ')) {
            return [];
        }
        if (str_starts_with($sql, 'INSERT INTO application_domains ')) {
            if ($this->failNextDomainPersist) {
                $this->failNextDomainPersist = false;
                throw new RuntimeException('forced_domain_persist_failure');
            }
            $this->putDomain(
                (string) $params[':domain_key'],
                json_decode((string) $params[':payload'], true, 512, JSON_THROW_ON_ERROR),
                (int) $params[':revision']
            );
            return [];
        }
        if (str_starts_with($sql, 'UPDATE application_domain_clock ')) {
            $this->revision = (int) $params[':global_revision'];
            return [];
        }
        throw new RuntimeException('Unexpected SQL in Studio fixture: ' . $sql);
    }
}

function requireImageStudioIdempotence(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['imageStudioChecks'] = ($GLOBALS['imageStudioChecks'] ?? 0) + 1;
}

function runImageStudioConversationCreate(
    ImageStudioMemoryConnection $database,
    array $body,
    string $accountId = 'account-gm'
): ImageStudioTestResponse {
    $GLOBALS['imageStudioTestIdentity'] = [
        'id' => $accountId,
        'username' => 'mj-test',
        'display_name' => 'MJ test',
        'effective_mode' => 'gm',
        'permanent_role' => 'gm',
        'can_administrate' => true,
        'image_studio_session' => false,
    ];
    $GLOBALS['imageStudioTestBody'] = $body;
    try {
        handleImageStudioRoute($database, '/api/v1/image-studio/conversations', 'POST', false);
    } catch (ImageStudioTestResponse $response) {
        return $response;
    }
    throw new RuntimeException('The Studio create route did not emit a response.');
}

$signature = imageStudioConversationCreateRequestSignature('Nouvelle vision');
requireImageStudioIdempotence(strlen($signature) === 64, 'La signature doit être un SHA-256.');
requireImageStudioIdempotence(
    hash_equals($signature, imageStudioConversationCreateRequestSignature('Nouvelle vision')),
    'Le même titre normalisé doit conserver sa signature.'
);
requireImageStudioIdempotence(
    !hash_equals($signature, imageStudioConversationCreateRequestSignature('Autre vision')),
    'Un titre différent doit produire un mismatch.'
);

// Compatibility path for 3.2.14: an absent request id remains accepted and keeps
// the historical non-idempotent behavior.
$legacyDatabase = new ImageStudioMemoryConnection();
$legacyRevision = $legacyDatabase->revision;
$legacyFirst = runImageStudioConversationCreate($legacyDatabase, ['title' => 'Ancienne requête']);
$legacySecond = runImageStudioConversationCreate($legacyDatabase, ['title' => 'Ancienne requête']);
requireImageStudioIdempotence(
    $legacyFirst->status === 201 && $legacySecond->status === 201,
    'Les créations héritées sans identifiant doivent rester acceptées.'
);
requireImageStudioIdempotence(
    count($legacyDatabase->conversations) === 2
        && $legacyDatabase->revision === $legacyRevision
        && count($legacyDatabase->activity()['resourceReceipts'] ?? []) === 0,
    'La voie héritée doit créer à chaque appel sans fabriquer de reçu.'
);

$database = new ImageStudioMemoryConnection();
$requestId = 'studio-create-20260912-0001';
$initialRevision = $database->revision;
$created = runImageStudioConversationCreate($database, [
    'clientRequestId' => $requestId,
    'title' => 'Vision persistante',
]);
$createdId = (string) ($created->body['conversation']['id'] ?? '');
$receipts = $database->activity()['resourceReceipts'] ?? [];
requireImageStudioIdempotence(
    $created->status === 201
        && ($created->body['deduplicated'] ?? null) === false
        && $createdId !== ''
        && count($database->conversations) === 1,
    'La route réelle doit créer exactement une conversation moderne.'
);
requireImageStudioIdempotence(
    $database->revision === $initialRevision + 1
        && count($receipts) === 1
        && ($receipts[0]['kind'] ?? '') === 'studio-conversation-create'
        && ($receipts[0]['requestId'] ?? '') === $requestId
        && ($receipts[0]['accountId'] ?? '') === 'account-gm'
        && ($receipts[0]['result']['conversationId'] ?? '') === $createdId,
    'La création moderne doit persister son reçu account+kind+résultat dans la même révision.'
);

$stableRevision = $database->revision;
$stableActivity = $database->activity();
$retry = runImageStudioConversationCreate($database, [
    'clientRequestId' => $requestId,
    'title' => 'Vision persistante',
]);
requireImageStudioIdempotence(
    $retry->status === 200
        && ($retry->body['deduplicated'] ?? null) === true
        && ($retry->body['conversation']['id'] ?? '') === $createdId,
    'Un retry identique doit rejouer la conversation créée.'
);
requireImageStudioIdempotence(
    count($database->conversations) === 1
        && $database->revision === $stableRevision
        && $database->activity() === $stableActivity,
    'Un retry identique ne doit créer ni conversation, ni reçu, ni révision supplémentaire.'
);

$mismatch = runImageStudioConversationCreate($database, [
    'clientRequestId' => $requestId,
    'title' => 'Titre modifié',
]);
requireImageStudioIdempotence(
    $mismatch->status === 409
        && ($mismatch->body['code'] ?? '') === 'conversation_create_request_mismatch'
        && count($database->conversations) === 1
        && $database->revision === $stableRevision
        && $database->activity() === $stableActivity,
    'Un même identifiant avec un autre titre doit être refusé sans mutation.'
);

$forbidden = runImageStudioConversationCreate($database, [
    'clientRequestId' => $requestId,
    'title' => 'Vision persistante',
], 'account-other-gm');
requireImageStudioIdempotence(
    $forbidden->status === 403
        && ($forbidden->body['code'] ?? '') === 'conversation_create_receipt_forbidden'
        && count($database->conversations) === 1
        && $database->revision === $stableRevision,
    'Un autre compte ne doit jamais pouvoir rejouer le reçu.'
);

$invalidDatabase = new ImageStudioMemoryConnection();
$invalid = runImageStudioConversationCreate($invalidDatabase, [
    'clientRequestId' => 'trop-court',
    'title' => 'Refusée',
]);
requireImageStudioIdempotence(
    $invalid->status === 400
        && ($invalid->body['code'] ?? '') === 'invalid_conversation_create_request'
        && $invalidDatabase->conversations === []
        && count($invalidDatabase->activity()['resourceReceipts'] ?? []) === 0,
    'Un identifiant mal formé doit être refusé avant toute mutation.'
);

$rollbackDatabase = new ImageStudioMemoryConnection();
$rollbackDatabase->failNextDomainPersist = true;
$rollbackRevision = $rollbackDatabase->revision;
try {
    runImageStudioConversationCreate($rollbackDatabase, [
        'clientRequestId' => 'studio-create-rollback-0001',
        'title' => 'Transaction annulée',
    ]);
    throw new RuntimeException('La panne de persistance forcée aurait dû remonter.');
} catch (RuntimeException $error) {
    requireImageStudioIdempotence(
        $error->getMessage() === 'forced_domain_persist_failure',
        'Le test de rollback doit échouer au point de persistance prévu.'
    );
}
requireImageStudioIdempotence(
    $rollbackDatabase->conversations === []
        && $rollbackDatabase->revision === $rollbackRevision
        && count($rollbackDatabase->activity()['resourceReceipts'] ?? []) === 0
        && !$rollbackDatabase->inTransaction(),
    'Une panne de reçu doit annuler ensemble conversation, reçu et révision.'
);

unset($database->conversations[$createdId]);
$stale = runImageStudioConversationCreate($database, [
    'clientRequestId' => $requestId,
    'title' => 'Vision persistante',
]);
requireImageStudioIdempotence(
    $stale->status === 409
        && ($stale->body['code'] ?? '') === 'conversation_create_receipt_stale'
        && $database->conversations === []
        && $database->revision === $stableRevision
        && $database->activity() === $stableActivity,
    'Un reçu dont la conversation a disparu doit échouer sans recréation implicite.'
);

$checks = (int) ($GLOBALS['imageStudioChecks'] ?? 0);
echo "Idempotence Studio PHP : {$checks} contrôles réussis.\n";
