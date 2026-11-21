<?php
declare(strict_types=1);

define('XAR_SPECIAL_FIXTURE_ONLY', true);
require __DIR__ . '/tactical-special-effects.php';

$db = specialFixture();
$db->put('initiative:scene-one', [...$db->payload('initiative:scene-one'), 'active' => false]);
$map = $db->payload('map:scene-one');
$db->put('map:scene-one', [...$map, 'background' => '/media/abcdefghijklmnopqrstuvwx']);
$placed = specialMechanics($db, 'structure.web.place', ['layerId' => 'ground', 'x' => 60, 'y' => 60, 'structureSize' => 109]);
specialCheck($placed->status === 200, 'Le MJ place la toile de la recette : ' . json_encode($placed->body));
$targetId = $placed->body['tokenId'];
$targetKey = 'token:scene-one:' . $targetId;
$targetBefore = $db->payload($targetKey);
$character = $db->payload('character:character-player');
$character['weaponText'] = 'Bâton / 1d2+66';
$character['weaponAttacks'] = [['id' => 'main', 'name' => 'Bâton', 'formula' => '1d2+66', 'damageType' => 'physical']];
$character['specialSuccessStock'] = 1;
$db->put('character:character-player', $character);
$db->put('token:scene-one:token-player',
    synchronizeOnlineCharacterToken($db->payload('token:scene-one:token-player'), $character));
$created = runCommand($db, 'token.attack', [
    'sceneId' => 'scene-one', 'sourceTokenId' => 'token-player', 'targetTokenId' => $targetId,
    'attackKind' => 'weapon', 'attackId' => 'main', 'statId' => 'character-stat-agility',
    'requestId' => 'pending-lifecycle-web-strike', 'opposed' => true, 'useJoker' => true,
]);
specialCheck($created->status === 200, 'La frappe réelle est acceptée : ' . json_encode($created->body));
$id = $created->body['attack']['id'];
$beforeApproval = $db->payload('activity');
$attack = array_column($beforeApproval['pendingAttacks'], null, 'id')[$id] ?? [];
specialCheck($attack['status'] === 'pending' && $attack['opposed'] === false,
    'Une toile sans défense produit une validation de dégâts, même sur demande d’opposition.');
$args = ['attackId' => $id, 'decision' => 'approve', 'confirmed' => true];
$approved = runCommand($db, 'token.attack.resolve', $args, true, 'account-gm');
specialCheck($approved->status === 200 && $approved->body['attack']['status'] === 'applied',
    'Le MJ approuve la frappe réelle : ' . json_encode($approved->body));
$afterApproval = $db->payload('activity');
$receiptAfterApproval = array_column($afterApproval['attackReceipts'], null, 'requestId')['pending-lifecycle-web-strike'];
specialCheck(!in_array($id, array_column($afterApproval['pendingAttacks'], 'id'), true)
    && $receiptAfterApproval['attack']['status'] === 'applied',
    'La frappe appliquée quitte la file et conserve son reçu terminal.');
$hp = $db->payload($targetKey)['hp'];
specialCheck((float) $hp === (float) $targetBefore['hp'] - (float) $attack['finalDamage'],
    'Le résultat du dé correspond à une seule application.');

$retainedOpposition = ['id' => 'attack-active-opposition-fixture', 'status' => 'awaiting-opposition',
    'targetRangeCells' => 4, 'sceneId' => 'scene-one'];
$current = [...$afterApproval, 'pendingAttacks' => [$retainedOpposition]];
$stale = [...$beforeApproval, 'pendingAttacks' => [...$beforeApproval['pendingAttacks'], $retainedOpposition]];
$incoming = prepareApplicationDomainUpsert('activity', $stale, ['revision' => 2, 'payload' => $current], true);
$reconciled = $incoming['payload'] ?? $current;
specialCheck($reconciled['pendingAttacks'] === [$retainedOpposition],
    'Un instantané MJ en retard ne remet pas la frappe dans la file et conserve une vraie opposition.');
specialCheck(array_column($reconciled['attackReceipts'], null, 'requestId')['pending-lifecycle-web-strike'] === $receiptAfterApproval,
    'Un instantané en retard ne peut pas remplacer le reçu terminal.');

$legacy = [...$current, 'pendingAttacks' => [$receiptAfterApproval['attack'], $retainedOpposition]];
$db->put('activity', $legacy);
specialCheck(projectApplicationGmPendingAreaGates($legacy)['pendingAttacks'] === [$retainedOpposition]
    && applicationDomainPayload($db->domains, 'activity')['pendingAttacks'] === [$retainedOpposition],
    'Le résidu 4.3.1 est filtré tant en lecture MJ que côté commande.');
$retry = runCommand($db, 'token.attack.resolve', $args, true, 'account-gm');
specialCheck($retry->status === 404 && $db->payload($targetKey)['hp'] === $hp,
    'Une ancienne validation ouverte ne peut pas rejouer les dégâts.');

echo json_encode(['ok' => true, 'checks' => $specialChecks], JSON_THROW_ON_ERROR) . PHP_EOL;
