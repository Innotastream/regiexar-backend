<?php
declare(strict_types=1);
define('XAR_TACTICAL_FIXTURE_ONLY',true);
require_once __DIR__.'/tactical-lifecycle.php';
$checks=0;function emblemVisualCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);$GLOBALS['checks']++;}
$id='ability-5f30d8c5-4efc-47d5-bf03-ea5cb192222b';$ability=['id'=>$id,'name'=>'Nom modifiable','effect'=>'enhancement','castingStatId'=>'','fatigueCost'=>10,'manaCost'=>0,'usesPerRest'=>1,'restRecharge'=>'long','attackEnhancement'=>['mode'=>'bonus','formula'=>'10','damageType'=>'inherit','turns'=>5,'target'=>'self','trigger'=>'all-attacks','bonuses'=>['physicalResistance'=>10,'magicalResistance'=>10,'attackResultBonus'=>10],'aura'=>['radiusCells'=>8,'affectCaster'=>true,'affectAllies'=>true,'affectEnemies'=>false]]];
$db=fixture();$character=$db->payload('character:character-player');$character['resources']=['hp'=>100,'maxHp'=>100,'mana'=>100,'maxMana'=>100];$character['fatigue']=['current'=>0,'max'=>150];$character['abilities']=normalizeOnlineAbilities([$ability]);$db->put('character:character-player',$character);
$request=['requestId'=>'emblem-visual-request-0001','sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-player','layerId'=>'ground','abilityId'=>$id];$response=runCommand($db,'ability.use',$request);
emblemVisualCheck($response->status===200,'Real activation');$effect=$db->payload('activity')['attackEnhancements'][0];$start=$effect['visualStartedAt']??null;
emblemVisualCheck(is_int($start)&&$start>(int)(microtime(true)*1000)-2000,'Authoritative timestamp');
emblemVisualCheck($effect['aura']['affectCaster']&&$effect['aura']['affectAllies']&&!$effect['aura']['affectEnemies'],'Caster and allies, not enemies');
emblemVisualCheck($effect['remainingTurns']===5&&$effect['formula']==='10'&&$effect['aura']['radiusCells']===8,'Rules intact');
emblemVisualCheck($db->payload('character:character-player')['fatigue']['current']===10,'Single payment');
$replay=runCommand($db,'ability.use',$request);emblemVisualCheck($replay->status===200&&($replay->body['deduplicated']??false),'Replay receipt');emblemVisualCheck($db->payload('activity')['attackEnhancements'][0]===$effect,'Timestamp and effect stable');
$hero=[...$db->payload('token:scene-one:token-player'),'x'=>50,'y'=>74,'size'=>60,'layerId'=>'ground','controllerPlayerId'=>'party'];$state=[...$db->payload('activity'),'activeSceneId'=>'scene-one','map'=>['background'=>'fixture','gridSize'=>22,'naturalWidth'=>1000,'naturalHeight'=>620,'activeLayerId'=>'ground','tokens'=>[$hero]]];
$visual=applicationTokenBuffVisuals($hero,$state,'scene-one')[0];emblemVisualCheck($visual['skin']==='sacred-circle'&&$visual['startedAt']===$start,'Public skin and start');emblemVisualCheck(($visual['auraScale']??0)>1,'Public emitter radius');emblemVisualCheck(!isset($visual['formula'])&&!isset($visual['casterTokenId'])&&!isset($visual['bonuses']),'No private rule/source projection');
emblemVisualCheck(applicationTokenBuffVisuals([...$hero,'dimSilhouette'=>true],$state,'scene-one')===[],'Silhouette excluded');emblemVisualCheck(applicationTokenBuffVisuals($hero,$state,'other')===[],'Scene isolated');
unset($state['attackEnhancements'][0]['visualStartedAt']);$old=applicationTokenBuffVisuals($hero,$state,'scene-one')[0];emblemVisualCheck($old['skin']==='sacred-circle'&&!isset($old['startedAt']),'Old effect has persistent area without new cinematic');
emblemVisualCheck(applicationEnhancementVisualSkin(['abilityId'=>'other'])===[],'Unknown effect unchanged');emblemVisualCheck(applicationEnhancementVisualSkin(['abilityId'=>'ability-6a6d080e-96b7-4d12-b25b-d179d5e17766','visualStartedAt'=>123])===['skin'=>'stigma-awakening','startedAt'=>123],'Stigmate signal preserved');
$ally=['id'=>'ally','name'=>'Allié','controllerPlayerId'=>'account-ally','x'=>51,'y'=>74,'hp'=>60,'maxHp'=>100,
    'layerId'=>'ground','elementalResistances'=>['poison'=>90,'bleeding'=>90]];
$state['map']['tokens'][]=$ally;
foreach(['poison','bleeding'] as $family)$state['damageOverTime'][]=['id'=>$family,'sceneId'=>'scene-one','targetTokenId'=>'ally',
    'family'=>$family,'damageType'=>$family,'remainingTurns'=>2];
foreach([true,false] as $stream){
    foreach([51,98] as $x){
        $state['map']['tokens'][1]['x']=$x;
        $projection=publicPlayerState($state,['id'=>'account-ally'],[],$stream);
        $projected=array_column($projection['map']['tokens'],null,'id')['ally'];
        $skins=array_values(array_intersect(array_column($projected['buffVisuals'],'skin'),['venom-target','bleeding']));
        emblemVisualCheck($skins===($x===51?[]:['venom-target','bleeding']),'Periodic badges only hide while the ally has immunity');
        if($stream)emblemVisualCheck(!isset($projected['elementalResistances'])&&!isset($projected['elementalResistanceOverrides']),
            'Stream does not expose either resistance field');
    }
}
for($i=0;$i<5;$i++)$state['attackEnhancements']=applicationAdvanceAttackEnhancements($state['attackEnhancements'],'scene-one',[],[],true);emblemVisualCheck(applicationTokenBuffVisuals($hero,$state,'scene-one')===[],'Five turns remove skin and area');
echo json_encode(['ok'=>true,'checks'=>$checks,'productionAccess'=>false],JSON_THROW_ON_ERROR);
