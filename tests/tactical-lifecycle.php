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
        'token-index:scene-one' => ['order' => ['token-player', 'token-monster']],
        'initiative:scene-one' => ['active' => true, 'order' => ['token-monster', 'token-player'], 'currentIndex' => 0],
        'character:character-player' => ['id' => 'character-player', 'ownerPlayerId' => 'account-player', 'name' => 'Personnage', 'color' => '#22aa33', 'resources' => ['hp' => 10, 'maxHp' => 100, 'mana' => 5, 'maxMana' => 10], 'conditions' => [], 'stats' => ['force' => 50]],
        'token:scene-one:token-player' => ['id' => 'token-player', 'characterId' => 'character-player', 'controllerPlayerId' => 'stale-controller', 'name' => 'Personnage', 'hp' => 99, 'maxHp' => 100, 'conditions' => [], 'x' => 20, 'y' => 50],
        'token:scene-two:token-copy' => ['id' => 'token-copy', 'characterId' => 'character-player', 'name' => 'Copie', 'hp' => 99, 'maxHp' => 100, 'conditions' => [], 'x' => 20, 'y' => 50],
        'token:scene-one:token-independent' => ['id' => 'token-independent', 'characterId' => 'character-player', 'followCharacter' => false, 'hp' => 40, 'maxHp' => 40, 'conditions' => ['Endormi']],
        'token:scene-one:token-monster' => ['id' => 'token-monster', 'name' => 'Créature', 'hp' => 40, 'maxHp' => 40, 'frameVariant' => 'boss', 'x' => 50, 'y' => 50],
        'activity' => ['actionTimers' => [], 'actionTimerTombstones' => [], 'mapPings' => [], 'shortcuts' => [], 'rolls' => [], 'playerActions' => [], 'pendingAttacks' => [], 'attackReceipts' => []],
    ]);
}

foreach ([[0,100,true,'down'],[-25,100,true,'down'],[-25.01,100,true,'dead'],[-26,100,true,'dead'],[0,100,false,'down'],[-1,100,false,'dead'],[9,100,true,'critical'],[10,100,true,'normal'],[0,0,true,'down'],[-1,0,true,'dead']] as [$hp,$max,$player,$code]) {
    requireTactical(onlineHealthState($hp,$max,$player)['code'] === $code, "Health boundary $hp/$max");
}
requireTactical(healthOverlayState(-26,100)['effect'] === 'Mort', 'The stream HP overlay must agree on player death.');
requireTactical(normalizeOnlineConditions(['poison', 'Empoisonné', 'endormis', 'KO', 'Mort', 'Marque du voile']) === ['Empoisonné','Endormi','Marque du voile'], 'Canonical labels, no duplicate or ordinary health states.');
requireTactical(normalizeOnlineConditions([], 'Poison') === [], 'An explicit empty array does not resurrect the legacy field.');
requireTactical(onlineManualDeath(['conditions'=>['Mort']]) && !onlineManualDeath(['conditions'=>['Mort'],'healthOverride'=>null]), 'Explicit override clearing wins over legacy Mort.');
$patched = playerCharacterPatch(['conditions'=>['Mort'], 'resources'=>['hp'=>1,'maxHp'=>100,'mana'=>1,'maxMana'=>10]], ['conditions'=>['Poison'], 'healthOverride'=>null, 'resources'=>['hp'=>-26,'mana'=>-2]]);
requireTactical($patched['healthOverride'] === 'dead' && $patched['conditions'] === ['Empoisonné'], 'A player patch preserves the legacy MJ death override.');
requireTactical($patched['resources']['hp'] === -26 && $patched['resources']['mana'] === 0, 'Player patches preserve signed HP and nonnegative mana.');
requireTactical(onlineDiceAppearance(['frameVariant'=>'boss']) === ['color'=>'#000000','foreground'=>'#ffffff'], 'A boss has black dice with white digits.');
requireTactical(onlineDiceAppearance(['frameVariant'=>'boss','color'=>'#ff0000'], true, ['color'=>'#ffffff']) === ['color'=>'#ffffff','foreground'=>'#000000'], 'Player ownership wins over frame and uses character colour.');
requireTactical(array_keys(publicOnlineDiceAppearance(['color'=>'#ffffff','foreground'=>'#000000','secret'=>'do-not-project'])) === ['color','foreground'], 'Dice projection is a strict whitelist.');

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
requireTactical($response->status===200 && in_array($response->body['attack']['status'] ?? '',['missed','applied'],true), 'A KO creature can be attacked, with no opposition regardless of the actual attack roll: '.$response->getMessage());

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
require __DIR__ . '/token-groups-cases.php';
require __DIR__ . '/vision-stream-layers-cases.php';
fwrite(STDOUT, 'Cycle tactique PHP 3.2.0 : ' . $GLOBALS['checks'] . " contrôles réussis\n");
