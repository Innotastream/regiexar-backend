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

$wallState = emptyApplicationWallState(1600, 900);
$wallBytes = applicationWallMaskBytes($wallState);
requireDomainCompatibility(is_string($wallBytes), 'Le masque de murs vide doit être décodable.');
$wallCenterX = (int) round(((int) $wallState['width'] - 1) / 2);
for ($wallY = 0; $wallY < (int) $wallState['height']; $wallY += 1) {
    for ($wallX = $wallCenterX - 1; $wallX <= $wallCenterX + 1; $wallX += 1) {
        applicationSetMaskBit($wallBytes, $wallY * (int) $wallState['width'] + $wallX, true);
    }
}
$wallState['mask'] = rtrim(strtr(base64_encode($wallBytes), '+/', '-_'), '=');
$blockedMovement = applicationResolveWallCollision(
    $wallState,
    ['x' => 25.0, 'y' => 50.0],
    ['x' => 75.0, 'y' => 50.0],
    50,
    1600,
    900
);
requireDomainCompatibility(
    ($blockedMovement['blocked'] ?? false) === true && (float) ($blockedMovement['x'] ?? 100) < 50.0,
    'Un déplacement envoyé directement au-delà d’un mur doit rester bloqué avant celui-ci.'
);
$trappedPlayerMovement = applicationResolveWallCollision(
    $wallState,
    ['x' => 50.0, 'y' => 50.0],
    ['x' => 75.0, 'y' => 50.0],
    50,
    1600,
    900,
    false
);
requireDomainCompatibility(
    ($trappedPlayerMovement['blocked'] ?? false) === true
        && (float) ($trappedPlayerMovement['x'] ?? 0) === 50.0,
    'Un joueur déjà recouvert par un mur doit attendre une ouverture ou une intervention MJ.'
);

$openingBytes = $wallBytes;
$wallCenterY = (int) round(((int) $wallState['height'] - 1) / 2);
for ($wallY = $wallCenterY - 12; $wallY <= $wallCenterY + 12; $wallY += 1) {
    for ($wallX = $wallCenterX - 1; $wallX <= $wallCenterX + 1; $wallX += 1) {
        applicationSetMaskBit($openingBytes, $wallY * (int) $wallState['width'] + $wallX, false);
    }
}
$openWallState = $wallState;
$openWallState['mask'] = rtrim(strtr(base64_encode($openingBytes), '+/', '-_'), '=');
$openMovement = applicationResolveWallCollision(
    $openWallState,
    ['x' => 25.0, 'y' => 50.0],
    ['x' => 75.0, 'y' => 50.0],
    50,
    1600,
    900
);
requireDomainCompatibility(
    ($openMovement['blocked'] ?? true) === false && (float) ($openMovement['x'] ?? 0) === 75.0,
    'Une ouverture volontaire assez large doit laisser passer le gabarit du token.'
);

$pillarState = emptyApplicationWallState(1000, 1000);
$pillarBytes = applicationWallMaskBytes($pillarState);
requireDomainCompatibility(is_string($pillarBytes), 'Le masque du pilier doit être décodable.');
$pillarCenter = (int) round(((int) $pillarState['width'] - 1) / 2);
for ($pillarY = $pillarCenter - 13; $pillarY <= $pillarCenter + 13; $pillarY += 1) {
    for ($pillarX = $pillarCenter - 1; $pillarX <= $pillarCenter + 1; $pillarX += 1) {
        applicationSetMaskBit($pillarBytes, $pillarY * (int) $pillarState['width'] + $pillarX, true);
    }
}
$pillarState['mask'] = rtrim(strtr(base64_encode($pillarBytes), '+/', '-_'), '=');
$pillarOcclusion = [
    'walls' => $pillarState,
    'vision' => ['version' => 1, 'enabled' => true, 'distance' => 5],
    'naturalWidth' => 1000,
    'naturalHeight' => 1000,
];
$visionFromLeft = applicationComputeVisionMask($pillarOcclusion, [['x' => 25, 'y' => 50]], 100);
requireDomainCompatibility(
    !applicationVisionCoversPoint($visionFromLeft, 40, 50)
        && applicationVisionCoversPoint($visionFromLeft, 70, 50),
    'Un pilier doit laisser son avant visible et masquer son arrière depuis la gauche.'
);
$visionFromAbove = applicationComputeVisionMask($pillarOcclusion, [['x' => 50, 'y' => 25]], 100);
requireDomainCompatibility(
    !applicationVisionCoversPoint($visionFromAbove, 50, 40)
        && applicationVisionCoversPoint($visionFromAbove, 50, 70),
    'L’ombre du pilier doit tourner lorsque l’origine de vision passe au-dessus.'
);
$partyVision = applicationComputeVisionMask($pillarOcclusion, [['x' => 25, 'y' => 50], ['x' => 75, 'y' => 50]], 100);
requireDomainCompatibility(
    !applicationVisionCoversPoint($partyVision, 40, 50)
        && !applicationVisionCoversPoint($partyVision, 70, 50),
    'Les champs de vision des tokens appartenant au même joueur doivent s’unir.'
);

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
$genericCharacterPatch = playerCharacterPatch([
    'id' => 'character-any-player',
    'ownerPlayerId' => 'account-any-player',
    'resources' => ['hp' => 42, 'maxHp' => 80, 'mana' => 69, 'maxMana' => 100],
    'stats' => ['force' => 61, 'dexterity' => 52, 'agility' => 48, 'intelligence' => 73],
    'fatigue' => ['current' => 12, 'max' => 100],
], [
    'resources' => ['mana' => 70],
    'stats' => ['force' => 62],
    'fatigue' => ['current' => 13],
]);
requireDomainCompatibility(
    ($genericCharacterPatch['resources'] ?? null) === ['hp' => 42, 'maxHp' => 80, 'mana' => 70, 'maxMana' => 100]
        && ($genericCharacterPatch['stats'] ?? null) === ['force' => 62, 'dexterity' => 52, 'agility' => 48, 'intelligence' => 73]
        && ($genericCharacterPatch['fatigue'] ?? null) === ['current' => 13, 'max' => 100],
    'Un patch partiel de n’importe quelle fiche doit préserver toutes les autres ressources, stats et valeurs de fatigue.'
);
$visibleNpcProjection = publicPlayerState([
    'session' => ['name' => 'Recette tactique'],
    'characters' => [],
    'map' => ['tokens' => [[
        'id' => 'token-visible-npc',
        'name' => 'Créature visible',
        'hidden' => false,
        'controllerPlayerId' => null,
        'revealDetailsToPlayers' => false,
        'hp' => 40,
        'maxHp' => 50,
        'mana' => 7,
        'maxMana' => 10,
        'stats' => [['id' => 'vigueur', 'label' => 'Vigueur', 'value' => '70']],
        'notes' => 'Note partageable désactivée',
        'gmNotes' => 'Secret MJ absolu',
    ]], 'background' => null],
    'initiative' => ['active' => false, 'order' => [], 'currentIndex' => 0],
    'tacticalSync' => ['paused' => false],
    'playerPreferences' => [],
    'rolls' => [],
    'actionTimers' => [],
    'mapPings' => [],
], ['id' => 'account-player', 'display_name' => 'Joueur'], []);
$visibleNpc = $visibleNpcProjection['map']['tokens'][0] ?? [];
requireDomainCompatibility(
    ($visibleNpc['detailsVisible'] ?? false) === true
        && ($visibleNpc['hp'] ?? null) === 40
        && ($visibleNpc['stats'][0]['value'] ?? null) === '70'
        && !array_key_exists('notes', $visibleNpc)
        && !array_key_exists('gmNotes', $visibleNpc)
        && ($visibleNpc['ownedByYou'] ?? true) === false
        && ($visibleNpc['controllable'] ?? true) === false,
    'Tout token visible doit exposer sa fiche tactique en lecture seule sans notes privées ni droit de contrôle.'
);

$fogWidth = 32;
$fogHeight = 32;
$fogBytes = str_repeat("\0", (int) ceil(($fogWidth * $fogHeight) / 8));
$fogIndex = ((int) round(50 / 100 * ($fogHeight - 1))) * $fogWidth
    + (int) round(50 / 100 * ($fogWidth - 1));
$fogByteIndex = intdiv($fogIndex, 8);
$fogBytes[$fogByteIndex] = chr(ord($fogBytes[$fogByteIndex]) | (1 << ($fogIndex % 8)));
$fogMask = rtrim(strtr(base64_encode($fogBytes), '+/', '-_'), '=');
$fog = [
    'version' => 1,
    'enabled' => true,
    'width' => $fogWidth,
    'height' => $fogHeight,
    'mask' => $fogMask,
];
requireDomainCompatibility(
    validApplicationFogState($fog)
        && applicationFogCoversPoint($fog, 50, 50)
        && !applicationFogCoversPoint($fog, 10, 10)
        && !validApplicationFogState([...$fog, 'mask' => 'masque!invalide']),
    'Le backend doit valider strictement le masque binaire et lire les mêmes cellules que le client.'
);
$fogProjection = publicPlayerState([
    'session' => ['name' => 'Recette brouillard'],
    'characters' => [],
    'activeSceneId' => 'scene-fog',
    'activeScene' => ['id' => 'scene-fog', 'name' => 'Crypte'],
    'map' => [
        'background' => '/media/abcdefghijklmnopqrstuvwx',
        'layers' => [
            'ground' => ['fog' => $fog],
            'upper' => ['fog' => [...$fog, 'mask' => '']],
        ],
        'tokens' => [
            ['id' => 'enemy-fogged', 'name' => 'Adversaire caché', 'x' => 50, 'y' => 50, 'hidden' => false],
            ['id' => 'owned-fogged', 'name' => 'Mon pion', 'x' => 50, 'y' => 50, 'hidden' => false, 'controllerPlayerId' => 'account-player'],
            ['id' => 'enemy-clear', 'name' => 'Adversaire révélé', 'x' => 10, 'y' => 10, 'hidden' => false],
        ],
    ],
    'initiative' => ['active' => false, 'order' => ['enemy-fogged', 'owned-fogged', 'enemy-clear'], 'currentIndex' => 0],
    'tacticalSync' => ['paused' => false],
    'playerPreferences' => [],
    'rolls' => [],
    'actionTimers' => [],
    'mapPings' => [
        ['id' => 'ping-fogged', 'sceneId' => 'scene-fog', 'x' => 50, 'y' => 50, 'expiresAt' => PHP_INT_MAX],
        ['id' => 'ping-clear', 'sceneId' => 'scene-fog', 'x' => 10, 'y' => 10, 'expiresAt' => PHP_INT_MAX],
    ],
], ['id' => 'account-player', 'display_name' => 'Joueur'], []);
$fogTokenIds = array_column($fogProjection['map']['tokens'] ?? [], 'id');
requireDomainCompatibility(
    $fogTokenIds === ['owned-fogged', 'enemy-clear']
        && !array_key_exists('layers', $fogProjection['map'] ?? [])
        && ($fogProjection['map']['fog']['mask'] ?? null) === $fogMask
        && (($fogProjection['map']['tokens'][0]['size'] ?? null) === 50.0)
        && array_column($fogProjection['mapPings'] ?? [], 'id') === ['ping-clear'],
    'Le brouillard actif doit masquer adversaires et signaux côté serveur, conserver le pion possédé et ne jamais exposer les autres niveaux.'
);
$d100Attempts = [
    ['total' => 44, 'rawD100' => 44],
    ['total' => 2, 'rawD100' => 2],
];
requireDomainCompatibility(
    selectOnlineRollAttemptIndex($d100Attempts, 'advantage') === 1,
    'L’avantage d100 doit toujours retenir le plus petit dé brut, même face à une ancienne catégorie critique.'
);
requireDomainCompatibility(
    selectOnlineRollAttemptIndex([
        ['total' => 66, 'rawD100' => 66],
        ['total' => 100, 'rawD100' => 100],
    ], 'disadvantage') === 1,
    'Le désavantage d100 doit toujours retenir le plus grand dé brut, avant toute classification du résultat.'
);
requireDomainCompatibility(
    selectOnlineRollAttemptIndex([
        ['total' => 7, 'rawD100' => null],
        ['total' => 12, 'rawD100' => null],
    ], 'advantage') === 1
        && selectOnlineRollAttemptIndex([
            ['total' => 7, 'rawD100' => null],
            ['total' => 12, 'rawD100' => null],
        ], 'disadvantage') === 0,
    'Les formules hors d100 doivent conserver le total haut sous avantage et le total bas sous désavantage.'
);
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
    $pollDelays === [250000, 500000, 750000, 750000, 750000, 750000],
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
            'linkedTokens' => [['id' => 'linked-ada-wolf', 'name' => 'Loup d’Ada']],
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
requireDomainCompatibility(
    onlineUnassignedCharacterCountAfterRepair($globalRepairRecords, $globalAccounts, $globalProposals) === 1,
    'Le contrôle de migration doit encore signaler la seule fiche sans compte correspondant.'
);
$declaredAssignments = onlineDeclaredCharacterAssignments($globalRepairRecords, $globalAccounts);
requireDomainCompatibility(
    ($declaredAssignments['character:character-ada-12345678'] ?? null) === $adaAccountId
        && ($declaredAssignments['character:character-vraska-12345678'] ?? null) === $adaAccountId
        && ($declaredAssignments['character:character-inho-12345678'] ?? null) === $inhoAccountId
        && !isset($declaredAssignments['character:character-autre-12345678']),
    'La table déclarée doit affecter Ada et Vraska à Ada, puis Inho à Innota, fiche par fiche.'
);
$wrongActiveOwnerRecords = $globalRepairRecords;
$wrongActiveOwnerRecords['character:character-ada-12345678']['payload']['ownerPlayerId'] = $inhoAccountId;
$wrongActiveOwnerRecords['character:character-vraska-12345678']['payload']['ownerPlayerId'] = $inhoAccountId;
$wrongActiveOwnerRecords['token:scene-1:token-ada'] = ['revision' => 1, 'payload' => [
    'id' => 'token-ada', 'characterId' => 'character-ada-12345678', 'controllerPlayerId' => $inhoAccountId,
    'name' => 'Ada', 'hidden' => false,
]];
$wrongActiveOwnerRecords['token:scene-1:token-vraska-legacy'] = ['revision' => 1, 'payload' => [
    'id' => 'token-vraska-legacy', 'controllerPlayerId' => $inhoAccountId,
    'name' => 'Vraska', 'hidden' => false,
]];
$wrongActiveOwnerRecords['token:scene-1:token-ada-wolf'] = ['revision' => 1, 'payload' => [
    'id' => 'token-ada-wolf', 'linkedTokenId' => 'linked-ada-wolf', 'controllerPlayerId' => $inhoAccountId,
    'name' => 'Loup d’Ada', 'hidden' => false,
]];
$wrongActiveOwnerRecords['activity'] = ['revision' => 1, 'payload' => [
    'actionTimers' => [[
        'id' => 'timer-ada', 'characterId' => 'character-ada-12345678', 'ownerPlayerId' => $inhoAccountId,
    ]],
    'actionTimerTombstones' => [],
    'mapPings' => [],
    'pingReceipts' => [],
    'shortcuts' => [],
    'rolls' => [],
]];
$wrongOwnerPending = [];
$wrongOwnerRepair = queueOnlineDeclaredCharacterAssignments(
    $wrongOwnerPending,
    $wrongActiveOwnerRecords,
    onlineDeclaredCharacterAssignments($wrongActiveOwnerRecords, $globalAccounts),
    200
);
requireDomainCompatibility(
    ($wrongOwnerPending['character:character-ada-12345678']['payload']['ownerPlayerId'] ?? null) === $adaAccountId
        && ($wrongOwnerPending['character:character-vraska-12345678']['payload']['ownerPlayerId'] ?? null) === $adaAccountId
        && ($wrongOwnerPending['token:scene-1:token-ada']['payload']['controllerPlayerId'] ?? null) === $adaAccountId
        && ($wrongOwnerPending['token:scene-1:token-vraska-legacy']['payload']['controllerPlayerId'] ?? null) === $adaAccountId
        && ($wrongOwnerPending['token:scene-1:token-ada-wolf']['payload']['controllerPlayerId'] ?? null) === $adaAccountId
        && ($wrongOwnerPending['activity']['payload']['actionTimers'][0]['ownerPlayerId'] ?? null) === $adaAccountId
        && (int) ($wrongOwnerRepair['characters'] ?? 0) === 3
        && (int) ($wrongOwnerRepair['tokens'] ?? 0) === 3,
    'Une fiche attachée au mauvais compte actif doit être réattribuée au compte déclaré, sans ambiguïté.'
);
$wrongTokenStatus = onlineDeclaredTokenOwnershipStatus(
    $wrongActiveOwnerRecords,
    $wrongOwnerPending,
    onlineDeclaredCharacterAssignments($wrongActiveOwnerRecords, $globalAccounts)
);
requireDomainCompatibility(
    $wrongTokenStatus === ['matched' => 3, 'correct' => 3, 'incorrect' => 0],
    'La réparation doit prouver que tous les tokens directs, historiques et liés utilisent le bon compte.'
);
requireDomainCompatibility(
    onlineEffectiveTokenControllerId(
        ['characterId' => 'character-ada-12345678', 'controllerPlayerId' => $inhoAccountId],
        ['character-ada-12345678' => $adaAccountId]
    ) === $adaAccountId,
    'Le propriétaire autoritaire de la fiche doit prévaloir immédiatement sur un contrôleur de token périmé.'
);
$adaProjection = publicPlayerState([
    'session' => ['name' => 'Recette'],
    'characters' => [$wrongOwnerPending['character:character-ada-12345678']['payload']],
    'map' => ['tokens' => [$wrongActiveOwnerRecords['token:scene-1:token-ada']['payload']], 'background' => null],
    'initiative' => ['active' => false, 'order' => [], 'currentIndex' => 0],
    'tacticalSync' => ['paused' => false],
    'playerPreferences' => [],
    'rolls' => [],
    'actionTimers' => [],
    'mapPings' => [],
], ['id' => $adaAccountId, 'display_name' => 'Ada'], []);
requireDomainCompatibility(
    ($adaProjection['map']['tokens'][0]['ownedByYou'] ?? false) === true
        && ($adaProjection['map']['tokens'][0]['controllable'] ?? false) === true,
    'La vue Joueur doit rendre immédiatement déplaçable un token dont la fiche lui appartient, même avant la migration physique.'
);
$rosterWithGhosts = $globalRepairRecords['roster']['payload'];
$rosterWithGhosts['players'][] = ['id' => $adaAccountId, 'name' => 'Ada en double'];
$rosterWithGhosts['players'][] = ['id' => 'player-ghost-without-sheet', 'name' => 'Fantôme'];
$rosterWithGhosts['playerPreferences']['player-ghost-without-sheet'] = ['activePage' => 'characters'];
$removedRosterGhosts = cleanupOnlinePhantomRosterPlayers(
    $rosterWithGhosts,
    $globalAccounts,
    $globalRepairRecords,
    $globalProposals,
    $declaredAssignments
);
requireDomainCompatibility(
    $removedRosterGhosts === 4
        && findEntryIndex($rosterWithGhosts['players'], $adaAccountId) >= 0
        && findEntryIndex($rosterWithGhosts['players'], 'player-ghost-without-sheet') < 0
        && !isset($rosterWithGhosts['playerPreferences']['player-ghost-without-sheet']),
    'Les doublons de roster et identités fantômes sans fiche doivent disparaître avec leurs préférences résiduelles.'
);
requireDomainCompatibility(
    onlineDuplicateActiveAccountDisplayCount([
        ...$globalAccounts,
        ['id' => 'usr_ada_duplicate', 'username' => 'ada-bis', 'display_name' => 'Ada'],
    ]) === 1,
    'Le contrôle doit distinguer un vrai doublon de nom dans les comptes actifs des fantômes du roster.'
);
$globalRepairWithCurrentCharacter = $globalRepairRecords;
$globalRepairWithCurrentCharacter['character:character-ada-nouvelle'] = [
    'revision' => 1,
    'payload' => [
        'id' => 'character-ada-nouvelle',
        'ownerPlayerId' => $adaAccountId,
        'name' => 'Brouillon après incident',
        '_updatedAt' => 100,
    ],
];
$proposalsWithCurrentCharacter = onlineRosterOwnershipProposals(
    $globalRepairWithCurrentCharacter,
    $globalAccounts
);
requireDomainCompatibility(
    ($proposalsWithCurrentCharacter[$adaAccountId]['oldId'] ?? null) === $adaLegacyId,
    'Une fiche créée après l’incident ne doit pas empêcher Ada de récupérer ses anciennes fiches.'
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
