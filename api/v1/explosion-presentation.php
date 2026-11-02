<?php
declare(strict_types=1);
require_once __DIR__.'/terrain-effects.php';
require_once __DIR__.'/spell-visuals.php';

function applicationExplosionFireSegments(string $key):array {
    $seed=2166136261;foreach(str_split($key) as $char)$seed=(($seed^ord($char))*16777619)&0xffffffff;
    $random=static function()use(&$seed):float{$seed=($seed*1664525+1013904223)&0xffffffff;return $seed/4294967296;};
    $result=[];
    for($i=0;$i<13;$i++){
        $angle=$random()*M_PI*2;$start=.08+$random()*.48;$length=.14+$random()*.34;$bend=($random()-.5)*.65;$thickness=.035+$random()*.045;
        $result[]=['ax'=>cos($angle)*$start,'ay'=>sin($angle)*$start,'bx'=>cos($angle+$bend)*($start+$length),'by'=>sin($angle+$bend)*($start+$length),'radius'=>$thickness];
    }
    return $result;
}
function applicationExplosionFireStroke(array $map,array $device,string $id,string $key):?array {
    $dimensions=applicationTerrainRasterDimensions($map);$radius=$device['definition']['radiusCells']*$map['gridSize'];$w=$map['naturalWidth'];$h=$map['naturalHeight'];if(!($radius>0&&$w>0&&$h>0))return null;
    $bytes=str_repeat("\0",(int)ceil($dimensions['width']*$dimensions['height']/8));$segments=applicationExplosionFireSegments($key);$cx=$device['x']*$w/100;$cy=$device['y']*$h/100;
    for($y=max(0,(int)floor(($cy-$radius)*$dimensions['height']/$h));$y<min($dimensions['height'],ceil(($cy+$radius)*$dimensions['height']/$h));$y++)for($x=max(0,(int)floor(($cx-$radius)*$dimensions['width']/$w));$x<min($dimensions['width'],ceil(($cx+$radius)*$dimensions['width']/$w));$x++){
        $px=(($x+.5)*$w/$dimensions['width']-$cx)/$radius;$py=(($y+.5)*$h/$dimensions['height']-$cy)/$radius;if($px*$px+$py*$py>1)continue;
        foreach($segments as $s){$dx=$s['bx']-$s['ax'];$dy=$s['by']-$s['ay'];$t=max(0,min(1,(($px-$s['ax'])*$dx+($py-$s['ay'])*$dy)/($dx*$dx+$dy*$dy)));
            if(($px-$s['ax']-$t*$dx)**2+($py-$s['ay']-$t*$dy)**2<=$s['radius']*$s['radius']){$index=$y*$dimensions['width']+$x;$byte=intdiv($index,8);$bytes[$byte]=chr(ord($bytes[$byte])|(1<<($index&7)));break;}
        }
    }
    $mask=applicationEncodeTerrainMask($bytes,$dimensions['width'],$dimensions['height']);return $mask!==''?['id'=>$id,'kind'=>'fire','layerId'=>$map['activeLayerId']??'ground',...$dimensions,'mask'=>$mask]:null;
}
function applicationApplyExplosionPresentation(array &$state,array $device,string $requestId,?int $now=null):?array {
    if(($device['definition']['kind']??'')!=='explosive')return null;
    $now??=(int)floor(microtime(true)*1000);$key='flux-blast-'.substr(hash('sha256',json_encode([$device['id'],$requestId],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),0,48);
    foreach($state['devices']['receipts']??[] as $receipt)if(($receipt['requestId']??'')===$key&&($receipt['signature']??'')==='flux-presentation-v1')return [...$receipt['result'],'deduplicated'=>true];
    $sceneId=$device['sceneId'];$layerId=$device['layerId']??'ground';
    if(($state['activeSceneId']??'')===$sceneId)$map=&$state['map'];else{$index=array_search($sceneId,array_column($state['scenes']??[],'id'),true);if($index===false)throw new RuntimeException('La scène de cet explosif n’existe plus.',409);$map=&$state['scenes'][$index]['combat']['map'];}
    $layer=$map['layers'][$layerId]??[];$working=[...$map,...$layer,'activeLayerId'=>$layerId,'gridSize'=>$layer['gridSize']??$map['gridSize']];$effects=$working['terrainEffects']??[];
    if(count($effects)>=64)throw new RuntimeException('Effacez une zone peinte avant cette explosion : la carte en contient déjà 64.',409);
    $stroke=applicationExplosionFireStroke($working,$device,'flux-fire-'.substr($key,11),$key);
    if($stroke!==null){$next=[...$effects,$stroke];if(isset($map['layers'][$layerId]))$map['layers'][$layerId]['terrainEffects']=$next;if(($map['activeLayerId']??'ground')===$layerId)$map['terrainEffects']=$next;}
    $spell=applicationSpellVisual(['version'=>1,'presetId'=>'timed-flux-explosion','placement'=>'area','sceneId'=>$sceneId,'layerId'=>$layerId,'source'=>['x'=>$device['x'],'y'=>$device['y'],'size'=>$device['size']??20],'targetPoint'=>['x'=>$device['x'],'y'=>$device['y']],'radiusCells'=>$device['definition']['radiusCells']]);
    $eventId='cue-'.$key;$cue=function_exists('normalizeApplicationAbilityCompletionCue')?normalizeApplicationAbilityCompletionCue(['version'=>1,'spell'=>$spell]):['version'=>1,'trigger'=>'completed','sound'=>null,'visual'=>['version'=>1,'kind'=>'none'],'spell'=>$spell];
    $events=array_values(array_filter($state['abilityCueEvents']??[],static fn($e)=>$e['expiresAt']>$now));if(!in_array($eventId,array_column($events,'id'),true))$events[]=['id'=>$eventId,'sceneId'=>$sceneId,'layerId'=>$layerId,'sourceTokenId'=>$device['id'],'visibility'=>'public','createdAt'=>$now,'expiresAt'=>$now+applicationSpellVisualDuration($spell),'completionCue'=>$cue];$state['abilityCueEvents']=array_slice($events,-40);
    $result=['completionCueEventId'=>$eventId,'terrainEffectIds'=>$stroke!==null?[$stroke['id']]:[],'deduplicated'=>false];$state['devices']['receipts'][]=['requestId'=>$key,'actorId'=>'mechanics-authority','role'=>'gm','signature'=>'flux-presentation-v1','result'=>$result,'at'=>gmdate('c',intdiv($now,1000))];$state['devices']['receipts']=array_slice($state['devices']['receipts'],-2048);return $result;
}
