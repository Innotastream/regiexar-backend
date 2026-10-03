<?php
declare(strict_types=1);

$serpentAbility = ['id' => 'celestial-serpent', 'name' => 'Bottes du Serpent Céleste', 'effect' => 'complex', 'formula' => '0', 'castingStatId' => '', 'usesPerCombat' => 1, 'manaCost' => 0, 'cooldownRounds' => 0,
    'workflow' => ['version' => 8, 'steps' => [
        ['id' => 'move', 'type' => 'movement', 'maximumMeters' => 20],
        ['id' => 'targets', 'type' => 'targets', 'minTargets' => 0, 'maxTargets' => 20, 'maximumPerTarget' => 1, 'allocationTotal' => 0, 'rangeCells' => 0, 'sourceMovementStepId' => 'move', 'targetRelation' => 'enemies'],
        ['id' => 'strikes', 'type' => 'allocated-attacks', 'sourceStepId' => 'targets', 'awarenessMode' => 'none', 'damageMode' => 'configured', 'hitMode' => 'automatic', 'statId' => 'character-stat-agility',
            'damageComponents' => [['type' => 'magical', 'formula' => '1d30+6', 'ignoreArmor' => true]], 'defenseStatId' => 'character-stat-agility', 'defenseRollMode' => 'disadvantage'],
    ]]];
$serpentDb = fixture();
$sheet = $serpentDb->payload('character:character-player'); $sheet['stats']['agility'] = 75; $sheet['abilities'] = [$serpentAbility];
$serpentDb->put('character:character-player', $sheet);
$serpentDb->put('initiative:scene-one', ['active' => true, 'combatId' => 'serpent-combat', 'round' => 1, 'currentIndex' => 0, 'order' => ['token-player', 'token-monster']]);
$serpentDb->put('map:scene-one', ['grid' => false, 'gridSize' => 50, 'naturalWidth' => 1000, 'naturalHeight' => 1000]);
$foe = $serpentDb->payload('token:scene-one:token-monster');
$foe = [...$foe, 'x' => 35, 'y' => 50, 'hp' => 1000, 'maxHp' => 1000, 'magicArmorCategory' => 'special', 'magicArmor' => 100,
    'stats' => [['id' => 'agility', 'label' => 'Agilité', 'value' => '60'], ['id' => 'force', 'label' => 'Force', 'value' => '0']]];
requireTactical(validApplicationTokenDomain($foe), 'Serpent defense fixture is a valid persistent token.');
$serpentDb->put('token:scene-one:token-monster', $foe);
$start = runCommand($serpentDb, 'ability.complex', ['action' => 'start', 'sceneId' => 'scene-one', 'layerId' => 'ground', 'sourceTokenId' => 'token-player', 'abilityId' => $serpentAbility['id'], 'requestId' => 'serpent-start-request']);
requireTactical($start->status === 200 && is_array($start->body['execution'] ?? null), 'Serpent starts without casting roll: ' . $start->getMessage());
$serpentExecution = $start->body['execution'];
$advanceSerpent = static function(array $command) use ($serpentDb, &$serpentExecution): TestResponse {
    $result = runCommand($serpentDb, 'ability.complex', ['action' => 'command', 'executionId' => $serpentExecution['id'], 'expectedRevision' => $serpentExecution['revision'], 'requestId' => 'serpent-command-' . bin2hex(random_bytes(8)), 'command' => $command]);
    if (is_array($result->body['execution'] ?? null)) $serpentExecution = $result->body['execution'];
    return $result;
};
requireTactical($advanceSerpent(['action' => 'confirm-movement'])->status === 409, 'Serpent cannot skip actual movement.');
$tooFar = runCommand($serpentDb, 'token.move', ['sceneId' => 'scene-one', 'tokenId' => 'token-player', 'x' => 80, 'y' => 50]);
requireTactical($tooFar->status === 409 && $serpentDb->payload('token:scene-one:token-player')['x'] == 20, 'Over 20m is rejected without moving the token.');
$move = runCommand($serpentDb, 'token.move', ['sceneId' => 'scene-one', 'tokenId' => 'token-player', 'x' => 60, 'y' => 50]);
requireTactical($move->status === 200, 'Serpent accepts actual movement: ' . $move->getMessage());
$serpentExecution = $serpentDb->payload('activity')['abilityExecutions'][0];
requireTactical(abs($serpentExecution['stepStates']['move']['distanceMeters'] - 16) < 1e-5, 'Movement persists cumulative route distance.');
requireTactical($advanceSerpent(['action' => 'confirm-movement'])->status === 200, 'Moved token unlocks target selection.');
requireTactical($advanceSerpent(['action' => 'select-targets', 'allocations' => [['tokenId' => 'token-monster-two', 'count' => 1]]])->status === 409, 'Enemy outside the actual path is refused.');
requireTactical($advanceSerpent(['action' => 'select-targets', 'allocations' => [['tokenId' => 'token-monster', 'count' => 2]]])->status === 400, 'One hit per selected enemy is enforced.');
requireTactical($advanceSerpent(['action' => 'select-targets', 'allocations' => [['tokenId' => 'token-monster', 'count' => 1]], 'attackPlan' => [['tokenId' => 'token-monster', 'attackId' => $serpentAbility['id'], 'statId' => 'character-stat-agility', 'opposed' => true]]])->status === 200, 'Serpent prepares fixed ability damage without a casting gate.');
requireTactical($advanceSerpent(['action' => 'prepare-target', 'targetTokenId' => 'token-monster'])->status === 200, 'Serpent prepares the target.');
$attackBody = ['sceneId' => 'scene-one', 'sourceTokenId' => 'token-player', 'targetTokenId' => 'token-monster', 'attackKind' => 'ability', 'attackId' => $serpentAbility['id'], 'statId' => 'character-stat-agility', 'complexExecutionId' => $serpentExecution['id'], 'opposed' => true, 'requestId' => 'serpent-strike-request'];
$attack = runCommand($serpentDb, 'token.attack', $attackBody);
requireTactical($attack->status === 200 && $attack->body['attack']['status'] === 'awaiting-opposition', 'Serpent hit persists valid activity: ' . $attack->getMessage());
requireTactical(($attack->body['attack']['hit']['outcome']['automatic'] ?? false) && ($attack->body['hitRoll'] ?? null) === null && count($serpentDb->payload('activity')['rolls'] ?? []) === 0, 'No synthetic ATK is stored or streamed.');
requireTactical(runCommand($serpentDb, 'token.attack', $attackBody)->body['deduplicated'], 'Serpent hit receipt is idempotent.');
$defense = runCommand($serpentDb, 'token.attack.oppose', ['attackId' => $attack->body['attack']['id'], 'requestId' => 'serpent-defense-request', 'decision' => 'roll', 'statId' => 'character-stat-force', 'rollMode' => 'normal'], true, 'account-gm');
requireTactical($defense->status === 200, 'Serpent defense resolves: ' . $defense->getMessage());
$resolved = $defense->body['attack'];
requireTactical($resolved['opposition']['statId'] === 'agility' && $resolved['opposition']['rollMode'] === 'disadvantage' && count($resolved['opposition']['attempts']) === 2, 'Server enforces creature Agility disadvantage despite forged client options.');
if (($resolved['opposition']['outcome']['success'] ?? false) === true) requireTactical($resolved['finalDamage'] === 0, 'Successful dodge prevents automatic hit damage.');
else requireTactical($resolved['damage']['rawDamage'] >= 7 && $resolved['damage']['rawDamage'] <= 36 && $resolved['damage']['armorPercent'] === 0 && $resolved['damage']['finalDamage'] === $resolved['damage']['rawDamage'], '1d30+6 remains magical and ignores armor.');
$names = normalizeOnlineWeaponAttacks([], ['1d30+6', '1d20+4', '1d30+6'], "Fouet d30+6,\n2 Dague d20+4,\nArbalète de poing d30+6");
requireTactical(array_column($names, 'name') === ['Fouet', '2 Dague', 'Arbalète de poing'], 'Duplicate weapon formulas keep distinct names.');
