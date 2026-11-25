<?php
declare(strict_types=1);

define('XAR_TACTICAL_FIXTURE_ONLY', true);
require __DIR__.'/tactical-lifecycle.php';

$db = fixture();
$background = '/media/'.str_repeat('a', 24);
$otherBackground = '/media/'.str_repeat('b', 24);
foreach ([$background, $otherBackground] as $reference) $db->mediaObjects[basename($reference)] = ['pending_delete_at' => null];
$map = [
    ...$db->payload('map:scene-one'),
    'activeLayerId' => 'ground',
    'background' => $background,
    'gridSize' => 47,
    'gridOffsetX' => 11,
    'layers' => [
        'ground' => ['background' => $background, 'naturalWidth' => 1600, 'naturalHeight' => 900],
        'upper' => ['background' => $otherBackground, 'naturalWidth' => 800, 'naturalHeight' => 700],
    ],
];
$db->put('map:scene-one', $map);
$activityBefore = $db->payload('activity');
$initiativeBefore = $db->payload('initiative:scene-one');
$id = 'map-animation-apply-01';
$animation = [
    'version' => 1,
    'id' => $id,
    'sourceBackground' => $background,
    'comment' => 'Vagues douces, quelques poissons dans le port.',
    'plan' => ['version' => 1, 'layers' => [
        ['kind' => 'water', 'intensity' => .45, 'speed' => .32, 'direction' => 25, 'fish' => .35,
            'mask' => ['width' => 4, 'height' => 4, 'rle' => base64_encode(chr(0).chr(8).chr(255).chr(8))]],
        ...array_map(static fn (string $kind): array => [
            'kind' => $kind, 'intensity' => .3, 'speed' => .2, 'direction' => 45, 'fish' => 0,
            'polygons' => [[[.20, .25], [.40, .25], [.40, .45], [.20, .45]]],
        ], ['mist', 'cloud', 'sun', 'drift', 'particles']),
        ['kind' => 'day-night', 'intensity' => .8, 'speed' => 1, 'direction' => 0, 'fish' => 0, 'cycleSeconds' => 1800,
            'polygons' => [[[0, 0], [1, 0], [1, 1], [0, 1]]]],
        ['kind' => 'rain', 'intensity' => .7, 'speed' => .6, 'direction' => 105, 'fish' => 0, 'cycleSeconds' => 95,
            'polygons' => [[[0, 0], [1, 0], [1, 1], [0, 1]]]],
        ['kind' => 'waterfall', 'intensity' => .55, 'speed' => .55, 'direction' => 90, 'fish' => 0,
            'mask' => ['width' => 3, 'height' => 2, 'rle' => base64_encode("\x00\x01\xff\x01\x00\x02\xff\x01\x00\x01")]],
        ['kind' => 'flame', 'intensity' => .65, 'speed' => .9, 'direction' => 270, 'fish' => 0, 'tint' => '#9651ff',
            'mask' => ['width' => 3, 'height' => 2, 'rle' => base64_encode("\x00\x01\xc0\x02\x00\x03")]],
    ]],
];
$apply = [
    'action' => 'apply',
    'sceneId' => 'scene-one',
    'layerId' => 'ground',
    'expectedBackground' => $background,
    'expectedAnimationId' => null,
    'expectedAnimationRevision' => null,
    'requestId' => $id,
    'animation' => $animation,
];
requireTactical(runCommand($db, 'map.animation', $apply)->status === 403, 'Le joueur ne peut pas appliquer l’animation.');
requireTactical(runCommand($db, 'map.animation', [...$apply, 'expectedBackground' => $otherBackground], true)->status === 409,
    'L’aperçu ne peut pas être appliqué sur un autre fond.');
$badAnimation = $animation;
$badAnimation['plan']['layers'][0]['mask']['rle'] = base64_encode(chr(255).chr(17));
requireTactical(runCommand($db, 'map.animation', [...$apply, 'animation' => $badAnimation], true)->status === 400,
    'Le masque doit couvrir la taille déclarée avec des longueurs valides.');
$badCycle = $animation;
$badCycle['plan']['layers'][6]['cycleSeconds'] = 1801;
requireTactical(runCommand($db, 'map.animation', [...$apply, 'animation' => $badCycle], true)->status === 400,
    'Une durée hors des bornes est refusée avant toute modification.');
foreach ([8, 9] as $subjectIndex) {
    $badSubject = $animation;
    unset($badSubject['plan']['layers'][$subjectIndex]['mask']);
    $badSubject['plan']['layers'][$subjectIndex]['polygons'] = [[[0, 0], [1, 0], [1, 1], [0, 1]]];
    requireTactical(runCommand($db, 'map.animation', [...$apply, 'animation' => $badSubject], true)->status === 400,
        'Les cascades et flammes doivent rester dans les silhouettes détectées.');
}
$saved = runCommand($db, 'map.animation', $apply, true);
requireTactical($saved->status === 200 && $saved->body['animationId'] === $id,
    'Le MJ applique son aperçu sur le niveau : '.json_encode($saved->body));
$afterApply = $db->payload('map:scene-one');
requireTactical(($afterApply['layers']['ground']['mapAnimation'] ?? []) == $animation, 'La configuration complète reste sur le niveau MJ.');
requireTactical(($afterApply['layers']['ground']['mapAnimationRevision'] ?? '') === $id
    && !isset($afterApply['mapAnimation']), 'La révision est par niveau, sans seconde copie du masque.');
requireTactical($afterApply['background'] === $background && $afterApply['gridSize'] === 47 && $afterApply['gridOffsetX'] === 11
    && $afterApply['layers']['upper'] == $map['layers']['upper'], 'La carte, la grille et les autres niveaux restent intacts.');
requireTactical($db->payload('activity') === $activityBefore && $db->payload('initiative:scene-one') === $initiativeBefore,
    'L’animation ne dépend ni du journal ni de l’initiative.');
$writeRevision = $db->revision;
$replay = runCommand($db, 'map.animation', $apply, true);
requireTactical($replay->status === 200 && $replay->body['deduplicated'] === true && $db->revision === $writeRevision,
    'Rejouer la même demande ne modifie ni la carte ni sa révision.');

$stored = domainsToApplicationState($db->domains, $db->revision);
$identity = ['id' => 'account-player', 'effective_mode' => 'player', 'permanent_role' => 'player'];
foreach ([false, true] as $streamPov) {
    $projection = publicPlayerState($stored, $identity, [], $streamPov)['map'];
    requireTactical(($projection['mapAnimation']['id'] ?? '') === $id
        && $projection['mapAnimation']['plan'] === $animation['plan']
        && !array_key_exists('comment', $projection['mapAnimation'])
        && !isset($projection['layers'], $projection['mapAnimationRevision']),
        'Chaque projection publique contient seulement le rendu du niveau diffusé.');
}
$hiddenPreview = $stored;
$hiddenPreview['map']['activeLayerId'] = 'upper';
$hiddenPreview['map']['background'] = $otherBackground;
requireTactical(!isset(publicPlayerState($hiddenPreview, $identity, [])['map']['mapAnimation']),
    'La description d’un niveau ne s’applique pas au niveau voisin.');

$ordinaryEdit = $map;
$ordinaryEdit['gridOffsetY'] = 7;
$preserved = protectApplicationDomainAgainstStaleEntityWrite('map:scene-one', $ordinaryEdit, ['payload' => $afterApply]);
requireTactical(($preserved['layers']['ground']['mapAnimation']['id'] ?? '') === $id && $preserved['gridOffsetY'] === 7,
    'Une modification indépendante de grille ne retire pas l’animation déjà enregistrée.');
$ordinaryEdit['background'] = $otherBackground;
$ordinaryEdit['layers']['ground']['background'] = $otherBackground;
$replaced = protectApplicationDomainAgainstStaleEntityWrite('map:scene-one', $ordinaryEdit, ['payload' => $afterApply]);
requireTactical(!isset($replaced['layers']['ground']['mapAnimation'], $replaced['layers']['ground']['mapAnimationRevision']),
    'Remplacer le fond retire la configuration et la révision devenues obsolètes.');

$variantBackground = '/media/'.str_repeat('c', 24);
$withWeather = $afterApply;
$weather = ['version' => 1, 'normal' => ['background' => $background], 'variants' => [[
    'id' => 'time-night-weather-rain', 'time' => 'night', 'weather' => 'rain', 'background' => $variantBackground, 'createdAt' => 1
]], 'activeVariantId' => null, 'intentRevision' => 1];
$withWeather['layers']['ground']['mapTimeWeather'] = $weather;
$night = applicationMapTimeWeatherChange($withWeather, [
    'action' => 'select', 'layerId' => 'ground', 'variantId' => 'time-night-weather-rain', 'requestId' => 'map-night-selection'
], 'account-player', 2);
$nightMap = prepareApplicationDomainUpsert('map:scene-one', $night['map'], ['payload' => $withWeather])['payload'];
requireTactical($nightMap['background'] === $variantBackground
    && $nightMap['layers']['ground']['mapAnimation']['sourceBackground'] === $background
    && $nightMap['layers']['ground']['mapAnimationRevision'] === $id,
    'L’animation conserve son même ancrage et sa révision quand une variante de temps est appliquée.');
$nightDto = publicApplicationMapAnimation($nightMap, 'ground');
requireTactical($nightDto['sourceBackground'] === $variantBackground && $nightDto['id'] === $id
    && $nightDto['plan'] == $animation['plan'] && !isset($nightDto['comment']),
    'Les rendus publics de la variante reçoivent la nouvelle bitmap et les mêmes masques.');

$nightEdit = 'map-animation-night-apply';
$edited = applyApplicationMapAnimationChange($nightMap, [...$apply,
    'requestId' => $nightEdit, 'expectedBackground' => $variantBackground, 'expectedAnimationId' => $id, 'expectedAnimationRevision' => $id,
    'animation' => [...$animation, 'id' => $nightEdit, 'sourceBackground' => $variantBackground]]);
requireTactical($edited['map']['layers']['ground']['mapAnimation']['sourceBackground'] === $background,
    'Une animation éditée sur la variante reste liée au fond d’origine.');
$normal = applicationMapTimeWeatherChange($edited['map'], [
    'action' => 'normal', 'layerId' => 'ground', 'requestId' => 'map-restore-normal-animation'
], 'account-player', 3);
$normalMap = applicationNormalizeMapAnimationFields($normal['map']);
requireTactical(publicApplicationMapAnimation($normalMap, 'ground')['id'] === $nightEdit
    && publicApplicationMapAnimation($normalMap, 'ground')['sourceBackground'] === $background,
    'La version Normal rejoue les mêmes effets.');

$olderNightMap = $nightMap;
$olderNightMap['layers']['ground']['mapAnimation']['sourceBackground'] = $variantBackground;
$prepare = applicationMapTimeWeatherChange($olderNightMap, [
    'action' => 'prepare', 'layerId' => 'ground', 'sceneId' => 'scene-one', 'requestId' => 'map-regenerate-night-variant',
    'time' => 'night', 'weather' => 'rain', 'expectedIntentRevision' => 2,
], 'account-player', 3);
$messageId = str_repeat('s', 24);
$prepare['map']['layers']['ground']['mapTimeWeather']['pending']['messageId'] = $messageId;
$prepare['map']['mapTimeWeather']['pending']['messageId'] = $messageId;
$replacementWeather = '/media/'.str_repeat('d', 24);
$generated = applicationApplyMapTimeWeatherResult($prepare['map'], $prepare['context'], $messageId, $replacementWeather, 4);
$generatedMap = applicationNormalizeMapAnimationFields($generated['map']);
requireTactical($generated['applied'] && publicApplicationMapAnimation($generatedMap, 'ground')['sourceBackground'] === $replacementWeather
    && $generatedMap['layers']['ground']['mapAnimation']['sourceBackground'] === $background,
    'Régénérer une ambiance ne détache pas un masque ancien, même si sa variante a été remplacée.');

$imported = $normalMap;
$imported['background'] = $otherBackground;
$imported['layers']['ground']['background'] = $otherBackground;
$imported = protectApplicationDomainAgainstStaleEntityWrite('map:scene-one', $imported, ['payload' => $normalMap]);
requireTactical(!isset($imported['layers']['ground']['mapAnimation'], $imported['layers']['ground']['mapAnimationRevision']),
    'Une importation extérieure à la famille des ambiances retire toujours l’ancienne animation.');

$remove = [...$apply, 'action' => 'remove', 'expectedAnimationId' => $id, 'expectedAnimationRevision' => $id,
    'requestId' => 'map-animation-remove-01'];
unset($remove['animation']);
requireTactical(runCommand($db, 'map.animation', [...$remove, 'expectedAnimationRevision' => null], true)->status === 409,
    'Un aperçu qui n’a pas vu la dernière révision ne peut pas écraser son animation.');
$removed = runCommand($db, 'map.animation', $remove, true);
$afterRemove = $db->payload('map:scene-one');
requireTactical($removed->status === 200 && $removed->body['animationId'] === null
    && !isset($afterRemove['layers']['ground']['mapAnimation'])
    && $afterRemove['layers']['ground']['mapAnimationRevision'] === $remove['requestId'],
    'Le niveau redevient statique tout en invalidant les anciens aperçus.');
$removalRevision = $db->revision;
$duplicateRemoval = runCommand($db, 'map.animation', $remove, true);
requireTactical($duplicateRemoval->status === 200 && $duplicateRemoval->body['deduplicated'] === true
    && $db->revision === $removalRevision, 'Une suppression rejouée reste sans effet.');
requireTactical(runCommand($db, 'map.animation', $apply, true)->status === 409,
    'Une ancienne application ne peut pas recréer l’animation retirée.');

echo json_encode(['ok' => true, 'checks' => $GLOBALS['checks']], JSON_THROW_ON_ERROR), PHP_EOL;
