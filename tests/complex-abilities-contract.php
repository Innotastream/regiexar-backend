<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/v1/domains.php';

function requireComplexAbility(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

requireComplexAbility(
    str_contains(applicationComplexAbilityWorkflowError(['version' => 3, 'steps' => [['type' => 'instruction']]]), 'format futur 3'),
    'A future workflow version is refused instead of being rewritten.'
);

$ability = [
    'id' => 'arvin-complex', 'name' => 'Enchaînement d’Arvin', 'effect' => 'complex', 'formula' => '0',
    'completionCue' => [
        'version' => 1, 'trigger' => 'completed',
        'sound' => ['url' => '/media/abcdefghijklmnopqrstuvwx', 'name' => 'Impact.wav', 'contentType' => 'audio/wav', 'byteSize' => 40044, 'durationMs' => 5000],
        'visual' => ['version' => 1, 'kind' => 'none'],
    ],
    'workflow' => ['version' => 1, 'steps' => [
        ['id' => 'targets', 'type' => 'targets', 'title' => 'Répartir', 'description' => '', 'minTargets' => 1, 'maxTargets' => 3, 'allocationTotal' => 5],
        ['id' => 'defenses', 'type' => 'defense-series', 'title' => 'Défendre', 'description' => '', 'sourceStepId' => 'targets', 'thresholdMode' => 'fixed', 'fixedThreshold' => 50, 'stopOnSuccess' => true],
        ['id' => 'orbs', 'type' => 'counter', 'title' => 'Orbes', 'description' => '', 'counterId' => 'blood-orb', 'counterLabel' => 'Orbes', 'initial' => 0, 'maximum' => 3, 'gainAmount' => 1, 'gainLabel' => 'Blessure', 'completionLabel' => 'Terminer',
            'spendOptions' => [['id' => 'harden', 'label' => 'Se surcir', 'description' => 'Parade', 'cost' => 1], ['id' => 'burst', 'label' => 'Exploser', 'description' => 'Dégâts', 'cost' => 1]]],
    ]],
];
$tokens = [
    ['id' => 'caster', 'name' => 'Arvin', 'controllerAccountId' => 'caster-player', 'hp' => 55, 'maxHp' => 100],
    ['id' => 'target-a', 'name' => 'Cible A', 'controllerAccountId' => 'player-a', 'hp' => 50, 'maxHp' => 100],
    ['id' => 'target-b', 'name' => 'Cible B', 'controllerAccountId' => 'player-b', 'hp' => 80, 'maxHp' => 100],
    ['id' => 'target-private', 'name' => 'Cible privée', 'hp' => 18, 'maxHp' => 100],
];
$execution = createApplicationComplexAbilityExecution([
    'id' => 'execution-contract', 'sceneId' => 'scene-one', 'sourceTokenId' => 'caster',
    'sourceName' => 'Arvin', 'controllerAccountId' => 'caster-player', 'controllerName' => 'Arvin',
    'ability' => $ability, 'now' => 1000,
]);
requireComplexAbility($execution['revision'] === 1 && $execution['currentStepIndex'] === 0, 'A new workflow starts at revision one.');
requireComplexAbility($execution['completionCue']['sound']['durationMs'] === 5000
    && $execution['completionCue']['visual']['kind'] === 'none',
    'A validated terminal sound and the dormant visual schema are snapshotted into the execution.');
$ability['completionCue']['sound']['durationMs'] = 1;
requireComplexAbility($execution['completionCue']['sound']['durationMs'] === 5000,
    'Editing the ability later cannot mutate an active execution sound.');
requireComplexAbility(!validApplicationAbilityCompletionCue([
    'version' => 1, 'trigger' => 'completed',
    'sound' => ['url' => '/media/abcdefghijklmnopqrstuvwx', 'name' => 'Trop long.wav', 'contentType' => 'audio/wav', 'byteSize' => 40045, 'durationMs' => 5001],
    'visual' => ['version' => 1, 'kind' => 'none'],
]), 'A terminal sound over five seconds is refused rather than rewritten.');
$legacyAbilityWrite = $ability;
unset($legacyAbilityWrite['completionCue']);
$legacyAbilityWrite['completionCue'] = preserveApplicationAbilityRows([$legacyAbilityWrite], [[...$ability, 'completionCue' => $execution['completionCue']]])[0]['completionCue'] ?? null;
requireComplexAbility(($legacyAbilityWrite['completionCue']['sound']['durationMs'] ?? 0) === 5000,
    'A draining older client cannot erase the terminal sound by omitting the new field.');
$explicitRemoval = preserveApplicationAbilityRows([[
    ...$legacyAbilityWrite, 'completionCue' => normalizeApplicationAbilityCompletionCue(null),
]], [[...$ability, 'completionCue' => $execution['completionCue']]])[0];
requireComplexAbility($explicitRemoval['completionCue']['sound'] === null,
    'The current client can explicitly remove a terminal sound.');

$before = $execution;
try {
    applyApplicationComplexAbilityCommand($execution, ['action' => 'select-targets', 'expectedRevision' => 0], [
        'actor' => ['id' => 'caster-player', 'name' => 'Arvin', 'role' => 'player'], 'tokens' => $tokens, 'now' => 1100,
    ]);
    throw new RuntimeException('A stale revision must fail.');
} catch (ApplicationComplexAbilityException $error) {
    requireComplexAbility($error->errorCode === 'complex_ability_revision_conflict', 'A stale revision has an explicit conflict code.');
}
requireComplexAbility($execution === $before, 'A refused command never mutates its input.');

$execution = applyApplicationComplexAbilityCommand($execution, [
    'action' => 'select-targets', 'expectedRevision' => 1,
    'allocations' => [['tokenId' => 'target-a', 'count' => 3], ['tokenId' => 'target-b', 'count' => 2]],
], ['actor' => ['id' => 'caster-player', 'name' => 'Arvin', 'role' => 'player'], 'tokens' => $tokens, 'now' => 1200]);
requireComplexAbility($execution['currentStepIndex'] === 1 && count($execution['stepStates']['defenses']['targets']) === 2,
    'Target validation initializes the following defense step for immediate participant access.');

try {
    applyApplicationComplexAbilityCommand($execution, ['action' => 'defense-roll', 'expectedRevision' => 2, 'targetTokenId' => 'target-a'], [
        'actor' => ['id' => 'stranger', 'name' => 'Intrus', 'role' => 'player'], 'tokens' => $tokens,
        'roll' => static fn(string $formula): array => ['formula' => $formula, 'total' => 1, 'rawD100' => 1, 'breakdown' => '[1]'], 'now' => 1250,
    ]);
    throw new RuntimeException('A stranger must not defend.');
} catch (ApplicationComplexAbilityException $error) {
    requireComplexAbility($error->httpStatus === 403, 'A non-participant is forbidden.');
}

$rolls = [90, 40, 30];
$roll = static function (string $formula) use (&$rolls): array {
    $value = array_shift($rolls);
    return ['formula' => $formula, 'total' => $value, 'rawD100' => $value, 'breakdown' => '[' . $value . ']'];
};
$execution = applyApplicationComplexAbilityCommand($execution, ['action' => 'defense-roll', 'expectedRevision' => 2, 'targetTokenId' => 'target-a'], [
    'actor' => ['id' => 'player-a', 'name' => 'A', 'role' => 'player'], 'tokens' => $tokens, 'roll' => $roll, 'now' => 1300,
]);
requireComplexAbility($execution['stepStates']['defenses']['targets'][0]['status'] === 'pending', 'A failed first defense keeps the same target active.');
$execution = applyApplicationComplexAbilityCommand($execution, ['action' => 'defense-roll', 'expectedRevision' => 3, 'targetTokenId' => 'target-a'], [
    'actor' => ['id' => 'player-a', 'name' => 'A', 'role' => 'player'], 'tokens' => $tokens, 'roll' => $roll, 'now' => 1400,
]);
$first = $execution['stepStates']['defenses']['targets'][0];
requireComplexAbility($first['parryFromAttack'] === 2 && $first['parryCount'] === 2,
    'The first success permits parrying the current attack and every remaining attack.');
$execution = applyApplicationComplexAbilityCommand($execution, ['action' => 'defense-roll', 'expectedRevision' => 4, 'targetTokenId' => 'target-b'], [
    'actor' => ['id' => 'player-b', 'name' => 'B', 'role' => 'player'], 'tokens' => $tokens, 'roll' => $roll, 'now' => 1500,
]);
requireComplexAbility($execution['currentStepIndex'] === 2 && $execution['stepStates']['defenses']['status'] === 'completed',
    'Every target is resolved in order before the counter step.');

for ($index = 0; $index < 3; $index += 1) {
    $execution = applyApplicationComplexAbilityCommand($execution, ['action' => 'counter-gain', 'expectedRevision' => 5 + $index], [
        'actor' => ['id' => 'caster-player', 'name' => 'Arvin', 'role' => 'player'], 'tokens' => $tokens, 'now' => 1600 + $index,
    ]);
}
requireComplexAbility($execution['stepStates']['orbs']['value'] === 3, 'The persistent counter reaches its exact maximum.');
try {
    applyApplicationComplexAbilityCommand($execution, ['action' => 'counter-gain', 'expectedRevision' => 8], [
        'actor' => ['id' => 'caster-player', 'name' => 'Arvin', 'role' => 'player'], 'tokens' => $tokens, 'now' => 1700,
    ]);
    throw new RuntimeException('A fourth orb must fail.');
} catch (ApplicationComplexAbilityException $error) {
    requireComplexAbility($error->errorCode === 'complex_ability_counter_maximum', 'The maximum is explicit.');
}
$execution = applyApplicationComplexAbilityCommand($execution, ['action' => 'counter-spend', 'expectedRevision' => 8, 'optionId' => 'burst'], [
    'actor' => ['id' => 'caster-player', 'name' => 'Arvin', 'role' => 'player'], 'tokens' => $tokens, 'now' => 1800,
]);
$serialized = json_decode(json_encode($execution, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
$resumed = normalizeApplicationComplexAbilityExecution($serialized);
requireComplexAbility($resumed['stepStates']['orbs']['value'] === 2, 'Serialization preserves charges and step progress.');
$cancelled = applyApplicationComplexAbilityCommand($resumed, ['action' => 'cancel', 'expectedRevision' => 9], [
    'actor' => ['id' => 'caster-player', 'name' => 'Arvin', 'role' => 'player'], 'tokens' => $tokens, 'now' => 1900,
]);
requireComplexAbility($cancelled['status'] === 'cancelled' && $cancelled['stepStates']['orbs']['value'] === 2,
    'Stopping midway is terminal without rolling back acquired state.');
try {
    applyApplicationComplexAbilityCommand($cancelled, ['action' => 'counter-gain', 'expectedRevision' => 10], [
        'actor' => ['id' => 'caster-player', 'name' => 'Arvin', 'role' => 'player'], 'tokens' => $tokens, 'now' => 2000,
    ]);
    throw new RuntimeException('A terminal workflow must reject later commands.');
} catch (ApplicationComplexAbilityException $error) {
    requireComplexAbility($error->errorCode === 'complex_ability_terminal', 'A cancelled workflow stays terminal.');
}

$conditionAbility = [
    'id' => 'conditional', 'name' => 'Seuil de sang', 'effect' => 'complex', 'formula' => '0',
    'workflow' => ['version' => 2, 'steps' => [
        ['id' => 'targets', 'type' => 'targets', 'title' => 'Cibles', 'minTargets' => 1, 'maxTargets' => 2],
        ['id' => 'half-life', 'type' => 'condition', 'title' => 'À mi-vie', 'subject' => 'targets',
            'sourceStepId' => 'targets', 'aggregation' => 'any', 'fact' => 'hp-percent', 'operator' => 'lte',
            'value' => 50, 'onTrueStepId' => 'weakened', 'onFalseStepId' => 'finish'],
        ['id' => 'weakened', 'type' => 'instruction', 'title' => 'Appliquer l’effet prévu'],
    ]],
];
$conditional = createApplicationComplexAbilityExecution([
    'id' => 'execution-condition', 'sceneId' => 'scene-one', 'sourceTokenId' => 'caster',
    'sourceName' => 'Arvin', 'controllerAccountId' => 'caster-player', 'controllerName' => 'Arvin',
    'ability' => $conditionAbility, 'now' => 3000,
]);
$conditional = applyApplicationComplexAbilityCommand($conditional, [
    'action' => 'select-targets', 'expectedRevision' => 1,
    'allocations' => [['tokenId' => 'target-a', 'count' => 1], ['tokenId' => 'target-b', 'count' => 1]],
], ['actor' => ['id' => 'caster-player', 'name' => 'Arvin', 'role' => 'player'], 'tokens' => $tokens, 'now' => 3100]);
$conditional = applyApplicationComplexAbilityCommand($conditional, ['action' => 'evaluate-condition', 'expectedRevision' => 2], [
    'actor' => ['id' => 'gm-owner', 'name' => 'MJ', 'role' => 'gm'], 'tokens' => $tokens, 'now' => 3200,
]);
requireComplexAbility($conditional['currentStepIndex'] === 2 && $conditional['stepStates']['half-life']['outcome'] === true,
    'Exactly fifty percent satisfies an at-most-fifty-percent condition and follows its forward branch.');
requireComplexAbility($conditional['stepStates']['half-life']['matchedCount'] === 1
    && $conditional['stepStates']['half-life']['subjectCount'] === 2,
    'A condition receipt exposes only the boolean aggregate and counts.');

$criticalWorkflow = normalizeApplicationComplexAbilityWorkflow(['version' => 2, 'steps' => [[
    'id' => 'critical', 'type' => 'condition', 'title' => 'Critique', 'fact' => 'health-state',
    'operator' => 'eq', 'value' => 'Critique', 'onTrueStepId' => 'finish', 'onFalseStepId' => 'finish',
]]]);
$criticalExecution = createApplicationComplexAbilityExecution([
    'id' => 'execution-critical', 'sceneId' => 'scene-one', 'sourceTokenId' => 'caster',
    'sourceName' => 'Arvin', 'controllerAccountId' => 'caster-player', 'controllerName' => 'Arvin',
    'ability' => ['id' => 'critical-check', 'name' => 'Critique', 'effect' => 'complex', 'formula' => '0', 'workflow' => $criticalWorkflow],
    'now' => 3300,
]);
$criticalStep = $criticalWorkflow['steps'][0];
requireComplexAbility(evaluateApplicationComplexAbilityCondition($criticalExecution, $criticalStep, [[...$tokens[0], 'hp' => 10]], true)['outcome'] === false,
    'Ten percent is not critical.');
requireComplexAbility(evaluateApplicationComplexAbilityCondition($criticalExecution, $criticalStep, [[...$tokens[0], 'hp' => 9]], true)['outcome'] === true,
    'Below ten percent is critical.');

$privateAbility = $conditionAbility;
$privateAbility['workflow']['steps'][0]['maxTargets'] = 1;
$privateAbility['workflow']['steps'][1]['fact'] = 'hp';
$privateAbility['workflow']['steps'][1]['value'] = 20;
$privateExecution = createApplicationComplexAbilityExecution([
    'id' => 'execution-private', 'sceneId' => 'scene-one', 'sourceTokenId' => 'caster',
    'sourceName' => 'Arvin', 'controllerAccountId' => 'caster-player', 'controllerName' => 'Arvin',
    'ability' => $privateAbility, 'now' => 3400,
]);
$privateExecution = applyApplicationComplexAbilityCommand($privateExecution, [
    'action' => 'select-targets', 'expectedRevision' => 1,
    'allocations' => [['tokenId' => 'target-private', 'count' => 1]],
], ['actor' => ['id' => 'caster-player', 'name' => 'Arvin', 'role' => 'player'], 'tokens' => $tokens, 'now' => 3500]);
try {
    applyApplicationComplexAbilityCommand($privateExecution, ['action' => 'evaluate-condition', 'expectedRevision' => 2], [
        'actor' => ['id' => 'caster-player', 'name' => 'Arvin', 'role' => 'player'], 'tokens' => $tokens, 'now' => 3600,
    ]);
    throw new RuntimeException('A player must not probe exact hostile health.');
} catch (ApplicationComplexAbilityException $error) {
    requireComplexAbility($error->errorCode === 'complex_ability_condition_private' && $error->httpStatus === 403,
        'Private tactical facts have an explicit refusal.');
}
$sharedTokens = array_map(static fn(array $token): array => ($token['id'] ?? '') === 'target-private'
    ? [...$token, 'tacticalDetailsShared' => true] : $token, $tokens);
$privateExecution = applyApplicationComplexAbilityCommand($privateExecution, ['action' => 'evaluate-condition', 'expectedRevision' => 2], [
    'actor' => ['id' => 'caster-player', 'name' => 'Arvin', 'role' => 'player'], 'tokens' => $sharedTokens, 'now' => 3700,
]);
requireComplexAbility($privateExecution['stepStates']['half-life']['outcome'] === true,
    'A player can evaluate the same condition after the MJ shares tactical details.');

$terminalExecutions = [];
for ($index = 0; $index < 70; $index += 1) {
    $terminal = $cancelled;
    $terminal['id'] = 'terminal-' . $index;
    $terminal['updatedAt'] = $index + 1;
    $terminalExecutions[] = $terminal;
}
$retained = trimApplicationComplexAbilityExecutions([$resumed, ...$terminalExecutions]);
requireComplexAbility(count($retained) === XAR_COMPLEX_ABILITY_MAXIMUM_EXECUTIONS && $retained[0]['id'] === $resumed['id'],
    'An old active execution is retained ahead of newer terminal history.');

echo "complex-abilities-contract: ok\n";
