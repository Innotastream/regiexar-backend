<?php
declare(strict_types=1);

function applicationLightGlow(array $occlusion, array $viewerMask, mixed $rawGridSize = 50): ?array {
    static $cache=[];
    $lights=array_values(array_filter(normalizeApplicationMapLights($occlusion['lights']??[]),static fn($l)=>$l['enabled']));
    if($lights===[])return null;
    $gridSize=max(12,min(240,is_numeric($rawGridSize)?(float)$rawGridSize:50));
    $key=hash('sha256',json_encode([$occlusion['walls'],$occlusion['fog']??null,$lights,$viewerMask,$occlusion['naturalWidth']??1600,$occlusion['naturalHeight']??900,$gridSize]));
    if(array_key_exists($key,$cache))return $cache[$key];
    $walls=$occlusion['walls'];$width=$walls['width'];$height=$walls['height'];
    $naturalWidth=max(1,(float)($occlusion['naturalWidth']??1600));$naturalHeight=max(1,(float)($occlusion['naturalHeight']??900));
    $fog=$occlusion['fog']??null;$allowed=str_repeat("\0",$width*$height);$pixels=str_repeat("\0",$width*$height*3);
    $opacity=isset($viewerMask['opacity'])?base64_decode(strtr($viewerMask['opacity'],'-_','+/')):false;
    $shadows=isset($viewerMask['occludedMask'])?base64_decode(strtr($viewerMask['occludedMask'],'-_','+/')):false;
    $solidBytes=applicationWallMaskBytes($walls);
    $viewerBytes=($viewerMask['enabled']??false)?applicationWallMaskBytes($viewerMask):null;
    $fogBytes=is_array($fog)&&($fog['enabled']??false)?applicationFogMaskBytes($fog):null;
    for($y=0;$y<$height;$y++)for($x=0;$x<$width;$x++){
        $px=$x/($width-1)*100;$py=$y/($height-1)*100;$i=$y*$width+$x;
        $vi=(int)round($y/($height-1)*(($viewerMask['height']??$height)-1))*($viewerMask['width']??$width)+(int)round($x/($width-1)*(($viewerMask['width']??$width)-1));
        $fi=$fogBytes!==null?(int)round($y/($height-1)*($fog['height']-1))*$fog['width']+(int)round($x/($width-1)*($fog['width']-1)):0;
        if(!($solidBytes!==null&&applicationMaskBit($solidBytes,$i))&&!($fogBytes!==null&&applicationMaskBit($fogBytes,$fi))&&!($shadows!==false&&applicationMaskBit($shadows,$vi))
          &&(!($viewerBytes!==null&&applicationMaskBit($viewerBytes,$vi))||($opacity!==false&&isset($opacity[$vi])&&ord($opacity[$vi])<255)))$allowed[$i]=chr($opacity!==false&&isset($opacity[$vi])?255-ord($opacity[$vi]):255);
    }
    $present=false;$sightWalls=applicationVisionWallsWithFog($occlusion);
    foreach($lights as $light){
        if(applicationLightCenterBlocked($occlusion,$light))continue;
        $mask=applicationComputeBaseVisionMask([...$occlusion,'walls'=>$sightWalls,'vision'=>['enabled'=>true,'distance'=>$light['visionDistance']]],
            [[...$light,'source'=>'light','environmentAdjusted'=>true]],$gridSize);
        $hidden=applicationWallMaskBytes($mask);if($hidden===null)continue;
        $radius=$light['visionDistance']*$gridSize;$cx=$light['x']/100*$naturalWidth;$cy=$light['y']/100*$naturalHeight;
        $left=max(0,(int)floor(($cx-$radius)/$naturalWidth*($width-1)));$right=min($width-1,(int)ceil(($cx+$radius)/$naturalWidth*($width-1)));
        $top=max(0,(int)floor(($cy-$radius)/$naturalHeight*($height-1)));$bottom=min($height-1,(int)ceil(($cy+$radius)/$naturalHeight*($height-1)));
        $rgb=array_map(static fn($start)=>hexdec(substr($light['color'],$start,2)),[1,3,5]);
        for($y=$top;$y<=$bottom;$y++)for($x=$left;$x<=$right;$x++){
            $i=$y*$width+$x;if($allowed[$i]==="\0"||applicationMaskBit($hidden,$i))continue;
            $distance=hypot($x/($width-1)*$naturalWidth-$cx,$y/($height-1)*$naturalHeight-$cy)/$radius;if($distance>=1)continue;
            if($distance<=.38)$strength=.38+(.25-.38)*$distance/.38;
            elseif($distance<=.72)$strength=.25+(.10-.25)*($distance-.38)/(.72-.38);
            else $strength=.10-.10*($distance-.72)/.28;
            $strength*=ord($allowed[$i])/255;
            for($c=0;$c<3;$c++){$value=(int)round($rgb[$c]*$strength);$p=$i*3+$c;if($value>ord($pixels[$p])){$pixels[$p]=chr($value);$present=true;}}
        }
    }
    $result=null;
    if($present){
        $rows='';for($y=0;$y<$height;$y++)$rows.="\0".substr($pixels,$y*$width*3,$width*3);
        $chunk=static fn(string $type,string $body):string=>pack('N',strlen($body)).$type.$body.hash('crc32b',$type.$body,true);
        $png="\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',$width,$height,8,2,0,0,0)).$chunk('IDAT',gzcompress($rows,3)).$chunk('IEND','');
        $result=['version'=>1,'width'=>$width,'height'=>$height,'png'=>base64_encode($png)];
    }
    $cache[$key]=$result;if(count($cache)>8)array_shift($cache);return $result;
}
