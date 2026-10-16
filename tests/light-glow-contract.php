<?php
declare(strict_types=1);
require_once __DIR__.'/../api/v1/domains.php';
require_once __DIR__.'/../api/v1/light-glow.php';
if(in_array('--json',$argv,true)){
  $cases=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
  echo json_encode(array_map(static fn($c)=>applicationLightGlow($c['occlusion'],$c['viewerMask'],$c['gridSize']),$cases),JSON_THROW_ON_ERROR);exit;
}
$walls=emptyApplicationWallState(1000,1000);
$base=['walls'=>$walls,'vision'=>['enabled'=>true,'distance'=>5],'naturalWidth'=>1000,'naturalHeight'=>1000,'fog'=>null];
$viewer=applicationComputeVisionRenderMask($base,[['x'=>20,'y'=>50,'visionDistance'=>5]],50);
$light=['id'=>'private-source','name'=>'Nom privé','x'=>70,'y'=>50,'visionDistance'=>9,'enabled'=>true,'color'=>'#aa33ff','carrierTokenId'=>'hidden-carrier','portable'=>true];
$glow=applicationLightGlow([...$base,'lights'=>[$light]],$viewer,50);
if($glow===null||str_contains(json_encode($glow),'private-source')||!applicationVisionCoversPoint($viewer,70,50))throw new RuntimeException('A glow is visible without exposing its hidden source or granting sight.');
if(applicationLightGlow([...$base,'lights'=>[[...$light,'enabled'=>false]]],$viewer,50)!==null)throw new RuntimeException('An extinguished light glows.');
echo "Halos PHP : lueur séparée de la source et de la vision.\n";
