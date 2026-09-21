<?php
declare(strict_types=1);

const XAR_COMPLEX_ABILITY_WORKFLOW_VERSION = 1;
const XAR_COMPLEX_ABILITY_MAXIMUM_STEPS = 12;
const XAR_COMPLEX_ABILITY_MAXIMUM_EXECUTIONS = 60;
const XAR_COMPLEX_ABILITY_MAXIMUM_EVENTS = 40;

final class ApplicationComplexAbilityException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'complex_ability_invalid',
        public readonly int $httpStatus = 409
    ) {
        parent::__construct($message);
    }
}

function applicationComplexAbilityFail(
    string $message,
    string $code = 'complex_ability_invalid',
    int $status = 409
): never {
    throw new ApplicationComplexAbilityException($message, $code, $status);
}

function applicationComplexAbilityInteger(mixed $value, int $minimum, int $maximum, int $fallback): int
{
    if (!is_numeric($value) || !is_finite((float) $value)) return $fallback;
    return max($minimum, min($maximum, (int) $value));
}

function applicationComplexAbilityText(mixed $value, int $maximum, string $fallback = ''): string
{
    $text = trim(is_scalar($value) ? (string) $value : '');
    if ($text === '') return $fallback;
    return substr($text, 0, $maximum);
}

function applicationComplexAbilityIdentifier(mixed $value, string $fallback = '', int $maximum = 80): string
{
    $candidate = preg_replace('/[^A-Za-z0-9_-]+/', '-', is_scalar($value) ? (string) $value : '') ?? '';
    $candidate = substr(trim($candidate, '-'), 0, $maximum);
    return $candidate !== '' ? $candidate : $fallback;
}

function applicationComplexAbilityUniqueIdentifier(mixed $value, string $fallback, array &$seen): string
{
    $base = applicationComplexAbilityIdentifier($value, $fallback);
    $id = $base;
    for ($suffix = 2; isset($seen[$id]); $suffix += 1) {
        $tail = '-' . $suffix;
        $id = substr($base, 0, 80 - strlen($tail)) . $tail;
    }
    $seen[$id] = true;
    return $id;
}

function normalizeApplicationComplexAbilityChoices(mixed $value, bool $fallback = true): array
{
    $source = is_array($value) && array_is_list($value) ? array_slice($value, 0, 12) : [];
    $seen = []; $choices = [];
    foreach ($source as $index => $entry) {
        if (!is_array($entry)) continue;
        $label = applicationComplexAbilityText($entry['label'] ?? '', 120);
        if ($label === '') continue;
        $choices[] = [
            'id' => applicationComplexAbilityUniqueIdentifier($entry['id'] ?? '', 'option-' . ($index + 1), $seen),
            'label' => $label,
            'description' => applicationComplexAbilityText($entry['description'] ?? '', 500),
        ];
    }
    if ($choices !== [] || !$fallback) return $choices;
    return [
        ['id' => 'option-1', 'label' => 'Premier choix', 'description' => ''],
        ['id' => 'option-2', 'label' => 'Second choix', 'description' => ''],
    ];
}

function normalizeApplicationComplexAbilitySpends(mixed $value): array
{
    $source = is_array($value) && array_is_list($value) ? array_slice($value, 0, 8) : [];
    $seen = []; $spends = [];
    foreach ($source as $index => $entry) {
        if (!is_array($entry)) continue;
        $label = applicationComplexAbilityText($entry['label'] ?? '', 120);
        if ($label === '') continue;
        $spends[] = [
            'id' => applicationComplexAbilityUniqueIdentifier($entry['id'] ?? '', 'spend-' . ($index + 1), $seen),
            'label' => $label,
            'description' => applicationComplexAbilityText($entry['description'] ?? '', 500),
            'cost' => applicationComplexAbilityInteger($entry['cost'] ?? null, 1, 999, 1),
        ];
    }
    return $spends;
}

function normalizeApplicationComplexAbilityWorkflow(mixed $value): array
{
    $source = is_array($value) ? $value : [];
    $rawSteps = is_array($source['steps'] ?? null) && array_is_list($source['steps'])
        ? array_slice($source['steps'], 0, XAR_COMPLEX_ABILITY_MAXIMUM_STEPS) : [];
    $types = ['instruction', 'targets', 'rolls', 'defense-series', 'choice', 'counter'];
    $titles = ['instruction' => 'Consigne', 'targets' => 'Choisir les cibles', 'rolls' => 'Effectuer les jets',
        'defense-series' => 'Défenses successives', 'choice' => 'Faire un choix', 'counter' => 'Suivre les charges'];
    $seen = []; $targetSteps = []; $steps = [];
    foreach ($rawSteps as $index => $raw) {
        $raw = is_array($raw) ? $raw : [];
        $type = in_array($raw['type'] ?? '', $types, true) ? (string) $raw['type'] : 'instruction';
        $step = [
            'id' => applicationComplexAbilityUniqueIdentifier($raw['id'] ?? '', 'step-' . ($index + 1), $seen),
            'type' => $type,
            'title' => applicationComplexAbilityText($raw['title'] ?? '', 120, $titles[$type]),
            'description' => applicationComplexAbilityText($raw['description'] ?? '', 1000),
        ];
        if ($type === 'targets') {
            $minimum = applicationComplexAbilityInteger($raw['minTargets'] ?? null, 1, 20, 1);
            $step['minTargets'] = $minimum;
            $step['maxTargets'] = applicationComplexAbilityInteger($raw['maxTargets'] ?? null, $minimum, 20, $minimum);
            $step['allocationTotal'] = applicationComplexAbilityInteger($raw['allocationTotal'] ?? null, 0, 100, 0);
            $targetSteps[] = $step['id'];
        } elseif ($type === 'rolls') {
            $formula = is_string($raw['formula'] ?? null) && validApplicationAbilityFormula($raw['formula'])
                ? strtolower(preg_replace('/\s+/', '', $raw['formula']) ?? '') : '1d100';
            $step['formula'] = $formula;
            $step['count'] = applicationComplexAbilityInteger($raw['count'] ?? null, 1, 20, 1);
            $step['threshold'] = ($raw['threshold'] ?? null) === '' || ($raw['threshold'] ?? null) === null
                ? null : applicationComplexAbilityInteger($raw['threshold'], 0, 100, 50);
        } elseif ($type === 'defense-series') {
            $requested = applicationComplexAbilityIdentifier($raw['sourceStepId'] ?? '');
            $step['sourceStepId'] = in_array($requested, $targetSteps, true) ? $requested : ($targetSteps[count($targetSteps) - 1] ?? '');
            $mode = in_array($raw['thresholdMode'] ?? '', ['fixed', 'hit', 'stat'], true) ? $raw['thresholdMode'] : 'fixed';
            $step['thresholdMode'] = $mode;
            $step['fixedThreshold'] = applicationComplexAbilityInteger($raw['fixedThreshold'] ?? null, 0, 100, 50);
            $step['targetStatId'] = $mode === 'stat'
                ? applicationComplexAbilityIdentifier($raw['targetStatId'] ?? '', 'character-stat-instinct', 120) : '';
            $step['stopOnSuccess'] = ($raw['stopOnSuccess'] ?? true) !== false;
        } elseif ($type === 'choice') {
            $step['options'] = normalizeApplicationComplexAbilityChoices($raw['options'] ?? null);
        } elseif ($type === 'counter') {
            $step['counterId'] = applicationComplexAbilityIdentifier($raw['counterId'] ?? '', 'counter-' . ($index + 1));
            $step['counterLabel'] = applicationComplexAbilityText($raw['counterLabel'] ?? '', 120, 'Charges');
            $step['maximum'] = applicationComplexAbilityInteger($raw['maximum'] ?? null, 1, 999, 3);
            $step['initial'] = applicationComplexAbilityInteger($raw['initial'] ?? null, 0, $step['maximum'], 0);
            $step['gainAmount'] = applicationComplexAbilityInteger($raw['gainAmount'] ?? null, 1, $step['maximum'], 1);
            $step['gainLabel'] = applicationComplexAbilityText($raw['gainLabel'] ?? '', 120, 'Gagner ' . $step['gainAmount']);
            $step['spendOptions'] = normalizeApplicationComplexAbilitySpends($raw['spendOptions'] ?? null);
            $step['completionLabel'] = applicationComplexAbilityText($raw['completionLabel'] ?? '', 120, 'Terminer cette étape');
        }
        $steps[] = $step;
    }
    return ['version' => XAR_COMPLEX_ABILITY_WORKFLOW_VERSION, 'steps' => $steps];
}

function applicationComplexAbilityWorkflowError(mixed $value): string
{
    if (is_array($value) && array_key_exists('version', $value)) {
        $version = $value['version'];
        if (!is_int($version) || $version < 1) return 'La version du déroulé complexe est invalide.';
        if ($version > XAR_COMPLEX_ABILITY_WORKFLOW_VERSION) {
            return 'Ce déroulé complexe utilise le format futur ' . $version . '. Mettez la Régie à jour avant de le modifier.';
        }
    }
    if (!is_array($value) || !is_array($value['steps'] ?? null) || !array_is_list($value['steps']) || count($value['steps']) < 1) {
        return 'Ajoutez au moins une étape à la compétence complexe.';
    }
    if (count($value['steps']) > XAR_COMPLEX_ABILITY_MAXIMUM_STEPS) {
        return 'Une compétence complexe contient au maximum ' . XAR_COMPLEX_ABILITY_MAXIMUM_STEPS . ' étapes.';
    }
    $workflow = normalizeApplicationComplexAbilityWorkflow($value);
    $seen = [];
    foreach ($workflow['steps'] as $index => $step) {
        if (isset($seen[$step['id']])) return 'L’étape ' . ($index + 1) . ' possède un identifiant en double.';
        $seen[$step['id']] = true;
        if ($step['title'] === '') return 'Donnez un titre à l’étape ' . ($index + 1) . '.';
        if ($step['type'] === 'defense-series' && ($step['sourceStepId'] === '' || !isset($seen[$step['sourceStepId']]))) {
            return 'L’étape ' . ($index + 1) . ' doit suivre une étape de ciblage.';
        }
        if ($step['type'] === 'choice' && count($step['options']) < 2) return 'Ajoutez au moins deux choix à l’étape ' . ($index + 1) . '.';
        if ($step['type'] === 'counter' && $step['spendOptions'] === []) return 'Ajoutez au moins une utilisation de charge à l’étape ' . ($index + 1) . '.';
    }
    return '';
}

function validApplicationComplexAbilityWorkflow(mixed $value): bool
{
    return applicationComplexAbilityWorkflowError($value) === '';
}

function applicationComplexAbilityInitialStepState(array $step): array
{
    $state = ['status' => 'pending'];
    if ($step['type'] === 'targets') $state['allocations'] = [];
    elseif ($step['type'] === 'rolls') $state['rolls'] = [];
    elseif ($step['type'] === 'defense-series') $state['targets'] = [];
    elseif ($step['type'] === 'choice') $state['choice'] = null;
    elseif ($step['type'] === 'counter') $state += ['value' => $step['initial'], 'events' => []];
    return $state;
}

function applicationComplexAbilityAppendEvent(array &$execution, array $value, int $now): array
{
    $event = [
        'id' => applicationComplexAbilityIdentifier($value['id'] ?? '', 'event-' . (((int) ($execution['revision'] ?? 0)) + 1) . '-' . (count($execution['events'] ?? []) + 1), 120),
        'at' => $now,
        'type' => applicationComplexAbilityIdentifier($value['type'] ?? '', 'event', 40),
        'actorId' => applicationComplexAbilityIdentifier($value['actorId'] ?? '', '', 128),
        'actorName' => applicationComplexAbilityText($value['actorName'] ?? '', 120),
        'stepId' => applicationComplexAbilityIdentifier($value['stepId'] ?? '', '', 80),
        'label' => applicationComplexAbilityText($value['label'] ?? '', 240),
        'detail' => applicationComplexAbilityText($value['detail'] ?? '', 500),
    ];
    $events = is_array($execution['events'] ?? null) ? $execution['events'] : [];
    $events[] = $event;
    $execution['events'] = array_slice($events, -XAR_COMPLEX_ABILITY_MAXIMUM_EVENTS);
    return $event;
}

function createApplicationComplexAbilityExecution(array $context): array
{
    $ability = is_array($context['ability'] ?? null) ? $context['ability'] : [];
    $error = applicationComplexAbilityWorkflowError($ability['workflow'] ?? null);
    if ($error !== '') applicationComplexAbilityFail($error, 'complex_ability_definition_invalid', 400);
    $workflow = normalizeApplicationComplexAbilityWorkflow($ability['workflow'] ?? null);
    $now = is_numeric($context['now'] ?? null) ? (int) $context['now'] : (int) floor(microtime(true) * 1000);
    $execution = [
        'id' => applicationComplexAbilityIdentifier($context['id'] ?? '', 'execution-' . ($context['requestId'] ?? $now), 120),
        'sceneId' => applicationComplexAbilityIdentifier($context['sceneId'] ?? '', '', 80),
        'layerId' => in_array($context['layerId'] ?? '', ['basement', 'ground', 'upper'], true) ? $context['layerId'] : 'ground',
        'sourceTokenId' => applicationComplexAbilityIdentifier($context['sourceTokenId'] ?? '', '', 80),
        'sourceName' => applicationComplexAbilityText($context['sourceName'] ?? '', 120, 'Personnage'),
        'characterId' => applicationComplexAbilityIdentifier($context['characterId'] ?? '', '', 180),
        'controllerAccountId' => applicationComplexAbilityIdentifier($context['controllerAccountId'] ?? '', '', 128),
        'controllerName' => applicationComplexAbilityText($context['controllerName'] ?? '', 120),
        'abilityId' => applicationComplexAbilityIdentifier($ability['id'] ?? '', '', 120),
        'abilityName' => applicationComplexAbilityText($ability['name'] ?? '', 120, 'Compétence complexe'),
        'workflow' => $workflow,
        'status' => 'active', 'revision' => 1, 'currentStepIndex' => 0,
        'stepStates' => [], 'events' => [], 'createdAt' => $now, 'updatedAt' => $now,
    ];
    if ($execution['sceneId'] === '' || $execution['sourceTokenId'] === '' || $execution['controllerAccountId'] === '' || $execution['abilityId'] === '') {
        applicationComplexAbilityFail('Le contexte de lancement de la compétence complexe est incomplet.', 'complex_ability_context_invalid', 400);
    }
    $first = $workflow['steps'][0];
    $execution['stepStates'][$first['id']] = applicationComplexAbilityInitialStepState($first);
    applicationComplexAbilityAppendEvent($execution, [
        'type' => 'started', 'actorId' => $execution['controllerAccountId'], 'actorName' => $execution['controllerName'],
        'label' => $execution['abilityName'] . ' commence',
    ], $now);
    return $execution;
}

function normalizeApplicationComplexAbilityExecution(mixed $value): array
{
    $source = is_array($value) ? $value : [];
    $workflow = normalizeApplicationComplexAbilityWorkflow($source['workflow'] ?? null);
    $status = in_array($source['status'] ?? '', ['active', 'completed', 'cancelled'], true) ? $source['status'] : 'cancelled';
    $execution = [
        'id' => applicationComplexAbilityIdentifier($source['id'] ?? '', '', 120),
        'sceneId' => applicationComplexAbilityIdentifier($source['sceneId'] ?? '', '', 80),
        'layerId' => in_array($source['layerId'] ?? '', ['basement', 'ground', 'upper'], true) ? $source['layerId'] : 'ground',
        'sourceTokenId' => applicationComplexAbilityIdentifier($source['sourceTokenId'] ?? '', '', 80),
        'sourceName' => applicationComplexAbilityText($source['sourceName'] ?? '', 120, 'Personnage'),
        'characterId' => applicationComplexAbilityIdentifier($source['characterId'] ?? '', '', 180),
        'controllerAccountId' => applicationComplexAbilityIdentifier($source['controllerAccountId'] ?? '', '', 128),
        'controllerName' => applicationComplexAbilityText($source['controllerName'] ?? '', 120),
        'abilityId' => applicationComplexAbilityIdentifier($source['abilityId'] ?? '', '', 120),
        'abilityName' => applicationComplexAbilityText($source['abilityName'] ?? '', 120, 'Compétence complexe'),
        'workflow' => $workflow, 'status' => $status,
        'revision' => applicationComplexAbilityInteger($source['revision'] ?? null, 1, PHP_INT_MAX, 1),
        'currentStepIndex' => applicationComplexAbilityInteger($source['currentStepIndex'] ?? null, 0, count($workflow['steps']), 0),
        'stepStates' => is_array($source['stepStates'] ?? null) ? $source['stepStates'] : [],
        'events' => [],
        'createdAt' => is_numeric($source['createdAt'] ?? null) ? (int) $source['createdAt'] : 0,
        'updatedAt' => is_numeric($source['updatedAt'] ?? null) ? (int) $source['updatedAt'] : 0,
    ];
    foreach (array_slice(is_array($source['events'] ?? null) ? $source['events'] : [], -XAR_COMPLEX_ABILITY_MAXIMUM_EVENTS) as $event) {
        if (!is_array($event)) continue;
        $execution['events'][] = [
            'id' => applicationComplexAbilityIdentifier($event['id'] ?? '', '', 120),
            'at' => is_numeric($event['at'] ?? null) ? (int) $event['at'] : 0,
            'type' => applicationComplexAbilityIdentifier($event['type'] ?? '', 'event', 40),
            'actorId' => applicationComplexAbilityIdentifier($event['actorId'] ?? '', '', 128),
            'actorName' => applicationComplexAbilityText($event['actorName'] ?? '', 120),
            'stepId' => applicationComplexAbilityIdentifier($event['stepId'] ?? '', '', 80),
            'label' => applicationComplexAbilityText($event['label'] ?? '', 240),
            'detail' => applicationComplexAbilityText($event['detail'] ?? '', 500),
        ];
    }
    foreach (['completedAt', 'cancelledAt'] as $field) if (is_numeric($source[$field] ?? null) && (int) $source[$field] > 0) $execution[$field] = (int) $source[$field];
    if (!empty($source['cancelledBy'])) $execution['cancelledBy'] = applicationComplexAbilityIdentifier($source['cancelledBy'], '', 128);
    return $execution;
}

function normalizeApplicationComplexAbilityExecutions(mixed $value): array
{
    if (!is_array($value) || !array_is_list($value)) return [];
    $byId = [];
    foreach ($value as $entry) {
        $execution = normalizeApplicationComplexAbilityExecution($entry);
        if ($execution['id'] === '' || $execution['sceneId'] === '' || $execution['sourceTokenId'] === ''
            || $execution['abilityId'] === '') continue;
        $previous = $byId[$execution['id']] ?? null;
        if (!is_array($previous) || $execution['revision'] >= $previous['revision']) $byId[$execution['id']] = $execution;
    }
    $active = array_values(array_filter($byId, static fn(array $entry): bool => $entry['status'] === 'active'));
    $terminal = array_values(array_filter($byId, static fn(array $entry): bool => $entry['status'] !== 'active'));
    usort($active, static fn(array $left, array $right): int => $right['updatedAt'] <=> $left['updatedAt']);
    usort($terminal, static fn(array $left, array $right): int => $right['updatedAt'] <=> $left['updatedAt']);
    $active = array_slice($active, 0, XAR_COMPLEX_ABILITY_MAXIMUM_EXECUTIONS);
    return [...$active, ...array_slice($terminal, 0, max(0, XAR_COMPLEX_ABILITY_MAXIMUM_EXECUTIONS - count($active)))];
}

function validApplicationComplexAbilityExecutions(mixed $value): bool
{
    if (!is_array($value) || !array_is_list($value) || count($value) > XAR_COMPLEX_ABILITY_MAXIMUM_EXECUTIONS) return false;
    foreach ($value as $entry) {
        if (!is_array($entry) || !validApplicationComplexAbilityWorkflow($entry['workflow'] ?? null)
            || applicationComplexAbilityIdentifier($entry['id'] ?? '') === ''
            || applicationComplexAbilityIdentifier($entry['sceneId'] ?? '') === ''
            || applicationComplexAbilityIdentifier($entry['sourceTokenId'] ?? '') === ''
            || applicationComplexAbilityIdentifier($entry['abilityId'] ?? '') === ''
            || !in_array($entry['status'] ?? '', ['active', 'completed', 'cancelled'], true)) return false;
    }
    return true;
}

function trimApplicationComplexAbilityExecutions(mixed $value): array
{
    $normalized = normalizeApplicationComplexAbilityExecutions($value);
    $active = array_values(array_filter($normalized, static fn(array $entry): bool => $entry['status'] === 'active'));
    $terminal = array_values(array_filter($normalized, static fn(array $entry): bool => $entry['status'] !== 'active'));
    usort($terminal, static fn(array $left, array $right): int => $right['updatedAt'] <=> $left['updatedAt']);
    return array_slice([...$active, ...array_slice($terminal, 0, max(0, XAR_COMPLEX_ABILITY_MAXIMUM_EXECUTIONS - count($active)))], 0, XAR_COMPLEX_ABILITY_MAXIMUM_EXECUTIONS);
}

function applicationComplexAbilityTokenById(array $tokens, mixed $id): ?array
{
    foreach ($tokens as $token) if (is_array($token) && (string) ($token['id'] ?? '') === (string) $id) return $token;
    return null;
}

function applicationComplexAbilityComparable(mixed $value): string
{
    return strtolower(preg_replace('/[^a-z0-9]/', '', strtr((string) $value, [
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'À' => 'a', 'Â' => 'a', 'Ä' => 'a',
        'î' => 'i', 'ï' => 'i', 'Î' => 'i', 'Ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'Ô' => 'o', 'Ö' => 'o',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u', 'ç' => 'c', 'Ç' => 'c',
    ])) ?? '');
}

function applicationComplexAbilityTokenThreshold(array $token, array $step): int
{
    if ($step['thresholdMode'] === 'fixed') return (int) $step['fixedThreshold'];
    if ($step['thresholdMode'] === 'hit') {
        if (!is_numeric($token['hitThreshold'] ?? null)) applicationComplexAbilityFail('La cible n’a plus de seuil de touché.', 'complex_ability_threshold_missing');
        return applicationComplexAbilityInteger($token['hitThreshold'], 0, 100, 0);
    }
    $statId = (string) ($step['targetStatId'] ?? '');
    $key = preg_replace('/^character-stat-/', '', $statId) ?? $statId;
    $labels = ['force' => 'force', 'dexterity' => 'dexterite', 'agility' => 'agilite', 'spiritSocial' => 'espritsocial', 'intelligence' => 'intelligence', 'instinct' => 'instinctperception'];
    foreach (is_array($token['stats'] ?? null) ? $token['stats'] : [] as $stat) {
        if (!is_array($stat)) continue;
        $matches = in_array((string) ($stat['id'] ?? ''), [$statId, $key, 'character-stat-' . $key], true)
            || applicationComplexAbilityComparable($stat['label'] ?? '') === ($labels[$key] ?? '');
        if ($matches && is_numeric($stat['value'] ?? null)) return applicationComplexAbilityInteger($stat['value'], 0, 100, 0);
    }
    applicationComplexAbilityFail('La statistique de défense n’existe plus sur la cible.', 'complex_ability_threshold_missing');
}

function applicationComplexAbilityInitializeDefenses(array &$execution, array $step, array &$state, array $tokens): void
{
    if (is_array($state['targets'] ?? null) && $state['targets'] !== []) return;
    $allocations = $execution['stepStates'][$step['sourceStepId']]['allocations'] ?? [];
    if (!is_array($allocations) || $allocations === []) applicationComplexAbilityFail('La répartition de cibles requise n’est plus disponible.', 'complex_ability_targets_missing');
    $state['targets'] = [];
    foreach ($allocations as $allocation) {
        $token = applicationComplexAbilityTokenById($tokens, $allocation['tokenId'] ?? '');
        $state['targets'][] = [
            'tokenId' => (string) ($allocation['tokenId'] ?? ''),
            'targetName' => applicationComplexAbilityText($token['name'] ?? ($allocation['targetName'] ?? ''), 120, 'Cible'),
            'attackCount' => applicationComplexAbilityInteger($allocation['count'] ?? null, 1, 100, 1),
            'rolls' => [], 'status' => 'pending', 'parryFromAttack' => null, 'parryCount' => 0,
        ];
    }
}

function applicationComplexAbilityCurrentDefenseIndex(array $state): ?int
{
    foreach (is_array($state['targets'] ?? null) ? $state['targets'] : [] as $index => $target) {
        if (($target['status'] ?? '') === 'pending') return $index;
    }
    return null;
}

function applicationComplexAbilityFinishStep(array &$execution, array &$state, int $now): void
{
    $state['status'] = 'completed'; $state['completedAt'] = $now;
    $execution['currentStepIndex'] += 1;
    if ($execution['currentStepIndex'] >= count($execution['workflow']['steps'])) {
        $execution['status'] = 'completed'; $execution['completedAt'] = $now;
        return;
    }
    $next = $execution['workflow']['steps'][$execution['currentStepIndex']];
    if (!isset($execution['stepStates'][$next['id']])) $execution['stepStates'][$next['id']] = applicationComplexAbilityInitialStepState($next);
}

function applyApplicationComplexAbilityCommand(
    mixed $value,
    mixed $commandValue,
    array $context = []
): array {
    $execution = normalizeApplicationComplexAbilityExecution($value);
    $command = is_array($commandValue) ? $commandValue : [];
    if ($execution['status'] !== 'active') applicationComplexAbilityFail('Cette compétence complexe est déjà terminée.', 'complex_ability_terminal');
    $expected = $command['expectedRevision'] ?? null;
    if (!is_int($expected) && !(is_numeric($expected) && (float) $expected === (float) (int) $expected)) $expected = null;
    if ($expected === null || (int) $expected !== $execution['revision']) {
        applicationComplexAbilityFail('Cette compétence a progressé ailleurs. Rouvrez son état courant.', 'complex_ability_revision_conflict');
    }
    $actor = is_array($context['actor'] ?? null) ? $context['actor'] : [];
    $actorId = applicationComplexAbilityIdentifier($actor['id'] ?? '', '', 128);
    $isGm = ($actor['role'] ?? '') === 'gm';
    $actorName = applicationComplexAbilityText($actor['name'] ?? '', 120, $isGm ? 'MJ' : 'Joueur');
    $owner = $actorId !== '' && $actorId === $execution['controllerAccountId'];
    $now = is_numeric($context['now'] ?? null) ? (int) $context['now'] : (int) floor(microtime(true) * 1000);
    $tokens = is_array($context['tokens'] ?? null) ? $context['tokens'] : [];
    $roll = is_callable($context['roll'] ?? null) ? $context['roll'] : null;
    $step = $execution['workflow']['steps'][$execution['currentStepIndex']] ?? null;
    if (!is_array($step)) applicationComplexAbilityFail('L’étape courante n’existe plus.', 'complex_ability_step_missing');
    if (!isset($execution['stepStates'][$step['id']]) || !is_array($execution['stepStates'][$step['id']])) {
        $execution['stepStates'][$step['id']] = applicationComplexAbilityInitialStepState($step);
    }
    $state =& $execution['stepStates'][$step['id']];
    $action = (string) ($command['action'] ?? '');
    if ($action === 'cancel') {
        if (!$owner && !$isGm) applicationComplexAbilityFail('Seul le lanceur ou le MJ peut arrêter cette compétence.', 'complex_ability_forbidden', 403);
        $execution['status'] = 'cancelled'; $execution['cancelledAt'] = $now; $execution['cancelledBy'] = $actorId;
        applicationComplexAbilityAppendEvent($execution, ['type' => 'cancelled', 'actorId' => $actorId, 'actorName' => $actorName,
            'stepId' => $step['id'], 'label' => 'Compétence arrêtée', 'detail' => 'Les étapes déjà validées et les coûts restent acquis.'], $now);
    } else {
        $defenseParticipant = false;
        if ($step['type'] === 'defense-series') {
            applicationComplexAbilityInitializeDefenses($execution, $step, $state, $tokens);
            $targetIndex = applicationComplexAbilityCurrentDefenseIndex($state);
            $target = $targetIndex === null ? null : $state['targets'][$targetIndex];
            $token = is_array($target) ? applicationComplexAbilityTokenById($tokens, $target['tokenId'] ?? '') : null;
            $controller = (string) ($token['controllerAccountId'] ?? $token['controllerPlayerId'] ?? '');
            $defenseParticipant = is_array($target) && $actorId !== '' && $controller === $actorId;
        }
        if (!$owner && !$isGm && !($defenseParticipant && $action === 'defense-roll')) {
            applicationComplexAbilityFail('Vous ne pouvez pas faire progresser cette compétence.', 'complex_ability_forbidden', 403);
        }
        if ($step['type'] === 'instruction' && $action === 'acknowledge') {
            applicationComplexAbilityAppendEvent($execution, ['type' => 'step', 'actorId' => $actorId, 'actorName' => $actorName,
                'stepId' => $step['id'], 'label' => $step['title'] . ' validée'], $now);
            applicationComplexAbilityFinishStep($execution, $state, $now);
        } elseif ($step['type'] === 'targets' && $action === 'select-targets') {
            $allocations = []; $ids = [];
            foreach (array_slice(is_array($command['allocations'] ?? null) ? $command['allocations'] : [], 0, $step['maxTargets']) as $entry) {
                if (!is_array($entry)) continue;
                $tokenId = applicationComplexAbilityIdentifier($entry['tokenId'] ?? '', '', 80);
                $token = applicationComplexAbilityTokenById($tokens, $tokenId);
                if ($tokenId === '' || !is_array($token)) continue;
                $allocations[] = ['tokenId' => $tokenId, 'targetName' => applicationComplexAbilityText($token['name'] ?? '', 120, 'Cible'),
                    'count' => applicationComplexAbilityInteger($entry['count'] ?? null, 1, 100, 1)];
                $ids[$tokenId] = true;
            }
            if (count($ids) !== count($allocations) || count($allocations) < $step['minTargets'] || count($allocations) > $step['maxTargets']) {
                applicationComplexAbilityFail('Choisissez entre ' . $step['minTargets'] . ' et ' . $step['maxTargets'] . ' cibles distinctes.', 'complex_ability_target_count', 400);
            }
            $total = array_sum(array_column($allocations, 'count'));
            if ($step['allocationTotal'] > 0 && $total !== $step['allocationTotal']) {
                applicationComplexAbilityFail('Répartissez exactement ' . $step['allocationTotal'] . ' actions.', 'complex_ability_allocation_total', 400);
            }
            $state['allocations'] = $allocations;
            applicationComplexAbilityAppendEvent($execution, ['type' => 'targets', 'actorId' => $actorId, 'actorName' => $actorName,
                'stepId' => $step['id'], 'label' => count($allocations) . ' cible(s) choisie(s)',
                'detail' => implode(' · ', array_map(static fn(array $entry): string => $entry['targetName'] . ' ×' . $entry['count'], $allocations))], $now);
            applicationComplexAbilityFinishStep($execution, $state, $now);
            $nextStep = $execution['workflow']['steps'][$execution['currentStepIndex']] ?? null;
            if ($execution['status'] === 'active' && ($nextStep['type'] ?? '') === 'defense-series') {
                $nextState =& $execution['stepStates'][$nextStep['id']];
                applicationComplexAbilityInitializeDefenses($execution, $nextStep, $nextState, $tokens);
                unset($nextState);
            }
        } elseif ($step['type'] === 'rolls' && $action === 'roll') {
            if (!is_callable($roll)) applicationComplexAbilityFail('Le service de dés est indisponible.', 'complex_ability_roll_unavailable', 503);
            $rolled = $roll($step['formula']);
            if (!is_array($rolled) || !is_numeric($rolled['total'] ?? null)) applicationComplexAbilityFail('Le résultat du dé est invalide.', 'complex_ability_roll_invalid', 500);
            $total = (int) $rolled['total'];
            $result = ['formula' => $step['formula'], 'total' => $total,
                'breakdown' => applicationComplexAbilityText($rolled['breakdown'] ?? '', 500, (string) $total)];
            if ($step['threshold'] !== null) $result += ['threshold' => $step['threshold'], 'success' => $total <= $step['threshold']];
            $state['rolls'][] = $result;
            applicationComplexAbilityAppendEvent($execution, ['type' => 'roll', 'actorId' => $actorId, 'actorName' => $actorName,
                'stepId' => $step['id'], 'label' => $step['title'] . ' · ' . $total,
                'detail' => $step['threshold'] === null ? $result['breakdown'] : $result['breakdown'] . ' · ' . ($result['success'] ? 'réussite' : 'échec') . ' sur ' . $step['threshold']], $now);
            if (count($state['rolls']) >= $step['count']) applicationComplexAbilityFinishStep($execution, $state, $now);
        } elseif ($step['type'] === 'defense-series' && $action === 'defense-roll') {
            if (!is_callable($roll)) applicationComplexAbilityFail('Le service de dés est indisponible.', 'complex_ability_roll_unavailable', 503);
            $targetIndex = applicationComplexAbilityCurrentDefenseIndex($state);
            if ($targetIndex === null) applicationComplexAbilityFail('Toutes les défenses ont déjà été résolues.', 'complex_ability_defenses_complete');
            $target =& $state['targets'][$targetIndex];
            if (!empty($command['targetTokenId']) && applicationComplexAbilityIdentifier($command['targetTokenId']) !== $target['tokenId']) {
                applicationComplexAbilityFail('Une autre cible doit résoudre sa défense avant celle-ci.', 'complex_ability_defense_order');
            }
            $token = applicationComplexAbilityTokenById($tokens, $target['tokenId']);
            if (!is_array($token)) applicationComplexAbilityFail('La cible n’est plus disponible.', 'complex_ability_target_missing', 404);
            $threshold = applicationComplexAbilityTokenThreshold($token, $step);
            $rolled = $roll('1d100');
            $total = is_array($rolled) && is_numeric($rolled['rawD100'] ?? $rolled['total'] ?? null) ? (int) ($rolled['rawD100'] ?? $rolled['total']) : null;
            if ($total === null) applicationComplexAbilityFail('Le résultat du d100 est invalide.', 'complex_ability_roll_invalid', 500);
            $result = ['formula' => '1d100', 'total' => $total, 'threshold' => $threshold, 'success' => $total <= $threshold,
                'breakdown' => applicationComplexAbilityText($rolled['breakdown'] ?? '', 500, (string) $total)];
            $target['rolls'][] = $result; $attackNumber = count($target['rolls']);
            if ($result['success'] && $step['stopOnSuccess']) {
                $target['status'] = 'succeeded'; $target['parryFromAttack'] = $attackNumber;
                $target['parryCount'] = max(0, $target['attackCount'] - $attackNumber + 1);
            } elseif ($attackNumber >= $target['attackCount']) {
                $target['status'] = $result['success'] ? 'succeeded' : 'failed';
                if ($result['success']) { $target['parryFromAttack'] = $attackNumber; $target['parryCount'] = 1; }
            }
            applicationComplexAbilityAppendEvent($execution, ['type' => 'defense', 'actorId' => $actorId, 'actorName' => $actorName,
                'stepId' => $step['id'], 'label' => $target['targetName'] . ' · attaque ' . $attackNumber . ' · ' . ($result['success'] ? 'réussite' : 'échec'),
                'detail' => $result['success'] ? $total . ' ≤ ' . $threshold . ' · parade autorisée sur ' . $target['parryCount'] . ' attaque(s)' : $total . ' > ' . $threshold], $now);
            unset($target);
            if (applicationComplexAbilityCurrentDefenseIndex($state) === null) applicationComplexAbilityFinishStep($execution, $state, $now);
        } elseif ($step['type'] === 'choice' && $action === 'choose') {
            $option = null; $optionId = applicationComplexAbilityIdentifier($command['optionId'] ?? '');
            foreach ($step['options'] as $candidate) if ($candidate['id'] === $optionId) { $option = $candidate; break; }
            if (!is_array($option)) applicationComplexAbilityFail('Ce choix n’existe plus.', 'complex_ability_choice_missing', 400);
            $state['choice'] = $option;
            applicationComplexAbilityAppendEvent($execution, ['type' => 'choice', 'actorId' => $actorId, 'actorName' => $actorName,
                'stepId' => $step['id'], 'label' => $option['label'], 'detail' => $option['description']], $now);
            applicationComplexAbilityFinishStep($execution, $state, $now);
        } elseif ($step['type'] === 'counter' && $action === 'counter-gain') {
            $previous = applicationComplexAbilityInteger($state['value'] ?? null, 0, $step['maximum'], $step['initial']);
            $next = min($step['maximum'], $previous + $step['gainAmount']);
            if ($next === $previous) applicationComplexAbilityFail($step['counterLabel'] . ' est déjà au maximum (' . $step['maximum'] . ').', 'complex_ability_counter_maximum');
            $state['value'] = $next; $state['events'][] = ['at' => $now, 'type' => 'gain', 'value' => $next, 'amount' => $next - $previous, 'actorId' => $actorId];
            $state['events'] = array_slice($state['events'], -XAR_COMPLEX_ABILITY_MAXIMUM_EVENTS);
            applicationComplexAbilityAppendEvent($execution, ['type' => 'counter', 'actorId' => $actorId, 'actorName' => $actorName,
                'stepId' => $step['id'], 'label' => $step['counterLabel'] . ' : ' . $previous . ' → ' . $next, 'detail' => $step['gainLabel']], $now);
        } elseif ($step['type'] === 'counter' && $action === 'counter-spend') {
            $option = null; $optionId = applicationComplexAbilityIdentifier($command['optionId'] ?? '');
            foreach ($step['spendOptions'] as $candidate) if ($candidate['id'] === $optionId) { $option = $candidate; break; }
            if (!is_array($option)) applicationComplexAbilityFail('Cette utilisation de charge n’existe plus.', 'complex_ability_spend_missing', 400);
            $previous = applicationComplexAbilityInteger($state['value'] ?? null, 0, $step['maximum'], $step['initial']);
            if ($previous < $option['cost']) applicationComplexAbilityFail($step['counterLabel'] . ' insuffisant : ' . $previous . '/' . $option['cost'] . '.', 'complex_ability_counter_insufficient');
            $state['value'] = $previous - $option['cost'];
            $state['events'][] = ['at' => $now, 'type' => 'spend', 'value' => $state['value'], 'amount' => $option['cost'], 'optionId' => $option['id'], 'actorId' => $actorId];
            $state['events'] = array_slice($state['events'], -XAR_COMPLEX_ABILITY_MAXIMUM_EVENTS);
            applicationComplexAbilityAppendEvent($execution, ['type' => 'counter', 'actorId' => $actorId, 'actorName' => $actorName,
                'stepId' => $step['id'], 'label' => $option['label'] . ' · ' . $step['counterLabel'] . ' : ' . $previous . ' → ' . $state['value'], 'detail' => $option['description']], $now);
        } elseif ($step['type'] === 'counter' && $action === 'complete-step') {
            applicationComplexAbilityAppendEvent($execution, ['type' => 'step', 'actorId' => $actorId, 'actorName' => $actorName,
                'stepId' => $step['id'], 'label' => $step['title'] . ' terminée', 'detail' => $step['counterLabel'] . ' restant : ' . $state['value']], $now);
            applicationComplexAbilityFinishStep($execution, $state, $now);
        } else {
            applicationComplexAbilityFail('Cette action ne correspond pas à l’étape courante.', 'complex_ability_action_invalid', 400);
        }
    }
    unset($state);
    $execution['revision'] += 1; $execution['updatedAt'] = $now;
    return $execution;
}
