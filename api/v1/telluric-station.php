<?php
declare(strict_types=1);
require_once __DIR__.'/device-state.php';

// Reuse the already computed wall geometry. Neither the DTO nor its animation
// exposes that private mask, the caster's resources or the station's owner.
function applicationTelluricStationVisuals(array $store,array $visibleDevices,array $characters,array $context,callable $visible):array {
    if(!empty($context['paused'])||empty($context['sceneId']))return [];
    $sceneId=$context['sceneId'];$layerId=$context['layerId']??'ground';$map=$context['telluricMap']??$context['map']??[];
    $stations=[];foreach($visibleDevices as $device)if(empty($device['controlOnly'])&&$device['status']==='active'&&$device['definition']['kind']==='battery'&&$device['sceneId']===$sceneId&&$device['layerId']===$layerId)$stations[$device['id']]=$device;
    if(!$stations)return [];
    $occlusion=$context['occlusion']??applicationActiveMapOcclusionState($map);$walls=$occlusion['walls'];$bytes=applicationWallMaskBytes($walls);
    $direct=static function(array $a,array $b)use($occlusion,$walls,$bytes):bool {
        if($bytes===null||($walls['mask']??'')==='')return true;
        $steps=max(1,(int)ceil(hypot(($a['x']-$b['x'])*$occlusion['naturalWidth']/100,($a['y']-$b['y'])*$occlusion['naturalHeight']/100)));
        for($i=0;$i<=$steps;$i++){$x=(int)round(($a['x']+($b['x']-$a['x'])*$i/$steps)/100*($walls['width']-1));$y=(int)round(($a['y']+($b['y']-$a['y'])*$i/$steps)/100*($walls['height']-1));if(applicationMaskBit($bytes,$y*$walls['width']+$x))return false;}
        return true;
    };
    $shown=array_fill_keys($context['clearlyVisibleTokenIds']??$context['visibleTokenIds']??[],true);$sheets=[];foreach($characters as $sheet)$sheets[$sheet['id']]=$sheet;
    $links=array_fill_keys(array_keys($stations),[]);
    foreach($map['tokens']??[] as $token){
        if(!isset($shown[$token['id']])||!empty($token['hidden'])||!empty($token['dimSilhouette'])||!empty($token['nonCombatant'])||isset($token['terrainBarrier'])||isset($token['fixedStructure'])||($token['layerId']??'ground')!==$layerId||!$visible($token))continue;
        $maxMana=$token['maxMana']??$token['resources']['maxMana']??((($token['followCharacter']??true)!==false&&empty($token['linkedTokenId']))?($sheets[$token['characterId']??'']['resources']['maxMana']??0):0);
        if(!is_numeric($maxMana)||(float)$maxMana<=0)continue;
        $index=dsProvider($store,$token,$map,$sceneId,$direct);
        if($index!==null&&isset($stations[$store['devices'][$index]['id']]))$links[$store['devices'][$index]['id']][]=$token['id'];
    }
    $rows=[];foreach($stations as $id=>$station){
        $row=['version'=>1,'kind'=>'telluric-station','id'=>$id,'sceneId'=>$sceneId,'layerId'=>$layerId,'x'=>$station['x'],'y'=>$station['y'],'size'=>$station['size']??64,'rangeCells'=>$station['definition']['radiusCells'],'linkTokenIds'=>$links[$id]];
        if(($station['artworkPreset']??'')!=='telluric-station'&&dsImage($station['image']??'')!=='')$row['image']=dsImage($station['image']);
        $rows[]=$row;
    }
    return $rows;
}
