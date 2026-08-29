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

$hiraIdentity = ['id' => 'usr_hira_12345678', 'username' => 'hira', 'display_name' => 'Hira'];
$duplicateRoster = [
    ['id' => 'usr_hira_12345678', 'name' => 'Hira'],
    ['id' => 'player-hira', 'name' => 'Hira'],
    ['id' => 'player-other', 'name' => 'Autre'],
];
requireDomainCompatibility(
    rosterMigrationCandidateIndex($duplicateRoster, 'usr_hira_12345678', $hiraIdentity, true) === 1,
    'Un compte déjà présent doit retrouver son ancien identifiant player-<identifiant> exact.'
);
requireDomainCompatibility(
    rosterMigrationCandidateIndex([
        ['id' => 'usr_hira_12345678', 'name' => 'Hira'],
        ['id' => 'player-other', 'name' => 'Hira'],
    ], 'usr_hira_12345678', $hiraIdentity, true) === 1,
    'Une unique ancienne entrée de même identité doit être retrouvée même si le compte est déjà présent.'
);
requireDomainCompatibility(
    rosterMigrationCandidateIndex([
        ['id' => 'usr_hira_12345678', 'name' => 'Hira'],
        ['id' => 'player-other-a', 'name' => 'Hira'],
        ['id' => 'player-other-b', 'name' => 'Hira'],
    ], 'usr_hira_12345678', $hiraIdentity, true) === -1,
    'Deux anciennes entrées homonymes doivent rester ambiguës et non fusionnées.'
);
requireDomainCompatibility(
    rosterMigrationCandidateIndex([
        ['id' => 'usr_hira_12345678', 'name' => 'Hira'],
        ['id' => 'player-hira', 'name' => 'Hira'],
        ['id' => 'PLAYER-HIRA', 'name' => 'Hira bis'],
    ], 'usr_hira_12345678', $hiraIdentity, true) === -1,
    'Deux anciens identifiants équivalents doivent rester ambigus et non fusionnés.'
);

$adaLegacyId = 'player-7fd6193e-b970-4d76-bbb9-11fc8ef8d386';
$adaAccountId = 'usr_ada_12345678';
$adaRoster = [
    'players' => [
        ['id' => $adaAccountId, 'name' => 'Ada'],
        ['id' => $adaLegacyId, 'name' => 'À renseigner'],
        ['id' => 'player-autre', 'name' => 'Autre'],
    ],
    'characterOrder' => [
        'character-ada-12345678',
        'character-vraska-12345678',
        'character-autre-12345678',
    ],
    'playerPreferences' => [$adaLegacyId => ['activePage' => 'characters']],
    'playerTombstones' => [],
    'characterTombstones' => [],
];
$adaRecords = [
    'roster' => [
        'revision' => 7,
        'payload' => $adaRoster,
    ],
    'character:character-ada-12345678' => [
        'revision' => 3,
        'payload' => [
            'id' => 'character-ada-12345678',
            'ownerPlayerId' => $adaLegacyId,
            'name' => 'Ada',
            '_updatedAt' => 100,
        ],
    ],
    'character:character-vraska-12345678' => [
        'revision' => 5,
        'payload' => [
            'id' => 'character-vraska-12345678',
            'ownerPlayerId' => $adaLegacyId,
            'name' => 'Vraska',
            '_updatedAt' => 100,
        ],
    ],
    'character:character-autre-12345678' => [
        'revision' => 2,
        'payload' => [
            'id' => 'character-autre-12345678',
            'ownerPlayerId' => 'player-autre',
            'name' => 'Autre',
            '_updatedAt' => 100,
        ],
    ],
];
requireDomainCompatibility(
    onlineIdentityLegacyOwnerId(
        $adaRecords,
        $adaAccountId,
        ['id' => $adaAccountId, 'username' => 'ada', 'display_name' => 'Ada']
    ) === $adaLegacyId,
    'Ada doit retrouver son ancien propriétaire grâce à la fiche homonyme, même si le roster affiche « À renseigner ».'
);
$inhoLegacyId = 'player-f11f894b-660c-4d2d-97e6-5de1b2b785e7';
$inhoAccountId = 'usr_innota_12345678';
$inhoRecords = [
    'roster' => [
        'revision' => 2,
        'payload' => [
            'players' => [
                ['id' => $inhoAccountId, 'name' => 'Innota'],
                ['id' => $inhoLegacyId, 'name' => 'À renseigner'],
            ],
            'characterOrder' => ['character-inho-12345678'],
            'playerPreferences' => [],
            'playerTombstones' => [],
            'characterTombstones' => [],
        ],
    ],
    'character:character-inho-12345678' => [
        'revision' => 4,
        'payload' => [
            'id' => 'character-inho-12345678',
            'ownerPlayerId' => $inhoLegacyId,
            'name' => 'Inho',
            '_updatedAt' => 100,
        ],
    ],
];
requireDomainCompatibility(
    onlineIdentityLegacyOwnerId(
        $inhoRecords,
        $inhoAccountId,
        ['id' => $inhoAccountId, 'username' => 'Innota', 'display_name' => 'Jonathan']
    ) === $inhoLegacyId,
    'Le compte Innota doit retrouver explicitement la fiche Inho même si les noms du compte et du roster diffèrent.'
);
requireDomainCompatibility(
    rosterRepairAliases(['username' => 'Goldark', 'display_name' => 'Goldark']) === ['goldark', 'kokaku']
        && rosterRepairAliases(['username' => 'Hohachu', 'display_name' => 'Hohachu'])
            === ['hohachu', 'gohachu', 'gohachu forgefer'],
    'Les rattachements déclarés Goldark vers Kokaku et Hohachu vers Gohachu doivent rester explicites.'
);
$globalRepairRecords = [
    'roster' => [
        'revision' => 9,
        'payload' => [
            'players' => [
                ['id' => $adaAccountId, 'name' => 'Ada'],
                ['id' => $adaLegacyId, 'name' => 'À renseigner'],
                ['id' => $inhoAccountId, 'name' => 'Innota'],
                ['id' => $inhoLegacyId, 'name' => 'À renseigner'],
                ['id' => 'player-autre', 'name' => 'Autre'],
            ],
            'characterOrder' => [
                'character-ada-12345678',
                'character-vraska-12345678',
                'character-inho-12345678',
                'character-autre-12345678',
            ],
            'playerPreferences' => [],
            'playerTombstones' => [],
            'characterTombstones' => [],
        ],
    ],
    'character:character-ada-12345678' => $adaRecords['character:character-ada-12345678'],
    'character:character-vraska-12345678' => $adaRecords['character:character-vraska-12345678'],
    'character:character-inho-12345678' => $inhoRecords['character:character-inho-12345678'],
    'character:character-autre-12345678' => $adaRecords['character:character-autre-12345678'],
];
$globalAccounts = [
    ['id' => $adaAccountId, 'username' => 'ada', 'display_name' => 'Ada'],
    ['id' => $inhoAccountId, 'username' => 'Innota', 'display_name' => 'Jonathan'],
    ['id' => 'usr_autre_12345678', 'username' => 'sans-fiche', 'display_name' => 'Sans fiche'],
];
$globalProposals = onlineRosterOwnershipProposals($globalRepairRecords, $globalAccounts);
requireDomainCompatibility(
    ($globalProposals[$adaAccountId]['oldId'] ?? null) === $adaLegacyId
        && ($globalProposals[$inhoAccountId]['oldId'] ?? null) === $inhoLegacyId
        && !isset($globalProposals['usr_autre_12345678']),
    'La réconciliation globale doit préparer Ada et Innota ensemble sans inventer de propriétaire au compte sans fiche.'
);
$ambiguousGlobalProposals = onlineRosterOwnershipProposals($globalRepairRecords, [
    ...$globalAccounts,
    ['id' => 'usr_ada_homonyme_12345678', 'username' => 'ada', 'display_name' => 'Ada'],
]);
requireDomainCompatibility(
    !isset($ambiguousGlobalProposals[$adaAccountId])
        && !isset($ambiguousGlobalProposals['usr_ada_homonyme_12345678'])
        && ($ambiguousGlobalProposals[$inhoAccountId]['oldId'] ?? null) === $inhoLegacyId,
    'Deux comptes homonymes ne doivent jamais se partager le même ancien propriétaire, sans bloquer Innota.'
);
$adaPending = [];
queueOnlinePlayerOwnershipRepair(
    $adaPending,
    $adaRecords,
    $adaRoster,
    $adaLegacyId,
    $adaAccountId,
    200
);
requireDomainCompatibility(
    ($adaPending['character:character-ada-12345678']['payload']['ownerPlayerId'] ?? null) === $adaAccountId
        && ($adaPending['character:character-vraska-12345678']['payload']['ownerPlayerId'] ?? null) === $adaAccountId,
    'Le compte Ada doit récupérer ensemble ses fiches Ada et Vraska.'
);
requireDomainCompatibility(
    !isset($adaPending['character:character-autre-12345678'])
        && !isset($adaRoster['playerPreferences'][$adaLegacyId])
        && ($adaRoster['playerPreferences'][$adaAccountId]['activePage'] ?? null) === 'characters',
    'La réparation d’Ada ne doit toucher ni les autres joueurs ni perdre ses préférences.'
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
