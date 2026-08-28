<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/v1/domains.php';
require_once __DIR__ . '/../api/v1/online.php';

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
    protectApplicationDomainAgainstStaleEntityWrite(
        'character:character-hira',
        $staleCharacter,
        $currentCharacterRecord
    ) === $currentCharacterRecord['payload'],
    'Une ancienne vue MJ ne doit jamais remettre une fiche joueur récente à zéro.'
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
$protectedToken = protectApplicationDomainAgainstStaleEntityWrite(
    'token:scene-1:token-boss',
    $staleTokenWithNewMovement,
    $currentTokenRecord
);
requireDomainCompatibility(
    is_array($protectedToken)
        && ($protectedToken['mana'] ?? null) === 69
        && ($protectedToken['maxMana'] ?? null) === 100
        && ($protectedToken['x'] ?? null) === 75
        && ($protectedToken['y'] ?? null) === 80
        && ($protectedToken['_movedAt'] ?? null) === 400,
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
$protectedMovement = protectApplicationDomainAgainstStaleEntityWrite(
    'token:scene-1:token-boss',
    $newTokenDataWithOldMovement,
    $currentTokenRecord
);
requireDomainCompatibility(
    is_array($protectedMovement)
        && ($protectedMovement['mana'] ?? null) === 90
        && ($protectedMovement['x'] ?? null) === 40
        && ($protectedMovement['y'] ?? null) === 50
        && ($protectedMovement['_movedAt'] ?? null) === 200,
    'Une modification récente de fiche token ne doit pas ramener sa position à une coordonnée plus ancienne.'
);

$wholeCharacterPatch = [
    'name' => 'Hira', 'surname' => '', 'givenName' => '', 'race' => '', 'age' => '',
    'className' => '', 'advancedClass' => '', 'profession' => '', 'previousProfession' => '',
    'pronouns' => '', 'portrait' => null, 'color' => '#8d72cb',
    'resources' => ['hp' => 0, 'maxHp' => 0, 'mana' => 0, 'maxMana' => 0],
    'stats' => [], 'fatigue' => ['current' => 40, 'max' => 100], 'morale' => '',
    'armor' => 0, 'speed' => 0, 'initiativeBonus' => 0, 'conditions' => [],
    'publicNotes' => '', 'armorText' => '', 'hitThreshold' => null, 'weaponText' => '',
    'passives' => '', 'skills' => '', 'specialSkills' => '', 'languages' => '',
    'inventory' => '', 'personalAdvantageStock' => 1, 'shortcuts' => [],
    'abilities' => [], 'linkedTokens' => [],
];
$currentHira = [
    ...$wholeCharacterPatch,
    'id' => 'character-hira',
    'ownerPlayerId' => 'player-hira',
    'resources' => ['hp' => 42, 'maxHp' => 80, 'mana' => 69, 'maxMana' => 100],
    'characterSchema' => 'xar-tsaroth.character-sheet',
    'characterSchemaVersion' => 3,
    '_updatedAt' => 500,
];
requireDomainCompatibility(
    legacyWholePlayerCharacterPatch($wholeCharacterPatch),
    'La réécriture complète produite par la restauration automatique 2.5.2 doit être reconnue.'
);
requireDomainCompatibility(
    playerCharacterPatchChangesCurrent($currentHira, $wholeCharacterPatch),
    'Une ancienne copie complète à zéro doit être distinguée de la fiche Hira récente.'
);
requireDomainCompatibility(
    !playerCharacterPatchChangesCurrent($currentHira, [
        ...$wholeCharacterPatch,
        'resources' => $currentHira['resources'],
    ]),
    'Une copie complète identique doit rester un simple non-effet.'
);
requireDomainCompatibility(
    !legacyWholePlayerCharacterPatch(['resources' => ['mana' => 70]]),
    'Une sauvegarde ciblée de mana doit toujours rester autorisée.'
);

$pollDelays = array_map('onlineEventPollDelayMicroseconds', [0, 2, 5, 11, 21, 1000]);
requireDomainCompatibility(
    $pollDelays === [250000, 500000, 750000, 1000000, 1500000, 1500000],
    'Le flux SSE doit accélérer après une action puis plafonner son attente au repos.'
);
requireDomainCompatibility(
    onlineEventReconnectDelayMilliseconds('connexion-a')
        === onlineEventReconnectDelayMilliseconds('connexion-a'),
    'La désynchronisation SSE doit rester stable pour une connexion donnée.'
);
$reconnectDelay = onlineEventReconnectDelayMilliseconds('connexion-a');
requireDomainCompatibility(
    $reconnectDelay >= 250 && $reconnectDelay <= 900,
    'La reconnexion SSE doit rester dans sa fenêtre bornée.'
);

unset($_GET['since']);
requireDomainCompatibility(
    requestedOnlineStateRevision() === null,
    'Une lecture d’état ordinaire ne doit pas activer le chemin conditionnel.'
);
$_GET['since'] = '42';
requireDomainCompatibility(
    requestedOnlineStateRevision() === 42,
    'Une révision conditionnelle valide doit être conservée exactement.'
);
unset($_GET['since']);

fwrite(STDOUT, "Compatibilité rétroactive des domaines : OK" . PHP_EOL);
