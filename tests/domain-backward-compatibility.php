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

fwrite(STDOUT, "Compatibilité rétroactive des domaines : OK" . PHP_EOL);
