<?php

declare(strict_types=1);

// Real PHP authority functions, with only transport/authentication and SQL storage replaced.
// This suite never opens a network connection or touches a real account/database.
require_once __DIR__ . '/../api/v1/domains.php';
require_once __DIR__ . '/../api/v1/online.php';
require_once __DIR__ . '/../api/v1/health-overlays.php';

final class TestResponse extends RuntimeException
{
    public function __construct(public int $status, public array $body) { parent::__construct(json_encode($body, JSON_UNESCAPED_UNICODE)); }
}
function sendJson(int $status, array $body, bool $headOnly = false): never { throw new TestResponse($status, $body); }
function sendError(int $status, string $message, string $code = ''): never { sendJson($status, ['error' => $message, 'code' => $code]); }
function randomToken(int $bytes = 32): string { static $index = 0; return str_pad((string) ++$index, max(16, $bytes), 'x', STR_PAD_LEFT); }
function resolveSession(PDO $connection, string $token): array { return $GLOBALS['testIdentity']; }
function requestSessionToken(): string { return 'test-session'; }
function readJsonBody(int $maximum): array { return $GLOBALS['testBody']; }
function acquireMaintenanceLock(PDO $connection, string $name): bool { return false; }

final class MemoryStatement extends PDOStatement
{
    private array $rows = [];
    public function __construct(private MemoryConnection $database, private string $sql) {}
    public function execute(?array $params = null): bool { $this->rows = $this->database->executeSql($this->sql, $params ?? []); return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return array_shift($this->rows) ?? false; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function fetchColumn(int $column = 0): mixed { $row = $this->fetch(); return is_array($row) ? array_values($row)[$column] ?? false : false; }
}
final class MemoryConnection extends PDO
{
    public array $domains = [];
    public array $healthOverlays = [];
    public array $accounts = [];
    public int $revision = 1;
    private ?array $snapshot = null;
    public function __construct(array $domains)
    {
        foreach ($domains as $key => $payload) $this->put($key, $payload);
    }
    public function put(string $key, array $payload, int $revision = 1): void
    {
        $this->domains[$key] = ['domain_key' => $key, 'schema_version' => 1, 'revision' => $revision, 'payload' => $payload, 'updated_at' => '2026-09-07T00:00:00Z'];
    }
    public function payload(string $key): array { return $this->domains[$key]['payload'] ?? []; }
    public function prepare(string $query, array $options = []): PDOStatement|false { return new MemoryStatement($this, $query); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { $s = $this->prepare($query); $s->execute(); return $s; }
    public function beginTransaction(): bool { $this->snapshot = [$this->domains, $this->revision]; return true; }
    public function inTransaction(): bool { return $this->snapshot !== null; }
    public function commit(): bool { $this->snapshot = null; return true; }
    public function rollBack(): bool { [$this->domains, $this->revision] = $this->snapshot; $this->snapshot = null; return true; }
    public function executeSql(string $sql, array $params): array
    {
        if (str_starts_with($sql, 'SELECT global_revision, state_schema_version')) return [[
            'global_revision' => $this->revision, 'state_schema_version' => 16, 'domain_schema_version' => 1, 'legacy_revision' => null, 'initialized_at' => 'done',
        ]];
        if (str_starts_with($sql, 'SELECT domain_key, schema_version')) {
            return array_values(array_filter($this->domains, static function (array $record) use ($sql, $params): bool {
                if (isset($params[':character_id'])) return str_starts_with($record['domain_key'], 'token:') && ($record['payload']['characterId'] ?? '') === $params[':character_id'];
                if (isset($params[':prefix'])) return str_starts_with($record['domain_key'], rtrim($params[':prefix'], '%'));
                return !str_contains($sql, 'WHERE domain_key IN') || in_array($record['domain_key'], $params, true);
            }));
        }
        if (str_contains($sql, 'FROM character_health_overlays o') && isset($params[':public_slug'])) {
            $overlay = $this->healthOverlays[(string) $params[':public_slug']] ?? null;
            $characterId = is_array($overlay) ? (string) ($overlay['character_id'] ?? '') : '';
            $domain = $this->domains['character:' . $characterId] ?? null;
            return is_array($overlay) && is_array($domain) ? [[
                'character_id' => $characterId,
                'payload' => json_encode($domain['payload'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'revision' => $domain['revision'],
                'updated_at' => $domain['updated_at'],
            ]] : [];
        }
        if (str_starts_with($sql, 'INSERT INTO application_domains ')) {
            $this->put($params[':domain_key'], json_decode($params[':payload'], true, 512, JSON_THROW_ON_ERROR), $params[':revision']);
            return [];
        }
        if (str_starts_with($sql, 'DELETE FROM application_domains WHERE domain_key')) {
            unset($this->domains[(string) ($params[':domain_key'] ?? '')]);
            return [];
        }
        if (str_starts_with($sql, 'UPDATE application_domain_clock')) { $this->revision = $params[':global_revision']; return []; }
        if (str_starts_with($sql, 'INSERT INTO application_domain_history') || str_starts_with($sql, 'INSERT INTO application_domain_changes')) return [];
        if (str_contains($sql, 'FROM shared_settings')) return [];
        if (str_contains($sql, 'FROM accounts') && str_contains($sql, 'revoked_at IS NULL')) return $this->accounts;
        throw new RuntimeException('Unexpected SQL in fixture: ' . $sql);
    }
}
function requireTactical(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    $GLOBALS['checks'] = ($GLOBALS['checks'] ?? 0) + 1;
}
function runCommand(
    MemoryConnection $db,
    string $command,
    array $payload,
    bool $gm = false,
    string $account = 'account-player',
    bool $administrator = false
): TestResponse
{
    $GLOBALS['testIdentity'] = [
        'id' => $account,
        'display_name' => $gm ? 'MJ test' : 'Joueur test',
        'effective_mode' => $gm ? 'gm' : 'player',
        'permanent_role' => $gm ? 'gm' : 'player',
        'can_administrate' => $administrator,
    ];
    $GLOBALS['testBody'] = ['command' => $command, 'payload' => $payload];
    try { commandOnlineState($db, []); } catch (TestResponse $response) { return $response; }
    throw new RuntimeException('No command response');
}
function fixture(): MemoryConnection
{
    return new MemoryConnection([
        'table' => ['activeSceneId' => 'scene-one', 'tacticalSync' => ['paused' => false]],
        'map:scene-one' => ['gridSize' => 50],
        'token-index:scene-one' => ['order' => ['token-player', 'token-monster', 'token-monster-two']],
        'initiative:scene-one' => ['active' => true, 'order' => ['token-monster', 'token-player'], 'currentIndex' => 0],
        'character:character-player' => ['id' => 'character-player', 'ownerPlayerId' => 'account-player', 'name' => 'Personnage', 'color' => '#22aa33', 'resources' => ['hp' => 10, 'maxHp' => 100, 'mana' => 5, 'maxMana' => 10], 'conditions' => [], 'stats' => ['force' => 50],
            'abilities' => [['id' => 'ability-one', 'name' => 'Frappe test', 'formula' => '1', 'damageType' => 'physical', 'description' => '']]],
        'luck' => ['characters' => []],
        'token:scene-one:token-player' => ['id' => 'token-player', 'characterId' => 'character-player', 'controllerPlayerId' => 'stale-controller', 'name' => 'Personnage', 'hp' => 99, 'maxHp' => 100, 'conditions' => [], 'x' => 20, 'y' => 50],
        'token:scene-two:token-copy' => ['id' => 'token-copy', 'characterId' => 'character-player', 'name' => 'Copie', 'hp' => 99, 'maxHp' => 100, 'conditions' => [], 'x' => 20, 'y' => 50],
        'token:scene-one:token-independent' => ['id' => 'token-independent', 'characterId' => 'character-player', 'followCharacter' => false, 'hp' => 40, 'maxHp' => 40, 'conditions' => ['Endormi']],
        'token:scene-one:token-monster' => ['id' => 'token-monster', 'name' => 'Créature', 'hp' => 40, 'maxHp' => 40, 'frameVariant' => 'boss', 'x' => 50, 'y' => 50,
            'stats' => [['id' => 'monster-force', 'label' => 'Force', 'value' => '70']], 'weaponAttacks' => [['id' => 'monster-claw', 'formula' => '1d6', 'damageType' => 'physical']]],
        'token:scene-one:token-monster-two' => ['id' => 'token-monster-two', 'name' => 'Seconde créature', 'hp' => 35, 'maxHp' => 35, 'x' => 65, 'y' => 50,
            'stats' => [['id' => 'monster-two-force', 'label' => 'Force', 'value' => '60']], 'weaponAttacks' => [['id' => 'monster-two-claw', 'formula' => '1d4', 'damageType' => 'physical']]],
        'activity' => ['actionTimers' => [], 'actionTimerTombstones' => [], 'mapPings' => [], 'shortcuts' => [], 'rolls' => [], 'playerActions' => [], 'pendingAttacks' => [], 'attackReceipts' => []],
    ]);
}

function deletionFixture(): MemoryConnection
{
    $database = fixture();
    $database->put('roster', [
        'players' => [['id' => 'account-player', 'name' => 'Joueur test']],
        'characterOrder' => ['character-player'],
        'playerPreferences' => ['account-player' => ['activeCharacterId' => 'character-player']],
        'playerTombstones' => [],
        'characterTombstones' => [],
    ]);
    $activity = $database->payload('activity');
    $activity['actionTimers'] = [[
        'id' => 'timer-character-delete',
        'sceneId' => 'scene-one',
        'label' => 'Recharge supprimée',
        'cooldown' => 3,
        'usedRound' => 1,
        'readyRound' => 4,
        'ownerPlayerId' => 'account-player',
        'characterId' => 'character-player',
    ]];
    $database->put('activity', $activity);
    $database->put('token-index:scene-two', ['order' => ['token-copy']]);
    $database->put('initiative:scene-two', ['active' => false, 'order' => ['token-copy'], 'currentIndex' => 0]);
    return $database;
}

// Exercise the player-account routes through the real command dispatcher. These
// commands used to be covered only by source-pattern assertions in the Node suite.
$accountDatabase = fixture();
$ensureResponse = runCommand($accountDatabase, 'ensure-player', []);
$ensuredRoster = $accountDatabase->payload('roster');
requireTactical(
    $ensureResponse->status === 200
        && count($ensuredRoster['players'] ?? []) === 1
        && ($ensuredRoster['players'][0]['id'] ?? '') === 'account-player'
        && ($ensuredRoster['players'][0]['name'] ?? '') === 'Joueur test',
    'The ensure-player command really creates the authenticated roster entry.'
);
$ensuredRevision = $accountDatabase->revision;
$ensureRetry = runCommand($accountDatabase, 'ensure-player', []);
requireTactical(
    $ensureRetry->status === 200
        && $accountDatabase->revision === $ensuredRevision
        && count($accountDatabase->payload('roster')['players'] ?? []) === 1,
    'The ensure-player route is idempotent for an unchanged authenticated account.'
);

$ambiguousEnsureDatabase = fixture();
$legacyAdaOwnerId = 'player-7fd6193e-b970-4d76-bbb9-11fc8ef8d386';
$ambiguousAdaAccountId = 'account-ada-primary';
$ambiguousEnsureDatabase->put('roster', [
    'players' => [
        ['id' => $legacyAdaOwnerId, 'name' => 'Ancienne Ada'],
        ['id' => $ambiguousAdaAccountId, 'name' => 'Joueur test'],
    ],
    'characterOrder' => ['character-legacy-ada'],
    'playerPreferences' => [$legacyAdaOwnerId => ['activePage' => 'characters']],
    'playerTombstones' => [],
    'characterTombstones' => [],
]);
$ambiguousEnsureDatabase->put('character:character-legacy-ada', [
    'id' => 'character-legacy-ada',
    'ownerPlayerId' => $legacyAdaOwnerId,
    'name' => 'Fiche historique',
]);
$ambiguousEnsureDatabase->put('token:scene-one:token-legacy-ada', [
    'id' => 'token-legacy-ada',
    'characterId' => 'character-legacy-ada',
    'controllerPlayerId' => $legacyAdaOwnerId,
]);
$ambiguousActivity = $ambiguousEnsureDatabase->payload('activity');
$ambiguousActivity['actionTimers'][] = [
    'id' => 'timer-legacy-ada',
    'characterId' => 'character-legacy-ada',
    'ownerPlayerId' => $legacyAdaOwnerId,
];
$ambiguousEnsureDatabase->put('activity', $ambiguousActivity);
$ambiguousEnsureDatabase->accounts = [
    ['id' => $ambiguousAdaAccountId, 'username' => 'ada', 'display_name' => 'Ada principale'],
    ['id' => 'account-ada-homonym', 'username' => 'autre', 'display_name' => 'Ada'],
];
$ambiguousEnsureBefore = $ambiguousEnsureDatabase->domains;
$ambiguousEnsureRevision = $ambiguousEnsureDatabase->revision;
$ambiguousEnsureResponse = runCommand(
    $ambiguousEnsureDatabase,
    'ensure-player',
    [],
    false,
    $ambiguousAdaAccountId
);
requireTactical(
    $ambiguousEnsureResponse->status === 200
        && $ambiguousEnsureDatabase->revision === $ambiguousEnsureRevision
        && $ambiguousEnsureDatabase->domains === $ambiguousEnsureBefore
        && ($ambiguousEnsureDatabase->payload('character:character-legacy-ada')['ownerPlayerId'] ?? '') === $legacyAdaOwnerId
        && ($ambiguousEnsureDatabase->payload('token:scene-one:token-legacy-ada')['controllerPlayerId'] ?? '') === $legacyAdaOwnerId
        && ($ambiguousEnsureDatabase->payload('activity')['actionTimers'][0]['ownerPlayerId'] ?? '') === $legacyAdaOwnerId,
    'An ambiguous active-account alias cannot migrate a legacy owner through ensure-player.'
);

$preferencesResponse = runCommand($accountDatabase, 'preferences.update', [
    'musicMuted' => true,
    'ambienceMuted' => false,
    'activePage' => 'characters',
    'activeCharacterId' => 'character-player',
]);
$storedPreferences = $accountDatabase->payload('roster')['playerPreferences']['account-player'] ?? [];
requireTactical(
    $preferencesResponse->status === 200
        && ($preferencesResponse->body['preferences'] ?? null) === $storedPreferences
        && ($storedPreferences['musicMuted'] ?? false) === true
        && ($storedPreferences['ambienceMuted'] ?? true) === false
        && ($storedPreferences['activePage'] ?? '') === 'characters'
        && ($storedPreferences['activeCharacterId'] ?? '') === 'character-player',
    'The preferences.update route persists and returns the normalized player preferences.'
);
$preferencesRevision = $accountDatabase->revision;
$foreignPreferences = runCommand($accountDatabase, 'preferences.update', [
    'activeCharacterId' => 'character-player',
], false, 'intruder');
requireTactical(
    $foreignPreferences->status === 403
        && ($foreignPreferences->body['code'] ?? '') === 'character_forbidden'
        && $accountDatabase->revision === $preferencesRevision,
    'The preferences route refuses a foreign active character without mutating the store.'
);

$requestedCharacterId = 'character-created-0001';
$characterCreatePayload = ['requestId' => 'character-create-request-0001', 'character' => [
    'id' => $requestedCharacterId,
    'name' => 'Nouvelle fiche',
    'ownerPlayerId' => 'intruder',
    'resources' => ['hp' => 12, 'maxHp' => 20, 'mana' => 0, 'maxMana' => 0],
]];
$createResponse = runCommand($accountDatabase, 'character.create', $characterCreatePayload);
$createdCharacterId = (string) ($createResponse->body['character']['id'] ?? '');
$createdCharacter = $accountDatabase->payload('character:' . $createdCharacterId);
requireTactical(
    $createResponse->status === 200
        && preg_match('/^character-[A-Za-z0-9_-]{16,}$/D', $createdCharacterId) === 1
        && $createdCharacterId !== $requestedCharacterId
        && ($createdCharacter['ownerPlayerId'] ?? '') === 'account-player'
        && in_array($createdCharacterId, $accountDatabase->payload('roster')['characterOrder'] ?? [], true),
    'The character.create route persists a server-id sheet and rejects injected identity fields.'
);
$characterCreateRevision = $accountDatabase->revision;
$characterCreateActions = count($accountDatabase->payload('activity')['playerActions'] ?? []);
$characterCreateRetry = runCommand($accountDatabase, 'character.create', $characterCreatePayload);
requireTactical(
    $characterCreateRetry->status === 200
        && ($characterCreateRetry->body['deduplicated'] ?? false) === true
        && ($characterCreateRetry->body['character']['id'] ?? '') === $createdCharacterId
        && $accountDatabase->revision === $characterCreateRevision
        && count($accountDatabase->payload('activity')['playerActions'] ?? []) === $characterCreateActions
        && count(array_filter(
            $accountDatabase->payload('activity')['resourceReceipts'] ?? [],
            static fn (mixed $entry): bool => is_array($entry) && ($entry['requestId'] ?? '') === $characterCreatePayload['requestId']
        )) === 1,
    'A lost character.create response can be replayed without a second sheet or journal entry.'
);
$characterMismatch = runCommand($accountDatabase, 'character.create', [
    ...$characterCreatePayload,
    'character' => [...$characterCreatePayload['character'], 'name' => 'Autre fiche'],
]);
requireTactical(
    $characterMismatch->status === 409
        && ($characterMismatch->body['code'] ?? '') === 'character_create_request_mismatch'
        && $accountDatabase->revision === $characterCreateRevision,
    'A character.create request id cannot be reused for different sheet content.'
);
$legacyCharacterDatabase = fixture();
runCommand($legacyCharacterDatabase, 'ensure-player', []);
$legacyCharacterCreate = runCommand($legacyCharacterDatabase, 'character.create', ['character' => ['name' => 'Fiche héritée']]);
requireTactical(
    $legacyCharacterCreate->status === 200
        && !array_key_exists('deduplicated', $legacyCharacterCreate->body)
        && ($legacyCharacterDatabase->payload('activity')['resourceReceipts'] ?? []) === [],
    'A legacy character.create request without a request id remains accepted during the compatibility window.'
);
$gmCharacterDatabase = fixture();
$gmCharacterRevision = $gmCharacterDatabase->revision;
$gmCharacterCreate = runCommand($gmCharacterDatabase, 'character.create', ['requestId' => 'gm-character-create-request-01', 'character' => [
    'id' => 'character-created-by-gm',
    'name' => 'Interdit',
]], true, 'account-gm');
requireTactical(
    $gmCharacterCreate->status === 403
        && ($gmCharacterCreate->body['code'] ?? '') === 'player_mode_required'
        && $gmCharacterDatabase->revision === $gmCharacterRevision
        && $gmCharacterDatabase->payload('roster') === []
        && $gmCharacterDatabase->payload('character:character-created-by-gm') === [],
    'The player character creation route is refused while authenticated in GM mode.'
);

$timerDatabase = fixture();
$invalidTimerRevision = $timerDatabase->revision;
$invalidTimer = runCommand($timerDatabase, 'timer.create', [
    'requestId' => 'invalid-timer-create-request-01',
    'sceneId' => 'scene-one',
    'characterId' => 'character-player',
    'label' => " \n ",
    'cooldown' => 3,
]);
requireTactical(
    $invalidTimer->status === 400
        && ($invalidTimer->body['code'] ?? '') === 'invalid_timer'
        && $timerDatabase->revision === $invalidTimerRevision,
    'The timer.create route rejects a blank label without mutating activity.'
);
$timerCreatePayload = [
    'requestId' => 'timer-create-request-0001',
    'sceneId' => 'scene-one',
    'characterId' => 'character-player',
    'label' => 'Souffle draconique',
    'cooldown' => 3,
    'visibility' => 'public',
];
$timerResponse = runCommand($timerDatabase, 'timer.create', $timerCreatePayload);
$timer = $timerResponse->body['timer'] ?? [];
$timerId = (string) ($timer['id'] ?? '');
requireTactical(
    $timerResponse->status === 200
        && preg_match('/^timer-[A-Za-z0-9_-]{16,}$/D', $timerId) === 1
        && ($timer['ownerPlayerId'] ?? '') === 'account-player'
        && ($timer['characterId'] ?? '') === 'character-player'
        && ($timer['label'] ?? '') === 'Souffle draconique'
        && ($timer['cooldown'] ?? 0) === 3
        && ($timer['readyRound'] ?? 0) === 4
        && ($timer['visibility'] ?? '') === 'public'
        && findEntryIndex($timerDatabase->payload('activity')['actionTimers'] ?? [], $timerId) === 0,
    'The timer.create route persists a complete owned cooldown.'
);
$timerCreateRevision = $timerDatabase->revision;
$timerCreateActions = count($timerDatabase->payload('activity')['playerActions'] ?? []);
$timerCreateRetry = runCommand($timerDatabase, 'timer.create', $timerCreatePayload);
requireTactical(
    $timerCreateRetry->status === 200
        && ($timerCreateRetry->body['deduplicated'] ?? false) === true
        && ($timerCreateRetry->body['timer']['id'] ?? '') === $timerId
        && $timerDatabase->revision === $timerCreateRevision
        && count($timerDatabase->payload('activity')['actionTimers'] ?? []) === 1
        && count($timerDatabase->payload('activity')['playerActions'] ?? []) === $timerCreateActions,
    'A lost timer.create response can be replayed without a second timer or journal entry.'
);
$timerMismatch = runCommand($timerDatabase, 'timer.create', [...$timerCreatePayload, 'cooldown' => 4]);
requireTactical(
    $timerMismatch->status === 409
        && ($timerMismatch->body['code'] ?? '') === 'timer_create_request_mismatch'
        && $timerDatabase->revision === $timerCreateRevision,
    'A timer.create request id cannot be reused for another cooldown.'
);
$legacyTimerDatabase = fixture();
$legacyTimerCreate = runCommand($legacyTimerDatabase, 'timer.create', [
    'characterId' => 'character-player', 'label' => 'Ancienne recharge', 'cooldown' => 2,
]);
requireTactical(
    $legacyTimerCreate->status === 200
        && !array_key_exists('deduplicated', $legacyTimerCreate->body)
        && ($legacyTimerDatabase->payload('activity')['resourceReceipts'] ?? []) === [],
    'A legacy timer.create request without a request id remains accepted during the compatibility window.'
);
$timerRevision = $timerDatabase->revision;
$earlyTimer = runCommand($timerDatabase, 'timer.update', ['timerId' => $timerId]);
requireTactical(
    $earlyTimer->status === 409
        && ($earlyTimer->body['code'] ?? '') === 'timer_not_ready'
        && $timerDatabase->revision === $timerRevision
        && (($timerDatabase->payload('activity')['actionTimers'][0]['usedRound'] ?? 0) === 1),
    'The timer.update route refuses a cooldown before its ready round without mutating activity.'
);
$foreignTimer = runCommand($timerDatabase, 'timer.update', ['timerId' => $timerId], false, 'intruder');
requireTactical(
    $foreignTimer->status === 403
        && ($foreignTimer->body['code'] ?? '') === 'timer_forbidden'
        && $timerDatabase->revision === $timerRevision,
    'The timer update route refuses a foreign owner without mutating activity.'
);
$initiative = $timerDatabase->payload('initiative:scene-one');
$initiative['round'] = 4;
$timerDatabase->put('initiative:scene-one', $initiative);
$timerUpdate = runCommand($timerDatabase, 'timer.update', ['timerId' => $timerId]);
$updatedTimer = $timerUpdate->body['timer'] ?? [];
requireTactical(
    $timerUpdate->status === 200
        && ($updatedTimer['usedRound'] ?? 0) === 4
        && ($updatedTimer['readyRound'] ?? 0) === 7
        && (($timerDatabase->payload('activity')['actionTimers'][0]['readyRound'] ?? 0) === 7),
    'The timer.update route reuses the owned cooldown from the current combat round.'
);
$timerDelete = runCommand($timerDatabase, 'timer.delete', ['timerId' => $timerId]);
$deletedActivity = $timerDatabase->payload('activity');
requireTactical(
    $timerDelete->status === 200
        && ($timerDelete->body['timer']['id'] ?? '') === $timerId
        && findEntryIndex($deletedActivity['actionTimers'] ?? [], $timerId) < 0
        && findEntryIndex($deletedActivity['actionTimerTombstones'] ?? [], $timerId) >= 0,
    'The timer.delete route removes the owned timer and persists its tombstone.'
);
$timerActions = array_values(array_filter(
    $deletedActivity['playerActions'] ?? [],
    static fn (mixed $entry): bool => is_array($entry) && ($entry['kind'] ?? '') === 'timer'
));
requireTactical(
    count($timerActions) === 3
        && ($timerActions[0]['summary'] ?? '') === 'Supprime une action en recharge'
        && ($timerActions[1]['summary'] ?? '') === 'Réutilise une action en recharge'
        && ($timerActions[2]['summary'] ?? '') === 'Ajoute une action en recharge',
    'All three timer command routes emit their player activity entries.'
);

// Exercise both deletion authorities through the real dispatcher. The fixture
// also proves that a rejected command is rolled back before any domain changes.
$foreignDeletionDatabase = deletionFixture();
$foreignDeletionDomains = $foreignDeletionDatabase->domains;
$foreignDeletionRevision = $foreignDeletionDatabase->revision;
$foreignDeletion = runCommand(
    $foreignDeletionDatabase,
    'character.delete',
    ['characterId' => 'character-player'],
    false,
    'intruder'
);
requireTactical(
    $foreignDeletion->status === 409
        && ($foreignDeletion->body['code'] ?? '') === 'character_owner_changed'
        && $foreignDeletionDatabase->domains === $foreignDeletionDomains
        && $foreignDeletionDatabase->revision === $foreignDeletionRevision,
    'A player cannot delete another owner\'s character and the rejected transaction is fully rolled back.'
);

$gmSelfDeletionDatabase = deletionFixture();
$gmSelfDeletionDomains = $gmSelfDeletionDatabase->domains;
$gmSelfDeletionRevision = $gmSelfDeletionDatabase->revision;
$gmSelfDeletion = runCommand(
    $gmSelfDeletionDatabase,
    'character.delete',
    ['characterId' => 'character-player'],
    true,
    'account-gm'
);
requireTactical(
    $gmSelfDeletion->status === 403
        && ($gmSelfDeletion->body['code'] ?? '') === 'player_mode_required'
        && $gmSelfDeletionDatabase->domains === $gmSelfDeletionDomains
        && $gmSelfDeletionDatabase->revision === $gmSelfDeletionRevision,
    'The self-service deletion route is unavailable in GM mode and has no side effect.'
);

$playerDeletionDatabase = deletionFixture();
$playerDeletion = runCommand(
    $playerDeletionDatabase,
    'character.delete',
    ['characterId' => 'character-player']
);
$playerDeletionRoster = $playerDeletionDatabase->payload('roster');
$playerDeletionActivity = $playerDeletionDatabase->payload('activity');
requireTactical(
    $playerDeletion->status === 200
        && ($playerDeletion->body['character']['id'] ?? '') === 'character-player'
        && ($playerDeletion->body['character']['removedTokens'] ?? -1) === 3
        && ($playerDeletion->body['character']['removedTimers'] ?? -1) === 1
        && $playerDeletionDatabase->payload('character:character-player') === []
        && $playerDeletionDatabase->payload('token:scene-one:token-player') === []
        && $playerDeletionDatabase->payload('token:scene-two:token-copy') === []
        && $playerDeletionDatabase->payload('token:scene-one:token-independent') === []
        && !in_array('character-player', $playerDeletionRoster['characterOrder'] ?? [], true)
        && findEntryIndex($playerDeletionRoster['characterTombstones'] ?? [], 'character-player') >= 0
        && findEntryIndex($playerDeletionActivity['actionTimers'] ?? [], 'timer-character-delete') < 0
        && findEntryIndex($playerDeletionActivity['actionTimerTombstones'] ?? [], 'timer-character-delete') >= 0
        && !in_array('token-player', $playerDeletionDatabase->payload('token-index:scene-one')['order'] ?? [], true)
        && !in_array('token-copy', $playerDeletionDatabase->payload('token-index:scene-two')['order'] ?? [], true)
        && !in_array('token-player', $playerDeletionDatabase->payload('initiative:scene-one')['order'] ?? [], true),
    'A player deletion removes the owned sheet, every linked token and timer, then persists all tombstones and indexes.'
);
requireTactical(
    count(array_filter(
        $playerDeletionActivity['playerActions'] ?? [],
        static fn (mixed $entry): bool => is_array($entry)
            && ($entry['kind'] ?? '') === 'character'
            && ($entry['summary'] ?? '') === 'Supprime une fiche'
    )) === 1,
    'A successful player deletion is journaled exactly once.'
);

$adminMismatchDatabase = deletionFixture();
$adminMismatchDomains = $adminMismatchDatabase->domains;
$adminMismatchRevision = $adminMismatchDatabase->revision;
$adminMismatch = runCommand(
    $adminMismatchDatabase,
    'admin.character.delete',
    ['characterId' => 'character-player', 'ownerPlayerId' => 'intruder-owner'],
    true,
    'account-gm',
    true
);
requireTactical(
    $adminMismatch->status === 409
        && ($adminMismatch->body['code'] ?? '') === 'character_owner_changed'
        && $adminMismatchDatabase->domains === $adminMismatchDomains
        && $adminMismatchDatabase->revision === $adminMismatchRevision,
    'Administrative deletion validates the selected owner and rolls back an ownership mismatch.'
);

$adminDeletionDatabase = deletionFixture();
$adminDeletion = runCommand(
    $adminDeletionDatabase,
    'admin.character.delete',
    ['characterId' => 'character-player', 'ownerPlayerId' => 'account-player'],
    true,
    'account-gm',
    true
);
requireTactical(
    $adminDeletion->status === 200
        && ($adminDeletion->body['character']['ownerPlayerId'] ?? '') === 'account-player'
        && ($adminDeletion->body['character']['removedTokens'] ?? -1) === 3
        && ($adminDeletion->body['character']['removedTimers'] ?? -1) === 1
        && $adminDeletionDatabase->payload('character:character-player') === []
        && findEntryIndex($adminDeletionDatabase->payload('roster')['characterTombstones'] ?? [], 'character-player') >= 0
        && findEntryIndex($adminDeletionDatabase->payload('activity')['actionTimerTombstones'] ?? [], 'timer-character-delete') >= 0,
    'An authorized administrator can delete the selected owner\'s sheet with the same tombstone cascade.'
);

foreach ([[0,100,true,'down'],[-25,100,true,'down'],[-25.01,100,true,'dead'],[-26,100,true,'dead'],[0,100,false,'down'],[-1,100,false,'dead'],[9,100,true,'critical'],[10,100,true,'normal'],[0,0,true,'down'],[-1,0,true,'dead']] as [$hp,$max,$player,$code]) {
    requireTactical(onlineHealthState($hp,$max,$player)['code'] === $code, "Health boundary $hp/$max");
}
requireTactical(healthOverlayState(-26,100)['effect'] === 'Mort', 'The stream HP overlay must agree on player death.');
$healthProjection = healthOverlayProjection([
    'name' => 'Personnage public',
    'color' => '#22AA33',
    'resources' => ['hp' => 25, 'maxHp' => 100, 'mana' => 5, 'maxMana' => 20],
    'conditions' => ['Empoisonné'],
], ['revision' => 7, 'updated_at' => '2026-09-12T12:00:00Z']);
requireTactical(
    $healthProjection['name'] === 'Personnage public'
        && $healthProjection['color'] === '#22aa33'
        && $healthProjection['hp'] === 25.0
        && $healthProjection['maxHp'] === 100.0
        && $healthProjection['percentage'] === 25.0
        && $healthProjection['mana'] === 5.0
        && $healthProjection['maxMana'] === 20.0
        && $healthProjection['hasMana'] === true
        && $healthProjection['manaPercentage'] === 25.0
        && $healthProjection['revision'] === 7
        && !array_key_exists('conditions', $healthProjection),
    'The public stream projection exposes color, HP and optional mana without ordinary conditions.'
);
$healthProjectionWithoutMana = healthOverlayProjection([
    'name' => 'Sans mana',
    'color' => 'red;display:none',
    'resources' => ['hp' => 1, 'maxHp' => 10, 'mana' => 12, 'maxMana' => 0],
], []);
requireTactical(
    $healthProjectionWithoutMana['color'] === '#8d72cb'
        && $healthProjectionWithoutMana['hasMana'] === false
        && $healthProjectionWithoutMana['manaPercentage'] === 0.0,
    'The stream projection rejects unsafe colors and hides absent mana pools.'
);
$healthDatabase = fixture();
$healthSlug = str_repeat('h', 43);
$healthDatabase->healthOverlays[$healthSlug] = ['character_id' => 'character-player'];
try {
    healthOverlayJson($healthDatabase, $healthSlug, false);
    throw new RuntimeException('The public health route did not answer.');
} catch (TestResponse $healthResponse) {
    $routedHealth = $healthResponse->body['health'] ?? [];
    requireTactical(
        $healthResponse->status === 200
            && ($routedHealth['name'] ?? '') === 'Personnage'
            && ($routedHealth['color'] ?? '') === '#22aa33'
            && ($routedHealth['hp'] ?? null) === 10.0
            && ($routedHealth['mana'] ?? null) === 5.0
            && ($routedHealth['hasMana'] ?? false) === true,
        'The public health JSON route executes the enriched projection instead of only matching its source text.'
    );
}
requireTactical(normalizeOnlineConditions(['poison', 'Empoisonné', 'endormis', 'KO', 'Mort', 'Marque du voile']) === ['Empoisonné','Endormi','Marque du voile'], 'Canonical labels, no duplicate or ordinary health states.');
requireTactical(normalizeOnlineConditions([], 'Poison') === [], 'An explicit empty array does not resurrect the legacy field.');
requireTactical(onlineManualDeath(['conditions'=>['Mort']]) && !onlineManualDeath(['conditions'=>['Mort'],'healthOverride'=>null]), 'Explicit override clearing wins over legacy Mort.');
$patched = playerCharacterPatch(['conditions'=>['Mort'], 'resources'=>['hp'=>1,'maxHp'=>100,'mana'=>1,'maxMana'=>10]], ['conditions'=>['Poison'], 'healthOverride'=>null, 'resources'=>['hp'=>-26,'mana'=>-2]]);
requireTactical($patched['healthOverride'] === 'dead' && $patched['conditions'] === ['Empoisonné'], 'A player patch preserves the legacy MJ death override.');
requireTactical($patched['resources']['hp'] === -26 && $patched['resources']['mana'] === 0, 'Player patches preserve signed HP and nonnegative mana.');
// Legacy maps without fog must work through both movement visibility callers.
foreach (['absent'=>['gridSize'=>50], 'null'=>['gridSize'=>50,'fog'=>null]] as $fogCase=>$fogMap) {
    $vision = applicationComputeVisionMask(applicationActiveMapOcclusionState($fogMap), [], 50);
    requireTactical(applicationActiveMapFogState($fogMap) === null && is_array($vision)
        && onlineVisiblePathPointTester(null, $vision)(25.0, 50.0), $fogCase . ': no fog is distinct from the always-array computed vision.');
    foreach ([false,true] as $fogGm) {
        $db=fixture();$db->put('map:scene-one',$fogMap);$db->put('initiative:scene-one',['active'=>false]);
        $move=['sceneId'=>'scene-one','tokenId'=>'token-player','x'=>25,'y'=>50];
        if ($fogGm) $move['assisted']=true;
        $response=runCommand($db,'token.move',$move,$fogGm,$fogGm?'account-gm':'account-player');
        requireTactical($response->status===200 && ($response->body['blockedByWall']??true)===false
            && (float)$db->payload('token:scene-one:token-player')['x']===25.0,
            $fogCase . ': player and assisted MJ reach an open destination: ' . $response->getMessage());
    }
    $db=fixture();$db->put('map:scene-one',$fogMap);
    $character=$db->payload('character:character-player');$character['resources']['hp']=0;$db->put('character:character-player',$character);
    $response=runCommand($db,'token.attack',['sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','requestId'=>'no-fog-attack-'.$fogCase.'-0001']);
    requireTactical($response->status===409 && ($response->body['code']??'')==='attack_source_defeated',
        $fogCase . ': attack visibility accepts the absent fog and reaches the authoritative KO guard.');
}
$protectedFog=['version'=>1,'enabled'=>true,'width'=>32,'height'=>32,'mask'=>rtrim(strtr(base64_encode(str_repeat("\xff",128)),'+/','-_'),'=')];
requireTactical(!onlineVisiblePathPointTester([...$protectedFog,'mask'=>'!invalid!'],['enabled'=>false])(25.0,50.0), 'Enabled malformed fog still fails closed.');
requireTactical(!onlineVisiblePathPointTester(null,$protectedFog)(25.0,50.0), 'Absent fog does not disable active vision.');
foreach (['active fog'=>['fog'=>$protectedFog], 'new upper floor'=>['activeLayerId'=>'upper','layers'=>['ground'=>['fog'=>$protectedFog],'upper'=>[]]]] as $fogCase=>$fogMap) {
    $db=fixture();$db->put('map:scene-one',$fogMap);$db->put('initiative:scene-one',['active'=>false]);
    foreach (['token-player','token-monster'] as $fogTokenId) {
        $fogToken=$db->payload('token:scene-one:'.$fogTokenId);$fogToken['layerId']=$fogMap['activeLayerId']??'ground';$db->put('token:scene-one:'.$fogTokenId,$fogToken);
    }
    $before=$db->domains;$beforeRevision=$db->revision;
    foreach ([false,true] as $fogGm) {
        $response=runCommand($db,'token.move',['sceneId'=>'scene-one','tokenId'=>'token-player','x'=>25,'y'=>50,'assisted'=>true],$fogGm,$fogGm?'account-gm':'account-player');
        requireTactical($response->status===200 && ($response->body['blockedByWall']??false)===true
            && ($response->body['positionChanged']??true)===false && $db->domains===$before && $db->revision===$beforeRevision,
            $fogCase . ': protected destinations remain inaccessible to player and assisted MJ.');
    }
    $response=runCommand($db,'token.attack',['sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','requestId'=>'hidden-fog-attack-0001']);
    requireTactical($response->status===403 && ($response->body['code']??'')==='attack_target_hidden' && $db->domains===$before,
        $fogCase . ': a concealed target cannot be attacked.');
}
requireTactical(onlineDiceAppearance(['frameVariant'=>'boss']) === ['color'=>'#000000','foreground'=>'#ffffff'], 'A boss has black dice with white digits.');
requireTactical(onlineDiceAppearance(['frameVariant'=>'boss','color'=>'#ff0000'], true, ['color'=>'#ffffff']) === ['color'=>'#ffffff','foreground'=>'#000000'], 'Player ownership wins over frame and uses character colour.');
requireTactical(array_keys(publicOnlineDiceAppearance(['color'=>'#ffffff','foreground'=>'#000000','secret'=>'do-not-project'])) === ['color','foreground'], 'Dice projection is a strict whitelist.');

$db = fixture();
$records = applicationDomainRecords($db);
$pending = [];
$db->beginTransaction();
$playerIdentity = ['id'=>'account-player','display_name'=>'Joueur test','effective_mode'=>'player','permanent_role'=>'player'];
$gmIdentity = ['id'=>'account-gm','display_name'=>'MJ test','effective_mode'=>'gm','permanent_role'=>'gm'];
requireTactical(
    onlineRecordCharacterLuckD100($db, $records, $pending, $playerIdentity, 'character-player', ['rawD100'=>42,'total'=>62]),
    'A player d100 must enter the authoritative luck aggregate.'
);
requireTactical(
    !onlineRecordCharacterLuckD100($db, $records, $pending, $playerIdentity, 'character-player', ['rawD100'=>91,'total'=>91], true)
        && !onlineRecordCharacterLuckD100($db, $records, $pending, $gmIdentity, 'character-player', ['rawD100'=>7,'total'=>7])
        && !onlineRecordCharacterLuckD100($db, $records, $pending, $playerIdentity, 'character-player', ['rawD100'=>null,'total'=>84]),
    'Damage d100, GM rolls and formulas without one selected d100 must stay excluded.'
);
requireTactical(
    onlineRecordCharacterLuckD100($db, $records, $pending, $playerIdentity, 'character-player', [
        'rawD100'=>18,
        'total'=>38,
        'rollMode'=>'advantage',
        'selectedIndex'=>1,
        'attempts'=>[['rawD100'=>73],['rawD100'=>18]],
    ]),
    'An advantage action records only its selected d100.'
);
$luckRecord = $pending['luck']['payload']['characters']['character-player'] ?? [];
requireTactical(
    ($luckRecord['rollCount'] ?? null) === 2 && ($luckRecord['rawTotal'] ?? null) === 60,
    'The luck aggregate must count selected raw faces without modifiers.'
);
$db->rollBack();

$db = fixture();
$character = $db->payload('character:character-player'); $character['ownerPlayerId'] = null; $db->put('character:character-player',$character);
$response = runCommand($db,'token.conditions.update',['sceneId'=>'scene-one','tokenId'=>'token-player','condition'=>'Poison','active'=>true]);
requireTactical($response->status === 403, 'Removing the sheet owner revokes a stale token controller immediately.');
$index = onlineCharacterOwnerIndex([$character]);
requireTactical(onlineEffectiveTokenControllerId($db->payload('token:scene-one:token-player'), $index) === '', 'An explicitly unassigned sheet overrides the historical controller.');
$view = publicPlayerState(['characters'=>[$character],'activeSceneId'=>'scene-one','map'=>['tokens'=>[$db->payload('token:scene-one:token-player')]],'initiative'=>[]],['id'=>'account-player','display_name'=>'Player'],[]);
requireTactical(!$view['map']['tokens'][0]['ownedByYou'] && !$view['map']['tokens'][0]['playerControlled'], 'Projection uses the new unassigned authority, including health classification.');

$db = fixture();
$effect = ['sceneId'=>'scene-one','tokenId'=>'token-player','condition'=>'poison','active'=>true];
$response = runCommand($db, 'token.conditions.update', $effect);
requireTactical($response->status === 200 && $response->body['changed'] === true, 'Owner can add an effect: ' . $response->getMessage());
requireTactical($db->payload('character:character-player')['conditions'] === ['Empoisonné'] && $db->payload('token:scene-two:token-copy')['conditions'] === ['Empoisonné'], 'The sheet and every following token converge.');
requireTactical($db->payload('token:scene-one:token-independent')['conditions'] === ['Endormi'], 'An independent linked token keeps its effects.');
$revision = $db->revision;
$response = runCommand($db, 'token.conditions.update', $effect);
requireTactical($response->status === 200 && !$response->body['changed'] && $db->revision === $revision && count($db->payload('activity')['playerActions']) === 1, 'A repeated set neither mutates nor logs twice.');
foreach ([['active'=>'false'], ['condition'=>'Mort'], ['condition'=>'KO'], ['condition'=>str_repeat('x',241)]] as $invalid) requireTactical(runCommand($db, 'token.conditions.update', [...$effect,...$invalid])->status === 400, 'Invalid effect input rejected.');
requireTactical(runCommand($db, 'token.conditions.update', $effect, false, 'intruder')->status === 403, 'Foreign control rejected.');
requireTactical(runCommand($db, 'token.conditions.update', [...$effect,'sceneId'=>'scene-two'])->status === 409, 'Stale scene rejected.');
$response = runCommand($db,'token.conditions.update',[...$effect,'active'=>false]);
requireTactical($response->status === 200 && $db->payload('character:character-player')['conditions'] === [] && count($db->payload('activity')['playerActions']) === 2, 'Removal updates the sheet and writes one audit.');
requireTactical(runCommand($db,'token.conditions.update',['sceneId'=>'scene-one','tokenId'=>'token-monster','condition'=>'petrified','active'=>true], true, 'account-gm')->status === 200, 'MJ can edit a standalone creature.');
$db = fixture();
$response = runCommand($db, 'character.conditions.update', ['characterId'=>'character-player','condition'=>'Enflammé','active'=>true], true, 'account-gm');
requireTactical($response->status === 200, 'MJ can update conditions directly from a sheet.');
$response = runCommand($db, 'character.patch', ['characterId'=>'character-player','patch'=>['conditions'=>['Empoisonné']]]);
requireTactical($response->status === 409 && $db->payload('character:character-player')['conditions'] === ['Enflammé'], 'A stale whole-array autosave cannot erase a concurrent MJ effect.');
$response = runCommand($db, 'character.patch', ['characterId'=>'character-player','patch'=>['conditions'=>['Empoisonné'],'conditionsBase'=>[]]]);
requireTactical($response->status === 200 && $db->payload('character:character-player')['conditions'] === ['Enflammé','Empoisonné'], 'Three-way merge preserves the independent MJ addition.');
runCommand($db, 'character.conditions.update', ['characterId'=>'character-player','condition'=>'Enflammé','active'=>false], true, 'account-gm');
$response = runCommand($db, 'character.patch', ['characterId'=>'character-player','patch'=>['conditions'=>['Enflammé','Empoisonné'],'conditionsBase'=>['Enflammé','Empoisonné']]]);
requireTactical($response->status === 200 && $db->payload('character:character-player')['conditions'] === ['Empoisonné'], 'An unchanged stale effect cannot resurrect a concurrent deletion.');
$db = fixture();
unset($db->domains['token:scene-one:token-player'], $db->domains['token:scene-two:token-copy']);
$response = runCommand($db, 'character.conditions.update', ['characterId'=>'character-player','condition'=>'Malédiction, niveau 2','active'=>true]);
requireTactical($response->status === 200 && $db->payload('character:character-player')['conditions'] === ['Malédiction, niveau 2'], 'A player can add a custom label containing punctuation without a token.');
requireTactical(runCommand($db,'character.conditions.update',['characterId'=>'character-player','condition'=>'KO','active'=>true])->status===400, 'Sheet command cannot modify a reserved health state.');
requireTactical(runCommand($db,'character.conditions.update',['characterId'=>'character-player','condition'=>'Poison','active'=>true],false,'intruder')->status===403, 'Sheet effects remain owner-only.');
$db = fixture();
$response = runCommand($db, 'character.conditions.update', ['characterId'=>'character-player','condition'=>str_repeat('é',240),'active'=>true]);
requireTactical($response->status===200 && $db->payload('character:character-player')['conditions']===[str_repeat('é',240)] && preg_match('//u',$db->payload('activity')['playerActions'][0]['summary'])===1, 'A long accented custom effect remains intact and cannot corrupt the JSON audit.');

$db = fixture();
$character = $db->payload('character:character-player'); $character['resources']['hp']=0; $db->put('character:character-player',$character);
$response=runCommand($db,'token.attack',['sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','requestId'=>'attack-request-test01']);
requireTactical($response->status===409 && ($response->body['code'] ?? '')==='attack_source_defeated', 'An authoritative KO sheet prevents attacking even if the stored token still has positive HP.');
$db = fixture();
$character=$db->payload('character:character-player');$character['weaponText']='1d6';$character['weaponAttacks']=[['id'=>'weapon-1','formula'=>'1d6','damageType'=>'ignore']];$db->put('character:character-player',$character);
$monster=$db->payload('token:scene-one:token-monster');$monster['hp']=0;$db->put('token:scene-one:token-monster',$monster);
$response=runCommand($db,'token.attack',['sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','requestId'=>'attack-request-test02','attackKind'=>'weapon','attackId'=>'weapon-1','statId'=>'character-stat-force','opposed'=>true]);
requireTactical($response->status===200 && in_array($response->body['attack']['status'] ?? '',['missed','applied','pending'],true), 'A KO creature can be attacked, with no opposition regardless of the actual attack roll: '.$response->getMessage());

$db = fixture();
$character = $db->payload('character:character-player');
$character['weaponText'] = '1d6';
$character['weaponAttacks'] = [['id' => 'weapon-1', 'formula' => '1d6', 'damageType' => 'ignore']];
$db->put('character:character-player', $character);
$response = runCommand($db, 'token.attack', [
    'sourceTokenId' => 'token-player', 'targetTokenId' => 'token-monster',
    'requestId' => 'gm-player-source-0001', 'attackKind' => 'weapon', 'attackId' => 'weapon-1',
    'statId' => 'character-stat-force',
], true, 'account-gm');
requireTactical($response->status === 200 && ($response->body['attack']['sourceTokenId'] ?? '') === 'token-player', 'The GM can attack with a player token instead of the principal creature: ' . $response->getMessage());

$db = fixture();
$response = runCommand($db, 'token.attack', [
    'sourceTokenId' => 'token-monster', 'targetTokenId' => 'token-monster-two',
    'requestId' => 'gm-creature-source-01', 'attackKind' => 'weapon', 'attackId' => 'monster-claw',
    'statId' => 'monster-force', 'rollMode' => 'advantage',
], true, 'account-gm');
requireTactical($response->status === 200 && ($response->body['attack']['targetTokenId'] ?? '') === 'token-monster-two', 'The GM can target another creature with a creature: ' . $response->getMessage());
$privateGmAttack = $response->body['attack'];
requireTactical(
    ($privateGmAttack['hit']['statId'] ?? '') === 'monster-force'
        && ($privateGmAttack['hit']['statLabel'] ?? '') === 'Force'
        && array_key_exists('baseThreshold', $privateGmAttack['hit']['outcome'] ?? [])
        && array_key_exists('threshold', $privateGmAttack['hit']['outcome'] ?? [])
        && count($privateGmAttack['hit']['attempts'] ?? []) === 2,
    'The authoritative GM response retains the private creature statistic and full calculation.'
);
$privateGmAttack['status'] = 'awaiting-opposition';
$privateGmAttack['targetTokenId'] = 'token-player';
$privateGmAttack['targetName'] = 'Personnage';
$activity = $db->payload('activity');
$projectionState = [
    'characters' => [$db->payload('character:character-player')],
    'activeSceneId' => 'scene-one',
    'activeScene' => ['id' => 'scene-one', 'name' => 'Scène'],
    'map' => [
        'gridSize' => 50,
        'tokens' => [
            $db->payload('token:scene-one:token-player'),
            $db->payload('token:scene-one:token-monster'),
            $db->payload('token:scene-one:token-monster-two'),
        ],
    ],
    'initiative' => ['active' => false, 'order' => []],
    'rolls' => $activity['rolls'] ?? [],
    'pendingAttacks' => [$privateGmAttack],
];
$privateProjection = publicPlayerState($projectionState, ['id' => 'account-player', 'display_name' => 'Joueur'], []);
$privateMapAttack = $privateProjection['pendingMapAttacks'][0] ?? [];
$privateOpposition = $privateProjection['pendingOppositions'][0] ?? [];
$privateProjectedRoll = null;
foreach ($privateProjection['rolls'] ?? [] as $projectedRoll) {
    if (($projectedRoll['id'] ?? '') === ($privateGmAttack['hit']['rollId'] ?? '')) {
        $privateProjectedRoll = $projectedRoll;
        break;
    }
}
foreach ([$privateMapAttack['hit'] ?? [], $privateOpposition['hit'] ?? [], $privateProjectedRoll ?? []] as $publicHit) {
    requireTactical(
        ($publicHit['label'] ?? '') === 'Jet ATK'
            && ($publicHit['formula'] ?? '') === '1d100'
            && count($publicHit['attempts'] ?? []) === 2
            && isset($publicHit['outcome']['raw'])
            && !array_key_exists('statId', $publicHit)
            && !array_key_exists('statLabel', $publicHit)
            && !array_key_exists('baseThreshold', $publicHit['outcome'] ?? [])
            && !array_key_exists('threshold', $publicHit['outcome'] ?? []),
        'Player/public attack projections retain dice and outcome but redact every private creature statistic and threshold.'
    );
}
$projectionState['map']['tokens'][1]['revealDetailsToPlayers'] = true;
$sharedProjection = publicPlayerState($projectionState, ['id' => 'account-player', 'display_name' => 'Joueur'], []);
$sharedHit = $sharedProjection['pendingMapAttacks'][0]['hit'] ?? [];
requireTactical(
    ($sharedHit['statId'] ?? '') === 'monster-force'
        && ($sharedHit['statLabel'] ?? '') === 'Force'
        && array_key_exists('baseThreshold', $sharedHit['outcome'] ?? [])
        && array_key_exists('threshold', $sharedHit['outcome'] ?? []),
    'An explicit GM detail reveal alone restores the creature statistic in public attack projections.'
);

$db = fixture();
$character = $db->payload('character:character-player');
$character['name'] = 'Nom autoritatif';
$character['stats'] = ['force' => 64];
$db->put('character:character-player', $character);
$staleToken = $db->payload('token:scene-one:token-player');
$staleToken['name'] = 'Ancien nom';
$staleToken['stats'] = [['id' => 'force', 'label' => 'Ancienne Force', 'value' => 1]];
$db->put('token:scene-one:token-player', $staleToken);
$playerTokenRollPayload = [
    'requestId' => 'player-token-roll-request-0001',
    'sceneId' => 'scene-one', 'tokenId' => 'token-player', 'kind' => 'stat', 'statId' => 'character-stat-force',
    'layerId' => 'ground', 'rollMode' => 'advantage', 'modifier' => 0, 'modifierMode' => 'result',
];
$response = runCommand($db, 'token.roll', $playerTokenRollPayload);
requireTactical(
    $response->status === 200
        && ($response->body['roll']['characterName'] ?? '') === 'Nom autoritatif'
        && ($response->body['roll']['label'] ?? '') === 'Force'
        && ($response->body['roll']['outcome']['threshold'] ?? null) === 64
        && ($response->body['roll']['outcome']['resultCustomized'] ?? false) === true
        && count($response->body['roll']['attempts'] ?? []) === 2,
    'A Player token roll must resynchronize its authoritative sheet and preserve a zero custom-result choice'
);
$playerTokenRollRevision = $db->revision;
$playerTokenRollActivity = $db->payload('activity');
$playerTokenRollLuck = $db->payload('luck');
$changedCharacter = $db->payload('character:character-player');
$changedCharacter['name'] = 'Nom modifié après le jet';
$changedCharacter['stats'] = ['force' => 1];
$db->put('character:character-player', $changedCharacter);
$playerTokenRollRetry = runCommand($db, 'token.roll', $playerTokenRollPayload);
requireTactical(
    $playerTokenRollRetry->status === 200
        && ($playerTokenRollRetry->body['deduplicated'] ?? false) === true
        && ($playerTokenRollRetry->body['roll']['id'] ?? '') === ($response->body['roll']['id'] ?? null)
        && ($playerTokenRollRetry->body['roll']['outcome']['threshold'] ?? null) === 64
        && !array_key_exists('discordPosted', $playerTokenRollRetry->body)
        && $db->revision === $playerTokenRollRevision
        && $db->payload('activity') === $playerTokenRollActivity
        && $db->payload('luck') === $playerTokenRollLuck,
    'A token.roll retry restores the initial authority result before re-reading a concurrently edited sheet.'
);
$playerTokenRollMismatch = runCommand($db, 'token.roll', [...$playerTokenRollPayload, 'modifier' => 1]);
requireTactical(
    $playerTokenRollMismatch->status === 409
        && ($playerTokenRollMismatch->body['code'] ?? '') === 'token_roll_request_mismatch'
        && $db->revision === $playerTokenRollRevision,
    'A token.roll request id cannot be reused with another modifier.'
);
$legacyTokenRollDatabase = fixture();
$legacyTokenRoll = runCommand($legacyTokenRollDatabase, 'token.roll', [
    'sceneId' => 'scene-one', 'tokenId' => 'token-player', 'kind' => 'stat',
    'statId' => 'character-stat-force', 'rollMode' => 'normal',
]);
requireTactical(
    $legacyTokenRoll->status === 200
        && !array_key_exists('deduplicated', $legacyTokenRoll->body)
        && ($legacyTokenRollDatabase->payload('activity')['resourceReceipts'] ?? []) === [],
    'A legacy token.roll without requestId remains accepted during the compatibility window.'
);
$initiativeRollDatabase = fixture();
$initiativeRollPayload = [
    'requestId' => 'player-initiative-roll-0001', 'sceneId' => 'scene-one', 'layerId' => 'ground',
    'tokenId' => 'token-player', 'kind' => 'initiative', 'rollMode' => 'advantage',
];
$initiativeRoll = runCommand($initiativeRollDatabase, 'token.roll', $initiativeRollPayload);
$initiativeRollRevision = $initiativeRollDatabase->revision;
$initiativeRollToken = $initiativeRollDatabase->payload('token:scene-one:token-player');
$initiativeRollState = $initiativeRollDatabase->payload('initiative:scene-one');
$initiativeRollActivity = $initiativeRollDatabase->payload('activity');
$initiativeRollLuck = $initiativeRollDatabase->payload('luck');
$initiativeRollRetry = runCommand($initiativeRollDatabase, 'token.roll', $initiativeRollPayload);
requireTactical(
    $initiativeRoll->status === 200
        && ($initiativeRoll->body['initiativeUpdated'] ?? false) === true
        && $initiativeRollRetry->status === 200
        && ($initiativeRollRetry->body['deduplicated'] ?? false) === true
        && ($initiativeRollRetry->body['roll']['id'] ?? '') === ($initiativeRoll->body['roll']['id'] ?? null)
        && $initiativeRollDatabase->revision === $initiativeRollRevision
        && $initiativeRollDatabase->payload('token:scene-one:token-player') === $initiativeRollToken
        && $initiativeRollDatabase->payload('initiative:scene-one') === $initiativeRollState
        && $initiativeRollDatabase->payload('activity') === $initiativeRollActivity
        && $initiativeRollDatabase->payload('luck') === $initiativeRollLuck,
    'A token initiative retry neither rerolls nor reorders initiative, luck or the journal.'
);
$gmPayload = [
    'sceneId' => 'scene-one', 'tokenId' => 'token-monster', 'layerId' => 'ground',
    'kind' => 'stat', 'statId' => 'monster-force', 'rollMode' => 'advantage',
    'modifier' => 0, 'modifierMode' => 'result', 'requestId' => 'gm-role-parity-roll-0001',
];
$missingSceneResponse = runCommand(fixture(), 'token.roll', $gmPayload, true, 'account-gm');
requireTactical(
    $missingSceneResponse->status === 409 && ($missingSceneResponse->body['code'] ?? '') === 'stale_scene',
    'The GM tactical route must reject a table pointer whose scene domain no longer exists'
);
$gmDatabase = fixture();
$gmDatabase->put('scene:scene-one', ['id' => 'scene-one', 'name' => 'Scène test']);
$gmResponse = runCommand($gmDatabase, 'token.roll', $gmPayload, true, 'account-gm');
requireTactical(
    $gmResponse->status === 200
        && ($gmResponse->body['roll']['label'] ?? '') === 'Force'
        && ($gmResponse->body['roll']['formula'] ?? '') === '1d100'
        && ($gmResponse->body['roll']['rollMode'] ?? '') === 'advantage'
        && ($gmResponse->body['roll']['outcome']['resultCustomized'] ?? false) === true
        && count($gmResponse->body['roll']['attempts'] ?? []) === 2,
    'The GM tactical route preserves the same canonical mode and customized-result fields'
);
$gmRollRevision = $gmDatabase->revision;
$gmRollActivity = $gmDatabase->payload('activity');
$gmRetry = runCommand($gmDatabase, 'token.roll', $gmPayload, true, 'account-gm');
requireTactical(
    $gmRetry->status === 200
        && ($gmRetry->body['deduplicated'] ?? false) === true
        && ($gmRetry->body['roll']['id'] ?? '') === ($gmResponse->body['roll']['id'] ?? null)
        && $gmDatabase->revision === $gmRollRevision
        && $gmDatabase->payload('activity') === $gmRollActivity,
    'An identical GM tactical retry restores the original roll without a second journal entry.'
);
$gmMismatch = runCommand(
    $gmDatabase,
    'token.roll',
    [...$gmPayload, 'modifier' => 1],
    true,
    'account-gm'
);
requireTactical(
    $gmMismatch->status === 409
        && ($gmMismatch->body['code'] ?? '') === 'tactical_roll_request_mismatch'
        && $gmDatabase->revision === $gmRollRevision
        && $gmDatabase->payload('activity') === $gmRollActivity,
    'A GM tactical request id cannot be reused with another modifier or mutate the journal.'
);
$gmPlayerDatabase = fixture();
$gmPlayerDatabase->put('scene:scene-one', ['id' => 'scene-one', 'name' => 'Scène test']);
$gmPlayerResponse = runCommand($gmPlayerDatabase, 'token.roll', [
    'sceneId' => 'scene-one', 'tokenId' => 'token-player', 'layerId' => 'ground',
    'kind' => 'stat', 'statId' => 'character-stat-force', 'rollMode' => 'advantage',
    'modifier' => 0, 'modifierMode' => 'result', 'requestId' => 'gm-player-token-roll-0001',
], true, 'account-gm');
$gmPlayerRoll = $gmPlayerResponse->body['roll'] ?? [];
$gmPlayerAction = $gmPlayerDatabase->payload('activity')['playerActions'][0] ?? [];
requireTactical(
    $gmPlayerResponse->status === 200
        && ($gmPlayerRoll['characterName'] ?? '') === 'Personnage'
        && ($gmPlayerRoll['label'] ?? '') === 'Force'
        && ($gmPlayerRoll['formula'] ?? '') === '1d100'
        && ($gmPlayerRoll['rollMode'] ?? '') === 'advantage'
        && ($gmPlayerRoll['rollerRole'] ?? '') === 'gm'
        && ($gmPlayerRoll['visibility'] ?? '') === 'public'
        && ($gmPlayerRoll['revealed'] ?? false) === true
        && ($gmPlayerRoll['outcome']['resultCustomized'] ?? false) === true
        && count($gmPlayerRoll['attempts'] ?? []) === 2,
    'A GM rolling the same Player token receives the complete canonical roll without role-dependent vocabulary'
);
requireTactical(
    ($gmPlayerAction['kind'] ?? '') === 'roll'
        && ($gmPlayerAction['characterName'] ?? '') === 'Personnage'
        && ($gmPlayerAction['summary'] ?? '') === 'Force (Avantage)'
        && substr_count((string) ($gmPlayerAction['detail'] ?? ''), "\n") === 2
        && str_contains((string) ($gmPlayerAction['detail'] ?? ''), '(jet ignoré)')
        && !str_contains((string) ($gmPlayerAction['summary'] ?? ''), 'Lance ')
        && !str_contains((string) ($gmPlayerAction['detail'] ?? ''), 'Nom :'),
    'The GM journal stores character, type, both calculations and outcome with the same role-independent wording'
);
$shortcutDatabase = fixture();
$shortcutCharacter = $shortcutDatabase->payload('character:character-player');
$shortcutCharacter['shortcuts'] = [[
    'id' => 'shortcut-perception', 'label' => 'Perception', 'kind' => 'roll', 'formula' => '1d100+15',
]];
$shortcutDatabase->put('character:character-player', $shortcutCharacter);
$shortcutPayload = [
    'requestId' => 'shortcut-roll-request-0001', 'sceneId' => 'scene-one',
    'characterId' => 'character-player', 'shortcutId' => 'shortcut-perception', 'rollMode' => 'advantage',
];
$shortcutResponse = runCommand($shortcutDatabase, 'roll', $shortcutPayload);
$shortcutRoll = $shortcutResponse->body['roll'] ?? [];
$shortcutAction = $shortcutDatabase->payload('activity')['playerActions'][0] ?? [];
requireTactical(
    $shortcutResponse->status === 200
        && ($shortcutRoll['characterName'] ?? '') === 'Personnage'
        && ($shortcutRoll['label'] ?? '') === 'Perception'
        && ($shortcutRoll['formula'] ?? '') === '1d100+15'
        && ($shortcutRoll['rollMode'] ?? '') === 'advantage'
        && count($shortcutRoll['attempts'] ?? []) === 2
        && ($shortcutAction['summary'] ?? '') === 'Perception (Avantage)'
        && substr_count((string) ($shortcutAction['detail'] ?? ''), '1d100+15 : ') === 2
        && str_contains((string) ($shortcutAction['detail'] ?? ''), '(jet ignoré)')
        && !str_contains((string) ($shortcutAction['detail'] ?? ''), 'Nom :'),
    'The direct Player shortcut route executes the same two-attempt presentation instead of only matching source text'
);
$shortcutRevision = $shortcutDatabase->revision;
$shortcutActivity = $shortcutDatabase->payload('activity');
$shortcutLuck = $shortcutDatabase->payload('luck');
$changedShortcutCharacter = $shortcutDatabase->payload('character:character-player');
$changedShortcutCharacter['shortcuts'][0]['formula'] = '1d100-40';
$shortcutDatabase->put('character:character-player', $changedShortcutCharacter);
$shortcutRetry = runCommand($shortcutDatabase, 'roll', $shortcutPayload);
requireTactical(
    $shortcutRetry->status === 200
        && ($shortcutRetry->body['deduplicated'] ?? false) === true
        && ($shortcutRetry->body['roll']['id'] ?? '') === ($shortcutRoll['id'] ?? null)
        && ($shortcutRetry->body['roll']['formula'] ?? '') === '1d100+15'
        && !array_key_exists('discordPosted', $shortcutRetry->body)
        && $shortcutDatabase->revision === $shortcutRevision
        && $shortcutDatabase->payload('activity') === $shortcutActivity
        && $shortcutDatabase->payload('luck') === $shortcutLuck,
    'A shortcut retry restores its first roll before re-reading a concurrently edited shortcut.'
);
$shortcutMismatch = runCommand($shortcutDatabase, 'roll', [...$shortcutPayload, 'rollMode' => 'disadvantage']);
requireTactical(
    $shortcutMismatch->status === 409
        && ($shortcutMismatch->body['code'] ?? '') === 'shortcut_roll_request_mismatch'
        && $shortcutDatabase->revision === $shortcutRevision,
    'A shortcut request id cannot be reused with another roll mode.'
);
$legacyShortcutDatabase = fixture();
$legacyShortcutCharacter = $legacyShortcutDatabase->payload('character:character-player');
$legacyShortcutCharacter['shortcuts'] = [[
    'id' => 'shortcut-legacy', 'label' => 'Ancien raccourci', 'kind' => 'roll', 'formula' => '1d100',
]];
$legacyShortcutDatabase->put('character:character-player', $legacyShortcutCharacter);
$legacyShortcutRoll = runCommand($legacyShortcutDatabase, 'roll', [
    'characterId' => 'character-player', 'shortcutId' => 'shortcut-legacy', 'rollMode' => 'normal',
]);
requireTactical(
    $legacyShortcutRoll->status === 200
        && !array_key_exists('deduplicated', $legacyShortcutRoll->body)
        && ($legacyShortcutDatabase->payload('activity')['resourceReceipts'] ?? []) === [],
    'A legacy shortcut roll without requestId remains accepted during the compatibility window.'
);

$db = fixture();
$response = runCommand($db, 'token.attack', [
    'sourceTokenId' => 'token-monster', 'targetTokenId' => 'token-monster-two',
    'requestId' => 'attack-attempts-0001', 'attackKind' => 'weapon', 'attackId' => 'monster-claw',
    'statId' => 'monster-force', 'rollMode' => 'advantage',
], true, 'account-gm');
$hit = $response->body['attack']['hit'] ?? [];
requireTactical(
    $response->status === 200
        && ($hit['rollMode'] ?? '') === 'advantage'
        && is_int($hit['selectedIndex'] ?? null)
        && count($hit['attempts'] ?? []) === 2
        && str_contains(onlineAttackHistoryDetail($response->body['attack']), '(Avantage)')
        && str_contains(onlineAttackHistoryDetail($response->body['attack']), '(jet ignoré)')
        && str_contains(onlineAttackDiscordContent($response->body['attack']), '(jet ignoré)'),
    'Attack history and Discord retain both advantage attempts and identify the ignored one'
);
$attackRevision = $db->revision;
$mismatchedAttack = runCommand($db, 'token.attack', [
    'sourceTokenId' => 'token-monster', 'targetTokenId' => 'token-player',
    'requestId' => 'attack-attempts-0001', 'attackKind' => 'weapon', 'attackId' => 'monster-claw',
    'statId' => 'monster-force', 'rollMode' => 'advantage',
], true, 'account-gm');
$storedAttackReceipt = current(array_values(array_filter(
    $db->payload('activity')['attackReceipts'] ?? [],
    static fn (mixed $entry): bool => is_array($entry) && ($entry['requestId'] ?? '') === 'attack-attempts-0001'
)));
requireTactical(
    $mismatchedAttack->status === 409
        && ($mismatchedAttack->body['code'] ?? '') === 'attack_request_mismatch'
        && $db->revision === $attackRevision
        && is_string($storedAttackReceipt['requestSignature'] ?? null),
    'Reusing an attack request id with another target must be rejected without a second mutation'
);

$mixedAttackSample = null;
for ($attempt = 0; $attempt < 200 && $mixedAttackSample === null; $attempt += 1) {
    $candidate = fixture();
    $payload = [
        'sourceTokenId' => 'token-monster', 'targetTokenId' => 'token-monster-two',
        'requestId' => 'mixed-damage-attack-01', 'attackKind' => 'custom',
        'customAttack' => [
            'name' => 'Griffe mixte', 'customStat' => true, 'threshold' => 100, 'statLabel' => 'Force',
            'damageComponents' => [
                ['type' => 'physical', 'formula' => '1'],
                ['type' => 'magical', 'formula' => '2'],
            ],
        ],
        'rollMode' => 'advantage', 'opposed' => false,
    ];
    $candidateResponse = runCommand($candidate, 'token.attack', $payload, true, 'account-gm');
    if ($candidateResponse->status === 200 && ($candidateResponse->body['attack']['status'] ?? '') === 'applied') {
        $mixedAttackSample = [$candidate, $candidateResponse, $payload];
    }
}
requireTactical(is_array($mixedAttackSample), 'A mixed advantage attack must reach an immediately applied ordinary success');
[$db, $response, $payload] = $mixedAttackSample;
$mixedAttack = $response->body['attack'];
$mixedRollIds = array_column($response->body['rolls'] ?? [], 'id');
$mixedActivity = $db->payload('activity');
$mixedDamageRoll = $response->body['damageRoll'] ?? [];
requireTactical(
    count($mixedRollIds) === 2
        && $mixedRollIds === [($mixedAttack['hitRoll']['id'] ?? ''), ($mixedAttack['damageRoll']['id'] ?? '')]
        && array_column($mixedActivity['rolls'] ?? [], 'id') === $mixedRollIds
        && count(array_unique($mixedRollIds)) === 2,
    'Immediate attack response, receipt journal and activity retain hit then damage exactly once'
);
requireTactical(
    ($mixedDamageRoll['rollMode'] ?? '') === 'advantage'
        && is_int($mixedDamageRoll['selectedIndex'] ?? null)
        && count($mixedDamageRoll['attempts'] ?? []) === 2
        && ($mixedAttack['damage']['rollMode'] ?? '') === 'advantage'
        && ($mixedAttack['damage']['selectedIndex'] ?? null) === ($mixedDamageRoll['selectedIndex'] ?? null)
        && ($mixedAttack['damage']['attempts'] ?? null) === ($mixedDamageRoll['attempts'] ?? null)
        && count($mixedAttack['damage']['components'] ?? []) === 2,
    'Mixed damage preserves its two global attempts, selected index and retained component calculation in the attack receipt'
);
$mixedHistory = onlineAttackHistoryDetail($mixedAttack);
requireTactical(
    str_contains($mixedHistory, 'Jet DMG (Avantage)')
        && substr_count($mixedHistory, '1+2 : 3') === 2
        && str_contains($mixedHistory, '(jet ignoré)'),
    'The GM attack journal exposes both complete damage attempts and identifies the ignored attempt'
);
$publicDamageRoll = publicOnlineAttackRoll($mixedAttack['damageRoll'], false, 'damage');
requireTactical(
    ($publicDamageRoll['total'] ?? null) === ($mixedAttack['appliedDamage'] ?? null)
        && ($publicDamageRoll['breakdown'] ?? '') === ($mixedAttack['appliedDamage'] ?? 0) . ' PV perdus'
        && !isset($publicDamageRoll['attempts'])
        && !str_contains(onlineAttackDiscordContent($mixedAttack), '1+2'),
    'The player projection and Discord expose only final applied HP loss, never raw damage or armor inference'
);
$mixedRevision = $db->revision;
$mixedRollCount = count($mixedActivity['rolls'] ?? []);
$mixedActionCount = count($mixedActivity['playerActions'] ?? []);
$mixedRetry = runCommand($db, 'token.attack', $payload, true, 'account-gm');
requireTactical(
    $mixedRetry->status === 200
        && ($mixedRetry->body['deduplicated'] ?? false) === true
        && array_column($mixedRetry->body['rolls'] ?? [], 'id') === $mixedRollIds
        && $db->revision === $mixedRevision
        && count($db->payload('activity')['rolls'] ?? []) === $mixedRollCount
        && count($db->payload('activity')['playerActions'] ?? []) === $mixedActionCount,
    'Retrying an applied mixed attack returns the immutable hit and damage bundle without reroll, journal or HP mutation'
);

$db = fixture();
$character = $db->payload('character:character-player');
$character['stats'] = ['force' => 50];
$db->put('character:character-player', $character);
$pendingAttack = [
    'id' => 'attack-opposed-0001', 'requestId' => 'attack-opposed-request1', 'sceneId' => 'scene-one',
    'sourceTokenId' => 'token-monster', 'targetTokenId' => 'token-player',
    'sourceName' => 'Créature', 'targetName' => 'Personnage', 'attackName' => 'Griffe',
    'accountId' => 'account-gm', 'attackerRole' => 'gm', 'playerName' => 'MJ test',
    'defenderAccountId' => 'account-player', 'status' => 'awaiting-opposition',
    'damageType' => 'ignore', 'damageFormula' => '1', 'damageRollMode' => 'normal',
    'hit' => [
        'raw' => 40, 'total' => 40, 'formula' => '1d100', 'rollMode' => 'normal',
        'selectedIndex' => 0, 'attempts' => [['total' => 40, 'rawD100' => 40, 'breakdown' => '[40]']],
        'outcome' => classifyOnlineD100Outcome(40, 50),
    ],
];
$activity = $db->payload('activity');
$activity['pendingAttacks'] = [$pendingAttack];
$activity['attackReceipts'] = [[
    'requestId' => $pendingAttack['requestId'], 'accountId' => 'account-gm',
    'expiresAt' => PHP_INT_MAX, 'attack' => $pendingAttack,
]];
$db->put('activity', $activity);
$response = runCommand($db, 'token.attack.oppose', [
    'attackId' => $pendingAttack['id'], 'requestId' => 'opposition-attempts-01',
    'statId' => 'character-stat-force', 'rollMode' => 'advantage',
]);
$opposition = $response->body['attack']['opposition'] ?? [];
$opposedReceiptAttack = $db->payload('activity')['attackReceipts'][0]['attack'] ?? [];
requireTactical(
    $response->status === 200
        && ($opposition['rollMode'] ?? '') === 'advantage'
        && is_int($opposition['selectedIndex'] ?? null)
        && count($opposition['attempts'] ?? []) === 2
        && str_contains(onlineAttackHistoryDetail($opposedReceiptAttack), 'Jet OPP · Force (Avantage)')
        && str_contains(onlineAttackHistoryDetail($opposedReceiptAttack), '(jet ignoré)')
        && str_contains(onlineAttackDiscordContent($opposedReceiptAttack), 'Jet OPP · Force (Avantage)')
        && str_contains(onlineAttackDiscordContent($opposedReceiptAttack), '(jet ignoré)'),
    'Opposition receipts and history retain both attempts for Player and GM renderers'
);

$pendingAbilityAttack = null;
for ($attempt = 0; $attempt < 200 && $pendingAbilityAttack === null; $attempt += 1) {
    $candidate = fixture();
    $character = $candidate->payload('character:character-player');
    $character['stats'] = ['force' => 100];
    $character['abilities'] = [[
        'id' => 'ability-opposed', 'name' => 'Onde opposée', 'effect' => 'damage', 'formula' => '1',
        'damageType' => 'magical', 'description' => '', 'manaCost' => 1, 'cooldownRounds' => 0,
        'castingStatId' => 'force',
    ]];
    $candidate->put('character:character-player', $character);
    $payload = [
        'sourceTokenId' => 'token-player', 'targetTokenId' => 'token-monster',
        'requestId' => 'pending-cast-attack-0001', 'attackKind' => 'ability',
        'attackId' => 'ability-opposed', 'abilityId' => 'ability-opposed', 'opposed' => true,
        'rollMode' => 'advantage',
    ];
    $candidateResponse = runCommand($candidate, 'token.attack', $payload);
    if ($candidateResponse->status === 200 && ($candidateResponse->body['attack']['status'] ?? '') === 'awaiting-opposition') {
        $pendingAbilityAttack = [$candidate, $candidateResponse, $payload];
    }
}
requireTactical(is_array($pendingAbilityAttack), 'A checked ability attack must reach an ordinary success awaiting opposition');
[$db, $response, $payload] = $pendingAbilityAttack;
$castRollId = (string) ($response->body['castRoll']['id'] ?? '');
$pending = $db->payload('activity')['pendingAttacks'][0] ?? [];
requireTactical(
    $castRollId !== ''
        && ($pending['cast']['roll']['id'] ?? '') === $castRollId
        && array_column($response->body['rolls'] ?? [], 'id') === [$castRollId],
    'A pending ability attack retains its canonical cast before opposition'
);
$opposed = runCommand($db, 'token.attack.oppose', [
    'attackId' => $response->body['attack']['id'], 'requestId' => 'pending-cast-oppose-01',
    'statId' => 'monster-force', 'rollMode' => 'advantage',
], true, 'account-gm');
requireTactical($opposed->status === 200, 'The pending ability attack can complete its opposition: ' . $opposed->getMessage());
$activity = $db->payload('activity');
$attackReceipt = null;
foreach ($activity['attackReceipts'] ?? [] as $receipt) {
    if (($receipt['requestId'] ?? '') === $payload['requestId']) { $attackReceipt = $receipt; break; }
}
requireTactical(
    is_array($attackReceipt) && ($attackReceipt['attack']['cast']['roll']['id'] ?? '') === $castRollId,
    'Opposition cannot erase the casting roll from the authoritative attack receipt'
);
$activity['resourceReceipts'] = [];
$db->put('activity', $activity);
$revision = $db->revision;
$retry = runCommand($db, 'token.attack', $payload);
$expectedRetryRollIds = array_column(
    onlineAttackResponseRollFields($attackReceipt['attack'], false, true, false)['rolls'],
    'id'
);
requireTactical(
    $retry->status === 200
        && ($retry->body['deduplicated'] ?? false) === true
        && ($retry->body['castRoll']['id'] ?? '') === $castRollId
        && array_column($retry->body['rolls'] ?? [], 'id') === $expectedRetryRollIds
        && count($expectedRetryRollIds) === count(array_unique($expectedRetryRollIds))
        && $db->revision === $revision,
    'An ability attack retry restores every visible canonical roll from the attack receipt without duplication'
);

$appliedAbilityAttack = null;
for ($attempt = 0; $attempt < 200 && $appliedAbilityAttack === null; $attempt += 1) {
    $candidate = fixture();
    $character = $candidate->payload('character:character-player');
    $character['stats'] = ['force' => 100];
    $character['abilities'] = [[
        'id' => 'ability-applied', 'name' => 'Onde appliquée', 'effect' => 'damage', 'formula' => '1',
        'damageType' => 'ignore', 'description' => '', 'manaCost' => 1, 'cooldownRounds' => 0,
        'castingStatId' => 'force',
    ]];
    $candidate->put('character:character-player', $character);
    $payload = [
        'sourceTokenId' => 'token-player', 'targetTokenId' => 'token-monster',
        'requestId' => 'applied-cast-attack-001', 'attackKind' => 'ability',
        'attackId' => 'ability-applied', 'abilityId' => 'ability-applied', 'opposed' => false,
    ];
    $candidateResponse = runCommand($candidate, 'token.attack', $payload);
    if ($candidateResponse->status === 200 && ($candidateResponse->body['attack']['status'] ?? '') === 'applied') {
        $appliedAbilityAttack = [$candidate, $candidateResponse, $payload];
    }
}
requireTactical(is_array($appliedAbilityAttack), 'A checked ability attack must reach an immediately applied ordinary success');
[$db, $response, $payload] = $appliedAbilityAttack;
$actions = $db->payload('activity')['playerActions'] ?? [];
$attackAction = current(array_values(array_filter($actions, static fn (mixed $entry): bool => is_array($entry) && ($entry['kind'] ?? '') === 'attack')));
$abilityReceipt = current(array_values(array_filter(
    $db->payload('activity')['resourceReceipts'] ?? [],
    static fn (mixed $entry): bool => is_array($entry) && ($entry['requestId'] ?? '') === $payload['requestId']
)));
requireTactical(
    is_array($attackAction) && is_array($abilityReceipt)
        && ($abilityReceipt['actionId'] ?? '') === ($attackAction['id'] ?? null),
    'An applied ability attack receipt must point to its attack action, not the later damage audit'
);

$longAttackDetail = onlineAttackHistoryDetail([
    'damageType' => 'ignore',
    'hit' => [
        'formula' => '1d100+15', 'rollMode' => 'advantage', 'selectedIndex' => 0,
        'attempts' => [['total' => 1], ['total' => 99]],
        'outcome' => ['threshold' => 75, 'label' => 'Réussite', 'success' => true],
    ],
    'opposition' => [
        'formula' => '1d100', 'rollMode' => 'disadvantage', 'selectedIndex' => 1,
        'attempts' => [['total' => 2], ['total' => 98]],
        'outcome' => ['threshold' => 60, 'label' => 'Échec', 'success' => false],
    ],
    'damage' => [
        'components' => array_map(static fn (string $type): array => [
            'type' => $type, 'formula' => str_repeat('1d6+', 20) . '1',
            'breakdown' => str_repeat('[6]+', 44) . '[6]', 'rawDamage' => 121,
            'armorPercent' => 0, 'preventedDamage' => 0, 'finalDamage' => 121,
        ], ['physical', 'magical', 'ignore']),
        'rawDamage' => 363, 'preventedDamage' => 0, 'finalDamage' => 363,
    ],
]);
requireTactical(
    strlen($longAttackDetail) > 500
        && strlen($longAttackDetail) <= XAR_PLAYER_ACTION_DETAIL_MAXIMUM_BYTES
        && str_ends_with($longAttackDetail, 'final 363'),
    'A complete multi-roll damage calculation must retain its final total beyond the old 500-byte truncation'
);

$db = fixture();
$remarkableFailure = [
    'id' => 'attack-critical-failure01', 'requestId' => 'critical-failure-request1', 'sceneId' => 'scene-one',
    'sourceTokenId' => 'token-monster', 'targetTokenId' => 'token-player', 'sourceName' => 'Créature', 'targetName' => 'Personnage',
    'attackName' => 'Griffe', 'accountId' => 'account-gm', 'attackerRole' => 'gm', 'playerName' => 'MJ test',
    'status' => 'pending', 'validationKind' => 'outcome', 'provisionalStatus' => 'missed', 'finalDamage' => 0,
    'damageType' => 'physical', 'damageFormula' => '1d6', 'damageRollMode' => 'normal',
    'hit' => ['raw' => 100, 'outcome' => classifyOnlineD100Outcome(100, 100)],
];
$activity = $db->payload('activity');
$activity['pendingAttacks'] = [$remarkableFailure];
$activity['attackReceipts'] = [['requestId' => $remarkableFailure['requestId'], 'accountId' => 'account-gm', 'expiresAt' => PHP_INT_MAX, 'attack' => $remarkableFailure]];
$db->put('activity', $activity);
$beforeHp = $db->payload('character:character-player')['resources']['hp'];
$response = runCommand($db, 'token.attack.resolve', ['attackId' => $remarkableFailure['id'], 'decision' => 'approve', 'confirmed' => true], true, 'account-gm');
requireTactical($response->status === 200 && ($response->body['attack']['status'] ?? '') === 'missed' && $db->payload('character:character-player')['resources']['hp'] === $beforeHp, 'GM validation of a critical failure finalizes the miss without touching HP');

$db = fixture();
$remarkableSuccess = [
    'id' => 'attack-critical-success01', 'requestId' => 'critical-success-request1', 'sceneId' => 'scene-one',
    'sourceTokenId' => 'token-player', 'targetTokenId' => 'token-monster', 'sourceName' => 'Personnage', 'targetName' => 'Créature',
    'attackName' => 'Lame', 'accountId' => 'account-gm', 'attackerRole' => 'gm', 'playerName' => 'MJ test',
    'status' => 'pending', 'validationKind' => 'outcome', 'provisionalStatus' => 'applied', 'finalDamage' => 5,
    'damageType' => 'ignore', 'damageFormula' => '5', 'damageRollMode' => 'normal',
    'hit' => ['raw' => 11, 'outcome' => classifyOnlineD100Outcome(11, 0)],
    'damage' => ['formula' => '5', 'breakdown' => '5', 'rawDamage' => 5, 'armorPercent' => 0, 'preventedDamage' => 0, 'finalDamage' => 5],
];
$activity = $db->payload('activity');
$activity['pendingAttacks'] = [$remarkableSuccess];
$activity['attackReceipts'] = [['requestId' => $remarkableSuccess['requestId'], 'accountId' => 'account-gm', 'expiresAt' => PHP_INT_MAX, 'attack' => $remarkableSuccess]];
$db->put('activity', $activity);
requireTactical($db->payload('token:scene-one:token-monster')['hp'] === 40, 'A remarkable hit cannot alter HP before GM validation');
$response = runCommand($db, 'token.attack.resolve', ['attackId' => $remarkableSuccess['id'], 'decision' => 'approve', 'confirmed' => true], true, 'account-gm');
requireTactical($response->status === 200 && ($response->body['attack']['status'] ?? '') === 'applied' && $db->payload('token:scene-one:token-monster')['hp'] === 35, 'Explicit GM validation applies a remarkable hit exactly once');

$db = fixture();
$response = runCommand($db, 'token.resource.adjust', ['tokenId'=>'token-player','resource'=>'hp','delta'=>-40,'requestId'=>'resource-request-0001']);
requireTactical($response->status === 200 && $response->body['current'] === -30, 'HP adjustment crosses zero without clipping: '.$response->getMessage());
requireTactical($db->payload('character:character-player')['resources']['hp'] === -30 && $db->payload('token:scene-two:token-copy')['hp'] === -30, 'Signed HP converges across sheet and tokens.');
$resourceAction = $db->payload('activity')['playerActions'][0]['id'];
runCommand($db, 'token.resource.adjust', ['tokenId'=>'token-player','resource'=>'hp','delta'=>5,'requestId'=>'resource-request-0002']);
$response = runCommand($db, 'action.undo', ['actionId'=>$resourceAction,'requestId'=>'undo-request-00001'], true, 'account-gm');
requireTactical($response->status === 200 && $db->payload('character:character-player')['resources']['hp'] === 15, 'Compensation preserves the later action across negative HP.');
$retryUndo = runCommand($db, 'action.undo', ['actionId'=>$resourceAction,'requestId'=>'undo-request-00001'], true, 'account-gm');
requireTactical($retryUndo->status === 200 && ($retryUndo->body['deduplicated'] ?? false), 'The same compensation request returns its receipt.');
requireTactical($db->payload('character:character-player')['resources']['hp'] === 15, 'Second undo cannot apply a second compensation.');

$db = fixture();
$character=$db->payload('character:character-player');$character['resources']['hp']=-24.1;$character['resources']['maxHp']=100.5;$db->put('character:character-player',$character);
$response=runCommand($db,'token.resource.adjust',['tokenId'=>'token-player','resource'=>'hp','delta'=>-1,'requestId'=>'fractional-request-001']);
requireTactical($response->status===200 && abs($response->body['current'] - -25.1)<0.000001 && $response->body['maximum']===100.5, 'An integer resource delta preserves fractional HP and maximum.');
$response=runCommand($db,'token.resource.adjust',['tokenId'=>'token-player','resource'=>'hp','delta'=>-1,'requestId'=>'fractional-request-001']);
requireTactical(abs($response->body['current'] - -25.1)<0.000001 && $response->body['maximum']===100.5, 'A resource receipt preserves fractional values exactly.');
$db=fixture();$character=$db->payload('character:character-player');$character['resources']['hp']=99.5;$character['resources']['maxHp']=99.75;$db->put('character:character-player',$character);
$response=runCommand($db,'token.resource.adjust',['tokenId'=>'token-player','resource'=>'hp','delta'=>1,'requestId'=>'fractional-request-002']);
requireTactical($response->status===200 && $response->body['appliedDelta']===0.25 && $response->body['current']===99.75, 'Clamping at a fractional maximum records the real fractional delta.');
$response=runCommand($db,'action.undo',['actionId'=>$response->body['action']['id'],'requestId'=>'fractional-undo-0001'],true,'account-gm');
requireTactical($response->status===200 && $db->payload('character:character-player')['resources']['hp']===99.5, 'Compensation restores a fractional delta without truncation.');
$db=fixture();$character=$db->payload('character:character-player');$character['resources']['hp']=-1000000000;$character['resources']['maxHp']=1000000000;$db->put('character:character-player',$character);
$records=[];$pending=[];$db->beginTransaction();
$adjustment=applyOnlineTokenResourceAdjustment($db,$records,$pending,'scene-one','token-player','hp',2000000000,'account-gm',true,'character-player',true);
requireTactical($adjustment['current']===1000000000 && $adjustment['appliedDelta']===2000000000 && $pending['token:scene-one:token-player']['payload']['resourcePulse']['delta']===2000000000, 'An internal compensation can span the full HP range with a valid pulse.');
$db->rollBack();
$response=runCommand($db,'token.resource.adjust',['tokenId'=>'token-player','resource'=>'hp','delta'=>2000000000,'requestId'=>'bounded-request-0001']);
requireTactical($response->status===200 && $response->body['appliedDelta']===1000000000 && $response->body['current']===0, 'User adjustments remain bounded to one billion.');

$db=fixture();$character=$db->payload('character:character-player');$character['resources']['hp']=1000000000;$character['resources']['maxHp']=1000000000;$db->put('character:character-player',$character);
$records=[];$pending=[];$db->beginTransaction();
$damage=applyOnlineAttackDamage($db,$records,$pending,'token:scene-one:token-player',$db->payload('token:scene-one:token-player'),2000000000);
requireTactical($damage['currentHp']===-1000000000 && $damage['appliedDamage']===2000000000 && $pending['token:scene-one:token-player']['payload']['resourcePulse']['delta']===-2000000000, 'Resolved attack damage can span the full HP range without being capped as a manual resource request.');
$db->rollBack();

foreach ([true,false] as $combatActive) {
    $db = fixture();
    $character = $db->payload('character:character-player'); $character['resources']['hp'] = -20; $db->put('character:character-player',$character);
    $initiative = $db->payload('initiative:scene-one'); $initiative['active'] = $combatActive; $db->put('initiative:scene-one',$initiative);
    $attack = ['id'=>'attack-xxxxxxxxxxxxxxxx','requestId'=>'request-xxxxxxxxxxxxxxxx','sceneId'=>'scene-one','sourceTokenId'=>'token-monster','targetTokenId'=>'token-player','sourceName'=>'Créature','targetName'=>'Personnage','attackName'=>'Griffe','accountId'=>'account-gm','attackerRole'=>'gm','playerName'=>'MJ test','defenderAccountId'=>'account-player','status'=>'awaiting-opposition','damageType'=>'ignore','damageFormula'=>'10','damageRollMode'=>'normal','hit'=>['raw'=>45,'outcome'=>['raw'=>45,'result'=>45,'modifier'=>0,'threshold'=>50,'code'=>'success','label'=>'RÉUSSITE','success'=>true,'effect'=>false]]];
    $activity=$db->payload('activity');$activity['pendingAttacks']=[$attack];$activity['attackReceipts']=[['requestId'=>$attack['requestId'],'accountId'=>'account-gm','expiresAt'=>PHP_INT_MAX,'attack'=>$attack]];$db->put('activity',$activity);
    $response = runCommand($db,'token.attack.oppose',['attackId'=>$attack['id'],'requestId'=>'opposition-request-0001']);
    requireTactical($response->status === 200, 'A target that became KO can resolve without a defense stat: '.$response->getMessage());
    requireTactical(($response->body['attack']['opposition']['skipped'] ?? false) && $response->body['attack']['status'] === ($combatActive?'applied':'pending'), 'No opposition die is rolled for a KO target.');
    $storedDamageRolls = array_values(array_filter(
        $db->payload('activity')['rolls'] ?? [],
        static fn (mixed $roll): bool => is_array($roll) && str_contains((string) ($roll['label'] ?? ''), 'Dégâts')
    ));
    $publicDamageEvents = array_values(array_filter(
        $storedDamageRolls,
        static fn (array $roll): bool => ($roll['visibility'] ?? '') === 'public'
            && ($roll['mapEvent']['kind'] ?? '') === 'damage'
            && ($roll['mapEvent']['applied'] ?? false) === true
    ));
    requireTactical(
        count($storedDamageRolls) === 1 && count($publicDamageEvents) === ($combatActive ? 1 : 0),
        'A rolled damage die is retained once, but only actually applied damage becomes a public event.'
    );
    $storedAttack = $db->payload('activity')['attackReceipts'][0]['attack'] ?? [];
    $storedDamageRollId = (string) ($storedAttack['damageRoll']['id'] ?? '');
    requireTactical(
        $storedDamageRollId !== ''
            && ($storedAttack['damageRoll']['rollMode'] ?? '') === 'normal'
            && ($storedAttack['damage']['rollId'] ?? '') === $storedDamageRollId
            && ($storedDamageRolls[0]['id'] ?? '') === $storedDamageRollId,
        'The KO opposition path stores its real normal damage roll in the receipt and activity.'
    );
    if (!$combatActive) {
        requireTactical(
            !isset($response->body['attack']['damage'],$response->body['attack']['appliedDamage'])
                && ($response->body['damageRoll'] ?? null) === null
                && ($storedAttack['damageRoll']['visibility'] ?? '') === 'gm',
            'Pending response hides prospective damage while the authoritative receipt keeps the already rolled die.'
        );
        $response = runCommand($db,'token.attack.resolve',['attackId'=>$attack['id'],'decision'=>'approve','confirmed'=>true],true,'account-gm');
        requireTactical($response->status === 200, 'The MJ explicitly approves damage out of combat.');
        requireTactical(
            ($response->body['damageRoll']['id'] ?? '') === $storedDamageRollId
                && ($response->body['damageRoll']['visibility'] ?? '') === 'public'
                && count(array_filter($db->payload('activity')['rolls'] ?? [], static fn (mixed $roll): bool => is_array($roll) && ($roll['id'] ?? '') === $storedDamageRollId)) === 1,
            'Approval promotes the stored damage roll without rerolling or duplicating it.'
        );
    } else {
        requireTactical(
            ($response->body['damageRoll']['id'] ?? '') === $storedDamageRollId
                && !isset($response->body['damageRoll']['attempts'])
                && ($response->body['damageRoll']['total'] ?? null) === ($response->body['attack']['appliedDamage'] ?? null),
            'A player sees only the sanitized applied-loss projection of the canonical damage roll.'
        );
    }
    requireTactical($db->payload('character:character-player')['resources']['hp'] === -30, 'Full reduced damage below zero can cross the death threshold.');
    $revision=$db->revision;
    requireTactical(runCommand($db,'token.attack.oppose',['attackId'=>$attack['id'],'requestId'=>'opposition-request-0001'])->status===200 && $db->revision===$revision, 'Replayed KO opposition cannot apply damage again.');
    requireTactical(runCommand($db,'token.attack.oppose',['attackId'=>$attack['id'],'requestId'=>'opposition-request-0001'],false,'intruder')->status===403, 'A third party cannot replay a KO receipt.');
}
$event = ['mapEvent'=>['kind'=>'roll','sceneId'=>'scene-one','attackId'=>'attack-xxx','sourceTokenId'=>'source','targetTokenId'=>'target','anchorTokenId'=>'source']];
requireTactical(onlineMapRollVisible($event,'scene-one',['source','target']), 'Visible attack dice stay available.');
requireTactical(!onlineMapRollVisible($event,'scene-two',['source','target']) && !onlineMapRollVisible($event,'scene-one',['source']), 'Other scenes and hidden targets do not reveal map dice.');
requireTactical(!onlineMapRollVisible(['mapEvent'=>[...$event['mapEvent'],'kind'=>'damage','applied'=>false,'value'=>20]],'scene-one',['source','target']), 'Prospective damage never becomes a public event.');
$abilityRollFields = [];
foreach ([['player', false, 'account-player'], ['gm', true, 'account-gm']] as [$role, $gm, $account]) {
    $db = fixture();
    $requestId = 'ability-journal-' . $role . '-0001';
    $payload = ['sceneId' => 'scene-one', 'tokenId' => 'token-player', 'kind' => 'ability', 'abilityId' => 'ability-one', 'rollMode' => 'advantage', 'requestId' => $requestId];
    $response = runCommand($db, 'token.roll', $payload, $gm, $account);
    $actions = $db->payload('activity')['playerActions'];
    requireTactical($response->status === 200 && array_column($actions, 'kind') === ['roll', 'ability'], "$role ability roll must append exactly one canonical roll action and one ability action: " . $response->getMessage());
    requireTactical(
        ($response->body['castRoll'] ?? null) === null
            && ($response->body['effectRoll']['id'] ?? '') === ($response->body['roll']['id'] ?? null)
            && array_column($response->body['rolls'] ?? [], 'id') === [($response->body['roll']['id'] ?? '')],
        "$role legacy ability without a casting check keeps its effect as the primary and sole roll"
    );
    $rollAction = $actions[0];
    $abilityAction = $actions[1];
    $abilityRollFields[$role] = array_intersect_key($rollAction, array_flip(['kind', 'characterName', 'summary', 'detail']));
    requireTactical($abilityRollFields[$role] === [
        'kind' => 'roll',
        'characterName' => 'Personnage',
        'summary' => 'Frappe test (Avantage)',
        'detail' => "1 : 1\n1 : 1 (jet ignoré)",
    ], "$role ability roll must use the canonical roll presentation");
    $receipts = array_values(array_filter($db->payload('activity')['resourceReceipts'] ?? [], static fn (mixed $entry): bool => is_array($entry) && ($entry['requestId'] ?? '') === $requestId));
    requireTactical(count($receipts) === 1 && ($receipts[0]['actionId'] ?? '') === $abilityAction['id'], "$role ability receipt must keep pointing to the ability action");
    $revision = $db->revision;
    $retry = runCommand($db, 'token.roll', $payload, $gm, $account);
    requireTactical($retry->status === 200 && ($retry->body['deduplicated'] ?? false) === true
        && array_column($retry->body['rolls'] ?? [], 'id') === array_column($response->body['rolls'] ?? [], 'id')
        && $db->revision === $revision && count($db->payload('activity')['playerActions']) === 2,
        "$role ability retry must return the same bundle without duplicating either journal entry");
}
requireTactical($abilityRollFields['player'] === $abilityRollFields['gm'], 'Player and GM ability rolls must produce identical canonical roll fields');

$checkedRoleFields = [];
foreach ([['player', false, 'account-player'], ['gm', true, 'account-gm']] as [$role, $gm, $account]) {
    $successful = null;
    for ($attempt = 0; $attempt < 200 && $successful === null; $attempt += 1) {
        $candidate = fixture();
        $character = $candidate->payload('character:character-player');
        $character['stats'] = ['force' => 100];
        $character['abilities'] = [[
            'id' => 'ability-checked', 'name' => 'Onde vérifiée', 'effect' => 'damage', 'formula' => '2',
            'damageType' => 'magical', 'description' => '', 'manaCost' => 1, 'cooldownRounds' => 0,
            'castingStatId' => 'force',
        ]];
        $candidate->put('character:character-player', $character);
        $payload = [
            'sceneId' => 'scene-one', 'tokenId' => 'token-player', 'kind' => 'ability',
            'abilityId' => 'ability-checked', 'rollMode' => 'advantage',
            'requestId' => 'checked-ability-' . $role . '-0001',
        ];
        $candidateResponse = runCommand($candidate, 'token.roll', $payload, $gm, $account);
        if ($candidateResponse->status === 200 && ($candidateResponse->body['castSucceeded'] ?? false) === true) {
            $successful = [$candidate, $candidateResponse, $payload];
        }
    }
    requireTactical(is_array($successful), "$role checked ability must reach a successful casting sample");
    [$db, $response, $payload] = $successful;
    $rolls = $response->body['rolls'] ?? [];
    requireTactical(
        count($rolls) === 2
            && array_column($rolls, 'id') === [($response->body['castRoll']['id'] ?? ''), ($response->body['effectRoll']['id'] ?? '')]
            && ($response->body['roll']['id'] ?? '') === ($response->body['effectRoll']['id'] ?? null)
            && ($response->body['cast']['roll']['id'] ?? '') === ($response->body['castRoll']['id'] ?? null),
        "$role successful checked ability must expose cast then effect without hiding the historical primary"
    );
    requireTactical(
        count($response->body['castRoll']['attempts'] ?? []) === 2
            && count($response->body['effectRoll']['attempts'] ?? []) === 2
            && array_column($db->payload('activity')['rolls'], 'id') === array_column($rolls, 'id')
            && array_column($db->payload('activity')['playerActions'], 'kind') === ['roll', 'roll', 'ability'],
        "$role journal and actions must contain both advantage rolls exactly once"
    );
    $checkedRoleFields[$role] = [
        $response->body['castRoll']['label'] ?? '',
        $response->body['effectRoll']['label'] ?? '',
        array_column($db->payload('activity')['playerActions'], 'summary'),
    ];
    $revision = $db->revision;
    $retry = runCommand($db, 'token.roll', $payload, $gm, $account);
    requireTactical(
        $retry->status === 200 && ($retry->body['deduplicated'] ?? false) === true
            && array_column($retry->body['rolls'] ?? [], 'id') === array_column($rolls, 'id')
            && $db->revision === $revision
            && count($db->payload('activity')['rolls']) === 2
            && count($db->payload('activity')['playerActions']) === 3,
        "$role checked ability retry must return both immutable rolls without republishing them"
    );
}
requireTactical($checkedRoleFields['player'][0] === $checkedRoleFields['gm'][0]
    && $checkedRoleFields['player'][1] === $checkedRoleFields['gm'][1],
    'Successful checked ability labels are identical for Player and GM');

$healingSuccess = null;
for ($attempt = 0; $attempt < 200 && $healingSuccess === null; $attempt += 1) {
    $candidate = fixture();
    $character = $candidate->payload('character:character-player');
    $character['stats'] = ['force' => 100];
    $character['abilities'] = [[
        'id' => 'ability-healing', 'name' => 'Souffle réparateur', 'effect' => 'healing',
        'formula' => '2', 'healingFormula' => '2', 'description' => '',
        'manaCost' => 1, 'cooldownRounds' => 0, 'castingStatId' => 'force',
    ]];
    $candidate->put('character:character-player', $character);
    $payload = [
        'sceneId' => 'scene-one', 'sourceTokenId' => 'token-player', 'targetTokenId' => 'token-player',
        'abilityId' => 'ability-healing', 'rollMode' => 'advantage',
        'requestId' => 'healing-ability-player-0001',
    ];
    $candidateResponse = runCommand($candidate, 'ability.use', $payload);
    if ($candidateResponse->status === 200 && ($candidateResponse->body['castSucceeded'] ?? false) === true) {
        $healingSuccess = [$candidate, $candidateResponse, $payload];
    }
}
requireTactical(is_array($healingSuccess), 'A checked healing ability must reach a successful casting sample');
[$db, $response, $payload] = $healingSuccess;
$healingRolls = $response->body['rolls'] ?? [];
requireTactical(
    count($healingRolls) === 2
        && array_column($healingRolls, 'id') === [($response->body['castRoll']['id'] ?? ''), ($response->body['effectRoll']['id'] ?? '')]
        && ($response->body['effectRoll']['label'] ?? '') === 'Souffle réparateur · Soin'
        && array_column($db->payload('activity')['rolls'], 'id') === array_column($healingRolls, 'id')
        && array_column($db->payload('activity')['playerActions'], 'kind') === ['roll', 'roll', 'ability', 'resource'],
    'Healing exposes and journals its cast and effect exactly once'
);
$healingDiscord = onlineDiscordResultRollContent($response->body);
requireTactical(
    substr_count($healingDiscord, '**Personnage**') === 2
        && str_contains($healingDiscord, 'Souffle réparateur · Lancement · Force (Avantage)')
        && str_contains($healingDiscord, 'Souffle réparateur · Soin')
        && str_contains($healingDiscord, '(jet ignoré)'),
    'Healing Discord output contains both public presentations and their ignored attempts'
);
$revision = $db->revision;
$retry = runCommand($db, 'ability.use', $payload);
requireTactical(
    $retry->status === 200 && ($retry->body['deduplicated'] ?? false) === true
        && array_column($retry->body['rolls'] ?? [], 'id') === array_column($healingRolls, 'id')
        && $db->revision === $revision
        && count($db->payload('activity')['playerActions']) === 4,
    'Healing retry restores both rolls without a second heal, journal entry or Discord publication'
);

$failed = null;
for ($attempt = 0; $attempt < 200 && $failed === null; $attempt += 1) {
    $candidate = fixture();
    $character = $candidate->payload('character:character-player');
    $character['stats'] = ['force' => 0];
    $character['abilities'] = [[
        'id' => 'ability-failed', 'name' => 'Onde manquée', 'effect' => 'damage', 'formula' => '2',
        'damageType' => 'magical', 'description' => '', 'manaCost' => 1, 'cooldownRounds' => 3,
        'castingStatId' => 'force',
    ]];
    $candidate->put('character:character-player', $character);
    $payload = [
        'sceneId' => 'scene-one', 'tokenId' => 'token-player', 'kind' => 'ability',
        'abilityId' => 'ability-failed', 'requestId' => 'failed-ability-player-0001',
    ];
    $candidateResponse = runCommand($candidate, 'token.roll', $payload);
    if ($candidateResponse->status === 200 && ($candidateResponse->body['castSucceeded'] ?? true) === false) {
        $failed = [$candidate, $candidateResponse, $payload];
    }
}
requireTactical(is_array($failed), 'A checked ability must reach a failed casting sample');
[$db, $response, $payload] = $failed;
requireTactical(
    ($response->body['effectRoll'] ?? null) === null
        && count($response->body['rolls'] ?? []) === 1
        && ($response->body['roll']['id'] ?? '') === ($response->body['castRoll']['id'] ?? null)
        && count($db->payload('activity')['rolls']) === 1
        && array_column($db->payload('activity')['playerActions'], 'kind') === ['roll', 'ability']
        && ($response->body['cast']['remainingRounds'] ?? -1) === 0,
    'A failed checked ability records only the cast, applies no effect and starts no cooldown'
);
$failedRevision = $db->revision;
$failedRetry = runCommand($db, 'token.roll', $payload);
requireTactical(
    $failedRetry->status === 200
        && ($failedRetry->body['deduplicated'] ?? false) === true
        && array_column($failedRetry->body['rolls'] ?? [], 'id') === array_column($response->body['rolls'] ?? [], 'id')
        && $db->revision === $failedRevision
        && count($db->payload('activity')['rolls']) === 1
        && count($db->payload('activity')['playerActions']) === 2,
    'A failed checked ability retry restores its cast without another mana cost or journal entry'
);
require __DIR__ . '/token-groups-cases.php';
require __DIR__ . '/token-size-defaults-cases.php';
require __DIR__ . '/gm-wall-placement-cases.php';
require __DIR__ . '/vision-stream-layers-cases.php';
require __DIR__ . '/map-visibility-cases.php';
require __DIR__ . '/light-relay-cases.php';
require __DIR__ . '/lighting-carry-cases.php';
fwrite(STDOUT, 'Cycle tactique PHP 3.2.0 : ' . $GLOBALS['checks'] . " contrôles réussis\n");
