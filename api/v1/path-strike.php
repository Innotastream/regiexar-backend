<?php
declare(strict_types=1);
require_once __DIR__.'/complex-movement.php';
require_once __DIR__.'/charge-path.php';
require_once __DIR__.'/charge-travel.php';
require_once __DIR__.'/token-pathfinding.php';
require_once __DIR__.'/contact-effect-zones.php';
function applicationPathStrikeFail(string $message):never {applicationComplexAbilityFail($message,'complex_ability_path_strike_invalid',409);}
function applicationPathStrike(mixed $v):?array {if(!$v)return null;if(!is_array($v)||($v['version']??null)!==1||($v['order']??'')!=='distance-to-end-desc'||($v['staged']??false)!==true)applicationComplexAbilityFail('Ce déplacement à frappes successives est invalide ou plus récent.','complex_ability_definition_invalid',400);return ['version'=>1,'order'=>'distance-to-end-desc','staged'=>true,...(isset($v['contactZone'])?['contactZone'=>applicationContactZoneDefinition($v['contactZone'])]:[])];}
function applicationPathStrikePoint(mixed $p):bool {return is_array($p)&&array_key_exists('x',$p)&&array_key_exists('y',$p)&&(is_int($p['x'])||is_float($p['x']))&&(is_int($p['y'])||is_float($p['y']))&&is_finite((float)$p['x'])&&is_finite((float)$p['y'])&&$p['x']>=0&&$p['x']<=100&&$p['y']>=0&&$p['y']<=100;}
function applicationPathStrikeXY(array $p):array{return ['x'=>$p['x'],'y'=>$p['y']];}
function applicationPathStrikeDistance(array $a,array $b,array $d):float{return hypot(($a['x']-$b['x'])*$d['width']/100,($a['y']-$b['y'])*$d['height']/100);}
function applicationPathStrikeDistanceFail(float $distance,float $maximum,bool $withStops=false):never {
    $meters=rtrim(rtrim(number_format($distance,2,',',''), '0'), ',');
    $subject=$withStops?'Le trajet avec les arrêts pour frapper':'Votre tracé';
    $correction=$withStops?'Retirez une cible ou rapprochez l’arrivée.':'Retirez le dernier point ou rapprochez l’arrivée.';
    applicationPathStrikeFail("$subject fait $meters m et dépasse la limite de $maximum mètres. $correction");
}
function applicationPathStrikeContext(array $execution,array $step,?array $source,array $tokens,array $map):array {
    if(applicationPathStrike($step['pathStrike']??null)===null||$source===null)applicationPathStrikeFail('Ce déplacement ne prépare pas de frappes successives.');
    $layer=$execution['layerId']??'ground';$level=[...$map,...($map['layers'][$layer]??[]),'tokens'=>$tokens,'sceneId'=>$execution['sceneId'],'activeLayerId'=>$layer];
    $body=applicationAbilityMovementSource($source,$level,($step['mountedMovement']??false)===true);$group=applicationAbilityMovementTokenIds($source,$level,($step['mountedMovement']??false)===true);
    $mount=null;foreach($tokens as$t)if(($t['id']??'')===($source['mountedOnTokenId']??'')&&empty($t['mountedOnTokenId']))$mount=$t;
    if(!applicationPathStrikePoint($body)||($body['layerId']??'ground')!==$layer||!empty($source['mountedOnTokenId'])&&$mount===null)applicationPathStrikeFail('Le lanceur ou sa monture n’est plus disponible à son départ.');
    if(applicationAbilityMovementIsBlocked($source,$level,$map['_abilityTimedConditions']??[],$execution['sceneId'],($step['mountedMovement']??false)===true))applicationPathStrikeFail('Le lanceur est immobilisé.');
    $others=array_values(array_filter($map['abilityExecutions']??$map['_abilityExecutions']??[],static fn($e)=>($e['id']??'')!==$execution['id']));
    foreach($tokens as$t)if(in_array($t['id'],$group,true)&&(!empty($t['immovable'])||!empty($t['terrainBarrier'])||!empty($t['fixedStructure'])||applicationAbilityMovementLock($others,$execution['sceneId'],$t['id'],$tokens,null,array_values(array_filter($map['abilityMovementLeases']??$map['_abilityMovementLeases']??[],static fn($lease)=>$lease['id']!=='path-strike-'.$execution['id'])))!==null))applicationPathStrikeFail('Un autre mouvement bloque ce pion.');
    return ['level'=>$level,'body'=>$body,'group'=>$group,'d'=>applicationComplexMovementDimensions($level),'source'=>$source];
}
function applicationPathStrikeSelectionState(array $execution,array $step,array $source,array $tokens,array $map,array $selection,int $now):array {
    $c=applicationPathStrikeContext($execution,$step,$source,$tokens,$map);$destination=$selection['destination']??null;
    if(!applicationPathStrikePoint($destination))applicationPathStrikeFail('Choisissez le point final du déplacement.');
    $path=$selection['path']??[applicationPathStrikeXY($c['body']),applicationPathStrikeXY($destination)];
    if(!is_array($path)||!array_is_list($path)||count($path)<2||count($path)>128||array_filter($path,static fn($p)=>!applicationPathStrikePoint($p))||!applicationChargeSame($path[0],$c['body'])||!applicationChargeSame($path[array_key_last($path)],$destination))applicationPathStrikeFail('Le trajet doit relier le départ courant à la destination choisie.');
    $state=initialApplicationComplexMovementState($c['body'],$now,$step['attackWidthCells']??0);
    try {
        for($i=1;$i<count($path);$i++)$state=appendApplicationComplexMovementPath($state,$path[$i-1],$path[$i],[$path[$i-1],$path[$i]],$c['level'],$step['maximumMeters'],$now);
    } catch(ApplicationComplexAbilityException $error) {
        $attempted=applicationPathStrikeMeters($path,$c['level']);
        if($attempted>$step['maximumMeters']+1e-7)applicationPathStrikeDistanceFail($attempted,$step['maximumMeters']);
        throw $error;
    }
    if(empty($state['moved']))applicationPathStrikeFail('Choisissez un déplacement réel avant de confirmer.');
    return [...$c,'selectionPath'=>array_map('applicationPathStrikeXY',$path),'destination'=>applicationPathStrikeXY($destination),'selectionState'=>[...$state,'status'=>'completed']];
}
function applicationPathStrikeCandidates(array $execution,array $step,array $source,array $tokens,array $map,array $selection):array {
    return applicationPathStrikeCandidatesFor(applicationPathStrikeSelectionState($execution,$step,$source,$tokens,$map,$selection,0),$execution,$source,$tokens);
}
function applicationPathStrikeCandidatesFor(array $c,array $execution,array $source,array $tokens):array {
    $riding=applicationAbilityMovementTokenIds($source,$c['level'],true);
    $candidates=array_values(array_filter($tokens,static fn($t)=>$t['id']!==$source['id']&&!in_array($t['id'],$riding,true)&&empty($t['hidden'])&&empty($t['dimSilhouette'])&&($t['hp']??0)>0&&($t['maxHp']??0)>0&&($t['layerId']??'ground')===$execution['layerId']&&applicationPathStrikePoint($t)&&applicationChargeEnemy($source,$t)&&applicationTargetTouchesComplexMovement($t,$c['selectionState'],$c['level'])));
    usort($candidates,static fn($a,$b)=>(applicationPathStrikeDistance(applicationTokenSpatialFootprint($b,$c['level']),$c['destination'],$c['d'])<=>applicationPathStrikeDistance(applicationTokenSpatialFootprint($a,$c['level']),$c['destination'],$c['d']))?:strcmp($a['id'],$b['id']));return $candidates;
}
function applicationPathStrikeLandingBodies(array $c,array $tokens):array {
    return array_values(array_filter($tokens,static function(array $token)use($c):bool {
        if(in_array($token['id'],$c['group'],true)||!empty($token['mountedOnTokenId'])
            ||($token['layerId']??'ground')!==$c['level']['activeLayerId']||!applicationPathStrikePoint($token)
            ||!empty($token['terrainBarrier']))return false;
        if(onlineManualDeath($token))return false;
        $hp=$token['hp']??$token['resources']['hp']??null;
        $maxHp=$token['maxHp']??$token['resources']['maxHp']??null;
        $publicHealth=$token['publicHealth']??'';
        if($publicHealth==='down')return true;
        if($hp===null)return in_array($publicHealth,['normal','critical'],true)
            && ($maxHp===null||$maxHp>0);
        $controlled=!empty($token['controllerPlayerId'])||!empty($token['playerControlled'])||!empty($token['ownedByYou']);
        return ($hp>0&&($maxHp===null||$maxHp>0))
            || ($maxHp>0&&onlineHealthState($hp,$maxHp,$controlled)['code']==='down');
    }));
}
function applicationPathStrikeBodyRadius(array $c,array $token):float {
    return (($c['body']['size']??40)+($token['size']??40))/2;
}
function applicationPathStrikeSegmentSafe(array $c,array $from,array $to,array $bodies):bool {
    return applicationChargeTravelAllowed($c['body'],$from,$to,$c['level'])&&!applicationPathStrikeCrossesStructure($c,$from,$to,$bodies);
}
function applicationPathStrikeCrossesStructure(array $c,array $from,array $to,array $bodies):bool {
    $dx=($to['x']-$from['x'])*$c['d']['width']/100;$dy=($to['y']-$from['y'])*$c['d']['height']/100;
    foreach($bodies as$other){$x=($other['x']-$from['x'])*$c['d']['width']/100;$y=($other['y']-$from['y'])*$c['d']['height']/100;$t=max(0,min(1,($x*$dx+$y*$dy)/(($dx*$dx+$dy*$dy)?:1)));$needed=applicationPathStrikeBodyRadius($c,$other);
        if(hypot($x-$dx,$y-$dy)<$needed-1e-6||hypot($x-$t*$dx,$y-$t*$dy)<min($needed,hypot($x,$y))-1e-6)return true;
    }
    return false;
}
function applicationPathStrikeExplainSegment(array $c,array $from,array $to,array $structures):string {
    $blocker=applicationChargeTravelBlocker($c['body'],$from,$to,$c['level'],true);
    if($blocker===null&&applicationPathStrikeCrossesStructure($c,$from,$to,$structures))$blocker='structure';
    return match($blocker){
        'edge'=>'Le pion dépasserait le bord de la carte. Éloignez le point du bord.',
        'wall'=>'Un mur coupe le passage. Ajoutez un point de passage autour du mur ou passez par une ouverture.',
        'terrain'=>'Une porte ou un obstacle de terrain coupe le passage. Passez par une ouverture ou modifiez le tracé.',
        'vision'=>'Le trajet quitte la zone visible. Tracez dans la zone visible ou demandez au MJ d’ajuster la vision.',
        'structure'=>'Une structure fixe bloque le passage. Ajoutez un point de passage à côté.',
        default=>'Aucun passage praticable n’a été trouvé. Ajoutez un point intermédiaire sur un passage libre.',
    };
}
function applicationPathStrikeLandingFree(array $c,array $endpoint,array $bodies):bool {
    foreach($bodies as $body) {
        if(applicationPathStrikeDistance($endpoint,$body,$c['d'])<applicationPathStrikeBodyRadius($c,$body)-1e-6)return false;
    }
    return true;
}
function applicationPathStrikeRoute(array &$c,array $from,array $to,array $bodies):?array {
    if(applicationPathStrikeSegmentSafe($c,$from,$to,$bodies))return [applicationPathStrikeXY($from),applicationPathStrikeXY($to)];
    if(($c['failedSearches']??0)>=4)return null;
    if(!isset($c['routeWalls'])){
        $walls=$c['level']['walls']??null;
        if(!validApplicationWallState($walls))$walls=emptyApplicationWallState($c['d']['width'],$c['d']['height']);
        $c['routeWalls']=applicationTerrainBarrierWalls($walls,[...$c['level'],'tokens'=>[
            ...array_filter($c['level']['tokens'],static fn($t)=>!empty($t['terrainBarrier'])),
            ...array_map(static fn($t)=>[...$t,'terrainBarrier'=>['version'=>1],'hp'=>1],$bodies),
        ]]);
        $c['routeVisible']=is_callable($c['level']['_chargeVisible']??null)?$c['level']['_chargeVisible']:onlineVisiblePathPointTester($c['level']['fog']??null,$c['level']['visionMask']??['enabled'=>false]);
    }
    $result=findApplicationVisibleTokenPath($c['routeWalls'],$from,$to,(float)($c['body']['size']??40),$c['d']['width'],$c['d']['height'],$c['routeVisible']);
    if(!empty($result['blocked'])||empty($result['path'])){if(in_array($result['reason']??'', ['search-limit','no-two-segment-path','unsafe-rounding'],true))$c['failedSearches']=($c['failedSearches']??0)+1;return null;}
    for($i=1;$i<count($result['path']);$i++)if(!applicationPathStrikeSegmentSafe($c,$result['path'][$i-1],$result['path'][$i],$bodies)){$c['failedSearches']=($c['failedSearches']??0)+1;return null;}
    return array_map('applicationPathStrikeXY',$result['path']);
}
function applicationPathStrikeRouteThrough(array &$c,array $from,array $waypoints,array $structures,int $firstIndex):?array {
    $path=[applicationPathStrikeXY($from)];
    foreach($waypoints as $offset=>$waypoint){
        $start=$path[array_key_last($path)];
        $section=applicationPathStrikeRoute($c,$start,$waypoint,$structures);
        if($section===null){
            $c['failedStep']=['from'=>$start,'to'=>$waypoint,'index'=>$firstIndex+$offset];
            return null;
        }
        array_push($path,...array_slice($section,1));
    }
    return $path;
}
function applicationPathStrikeFailGuide(array $c,array $structures):never {
    ['from'=>$from,'to'=>$to,'index'=>$index]=$c['failedStep'];
    applicationPathStrikeFail("Passage vers le point n° $index. ".applicationPathStrikeExplainSegment($c,$from,$to,$structures));
}
function applicationPathStrikeClosestGuideSegment(array $c,array $target):int {
    $best=0;
    $nearest=INF;
    for($i=0;$i<count($c['selectionPath'])-1;$i++){
        $gap=applicationChargeClosest($target,$c['selectionPath'][$i],$c['selectionPath'][$i+1],$c['d'])['distance'];
        if($gap<$nearest){$nearest=$gap;$best=$i;}
    }
    return $best;
}
function applicationPathStrikeMeters(array $path,array $map):float {$sum=0;for($i=1;$i<count($path);$i++)$sum+=applicationChargeDistanceMeters($path[$i-1],$path[$i],$map);return $sum;}
function applicationPlanPathStrike(array $execution,array $step,array $source,array $tokens,array $map,array $selection,int $now):array {
    $c=applicationPathStrikeSelectionState($execution,$step,$source,$tokens,$map,$selection,$now);$candidates=applicationPathStrikeCandidatesFor($c,$execution,$source,$tokens);$selected=$selection['targetTokenIds']??[];
    if(!is_array($selected)||!array_is_list($selected)||count($selected)>20||array_filter($selected,static fn($id)=>!is_string($id))||count(array_unique($selected))!==count($selected)||array_diff($selected,array_column($candidates,'id')))applicationPathStrikeFail('Choisissez jusqu’à vingt ennemis distincts sur le trajet.');
    $chosen=array_values(array_filter($candidates,static fn($t)=>in_array($t['id'],$selected,true)));$positions=[];foreach($chosen as$t){$p=applicationTokenSpatialFootprint($t,$c['level']);$positions[]=['tokenId'=>$t['id'],'x'=>$p['x'],'y'=>$p['y'],'size'=>$p['size']??40,'layerId'=>$p['layerId']??'ground'];}
    $occupants=applicationPathStrikeLandingBodies($c,$tokens);
    $structures=array_values(array_filter($occupants,static fn($token)=>!empty($token['fixedStructure'])));
    if(!applicationPathStrikeLandingFree($c,$c['destination'],$occupants))
        applicationPathStrikeFail('La destination finale est occupée. Choisissez une case libre à côté.');
    if(!applicationPathStrikeSegmentSafe($c,$c['destination'],$c['destination'],$structures))
        applicationPathStrikeFail('Point d’arrivée. '.applicationPathStrikeExplainSegment($c,$c['destination'],$c['destination'],$structures));
    $attackStep=null;foreach($execution['workflow']['steps']??[]as$s)if(($s['type']??'')==='allocated-attacks'){foreach($execution['workflow']['steps']as$p)if(($s['sourceStepId']??'')===$step['id']||$p['id']===($s['sourceStepId']??'')&&($p['sourceMovementStepId']??'')===$step['id']){$attackStep=$s;break 2;}}
    $build=static function(int $side)use($c,$chosen,$positions,$execution,$step,$attackStep,$structures,$now):array {
    $c['failedSearches']=0;
    $previous=applicationPathStrikeXY($c['body']);$points=[$previous];$distanceMeters=0;$targets=[];$attackPlan=[];$finalPath=null;$nextWaypoint=1;
    foreach($chosen as$index=>$target){
        $physical=applicationTokenSpatialFootprint($target,$c['level']);$radius=applicationPathStrikeBodyRadius($c,$physical)+4;
        $guideEnd=max($nextWaypoint,applicationPathStrikeClosestGuideSegment($c,$physical)+1);
        $approach=applicationPathStrikeRouteThrough($c,$previous,array_slice($c['selectionPath'],$nextWaypoint,$guideEnd-$nextWaypoint),$structures,$nextWaypoint);
        if($approach===null)applicationPathStrikeFailGuide($c,$structures);
        $nextWaypoint=$guideEnd;
        $heading=atan2(($c['destination']['y']-$c['body']['y'])*$c['d']['height'],($c['destination']['x']-$c['body']['x'])*$c['d']['width']);$base=$heading+$side*M_PI/2;
        $isLast=$index===count($chosen)-1;$best=null;$reachableStop=false;
        foreach([0,1,-1,2,-2,3,-3,4,-4,5,-5,6,-6,7,-7,8]as$offset){$angle=$base+$offset*M_PI/8;$contact=['x'=>$physical['x']+cos($angle)*$radius*100/$c['d']['width'],'y'=>$physical['y']+sin($angle)*$radius*100/$c['d']['height']];if(!applicationPathStrikePoint($contact))continue;
            $section=applicationPathStrikeRoute($c,$approach[array_key_last($approach)],$contact,$structures);
            if($section===null)continue;
            $path=[...$approach,...array_slice($section,1)];$reachableStop=true;
            $exitPath=$isLast?applicationPathStrikeRouteThrough($c,$contact,array_slice($c['selectionPath'],$nextWaypoint),$structures,$nextWaypoint):null;
            if($isLast&&$exitPath===null)continue;
            $cost=applicationPathStrikeMeters($path,$c['level']);$totalCost=$cost+($exitPath!==null?applicationPathStrikeMeters($exitPath,$c['level']):0);
            if($best===null||$totalCost<$best['totalCost'])$best=compact('contact','path','cost','totalCost','exitPath');
            if(!$isLast||$distanceMeters+$totalCost<=$step['maximumMeters']+1e-7)break;
        }
        if($best===null){
            if($reachableStop)applicationPathStrikeFailGuide($c,$structures);
            $name=($target['name']??'')?:'cette cible';
            applicationPathStrikeFail("Aucune position libre pour frapper $name. Retirez cette cible ou rapprochez le tracé.");
        }
        if($isLast)$finalPath=$best['exitPath'];
        $distanceMeters+=$best['cost'];$points=[...$points,...array_slice($best['path'],1)];$targets[]=['tokenId'=>$target['id'],'contactPoint'=>$best['contact']];
        $attackPlan[]=['tokenId'=>$target['id'],'attackId'=>$execution['abilityId'],'statId'=>$attackStep['statId']??'','configured'=>true,'opposed'=>false,'movePoint'=>$best['contact'],'path'=>$best['path']];$previous=$best['contact'];
    }
    $finalPath??=applicationPathStrikeRouteThrough($c,$previous,array_slice($c['selectionPath'],$nextWaypoint),$structures,$nextWaypoint);
    if($finalPath===null)applicationPathStrikeFailGuide($c,$structures);
    $distanceMeters+=applicationPathStrikeMeters($finalPath,$c['level']);$points=[...$points,...array_slice($finalPath,1)];
    if(count($points)>256)applicationPathStrikeFail('Ce trajet comporte trop de segments.');
    return [...initialApplicationComplexMovementState($c['body'],$now,$step['attackWidthCells']??0),'planned'=>true,'moved'=>true,'selectionPath'=>$c['selectionPath'],'destination'=>$c['destination'],'points'=>$points,'distanceMeters'=>$distanceMeters,'lastMovedAt'=>$now,'targets'=>$targets,
        'allocations'=>array_map(static fn($t)=>['tokenId'=>$t['id'],'targetName'=>$t['name']??'Cible','count'=>1],$chosen),'attackPlan'=>$attackPlan,'finalMovePoint'=>$c['destination'],'finalPath'=>$finalPath,'targetPositions'=>$positions];
    };
    $plans=[];$failure=null;
    foreach($chosen!==[]?[-1,1]:[1] as $side)try{$plans[]=$build($side);}catch(RuntimeException $error){$failure=$error;}
    if($plans===[])throw $failure;usort($plans,static fn($a,$b)=>$a['distanceMeters']<=>$b['distanceMeters']);
    if($plans[0]['distanceMeters']>$step['maximumMeters']+1e-7)
        applicationPathStrikeDistanceFail($plans[0]['distanceMeters'],$step['maximumMeters'],$chosen!==[]);
    return $plans[0];
}
function applicationPathStrikeMovementFor(array $execution):?array {foreach($execution['workflow']['steps']??[]as$step)if(!empty($step['pathStrike']['staged']))return ['step'=>$step,'state'=>$execution['stepStates'][$step['id']]??null];return null;}
function applicationPathStrikeValidatedTravel(array $execution,array $step,array $state,array $tokens,array $map,array $from,array $to,array $path,string $targetTokenId=''):array {
    if(($state['pathStrikeReadyAt']??0)>(int)floor(microtime(true)*1000))applicationPathStrikeFail('Attendez la fin du déplacement en cours.');
    $source=applicationComplexAbilityTokenById($tokens,$execution['sourceTokenId']);$c=applicationPathStrikeContext($execution,$step,$source,$tokens,$map);
    if(!applicationChargeSame($c['body'],$from)||!applicationPathStrikePoint($to)||count($path)<2||!applicationChargeSame($path[0],$from)||!applicationChargeSame($path[array_key_last($path)],$to))applicationPathStrikeFail('Le lanceur a quitté le trajet prévu.');
    foreach($state['targetPositions']??[]as$saved){$target=applicationComplexAbilityTokenById($tokens,$saved['tokenId']);$p=$target?applicationTokenSpatialFootprint($target,$c['level']):null;if(!$p||!applicationChargeSame($p,$saved)||($p['size']??40)!=$saved['size']||($p['layerId']??'ground')!==$saved['layerId'])applicationPathStrikeFail('Une cible a quitté sa position. Arrêtez puis préparez à nouveau le trajet.');}
    $occupants=applicationPathStrikeLandingBodies($c,$tokens);
    $structures=array_values(array_filter($occupants,static fn($token)=>!empty($token['fixedStructure'])));
    if($targetTokenId===''&&!applicationPathStrikeLandingFree($c,$to,$occupants))
        applicationPathStrikeFail('La destination finale est maintenant occupée.');
    for($i=1;$i<count($path);$i++)if(!applicationPathStrikeSegmentSafe($c,$path[$i-1],$path[$i],$structures))
        applicationPathStrikeFail('Le trajet prévu est désormais bloqué. '.applicationPathStrikeExplainSegment($c,$path[$i-1],$path[$i],$structures));
    $target=applicationComplexAbilityTokenById($tokens,$targetTokenId);$physical=$target?applicationTokenSpatialFootprint($target,$c['level']):null;$moves=[];foreach($c['group']as$id){$t=applicationComplexAbilityTokenById($tokens,$id);$moves[]=['tokenId'=>$id,'size'=>$t['size']??40,'from'=>applicationPathStrikeXY($t),'to'=>applicationPathStrikeXY($to)];}
    return ['sourceTokenId'=>$source['id'],'from'=>applicationPathStrikeXY($from),'to'=>applicationPathStrikeXY($to),'path'=>array_map('applicationPathStrikeXY',$path),'size'=>$c['body']['size']??40,'pathStrike'=>true,...($target?['targetTokenId'=>$target['id'],'targetPoint'=>['x'=>$physical['x'],'y'=>$physical['y'],'size'=>$physical['size']??40]]:[]),
        ...(!applicationChargeSame($from,$to)&&!empty($source['mountedOnTokenId'])&&($step['mountedMovement']??false)!==true?['dismountTokenIds'=>[$source['id']]]:[]),'moves'=>$moves];
}
function applicationPathStrikeTravel(array $execution,array $command,array $tokens,array $map):?array {
    $movement=applicationPathStrikeMovementFor($execution);$step=$execution['workflow']['steps'][$execution['currentStepIndex']]??null;if(($command['action']??'')!=='prepare-target'||$movement===null||($step['type']??'')!=='allocated-attacks')return null;
    $state=$execution['stepStates'][$step['id']]??[];$done=0;foreach($state['targets']??[]as$t)$done+=count($t['attacks']??[]);$strike=$movement['state']['attackPlan'][$done]??null;
    if(!empty($state['pendingTargetTokenId'])||!$strike||$strike['tokenId']!==($command['targetTokenId']??''))applicationPathStrikeFail('La prochaine cible de ce trajet a changé.');
    return applicationPathStrikeValidatedTravel($execution,$movement['step'],$movement['state'],$tokens,$map,$done?$movement['state']['attackPlan'][$done-1]['movePoint']:$movement['state']['origin'],$strike['movePoint'],$strike['path'],$strike['tokenId']);
}
function applicationPathStrikeFinalTravel(array $execution,array $tokens,array $map):?array {
    $movement=applicationPathStrikeMovementFor($execution);if(empty($movement['state']['planned'])||!empty($movement['state']['finalMoveApplied'])||($execution['status']??'')!=='completed')return null;$plan=$movement['state']['attackPlan']??[];$from=$plan!==[]?$plan[array_key_last($plan)]['movePoint']:$movement['state']['origin'];
    return applicationPathStrikeValidatedTravel($execution,$movement['step'],$movement['state'],$tokens,$map,$from,$movement['state']['finalMovePoint'],$movement['state']['finalPath']);
}
