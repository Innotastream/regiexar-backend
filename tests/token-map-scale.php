<?php
declare(strict_types=1);
require_once __DIR__.'/../api/v1/token-map-scale.php';
if(in_array('--json',$argv,true)){
 $cases=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
 echo json_encode(array_map(static fn($c)=>applicationScaleUnitForMap($c['token'],$c['map'],$c['layerId']??null),$cases),JSON_THROW_ON_ERROR);exit;
}
ob_start();require_once __DIR__.'/tactical-lifecycle.php';ob_end_clean();
$checks=0;
$check=static function($value,$label)use(&$checks){if(!$value)throw new RuntimeException($label);$checks++;};
$map=['activeLayerId'=>'ground','gridSize'=>50,'naturalWidth'=>1000,'naturalHeight'=>1000,
 'layers'=>['ground'=>['gridSize'=>50,'naturalWidth'=>1000,'naturalHeight'=>1000],'upper'=>['gridSize'=>75,'naturalWidth'=>1000,'naturalHeight'=>1000]]];
foreach(['small'=>45,'normal'=>60,'large'=>90,'huge'=>150] as $category=>$expected){
 $t=['id'=>'unit','size'=>40,'sizeCategory'=>$category];
 for($i=0;$i<5;$i++){$t=applicationScaleUnitForMap($t,$map,'upper');$check($t['size']==$expected,'Indoor size');$t=applicationScaleUnitForMap($t,$map,'ground');}
 $check(validApplicationTokenDomain([...$t,'id'=>'unit','size'=>480]),'Large units valid');
}
$character=['id'=>'owner','linkedTokens'=>[['id'=>'pet','size'=>40,'sizeCategory'=>'normal']]];
$pet=['id'=>'pet-token','characterId'=>'owner','linkedTokenId'=>'pet','size'=>60,'sizeCategory'=>'normal'];
$check(synchronizeOnlineCharacterToken($pet,$character)['size']===60,'Sheet sync preserves scaled companion');
$character['linkedTokens'][0]['sizeCategory']='large';
$read=synchronizeOnlineCharacterToken($pet,$character);
$check($read['size']===60&&$read['sizeCategory']==='normal','Read without map cannot discard arrival scale');
$edited=synchronizeOnlineCharacterToken([...$pet,'layerId'=>'upper'],$character,$map);
$check($edited['size']==90&&$edited['sizeCategory']==='large','Companion category edit uses its own floor');
$character['linkedTokens'][0]['size']=62;$character['linkedTokens'][0]['sizeCategory']='custom';
$edited=synchronizeOnlineCharacterToken($pet,$character);$check($edited['size']===62&&$edited['sizeCategory']==='custom','Explicit companion manual edit remains effective');
$db=fixture();$db->put('map:scene-one',$map);
$mount=['id'=>'mount','layerId'=>'upper','x'=>50,'y'=>50,'size'=>150,'sizeCategory'=>'huge','mountable'=>true];
$rider=['id'=>'rider','layerId'=>'ground','x'=>20,'y'=>20,'size'=>40,'mountedOnTokenId'=>'mount'];
$db->put('token:scene-one:mount',$mount);$db->put('token:scene-one:rider',$rider);
$records=applicationDomainRecords($db);$pending=[];
reconcileApplicationMountedTokenDomains($db,$records,$pending,['scene-one']);
$arrival=$pending['token:scene-one:rider']['payload']??[];
$check(($arrival['size']??0)==60&&($arrival['layerId']??'')==='upper','Following rider adapts to destination');
$check(($arrival['x']??0)===50&&($arrival['y']??0)===50,'Following rider stays on mount');
$check(!validApplicationTokenDomain(['id'=>'bad','sizeCategory'=>'enormous']),'Invalid category rejected');
$check(!validApplicationMapFogState(['gridSize'=>INF]),'Invalid grid rejected');
$check(!validApplicationCharacterDomain(['id'=>'bad','sizeCategory'=>'enormous']),'Invalid character category rejected');
echo json_encode(['ok'=>true,'checks'=>$checks]),PHP_EOL;
