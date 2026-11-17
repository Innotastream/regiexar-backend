<?php
declare(strict_types=1);
define('XAR_TACTICAL_FIXTURE_ONLY',true);
require_once __DIR__.'/tactical-lifecycle.php';
$checks=0;
function stigmaCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);$GLOBALS['checks']++;}
$id='ability-6a6d080e-96b7-4d12-b25b-d179d5e17766';$ability=['id'=>$id,'name'=>'Nom modifiable','effect'=>'enhancement','castingStatId'=>'','fatigueCost'=>10,'manaCost'=>0,'restRecharge'=>'long','usesPerRest'=>1,'attackEnhancement'=>['mode'=>'bonus','formula'=>'15','damageType'=>'inherit','turns'=>5,'target'=>'self','bonuses'=>['force'=>15,'agility'=>15],'criticalFailureAsFailure'=>true]];
$db=fixture();$character=$db->payload('character:character-player');$character['resources']=['hp'=>100,'maxHp'=>100,'mana'=>100,'maxMana'=>100];$character['fatigue']=['current'=>0,'max'=>150];$character['abilities']=normalizeOnlineAbilities([$ability]);$db->put('character:character-player',$character);
$request=['requestId'=>'stigma-visual-request-0001','sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-player','layerId'=>'ground','abilityId'=>$id];
$response=runCommand($db,'ability.use',$request);stigmaCheck($response->status===200,'Canonical activation: '.json_encode($response->body));
$effect=$db->payload('activity')['attackEnhancements'][0];stigmaCheck(is_int($effect['visualStartedAt'])&&$effect['visualStartedAt']>(int)(microtime(true)*1000)-2000,'Timestamp in canonical storage');
stigmaCheck($effect['remainingTurns']===5&&$effect['formula']==='15','Duration and weapon bonus preserved');stigmaCheck($effect['bonuses']===['force'=>15,'agility'=>15]&&$effect['criticalFailureAsFailure'],'Stats and critical failure protection preserved');
stigmaCheck($db->payload('character:character-player')['fatigue']['current']===10,'Paid ten fatigue once');stigmaCheck($db->payload('character:character-player')['resources']['mana']===100,'No mana payment');
$replay=runCommand($db,'ability.use',$request);stigmaCheck($replay->status===200&&($replay->body['deduplicated']??false),'Receipt replay');stigmaCheck($db->payload('activity')['attackEnhancements'][0]===$effect,'No new effect or timestamp');stigmaCheck($db->payload('character:character-player')['fatigue']['current']===10,'No repeated payment');
$token=$db->payload('token:scene-one:token-player');$state=[...$db->payload('activity'),'activeSceneId'=>'scene-one'];$visual=applicationTokenBuffVisuals($token,$state,'scene-one');stigmaCheck($visual[0]['skin']==='stigma-awakening'&&$visual[0]['startedAt']===$effect['visualStartedAt'],'Public cosmetic DTO');
stigmaCheck(!isset($visual[0]['formula'])&&!isset($visual[0]['casterTokenId']),'No rule/source leak');stigmaCheck(applicationTokenBuffVisuals([...$token,'followCharacter'=>false],$state,'scene-one')===[],'Independent clone isolated');stigmaCheck(applicationTokenBuffVisuals([...$token,'dimSilhouette'=>true],$state,'scene-one')===[],'No cinematic on silhouette');stigmaCheck(applicationTokenBuffVisuals($token,$state,'other')===[],'Scene isolated');
stigmaCheck(applicationEnhancementVisualSkin(['abilityId'=>'other'])===[],'Unknown ability unchanged');
unset($state['attackEnhancements'][0]['visualStartedAt']);$old=applicationTokenBuffVisuals($token,$state,'scene-one')[0];stigmaCheck($old['skin']==='stigma-awakening'&&!isset($old['startedAt']),'Old active buff gets aura without new cinematic');
for($i=0;$i<5;$i++)$state['attackEnhancements']=applicationAdvanceAttackEnhancements($state['attackEnhancements'],'scene-one',[],[],true);stigmaCheck(applicationTokenBuffVisuals($token,$state,'scene-one')===[],'Five turns remove cosmetic and mechanics together');
echo json_encode(['ok'=>true,'checks'=>$checks,'productionAccess'=>false],JSON_THROW_ON_ERROR);
