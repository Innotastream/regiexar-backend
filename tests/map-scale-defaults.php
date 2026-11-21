<?php
declare(strict_types=1);

ob_start();
require_once __DIR__ . '/tactical-lifecycle.php';
ob_end_clean();
require_once __DIR__ . '/../api/v1/map-scale-defaults.php';

$lobby = 'scene-lobby';
$deuillroute = 'scene-deuillroute';
$ordinary = 'scene-ordinary';
$baseLayer = ['gridSize' => 62, 'unitScalePreset' => 'custom', 'gridOffsetX' => 5, 'gridOffsetY' => -3,
    'background' => '/media/1234567890abcdef.webp', 'naturalWidth' => 1672, 'naturalHeight' => 941];
$map = [...$baseLayer, 'activeLayerId' => 'upper', 'zoom' => 1.5, 'panX' => 120, 'panY' => 10,
    'layers' => ['basement' => [...$baseLayer, 'background' => null], 'ground' => [...$baseLayer, 'gridSize' => 75, 'unitScalePreset' => 'indoor'], 'upper' => $baseLayer]];

$defaults = static fn(array $before, string $sceneId): array => applicationMapWithDefaultScalePresets($before, $sceneId, $lobby, $deuillroute);
requireTactical($defaults($map, $lobby) === $map, 'Lobby grid and all its map layers remain unmodified.');
foreach ([$ordinary, $deuillroute] as $sceneId) {
    $converted = $defaults($map, $sceneId);
    foreach ([$converted, ...array_values($converted['layers'])] as $index => $layer) {
        $indoor = $sceneId === $deuillroute && ($index === 0 || $index === 3);
        requireTactical($layer['unitScalePreset'] === ($indoor ? 'indoor' : 'outdoor') && $layer['gridSize'] == ($indoor ? 75 : 50), 'Each layer and the active mirror agree with its effective scale.');
        $expected = array_replace($index === 0 ? $map : array_values($map['layers'])[$index - 1], [
            'unitScalePreset' => $layer['unitScalePreset'], 'gridSize' => $layer['gridSize'],
        ]);
        if ($index === 0) unset($expected['layers'], $layer['layers']);
        requireTactical($layer === $expected, 'The map image, offset, dimensions and camera remain intact.');
    }
    requireTactical($defaults($converted, $sceneId) === $converted, 'Reapplying the same presets makes no changes.');
    $regular = ['id' => 'guard', 'sizeCategory' => 'normal'];
    requireTactical(applicationScaleUnitForMap($regular, $converted, 'ground')['size'] == 40, 'The ground floor receives an outdoor-size normal unit.');
    requireTactical(applicationScaleUnitForMap($regular, $converted, 'upper')['size'] == ($sceneId === $deuillroute ? 60 : 40),
        'The Deuillroute upper floor is the only layer receiving an indoor-size normal unit.');
}

$legacy = $baseLayer;
$legacyResult = $defaults($legacy, $deuillroute);
requireTactical($legacyResult['unitScalePreset'] === 'outdoor' && $legacyResult['gridSize'] == 50 && !isset($legacyResult['layers']), 'A historical flat map receives its ground preset without inventing floors.');

$fixture = fixture();
$fixture->put('scene-index', ['order' => [$lobby, $deuillroute, $ordinary]]);
foreach ([$lobby, $deuillroute, $ordinary, 'scene-orphan'] as $id) $fixture->put('map:' . $id, $map);
$fixture->put('token:' . $ordinary . ':garde', ['id' => 'garde', 'x' => 10.5, 'y' => 30.25, 'size' => 40, 'layerId' => 'ground']);
$changes = applicationDefaultMapScaleChanges(applicationDomainRecords($fixture), $lobby, $deuillroute);
requireTactical(array_column($changes, 'key') === ['map:' . $deuillroute, 'map:' . $ordinary], 'Only registered non-Lobby map documents are considered.');
foreach ($changes as $change) {
    $fixture->put($change['key'], $change['payload']);
}
requireTactical(applicationDefaultMapScaleChanges(applicationDomainRecords($fixture), $lobby, $deuillroute) === [], 'There is no second domain write at the same presets.');
requireTactical($fixture->payload('token:' . $ordinary . ':garde')['x'] === 10.5, 'No token positions or sizes are changed by the map update.');

echo "Map scale defaults verified.\n";
