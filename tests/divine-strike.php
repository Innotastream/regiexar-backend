<?php
declare(strict_types=1);
define('XAR_CONTACT_FIXTURE_ONLY',true);
require __DIR__.'/melee-contact-variants.php';
$specialChecks=0;
const DIVINE_ABILITY='ability-0eaec688-fbcd-49cf-8e85-b6e1e7c8fa25';
function divineFixture(bool $success=true):MemoryConnection {
 $db=contactFixture();$c=$db->payload('character:'.CONTACT_CHARACTER);
 $c['abilities']=[['id'=>DIVINE_ABILITY,'name'=>'Nom de recette indépendant','effect'=>'damage','formula'=>'1d40+10','damageType'=>'magical','castingStatId'=>'character-stat-spiritSocial','cooldownRounds'=>5,'usesPerRest'=>1]];
 $c['weaponText']='Arme de recette / 1d40+6';$c['weaponAttacks'][0]['formula']='1d40+6';$c['stats']['force']=$success?100:1;$db->put('character:'.CONTACT_CHARACTER,$c);
 $db->put('token:scene-one:token-player',synchronizeOnlineCharacterToken($db->payload('token:scene-one:token-player'),$c));
 $items=$db->payload('items');$items['templates'][0]['equipment']['formula']='1d40+6';$db->put('items',$items);
 $target=$db->payload('token:scene-one:token-monster');$target['hp']=$target['maxHp']=1000;$target['armorCategory']='special';$target['armor']=100;$target['magicArmor']=100;$db->put('token:scene-one:token-monster',$target);return $db;
}
function divineCase(string $status,bool $opposed=false,bool $success=true):array {
 for($i=0;$i<100;$i++){$db=divineFixture($success);$args=['weaponAttackId'=>'halberd','opposed'=>$opposed,'requestId'=>'divine-'.randomToken(20)];$r=contactAttack($db,'ability',DIVINE_ABILITY,$args);if($r->status!==200)throw new RuntimeException(json_encode($r->body));if(($r->body['attack']['status']??'')===$status)return [$db,$r,$args];}
 throw new RuntimeException('Ordinary divine case unavailable: '.$status);
}
[$db,$r,$args]=divineCase('applied');$character=$db->payload('character:'.CONTACT_CHARACTER);$timer=$db->payload('activity')['actionTimers'][0];
specialCheck($r->body['attack']['appliedDamage']>=11&&$r->body['attack']['appliedDamage']<=50,'Only ignore-armour component passes both 100 percent armours');
$stored=$db->payload('activity')['attackReceipts'][0]['attack'];specialCheck($stored['damageComponents']===[['type'=>'physical','formula'=>'1d40+6'],['type'=>'ignore','formula'=>'1d40+10']],'Physical weapon and divine ignore remain distinct');
specialCheck($r->body['cast']['statId']==='character-stat-force','Force is authoritative');specialCheck($character['resources']['mana']===80&&$character['fatigue']['current']===5,'20 mana and 5 fatigue paid once');
specialCheck($timer['readyRound']===4&&$timer['restUseCount']===1&&$timer['restUseLimit']===2&&$timer['restRecharge']==='short','Three rounds and two short-rest uses');specialCheck(contactLastPreset($db)==='light-halberd-divine','Whole-weapon divine visual emitted on impact');
$items=$db->payload('items');$events=count($db->payload('activity')['abilityCueEvents']);$again=contactAttack($db,'ability',DIVINE_ABILITY,$args);specialCheck($again->status===200&&!empty($again->body['deduplicated']),'Receipt replay deduplicated');specialCheck($db->payload('character:'.CONTACT_CHARACTER)['resources']['mana']===80&&count($db->payload('activity')['abilityCueEvents'])===$events&&$db->payload('items')===$items,'Replay changes neither cost, inventory nor visual');
$blocked=contactAttack($db,'ability',DIVINE_ABILITY,['weaponAttackId'=>'halberd']);specialCheck($blocked->status===409,'Cooldown blocks another attempt');
[$db,$r]=divineCase('missed',false,false);$timer=$db->payload('activity')['actionTimers'][0];$character=$db->payload('character:'.CONTACT_CHARACTER);specialCheck($r->body['cast']['success']===false,'Failed Force check');specialCheck($character['resources']['mana']===80&&$character['fatigue']['current']===0&&$timer['readyRound']===2,'Reduced failure refunds fatigue, keeps mana spent and one-round cooldown');specialCheck($db->payload('token:scene-one:token-monster')['hp']===1000&&empty($db->payload('activity')['abilityCueEvents']),'Failed cast has neither damage nor successful-impact visual');
[$db,$r]=divineCase('awaiting-opposition',true);specialCheck(empty($db->payload('activity')['abilityCueEvents']),'Optional opposition delays the impact visual');
$rules=normalizeOnlineAbilities([['id'=>DIVINE_ABILITY,'name'=>'Autre nom','effect'=>'damage','formula'=>'1']])[0];$rules['manaCost']=23;specialCheck(normalizeOnlineAbilities([$rules])[0]['manaCost']===23,'Later explicit configuration stays editable');
echo json_encode(['ok'=>true,'checks'=>$specialChecks,'productionAccess'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
