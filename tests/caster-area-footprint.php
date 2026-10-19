<?php
declare(strict_types=1);
define('XAR_TACTICAL_FIXTURE_ONLY',true);
require __DIR__.'/tactical-lifecycle.php';
$map=['naturalWidth'=>1000,'naturalHeight'=>1000,'gridSize'=>50,'activeLayerId'=>'ground'];
foreach([20,40,150,300] as $size)foreach([false,true] as $grid){
    $map['grid']=$grid;
    $source=['id'=>'independent-clone','x'=>30,'y'=>50,'size'=>$size,'layerId'=>'ground'];
    $target=['id'=>'edge','x'=>30+(100+$size/2+20)/10,'y'=>50,'size'=>40,'layerId'=>'ground'];
    $outside=[...$target,'id'=>'outside','x'=>$target['x']+.001];
    $hidden=[...$target,'id'=>'hidden','hidden'=>true];$upper=[...$target,'id'=>'upper','layerId'=>'upper'];
    $tokens=[$target,$outside,$hidden,$upper];
    requireTactical(array_column(onlineDamageAreaTargets($tokens,$source,$map,2,true),'id')===['edge'],
        'Caster aura includes the touching edge, excludes the next point, hidden pawns and another floor.');
    requireTactical(onlineDamageAreaTargets([$target],$source,$map,2)===[],
        'An aimed circle keeps its fixed radius rather than inheriting a token footprint.');
}
echo json_encode(['ok'=>true,'checks'=>$GLOBALS['checks']]),"\n";
