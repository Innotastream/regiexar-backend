<?php

declare(strict_types=1);

// Included by tactical-lifecycle.php: environment rules and the real online
// light-carry transaction, without network or production storage.
requireTactical(normalizeApplicationMapLightingMode('dark') === 'dark'
    && normalizeApplicationMapLightingMode('unknown') === 'normal', 'Lighting modes normalize without an unsafe fallback.');
requireTactical(normalizeApplicationDarkVision('dim') === 'dim'
    && normalizeApplicationDarkVision('unknown') === 'none', 'Dark vision modes normalize without inventing a capability.');
requireTactical(applicationEffectiveVisionDistance(13, 'dark', 'none') === 4
    && applicationEffectiveVisionDistance(13, 'dark', 'dim') === 4
    && applicationEffectiveVisionDistance(13, 'dark', 'full') === 8
    && applicationEffectiveVisionDistance(13, 'normal', 'full') === 13
    && applicationEffectiveVisionDistance(2, 'bright', 'none') === 16,
    'Dark, normal, bright and full dark vision use their exact authoritative distances.');
requireTactical(applicationVisionFadeDistance(4) === 3
    && applicationVisionFadeDistance(16) === 12
    && applicationRemoteLightDetectionDistance(8, 'dark') === 24
    && applicationRemoteLightDetectionDistance(8, 'normal') === 24
    && applicationRemoteLightDetectionDistance(8, 'bright') === 8,
    'Fade and remote light detection remain proportional and bounded.');

function lightingCarryMap(array $light): array
{
    return [
        'activeLayerId' => 'ground',
        'gridSize' => 50,
        'naturalWidth' => 1000,
        'naturalHeight' => 1000,
        'lightingMode' => 'dark',
        'layers' => [
            'basement' => ['naturalWidth' => 1000, 'naturalHeight' => 1000, 'lightingMode' => 'normal', 'lights' => []],
            'ground' => ['naturalWidth' => 1000, 'naturalHeight' => 1000, 'lightingMode' => 'dark', 'lights' => [$light]],
            'upper' => ['naturalWidth' => 1000, 'naturalHeight' => 1000, 'lightingMode' => 'bright', 'lights' => []],
        ],
        'lights' => [$light],
    ];
}

function lightingFixture(array $overrides = []): MemoryConnection
{
    $db = fixture();
    $light = array_replace([
        'id' => 'portable-light', 'name' => 'Torche portable', 'x' => 20.0, 'y' => 50.0,
        'visionDistance' => 8, 'enabled' => true, 'portable' => true, 'carrierTokenId' => null,
        'color' => '#ffd36a', 'icon' => 'torch',
    ], $overrides);
    $db->put('map:scene-one', lightingCarryMap($light));
    return $db;
}

$normalizedLights = normalizeApplicationMapLights([[
    'id' => 'styled-light', 'name' => '  Feu follet  ', 'x' => 10, 'y' => 20,
    'portable' => true, 'carrierTokenId' => 'token-player', 'color' => '#AABBCC', 'icon' => 'wisp',
]]);
requireTactical(count($normalizedLights) === 1
    && $normalizedLights[0]['visionDistance'] === 8
    && $normalizedLights[0]['color'] === '#aabbcc'
    && $normalizedLights[0]['icon'] === 'wisp'
    && $normalizedLights[0]['carrierTokenId'] === 'token-player',
    'Historical lights receive range eight while style and carrier remain explicit.');
requireTactical(!validApplicationMapLights([[
    'id' => 'forged-light', 'name' => 'Fausse', 'x' => 10, 'y' => 20,
    'portable' => false, 'carrierTokenId' => 'token-player',
]]), 'A non-portable light can never forge a carrier.');

$db = lightingFixture();
$take = [
    'requestId' => 'light-carry-request-0001', 'sceneId' => 'scene-one', 'layerId' => 'ground',
    'lightId' => 'portable-light', 'tokenId' => 'token-player', 'carry' => true,
];
$response = runCommand($db, 'light.carry', $take);
$carried = $db->payload('map:scene-one')['layers']['ground']['lights'][0];
requireTactical($response->status === 200 && !($response->body['deduplicated'] ?? true)
    && $carried['carrierTokenId'] === 'token-player'
    && (float) $carried['x'] === 20.0 && (float) $carried['y'] === 50.0,
    'A player can take one portable light at contact: ' . $response->getMessage());
$revision = $db->revision;
$response = runCommand($db, 'light.carry', $take);
requireTactical($response->status === 200 && ($response->body['deduplicated'] ?? false)
    && $db->revision === $revision, 'Replaying the same light receipt is an exact no-op.');
$mismatch = runCommand($db, 'light.carry', [...$take, 'carry' => false]);
requireTactical($mismatch->status === 409 && ($mismatch->body['code'] ?? '') === 'light_receipt_mismatch',
    'A light receipt cannot be reused for a different operation.');
$foreign = runCommand($db, 'light.carry', [
    ...$take, 'requestId' => 'foreign-light-drop-0001', 'carry' => false,
], false, 'account-intruder');
requireTactical($foreign->status === 403 && ($foreign->body['code'] ?? '') === 'token_forbidden',
    'A foreign player cannot drop another character light.');
$token = $db->payload('token:scene-one:token-player');
$token['x'] = 31.0; $token['y'] = 44.0; $db->put('token:scene-one:token-player', $token, $db->revision);
$response = runCommand($db, 'light.carry', [
    ...$take, 'requestId' => 'light-drop-request-0001', 'carry' => false,
]);
$dropped = $db->payload('map:scene-one')['layers']['ground']['lights'][0];
requireTactical($response->status === 200 && $dropped['carrierTokenId'] === null
    && (float) $dropped['x'] === 31.0 && (float) $dropped['y'] === 44.0,
    'Dropping a light persists the current authoritative token position.');
requireTactical(count($db->payload('activity')['playerActions']) === 2,
    'Only the successful take and drop are recorded in the private action audit.');

$db = lightingFixture(['x' => 80.0]);
$before = $db->domains; $beforeRevision = $db->revision;
$response = runCommand($db, 'light.carry', $take);
requireTactical($response->status === 409 && ($response->body['code'] ?? '') === 'light_out_of_reach'
    && $db->domains === $before && $db->revision === $beforeRevision,
    'A distant light changes neither map, receipt nor revision.');
$db = lightingFixture(['portable' => false]);
requireTactical(runCommand($db, 'light.carry', $take)->status === 403,
    'Only a light explicitly marked portable can be taken.');
$db = lightingFixture();
$player = $db->payload('token:scene-one:token-player'); $player['hp'] = 0; $db->put('token:scene-one:token-player', $player);
$character = $db->payload('character:character-player'); $character['resources']['hp'] = 0; $db->put('character:character-player', $character);
requireTactical((runCommand($db, 'light.carry', $take)->body['code'] ?? '') === 'light_carrier_incapacitated',
    'A defeated unit cannot pick up a light.');

$db = lightingFixture(['x' => 50.0]);
$creatureTake = [
    'requestId' => 'creature-light-take-01', 'sceneId' => 'scene-one', 'layerId' => 'ground',
    'lightId' => 'portable-light', 'tokenId' => 'token-monster', 'carry' => true,
];
$response = runCommand($db, 'light.carry', $creatureTake, true, 'account-gm');
requireTactical($response->status === 200
    && $db->payload('map:scene-one')['layers']['ground']['lights'][0]['carrierTokenId'] === 'token-monster',
    'The GM can make a creature carry a portable light.');
$monster = $db->payload('token:scene-one:token-monster');
$monster['x'] = 57.0; $monster['y'] = 62.0; $db->put('token:scene-one:token-monster', $monster, $db->revision);
$response = runCommand($db, 'token.resource.adjust', [
    'requestId' => 'kill-light-carrier-001', 'sceneId' => 'scene-one', 'tokenId' => 'token-monster',
    'resource' => 'hp', 'delta' => -40,
], true, 'account-gm');
$fallen = $db->payload('map:scene-one')['layers']['ground']['lights'][0];
requireTactical($response->status === 200 && $fallen['carrierTokenId'] === null
    && (float) $fallen['x'] === 57.0 && (float) $fallen['y'] === 62.0,
    'At zero HP a creature drops its light exactly at its live position.');

$map = lightingCarryMap([
    'id' => 'cross-floor-light', 'name' => 'Lanterne', 'x' => 10.0, 'y' => 10.0,
    'visionDistance' => 8, 'enabled' => true, 'portable' => true, 'carrierTokenId' => 'token-player',
    'color' => '#ffaa33', 'icon' => 'orb',
]);
$tokens = [['id' => 'token-player', 'layerId' => 'upper', 'x' => 73.0, 'y' => 29.0, 'hp' => 1, 'maxHp' => 10]];
requireTactical(applicationMapLightsForLayer($map, 'ground', $tokens) === []
    && (float) applicationMapLightsForLayer($map, 'upper', $tokens)[0]['x'] === 73.0
    && (float) applicationMapLightsForLayer($map, 'upper', $tokens)[0]['y'] === 29.0,
    'A carried light follows its unit across floors and never remains projected below.');
