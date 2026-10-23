<?php
declare(strict_types=1);

function applicationTokenSpatialFootprint(array $token,array $map):array {
    $mountId=$token['mountedOnTokenId']??'';
    if($mountId===''||$mountId===($token['id']??''))return $token;
    foreach($map['tokens']??[] as $mount)if(($mount['id']??'')===$mountId&&empty($mount['mountedOnTokenId']))
        return [...$token,'x'=>$mount['x'],'y'=>$mount['y'],'layerId'=>$mount['layerId']??'ground','size'=>$mount['size']??40];
    return $token;
}

function applicationMapDistanceInCells(array $a,array $b,array $map,bool $diagonalAsOne=false,bool $fromEdges=false):float {
    $a=applicationTokenSpatialFootprint($a,$map);$b=applicationTokenSpatialFootprint($b,$map);
    $layer=$a['layerId']??$map['activeLayerId']??'ground';$level=$map['layers'][$layer]??$map;
    if(($b['layerId']??$layer)!==$layer)return INF;
    $grid=(float)($map['gridSize']??0);$width=(float)(($level['naturalWidth']??0)?:($map['naturalWidth']??0));$height=(float)(($level['naturalHeight']??0)?:($map['naturalHeight']??0));
    if(!($grid>0&&$width>0&&$height>0))return INF;
    $dx=abs(($a['x']-$b['x'])*$width/(100*$grid));$dy=abs(($a['y']-$b['y'])*$height/(100*$grid));
    $radius=static fn($p)=>max(0,(float)(is_numeric($p['size']??null)?$p['size']:(!empty($p['id'])?40:0)))/(2*$grid);
    $edges=$fromEdges?$radius($a)+$radius($b):0;
    return $diagonalAsOne?max(0,$dx-$edges,$dy-$edges):max(0,hypot($dx,$dy)-$edges);
}

function applicationComplexMovementPoint(array $value): array {
    foreach (['x', 'y'] as $axis) if (!is_numeric($value[$axis] ?? null) || !is_finite((float) $value[$axis]) || $value[$axis] < 0 || $value[$axis] > 100)
        applicationComplexAbilityFail('Position de déplacement invalide.', 'complex_ability_movement_invalid');
    return ['x' => (float) $value['x'], 'y' => (float) $value['y']];
}
function applicationComplexMovementSamePoint(array $a, array $b): bool {
    return abs($a['x'] - $b['x']) < 1e-5 && abs($a['y'] - $b['y']) < 1e-5;
}
function applicationComplexMovementDimensions(array $map): array {
    return ['width' => ($map['naturalWidth'] ?? 0) > 0 ? (float) $map['naturalWidth'] : 1600,
        'height' => ($map['naturalHeight'] ?? 0) > 0 ? (float) $map['naturalHeight'] : 900,
        'grid' => ($map['gridSize'] ?? 0) > 0 ? (float) $map['gridSize'] : 50];
}
function initialApplicationComplexMovementState(array $source, int $now,float $attackWidthCells=0): array {
    $point = applicationComplexMovementPoint($source);
    return ['status' => 'pending', 'origin' => $point, 'points' => [$point], 'sourceSize' => max(0, (float) ($source['size'] ?? 40)),
        ...($attackWidthCells>0?['attackWidthCells'=>$attackWidthCells]:[]),
        'distanceMeters' => 0, 'moved' => false, 'startedAt' => $now, 'lastMovedAt' => 0];
}
function appendApplicationComplexMovementPath(array $state, array $start, array $end, mixed $acceptedPath, array $map, int $maximumMeters, int $now, int $maximumCells=0): array {
    $from = applicationComplexMovementPoint($start); $to = applicationComplexMovementPoint($end);
    if (applicationComplexMovementSamePoint($from, $to)) return $state;
    $points = array_map('applicationComplexMovementPoint', $state['points'] ?? []);
    if ($points === [] || !applicationComplexMovementSamePoint($points[count($points) - 1], $from))
        applicationComplexAbilityFail('La position a changé en dehors du trajet de cette compétence. Reprenez son déplacement.', 'complex_ability_movement_invalid');
    $path = is_array($acceptedPath) && $acceptedPath !== [] ? array_map('applicationComplexMovementPoint', $acceptedPath) : [$from, $to];
    if (!applicationComplexMovementSamePoint($path[0], $from) || !applicationComplexMovementSamePoint($path[count($path) - 1], $to))
        applicationComplexAbilityFail('Le trajet du déplacement n’a pas été confirmé.', 'complex_ability_movement_invalid');
    $dimensions = applicationComplexMovementDimensions($map); $distance = (float) ($state['distanceMeters'] ?? 0);$cells=(float)($state['distanceCells']??0);
    for ($i = 1; $i < count($path); $i++) {
        if (applicationComplexMovementSamePoint($path[$i - 1], $path[$i])) continue;
        $dx = abs($path[$i]['x'] - $path[$i - 1]['x']) * $dimensions['width'] / (100 * $dimensions['grid']);
        $dy = abs($path[$i]['y'] - $path[$i - 1]['y']) * $dimensions['height'] / (100 * $dimensions['grid']);
        $distance += 2 * max($dx, $dy) + min($dx, $dy);$cells+=max($dx,$dy); $points[] = $path[$i];
    }
    if($maximumCells>0&&$cells>$maximumCells+1e-7)applicationComplexAbilityFail('Ce déplacement dépasserait les '.$maximumCells.' cases de la compétence.','complex_ability_movement_invalid');
    if (!$maximumCells&&$distance > $maximumMeters + 1e-7) applicationComplexAbilityFail('Ce déplacement dépasserait les ' . $maximumMeters . ' mètres de la compétence.', 'complex_ability_movement_invalid');
    if (count($points) > 256) applicationComplexAbilityFail('Confirmez ce déplacement avant d’ajouter d’autres segments.', 'complex_ability_movement_invalid');
    return [...$state, 'points' => $points, 'distanceMeters' => $distance,...($maximumCells?['distanceCells'=>$cells]:[]), 'moved' => true, 'lastMovedAt' => $now];
}
function applicationComplexMovementAtDestination(array $state, ?array $source): bool {
    $points = $state['points'] ?? [];
    return $source !== null && ($state['moved'] ?? false) === true && ($state['distanceMeters'] ?? 0) > 0 && count($points) > 1
        && applicationComplexMovementSamePoint($points[count($points) - 1], applicationComplexMovementPoint($source));
}
function applicationTargetTouchesComplexMovement(array $target, array $state, array $map): bool {
    $target=applicationTokenSpatialFootprint($target,$map);
    $closest=applicationComplexMovementClosestPoint($target,$state,$map);
    $radius = applicationComplexMovementAttackWidth($state,$map) / 2 + (float) ($target['size'] ?? 40) / 2;
    return $closest!==null&&$closest['distance']<=$radius+1e-7;
}
function applicationComplexMovementAttackWidth(array $state,array $map):float {
    return !empty($state['attackWidthCells'])?$state['attackWidthCells']*applicationComplexMovementDimensions($map)['grid']:(float)($state['sourceSize']??40);
}
function applicationComplexMovementClosestPoint(array $target,array $state,array $map):?array {
    $target=applicationTokenSpatialFootprint($target,$map);
    if(($state['status']??'')!=='completed'||empty($state['moved'])||count($state['points']??[])<2)return null;
    $d=applicationComplexMovementDimensions($map);$px=$target['x']*$d['width']/100;$py=$target['y']*$d['height']/100;$closest=null;$points=$state['points'];
    for($i=1;$i<count($points);$i++){$ax=$points[$i-1]['x']*$d['width']/100;$ay=$points[$i-1]['y']*$d['height']/100;$dx=$points[$i]['x']*$d['width']/100-$ax;$dy=$points[$i]['y']*$d['height']/100-$ay;
        $t=max(0,min(1,(($px-$ax)*$dx+($py-$ay)*$dy)/($dx*$dx+$dy*$dy?:1)));$distance=hypot($px-$ax-$t*$dx,$py-$ay-$t*$dy);
        if($closest===null||$distance<$closest['distance'])$closest=['x'=>($ax+$t*$dx)*100/$d['width'],'y'=>($ay+$t*$dy)*100/$d['height'],'distance'=>$distance];}
    return $closest;
}
function applicationComplexAbilityStrikeOrigin(array $execution,array $step,array $target,array $map):?array {
    $origin=null;foreach($execution['workflow']['steps']??[] as $entry)if(($entry['id']??'')===($step['sourceStepId']??''))$origin=$entry;
    $targeting=($step['type']??'')==='targets'?$step:(($origin['type']??'')==='targets'?$origin:null);
    $movementId=($targeting['sourceMovementStepId']??'')?:(!empty($origin['charge'])?$origin['id']:'');$movement=$execution['stepStates'][$movementId]??[];
    $closest=applicationComplexMovementClosestPoint($target,$movement,$map);if($closest===null)return null;
    $final=!empty($origin['charge'])&&($execution['stepStates'][$origin['charge']['targetStepId']]['allocations'][0]['tokenId']??'')===$target['id'];
    if(!$final&&!applicationTargetTouchesComplexMovement($target,$movement,$map))return null;
    return ['x'=>$closest['x'],'y'=>$closest['y'],'size'=>applicationComplexMovementAttackWidth($movement,$map),'layerId'=>$execution['layerId']];
}
function applicationComplexAbilityTargetEligible(array $execution, array $step, ?array $source, ?array $target, array $map,string $attackId=''): bool {
    $range=applicationComplexAbilityTargetRangeCells($execution,$step,$source,$attackId);
    if($target===null)return false;
    $origin=null;foreach($execution['workflow']['steps']??[] as $entry)if(($entry['id']??'')===($step['sourceStepId']??''))$origin=$entry;
    $targeting=($step['type']??'')==='targets'?$step:(($origin['type']??'')==='targets'?$origin:$step);$strikeOrigin=applicationComplexAbilityStrikeOrigin($execution,$step,$target,$map);
    if((!empty($targeting['sourceMovementStepId'])||!empty($origin['charge']))&&$strikeOrigin===null)return false;
    if (!applicationComplexAbilityTargetInRange($strikeOrigin!==null?[...($source??[]),...$strikeOrigin]:$source, $target, $map, $range)) return false;
    if (($targeting['targetRelation']??'') === 'enemies' && ($source === null || !applicationDamageAreaAffects(['affectCaster' => false, 'affectAllies' => false, 'affectEnemies' => true], $source['id'], (string) ($source['controllerAccountId'] ?? $source['controllerPlayerId'] ?? ''), $target['id'], (string) ($target['controllerAccountId'] ?? $target['controllerPlayerId'] ?? '')))) return false;
    return empty($targeting['sourceMovementStepId']) || applicationTargetTouchesComplexMovement($target, $execution['stepStates'][$targeting['sourceMovementStepId']] ?? [], $map);
}
function recordApplicationComplexAbilityMovement(array $executions, array $context): array {
    foreach ($executions as &$execution) {
        $step = $execution['workflow']['steps'][$execution['currentStepIndex'] ?? 0] ?? null;
        if (($execution['status'] ?? '') !== 'active' || ($step['type'] ?? '') !== 'movement' || ($execution['sceneId'] ?? '') !== $context['sceneId']
            || ($execution['layerId'] ?? 'ground') !== $context['layerId'] || ($execution['sourceTokenId'] ?? '') !== $context['source']['id']) continue;
        $state = $execution['stepStates'][$step['id']] ?? [];
        if (!isset($state['origin'])) $state = initialApplicationComplexMovementState($context['source'], $context['now'],$step['attackWidthCells']??0);
        $updated = appendApplicationComplexMovementPath($state, $context['source'], $context['destination'], $context['path'] ?? null, $context['map'], $step['maximumMeters'], $context['now'],$step['maximumCells']??0);
        if(!empty($step['charge']))applicationValidateChargePath($updated,applicationChargeFinalTarget($execution,$step,$context['tokens']??$context['map']['tokens']??[]),$context['map']);
        if ($updated !== $state) { $execution['stepStates'][$step['id']] = $updated; $execution['revision']++; $execution['updatedAt'] = $context['now']; }
    }
    unset($execution); return $executions;
}
