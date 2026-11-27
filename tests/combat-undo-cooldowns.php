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

$chainDatabase = specialFixture();
$chainCharacterKey = 'character:character-player';
$chainTargetKey = 'token:scene-one:token-monster';
$chainCharacter = $chainDatabase->payload($chainCharacterKey);
$chainCharacter['fatigue']['current'] = 12;
$chainCharacter['specialSuccessStock'] = 4;
$chainCharacter['abilities'] = [[
    'id' => 'undo-chain', 'name' => 'Enchaînement de recette', 'effect' => 'complex', 'formula' => '0',
    'castingStatId' => 'character-stat-agility', 'fatigueCost' => 5, 'cooldownRounds' => 5, 'restRecharge' => 'none',
    'workflow' => ['version' => 8, 'steps' => [[
        'id' => 'chain', 'type' => 'attack-chain', 'count' => 4, 'statId' => 'character-stat-agility',
        'firstGateAtCast' => true, 'criticalSuccessCompletesChain' => true, 'penaltyPerSuccess' => 10,
        'stopOnFailure' => true, 'resolutionMode' => 'batch', 'targetMode' => 'once', 'oppositionMode' => 'once',
        'damageMode' => 'configured', 'damageComponents' => [['type' => 'ignore', 'formula' => '12']],
        'allowOpposition' => true,
    ]]],
]];
$chainDatabase->put($chainCharacterKey, $chainCharacter);
$chainCast = runCommand($chainDatabase, 'ability.complex', [
    'action' => 'start', 'requestId' => 'undo-chain-cast-0001', 'sceneId' => 'scene-one',
    'sourceTokenId' => 'token-player', 'targetTokenId' => 'token-monster', 'abilityId' => 'undo-chain', 'useJoker' => true,
]);
specialCheck($chainCast->status === 200 && isset($chainCast->body['execution'])
    && (float) $chainDatabase->payload($chainCharacterKey)['fatigue']['current'] === 17.0,
    'Starting the actual complex ability charges the initial fatigue once: ' . $chainCast->getMessage());
$execution = $chainCast->body['execution'];
$chainActivity = $chainDatabase->payload('activity');
$chainTimer = $chainActivity['actionTimers'][0];
specialCheck($chainTimer['abilityId'] === 'undo-chain' && $chainTimer['cooldown'] === 5,
    'The initial launch starts the five-round cooldown.');

$prepared = runCommand($chainDatabase, 'ability.complex', [
    'action' => 'command', 'requestId' => 'undo-chain-prepare-01', 'executionId' => $execution['id'],
    'expectedRevision' => $execution['revision'], 'command' => [
        'action' => 'prepare-chain', 'targetTokenId' => 'token-monster', 'attackId' => 'undo-chain',
        'statId' => 'character-stat-agility', 'opposed' => true, 'useJoker' => true,
    ],
]);
specialCheck($prepared->status === 200 && $prepared->body['execution']['stepStates']['chain']['queuedAttackCount'] === 4,
    'Four actual gates are accepted for the chain: ' . $prepared->getMessage());
$execution = $prepared->body['execution'];
$strikeArgs = ['requestId' => 'undo-chain-strike-01', 'sceneId' => 'scene-one', 'sourceTokenId' => 'token-player',
    'targetTokenId' => 'token-monster', 'attackKind' => 'ability', 'attackId' => 'undo-chain', 'statId' => 'character-stat-agility',
    'complexExecutionId' => $execution['id'], 'opposed' => true];
$initialStrike = runCommand($chainDatabase, 'token.attack', $strikeArgs);
specialCheck($initialStrike->status === 200 && $initialStrike->body['attack']['status'] === 'awaiting-opposition',
    'The first actual strike waits for the unique opposition: ' . $initialStrike->getMessage());
$unconfirmedChainDomains = $chainDatabase->domains;
$confirmed = runCommand($chainDatabase, 'ability.complex', [
    'action' => 'command', 'requestId' => 'undo-chain-confirm-01', 'executionId' => $execution['id'],
    'expectedRevision' => $execution['revision'],
    'command' => ['action' => 'confirm-attack', 'attackRequestId' => $strikeArgs['requestId']],
]);
specialCheck($confirmed->status === 200, 'The first pending strike is recorded as part of the chain.');
$confirmedChainDomains = $chainDatabase->domains;

// The MJ control commits a removed timer and its deletion marker together.
$chainActivity = $chainDatabase->payload('activity');
$chainRelease = ['id' => $chainTimer['id'], 'deletedAt' => gmdate('c')];
$chainActivity['actionTimers'] = [];
$chainActivity['actionTimerTombstones'] = [$chainRelease];
$chainDatabase->put('activity', $chainActivity);
$fatigueAfterRelease = $chainDatabase->payload($chainCharacterKey)['fatigue']['current'];
specialCheck((float) $fatigueAfterRelease === 17.0, 'Returning the cooldown alone does not refund the cast.');

$chainAction = $chainActivity['combatActions'][0];
$targetHp = $chainDatabase->payload($chainTargetKey)['hp'];
$cancelArgs = ['actionId' => $chainAction['actionIds'][0], 'requestId' => 'undo-chain-full-0001'];
$chainCancel = runCommand($chainDatabase, 'action.undo', $cancelArgs, true, 'account-gm');
specialCheck($chainCancel->status === 200, 'The MJ can undo the full chain after returning the cooldown: ' . $chainCancel->getMessage());
$chainActivity = $chainDatabase->payload('activity');
specialCheck((float) $chainDatabase->payload($chainCharacterKey)['fatigue']['current'] === 12.0
    && $chainActivity['actionTimers'] === [] && $chainActivity['actionTimerTombstones'] === [$chainRelease],
    'Full undo refunds only the five charged fatigue and preserves the prior cooldown release.');
specialCheck($chainDatabase->payload($chainTargetKey)['hp'] === $targetHp && $chainActivity['pendingAttacks'] === []
    && $chainActivity['abilityExecutions'][0]['status'] === 'cancelled',
    'The waiting strike and execution both close without harming the target.');
$chainRepeat = runCommand($chainDatabase, 'action.undo', $cancelArgs, true, 'account-gm');
specialCheck($chainRepeat->status === 200 && $chainRepeat->body['deduplicated'] === true
    && (float) $chainDatabase->payload($chainCharacterKey)['fatigue']['current'] === 12.0,
    'Replaying the same cancellation cannot refund the cast again.');

$inhoId = 'ability-c2d11a48-40e5-46a6-b43a-05405d1a5362';
$attackId = $initialStrike->body['attack']['id'];
$refuseStrike = ['requestId' => 'undo-chain-refuse-01', 'attackId' => $attackId, 'decision' => 'reject'];
$oldSnapshots = [
    'before chain confirmation' => $unconfirmedChainDomains,
    'after chain confirmation' => $confirmedChainDomains,
    'chain without Inho policy' => $confirmedChainDomains,
];
foreach ($oldSnapshots as $case => $domains) {
    $fixture = new MemoryConnection([]);
    $fixture->domains = $domains;
    $activity = $fixture->payload('activity');
    $isInho = $case !== 'chain without Inho policy';
    if ($isInho) {
        // Reproduce a started execution whose original opposed workflow is still stored.
        $activity['abilityExecutions'][0]['abilityId'] = $inhoId;
    }
    $activity['actionTimers'] = [];
    $activity['actionTimerTombstones'] = [$chainRelease];
    $fixture->put('activity', $activity);
    $initiative = $fixture->payload('initiative:scene-one');
    $fixture->put('initiative:scene-one', [...$initiative, 'active' => false]);
    $defense = runCommand($fixture, 'token.attack.oppose', [
        'requestId' => 'undo-chain-defense-01', 'attackId' => $attackId, 'decision' => 'skip',
    ], true, 'account-gm');
    specialCheck($defense->status === 200 && ($defense->body['attack']['status'] ?? '') === 'pending',
        'The old opposition precedes a real pending validation, ' . $case . ': ' . $defense->getMessage());
    $before = $fixture->payload($chainTargetKey)['hp'];
    $reply = runCommand($fixture, 'token.attack.resolve', $refuseStrike, true, 'account-gm');
    specialCheck($reply->status === 200 && ($reply->body['attack']['status'] ?? '') === 'rejected',
        'The MJ explicitly rejects the pending first strike, ' . $case . ': ' . $reply->getMessage());
    $activity = $fixture->payload('activity');
    specialCheck($activity['pendingAttacks'] === [] && $activity['actionTimers'] === []
        && $fixture->payload($chainTargetKey)['hp'] === $before,
        'Refusing the validation applies no damage and preserves the released cooldown, ' . $case);
    if (!$isInho) {
        specialCheck(!isset($reply->body['refundedCosts']) && (float) $fixture->payload($chainCharacterKey)['fatigue']['current'] === 17.0,
            'A different attack chain keeps its rules.');
        continue;
    }
    specialCheck((float) ($reply->body['refundedCosts']['fatigue'] ?? 0) === 5.0
        && (float) $fixture->payload($chainCharacterKey)['fatigue']['current'] === 12.0
        && $activity['abilityExecutions'][0]['status'] === 'completed'
        && $activity['abilityExecutions'][0]['stepStates']['chain']['skippedAttackCount'] === 3,
        'Refusing the first strike restores the exact fatigue and closes the chain, ' . $case);
    $duplicate = runCommand($fixture, 'token.attack.resolve', $refuseStrike, true, 'account-gm');
    $undone = runCommand($fixture, 'action.undo', [
        'actionId' => $activity['combatActions'][0]['actionIds'][0], 'requestId' => 'undo-chain-after-refusal-001',
    ], true, 'account-gm');
    specialCheck($duplicate->status === 404 && $undone->status === 200
        && (float) $fixture->payload($chainCharacterKey)['fatigue']['current'] === 12.0,
        'A repeated refusal and a later complete undo cannot restore fatigue twice, ' . $case);
}

$inhoCharacter = $chainCharacter;
$inhoCharacter['abilities'][0]['id'] = $inhoId;
$inhoCastArgs = [
    'action' => 'start', 'requestId' => 'inho-chain-native-0001', 'sceneId' => 'scene-one',
    'sourceTokenId' => 'token-player', 'targetTokenId' => 'token-monster', 'abilityId' => $inhoId,
];
for ($attempt = 0; $attempt < 200; $attempt++) {
    $fixture = specialFixture();
    $fixture->put($chainCharacterKey, $inhoCharacter);
    $inhocast = runCommand($fixture, 'ability.complex', $inhoCastArgs);
    if (!empty($inhocast->body['pendingValidation'])) break;
}
specialCheck($attempt < 200 && (float) $fixture->payload($chainCharacterKey)['fatigue']['current'] === 17.0,
    'A native remarkable launch is charged while it awaits the MJ.');
$refuseLaunch = runCommand($fixture, 'ability.resolve', [
    'requestId' => 'inho-reject-cast-0001', 'validationId' => $inhocast->body['validation']['id'], 'decision' => 'reject',
], true, 'account-gm');
specialCheck($refuseLaunch->status === 200 && (float) ($refuseLaunch->body['refundedCosts']['fatigue'] ?? 0) === 5.0
    && (float) $fixture->payload($chainCharacterKey)['fatigue']['current'] === 12.0,
    'Refusal at the casting gate continues to refund via the ability validation route.');

$fixture = specialFixture();
$fixture->put($chainCharacterKey, $inhoCharacter);
$initiative = $fixture->payload('initiative:scene-one');
$fixture->put('initiative:scene-one', [...$initiative, 'active' => false]);
$inhoCast = runCommand($fixture, 'ability.complex', [...$inhoCastArgs, 'useJoker' => true]);
specialCheck($inhoCast->status === 200 && isset($inhoCast->body['execution']), 'The current Inho configuration starts.');
$inhoExecution = $inhoCast->body['execution'];
$prepared = runCommand($fixture, 'ability.complex', [
    'action' => 'command', 'requestId' => 'inho-native-prepare-01', 'executionId' => $inhoExecution['id'],
    'expectedRevision' => $inhoExecution['revision'], 'command' => [
        'action' => 'prepare-chain', 'targetTokenId' => 'token-monster', 'attackId' => $inhoId,
        'statId' => 'character-stat-agility', 'opposed' => false, 'useJoker' => true,
    ],
]);
specialCheck($prepared->status === 200, 'The current Inho chain prepares without opposition: ' . $prepared->getMessage());
$inhoExecution = $prepared->body['execution'];
$inhoStrikeArgs = [...$strikeArgs, 'requestId' => 'inho-native-strike-01', 'attackId' => $inhoId,
    'opposed' => false, 'complexExecutionId' => $inhoExecution['id']];
$firstStrike = runCommand($fixture, 'token.attack', $inhoStrikeArgs);
specialCheck($firstStrike->status === 200 && ($firstStrike->body['attack']['status'] ?? '') === 'pending',
    'Outside combat the current first strike reaches MJ damage validation: ' . $firstStrike->getMessage());
$firstStrikeRefusal = [...$refuseStrike, 'attackId' => $firstStrike->body['attack']['id']];
$firstStrikeFixture = new MemoryConnection([]);
$firstStrikeFixture->domains = $fixture->domains;
$firstStrikeActivity = $firstStrikeFixture->payload('activity');
specialCheck((float) ($firstStrikeActivity['abilityExecutions'][0]['castGate']['openingStrikeCost']['fatigueGained'] ?? 0) === 5.0,
    'The new execution retains its actual paid fatigue.');
$firstStrikeActivity['resourceReceipts'] = [];
$firstStrikeActivity['combatActions'] = [];
$firstStrikeFixture->put('activity', $firstStrikeActivity);
$reply = runCommand($firstStrikeFixture, 'token.attack.resolve', $firstStrikeRefusal, true, 'account-gm');
specialCheck($reply->status === 200 && (float) ($reply->body['refundedCosts']['fatigue'] ?? 0) === 5.0
    && (float) $firstStrikeFixture->payload($chainCharacterKey)['fatigue']['current'] === 12.0,
    'The MJ refusal refunds the first strike of the current rule after the journal is gone.');

$confirmed = runCommand($fixture, 'ability.complex', [
    'action' => 'command', 'requestId' => 'inho-native-confirm-01', 'executionId' => $inhoExecution['id'],
    'expectedRevision' => $inhoExecution['revision'],
    'command' => ['action' => 'confirm-attack', 'attackRequestId' => $inhoStrikeArgs['requestId']],
]);
specialCheck($confirmed->status === 200, 'The first pending strike is confirmed into the series.');
$nextStrike = runCommand($fixture, 'token.attack', [...$inhoStrikeArgs, 'requestId' => 'inho-native-strike-02']);
specialCheck($nextStrike->status === 200 && ($nextStrike->body['attack']['status'] ?? '') === 'pending',
    'The series can reserve its second strike while the first is awaiting damage approval.');
$reply = runCommand($fixture, 'token.attack.resolve', $firstStrikeRefusal, true, 'account-gm');
specialCheck($reply->status === 200 && !isset($reply->body['refundedCosts'])
    && (float) $fixture->payload($chainCharacterKey)['fatigue']['current'] === 17.0,
    'The first strike cannot refund the whole cast after the second is reserved.');
$reply = runCommand($fixture, 'token.attack.resolve', [...$refuseStrike, 'attackId' => $nextStrike->body['attack']['id']], true, 'account-gm');
specialCheck($reply->status === 200 && !isset($reply->body['refundedCosts'])
    && (float) $fixture->payload($chainCharacterKey)['fatigue']['current'] === 17.0,
    'Refusing the second strike does not refund the cast.');

$inhoCharacter['stats']['agility'] = 1;
for ($attempt = 0; $attempt < 100; $attempt++) {
    $fixture = specialFixture();
    $fixture->put($chainCharacterKey, $inhoCharacter);
    $fixture->put('initiative:scene-one', [...$initiative, 'active' => false]);
    $started = runCommand($fixture, 'ability.complex', [...$inhoCastArgs, 'useJoker' => true]);
    specialCheck($started->status === 200 && isset($started->body['execution']), 'The initial Joker gives the one certain success.');
    $execution = $started->body['execution'];
    $prepared = runCommand($fixture, 'ability.complex', [
        'action' => 'command', 'requestId' => 'inho-one-hit-prepare-01', 'executionId' => $execution['id'],
        'expectedRevision' => $execution['revision'], 'command' => [
            'action' => 'prepare-chain', 'targetTokenId' => 'token-monster', 'attackId' => $inhoId,
            'statId' => 'character-stat-agility', 'opposed' => false,
        ],
    ]);
    specialCheck($prepared->status === 200, 'The remaining rolls use the standard dice.');
    $execution = $prepared->body['execution'];
    if (($execution['stepStates']['chain']['queuedAttackCount'] ?? 0) === 1) break;
}
specialCheck($attempt < 100, 'A subsequent ordinary failure leaves only the initial strike.');
$singleStrike = runCommand($fixture, 'token.attack', [
    ...$inhoStrikeArgs, 'requestId' => 'inho-one-hit-strike-01', 'complexExecutionId' => $execution['id'],
]);
specialCheck($singleStrike->status === 200 && ($singleStrike->body['attack']['status'] ?? '') === 'pending',
    'The single strike is still waiting for validation.');
$confirmed = runCommand($fixture, 'ability.complex', [
    'action' => 'command', 'requestId' => 'inho-one-hit-confirm-01', 'executionId' => $execution['id'],
    'expectedRevision' => $execution['revision'],
    'command' => ['action' => 'confirm-attack', 'attackRequestId' => 'inho-one-hit-strike-01'],
]);
specialCheck($confirmed->status === 200 && $confirmed->body['execution']['status'] === 'completed',
    'The server can conclude the chain after queuing the only strike, before validation.');
$reply = runCommand($fixture, 'token.attack.resolve', [...$refuseStrike, 'attackId' => $singleStrike->body['attack']['id']], true, 'account-gm');
$execution = $fixture->payload('activity')['abilityExecutions'][0];
specialCheck($reply->status === 200 && (float) ($reply->body['refundedCosts']['fatigue'] ?? 0) === 5.0
    && (float) $fixture->payload($chainCharacterKey)['fatigue']['current'] === 12.0
    && $execution['status'] === 'completed' && $execution['stepStates']['chain']['attacks'][0]['status'] === 'rejected',
    'Refusing the only pending strike returns its fatigue without reopening the completed workflow.');

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
