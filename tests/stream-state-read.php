<?php
declare(strict_types=1);
define('XAR_TACTICAL_FIXTURE_ONLY', true);
require __DIR__ . '/tactical-lifecycle.php';

// Production projection and SQL selection, with storage/transport replaced.
// Never opens a network connection or reads a real character.
final class StreamReadStatement extends PDOStatement
{
    private array $rows = [];
    public function __construct(private StreamReadConnection $database, private string $sql) {}
    public function execute(?array $params = null): bool
    {
        $this->rows = $this->database->readRows($this->sql, $params ?? []);
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return array_shift($this->rows) ?? false; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
}
final class StreamReadConnection extends PDO
{
    public array $selectedKeys = [];
    public int $presenceReads = 0;
    public function __construct(public MemoryConnection $storage) {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new StreamReadStatement($this, $query); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { $s = $this->prepare($query); $s->execute(); return $s; }
    public function readRows(string $sql, array $params): array
    {
        if (str_contains($sql, 'FROM live_connections')) { $this->presenceReads++; return []; }
        $rows = $this->storage->executeSql($sql, $params);
        if (str_starts_with($sql, 'SELECT domain_key, schema_version')) {
            array_push($this->selectedKeys, ...array_column($rows, 'domain_key'));
        }
        return $rows;
    }
}
function readStreamFixture(MemoryConnection $storage, array $identity, array $query): array
{
    $connection = new StreamReadConnection($storage);
    $GLOBALS['testIdentity'] = $identity;
    $_GET = $query;
    try { readOnlineState($connection); } catch (TestResponse $response) { return [$response, $connection]; }
    throw new RuntimeException('Stream state did not respond.');
}
function comparableStream(array $state): string
{
    unset($state['updatedAt'], $state['tableTokens']['serverNow']);
    // Some empty public maps are stdClass. Compare their wire value, not PHP
    // object identity, while preserving the distinction between {} and [].
    return json_encode($state, JSON_THROW_ON_ERROR);
}
function loadedStreamFixture(): MemoryConnection
{
    $db = fixture();
    $db->put('roster', [...$db->payload('roster'), '_ownershipRepairVersion' => XAR_IDENTITY_OWNERSHIP_REPAIR_VERSION]);
    $db->put('devices', [...dsEmpty(), 'manualTurns' => ['scene-one' => 17],
        'devices' => [['id' => 'visible-station', 'revision' => 1, 'status' => 'active',
            'creatorCharacterId' => 'character-player', 'sceneId' => 'scene-one', 'layerId' => 'ground',
            'x' => 22, 'y' => 50, 'definition' => ['kind' => 'battery']]]]);
    $index = $db->payload('scene-index');
    for ($scene = 0; $scene < 80; $scene++) {
        $id = 'unused-' . $scene;
        $index['order'][] = $id;
        $db->put('scene:' . $id, ['metadata' => ['id' => $id, 'name' => 'Private unused scene']]);
        $db->put('map:' . $id, ['gridSize' => 50, 'naturalWidth' => 1600, 'naturalHeight' => 900]);
        $db->put('initiative:' . $id, ['active' => false]);
        $tokens = [];
        for ($token = 0; $token < 30; $token++) {
            $key = 'private-' . $token;
            $tokens[] = $key;
            $db->put('token:' . $id . ':' . $key, ['id' => $key, 'name' => 'Private unused unit', 'x' => 50, 'y' => 50]);
        }
        $db->put('token-index:' . $id, ['order' => $tokens]);
    }
    $db->put('scene-index', $index);
    return $db;
}
$gm = ['id' => 'account-gm', 'display_name' => 'MJ fictif', 'effective_mode' => 'gm', 'permanent_role' => 'gm'];
$evidence = [];
foreach (['live', 'vision', 'paused', 'paused-legacy', 'other-layer', 'empty'] as $scenario) {
    $db = loadedStreamFixture();
    $map = $db->payload('map:scene-one');
    $map['background'] = '/media/abcdefghijklmnopqrstuvwx';
    $map['walls'] = emptyApplicationWallState(1600, 900);
    $map['lights'] = [['id' => 'public-light', 'x' => 20, 'y' => 50, 'visionDistance' => 8, 'color' => '#8844ee']];
    if ($scenario === 'vision') $map['vision'] = ['enabled' => true, 'shared' => false, 'distance' => 8];
    $db->put('token-index:scene-one', ['order' => [...$db->payload('token-index:scene-one')['order'], 'token-independent']]);
    $db->put('token:scene-one:token-independent', [...$db->payload('token:scene-one:token-independent'),
        'name' => 'Independent clone', 'x' => 20, 'y' => 60]);
    $db->put('items', ['version' => 1, 'templates' => [['id' => 'visible-object', 'name' => 'Visible ground item', 'kind' => 'object']],
        'instances' => [['id' => 'ground-one', 'templateId' => 'visible-object', 'revision' => 1,
            'location' => ['kind' => 'ground', 'sceneId' => 'scene-one', 'layerId' => 'ground', 'x' => 24, 'y' => 50]]]]);
    $activity = $db->payload('activity');
    $activity['rolls'] = [['id' => 'visible-special', 'formula' => '1d100', 'total' => 55, 'visibility' => 'public',
        'createdAt' => gmdate('c'), 'outcome' => ['raw' => 55, 'result' => 55, 'code' => 'special-success', 'success' => true, 'effect' => true],
        'mapEvent' => ['kind' => 'roll', 'sceneId' => 'scene-one', 'layerId' => 'ground', 'tokenId' => 'token-player']]];
    appendApplicationAbilityCueEvent($activity, ['version' => 1, 'trigger' => 'completed', 'visual' => ['version' => 1, 'kind' => 'none'],
        'sound' => ['url' => '/media/abcdefghijklmnopqrstuvwx', 'name' => 'signal.mp3', 'contentType' => 'audio/mpeg',
            'byteSize' => 79000, 'durationMs' => 3000]], 'scene-one', 'ground', 'token-player');
    $db->put('activity', $activity);
    $db->put('map:scene-one', $map);
    if ($scenario === 'other-layer') {
        $map['activeLayerId'] = 'upper';
        $db->put('map:scene-one', $map);
    }
    if (str_starts_with($scenario, 'paused')) {
        $db->put('table', [...$db->payload('table'), 'tacticalSync' => ['paused' => true]]);
        if ($scenario === 'paused') {
            $db->put('presentation:scene-one', ['map' => [...$map, 'tokens' => [$db->payload('token:scene-one:token-player')]],
                'initiative' => ['active' => false], 'activeScene' => ['id' => 'scene-one', 'name' => 'Frozen published map']]);
        }
    }
    if ($scenario === 'empty') $db->put('table', ['activeSceneId' => '']);
    $full = domainApplicationStateRecord($db);
    $full['state']['revision'] = $full['revision'];
    $expected = publicPlayerState(sanitizeStateImageReferences($full['state']), $gm, [], true);
    [$response, $reader] = readStreamFixture($db, $gm, ['view' => 'stream']);
    requireTactical($response->status === 200, $scenario . ': Stream read succeeds.');
    requireTactical(comparableStream($response->body['state']) === comparableStream($expected),
        $scenario . ': The lightweight Stream is identical to the full authoritative public projection.');
    requireTactical(!array_filter($reader->selectedKeys, static fn($key) => str_contains($key, 'unused-')),
        $scenario . ': No inactive scene or unit is loaded.');
    requireTactical($reader->presenceReads === 0, 'The silent map does not query unused account presence.');
    requireTactical($response->body['presence'] === [], 'Stream transports no account presence.');
    if (in_array($scenario, ['live', 'vision'], true)) {
        requireTactical(($response->body['state']['devices']['manualTurns']['scene-one'] ?? null) === 17,
            'Round clocks survive the selective read.');
        requireTactical(count($response->body['state']['devices']['devices']) === 1, 'Visible machines survive the selective read.');
        requireTactical(count($response->body['state']['items']['instances']) === 1, 'Visible ground items survive the selective read.');
        requireTactical(count($response->body['state']['abilityCueEvents']) === 1 && count($response->body['state']['rolls']) === 1,
            'The complete read still delivers ability and special-success animations.');
        requireTactical(in_array('token-independent', array_column($response->body['state']['map']['tokens'], 'id'), true),
            'Independent clones remain independent visible map units.');
    }
    $evidence[$scenario] = ['worldDomains' => count($db->domains), 'readDomains' => count(array_unique($reader->selectedKeys))];
    [$unchanged, $audit] = readStreamFixture($db, $gm, ['view' => 'stream', 'since' => (string) $db->revision]);
    requireTactical(($unchanged->body['unchanged'] ?? false) === true, 'An unchanged revision stays a lightweight audit.');
    requireTactical($audit->selectedKeys === ['roster'], 'An unchanged audit never assembles a map.');
}
[$private, $privateReader] = readStreamFixture($db = loadedStreamFixture(), $gm, []);
requireTactical($private->status === 200 && count($private->body['state']['scenes']) === 82, 'Private MJ snapshots retain every scene.');
requireTactical($privateReader->presenceReads === 1, 'Private MJ snapshots retain account presence.');
[$asPlayer] = readStreamFixture($db, [...$gm, 'effective_mode' => 'player'], ['view' => 'stream']);
requireTactical($asPlayer->status === 200 && comparableStream($asPlayer->body['state']) === comparableStream(
    publicPlayerState(sanitizeStateImageReferences($private->body['state']), [...$gm, 'effective_mode' => 'player'], [], true)),
    'An MJ in Player mode receives the same common Stream vision.');
[$forbidden, $forbiddenReader] = readStreamFixture($db, [...$gm, 'effective_mode' => 'player', 'permanent_role' => 'player'], ['view' => 'stream']);
requireTactical($forbidden->status === 403 && $forbiddenReader->selectedKeys === [], 'A player cannot request the common MJ Stream projection.');
echo json_encode(['ok' => true, 'checks' => $GLOBALS['checks'], 'scenarios' => $evidence], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
