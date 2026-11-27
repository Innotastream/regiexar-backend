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
$map=['activeLayerId'=>'ground','gridSize'=>40,'unitScalePreset'=>'outdoor','naturalWidth'=>1000,'naturalHeight'=>1000,
 'layers'=>['ground'=>['gridSize'=>40,'unitScalePreset'=>'outdoor','naturalWidth'=>1000,'naturalHeight'=>1000],
    'upper'=>['gridSize'=>60,'unitScalePreset'=>'indoor','naturalWidth'=>1000,'naturalHeight'=>1000]]];
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
$normal = ['id'=>'normal', 'sizeCategory'=>'normal'];
foreach (['outdoor'=>40,'wide'=>25,'indoor'=>60] as $preset=>$grid) {
    $check(applicationMapScaleSettings(['unitScalePreset'=>$preset])['gridSize']===$grid*1.0,'Preset supplies its matching grid.');
    $check(applicationScaleUnitForMap($normal,['unitScalePreset'=>$preset])['size']==$grid,'Normal unit equals its preset cell size.');
}
foreach ([false,true] as $auto) {
    $customMap = ['unitScalePreset'=>'custom','unitScaleAuto'=>$auto,'gridSize'=>20];
    $check(applicationScaleUnitForMap($normal,$customMap)['size']==20,'Normal unit equals a 20-pixel custom cell.');
    $check(applicationScaleUnitForMap(['id'=>'large','size'=>60],$customMap)['size']==30,
        'A legacy large unit keeps the standard category ratio on a 20-pixel grid.');
    $check(applicationScaleUnitForMap([...$normal,'sizeCategory'=>'huge'],$customMap)['size']==50,'Other sizes preserve their category ratio.');
    $check(applicationScaleUnitForMap(['id'=>'bespoke','sizeCategory'=>'custom','size'=>37],$customMap)['size']==37,'Manual units retain their custom size.');
}
$mixed = ['activeLayerId'=>'ground','unitScalePreset'=>'outdoor','gridSize'=>40,'unitScaleAuto'=>true,'layers'=>[
    'ground'=>['unitScalePreset'=>'outdoor','gridSize'=>40,'unitScaleAuto'=>true], 'upper'=>['unitScalePreset'=>'wide','unitScaleAuto'=>false],
    'basement'=>['gridSize'=>50],
]];
$check(applicationMapScaleSettings($mixed,'ground')['unitScaleAuto']===true,'The active layer supplies its own auto flag.');
$check(applicationMapScaleSettings($mixed,'upper')['unitScaleAuto']===false&&applicationMapForUnitLayer($mixed,'upper')['gridSize']==25,
    'A wide level retains its own false setting and uses the wide default.');
$check(applicationMapScaleSettings($mixed,'basement')['unitScaleAuto']===false&&applicationMapScaleSettings($mixed,'basement')['unitScalePreset']==='custom',
    'A numeric historical level retains its grid without inheriting the active auto flag.');
$check(applicationScaleUnitForMap($normal,['gridSize'=>50])['size']==50&&applicationMapScaleSettings(['gridSize'=>50])['unitScalePreset']==='custom',
    'An explicit historical numeric grid is not rewritten at read time.');
$check(applicationMapScaleSettings(['unitScalePreset'=>'indoor','gridSize'=>75])['gridSize']==75,
    'An explicit old preset caliber is rewritten only by the data migration.');
foreach (['small'=>20,'normal'=>25,'large'=>35,'huge'=>62.5] as $category=>$size) {
    $check(applicationScaleUnitForMap([...$normal,'sizeCategory'=>$category],['unitScalePreset'=>'wide'])['size']==$size,
        'Wide calibration applies its own category sizes.');
}
foreach (['small'=>16,'normal'=>20,'large'=>28,'huge'=>50] as $category=>$size) {
    $customWide = ['unitScalePreset'=>'custom','unitScaleProfile'=>'wide','gridSize'=>20];
    $check(applicationScaleUnitForMap([...$normal,'sizeCategory'=>$category],$customWide)['size']==$size,
        'Customizing the grid retains the wide category ratios.');
}
$check(!isset(applicationMapScaleSettings(['unitScalePreset'=>'outdoor','unitScaleProfile'=>'wide'])['unitScaleProfile']),
    'Named presets ignore the custom profile.');
$check(validApplicationMapFogState($mixed)&&!validApplicationMapFogState(['unitScaleAuto'=>1])
    &&!validApplicationMapFogState(['layers'=>['ground'=>['unitScalePreset'=>'wide','unitScaleAuto'=>'false']]])
    &&!validApplicationMapFogState(['layers'=>['ground'=>['unitScalePreset'=>'custom','unitScaleProfile'=>'other']]]),
    'Domains validate the new preset and require a real boolean on every level.');
$db->put('map:scene-one',$mixed);
$state = domainsToApplicationState(applicationDomainRecords($db),0);
foreach ([false,true] as $stream) {
    $projection = publicPlayerState($state,['id'=>'account-player'],[],$stream);
    $check(($projection['map']['unitScaleAuto']??null)===true&&($projection['map']['gridSize']??null)==40,
        'The active settings are consistent in both player and stream projections.');
}
echo json_encode(['ok'=>true,'checks'=>$checks]),PHP_EOL;
