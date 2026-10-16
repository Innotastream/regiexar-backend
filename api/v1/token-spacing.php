<?php
declare(strict_types=1);
function separateOnlineTokenPosition(array $token,array $desired,array $map,array $tokens,?Closure $visible=null):array {
    $layerId=onlineTokenLayerId($token,$map);$level=array_replace($map,$map['layers'][$layerId]??[]);$width=(float)($level['naturalWidth']??1600)?:1600;$height=(float)($level['naturalHeight']??900)?:900;
    $fog=applicationActiveMapFogState([...$map,'activeLayerId'=>$layerId]);
    $others=array_values(array_filter($tokens,static fn($other)=>$other['id']!==$token['id']&&empty($other['mountedOnTokenId'])&&empty($other['hidden'])&&onlineTokenLayerId($other,$map)===$layerId&&!applicationFogCoversPoint($fog,$other['x'],$other['y'])&&($visible===null||$visible($other['x'],$other['y']))));
    // Keep close or partially overlapping portraits; separate only complete covers.
    $overlaps=false;foreach($others as $other)if(hypot(($desired['x']-$other['x'])*$width/100,($desired['y']-$other['y'])*$height/100)<=abs(onlineGroupTokenSize($token)-onlineGroupTokenSize($other))/2){$overlaps=true;break;}
    if(!$overlaps)return $desired;
    $allowed=$visible?static function($point)use($visible,$desired,$width,$height):bool{
        if(!$visible($point['x'],$point['y']))return false;
        $steps=max(1,(int)ceil(max(abs($point['x']-$desired['x'])*$width/100,abs($point['y']-$desired['y'])*$height/100)));
        for($i=1;$i<$steps;$i++)if(!$visible($desired['x']+($point['x']-$desired['x'])*$i/$steps,$desired['y']+($point['y']-$desired['y'])*$i/$steps))return false;
        return true;
    }:null;
    $position=nearestOnlineGroupPosition($desired,$token,$level,$others,$desired,4,$allowed);
    if(!$position||hypot(($position['x']-$desired['x'])*$width/100,($position['y']-$desired['y'])*$height/100)>max(onlineGroupTokenSize($token)*2,(float)($map['gridSize']??50)))return $desired;
    return [...$desired,...$position,'separated'=>true,'adjusted'=>true,...(isset($desired['path'])?['path'=>[...$desired['path'],$position]]:[])];
}
