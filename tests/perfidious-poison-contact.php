<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);
require __DIR__.'/tactical-special-effects.php';
$specialChecks=0;
const VENOM_ABILITY='ability-93fa3849-3fbf-43fa-84cb-0c0c9d7f4189';
$db=specialFixture();
$sheet=$db->payload('character:character-player');$sheet['specialSuccessStock']=10;$sheet['stats']['force']=100;
$sheet['weaponText']="Hallebarde / 1d2\nPattes / 1d2\nTir / 1d2\nLame magique / 1d2";
$sheet['weaponAttacks']=[['id'=>'weapon-1','formula'=>'1d2','damageType'=>'physical','reach'=>'reach'],['id'=>'weapon-2','formula'=>'1d2','damageType'=>'physical','naturalWeapon'=>true,'reach'=>'melee'],['id'=>'shot','formula'=>'1d2','damageType'=>'physical','projectile'=>true,'reach'=>'ranged'],['id'=>'magic','formula'=>'1d2','damageType'=>'magical','reach'=>'melee']];
$legacy=['id'=>VENOM_ABILITY,'name'=>'Venin fictif','effect'=>'enhancement','formula'=>'0','castingStatId'=>'','fatigueCost'=>10,'manaCost'=>0,'cooldownRounds'=>0,'usesPerRest'=>1,'restRecharge'=>'long','attackEnhancement'=>['mode'=>'on-attack-dot','turns'=>5,'dot'=>['label'=>'Poison mortel','formula'=>'1d20','turns'=>5]]];
$sheet['abilities']=[$legacy,['id'=>'spell','name'=>'Sort fictif','effect'=>'damage','formula'=>'1d2','damageType'=>'magical','castingStatId'=>'character-stat-force'],['id'=>'combat','name'=>'Compétence de combat fictive','effect'=>'damage','formula'=>'1d2','damageType'=>'physical','useWeaponDamage'=>true,'weaponDamageMode'=>'replace','castingStatId'=>'character-stat-force']];
$db->put('character:character-player',$sheet);
$target=$db->payload('token:scene-one:token-monster');$target['hp']=$target['maxHp']=1000;$db->put('token:scene-one:token-monster',$target);
$normalized=normalizeOnlineAbilities([$legacy])[0];specialCheck($normalized['attackEnhancement']['trigger']==='melee-weapon','Known legacy sheet gets the melee trigger');specialCheck($normalized['attackEnhancement']['weaponIds']===['weapon-2'],'Only the configured legs attack is authorized');
$custom=$legacy;$custom['attackEnhancement']['trigger']='all-attacks';$custom['attackEnhancement']['turns']=7;$normalized=normalizeOnlineAbilities([$custom])[0];specialCheck($normalized['attackEnhancement']['trigger']==='all-attacks'&&$normalized['attackEnhancement']['turns']===7,'Explicit later settings are preserved');
$result=specialUse($db,VENOM_ABILITY,'token-player');specialCheck($result->status===200,'Activation succeeds: '.json_encode($result->body));specialCheck($db->payload('character:character-player')['fatigue']['current']===10,'Activation spends ten fatigue exactly once');
$strike=static fn(array $options)=>runCommand($db,'token.attack',['requestId'=>'venom-'.randomToken(20),'sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','attackKind'=>'weapon','attackId'=>'weapon-2','statId'=>'character-stat-force','opposed'=>false,'useJoker'=>true,...$options]);
foreach([['attackKind'=>'ability','attackId'=>'spell','echoAttackContext'=>['weaponBased'=>true,'projectile'=>false]],['attackId'=>'shot'],['attackId'=>'magic'],['attackId'=>'weapon-1','echoAttackContext'=>['weaponId'=>'weapon-2','weaponBased'=>true,'naturalWeapon'=>true,'projectile'=>false]],['attackKind'=>'ability','attackId'=>'combat','weaponAttackId'=>'weapon-1']] as $options){$result=$strike($options);specialCheck($result->status===200,'Non-contact attack resolves: '.json_encode($result->body));specialCheck(count($db->payload('activity')['damageOverTime'])===0,'Spell, projectile or purely magical weapon cannot apply poison');}
$result=$strike(['attackKind'=>'ability','attackId'=>'combat','weaponAttackId'=>'weapon-2']);specialCheck($result->status===200,'Physical combat ability resolves: '.json_encode($result->body));
$activity=$db->payload('activity');specialCheck(count($activity['damageOverTime'])===1,'Physical combat ability applies one charge');specialCheck(($activity['damageOverTime'][0]['sourceAbilityId']??'')===VENOM_ABILITY,'Charge preserves its originating configured ability');
$result=$strike([]);specialCheck($result->status===200&&count($db->payload('activity')['damageOverTime'])===1,'Second contact on same turn does not add a charge');
$clock=$db->payload('initiative:scene-one');$clock['turnSerial']++;$db->put('initiative:scene-one',$clock);$result=$strike([]);specialCheck($result->status===200&&count($db->payload('activity')['damageOverTime'])===2,'Next turn adds a separate five-turn charge');
$state=domainsToApplicationState($db->domains,0);$source=$state['map']['tokens'][0];$target=array_values(array_filter($state['map']['tokens'],static fn($row)=>$row['id']==='token-monster'))[0];
$attack=['id'=>'duplicate','sceneId'=>'scene-one','status'=>'blocked','hit'=>['outcome'=>['success'=>true]],'damageType'=>'physical','echoAttackContext'=>['weaponId'=>'weapon-2','weaponBased'=>true,'projectile'=>false,'reach'=>'melee']];$state['initiative']['turnSerial']++;
applicationAttackPoisonStacks($state,$attack,$source,$target,static fn($prefix)=>$prefix.'-native-'.randomToken(12));specialCheck(count($attack['poisonApplications']??[])===1,'Only a real stack records its trusted injection');
$count=count($state['damageOverTime']);applicationAttackPoisonStacks($state,$attack,$source,$target,static fn($prefix)=>$prefix.'-native-'.randomToken(12));specialCheck(count($state['damageOverTime'])===$count,'An existing receipt never applies poison twice');
fwrite(STDOUT,json_encode(['ok'=>true,'checks'=>$specialChecks],JSON_UNESCAPED_UNICODE)."\n");
