<?php
declare(strict_types=1);

/**
 * Expiration releases resolved records only. Pending attacks and active
 * executions still need their original result and the entire related group.
 */
function onlineRetainedAttackReceipts(array $activity, int $now, ?string $resolvingAttackId = null): array
{
    $receipts = is_array($activity['attackReceipts'] ?? null) ? $activity['attackReceipts'] : [];
    if ($receipts === []) return [];
    $unexpired = static fn(mixed $receipt): bool => is_array($receipt) && (int) ($receipt['expiresAt'] ?? 0) > $now;
    $needsPruning = false;
    foreach ($receipts as $receipt) {
        if ($unexpired($receipt)) continue;
        $needsPruning = true;
        break;
    }
    if (!$needsPruning) return array_values($receipts);

    $activeExecutions = [];
    $protected = $resolvingAttackId === null ? [] : [$resolvingAttackId => true];
    foreach ($activity['abilityExecutions'] ?? [] as $execution) {
        if (($execution['status'] ?? null) !== 'active') continue;
        if (is_string($execution['id'] ?? null) && $execution['id'] !== '') $activeExecutions[$execution['id']] = true;
        if (is_string($execution['echoParentId'] ?? null) && $execution['echoParentId'] !== '') {
            $protected[$execution['echoParentId']] = true;
        }
    }
    $related = [];
    $link = static function (string $id, mixed $relative) use (&$related): void {
        if (!is_string($relative) || $relative === '' || $relative === $id) return;
        $related[$id][$relative] = true;
        $related[$relative][$id] = true;
    };
    $register = static function (mixed $attack, bool $live, array $children = []) use (&$protected, $activeExecutions, $link): void {
        $id = $attack['id'] ?? null;
        if (!is_array($attack) || !is_string($id) || $id === '') return;
        foreach (['areaGateParentId', 'weaponAttackParentId', 'echoParentId'] as $field) {
            $link($id, $attack[$field] ?? null);
        }
        foreach ($children as $child) $link($id, $child);
        $executionId = $attack['areaGate']['executionId'] ?? '';
        foreach (['complexExecutionId', 'counterExecutionId', 'originExecutionId', 'sourceExecutionId'] as $field) {
            if (is_string($attack[$field] ?? null) && isset($activeExecutions[$attack[$field]])) $live = true;
        }
        if ($live || (is_string($executionId) && isset($activeExecutions[$executionId]))) $protected[$id] = true;
    };
    foreach ($activity['pendingAttacks'] ?? [] as $pending) $register($pending, true);
    foreach ($receipts as $receipt) {
        if (!is_array($receipt)) continue;
        $register($receipt['attack'] ?? null, $unexpired($receipt),
            is_array($receipt['areaAttackIds'] ?? null) ? $receipt['areaAttackIds'] : []);
    }
    $toVisit = array_keys($protected);
    while ($toVisit !== []) {
        foreach ($related[array_pop($toVisit)] ?? [] as $relative => $_) {
            if (isset($protected[$relative])) continue;
            $protected[$relative] = true;
            $toVisit[] = $relative;
        }
    }
    return array_values(array_filter($receipts, static function (mixed $receipt) use ($unexpired, $protected): bool {
        if ($unexpired($receipt)) return true;
        $id = $receipt['attack']['id'] ?? null;
        return is_string($id) && isset($protected[$id]);
    }));
}

function onlinePruneExpiredAttackReceipts(array $records, array &$pending, array $activity, int $now, ?string $resolvingAttackId = null): array
{
    $retained = onlineRetainedAttackReceipts($activity, $now, $resolvingAttackId);
    if ($retained !== ($activity['attackReceipts'] ?? [])) {
        $activity['attackReceipts'] = $retained;
        queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
    }
    return $activity;
}
