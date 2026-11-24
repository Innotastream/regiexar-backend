<?php
declare(strict_types=1);
if(in_array('--json',$argv,true)){require __DIR__.'/../api/v1/combat-undo.php';$cases=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$results=[];foreach($cases as$c){$domains=$c['after'];combatUndoRecord($c['before'],$domains,$c['command'],$c['args'],$c['result'],$c['id']);foreach($c['undos']as$undo)combatUndoApply($domains,$undo);foreach($domains['activity']['combatActions']??[]as$i=>$a)foreach($a['undoReceipts']??[]as$j=>$r)unset($domains['activity']['combatActions'][$i]['undoReceipts'][$j]['createdAt']);$results[]=$domains;}echo json_encode($results,JSON_THROW_ON_ERROR);exit;}
define('XAR_CONTACT_FIXTURE_ONLY',true);require __DIR__.'/melee-contact-variants.php';set_error_handler(static function($severity,$message){throw new ErrorException($message,0,$severity);});$specialChecks=0;
$db=contactFixture();$c=$db->payload('character:'.CONTACT_CHARACTER);$c['resources']['hp']=70;$c['abilities']=[['id'=>'undo-heal','name'=>'Soin de recette','effect'=>'healing','formula'=>'7','healingFormula'=>'7','castingStatId'=>'','manaCost'=>5,'hpCost'=>2,'fatigueCost'=>5,'cooldownRounds'=>3,'restRecharge'=>'short','usesPerRest'=>2]];$db->put('character:'.CONTACT_CHARACTER,$c);$t=$db->payload('token:scene-one:token-player');$db->put('token:scene-one:token-player',synchronizeOnlineCharacterToken($t,$c));$before=$db->payload('character:'.CONTACT_CHARACTER);$timers=$db->payload('activity')['actionTimers']??[];
$r=runCommand($db,'ability.use',['requestId'=>'undo-heal-native-0001','sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-player','abilityId'=>'undo-heal']);specialCheck($r->status===200,'Native healing cast: '.json_encode($r->body));$activity=$db->payload('activity');specialCheck(count($activity['combatActions']??[])===1,'Native cast has a complete action ledger');$privateState=domainsToApplicationState(applicationDomainRecords($db),0);specialCheck(!array_key_exists('combatActions',publicPlayerState($privateState,['id'=>'account-player'],[])),'Undo ledger is private from players');specialCheck(!array_key_exists('combatActions',publicPlayerState($privateState,['id'=>'account-player'],[],true)),'Undo ledger is private from Stream');$action=$activity['combatActions'][0];$args=['requestId'=>'undo-native-receipt-0001','actionId'=>$action['actionIds'][0]];
$r=runCommand($db,'action.undo',$args);specialCheck($r->status===403,'Player cannot undo an action');$r=runCommand($db,'action.undo',$args,true,'account-gm');specialCheck($r->status===200,'Native complete undo: '.json_encode($r->body));specialCheck($db->payload('character:'.CONTACT_CHARACTER)['resources']===$before['resources'],'HP and mana restored');specialCheck($db->payload('character:'.CONTACT_CHARACTER)['fatigue']===$before['fatigue'],'Fatigue restored');specialCheck(($db->payload('activity')['actionTimers']??[])===$timers,'Cooldown and rest usage restored');specialCheck($db->payload('token:scene-one:token-player')['hp']===$before['resources']['hp'],'Linked token agrees with restored sheet');$r=runCommand($db,'action.undo',$args,true,'account-gm');specialCheck($r->status===200&&!empty($r->body['deduplicated']),'Durable undo receipt prevents duplicate refund');
// Reusing an equipped weapon must never merge separate actions or block later attacks.
$base=['table'=>['activeSceneId'=>'scene'],'token:scene:target'=>['id'=>'target','hp'=>100,'maxHp'=>100],'activity'=>['playerActions'=>[],'attackReceipts'=>[]]];
$strike=static function(array $before,string $id,int $amount):array{$d=$before;$a=['id'=>'hit-'.$id,'targetTokenId'=>'target','sceneId'=>'scene','status'=>'applied','appliedDamage'=>$amount];$d['token:scene:target']['hp']-=$amount;array_unshift($d['activity']['playerActions'],['id'=>'action-'.$id,'kind'=>'attack','sceneId'=>'scene']);$d['activity']['attackReceipts'][]=['requestId'=>'request-'.$id,'attack'=>$a];combatUndoRecord($before,$d,'token.attack',['requestId'=>'request-'.$id,'attackId'=>'same-weapon','sourceTokenId'=>'source'],['attack'=>$a],'combat-'.$id);return $d;};
$first=$strike($base,'first-00000000001',10);$second=$strike($first,'second-0000000001',7);specialCheck(count($second['activity']['combatActions'])===2,'Same weapon creates two distinct actions');
combatUndoApply($second,['actionId'=>'action-first-00000000001','actorId'=>'gm','requestId'=>'undo-same-weapon-000001']);specialCheck($second['token:scene:target']['hp']===93.0,'Undo preserves damage from the later independent strike');specialCheck($second['activity']['attackReceipts'][1]['attack']['status']==='applied','Later attack receipt remains applied');
$third=$strike($second,'third-00000000001',5);specialCheck(count($third['activity']['combatActions'])===3&&$third['token:scene:target']['hp']===88.0,'Weapon remains usable after another action is cancelled');
$heavyStrike = static function (array $before, string $id):array {
    $next = $before;
    $hit = ['id'=>'hit-'.$id,'targetTokenId'=>'target','sceneId'=>'scene','status'=>'applied','appliedDamage'=>25];
    $next['token:scene:target']['hp'] -= 25;
    $next['activity']['playerActions'][] = ['id'=>'action-'.$id,'kind'=>'attack','sceneId'=>'scene'];
    $next['activity']['attackReceipts'][] = ['requestId'=>'request-'.$id,'attack'=>$hit];
    applicationApplyImpactBleeding($next['activity'],$next['token:scene:target'],'scene',[],0,'resource-'.$id);
    combatUndoRecord($before,$next,'token.attack',['requestId'=>'request-'.$id,'attackId'=>'same-weapon','sourceTokenId'=>'source'],['attack'=>$hit],'combat-'.$id);
    return $next;
};
$first = $heavyStrike($base,'large-first');
$second = $heavyStrike($first,'large-second');
specialCheck(count($second['activity']['combatActions']) === 2 && count($second['activity']['damageOverTime']) === 1,
    'A refreshed injury keeps separate attack undo records.');
combatUndoApply($second,['actionId'=>'action-large-first','actorId'=>'gm','requestId'=>'undo-large-first-0001']);
specialCheck($second['token:scene:target']['hp'] === 75.0 && $second['activity']['damageOverTime'][0]['impactPulseId'] === 'resource-large-second',
    'Undoing an older attack keeps the newer injury.');
combatUndoApply($second,['actionId'=>'action-large-second','scope'=>'target-damage','tokenId'=>'target','actorId'=>'gm','requestId'=>'undo-large-second-0001']);
specialCheck($second['token:scene:target']['hp'] === 100.0 && $second['activity']['damageOverTime'] === [],
    'Removing the remaining attack damage does not revive a cancelled injury.');
$latest = $heavyStrike($second,'large-third');
specialCheck(count($latest['activity']['combatActions']) === 3 && count($latest['activity']['damageOverTime']) === 1,
    'A stable bleeding identity does not block a new strike after an earlier cancellation.');
$withPrior = $heavyStrike($first,'large-second');
$withPrior['activity']['damageOverTime'][0]['remainingTurns'] = 2;
$withPrior['activity']['damageOverTime'][0]['lastManualTurn'] = 1;
combatUndoApply($withPrior,['actionId'=>'action-large-second','scope'=>'target-damage','tokenId'=>'target','actorId'=>'gm','requestId'=>'undo-large-refreshed-0001']);
specialCheck($withPrior['activity']['damageOverTime'][0]['impactPulseId'] === 'resource-large-first'
    && $withPrior['activity']['damageOverTime'][0]['remainingTurns'] === 2
    && $withPrior['activity']['damageOverTime'][0]['lastManualTurn'] === 1,
    'Undo of a refresh restores the earlier active injury and accounts for ticks already played.');

$existing = specialFixture();
$sourceKey = 'token:scene-one:token-monster';
$targetKey = 'token:scene-one:token-monster-two';
$otherKey = 'token:scene-two:token-monster-two';
$ability = ['id'=>'shared-target-strike', 'name'=>'Impact de recette', 'effect'=>'damage', 'castingStatId'=>'',
    'formula'=>'25', 'damageType'=>'ignore', 'rangeCells'=>8];
$existing->put($sourceKey, [...$existing->payload($sourceKey), 'abilities'=>[$ability]]);
$existing->put($targetKey, [...$existing->payload($targetKey), 'x'=>52, 'y'=>50, 'hp'=>100, 'maxHp'=>100, 'layerId'=>'ground']);
$existing->put($otherKey, [...$existing->payload($targetKey), 'hp'=>61]);
$existing->put('initiative:scene-one', [...$existing->payload('initiative:scene-one'), 'currentIndex'=>1]);
$attack = runCommand($existing, 'token.attack', ['requestId'=>'shared-target-hit-001', 'sceneId'=>'scene-one',
    'sourceTokenId'=>'token-monster', 'targetTokenId'=>'token-monster-two', 'attackKind'=>'ability',
    'attackId'=>$ability['id'], 'opposed'=>false], true, 'account-gm');
specialCheck($attack->status === 200 && ($attack->body['attack']['status'] ?? '') === 'applied'
    && $existing->payload($targetKey)['hp'] === 75, 'The authoritative attack prepares a real damage and injury receipt: '.json_encode($attack->body));
$activity = $existing->payload('activity');
$undoTarget = ['actionId'=>$activity['combatActions'][0]['actionIds'][0], 'scope'=>'target-damage',
    'tokenId'=>'token-monster-two', 'requestId'=>'undo-target-scene-0001'];
$activity['combatActions'][0]['damage'][0]['entityKey'] = $otherKey;
$existing->put('activity', $activity);
$legacyDomains = $existing->domains;
$reply = runCommand($existing,'action.undo',$undoTarget,true,'account-gm');
specialCheck($reply->status === 200 && $existing->payload($targetKey)['hp'] === 100
    && $existing->payload($otherKey)['hp'] === 61,
    'A historical target receipt is resolved in the original scene, before its stored entity key: '.json_encode($reply->body));
specialCheck($existing->payload('activity')['damageOverTime'] === []
    && runCommand($existing,'action.undo',$undoTarget,true,'account-gm')->body['deduplicated'] === true,
    'The matching injury is removed and an exact retry cannot refund twice.');

$missing = new MemoryConnection([]);
$missing->domains = $legacyDomains;
unset($missing->domains[$targetKey]);
$reply = runCommand($missing,'action.undo',$undoTarget,true,'account-gm');
specialCheck($reply->status === 409 && $missing->payload($otherKey)['hp'] === 61
    && $missing->payload('activity') === $activity,
    'A removed target never transfers its refund to an unrelated token in another scene');
$linked = $heavyStrike($base, 'linked-target');
unset($linked['token:scene:target']);
$linked['token:other:target'] = ['id'=>'target', 'hp'=>61, 'maxHp'=>100];
$linked['character:linked-target'] = ['id'=>'linked-target', 'resources'=>['hp'=>75, 'maxHp'=>100]];
$linked['activity']['combatActions'][0]['damage'][0]['entityKey'] = 'character:linked-target';
$linkedArgs = ['actionId'=>'action-linked-target', 'scope'=>'target-damage', 'tokenId'=>'target',
    'requestId'=>'undo-linked-scene-0001', 'actorId'=>'gm'];
$relinked = $linked;
combatUndoApply($linked, $linkedArgs);
specialCheck($linked['character:linked-target']['resources']['hp'] === 100.0 && $linked['token:other:target']['hp'] === 61,
    'A linked character still receives its own refund if its map occurrence was removed');
$relinked['token:scene:target'] = ['id'=>'target', 'characterId'=>'replacement-sheet', 'hp'=>61, 'maxHp'=>100];
$relinked['character:replacement-sheet'] = ['id'=>'replacement-sheet', 'resources'=>['hp'=>61, 'maxHp'=>100]];
combatUndoApply($relinked, $linkedArgs);
specialCheck($relinked['character:linked-target']['resources']['hp'] === 100.0
    && $relinked['character:replacement-sheet']['resources']['hp'] === 61 && $relinked['token:scene:target']['hp'] === 61,
    'A change of linked sheet still credits the immutable original character');

$rebound = $heavyStrike($base, 'rebound-target');
$rebound['token:scene:target']['characterId'] = 'replacement-sheet';
$rebound['character:replacement-sheet'] = ['id'=>'replacement-sheet', 'resources'=>['hp'=>61, 'maxHp'=>100]];
$reboundBefore = $rebound;
try {
    combatUndoApply($rebound, ['actionId'=>'action-rebound-target', 'scope'=>'target-damage', 'tokenId'=>'target',
        'requestId'=>'undo-rebound-scene-0001', 'actorId'=>'gm']);
    specialCheck(false, 'A different sheet cannot receive the refund');
} catch (RuntimeException $error) {
    specialCheck($error->getCode() === 409 && $rebound === $reboundBefore,
        'A changed sheet binding rejects the refund without changing combat or resources');
}
$complete = $heavyStrike(['token:other:target'=>['id'=>'target', 'hp'=>61, 'maxHp'=>100], ...$base], 'deleted-full-target');
unset($complete['token:scene:target']);
combatUndoApply($complete, ['actionId'=>'action-deleted-full-target', 'scope'=>'action',
    'requestId'=>'undo-deleted-full-0001', 'actorId'=>'gm']);
specialCheck($complete['token:other:target']['hp'] === 61 && !isset($complete['token:scene:target'])
    && $complete['activity']['combatActions'][0]['cancelled'] === true,
    'A full undo after a target vanished leaves the same token ID in another scene untouched');
echo json_encode(['ok'=>true,'checks'=>$specialChecks,'productionAccess'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
