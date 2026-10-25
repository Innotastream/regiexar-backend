<?php
declare(strict_types=1);
require_once __DIR__.'/../api/v1/domains.php';
if(in_array('--json',$argv,true)){
    $cases=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$out=[];
    foreach($cases as $case)$out[]=applicationPublicTerrainEffects($case['map'],$case['layerId']??'ground',static fn($x,$y)=>($case['visibleMode']??'all')==='all'||($case['visibleMode']??'all')==='left'&&$x<50);
    echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);exit;
}
$checks=0;$check=static function(bool $ok,string $label)use(&$checks){if(!$ok)throw new RuntimeException($label);$checks++;};
$effect=['id'=>'private-stroke','kind'=>'fire','layerId'=>'ground','width'=>32,'height'=>32,'mask'=>rtrim(strtr(base64_encode(str_repeat(chr(255),128)),'+/','-_'),'=')];
$check(count(normalizeApplicationTerrainEffects([$effect]))===1,'normalisation');
$check(normalizeApplicationTerrainEffects([])===[],'suppression de toutes les zones');
$check(!isset(normalizeApplicationTerrainEffects([[...$effect,'privateNote'=>'secret']])[0]['privateNote']),'pas de champ libre');
$invalid=[null,[],[...$effect,'kind'=>'future'],[...$effect,'layerId'=>'roof'],[...$effect,'width'=>513],[...$effect,'width'=>'32'],[...$effect,'mask'=>'broken'],[...$effect,'mask'=>'']];
foreach($invalid as $bad){if($bad===[])continue;$input=[$bad];$before=$input;$failed=false;try{normalizeApplicationTerrainEffects($input);}catch(RuntimeException){$failed=true;}$check($failed,'paramètre invalide');$check($input===$before,'aucune réécriture sur rejet');}
$failed=false;try{normalizeApplicationTerrainEffects([$effect,$effect]);}catch(RuntimeException){$failed=true;}$check($failed,'doublon');
$failed=false;try{normalizeApplicationTerrainEffects(array_map(static fn($i)=>[...$effect,'id'=>'id-'.$i],range(1,65)));}catch(RuntimeException){$failed=true;}$check($failed,'limite atomique');
$check(validApplicationMapFogState(['terrainEffects'=>[$effect]]),'domaine de carte valide');$check(!validApplicationMapFogState(['terrainEffects'=>[[...$effect,'kind'=>'unknown']]]),'domaine de carte refusé');
$map=['naturalWidth'=>1000,'naturalHeight'=>1000,'activeLayerId'=>'ground','terrainEffects'=>[$effect,[...$effect,'id'=>'second']]];
$all=applicationPublicTerrainEffects($map,'ground',static fn()=>true);$check(count($all)===1,'fusion du matériau');$check($all[0]['id']==='terrain-fire-ground','identifiant générique');$check(!str_contains(json_encode($all),'private-stroke'),'aucun identifiant privé');
$check(applicationPublicTerrainEffects($map,'upper',static fn()=>true)===[],'autre niveau');$check(applicationPublicTerrainEffects($map,'ground',static fn()=>false)===[],'zone invisible');
$left=applicationPublicTerrainEffects($map,'ground',static fn($x,$y)=>$x<50);$bytes=applicationTerrainMaskBytes($left[0]);$check(ord($bytes[0])===255,'partie visible');$check(ord($bytes[63])===0,'prolongement caché effacé');
$check(applicationPublicTerrainEffects($map,'ground',static fn()=>true)===$all,'cache indépendant de la visibilité');
$check(applicationTerrainRasterDimensions(['naturalWidth'=>2000,'naturalHeight'=>1000])===['width'=>512,'height'=>256],'ratio paysage');$check(applicationTerrainRasterDimensions(['naturalWidth'=>1000,'naturalHeight'=>2000])===['width'=>256,'height'=>512],'ratio portrait');
echo json_encode(['ok'=>true,'checks'=>$checks,'productionAccess'=>false],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n";
