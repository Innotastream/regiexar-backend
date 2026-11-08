<?php
declare(strict_types=1);
require_once __DIR__.'/../api/v1/domains.php';
require_once __DIR__.'/../api/v1/light-glow.php';
require_once __DIR__.'/fixtures/light-glow-reference.php';
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
if($glow!==referenceLightGlow([...$base,'lights'=>[$light]],$viewer,50))throw new RuntimeException('A full-resolution glow differs from the previous PNG.');

// Preserve complete PNG bytes across matched/mismatched rasters, partial
// opacity data, occlusion, fog, darkness and several overlapping lights.
mt_srand(813755);
$pack=static fn(string $bytes):string=>rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');
for($case=0;$case<36;++$case){
    $width=$case%3===0?48:32;$height=$case%3===1?48:32;
    $wallBytes=str_repeat("\0",(int)ceil($width*$height/8));
    for($i=0;$i<24;++$i)applicationSetMaskBit($wallBytes,mt_rand(0,$width*$height-1),true);
    $wall=['version'=>1,'width'=>$width,'height'=>$height,'mask'=>$pack($wallBytes)];
    $viewWidth=$case%4===0?32:$width;$viewHeight=$case%4===0?32:$height;
    $viewBits=str_repeat("\0",(int)ceil($viewWidth*$viewHeight/8));
    for($i=0;$i<$viewWidth*$viewHeight/3;++$i)applicationSetMaskBit($viewBits,mt_rand(0,$viewWidth*$viewHeight-1),true);
    $view=['version'=>1,'enabled'=>$case%5!==0,'width'=>$viewWidth,'height'=>$viewHeight,'mask'=>$pack($viewBits)];
    $opacity='';for($i=0;$i<$viewWidth*$viewHeight;++$i)$opacity.=chr(mt_rand(0,255));
    if($case%6!==0)$view['opacity']=$pack($case%6===1?substr($opacity,0,17):$opacity);
    if($case%3===0)$view['occludedMask']=$pack($viewBits);
    if($case%9===0)$view['occludedMask']=$pack(substr($viewBits,0,5));
    $fog=$case%2===0?['version'=>1,'enabled'=>true,'width'=>$viewWidth,'height'=>$viewHeight,'mask'=>$pack($viewBits)]:null;
    $lights=[];for($i=0;$i<1+$case%3;++$i)$lights[]=[...$light,'id'=>'light-'.$i,'x'=>10+mt_rand(0,8000)/100,'y'=>10+mt_rand(0,8000)/100,'color'=>['#aa33ff','#ffc800','#00ffcc'][$i],'visionDistance'=>3+$i];
    $occlusion=['walls'=>$wall,'vision'=>['enabled'=>true,'distance'=>5],'fog'=>$fog,'naturalWidth'=>1000,'naturalHeight'=>750,'lights'=>$lights];
    $expected=referenceLightGlow($occlusion,$view,50);
    if(applicationCalculateLightGlow($occlusion,$view,50)!==$expected||applicationLightGlow($occlusion,$view,50)!==$expected)throw new RuntimeException('Glow PNG changed in geometry case '.$case);
}
$rectangle=['walls'=>['version'=>1,'width'=>512,'height'=>288,'mask'=>''],'vision'=>['enabled'=>true,'distance'=>8],
    'naturalWidth'=>1672.0,'naturalHeight'=>941.0,'lights'=>[[...$light,'x'=>36.5,'y'=>48.75,'visionDistance'=>6]]];
$fogBits=str_repeat("\0",1152);for($row=5;$row<65;++$row)applicationSetMaskBit($fogBits,$row*128+59,true);
$rectangle['fog']=['version'=>1,'enabled'=>true,'width'=>128,'height'=>72,'mask'=>$pack($fogBits)];
$viewBits=str_repeat("\0",4608);for($row=5;$row<130;++$row)applicationSetMaskBit($viewBits,$row*256+103,true);
$rectangleView=['version'=>1,'enabled'=>true,'width'=>256,'height'=>144,'mask'=>$pack($viewBits),'opacity'=>$pack(str_repeat("\x80",36864))];
if(applicationLightGlow($rectangle,$rectangleView,50)!==referenceLightGlow($rectangle,$rectangleView,50))throw new RuntimeException('A rectangular map with three raster sizes changes its PNG.');
echo "Halos PHP : lueur séparée de la source et de la vision ; 38 PNG identiques à la référence.\n";
