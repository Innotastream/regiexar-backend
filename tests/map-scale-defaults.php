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
    requireTactical(applicationScaleUnitForMap($regular, $converted, 'ground')['size'] == 50, 'Before migration 29 the historical ground grid is still 50.');
    requireTactical(applicationScaleUnitForMap($regular, $converted, 'upper')['size'] == ($sceneId === $deuillroute ? 75 : 50),
        'Before migration 29 the historical Deuillroute upper grid is still 75.');
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

foreach ([$lobby=>' Lobby ', $deuillroute=>'Village de Deuilroute', $ordinary=>'Salle'] as $id=>$name) {
    $fixture->put('scene:'.$id,['metadata'=>['name'=>$name]]);
}
$oldOrdinary = $fixture->payload('map:'.$ordinary);
$oldOrdinary['layers']['basement'] = [...$baseLayer,'gridSize'=>20,'unitScaleAuto'=>true];
$oldOrdinary['layers']['ground']['unitScaleAuto'] = false;
$fixture->put('map:'.$ordinary,$oldOrdinary);
$oldToken = $fixture->payload('token:'.$ordinary.':garde');
$updatedGrids = applicationUpdatedPresetGridChanges(applicationDomainRecords($fixture));
requireTactical(array_column($updatedGrids,'key')===['map:'.$deuillroute,'map:'.$ordinary],
    'The second migration touches only registered maps with a real previous preset, excluding Lobby.');
foreach ($updatedGrids as $change) $fixture->put($change['key'],$change['payload']);
$converted = $fixture->payload('map:'.$deuillroute);
requireTactical($converted['gridSize']===60&&$converted['layers']['upper']['gridSize']===60
    &&$converted['layers']['ground']['gridSize']===40,'Interior and exterior defaults convert in each layer and the active mirror.');
$converted = $fixture->payload('map:'.$ordinary);
requireTactical($converted['gridSize']===40&&$converted['layers']['ground']['gridSize']===40
    &&$converted['layers']['ground']['unitScaleAuto']===false&&$converted['layers']['basement']==$oldOrdinary['layers']['basement'],
    'A custom grid, the per-level flag and unrelated map fields survive the migration.');
requireTactical($fixture->payload('map:'.$lobby)===$map&&$fixture->payload('token:'.$ordinary.':garde')===$oldToken,
    'Lobby and every stored token record stay untouched.');
requireTactical(applicationUpdatedPresetGridChanges(applicationDomainRecords($fixture))===[],'The preset migration is idempotent.');

$currentMap = [...$map, 'gridSize'=>25, 'unitScalePreset'=>'wide', 'unitScaleAuto'=>true,
    'layers'=>['ground'=>[...$baseLayer, 'gridSize'=>40, 'unitScalePreset'=>'outdoor', 'unitScaleAuto'=>true],
        'upper'=>[...$baseLayer, 'gridSize'=>25, 'unitScalePreset'=>'wide', 'unitScaleAuto'=>true],
        'basement'=>[...$baseLayer, 'gridSize'=>20, 'unitScaleProfile'=>'wide', 'unitScaleAuto'=>false]]];
// The 4.4.5 reader labels 25/40/60 px grids custom and drops both new keys.
$oldEdit = $currentMap;
unset($oldEdit['unitScaleAuto']);
$oldEdit['unitScalePreset'] = 'custom';
$oldEdit['gridOffsetX'] = 12;
foreach ($oldEdit['layers'] as &$level) {
    $level['unitScalePreset'] = 'custom';
    unset($level['unitScaleAuto'], $level['unitScaleProfile']);
}
unset($level);
$oldEdit['layers']['upper']['gridOffsetX'] = 12;
$_SERVER['HTTP_X_XAR_CLIENT_VERSION'] = '4.4.5';
$kept = protectApplicationDomainAgainstStaleEntityWrite('map:'.$ordinary, $oldEdit, ['payload'=>$currentMap]);
requireTactical($kept['unitScalePreset']==='wide' && $kept['unitScaleAuto']===true
    && $kept['layers']['ground']['unitScalePreset']==='outdoor' && $kept['layers']['ground']['unitScaleAuto']===true
    && $kept['layers']['basement']['unitScaleAuto']===false && $kept['layers']['basement']['unitScaleProfile']==='wide'
    && $kept['layers']['upper']['gridOffsetX']===12, 'A legacy map edit keeps the authoritative settings on every unchanged grid.');
$oldEdit['activeLayerId']='ground';
$oldEdit['gridSize']=40;
$otherFloor = protectApplicationDomainAgainstStaleEntityWrite('map:'.$ordinary, $oldEdit, ['payload'=>$currentMap]);
requireTactical($otherFloor['unitScalePreset']==='outdoor' && $otherFloor['unitScaleAuto']===true,
    'A legacy save mirrors the settings of its selected floor.');
$oldEdit['gridSize']=35;
$oldEdit['layers']['ground']['gridSize']=35;
$changed = protectApplicationDomainAgainstStaleEntityWrite('map:'.$ordinary, $oldEdit, ['payload'=>$currentMap]);
requireTactical($changed['gridSize']===35 && $changed['unitScalePreset']==='custom'
    && !isset($changed['unitScaleAuto']) && $changed['layers']['upper']['unitScalePreset']==='wide',
    'A legacy client can still intentionally alter its active grid without losing the settings of other floors.');
$_SERVER['HTTP_X_XAR_CLIENT_VERSION'] = '4.4.6';
$modern = protectApplicationDomainAgainstStaleEntityWrite('map:'.$ordinary, [...$currentMap, 'unitScaleAuto'=>false], ['payload'=>$currentMap]);
requireTactical($modern['unitScaleAuto']===false, 'The current client can explicitly turn off automatic sizing.');
unset($_SERVER['HTTP_X_XAR_CLIENT_VERSION']);

echo "Map scale defaults verified.\n";
