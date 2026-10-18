<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);
require __DIR__.'/tactical-special-effects.php';
$specialChecks=0;
$ability=['id'=>'simple-main','name'=>'Frappe fictive','effect'=>'damage','formula'=>'10','damageComponents'=>[['type'=>'magical','formula'=>'10']],'useWeaponDamage'=>true,'mainWeaponOnly'=>true,'weaponDamageMode'=>'add','castingStatId'=>'','fatigueCost'=>5,'manaCost'=>0,'cooldownRounds'=>0,'restRecharge'=>'none'];
$weapons=[['id'=>'main','formula'=>'8','damageType'=>'physical'],['id'=>'other','formula'=>'1000','damageType'=>'ignore']];
$r=applicationSimpleWeaponDamage($ability,$weapons);specialCheck($r['damageComponents']===[['type'=>'physical','formula'=>'8'],['type'=>'magical','formula'=>'10']],'Simple weapon keeps physical and magical components');
specialCheck($r['selectedWeapon']['id']==='main','Main weapon selected without client id');
$r=applicationSimpleWeaponDamage([...$ability,'maximumWeaponDamage'=>true],[['id'=>'main','formula'=>'2d6+4','damageType'=>'physical']]);specialCheck($r['damageComponents'][0]['formula']==='16'&&$r['damageComponents'][1]['formula']==='10','Only weapon is maximized, extras keep own rolls');
try{applicationSimpleWeaponDamage($ability,$weapons,'other');specialCheck(false,'Secondary must be refused');}catch(RuntimeException){specialCheck(true,'Forged secondary refused');}
$r=applicationSimpleWeaponDamage([...$ability,'weaponDamageMode'=>'replace'],$weapons);specialCheck(count($r['damageComponents'])===1,'Legacy replacement unchanged');
$db=specialFixture();$c=$db->payload('character:character-player');$c['abilities']=[$ability];$db->put('character:character-player',$c);
$args=['sceneId'=>'scene-one','requestId'=>'simple-main-'.randomToken(16),'sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','attackKind'=>'ability','attackId'=>'simple-main','opposed'=>false];
$r=runCommand($db,'token.attack',$args);specialCheck($r->status===200,'Native simple attack: '.json_encode($r->body));$stored=$db->payload('activity')['attackReceipts'][0]['attack'];specialCheck(str_contains($stored['damageFormula'],'1d6+4')&&str_contains($stored['damageFormula'],'10'),'Current weapon formula and extras used');specialCheck(($db->payload('activity')['abilityExecutions']??[])===[],'No complex execution');specialCheck($db->payload('character:character-player')['fatigue']['current']===5,'Single fatigue cost');
$again=runCommand($db,'token.attack',$args);specialCheck($again->status===200&&$again->body['deduplicated']&&$db->payload('character:character-player')['fatigue']['current']===5,'Native replay no extra cost');

$db=specialFixture();$records=$db->domains;$pending=[];$attack=['id'=>'intimidation-test','status'=>'blocked','sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','attackName'=>'Cri fictif','hit'=>['outcome'=>['code'=>'critical-success']],
 'conditionRider'=>['version'=>1,'label'=>'Intimidé','turns'=>3,'resistance'=>false,'attackPenalty'=>15,'criticalLabel'=>'Effrayé']];
$attack=onlineDeviceRider($db,$records,$pending,$attack);$state=domainsToApplicationState(array_replace($records,$pending),0);
$conditions=$state['devices']['timedConditions'];specialCheck(count($conditions)===2,'Critical native rider adds intimidation and fear');specialCheck(array_column($conditions,'remainingTurns')===[3,3],'Both effects last three target turns');specialCheck(!in_array('Doute',array_column($conditions,'label'),true),'No doubt or morale effect');
$monster=$state['map']['tokens'][array_search('token-monster',array_column($state['map']['tokens'],'id'),true)];specialCheck(applicationTimedAttackPenalty($state,$monster,'token-player','scene-one')===15,'Penalty only against caster');specialCheck(applicationTimedAttackPenalty($state,$monster,'other-target','scene-one')===0,'Other opponents unaffected');specialCheck(applicationTimedAttackPenalty($state,[...$monster,'id'=>'independent-clone','followCharacter'=>false],'token-player','scene-one')===0,'Independent clone does not inherit affected token');
foreach($state['devices']['timedConditions'] as &$effect)$effect['remainingTurns']=0;unset($effect);specialCheck(applicationTimedAttackPenalty($state,$monster,'token-player','scene-one')===0,'Expired intimidation no longer penalizes');
$again=onlineDeviceRider($db,$records,$pending,$attack);specialCheck(count(($pending['devices']['payload']??$records['devices']['payload'])['timedConditions'])===2,'Rider replay does not duplicate effects');

$db=specialFixture();$c=$db->payload('character:character-player');$c['abilities']=[['id'=>'repeat-rest','name'=>'Test quota','effect'=>'damage','formula'=>'0','castingStatId'=>'','fatigueCost'=>5,'manaCost'=>0,'cooldownRounds'=>0,'restRecharge'=>'short','usesPerRest'=>2,'reusableInTurn'=>false]];$db->put('character:character-player',$c);
$args=['sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','attackKind'=>'ability','attackId'=>'repeat-rest','opposed'=>false];
for($i=0;$i<2;$i++){ $r=runCommand($db,'token.attack',[...$args,'requestId'=>'quota-'.randomToken(16)]);specialCheck($r->status===200,'Two native uses available'); }
$r=runCommand($db,'token.attack',[...$args,'requestId'=>'quota-third-'.randomToken(16)]);specialCheck($r->status===409,'Third use refused');specialCheck($db->payload('character:character-player')['fatigue']['current']===10,'Rejected third use has no cost');
fwrite(STDOUT,json_encode(['ok'=>true,'checks'=>$specialChecks],JSON_UNESCAPED_UNICODE)."\n");
