<?php

declare(strict_types=1);

// Exercise the actual command path with fixture identities and storage only.
$placementWalls = ['version' => 1, 'width' => 512, 'height' => 512, 'mask' => ''];
$placementWallBytes = str_repeat("\0", 32768);
for ($row = 0; $row < 512; $row++) {
    $index = $row * 512 + 255;
    $placementWallBytes[$index >> 3] = chr(ord($placementWallBytes[$index >> 3]) | (1 << ($index & 7)));
}
$placementWalls['mask'] = rtrim(strtr(base64_encode($placementWallBytes), '+/', '-_'), '=');
$placementMap = ['activeLayerId' => 'ground', 'viewLocked' => true, 'naturalWidth' => 1000, 'naturalHeight' => 1000, 'walls' => $placementWalls];
foreach (['token-player', 'token-monster'] as $placementId) {
    $db = fixture();
    $db->put('map:scene-one', $placementMap);
    $db->put('initiative:scene-one', ['active' => false]);
    $placementToken = $db->payload('token:scene-one:' . $placementId);
    $placementToken['x'] = 20;
    $placementToken['size'] = 40;
    $db->put('token:scene-one:' . $placementId, $placementToken);
    $response = runCommand($db, 'token.move', ['sceneId' => 'scene-one', 'tokenId' => $placementId, 'x' => 80, 'y' => 50], true, 'account-gm');
    requireTactical($response->status === 200 && $response->body['token']['x'] === 80.0 && !$response->body['blockedByWall'], 'MJ crosses a wall when placing either a player or creature token: ' . $placementId);
    $placementRevision = $db->revision;
    $placementSavedToken = $db->payload('token:scene-one:' . $placementId);
    foreach ([50, 48.5] as $placementX) {
        $response = runCommand($db, 'token.move', ['sceneId' => 'scene-one', 'tokenId' => $placementId, 'x' => $placementX, 'y' => 50], true, 'account-gm');
        $placementActualX = $response->body['token']['x'] ?? null;
        // JSON storage returns 80 as an integer; an unchanged position must not
        // require that the pre-storage floating-point PHP type survives a read.
        requireTactical($response->status === 200 && (is_int($placementActualX) || is_float($placementActualX))
            && (float) $placementActualX === 80.0 && ($response->body['blockedByWall'] ?? false) === true
            && ($response->body['positionChanged'] ?? true) === false && $db->revision === $placementRevision
            && $db->payload('token:scene-one:' . $placementId) === $placementSavedToken,
            'A final wall or overlapping footprint does not change authoritative position: ' . json_encode([
                'token' => $placementId, 'requestedX' => $placementX, 'status' => $response->status,
                'actualX' => $placementActualX, 'actualType' => gettype($placementActualX),
                'blocked' => $response->body['blockedByWall'] ?? null,
                'positionChanged' => $response->body['positionChanged'] ?? null,
                'revision' => $db->revision, 'expectedRevision' => $placementRevision,
            ], JSON_UNESCAPED_UNICODE));
    }
}
$db = fixture();
$db->put('map:scene-one', $placementMap);
$db->put('initiative:scene-one', ['active' => false]);
$response = runCommand($db, 'token.move', ['sceneId' => 'scene-one', 'tokenId' => 'token-player', 'x' => 80, 'y' => 50, 'isGm' => true]);
requireTactical($response->status === 200 && $response->body['blockedByWall'] && $response->body['token']['x'] > 20 && $response->body['token']['x'] < 50, 'An effective Player cannot bypass the travelled wall path with a request flag.');
requireTactical(runCommand($db, 'token.move', ['sceneId' => 'scene-one', 'tokenId' => 'token-monster', 'x' => 80, 'y' => 50])->status === 403, 'Player ownership restrictions remain authoritative.');

$db = fixture();
$db->put('map:scene-one', $placementMap);
$placementPlayer = $db->payload('token:scene-one:token-player'); $placementPlayer['x'] = 20; $placementPlayer['y'] = 30; $placementPlayer['size'] = 40; $db->put('token:scene-one:token-player', $placementPlayer);
$placementMonster = $db->payload('token:scene-one:token-monster'); $placementMonster['x'] = 30; $placementMonster['y'] = 60; $placementMonster['size'] = 80; $db->put('token:scene-one:token-monster', $placementMonster);
$response = runCommand($db, 'tokens.transform', groupArguments($db, ['dx' => 30, 'dy' => 0]), true, 'account-gm');
requireTactical($response->status === 200, 'The MJ group command accepts the placement across a wall.');
$placementWallFree = onlineGroupPositionValidator($placementMap);
foreach (['token-player', 'token-monster'] as $placementId) {
    $placementToken = $db->payload('token:scene-one:' . $placementId);
    requireTactical($placementWallFree($placementToken, $placementToken), 'Every final group footprint is clear of the wall.');
}
requireTactical($db->payload('token:scene-one:token-monster')['x'] == 60, 'A clear destination across the wall preserves the intended group position.');
