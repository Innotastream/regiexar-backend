<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);
require __DIR__.'/tactical-special-effects.php';
$specialChecks=0;
const PRECISION_ID='ability-b7c57dd7-7a09-45aa-b5a0-7e1928a06066';
const PRECISION_WHIP='v370-template-d29417860cce105b93bb7f17';
function precisionFixture():MemoryConnection{
 $db=specialFixture();$c=$db->payload('character:character-player');
 $c['abilities']=[['id'=>PRECISION_ID,'name'=>'Précision serpentaire','effect'=>'damage','formula'=>'1d30+6','damageType'=>'physical','fatigueCost'=>5,'manaCost'=>0,'cooldownRounds'=>5,'restRecharge'=>'none','castingStatId'=>'character-stat-dexterity']];
 $c['stats']=['force'=>0,'agility'=>0,'intelligence'=>0,'spiritSocial'=>0];$c['weaponText']='Fouet / 1d30+6';$c['weaponAttacks']=[['id'=>'main','formula'=>'1d30+6','damageType'=>'physical','reach'=>'reach','equipmentBinding'=>['kind'=>'item','id'=>'whip']]];
 $db->put('character:character-player',$c);$db->put('token:scene-one:token-player',synchronizeOnlineCharacterToken($db->payload('token:scene-one:token-player'),$c));
 $second=$db->payload('token:scene-one:token-monster');$second['id']='precision-second';$second['x']=65;$db->put('token:scene-one:precision-second',$second);
 $db->put('items',['version'=>1,'templates'=>[['id'=>PRECISION_WHIP,'name'=>'Fouet de recette','kind'=>'object','equipment'=>['version'=>1,'kind'=>'weapon','hands'=>1,'formula'=>'1d30+6','damageType'=>'physical','reach'=>'reach']]],
  'instances'=>[['id'=>'whip','templateId'=>PRECISION_WHIP,'revision'=>1,'location'=>['kind'=>'inventory','characterId'=>'character-player']]],
  'equipmentLoadouts'=>['character-player'=>['sets'=>[],'activeSetId'=>'','equippedItemIds'=>['whip']]]]);
 return $db;
}
function precisionStrike(MemoryConnection $db,array $extra=[],bool $gm=false):TestResponse{
 return runCommand($db,'token.attack',['requestId'=>'precision-'.randomToken(18),'sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','targetTokenIds'=>['token-monster','precision-second'],'attackKind'=>'ability','attackId'=>PRECISION_ID,'weaponAttackId'=>'main','opposed'=>true,...$extra],$gm,$gm?'account-gm':'account-player');
}
foreach([false,true] as $gm){
 $db=precisionFixture();$request='precision-replay-'.randomToken(18);$result=precisionStrike($db,['requestId'=>$request],$gm);
 specialCheck($result->status===200,'Guaranteed two-target skill: '.json_encode($result->body));
 specialCheck(empty($result->body['pendingAreaGate'])&&empty($db->payload('activity')['pendingAttacks'])&&empty($db->payload('activity')['pendingAbilityCasts']),'No defense or remarkable validation');
 specialCheck($db->payload('token:scene-one:token-monster')['hp']===450&&$db->payload('token:scene-one:precision-second')['hp']===450,'Both distinct units take fifty damage: '.json_encode(['first'=>$db->payload('token:scene-one:token-monster')['hp'],'second'=>$db->payload('token:scene-one:precision-second')['hp'],'formula'=>$result->body['attack']['damageFormula']??null]));
 specialCheck($db->payload('character:character-player')['fatigue']['current']===5,'Five fatigue once');specialCheck($db->payload('character:character-player')['resources']['mana']===100,'No mana spent');
 $timers=array_values(array_filter($db->payload('activity')['actionTimers'],static fn($t)=>$t['abilityId']===PRECISION_ID));specialCheck(count($timers)===1&&$timers[0]['readyRound']===6,'One five-round cooldown');
 $cues=array_values(array_filter($db->payload('activity')['abilityCueEvents'],static fn($e)=>($e['completionCue']['spell']['presetId']??'')==='bladed-whip-precision'));
 specialCheck(count($cues)===2,'Two trusted, target-bound precision cues: '.json_encode(array_map(static fn($e)=>$e['completionCue']['spell']??null,$db->payload('activity')['abilityCueEvents'])));
 $ids=array_column(array_map(static fn($e)=>$e['completionCue']['spell'],$cues),'targetTokenId');sort($ids);specialCheck($ids===['precision-second','token-monster'],'Correct targets even with matching names');
 $before=$db->domains;$replay=precisionStrike($db,['requestId'=>$request],$gm);specialCheck($replay->status===200&&!empty($replay->body['deduplicated'])&&$before===$db->domains,'Replayed request has no new cost, damage or animation');
}
foreach([['token-monster','token-monster'],['token-monster','precision-second','third'],[],null] as $targets){
 $db=precisionFixture();$before=$db->domains;$result=precisionStrike($db,['targetTokenIds'=>$targets]);
 specialCheck($result->status===400&&$db->domains===$before,'Invalid selection refused before every mutation');
}
$db=precisionFixture();$result=precisionStrike($db,['targetTokenIds'=>['token-monster']]);specialCheck($result->status===200&&$db->payload('token:scene-one:token-monster')['hp']===450&&$db->payload('token:scene-one:precision-second')['hp']===500,'One target remains legal');
$db=precisionFixture();$target=$db->payload('token:scene-one:precision-second');$target['x']=99;$db->put('token:scene-one:precision-second',$target);$before=$db->domains;$result=precisionStrike($db);specialCheck($result->status===409&&$db->domains===$before,'Out of range is atomic');
$db=precisionFixture();$c=$db->payload('character:character-player');$c['abilities']=normalizeOnlineAbilities($c['abilities']);$c['abilities'][0]['useWeaponTargetCount']=false;$db->put('character:character-player',$c);$before=$db->domains;$result=precisionStrike($db);specialCheck($result->status===400&&$db->domains===$before,'The optional setting can be disabled without a name exception');
echo json_encode(['ok'=>true,'checks'=>$specialChecks,'productionAccess'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
