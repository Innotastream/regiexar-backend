<?php
declare(strict_types=1);
define('XAR_TACTICAL_FIXTURE_ONLY',true);
require __DIR__.'/tactical-lifecycle.php';
$abilityId='ability-3c2a4c7c-74e8-4110-96a1-468e4ce52132';$checks=0;
$check=static function(bool $yes,string $label)use(&$checks){if(!$yes)throw new RuntimeException($label);$checks++;};
function riposteFixture(string $abilityId,bool $success): MemoryConnection {
 $db=fixture();$db->put('map:scene-one',['naturalWidth'=>1000,'naturalHeight'=>1000,'gridSize'=>50,'activeLayerId'=>'ground']);
 $c=$db->payload('character:character-player');$c['resources']['hp']=100;$c['fatigue']=['current'=>0,'max'=>150];$c['stats']['agility']=$success?100:1;
 $c['abilities']=[['id'=>$abilityId,'name'=>'Contre sans nom particulier','effect'=>'movement','formula'=>'0','castingStatId'=>'character-stat-dexterity','usesPerRest'=>1,'restRecharge'=>'none','reducedFailureCooldown'=>true,'onHitConditions'=>['Désarmé']]];$db->put('character:character-player',$c);
 $a=$db->payload('token:scene-one:token-monster');$a['x']=25;$a['stats'][0]['value']='100';$a['weaponAttacks'][0]['formula']='20';$db->put('token:scene-one:token-monster',$a);
 $db->put('initiative:scene-one',['active'=>true,'combatId'=>'riposte-combat','round'=>1,'turnSerial'=>1,'order'=>['token-monster','token-player'],'currentIndex'=>0]);
 return $db;
}
foreach(['won','lost','failed']as$case){
 $found=null;
 for($i=0;$i<100&&$found===null;$i++){
  $db=riposteFixture($abilityId,$case!=='failed');
  $incoming=runCommand($db,'token.attack',['requestId'=>'riposte-incoming-0001','sceneId'=>'scene-one','layerId'=>'ground','sourceTokenId'=>'token-monster','targetTokenId'=>'token-player','attackKind'=>'weapon','attackId'=>'monster-claw','statId'=>'monster-force','opposed'=>true],true,'account-gm');
  if($incoming->status!==200||($incoming->body['attack']['status']??'')!=='awaiting-opposition')continue;
  $args=['requestId'=>'riposte-opposition-0001','sceneId'=>'scene-one','attackId'=>$incoming->body['attack']['id'],'abilityId'=>$abilityId,'optionId'=>'riposte'];
  $result=runCommand($db,'token.attack.oppose',$args,false,'account-player');
  if($result->status!==200)throw new RuntimeException($result->getMessage());
  $attack=$result->body['attack'];$success=$attack['opposition']['outcome']['success']??false;
  if($case==='won'&&$attack['status']==='defended'&&$success||$case==='lost'&&$attack['status']==='applied'&&$success||$case==='failed'&&$attack['status']==='applied'&&!$success)$found=[$db,$attack,$args];
 }
 $check($found!==null,'Ordinary reaction case found: '.$case);[$db,$attack,$args]=$found;
 $check($attack['opposition']['statId']==='character-stat-agility','Agility is authoritative');
 $check(($db->payload('character:character-player')['fatigue']['current']??0)===5,'Full fatigue is paid once');
 $timer=$db->payload('activity')['actionTimers'][0];$check($timer['readyRound']===4&&$timer['restUseCount']===1&&$timer['restUseLimit']===2&&$timer['restRecharge']==='short','Three rounds and two short-rest uses');
 $conditions=$db->payload('devices')['timedConditions']??[];$events=array_values(array_filter($db->payload('activity')['abilityCueEvents']??[],static fn($e)=>in_array($e['completionCue']['spell']['presetId']??'',['sabre-fire-counter','sabre-frost-counter'],true)));
 if($case==='failed')$check($conditions===[]&&$events===[],'Failed agility causes no disarm or successful counter');
 else{$check(count($conditions)===1&&$conditions[0]['targetTokenId']==='token-monster'&&$conditions[0]['remainingTurns']===1&&$conditions[0]['expiry']==='end-next-turn','One full turn on the attacker, even when its attack wins');
 $check(count($events)===1&&$events[0]['sourceTokenId']==='token-player'&&$events[0]['completionCue']['spell']['targetTokenId']==='token-monster','One cosmetic reaction chosen independently of equipment');}
 $hp=$db->payload('character:character-player')['resources']['hp'];$check((float)$hp===($case==='won'?100.0:80.0),'Incoming damage follows the duel winner');
 $revision=$db->revision;$again=runCommand($db,'token.attack.oppose',$args,false,'account-player');$check($again->status===200&&!empty($again->body['deduplicated'])&&$db->revision===$revision,'Replay changes neither effects, costs nor visual');
}
echo json_encode(['ok'=>true,'checks'=>$checks,'productionAccess'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
