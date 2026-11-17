<?php
declare(strict_types=1);
define('XAR_TACTICAL_FIXTURE_ONLY',true);
require_once __DIR__.'/tactical-lifecycle.php';
$checks=0;
function requireHealing(bool $value,string $label):void{if(!$value)throw new RuntimeException($label);$GLOBALS['checks']++;}
$db=fixture();
$db->put('initiative:scene-one',['active'=>false,'order'=>[],'currentIndex'=>0,'round'=>1]);
$c=$db->payload('character:character-player');$c['resources']['mana']=40;$c['resources']['maxMana']=100;
$c['abilities']=[['id'=>'ability-3dcf5d02-9d9d-479c-9dc0-a31900d8a000','name'=>'Touché curatif','effect'=>'healing','formula'=>'12','healingFormula'=>'12','manaCost'=>7,'fatigueCost'=>0,'cooldownRounds'=>0,'castingStatId'=>'']];
$db->put('character:character-player',$c);
$target=$db->payload('token:scene-one:token-monster');$target['hp']=30;$target['maxHp']=100;$target['x']=24;$db->put('token:scene-one:token-monster',$target);
$body=['requestId'=>'healing-touch-native-request-01','sceneId'=>'scene-one','layerId'=>'ground','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','abilityId'=>'ability-3dcf5d02-9d9d-479c-9dc0-a31900d8a000'];
$result=runCommand($db,'ability.use',$body);
requireHealing($result->status===200,'Le soin PHP doit réussir : '.json_encode($result->body));
requireHealing($db->payload('token:scene-one:token-monster')['hp']==42,'Douze PV sont rendus une seule fois à la vraie cible');
requireHealing($db->payload('character:character-player')['resources']['mana']==33,'Le coût de mana est conservé');
$events=array_values(array_filter($db->payload('activity')['abilityCueEvents']??[],static fn($e)=>($e['completionCue']['spell']['presetId']??'')==='healing-touch'));
requireHealing(count($events)===1,'Un seul événement spatial est créé');
$spell=$events[0]['completionCue']['spell'];
requireHealing($spell['sourceTokenId']==='token-player'&&$spell['anchorTokenId']==='token-monster','Lucioles et fleurs gardent leurs ancrages distincts');
requireHealing(applicationSpellVisualDuration($spell)===8800,'La fenêtre de livraison conserve la vitesse commune');
$again=runCommand($db,'ability.use',$body);
requireHealing($again->status===200&&$db->payload('token:scene-one:token-monster')['hp']==42,'Le reçu ne rejoue pas le soin');
$eventsAgain=array_filter($db->payload('activity')['abilityCueEvents']??[],static fn($e)=>($e['completionCue']['spell']['presetId']??'')==='healing-touch');
requireHealing(count($eventsAgain)===1,'Le reçu ne rejoue pas l’animation');
echo json_encode(['ok'=>true,'checks'=>$checks],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
