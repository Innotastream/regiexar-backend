<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/v1/domains.php';

function requireDomainCompatibility(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$musicFolders = [[
    'id' => 'music-campaign',
    'name' => 'Campagne',
    'channel' => 'music',
    'createdAt' => '2026-08-28T00:00:00.000Z',
]];

requireDomainCompatibility(
    validApplicationAudioTracks([[
        'assetId' => 'ancienne-piste-locale',
        'name' => 'Ancienne piste',
    ]], $musicFolders),
    'Une piste historique non classée doit rester enregistrable.'
);
requireDomainCompatibility(
    validApplicationAudioTracks([[
        'id' => 'track-modern',
        'channel' => 'music',
        'folderId' => 'music-campaign',
    ]], $musicFolders),
    'Une piste classée dans un répertoire du même canal doit être acceptée.'
);
requireDomainCompatibility(
    !validApplicationAudioTracks([[
        'id' => 'track-wrong-channel',
        'channel' => 'ambience',
        'folderId' => 'music-campaign',
    ]], $musicFolders),
    'Une piste ne doit jamais rejoindre un répertoire de l’autre canal.'
);
requireDomainCompatibility(
    !validApplicationAudioTracks([[
        'id' => 'track-missing-folder',
        'channel' => 'music',
        'folderId' => 'unknown-folder',
    ]], $musicFolders),
    'Une piste ne doit jamais référencer un répertoire absent.'
);
requireDomainCompatibility(
    validApplicationTokenDomain([
        'id' => 'legacy-token',
        'resourcePulse' => null,
    ]),
    'Un token sans effet temporaire de ressource doit rester enregistrable.'
);
requireDomainCompatibility(
    !validApplicationTokenDomain([
        'id' => 'invalid-pulse-token',
        'resourcePulse' => ['resource' => 'hp', 'delta' => 1, 'at' => 1],
    ]),
    'Un effet temporaire non nul et incomplet doit rester refusé.'
);

$orderedToken = [
    'id' => 'legacy-token',
    'name' => 'Goldark',
    'resources' => ['hp' => 42, 'mana' => 7],
];
$reorderedToken = [
    'resources' => ['mana' => 7, 'hp' => 42],
    'resourcePulse' => null,
    'name' => 'Goldark',
    'id' => 'legacy-token',
];
requireDomainCompatibility(
    applicationDomainPayloadForComparison('token:scene-1:legacy-token', $reorderedToken)
        === applicationDomainPayloadForComparison('token:scene-1:legacy-token', $orderedToken),
    'L’ordre des propriétés et resourcePulse null ne doivent pas créer un faux changement de token.'
);

$legacyAudio = [
    'tracks' => [[
        'assetId' => 'ancienne-piste-locale',
        'name' => 'Ancienne piste',
    ]],
    'playback' => ['muted' => false, 'masterVolume' => 70],
];
$normalizedAudio = [
    'playback' => ['masterVolume' => 70, 'muted' => false],
    'folders' => [],
    'tracks' => [[
        'name' => 'Ancienne piste',
        'folderId' => null,
        'assetId' => 'ancienne-piste-locale',
    ]],
];
requireDomainCompatibility(
    applicationDomainPayloadForComparison('audio', $normalizedAudio)
        === applicationDomainPayloadForComparison('audio', $legacyAudio),
    'Les valeurs de classement médias absentes et leurs valeurs vides normalisées doivent être équivalentes.'
);

$currentCharacterRecord = [
    'revision' => 8,
    'payload' => [
        'id' => 'character-hira',
        'resources' => ['hp' => 42, 'maxHp' => 80, 'mana' => 69, 'maxMana' => 100],
        '_updatedAt' => 200,
    ],
];
$staleCharacter = [
    'id' => 'character-hira',
    'resources' => ['hp' => 0, 'maxHp' => 0, 'mana' => 0, 'maxMana' => 0],
    '_updatedAt' => 100,
];
requireDomainCompatibility(
    prepareApplicationDomainUpsert('character:character-hira', $staleCharacter, $currentCharacterRecord, true) === null,
    'Une ancienne vue MJ ne doit jamais remettre une fiche joueur récente à zéro.'
);
requireDomainCompatibility(
    prepareApplicationDomainUpsert('character:character-hira', $staleCharacter, $currentCharacterRecord) !== null,
    'Les commandes ciblées du serveur doivent rester capables de remplacer un document.'
);

$currentTokenRecord = [
    'revision' => 12,
    'payload' => [
        'id' => 'token-boss',
        'name' => 'Boss',
        'x' => 40,
        'y' => 50,
        'mana' => 69,
        'maxMana' => 100,
        '_updatedAt' => 300,
        '_movedAt' => 200,
    ],
];
$staleTokenWithNewMovement = [
    'id' => 'token-boss',
    'name' => 'Boss',
    'x' => 75,
    'y' => 80,
    'mana' => 0,
    'maxMana' => 0,
    '_updatedAt' => 100,
    '_movedAt' => 400,
];
$protectedToken = prepareApplicationDomainUpsert(
    'token:scene-1:token-boss',
    $staleTokenWithNewMovement,
    $currentTokenRecord,
    true
);
requireDomainCompatibility(
    is_array($protectedToken)
        && ($protectedToken['payload']['mana'] ?? null) === 69
        && ($protectedToken['payload']['maxMana'] ?? null) === 100
        && ($protectedToken['payload']['x'] ?? null) === 75
        && ($protectedToken['payload']['y'] ?? null) === 80
        && ($protectedToken['payload']['_movedAt'] ?? null) === 400,
    'Un ancien token ne doit pas effacer ses ressources, mais son déplacement plus récent doit rester accepté.'
);

$newTokenDataWithOldMovement = [
    'id' => 'token-boss',
    'name' => 'Boss renforcé',
    'x' => 5,
    'y' => 6,
    'mana' => 90,
    'maxMana' => 120,
    '_updatedAt' => 500,
    '_movedAt' => 100,
];
$protectedMovement = prepareApplicationDomainUpsert(
    'token:scene-1:token-boss',
    $newTokenDataWithOldMovement,
    $currentTokenRecord,
    true
);
requireDomainCompatibility(
    is_array($protectedMovement)
        && ($protectedMovement['payload']['mana'] ?? null) === 90
        && ($protectedMovement['payload']['x'] ?? null) === 40
        && ($protectedMovement['payload']['y'] ?? null) === 50
        && ($protectedMovement['payload']['_movedAt'] ?? null) === 200,
    'Une modification récente de fiche token ne doit pas ramener sa position à une coordonnée plus ancienne.'
);

fwrite(STDOUT, "Compatibilité rétroactive des domaines : OK" . PHP_EOL);
