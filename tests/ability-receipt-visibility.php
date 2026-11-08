<?php
declare(strict_types=1);
define('XAR_TACTICAL_FIXTURE_ONLY', true);
require __DIR__ . '/tactical-lifecycle.php';

function complexReceiptCommand(MemoryConnection $db, array $arguments, bool $gm, string $account = 'account-player'): TestResponse {
    // Keep the permanent GM role: only the effective play mode changes.
    $GLOBALS['testIdentity'] = ['id'=>$account,'display_name'=>'Synthetic GM','permanent_role'=>'gm',
        'effective_mode'=>$gm?'gm':'player','can_administrate'=>false];
    $GLOBALS['testBody'] = ['command'=>'ability.complex','payload'=>$arguments];
    try { commandOnlineState($db, []); } catch (TestResponse $response) { return $response; }
    throw new RuntimeException('Missing response');
}
function complexReceiptFixture(): MemoryConnection {
    $db = fixture();
    $character = $db->payload('character:character-player');
    $character['abilities'] = [['id'=>'private-complex','name'=>'Synthetic phase','effect'=>'complex','formula'=>'0',
        'castingStatId'=>'','manaCost'=>2,'workflow'=>['version'=>1,'steps'=>[
            ['id'=>'instruction','type'=>'instruction','title'=>'Phase','description'=>'SYNTHETIC_PRIVATE_PHASE']]]]];
    $db->put('character:character-player', $character);
    return $db;
}
function complexReceiptArguments(string $id): array {
    return ['action'=>'start','requestId'=>$id,'sceneId'=>'scene-one','sourceTokenId'=>'token-player','abilityId'=>'private-complex'];
}
function complexReceiptState(MemoryConnection $db): string { return serialize([$db->domains,$db->revision]); }
function complexReceiptRejectedWithoutWrite(MemoryConnection $db, array $args, string $account, string $message): void {
    $before = complexReceiptState($db);
    $result = complexReceiptCommand($db, $args, false, $account);
    requireTactical($result->status === 403 && !isset($result->body['execution']), $message);
    requireTactical(complexReceiptState($db) === $before, $message . ': no write or second payment');
}

$db = complexReceiptFixture(); $args = complexReceiptArguments('complex-private-receipt-0001');
$source = $db->payload('token:scene-one:token-player'); $source['hidden'] = true; $db->put('token:scene-one:token-player', $source);
$first = complexReceiptCommand($db, $args, true, 'account-gm');
requireTactical($first->status === 200 && $first->body['rolls'] === [] && $first->body['cast']['roll'] === null,
    'A GM can create a private workflow without a casting roll');
complexReceiptRejectedWithoutWrite($db, $args, 'account-gm', 'A permanent GM in Player mode cannot replay a private GM workflow');
$before = complexReceiptState($db); $gmReplay = complexReceiptCommand($db, $args, true, 'account-gm');
requireTactical($gmReplay->status === 200 && $gmReplay->body['deduplicated'] === true
    && $gmReplay->body['execution'] == $first->body['execution'] && complexReceiptState($db) === $before,
    'GM replay keeps its complete historical result without executing again');
$activity = $db->payload('activity'); unset($activity['resourceReceipts'][0]['authoritySignature']); $db->put('activity', $activity);
complexReceiptRejectedWithoutWrite($db, $args, 'account-gm', 'An older receipt without an authority marker still checks current source access');

$db = complexReceiptFixture(); $args = complexReceiptArguments('complex-player-receipt-0001');
$first = complexReceiptCommand($db, $args, false);
requireTactical($first->status === 200 && $db->payload('character:character-player')['resources']['mana'] === 3,
    'An owned player workflow pays its cost once');
$before = complexReceiptState($db); $replay = complexReceiptCommand($db, $args, false);
requireTactical($replay->status === 200 && $replay->body['deduplicated'] === true && $replay->body['execution']['ownedByYou'] === true
    && complexReceiptState($db) === $before, 'The legitimate player replay is projected without another cost or revision');
$character = $db->payload('character:character-player'); $character['ownerPlayerId'] = 'new-owner'; $db->put('character:character-player', $character);
complexReceiptRejectedWithoutWrite($db, $args, 'account-player', 'A launch controller cannot replay after the unit is transferred');
$character['ownerPlayerId'] = 'account-player'; $db->put('character:character-player', $character);
$source = $db->payload('token:scene-one:token-player'); $source['hidden'] = true; $db->put('token:scene-one:token-player', $source);
complexReceiptRejectedWithoutWrite($db, $args, 'account-player', 'A newly hidden source cannot be recovered through its old receipt');
$source['hidden'] = false; $db->put('token:scene-one:token-player', $source);
$table = $db->payload('table'); $table['activeSceneId'] = 'scene-two'; $db->put('table', $table);
complexReceiptRejectedWithoutWrite($db, $args, 'account-player', 'An unpublished previous scene does not leak through a receipt');
$table['activeSceneId'] = 'scene-one'; $db->put('table', $table);
complexReceiptRejectedWithoutWrite($db, $args, 'another-account', 'The receipt remains bound to its original account');
$before = complexReceiptState($db); $replay = complexReceiptCommand($db, $args, true);
requireTactical($replay->status === 200 && $replay->body['deduplicated'] && complexReceiptState($db) === $before,
    'A player receipt remains replayable after returning to GM mode');
$command = ['action'=>'command','requestId'=>'complex-command-receipt-001','executionId'=>$first->body['execution']['id'],
    'expectedRevision'=>1,'command'=>['action'=>'acknowledge']];
$ack = complexReceiptCommand($db, $command, false);
requireTactical($ack->status === 200 && $ack->body['execution']['status'] === 'completed', 'The no-roll workflow command completes normally');
$before = complexReceiptState($db); $again = complexReceiptCommand($db, $command, false);
requireTactical($again->status === 200 && $again->body['deduplicated'] && complexReceiptState($db) === $before,
    'A completed workflow command remains idempotent');
$mismatch = complexReceiptCommand($db, [...$command,'command'=>['action'=>'cancel']], false);
requireTactical($mismatch->status === 409 && complexReceiptState($db) === $before, 'Request signatures still reject a changed command');

// A completed defender's receipt is already a participant projection. Current
// control of that defender must preserve the replay, then revoke it on transfer.
$db = complexReceiptFixture(); $now = (int) floor(microtime(true) * 1000);
$execution = createApplicationComplexAbilityExecution(['id'=>'participant-execution','sceneId'=>'scene-one',
    'sourceTokenId'=>'token-monster','controllerAccountId'=>'monster-owner','ability'=>[
        'id'=>'defense-workflow','name'=>'Defense','effect'=>'complex','formula'=>'0','workflow'=>['version'=>1,'steps'=>[
            ['id'=>'selection','type'=>'targets','minTargets'=>1,'maxTargets'=>2,'allocationTotal'=>2],
            ['id'=>'defenses','type'=>'defense-series','sourceStepId'=>'selection','thresholdMode'=>'fixed','fixedThreshold'=>50]]]],'now'=>$now]);
$execution['currentStepIndex'] = 2; $execution['status'] = 'completed';
$execution['stepStates'] = ['selection'=>['status'=>'completed','allocations'=>[['tokenId'=>'token-player','amount'=>1],['tokenId'=>'token-monster-two','amount'=>1]]],
    'defenses'=>['status'=>'completed','targets'=>[['tokenId'=>'token-player','status'=>'completed'],['tokenId'=>'token-monster-two','status'=>'completed']]]];
$projected = publicApplicationComplexAbilityExecution($execution,'account-player',false,[['id'=>'token-player','controllerAccountId'=>'account-player']], 'token-player');
requireTactical(is_array($projected) && $projected['participantOnly'] && count($projected['stepStates']['defenses']['targets']) === 1,
    'The original defender receipt has a limited projection');
$args = ['action'=>'command','executionId'=>'participant-execution','expectedRevision'=>1,
    'requestId'=>'complex-defender-receipt-001','command'=>['action'=>'roll-defense']];
$activity = $db->payload('activity'); $activity['resourceReceipts'] = [[
    'kind'=>'ability-workflow','requestId'=>$args['requestId'],'accountId'=>'account-player','actionId'=>'synthetic-action',
    'sourceKey'=>'participant-execution','expiresAt'=>$now+600000,'authoritySignature'=>'player',
    'requestSignature'=>onlineComplexAbilityRequestSignature($args),'result'=>['effect'=>'complex','execution'=>$projected,'rolls'=>[]]]];
$db->put('activity',$activity); $before = complexReceiptState($db);
$replay = complexReceiptCommand($db,$args,false);
requireTactical($replay->status === 200 && $replay->body['deduplicated'] && $replay->body['execution']['participantOnly']
    && count($replay->body['execution']['stepStates']['defenses']['targets']) === 1 && complexReceiptState($db) === $before,
    'A defender can replay a completed step without receiving other targets');
$source=$db->payload('token:scene-one:token-monster');$source['hidden']=true;$db->put('token:scene-one:token-monster',$source);$before=complexReceiptState($db);
$replay=complexReceiptCommand($db,$args,false);
requireTactical($replay->status===200 && $replay->body['execution']['participantOnly']
    && count($replay->body['execution']['stepStates']['defenses']['targets'])===1 && complexReceiptState($db)===$before,
    'A defender keeps its own limited receipt when the caster is hidden');
$character=$db->payload('character:character-player');$character['ownerPlayerId']='new-owner';$db->put('character:character-player',$character);
complexReceiptRejectedWithoutWrite($db,$args,'account-player','A defender receipt loses access when the defender is transferred');

// Deferred complex starts use attack receipts, not resource receipts. Cover the
// same authority boundary and project their execution before returning it.
$db=complexReceiptFixture();$args=complexReceiptArguments('complex-deferred-receipt-001');
$execution=createApplicationComplexAbilityExecution(['id'=>'deferred-execution','sceneId'=>'scene-one','sourceTokenId'=>'token-player',
    'controllerAccountId'=>'account-player','ability'=>$db->payload('character:character-player')['abilities'][0],'now'=>$now]);
$execution['defenseGate']=['targetTokenIds'=>['token-monster'],'parties'=>['secret'=>'SYNTHETIC_DEFENSE_PARTIES']];
$attack=['id'=>'deferred-attack','requestId'=>$args['requestId'],'sceneId'=>'scene-one','layerId'=>'ground','sourceTokenId'=>'token-player',
    'targetTokenId'=>'token-monster','accountId'=>'account-player','attackerRole'=>'player','status'=>'resolved','visibility'=>'public',
    'areaGate'=>['kind'=>'complex-start','executionId'=>'deferred-execution']];
$activity=$db->payload('activity');$activity['abilityExecutions']=[$execution];$activity['attackReceipts']=[[
    'requestId'=>$args['requestId'],'accountId'=>'account-player','requestSignature'=>onlineComplexAbilityRequestSignature($args),
    'expiresAt'=>$now+600000,'attack'=>$attack]];$db->put('activity',$activity);$before=complexReceiptState($db);
$replay=complexReceiptCommand($db,$args,false);
requireTactical($replay->status===200 && $replay->body['deduplicated'] && !isset($replay->body['execution']['defenseGate']['parties'])
    && complexReceiptState($db)===$before,'A deferred player receipt strips private defense parties without writing');
$activity=$db->payload('activity');$activity['attackReceipts'][0]['attack']['attackerRole']='gm';$db->put('activity',$activity);
complexReceiptRejectedWithoutWrite($db,$args,'account-player','A deferred GM start cannot be replayed in Player mode');
$activity=$db->payload('activity');$activity['attackReceipts'][0]['attack']['attackerRole']='player';$activity['attackReceipts'][0]['attack']['status']='pending';
unset($activity['attackReceipts'][0]['attack']['areaGate']['executionId']);$db->put('activity',$activity);$before=complexReceiptState($db);
$replay=complexReceiptCommand($db,$args,false);
requireTactical($replay->status===200 && $replay->body['pendingAreaGate'] && $replay->body['execution']===null && complexReceiptState($db)===$before,
    'An owned pending defense gate remains replayable before an execution exists');
$character=$db->payload('character:character-player');$character['ownerPlayerId']='new-owner';$db->put('character:character-player',$character);
complexReceiptRejectedWithoutWrite($db,$args,'account-player','A pending defense gate also checks current control');

echo 'Complex ability receipt visibility passed (' . $GLOBALS['checks'] . " checks).\n";
