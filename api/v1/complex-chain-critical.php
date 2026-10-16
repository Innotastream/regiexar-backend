<?php
declare(strict_types=1);

function applicationChainCritical(array $step, array $state): ?array
{
    if (($step['type'] ?? '') !== 'attack-chain' || ($step['resolutionMode'] ?? '') !== 'batch'
        || ($step['criticalSuccessCompletesChain'] ?? false) !== true) return null;
    foreach ($state['rolls'] ?? [] as $index => $gate) {
        if (($gate['success'] ?? false) !== true || ($gate['outcomeDetails']['code'] ?? '') !== 'critical-success') continue;
        $attack = $state['attacks'][$index] ?? null;
        return ['index' => $index, 'gate' => $gate, 'rejected' => in_array($attack['status'] ?? '', ['rejected', 'cancelled'], true),
            'waiting' => ($gate['outcomeDetails']['requiresGmValidation'] ?? false) === true && ($attack === null || ($attack['status'] ?? '') === 'pending')];
    }
    return null;
}

function applicationChainAttackGate(array $step, array $state): ?array
{
    $index = count($state['attacks'] ?? []);
    $actual = array_values(array_filter($state['rolls'] ?? [], static fn(array $gate): bool => ($gate['success'] ?? false) === true))[$index] ?? null;
    if ($actual !== null) return $actual;
    $critical = applicationChainCritical($step, $state);
    if ($critical === null || $critical['waiting'] || $critical['rejected'] || $index >= $step['count']) return null;
    return ['success' => true, 'statId' => $step['statId'], 'total' => null, 'threshold' => null, 'formula' => '', 'breakdown' => 'Frappe autorisée par le critique', 'outcome' => 'success', 'outcomeDetails' => ['code' => 'success', 'label' => 'RÉUSSITE · ENCHAÎNEMENT CRITIQUE',
        'success' => true, 'automatic' => true, 'effect' => false, 'requiresGmValidation' => false]];
}

function applicationSettleRejectedChainCritical(array &$execution, int $now): void
{
    $step = $execution['workflow']['steps'][$execution['currentStepIndex']] ?? null;
    if (($execution['status'] ?? '') !== 'active' || $step === null) return;
    $state = &$execution['stepStates'][$step['id']];
    if (!is_array($state) || !(applicationChainCritical($step, $state)['rejected'] ?? false)) return;
    $state['criticalRejected'] = true;
    $state['skippedAttackCount'] = max(0, ($state['queuedAttackCount'] ?? 0) - count($state['attacks'] ?? []));
    $state['awaitingAttack'] = false; $state['attackRequestId'] = '';
    $state['status'] = 'completed'; $state['completedAt'] = $now;
    $execution['currentStepIndex'] = count($execution['workflow']['steps']);
    $execution['status'] = 'completed'; $execution['completedAt'] = $now; $execution['endedByFailure'] = true;
}
