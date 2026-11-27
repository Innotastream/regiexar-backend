<?php

declare(strict_types=1);

// Included by tactical-lifecycle.php: exercise real authority/transaction code.
function requireGmIdentity(PDO $connection): array
{
    $identity = $GLOBALS['testIdentity'];
    if (($identity['effective_mode'] ?? '') !== 'gm') sendError(403, 'MJ requis');
    return $identity;
}
function patchGroupDomains(MemoryConnection $db, array $payloads): TestResponse
{
    $GLOBALS['testIdentity'] = ['id' => 'account-gm', 'display_name' => 'MJ', 'effective_mode' => 'gm', 'permanent_role' => 'gm'];
    $changes = [];
    foreach ($payloads as $key => $payload) $changes[] = ['key' => $key, 'payload' => $payload, 'operation' => 'upsert', 'expectedRevision' => $db->domains[$key]['revision'] ?? 0];
    $GLOBALS['testBody'] = ['stateSchemaVersion' => XAR_SESSION_SCHEMA_VERSION, 'domainSchemaVersion' => 1, 'changes' => $changes];
    try { patchApplicationDomains($db); } catch (TestResponse $response) { return $response; }
    throw new RuntimeException('No domain response');
}
function groupArguments(MemoryConnection $db, array $extra = []): array
{
    $base = [];
    foreach (['token-player','token-monster'] as $id) {
        $token = $db->payload('token:scene-one:' . $id);
        $base[$id] = ['x' => $token['x'], 'y' => $token['y']];
    }
    return array_replace(['sceneId'=>'scene-one', 'layerId'=>'ground', 'targetLayerId'=>'ground',
        'tokenIds'=>['token-player','token-monster'], 'base'=>$base, 'dx'=>5, 'dy'=>3,
        'mapRevision'=>$db->domains['map:scene-one']['revision']], $extra);
}

// Directional targets are scene-local, floor-local and never inherited by a clone.
$targetDb = fixture();
$targetSource = $targetDb->payload('token:scene-one:token-player');
$targetSource['targetTokenId'] = 'token-monster';
$targetResponse = patchGroupDomains($targetDb, ['token:scene-one:token-player' => $targetSource]);
requireTactical($targetResponse->status === 200
    && ($targetDb->payload('token:scene-one:token-player')['targetTokenId'] ?? null) === 'token-monster',
    'A valid same-floor directional target persists.');
$targetMap = $targetDb->payload('map:scene-one');
$targetMap['vision'] = ['enabled' => false, 'shared' => true];
$targetMap['tokens'] = [
    $targetDb->payload('token:scene-one:token-player'),
    $targetDb->payload('token:scene-one:token-monster'),
];
$targetProjection = publicPlayerState([
    'characters' => [$targetDb->payload('character:character-player')],
    'activeSceneId' => 'scene-one', 'map' => $targetMap, 'initiative' => [],
], ['id' => 'account-player', 'display_name' => 'Player'], []);
requireTactical(($targetProjection['map']['tokens'][0]['targetTokenId'] ?? null) === 'token-monster',
    'The public projection exposes a target only when both endpoints are visible.');
$targetMoved = $targetDb->payload('token:scene-one:token-monster');
$targetMoved['layerId'] = 'upper';
$targetResponse = patchGroupDomains($targetDb, ['token:scene-one:token-monster' => $targetMoved]);
requireTactical($targetResponse->status === 200
    && ($targetDb->payload('token:scene-one:token-player')['targetTokenId'] ?? null) === null,
    'Moving the target to another floor clears the source relation atomically.');

$targetDb = fixture();
$targetDb->put('map:scene-one', ['activeLayerId' => 'ground', 'viewLocked' => true, 'naturalWidth' => 1000, 'naturalHeight' => 1000]);
$targetSource = $targetDb->payload('token:scene-one:token-player');
$targetSource['targetTokenId'] = 'token-monster';
$targetDb->put('token:scene-one:token-player', $targetSource);
$targetClone = runCommand($targetDb, 'token.clone', ['requestId' => 'target-clone-request-0001', 'sceneId' => 'scene-one', 'tokenId' => 'token-player'], true);
requireTactical($targetClone->status === 200 && ($targetClone->body['token']['targetTokenId'] ?? null) === null,
    'A clone never inherits the source directional target.');
$legacyClone = runCommand($targetDb, 'token.clone', ['sceneId' => 'scene-one', 'tokenId' => 'token-monster'], true);
requireTactical(
    $legacyClone->status === 200
        && !array_key_exists('deduplicated', $legacyClone->body)
        && count(array_filter(
            $targetDb->payload('activity')['resourceReceipts'] ?? [],
            static fn (mixed $entry): bool => is_array($entry) && ($entry['kind'] ?? '') === 'token-clone'
        )) === 1,
    'A legacy token.clone request without a request id remains accepted without forging a durable receipt.'
);

$db = fixture();
requireTactical(runCommand($db,'tokens.transform',groupArguments($db))->status === 403, 'Players cannot transform groups.');
$db->put('map:scene-one',['activeLayerId'=>'ground','viewLocked'=>false,'naturalWidth'=>1000,'naturalHeight'=>1000]);
$initialHp = $db->payload('token:scene-one:token-player')['hp'];
$request = groupArguments($db);
$response = runCommand($db,'tokens.transform',$request,true);
requireTactical($response->status === 200, 'The MJ can move a selected group independently of the legacy shared camera lock.');
requireTactical($db->payload('token:scene-one:token-player')['x'] == $request['base']['token-player']['x']+5 && $db->payload('token:scene-one:token-monster')['x'] == $request['base']['token-monster']['x']+5, 'The group preserves its formation.');
requireTactical($db->payload('token:scene-one:token-player')['hp'] === $initialHp, 'A positional command never rewrites HP from a stale snapshot.');
$before = $db->domains; $revision = $db->revision;
requireTactical(runCommand($db,'tokens.transform',$request,true,'second-gm')->status === 409 && $db->domains === $before && $db->revision === $revision, 'Two MJs using the same positions cannot move twice or partially.');
$stale = groupArguments($db, ['mapRevision'=>0]);
requireTactical(runCommand($db,'tokens.transform',$stale,true)->status === 409 && $db->domains === $before, 'A changed map revision refuses the complete group.');
foreach ([['tokenIds'=>[]],['tokenIds'=>['token-player','token-player']],['dx'=>'3'],['targetLayerId'=>'roof']] as $invalid) {
    requireTactical(runCommand($db,'tokens.transform',groupArguments($db,$invalid),true)->status === 400 && $db->domains === $before, 'Malformed group commands have no side effect.');
}
$response = runCommand($db,'tokens.transform',groupArguments($db,['targetLayerId'=>'upper','dx'=>0,'dy'=>0]),true);
requireTactical($response->status === 200 && $db->payload('token:scene-one:token-player')['layerId'] === 'upper', 'Elevation changes are atomic too.');
$map = $db->payload('map:scene-one');
$map['tokens'] = [$db->payload('token:scene-one:token-player'),$db->payload('token:scene-one:token-monster')];
$public = publicPlayerState(['characters'=>[$db->payload('character:character-player')],'activeSceneId'=>'scene-one','map'=>$map,'initiative'=>[]],['id'=>'account-player','display_name'=>'Player'],[]);
requireTactical($public['map']['tokens'] === [], 'Even an owned token is invisible from another floor.');
requireTactical(runCommand($db,'token.move',['sceneId'=>'scene-one','tokenId'=>'token-player','x'=>30,'y'=>30])->status === 409, 'An off-floor token cannot move.');
requireTactical(runCommand($db,'token.roll',['tokenId'=>'token-player','kind'=>'luck'])->status === 409, 'An off-floor token cannot roll on the map.');
requireTactical(runCommand($db,'token.attack',['requestId'=>'group-floor-attack-0001','sourceTokenId'=>'token-monster','targetTokenId'=>'token-player'],true)->status === 409, 'The MJ cannot attack through floors.');

// Old pions have no layer field: stamp the PREVIOUS floor before activating a new one.
$db = fixture();
$map = ['activeLayerId'=>'ground','viewLocked'=>true,'naturalWidth'=>1000,'naturalHeight'=>1000];
$db->put('map:scene-one',$map);
$response = patchGroupDomains($db,['map:scene-one'=>array_replace($map,['activeLayerId'=>'upper'])]);
requireTactical($response->status === 200, 'Legacy map changes are accepted.');
requireTactical($db->payload('token:scene-one:token-player')['layerId'] === 'ground' && $db->payload('token:scene-one:token-monster')['layerId'] === 'ground', 'Legacy tokens remain on their previous floor.');
$map['activeLayerId'] = 'ground'; $db->put('map:scene-one',$map);
requireTactical(runCommand($db,'token.clone',['sceneId'=>'scene-one','tokenId'=>'token-player'])->status === 403, 'Players cannot create clones.');
$clonePayload = ['requestId'=>'token-clone-request-0001','sceneId'=>'scene-one','tokenId'=>'token-player'];
$response = runCommand($db,'token.clone',$clonePayload,true);
requireTactical($response->status === 200, 'The MJ explicitly creates a clone.');
$clone = $response->body['token']; $cloneKey = 'token:scene-one:' . $clone['id'];
requireTactical($clone['characterId'] === null && $clone['followCharacter'] === false && $clone['cloneSourceCharacterId'] === 'character-player', 'The clone has a distinct identity with provenance only.');
requireTactical($clone['hp'] == 10 && $clone['name'] === 'Personnage' && $clone['controllerPlayerId'] === 'account-player', 'The clone uses the current sheet at invocation, not stale token HP.');
requireTactical($clone['x'] !== $db->payload('token:scene-one:token-player')['x'], 'A clone is placed beside its source.');
$cloneRevision = $db->revision;
$cloneActions = count($db->payload('activity')['playerActions'] ?? []);
$cloneRetry = runCommand($db, 'token.clone', $clonePayload, true);
requireTactical(
    $cloneRetry->status === 200
        && ($cloneRetry->body['deduplicated'] ?? false) === true
        && ($cloneRetry->body['token']['id'] ?? '') === $clone['id']
        && $db->revision === $cloneRevision
        && count($db->payload('token-index:scene-one')['order'] ?? []) === 4
        && count($db->payload('activity')['playerActions'] ?? []) === $cloneActions,
    'A lost token.clone response can be replayed without a second clone or journal entry.'
);
$cloneMismatch = runCommand($db, 'token.clone', [...$clonePayload, 'tokenId' => 'token-monster'], true);
requireTactical(
    $cloneMismatch->status === 409
        && ($cloneMismatch->body['code'] ?? '') === 'token_clone_request_mismatch'
        && $db->revision === $cloneRevision,
    'A token.clone request id cannot be reused for another source.'
);
$character = $db->payload('character:character-player');
$character['resources']['hp'] = -20; $character['name'] = 'Nouveau nom'; $character['conditions'] = ['Empoisonné'];
$response = patchGroupDomains($db,['character:character-player'=>$character]);
requireTactical($response->status === 200, 'The generic MJ character patch succeeds.');
foreach (['token:scene-one:token-player','token:scene-two:token-copy'] as $key) {
    $pion = $db->payload($key);
    requireTactical($pion['hp'] == -20 && $pion['name'] === 'Nouveau nom' && $pion['conditions'] === ['Empoisonné'], 'Sheet fields propagate across all scenes.');
    requireTactical($pion['x'] == 20 && $pion['y'] == 50, 'Scene positions stay independent from sheet fields.');
}
requireTactical($db->payload($cloneKey)['hp'] == 10 && $db->payload($cloneKey)['conditions'] === [], 'The explicit clone is not synchronized back to the source.');
requireTactical($db->payload('token:scene-one:token-independent')['hp'] == 40, 'A deliberately detached legacy copy keeps its values.');
$response = runCommand($db,'character.patch',['characterId'=>'character-player','patch'=>['resources'=>['hp'=>35,'maxHp'=>100,'mana'=>5,'maxMana'=>10]]]);
requireTactical($response->status === 200 && $db->payload('token:scene-two:token-copy')['hp'] == 35 && $db->payload($cloneKey)['hp'] == 10, 'Player sheet changes synchronize all scenes while respecting the clone.');

// Existing close formations and hidden neighbours follow the same spacing rule
// as an individual move, including the destination of a floor transfer.
$closeMap = ['viewLocked'=>true,'activeLayerId'=>'ground','naturalWidth'=>1000,'naturalHeight'=>1000,
    'layers'=>['upper'=>['naturalWidth'=>1000,'naturalHeight'=>1000]],
    'tokens'=>[['id'=>'a','x'=>40,'y'=>40,'size'=>80,'layerId'=>'ground'],['id'=>'b','x'=>45,'y'=>40,'size'=>80,'layerId'=>'ground']]];
$closeRequest = ['tokenIds'=>['a','b'],'layerId'=>'ground','base'=>['a'=>['x'=>40,'y'=>40],'b'=>['x'=>45,'y'=>40]],'dx'=>10,'dy'=>0];
foreach (['ground','upper'] as $targetLayer) {
    $plan = planApplicationTokenGroupTransform($closeMap, [...$closeRequest,'targetLayerId'=>$targetLayer]);
    requireTactical($plan[0]['x']==50 && $plan[1]['x']==55 && !$plan[0]['relocated'] && !$plan[1]['relocated'], 'A close group keeps its actual formation on '.$targetLayer.'.');
}
$floorRequest=['targetLayerId'=>'upper','tokens'=>$closeMap['tokens']];
$plan=planApplicationTokenLayers($closeMap,$floorRequest);
requireTactical($plan[0]['x']==40 && $plan[1]['x']==45 && !$plan[1]['relocated'], 'A close group keeps its partial overlap during a floor transfer.');
foreach (['hidden','fog'] as $concealment) {
    $map=$closeMap;$map['tokens']=[['id'=>'a','x'=>40,'y'=>50,'size'=>30,'layerId'=>'ground'],['id'=>'obstacle','x'=>50,'y'=>50,'size'=>220,'layerId'=>'ground','hidden'=>$concealment==='hidden']];
    $fog=['version'=>1,'enabled'=>$concealment==='fog','width'=>512,'height'=>512,'mask'=>rtrim(strtr(base64_encode(str_repeat("\xff",32768)),'+/','-_'),'=')];
    $map['fog']=$fog;
    $plan=planApplicationTokenGroupTransform($map,['tokenIds'=>['a'],'layerId'=>'ground','base'=>['a'=>['x'=>40,'y'=>50]],'dx'=>10,'dy'=>0]);
    requireTactical($plan[0]['x']==50 && $plan[0]['y']==50 && !$plan[0]['relocated'], 'A '.$concealment.' neighbour never repels a group.');
    $map['tokens'][0]['x']=50;$map['tokens'][1]['layerId']='upper';$map['layers']['upper']['fog']=$fog;
    $plan=planApplicationTokenLayers($map,['targetLayerId'=>'upper','tokens'=>[$map['tokens'][0]]]);
    requireTactical($plan[0]['x']==50 && $plan[0]['y']==50 && !$plan[0]['relocated'], 'A '.$concealment.' neighbour never repels a floor transfer.');
}
$closeMap['tokens'][0]['immovable']=true;
foreach (['group','floor'] as $kind) {
    $refused=false;
    try { if($kind==='group')planApplicationTokenGroupTransform($closeMap,$closeRequest);else planApplicationTokenLayers($closeMap,$floorRequest); }
    catch(DomainException $error){$refused=$error->getCode()===409;}
    requireTactical($refused,'The native '.$kind.' authority refuses an immovable token atomically.');
}

// Geometric parity is shared with the JavaScript local authority, at 1/10000 percent.
foreach (json_decode(file_get_contents(__DIR__ . '/token-group-geometry.json'), true, 512, JSON_THROW_ON_ERROR) as $case) {
    $result = planApplicationTokenGroupTransform($case['map'], $case['request']);
    $geometry=array_map(static fn($p)=>array_intersect_key($p,array_flip(['id','x','y','layerId','relocated'])),$result);
    requireTactical(json_encode($geometry) === json_encode($case['expected']), 'PHP/JS group geometry parity: ' . $case['name']);
}

// The editor may never have seen a token document that dropped out of a scene index.
$db = fixture();
$db->put('token-index:scene-two', ['order'=>['token-copy']]);
$db->put('token:scene-two:token-independent', $db->payload('token:scene-one:token-independent'));
$db->put('initiative:scene-two', ['order'=>['token-copy']]);
$db->put('presentation:scene-two', ['map'=>[], 'initiative'=>[]]);
$portal = ['id'=>'return-marker','revision'=>3,'name'=>'Vers la seconde salle','kind'=>'stairs','layerId'=>'ground','x'=>53,'y'=>45,
    'destination'=>['sceneId'=>'scene-two','passageId'=>'outgoing-marker']];
$reverse = [...$portal,'id'=>'outgoing-marker','name'=>'Vers le hall','destination'=>['sceneId'=>'scene-one','passageId'=>'return-marker']];
$secondMarker = [...$portal,'id'=>'unrelated-marker','destination'=>null];
$db->put('map:scene-one',[...$db->payload('map:scene-one'),'passages'=>[$portal,$secondMarker]]);
$db->put('map:scene-two',[...$db->payload('map:scene-two'),'passages'=>[$reverse]]);
$db->put('devices',[...dsEmpty(),'manualTurns'=>['scene-one'=>4,'scene-two'=>15],
    'elementalSaveTurns'=>['scene-one'=>['unchanged'=>1],'scene-two'=>['orphan'=>2]],
    'poisonSaveTurns'=>['scene-two'=>['orphan'=>3]]]);
$db->put('items', ['version'=>1, 'templates'=>[], 'instances'=>[],
    'inventoryCatalog'=>['token-independent'=>['legacy-model-id']]]);
$preserved = array_map(static fn(string $key): array => $db->payload($key),
    ['token:scene-one:token-independent','character:character-player','items','table','activity']);
$removeScene = static function (array $changes, ?MemoryConnection $campaign = null) use ($db): TestResponse {
    $GLOBALS['testIdentity'] = ['id'=>'account-gm','display_name'=>'MJ','effective_mode'=>'gm','permanent_role'=>'gm'];
    $GLOBALS['testBody'] = ['stateSchemaVersion'=>XAR_SESSION_SCHEMA_VERSION,'domainSchemaVersion'=>XAR_DOMAIN_SCHEMA_VERSION,'changes'=>$changes];
    try { patchApplicationDomains($campaign ?? $db); } catch (TestResponse $response) { return $response; }
    throw new RuntimeException('No domain response');
};
$delete = static fn(string $key): array => ['key'=>$key,'operation'=>'delete','expectedRevision'=>1];
$request = [
    ['key'=>'scene-index','operation'=>'upsert','expectedRevision'=>1,'payload'=>['order'=>['scene-one']]],
    $delete('scene:scene-two'),
];
$blocked = $removeScene($request);
requireTactical($blocked->status===409 && isset($db->domains['scene:scene-two'], $db->domains['token:scene-two:token-independent']),
    'A stale or incomplete request cannot delete a scene whose indexed tokens were not confirmed.');
$removed = $removeScene([...$request, $delete('token-index:scene-two'), $delete('token:scene-two:token-copy')]);
requireTactical($removed->status===200, 'The scene removal commits: ' . json_encode($removed->body));
foreach (['scene:scene-two','map:scene-two','initiative:scene-two','presentation:scene-two','token-index:scene-two',
    'token:scene-two:token-copy','token:scene-two:token-independent'] as $key) {
    requireTactical(!isset($db->domains[$key]), 'Scene removal also deletes '.$key);
}
requireTactical(array_map(static fn(string $key): array => $db->payload($key),
    ['token:scene-one:token-independent','character:character-player','items','table','activity'])===$preserved,
    'Other scenes, inventories, characters, the broadcast table and the activity journal are preserved.');
requireTactical($db->payload('map:scene-one')['passages'] === [[...$portal,'destination'=>null,'revision'=>4],$secondMarker],
    'Deleting a scene detaches the reciprocal passage and leaves the marker and unrelated exits intact.');
requireTactical($db->payload('devices')['manualTurns'] === ['scene-one'=>4]
    && $db->payload('devices')['elementalSaveTurns'] === ['scene-one'=>['unchanged'=>1]]
    && !isset($db->payload('devices')['poisonSaveTurns']),
    'Deleting a scene retires its free-turn clock and saving clocks without affecting the other scene.');
$stale = $removeScene([['key'=>'token:scene-two:token-independent','operation'=>'upsert','expectedRevision'=>0,
    'payload'=>$db->payload('token:scene-one:token-independent')]]);
requireTactical($stale->status===409 && !isset($db->domains['token:scene-two:token-independent']),
    'A stale editor cannot recreate an orphan token after removal.');

$campaign = fixture();
$target = $campaign->payload('token:scene-one:token-monster');
$campaign->put('token:scene-two:token-room-target',[...$target,'id'=>'token-room-target']);
$campaign->put('token-index:scene-two',['order'=>['token-copy','token-room-target']]);
$campaign->put('initiative:scene-two',['active'=>false,'order'=>[]]);
$model = ['id'=>'scene-delete-item-template','name'=>'Potion d’opposition de recette','kind'=>'potion','active'=>[
    'version'=>1,'effects'=>[['kind'=>'damage','components'=>[['type'=>'ignore','formula'=>'10']]]],
    'targeting'=>['mode'=>'single','rangeCells'=>10],
]];
$keptItem = ['id'=>'item-room-activation','templateId'=>$model['id'],'revision'=>1,
    'location'=>['kind'=>'inventory','characterId'=>'character-player']];
$campaign->put('items',normalizeItemStore(['version'=>1,'templates'=>[$model],
    'instances'=>[$keptItem,[...$keptItem,'id'=>'item-hall-activation']]]));
$roomUse = ['requestId'=>'prepare-room-item-delete','sceneId'=>'scene-two','tokenId'=>'token-copy','targetTokenId'=>'token-room-target',
    'itemId'=>'item-room-activation','characterId'=>'character-player','expectedItemRevision'=>1,'opposed'=>true];
$hallUse = [...$roomUse,'requestId'=>'prepare-hall-item-survives','sceneId'=>'scene-one','tokenId'=>'token-player',
    'targetTokenId'=>'token-monster','itemId'=>'item-hall-activation'];
$heldRoom = runCommand($campaign,'item.activate',$roomUse,true);
$heldHall = runCommand($campaign,'item.activate',$hallUse,true);
requireTactical($heldRoom->status===200 && $heldHall->status===200
    && count($campaign->payload('items')['pendingUses'])===2,
    'Native item activation leaves two pending possessions before removing a scene: '.json_encode([$heldRoom->body,$heldHall->body]));
$legacyCampaign = fixture();
$legacyCampaign->put('table',['activeSceneId'=>null]);
$legacyCampaign->put('scene-index',['order'=>[]]);
$legacyItems = $campaign->payload('items');
$legacyCampaign->put('items',$legacyItems);
$cancelArgs = ['requestId'=>'cancel-stale-item-native','sceneId'=>'scene-two','useId'=>$heldRoom->body['pendingUse']];
$cancelAsPlayer = runCommand($legacyCampaign,'item.use.cancel',$cancelArgs);
requireTactical($cancelAsPlayer->status===403 && $legacyCampaign->payload('items')===$legacyItems,
    'Without a scene, a player still cannot cancel an orphaned activation.');
$cancel = runCommand($legacyCampaign,'item.use.cancel',$cancelArgs,true);
$replayCancel = runCommand($legacyCampaign,'item.use.cancel',$cancelArgs,true);
requireTactical($cancel->status===200 && $cancel->body['cancelled'] && ($replayCancel->body['deduplicated'] ?? false)
    && array_column($legacyCampaign->payload('items')['pendingUses'],'id')===[$heldHall->body['pendingUse']]
    && $legacyCampaign->payload('items')['instances'] === $legacyItems['instances'],
    'The GM can retire and replay an old orphaned activation without a surviving scene or modifying the items.');
$itemInstances = $campaign->payload('items')['instances'];
$sceneDeletion = $removeScene([...$request, $delete('token-index:scene-two'), $delete('token:scene-two:token-copy'),
    $delete('token:scene-two:token-room-target')],$campaign);
requireTactical($sceneDeletion->status===200
    && array_column($campaign->payload('items')['pendingUses'],'id')===[$heldHall->body['pendingUse']]
    && $campaign->payload('items')['instances'] === $itemInstances,
    'Deleting the scene retires the obsolete item activation without consuming either inventory item or clearing the unrelated pending use.');
$newUse = runCommand($campaign,'item.activate',[...$hallUse,'itemId'=>'item-room-activation','requestId'=>'reuse-item-after-scene-removed','opposed'=>false],true);
requireTactical($newUse->status===200 && $campaign->payload('token:scene-one:token-monster')['hp']===30,
    'The original inventory item can immediately be used again after the scene deletion: '.json_encode($newUse->body));
