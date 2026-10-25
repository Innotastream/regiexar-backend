<?php
declare(strict_types=1);
require_once __DIR__.'/../api/v1/domains.php';
require_once __DIR__.'/../api/v1/online.php';
set_error_handler(static function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
if(in_array('--json',$argv,true)){
    $cases=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$result=[];
    foreach($cases as $case){$state=$case['state'];$result[]=applicationPublicMovementEffectZones($state,$state['map'],$case['publicTokens'],$case['sceneId']??'scene',$case['layerId']??'ground',static fn($x,$y)=>!($case['hidden']??false),$case['paused']??false);}
    echo json_encode($result,JSON_THROW_ON_ERROR);exit;
}
$checks=0;$check=static function(bool $value,string $message)use(&$checks):void{if(!$value)throw new RuntimeException($message);$checks++;};
$caster=['id'=>'caster','characterId'=>'sheet','followCharacter'=>true,'x'=>40,'y'=>50,'layerId'=>'ground','size'=>40,'hp'=>10,'maxHp'=>10];
$own=['id'=>'own','controllerPlayerId'=>'owner','x'=>90,'y'=>50,'layerId'=>'ground','size'=>40,'hp'=>10,'maxHp'=>10];
$enemy=['id'=>'enemy','x'=>40,'y'=>50,'layerId'=>'ground','size'=>40,'hp'=>10,'maxHp'=>10];
$clone=[...$caster,'id'=>'clone','followCharacter'=>false,'x'=>90];
$aura=['id'=>'positive','sceneId'=>'scene','mode'=>'stats','bonuses'=>['force'=>10],'turns'=>5,'remainingTurns'=>4,'casterTokenId'=>'caster','targetTokenId'=>'','holder'=>'token:caster','label'=>'PRIVATE-LABEL','abilityImage'=>'PRIVATE-IMAGE','aura'=>['radiusCells'=>3,'affectCaster'=>false,'affectAllies'=>true,'affectEnemies'=>false]];
$fixed=['id'=>'negative','sceneId'=>'scene','mode'=>'stats','bonuses'=>['physicalResistance'=>-30],'turns'=>5,'remainingTurns'=>4,'casterTokenId'=>'PRIVATE-DELETED','targetTokenId'=>'','holder'=>'zone:private','fixedArea'=>['x'=>50,'y'=>50,'layerId'=>'ground','radiusCells'=>2]];
$state=['activeSceneId'=>'scene','activeScene'=>['id'=>'scene'],'characters'=>[['id'=>'sheet','ownerPlayerId'=>'owner']],'initiative'=>[],'rolls'=>[],'pendingAttacks'=>[],
    'map'=>['background'=>'/assets/map.webp','naturalWidth'=>1000,'naturalHeight'=>1000,'gridSize'=>50,'activeLayerId'=>'ground','tokens'=>[$caster,$own,$enemy,$clone]],'attackEnhancements'=>[$aura,$fixed],'devices'=>['devices'=>[]]];
$public=[$caster,[...$own,'ownedByYou'=>true,'controllable'=>true],$enemy,$clone];$controller=static fn($token)=>onlineEffectiveTokenControllerId($token,['sheet'=>'owner']);
$project=static fn($s,$tokens,$visible=true,$paused=false)=>applicationPublicMovementEffectZones($s,$s['map'],$tokens,'scene','ground',static fn($x,$y)=>$visible,$paused);
$before=$state;$zones=$project($state,$public);$check(count($zones)===2,'Both spatial effects visible');$check($zones[0]['recipientTokenIds']===['own'],'Future distant ally eligible, caster/enemy/independent clone excluded');
$check($zones[1]['recipientTokenIds']===['own'],'Fixed effect references own units only');$check($zones[0]['polarity']==='positive'&&$zones[1]['polarity']==='negative','Qualitative polarity');$check($state===$before,'Projection immutable');
$check(!preg_match('/PRIVATE-|bonuses|force|physicalResistance|holder|abilityImage/',json_encode($zones)),'No numerical rules or secret labels');
$check($project($state,$public,false)===[],'Hidden point omitted');$check($project($state,$public,true,true)===[],'Pause omitted');$check($project($state,$state['map']['tokens'])===[],'No recipient no metadata');
$silhouette=$public;$silhouette[0]['dimSilhouette']=true;$check(array_column($project($state,$silhouette),'id')===['negative'],'Anonymous caster stays secret');
$state['map']['tokens']=[$own,$enemy,$clone];$check(array_column($project($state,$public),'id')===['negative'],'Removed caster does not affect fixed field');$state=$before;
$state['devices']['devices']=[['id'=>'dome','status'=>'active','sceneId'=>'scene','layerId'=>'ground','mode'=>'carried','sourceTokenId'=>'clone','creatorCharacterId'=>'sheet','definition'=>['kind'=>'dome','radiusCells'=>100]]];$zones=$project($state,$public);
$check(count($zones)===3&&$zones[2]['sourceTokenId']==='caster','Carried dome follows creator effective identity');$check($zones[2]['geometry']==='centers'&&$zones[2]['radiusCells']===100,'Dome uses center geometry and own radius limit');
$state['devices']['devices'][0]=[...$state['devices']['devices'][0],'mode'=>'placed','x'=>65,'y'=>40];$zones=$project($state,$public);$check($zones[2]['origin']==='fixed'&&!isset($zones[2]['sourceTokenId']),'Placed dome detached from carrier');
$check(applicationEffectZonePolarity(['mode'=>'stats','bonuses'=>['force'=>10,'agility'=>-10]])==='mixed','Mixed polarity');$check(applicationEffectZonePolarity(['mode'=>'immunity'])==='positive','Immunity positive');$check(applicationEffectZonePolarity(['mode'=>'replace','formula'=>'0'])==='neutral','Replacement unknown neutral');
$view=publicPlayerState($before,['id'=>'owner'],[]);$check(count($view['movementEffectZones'])===2,'Real PHP player projection connects the contract');
$check(!isset(publicPlayerState($before,['id'=>'owner'],[],true)['movementEffectZones']),'Stream carries no drag-only metadata');
$ack=applicationPlayerMoveMapProjection($view,'scene');$check($ack['movementEffectZones']===$view['movementEffectZones'],'Movement ACK carries current warning zones');
$check(applicationPlayerMoveMapProjection(['map'=>[]],'scene')['movementEffectZones']===[],'Zero-zone movement ACK explicitly clears prior zones');
foreach([null,'controlled','following','unowned-sheet'] as $case){$s=$before;if($case==='controlled'){$s['map']['tokens'][0]['controllerPlayerId']='obsolete';$s['map']['tokens'][0]['controlEffect']=['controllerId'=>'current'];}if($case==='following')$s['map']['tokens'][3]['followCharacter']=true;if($case==='unowned-sheet'){$s['characters'][0]['ownerPlayerId']='';$s['map']['tokens'][0]['controllerPlayerId']='obsolete';}
    $all=array_map(static fn($token)=>[...$token,'ownedByYou'=>true],$s['map']['tokens']);$zonesNow=applicationPublicMovementEffectZones($s,$s['map'],$all,'scene','ground',static fn($x,$y)=>true);$positive=null;foreach($zonesNow as $zoneNow)if($zoneNow['id']==='positive')$positive=$zoneNow;
    foreach($s['map']['tokens'] as $target){$moved=[...$target,'x'=>40,'y'=>50];$preview=$s;foreach($preview['map']['tokens']as &$entry)if($entry['id']===$target['id'])$entry=$moved;unset($entry);$check(applicationEnhancementAuraAffects($s['attackEnhancements'][0],$moved,$preview,'scene')===in_array($target['id'],$positive['recipientTokenIds']??[],true),'Actual aura agrees with preview effective ownership '.$case.' '.$target['id']);}}
echo json_encode(['ok'=>true,'checks'=>$checks,'productionAccess'=>false],JSON_THROW_ON_ERROR)."\n";
