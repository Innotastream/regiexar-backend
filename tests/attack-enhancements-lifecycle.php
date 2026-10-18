<?php
declare(strict_types=1);
define('XAR_TACTICAL_FIXTURE_ONLY',true);
require __DIR__ . '/tactical-lifecycle.php';

// The normal command path is used with synthetic storage and an unchecked cast
// so that this lifecycle test does not depend on cryptographic d100 outcomes.
$db = fixture();
$db->put('scene-index',['order'=>['scene-one']]);
$db->put('scene:scene-one',['metadata'=>['id'=>'scene-one','name'=>'Recette de bonus fictive']]);
$db->put('map:scene-one',['naturalWidth'=>1000,'naturalHeight'=>1000,'gridSize'=>50]);
$character = $db->payload('character:character-player');
$character['resources']['mana'] = 100; $character['resources']['maxMana'] = 100; $character['stats']['force'] = 100;
$character['weaponText'] = 'Gourdin ordinaire 1d6+2'; $character['weaponAttacks'] = [['id'=>'weapon-1','formula'=>'1d6+2','damageType'=>'physical']];
$character['abilities'] = [['id'=>'club','name'=>'Gourdin de recette','effect'=>'damage','castingStatId'=>'','manaCost'=>20,'cooldownRounds'=>5,'restRecharge'=>'short','usesPerRest'=>3,
    'formula'=>'1d70+12','damageType'=>'magical','attackEnhancement'=>['mode'=>'replace','formula'=>'1d70+12','damageType'=>'magical','turns'=>5]]];
$db->put('character:character-player',$character);
$target = $db->payload('token:scene-one:token-monster'); $target['x']=25; $target['hp']=10000; $target['maxHp']=10000; $target['magicArmor']=50; $db->put('token:scene-one:token-monster',$target);
$launch = ['requestId'=>'enhancement-cast-request-0001','sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','attackKind'=>'ability','attackId'=>'club','statId'=>''];
$response = runCommand($db,'token.attack',$launch);
requireTactical($response->status===200 && $response->body['castSucceeded'],'PHP cast succeeds: '.$response->getMessage());
$activity = $db->payload('activity');
requireTactical(count($activity['attackEnhancements'])===1 && $activity['attackEnhancements'][0]['remainingTurns']===5,'PHP commit activates effect');
$effectId = $activity['attackEnhancements'][0]['id'];
requireTactical((float)$db->payload('character:character-player')['resources']['mana']===80.0 && $activity['actionTimers'][0]['restUseCount']===1,'PHP activation pays once');
$repeat = runCommand($db,'token.attack',$launch);
requireTactical($repeat->body['deduplicated'] && $db->payload('activity')['attackEnhancements'][0]['id']===$effectId,'PHP retry preserves one effect');
$base = runCommand($db,'token.attack',['requestId'=>'enhancement-weapon-request-01','sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','attackKind'=>'weapon','attackId'=>'weapon-1','statId'=>'character-stat-force','damageFormula'=>'999999']);
requireTactical($base->status===200,'PHP base attack: '.$base->getMessage());
$attack = array_values(array_filter($db->payload('activity')['attackReceipts'],static fn(array $entry):bool=>$entry['requestId']==='enhancement-weapon-request-01'))[0]['attack'];
requireTactical($attack['damageFormula']==='1d70+12' && $attack['damageType']==='magical','PHP authoritative base formula');
requireTactical((float)$db->payload('character:character-player')['resources']['mana']===80.0,'PHP subsequent strike free');
$remove = ['requestId'=>'enhancement-remove-request-01','kind'=>'enhancement','effectId'=>$effectId];
requireTactical(runCommand($db,'combat.effect.remove',$remove)->status===403,'Player cannot remove effect');
requireTactical(runCommand($db,'combat.effect.remove',$remove,true,'account-gm')->status===200 && $db->payload('activity')['attackEnhancements']===[],'MJ removal authoritative');
requireTactical(runCommand($db,'combat.effect.remove',$remove,true,'account-gm')->body['deduplicated'],'MJ removal idempotent');
$activity = $db->payload('activity');
$initiative = $db->payload('initiative:scene-one');
applicationActivateAttackEnhancement($activity,$character['abilities'][0],$db->payload('token:scene-one:token-player'),'scene-one',$initiative,'clock-effect');
$db->put('activity',$activity);
$records = applicationDomainRecords($db,['activity','initiative:scene-one']); $pending = [];
queueOnlineDomainUpsert($pending,$records,'initiative:scene-one',[...$initiative,'turnSerial'=>1,'currentIndex'=>1]);
applyApplicationDamageOverTimeOnTurn($db,$records,$pending,['id'=>'account-gm','effective_mode'=>'gm','permanent_role'=>'gm']);
requireTactical($pending['activity']['payload']['attackEnhancements'][0]['remainingTurns']===4,'PHP canonical turn clock ticks effect');
$db->put('activity',$pending['activity']['payload']); $db->put('initiative:scene-one',[...$initiative,'turnSerial'=>1,'currentIndex'=>1]);
$records = applicationDomainRecords($db,['activity','initiative:scene-one']); $pending=[];
queueOnlineDomainUpsert($pending,$records,'initiative:scene-one',[...$initiative,'active'=>false,'turnSerial'=>2]);
applyApplicationDamageOverTimeOnTurn($db,$records,$pending,['id'=>'account-gm','effective_mode'=>'gm','permanent_role'=>'gm']);
requireTactical($pending['activity']['payload']['attackEnhancements']===[],'PHP end combat clears enhancement');
echo "attack-enhancements-lifecycle: ok\n";
