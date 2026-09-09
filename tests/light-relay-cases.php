<?php

declare(strict_types=1);

$lightFixture = json_decode(file_get_contents(__DIR__ . '/fixtures/light-relay.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($lightFixture['cases'] as $case) {
    $occlusion = [
        ...$lightFixture['options'],
        'walls' => $case['walls'] ?? $lightFixture['walls'],
        'vision' => $lightFixture['vision'],
        'fog' => $case['fog'] ?? null,
        'lights' => $case['lights'],
    ];
    $resolved = applicationResolveVisionRelays($occlusion, $case['origins'], $lightFixture['options']['gridSize']);
    $render = applicationComputeVisionRenderMask($occlusion, $case['origins'], $lightFixture['options']['gridSize']);
    requireTactical($render['mask'] === $resolved['mask']['mask'], $case['name'] . ': halo never changes the strict mask.');
    foreach ($case['points'] as $point) {
        requireTactical(!applicationVisionCoversPoint($render, $point['x'], $point['y']) === $point['visible'], $case['name'] . ': strict visibility at ' . $point['x'] . ',' . $point['y']);
    }
    $reordered = applicationComputeVisionMask([...$occlusion, 'lights' => array_reverse($case['lights'])], $case['origins'], 50);
    requireTactical($reordered['mask'] === $render['mask'], $case['name'] . ': light insertion order does not affect visibility.');
    requireTactical(hash('sha256', base64_decode(strtr($render['mask'], '-_', '+/'))) === $case['strictSha256'], $case['name'] . ': PHP/JS strict mask byte parity.');
    requireTactical(hash('sha256', base64_decode(strtr($render['opacity'], '-_', '+/'))) === $case['opacitySha256'], $case['name'] . ': PHP/JS halo byte parity.');
    if ($case['name'] === 'quarante-origines-et-quarante-relais') {
        requireTactical(count($resolved['origins']) === 80 && count($resolved['activatedLightIds']) === 40, 'Forty initial origins and forty relays keep independent budgets.');
    }
    if (in_array($case['name'], ['halo-ne-declenche-pas', 'aucune-origine', 'lumiere-dans-mur', 'brouillard-interdit-relais', 'lumiere-desactivee'], true)) {
        requireTactical($resolved['activatedLightIds'] === [], $case['name'] . ': no relay activates from an unauthorized center.');
    }
}

$light = ['id' => 'torch', 'name' => 'Torche', 'x' => 20, 'y' => 50];
requireTactical(normalizeApplicationMapLights([[...$light, 'name' => '  Torche  ']])[0]['name'] === 'Torche', 'Light labels normalize their surrounding spaces like the client.');
requireTactical(validApplicationMapLights([$light]) && normalizeApplicationMapLights([$light])[0]['visionDistance'] === 8 && normalizeApplicationMapLights([$light])[0]['enabled'], 'Lights default to eight cells and enabled.');
requireTactical(validApplicationMapLights([[...$light, 'name' => str_repeat('é', 80)]]) && !validApplicationMapLights([[...$light, 'name' => str_repeat('é', 81)]]), 'The eighty-character name limit accepts French accents without counting UTF-8 bytes as characters.');
$invalidLists = [[$light, $light], ['torch' => $light], array_fill(0, 41, $light)];
foreach ([['id' => '../bad'], ['id' => str_repeat('a', 81)], ['name' => ''], ['name' => '   '], ['name' => str_repeat('a', 81)], ['name' => "bad\0name"], ['x' => -1], ['y' => 101], ['x' => '20'], ['x' => INF], ['visionDistance' => 0], ['visionDistance' => 41], ['visionDistance' => 1.5], ['enabled' => 'true']] as $invalid) {
    $invalidLists[] = [[...$light, ...$invalid]];
}
foreach ($invalidLists as $invalid) {
    requireTactical(!validApplicationMapLights($invalid) && !validApplicationMapFogState(['lights' => $invalid]), 'Invalid light collections refuse the complete map alias.');
    requireTactical(!validApplicationMapFogState(['lights' => [$light], 'layers' => ['ground' => ['lights' => [$light]], 'upper' => ['lights' => $invalid]]]), 'An invalid inactive layer refuses the whole map atomically.');
}
try {
    validatedDomainPayload('map:scene-one', ['lights' => [$light], 'layers' => ['upper' => ['lights' => [[...$light, 'x' => 101]]]]]);
    throw new RuntimeException('Invalid lights passed domain validation.');
} catch (TestResponse $response) {
    requireTactical($response->status === 400 && $response->body['code'] === 'invalid_map_fog', 'The actual domain write validator refuses invalid inactive-layer lights.');
}

$chain = $lightFixture['cases'][0];
$map = [...$lightFixture['options'], 'activeLayerId' => 'ground', 'walls' => $lightFixture['walls'], 'vision' => $lightFixture['vision'], 'lights' => $chain['lights']];
$activeOcclusion = applicationActiveMapOcclusionState([...$map, 'layers' => ['ground' => ['lights' => []], 'upper' => ['lights' => $chain['lights']]]]);
requireTactical($activeOcclusion['lights'] === [], 'An explicitly empty active level never inherits another level or stale light alias.');
requireTactical(applicationActiveMapOcclusionState([...$map, 'activeLayerId' => 'upper', 'layers' => ['upper' => ['lights' => [$light], 'fog' => $lightFixture['cases'][5]['fog']]]])['fog'] === $lightFixture['cases'][5]['fog'], 'Relay occlusion uses the selected level fog.');

$map['lights'][0]['gmNotes'] = 'private light metadata';
$map['lights'][] = ['id' => 'off', 'name' => 'Éteinte', 'x' => 20, 'y' => 50, 'enabled' => false];
$map['lights'][] = ['id' => 'distant', 'name' => 'Lointaine', 'x' => 80, 'y' => 50, 'visionDistance' => 2];
$map['layers'] = ['upper' => ['lights' => [['id' => 'upper', 'name' => 'Étage', 'x' => 20, 'y' => 50]]]];
$map['tokens'] = [
    ['id' => 'observer', 'controllerPlayerId' => 'account-player', 'x' => 10, 'y' => 50, 'visionDistance' => 3],
    ['id' => 'relay-visible', 'x' => 50, 'y' => 50],
    ['id' => 'halo', 'x' => 65, 'y' => 50],
];
$state = ['activeSceneId' => 'scene-one', 'map' => $map, 'initiative' => [], 'characters' => [], 'rolls' => [
    ['id' => 'visible-roll', 'visibility' => 'public', 'mapEvent' => ['kind' => 'roll', 'sceneId' => 'scene-one', 'tokenId' => 'relay-visible']],
    ['id' => 'halo-roll', 'visibility' => 'public', 'mapEvent' => ['kind' => 'roll', 'sceneId' => 'scene-one', 'tokenId' => 'halo']],
]];
$view = publicPlayerState($state, ['id' => 'account-player', 'display_name' => 'Player'], []);
requireTactical(array_column($view['map']['lights'], 'id') === ['a', 'b'], 'Only enabled visible lights from the published level reach the player.');
requireTactical(array_keys($view['map']['lights'][1]) === ['id', 'name', 'x', 'y', 'visionDistance', 'enabled', 'portable', 'carrierTokenId', 'color', 'icon'], 'Light projection has exactly ten safe public fields.');
requireTactical(array_column($view['map']['tokens'], 'id') === ['observer', 'relay-visible'] && array_column($view['rolls'], 'id') === ['visible-roll'], 'Relay vision reveals targets and their events while the halo cannot.');
$view = publicPlayerState($state, ['id' => 'other-account', 'display_name' => 'Other'], []);
requireTactical($view['map']['lights'] === [] && $view['map']['tokens'] === [], 'Another player cannot borrow a private relay chain without an origin.');
$shared = $state;
$shared['map']['vision']['shared'] = true;
$view = publicPlayerState($shared, ['id' => 'other-account', 'display_name' => 'Other'], []);
requireTactical(array_column($view['map']['lights'], 'id') === ['a', 'b'], 'Shared vision can activate the common relay chain.');
$shared['map']['vision']['isolatedPlayerIds'] = ['other-account'];
$view = publicPlayerState($shared, ['id' => 'other-account', 'display_name' => 'Other'], []);
requireTactical($view['map']['lights'] === [] && $view['map']['tokens'] === [], 'An isolated player cannot borrow shared light relays.');
$paused = $state;
$paused['tacticalSync'] = ['paused' => true, 'publishedMap' => [...$map, 'lights' => []], 'publishedInitiative' => [], 'publishedActiveScene' => ['id' => 'scene-published']];
$view = publicPlayerState($paused, ['id' => 'account-player', 'display_name' => 'Player'], []);
requireTactical($view['map']['lights'] === [] && array_column($view['map']['tokens'], 'id') === ['observer'], 'A secret prepared scene never leaks its lights into the paused published scene.');

// The same strict wrapper guards the server attack path, including revalidation.
$db = fixture();
unset($db->domains['token:scene-one:token-independent']);
$character = $db->payload('character:character-player');
$character['visionDistance'] = 3;
$db->put('character:character-player', $character);
$observer = $db->payload('token:scene-one:token-player');
$observer['x'] = 10;
$db->put('token:scene-one:token-player', $observer);
$db->put('map:scene-one', $map);
$target = $db->payload('token:scene-one:token-monster');
$records = $db->domains;
requireTactical(onlineAttackTargetVisible($db, $records, $map, $target, 'account-player', 'scene-one'), 'Attack target visibility accepts the same relay chain as player projection.');
$darkMap = [...$map, 'lights' => []];
$records = $db->domains;
requireTactical(!onlineAttackTargetVisible($db, $records, $darkMap, $target, 'account-player', 'scene-one'), 'Removing a relay immediately revokes target visibility.');
$db->put('map:scene-one', $darkMap);
$records = $db->domains;
try {
    onlinePendingAttackParties($db, $records, ['sceneId' => 'scene-one', 'sourceTokenId' => 'token-player', 'targetTokenId' => 'token-monster', 'accountId' => 'account-player']);
    throw new RuntimeException('A pending attack kept visibility after relay removal.');
} catch (TestResponse $response) {
    requireTactical($response->status === 409 && $response->body['code'] === 'attack_target_hidden', 'Pending attacks revalidate light removal before the next phase.');
}
