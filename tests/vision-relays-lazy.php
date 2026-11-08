<?php
declare(strict_types=1);
require_once __DIR__.'/../api/v1/domains.php';
require_once __DIR__.'/fixtures/vision-relays-reference.php';
set_error_handler(static function(int $severity,string $message):bool {
    if (!(error_reporting() & $severity)) return false;
    throw new RuntimeException($message);
});
$checks=0;
function relayCheck(array $occlusion,array $origins,int $grid,string $label):void {
    ++$GLOBALS['checks'];
    if (applicationResolveVisionRelays($occlusion,$origins,$grid)!==referenceVisionRelays($occlusion,$origins,$grid)) {
        throw new RuntimeException('Relay geometry changed: '.$label);
    }
}
$base=['walls'=>['version'=>1,'width'=>48,'height'=>32,'mask'=>''],
    'fog'=>['version'=>1,'enabled'=>false,'width'=>48,'height'=>32,'mask'=>''],
    'vision'=>['version'=>1,'enabled'=>true,'distance'=>4,'shared'=>true],
    'naturalWidth'=>1600.0,'naturalHeight'=>900.0,'lightingMode'=>'normal','lights'=>[]];
$origins=[['x'=>10.0,'y'=>50.0,'visionDistance'=>4,'darkVision'=>'none','source'=>'token']];
$lights=[];
foreach([15,30,50,70,90] as $i=>$x)$lights[]=['id'=>'light-'.$i,'name'=>'Synthetic light','x'=>$x,'y'=>50,'visionDistance'=>6,'enabled'=>true,'visibleFromAfar'=>$i===4];
foreach(['normal','dark','bright','custom'] as $mode) {
    $state=[...$base,'lightingMode'=>$mode];
    relayCheck($state,$origins,50,$mode.' without lights');
    relayCheck([...$state,'lights'=>$lights],$origins,50,$mode.' chained lights');
    relayCheck([...$state,'lights'=>array_reverse($lights)],$origins,50,$mode.' reversed persisted order');
}
$bytes=str_repeat("\0",192);
for($y=0;$y<32;$y++)applicationSetMaskBit($bytes,$y*48+22,true);
$blocked=$base;$blocked['walls']['mask']=rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');$blocked['lights']=$lights;
relayCheck($blocked,$origins,50,'Solid wall blocks even distant lights');
$blocked['fog']=['version'=>1,'enabled'=>true,...$blocked['walls']];$blocked['walls']['mask']='';
relayCheck($blocked,$origins,50,'Fog occludes relays');
relayCheck([...$base,'vision'=>['enabled'=>false],'lights'=>$lights],$origins,50,'Vision disabled');
relayCheck([...$base,'lights'=>$lights],[],50,'No observer');
mt_srand(81773);
for($case=0;$case<80;$case++) {
    $state=$base;$state['lightingMode']=['normal','dark','bright','custom'][$case%4];
    $state['customLighting']=['distance'=>3+$case%8,'night'=>$case%2===0];
    $bytes=str_repeat("\0",192);
    for($i=0;$i<70;$i++)applicationSetMaskBit($bytes,mt_rand(0,1535),true);
    $state['walls']['mask']=rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');
    $state['lights']=[];
    for($i=0;$i<$case%8;$i++)$state['lights'][]=['id'=>'random-'.$i,'name'=>'Synthetic','x'=>mt_rand(0,10000)/100,'y'=>mt_rand(0,10000)/100,'visionDistance'=>mt_rand(1,12),'enabled'=>$i%5!==0,'visibleFromAfar'=>$i%3===0];
    $viewers=[];
    for($i=0;$i<1+$case%7;$i++)$viewers[]=['x'=>mt_rand(0,10000)/100,'y'=>mt_rand(0,10000)/100,'visionDistance'=>mt_rand(1,10),'darkVision'=>$i%2?'full':'none','source'=>'token'];
    relayCheck($state,$viewers,50,'Random '.$case);
}
echo json_encode(['ok'=>true,'relayChecks'=>$checks,'randomGeometries'=>80,'productionAccess'=>false]).PHP_EOL;
