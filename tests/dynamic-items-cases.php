<?php
declare(strict_types=1);

$db = fixture();
$db->put('scene-index', ['order' => ['scene-one']]);
$db->put('character:character-receiver', ['id' => 'character-receiver', 'name' => 'Destinataire', 'ownerPlayerId' => 'account-receiver']);
$templateArgs = ['requestId' => 'item-template-native-0001', 'template' => ['id' => 'native-potion', 'kind' => 'potion', 'name' => 'Potion de soin', 'potion' => ['hp' => 10, 'mana' => 0, 'fatigue' => 0]]];
$response = runCommand($db, 'item.template.create', $templateArgs, false, 'account-player', true);
requireTactical($response->status === 403 && $db->payload('items') === [], 'Permanent administrator in player mode cannot create an item model');
$response = runCommand($db, 'item.template.create', $templateArgs, true, 'account-gm');
requireTactical($response->status === 200 && ($response->body['templateId'] ?? '') === 'native-potion', 'Native dispatcher creates a model in MJ mode');
$spawn = ['requestId' => 'item-spawn-native-0001', 'itemId' => 'native-item', 'templateId' => 'native-potion', 'location' => ['kind' => 'inventory', 'characterId' => 'character-player']];
$response = runCommand($db, 'item.spawn', $spawn, true, 'account-gm');
requireTactical($response->status === 200 && count($db->payload('items')['instances']) === 1, 'Native dispatcher creates exactly one instance');
$revision = $db->revision;
$retry = runCommand($db, 'item.spawn', $spawn, true, 'account-gm');
requireTactical($retry->status === 200 && ($retry->body['deduplicated'] ?? false) && $db->revision === $revision && count($db->payload('items')['instances']) === 1, 'Persistent replay does not duplicate an instance or bump the clock');
$drop = ['requestId' => 'item-drop-native-0001', 'itemId' => 'native-item', 'characterId' => 'character-player', 'expectedItemRevision' => 1, 'sceneId' => 'scene-one', 'tokenId' => 'token-player', 'x' => 99, 'y' => 99];
$response = runCommand($db, 'item.drop', $drop);
requireTactical($response->status === 200 && $db->payload('items')['instances'][0]['location']['x'] === 20, 'Native drop uses token coordinates, not client coordinates');
$pickup = [...$drop, 'requestId' => 'item-pickup-native-0001', 'expectedItemRevision' => 2];
$response = runCommand($db, 'item.pickup', $pickup);
requireTactical($response->status === 200 && $db->payload('items')['instances'][0]['revision'] === 3, 'Native pickup verifies current instance revision');
$second = runCommand($db, 'item.pickup', [...$pickup, 'requestId' => 'item-pickup-native-0002']);
requireTactical($second->status === 409 && count($db->payload('items')['instances']) === 1, 'Second pickup cannot acquire the same ground instance');
$consume = ['requestId' => 'item-consume-native-0001', 'itemId' => 'native-item', 'characterId' => 'character-player', 'expectedItemRevision' => 3];
$response = runCommand($db, 'item.consume', $consume);
requireTactical($response->status === 200 && $db->payload('character:character-player')['resources']['hp'] === 20
    && $db->payload('token:scene-one:token-player')['hp'] === 20 && $db->payload('token:scene-two:token-copy')['hp'] === 20
    && $db->payload('token:scene-one:token-independent')['hp'] === 40
    && $db->payload('items')['instances'][0]['location']['kind'] === 'consumed', 'Potion commits consumption, resources and direct following tokens together');
$revision = $db->revision;
$retry = runCommand($db, 'item.consume', $consume);
requireTactical($retry->status === 200 && ($retry->body['deduplicated'] ?? false) && $db->revision === $revision && $db->payload('character:character-player')['resources']['hp'] === 20, 'Persistent potion replay never applies the heal twice');
$conflict = runCommand($db, 'item.consume', [...$consume, 'characterId' => 'character-receiver']);
requireTactical($conflict->status === 409 && $db->revision === $revision, 'A reused request identity with changed arguments is refused');
$refusedLore = runCommand($db, 'character.patch', ['characterId' => 'character-player', 'patch' => ['lore' => 'Edit forbidden']]);
requireTactical($refusedLore->status === 403 && !isset($db->payload('character:character-player')['lore']), 'Native player patch keeps the lore read only');

$mediaId = 'abcdefghijklmnopqrstuvwx';
$mediaState = ['characters' => [['id' => 'hero-doc', 'ownerPlayerId' => 'owner-doc']], 'map' => [], 'scenes' => [],
    'items' => ['version' => 1, 'templates' => [['id' => 'doc', 'kind' => 'letter', 'name' => 'Lettre', 'image' => '', 'document' => ['blocks' => [['type' => 'image', 'src' => '/media/' . $mediaId]]]]],
    'instances' => [['id' => 'doc-one', 'templateId' => 'doc', 'revision' => 1, 'location' => ['kind' => 'inventory', 'characterId' => 'hero-doc']]]]];
requireTactical(onlineMediaVisibleInPlayerState($mediaState, ['id' => 'owner-doc'], $mediaId), 'Native media authority includes illustrations from the current owned inventory');
requireTactical(!onlineMediaVisibleInPlayerState($mediaState, ['id' => 'other-owner'], $mediaId), 'Native media authority refuses another player’s document illustrations');
