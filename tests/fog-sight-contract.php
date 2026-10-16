<?php
declare(strict_types=1);
require_once __DIR__.'/../api/v1/domains.php';
if(in_array('--json',$argv,true)){
    $cases=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    echo json_encode(array_map(static fn(array $case):array=>applicationComputeVisionRenderMask($case['occlusion'],$case['origins'],$case['gridSize']),$cases),JSON_THROW_ON_ERROR);
    exit;
}
$checks=0;$check=static function(bool $value,string $message)use(&$checks):void{if(!$value)throw new RuntimeException($message);$checks++;};
$walls=emptyApplicationWallState(1000,1000);$width=$walls['width'];$height=$walls['height'];
$bytes=str_repeat("\0",(int)ceil($width*$height/8));
for($y=0;$y<$height;$y++)for($x=(int)round(.43*($width-1));$x<=(int)round(.47*($width-1));$x++)applicationSetMaskBit($bytes,$y*$width+$x,true);
$fog=['version'=>1,'enabled'=>true,'width'=>$width,'height'=>$height,'mask'=>rtrim(strtr(base64_encode($bytes),'+/','-_'),'=')];
$occlusion=['walls'=>$walls,'fog'=>$fog,'vision'=>['enabled'=>true,'distance'=>16],'naturalWidth'=>1000,'naturalHeight'=>1000];
$origins=[['x'=>20,'y'=>50,'visionDistance'=>16]];
$mask=applicationComputeVisionRenderMask($occlusion,$origins,50);
$check(!applicationVisionCoversPoint($mask,35,50),'Near ground remains visible');
$check(applicationVisionCoversPoint($mask,60,50),'Painted fog blocks sight behind it');
$check(applicationVisionTokenCoversPoint($mask,60,50),'No anonymous token behind fog');
$check($mask['occludedMask']==='','Fog does not create black wall shadow');
$check($walls['mask']===''&&$occlusion['fog']===$fog,'Stored wall and fog masks stay intact');
$light=['id'=>'beacon','name'=>'Balise','x'=>80,'y'=>50,'visionDistance'=>2,'enabled'=>true,'visibleFromAfar'=>true];
$far=[...$occlusion,'fog'=>null,'lightingMode'=>'normal','lights'=>[$light]];$viewer=[['x'=>10,'y'=>50,'visionDistance'=>1]];
$check(!applicationVisionCoversPoint(applicationComputeVisionMask($far,$viewer,50),85,50),'A distant visible beacon relays its own light');
$far['fog']=$fog;$check(applicationVisionCoversPoint(applicationComputeVisionMask($far,$viewer,50),85,50),'Fog still hides a distant beacon');
$far['fog']=null;$far['lights'][0]['visibleFromAfar']=false;
$check(applicationVisionCoversPoint(applicationComputeVisionMask($far,$viewer,50),85,50),'The unchecked option does not reveal distant light');
$check(normalizeApplicationMapLights([array_diff_key($light,['visibleFromAfar'=>true])])[0]['visibleFromAfar']===false,'Default is unchecked');
$check(!validApplicationMapLights([[...$light,'visibleFromAfar'=>'yes']]),'Only JSON booleans are accepted');
$check(normalizeApplicationMapLights([$light])[0]['iconHidden']===false,'Light icon is shown by default');
$check(!validApplicationMapLights([[...$light,'iconHidden'=>'yes']]),'Icon visibility accepts only booleans');
$hiddenLight=[...$light,'iconHidden'=>true];
$plain=[...$occlusion,'fog'=>null,'lights'=>[$light]];
$hidden=[...$plain,'lights'=>[$hiddenLight]];
$check(applicationComputeVisionRenderMask($plain,$viewer,50)===applicationComputeVisionRenderMask($hidden,$viewer,50),'Hiding an icon never alters sight or lighting');
$groups=[['id'=>'chapter','name'=>'Chapitre','entryIds'=>['one','two']]];
$check(validApplicationCollectionGroups($groups),'Ordered groups are valid');
$check(!validApplicationCollectionGroups([...$groups,['id'=>'other','name'=>'Autre','entryIds'=>['one']]]),'One entry cannot belong to two groups');

$clearFog=[...$fog,'enabled'=>false,'mask'=>''];
$map=['activeLayerId'=>'upper','layers'=>['ground'=>['fog'=>$fog,'vision'=>['enabled'=>true]],'upper'=>['fog'=>$clearFog,'naturalWidth'=>1000,'naturalHeight'=>1000]]];
$before=$map;
$check(applicationActiveMapFogState($map)===[...$clearFog,'enabled'=>true],'Enabling a scene preserves an unpainted floor');
$check($map===$before,'Reading shared fog does not modify saved areas');
$map['activeLayerId']='ground';
$check(applicationActiveMapFogState($map)===$fog,'An intentional painted area remains intact');
$map['activeLayerId']='upper';unset($map['layers']['upper']['fog']);
$check(applicationActiveMapFogState($map)['mask']==='','Missing floor fog starts clear, not fully painted');
echo json_encode(['ok'=>true,'fogLightGroupChecks'=>$checks]),"\n";
