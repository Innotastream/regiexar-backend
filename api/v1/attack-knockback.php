<?php
declare(strict_types=1);
require_once __DIR__.'/displacement-schema.php';
require_once __DIR__.'/charge-travel.php';

function applicationPlanAttackKnockback(array $attack,array $map,array $executions=[]): ?array {
    $config=applicationKnockback($attack['knockback']??null);$roll=$attack['damage']['knockbackRoll']??null;
    if($config===null||$roll===null||isset($attack['knockbackApplied'])||!empty($attack['areaAnchorOnly'])||!in_array($attack['status']??'',['applied','blocked'],true))return null;
    $raw=$roll['raw']??null;if(!is_int($raw)||$raw<1||$raw>$config['dieSides']||($roll['dieSides']??null)!==$config['dieSides'])return null;
    $requested=min($config['maximumCells'],(int)ceil($raw/$config['bandSize'])*$config['cellsPerBand']);
    $tokens=$map['tokens']??[];$byId=array_column($tokens,null,'id');$source=isset($byId[$attack['sourceTokenId']])?applicationTokenSpatialFootprint($byId[$attack['sourceTokenId']],$map):null;$recipient=$byId[$attack['targetTokenId']]??null;
    $body=!empty($recipient['mountedOnTokenId'])?($byId[$recipient['mountedOnTokenId']]??null):$recipient;
    $group=$body===null?[]:array_values(array_filter($tokens,static fn($t)=>$t['id']===$body['id']||($t['mountedOnTokenId']??'')===$body['id']));
    $blocked=static fn()=>['raw'=>$raw,'requestedCells'=>$requested,'movedCells'=>0,'blocked'=>true,'moves'=>[]];
    if($source===null||$body===null||$source['id']===$recipient['id']||$source['id']===$body['id']||$group===[]||($source['layerId']??'ground')!==($body['layerId']??'ground'))return $blocked();
    foreach($group as$t)if(!empty($t['immovable'])||!empty($t['terrainBarrier'])||!empty($t['fixedStructure'])||applicationAbilityMovementLock($executions,$attack['sceneId'],$t['id'],$tokens)!==null)return $blocked();
    $layer=$body['layerId']??'ground';$level=[...$map,...($map['layers'][$layer]??[]),'tokens'=>$tokens,'activeLayerId'=>$layer,'fog'=>null,'visionMask'=>null,'sceneId'=>$attack['sceneId'],'_abilityExecutions'=>$executions];
    $width=(float)($level['naturalWidth']??0);$height=(float)($level['naturalHeight']??0);$grid=(float)($level['gridSize']??0);
    if(!($width>0&&$height>0&&$grid>0))return $blocked();
    $dx=($body['x']-$source['x'])*$width/100;$dy=($body['y']-$source['y'])*$height/100;$length=hypot($dx,$dy);if(!($length>0))return $blocked();$ux=$dx/$length;$uy=$dy/$length;
    $from=['x'=>$body['x'],'y'=>$body['y']];$to=$from;$travelled=0;$impact=null;$ids=array_column($group,'id');$displaced=[];$positions=$byId;
    $others=array_values(array_filter($tokens,static fn($t)=>!in_array($t['id'],$ids,true)&&empty($t['mountedOnTokenId'])&&($t['layerId']??'ground')===$layer));
    $allowed=static function($a,$b)use($body,$level,$others,$width,$height,$from):bool {
        if(!applicationChargeTravelAllowed($body,$a,$b,$level))return false;
        foreach($others as$other){
            if(!is_numeric($other['x']??null)||!is_numeric($other['y']??null))continue;
            $vx=($b['x']-$a['x'])*$width/100;$vy=($b['y']-$a['y'])*$height/100;$ox=($other['x']-$a['x'])*$width/100;$oy=($other['y']-$a['y'])*$height/100;
            $t=max(0,min(1,($ox*$vx+$oy*$vy)/($vx*$vx+$vy*$vy?:1)));$distance=hypot($ox-$vx*$t,$oy-$vy*$t);$start=hypot(($other['x']-$from['x'])*$width/100,($other['y']-$from['y'])*$height/100);
            if($distance<min((($body['size']??40)+($other['size']??40))/2+1,$start)-1e-7)return false;
        }return true;
    };
    $steps=$requested*20;
    for($i=1;$i<=$steps;$i++){$distance=$requested*$grid*$i/$steps;$next=['x'=>$from['x']+$ux*$distance*100/$width,'y'=>$from['y']+$uy*$distance*100/$height];if(!(empty($config['pushAside'])?$allowed($to,$next):applicationChargeTravelAllowed($body,$to,$next,$level))){
        $rawWalls=$level['walls']??null;$walls=applicationTerrainBarrierWalls(validApplicationWallState($rawWalls)?$rawWalls:emptyApplicationWallState($width,$height),$level);$collision=applicationResolveWallCollision($walls,$to,$next,$body['size']??40,$width,$height,false);
        if(!empty($collision['blocked'])){$r=max(0,($body['size']??40)/2-$grid*.08);$impact=['x'=>max(0,min(100,$to['x']+$ux*$r*100/$width)),'y'=>max(0,min(100,$to['y']+$uy*$r*100/$height))];}break;
    }
    if(!empty($config['pushAside'])){try{$sideMoves=applicationChargePushAside([...$body,...$to],$to,$next,array_values($positions),$level,['enabled'=>true,'allowHidden'=>true,'minimumSideCells'=>$config['sideStepCells'],'fromCurrentPosition'=>true]);}catch(RuntimeException){break;}
        foreach($sideMoves as$move){$previous=$displaced[$move['tokenId']]??null;$displaced[$move['tokenId']]=[...$move,'from'=>$previous['from']??$move['from']];$positions[$move['tokenId']]=[...$positions[$move['tokenId']],...$move['to']];}}
    $to=$next;$travelled=$distance;foreach($group as$t)$positions[$t['id']]=[...$t,...$to];}
    return ['raw'=>$raw,'requestedCells'=>$requested,'movedCells'=>round($travelled/$grid*100)/100,'blocked'=>$travelled<$requested*$grid-1e-6,
        ...($impact!==null?['impact'=>$impact]:[]),'moves'=>$travelled>0?[...array_values($displaced),...array_map(static fn($t)=>['tokenId'=>$t['id'],'from'=>['x'=>$t['x'],'y'=>$t['y']],'to'=>$to],$group)]:[]];
}

function onlineApplyAttackKnockback(PDO $db,array &$records,array &$pending,array $attack):array {
    if(empty($attack['knockback'])||isset($attack['knockbackApplied'])||!isset($attack['damage']['knockbackRoll'])||!in_array($attack['status']??'',['applied','blocked'],true))return $attack;
    $state=onlineDeviceSelectScene(onlineDeviceState($db,$records,$pending),(string)$attack['sceneId']);
    $plan=applicationPlanAttackKnockback($attack,$state['map'],$state['abilityExecutions']??[]);if($plan===null)return $attack;
    $attack['knockbackApplied']=$plan;$now=(int)floor(microtime(true)*1000);$trap=false;
    foreach($plan['moves'] as $move){
        $key=onlineTokenDomainKey($attack['sceneId'],$move['tokenId']);$token=$pending[$key]['payload']??applicationDomainPayload($records,$key);
        if($token===[])continue;
        $trap=queueItemTrapPassages($state,$token,$move['from'],$move['to'],[$move['from'],$move['to']],static fn($p)=>$p.'-'.randomToken(16))||$trap;
        queueOnlineDomainUpsert($pending,$records,$key,[...$token,...$move['to'],'_movedAt'=>$now,'_updatedAt'=>$now]);
    }
    if($trap)queueOnlineDomainUpsert($pending,$records,'items',$state['items']);
    if(isset($plan['impact'])&&empty(applicationSpellVisualBindings()[$attack['attackId']??'']['handlesKnockbackImpact'])){
        $key=onlineTokenDomainKey($attack['sceneId'],$attack['targetTokenId']);$target=$pending[$key]['payload']??applicationDomainPayload($records,$key);
        if($target!==[]){$point=applicationTokenSpatialFootprint($target,['tokens'=>onlineSceneTokensForLights($db,$records,$pending,$attack['sceneId'])]);$activity=$pending['activity']['payload']??applicationDomainPayload($records,'activity');
            appendApplicationAbilityCueEvent($activity,['version'=>1,'trigger'=>'completed','sound'=>null,'visual'=>['version'=>1,'kind'=>'none'],'spell'=>['version'=>1,'presetId'=>'force-wall-shatter','placement'=>'target','sceneId'=>$attack['sceneId'],'layerId'=>$point['layerId']??'ground','source'=>['x'=>$point['x'],'y'=>$point['y'],'size'=>$point['size']??40],'targetPoint'=>$plan['impact'],'radiusCells'=>1.5]],$attack['sceneId'],$point['layerId']??'ground',$target['id'],$attack['visibility']??'public');queueOnlineDomainUpsert($pending,$records,'activity',$activity);
        }
    }
    return $attack;
}
