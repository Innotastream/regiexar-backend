<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY', true);
require __DIR__.'/tactical-special-effects.php';

$keyholeChecks = 0;
function keyholeCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    $GLOBALS['keyholeChecks']++;
}

function thinBarrierFixture(string $kind, int $rotation): MemoryConnection {
    $db = specialFixture();
    $map = $db->payload('map:scene-one');
    $map['vision'] = ['enabled'=>true,'distance'=>15,'shared'=>false];
    $map['lightingMode'] = 'day';
    $db->put('map:scene-one', $map);
    $door = applicationCreateTerrainBarrierToken(
        ['id'=>'door','name'=>'Porte test','terrainBarrier'=>['version'=>1,'shape'=>'door','hp'=>50,'size'=>40]],
        ['x'=>50,'y'=>50,'rotation'=>$rotation], 'thin-barrier-door');
    $door['terrainBarrier']['allowPeek'] = true;
    $db->put('token:scene-one:thin-barrier-door', $door);
    $index = $db->payload('token-index:scene-one'); $index['order'][] = $door['id'];
    $db->put('token-index:scene-one', $index);
    $angle = deg2rad($rotation); $cos = cos($angle); $sin = sin($angle);
    $source = $db->payload('token:scene-one:token-player');
    $source['x']=50+2*$cos-5*$sin; $source['y']=50+2*$sin+5*$cos;
    $db->put('token:scene-one:token-player', $source);
    $foe = $db->payload('token:scene-one:token-monster');
    $foe['x']=50+2*$cos+8*$sin; $foe['y']=50+2*$sin-8*$cos;
    $db->put('token:scene-one:token-monster', $foe);
    $peek = runCommand($db, 'door.interact', ['sceneId'=>'scene-one','layerId'=>'ground',
        'tokenId'=>$door['id'],'sourceTokenId'=>$source['id'],'action'=>'peek']);
    keyholeCheck($peek->status === 200, 'The permitted observation starts normally');
    if ($kind !== 'clear') {
        $mask = emptyApplicationWallState(1000,1000);
        $bytes = str_repeat("\0", (int)ceil($mask['width']*$mask['height']/8));
        // A one-cell barrier lies between the lock and the camera origin.
        $cell = in_array($rotation,[0,270],true) ? 254 : 257;
        for ($i=0; $i<$mask['width']; $i++) applicationSetMaskBit($bytes,
            $rotation%180===0 ? $cell*$mask['width']+$i : $i*$mask['width']+$cell,true);
        $mask['mask'] = rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');
        if ($kind === 'fog') $mask['enabled'] = true;
        $map[$kind === 'fog' ? 'fog' : 'walls'] = $mask;
        $db->put('map:scene-one', $map);
    }
    return $db;
}

foreach ([0,90,180,270] as $rotation) foreach (['clear','wall','fog'] as $kind) {
    $db = thinBarrierFixture($kind, $rotation);
    $view = publicPlayerState(domainsToApplicationState($db->domains,0),
        ['id'=>'account-player','effective_mode'=>'player'],[],false,true)['doorPeek'];
    if ($kind === 'clear') keyholeCheck(!empty($view['mask']) && count($view['occupants']) > 0,
        'A clear keyhole still reveals the scene and its occupant');
    else keyholeCheck(!empty($view['obscured']) && empty($view['mask']) && empty($view['occupants']),
        'A thin '.$kind.' immediately behind the keyhole at '.$rotation.' degrees blocks the scene and occupant');
}

echo json_encode(['ok'=>true,'checks'=>$keyholeChecks], JSON_THROW_ON_ERROR)."\n";
