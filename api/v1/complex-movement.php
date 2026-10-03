<?php
declare(strict_types=1);

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
function initialApplicationComplexMovementState(array $source, int $now): array {
    $point = applicationComplexMovementPoint($source);
    return ['status' => 'pending', 'origin' => $point, 'points' => [$point], 'sourceSize' => max(10, min(220, (float) ($source['size'] ?? 40))),
        'distanceMeters' => 0, 'moved' => false, 'startedAt' => $now, 'lastMovedAt' => 0];
}
function appendApplicationComplexMovementPath(array $state, array $start, array $end, mixed $acceptedPath, array $map, int $maximumMeters, int $now): array {
    $from = applicationComplexMovementPoint($start); $to = applicationComplexMovementPoint($end);
    if (applicationComplexMovementSamePoint($from, $to)) return $state;
    $points = array_map('applicationComplexMovementPoint', $state['points'] ?? []);
    if ($points === [] || !applicationComplexMovementSamePoint($points[count($points) - 1], $from))
        applicationComplexAbilityFail('La position a changé en dehors du trajet de cette compétence. Reprenez son déplacement.', 'complex_ability_movement_invalid');
    $path = is_array($acceptedPath) && $acceptedPath !== [] ? array_map('applicationComplexMovementPoint', $acceptedPath) : [$from, $to];
    if (!applicationComplexMovementSamePoint($path[0], $from) || !applicationComplexMovementSamePoint($path[count($path) - 1], $to))
        applicationComplexAbilityFail('Le trajet du déplacement n’a pas été confirmé.', 'complex_ability_movement_invalid');
    $dimensions = applicationComplexMovementDimensions($map); $distance = (float) ($state['distanceMeters'] ?? 0);
    for ($i = 1; $i < count($path); $i++) {
        if (applicationComplexMovementSamePoint($path[$i - 1], $path[$i])) continue;
        $dx = abs($path[$i]['x'] - $path[$i - 1]['x']) * $dimensions['width'] / (100 * $dimensions['grid']);
        $dy = abs($path[$i]['y'] - $path[$i - 1]['y']) * $dimensions['height'] / (100 * $dimensions['grid']);
        $distance += 2 * max($dx, $dy) + min($dx, $dy); $points[] = $path[$i];
    }
    if ($distance > $maximumMeters + 1e-7) applicationComplexAbilityFail('Ce déplacement dépasserait les ' . $maximumMeters . ' mètres de la compétence.', 'complex_ability_movement_invalid');
    if (count($points) > 256) applicationComplexAbilityFail('Confirmez ce déplacement avant d’ajouter d’autres segments.', 'complex_ability_movement_invalid');
    return [...$state, 'points' => $points, 'distanceMeters' => $distance, 'moved' => true, 'lastMovedAt' => $now];
}
function applicationComplexMovementAtDestination(array $state, ?array $source): bool {
    $points = $state['points'] ?? [];
    return $source !== null && ($state['moved'] ?? false) === true && ($state['distanceMeters'] ?? 0) > 0 && count($points) > 1
        && applicationComplexMovementSamePoint($points[count($points) - 1], applicationComplexMovementPoint($source));
}
function applicationTargetTouchesComplexMovement(array $target, array $state, array $map): bool {
    $points = $state['points'] ?? [];
    if (($state['status'] ?? '') !== 'completed' || !($state['moved'] ?? false) || count($points) < 2) return false;
    $dimensions = applicationComplexMovementDimensions($map); $point = applicationComplexMovementPoint($target);
    $px = $point['x'] * $dimensions['width'] / 100; $py = $point['y'] * $dimensions['height'] / 100;
    $radius = (float) ($state['sourceSize'] ?? 40) / 2 + (float) ($target['size'] ?? 40) / 2;
    for ($i = 1; $i < count($points); $i++) {
        $ax = $points[$i - 1]['x'] * $dimensions['width'] / 100; $ay = $points[$i - 1]['y'] * $dimensions['height'] / 100;
        $dx = $points[$i]['x'] * $dimensions['width'] / 100 - $ax; $dy = $points[$i]['y'] * $dimensions['height'] / 100 - $ay;
        $t = max(0, min(1, (($px - $ax) * $dx + ($py - $ay) * $dy) / ($dx * $dx + $dy * $dy ?: 1)));
        if (hypot($px - $ax - $t * $dx, $py - $ay - $t * $dy) <= $radius + 1e-7) return true;
    }
    return false;
}
function applicationComplexAbilityTargetEligible(array $execution, array $step, ?array $source, ?array $target, array $map): bool {
    if ($target === null || !applicationComplexAbilityTargetInRange($source, $target, $map, $step['rangeCells'])) return false;
    if ($step['targetRelation'] === 'enemies' && ($source === null || !applicationDamageAreaAffects(['affectCaster' => false, 'affectAllies' => false, 'affectEnemies' => true], $source['id'], (string) ($source['controllerAccountId'] ?? $source['controllerPlayerId'] ?? ''), $target['id'], (string) ($target['controllerAccountId'] ?? $target['controllerPlayerId'] ?? '')))) return false;
    return $step['sourceMovementStepId'] === '' || applicationTargetTouchesComplexMovement($target, $execution['stepStates'][$step['sourceMovementStepId']] ?? [], $map);
}
function recordApplicationComplexAbilityMovement(array $executions, array $context): array {
    foreach ($executions as &$execution) {
        $step = $execution['workflow']['steps'][$execution['currentStepIndex'] ?? 0] ?? null;
        if (($execution['status'] ?? '') !== 'active' || ($step['type'] ?? '') !== 'movement' || ($execution['sceneId'] ?? '') !== $context['sceneId']
            || ($execution['layerId'] ?? 'ground') !== $context['layerId'] || ($execution['sourceTokenId'] ?? '') !== $context['source']['id']) continue;
        $state = $execution['stepStates'][$step['id']] ?? [];
        if (!isset($state['origin'])) $state = initialApplicationComplexMovementState($context['source'], $context['now']);
        $updated = appendApplicationComplexMovementPath($state, $context['source'], $context['destination'], $context['path'] ?? null, $context['map'], $step['maximumMeters'], $context['now']);
        if ($updated !== $state) { $execution['stepStates'][$step['id']] = $updated; $execution['revision']++; $execution['updatedAt'] = $context['now']; }
    }
    unset($execution); return $executions;
}
