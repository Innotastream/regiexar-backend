<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);
require __DIR__.'/tactical-special-effects.php';
$checks=0;
foreach(['handle-right','handle-left','double'] as $layout)foreach([1.25,2,4] as $width)foreach([0,30,90,150] as $rotation){
    $map=['naturalWidth'=>1024,'naturalHeight'=>1024,'gridSize'=>128,'activeLayerId'=>'ground'];
    $walls=['version'=>1,'width'=>512,'height'=>512,'mask'=>''];
    $door=['id'=>'door','x'=>50,'y'=>50,'hp'=>50,'size'=>40,'layerId'=>'ground','terrainBarrier'=>['version'=>1,'shape'=>'door','widthCells'=>$width,'rotation'=>$rotation,'layout'=>$layout,'open'=>false]];
    $point=static function(float $x,float $y=0)use($rotation):array{$a=deg2rad($rotation);return [50+($x*cos($a)-$y*sin($a))*12.5,50+($x*sin($a)+$y*cos($a))*12.5];};
    foreach([false,true] as $open){
        $door['terrainBarrier']['open']=$open;
        $result=applicationTerrainBarrierWalls($walls,[...$map,'tokens'=>[$door]]);
        $bytes=applicationWallMaskBytes($result);
        $at=static function(array $p)use($result,$bytes):bool{$x=max(0,min($result['width']-1,(int)floor($p[0]/100*$result['width'])));$y=max(0,min($result['height']-1,(int)floor($p[1]/100*$result['height'])));$i=$y*$result['width']+$x;return (ord($bytes[$i>>3])&(1<<($i%8)))!==0;};
        foreach([-1,1] as $side){
            if(!$at($point($side*.52*$width)))throw new RuntimeException("Jamb gap: $width/$rotation/$side");$checks++;
            if($at($point($side*.60*$width)))throw new RuntimeException('The overlap exceeds the small jamb margin');$checks++;
        }
        if($at($point(0))!==!$open)throw new RuntimeException('Open double door must leave its centre clear');$checks++;
    }
    $door['hp']=0;
    if(applicationTerrainBarrierWalls($walls,[...$map,'tokens'=>[$door]])!==$walls)throw new RuntimeException('Destroyed jambs must disappear');$checks++;
}
echo "Door jamb shadows: $checks checks passed\n";
