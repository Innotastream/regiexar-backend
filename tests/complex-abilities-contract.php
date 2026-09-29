<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/v1/domains.php';
require_once __DIR__ . '/../api/v1/online.php';

function requireComplexAbility(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

requireComplexAbility(
    str_contains(applicationComplexAbilityWorkflowError(['version' => 7, 'steps' => [['type' => 'instruction']]]), 'format futur 7'),
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

foreach (['advantage' => [0, 20, true], 'disadvantage' => [1, 80, false]] as $mode => [$selectedIndex, $total, $success]) {
    $defended = applyApplicationComplexAbilityCommand($execution, [
        'action' => 'defense-roll', 'expectedRevision' => 2, 'targetTokenId' => 'target-a', 'rollMode' => $mode,
    ], [
        'actor' => ['id' => 'player-a', 'name' => 'A', 'role' => 'player'], 'tokens' => $tokens, 'now' => 1220,
        'roll' => static function (string $formula, string $requestedMode, bool $d100RollUnder) use ($mode, $selectedIndex, $total): array {
            requireComplexAbility($formula === '1d100' && $requestedMode === $mode && $d100RollUnder,
                'The defender mode reaches the d100 roll service.');
            return ['formula' => $formula, 'total' => $total, 'rawD100' => $total, 'selectedIndex' => $selectedIndex,
                'attempts' => [['total' => 20, 'rawD100' => 20, 'breakdown' => '20'],
                    ['total' => 80, 'rawD100' => 80, 'breakdown' => '80']]];
        },
    ]);
    $defense = $defended['stepStates']['defenses']['targets'][0]['rolls'][0];
    requireComplexAbility($defense['rollMode'] === $mode && $defense['selectedIndex'] === $selectedIndex
        && count($defense['attempts']) === 2 && $defense['success'] === $success,
        'Both dice and the selected success are stored for defense ' . $mode . '.');
}

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

$markerAbility = ['id' => 'configurable-marker', 'name' => 'Balises', 'effect' => 'complex',
    'workflow' => ['version' => 4, 'steps' => [['id' => 'orbs', 'type' => 'counter', 'title' => 'Balises',
        'initial' => 3, 'maximum' => 3, 'combatPersistent' => true, 'resetOnEnd' => true,
        'gainTrigger' => 'hp-loss', 'gainCondition' => 'Brûlure', 'radiusCells' => 2,
        'spendOptions' => [
            ['id' => 'mine', 'label' => 'Mine fixe', 'cost' => 1, 'effect' => ['kind' => 'marker', 'movable' => false]],
            ['id' => 'mobile', 'label' => 'Balise mobile', 'cost' => 1, 'effect' => ['kind' => 'marker', 'movable' => true]],
        ],
    ]]],
];
$markerTokens = [[...$tokens[0], 'x' => 20, 'y' => 35, 'layerId' => 'ground'],
    [...$tokens[1], 'x' => 30, 'y' => 35, 'layerId' => 'ground']];
$markerExecution = createApplicationComplexAbilityExecution([
    'id' => 'marker-contract', 'sceneId' => 'scene-one', 'sourceTokenId' => 'caster',
    'controllerAccountId' => 'caster-player', 'ability' => $markerAbility, 'combatId' => 'combat-one', 'now' => 1000,
]);
$markerContext = ['actor' => ['id' => 'caster-player', 'name' => 'Arvin', 'role' => 'player'],
    'tokens' => $markerTokens, 'combatActive' => true, 'turnKey' => 'combat-one:turn-1', 'now' => 1001];
$markerExecution = applyApplicationComplexAbilityCommand($markerExecution,
    ['action' => 'counter-spend', 'expectedRevision' => 1, 'optionId' => 'mine'], $markerContext);
$placed = applicationComplexAbilityPlacedMarkers([$markerExecution], 'scene-one', 'ground');
requireComplexAbility(count($placed) === 1 && $placed[0]['x'] === 20.0 && $placed[0]['movable'] === false,
    'A fixed marker is a projected execution effect at the caster position.');
requireComplexAbility($markerExecution['stepStates']['orbs']['value'] === 3 && count($markerExecution['stepStates']['orbs']['deployments']) === 1,
    'A placed marker occupies one of the maximum slots without consuming the total.');
try {
    applyApplicationComplexAbilityCommand($markerExecution, ['action' => 'marker-move', 'expectedRevision' => 2,
        'markerId' => $placed[0]['id'], 'x' => 40, 'y' => 40], $markerContext);
    throw new RuntimeException('A fixed marker cannot move.');
} catch (ApplicationComplexAbilityException $error) {
    requireComplexAbility($error->errorCode === 'complex_ability_marker_fixed', 'Immobility is configured per marker option.');
}
$markerExecution = applyApplicationComplexAbilityCommand($markerExecution,
    ['action' => 'marker-remove', 'expectedRevision' => 2, 'markerId' => $placed[0]['id']], $markerContext);
requireComplexAbility($markerExecution['stepStates']['orbs']['value'] === 2
    && applicationComplexAbilityPlacedMarkers([$markerExecution]) === [], 'Owner deletion frees a slot.');
$markerExecution = applyApplicationComplexAbilityCommand($markerExecution,
    ['action' => 'counter-spend', 'expectedRevision' => 3, 'optionId' => 'mobile'], $markerContext);
$mobile = applicationComplexAbilityPlacedMarkers([$markerExecution])[0];
$markerExecution = applyApplicationComplexAbilityCommand($markerExecution,
    ['action' => 'marker-move', 'expectedRevision' => 4, 'markerId' => $mobile['id'], 'x' => 45, 'y' => 60], $markerContext);
requireComplexAbility(applicationComplexAbilityPlacedMarkers([$markerExecution])[0]['x'] === 45.0,
    'Another skill can configure a movable marker.');
$markerExecution = applyApplicationComplexAbilityCommand($markerExecution,
    ['action' => 'cancel', 'expectedRevision' => 5], $markerContext);
requireComplexAbility($markerExecution['stepStates']['orbs']['value'] === 0
    && applicationComplexAbilityPlacedMarkers([$markerExecution]) === [], 'Stopping the skill clears markers and its counter.');
$hpContext = ['sceneId' => 'scene-one', 'layerId' => 'ground', 'tokens' => $markerTokens,
    'map' => ['naturalWidth' => 1000, 'naturalHeight' => 1000, 'gridSize' => 100], 'hpLost' => 2,
    'combatId' => 'combat-one', 'target' => $markerTokens[1]];
$gainAbility = $markerAbility;
$gainAbility['workflow']['steps'][0]['initial'] = 0;
$fresh = createApplicationComplexAbilityExecution([
    'id' => 'gain-contract', 'sceneId' => 'scene-one', 'sourceTokenId' => 'caster',
    'controllerAccountId' => 'caster-player', 'ability' => $gainAbility, 'combatId' => 'combat-one', 'now' => 1000,
]);
requireComplexAbility(applicationComplexAbilityGainBleedingCharges([$fresh], $hpContext)[0]['stepStates']['orbs']['value'] === 0,
    'The chosen condition is required for this HP loss trigger.');
$hpContext['target']['conditions'] = ['Brûlure'];
requireComplexAbility(applicationComplexAbilityGainBleedingCharges([$fresh], $hpContext)[0]['stepStates']['orbs']['value'] === 1,
    'A configured condition on the injured target produces exactly one charge.');
$caseVariant = $fresh;
$caseVariant['workflow']['steps'][0]['gainCondition'] = 'BRULURE';
requireComplexAbility(applicationComplexAbilityGainBleedingCharges([$caseVariant], $hpContext)[0]['stepStates']['orbs']['value'] === 1,
    'A custom condition matches regardless of case and accent.');

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

$singlePlanAbility = ['id' => 'generic-single-plan', 'name' => 'Série sans répétition', 'effect' => 'complex',
    'castingStatId' => 'character-stat-agility', 'workflow' => ['version' => 5, 'steps' => [[
        'id' => 'chain', 'type' => 'attack-chain', 'statId' => 'character-stat-agility', 'count' => 5,
        'penaltyPerSuccess' => 10, 'stopOnFailure' => true, 'targetMode' => 'once', 'firstGateAtCast' => true,
    ]]]];
$single = createApplicationComplexAbilityExecution(['id' => 'execution-single-plan', 'sceneId' => 'scene-one',
    'sourceTokenId' => 'caster', 'controllerAccountId' => 'caster-player', 'ability' => $singlePlanAbility, 'now' => 6000,
    'cast' => ['success' => true, 'statId' => 'character-stat-agility',
        'outcome' => ['raw' => 32, 'threshold' => 70, 'code' => 'success'], 'roll' => ['breakdown' => '32']]]);
requireComplexAbility(count($single['stepStates']['chain']['rolls']) === 1
    && $single['stepStates']['chain']['awaitingAttack'] === true, 'The cast is the first gate, never rolled twice.');
$singleTokens = [[...$chainTokens[0], 'weaponAttacks' => [['id' => 'blade', 'formula' => '1d6']]], ...array_slice($chainTokens, 1)];
$single = applyApplicationComplexAbilityCommand($single, ['action' => 'prepare-chain', 'expectedRevision' => 1,
    'targetTokenId' => 'target-a', 'attackId' => 'blade', 'statId' => 'character-stat-agility', 'opposed' => true],
    ['actor' => $chainContext['actor'], 'tokens' => $singleTokens, 'now' => 6001]);
$single['stepStates']['chain']['attackRequestId'] = 'request-single-plan';
$plannedAttack = ['id' => 'attack-planned', 'requestId' => 'request-single-plan', 'attackKind' => 'weapon',
    'attackId' => 'blade', 'complexExecutionId' => $single['id'], 'sceneId' => 'scene-one',
    'sourceTokenId' => 'caster', 'accountId' => 'caster-player', 'targetTokenId' => 'target-a',
    'opposed' => true, 'hit' => ['statId' => 'character-stat-agility'], 'status' => 'applied', 'createdAt' => 6002];
try {
    applyApplicationComplexAbilityCommand($single, ['action' => 'confirm-attack', 'expectedRevision' => 2,
        'attackRequestId' => 'request-single-plan'], ['actor' => $chainContext['actor'], 'tokens' => $singleTokens,
        'now' => 6003, 'attack' => [...$plannedAttack, 'targetTokenId' => 'target-b']]);
    throw new RuntimeException('A locked series cannot switch target.');
} catch (ApplicationComplexAbilityException $error) {
    requireComplexAbility($error->errorCode === 'complex_ability_attack_mismatch', 'The server rejects a switched target.');
}
$single = applyApplicationComplexAbilityCommand($single, ['action' => 'confirm-attack', 'expectedRevision' => 2,
    'attackRequestId' => 'request-single-plan'], ['actor' => $chainContext['actor'], 'tokens' => $singleTokens,
    'now' => 6003, 'attack' => $plannedAttack]);
requireComplexAbility(count($single['stepStates']['chain']['attacks']) === 1, 'The planned attack advances once.');

$predicate = ['type' => 'all', 'conditions' => [
    ['type' => 'fact', 'subject' => 'source', 'fact' => 'missing-hp', 'operator' => 'gte', 'value' => 40],
    ['type' => 'any', 'conditions' => [
        ['type' => 'fact', 'subject' => 'source', 'fact' => 'mana', 'operator' => 'gte', 'value' => 8],
        ['type' => 'not', 'condition' => ['type' => 'fact', 'subject' => 'source', 'fact' => 'condition',
            'operator' => 'eq', 'conditionLabel' => 'Influencé']],
    ]],
]];
$conditionalAbility = ['id' => 'generic-expression', 'name' => 'Condition composée', 'effect' => 'complex',
    'workflow' => ['version' => 5, 'steps' => [['id' => 'branch', 'type' => 'condition', 'expression' => $predicate,
        'onTrueStepId' => 'finish', 'onFalseStepId' => 'finish']]]];
requireComplexAbility(applicationComplexAbilityWorkflowError($conditionalAbility['workflow']) === '', 'Nested predicates validate.');
$conditional = createApplicationComplexAbilityExecution(['id' => 'execution-condition', 'sceneId' => 'scene-one',
    'sourceTokenId' => 'caster', 'controllerAccountId' => 'caster-player', 'ability' => $conditionalAbility, 'now' => 6100]);
$predicateTokens = [[...$tokens[0], 'hp' => 55, 'maxHp' => 100, 'mana' => 8], ...array_slice($tokens, 1)];
requireComplexAbility(evaluateApplicationComplexAbilityCondition($conditional, $conditional['workflow']['steps'][0], $predicateTokens)['outcome'] === true,
    'Nested all/any/not evaluates live authoritative resources.');

$allocatedAbility = ['id' => 'generic-allocated', 'name' => 'Cinq frappes', 'effect' => 'complex', 'formula' => '0',
    'workflow' => ['version' => 3, 'steps' => [
        ['id' => 'targets', 'type' => 'targets', 'title' => 'Répartir', 'minTargets' => 1, 'maxTargets' => 5, 'allocationTotal' => 5],
        ['id' => 'strikes', 'type' => 'allocated-attacks', 'title' => 'Frappes', 'sourceStepId' => 'targets',
            'awarenessStatId' => 'character-stat-instinct', 'damagePercent' => 50],
    ]]];
requireComplexAbility(applicationComplexAbilityWorkflowError($allocatedAbility['workflow']) === '', 'Allocated attacks are a generic editable step.');
requireComplexAbility(normalizeApplicationComplexAbilityWorkflow($allocatedAbility['workflow'])['steps'][1]['awarenessMode'] === 'required',
    'An existing allocated attack preserves its awareness gate when the setting is absent.');
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

$noGateAbility = ['id' => 'generic-strikes', 'name' => 'Répartition libre', 'effect' => 'complex', 'formula' => '0',
    'workflow' => ['version' => 4, 'steps' => [
        ['id' => 'targets', 'type' => 'targets', 'minTargets' => 1, 'maxTargets' => 2, 'allocationTotal' => 2],
        ['id' => 'strikes', 'type' => 'allocated-attacks', 'sourceStepId' => 'targets',
            'awarenessMode' => 'none', 'awarenessStatId' => 'character-stat-invalid', 'damagePercent' => 100],
    ]]];
requireComplexAbility(applicationComplexAbilityWorkflowError($noGateAbility['workflow']) === '',
    'A multi-target ability without awareness does not need an awareness statistic.');
$noGate = createApplicationComplexAbilityExecution(['id' => 'execution-no-gate', 'sceneId' => 'scene-one',
    'sourceTokenId' => 'caster', 'controllerAccountId' => 'caster-player', 'ability' => $noGateAbility, 'now' => 8000]);
$noGate = applyApplicationComplexAbilityCommand($noGate, ['action' => 'select-targets', 'expectedRevision' => $noGate['revision'],
    'allocations' => [['tokenId' => 'target-a', 'count' => 1], ['tokenId' => 'target-b', 'count' => 1]]],
    ['actor' => $owner, 'tokens' => $allocatedTokens, 'now' => 8100]);
foreach (['target-a', 'target-b'] as $index => $targetId) {
    $noGate = applyApplicationComplexAbilityCommand($noGate, ['action' => 'prepare-target', 'expectedRevision' => $noGate['revision'],
        'targetTokenId' => $targetId], ['actor' => $owner, 'tokens' => $allocatedTokens, 'now' => 8200 + $index * 200]);
    requireComplexAbility($noGate['stepStates']['strikes']['awaitingAwareness'] === false
        && $noGate['stepStates']['strikes']['awaitingAttack'] === true,
        'A multi-target strike without awareness goes directly to the attack.');
    $requestId = 'request-no-gate-' . ($index + 1) . '-123456';
    $noGate['stepStates']['strikes']['attackRequestId'] = $requestId;
    $attack = ['id' => 'attack-no-gate-' . ($index + 1), 'requestId' => $requestId, 'attackKind' => 'weapon',
        'attackId' => 'sabre-glace', 'complexExecutionId' => $noGate['id'], 'sceneId' => 'scene-one',
        'sourceTokenId' => 'caster', 'targetTokenId' => $targetId, 'accountId' => 'caster-player',
        'opposed' => $index === 0, 'damagePercent' => 100, 'status' => 'applied', 'createdAt' => 8300 + $index * 200];
    $noGate = applyApplicationComplexAbilityCommand($noGate, ['action' => 'confirm-attack',
        'expectedRevision' => $noGate['revision'], 'attackRequestId' => $requestId],
        ['actor' => $owner, 'tokens' => $allocatedTokens, 'attack' => $attack, 'now' => 8300 + $index * 200]);
}
requireComplexAbility($noGate['status'] === 'completed'
    && ($noGate['stepStates']['strikes']['targets'][0]['attacks'][0]['opposed'] ?? null) === true
    && ($noGate['stepStates']['strikes']['targets'][1]['attacks'][0]['opposed'] ?? null) === false,
    'Opposition remains selectable for each target without an awareness roll.');

$rangedAbility = ['id' => 'ranged-attacks', 'name' => 'Répartition à portée', 'effect' => 'complex', 'formula' => '0',
    'workflow' => ['version' => 4, 'steps' => [
        ['id' => 'targets', 'type' => 'targets', 'minTargets' => 1, 'maxTargets' => 3, 'allocationTotal' => 5, 'rangeCells' => 2],
        ['id' => 'strikes', 'type' => 'allocated-attacks', 'sourceStepId' => 'targets', 'awarenessMode' => 'required', 'damagePercent' => 50],
    ]]];
$ranged = createApplicationComplexAbilityExecution(['id' => 'execution-range', 'sceneId' => 'scene-one',
    'sourceTokenId' => 'caster', 'controllerAccountId' => 'caster-player', 'ability' => $rangedAbility, 'now' => 9000]);
$rangedMap = ['naturalWidth' => 1000, 'naturalHeight' => 1000, 'gridSize' => 100];
$rangedTokens = [[...$tokens[0], 'x' => 10, 'y' => 10], [...$tokens[1], 'x' => 30, 'y' => 10],
    [...$tokens[2], 'x' => 41, 'y' => 10]];
try {
    applyApplicationComplexAbilityCommand($ranged, ['action' => 'select-targets', 'expectedRevision' => 1,
        'allocations' => [['tokenId' => 'target-b', 'count' => 5]]],
        ['actor' => $owner, 'tokens' => $rangedTokens, 'map' => $rangedMap, 'now' => 9010]);
    throw new RuntimeException('A target outside two grid cells must fail.');
} catch (ApplicationComplexAbilityException $error) {
    requireComplexAbility($error->errorCode === 'complex_ability_target_out_of_range', 'Range is enforced by the backend.');
}
$ranged = applyApplicationComplexAbilityCommand($ranged, ['action' => 'select-targets', 'expectedRevision' => 1,
    'allocations' => [['tokenId' => 'target-a', 'count' => 5]]],
    ['actor' => $owner, 'tokens' => $rangedTokens, 'map' => $rangedMap, 'now' => 9010]);
requireComplexAbility(($ranged['stepStates']['strikes']['targets'][0]['tokenId'] ?? '') === 'target-a'
    && ($ranged['stepStates']['strikes']['targets'][0]['attackCount'] ?? 0) === 5,
    'A selected target is ready on entry to allocated attacks without a preparatory command.');

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
requireComplexAbility($manuallyEnded['status'] === 'cancelled' && $manuallyEnded['stepStates']['charges']['value'] === 0
    && $gain($manuallyEnded, $bleeding, 1)['revision'] === 6,
    'An interrupted persistent spell immediately loses its remaining orbs and cannot gain another.');
$retainedWorkflow = $persistentAbility['workflow'];
$retainedWorkflow['steps'][0]['resetOnEnd'] = false;
$retainedAbility = [...$persistentAbility, 'id' => 'retained-counter', 'workflow' => $retainedWorkflow];
$retained = createApplicationComplexAbilityExecution(['id' => 'execution-retained', 'sceneId' => 'scene-one',
    'sourceTokenId' => 'caster', 'controllerAccountId' => 'caster-player', 'ability' => $retainedAbility,
    'combatId' => 'combat-one', 'now' => 6400]);
$retained = $gain($retained, $bleeding, 1);
$retained = applyApplicationComplexAbilityCommand($retained, ['action' => 'cancel', 'expectedRevision' => 2],
    ['actor' => $owner, 'now' => 6500]);
requireComplexAbility($retained['stepStates']['charges']['value'] === 1,
    'Another persistent counter can explicitly retain its value on termination.');

$onceAbility = ['id' => 'once-per-target', 'name' => 'Série libre', 'effect' => 'complex', 'formula' => '0',
    'workflow' => ['version' => 4, 'steps' => [
        ['id' => 'targets', 'type' => 'targets', 'minTargets' => 1, 'maxTargets' => 2, 'allocationTotal' => 3],
        ['id' => 'strikes', 'type' => 'allocated-attacks', 'sourceStepId' => 'targets',
            'awarenessMode' => 'once', 'awarenessStatId' => 'character-stat-instinct', 'damagePercent' => 50],
    ]]];
$onceTokens = [[...$tokens[0], 'stats' => [['id' => 'character-stat-instinct', 'value' => 60]],
    'weaponAttacks' => [['id' => 'ice', 'formula' => '1d8'], ['id' => 'fire', 'formula' => '1d6']]],
    [...$tokens[1], 'stats' => [['id' => 'character-stat-instinct', 'value' => 55]]],
    [...$tokens[2], 'stats' => [['id' => 'character-stat-instinct', 'value' => 40]]]];
$once = createApplicationComplexAbilityExecution(['id' => 'execution-once', 'sceneId' => 'scene-one',
    'sourceTokenId' => 'caster', 'controllerAccountId' => 'caster-player', 'ability' => $onceAbility, 'now' => 11000]);
$onceRolls = [20, 80];
$plan = [['tokenId' => 'target-a', 'attackId' => 'ice', 'statId' => 'character-stat-instinct', 'opposed' => true],
    ['tokenId' => 'target-b', 'attackId' => 'fire', 'statId' => 'character-stat-instinct', 'opposed' => true],
    ['tokenId' => 'target-a', 'attackId' => 'fire', 'statId' => 'character-stat-instinct', 'opposed' => true]];
$once = applyApplicationComplexAbilityCommand($once, ['action' => 'select-targets', 'expectedRevision' => 1,
    'allocations' => [['tokenId' => 'target-a', 'count' => 2], ['tokenId' => 'target-b', 'count' => 1]],
    'attackPlan' => $plan], ['actor' => $owner, 'tokens' => $onceTokens, 'now' => 11001,
    'roll' => static function (string $formula) use (&$onceRolls): array {
        $raw = array_shift($onceRolls); return ['rawD100' => $raw, 'total' => $raw];
    }]);
requireComplexAbility($onceRolls === [] && $once['stepStates']['strikes']['targets'][0]['aware'] === true
    && $once['stepStates']['strikes']['targets'][1]['aware'] === false,
    'Each target rolls exactly once before the first strike, with independent parry rights.');
$once = applyApplicationComplexAbilityCommand($once, ['action' => 'prepare-target', 'expectedRevision' => $once['revision'],
    'targetTokenId' => 'target-b'], ['actor' => $owner, 'tokens' => $onceTokens, 'now' => 11002]);
requireComplexAbility($once['stepStates']['strikes']['awaitingAwareness'] === false
    && $once['stepStates']['strikes']['awaitingAttack'] === true,
    'Failed initial awareness never asks for a second roll and proceeds without opposition.');
$reportExecution = [...$once, 'status' => 'completed', 'stepStates' => [
    ...$once['stepStates'], 'strikes' => [...$once['stepStates']['strikes'], 'targets' => [
        [...$once['stepStates']['strikes']['targets'][0], 'attacks' => [['requestId' => 'first'], ['requestId' => 'third']]],
        [...$once['stepStates']['strikes']['targets'][1], 'attacks' => [['requestId' => 'second']]],
    ]],
]];
$receipts = [];
foreach (['first', 'second', 'third'] as $index => $id) $receipts[] = ['requestId' => $id, 'attack' => [
    'complexExecutionId' => $once['id'], 'requestId' => $id,
    'targetTokenId' => $index === 1 ? 'target-b' : 'target-a',
    'targetName' => $index === 1 ? 'Cible B' : 'Cible A', 'visibility' => 'public',
    'status' => 'applied', 'hit' => ['raw' => 30 + $index, 'outcome' => ['success' => true]],
    'appliedDamage' => $index === 1 ? 0 : 8,
]];
$report = onlineCompactComplexResult($reportExecution, $receipts);
requireComplexAbility(is_string($report) && substr_count($report, 'ATK') === 3 && substr_count($report, 'VIG') === 2
    && str_contains($report, 'Dégâts :'), 'One compact report contains the attacks, initial awareness and damage.');
$receipts[1]['attack']['status'] = 'awaiting-opposition';
requireComplexAbility(onlineCompactComplexResult($reportExecution, $receipts) === null,
    'The single report waits for all eligible oppositions.');

$guardWorkflow = ['version' => 4, 'steps' => [
    ['id' => 'self', 'type' => 'guard', 'scope' => 'source', 'percent' => 50],
    ['id' => 'nearby', 'type' => 'targets', 'minTargets' => 0, 'maxTargets' => 3, 'rangeCells' => 2],
    ['id' => 'others', 'type' => 'guard', 'scope' => 'targets', 'sourceStepId' => 'nearby', 'percent' => 25],
]];
requireComplexAbility(applicationComplexAbilityWorkflowError($guardWorkflow) === '',
    'Separate guard and target steps are valid without a named character.');
$guard = createApplicationComplexAbilityExecution(['id' => 'generic-guard', 'sceneId' => 'scene-one',
    'sourceTokenId' => 'caster', 'controllerAccountId' => 'caster-player',
    'ability' => ['id' => 'generic-protection', 'name' => 'Protection modulable', 'effect' => 'complex',
        'formula' => '0', 'workflow' => $guardWorkflow], 'now' => 12000]);
$nearbyTokens = [[...$tokens[0], 'x' => 10, 'y' => 10], [...$tokens[1], 'x' => 20, 'y' => 10],
    [...$tokens[2], 'x' => 40, 'y' => 10]];
try {
    applyApplicationComplexAbilityCommand($guard, ['action' => 'apply-guard', 'expectedRevision' => 1],
        ['actor' => $owner, 'tokens' => $nearbyTokens, 'combatActive' => false]);
    throw new RuntimeException('Guard should require active combat.');
} catch (ApplicationComplexAbilityException $error) {
    requireComplexAbility($error->errorCode === 'complex_ability_guard_outside_combat', 'A guard requires combat.');
}
$guard = applyApplicationComplexAbilityCommand($guard, ['action' => 'apply-guard', 'expectedRevision' => 1],
    ['actor' => $owner, 'tokens' => $nearbyTokens, 'combatActive' => true, 'now' => 12001]);
requireComplexAbility($guard['stepStates']['self']['protectedTokenIds'] === ['caster'],
    'The first module protects only the source.');
try {
    applyApplicationComplexAbilityCommand($guard, ['action' => 'select-targets', 'expectedRevision' => 2,
        'allocations' => [['tokenId' => 'target-b', 'count' => 1]]],
        ['actor' => $owner, 'tokens' => $nearbyTokens, 'map' => $map, 'now' => 12002]);
    throw new RuntimeException('Out-of-range protection should fail.');
} catch (ApplicationComplexAbilityException $error) {
    requireComplexAbility($error->errorCode === 'complex_ability_target_out_of_range', 'The target range is enforced.');
}
$guard = applyApplicationComplexAbilityCommand($guard, ['action' => 'select-targets', 'expectedRevision' => 2,
    'allocations' => [['tokenId' => 'target-a', 'count' => 1]]],
    ['actor' => $owner, 'tokens' => $nearbyTokens, 'map' => $map, 'now' => 12003]);
$guard = applyApplicationComplexAbilityCommand($guard, ['action' => 'apply-guard', 'expectedRevision' => 3],
    ['actor' => $owner, 'tokens' => $nearbyTokens, 'combatActive' => true, 'now' => 12004]);
requireComplexAbility($guard['status'] === 'completed' && $guard['stepStates']['others']['protectedTokenIds'] === ['target-a']
    && $guard['workflow']['steps'][0]['percent'] === 50 && $guard['workflow']['steps'][2]['percent'] === 25,
    'Independent target protections retain their own strengths.');

$reaction = ['id' => 'configurable-reaction', 'name' => 'Riposte', 'effect' => 'opposition', 'formula' => '0',
    'cooldownRounds' => 3, 'opposition' => ['statId' => 'character-stat-force', 'options' => [
        ['id' => 'free', 'label' => 'Sans mana', 'manaCost' => 0, 'reflectPercent' => 25],
        ['id' => 'paid', 'label' => 'Avec mana', 'manaCost' => 10, 'reflectPercent' => 50],
    ]]];
requireComplexAbility(validApplicationAbilityEffects($reaction)
    && applicationAbilityEffectFields($reaction)['opposition']['options'][1]['reflectPercent'] === 50,
    'A configurable defensive ability retains its choices and cooldown.');
$periodic = ['id' => 'configurable-dot', 'name' => 'Effet récurrent', 'effect' => 'damage',
    'formula' => '0', 'damageComponents' => [['type' => 'physical', 'formula' => '0']],
    'damageOverTime' => ['label' => 'Poison', 'formula' => '2d6', 'damageType' => 'ignore', 'turns' => 3]];
requireComplexAbility(validApplicationAbilityEffects($periodic)
    && applicationAbilityEffectFields($periodic)['damageOverTime']['turns'] === 3,
    'Periodic damage is part of the damage effect and may have zero initial damage.');

echo "complex-abilities-contract: ok\n";


$batchAbility = ['id' => 'batch-formula', 'name' => 'Combo complet', 'workflow' => ['version' => 6, 'steps' => [[
    'id' => 'chain', 'type' => 'attack-chain', 'resolutionMode' => 'batch', 'statId' => 'character-stat-agility',
    'count' => 5, 'penaltyPerSuccess' => 10, 'stopOnFailure' => true, 'damageMode' => 'configured',
    'damageComponents' => [['type' => 'ignore', 'formula' => '2d40+12']], 'allowOpposition' => true,
]]]];
requireComplexAbility(applicationComplexAbilityWorkflowError($batchAbility['workflow']) === '', 'Batch damage is a supported reusable module.');
$batchTokens = [['id' => 'batch-source', 'hp' => 80, 'maxHp' => 100, 'stats' => [['id' => 'character-stat-agility', 'value' => 80]], 'weaponAttacks' => []],
    ['id' => 'batch-target', 'hp' => 500, 'maxHp' => 500]];
$batch = createApplicationComplexAbilityExecution(['id' => 'execution-batch', 'sceneId' => 'scene-one', 'sourceTokenId' => 'batch-source',
    'controllerAccountId' => 'batch-player', 'ability' => $batchAbility, 'now' => 9000]);
$draws = [12, 13, 81, 1]; $drawCount = 0;
$batchContext = ['actor' => ['id' => 'batch-player', 'name' => 'Joueur', 'role' => 'player'], 'tokens' => $batchTokens, 'now' => 9001,
    'roll' => static function(string $formula, string $mode, bool $under) use (&$draws, &$drawCount): array {
        requireComplexAbility($formula === '1d100' && $mode === 'advantage' && $under, 'Batch keeps the player roll mode.');
        $value = $draws[$drawCount++]; return ['total' => $value, 'rawD100' => $value];
    }];
$batch = applyApplicationComplexAbilityCommand($batch, ['action' => 'prepare-chain', 'expectedRevision' => 1,
    'targetTokenId' => 'batch-target', 'attackId' => 'batch-formula', 'statId' => 'character-stat-agility', 'opposed' => true, 'rollMode' => 'advantage'], $batchContext);
requireComplexAbility($drawCount === 3 && $batch['stepStates']['chain']['queuedAttackCount'] === 2, 'First failure stops the useful gates; successes determine the attacks.');
requireComplexAbility(array_column($batch['stepStates']['chain']['rolls'], 'threshold') === [80,70,60], 'Cumulative penalty is exact.');
for ($i = 0; $i < 2; $i++) {
    $request = 'batch-attack-' . $i; $batch['stepStates']['chain']['attackRequestId'] = $request;
    $batchContext['attack'] = ['id' => 'attack-' . $i, 'requestId' => $request, 'attackKind' => 'ability', 'attackId' => 'batch-formula',
        'complexExecutionId' => $batch['id'], 'sceneId' => $batch['sceneId'], 'sourceTokenId' => $batch['sourceTokenId'],
        'targetTokenId' => 'batch-target', 'accountId' => 'batch-player', 'opposed' => true,
        'hit' => ['statId' => 'character-stat-agility'], 'status' => 'awaiting-opposition', 'createdAt' => 9002];
    $batch = applyApplicationComplexAbilityCommand($batch, ['action' => 'confirm-attack', 'expectedRevision' => $batch['revision'], 'attackRequestId' => $request], $batchContext);
}
requireComplexAbility($batch['status'] === 'completed' && $batch['endedByFailure'] && count($batch['stepStates']['chain']['attacks']) === 2,
    'Already successful strikes remain queued when the next gate fails.');
requireComplexAbility($batch['workflow']['steps'][0]['damageComponents'] === [['type' => 'ignore', 'formula' => '2d40+12']], 'Special damage is never replaced with the weapon.');
