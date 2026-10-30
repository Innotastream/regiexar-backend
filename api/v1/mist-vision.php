<?php
declare(strict_types=1);
require_once __DIR__.'/vision-zones.php';
function applicationMistBytes(mixed $encoded,int $length):?string{
 if(!is_string($encoded))return null;$raw=base64_decode(strtr($encoded,'-_','+/').str_repeat('=',(4-strlen($encoded)%4)%4),true);return is_string($raw)&&strlen($raw)===$length?$raw:null;
}
function applicationMistEncode(string $bytes,bool $mask=false):string{return $mask&&trim($bytes,"\0")===''?'':rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');}
function applicationMistBit(?string $bytes,int $index):bool{return $bytes!==null&&isset($bytes[$index>>3])&&(ord($bytes[$index>>3])&(1<<($index&7)))!==0;}
function applicationMistMask(mixed $mask):?array{
 if(!is_array($mask)||empty($mask['enabled']))return null;$width=$mask['width']??0;$height=$mask['height']??0;if(!is_int($width)||!is_int($height)||$width<1||$height<1)return null;
 $count=(int)ceil($width*$height/8);$strict=($mask['mask']??'')===''?str_repeat("\0",$count):applicationMistBytes($mask['mask'],$count);if($strict===null)return null;
 $token=isset($mask['tokenMask'])?($mask['tokenMask']===''?str_repeat("\0",$count):applicationMistBytes($mask['tokenMask'],$count)):$strict;
 return ['width'=>$width,'height'=>$height,'strict'=>$strict,'token'=>$token??$strict];
}
function applicationMistCovered(?array $mask,float $x,float $y,bool $token=false):bool{
 if($mask===null)return false;$ix=(int)round(max(0,min(100,$x))/100*($mask['width']-1));$iy=(int)round(max(0,min(100,$y))/100*($mask['height']-1));return applicationMistBit($mask[$token?'token':'strict'],$iy*$mask['width']+$ix);
}
function applicationMistInside(array $point,array $zone):bool{return hypot($point['x']-$zone['x'],$point['y']-$zone['y'])<$zone['radius']-1e-7;}
function applicationMistCrosses(array $a,array $b,array $zone):bool{
 $dx=$b['x']-$a['x'];$dy=$b['y']-$a['y'];$t=max(0,min(1,(($zone['x']-$a['x'])*$dx+($zone['y']-$a['y'])*$dy)/($dx*$dx+$dy*$dy?:1)));
 return hypot($a['x']+$dx*$t-$zone['x'],$a['y']+$dy*$t-$zone['y'])<$zone['radius']-1e-7;
}
function applicationCreateMistVision(mixed $value,array $map=[],array $observers=[],array $options=[]):array{
 $sceneId=$options['sceneId']??$map['sceneId']??'';$layerId=$options['layerId']??$map['activeLayerId']??'ground';$level=[...$map,...($map['layers'][$layerId]??[])];
 $width=max(1,(float)($level['naturalWidth']??1600));$height=max(1,(float)($level['naturalHeight']??900));$grid=max(1,(float)($map['gridSize']??50));$zones=[];
 foreach(normalizeApplicationVisionZones($value??[])as$zone)if($zone['sceneId']===$sceneId&&$zone['layerId']===$layerId)$zones[]=['x'=>$zone['x']*$width/100,'y'=>$zone['y']*$height/100,'radius'=>$zone['radiusCells']*$grid];
 $origins=[];foreach($observers as$origin){if(!is_numeric($origin['x']??null)||!is_numeric($origin['y']??null))continue;$point=['x'=>$origin['x']*$width/100,'y'=>$origin['y']*$height/100];$inside=false;foreach($zones as$zone)if(applicationMistInside($point,$zone)){$inside=true;break;}
  $range=!empty($origin['environmentAdjusted'])?($origin['visionDistance']??8):applicationEffectiveVisionDistance($origin['visionDistance']??8,$level['lightingMode']??'normal',$origin['darkVision']??'none',$level['customLighting']??[]);
  $origins[]=[...$point,'size'=>max(0,(float)($origin['size']??0)),'range'=>$range*$grid,'mask'=>applicationMistMask($origin['mask']??null),'inside'=>$inside,'original'=>$origin];}
 return ['zones'=>$zones,'origins'=>$origins,'width'=>$width,'height'=>$height,'grid'=>$grid,'map'=>$map,'active'=>$zones!==[]&&$origins!==[]];
}
function applicationMistBlocked(array $context,array $origin,array $point,float $size=0):bool{
 if($origin['inside']){$dx=abs($point['x']-$origin['x']);$dy=abs($point['y']-$origin['y']);$padding=($origin['size']+$size)/2;$distance=($context['map']['grid']??false)===true?max(0,$dx-$padding,$dy-$padding):max(0,hypot($dx,$dy)-$padding);return $distance>$context['grid']+1e-7;}
 foreach($context['zones']as$zone)if(applicationMistCrosses($origin,$point,$zone))return true;return false;
}
function applicationMistTokenVisibility(array $context,array $token):string{
 if(!$context['active'])return 'clear';$physical=function_exists('applicationTokenSpatialFootprint')?applicationTokenSpatialFootprint($token,$context['map']):$token;$x=$physical['x'];$y=$physical['y'];$point=['x'=>$x*$context['width']/100,'y'=>$y*$context['height']/100];$size=(float)($physical['size']??0);$silhouette=false;
 foreach($context['origins']as$origin){$distance=hypot($point['x']-$origin['x'],$point['y']-$origin['y']);if($distance<1e-7)return 'clear';if(applicationMistCovered($origin['mask'],$x,$y,true))continue;if(!applicationMistBlocked($context,$origin,$point,$size))return 'clear';if($distance<=$origin['range']+1e-7)$silhouette=true;}
 return $silhouette?'silhouette':'hidden';
}
function applicationMistShadowAtPoint(array $context,float $x,float $y):bool{
 if(!$context['active'])return false;$point=['x'=>$x*$context['width']/100,'y'=>$y*$context['height']/100];$obscured=false;
 foreach($context['origins']as$origin){if(applicationMistCovered($origin['mask'],$x,$y,true))continue;if(!applicationMistBlocked($context,$origin,$point))return false;$obscured=true;}return $obscured;
}
function applicationApplyMistVisionMask(array $baseMask,array $context):array{
 if(!$context['active'])return $baseMask;
 $calculate=static function()use($baseMask,$context):array{
  $width=(int)($baseMask['width']??512);$height=(int)($baseMask['height']??max(32,(int)round($width*$context['height']/$context['width'])));$count=$width*$height;$length=(int)ceil($count/8);
  $hard=!empty($baseMask['mask'])?applicationMistBytes($baseMask['mask'],$length):str_repeat("\0",$length);$opacity=applicationMistBytes($baseMask['opacity']??null,$count);
  if($opacity===null){$opacity=str_repeat("\0",$count);for($i=0;$i<$count;$i++)if(applicationMistBit($hard,$i))$opacity[$i]="\xff";}
  $occluded=!empty($baseMask['occludedMask'])?(applicationMistBytes($baseMask['occludedMask'],$length)??str_repeat("\0",$length)):str_repeat("\0",$length);
  for($y=0;$y<$height;$y++)for($x=0;$x<$width;$x++){$index=$y*$width+$x;if(applicationMistBit($hard,$index)&&ord($opacity[$index])===255)continue;
   if(applicationMistShadowAtPoint($context,$x*100/max(1,$width-1),$y*100/max(1,$height-1))){$opacity[$index]="\xff";$byte=$index>>3;$occluded[$byte]=chr(ord($occluded[$byte])|(1<<($index&7)));}}
  return [...$baseMask,'version'=>1,'enabled'=>true,'width'=>$width,'height'=>$height,'mask'=>$baseMask['mask']??'','opacity'=>applicationMistEncode($opacity),'occludedMask'=>applicationMistEncode($occluded,true)];
 };
 $origins=array_map(static fn($origin)=>[$origin['x'],$origin['y'],$origin['size'],$origin['range'],$origin['original']['mask']??null],$context['origins']);
 return function_exists('applicationMemoizedVisionMask')?applicationMemoizedVisionMask('render',['mist-version'=>1,$baseMask,$context['zones'],$origins,$context['width'],$context['height'],$context['grid'],$context['map']['grid']??false],$calculate):$calculate();
}
