<?php
declare(strict_types=1);

function onlineWantsTotalOpposition(array $arguments): bool
{
    return ($arguments['oppositionMode'] ?? null) === 'total';
}

function onlineAreaDefenseSignature(array $arguments, array $allowed): string
{
    return json_encode([$arguments['decision'], $allowed,
        ...(onlineWantsTotalOpposition($arguments) ? ['total'] : [])], JSON_THROW_ON_ERROR);
}

function onlineTotalAreaLaunchCanResolve(array $gate): bool
{
    foreach ($gate['preparedHit']['outcomes'] ?? [] as $outcome) {
        if (!empty($outcome['requiresGmValidation'])) return false;
    }
    return empty($gate['chanceOutcome']['requiresGmValidation'])
        && ($gate['chanceOutcome']['code'] ?? '') !== 'critical-failure';
}

function onlineTotalAreaDefenseIds(PDO $connection, array &$records, array $pending, array $attack): array
{
    $gate = $attack['areaGate'];
    if (isset($gate['resolutionSignature']) && onlineWantsTotalOpposition($gate)) return $gate['defenseTokenIds'] ?? [];
    $keys = [];
    foreach ($gate['defenseEligibleTokenIds'] ?? $gate['targetTokenIds'] ?? [] as $id) {
        $key = onlineTokenDomainKey($attack['sceneId'], $id);
        if ($key !== '') $keys[$id] = $key;
    }
    $missing = array_diff(array_values($keys), array_keys($records), array_keys($pending));
    if ($missing !== []) $records = array_replace($records, applicationDomainRecords($connection, $missing));
    return array_keys(array_filter($keys, static function (string $key) use ($records, $pending): bool {
        $target = $pending[$key]['payload'] ?? applicationDomainPayload($records, $key);
        return $target !== [] && !applicationIsNonCombatant($target);
    }));
}

function onlineTotalOppositionStat(array $stats, array $attack): string
{
    $available = array_values(array_filter($stats, static fn(array $stat): bool =>
        !empty($stat['id']) && is_numeric($stat['value'] ?? null)));
    $required = $attack['defenseStatId'] ?? '';
    if ($required !== '') return (string) (applicationAbilityCastingStat($available, $required)['id'] ?? '');
    return (string) (applicationAbilityCastingStat($available, 'character-stat-agility')['id'] ?? $available[0]['id'] ?? '');
}

function onlineResolveTotalOppositionResults(PDO $connection, array &$records, array &$pending,
    array $result, bool $isGm, bool $requested = false): array
{
    if (!empty($result['deduplicated']) || !empty($result['pendingAreaGate'])) return $result;
    $ids = [];
    foreach ([$result['attack'] ?? null, ...($result['areaAttacks'] ?? [])] as $attack) {
        if (is_string($attack['id'] ?? null)) $ids[$attack['id']] = true;
    }
    if ($ids === []) return $result;
    $activity = $pending['activity']['payload'] ?? applicationDomainPayload($records, 'activity');
    $attacks = [];
    $totalExecutions = [];
    foreach ($activity['attackReceipts'] ?? [] as $receipt) {
        $attack = $receipt['attack'] ?? [];
        if (isset($ids[$attack['id'] ?? ''])) $attacks[$attack['id']] = $attack;
        if (onlineWantsTotalOpposition($attack['areaGate'] ?? []) && is_string($attack['areaGate']['executionId'] ?? null)) {
            $totalExecutions[$attack['areaGate']['executionId']] = true;
        }
    }
    $groupIds = [];
    foreach ($attacks as $attack) {
        if (onlineWantsTotalOpposition($attack['areaGate'] ?? [])) $groupIds[$attack['id']] = true;
    }
    $resolved = [];
    $rolls = $result['rolls'] ?? [];
    $notifications = isset($result['discordContent']) ? [$result['discordContent']] : [];
    $automatic = ['id'=>'regie-auto-defense', 'display_name'=>'Régie · défense automatique', 'effective_mode'=>'gm', 'permanent_role'=>'gm'];
    foreach ($activity['pendingAttacks'] ?? [] as $attack) {
        $id = $attack['id'] ?? '';
        $group = $attack['areaGateParentId'] ?? '';
        if ((!isset($ids[$id]) && !isset($groupIds[$group])) || ($attack['status'] ?? '') !== 'awaiting-opposition') continue;
        if (!$requested && !onlineWantsTotalOpposition($attack)
            && !isset($groupIds[$group]) && !isset($totalExecutions[$attack['complexExecutionId'] ?? ''])) continue;
        $sceneId = $attack['sceneId'];
        $targetKey = onlineTokenDomainKey($sceneId, $attack['targetTokenId'] ?? '');
        if (!isset($records[$targetKey]) && !isset($pending[$targetKey])) {
            $records = array_replace($records, applicationDomainRecords($connection, [$targetKey]));
        }
        $target = $pending[$targetKey]['payload'] ?? applicationDomainPayload($records, $targetKey);
        if ($target === [] || applicationIsNonCombatant($target)
            || onlineTokenControllerIdFromRecords($connection, $records, $target) !== '') continue;
        $character = applicationDomainPayload($records, 'character:' . ($target['characterId'] ?? ''));
        if ($character !== []) $target = synchronizeOnlineCharacterToken($target, $character);
        $defeated = onlineTokenIsDead($target, false) || !is_numeric($target['maxHp'] ?? null) || (float)$target['maxHp'] <= 0;
        $statId = $defeated ? '' : onlineTotalOppositionStat(onlineEnhancedStatRows($connection, $records, $pending, $target, $sceneId), $attack);
        $defense = onlinePerformAttackOpposition($connection, $records, $pending, $automatic, [
            'attackId'=>$id, 'requestId'=>'total-opp-' . hash('sha256', $id),
            'decision'=>$defeated ? 'cancel' : ($statId !== '' ? 'roll' : 'skip'), 'statId'=>$statId,
        ], true, $sceneId, true);
        $settled = $defense['attack'];
        $resolved[$id] = $settled;
        if ($isGm || empty($settled['concealedAreaTarget'])) {
            $rolls = [...$rolls, ...onlineAttackResponseRollFields($settled, $isGm, true, false)['rolls']];
        }
        if ($sceneId === onlineActiveSceneId(applicationDomainPayload($records, 'table')) && !empty($defense['discordContent'])) {
            $notifications[] = $defense['discordContent'];
        }
    }
    if ($resolved === []) return $result;
    $present = static fn(array $attack): array => $isGm ? $attack : publicOnlineAttackResult($attack, true, false);
    $primaryId = $result['attack']['id'] ?? '';
    if (isset($resolved[$primaryId])) {
        $result['attack'] = $present($resolved[$primaryId]);
        $primaryRolls = onlineAttackResponseRollFields($resolved[$primaryId], $isGm, true, false);
        unset($primaryRolls['rolls']);
        $result = [...$result, ...$primaryRolls];
    }
    if (isset($result['areaAttacks'])) {
        $result['areaAttacks'] = array_map(static fn(array $attack): array =>
            isset($resolved[$attack['id']]) ? $present($resolved[$attack['id']]) : $attack, $result['areaAttacks']);
    }
    $result['rolls'] = applicationUniqueRolls($rolls);
    $result['discordContent'] = implode("\n\n", array_filter($notifications));
    return $result;
}
