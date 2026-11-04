<?php
declare(strict_types=1);

const XAR_KEYHOLE_RANGE = 5;
const XAR_KEYHOLE_REACH = 2;

function applicationKeyholeMovementCheckpoint(array $source,array $map): array {
    $body=$source;
    $mountId=$source['mountedOnTokenId']??null;
    if($mountId!==null)foreach($map['tokens']??[] as $token)if($token['id']===$mountId&&empty($token['mountedOnTokenId'])){$body=$token;break;}
    return [$body['id'],(float)$body['x'],(float)$body['y'],$body['layerId']??'ground',$body['_movedAt']??0,$source['_movedAt']??0];
}

function applicationKeyholeDoorCheckpoint(array $door): array {
    $barrier=$door['terrainBarrier'];
    return [(float)$door['x'],(float)$door['y'],$door['layerId']??'ground',$door['_movedAt']??0,$barrier['swungAt']??0,
        $barrier['peekPermissionAt']??0,$barrier['rotation'],applicationObstacleDoorWidth($door)];
}

function applicationKeyholeDoorAvailable(array $door): bool {
    return applicationIsObstacleDoor($door)&&($door['terrainBarrier']['allowPeek']??false)===true
        &&empty($door['terrainBarrier']['open'])&&empty($door['hidden'])&&applicationTerrainBarrierActive($door);
}

function applicationKeyholeGeometry(array $source,array $door,array $map,array $occlusion): ?array {
    $width=(float)$occlusion['naturalWidth'];$height=(float)$occlusion['naturalHeight'];$grid=(float)($map['gridSize']??0);
    if(!($width>0&&$height>0&&$grid>0)||applicationMapDistanceInCells($source,$door,$map,true,true)>XAR_KEYHOLE_REACH+1e-9)return null;
    $pawn=applicationTokenSpatialFootprint($source,$map);$angle=deg2rad($door['terrainBarrier']['rotation']);
    $tx=cos($angle);$ty=sin($angle);
    $side=($pawn['x']-$door['x'])*$width/100*(-$ty)+($pawn['y']-$door['y'])*$height/100*$tx;
    if(abs($side)<1e-6)return null;
    $sign=$side>0?1:-1;$ux=$ty*$sign;$uy=-$tx*$sign;
    $hole=applicationObstacleDoorWidth($door)*$grid*.37;
    $hx=$door['x']*$width/100+$tx*$hole;$hy=$door['y']*$height/100+$ty*$hole;
    $walls=$occlusion['walls'];
    $offset=max($grid*.16,2*max($width/max(1,$walls['width']-1),$height/max(1,$walls['height']-1)));
    $x=$hx+$ux*$offset;$y=$hy+$uy*$offset;$range=XAR_KEYHOLE_RANGE*$grid;
    if($x<0||$x>$width||$y<0||$y>$height)return null;
    $slope=tan(deg2rad(35));$half=$range*$slope;$padding=$grid*.12;
    $xs=[$x,$x+$ux*$range-$uy*$half,$x+$ux*$range+$uy*$half];
    $ys=[$y,$y+$uy*$range+$ux*$half,$y+$uy*$range-$ux*$half];
    $left=max(0,min($xs)-$padding);$right=min($width,max($xs)+$padding);
    $top=max(0,min($ys)-$padding);$bottom=min($height,max($ys)+$padding);
    if(!($right>$left&&$bottom>$top))return null;
    return compact('x','y','hx','hy','ux','uy','width','height','range','slope','left','top','right','bottom');
}

function applicationKeyholeWallPoint(array $walls,?string $bytes,float $x,float $y):bool {
    if($bytes===null)return false;
    $col=(int)round($x/100*($walls['width']-1));$row=(int)round($y/100*($walls['height']-1));
    return applicationMaskBit($bytes,$row*$walls['width']+$col);
}

function applicationProjectDoorKeyhole(array $map,string $accountId,array $characterOwners,array $characters,array $occlusion,mixed $fog,array $mistVision): ?array {
    $tokens=$map['tokens']??[];$source=null;
    foreach($tokens as $token)if(($token['doorPeek']['accountId']??null)===$accountId&&onlineEffectiveTokenControllerId($token,$characterOwners)===$accountId){$source=$token;break;}
    if(!$source||!empty($source['hidden'])||($source['hp']??0)<=0||($source['healthOverride']??'')==='dead')return null;
    $peek=$source['doorPeek'];$door=array_column($tokens,null,'id')[$peek['doorId']??'']??[];
    if(!applicationKeyholeDoorAvailable($door)||!onlineTokenOnActiveLayer($door,$map)
        ||($peek['movement']??null)!=applicationKeyholeMovementCheckpoint($source,$map)
        ||($peek['door']??null)!=applicationKeyholeDoorCheckpoint($door))return null;
    $geometry=applicationKeyholeGeometry($source,$door,$map,$occlusion);
    if($geometry===null)return null;
    ['x'=>$x,'y'=>$y,'hx'=>$hx,'hy'=>$hy,'ux'=>$ux,'uy'=>$uy,'width'=>$width,'height'=>$height,'slope'=>$slope,
        'range'=>$range,'left'=>$left,'right'=>$right,'top'=>$top,'bottom'=>$bottom]=$geometry;
    $character=$characters[$source['characterId']??'']??null;
    $origin=['x'=>$x/$width*100,'y'=>$y/$height*100,'source'=>'token',
        'visionDistance'=>min(XAR_KEYHOLE_RANGE,onlineTokenVisionDistance($source,$character)),
        'darkVision'=>onlineTokenDarkVision($source,$character)];
    $otherWalls=applicationActiveMapOcclusionState($map,array_values(array_filter($tokens,static fn($token)=>$token['id']!==$door['id'])))['walls'];
    $otherBytes=applicationWallMaskBytes($otherWalls);
    $view=['doorId'=>$door['id'],'doorName'=>$door['name']??'Porte','sourceTokenId'=>$source['id']];
    foreach([0,.5,1] as $factor){
        $px=($hx+($x-$hx)*$factor)/$width*100;$py=($hy+($y-$hy)*$factor)/$height*100;
        if(applicationKeyholeWallPoint($otherWalls,$otherBytes,$px,$py)||applicationFogCoversPoint($fog,$px,$py))return [...$view,'obscured'=>true];
    }
    $mask=applicationComputeVisionMask([...$occlusion,'vision'=>[...$occlusion['vision'],'enabled'=>true]],[$origin],$map['gridSize']);
    $visionBytes=applicationFogMaskBytes([...$mask,'version'=>XAR_FOG_MASK_VERSION]);
    if(empty($mask['enabled'])||$visionBytes===null)return [...$view,'obscured'=>true];
    $fogBytes=applicationFogMaskBytes($fog);
    $visible=static function(float $px,float $py) use($x,$y,$ux,$uy,$width,$height,$slope,$range,$mask,$visionBytes,$fog,$fogBytes,$mistVision):bool{
        $dx=$px-$x;$dy=$py-$y;$forward=$dx*$ux+$dy*$uy;$lateral=-$dx*$uy+$dy*$ux;
        if($forward<0||$dx*$dx+$dy*$dy>$range*$range||abs($lateral)>$slope*$forward)return false;
        $mx=$px/$width*100;$my=$py/$height*100;
        return !applicationKeyholeWallPoint($mask,$visionBytes,$mx,$my)
            &&(empty($fog['enabled'])||!applicationKeyholeWallPoint($fog,$fogBytes,$mx,$my))
            &&!applicationMistShadowAtPoint($mistVision,$mx,$my);
    };
    $ratio=($right-$left)/($bottom-$top);
    $columns=max(1,(int)round(116*min(1,$ratio)));$rows=max(1,(int)round(116/max(1,$ratio)));
    $sampleColumns=max($mask['width'],$fog['width']??0)-1;
    $sampleRows=max($mask['height'],$fog['height']??0)-1;
    $cellVisible=static function(float $x0,float $y0,float $x1,float $y1)use($width,$height,$sampleColumns,$sampleRows,$visible):bool{
        for($row=(int)floor($y0/$height*$sampleRows);$row<=(int)ceil($y1/$height*$sampleRows);$row++)
            for($col=(int)floor($x0/$width*$sampleColumns);$col<=(int)ceil($x1/$width*$sampleColumns);$col++)
                if(!$visible($col/$sampleColumns*$width,$row/$sampleRows*$height))return false;
        return true;
    };
    $bytes=str_repeat("\0",(int)ceil($columns*$rows/8));$revealed=0;
    for($row=0;$row<$rows;$row++)for($col=0;$col<$columns;$col++){
        if(!$visible($left+($col+.5)/$columns*($right-$left),$top+($row+.5)/$rows*($bottom-$top)))continue;
        if(!$cellVisible($left+$col/$columns*($right-$left),$top+$row/$rows*($bottom-$top),
            $left+($col+1)/$columns*($right-$left),$top+($row+1)/$rows*($bottom-$top)))continue;
        applicationSetMaskBit($bytes,$row*$columns+$col,true);$revealed++;
    }
    if(!$revealed)return [...$view,'obscured'=>true];
    $occupants=[];
    foreach($tokens as $token){
        if($token['id']===$door['id']||!empty($token['hidden'])||!onlineTokenOnActiveLayer($token,$map)
            ||!$visible($token['x']*$width/100,$token['y']*$height/100))continue;
        $occupants[]=['x'=>$token['x'],'y'=>$token['y'],'size'=>$token['size']??40,
            'image'=>applicationTacticalImage($token,$token['image']??null),'name'=>itemText($token['name']??'',120)];
        if(count($occupants)===40)break;
    }
    return [...$view,'columns'=>$columns,'rows'=>$rows,'bounds'=>[$left/$width,$top/$height,($right-$left)/$width,($bottom-$top)/$height],
        'mask'=>base64_encode($bytes),'occupants'=>$occupants];
}
