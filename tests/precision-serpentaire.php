<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);
require __DIR__.'/tactical-special-effects.php';
$specialChecks=0;
const PRECISION_ID='ability-b7c57dd7-7a09-45aa-b5a0-7e1928a06066';
const PRECISION_WHIP='v370-template-d29417860cce105b93bb7f17';
function precisionFixture(int $agility=85):MemoryConnection{
 $db=specialFixture();$c=$db->payload('character:character-player');
 $c['abilities']=[['id'=>PRECISION_ID,'name'=>'Précision serpentaire','effect'=>'damage','formula'=>'1d30+6','damageType'=>'physical','fatigueCost'=>5,'manaCost'=>0,'cooldownRounds'=>5,'restRecharge'=>'none','castingStatId'=>'character-stat-dexterity']];
 $c['stats']=['force'=>0,'agility'=>$agility,'intelligence'=>0,'spiritSocial'=>0];$c['specialSuccessStock']=1;$c['weaponText']='Fouet / 1d30+6';$c['weaponAttacks']=[['id'=>'main','formula'=>'1d30+6','damageType'=>'physical','reach'=>'reach','equipmentBinding'=>['kind'=>'item','id'=>'whip']]];
 $db->put('character:character-player',$c);$db->put('token:scene-one:token-player',synchronizeOnlineCharacterToken($db->payload('token:scene-one:token-player'),$c));
 $second=$db->payload('token:scene-one:token-monster');$second['id']='precision-second';$second['x']=65;$db->put('token:scene-one:precision-second',$second);
 $db->put('items',['version'=>1,'templates'=>[['id'=>PRECISION_WHIP,'name'=>'Fouet de recette','kind'=>'object','equipment'=>['version'=>1,'kind'=>'weapon','hands'=>1,'formula'=>'1d30+6','damageType'=>'physical','reach'=>'reach']]],
  'instances'=>[['id'=>'whip','templateId'=>PRECISION_WHIP,'revision'=>1,'location'=>['kind'=>'inventory','characterId'=>'character-player']]],
  'equipmentLoadouts'=>['character-player'=>['sets'=>[],'activeSetId'=>'','equippedItemIds'=>['whip']]]]);
 return $db;
}
function precisionStrike(MemoryConnection $db,array $extra=[],bool $gm=false):TestResponse{
 return runCommand($db,'token.attack',['requestId'=>'precision-'.randomToken(18),'sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','targetTokenIds'=>['token-monster','precision-second'],'attackKind'=>'ability','attackId'=>PRECISION_ID,'weaponAttackId'=>'main','statId'=>'character-stat-agility','opposed'=>true,'useJoker'=>true,...$extra],$gm,$gm?'account-gm':'account-player');
}
function precisionAttemptWithOutcome(string $outcome,bool $combat=true):array{
 for($attempt=0;$attempt<500;$attempt++){
  $db=precisionFixture($outcome==='failure'?0:100);
  if(!$combat){$clock=$db->payload('initiative:scene-one');$clock['active']=false;$db->put('initiative:scene-one',$clock);}
  $request=['requestId'=>'precision-roll-'.randomToken(18),'useJoker'=>false];
  $result=precisionStrike($db,$request);
  if($result->status!==200)throw new RuntimeException('Precision launch failed before the roll: '.json_encode($result->body));
  if(($result->body['attack']['hit']['outcome']['code']??null)===$outcome)return [$db,$result,$request];
 }
 throw new RuntimeException('The isolated fixture did not obtain the required raw D100 outcome: '.$outcome);
}
function requireGmIdentity(PDO $connection):array {
 return ['id'=>'account-gm','display_name'=>'MJ test','effective_mode'=>'gm','permanent_role'=>'gm'];
}
function precisionDeleteTarget(MemoryConnection $db,string $key):TestResponse {
 $GLOBALS['testBody']=['stateSchemaVersion'=>XAR_SESSION_SCHEMA_VERSION,'domainSchemaVersion'=>XAR_DOMAIN_SCHEMA_VERSION,
  'changes'=>[['key'=>$key,'operation'=>'delete','expectedRevision'=>$db->domains[$key]['revision']]]];
 try{patchApplicationDomains($db);}
 catch(TestResponse $response){return $response;}
 throw new RuntimeException('No domain response after removing the target');
}
foreach([false,true] as $gm){
 $db=precisionFixture();$request='precision-replay-'.randomToken(18);$result=precisionStrike($db,['requestId'=>$request],$gm);
 specialCheck($result->status===200,'Guaranteed two-target skill: '.json_encode($result->body));
 specialCheck($result->body['attack']['hit']['statId']==='character-stat-agility'
    && $result->body['attack']['hit']['rollId']===$result->body['areaAttacks'][0]['hit']['rollId']
    && $db->payload('character:character-player')['specialSuccessStock']===0,'Both attacks share the one Agility roll and only one Joker is consumed');
 specialCheck(empty($result->body['pendingAreaGate'])&&empty($db->payload('activity')['pendingAttacks'])&&empty($db->payload('activity')['pendingAbilityCasts']),'No defense or remarkable validation');
 specialCheck($db->payload('token:scene-one:token-monster')['hp']===450&&$db->payload('token:scene-one:precision-second')['hp']===450,'Both distinct units take fifty damage: '.json_encode(['first'=>$db->payload('token:scene-one:token-monster')['hp'],'second'=>$db->payload('token:scene-one:precision-second')['hp'],'formula'=>$result->body['attack']['damageFormula']??null]));
 $dots=$db->payload('activity')['damageOverTime']??[];
 $targets=array_column($dots,'targetTokenId');sort($targets);
 specialCheck($targets===['precision-second','token-monster']&&array_column($dots,'family')===['bleeding','bleeding']
    &&array_column($dots,'formula')===['1d5','1d5']&&array_column($dots,'remainingTurns')===[1,1],'The equipped whip schedules one separate bleed for each target');
 specialCheck($db->payload('character:character-player')['fatigue']['current']===5,'Five fatigue once');specialCheck($db->payload('character:character-player')['resources']['mana']===100,'No mana spent');
 $timers=array_values(array_filter($db->payload('activity')['actionTimers'],static fn($t)=>$t['abilityId']===PRECISION_ID));specialCheck(count($timers)===1&&$timers[0]['readyRound']===6,'One five-round cooldown');
 $cues=array_values(array_filter($db->payload('activity')['abilityCueEvents'],static fn($e)=>($e['completionCue']['spell']['presetId']??'')==='bladed-whip-precision'));
 specialCheck(count($cues)===2,'Two trusted, target-bound precision cues: '.json_encode(array_map(static fn($e)=>$e['completionCue']['spell']??null,$db->payload('activity')['abilityCueEvents'])));
 $ids=array_column(array_map(static fn($e)=>$e['completionCue']['spell'],$cues),'targetTokenId');sort($ids);specialCheck($ids===['precision-second','token-monster'],'Correct targets even with matching names');
 $before=$db->domains;$replay=precisionStrike($db,['requestId'=>$request],$gm);specialCheck($replay->status===200&&!empty($replay->body['deduplicated'])&&$before===$db->domains,'Replayed request has no new cost, damage or animation');
}
$db=precisionFixture();$before=$db->domains;$result=precisionStrike($db,['statId'=>'character-stat-force']);
specialCheck($result->status===400&&$db->domains===$before,'An explicit other stat cannot replace the Agility roll');
foreach([true,false] as $combat){
 [$db,$failed,$request]=precisionAttemptWithOutcome('failure',$combat);
 specialCheck($failed->body['castSucceeded']===false&&empty($failed->body['areaAttacks']),'One failed Agility roll stops the complete attack: '.json_encode($failed->body));
 specialCheck($db->payload('character:character-player')['fatigue']['current']===0&&empty($db->payload('activity')['abilityCueEvents']),'Reduced failure restores fatigue and emits no successful attack cue');
 specialCheck($db->payload('token:scene-one:token-monster')['hp']===500&&$db->payload('token:scene-one:precision-second')['hp']===500,'Neither target is damaged after the failed roll');
 specialCheck(empty($db->payload('activity')['damageOverTime']),'A failed roll places no bleed');
 $timer=array_values(array_filter($db->payload('activity')['actionTimers']??[],static fn($row)=>$row['abilityId']===PRECISION_ID))[0]??null;
 specialCheck(($timer['readyRound']??null)===($combat?2:null),'Reduced failure creates exactly one round of cooldown in combat, none in free mode');
 $before=$db->domains;$replay=precisionStrike($db,$request);
 specialCheck(!empty($replay->body['deduplicated'])&&$db->domains===$before,'Failed launch can be replayed without a second roll or cost');
}
foreach(['approve','reject'] as $decision){
 [$db,$critical]=precisionAttemptWithOutcome('critical-success');
 specialCheck(count($db->payload('activity')['pendingAttacks'])===2,'An exceptional Agility roll holds both targets pending');
 specialCheck(empty($db->payload('activity')['damageOverTime']),'A pending roll has not yet placed a bleed');
 $resolution=runCommand($db,'token.attack.resolve',['attackId'=>$critical->body['areaAttacks'][0]['id'],'decision'=>$decision,'confirmed'=>true],true,'account-gm');
 specialCheck($resolution->status===200&&empty($db->payload('activity')['pendingAttacks']),'One decision resolves both hits of the critical cast: '.json_encode($resolution->body));
 $hp=$decision==='approve'?450:500;
 specialCheck($db->payload('token:scene-one:token-monster')['hp']===$hp&&$db->payload('token:scene-one:precision-second')['hp']===$hp,'Both targets follow the same critical decision');
 specialCheck(count($db->payload('activity')['damageOverTime']??[])===($decision==='approve'?2:0),'Both bleeds follow the same critical decision');
}
foreach(['moved','deleted','both-moved','both-dead','both-deleted'] as $situation){
 [$db,$critical,$request]=precisionAttemptWithOutcome('critical-success');
 $first='token:scene-one:token-monster';$second='token:scene-one:precision-second';
 $deleted=$situation==='deleted'||$situation==='both-deleted';
 $allUnavailable=in_array($situation,['both-moved','both-dead'],true);
 if($deleted){
  specialCheck(precisionDeleteTarget($db,$first)->status===200,'The primary token can be removed while the outcome is pending');
 }else{
  foreach($allUnavailable?[$first,$second]:[$first] as $key){
   $target=$db->payload($key);
   if($situation==='both-dead'){$target['hp']=0;$target['healthOverride']='dead';}
   else{$target['x']=99;$target['y']=99;}
   $db->put($key,$target);
  }
 }
 specialCheck(count($db->payload('activity')['pendingAttacks'])===2,'The surviving target keeps the shared cast decision and cost');
 if($situation==='both-deleted'){
  specialCheck(precisionDeleteTarget($db,$second)->status===200,'The last removed target clears its shared cast');
 }else{
  $result=runCommand($db,'token.attack.resolve',['attackId'=>$critical->body['attack']['id'],'decision'=>'approve','confirmed'=>true],true,'account-gm');
  specialCheck($result->status===200&&($result->body['attack']['status']??null)==='rejected'
   &&count($result->body['skippedTargets']??[])===($allUnavailable?2:1)
   &&($result->body['areaAttacks'][0]['status']??null)===($allUnavailable?'rejected':'applied'),
   'The one decision skips unavailable targets and resolves the remaining hit: '.json_encode($result->body));
 }
 $hits=!str_starts_with($situation,'both-');
 $untouchedHp=$situation==='both-dead'?0:500;
 specialCheck(($db->payload($first)['hp']??null)===($deleted?null:$untouchedHp)
  &&($db->payload($second)['hp']??null)===($situation==='both-deleted'?null:($hits?450:$untouchedHp))
  &&empty($db->payload('activity')['pendingAttacks']),'A stale target never takes damage and its group never remains pending');
 $timers=array_values(array_filter($db->payload('activity')['actionTimers']??[],static fn($timer)=>$timer['abilityId']===PRECISION_ID));
 specialCheck(count($timers)===($hits?1:0)&&$db->payload('character:character-player')['fatigue']['current']===5,
  'The shared cast uses only one cost and requires a surviving hit to apply its cooldown');
 $before=$db->domains;$replay=precisionStrike($db,$request);
 specialCheck($replay->status===200&&!empty($replay->body['deduplicated'])&&$before===$db->domains,'A replay never restores discarded hits or a shared cast');
}
[$db,$critical]=precisionAttemptWithOutcome('critical-success');
$caster=$db->payload('character:character-player');$caster['resources']['hp']=0;
$db->put('character:character-player',$caster);
$db->put('token:scene-one:token-player',synchronizeOnlineCharacterToken($db->payload('token:scene-one:token-player'),$caster));
foreach(['token:scene-one:token-monster','token:scene-one:precision-second'] as $key){
 $target=$db->payload($key);$target['x']=99;$target['y']=99;$db->put($key,$target);
}
$decision=['attackId'=>$critical->body['attack']['id'],'confirmed'=>true];
$refusal=runCommand($db,'token.attack.resolve',[...$decision,'decision'=>'approve'],true,'account-gm');
specialCheck($refusal->status===409&&($refusal->body['code']??null)==='attack_source_defeated'
 &&count($db->payload('activity')['pendingAttacks'])===2,'Out-of-range targets do not hide that their caster has been defeated: '.json_encode($refusal->body));
$rejected=runCommand($db,'token.attack.resolve',[...$decision,'decision'=>'reject'],true,'account-gm');
specialCheck($rejected->status===200&&empty($db->payload('activity')['pendingAttacks']),'The GM can refuse the full cast after its caster was defeated');
foreach([['token-monster','token-monster'],['token-monster','precision-second','third'],[],null] as $targets){
 $db=precisionFixture();$before=$db->domains;$result=precisionStrike($db,['targetTokenIds'=>$targets]);
 specialCheck($result->status===400&&$db->domains===$before,'Invalid selection refused before every mutation');
}
$db=precisionFixture();$result=precisionStrike($db,['targetTokenIds'=>['token-monster']]);specialCheck($result->status===200&&$db->payload('token:scene-one:token-monster')['hp']===450&&$db->payload('token:scene-one:precision-second')['hp']===500,'One target remains legal');
$db=precisionFixture();$target=$db->payload('token:scene-one:precision-second');$target['x']=99;$db->put('token:scene-one:precision-second',$target);$before=$db->domains;$result=precisionStrike($db);specialCheck($result->status===409&&$db->domains===$before,'Out of range is atomic');
$db=precisionFixture();$c=$db->payload('character:character-player');$c['abilities']=normalizeOnlineAbilities($c['abilities']);$c['abilities'][0]['useWeaponTargetCount']=false;$db->put('character:character-player',$c);$before=$db->domains;$result=precisionStrike($db);specialCheck($result->status===400&&$db->domains===$before,'The optional setting can be disabled without a name exception');
echo json_encode(['ok'=>true,'checks'=>$specialChecks,'productionAccess'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
