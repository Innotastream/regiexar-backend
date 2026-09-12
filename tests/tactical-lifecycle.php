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
        if (str_starts_with($sql, 'INSERT INTO application_domains ')) {
            $this->put($params[':domain_key'], json_decode($params[':payload'], true, 512, JSON_THROW_ON_ERROR), $params[':revision']);
            return [];
        }
        if (str_starts_with($sql, 'UPDATE application_domain_clock')) { $this->revision = $params[':global_revision']; return []; }
        if (str_starts_with($sql, 'INSERT INTO application_domain_history') || str_starts_with($sql, 'INSERT INTO application_domain_changes')) return [];
        if (str_contains($sql, 'FROM shared_settings')) return [];
        throw new RuntimeException('Unexpected SQL in fixture: ' . $sql);
    }
}
function requireTactical(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    $GLOBALS['checks'] = ($GLOBALS['checks'] ?? 0) + 1;
}
function runCommand(MemoryConnection $db, string $command, array $payload, bool $gm = false, string $account = 'account-player'): TestResponse
{
    $GLOBALS['testIdentity'] = ['id' => $account, 'display_name' => $gm ? 'MJ test' : 'Joueur test', 'effective_mode' => $gm ? 'gm' : 'player', 'permanent_role' => $gm ? 'gm' : 'player'];
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
    'statId' => 'monster-force',
], true, 'account-gm');
requireTactical($response->status === 200 && ($response->body['attack']['targetTokenId'] ?? '') === 'token-monster-two', 'The GM can target another creature with a creature: ' . $response->getMessage());

$db = fixture();
$character = $db->payload('character:character-player');
$character['name'] = 'Nom autoritatif';
$character['stats'] = ['force' => 64];
$db->put('character:character-player', $character);
$staleToken = $db->payload('token:scene-one:token-player');
$staleToken['name'] = 'Ancien nom';
$staleToken['stats'] = [['id' => 'force', 'label' => 'Ancienne Force', 'value' => 1]];
$db->put('token:scene-one:token-player', $staleToken);
$response = runCommand($db, 'token.roll', [
    'sceneId' => 'scene-one', 'tokenId' => 'token-player', 'kind' => 'stat', 'statId' => 'character-stat-force',
    'rollMode' => 'advantage', 'modifier' => 0, 'modifierMode' => 'result',
]);
requireTactical(
    $response->status === 200
        && ($response->body['roll']['characterName'] ?? '') === 'Nom autoritatif'
        && ($response->body['roll']['label'] ?? '') === 'Force'
        && ($response->body['roll']['outcome']['threshold'] ?? null) === 64
        && ($response->body['roll']['outcome']['resultCustomized'] ?? false) === true
        && count($response->body['roll']['attempts'] ?? []) === 2,
    'A Player token roll must resynchronize its authoritative sheet and preserve a zero custom-result choice'
);
$gmResponse = runCommand(fixture(), 'token.roll', [
    'sceneId' => 'scene-one', 'tokenId' => 'token-monster', 'layerId' => 'ground',
    'kind' => 'stat', 'statId' => 'monster-force', 'rollMode' => 'advantage',
    'modifier' => 0, 'modifierMode' => 'result', 'requestId' => 'gm-role-parity-roll-0001',
], true, 'account-gm');
requireTactical(
    $gmResponse->status === 200
        && ($gmResponse->body['roll']['label'] ?? '') === 'Force'
        && ($gmResponse->body['roll']['outcome']['resultCustomized'] ?? false) === true
        && count($gmResponse->body['roll']['attempts'] ?? []) === 2,
    'The GM tactical route preserves the same canonical mode and customized-result fields'
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
requireTactical(
    $retry->status === 200
        && ($retry->body['deduplicated'] ?? false) === true
        && ($retry->body['castRoll']['id'] ?? '') === $castRollId
        && array_column($retry->body['rolls'] ?? [], 'id') === [$castRollId]
        && $db->revision === $revision,
    'An ability attack retry restores its cast from the attack receipt after the ability receipt expires'
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
    requireTactical(count($db->payload('activity')['rolls']) === ($combatActive?1:0), 'Only actually applied damage has a public event.');
    if (!$combatActive) {
        requireTactical(!isset($response->body['attack']['damage'],$response->body['attack']['appliedDamage']), 'Pending response hides prospective damage.');
        $response = runCommand($db,'token.attack.resolve',['attackId'=>$attack['id'],'decision'=>'approve','confirmed'=>true],true,'account-gm');
        requireTactical($response->status === 200, 'The MJ explicitly approves damage out of combat.');
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
