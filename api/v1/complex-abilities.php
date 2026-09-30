<?php
declare(strict_types=1);

const XAR_COMPLEX_ABILITY_WORKFLOW_VERSION = 7;
const XAR_COMPLEX_ABILITY_MAXIMUM_STEPS = 12;
const XAR_COMPLEX_ABILITY_MAXIMUM_EXECUTIONS = 60;
const XAR_COMPLEX_ABILITY_MAXIMUM_EVENTS = 40;
const XAR_ABILITY_COMPLETION_CUE_VERSION = 1;
const XAR_ABILITY_SOUND_MAXIMUM_BYTES = 500 * 1024;
const XAR_ABILITY_SOUND_MAXIMUM_DURATION_MILLISECONDS = 5000;

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

function normalizeApplicationAbilityCompletionCue(mixed $value): array
{
    $source = is_array($value) && !array_is_list($value) ? $value : [];
    $rawSound = is_array($source['sound'] ?? null) && !array_is_list($source['sound']) ? $source['sound'] : [];
    $url = trim((string) ($rawSound['url'] ?? ''));
    $contentType = strtolower(trim((string) ($rawSound['contentType'] ?? '')));
    $byteSize = is_numeric($rawSound['byteSize'] ?? null) ? (int) $rawSound['byteSize'] : 0;
    $durationMs = is_numeric($rawSound['durationMs'] ?? null) ? (int) $rawSound['durationMs'] : 0;
    $sound = preg_match('#^/media/[A-Za-z0-9_-]{24}$#D', $url) === 1
        && in_array($contentType, ['audio/mpeg', 'audio/wav'], true)
        && $byteSize > 0 && $byteSize <= XAR_ABILITY_SOUND_MAXIMUM_BYTES
        && $durationMs > 0 && $durationMs <= XAR_ABILITY_SOUND_MAXIMUM_DURATION_MILLISECONDS
        ? [
            'url' => $url,
            'name' => applicationComplexAbilityText(
                $rawSound['name'] ?? '',
                180,
                $contentType === 'audio/mpeg' ? 'son-de-fin.mp3' : 'son-de-fin.wav'
            ),
            'contentType' => $contentType,
            'byteSize' => $byteSize,
            'durationMs' => $durationMs,
        ]
        : null;
    return [
        'version' => XAR_ABILITY_COMPLETION_CUE_VERSION,
        'trigger' => 'completed',
        'sound' => $sound,
        // Réservation de schéma uniquement : aucune animation n'est exécutée.
        'visual' => ['version' => 1, 'kind' => 'none'],
    ];
}

function validApplicationAbilityCompletionCue(mixed $value): bool
{
    if (!is_array($value) || array_is_list($value)
        || (int) ($value['version'] ?? 0) !== XAR_ABILITY_COMPLETION_CUE_VERSION
        || ($value['trigger'] ?? '') !== 'completed') return false;
    $visual = $value['visual'] ?? null;
    if (!is_array($visual) || array_is_list($visual)
        || (int) ($visual['version'] ?? 0) !== 1 || ($visual['kind'] ?? '') !== 'none') return false;
    if (($value['sound'] ?? null) === null) return true;
    $sound = $value['sound'];
    if (!is_array($sound) || array_is_list($sound)
        || preg_match('#^/media/[A-Za-z0-9_-]{24}$#D', (string) ($sound['url'] ?? '')) !== 1
        || !in_array($sound['contentType'] ?? '', ['audio/mpeg', 'audio/wav'], true)
        || !is_int($sound['byteSize'] ?? null) || $sound['byteSize'] < 1 || $sound['byteSize'] > XAR_ABILITY_SOUND_MAXIMUM_BYTES
        || !is_int($sound['durationMs'] ?? null) || $sound['durationMs'] < 1 || $sound['durationMs'] > XAR_ABILITY_SOUND_MAXIMUM_DURATION_MILLISECONDS) return false;
    $name = $sound['name'] ?? null;
    return is_string($name) && trim($name) !== '' && preg_match('/[\x00\r\n]/', $name) !== 1 && strlen($name) <= 180;
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
        $rawEffect = is_array($entry['effect'] ?? null) ? $entry['effect'] : [];
        $kind = in_array($rawEffect['kind'] ?? '', ['damage', 'guard', 'marker'], true) ? $rawEffect['kind'] : 'none';
        $effect = ['kind' => $kind];
        if ($kind === 'damage') {
            $effect['formula'] = validApplicationAbilityFormula($rawEffect['formula'] ?? null)
                ? strtolower(preg_replace('/\s+/', '', $rawEffect['formula']) ?? '') : '1d2';
            $effect['damageType'] = in_array($rawEffect['damageType'] ?? '', ['physical', 'magical', 'ignore'], true)
                ? $rawEffect['damageType'] : 'physical';
        } elseif ($kind === 'guard') {
            $effect['percent'] = applicationComplexAbilityInteger($rawEffect['percent'] ?? null, 1, 100, 20);
            $effect['stackable'] = ($rawEffect['stackable'] ?? false) === true;
            $effect['trigger'] = ($rawEffect['trigger'] ?? '') === 'damage' ? 'damage' : 'attack';
            $effect['persistAfterEnd'] = ($rawEffect['persistAfterEnd'] ?? false) === true;
            $types = is_array($rawEffect['damageTypes'] ?? null) ? array_values(array_intersect(['physical', 'magical', 'ignore'], $rawEffect['damageTypes'])) : [];
            $effect['damageTypes'] = $types !== [] ? $types : ['physical', 'magical', 'ignore'];
            $effect['armorStacking'] = ($rawEffect['armorStacking'] ?? '') === 'add' ? 'add' : 'after';
        } elseif ($kind === 'marker') {
            $effect['movable'] = ($rawEffect['movable'] ?? true) !== false;
        }
        $spends[] = [
            'id' => applicationComplexAbilityUniqueIdentifier($entry['id'] ?? '', 'spend-' . ($index + 1), $seen),
            'label' => $label,
            'description' => applicationComplexAbilityText($entry['description'] ?? '', 500),
            'cost' => $kind === 'marker' ? 1 : applicationComplexAbilityInteger($entry['cost'] ?? null, 1, 999, 1),
            'effect' => $effect,
        ];
    }
    return $spends;
}

function normalizeApplicationComplexAbilityBranch(mixed $value): string
{
    $branch = trim(is_scalar($value) ? (string) $value : 'next');
    if (in_array($branch, ['next', 'finish'], true)) return $branch;
    return applicationComplexAbilityIdentifier($branch, 'next');
}

function applicationComplexAbilityFacts(): array
{
    return ['hp-percent', 'health-state', 'hp', 'mana-percent', 'mana', 'fatigue', 'condition', 'stat',
        'target-count', 'roll-successes', 'last-roll-success', 'choice', 'counter', 'max-hp', 'missing-hp',
        'max-mana', 'missing-mana', 'attack-count', 'gate-successes', 'gate-failures', 'last-gate-success', 'last-attack-status'];
}

function applicationComplexAbilityReferenceTypes(): array
{
    return ['roll-successes' => 'rolls', 'last-roll-success' => 'rolls', 'choice' => 'choice', 'counter' => 'counter',
        'attack-count' => 'attack-chain', 'gate-successes' => 'attack-chain', 'gate-failures' => 'attack-chain',
        'last-gate-success' => 'attack-chain', 'last-attack-status' => 'attack-chain'];
}

function normalizeApplicationComplexAbilityFact(array $raw, array $targetSteps, array $previousSteps): array
{
    $subject = in_array($raw['subject'] ?? '', ['source', 'targets'], true) ? $raw['subject'] : 'source';
    $fact = in_array($raw['fact'] ?? '', applicationComplexAbilityFacts(), true) ? $raw['fact'] : 'hp-percent';
    $requestedTarget = applicationComplexAbilityIdentifier($raw['sourceStepId'] ?? '');
    $requestedReference = applicationComplexAbilityIdentifier($raw['referenceStepId'] ?? '');
    $operators = ['eq', 'ne', 'lt', 'lte', 'gt', 'gte', 'between', 'contains', 'not-contains'];
    return [
        'subject' => $subject, 'fact' => $fact,
        'sourceStepId' => $subject === 'targets' || $fact === 'target-count'
            ? (in_array($requestedTarget, $targetSteps, true) ? $requestedTarget : ($targetSteps[count($targetSteps) - 1] ?? '')) : '',
        'aggregation' => in_array($raw['aggregation'] ?? '', ['any', 'all', 'none'], true) ? $raw['aggregation'] : 'any',
        'operator' => in_array($raw['operator'] ?? '', $operators, true) ? $raw['operator'] : 'lte',
        'value' => is_numeric($raw['value'] ?? null)
            ? max(-1000000000, min(1000000000, 0 + $raw['value'])) : applicationComplexAbilityText($raw['value'] ?? '', 240),
        'secondValue' => is_numeric($raw['secondValue'] ?? null)
            ? max(-1000000000, min(1000000000, 0 + $raw['secondValue'])) : applicationComplexAbilityText($raw['secondValue'] ?? '', 240),
        'referenceStepId' => in_array($requestedReference, array_column($previousSteps, 'id'), true) ? $requestedReference : '',
        'statId' => applicationComplexAbilityIdentifier($raw['statId'] ?? '', 'character-stat-instinct', 120),
        'conditionLabel' => applicationComplexAbilityText($raw['conditionLabel'] ?? '', 240),
    ];
}

function normalizeApplicationComplexAbilityExpression(mixed $raw, array $targetSteps, array $previousSteps, int $depth = 0, int &$nodes = 0): array
{
    $raw = is_array($raw) ? $raw : [];
    if (++$nodes > 32 || $depth > 5) return ['type' => 'fact'] + normalizeApplicationComplexAbilityFact([], $targetSteps, $previousSteps);
    if (in_array($raw['type'] ?? '', ['all', 'any'], true)) return [
        'type' => $raw['type'],
        'conditions' => array_map(static function(mixed $child) use ($targetSteps, $previousSteps, $depth, &$nodes): array {
            return normalizeApplicationComplexAbilityExpression($child, $targetSteps, $previousSteps, $depth + 1, $nodes);
        }, array_slice(is_array($raw['conditions'] ?? null) ? $raw['conditions'] : [], 0, 16)),
    ];
    if (($raw['type'] ?? '') === 'not') return ['type' => 'not', 'condition' => normalizeApplicationComplexAbilityExpression($raw['condition'] ?? null, $targetSteps, $previousSteps, $depth + 1, $nodes)];
    return ['type' => 'fact'] + normalizeApplicationComplexAbilityFact($raw, $targetSteps, $previousSteps);
}

function applicationComplexAbilityExpressionLeaves(mixed $raw, int $depth = 0, int &$nodes = 0): ?array
{
    if (!is_array($raw) || array_is_list($raw) || ++$nodes > 32 || $depth > 5) return null;
    if (($raw['type'] ?? '') === 'fact') return [$raw];
    if (($raw['type'] ?? '') === 'not') return applicationComplexAbilityExpressionLeaves($raw['condition'] ?? null, $depth + 1, $nodes);
    if (!in_array($raw['type'] ?? '', ['all', 'any'], true) || !is_array($raw['conditions'] ?? null)
        || !array_is_list($raw['conditions']) || count($raw['conditions']) < 2 || count($raw['conditions']) > 16) return null;
    $leaves = [];
    foreach ($raw['conditions'] as $child) {
        $nested = applicationComplexAbilityExpressionLeaves($child, $depth + 1, $nodes);
        if ($nested === null) return null;
        array_push($leaves, ...$nested);
    }
    return $leaves;
}

function normalizeApplicationComplexAbilityWorkflow(mixed $value, bool $snapshot = false): array
{
    $source = is_array($value) ? $value : [];
    $rawSteps = is_array($source['steps'] ?? null) && array_is_list($source['steps'])
        ? array_slice($source['steps'], 0, XAR_COMPLEX_ABILITY_MAXIMUM_STEPS) : [];
    $types = ['instruction', 'targets', 'rolls', 'attack-chain', 'allocated-attacks', 'defense-series', 'guard', 'choice', 'counter', 'condition'];
    $titles = ['instruction' => 'Consigne', 'targets' => 'Choisir les cibles', 'rolls' => 'Effectuer les jets', 'attack-chain' => 'Attaques conditionnelles', 'allocated-attacks' => 'Attaques réparties',
        'defense-series' => 'Défenses successives', 'guard' => 'Protéger de la prochaine attaque', 'choice' => 'Faire un choix', 'counter' => 'Suivre les charges',
        'condition' => 'Vérifier une condition'];
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
            $minimum = applicationComplexAbilityInteger($raw['minTargets'] ?? null, 0, 20, 1);
            $step['minTargets'] = $minimum;
            $step['maxTargets'] = applicationComplexAbilityInteger($raw['maxTargets'] ?? null, $minimum, 20, $minimum);
            $step['allocationTotal'] = applicationComplexAbilityInteger($raw['allocationTotal'] ?? null, 0, 100, 0);
            $step['rangeCells'] = applicationComplexAbilityInteger($raw['rangeCells'] ?? null, 0, 100, 0);
            $targetSteps[] = $step['id'];
        } elseif ($type === 'rolls') {
            $formula = is_string($raw['formula'] ?? null) && validApplicationAbilityFormula($raw['formula'])
                ? strtolower(preg_replace('/\s+/', '', $raw['formula']) ?? '') : '1d100';
            $step['formula'] = $formula;
            $step['count'] = applicationComplexAbilityInteger($raw['count'] ?? null, 1, 20, 1);
            $step['threshold'] = ($raw['threshold'] ?? null) === '' || ($raw['threshold'] ?? null) === null
                ? null : applicationComplexAbilityInteger($raw['threshold'], 0, 100, 50);
        } elseif ($type === 'attack-chain') {
            $step['statId'] = applicationComplexAbilityIdentifier($raw['statId'] ?? '', 'character-stat-agility', 120);
            $step['count'] = applicationComplexAbilityInteger($raw['count'] ?? null, 1, 12, 4);
            $step['penaltyPerSuccess'] = applicationComplexAbilityInteger($raw['penaltyPerSuccess'] ?? null, 0, 100, 10);
            $step['stopOnFailure'] = ($raw['stopOnFailure'] ?? true) !== false;
            $step['resolutionMode'] = ($raw['resolutionMode'] ?? '') === 'individual' || ($snapshot && ($raw['resolutionMode'] ?? '') !== 'batch') ? 'individual' : 'batch';
            $step['targetMode'] = $step['resolutionMode'] === 'batch' || ($raw['targetMode'] ?? '') === 'once' ? 'once' : 'each';
            $step['damageMode'] = ($raw['damageMode'] ?? '') === 'configured' ? 'configured' : 'weapon';
            $step['damageComponents'] = $step['damageMode'] === 'configured' ? applicationDamageComponents($raw['damageComponents'] ?? []) : [];
            $step['allowOpposition'] = ($raw['allowOpposition'] ?? true) !== false;
            $step['firstGateAtCast'] = ($raw['firstGateAtCast'] ?? false) === true;
        } elseif ($type === 'allocated-attacks') {
            $requested = applicationComplexAbilityIdentifier($raw['sourceStepId'] ?? '');
            $step['sourceStepId'] = in_array($requested, $targetSteps, true) ? $requested : ($targetSteps[count($targetSteps) - 1] ?? '');
            // Absence du réglage : préserver la vigilance des étapes déjà enregistrées.
            $step['awarenessMode'] = in_array($raw['awarenessMode'] ?? '', ['none', 'once'], true) ? $raw['awarenessMode'] : 'required';
            $step['awarenessStatId'] = applicationComplexAbilityIdentifier($raw['awarenessStatId'] ?? '', 'character-stat-instinct', 120);
            $step['damagePercent'] = applicationComplexAbilityInteger($raw['damagePercent'] ?? null, 1, 100, 100);
            $step['damageMode'] = ($raw['damageMode'] ?? '') === 'configured' ? 'configured' : 'weapon';
            $step['damageComponents'] = $step['damageMode'] === 'configured' ? applicationDamageComponents($raw['damageComponents'] ?? []) : [];
            $step['statId'] = applicationComplexAbilityIdentifier($raw['statId'] ?? '', 'character-stat-agility', 120);
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
        } elseif ($type === 'guard') {
            $step['scope'] = ($raw['scope'] ?? '') === 'targets' ? 'targets' : 'source';
            $requested = applicationComplexAbilityIdentifier($raw['sourceStepId'] ?? '');
            $step['sourceStepId'] = $step['scope'] === 'targets'
                ? (in_array($requested, $targetSteps, true) ? $requested : ($targetSteps[count($targetSteps) - 1] ?? '')) : '';
            $step['percent'] = applicationComplexAbilityInteger($raw['percent'] ?? null, 1, 100, 25);
        } elseif ($type === 'counter') {
            $step['counterId'] = applicationComplexAbilityIdentifier($raw['counterId'] ?? '', 'counter-' . ($index + 1));
            $step['counterLabel'] = applicationComplexAbilityText($raw['counterLabel'] ?? '', 120, 'Charges');
            $step['maximum'] = applicationComplexAbilityInteger($raw['maximum'] ?? null, 1, 999, 3);
            $step['initial'] = applicationComplexAbilityInteger($raw['initial'] ?? null, 0, $step['maximum'], 0);
            $step['gainAmount'] = applicationComplexAbilityInteger($raw['gainAmount'] ?? null, 1, $step['maximum'], 1);
            $step['gainLabel'] = applicationComplexAbilityText($raw['gainLabel'] ?? '', 120, 'Gagner ' . $step['gainAmount']);
            $step['spendOptions'] = normalizeApplicationComplexAbilitySpends($raw['spendOptions'] ?? null);
            $step['completionLabel'] = applicationComplexAbilityText($raw['completionLabel'] ?? '', 120, 'Terminer cette étape');
            $step['gainTrigger'] = in_array($raw['gainTrigger'] ?? '', ['manual', 'hp-loss', 'bleeding-hp-loss', 'condition-applied', 'hp-loss-or-condition'], true)
                ? $raw['gainTrigger'] : 'manual';
            $step['gainCondition'] = $step['gainTrigger'] === 'bleeding-hp-loss' ? 'Saignement'
                : applicationComplexAbilityText($raw['gainCondition'] ?? '', 120);
            $step['radiusCells'] = applicationComplexAbilityInteger($raw['radiusCells'] ?? null, 0, 100, 2);
            $step['spendOncePerTurn'] = ($raw['spendOncePerTurn'] ?? false) === true;
            $step['spendOnSourceTurn'] = ($raw['spendOnSourceTurn'] ?? false) === true;
            $step['visual'] = ($raw['visual'] ?? '') === 'orbs' ? 'orbs' : 'none';
            $step['combatPersistent'] = ($raw['combatPersistent'] ?? false) === true;
            $step['resetOnEnd'] = ($raw['resetOnEnd'] ?? null) === true
                || ($step['combatPersistent'] && ($raw['resetOnEnd'] ?? null) !== false);
        } elseif ($type === 'condition') {
            $step += normalizeApplicationComplexAbilityFact($raw, $targetSteps, $steps);
            if (array_key_exists('expression', $raw) && $raw['expression'] !== null)
                $step['expression'] = normalizeApplicationComplexAbilityExpression($raw['expression'], $targetSteps, $steps);
            $step['onTrueStepId'] = normalizeApplicationComplexAbilityBranch($raw['onTrueStepId'] ?? 'next');
            $step['onFalseStepId'] = normalizeApplicationComplexAbilityBranch($raw['onFalseStepId'] ?? 'next');
        }
        $steps[] = $step;
    }
    return ['version' => $snapshot ? applicationComplexAbilityInteger($source['version'] ?? null, 1, XAR_COMPLEX_ABILITY_WORKFLOW_VERSION, 1) : XAR_COMPLEX_ABILITY_WORKFLOW_VERSION, 'steps' => $steps];
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
    foreach ($value['steps'] as $entry) {
        if (is_array($entry) && ($entry['type'] ?? '') === 'condition' && isset($entry['expression'])) {
            $nodes = 0;
            if (applicationComplexAbilityExpressionLeaves($entry['expression'], 0, $nodes) === null)
                return 'Une expression de condition est invalide ou dépasse 5 niveaux et 32 nœuds.';
        }
    }
    $workflow = normalizeApplicationComplexAbilityWorkflow($value);
    $seen = [];
    foreach ($workflow['steps'] as $index => $step) {
        if (isset($seen[$step['id']])) return 'L’étape ' . ($index + 1) . ' possède un identifiant en double.';
        $seen[$step['id']] = true;
        if ($step['title'] === '') return 'Donnez un titre à l’étape ' . ($index + 1) . '.';
        if ($step['type'] === 'attack-chain' && preg_match('/^character-stat-(force|dexterity|agility|spiritSocial|intelligence|instinct)$/D', $step['statId']) !== 1) {
            return 'Choisissez une statistique valide pour les attaques conditionnelles de l’étape ' . ($index + 1) . '.';
        }
        if ($step['type'] === 'attack-chain' && $step['firstGateAtCast'] && ($index !== 0 || !$step['stopOnFailure']))
            return 'Le premier jet au lancement demande une chaîne en première étape avec arrêt à l’échec.';
        if ($step['type'] === 'attack-chain' && $step['damageMode'] === 'configured' && ($step['resolutionMode'] !== 'batch'
            || $step['damageComponents'] === [] || count($step['damageComponents']) !== count($value['steps'][$index]['damageComponents'] ?? [])))
            return 'La série de l’étape ' . ($index + 1) . ' exige le mode automatique et des composantes de dégâts valides.';
        if ($step['type'] === 'allocated-attacks' && $step['damageMode'] === 'configured' && ($step['damageComponents'] === [] || count($step['damageComponents']) !== count($value['steps'][$index]['damageComponents'] ?? []) || preg_match('/^character-stat-(force|dexterity|agility|spiritSocial|intelligence|instinct)$/D', $step['statId']) !== 1)) return 'Définissez des dégâts et une statistique valides à l’étape ' . ($index + 1) . '.';
        if ($step['type'] === 'allocated-attacks') {
            $sources = array_filter(array_slice($workflow['steps'], 0, $index), static fn(array $candidate): bool => $candidate['id'] === $step['sourceStepId'] && $candidate['type'] === 'targets' && ($candidate['allocationTotal'] > 0 || $step['damageMode'] === 'configured'));
            if ($sources === []) return 'L’étape ' . ($index + 1) . ' requiert une répartition précédente avec un total d’attaques fixé.';
            if ($step['awarenessMode'] !== 'none' && preg_match('/^character-stat-(force|dexterity|agility|spiritSocial|intelligence|instinct)$/D', $step['awarenessStatId']) !== 1) return 'Choisissez une statistique de vigilance valide à l’étape ' . ($index + 1) . '.';
        }
        if ($step['type'] === 'defense-series' && ($step['sourceStepId'] === '' || !isset($seen[$step['sourceStepId']]))) {
            return 'L’étape ' . ($index + 1) . ' doit suivre une étape de ciblage.';
        }
        if ($step['type'] === 'guard' && $step['scope'] === 'targets' && ($step['sourceStepId'] === '' || !isset($seen[$step['sourceStepId']]))) {
            return 'La protection de l’étape ' . ($index + 1) . ' doit utiliser une sélection de cibles précédente.';
        }
        if ($step['type'] === 'choice' && count($step['options']) < 2) return 'Ajoutez au moins deux choix à l’étape ' . ($index + 1) . '.';
        if ($step['type'] === 'counter') {
            if ($step['spendOptions'] === []) return 'Ajoutez au moins une utilisation de charge à l’étape ' . ($index + 1) . '.';
            foreach (is_array($value['steps'][$index]['spendOptions'] ?? null) ? $value['steps'][$index]['spendOptions'] : [] as $rawOption) {
                $effect = is_array($rawOption) ? ($rawOption['effect'] ?? null) : null;
                if ($effect === null) continue;
                if (!is_array($effect) || !in_array($effect['kind'] ?? '', ['none', 'damage', 'guard', 'marker'], true))
                    return 'L’effet d’une utilisation de charge à l’étape ' . ($index + 1) . ' est inconnu.';
                if ($effect['kind'] === 'damage' && (!validApplicationAbilityFormula($effect['formula'] ?? null)
                    || !in_array($effect['damageType'] ?? '', ['physical', 'magical', 'ignore'], true)))
                    return 'Renseignez une formule et un type de dégâts valides à l’étape ' . ($index + 1) . '.';
                if ($effect['kind'] === 'guard' && (!is_int($effect['percent'] ?? null)
                    || $effect['percent'] < 1 || $effect['percent'] > 100))
                    return 'La protection de l’étape ' . ($index + 1) . ' doit valoir de 1 à 100 %.';
                if ($effect['kind'] === 'marker' && ($rawOption['cost'] ?? null) !== 1)
                    return 'Un marqueur posé occupe exactement une place à l’étape ' . ($index + 1) . '.';
            }
        }
        if ($step['type'] === 'condition') {
            $rawExpression = is_array($value['steps'][$index] ?? null) ? ($value['steps'][$index]['expression'] ?? null) : null;
            $nodes = 0;
            $leaves = $rawExpression === null ? [$step] : applicationComplexAbilityExpressionLeaves($rawExpression, 0, $nodes);
            if ($leaves === null) return 'L’expression de l’étape ' . ($index + 1) . ' doit contenir 2 à 16 critères par groupe, au plus 32 nœuds et 5 niveaux, avec all, any, not ou fact.';
            foreach ($leaves as $leaf) {
                if ($rawExpression !== null && (!in_array($leaf['fact'] ?? '', applicationComplexAbilityFacts(), true)
                    || !in_array($leaf['operator'] ?? '', ['eq', 'ne', 'lt', 'lte', 'gt', 'gte', 'between', 'contains', 'not-contains'], true)
                    || !in_array($leaf['subject'] ?? '', ['source', 'targets'], true))) return 'Un critère de l’étape ' . ($index + 1) . ' est inconnu.';
                $criterion = $rawExpression === null ? $step : normalizeApplicationComplexAbilityFact($leaf,
                    array_column(array_filter(array_slice($workflow['steps'], 0, $index), static fn(array $candidate): bool => $candidate['type'] === 'targets'), 'id'),
                    array_slice($workflow['steps'], 0, $index));
                if (($criterion['subject'] === 'targets' || $criterion['fact'] === 'target-count')
                    && ($criterion['sourceStepId'] === '' || !isset($seen[$criterion['sourceStepId']])))
                    return 'L’étape ' . ($index + 1) . ' doit utiliser une sélection de cibles précédente.';
                $expectedReferenceType = applicationComplexAbilityReferenceTypes()[$criterion['fact']] ?? '';
                if ($expectedReferenceType !== '' && ($criterion['referenceStepId'] === '' || !isset($seen[$criterion['referenceStepId']])))
                    return 'L’étape ' . ($index + 1) . ' doit référencer une étape précédente compatible.';
                $reference = null;
                foreach (array_slice($workflow['steps'], 0, $index) as $candidate) {
                    if ($candidate['id'] === $criterion['referenceStepId']) { $reference = $candidate; break; }
                }
                if ($expectedReferenceType !== '' && (!is_array($reference) || $reference['type'] !== $expectedReferenceType))
                    return 'L’étape ' . ($index + 1) . ' doit référencer une étape « ' . $expectedReferenceType . ' ».';
                if (in_array($criterion['fact'], ['roll-successes', 'last-roll-success'], true)
                    && is_array($reference) && $reference['threshold'] === null)
                    return 'L’étape de jets référencée par l’étape ' . ($index + 1) . ' doit avoir un seuil de réussite.';
                if ($criterion['fact'] === 'condition' && $criterion['conditionLabel'] === '') return 'Renseignez l’effet recherché à l’étape ' . ($index + 1) . '.';
                if ($criterion['fact'] !== 'condition' && trim((string) $criterion['value']) === '') return 'Renseignez la valeur attendue à l’étape ' . ($index + 1) . '.';
                if (in_array($criterion['fact'], ['condition', 'last-roll-success', 'last-gate-success', 'health-state'], true)
                    && !in_array($criterion['operator'], ['eq', 'ne'], true))
                    return 'La comparaison de l’étape ' . ($index + 1) . ' doit être « égal » ou « différent ».';
                if ($criterion['operator'] === 'between' && (trim((string) $criterion['secondValue']) === '' || !is_numeric($criterion['secondValue'])))
                    return 'Renseignez la seconde valeur numérique de l’étape ' . ($index + 1) . '.';
            }
            foreach ([$step['onTrueStepId'], $step['onFalseStepId']] as $branch) {
                if (in_array($branch, ['next', 'finish'], true)) continue;
                $targetIndex = array_search($branch, array_column($workflow['steps'], 'id'), true);
                if ($targetIndex === false || $targetIndex <= $index) return 'La branche de l’étape ' . ($index + 1) . ' doit aller vers une étape suivante ou terminer la compétence.';
            }
        }
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
    elseif ($step['type'] === 'attack-chain') $state += ['rolls' => [], 'attacks' => [], 'awaitingAttack' => false, 'plan' => null];
    elseif ($step['type'] === 'allocated-attacks') $state += ['targets' => [], 'pendingTargetTokenId' => '', 'awaitingAwareness' => false, 'awaitingAttack' => false];
    elseif ($step['type'] === 'defense-series') $state['targets'] = [];
    elseif ($step['type'] === 'choice') $state['choice'] = null;
    elseif ($step['type'] === 'counter') $state += ['value' => $step['initial'], 'deployments' => [], 'events' => [], 'lastSpendTurnKey' => ''];
    elseif ($step['type'] === 'guard') $state['protectedTokenIds'] = [];
    elseif ($step['type'] === 'condition') $state += ['outcome' => null, 'matchedCount' => 0, 'subjectCount' => 0, 'branch' => ''];
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
    $first = $workflow['steps'][0];
    $cast = is_array($context['cast'] ?? null) ? $context['cast'] : [];
    foreach ($workflow['steps'] as $configuredStep) if ($configuredStep['type'] === 'allocated-attacks' && $configuredStep['damageMode'] === 'configured' && (($ability['castingStatId'] ?? '') !== $configuredStep['statId'] || !($cast['success'] ?? false) || ($cast['statId'] ?? '') !== $configuredStep['statId'] || !is_array($cast['outcome'] ?? null))) applicationComplexAbilityFail('Cette série exige un jet de lancement de la même statistique.', 'complex_ability_definition_invalid', 400);
    if (($first['firstGateAtCast'] ?? false) === true && (($ability['castingStatId'] ?? '') !== $first['statId']
        || ($cast['success'] ?? false) !== true || ($cast['statId'] ?? '') !== $first['statId']
        || !is_array($cast['outcome'] ?? null)))
        applicationComplexAbilityFail('Le premier jet de chaîne doit être le jet de lancement de la même statistique.', 'complex_ability_definition_invalid', 400);
    $now = is_numeric($context['now'] ?? null) ? (int) $context['now'] : (int) floor(microtime(true) * 1000);
    $execution = [
        'id' => applicationComplexAbilityIdentifier($context['id'] ?? '', 'execution-' . ($context['requestId'] ?? $now), 120),
        'sceneId' => applicationComplexAbilityIdentifier($context['sceneId'] ?? '', '', 80),
        'layerId' => in_array($context['layerId'] ?? '', ['basement', 'ground', 'upper'], true) ? $context['layerId'] : 'ground',
        'sourceTokenId' => applicationComplexAbilityIdentifier($context['sourceTokenId'] ?? '', '', 80),
        'initialTargetTokenId' => applicationComplexAbilityIdentifier($context['targetTokenId'] ?? '', '', 80),
        'sourceName' => applicationComplexAbilityText($context['sourceName'] ?? '', 120, 'Personnage'),
        'characterId' => applicationComplexAbilityIdentifier($context['characterId'] ?? '', '', 180),
        'controllerAccountId' => applicationComplexAbilityIdentifier($context['controllerAccountId'] ?? '', '', 128),
        'controllerName' => applicationComplexAbilityText($context['controllerName'] ?? '', 120),
        'combatId' => applicationComplexAbilityIdentifier($context['combatId'] ?? '', '', 120),
        'abilityId' => applicationComplexAbilityIdentifier($ability['id'] ?? '', '', 120),
        'abilityName' => applicationComplexAbilityText($ability['name'] ?? '', 120, 'Compétence complexe'),
        'castGate' => ($cast['success'] ?? false) && is_array($cast['outcome'] ?? null) ? [
            'formula' => '1d100', 'total' => (int) $cast['outcome']['raw'], 'threshold' => (int) $cast['outcome']['threshold'],
            'success' => true, 'statId' => (string) $cast['statId'], 'outcomeDetails' => $cast['outcome'], 'rollId' => $cast['roll']['id'] ?? '',
        ] : null,
        'onHitConditions' => normalizeOnlineConditions($ability['onHitConditions'] ?? []),
        'completionCue' => normalizeApplicationAbilityCompletionCue($ability['completionCue'] ?? null),
        'workflow' => $workflow,
        'status' => 'active', 'revision' => 1, 'currentStepIndex' => 0,
        'stepStates' => [], 'events' => [], 'createdAt' => $now, 'updatedAt' => $now, 'endedByFailure' => false,
    ];
    if ($execution['sceneId'] === '' || $execution['sourceTokenId'] === '' || $execution['controllerAccountId'] === '' || $execution['abilityId'] === '') {
        applicationComplexAbilityFail('Le contexte de lancement de la compétence complexe est incomplet.', 'complex_ability_context_invalid', 400);
    }
    $execution['stepStates'][$first['id']] = applicationComplexAbilityInitialStepState($first);
    if (($first['firstGateAtCast'] ?? false) === true) {
        $outcome = $cast['outcome'];
        $execution['stepStates'][$first['id']]['rolls'][] = [
            'formula' => '1d100', 'total' => (int) ($outcome['raw'] ?? 0), 'threshold' => (int) ($outcome['threshold'] ?? 1),
            'success' => true, 'outcome' => (string) ($outcome['code'] ?? 'success'),
            'outcomeDetails' => $outcome, 'rollId' => $cast['roll']['id'] ?? '',
            'breakdown' => applicationComplexAbilityText($cast['roll']['breakdown'] ?? '', 500, (string) ($outcome['raw'] ?? '')),
            'fromCast' => true,
        ];
        $execution['stepStates'][$first['id']]['awaitingAttack'] = true;
        $execution['stepStates'][$first['id']]['gateAt'] = $now;
    }
    applicationComplexAbilityAppendEvent($execution, [
        'type' => 'started', 'actorId' => $execution['controllerAccountId'], 'actorName' => $execution['controllerName'],
        'label' => $execution['abilityName'] . ' commence',
    ], $now);
    return $execution;
}

function normalizeApplicationComplexAbilityExecution(mixed $value): array
{
    $source = is_array($value) ? $value : [];
    $workflow = normalizeApplicationComplexAbilityWorkflow($source['workflow'] ?? null, (int) ($source['workflow']['version'] ?? 1) < 7);
    $status = in_array($source['status'] ?? '', ['active', 'completed', 'cancelled'], true) ? $source['status'] : 'cancelled';
    $execution = [
        'id' => applicationComplexAbilityIdentifier($source['id'] ?? '', '', 120),
        'sceneId' => applicationComplexAbilityIdentifier($source['sceneId'] ?? '', '', 80),
        'layerId' => in_array($source['layerId'] ?? '', ['basement', 'ground', 'upper'], true) ? $source['layerId'] : 'ground',
        'sourceTokenId' => applicationComplexAbilityIdentifier($source['sourceTokenId'] ?? '', '', 80),
        'initialTargetTokenId' => applicationComplexAbilityIdentifier($source['initialTargetTokenId'] ?? '', '', 80),
        'sourceName' => applicationComplexAbilityText($source['sourceName'] ?? '', 120, 'Personnage'),
        'characterId' => applicationComplexAbilityIdentifier($source['characterId'] ?? '', '', 180),
        'controllerAccountId' => applicationComplexAbilityIdentifier($source['controllerAccountId'] ?? '', '', 128),
        'controllerName' => applicationComplexAbilityText($source['controllerName'] ?? '', 120),
        'combatId' => applicationComplexAbilityIdentifier($source['combatId'] ?? '', '', 120),
        'abilityId' => applicationComplexAbilityIdentifier($source['abilityId'] ?? '', '', 120),
        'abilityName' => applicationComplexAbilityText($source['abilityName'] ?? '', 120, 'Compétence complexe'),
        'castGate' => is_array($source['castGate'] ?? null) ? $source['castGate'] : null,
        'onHitConditions' => normalizeOnlineConditions($source['onHitConditions'] ?? []),
        'completionCue' => normalizeApplicationAbilityCompletionCue($source['completionCue'] ?? null),
        'workflow' => $workflow, 'status' => $status,
        'revision' => applicationComplexAbilityInteger($source['revision'] ?? null, 1, PHP_INT_MAX, 1),
        'currentStepIndex' => applicationComplexAbilityInteger($source['currentStepIndex'] ?? null, 0, count($workflow['steps']), 0),
        'stepStates' => is_array($source['stepStates'] ?? null) ? $source['stepStates'] : [],
        'events' => [],
        'createdAt' => is_numeric($source['createdAt'] ?? null) ? (int) $source['createdAt'] : 0,
        'updatedAt' => is_numeric($source['updatedAt'] ?? null) ? (int) $source['updatedAt'] : 0,
        'endedByFailure' => ($source['endedByFailure'] ?? false) === true,
        'endedByCombat' => ($source['endedByCombat'] ?? false) === true,
        'compactDiscordSent' => ($source['compactDiscordSent'] ?? false) === true,
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

function applicationComplexAbilityFinishChainBatch(array &$execution, array $step, array &$state, int $now): void
{
    $state['awaitingAttack'] = false;
    applicationComplexAbilityFinishStep($execution, $state, $now);
    if (($state['gateFailure'] ?? false) && $step['stopOnFailure']) {
        $execution['currentStepIndex'] = count($execution['workflow']['steps']);
        $execution['status'] = 'completed'; $execution['completedAt'] = $now; $execution['endedByFailure'] = true;
    }
}

function applicationComplexAbilityPrepareChainBatch(array &$execution, array $step, array &$state, array $source,
    string $actorId, string $actorName, callable $roll, string $rollMode, int $now): void
{
    $baseThreshold = applicationComplexAbilityTokenThreshold($source, ['thresholdMode' => 'stat', 'targetStatId' => $step['statId']]);
    $successes = count(array_filter($state['rolls'], static fn(array $entry): bool => ($entry['success'] ?? false) === true));
    while (count($state['rolls']) < $step['count'] && count(array_filter($state['rolls'], static fn(array $entry): bool => ($entry['success'] ?? true) === false)) === 0) {
        $modifier = -min(100, $successes * $step['penaltyPerSuccess']);
        $rolled = $roll('1d100', normalizeOnlineRollMode($rollMode), true);
        $raw = $rolled['rawD100'] ?? $rolled['total'] ?? null;
        $outcome = classifyOnlineD100Outcome($raw, $baseThreshold, $modifier, 0, true, true);
        if ($outcome === null) applicationComplexAbilityFail('Le résultat du d100 est invalide.', 'complex_ability_roll_invalid', 500);
        $state['rolls'][] = [...$rolled, 'formula' => '1d100', 'total' => (int) $raw, 'rawD100' => (int) $raw,
            'threshold' => $outcome['threshold'], 'success' => $outcome['success'], 'outcome' => $outcome['code'],
            'outcomeDetails' => $outcome, 'breakdown' => applicationComplexAbilityText($rolled['breakdown'] ?? '', 500, (string) $raw)];
        applicationComplexAbilityAppendEvent($execution, ['type' => 'roll', 'actorId' => $actorId, 'actorName' => $actorName,
            'stepId' => $step['id'], 'label' => $step['title'] . ' · jet ' . count($state['rolls']) . '/' . $step['count'] . ' · ' . $outcome['label'],
            'detail' => $raw . ' · seuil ' . $baseThreshold . ($modifier ? ' ' . $modifier : '') . ' → ' . $outcome['threshold']], $now);
        if ($outcome['success']) $successes++;
    }
    $state['batchReady'] = true; $state['queuedAttackCount'] = $successes;
    $state['gateFailure'] = count(array_filter($state['rolls'], static fn(array $entry): bool => ($entry['success'] ?? true) === false)) > 0;
    $state['awaitingAttack'] = $successes > 0; $state['gateAt'] = $now;
    if ($successes === 0) applicationComplexAbilityFinishChainBatch($execution, $step, $state, $now);
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

function applicationComplexAbilityPlacedMarkers(mixed $value, string $sceneId = '', string $layerId = '', array $tokens = [], array $guards = [], array $initiative = []): array
{
    $markers = [];
    foreach (normalizeApplicationComplexAbilityExecutions($value) as $execution) {
        if ($execution['status'] !== 'active' || ($sceneId !== '' && $execution['sceneId'] !== $sceneId)
            || ($layerId !== '' && $execution['layerId'] !== $layerId)) continue;
        $step = $execution['workflow']['steps'][$execution['currentStepIndex']] ?? null;
        if (!is_array($step) || $step['type'] !== 'counter') continue;
        $state = $execution['stepStates'][$step['id']] ?? [];
        $source = applicationComplexAbilityTokenById($tokens, (string) ($execution['sourceTokenId'] ?? ''));
        if (($step['visual'] ?? '') === 'orbs' && is_array($source) && ($source['layerId'] ?? 'ground') === $execution['layerId']) {
            $count = min(12, max(0, (int) ($state['value'] ?? $step['initial']) - count($state['deployments'] ?? [])));
            for ($i = 0; $i < $count; $i++) $markers[] = ['id' => 'orb-' . $execution['id'] . '-' . $i,
                'executionId' => $execution['id'], 'sceneId' => $execution['sceneId'], 'layerId' => $execution['layerId'],
                'sourceTokenId' => $execution['sourceTokenId'], 'x' => (float) $source['x'], 'y' => (float) $source['y'],
                'label' => $step['counterLabel'], 'visual' => 'orb', 'orbitIndex' => $i, 'movable' => false,
                'controllerAccountId' => $execution['controllerAccountId']];
        }
        foreach (array_slice(is_array($state['deployments'] ?? null) ? $state['deployments'] : [], 0, $step['maximum']) as $marker) {
            if (!is_array($marker) || !is_numeric($marker['x'] ?? null) || !is_numeric($marker['y'] ?? null)) continue;
            $id = applicationComplexAbilityIdentifier($marker['id'] ?? '', '', 80);
            if ($id === '') continue;
            $markers[] = ['id' => $id, 'executionId' => $execution['id'], 'sceneId' => $execution['sceneId'],
                'layerId' => $execution['layerId'], 'sourceTokenId' => $execution['sourceTokenId'],
                'x' => (float) $marker['x'], 'y' => (float) $marker['y'],
                'label' => applicationComplexAbilityText($marker['label'] ?? '', 120, 'Marqueur'),
                'movable' => ($marker['movable'] ?? false) === true,
                'controllerAccountId' => $execution['controllerAccountId']];
        }
    }
    foreach ($tokens as $token) {
        if (($token['layerId'] ?? 'ground') !== $layerId || !($initiative['active'] ?? false)) continue;
        $matching = array_values(array_filter($guards, static fn(array $guard): bool => ($guard['sceneId'] ?? '') === $sceneId && ($guard['targetTokenId'] ?? '') === ($token['id'] ?? '') && ($guard['combatId'] ?? '') === ($initiative['combatId'] ?? '')));
        if ($matching === []) continue;
        $first = $matching[0]; $percent = max(1, min(100, (int) ($first['percent'] ?? 0)));
        if (($first['stackGroup'] ?? '') !== '') foreach (array_slice($matching, 1) as $guard) if (($guard['stackGroup'] ?? '') === $first['stackGroup']) $percent = min(100, $percent + max(1, min(100, (int) ($guard['percent'] ?? 0))));
        $markers[] = ['id' => 'guard-display-' . $token['id'], 'visual' => 'guard', 'percent' => $percent,
            'x' => (float) $token['x'], 'y' => (float) $token['y'], 'label' => 'Protection · ' . $percent . ' %',
            'sceneId' => $sceneId, 'layerId' => $layerId, 'sourceTokenId' => $token['id'], 'executionId' => '', 'controllerAccountId' => '', 'movable' => false];
    }
    return $markers;
}

function validApplicationComplexAbilityExecutions(mixed $value): bool
{
    if (!is_array($value) || !array_is_list($value) || count($value) > XAR_COMPLEX_ABILITY_MAXIMUM_EXECUTIONS) return false;
    foreach ($value as $entry) {
        if (!is_array($entry) || !validApplicationComplexAbilityWorkflow($entry['workflow'] ?? null)
            || (array_key_exists('completionCue', $entry) && !validApplicationAbilityCompletionCue($entry['completionCue']))
            || (array_key_exists('onHitConditions', $entry) && !validApplicationConditions($entry['onHitConditions']))
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

function applicationComplexAbilityGainBleedingCharges(mixed $value, array $context): array
{
    $executions = normalizeApplicationComplexAbilityExecutions($value);
    $target = is_array($context['target'] ?? null) ? $context['target'] : [];
    $added = is_array($context['addedConditions'] ?? null) ? $context['addedConditions'] : [];
    if (($target['id'] ?? '') === '' || (float) ($context['hpLost'] ?? 0) <= 0 && $added === []) return $executions;
    $map = is_array($context['map'] ?? null) ? $context['map'] : [];
    $width = (float) ($map['naturalWidth'] ?? 0); $height = (float) ($map['naturalHeight'] ?? 0); $grid = (float) ($map['gridSize'] ?? 0);
    if ($width <= 0 || $height <= 0 || $grid <= 0) return $executions;
    $sceneId = (string) ($context['sceneId'] ?? ''); $layerId = (string) ($context['layerId'] ?? 'ground');
    $tokens = is_array($context['tokens'] ?? null) ? $context['tokens'] : [];
    $combatId = (string) ($context['combatId'] ?? '');
    $now = (int) ($context['now'] ?? floor(microtime(true) * 1000));
    foreach ($executions as &$execution) {
        if ($execution['status'] !== 'active' || $execution['sceneId'] !== $sceneId || $execution['layerId'] !== $layerId) continue;
        $step = $execution['workflow']['steps'][$execution['currentStepIndex']] ?? null;
        if (!is_array($step) || $step['type'] !== 'counter' || !in_array($step['gainTrigger'], ['hp-loss', 'bleeding-hp-loss', 'condition-applied', 'hp-loss-or-condition'], true)
            || ($step['combatPersistent'] && ($combatId === '' || $execution['combatId'] !== $combatId))) continue;
        if ($step['gainCondition'] !== '' && !in_array(onlineConditionKey(normalizeOnlineConditions([$step['gainCondition']])[0] ?? $step['gainCondition']),
            array_map('onlineConditionKey', normalizeOnlineConditions($target['conditions'] ?? [], $target['condition'] ?? '')), true)) continue;
        $applied = $step['gainCondition'] !== '' && in_array(onlineConditionKey(normalizeOnlineConditions([$step['gainCondition']])[0] ?? $step['gainCondition']), array_map('onlineConditionKey', normalizeOnlineConditions($added)), true);
        $wounded = (float) ($context['hpLost'] ?? 0) > 0;
        if ($step['gainTrigger'] === 'condition-applied' ? !$applied : ($step['gainTrigger'] === 'hp-loss-or-condition' ? !$wounded && !$applied : !$wounded)) continue;
        $source = applicationComplexAbilityTokenById($tokens, $execution['sourceTokenId']);
        if (!is_array($source) || ($target['layerId'] ?? 'ground') !== $layerId || ($source['layerId'] ?? 'ground') !== $layerId) continue;
        $dx = ((float) ($source['x'] ?? 0) - (float) ($target['x'] ?? 0)) * $width / (100 * $grid);
        $dy = ((float) ($source['y'] ?? 0) - (float) ($target['y'] ?? 0)) * $height / (100 * $grid);
        if (sqrt($dx * $dx + $dy * $dy) > $step['radiusCells']) continue;
        if (!isset($execution['stepStates'][$step['id']])) $execution['stepStates'][$step['id']] = applicationComplexAbilityInitialStepState($step);
        $state =& $execution['stepStates'][$step['id']];
        $previous = applicationComplexAbilityInteger($state['value'] ?? null, 0, $step['maximum'], $step['initial']);
        if ($previous < $step['maximum']) {
            $next = min($step['maximum'], $previous + $step['gainAmount']);
            $state['value'] = $next;
            $state['events'][] = ['at' => $now, 'type' => 'gain', 'value' => $next, 'amount' => $next - $previous, 'actorId' => 'system'];
            $state['events'] = array_slice($state['events'], -XAR_COMPLEX_ABILITY_MAXIMUM_EVENTS);
            applicationComplexAbilityAppendEvent($execution, ['type' => 'counter', 'actorId' => 'system', 'actorName' => 'Combat',
                'stepId' => $step['id'], 'label' => $step['counterLabel'] . ' : ' . $previous . ' → ' . $next,
                'detail' => ($wounded ? 'Perte de PV' : 'Nouvel état') . ($step['gainCondition'] !== '' ? ' avec ' . $step['gainCondition'] : '') . ' à portée'], $now);
            $execution['revision'] += 1; $execution['updatedAt'] = $now;
        }
        unset($state);
    }
    unset($execution);
    return $executions;
}

function applicationComplexAbilityTokenById(array $tokens, mixed $id): ?array
{
    foreach ($tokens as $token) if (is_array($token) && (string) ($token['id'] ?? '') === (string) $id) return $token;
    return null;
}

function applicationComplexAbilityComparable(mixed $value): string
{
    $normalized = strtolower(strtr((string) $value, [
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'À' => 'a', 'Â' => 'a', 'Ä' => 'a',
        'î' => 'i', 'ï' => 'i', 'Î' => 'i', 'Ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'Ô' => 'o', 'Ö' => 'o',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u', 'ç' => 'c', 'Ç' => 'c',
    ]));
    return preg_replace('/[^a-z0-9]/', '', $normalized) ?? '';
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
        if ($matches && is_numeric($stat['value'] ?? null)) return max(1, applicationComplexAbilityInteger($stat['value'], 0, 100, 0));
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

function applicationComplexAbilityInitializeAllocatedTargets(array &$execution, array $step, array &$state, array $tokens): void
{
    if (is_array($state['targets'] ?? null) && $state['targets'] !== []) return;
    $allocations = $execution['stepStates'][$step['sourceStepId']]['allocations'] ?? [];
    if (!is_array($allocations) || $allocations === []) applicationComplexAbilityFail('La répartition d’attaques n’est plus disponible.', 'complex_ability_targets_missing');
    $state['targets'] = [];
    foreach ($allocations as $allocation) {
        $token = applicationComplexAbilityTokenById($tokens, $allocation['tokenId'] ?? '');
        $state['targets'][] = [
            'tokenId' => (string) ($allocation['tokenId'] ?? ''),
            'targetName' => applicationComplexAbilityText($token['name'] ?? ($allocation['targetName'] ?? ''), 120, 'Cible'),
            'attackCount' => applicationComplexAbilityInteger($allocation['count'] ?? null, 1, 100, 1),
            'awarenessRolls' => [], 'aware' => $step['awarenessMode'] === 'none', 'awarenessRolled' => false, 'attacks' => [],
        ];
    }
}

function applicationComplexAbilityTargetInRange(?array $source, ?array $target, array $map, int $rangeCells): bool
{
    if ($rangeCells < 1) return true;
    $width = (float) ($map['naturalWidth'] ?? 0);
    $height = (float) ($map['naturalHeight'] ?? 0);
    $grid = (float) ($map['gridSize'] ?? 0);
    if ($source === null || $target === null || $width <= 0 || $height <= 0 || $grid <= 0) return false;
    $dx = ((float) ($source['x'] ?? 0) - (float) ($target['x'] ?? 0)) * $width / (100 * $grid);
    $dy = ((float) ($source['y'] ?? 0) - (float) ($target['y'] ?? 0)) * $height / (100 * $grid);
    return sqrt($dx * $dx + $dy * $dy) <= $rangeCells + 1e-9;
}

function applicationComplexAbilityCurrentDefenseIndex(array $state): ?int
{
    foreach (is_array($state['targets'] ?? null) ? $state['targets'] : [] as $index => $target) {
        if (($target['status'] ?? '') === 'pending') return $index;
    }
    return null;
}

function applicationComplexAbilityHealthStateValue(mixed $value): string
{
    $text = applicationComplexAbilityComparable($value);
    if (in_array($text, ['critique', 'critical'], true)) return 'critical';
    if (in_array($text, ['ko', 'down', 'inconscient'], true)) return 'down';
    if (in_array($text, ['mort', 'dead'], true)) return 'dead';
    return 'normal';
}

function applicationComplexAbilityTokenConditionValue(array $token, array $step): mixed
{
    $hp = is_numeric($token['hp'] ?? null) ? (float) $token['hp'] : 0.0;
    $maxHp = is_numeric($token['maxHp'] ?? null) ? (float) $token['maxHp'] : 0.0;
    $mana = is_numeric($token['mana'] ?? null) ? (float) $token['mana'] : 0.0;
    $maxMana = is_numeric($token['maxMana'] ?? null) ? (float) $token['maxMana'] : 0.0;
    if ($step['fact'] === 'hp') return $hp;
    if ($step['fact'] === 'max-hp') return $maxHp;
    if ($step['fact'] === 'missing-hp') return max(0, $maxHp - $hp);
    if ($step['fact'] === 'hp-percent') return $maxHp > 0 ? max(0, min(100, $hp / $maxHp * 100)) : 0;
    if ($step['fact'] === 'mana') return $mana;
    if ($step['fact'] === 'max-mana') return $maxMana;
    if ($step['fact'] === 'missing-mana') return max(0, $maxMana - $mana);
    if ($step['fact'] === 'mana-percent') return $maxMana > 0 ? max(0, min(100, $mana / $maxMana * 100)) : 0;
    if ($step['fact'] === 'fatigue') {
        $fatigue = is_array($token['fatigue'] ?? null) ? ($token['fatigue']['current'] ?? 0) : ($token['fatigue'] ?? 0);
        return is_numeric($fatigue) ? 0 + $fatigue : 0;
    }
    if ($step['fact'] === 'health-state') {
        $manualDeath = ($token['healthOverride'] ?? null) === 'dead';
        $playerControlled = trim((string) ($token['controllerAccountId'] ?? $token['controllerPlayerId'] ?? '')) !== '';
        if ($manualDeath || ($hp < 0 && (!$playerControlled || $hp < -$maxHp / 4))) return 'dead';
        if ($hp <= 0) return 'down';
        return $maxHp > 0 && ($hp / $maxHp * 100) <= 10 ? 'critical' : 'normal';
    }
    if ($step['fact'] === 'condition') {
        $wanted = applicationComplexAbilityComparable($step['conditionLabel'] !== '' ? $step['conditionLabel'] : $step['value']);
        $conditions = is_array($token['conditions'] ?? null)
            ? $token['conditions'] : explode(',', (string) ($token['condition'] ?? ''));
        foreach ($conditions as $condition) if (applicationComplexAbilityComparable($condition) === $wanted) return true;
        return false;
    }
    if ($step['fact'] === 'stat') {
        $requested = preg_replace('/^character-stat-/', '', (string) $step['statId']) ?? '';
        $aliases = ['dexterity' => 'dexterite', 'agility' => 'agilite', 'spiritSocial' => 'espritsocial', 'instinct' => 'instinctperception'];
        $stats = is_array($token['stats'] ?? null) ? $token['stats'] : [];
        if (!array_is_list($stats)) {
            $stats = array_map(static fn(string $id, mixed $value): array => ['id' => $id, 'value' => $value], array_keys($stats), array_values($stats));
        }
        foreach ($stats as $stat) {
            if (!is_array($stat)) continue;
            $id = preg_replace('/^character-stat-/', '', (string) ($stat['id'] ?? '')) ?? '';
            $matches = $id === $requested || applicationComplexAbilityComparable($stat['label'] ?? '') === ($aliases[$requested] ?? applicationComplexAbilityComparable($requested));
            if ($matches && is_numeric($stat['value'] ?? null)) return 0 + $stat['value'];
        }
        applicationComplexAbilityFail('La statistique demandée n’est plus disponible.', 'complex_ability_condition_data_missing');
    }
    applicationComplexAbilityFail('Cette donnée ne s’évalue pas sur un pion.', 'complex_ability_condition_invalid', 400);
}

function applicationComplexAbilityWorkflowConditionValue(array $execution, array $step): mixed
{
    if ($step['fact'] === 'target-count') {
        $allocations = $execution['stepStates'][$step['sourceStepId']]['allocations'] ?? [];
        return is_array($allocations) ? count($allocations) : 0;
    }
    $reference = $execution['stepStates'][$step['referenceStepId']] ?? null;
    if (!is_array($reference)) applicationComplexAbilityFail('L’étape référencée n’a pas encore de résultat.', 'complex_ability_condition_data_missing');
    if ($step['fact'] === 'roll-successes') {
        return count(array_filter(is_array($reference['rolls'] ?? null) ? $reference['rolls'] : [], static fn(mixed $entry): bool => is_array($entry) && ($entry['success'] ?? false) === true));
    }
    if ($step['fact'] === 'last-roll-success') {
        $rolls = is_array($reference['rolls'] ?? null) ? $reference['rolls'] : [];
        $last = $rolls[count($rolls) - 1] ?? null;
        if (!is_array($last) || !is_bool($last['success'] ?? null)) applicationComplexAbilityFail('Le dernier jet référencé n’a pas d’issue réussite/échec.', 'complex_ability_condition_data_missing');
        return $last['success'];
    }
    if ($step['fact'] === 'choice') return is_array($reference['choice'] ?? null) ? $reference['choice'] : [];
    if ($step['fact'] === 'counter') return is_numeric($reference['value'] ?? null) ? 0 + $reference['value'] : 0;
    if ($step['fact'] === 'attack-count') return count(is_array($reference['attacks'] ?? null) ? $reference['attacks'] : []);
    if ($step['fact'] === 'gate-successes' || $step['fact'] === 'gate-failures') {
        $success = $step['fact'] === 'gate-successes';
        return count(array_filter(is_array($reference['rolls'] ?? null) ? $reference['rolls'] : [],
            static fn(mixed $entry): bool => is_array($entry) && ($entry['success'] ?? null) === $success));
    }
    if ($step['fact'] === 'last-gate-success') {
        $rolls = is_array($reference['rolls'] ?? null) ? $reference['rolls'] : [];
        $last = $rolls[count($rolls) - 1] ?? null;
        if (!is_array($last) || !is_bool($last['success'] ?? null)) applicationComplexAbilityFail('Le dernier jet de chaîne n’est pas disponible.', 'complex_ability_condition_data_missing');
        return $last['success'];
    }
    if ($step['fact'] === 'last-attack-status') {
        $attacks = is_array($reference['attacks'] ?? null) ? $reference['attacks'] : [];
        $last = $attacks[count($attacks) - 1] ?? null;
        if (!is_array($last)) applicationComplexAbilityFail('La dernière attaque n’est pas disponible.', 'complex_ability_condition_data_missing');
        return $last['status'] ?? '';
    }
    applicationComplexAbilityFail('Cette donnée d’étape n’est pas disponible.', 'complex_ability_condition_data_missing');
}

function applicationComplexAbilityCompareCondition(mixed $actual, array $step): bool
{
    if (is_bool($actual)) {
        $expectedText = applicationComplexAbilityComparable($step['value']);
        $expected = $step['fact'] === 'condition' && $expectedText === ''
            ? true
            : in_array($expectedText, ['true', 'vrai', 'oui', '1', 'reussite'], true);
        return $step['operator'] === 'ne' ? $actual !== $expected : $actual === $expected;
    }
    if ($step['fact'] === 'choice' && is_array($actual)) {
        $expected = applicationComplexAbilityComparable($step['value']);
        $values = [applicationComplexAbilityComparable($actual['id'] ?? ''), applicationComplexAbilityComparable($actual['label'] ?? '')];
        if (in_array($step['operator'], ['contains', 'not-contains'], true)) {
            $contains = false;
            foreach ($values as $value) if (str_contains($value, $expected)) { $contains = true; break; }
            return $step['operator'] === 'not-contains' ? !$contains : $contains;
        }
        $equal = in_array($expected, $values, true);
        return $step['operator'] === 'ne' ? !$equal : $equal;
    }
    if ($step['fact'] === 'health-state') {
        $equal = applicationComplexAbilityHealthStateValue($actual) === applicationComplexAbilityHealthStateValue($step['value']);
        return $step['operator'] === 'ne' ? !$equal : $equal;
    }
    if (is_numeric($actual) && is_numeric($step['value']) && !in_array($step['operator'], ['contains', 'not-contains'], true)) {
        $left = (float) $actual; $right = (float) $step['value'];
        return match ($step['operator']) {
            'lt' => $left < $right, 'lte' => $left <= $right, 'gt' => $left > $right, 'gte' => $left >= $right,
            'between' => is_numeric($step['secondValue']) && $left >= min($right, (float) $step['secondValue']) && $left <= max($right, (float) $step['secondValue']),
            'ne' => $left !== $right, default => $left === $right,
        };
    }
    $left = applicationComplexAbilityComparable($actual); $right = applicationComplexAbilityComparable($step['value']);
    if ($step['operator'] === 'contains') return str_contains($left, $right);
    if ($step['operator'] === 'not-contains') return !str_contains($left, $right);
    return $step['operator'] === 'ne' ? $left !== $right : $left === $right;
}

function evaluateApplicationComplexAbilityCondition(array $execution, array $step, array $tokens, bool $isGm = false): array
{
    if (isset($step['expression'])) {
        $outcome = applicationComplexAbilityEvaluateExpression($execution, $step['expression'], $tokens, $isGm);
        return ['outcome' => $outcome, 'matchedCount' => $outcome ? 1 : 0, 'subjectCount' => 1];
    }
    if (in_array($step['fact'], ['target-count', 'roll-successes', 'last-roll-success', 'choice', 'counter',
        'attack-count', 'gate-successes', 'gate-failures', 'last-gate-success', 'last-attack-status'], true)) {
        $outcome = applicationComplexAbilityCompareCondition(applicationComplexAbilityWorkflowConditionValue($execution, $step), $step);
        return ['outcome' => $outcome, 'matchedCount' => $outcome ? 1 : 0, 'subjectCount' => 1];
    }
    $subjects = [];
    if ($step['subject'] === 'targets') {
        foreach (is_array($execution['stepStates'][$step['sourceStepId']]['allocations'] ?? null) ? $execution['stepStates'][$step['sourceStepId']]['allocations'] : [] as $allocation) {
            $token = applicationComplexAbilityTokenById($tokens, $allocation['tokenId'] ?? '');
            if (is_array($token)) $subjects[] = $token;
        }
    } else {
        $token = applicationComplexAbilityTokenById($tokens, $execution['sourceTokenId'] ?? '');
        if (is_array($token)) $subjects[] = $token;
    }
    if ($subjects === []) applicationComplexAbilityFail('Aucun sujet n’est disponible pour cette condition.', 'complex_ability_condition_subject_missing', 404);
    $privateFacts = ['hp-percent', 'hp', 'max-hp', 'missing-hp', 'mana-percent', 'mana', 'max-mana', 'missing-mana', 'fatigue', 'stat'];
    if (!$isGm && $step['subject'] === 'targets' && in_array($step['fact'], $privateFacts, true)) {
        foreach ($subjects as $token) {
            $detailsVisible = ($token['revealDetailsToPlayers'] ?? false) === true
                || ($token['tacticalDetailsShared'] ?? false) === true
                || ($token['playerControlled'] ?? false) === true
                || trim((string) ($token['controllerAccountId'] ?? $token['controllerPlayerId'] ?? '')) !== '';
            if (!$detailsVisible) {
                applicationComplexAbilityFail(
                    'Cette condition utilise une donnée tactique privée. Le MJ doit partager les détails de la cible ou évaluer l’étape.',
                    'complex_ability_condition_private',
                    403
                );
            }
        }
    }
    $matched = 0;
    foreach ($subjects as $token) if (applicationComplexAbilityCompareCondition(applicationComplexAbilityTokenConditionValue($token, $step), $step)) $matched += 1;
    $outcome = $step['aggregation'] === 'all' ? $matched === count($subjects)
        : ($step['aggregation'] === 'none' ? $matched === 0 : $matched > 0);
    return ['outcome' => $outcome, 'matchedCount' => $matched, 'subjectCount' => count($subjects)];
}

function applicationComplexAbilityEvaluateExpression(array $execution, array $node, array $tokens, bool $isGm): bool
{
    if (($node['type'] ?? '') === 'all') {
        foreach ($node['conditions'] as $child) if (!applicationComplexAbilityEvaluateExpression($execution, $child, $tokens, $isGm)) return false;
        return true;
    }
    if (($node['type'] ?? '') === 'any') {
        foreach ($node['conditions'] as $child) if (applicationComplexAbilityEvaluateExpression($execution, $child, $tokens, $isGm)) return true;
        return false;
    }
    if (($node['type'] ?? '') === 'not') return !applicationComplexAbilityEvaluateExpression($execution, $node['condition'], $tokens, $isGm);
    if (($node['type'] ?? '') !== 'fact') applicationComplexAbilityFail('Expression de condition invalide.', 'complex_ability_condition_invalid', 400);
    return evaluateApplicationComplexAbilityCondition($execution, [...$node, 'type' => 'condition'], $tokens, $isGm)['outcome'];
}

function applicationComplexAbilityFinishCondition(array &$execution, array $step, array &$state, string $branch, int $now): void
{
    $state['status'] = 'completed'; $state['completedAt'] = $now; $state['branch'] = $branch;
    if ($branch === 'finish') {
        $execution['currentStepIndex'] = count($execution['workflow']['steps']);
        $execution['status'] = 'completed'; $execution['completedAt'] = $now;
        return;
    }
    $targetIndex = $branch === 'next' ? $execution['currentStepIndex'] + 1
        : array_search($branch, array_column($execution['workflow']['steps'], 'id'), true);
    if ($targetIndex === count($execution['workflow']['steps'])) {
        $execution['currentStepIndex'] = $targetIndex; $execution['status'] = 'completed'; $execution['completedAt'] = $now;
        return;
    }
    if (!is_int($targetIndex) || $targetIndex <= $execution['currentStepIndex'] || $targetIndex >= count($execution['workflow']['steps'])) {
        applicationComplexAbilityFail('La branche de cette condition n’est plus valide.', 'complex_ability_condition_branch_invalid');
    }
    $execution['currentStepIndex'] = $targetIndex;
    $next = $execution['workflow']['steps'][$targetIndex];
    if (!isset($execution['stepStates'][$next['id']])) $execution['stepStates'][$next['id']] = applicationComplexAbilityInitialStepState($next);
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

function applicationComplexAbilityResetCountersOnEnd(array &$execution, int $now): void
{
    foreach ($execution['workflow']['steps'] as $step) {
        if ($step['type'] !== 'counter' || !isset($execution['stepStates'][$step['id']])) continue;
        $state =& $execution['stepStates'][$step['id']];
        $placed = is_array($state['deployments'] ?? null) ? count($state['deployments']) : 0;
        $state['deployments'] = [];
        $previous = applicationComplexAbilityInteger($state['value'] ?? null, 0, $step['maximum'], $step['initial']);
        $state['value'] = $step['resetOnEnd'] ? 0 : max(0, $previous - $placed);
        if ($previous !== $state['value']) {
            $state['events'][] = ['at' => $now, 'type' => 'reset', 'value' => $state['value'], 'amount' => $state['value'] - $previous, 'actorId' => 'system'];
            $state['events'] = array_slice($state['events'], -XAR_COMPLEX_ABILITY_MAXIMUM_EVENTS);
            applicationComplexAbilityAppendEvent($execution, ['type' => 'counter', 'actorId' => 'system', 'actorName' => 'Combat',
                'stepId' => $step['id'], 'label' => $step['counterLabel'] . ' : ' . $previous . ' → ' . $state['value'],
                'detail' => 'Marqueurs dissipés à la fin de l’effet'], $now);
        }
        unset($state);
    }
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
    $map = is_array($context['map'] ?? null) ? $context['map'] : [];
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
        applicationComplexAbilityResetCountersOnEnd($execution, $now);
        applicationComplexAbilityAppendEvent($execution, ['type' => 'cancelled', 'actorId' => $actorId, 'actorName' => $actorName,
            'stepId' => $step['id'], 'label' => 'Compétence arrêtée', 'detail' => 'Les étapes déjà validées et les coûts restent acquis.'], $now);
    } else {
        $awarenessParticipant = false;
        if ($step['type'] === 'allocated-attacks' && $action === 'awareness-roll' && ($state['awaitingAwareness'] ?? false)) {
            $token = applicationComplexAbilityTokenById($tokens, $state['pendingTargetTokenId'] ?? '');
            $controller = (string) ($token['controllerAccountId'] ?? $token['controllerPlayerId'] ?? '');
            $awarenessParticipant = $actorId !== '' && $controller === $actorId;
        }
        $defenseParticipant = false;
        if ($step['type'] === 'defense-series') {
            applicationComplexAbilityInitializeDefenses($execution, $step, $state, $tokens);
            $targetIndex = applicationComplexAbilityCurrentDefenseIndex($state);
            $target = $targetIndex === null ? null : $state['targets'][$targetIndex];
            $token = is_array($target) ? applicationComplexAbilityTokenById($tokens, $target['tokenId'] ?? '') : null;
            $controller = (string) ($token['controllerAccountId'] ?? $token['controllerPlayerId'] ?? '');
            $defenseParticipant = is_array($target) && $actorId !== '' && $controller === $actorId;
        }
        $plannedAwareness = $owner && $action === 'awareness-roll'
            && is_array($execution['stepStates'][$step['sourceStepId'] ?? '']['attackPlan'] ?? null);
        if (!$owner && !$isGm && !($defenseParticipant && $action === 'defense-roll') && !$awarenessParticipant) {
            applicationComplexAbilityFail('Vous ne pouvez pas faire progresser cette compétence.', 'complex_ability_forbidden', 403);
        }
        if ($action === 'awareness-roll' && !$isGm && !$awarenessParticipant && !$plannedAwareness) {
            applicationComplexAbilityFail('Seule la cible courante ou le MJ peut lancer la vigilance.', 'complex_ability_forbidden', 403);
        }
        if ($action === 'defense-roll' && !$isGm && !$defenseParticipant) {
            applicationComplexAbilityFail('Seul le défenseur courant ou le MJ peut lancer cette défense.', 'complex_ability_forbidden', 403);
        }
        if ($step['type'] === 'condition' && $action === 'evaluate-condition') {
            $result = evaluateApplicationComplexAbilityCondition($execution, $step, $tokens, $isGm);
            $state['outcome'] = $result['outcome'];
            $state['matchedCount'] = $result['matchedCount'];
            $state['subjectCount'] = $result['subjectCount'];
            $branch = $result['outcome'] ? $step['onTrueStepId'] : $step['onFalseStepId'];
            applicationComplexAbilityAppendEvent($execution, [
                'type' => 'condition', 'actorId' => $actorId, 'actorName' => $actorName, 'stepId' => $step['id'],
                'label' => $step['title'] . ' · ' . ($result['outcome'] ? 'vraie' : 'fausse'),
                'detail' => $result['matchedCount'] . '/' . $result['subjectCount'] . ' sujet(s) correspondent.',
            ], $now);
            applicationComplexAbilityFinishCondition($execution, $step, $state, $branch, $now);
        } elseif ($step['type'] === 'guard' && $action === 'apply-guard') {
            if (($context['combatActive'] ?? false) !== true) applicationComplexAbilityFail('Cette protection exige un combat actif.', 'complex_ability_guard_outside_combat');
            $selected = $step['scope'] === 'source' ? [$execution['sourceTokenId']]
                : array_column($execution['stepStates'][$step['sourceStepId']]['allocations'] ?? [], 'tokenId');
            foreach ($selected as $tokenId) if (!is_array(applicationComplexAbilityTokenById($tokens, (string) $tokenId))) {
                applicationComplexAbilityFail('Une cible de la protection n’est plus disponible.', 'complex_ability_target_missing', 404);
            }
            $state['protectedTokenIds'] = array_values(array_unique($selected));
            applicationComplexAbilityAppendEvent($execution, ['type' => 'guard', 'actorId' => $actorId, 'actorName' => $actorName,
                'stepId' => $step['id'], 'label' => $step['title'] . ' · ' . count($selected) . ' protégé(s)',
                'detail' => $step['percent'] . ' % sur la prochaine attaque de chacun'], $now);
            applicationComplexAbilityFinishStep($execution, $state, $now);
        } elseif ($step['type'] === 'instruction' && $action === 'acknowledge') {
            applicationComplexAbilityAppendEvent($execution, ['type' => 'step', 'actorId' => $actorId, 'actorName' => $actorName,
                'stepId' => $step['id'], 'label' => $step['title'] . ' validée'], $now);
            applicationComplexAbilityFinishStep($execution, $state, $now);
        } elseif ($step['type'] === 'targets' && $action === 'select-targets') {
            if (!is_array($command['allocations'] ?? null) || !array_is_list($command['allocations'])
                || count($command['allocations']) < $step['minTargets'] || count($command['allocations']) > $step['maxTargets']) {
                applicationComplexAbilityFail('Choisissez entre ' . $step['minTargets'] . ' et ' . $step['maxTargets'] . ' cibles distinctes.', 'complex_ability_target_count', 400);
            }
            $allocations = []; $ids = [];
            foreach ($command['allocations'] as $entry) {
                if (!is_array($entry) || !is_int($entry['count'] ?? null) || $entry['count'] < 1 || $entry['count'] > 100) {
                    applicationComplexAbilityFail('Chaque cible doit avoir un nombre entier de 1 à 100 actions.', 'complex_ability_allocation_invalid', 400);
                }
                $tokenId = applicationComplexAbilityIdentifier($entry['tokenId'] ?? '', '', 80);
                $token = applicationComplexAbilityTokenById($tokens, $tokenId);
                if ($tokenId === '' || !is_array($token)) applicationComplexAbilityFail('Une cible n’est plus disponible.', 'complex_ability_target_missing', 404);
                if (!applicationComplexAbilityTargetInRange(
                    applicationComplexAbilityTokenById($tokens, $execution['sourceTokenId']), $token, $map, $step['rangeCells']
                )) applicationComplexAbilityFail('Cette cible est hors de portée (' . $step['rangeCells'] . ' cases).', 'complex_ability_target_out_of_range');
                $allocations[] = ['tokenId' => $tokenId, 'targetName' => applicationComplexAbilityText($token['name'] ?? '', 120, 'Cible'),
                    'count' => $entry['count']];
                $ids[$tokenId] = true;
            }
            if (count($ids) !== count($allocations) || count($allocations) < $step['minTargets'] || count($allocations) > $step['maxTargets']) {
                applicationComplexAbilityFail('Choisissez entre ' . $step['minTargets'] . ' et ' . $step['maxTargets'] . ' cibles distinctes.', 'complex_ability_target_count', 400);
            }
            $total = array_sum(array_column($allocations, 'count'));
            if ($step['allocationTotal'] > 0 && $total !== $step['allocationTotal']) {
                applicationComplexAbilityFail('Répartissez exactement ' . $step['allocationTotal'] . ' actions.', 'complex_ability_allocation_total', 400);
            }
            $plan = $command['attackPlan'] ?? null;
            if ($plan !== null) {
                $nextStep = $execution['workflow']['steps'][$execution['currentStepIndex'] + 1] ?? null;
                if (($nextStep['type'] ?? '') !== 'allocated-attacks' || ($nextStep['sourceStepId'] ?? '') !== $step['id']
                    || !is_array($plan) || !array_is_list($plan) || count($plan) !== $total) {
                    applicationComplexAbilityFail('Le plan de frappes ne correspond pas à cette répartition.', 'complex_ability_attack_plan_invalid');
                }
                $source = applicationComplexAbilityTokenById($tokens, $execution['sourceTokenId']);
                $weapons = is_array($source['weaponAttacks'] ?? null) ? array_column($source['weaponAttacks'], 'id') : [];
                $stats = is_array($source['stats'] ?? null) ? array_column($source['stats'], 'id') : [];
                $counts = array_fill_keys(array_column($allocations, 'tokenId'), 0);
                foreach ($plan as $strike) {
                    if (!is_array($strike) || !isset($counts[$strike['tokenId'] ?? ''])
                        || ($nextStep['damageMode'] === 'configured' ? ($strike['attackId'] ?? '') !== $execution['abilityId'] || ($strike['statId'] ?? '') !== $nextStep['statId'] : !in_array($strike['attackId'] ?? '', $weapons, true))
                        || !in_array($strike['statId'] ?? '', $stats, true)
                        || !is_bool($strike['opposed'] ?? null)) {
                        applicationComplexAbilityFail('Une frappe prévue a une cible, une arme ou une statistique invalide.', 'complex_ability_attack_plan_invalid');
                    }
                    $counts[$strike['tokenId']]++;
                }
                foreach ($allocations as $allocation) if ($counts[$allocation['tokenId']] !== $allocation['count']) {
                    applicationComplexAbilityFail('Le plan ne respecte pas le nombre de frappes par cible.', 'complex_ability_attack_plan_invalid');
                }
                $state['attackPlan'] = array_map(static fn(array $strike): array => [...$strike, ...($nextStep['damageMode'] === 'configured' ? ['configured' => true] : [])], $plan);
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
            if ($execution['status'] === 'active' && ($nextStep['type'] ?? '') === 'allocated-attacks') {
                if ($nextStep['damageMode'] === 'configured' && (!($execution['castGate']['success'] ?? false) || ($execution['castGate']['statId'] ?? '') !== $nextStep['statId']))
                    applicationComplexAbilityFail('Cette série de dégâts doit réutiliser le jet de lancement configuré.', 'complex_ability_cast_gate_missing');
                $nextState =& $execution['stepStates'][$nextStep['id']];
                applicationComplexAbilityInitializeAllocatedTargets($execution, $nextStep, $nextState, $tokens);
                if ($nextStep['awarenessMode'] === 'once') {
                    if (!is_callable($roll)) applicationComplexAbilityFail('Le service de dés est indisponible.', 'complex_ability_roll_unavailable', 503);
                    foreach ($nextState['targets'] as &$target) {
                        $token = applicationComplexAbilityTokenById($tokens, $target['tokenId']);
                        $threshold = applicationComplexAbilityTokenThreshold($token, ['thresholdMode' => 'stat', 'targetStatId' => $nextStep['awarenessStatId']]);
                        $rolled = $roll('1d100');
                        $raw = is_array($rolled) && is_numeric($rolled['rawD100'] ?? $rolled['total'] ?? null) ? (int) ($rolled['rawD100'] ?? $rolled['total']) : 0;
                        if ($raw < 1 || $raw > 100) applicationComplexAbilityFail('Le résultat du d100 est invalide.', 'complex_ability_roll_invalid', 500);
                        $success = in_array($raw, [1, 11, 22, 33, 44, 55], true)
                            || (!in_array($raw, [66, 77, 88, 99, 100], true) && $raw <= $threshold);
                        $target['awarenessRolls'][] = ['total' => $raw, 'success' => $success];
                        $target['awarenessRolled'] = true;
                        $target['aware'] = $success;
                        applicationComplexAbilityAppendEvent($execution, ['type' => 'awareness', 'actorId' => $actorId,
                            'actorName' => $actorName, 'stepId' => $nextStep['id'],
                            'label' => $target['targetName'] . ' · vigilance ' . ($success ? 'réussie' : 'ratée'),
                            'detail' => (string) $raw], $now);
                    }
                    unset($target);
                }
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
        } elseif ($step['type'] === 'attack-chain' && $action === 'prepare-chain') {
            if (($step['targetMode'] ?? 'each') !== 'once' || is_array($state['plan'] ?? null)
                || count($state['attacks'] ?? []) > 0 || (($state['awaitingAttack'] ?? false) !== true && count($state['rolls'] ?? []) > 0))
                applicationComplexAbilityFail('Cette série a déjà commencé ou ne conserve pas ses choix.', 'complex_ability_attack_pending');
            $source = applicationComplexAbilityTokenById($tokens, $execution['sourceTokenId']);
            $targetTokenId = applicationComplexAbilityIdentifier($command['targetTokenId'] ?? '', '', 80);
            $attackId = applicationComplexAbilityIdentifier($command['attackId'] ?? '', '', 120);
            $statId = applicationComplexAbilityIdentifier($command['statId'] ?? '', '', 120);
            $configured = $step['resolutionMode'] === 'batch' && $step['damageMode'] === 'configured';
            $target = applicationComplexAbilityTokenById($tokens, $targetTokenId);
            if (!is_array($source) || $targetTokenId === $execution['sourceTokenId']
                || !is_array(applicationComplexAbilityTokenById($tokens, $targetTokenId))
                || ($configured ? $attackId !== $execution['abilityId'] : !in_array($attackId, array_column(is_array($source['weaponAttacks'] ?? null) ? $source['weaponAttacks'] : [], 'id'), true))
                || ($step['resolutionMode'] === 'batch' && ($statId !== $step['statId'] || (float) ($target['maxHp'] ?? 0) <= 0
                    || (float) ($target['hp'] ?? 0) <= 0 || (!$isGm && ($target['hidden'] ?? false) === true)))
                || !in_array($statId, array_column(is_array($source['stats'] ?? null) ? $source['stats'] : [], 'id'), true)
                || !is_bool($command['opposed'] ?? null))
                applicationComplexAbilityFail('La cible, l’arme, la statistique ou l’opposition choisie n’est plus disponible.', 'complex_ability_attack_mismatch');
            $state['plan'] = compact('targetTokenId', 'attackId', 'statId') + ['opposed' => $command['opposed']];
            if ($configured) $state['plan'] += ['attackKind' => 'ability', 'configured' => true];
            if ($step['resolutionMode'] === 'batch') {
                if ((float) ($source['hp'] ?? 0) <= 0) applicationComplexAbilityFail('Le lanceur n’est plus en état d’attaquer.', 'complex_ability_source_defeated');
                if ($command['opposed'] !== $step['allowOpposition']) applicationComplexAbilityFail('Respectez le droit d’opposition configuré pour la série.', 'complex_ability_attack_mismatch');
                if (!is_callable($roll)) applicationComplexAbilityFail('Le service de dés est indisponible.', 'complex_ability_roll_unavailable', 503);
                applicationComplexAbilityPrepareChainBatch($execution, $step, $state, $source, $actorId, $actorName, $roll,
                    (string) ($command['rollMode'] ?? 'normal'), $now);
            }
        } elseif ($step['type'] === 'attack-chain' && $action === 'gate-roll') {
            if (($step['targetMode'] ?? 'each') === 'once' && !is_array($state['plan'] ?? null))
                applicationComplexAbilityFail('Préparez la série avant les jets.', 'complex_ability_attack_pending');
            if (($state['awaitingAttack'] ?? false) === true) applicationComplexAbilityFail('Résolvez l’attaque autorisée avant le jet suivant.', 'complex_ability_attack_pending');
            $source = applicationComplexAbilityTokenById($tokens, $execution['sourceTokenId']);
            if (!is_array($source) || (float) ($source['hp'] ?? 0) <= 0) applicationComplexAbilityFail('Le lanceur n’est plus en état d’attaquer.', 'complex_ability_source_defeated');
            $baseThreshold = applicationComplexAbilityTokenThreshold($source, ['thresholdMode' => 'stat', 'targetStatId' => $step['statId']]);
            $modifier = -min(100, count($state['attacks'] ?? []) * $step['penaltyPerSuccess']);
            $threshold = max(1, $baseThreshold + $modifier);
            if (!is_callable($roll)) applicationComplexAbilityFail('Le service de dés est indisponible.', 'complex_ability_roll_unavailable', 503);
            $rolled = $roll('1d100');
            $raw = is_array($rolled) && is_numeric($rolled['rawD100'] ?? $rolled['total'] ?? null) ? (int) ($rolled['rawD100'] ?? $rolled['total']) : 0;
            if ($raw < 1 || $raw > 100) applicationComplexAbilityFail('Le résultat du d100 est invalide.', 'complex_ability_roll_invalid', 500);
            $success = in_array($raw, [1, 11, 22, 33, 44, 55], true) || (!in_array($raw, [66, 77, 88, 99, 100], true) && $raw <= $threshold);
            $state['rolls'][] = ['formula' => '1d100', 'total' => $raw, 'threshold' => $threshold,
                'success' => $success, 'breakdown' => applicationComplexAbilityText($rolled['breakdown'] ?? '', 500, (string) $raw)];
            $state['awaitingAttack'] = $success;
            if ($success) $state['gateAt'] = $now;
            applicationComplexAbilityAppendEvent($execution, ['type' => 'roll', 'actorId' => $actorId, 'actorName' => $actorName,
                'stepId' => $step['id'], 'label' => $step['title'] . ' · jet ' . count($state['rolls']) . '/' . $step['count'] . ' · ' . ($success ? 'réussite' : 'échec'),
                'detail' => $raw . ' · seuil ' . $baseThreshold . ($modifier !== 0 ? ' ' . $modifier : '') . ' → ' . $threshold], $now);
            if (!$success) {
                applicationComplexAbilityFinishStep($execution, $state, $now);
                if ($step['stopOnFailure']) {
                    $execution['currentStepIndex'] = count($execution['workflow']['steps']);
                    $execution['status'] = 'completed'; $execution['completedAt'] = $now; $execution['endedByFailure'] = true;
                }
            }
        } elseif ($step['type'] === 'attack-chain' && $action === 'confirm-attack') {
            $attack = is_array($context['attack'] ?? null) ? $context['attack'] : [];
            if (($state['awaitingAttack'] ?? false) !== true || ($attack['attackKind'] ?? '') !== ($state['plan']['attackKind'] ?? 'weapon')
                || ($attack['complexExecutionId'] ?? '') !== $execution['id'] || ($attack['sceneId'] ?? '') !== $execution['sceneId']
                || ($attack['sourceTokenId'] ?? '') !== $execution['sourceTokenId'] || ($attack['accountId'] ?? '') !== $actorId
                || ($attack['requestId'] ?? '') !== ($command['attackRequestId'] ?? '')
                || ($state['attackRequestId'] ?? '') !== ($attack['requestId'] ?? '')
                || !is_numeric($attack['createdAt'] ?? null) || (int) $attack['createdAt'] < (int) ($state['gateAt'] ?? 0)
                || in_array($attack['requestId'] ?? '', array_column($state['attacks'] ?? [], 'requestId'), true)
                || (($step['targetMode'] ?? 'each') === 'once' && (!is_array($state['plan'] ?? null)
                    || ($attack['targetTokenId'] ?? '') !== $state['plan']['targetTokenId']
                    || ($attack['attackId'] ?? '') !== $state['plan']['attackId']
                    || ($attack['hit']['statId'] ?? '') !== $state['plan']['statId']
                    || (($attack['opposed'] ?? null) !== $state['plan']['opposed'] && !($step['resolutionMode'] === 'batch' && $state['plan']['opposed'] && ($attack['opposed'] ?? null) === false))))) {
                applicationComplexAbilityFail('Cette attaque de base ne correspond pas au jet autorisé.', 'complex_ability_attack_mismatch');
            }
            $state['attacks'][] = ['id' => applicationComplexAbilityIdentifier($attack['id'] ?? '', '', 120),
                'requestId' => $attack['requestId'], 'targetTokenId' => applicationComplexAbilityIdentifier($attack['targetTokenId'] ?? '', '', 80),
                'status' => applicationComplexAbilityText($attack['status'] ?? '', 40),
                ...(($attack['appliedDamage'] ?? 0) > 0 ? ['appliedDamage' => $attack['appliedDamage']] : [])];
            $state['awaitingAttack'] = $step['resolutionMode'] === 'batch' && count($state['attacks']) < ($state['queuedAttackCount'] ?? 0);
            if ($step['resolutionMode'] === 'batch' && $state['awaitingAttack']) {
                $target = applicationComplexAbilityTokenById($tokens, $state['plan']['targetTokenId']);
                if (is_array($target) && onlineHealthState($target['hp'] ?? 0, $target['maxHp'] ?? 0, trim((string) ($target['controllerAccountId'] ?? $target['controllerPlayerId'] ?? '')) !== '', ($target['healthOverride'] ?? '') === 'dead')['code'] === 'dead') {
                    $state['skippedAttackCount'] = $state['queuedAttackCount'] - count($state['attacks']);
                    $state['awaitingAttack'] = false;
                }
            }
            $state['attackRequestId'] = '';
            applicationComplexAbilityAppendEvent($execution, ['type' => 'attack', 'actorId' => $actorId, 'actorName' => $actorName,
                'stepId' => $step['id'], 'label' => $step['title'] . ' · attaque ' . count($state['attacks']) . '/' . $step['count'],
                'detail' => applicationComplexAbilityText($attack['targetName'] ?? '', 120, 'Cible') . ' · ' . applicationComplexAbilityText($attack['status'] ?? '', 40)], $now);
            if ($step['resolutionMode'] === 'batch') {
                if (!$state['awaitingAttack']) applicationComplexAbilityFinishChainBatch($execution, $step, $state, $now);
            } elseif (count($state['attacks']) >= $step['count']) applicationComplexAbilityFinishStep($execution, $state, $now);
        } elseif ($step['type'] === 'allocated-attacks' && $action === 'prepare-target') {
            if (($state['pendingTargetTokenId'] ?? '') !== '') applicationComplexAbilityFail('Terminez l’attaque courante avant de choisir une nouvelle cible.', 'complex_ability_attack_pending');
            applicationComplexAbilityInitializeAllocatedTargets($execution, $step, $state, $tokens);
            $targetId = applicationComplexAbilityIdentifier($command['targetTokenId'] ?? '', '', 80);
            $targetIndex = null;
            foreach ($state['targets'] as $index => $candidate) {
                if ($candidate['tokenId'] === $targetId && count($candidate['attacks']) < $candidate['attackCount'] && $targetId !== $execution['sourceTokenId']) { $targetIndex = $index; break; }
            }
            if ($targetIndex === null || !is_array(applicationComplexAbilityTokenById($tokens, $targetId))) applicationComplexAbilityFail('Cette cible n’a plus d’attaque attribuée ou n’est plus disponible.', 'complex_ability_target_missing', 404);
            $target = $state['targets'][$targetIndex];
            $state['pendingTargetTokenId'] = $targetId;
            $needsAwareness = ($step['awarenessMode'] === 'required' && !$target['aware'])
                || ($step['awarenessMode'] === 'once' && !($target['awarenessRolled'] ?? false));
            $state['awaitingAwareness'] = $needsAwareness;
            $state['awaitingAttack'] = !$needsAwareness;
            $state['attackRequestId'] = '';
            $state['gateAt'] = $now;
            applicationComplexAbilityAppendEvent($execution, ['type' => 'target', 'actorId' => $actorId, 'actorName' => $actorName,
                'stepId' => $step['id'], 'label' => $step['title'] . ' · ' . $target['targetName'],
                'detail' => $needsAwareness ? 'Jet de vigilance attendu' : ($target['aware'] ? 'Opposition autorisée' : 'Sans opposition')], $now);
        } elseif ($step['type'] === 'allocated-attacks' && $action === 'awareness-roll') {
            if (($state['awaitingAwareness'] ?? false) !== true || ($state['pendingTargetTokenId'] ?? '') === '') applicationComplexAbilityFail('Aucun jet de vigilance n’est attendu.', 'complex_ability_awareness_not_ready');
            $targetIndex = array_search($state['pendingTargetTokenId'], array_column($state['targets'], 'tokenId'), true);
            $token = applicationComplexAbilityTokenById($tokens, $state['pendingTargetTokenId']);
            if ($targetIndex === false || !is_array($token)) applicationComplexAbilityFail('La cible n’est plus disponible.', 'complex_ability_target_missing', 404);
            $threshold = applicationComplexAbilityTokenThreshold($token, ['thresholdMode' => 'stat', 'targetStatId' => $step['awarenessStatId']]);
            if (!is_callable($roll)) applicationComplexAbilityFail('Le service de dés est indisponible.', 'complex_ability_roll_unavailable', 503);
            $rolled = $roll('1d100');
            $raw = is_array($rolled) && is_numeric($rolled['rawD100'] ?? $rolled['total'] ?? null) ? (int) ($rolled['rawD100'] ?? $rolled['total']) : 0;
            if ($raw < 1 || $raw > 100) applicationComplexAbilityFail('Le résultat du d100 est invalide.', 'complex_ability_roll_invalid', 500);
            $success = in_array($raw, [1, 11, 22, 33, 44, 55], true) || (!in_array($raw, [66, 77, 88, 99, 100], true) && $raw <= $threshold);
            $target =& $state['targets'][$targetIndex];
            $target['awarenessRolls'][] = ['total' => $raw, 'success' => $success];
            $target['awarenessRolled'] = true;
            if ($success) $target['aware'] = true;
            $state['awaitingAwareness'] = false;
            $state['awaitingAttack'] = true;
            $state['gateAt'] = $now;
            applicationComplexAbilityAppendEvent($execution, ['type' => 'awareness', 'actorId' => $actorId, 'actorName' => $actorName,
                'stepId' => $step['id'], 'label' => $target['targetName'] . ' · vigilance ' . ($success ? 'réussie' : 'ratée'),
                'detail' => $raw . ' · ' . ($success ? 'opposition autorisée dès cette attaque' : 'pas d’opposition à cette attaque')], $now);
            unset($target);
        } elseif ($step['type'] === 'allocated-attacks' && $action === 'confirm-attack') {
            $attack = is_array($context['attack'] ?? null) ? $context['attack'] : [];
            $targetIndex = array_search($state['pendingTargetTokenId'] ?? '', array_column($state['targets'], 'tokenId'), true);
            $target = $targetIndex === false ? null : $state['targets'][$targetIndex];
            if (!is_array($target) || ($state['awaitingAttack'] ?? false) !== true || ($attack['attackKind'] ?? '') !== ($step['damageMode'] === 'configured' ? 'ability' : 'weapon')
                || ($attack['complexExecutionId'] ?? '') !== $execution['id'] || ($attack['sceneId'] ?? '') !== $execution['sceneId']
                || ($attack['sourceTokenId'] ?? '') !== $execution['sourceTokenId'] || ($attack['accountId'] ?? '') !== $actorId
                || ($attack['targetTokenId'] ?? '') !== $target['tokenId'] || (($attack['opposed'] ?? false) === true && $step['awarenessMode'] !== 'none' && !$target['aware'])
                || ($attack['damagePercent'] ?? null) !== $step['damagePercent']
                || ($attack['requestId'] ?? '') !== ($command['attackRequestId'] ?? '') || ($state['attackRequestId'] ?? '') !== ($attack['requestId'] ?? '')
                || !is_numeric($attack['createdAt'] ?? null) || (int) $attack['createdAt'] < (int) ($state['gateAt'] ?? 0)
                || in_array($attack['requestId'] ?? '', array_merge(...array_map(static fn(array $entry): array => array_column($entry['attacks'], 'requestId'), $state['targets'])), true)) {
                applicationComplexAbilityFail('Cette attaque ne correspond pas à la répartition et à la vigilance de la cible.', 'complex_ability_attack_mismatch');
            }
            $state['targets'][$targetIndex]['attacks'][] = ['id' => applicationComplexAbilityIdentifier($attack['id'] ?? '', '', 120),
                'requestId' => $attack['requestId'], 'status' => applicationComplexAbilityText($attack['status'] ?? '', 40), 'opposed' => $attack['opposed']];
            $state['pendingTargetTokenId'] = '';
            $state['awaitingAttack'] = false;
            applicationComplexAbilityAppendEvent($execution, ['type' => 'attack', 'actorId' => $actorId, 'actorName' => $actorName,
                'stepId' => $step['id'], 'label' => $step['title'] . ' · ' . $target['targetName'] . ' · attaque ' . count($state['targets'][$targetIndex]['attacks']) . '/' . $target['attackCount'],
                'detail' => $attack['opposed'] ? 'Opposition ouverte' : 'Sans opposition'], $now);
            if (count(array_filter($state['targets'], static fn(array $entry): bool => count($entry['attacks']) < $entry['attackCount'])) === 0) applicationComplexAbilityFinishStep($execution, $state, $now);
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
            $rollMode = normalizeOnlineRollMode($command['rollMode'] ?? 'normal');
            $rolled = $roll('1d100', $rollMode, true);
            $total = is_array($rolled) && is_numeric($rolled['rawD100'] ?? $rolled['total'] ?? null) ? (int) ($rolled['rawD100'] ?? $rolled['total']) : null;
            if ($total === null || $total < 1 || $total > 100 || ($rollMode !== 'normal' && count($rolled['attempts'] ?? []) !== 2)) {
                applicationComplexAbilityFail('Le résultat du d100 est invalide.', 'complex_ability_roll_invalid', 500);
            }
            $result = ['formula' => '1d100', 'total' => $total, 'threshold' => $threshold, 'success' => $total <= $threshold,
                'breakdown' => applicationComplexAbilityText($rolled['breakdown'] ?? '', 500, (string) $total),
                'rollMode' => $rollMode, 'selectedIndex' => $rolled['selectedIndex'] ?? 0,
                'attempts' => $rolled['attempts'] ?? [['total' => $total, 'rawD100' => $total,
                    'breakdown' => applicationComplexAbilityText($rolled['breakdown'] ?? '', 500, (string) $total)]]];
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
            if ($step['gainTrigger'] !== 'manual') applicationComplexAbilityFail('Le gain de ce compteur suit son déclencheur configuré ; il ne peut pas être ajouté manuellement.', 'complex_ability_gain_automatic');
            $previous = applicationComplexAbilityInteger($state['value'] ?? null, 0, $step['maximum'], $step['initial']);
            $next = min($step['maximum'], $previous + $step['gainAmount']);
            if ($next === $previous) applicationComplexAbilityFail($step['counterLabel'] . ' est déjà au maximum (' . $step['maximum'] . ').', 'complex_ability_counter_maximum');
            $state['value'] = $next; $state['events'][] = ['at' => $now, 'type' => 'gain', 'value' => $next, 'amount' => $next - $previous, 'actorId' => $actorId];
            $state['events'] = array_slice($state['events'], -XAR_COMPLEX_ABILITY_MAXIMUM_EVENTS);
            applicationComplexAbilityAppendEvent($execution, ['type' => 'counter', 'actorId' => $actorId, 'actorName' => $actorName,
                'stepId' => $step['id'], 'label' => $step['counterLabel'] . ' : ' . $previous . ' → ' . $next, 'detail' => $step['gainLabel']], $now);
        } elseif ($step['type'] === 'counter' && $action === 'counter-spend') {
            if ($step['combatPersistent'] && ($context['combatActive'] ?? false) !== true) applicationComplexAbilityFail('Ce sort prend fin avec le combat.', 'complex_ability_combat_ended');
            $turnKey = applicationComplexAbilityIdentifier($context['turnKey'] ?? '', '', 180);
            if ($step['spendOnSourceTurn'] && ($context['currentTokenId'] ?? '') !== $execution['sourceTokenId'])
                applicationComplexAbilityFail('Les charges de cette compétence s’utilisent pendant le tour du lanceur.', 'complex_ability_source_turn_required');
            if ($step['spendOncePerTurn'] && ($turnKey === '' || ($state['lastSpendTurnKey'] ?? '') === $turnKey)) applicationComplexAbilityFail('Une seule utilisation de ce compteur est autorisée durant ce tour.', 'complex_ability_turn_spend_limit');
            $option = null; $optionId = applicationComplexAbilityIdentifier($command['optionId'] ?? '');
            foreach ($step['spendOptions'] as $candidate) if ($candidate['id'] === $optionId) { $option = $candidate; break; }
            if (!is_array($option)) applicationComplexAbilityFail('Cette utilisation de charge n’existe plus.', 'complex_ability_spend_missing', 400);
            $previous = applicationComplexAbilityInteger($state['value'] ?? null, 0, $step['maximum'], $step['initial']);
            $deployments = is_array($state['deployments'] ?? null) ? array_slice($state['deployments'], 0, $step['maximum']) : [];
            $orbiting = max(0, $previous - count($deployments));
            if ($orbiting < $option['cost']) applicationComplexAbilityFail($step['counterLabel'] . ' disponible insuffisant : ' . $orbiting . '/' . $option['cost'] . '.', 'complex_ability_counter_insufficient');
            $effect = $option['effect'] ?? ['kind' => 'none'];
            $targetId = applicationComplexAbilityIdentifier($command['targetTokenId'] ?? '');
            $target = in_array($effect['kind'], ['damage', 'guard'], true) ? applicationComplexAbilityTokenById($tokens, $targetId) : null;
            if (in_array($effect['kind'], ['damage', 'guard'], true) && (!is_array($target) || ($target['layerId'] ?? 'ground') !== $execution['layerId'])) {
                applicationComplexAbilityFail('Choisissez une cible visible sur le niveau courant pour cet effet.', 'complex_ability_effect_target_missing', 400);
            }
            $source = $effect['kind'] === 'marker' ? applicationComplexAbilityTokenById($tokens, $execution['sourceTokenId']) : null;
            if ($effect['kind'] === 'marker' && (!is_array($source) || ($source['layerId'] ?? 'ground') !== $execution['layerId']
                || !is_numeric($source['x'] ?? null) || !is_numeric($source['y'] ?? null))) {
                applicationComplexAbilityFail('Le lanceur doit être sur le niveau courant pour poser le marqueur.', 'complex_ability_marker_source_missing');
            }
            $rolled = null;
            if ($effect['kind'] === 'damage') {
                if (!is_callable($roll)) applicationComplexAbilityFail('Le jet de dégâts autoritatif est indisponible.', 'complex_ability_effect_roll_missing');
                $rolled = $roll($effect['formula']);
            }
            $markerId = $effect['kind'] === 'marker' ? 'marker-' . ($execution['revision'] + 1) : '';
            $state['value'] = $effect['kind'] === 'marker' ? $previous : $previous - $option['cost'];
            if ($markerId !== '') $state['deployments'] = [...$deployments, ['id' => $markerId, 'x' => (float) $source['x'],
                'y' => (float) $source['y'], 'label' => $option['label'], 'movable' => $effect['movable'] === true]];
            if ($step['spendOncePerTurn']) $state['lastSpendTurnKey'] = $turnKey;
            $state['lastUse'] = ['optionId' => $optionId, 'effect' => $effect, 'targetTokenId' => $targetId,
                'targetName' => is_array($target) ? applicationComplexAbilityText($target['name'] ?? '', 120, 'Cible') : '',
                'markerId' => $markerId, 'rolled' => $rolled, 'revision' => $execution['revision'] + 1];
            $state['events'][] = ['at' => $now, 'type' => $markerId !== '' ? 'deploy' : 'spend', 'value' => $state['value'],
                'amount' => $markerId !== '' ? 0 : $option['cost'], 'occupied' => $markerId !== '' ? 1 : 0,
                'optionId' => $option['id'], 'actorId' => $actorId];
            $state['events'] = array_slice($state['events'], -XAR_COMPLEX_ABILITY_MAXIMUM_EVENTS);
            applicationComplexAbilityAppendEvent($execution, ['type' => 'counter', 'actorId' => $actorId, 'actorName' => $actorName,
                'stepId' => $step['id'], 'label' => $markerId !== ''
                    ? $option['label'] . ' posé · ' . ($orbiting - 1) . ' disponible(s), ' . $state['value'] . '/' . $step['maximum'] . ' en tout'
                    : $option['label'] . ' · ' . $step['counterLabel'] . ' : ' . $previous . ' → ' . $state['value'],
                'detail' => trim($option['description'] . (is_array($target) ? ' · ' . $state['lastUse']['targetName'] : ''))], $now);
        } elseif ($step['type'] === 'counter' && $action === 'marker-remove') {
            $markerId = applicationComplexAbilityIdentifier($command['markerId'] ?? '', '', 80);
            $deployments = is_array($state['deployments'] ?? null) ? $state['deployments'] : [];
            $marker = null;
            foreach ($deployments as $candidate) if (($candidate['id'] ?? '') === $markerId) { $marker = $candidate; break; }
            if (!is_array($marker)) applicationComplexAbilityFail('Ce marqueur n’existe plus.', 'complex_ability_marker_missing');
            $state['deployments'] = array_values(array_filter($deployments, static fn (array $entry): bool => ($entry['id'] ?? '') !== $markerId));
            $state['value'] = max(0, applicationComplexAbilityInteger($state['value'] ?? null, 0, $step['maximum'], $step['initial']) - 1);
            applicationComplexAbilityAppendEvent($execution, ['type' => 'counter', 'actorId' => $actorId, 'actorName' => $actorName,
                'stepId' => $step['id'], 'label' => applicationComplexAbilityText($marker['label'] ?? '', 120, 'Marqueur')
                    . ' supprimé · ' . $state['value'] . '/' . $step['maximum'], 'detail' => 'Emplacement libéré'], $now);
        } elseif ($step['type'] === 'counter' && $action === 'marker-move') {
            $markerId = applicationComplexAbilityIdentifier($command['markerId'] ?? '', '', 80);
            $index = -1;
            foreach (is_array($state['deployments'] ?? null) ? $state['deployments'] : [] as $candidateIndex => $candidate) {
                if (($candidate['id'] ?? '') === $markerId) { $index = $candidateIndex; break; }
            }
            if ($index < 0) applicationComplexAbilityFail('Ce marqueur n’existe plus.', 'complex_ability_marker_missing');
            $marker =& $state['deployments'][$index];
            if (($marker['movable'] ?? false) !== true) applicationComplexAbilityFail('Ce marqueur est fixe.', 'complex_ability_marker_fixed');
            $x = $command['x'] ?? null; $y = $command['y'] ?? null;
            if (!is_int($x) && !is_float($x) || !is_int($y) && !is_float($y)
                || !is_finite((float) $x) || !is_finite((float) $y)
                || (float) $x < 0 || (float) $x > 100 || (float) $y < 0 || (float) $y > 100)
                applicationComplexAbilityFail('La destination du marqueur est invalide.', 'complex_ability_marker_position_invalid', 400);
            $marker['x'] = (float) $x; $marker['y'] = (float) $y;
            applicationComplexAbilityAppendEvent($execution, ['type' => 'marker', 'actorId' => $actorId, 'actorName' => $actorName,
                'stepId' => $step['id'], 'label' => applicationComplexAbilityText($marker['label'] ?? '', 120, 'Marqueur') . ' déplacé'], $now);
            unset($marker);
        } elseif ($step['type'] === 'counter' && $action === 'complete-step') {
            if ($step['combatPersistent']) applicationComplexAbilityFail('Ce sort persiste jusqu’à son interruption ou à la fin du combat.', 'complex_ability_persistent');
            applicationComplexAbilityAppendEvent($execution, ['type' => 'step', 'actorId' => $actorId, 'actorName' => $actorName,
                'stepId' => $step['id'], 'label' => $step['title'] . ' terminée', 'detail' => $step['counterLabel'] . ' restant : ' . $state['value']], $now);
            applicationComplexAbilityFinishStep($execution, $state, $now);
        } else {
            applicationComplexAbilityFail('Cette action ne correspond pas à l’étape courante.', 'complex_ability_action_invalid', 400);
        }
    }
    unset($state);
    if ($execution['status'] === 'completed') applicationComplexAbilityResetCountersOnEnd($execution, $now);
    $execution['revision'] += 1; $execution['updatedAt'] = $now;
    return $execution;
}
