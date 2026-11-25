<?php
declare(strict_types=1);
require_once __DIR__.'/ability-rule-presets.php';

function applicationCancelAttacksForDeletedTokens(PDO $connection, array $changes): array
{
    $removed = [];
    $removedScenes = [];
    $arrivals = [];
    $activityIndex = null;
    foreach ($changes as $index => $change) {
        $key = $change['key'];
        if ($key === 'activity') $activityIndex = $index;
        if ($change['operation'] === 'delete' && str_starts_with($key, 'scene:')) {
            $removedScenes[substr($key, 6)] = true;
        }
        if (str_starts_with($key, 'token:')) {
            [, $sceneId, $tokenId] = explode(':', $key, 3);
            if ($change['operation'] === 'delete') $removed[$sceneId][$tokenId] = true;
            elseif ($change['operation'] === 'upsert' && !isset($change['current'])) $arrivals[$tokenId] = true;
        }
    }
    if ($removed === [] && $removedScenes === []) return $changes;

    $records = applicationDomainRecords($connection, ['activity']);
    $stored = applicationDomainPayload($records, 'activity');
    $activity = $changes[$activityIndex]['payload'] ?? $stored;
    $reconciled = applicationCancelPendingAttacksForRemovedTokens(
        $activity, $removed, (int) floor(microtime(true) * 1000), $stored, $removedScenes, $arrivals
    );
    if ($reconciled === $activity) return $changes;

    $prepared = prepareApplicationDomainUpsert('activity', $reconciled, $records['activity'] ?? null);
    if ($prepared === null) {
        if ($activityIndex !== null) unset($changes[$activityIndex]);
        return array_values($changes);
    }
    if ($activityIndex === null) $changes[] = $prepared;
    else $changes[$activityIndex] = $prepared;
    return $changes;
}

function applicationCancelPendingAttacksForRemovedTokens(
    array $activity, array $removed, int $now, ?array $stored = null, array $removedScenes = [], array $arrivals = []
): array
{
    if ($removed === [] && $removedScenes === []) return $activity;
    $stored ??= $activity;
    foreach (['damageOverTime', 'pendingDotResolutions'] as $field) {
        if (!is_array($activity[$field] ?? null)) continue;
        $activity[$field] = array_values(array_filter($activity[$field], static function (mixed $effect) use ($removed, $removedScenes, $arrivals): bool {
            if (!is_array($effect)) return true;
            $sceneId = $effect['sceneId'] ?? '';
            $targetId = $effect['targetTokenId'] ?? '';
            return !is_string($sceneId) || (!isset($removedScenes[$sceneId])
                && (!is_string($targetId) || !isset($removed[$sceneId][$targetId]) || isset($arrivals[$targetId])));
        }));
    }
    $removedIds = [];
    foreach ($removed as $tokens) $removedIds += $tokens;
    $wasOmittedByClient = static function (mixed $attack) use ($removedIds): bool {
        if (!is_array($attack)) return false;
        foreach ([$attack['sourceTokenId'] ?? '', $attack['targetTokenId'] ?? ''] as $id) {
            if (is_string($id) && isset($removedIds[$id])) return true;
        }
        return false;
    };
    $involvesRemovedParty = static function (mixed $attack) use ($removed, $removedScenes): bool {
        if (!is_array($attack) || !is_string($attack['sceneId'] ?? null)) return false;
        if (isset($removedScenes[$attack['sceneId']])) return true;
        $removedOnScene = $removed[$attack['sceneId']] ?? [];
        if ($removedOnScene === []) return false;
        $parties = [$attack['sourceTokenId'] ?? '', $attack['targetTokenId'] ?? '',
            ...(is_array($attack['areaGate']['targetTokenIds'] ?? null) ? $attack['areaGate']['targetTokenIds'] : [])];
        foreach ($parties as $id) if (is_string($id) && isset($removedOnScene[$id])) return true;
        return false;
    };

    $active = static fn(mixed $attack): bool => is_array($attack)
        && is_string($attack['id'] ?? null)
        && in_array($attack['status'] ?? null, ['pending', 'awaiting-opposition'], true);
    $precisionOutcome = static fn(mixed $attack): bool => $active($attack)
        && ($attack['validationKind'] ?? null) === 'outcome' && ($attack['attackKind'] ?? null) === 'ability'
        && ($attack['attackId'] ?? null) === XAR_PRECISION_SERPENTAIRE_ID;
    $previousAndCurrent = [];
    foreach ([$stored, $activity] as $state) {
        foreach ($state['pendingAttacks'] ?? [] as $attack) {
            if ($active($attack)) $previousAndCurrent[$attack['id']] = $attack;
        }
    }
    $survivingChildren = [];
    foreach ($previousAndCurrent as $attack) {
        if ($precisionOutcome($attack) && !empty($attack['weaponAttackParentId']) && !$involvesRemovedParty($attack)) {
            $survivingChildren[$attack['weaponAttackParentId']] = true;
        }
    }
    $keepPrecisionAnchor = static fn(mixed $attack): bool => $precisionOutcome($attack)
        && !empty($attack['deferredAbilityCast']) && isset($survivingChildren[$attack['id']])
        && !isset($removedScenes[$attack['sceneId']]) && !isset($removed[$attack['sceneId']][$attack['sourceTokenId']]);
    $shouldRetire = static fn(array $attack): bool => ($involvesRemovedParty($attack) || !empty($attack['precisionPrimaryRemoved']))
        && !$keepPrecisionAnchor($attack);
    $retired = array_filter($previousAndCurrent, $shouldRetire);
    $pending = $activity['pendingAttacks'] ?? [];
    $pendingIds = array_fill_keys(array_column($pending, 'id'), true);
    foreach ($stored['pendingAttacks'] ?? [] as $attack) {
        // Older clients filter by token ID alone when sending their snapshot.
        if (!$active($attack) || !$wasOmittedByClient($attack) || $shouldRetire($attack)
            || isset($pendingIds[$attack['id']])) continue;
        $pending[] = $attack;
        $pendingIds[$attack['id']] = true;
    }
    foreach ($pending as $index => $attack) {
        if ($keepPrecisionAnchor($attack) && $involvesRemovedParty($attack)) {
            $pending[$index]['precisionPrimaryRemoved'] = true;
        }
    }
    $receipts = [];
    foreach ($activity['attackReceipts'] ?? [] as $receipt) {
        if (is_string($receipt['requestId'] ?? null)) $receipts[$receipt['requestId']] = $receipt;
    }
    foreach ($stored['attackReceipts'] ?? [] as $receipt) {
        // An older MJ client may send the deletion with those receipts already
        // omitted. The deletion is no reason to discard replay protection.
        $attack = $receipt['attack'] ?? null;
        if ((!$involvesRemovedParty($attack) && !isset($retired[$attack['id'] ?? '']) && !$wasOmittedByClient($attack))
            || !is_string($receipt['requestId'] ?? null)) continue;
        $current = $receipts[$receipt['requestId']] ?? null;
        if ($current === null || (!$active($attack) && $active($current['attack'] ?? null))) {
            $receipts[$receipt['requestId']] = $receipt;
        }
    }
    if ($retired === [] && array_values($receipts) === ($activity['attackReceipts'] ?? [])
        && $pending === ($activity['pendingAttacks'] ?? [])) return $activity;

    foreach ($receipts as &$receipt) {
        $attack = $receipt['attack'] ?? null;
        if (!$active($attack) || !isset($retired[$attack['id']])) continue;
        $attack['status'] = 'cancelled';
        $attack['resolvedAt'] = $now;
        unset($attack['deferredAbilityCast'], $attack['oppositionAbility'], $attack['cast']['pendingValidation']);
        if (is_array($attack['areaGate'] ?? null)) {
            // Freeze the prepared set of targets. A delayed approval cannot
            // release a different attack after one of its parties is removed.
            $attack['areaGate']['resolutionSignature'] = json_encode(['reject', []], JSON_THROW_ON_ERROR);
            $attack['areaGate']['defenseTokenIds'] = [];
            unset($attack['areaGate']['resolving']);
        }
        $receipt['attack'] = $attack;
        $receipt['expiresAt'] = $now + XAR_ATTACK_RECEIPT_TTL_MILLISECONDS;
    }
    unset($receipt);

    $activity['attackReceipts'] = array_values($receipts);
    $activity['pendingAttacks'] = array_values(array_filter($pending,
        static fn(mixed $attack): bool => !$active($attack) || !isset($retired[$attack['id']])));
    return $activity;
}
