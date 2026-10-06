<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/v1/dynamic-items.php';

$checks = 0;
function itemCheck(bool $value, string $message): void { global $checks; if (!$value) throw new RuntimeException($message); $checks++; }
function itemRefused(callable $action, int $status): void {
    try { $action(); } catch (RuntimeException $error) { itemCheck($error->getCode() === $status, $error->getMessage()); return; }
    throw new RuntimeException('An invalid item command was accepted');
}
$state = ['activeSceneId' => 'scene', 'scenes' => [['id' => 'scene']], 'tacticalSync' => ['paused' => false],
    'characters' => [['id' => 'hero-a', 'ownerPlayerId' => 'owner-a', 'resources' => ['hp' => -60, 'maxHp' => 100, 'mana' => 5, 'maxMana' => 10], 'fatigue' => ['current' => 5, 'max' => 150]], ['id' => 'hero-b', 'ownerPlayerId' => 'owner-b']],
    'map' => ['activeLayerId' => 'ground', 'naturalWidth' => 1600, 'naturalHeight' => 900, 'tokens' => [['id' => 'token-a', 'characterId' => 'hero-a', 'x' => 50, 'y' => 50, 'size' => 40]]],
    'items' => ['version' => 1, 'templates' => [['id' => 'letter', 'kind' => 'letter', 'name' => 'Lettre', 'document' => ['blocks' => [['type' => 'paragraph', 'text' => 'Secret privé']]]], ['id' => 'potion', 'kind' => 'potion', 'name' => 'Potion', 'potion' => ['hp' => 10, 'mana' => -100, 'fatigue' => 200]]],
    'instances' => [['id' => 'letter-one', 'templateId' => 'letter', 'revision' => 1, 'location' => ['kind' => 'inventory', 'characterId' => 'hero-a']], ['id' => 'potion-one', 'templateId' => 'potion', 'revision' => 1, 'location' => ['kind' => 'inventory', 'characterId' => 'hero-a']]]]];
$owner = ['id' => 'owner-a', 'role' => 'player'];
itemCheck(itemText("été\nsuite", 2) === 'ét', 'Unicode text stays valid with or without mbstring');
$args = ['itemId' => 'letter-one', 'characterId' => 'hero-a', 'expectedItemRevision' => 1, 'targetCharacterId' => 'hero-b'];
itemRefused(fn() => applyDynamicItemCommand($state, ['id' => 'owner-b', 'role' => 'player'], 'item.transfer', $args), 403);
$given = applyDynamicItemCommand($state, $owner, 'item.transfer', $args);
itemCheck(count($given['items']['instances']) === 2 && $given['items']['instances'][0]['location']['characterId'] === 'hero-b', 'Transfer moves one instance');
itemCheck($state['items']['instances'][0]['location']['characterId'] === 'hero-a', 'Previous snapshot is preserved');
$after = [...$state, 'items' => $given['items']];
itemRefused(fn() => applyDynamicItemCommand($after, $owner, 'item.transfer', $args), 409);
$projection = publicItemStore($given['items'], $state['characters'], $owner, [], fn() => true);
itemCheck(!in_array('letter', array_column($projection['templates'], 'id'), true), 'Previous owner cannot read transferred document');
$receiver = publicItemStore($given['items'], $state['characters'], ['id' => 'owner-b'], [], fn() => true);
itemCheck($receiver['templates'][0]['document']['blocks'][0]['runs'][0]['text'] === 'Secret privé', 'Receiver can read the original document');
$dropArgs = ['itemId' => 'letter-one', 'characterId' => 'hero-a', 'expectedItemRevision' => 1, 'tokenId' => 'token-a', 'sceneId' => 'scene', 'x' => 99, 'y' => 99];
$dropped = applyDynamicItemCommand($state, $owner, 'item.drop', $dropArgs);
itemCheck($dropped['result']['item']['location']['x'] === 50 && $dropped['result']['item']['location']['y'] === 50, 'Drop uses authoritative token coordinates');
$ground = [...$state, 'items' => $dropped['items']];
$visible = publicItemStore($dropped['items'], $state['characters'], ['id' => 'owner-b'], ['sceneId' => 'scene', 'layerId' => 'ground'], fn() => true);
itemCheck(count($visible['instances']) === 1 && !isset($visible['templates'][0]['document']), 'Ground projection hides document content');
itemCheck(publicItemStore($dropped['items'], $state['characters'], ['id' => 'owner-b'], ['sceneId' => 'scene', 'layerId' => 'ground'], fn() => false)['instances'] === [], 'Unseen ground objects stay private');
$pickupArgs = [...$dropArgs, 'expectedItemRevision' => 2];
$picked = applyDynamicItemCommand($ground, $owner, 'item.pickup', $pickupArgs);
itemRefused(fn() => applyDynamicItemCommand([...$ground, 'items' => $picked['items']], $owner, 'item.pickup', $pickupArgs), 409);
itemRefused(fn() => applyDynamicItemCommand([...$ground, 'tacticalSync' => ['paused' => true]], $owner, 'item.pickup', $pickupArgs), 423);
$far = $ground; $far['items']['instances'][0]['location']['x'] = 80;
itemRefused(fn() => applyDynamicItemCommand($far, $owner, 'item.pickup', $pickupArgs), 403);
$potionArgs = ['itemId' => 'potion-one', 'characterId' => 'hero-a', 'expectedItemRevision' => 1];
$consumed = applyDynamicItemCommand($state, $owner, 'item.consume', $potionArgs);
itemCheck($consumed['characterPatch']['resources']['hp'] === -50 && $consumed['characterPatch']['resources']['mana'] === 0 && $consumed['characterPatch']['fatigue']['current'] === 150, 'Potion preserves negative HP and bounds other resources');
itemRefused(fn() => applyDynamicItemCommand([...$state, 'items' => $consumed['items']], $owner, 'item.consume', $potionArgs), 409);
itemRefused(fn() => applyDynamicItemCommand($state, $owner, 'item.template.create', ['template' => ['id' => 'key', 'kind' => 'object', 'name' => 'Clef']]), 403);
itemRefused(fn() => normalizeItemStore([...$state['items'], 'version' => 2]), 409);
itemRefused(fn() => normalizeItemStore([...$state['items'], 'instances' => [...$state['items']['instances'], $state['items']['instances'][0]]]), 400);
itemRefused(fn() => normalizeItemTemplate(['id' => 'bad', 'kind' => 'book', 'name' => 'Livre', 'image' => 'https://other.example/track.png']), 400);
fwrite(STDOUT, "Dynamic items PHP contract: $checks checks passed.\n");
