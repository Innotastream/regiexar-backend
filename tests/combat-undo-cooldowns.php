<?php
declare(strict_types=1);
define('XAR_CONTACT_FIXTURE_ONLY', true);
require __DIR__ . '/melee-contact-variants.php';
set_error_handler(static function(int $severity, string $message): never { throw new ErrorException($message, 0, $severity); });
$specialChecks = 0;

$database = contactFixture();
$characterKey = 'character:' . CONTACT_CHARACTER;
$character = $database->payload($characterKey);
$character['resources']['hp'] = 70;
$character['abilities'] = [
    ['id' => 'undo-cooldown', 'name' => 'Soin contrôlé', 'effect' => 'healing', 'formula' => '7',
        'healingFormula' => '7', 'castingStatId' => '', 'manaCost' => 5, 'cooldownRounds' => 3, 'restRecharge' => 'short', 'usesPerRest' => 3],
];
$database->put($characterKey, $character);
$tokenKey = 'token:scene-one:token-player';
$database->put($tokenKey, synchronizeOnlineCharacterToken($database->payload($tokenKey), $character));
$cast = runCommand($database, 'ability.use', [
    'requestId' => 'ability-timer-release-001', 'sceneId' => 'scene-one', 'sourceTokenId' => 'token-player',
    'targetTokenId' => 'token-player', 'abilityId' => 'undo-cooldown',
]);
specialCheck($cast->status === 200, 'Native ability causes a cooldown and spends resources.');
$activity = $database->payload('activity');
$actionId = $activity['combatActions'][0]['actionIds'][0];
$timer = $activity['actionTimers'][0];
$release = ['id' => $timer['id'], 'deletedAt' => '2026-01-01T00:00:00+00:00'];
$activity['actionTimers'] = [];
$activity['actionTimerTombstones'] = [$release];
$database->put('activity', $activity);
$undo = ['actionId' => $actionId, 'requestId' => 'undo-timer-release-native-001'];
$cancelled = runCommand($database, 'action.undo', $undo, true, 'account-gm');
specialCheck($cancelled->status === 200, 'A cooldown liberated by the MJ does not block combat undo.');
$activity = $database->payload('activity');
specialCheck($activity['actionTimers'] === [] && $activity['actionTimerTombstones'] === [$release], 'The liberated timer and its deletion marker preserve their state.');
specialCheck($database->payload($characterKey)['resources'] === $character['resources'], 'Native undo restores spent mana and the heal.');
specialCheck(($activity['combatActions'][0]['cancelled'] ?? false) === true && ($activity['resourceReceipts'][0]['result']['actionUndone'] ?? false) === true, 'Ledger and trusted resource receipt agree that the action was cancelled.');
$repeat = runCommand($database, 'action.undo', $undo, true, 'account-gm');
specialCheck($repeat->status === 200 && $repeat->body['deduplicated'] === true, 'An undo replay does not restore resources twice.');

function cooldownFixtureBase(): array {
    return [
        'table' => ['activeSceneId' => 'scene'],
        'character:caster' => ['resources' => ['mana' => 50, 'maxMana' => 50]],
        'activity' => ['playerActions' => [], 'actionTimers' => [], 'attackReceipts' => []],
    ];
}
function cooldownFixtureCast(array $before, string $id, array $timer, int $spend = 10): array {
    $after = $before;
    $after['character:caster']['resources']['mana'] -= $spend;
    $after['activity']['actionTimers'] = [$timer];
    array_unshift($after['activity']['playerActions'], ['id' => 'action-' . $id, 'kind' => 'ability', 'sceneId' => 'scene']);
    combatUndoRecord($before, $after, 'ability.use', ['requestId' => 'request-' . $id], [], 'combat-' . $id);
    return $after;
}
function cooldownFixtureUndo(array &$domains, string $id): void {
    combatUndoApply($domains, ['actionId' => 'action-' . $id, 'actorId' => 'gm', 'requestId' => 'undo-request-' . $id]);
}

$oldTimer = ['id' => 'timer-shared', 'abilityId' => 'focus', 'usedRound' => 1, 'readyRound' => 4,
    'cooldown' => 3, 'cooldownActive' => true, 'restRecharge' => 'short', 'restUseCount' => 1,
    'combatId' => 'combat-x', 'combatUseCount' => 1, 'turnKey' => 'turn-1', 'useCount' => 1];
$newTimer = [...$oldTimer, 'usedRound' => 4, 'readyRound' => 9, 'cooldown' => 5, 'turnKey' => 'turn-4',
    'restUseCount' => 2, 'combatUseCount' => 2];
$first = cooldownFixtureCast(cooldownFixtureBase(), 'first-0001', $oldTimer);
$later = cooldownFixtureCast($first, 'later-0001', $newTimer, 13);
cooldownFixtureUndo($later, 'first-0001');
$remaining = [...$newTimer, 'restUseCount' => 1, 'combatUseCount' => 1];
specialCheck($later['activity']['actionTimers'] === [$remaining], 'Previous cast undo keeps the newer cooldown, turn use and its own remaining rest/combat use.');
specialCheck($later['character:caster']['resources']['mana'] === 37.0, 'Only first spell mana is restored after a later cast.');
cooldownFixtureUndo($later, 'later-0001');
specialCheck($later['activity']['actionTimers'] === [] && $later['activity']['actionTimerTombstones'][0]['id'] === $oldTimer['id'],
    'Undoing the last reuse cannot resurrect the already cancelled creator cooldown.');
specialCheck($later['character:caster']['resources']['mana'] === 50.0, 'Both costs are restored once.');

$extended = $first;
$extended['activity']['actionTimers'][0]['readyRound'] = 12;
cooldownFixtureUndo($extended, 'first-0001');
specialCheck($extended['activity']['actionTimers'][0]['readyRound'] === 12
    && $extended['activity']['actionTimers'][0]['cooldownActive'] === true
    && $extended['activity']['actionTimers'][0]['restUseCount'] === 0, 'A manual extension survives while refunded capacity returns.');

$recharged = cooldownFixtureCast($first, 'reset-0001',
    [...$oldTimer, 'cooldown' => 0, 'cooldownActive' => false, 'readyRound' => 0, 'restUseCount' => 0, 'combatUseCount' => 0, 'useCount' => 0], 0);
$afterReset = cooldownFixtureCast($recharged, 'recent-001',
    [...$newTimer, 'restUseCount' => 1, 'combatUseCount' => 1, 'useCount' => 1], 9);
cooldownFixtureUndo($afterReset, 'first-0001');
specialCheck($afterReset['activity']['actionTimers'][0]['restUseCount'] === 1
    && $afterReset['activity']['actionTimers'][0]['combatUseCount'] === 1
    && $afterReset['activity']['actionTimers'][0]['useCount'] === 1, 'Earlier spent capacity already refunded by a verified reset does not subtract the new use.');

$weapon = ['id' => 'timer-weapon', 'kind' => 'weapon-turn-limit', 'combatId' => 'combat-x',
    'turnKey' => 'turn-1', 'useCount' => 1, 'requestIds' => ['first-strike']];
$firstWeapon = cooldownFixtureCast(cooldownFixtureBase(), 'weapon-001', $weapon, 0);
$secondWeapon = cooldownFixtureCast($firstWeapon, 'weapon-002', [...$weapon, 'useCount' => 2, 'requestIds' => ['first-strike', 'later-strike']], 0);
cooldownFixtureUndo($secondWeapon, 'weapon-001');
specialCheck($secondWeapon['activity']['actionTimers'][0]['useCount'] === 1
    && $secondWeapon['activity']['actionTimers'][0]['requestIds'] === ['later-strike'], 'Only the cancelled strike returns its weapon use.');

$before = cooldownFixtureBase();
$firstEffect = $before;
$firstEffect['activity']['attackEnhancements'] = [['id' => 'real-effect', 'charges' => 2]];
$firstEffect['activity']['playerActions'] = [['id' => 'action-effect1', 'kind' => 'ability', 'sceneId' => 'scene']];
combatUndoRecord($before, $firstEffect, 'ability.use', ['requestId' => 'request-effect1'], [], 'combat-effect1');
$usedEffect = $firstEffect;
$usedEffect['activity']['attackEnhancements'][0]['charges'] = 1;
array_unshift($usedEffect['activity']['playerActions'], ['id' => 'action-effect2', 'kind' => 'ability', 'sceneId' => 'scene']);
combatUndoRecord($firstEffect, $usedEffect, 'ability.use', ['requestId' => 'request-effect2'], [], 'combat-effect2');
try {
    cooldownFixtureUndo($usedEffect, 'effect1');
    specialCheck(false, 'Undoing an action with a consumed independent effect must be rejected.');
} catch (RuntimeException $error) {
    specialCheck($error->getCode() === 409, 'The dependency guard still protects later use of a real combat effect.');
}

echo json_encode(['ok' => true, 'checks' => $specialChecks, 'productionAccess' => false], JSON_THROW_ON_ERROR) . PHP_EOL;
