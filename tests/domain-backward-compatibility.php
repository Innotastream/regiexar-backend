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
    prepareApplicationDomainUpsert('token:scene-1:legacy-token', $reorderedToken, [
        'payload' => $orderedToken,
        'revision' => 12,
    ]) === null,
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
    prepareApplicationDomainUpsert('audio', $normalizedAudio, [
        'payload' => $legacyAudio,
        'revision' => 8,
    ]) === null,
    'Les valeurs de classement médias absentes et leurs valeurs vides normalisées doivent être équivalentes.'
);

fwrite(STDOUT, "Compatibilité rétroactive des domaines : OK" . PHP_EOL);
