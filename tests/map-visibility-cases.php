<?php

$visibilityFog = ['version' => 1, 'enabled' => false, 'width' => 32, 'height' => 32, 'mask' => ''];
$visibilityVision = normalizeApplicationVisionSettings(['enabled' => false]);
$visibilityMap = ['activeLayerId' => 'upper', 'gridSize' => 50, 'naturalWidth' => 2000, 'naturalHeight' => 1000,
    'layers' => [
        'ground' => ['naturalWidth' => 2000, 'naturalHeight' => 1000, 'fog' => $visibilityFog, 'vision' => [...$visibilityVision, 'enabled' => true]],
        'upper' => ['naturalWidth' => 2000, 'naturalHeight' => 1000, 'fog' => $visibilityFog, 'vision' => $visibilityVision],
    ],
    'tokens' => [
        ['id' => 'observer', 'name' => 'Observateur', 'layerId' => 'ground', 'x' => 20, 'y' => 50, 'controllerPlayerId' => 'account-player'],
        ['id' => 'secret', 'name' => 'Secret', 'layerId' => 'upper', 'x' => 90, 'y' => 50],
    ]];
$visibilityState = ['activeSceneId' => 'visibility', 'map' => $visibilityMap, 'initiative' => ['active' => false], 'characters' => [],
    'mapPings' => [['id' => 'secret-ping', 'sceneId' => 'visibility', 'layerId' => 'upper', 'x' => 90, 'y' => 50, 'expiresAt' => PHP_INT_MAX]]];
$visibilityIdentity = ['id' => 'account-player', 'display_name' => 'Joueur'];
$visibilityView = publicPlayerState($visibilityState, $visibilityIdentity, []);
requireTactical($visibilityView['map']['vision']['enabled'] === true && $visibilityView['map']['tokens'] === [] && $visibilityView['mapPings'] === [], 'A legacy disabled upper floor cannot expose its tokens or pings when vision is active downstairs.');
requireTactical(applicationVisionCoversPoint($visibilityView['map']['visionMask'], 50, 50), 'An upper floor without an observer remains concealed.');
$visibilityState['map']['tokens'][0]['layerId'] = 'upper';
$visibilityView = publicPlayerState($visibilityState, $visibilityIdentity, []);
requireTactical(array_column($visibilityView['map']['tokens'], 'id') === ['observer'], 'Moving the observer upstairs reveals only its own field of view.');
$visibilityState['map']['layers']['ground']['vision']['enabled'] = false;
$visibilityState['map']['layers']['ground']['fog']['enabled'] = true;
foreach ([0, 10, 50, 99, 100] as $point) {
    requireTactical(applicationFogCoversPoint(applicationActiveMapFogState($visibilityState['map']), $point, $point), 'A pristine upper floor starts fully concealed when fog is active downstairs.');
}
requireTactical(applicationActiveMapOcclusionState($visibilityState['map'])['fog'] === applicationActiveMapFogState($visibilityState['map']), 'Vision relays and public fog use the same protected mask.');
$visibilityView = publicPlayerState($visibilityState, $visibilityIdentity, []);
requireTactical(array_column($visibilityView['map']['tokens'], 'id') === ['observer'] && $visibilityView['mapPings'] === [], 'Fog also conceals public tokens and effects, while the owner keeps its own token.');
$visibilityState['map']['layers']['upper']['fog']['enabled'] = true;
requireTactical(!applicationFogCoversPoint(applicationActiveMapFogState($visibilityState['map']), 50, 50), 'An intentionally revealed upper-floor mask is preserved.');
$visibilityState['map']['layers']['ground']['fog']['enabled'] = false;
$visibilityState['map']['layers']['upper']['fog']['enabled'] = false;
requireTactical(applicationActiveMapFogState($visibilityState['map'])['enabled'] === false && !applicationMapVisionSettings($visibilityState['map'])['enabled'], 'Explicitly disabling all floors keeps the protections off.');
