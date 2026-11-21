<?php
declare(strict_types=1);

function rejectLegacySceneIdentityRepair(int $status, string $message, string $code): never
{
    throw new UnexpectedValueException($code, $status);
}

// Earlier scene copies reused token IDs. Preserve the oldest occurrence, which
// retains its inventory, and give idle copies independent identities.
function legacySceneTokenOccurrences(PDO $connection): array
{
    $statement = $connection->query(
        "SELECT domain_key FROM application_domains WHERE domain_key LIKE 'token:%' ORDER BY created_at, domain_key"
    );
    $groups = [];
    foreach ($statement === false ? [] : $statement->fetchAll(PDO::FETCH_COLUMN) as $key) {
        $parts = explode(':', (string) $key);
        if (count($parts) === 3 && validApplicationDomainKey((string) $key)) $groups[$parts[2]][] = (string) $key;
    }
    return array_filter($groups, static fn(array $keys): bool => count($keys) > 1);
}

function legacySceneIdentityRepairs(PDO $connection, array $groups): array
{
    $keys = array_merge(...array_values($groups));
    $records = applicationDomainRecords($connection, [...$keys, 'table', 'activity', 'devices', 'items']);
    $table = applicationDomainPayload($records, 'table');
    $activity = applicationDomainPayload($records, 'activity');
    $devices = applicationDomainPayload($records, 'devices');
    $items = applicationDomainPayload($records, 'items');
    $protected = [];
    foreach (['pendingAttacks', 'pendingAbilityCasts', 'pendingDotResolutions', 'damageOverTime',
        'attackEnhancements', 'nextAttackGuards', 'abilityMovementLeases'] as $field) {
        foreach ($activity[$field] ?? [] as $entry) if (!empty($entry['sceneId'])) $protected[$entry['sceneId']] = true;
    }
    foreach ($activity['abilityExecutions'] ?? [] as $entry) {
        if (!empty($entry['sceneId']) && ($entry['status'] ?? '') === 'active') $protected[$entry['sceneId']] = true;
    }
    foreach ([...($devices['pendingRolls'] ?? []), ...($items['pendingUses'] ?? [])] as $entry) {
        if (!empty($entry['sceneId'])) $protected[$entry['sceneId']] = true;
    }
    foreach ($devices['devices'] ?? [] as $entry) {
        if (!empty($entry['sceneId']) && ($entry['status'] ?? '') === 'active') $protected[$entry['sceneId']] = true;
    }
    if (!empty($table['tacticalSync']['paused']) && !empty($table['activeSceneId'])) $protected[$table['activeSceneId']] = true;

    $byScene = [];
    foreach ($groups as $oldId => $occurrences) {
        $autonomous = array_values(array_filter($occurrences, static function (string $key) use ($records): bool {
            $token = applicationDomainPayload($records, $key);
            return $token !== [] && !unitUsesCharacterSheet($token);
        }));
        foreach (array_slice($autonomous, 1) as $key) {
            [, $sceneId] = explode(':', $key);
            if (!isset($protected[$sceneId])) $byScene[$sceneId][$oldId] = 'token-recovered-' . substr(hash('sha256', $key), 0, 28);
        }
    }
    return $byScene;
}

function queueLegacySceneIdentityRepair(PDO $connection, array &$pending, string $sceneId, array $ids): void
{
    $mapKey = 'map:' . $sceneId;
    $clockKey = 'initiative:' . $sceneId;
    $indexKey = 'token-index:' . $sceneId;
    $records = applicationDomainRecords($connection, [$mapKey, $clockKey, $indexKey,
        ...array_map(static fn(string $id): string => 'token:' . $sceneId . ':' . $id, array_values($ids))]);
    $map = applicationDomainPayload($records, $mapKey);
    $index = applicationDomainPayload($records, $indexKey);
    if ($map === [] || !is_array($index['order'] ?? null)) return;
    // An already occupied generated identity is never overwritten.
    foreach ($ids as $new) if (isset($records['token:' . $sceneId . ':' . $new])) return;
    $tokens = applicationDomainRecords($connection, array_map(
        static fn(string $id): string => 'token:' . $sceneId . ':' . $id,
        array_unique([...$index['order'], ...array_keys($ids)])
    ));
    $records += $tokens;
    $upsert = static function (string $key, array $payload) use (&$pending, $records): void {
        $change = prepareApplicationDomainUpsert($key, $payload, $records[$key] ?? null, reject: 'rejectLegacySceneIdentityRepair');
        if ($change !== null) $pending[$key] = $change;
    };
    $remap = static fn(mixed $id): mixed => is_string($id) ? ($ids[$id] ?? $id) : $id;
    foreach ($tokens as $key => $record) {
        $token = $original = $record['payload'];
        $old = (string) ($token['id'] ?? '');
        foreach (['mountedOnTokenId', 'targetTokenId', 'cloneSourceTokenId'] as $field) {
            if (isset($token[$field])) $token[$field] = $remap($token[$field]);
        }
        $token['id'] = $remap($old);
        if ($token === $original) continue;
        if ($token['id'] !== $old) queueOnlineDomainDelete($pending, $records, $key);
        $upsert('token:' . $sceneId . ':' . $token['id'], $token);
    }
    $index['order'] = array_map($remap, $index['order']);
    $upsert($indexKey, $index);
    $remapLights = static function (array $level) use ($remap): array {
        foreach ($level['lights'] ?? [] as $i => $light) {
            if (isset($light['carrierTokenId'])) $level['lights'][$i]['carrierTokenId'] = $remap($light['carrierTokenId']);
        }
        return $level;
    };
    $map = $remapLights($map);
    foreach ($map['layers'] ?? [] as $layerId => $layer) $map['layers'][$layerId] = $remapLights($layer);
    if ($map !== $records[$mapKey]['payload']) $upsert($mapKey, $map);
    $clock = applicationDomainPayload($records, $clockKey);
    if ($clock !== []) {
        foreach (['order', 'roundBaseOrder', 'delayedTurnIds'] as $field) {
            if (isset($clock[$field])) $clock[$field] = array_map($remap, $clock[$field]);
            if (isset($clock['previousRoundOrder'][$field])) $clock['previousRoundOrder'][$field] = array_map($remap, $clock['previousRoundOrder'][$field]);
        }
        if (is_array($clock['movementOverrides'] ?? null)) {
            $overrides = [];
            foreach ($clock['movementOverrides'] as $id => $allowed) $overrides[$remap($id)] = $allowed;
            $clock['movementOverrides'] = $overrides;
        }
        if ($clock !== $records[$clockKey]['payload']) $upsert($clockKey, $clock);
    }
}

function repairLegacySceneIdentities(PDO $connection, array $identity): int
{
    if (legacySceneTokenOccurrences($connection) === []) return 0;
    $connection->beginTransaction();
    try {
        $clock = domainClockRecord($connection, true);
        $groups = legacySceneTokenOccurrences($connection);
        $pending = [];
        $repairs = $groups === [] ? [] : legacySceneIdentityRepairs($connection, $groups);
        foreach ($repairs as $sceneId => $ids) {
            $sceneChanges = [];
            try {
                queueLegacySceneIdentityRepair($connection, $sceneChanges, (string) $sceneId, $ids);
                if ($identity !== []) assertApplicationAbilitySoundAssets($connection, $sceneChanges, 'rejectLegacySceneIdentityRepair');
                $pending += $sceneChanges;
            } catch (UnexpectedValueException $error) {
                // A malformed historical document can be left intact without blocking the session.
                error_log('[xar-regie-api] skipped invalid legacy scene identity repair: ' . $error->getMessage());
            }
        }
        if ($pending !== []) persistDomainChangesInTransaction($connection, $identity, $clock, $pending, 'rejectLegacySceneIdentityRepair');
        $connection->commit();
        return count(array_filter($pending, static fn(array $change): bool => $change['operation'] === 'delete'));
    } catch (Throwable $error) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $error;
    }
}
