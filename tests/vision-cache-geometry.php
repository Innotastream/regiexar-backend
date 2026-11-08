<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/v1/domains.php';
require_once __DIR__ . '/fixtures/vision-base-reference.php';
require_once __DIR__ . '/fixtures/vision-render-reference.php';
set_error_handler(static function (int $severity, string $message): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new RuntimeException($message);
});
$checks = 0;
function visionRequire(bool $ok, string $message): void {
    ++$GLOBALS['checks'];
    if (!$ok) throw new RuntimeException($message);
}
function visionEqual(array $occlusion, array $origins, mixed $grid, int $extension = 0): void {
    $expected = referenceVisionBaseMask($occlusion, $origins, $grid, $extension);
    visionRequire(applicationCalculateBaseVisionMask($occlusion, $origins, $grid, $extension) === $expected, 'Cold computation must match the previous engine byte for byte');
    visionRequire(applicationComputeBaseVisionMask($occlusion, $origins, $grid, $extension) === $expected, 'Per-origin union must match the complete original mask');
    visionRequire(applicationComputeBaseVisionMask($occlusion, $origins, $grid, $extension) === $expected, 'Warm base geometry must match');
}
function renderEqual(array $occlusion, array $origins, mixed $grid): array {
    $expected = referenceVisionRenderMask($occlusion, $origins, $grid);
    visionRequire(applicationCalculateVisionRenderMask($occlusion, $origins, $grid) === $expected, 'Optimized renderer must preserve original opacity and visibility byte for byte');
    visionRequire(applicationComputeVisionRenderMask($occlusion, $origins, $grid) === $expected, 'Cached render must match uncached geometry, opacity and token visibility');
    visionRequire(applicationComputeVisionRenderMask($occlusion, $origins, $grid) === $expected, 'Warm render must match');
    return $expected;
}
mt_srand(37955);
$base = [
    'walls' => ['version'=>1,'width'=>48,'height'=>32,'mask'=>''],
    'vision' => ['version'=>1,'enabled'=>true,'shared'=>false,'distance'=>8],
    'fog' => ['version'=>1,'enabled'=>false,'width'=>48,'height'=>32,'mask'=>''],
    'lights' => [],'naturalWidth'=>960.0,'naturalHeight'=>640.0,'lightingMode'=>'normal',
];
$origins = [['x'=>23.5,'y'=>34.75,'visionDistance'=>8,'darkVision'=>'none','source'=>'token']];
$wallBytes = str_repeat("\0", 192);
for ($row=0;$row<32;++$row) if ($row!==13) applicationSetMaskBit($wallBytes,$row*48+25,true);
$base['walls']['mask'] = rtrim(strtr(base64_encode($wallBytes),'+/','-_'),'=');
$initial = renderEqual($base,$origins,50);
visionEqual($base,$origins,50);
$variations = [];
$next=$base;$next['walls']['mask']='';$variations[]=$next;
$next=$base;$next['walls']['width']=32;$next['walls']['mask']='';$variations[]=$next;
$next=$base;$next['walls']['height']=48;$next['walls']['mask']='';$variations[]=$next;
$next=$base;$next['naturalWidth']=1672;$variations[]=$next;
$next=$base;$next['naturalHeight']=941;$variations[]=$next;
$next=$base;$next['vision']['enabled']=false;$variations[]=$next;
$next=$base;$next['vision']['distance']=15;$variations[]=$next;
foreach (['bright','dark','custom'] as $mode) {$next=$base;$next['lightingMode']=$mode;$variations[]=$next;}
$next=$base;unset($next['lightingMode']);$variations[]=$next;
$next=$base;$next['lightingMode']='custom';$next['customLighting']=['distance'=>5,'night'=>true];$variations[]=$next;
$next=$base;$next['fog']['enabled']=true;$next['fog']['mask']=$base['walls']['mask'];$variations[]=$next;
$next=$base;$next['lights']=[['id'=>'light-a','name'=>'Light','x'=>36,'y'=>34,'visionDistance'=>10,'enabled'=>true,'visibleFromAfar'=>true]];$variations[]=$next;
$next=$next;$next['lights'][0]['enabled']=false;$variations[]=$next;
$next=$base;$next['lights']=[['id'=>'carried','x'=>15,'y'=>20,'visionDistance'=>4,'enabled'=>true,'visibleFromAfar'=>false]];$variations[]=$next;
foreach ($variations as $occlusion) {visionEqual($occlusion,$origins,50);renderEqual($occlusion,$origins,50);}
foreach ([12,70,240,'70',null] as $grid) {visionEqual($base,$origins,$grid);renderEqual($base,$origins,$grid);}
foreach (['x'=>30.0,'y'=>41.0,'visionDistance'=>4,'darkVision'=>'full','source'=>'light','environmentAdjusted'=>true] as $field=>$value) {
    $changed=$origins;$changed[0][$field]=$value;
    visionEqual($base,$changed,50);renderEqual($base,$changed,50);
}
visionRequire(renderEqual($base,$origins,50)===$initial,'Returning to initial geometry cannot keep another state');
foreach ([[],[null],['invalid'],[['x'=>30]],array_fill(0,40,null)] as $invalid) {
    visionEqual($base,$invalid,50);renderEqual($base,$invalid,50);
}
$forty=array_fill(0,40,$origins[0]);
visionRequire(applicationComputeVisionRenderMask($base,[...$forty,['x'=>90,'y'=>90]],50)
    ===applicationCalculateVisionRenderMask($base,$forty,50),'The forty-origin bound remains unchanged');
for($case=0;$case<80;++$case) {
    $width=[32,33,47,64][$case%4];$height=[32,35,48][$case%3];
    $bytes=str_repeat("\0",(int)ceil($width*$height/8));
    for($i=0;$i<$width*$height/6;++$i)applicationSetMaskBit($bytes,mt_rand(0,$width*$height-1),true);
    $occlusion=$base;$occlusion['walls']=['version'=>1,'width'=>$width,'height'=>$height,'mask'=>rtrim(strtr(base64_encode($bytes),'+/','-_'),'=')];
    $occlusion['naturalWidth']=mt_rand(300,2000);$occlusion['naturalHeight']=mt_rand(300,1500);
    $occlusion['lightingMode']=['normal','dark','bright'][$case%3];
    $randomOrigins=[];
    for($i=0;$i<1+$case%4;++$i)$randomOrigins[]=['x'=>mt_rand(-10,11000)/100,'y'=>mt_rand(-10,11000)/100,'visionDistance'=>mt_rand(1,40),'darkVision'=>$i%2?'full':'none'];
    $grid=mt_rand(12,240);
    visionEqual($occlusion,$randomOrigins,$grid,$case%7);
    renderEqual($occlusion,$randomOrigins,$grid);
}
// Cover the production-size ray sweep, edge origins, long-distance shadows,
// empty maps, solid first wall bodies and every possible packed input byte.
$bytes=str_repeat("\0",32768);
foreach([90,210,350] as $column)for($row=40;$row<470;++$row){
    if($row>200&&$row<240)continue;
    applicationSetMaskBit($bytes,$row*512+$column,true);
    if($column===210)applicationSetMaskBit($bytes,$row*512+$column+1,true);
}
$large=$base;$large['walls']=['version'=>1,'width'=>512,'height'=>512,'mask'=>rtrim(strtr(base64_encode($bytes),'+/','-_'),'=')];
$large['naturalWidth']=$large['naturalHeight']=1000.0;
foreach([[15.0,45.0,0],[100.0,0.0,30],[41.0958904109589,50.0,6]] as [$x,$y,$extension]){
    $origin=[['x'=>$x,'y'=>$y,'visionDistance'=>8]];
    visionEqual($large,$origin,50,$extension);
    renderEqual($large,$origin,50);
}
$large['walls']['mask']='';visionEqual($large,[['x'=>0.0,'y'=>100.0,'visionDistance'=>40]],50);
$rectangle=$base;$rectangle['walls']=['version'=>1,'width'=>512,'height'=>288,'mask'=>''];
$rectangle['naturalWidth']=1672.0;$rectangle['naturalHeight']=941.0;
$roomBytes=str_repeat("\0",18432);
foreach([[80,170,40,240],[230,250,60,260]] as [$left,$right,$top,$bottom]){
    for($column=$left;$column<=$right;++$column){applicationSetMaskBit($roomBytes,$top*512+$column,true);applicationSetMaskBit($roomBytes,$bottom*512+$column,true);}
    for($row=$top;$row<=$bottom;++$row){if($row<135||$row>145)applicationSetMaskBit($roomBytes,$row*512+$left,true);applicationSetMaskBit($roomBytes,$row*512+$right,true);}
}
$rectangle['walls']['mask']=rtrim(strtr(base64_encode($roomBytes),'+/','-_'),'=');
$fogBytes=str_repeat("\0",1152);for($row=5;$row<60;++$row)applicationSetMaskBit($fogBytes,$row*128+52,true);
$rectangle['fog']=['version'=>1,'enabled'=>true,'width'=>128,'height'=>72,'mask'=>rtrim(strtr(base64_encode($fogBytes),'+/','-_'),'=')];
$roomOrigins=[['x'=>23.5,'y'=>48,'visionDistance'=>8],['x'=>47,'y'=>70,'visionDistance'=>4]];
foreach([array_slice($roomOrigins,0,1),$roomOrigins] as $roomView){visionEqual($rectangle,$roomView,50,6);renderEqual($rectangle,$roomView,50);}
$allBytes='';for($byte=0;$byte<256;++$byte)$allBytes.=chr($byte);
$packed=$base;$packed['walls']=['version'=>1,'width'=>64,'height'=>32,'mask'=>rtrim(strtr(base64_encode($allBytes),'+/','-_'),'=')];
visionEqual($packed,[['x'=>0,'y'=>0,'visionDistance'=>40],['x'=>15.7,'y'=>3.3,'visionDistance'=>40]],50);
renderEqual($packed,[['x'=>0,'y'=>0,'visionDistance'=>40]],50);
echo json_encode(['ok'=>true,'geometryChecks'=>$checks,'randomGeometries'=>80,'largeGeometries'=>6,'allPackedBytes'=>true,'mixedRasterSizes'=>true,'productionAccess'=>false]).PHP_EOL;
