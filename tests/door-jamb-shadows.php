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
$map = ['naturalWidth'=>1200,'naturalHeight'=>8000,'gridSize'=>16,'activeLayerId'=>'ground',
    'walls'=>emptyApplicationWallState(1200,8000),'vision'=>['version'=>1,'enabled'=>true,'distance'=>12,'shared'=>false],
    'lightingMode'=>'bright'];
$door = ['id'=>'thin-closed-door','x'=>50.07,'y'=>50.02,'hp'=>50,'size'=>40,'layerId'=>'ground',
    'terrainBarrier'=>['version'=>1,'shape'=>'door','widthCells'=>2.5,'rotation'=>75,'open'=>false]];
$angle = deg2rad(75);
$point = static function(float $normal) use ($angle): array {
    return ['x'=>50.07 + (-12 * cos($angle) - $normal * sin($angle))/12,
        'y'=>50.02 + (-12 * sin($angle) + $normal * cos($angle))/80];
};
foreach ([18,24] as $viewerDistance) {
    $viewer = [...$point($viewerDistance), 'source'=>'token', 'visionDistance'=>12, 'darkVision'=>'none'];
    $near = $point(52);
    $far = $point(-26);
    $db = specialFixture();
    $db->put('map:scene-one', $map);
    $db->put('token-index:scene-one', ['order'=>['token-player', 'token-monster', 'token-monster-two', $door['id']]]);
    $db->put('token:scene-one:token-player', [...$db->payload('token:scene-one:token-player'), ...$point($viewerDistance)]);
    $db->put('token:scene-one:token-monster', [...$db->payload('token:scene-one:token-monster'), ...$near, 'size'=>12]);
    $db->put('token:scene-one:token-monster-two', [...$db->payload('token:scene-one:token-monster-two'), ...$far, 'size'=>12]);
    foreach ([false,true,false] as $open) {
        $door['terrainBarrier']['open'] = $open;
        $occlusion = applicationActiveMapOcclusionState($map, [$door]);
        $visible = onlineVisiblePathPointTester(null, applicationComputeVisionMask($occlusion, [$viewer], 16));
        if (!$visible($near['x'], $near['y'])) {
            throw new RuntimeException('A narrow closed door must preserve sight on the observer’s side.');
        }
        if ($visible($far['x'], $far['y']) !== $open) {
            throw new RuntimeException('The rotated door must block this sight line only when closed.');
        }
        $render = applicationComputeVisionRenderMask($occlusion, [$viewer], 16);
        if (applicationVisionTokenCoversPoint($render, $near['x'], $near['y'])
            || applicationVisionTokenCoversPoint($render, $far['x'], $far['y']) === $open) {
            throw new RuntimeException('The player render and current authority must agree across close, open and close: '
                . json_encode([$viewerDistance, $open, $near, $far,
                    applicationVisionTokenCoversPoint($render, $near['x'], $near['y']),
                    applicationVisionTokenCoversPoint($render, $far['x'], $far['y'])]));
        }
        $db->put('token:scene-one:' . $door['id'], $door);
        $state = domainsToApplicationState(applicationDomainRecords($db), $db->revision);
        $player = publicPlayerState($state, ['id'=>'account-player', 'display_name'=>'Joueur'], []);
        $ids = array_column($player['map']['tokens'] ?? [], 'id');
        if (!in_array('token-monster', $ids, true) || in_array('token-monster-two', $ids, true) !== $open) {
            throw new RuntimeException('The player snapshot must only reveal the target behind an open door: '
                . json_encode([$viewerDistance, $open, $ids]));
        }
        $checks += 4;
    }
}
echo "Door jamb shadows: $checks checks passed\n";
