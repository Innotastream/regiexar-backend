<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/v1/domains.php';
require_once __DIR__ . '/../api/v1/online.php';

function requireComplexAbility(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

requireComplexAbility(
    str_contains(applicationComplexAbilityWorkflowError(['version' => 5, 'steps' => [['type' => 'instruction']]]), 'format futur 5'),
    'A future workflow version is refused instead of being rewritten.'
);

$ability = [
    'id' => 'arvin-complex', 'name' => 'Enchaînement d’Arvin', 'effect' => 'complex', 'formula' => '0',
    'onHitConditions' => ['Empoisonné'],
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
requireComplexAbility($execution['onHitConditions'] === ['Empoisonné'], 'Complex conditions are snapshotted at launch.');
$ability['onHitConditions'] = ['Entravé'];
requireComplexAbility(normalizeApplicationComplexAbilityExecution($execution)['onHitConditions'] === ['Empoisonné'], 'Later edits do not change an active complex execution.');
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

$classic = ['id' => 'arvin-classic', 'name' => 'Frappe', 'effect' => 'damage',
    'formula' => '1d6', 'completionCue' => $execution['completionCue']];
requireComplexAbility(validApplicationAbilities([$classic]), 'A classic ability accepts a validated sound.');
$normalizedClassic = normalizeOnlineAbilities([$classic])[0];
requireComplexAbility(($normalizedClassic['completionCue']['sound']['url'] ?? '') === '/media/abcdefghijklmnopqrstuvwx',
    'The classic sound survives server normalization.');
$legacyClassic = $classic;
unset($legacyClassic['completionCue']);
requireComplexAbility(isset(preserveApplicationAbilityRows([$legacyClassic], [$classic])[0]['completionCue']),
    'An older client cannot erase a classic sound it does not know about.');
requireComplexAbility(preserveApplicationAbilityRows([[
    ...$classic, 'completionCue' => normalizeApplicationAbilityCompletionCue(null),
]], [$classic])[0]['completionCue']['sound'] === null,
    'The classic sound can be removed explicitly.');
requireComplexAbility(!validApplicationAbilities([[
    ...$classic, 'completionCue' => ['version' => 1, 'trigger' => 'completed',
        'sound' => ['url' => 'https://example.com/untrusted.wav'], 'visual' => ['version' => 1, 'kind' => 'none']],
]]), 'A classic ability cannot bypass sound reference validation.');

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
requireComplexAbility(evaluateApplicationComplexAbilityCondition($criticalExecution, $criticalStep, [[...$tokens[0], 'hp' => 10]], true)['outcome'] === true,
    'Ten percent is critical, inclusively.');
requireComplexAbility(evaluateApplicationComplexAbilityCondition($criticalExecution, $criticalStep, [[...$tokens[0], 'hp' => 9]], true)['outcome'] === true,
    'Below ten percent is critical.');
requireComplexAbility(evaluateApplicationComplexAbilityCondition($criticalExecution, $criticalStep, [[...$tokens[0], 'hp' => 11]], true)['outcome'] === false,
    'Above ten percent is not critical.');

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

$chainAbility = ['id' => 'generic-chain', 'name' => 'Série configurable', 'effect' => 'complex', 'formula' => '0',
    'workflow' => ['version' => 3, 'steps' => [['id' => 'chain', 'type' => 'attack-chain', 'title' => 'Série',
        'statId' => 'character-stat-agility', 'count' => 4, 'penaltyPerSuccess' => 10, 'stopOnFailure' => true]]]];
requireComplexAbility(applicationComplexAbilityWorkflowError($chainAbility['workflow']) === '', 'A generic attack chain is editable.');
$chain = createApplicationComplexAbilityExecution(['id' => 'execution-generic-chain', 'sceneId' => 'scene-one',
    'sourceTokenId' => 'caster', 'controllerAccountId' => 'caster-player', 'ability' => $chainAbility, 'now' => 4000]);
$chainTokens = [[...$tokens[0], 'stats' => [['id' => 'character-stat-agility', 'label' => 'Agilité', 'value' => 70]]], ...array_slice($tokens, 1)];
$chainContext = ['actor' => ['id' => 'caster-player', 'name' => 'Arvin', 'role' => 'player'], 'tokens' => $chainTokens, 'now' => 4100,
    'roll' => static fn(string $formula): array => ['formula' => $formula, 'rawD100' => 65, 'total' => 65, 'breakdown' => '65']];
$chain = applyApplicationComplexAbilityCommand($chain, ['action' => 'gate-roll', 'expectedRevision' => 1], $chainContext);
requireComplexAbility($chain['stepStates']['chain']['awaitingAttack'] === true
    && $chain['stepStates']['chain']['rolls'][0]['threshold'] === 70, 'The first check uses current Agility.');
try {
    applyApplicationComplexAbilityCommand($chain, ['action' => 'gate-roll', 'expectedRevision' => 2], $chainContext);
    throw new RuntimeException('A second gate must wait for the actual attack.');
} catch (ApplicationComplexAbilityException $error) {
    requireComplexAbility($error->errorCode === 'complex_ability_attack_pending', 'The pending attack blocks a new gate.');
}
$chain['stepStates']['chain']['attackRequestId'] = 'request-attack-123456';
$attack = ['id' => 'attack-real', 'requestId' => 'request-attack-123456', 'attackKind' => 'weapon',
    'complexExecutionId' => $chain['id'], 'sceneId' => 'scene-one', 'sourceTokenId' => 'caster', 'accountId' => 'caster-player',
    'targetTokenId' => 'target-a', 'targetName' => 'Cible A', 'status' => 'applied', 'createdAt' => 4101];
$chain = applyApplicationComplexAbilityCommand($chain, ['action' => 'confirm-attack', 'expectedRevision' => 2,
    'attackRequestId' => $attack['requestId']], [...$chainContext, 'attack' => $attack, 'now' => 4200]);
requireComplexAbility(count($chain['stepStates']['chain']['attacks']) === 1, 'The authoritative weapon attack is counted once.');
$chain = applyApplicationComplexAbilityCommand($chain, ['action' => 'gate-roll', 'expectedRevision' => 3],
    [...$chainContext, 'now' => 4300, 'roll' => static fn(string $formula): array => ['formula' => $formula,
        'rawD100' => 61, 'total' => 61, 'breakdown' => '61']]);
requireComplexAbility($chain['stepStates']['chain']['rolls'][1]['threshold'] === 60
    && $chain['status'] === 'completed' && $chain['endedByFailure'] === true
    && count($chain['stepStates']['chain']['attacks']) === 1, 'The progressive penalty and first failure stop the series.');

$allocatedAbility = ['id' => 'generic-allocated', 'name' => 'Cinq frappes', 'effect' => 'complex', 'formula' => '0',
    'workflow' => ['version' => 3, 'steps' => [
        ['id' => 'targets', 'type' => 'targets', 'title' => 'Répartir', 'minTargets' => 1, 'maxTargets' => 5, 'allocationTotal' => 5],
        ['id' => 'strikes', 'type' => 'allocated-attacks', 'title' => 'Frappes', 'sourceStepId' => 'targets',
            'awarenessStatId' => 'character-stat-instinct', 'damagePercent' => 50],
    ]]];
requireComplexAbility(applicationComplexAbilityWorkflowError($allocatedAbility['workflow']) === '', 'Allocated attacks are a generic editable step.');
requireComplexAbility(preserveApplicationAbilityRows([['id' => 'generic-allocated', 'effect' => 'complex',
    'workflow' => ['version' => 2, 'steps' => [['id' => 'strikes', 'type' => 'instruction']]]]], [$allocatedAbility])[0]['workflow'] === $allocatedAbility['workflow'],
    'An older client cannot downgrade an unknown complex workflow.');
$allocated = createApplicationComplexAbilityExecution(['id' => 'execution-allocated', 'sceneId' => 'scene-one',
    'sourceTokenId' => 'caster', 'controllerAccountId' => 'caster-player', 'ability' => $allocatedAbility, 'now' => 5000]);
$allocatedTokens = [$tokens[0], [...$tokens[1], 'stats' => [['id' => 'character-stat-instinct', 'value' => 55]]], $tokens[2]];
$owner = ['id' => 'caster-player', 'name' => 'Arvin', 'role' => 'player'];
$defender = ['id' => 'player-a', 'name' => 'A', 'role' => 'player'];
$allocated = applyApplicationComplexAbilityCommand($allocated, ['action' => 'select-targets', 'expectedRevision' => 1,
    'allocations' => [['tokenId' => 'target-a', 'count' => 5]]], ['actor' => $owner, 'tokens' => $allocatedTokens, 'now' => 5100]);
$allocated = applyApplicationComplexAbilityCommand($allocated, ['action' => 'prepare-target', 'expectedRevision' => 2,
    'targetTokenId' => 'target-a'], ['actor' => $owner, 'tokens' => $allocatedTokens, 'now' => 5200]);
$projection = publicApplicationComplexAbilityExecution($allocated, 'player-a', false, $allocatedTokens);
requireComplexAbility(($projection['participantOnly'] ?? false) === true && count($projection['stepStates']['strikes']['targets'] ?? []) === 1
    && ($projection['controllerAccountId'] ?? 'nonempty') === '', 'Only the pending defender sees their own awareness prompt.');
try {
    applyApplicationComplexAbilityCommand($allocated, ['action' => 'awareness-roll', 'expectedRevision' => 3], [
        'actor' => $owner, 'tokens' => $allocatedTokens, 'now' => 5300,
        'roll' => static fn(string $formula): array => ['rawD100' => 1, 'total' => 1]]);
    throw new RuntimeException('The attacker cannot roll for the defender.');
} catch (ApplicationComplexAbilityException $error) {
    requireComplexAbility($error->httpStatus === 403, 'Awareness is authorized for the target or GM only.');
}
$allocated = applyApplicationComplexAbilityCommand($allocated, ['action' => 'awareness-roll', 'expectedRevision' => 3], [
    'actor' => $defender, 'tokens' => $allocatedTokens, 'now' => 5300,
    'roll' => static fn(string $formula): array => ['rawD100' => 90, 'total' => 90]]);
requireComplexAbility(($allocated['stepStates']['strikes']['targets'][0]['aware'] ?? null) === false, 'A failed awareness prevents opposition.');
$allocated['stepStates']['strikes']['attackRequestId'] = 'request-first-123456';
$first = ['id' => 'attack-first', 'requestId' => 'request-first-123456', 'attackKind' => 'weapon',
    'attackId' => 'sabre-glace', 'complexExecutionId' => $allocated['id'], 'sceneId' => 'scene-one',
    'sourceTokenId' => 'caster', 'targetTokenId' => 'target-a', 'accountId' => 'caster-player',
    'opposed' => false, 'damagePercent' => 50, 'status' => 'applied', 'createdAt' => 5301];
try {
    applyApplicationComplexAbilityCommand($allocated, ['action' => 'confirm-attack', 'expectedRevision' => 4,
        'attackRequestId' => $first['requestId']], ['actor' => $owner, 'tokens' => $allocatedTokens,
        'attack' => [...$first, 'opposed' => true], 'now' => 5400]);
    throw new RuntimeException('A failed awareness cannot open opposition.');
} catch (ApplicationComplexAbilityException $error) {
    requireComplexAbility($error->errorCode === 'complex_ability_attack_mismatch', 'A forged opposition is rejected.');
}
$allocated = applyApplicationComplexAbilityCommand($allocated, ['action' => 'confirm-attack', 'expectedRevision' => 4,
    'attackRequestId' => $first['requestId']], ['actor' => $owner, 'tokens' => $allocatedTokens, 'attack' => $first, 'now' => 5400]);
$allocated = applyApplicationComplexAbilityCommand($allocated, ['action' => 'prepare-target', 'expectedRevision' => 5,
    'targetTokenId' => 'target-a'], ['actor' => $owner, 'tokens' => $allocatedTokens, 'now' => 5500]);
$allocated = applyApplicationComplexAbilityCommand($allocated, ['action' => 'awareness-roll', 'expectedRevision' => 6], [
    'actor' => $defender, 'tokens' => $allocatedTokens, 'now' => 5600,
    'roll' => static fn(string $formula): array => ['rawD100' => 30, 'total' => 30]]);
$allocated['stepStates']['strikes']['attackRequestId'] = 'request-second-123456';
$second = [...$first, 'id' => 'attack-second', 'requestId' => 'request-second-123456',
    'attackId' => 'sabre-feu', 'opposed' => true, 'createdAt' => 5601];
$allocated = applyApplicationComplexAbilityCommand($allocated, ['action' => 'confirm-attack', 'expectedRevision' => 7,
    'attackRequestId' => $second['requestId']], ['actor' => $owner, 'tokens' => $allocatedTokens, 'attack' => $second, 'now' => 5700]);
$allocated = applyApplicationComplexAbilityCommand($allocated, ['action' => 'prepare-target', 'expectedRevision' => 8,
    'targetTokenId' => 'target-a'], ['actor' => $owner, 'tokens' => $allocatedTokens, 'now' => 5800]);
requireComplexAbility(($allocated['stepStates']['strikes']['awaitingAwareness'] ?? true) === false
    && ($allocated['stepStates']['strikes']['targets'][0]['aware'] ?? false) === true,
    'Successful awareness applies to the current and later strikes without another check, even with a new weapon.');
$damage = onlineRollAttackDamage(['damageComponents' => [['formula' => '20', 'type' => 'physical']],
    'damagePercent' => 50], ['armorCategory' => 'medium', 'armor' => 20],
    static fn(string $formula): array => ['total' => 20, 'breakdown' => '20']);
requireComplexAbility(($damage['damage']['rawDamage'] ?? null) === 10 && ($damage['damage']['finalDamage'] ?? null) === 8,
    'One-weapon fifty percent damage is applied before armor.');

$persistentAbility = ['id' => 'persistent-orbs', 'name' => 'Orbes configurables', 'effect' => 'complex', 'formula' => '0',
    'workflow' => ['version' => 4, 'steps' => [[
        'id' => 'charges', 'type' => 'counter', 'title' => 'Orbes', 'counterLabel' => 'Orbes',
        'initial' => 0, 'maximum' => 3, 'gainAmount' => 1, 'gainTrigger' => 'bleeding-hp-loss',
        'radiusCells' => 2, 'spendOncePerTurn' => true, 'combatPersistent' => true,
        'spendOptions' => [['id' => 'burst', 'label' => 'Explosion', 'cost' => 1]],
    ]]]];
requireComplexAbility(applicationComplexAbilityWorkflowError($persistentAbility['workflow']) === '',
    'A persistent blood counter can be built without a named-character rule.');
$persistent = createApplicationComplexAbilityExecution(['id' => 'execution-persistent', 'sceneId' => 'scene-one',
    'sourceTokenId' => 'caster', 'controllerAccountId' => 'caster-player', 'ability' => $persistentAbility,
    'combatId' => 'combat-one', 'now' => 6000]);
$map = ['naturalWidth' => 1000, 'naturalHeight' => 1000, 'gridSize' => 100];
$source = [...$tokens[0], 'x' => 10, 'y' => 10, 'layerId' => 'ground'];
$bleeding = [...$tokens[1], 'x' => 20, 'y' => 10, 'layerId' => 'ground', 'conditions' => ['Saignement']];
$gain = static fn(array $entry, array $target, int $hpLost, string $combatId = 'combat-one'): array =>
    applicationComplexAbilityGainBleedingCharges([$entry], ['sceneId' => 'scene-one', 'layerId' => 'ground',
        'target' => $target, 'tokens' => [$source, $target], 'map' => $map, 'hpLost' => $hpLost,
        'combatId' => $combatId, 'now' => $entry['updatedAt'] + 1])[0];
foreach ([$gain($persistent, $bleeding, 0), $gain($persistent, [...$bleeding, 'conditions' => []], 1),
    $gain($persistent, [...$bleeding, 'x' => 31], 1), $gain($persistent, $bleeding, 1, 'combat-two')] as $unchanged) {
    requireComplexAbility($unchanged['revision'] === 1, 'No bleeding, HP loss, proximity, or combat means no gain.');
}
for ($index = 0; $index < 4; $index += 1) $persistent = $gain($persistent, $bleeding, 1);
requireComplexAbility($persistent['stepStates']['charges']['value'] === 3 && $persistent['revision'] === 4,
    'Repeated injuries fill the counter to exactly three charges.');
$persistent = applyApplicationComplexAbilityCommand($persistent, ['action' => 'counter-spend', 'optionId' => 'burst',
    'expectedRevision' => 4], ['actor' => $owner, 'combatActive' => true, 'turnKey' => 'combat-one:turn-1', 'now' => 6100]);
requireComplexAbility($persistent['stepStates']['charges']['value'] === 2, 'One use consumes one charge.');
try {
    applyApplicationComplexAbilityCommand($persistent, ['action' => 'counter-spend', 'optionId' => 'burst',
        'expectedRevision' => 5], ['actor' => $owner, 'combatActive' => true, 'turnKey' => 'combat-one:turn-1', 'now' => 6200]);
    throw new RuntimeException('A second free use in the same turn must fail.');
} catch (ApplicationComplexAbilityException $error) {
    requireComplexAbility($error->errorCode === 'complex_ability_turn_spend_limit', 'The turn limit has a specific refusal.');
}
$manuallyEnded = applyApplicationComplexAbilityCommand($persistent, ['action' => 'cancel', 'expectedRevision' => 5],
    ['actor' => $owner, 'now' => 6300]);
requireComplexAbility($manuallyEnded['status'] === 'cancelled' && $gain($manuallyEnded, $bleeding, 1)['revision'] === 6,
    'An interrupted persistent spell cannot gain a charge afterward.');

echo "complex-abilities-contract: ok\n";
