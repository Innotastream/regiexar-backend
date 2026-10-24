<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);require __DIR__.'/tactical-special-effects.php';
$specialChecks=0;
$weapon=['id'=>'main','formula'=>'1d2+6','damageType'=>'ignore','reach'=>'reach','targetCount'=>2,'projectile'=>false,'infusion'=>['version'=>1,'enabled'=>true,'ratio'=>1,'minimumMana'=>0,'refundOnMiss'=>true]];
$normalized=normalizeOnlineWeaponAttacks([$weapon,[...$weapon,'id'=>'crossbow','reach'=>'ranged','targetCount'=>1,'projectile'=>true]],['1d2+6','1d2+6']);
specialCheck($normalized[0]['targetCount']===2&&$normalized[1]['targetCount']===1&&$normalized[0]['reach']==='reach'&&$normalized[1]['reach']==='ranged','Equal formulas retain separate weapon metadata');
foreach([['token-monster','token-monster'],['token-monster','second','third'],[]] as $ids){try{applicationWeaponTargetIds($weapon,'token-monster',$ids);specialCheck(false,'Forged count accepted');}catch(RuntimeException){specialCheck(true,'Forged count rejected');}}
$map=['naturalWidth'=>1000,'naturalHeight'=>1000,'gridSize'=>50];$a=['id'=>'a','x'=>10,'y'=>10,'size'=>40];$b=['id'=>'b','x'=>24,'y'=>24,'size'=>160];
specialCheck(abs(applicationMapDistanceInCells($a,$b,$map,true,true)-.8)<1e-9,'Edges and diagonal metric');
foreach(['melee'=>2,'reach'=>4,'ranged'=>15] as $reach=>$cells){$edge=10+($cells*50+40)/10;$b=[...$a,'id'=>'b','x'=>$edge,'y'=>$edge];specialCheck(applicationWeaponInRange(['reach'=>$reach],$a,$b,$map),'Inclusive range boundary');specialCheck(!applicationWeaponInRange(['reach'=>$reach],$a,[...$b,'x'=>$edge+.01],$map),'Outside boundary rejected');}
specialCheck(applicationAbilityRangeCells(['useWeaponDamage'=>true],[$weapon])===4.0,'Weapon fallback');specialCheck(applicationAbilityRangeCells([],[$weapon])===15.0,'Spell fallback');specialCheck(applicationAbilityRangeCells(['rangeCells'=>30,'useWeaponDamage'=>true],[$weapon])===30.0,'Explicit range wins');
function targetingFixture(array $weapon):MemoryConnection {
 $db=specialFixture();$c=$db->payload('character:character-player');$c['weaponText']='Arme fictive / 1d2+6';$c['weaponAttacks']=[$weapon];$c['specialSuccessStock']=1;$db->put('character:character-player',$c);
 $second=$db->payload('token:scene-one:token-monster');$second['id']='token-monster-two';$second['x']=65;$db->put('token:scene-one:token-monster-two',$second);return $db;
}
$args=['sceneId'=>'scene-one','requestId'=>'multi-'.randomToken(16),'sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','targetTokenIds'=>['token-monster','token-monster-two'],'attackKind'=>'weapon','attackId'=>'main','statId'=>'character-stat-agility','opposed'=>false,'useJoker'=>true,'infusionMana'=>3];
$db=targetingFixture($weapon);$r=runCommand($db,'token.attack',$args);specialCheck($r->status===200,'Native multi attack: '.json_encode($r->body));
$activity=$db->payload('activity');$parent=$r->body['attack'];$second=$r->body['areaAttacks'][0]??[];
specialCheck($second!==[],'Two independent attack receipts');specialCheck($parent['hit']['rollId']===$second['hit']['rollId'],'Shared attack roll');specialCheck($db->payload('character:character-player')['resources']['mana']===97,'One infusion payment');specialCheck($db->payload('character:character-player')['specialSuccessStock']===0,'One special success spent');
$r=runCommand($db,'token.attack',$args);specialCheck($r->status===200&&$r->body['deduplicated']&&count($r->body['areaAttacks'])===1,'Replay retains secondary receipt');specialCheck($db->payload('character:character-player')['resources']['mana']===97,'Replay has no extra cost');
foreach($db->payload('activity')['pendingAttacks']??[] as $attack){$r=runCommand($db,'token.attack.resolve',['attackId'=>$attack['id'],'decision'=>'approve','confirmed'=>true],true,'account-gm');specialCheck($r->status===200,'Each result resolves independently: '.json_encode($r->body));}
specialCheck($db->payload('token:scene-one:token-monster')['hp']<500&&$db->payload('token:scene-one:token-monster-two')['hp']<500,'Damage to each target');
specialCheck(runCommand($db,'token.attack',[...$args,'targetTokenIds'=>['token-monster']])->status===409,'Changed target list cannot reuse receipt');
foreach([['token-monster','token-monster'],['token-monster','token-monster-two','third'],null] as $ids){$db=targetingFixture($weapon);$r=runCommand($db,'token.attack',[...$args,'requestId'=>'forged-'.randomToken(16),'targetTokenIds'=>$ids]);specialCheck($r->status===400,'Invalid target list rejected');specialCheck($db->payload('character:character-player')['resources']['mana']===100,'Invalid request has no cost');}
$db=targetingFixture($weapon);$second=$db->payload('token:scene-one:token-monster-two');$second['x']=99;$db->put('token:scene-one:token-monster-two',$second);$r=runCommand($db,'token.attack',[...$args,'requestId'=>'range-'.randomToken(16),'reach'=>'ranged','targetCount'=>20]);specialCheck($r->status===409,'Range comes from server weapon, not request');specialCheck($db->payload('character:character-player')['resources']['mana']===100,'Out of range has no cost');
foreach(['reach','ranged'] as $reach){$db=targetingFixture($weapon);$records=$db->domains;$pending=[];$r=onlineStructureContact($db,$records,$pending,['id'=>'contact','sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','status'=>'applied','echoAttackContext'=>['weaponBased'=>true,'projectile'=>false,'reach'=>$reach]]);specialCheck(empty($r['structureContactProcessed']),'No direct contact for '.$reach);}
specialCheck(applicationAttackTriggersEnhancement(['trigger'=>'melee-weapon'],['echoAttackContext'=>['weaponBased'=>true,'projectile'=>false,'reach'=>'reach']]),'Reach weapon retains weapon buffs');
// The victim alone owns the range exemption. Layer and visibility remain mandatory.
$far=['id'=>'far','x'=>99,'y'=>99,'size'=>40,'conditions'=>['Cible']];
specialCheck(applicationWeaponInRange(['reach'=>'melee'],$a,$far,$map),'Cible bypasses weapon range');
specialCheck(!applicationWeaponInRange(['reach'=>'melee'],[...$a,'conditions'=>['Cible']],[...$far,'conditions'=>[]],$map),'Cible on attacker does not extend range');
specialCheck(!applicationWeaponInRange(['reach'=>'melee'],$a,[...$far,'layerId'=>'upper'],$map),'Cible never changes layer');
$circle=[['id'=>'edge','x'=>60.5,'y'=>20,'size'=>160],['id'=>'outside','x'=>60.501,'y'=>20,'size'=>160,'conditions'=>['Cible']]];
specialCheck(array_column(onlineDamageAreaTargets($circle,['id'=>'centre','x'=>40,'y'=>20,'size'=>400],$map,2.5),'id')===['edge'],'Large token edge intersects the unchanged circular radius');
specialCheck(applicationMapDistanceInCells(['id'=>'a','x'=>10,'y'=>10,'size'=>0],['id'=>'b','x'=>15,'y'=>10,'size'=>0],$map,false,true)===1.0,'Zero-sized token does not gain a fictitious radius');
$db=specialFixture();$m=$db->payload('token:scene-one:token-monster');$m['passiveAbilities']=[['version'=>1,'id'=>'opportunity','kind'=>'opportunity-attack']];$db->put('token:scene-one:token-monster',$m);
$r=specialMove($db,90);specialCheck($r->status===200,'Long ordinary movement accepted');$p=$db->payload('devices')['pendingRolls'][0]??[];specialCheck(($p['strikeTargetPosition']['x']??0)==55,'Reaction remembers departure contact');
$r=specialMechanics($db,'opportunity.resolve',['validationId'=>$p['id'],'approve'=>true,'opposed'=>false]);specialCheck($r->status===200,'Reaction after long movement: '.json_encode($r->body));
foreach($db->payload('activity')['pendingAttacks']??[] as $pending)runCommand($db,'token.attack.resolve',['attackId'=>$pending['id'],'decision'=>'approve','confirmed'=>true],true,'account-gm');
specialCheck($db->payload('token:scene-one:token-player')['x']==90,'Reaction does not move victim back');
$reaction=$r->body['attack'];
$stored=array_values(array_filter($db->payload('activity')['attackReceipts'],static fn($entry)=>$entry['attack']['id']===$reaction['id']))[0]['attack'];
$expectedHp=$stored['hit']['outcome']['success']?93:100;
specialCheck($db->payload('character:character-player')['resources']['hp']===$expectedHp,'Departure contact applies damage only when the actual reaction hits');
$route=initialApplicationComplexMovementState(['x'=>20,'y'=>50,'size'=>40],1,3);$route=appendApplicationComplexMovementPath($route,['x'=>20,'y'=>50],['x'=>60,'y'=>50],null,$map,20,2);$route['status']='completed';
specialCheck(applicationComplexMovementAttackWidth($route,$map)===150.0,'Movement attack width is configurable');
$edge=['id'=>'edge','x'=>40,'y'=>59.5,'size'=>40];$outside=[...$edge,'y'=>59.501];
specialCheck(applicationTargetTouchesComplexMovement($edge,$route,$map)&&!applicationTargetTouchesComplexMovement($outside,$route,$map),'Configured corridor includes exact token edge only');
specialCheck(is_finite(applicationChargeFirstContact($edge,$route,$map))&&!is_finite(applicationChargeFirstContact($outside,$route,$map)),'Charge shares configured corridor width');
fwrite(STDOUT,json_encode(['ok'=>true,'checks'=>$specialChecks],JSON_UNESCAPED_UNICODE)."\n");
