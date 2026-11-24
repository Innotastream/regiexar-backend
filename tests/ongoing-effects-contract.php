<?php
declare(strict_types=1);
define('XAR_TACTICAL_FIXTURE_ONLY',true);
require __DIR__ . '/tactical-lifecycle.php';

function effectFixture(array $dots): MemoryConnection {
    $db = fixture();
    $activity = $db->payload('activity');
    $activity['damageOverTime'] = $dots;
    $db->put('activity', $activity);
    return $db;
}
function endEffectCombat(MemoryConnection $db): array {
    $records = applicationDomainRecords($db);
    $pending = [];
    queueOnlineDomainUpsert($pending, $records, 'initiative:scene-one', [...$db->payload('initiative:scene-one'), 'active' => false]);
    applyApplicationDamageOverTimeOnTurn($db, $records, $pending, ['id' => 'account-gm', 'display_name' => 'MJ', 'effective_mode' => 'gm', 'permanent_role' => 'gm']);
    foreach ($pending as $key => $change) $db->put($key, $change['payload']);
    return $db->payload('activity');
}
// Compatibility fixture for remainder choices stored by an older version.
// New combat endings must never create these entries.
function historicalRemainder(MemoryConnection $db): void {
    $records=applicationDomainRecords($db);$pending=[];$activity=$db->payload('activity');
    onlineCaptureEndedDots($db,$records,$pending,$activity,['scene-one'=>true]);
    $activity['damageOverTime']=[];$db->put('activity',$activity);
}
$dot = ['id' => 'dot-contract-one', 'sceneId' => 'scene-one', 'targetTokenId' => 'token-monster', 'label' => 'Poison', 'formula' => '10', 'damageType' => 'ignore', 'remainingTurns' => 2];
requireTactical(onlineRemainingDotFormula('1d10', 2) === '2d10' && onlineRemainingDotFormula('2d6+3-1d4', 3) === '6d6+9-3d4', 'Remaining dice and constants are multiplied');
$db = effectFixture([$dot, [...$dot, 'id' => 'dot-contract-two']]);
$body = ['effectId' => $dot['id'], 'kind' => 'dot', 'requestId' => 'remove-effect-contract-0001'];
requireTactical(runCommand($db, 'combat.effect.remove', $body)->status === 403, 'Players cannot remove server effects');
requireTactical(runCommand($db, 'combat.effect.remove', $body, true, 'account-gm')->status === 200, 'GM removes exactly one effect');
requireTactical(count($db->payload('activity')['damageOverTime']) === 1, 'Other DOT remains untouched');
requireTactical(runCommand($db, 'combat.effect.remove', $body, true, 'account-gm')->body['deduplicated'] === true, 'Removal retry is idempotent');
$activity = endEffectCombat($db);
requireTactical($activity['damageOverTime'] === [] && $activity['pendingDotResolutions'] === [] && (int)$db->payload('token:scene-one:token-monster')['hp']===40, 'Combat end expires DOT without a remaining-damage prompt or HP change');
$stale = preserveApplicationAbilityExtensions('activity', ['damageOverTime' => [$dot], 'pendingDotResolutions' => [], 'resourceReceipts' => []], $activity);
requireTactical($stale['damageOverTime'] === [] && $stale['pendingDotResolutions'] === [] && count($stale['resourceReceipts']) === 1, 'Stale activity cannot restore expired DOT or erase receipts');
$db=effectFixture([[...$dot,'id'=>'dot-contract-two']]);historicalRemainder($db);
$body = ['effectId' => 'dot-contract-two', 'decision' => 'roll', 'requestId' => 'resolve-effect-contract-0001'];
$resolved = runCommand($db, 'combat.dot.resolve', $body, true, 'account-gm');
requireTactical($resolved->status === 200 && $resolved->body['roll']['formula'] === '20' && $resolved->body['appliedDamage'] === 20 && (int) $db->payload('token:scene-one:token-monster')['hp'] === 20, 'Calculated remainder applies once to current HP');
requireTactical(runCommand($db, 'combat.dot.resolve', $body, true, 'account-gm')->body['deduplicated'] === true && (int) $db->payload('token:scene-one:token-monster')['hp'] === 20, 'Damage retry never applies twice');
requireTactical(runCommand($db, 'combat.dot.resolve', [...$body, 'decision' => 'custom', 'formula' => '7'], true, 'account-gm')->status === 409, 'Reusing an id for different damage is rejected');
$db = effectFixture([[...$dot, 'label' => 'Brûlure', 'damageType' => 'magical']]);
$target = $db->payload('token:scene-one:token-monster');
$target['elementalResistances'] = ['fire' => 100];
$db->put('token:scene-one:token-monster', $target);
historicalRemainder($db);
$body = ['effectId' => $dot['id'], 'decision' => 'roll', 'requestId' => 'resolve-fire-immune-0001'];
$response = runCommand($db, 'combat.dot.resolve', $body, true, 'account-gm');
requireTactical($response->status === 200 && $response->body['appliedDamage'] === 0
    && $response->body['roll']['mapEvent']['applied'] === false
    && $db->payload('token:scene-one:token-monster')['hp'] === 40
    && $db->payload('activity')['pendingDotResolutions'] === [], 'An immune target clears the legacy remainder without a damage write.');
requireTactical(runCommand($db, 'combat.dot.resolve', $body, true, 'account-gm')->body['deduplicated'] === true, 'Fully resisted damage is deduplicated.');
foreach (['custom', 'skip'] as $decision) {
    $db = effectFixture([$dot]); historicalRemainder($db);
    $response = runCommand($db, 'combat.dot.resolve', ['effectId' => $dot['id'], 'decision' => $decision, 'formula' => '7', 'requestId' => 'resolve-' . $decision . '-contract-0001'], true, 'account-gm');
    requireTactical($response->status === 200 && (int) $db->payload('token:scene-one:token-monster')['hp'] === ($decision === 'custom' ? 33 : 40) && $db->payload('activity')['pendingDotResolutions'] === [], 'Custom/skip resolves only the selected remainder');
}
$db = effectFixture([$dot]);
$target = $db->payload('token:scene-one:token-monster'); $target['hp'] = -1; $db->put('token:scene-one:token-monster', $target);
requireTactical(endEffectCombat($db)['pendingDotResolutions'] === [], 'Dead creatures produce no remaining-damage prompt');
$db = effectFixture([[...$dot, 'targetTokenId' => 'token-player']]);
$character = $db->payload('character:character-player'); $character['resources']['hp'] = 0; $db->put('character:character-player', $character);
requireTactical(endEffectCombat($db)['pendingDotResolutions'] === [] && (int)$db->payload('character:character-player')['resources']['hp']===0, 'A KO player also loses round effects without receiving damage or healing');
$db = effectFixture([$dot]); historicalRemainder($db);
$target = $db->payload('token:scene-one:token-monster'); $target['characterId'] = 'character-player'; $db->put('token:scene-one:token-monster', $target);
$response = runCommand($db, 'combat.dot.resolve', ['effectId' => $dot['id'], 'decision' => 'roll', 'requestId' => 'rebound-effect-contract-0001'], true, 'account-gm');
requireTactical($response->status === 200 && $response->body['targetUnavailable'] === true && (int) $db->payload('character:character-player')['resources']['hp'] === 10, 'Rebinding a token never damages the replacement character');

$db = fixture();
$target = $db->payload('token:scene-one:token-monster'); $target['hidden'] = true; $db->put('token:scene-one:token-monster', $target);
$created = runCommand($db, 'token.roll', ['sceneId' => 'scene-one', 'layerId' => 'ground', 'tokenId' => 'token-monster', 'kind' => 'stat', 'statId' => 'monster-force', 'requestId' => 'reveal-creature-contract-0001'], true, 'account-gm');
requireTactical($created->status === 200, 'Hidden creature roll is created');
$private = $created->body['roll'];
$activity = $db->payload('activity'); $activity['rolls'] = [$private]; $db->put('activity', $activity);
requireTactical(runCommand($db, 'roll.reveal', ['rollId' => $private['id']])->status === 403, 'Revelation requires GM authority');
$response = runCommand($db, 'roll.reveal', ['rollId' => $private['id']], true, 'account-gm');
requireTactical($response->status === 200 && $response->body['roll']['total'] === $private['total'] && !isset($response->body['roll']['outcome']['threshold'], $response->body['roll']['sourceTokenId']), 'Revealing a creature publishes the score without its threshold or position: ' . json_encode($response->body));
requireTactical(runCommand($db, 'roll.reveal', ['rollId' => $private['id']], true, 'account-gm')->body['deduplicated'] === true, 'Revelation is idempotent');
$activity = $db->payload('activity'); $activity['rolls'][] = [...$private, 'id' => 'private-scene-roll', 'sourceSceneId' => 'scene-two', 'mapEvent' => [...($private['mapEvent'] ?? []), 'sceneId' => 'scene-two']]; $activity['rolls'][] = [...$private, 'id' => 'unapplied-damage-roll', 'rollKind' => 'damage']; $db->put('activity', $activity);
requireTactical(runCommand($db, 'roll.reveal', ['rollId' => 'private-scene-roll'], true, 'account-gm')->status === 409, 'An undistributed scene remains private');
requireTactical(runCommand($db, 'roll.reveal', ['rollId' => 'unapplied-damage-roll'], true, 'account-gm')->status === 409, 'Unapplied damage remains private');

$db = fixture();
$before = $db->payload('initiative:scene-one');
$periodic = ['sceneId'=>'scene-one','layerId'=>'ground','tokenId'=>'token-monster','direction'=>-1,
    'label'=>'Plaie de recette','formula'=>'1d2+3','turns'=>2,'requestId'=>'manual-hp-php-proof-0001'];
requireTactical(runCommand($db,'combat.effect.periodic-hp',$periodic)->status===403,'Only the GM creates recurring HP effects');
$created = runCommand($db,'combat.effect.periodic-hp',$periodic,true,'account-gm');
requireTactical($created->status===200 && $created->body['effect']['remainingTurns']===2, 'Manual effect is created for exactly two turns: '.json_encode($created->body));
requireTactical(runCommand($db,'combat.effect.periodic-hp',$periodic,true,'account-gm')->body['deduplicated']===true,'The same request creates one effect');
requireTactical(runCommand($db,'combat.effect.periodic-hp',[...$periodic,'startNextTurn'=>true],true,'account-gm')->body['deduplicated']===true,'Explicit default is compatible with an older receipt');
requireTactical((int)$db->payload('token:scene-one:token-monster')['hp']===40,'Creating the effect leaves HP until the target turn');
$progress = static function(MemoryConnection $db,array $initiative):void {
    $records=applicationDomainRecords($db);$pending=[];
    queueOnlineDomainUpsert($pending,$records,'initiative:scene-one',$initiative);
    applyApplicationDamageOverTimeOnTurn($db,$records,$pending,['id'=>'account-gm','display_name'=>'MJ','effective_mode'=>'gm','permanent_role'=>'gm']);
    foreach($pending as $id=>$change)$db->put($id,$change['payload']);
};
$clock = [...$before,'active'=>true,'turnsStarted'=>true,'order'=>['token-player','token-monster'],'currentIndex'=>1,'round'=>1];
$progress($db,[...$clock,'turnSerial'=>1,'rewoundTurnSerial'=>1]);
requireTactical((int)$db->payload('token:scene-one:token-monster')['hp']===40 && $db->payload('activity')['damageOverTime'][0]['remainingTurns']===2,'A backward visit does not apply the effect');
$progress($db,[...$clock,'turnSerial'=>2]);
requireTactical((int)$db->payload('token:scene-one:token-monster')['hp']===40 && $db->payload('activity')['damageOverTime'][0]['remainingTurns']===2,'Redisplaying the placement turn does not apply the effect');
$progress($db,[...$clock,'turnSerial'=>3,'round'=>2]);
requireTactical(in_array((int)$db->payload('token:scene-one:token-monster')['hp'],[35,36],true),'The target loses the rolled HP on its next turn');
requireTactical($db->payload('activity')['damageOverTime'][0]['remainingTurns']===1,'One turn was consumed');
$removed=runCommand($db,'combat.effect.remove',['kind'=>'dot','effectId'=>$created->body['effect']['id'],'requestId'=>'manual-hp-remove-php-0001'],true,'account-gm');
requireTactical($removed->status===200 && $db->payload('activity')['damageOverTime']===[],'The GM can remove the remaining periodic effect');

$db=fixture();
$db->put('initiative:scene-one',[...$db->payload('initiative:scene-one'),'active'=>false]);
$now=[...$periodic,'startNextTurn'=>false,'tokenId'=>'token-player','direction'=>1,'formula'=>'4','label'=>'Soin de recette','requestId'=>'manual-hp-php-immediate-0001'];
$immediate=runCommand($db,'combat.effect.periodic-hp',$now,true,'account-gm');
requireTactical($immediate->status===200 && $immediate->body['appliedDelta']===4 && $immediate->body['effect']['remainingTurns']===1
    && $db->payload('character:character-player')['resources']['hp']===14,'Immediate healing makes one roll and leaves one of the two turns');
$activity=$db->payload('activity');
$merge=preserveApplicationAbilityExtensions('activity',['resourceReceipts'=>[]],$activity);
requireTactical(count($merge['resourceReceipts'])===1,'A stale activity update cannot remove the receipt for the immediate roll');
requireTactical(runCommand($db,'combat.effect.periodic-hp',$now,true,'account-gm')->body['deduplicated']===true
    && $db->payload('character:character-player')['resources']['hp']===14,'Retry never rolls immediate healing twice');
requireTactical(runCommand($db,'combat.effect.periodic-hp',[...$now,'startNextTurn'=>true],true,'account-gm')->status===409,'The same reference cannot change the start timing');
$advanced=runCommand($db,'mechanics.command',['command'=>'scene.advance','arguments'=>['sceneId'=>'scene-one','requestId'=>'manual-hp-php-turn-0001']],true,'account-gm');
requireTactical($advanced->status===200 && $db->payload('character:character-player')['resources']['hp']===18
    && $db->payload('activity')['damageOverTime']===[],'One manual advance completes the immediate two-turn effect');

$existing = array_map(static fn(int $index): array => [...$dot, 'id'=>'existing-dot-'.$index], range(1, 100));
$db = effectFixture($existing);
$scheduled = runCommand($db, 'combat.effect.periodic-hp', $periodic, true, 'account-gm');
requireTactical($scheduled->status === 200 && count($db->payload('activity')['damageOverTime']) === 101,
    'A full old list does not reject the next scheduled effect.');
$records = applicationDomainRecords($db);
$pending = [];
$attack = applyOnlineAttackConditions($db, $records, $pending, [
    'id'=>'ongoing-capacity-attack', 'sceneId'=>'scene-one', 'targetTokenId'=>'token-monster', 'status'=>'applied',
    'damageOverTime'=>['label'=>'Poison de recette', 'formula'=>'7', 'damageType'=>'poison', 'turns'=>2],
]);
$activity = validatedDomainPayload('activity', $pending['activity']['payload']);
requireTactical(($attack['dotApplied'] ?? false) && count($activity['damageOverTime']) === 102,
    'The attack keeps its poison and the resulting activity remains a valid domain.');
echo "Ongoing effects, residual damage and roll revelation contracts passed.\n";
