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
    $GLOBALS['testBody'] = ['stateSchemaVersion' => 16, 'domainSchemaVersion' => 1, 'changes' => $changes];
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
requireTactical(runCommand($db,'tokens.transform',groupArguments($db),true)->status === 423, 'An unlocked map refuses groups.');
$db->put('map:scene-one',['activeLayerId'=>'ground','viewLocked'=>true,'naturalWidth'=>1000,'naturalHeight'=>1000]);
$initialHp = $db->payload('token:scene-one:token-player')['hp'];
$request = groupArguments($db);
$response = runCommand($db,'tokens.transform',$request,true);
requireTactical($response->status === 200, 'The MJ can move a selected group.');
requireTactical($db->payload('token:scene-one:token-player')['x'] == 25 && $db->payload('token:scene-one:token-monster')['x'] == 55, 'The group preserves its formation.');
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

// Geometric parity is shared with the JavaScript local authority, at 1/10000 percent.
foreach (json_decode(file_get_contents(__DIR__ . '/token-group-geometry.json'), true, 512, JSON_THROW_ON_ERROR) as $case) {
    $result = planApplicationTokenGroupTransform($case['map'], $case['request']);
    requireTactical(json_encode($result) === json_encode($case['expected']), 'PHP/JS group geometry parity: ' . $case['name']);
}
