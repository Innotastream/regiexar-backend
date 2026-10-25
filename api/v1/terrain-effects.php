<?php
declare(strict_types=1);

const APPLICATION_TERRAIN_KINDS=['fire','poison','ice','acid','lightning','darkness','roots'];
function normalizeApplicationTerrainHue(mixed $value): int
{
    if(!is_int($value)||$value<0||$value>360)throw new RuntimeException('Choisissez une teinte de 0 à 360 degrés.');
    return $value%360;
}
function applicationTerrainMaskBytes(array $value): ?string
{
    foreach(['width','height'] as $key)if(!is_int($value[$key]??null)||$value[$key]<32||$value[$key]>512)return null;
    if(!is_string($value['mask']??null)||strlen($value['mask'])>44000||preg_match('/\A[A-Za-z0-9_-]*\z/D',$value['mask'])!==1)return null;
    $length=(int)ceil($value['width']*$value['height']/8);if($value['mask']==='')return str_repeat("\0",$length);
    $base=strtr($value['mask'],'-_','+/');$decoded=base64_decode($base.str_repeat('=',(4-strlen($base)%4)%4),true);
    return is_string($decoded)&&strlen($decoded)===$length?$decoded:null;
}
function applicationEncodeTerrainMask(string $bytes,int $width,int $height): string
{
    $bits=$width*$height;if($bits%8)$bytes[strlen($bytes)-1]=chr(ord($bytes[strlen($bytes)-1])&((1<<($bits%8))-1));
    return trim($bytes,"\0")===''?'':rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');
}
function normalizeApplicationTerrainEffects(mixed $value): array
{
    if(!is_array($value)||!array_is_list($value)||count($value)>64)throw new RuntimeException('Limitez les zones de terrain à 64 par scène.');
    $ids=[];$result=[];
    foreach($value as $effect){
        if(!is_array($effect)||!is_string($effect['id']??null)||preg_match('/\A[A-Za-z0-9_-]{1,120}\z/D',$effect['id'])!==1||isset($ids[$effect['id']])||!in_array($effect['kind']??null,APPLICATION_TERRAIN_KINDS,true)||!in_array($effect['layerId']??null,['ground','upper','basement'],true))throw new RuntimeException('Cette zone de terrain est invalide.');
        $bytes=applicationTerrainMaskBytes($effect);if($bytes===null||$effect['mask']==='')throw new RuntimeException('Le dessin de cette zone de terrain est invalide.');
        $mask=applicationEncodeTerrainMask($bytes,$effect['width'],$effect['height']);if($mask==='')throw new RuntimeException('Une zone de terrain doit comporter un dessin.');
        $hue=array_key_exists('hue',$effect)?normalizeApplicationTerrainHue($effect['hue']):0;
        $ids[$effect['id']]=true;$result[]=['id'=>$effect['id'],'kind'=>$effect['kind'],'layerId'=>$effect['layerId'],'width'=>$effect['width'],'height'=>$effect['height'],'mask'=>$mask,...($hue?['hue'=>$hue]:[])];
    }
    return $result;
}
function applicationTerrainRasterDimensions(array $map): array
{
    $w=is_numeric($map['naturalWidth']??null)&&is_finite((float)$map['naturalWidth'])&&(float)$map['naturalWidth']>0?(float)$map['naturalWidth']:1600;
    $h=is_numeric($map['naturalHeight']??null)&&is_finite((float)$map['naturalHeight'])&&(float)$map['naturalHeight']>0?(float)$map['naturalHeight']:900;
    return $w>=$h?['width'=>512,'height'=>max(32,min(512,(int)round(512*$h/$w)))]:['width'=>max(32,min(512,(int)round(512*$w/$h))),'height'=>512];
}
function applicationPublicTerrainEffects(array $map,string $layerId,callable $visible): array
{
    try{$effects=array_values(array_filter(normalizeApplicationTerrainEffects($map['terrainEffects']??[]),static fn($effect)=>$effect['layerId']===$layerId));}catch(RuntimeException){return [];}
    if($effects===[])return [];
    static $cache=[];$dimensions=applicationTerrainRasterDimensions($map);$width=$dimensions['width'];$height=$dimensions['height'];$key=hash('sha256',json_encode([$dimensions,$effects],JSON_THROW_ON_ERROR));
    if(!isset($cache[$key])){
        $composites=[];
        foreach(APPLICATION_TERRAIN_KINDS as $kind){
            $kindRows=array_values(array_filter($effects,static fn($effect)=>$effect['kind']===$kind));
            $hues=array_values(array_unique(array_map(static fn($effect)=>$effect['hue']??0,$kindRows)));sort($hues,SORT_NUMERIC);
            foreach($hues as $hue){
            $rows=array_values(array_filter($kindRows,static fn($effect)=>($effect['hue']??0)===$hue));
            $bytes=str_repeat("\0",(int)ceil($width*$height/8));
            foreach($rows as $row){$input=applicationTerrainMaskBytes($row);
                if($row['width']===$width&&$row['height']===$height){$bytes=$bytes|$input;continue;}
                for($y=0;$y<$height;$y++)for($x=0;$x<$width;$x++){
                    $index=min($row['height']-1,(int)floor(($y+.5)*$row['height']/$height))*$row['width']+min($row['width']-1,(int)floor(($x+.5)*$row['width']/$width));
                    if(ord($input[$index>>3])&(1<<($index&7))){$i=$y*$width+$x;$bytes[$i>>3]=chr(ord($bytes[$i>>3])|(1<<($i&7)));}
                }
            }
            $composites[]=['kind'=>$kind,'hue'=>$hue,'bytes'=>$bytes];
            }
        }
        $cache[$key]=$composites;if(count($cache)>8)array_shift($cache);
    }
    $result=[];$visibility=str_repeat("\0",(int)ceil($width*$height/8));foreach($cache[$key] as $row)$visibility=$visibility|$row['bytes'];
    for($y=0;$y<$height;$y++)for($x=0;$x<$width;$x++){
        $i=$y*$width+$x;if((ord($visibility[$i>>3])&(1<<($i&7)))&&!$visible(($x+.5)*100/$width,($y+.5)*100/$height))$visibility[$i>>3]=chr(ord($visibility[$i>>3])&~(1<<($i&7)));
    }
    foreach($cache[$key] as $row){$kind=$row['kind'];$hue=$row['hue'];$bytes=$row['bytes']&$visibility;
        $mask=applicationEncodeTerrainMask($bytes,$width,$height);if($mask!=='')$result[]=['id'=>'terrain-'.$kind.($hue?'-hue-'.$hue:'').'-'.$layerId,'kind'=>$kind,'layerId'=>$layerId,...$dimensions,'mask'=>$mask,...($hue?['hue'=>$hue]:[])];
    }
    return $result;
}
