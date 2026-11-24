<?php
declare(strict_types=1);

define('XAR_TACTICAL_FIXTURE_ONLY', true);
require __DIR__.'/tactical-lifecycle.php';

$db = fixture();
applicationResourceReceiptLedgerEnabled($db, true);
$map = [...$db->payload('map:scene-one'), 'activeLayerId' => 'ground'];
$db->put('map:scene-one', $map);
$existing = $db->payload('initiative:scene-one');
$existing['roundBaseOrder'] = $existing['order'];
$existing['round'] = 2;
$db->put('initiative:scene-one', $existing);
$character = $db->payload('character:character-player');
$character['initiativeBonus'] = 37;
$db->put('character:character-player', $character);
$index = $db->payload('token-index:scene-one');
$ids = ['token-player', 'token-monster'];
for ($number = 0; $number < 48; $number++) {
    $id = 'initiative-pion-' . $number;
    $characterId = 'initiative-model-' . $number;
    $hasSheet = $number % 8 === 0;
    if ($hasSheet) $db->put('character:' . $characterId, [
        'id' => $characterId, 'name' => 'Personnage ' . $number,
        'stats' => [], 'resources' => ['hp' => 50, 'maxHp' => 50], 'initiativeBonus' => 12
    ]);
    $db->put('token:scene-one:'.$id, [
        'id' => $id, 'name' => 'Pion ' . $number, 'layerId' => 'ground',
        'x' => 20 + $number, 'y' => 50, 'hp' => 50, 'maxHp' => 50,
        'hidden' => $number === 7, 'initiativeBonus' => -5,
        ...($hasSheet ? ['characterId' => $characterId] : []),
    ]);
    $index['order'][] = $id;
    $ids[] = $id;
}
$db->put('token-index:scene-one', $index);
$requestId = 'initiative-batch-scene-one';
$args = ['requestId' => $requestId, 'sceneId' => 'scene-one', 'layerId' => 'ground', 'tokenIds' => $ids];
requireTactical(runCommand($db, 'token.initiative.batch', $args)->status === 403, 'Le joueur ne peut pas lancer ces initiatives.');
requireTactical(runCommand($db, 'token.initiative.batch', [...$args, 'layerId' => 'upper'], true)->status === 409,
    'Un ancien niveau ne peut pas relancer les pions du niveau actuel.');
$before = $db->domains;
$beforeRevision = $db->revision;
requireTactical(runCommand($db, 'token.initiative.batch', [...$args, 'tokenIds' => [...$ids, 'gone']], true)->status === 409
    && $db->domains === $before && $db->revision === $beforeRevision && $db->resourceReceiptLedger === [],
    'La sélection complète est contrôlée avant toute mutation ou reçu.');

$db->domainReads = 0;
$applied = runCommand($db, 'token.initiative.batch', $args, true);
requireTactical($applied->status === 200 && $applied->body['rolled'] === 50 && $applied->body['deduplicated'] === false
    && $db->revision === $beforeRevision + 1, 'Cinquante jets dans une seule transaction : '.json_encode($applied->body));
$readsForFifty = $db->domainReads;
requireTactical($readsForFifty <= 8, 'Chargements groupés de la scène, des pions et des fiches : '.$readsForFifty);
$activity = $db->payload('activity');
$rolls = array_column($activity['rolls'], null, 'sourceTokenId');
requireTactical(count($rolls) === 50 && count($activity['playerActions']) === 1
    && $activity['playerActions'][0]['summary'] === '50 initiatives lancées'
    && !isset($activity['resourceReceipts']), 'Resultats détaillés et une entrée groupée, avec reçu indépendant.');
requireTactical($rolls['token-player']['formula'] === '1d100-37'
    && $rolls['initiative-pion-0']['formula'] === '1d100-12' && $rolls['initiative-pion-1']['formula'] === '1d100+5',
    'Le jet utilise le bonus réel de la fiche ou du pion.');
requireTactical($rolls['initiative-pion-7']['visibility'] === 'gm' && !$rolls['initiative-pion-7']['revealed']
    && $rolls['initiative-pion-8']['visibility'] === 'public', 'La visibilité de chaque jet correspond à son pion.');
$batchId = $rolls['token-player']['initiativeBatchId'];
requireTactical(preg_match('/^[0-9a-f]{24}$/D', $batchId) === 1
    && array_unique(array_column($rolls, 'initiativeBatchId')) === [$batchId]
    && publicOnlineAttackRoll($rolls['initiative-pion-8'], false, 'roll')['initiativeBatchId'] === $batchId,
    'Un marqueur de présentation opaque et commun identifie les 50 jets jusque dans la projection publique.');
foreach ($rolls as $tokenId => $roll) {
    if ($roll['rollMode'] !== 'normal' || isset($roll['mapEvent'])
        || $db->payload('token:scene-one:'.$tokenId)['initiative'] !== $roll['total']) {
        throw new RuntimeException('Le jet et le score diffèrent pour '.$tokenId);
    }
}
requireTactical(count($db->resourceReceiptLedger) === 1 && $db->resourceReceiptLedger[$requestId]['result']['rolled'] === 50,
    'La demande écrit un seul reçu compact dans le registre privé.');
$order = $db->payload('initiative:scene-one');
$scores = array_map(static fn(string $id) => $db->payload('token:scene-one:'.$id)['initiative'], $order['roundBaseOrder']);
$sorted = $scores;
sort($sorted);
requireTactical($order['order'][$order['currentIndex']] === 'token-monster' && $order['round'] === 2
    && count($order['order']) === count($ids) && $scores === $sorted, 'Le tour en cours reste actif ; le prochain ordre est trié.');

$afterRevision = $db->revision;
$after = $db->domains;
$replay = runCommand($db, 'token.initiative.batch', $args, true);
requireTactical($replay->status === 200 && $replay->body['deduplicated'] === true && $replay->body['rolled'] === 50
    && $db->domains === $after && $db->revision === $afterRevision, 'Répéter une demande ne relance aucun dé.');
requireTactical(runCommand($db, 'token.initiative.batch', [...$args, 'tokenIds' => [$ids[0]]], true)->status === 409,
    'Une autre sélection ne peut pas réutiliser le reçu.');
requireTactical(runCommand($db, 'token.initiative.batch', $args, true, 'second-gm')->status === 403,
    'Le reçu appartient à son MJ.');

$db->put('map:scene-one', [...$map, 'vision' => ['enabled' => true, 'distance' => 1]]);
$db->put('character:character-player', [...$character, 'visionDistance' => 1]);
$withVision = runCommand($db, 'token.initiative.batch', [...$args, 'requestId' => 'initiative-batch-private-vision',
    'tokenIds' => ['token-player', 'initiative-pion-47']], true);
$visibleRolls = array_column(array_slice($db->payload('activity')['rolls'], 0, 2), null, 'sourceTokenId');
requireTactical($withVision->status === 200 && $visibleRolls['token-player']['visibility'] === 'public'
    && $visibleRolls['initiative-pion-47']['visibility'] === 'gm', 'La vision dynamique protège les jets hors de vue.');
requireTactical($visibleRolls['token-player']['initiativeBatchId'] !== $batchId, 'Deux lots ont deux marqueurs différents.');
$single = runCommand($db, 'token.initiative.batch', [...$args, 'requestId' => 'initiative-une-seule-unite',
    'tokenIds' => ['token-player']], true);
requireTactical($single->status === 200 && $single->body['rolled'] === 1
    && !isset($db->payload('activity')['rolls'][0]['initiativeBatchId']), 'Une initiative isolée garde sa présentation individuelle.');

echo json_encode(['ok' => true, 'checks' => $GLOBALS['checks'], 'domainReadsForFifty' => $readsForFifty], JSON_THROW_ON_ERROR), PHP_EOL;
