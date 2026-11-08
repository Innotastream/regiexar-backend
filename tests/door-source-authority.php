<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY', true);
require __DIR__.'/tactical-special-effects.php';

function doorSourceCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    $GLOBALS['doorSourceChecks'] = ($GLOBALS['doorSourceChecks'] ?? 0) + 1;
}

function doorSourceFixture(int $sheetHp, int $tokenHp, array $tokenFields = [], array $characterFields = []): array {
    $db = specialFixture();
    $character = array_replace($db->payload('character:character-player'), $characterFields);
    $character['resources']['hp'] = $sheetHp;
    $db->put('character:character-player', $character);
    $source = array_replace($db->payload('token:scene-one:token-player'),
        ['x' => 52, 'y' => 55, 'hp' => $tokenHp], $tokenFields);
    $db->put('token:scene-one:token-player', $source);
    $door = applicationCreateTerrainBarrierToken(
        ['id' => 'door', 'terrainBarrier' => ['version' => 1, 'shape' => 'door', 'hp' => 50, 'size' => 40]],
        ['x' => 50, 'y' => 50, 'rotation' => 0], 'fixture-door');
    $door['terrainBarrier']['allowPeek'] = true;
    $db->put('token:scene-one:fixture-door', $door);
    $index = $db->payload('token-index:scene-one');
    $index['order'][] = 'fixture-door';
    $db->put('token-index:scene-one', $index);
    return [$db, ['sceneId' => 'scene-one', 'layerId' => 'ground',
        'tokenId' => 'fixture-door', 'sourceTokenId' => 'token-player']];
}

foreach (['open', 'close', 'peek', 'pick'] as $action) {
    [$db, $command] = doorSourceFixture(0, 100);
    $door = $db->payload('token:scene-one:fixture-door');
    $door['terrainBarrier']['open'] = $action === 'close';
    $door['terrainBarrier']['locked'] = $action === 'pick';
    $db->put('token:scene-one:fixture-door', $door);
    if ($action === 'pick') {
        $tool = runCommand($db, 'door.interact', [...$command, 'action' => 'spawn-pick',
            'recipientCharacterId' => 'character-player'], true);
        doorSourceCheck($tool->status === 200, 'The fixture gives the character a real lockpick');
    }
    $before = $db->domains;
    $result = runCommand($db, 'door.interact', [...$command, 'action' => $action,
        'requestId' => 'door-source-authority-'.$action]);
    doorSourceCheck($result->status === 403, 'A KO character with stale positive token HP cannot '.$action);
    doorSourceCheck($db->domains === $before, 'Refusing '.$action.' preserves the door, the tool and the activity');
}

foreach ([
    ['name' => 'recovered character', 'sheetHp' => 100, 'tokenHp' => 0, 'status' => 200],
    ['name' => 'dead character', 'sheetHp' => 100, 'tokenHp' => 100, 'character' => ['healthOverride' => 'dead'], 'status' => 403],
    ['name' => 'living independent clone', 'sheetHp' => 0, 'tokenHp' => 100,
        'token' => ['followCharacter' => false, 'independentClone' => true], 'status' => 200],
    ['name' => 'KO independent clone', 'sheetHp' => 100, 'tokenHp' => 0,
        'token' => ['followCharacter' => false, 'independentClone' => true], 'status' => 403],
    ['name' => 'living linked companion', 'sheetHp' => 0, 'tokenHp' => 100,
        'token' => ['linkedTokenId' => 'fixture-companion'], 'status' => 200],
] as $case) {
    [$db, $command] = doorSourceFixture($case['sheetHp'], $case['tokenHp'], $case['token'] ?? [], $case['character'] ?? []);
    $result = runCommand($db, 'door.interact', [...$command, 'action' => 'open']);
    doorSourceCheck($result->status === $case['status'], 'Door interaction uses the effective HP of the '.$case['name']);
}

[$db, $command] = doorSourceFixture(100, 100);
$peek = runCommand($db, 'door.interact', [...$command, 'action' => 'peek']);
doorSourceCheck($peek->status === 200, 'A living character can look through the keyhole');
$project = static fn() => publicPlayerState(domainsToApplicationState($db->domains, 0),
    ['id' => 'account-player', 'effective_mode' => 'player'], [], false, true);
doorSourceCheck($project()['doorPeek'] !== null, 'The keyhole observation is initially visible');
$character = $db->payload('character:character-player');
$character['resources']['hp'] = 0;
$db->put('character:character-player', $character);
doorSourceCheck($project()['doorPeek'] === null, 'An existing keyhole observation stops when the character becomes KO');

[$db, $command] = doorSourceFixture(100, 0);
$peek = runCommand($db, 'door.interact', [...$command, 'action' => 'peek']);
doorSourceCheck($peek->status === 200, 'A recovered character can start observing despite stale zero token HP');
doorSourceCheck($db->payload('character:character-player')['resources']['hp'] === 100,
    'Observing never copies stale token HP onto the character');

[$db, $command] = doorSourceFixture(100, 100);
$toolResult = runCommand($db, 'door.interact', [...$command, 'action' => 'spawn-pick',
    'recipientCharacterId' => 'character-player'], true);
doorSourceCheck($toolResult->status === 200, 'The delayed lockpick owns a real tool');
$state = domainsToApplicationState($db->domains, 0);
$source = array_column($state['map']['tokens'], null, 'id')['token-player'];
$doorIndex = array_search('fixture-door', array_column($state['map']['tokens'], 'id'), true);
$state['map']['tokens'][$doorIndex]['terrainBarrier']['locked'] = true;
$door = $state['map']['tokens'][$doorIndex];
$tool = $state['items']['instances'][0];
$pending = ['sceneId' => 'scene-one', 'doorId' => 'fixture-door', 'tokenId' => 'token-player',
    'ownerId' => 'character-player', 'accountId' => 'account-player',
    'toolId' => $tool['id'], 'toolRevision' => $tool['revision'],
    'doorCheckpoint' => applicationDoorPickCheckpoint($door),
    'sourceCheckpoint' => applicationKeyholeMovementCheckpoint($source, $state['map']),
    'requestId' => 'door-source-delayed-validation', 'cast' => ['success' => true]];
$characterIndex = array_search('character-player', array_column($state['characters'], 'id'), true);
$state['characters'][$characterIndex]['healthOverride'] = 'dead';
$beforeItems = $state['items'];
$result = applicationResolveDoorPick($state, $pending, true);
doorSourceCheck(!$result['applied'] && $result['obsolete'],
    'A delayed lockpick becomes obsolete when its effective character dies');
doorSourceCheck($state['items'] === $beforeItems && $state['map']['tokens'][$doorIndex]['terrainBarrier']['locked'],
    'An obsolete validation keeps both the tool and the keyed door unchanged');

echo json_encode(['ok' => true, 'checks' => $GLOBALS['doorSourceChecks']], JSON_THROW_ON_ERROR)."\n";
