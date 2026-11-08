<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY', true);
require __DIR__.'/tactical-special-effects.php';

$reservationChecks = 0;
function reservationCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    $GLOBALS['reservationChecks']++;
}

function reservationFixture(): array {
    $db = specialFixture();
    $character = $db->payload('character:character-player');
    $character['stats']['dexterity'] = 100;
    $db->put('character:character-player', $character);
    $source = $db->payload('token:scene-one:token-player');
    $source['x'] = 52; $source['y'] = 55;
    $db->put('token:scene-one:token-player', $source);
    $door = applicationCreateTerrainBarrierToken(
        ['id'=>'door', 'terrainBarrier'=>['version'=>1,'shape'=>'door','hp'=>50,'size'=>40]],
        ['x'=>50,'y'=>50,'rotation'=>0], 'reserved-door');
    $door['terrainBarrier']['locked'] = true;
    $db->put('token:scene-one:reserved-door', $door);
    $index = $db->payload('token-index:scene-one'); $index['order'][] = $door['id'];
    $db->put('token-index:scene-one', $index);
    $args = ['sceneId'=>'scene-one','layerId'=>'ground','tokenId'=>$door['id'],'sourceTokenId'=>$source['id']];
    $created = runCommand($db, 'door.interact', [...$args,'action'=>'spawn-pick','recipientCharacterId'=>'character-player'], true);
    reservationCheck($created->status === 200, 'The fixture creates a real lockpick');
    $tool = $created->body['key'];
    $pending = ['id'=>'reserved-validation','type'=>'door-pick','sceneId'=>'scene-one',
        'doorId'=>$door['id'],'tokenId'=>$source['id'],'ownerId'=>'character-player','accountId'=>'account-player',
        'requestId'=>'reserved-earlier-request','toolId'=>$tool['id'],'toolRevision'=>$tool['revision'],
        'doorCheckpoint'=>applicationDoorPickCheckpoint($door),
        'sourceCheckpoint'=>applicationKeyholeMovementCheckpoint($source, $db->payload('map:scene-one')),
        'cast'=>['success'=>true,'pendingGmApproval'=>true]];
    return [$db, $args, $pending];
}

foreach (['same-door', 'reserved-tool', 'full-queue'] as $scenario) {
    [$db, $args, $pending] = reservationFixture();
    $store = dsEmpty();
    if ($scenario === 'reserved-tool') $pending['doorId'] = 'another-door';
    if ($scenario === 'full-queue') {
        for ($i = 0; $i < 1000; $i++) $store['pendingRolls'][] = [...$pending,
            'id'=>'validation-'.$i,'doorId'=>'another-door-'.$i,'toolId'=>'another-tool-'.$i];
    } else $store['pendingRolls'][] = $pending;
    $db->put('devices', $store);
    $before = $db->domains;
    $revision = $db->revision;
    $response = runCommand($db, 'door.interact', [...$args,'action'=>'pick','requestId'=>'reserved-new-request']);
    reservationCheck($response->status === ($scenario === 'full-queue' ? 429 : 409),
        $scenario.' must reject before another roll, got '.json_encode($response->body));
    reservationCheck($db->domains === $before && $db->revision === $revision,
        $scenario.' leaves the door, tool, pending decision, roll history and clock unchanged');
}

[$db, $args, $pending] = reservationFixture();
$store = dsEmpty(); $store['pendingRolls'][] = $pending; $db->put('devices', $store);
$door = $db->payload('token:scene-one:reserved-door');
$recorded = ['pendingValidation'=>true, 'validationId'=>$pending['id'], 'success'=>true];
$door['terrainBarrier']['pickReceipts'][] = ['id'=>$pending['requestId'], 'sourceId'=>'token-player','attempt'=>$recorded];
$db->put('token:scene-one:reserved-door', $door);
$before = $db->domains;
$retry = runCommand($db, 'door.interact', [...$args,'action'=>'pick','requestId'=>$pending['requestId']]);
reservationCheck($retry->status === 200 && !empty($retry->body['deduplicated']) && $retry->body['attempt'] === $recorded,
    'A retry of the existing pending request returns its original decision receipt');
reservationCheck($db->domains === $before, 'An existing receipt does not consume another lockpick or queue a second roll');

echo json_encode(['ok'=>true,'checks'=>$reservationChecks], JSON_THROW_ON_ERROR)."\n";
