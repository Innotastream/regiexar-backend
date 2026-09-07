<?php

declare(strict_types=1);

// Same 48-pixel corridor and natural coordinates as the JavaScript regression.
$sizeWalls = ['version' => 1, 'width' => 512, 'height' => 512, 'mask' => ''];
$sizeWallBytes = str_repeat("\0", 32768);
foreach ([243, 268] as $row) {
    for ($column = 153; $column <= 358; $column++) {
        $index = $row * 512 + $column;
        $byteIndex = $index >> 3;
        $sizeWallBytes[$byteIndex] = chr(ord($sizeWallBytes[$byteIndex]) | (1 << ($index & 7)));
    }
}
$sizeWalls['mask'] = rtrim(strtr(base64_encode($sizeWallBytes), '+/', '-_'), '=');
$sizeMap = ['naturalWidth' => 1000, 'naturalHeight' => 1000, 'walls' => $sizeWalls];
$defaultSizeMove = applicationResolveWallCollision($sizeWalls, ['x' => 20, 'y' => 50], ['x' => 80, 'y' => 50], null, 1000, 1000);
$oldSizeMove = applicationResolveWallCollision($sizeWalls, ['x' => 20, 'y' => 50], ['x' => 80, 'y' => 50], 50, 1000, 1000);
requireTactical(!$defaultSizeMove['blocked'] && $defaultSizeMove['x'] === 80.0, 'The default 40-pixel token can traverse the narrow corridor.');
requireTactical($oldSizeMove['blocked'] && $oldSizeMove['x'] > 20 && $oldSizeMove['x'] < 30, 'An explicit existing size of 50 remains blocked by the same corridor.');
requireTactical(onlineGroupTokenSize([]) === 40.0, 'New group tokens use a diameter of 40.');
foreach ([20, 30, 40, 50, 60, 62, 70, 80, 100, 137] as $savedSize) {
    requireTactical(onlineGroupTokenSize(['size' => $savedSize]) === (float) $savedSize, 'Explicit saved token sizes remain intact: ' . $savedSize);
}
$sizeToken = ['id' => 'size-default', 'x' => 20, 'y' => 50];
$sizePosition = nearestOnlineGroupPosition(['x' => 50, 'y' => 50], $sizeToken, $sizeMap, [], $sizeToken);
requireTactical($sizePosition !== null && $sizePosition['x'] == 50 && $sizePosition['y'] == 50, 'A group movement uses the same 40-pixel collision radius.');
$sizeTranslation = onlineGroupTranslation([$sizeToken], -100, 0, $sizeMap);
requireTactical($sizeTranslation['x'] === -18.0, 'Group bounds leave 20 natural pixels around the default center.');
$sizeState = ['activeSceneId' => 'scene-one', 'characters' => [], 'map' => ['tokens' => [
    ['id' => 'size-default', 'x' => 20, 'y' => 50],
    ['id' => 'size-existing', 'x' => 60, 'y' => 50, 'size' => 62]
]], 'initiative' => []];
$sizeProjection = publicPlayerState($sizeState, ['id' => 'account-player', 'display_name' => 'Player'], []);
$projectedSizes = array_column($sizeProjection['map']['tokens'], 'size', 'id');
requireTactical(($projectedSizes['size-default'] ?? null) === 40.0 && ($projectedSizes['size-existing'] ?? null) === 62.0, 'The public projection uses 40 only when size is absent.');
