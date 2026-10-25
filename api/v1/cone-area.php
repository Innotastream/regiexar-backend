<?php
declare(strict_types=1);
require_once __DIR__.'/complex-movement.php';
function applicationConeAreaGeometry(array $source,array $point,array $map,int $length): ?array {
    $source=applicationTokenSpatialFootprint($source,$map);$layer=$source['layerId']??'ground';$level=$map['layers'][$layer]??$map;
    $width=(float)($level['naturalWidth']??$map['naturalWidth']??0);$height=(float)($level['naturalHeight']??$map['naturalHeight']??0);$grid=(float)($map['gridSize']??0);
    if($length<1||$length>20||$width<=0||$height<=0||$grid<=0)return null;
    $sx=(float)$source['x']*$width/100;$sy=(float)$source['y']*$height/100;$dx=((float)$point['x']-(float)$source['x'])*$width/100;$dy=((float)$point['y']-(float)$source['y'])*$height/100;$distance=hypot($dx,$dy);
    if(!is_finite($distance)||$distance<1e-6)return null;$ux=$dx/$distance;$uy=$dy/$distance;$offset=max(0,(float)($source['size']??40))/2;
    return ['width'=>$width,'height'=>$height,'grid'=>$grid,'layerId'=>$layer,'x'=>$sx+$ux*$offset,'y'=>$sy+$uy*$offset,'ux'=>$ux,'uy'=>$uy,'length'=>$length];
}
function applicationConeAreaTargets(array $tokens,array $source,array $point,array $map,int $length): array {
    if(empty($map['tokens']))$map['tokens']=$tokens;
    $g=applicationConeAreaGeometry($source,$point,$map,$length);if($g===null)return [];
    return array_values(array_filter($tokens,static function(array $token)use($g,$map):bool{
        if(!empty($token['hidden']))return false;$target=applicationTokenSpatialFootprint($token,$map);if(($target['layerId']??'ground')!==$g['layerId'])return false;
        $dx=(float)$target['x']*$g['width']/100-$g['x'];$dy=(float)$target['y']*$g['height']/100-$g['y'];$forward=$dx*$g['ux']+$dy*$g['uy'];$lateral=-$dx*$g['uy']+$dy*$g['ux'];$radius=max(0,(float)($target['size']??40))/2;
        for($i=0;$i<$g['length'];$i++)if(hypot(max($i*$g['grid']-$forward,0,$forward-($i+1)*$g['grid']),max(0,abs($lateral)-($i+.5)*$g['grid']))<=$radius+1e-8)return true;return false;
    }));
}
