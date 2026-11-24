<?php
declare(strict_types=1);

function applicationGroupedAttackLedgerEnabled(PDO $connection, bool $enable = false): bool
{
    static $connections;
    $connections ??= new WeakMap();
    if ($enable) $connections[$connection] = true;
    return isset($connections[$connection]);
}

function applicationSelectArchivedAttackReceipts(PDO $connection, string $index, array $keys, int $now): array
{
    if ($keys === []) return [];
    if (!in_array($index, ['attack_id', 'complex_execution_id', 'origin_execution_id'], true)) {
        throw new InvalidArgumentException('Unsupported attack receipt index.');
    }
    $order = applicationGroupedAttackLedgerEnabled($connection) ? ' ORDER BY group_id,group_position,request_id' : '';
    $query = $connection->prepare('SELECT payload FROM application_direct_attack_receipts WHERE '
        . $index . ' IN (' . implode(',', array_fill(0, count($keys), '?')) . ') AND expires_at>?' . $order);
    $query->execute([...$keys, $now]);
    return array_map(static fn(string $payload): array => json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
        $query->fetchAll(PDO::FETCH_COLUMN));
}

function applicationAttackReceiptByRequest(PDO $connection, array $activity, string $requestId, int $now): ?array
{
    foreach ($activity['attackReceipts'] ?? [] as $receipt) {
        if (($receipt['requestId'] ?? null) === $requestId) return $receipt;
    }
    return applicationReadDirectAttackReceipt($connection, $requestId, $now);
}

function applicationArchivedAttackFamily(PDO $connection, string $attackId, int $now): array
{
    if (!applicationGroupedAttackLedgerEnabled($connection) || !applicationResourceReceiptKey($attackId, 120)) return [];
    $query = $connection->prepare('SELECT payload FROM application_direct_attack_receipts WHERE expires_at>? '
        . 'AND group_id IN (SELECT group_id FROM application_direct_attack_receipts WHERE attack_id=? AND expires_at>?) '
        . 'ORDER BY group_id,group_position,request_id');
    $query->execute([$now, $attackId, $now]);
    return array_map(static fn(string $payload): array => json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
        $query->fetchAll(PDO::FETCH_COLUMN));
}

function applicationAttackFamilyChildren(PDO $connection, array $activity, string $parentId, array $fields): array
{
    $children = static function (array $receipts) use ($parentId, $fields): array {
        $result = [];
        foreach ($receipts as $receipt) {
            $attack = $receipt['attack'] ?? [];
            foreach ($fields as $field) {
                if (($attack[$field] ?? null) !== $parentId) continue;
                $result[] = $attack;
                break;
            }
        }
        return $result;
    };
    $live = $children($activity['attackReceipts'] ?? []);
    return $live !== [] ? $live : $children(applicationArchivedAttackFamily($connection, $parentId, applicationResourceReceiptClock()));
}

function applicationReceiptGroupTerminal(array $attack): bool
{
    return applicationAttackIsFinalized($attack) || (($attack['status'] ?? '') === 'resolved'
        && is_string($attack['areaGate']['resolutionSignature'] ?? null));
}

/**
 * Build the connected components touched by zone parents, ordered weapon
 * strikes, echoes and complex executions. The complete live component stays
 * in activity, so normal workflow and validation still see the same rows.
 */
function applicationClosedAttackReceiptGroups(array $activity): array
{
    $links = [];
    $live = [];
    $indexed = [];
    $activeExecutions = [];
    foreach ($activity['abilityExecutions'] ?? [] as $execution) {
        if (($execution['status'] ?? '') !== 'active') continue;
        if (applicationResourceReceiptKey($execution['id'] ?? null, 80)) $activeExecutions[$execution['id']] = true;
        if (applicationResourceReceiptKey($execution['echoParentId'] ?? null, 120)) $live['attack:' . $execution['echoParentId']] = true;
    }
    $link = static function (string $left, string $right) use (&$links): void {
        if ($left === $right) return;
        $links[$left][$right] = true;
        $links[$right][$left] = true;
    };
    $register = static function (array $attack, array $children, bool $pending) use (&$live, $link, $activeExecutions): void {
        if (!applicationResourceReceiptKey($attack['id'] ?? null, 120)) return;
        $node = 'attack:' . $attack['id'];
        foreach (['areaGateParentId', 'weaponAttackParentId', 'echoParentId'] as $field) {
            if (applicationResourceReceiptKey($attack[$field] ?? null, 120)) $link($node, 'attack:' . $attack[$field]);
        }
        foreach (is_array($attack['echoAttacks'] ?? null) ? $attack['echoAttacks'] : [] as $echo) {
            $children[] = is_array($echo) ? ($echo['id'] ?? '') : $echo;
        }
        foreach ($children as $child) if (applicationResourceReceiptKey($child, 120)) $link($node, 'attack:' . $child);
        foreach ([$attack['complexExecutionId'] ?? null, $attack['areaGate']['executionId'] ?? null] as $id) {
            if (applicationResourceReceiptKey($id, 80)) $link($node, 'execution:' . $id);
        }
        $executionIds = [$attack['complexExecutionId'] ?? null, $attack['counterExecutionId'] ?? null,
            $attack['originExecutionId'] ?? null, $attack['sourceExecutionId'] ?? null, $attack['areaGate']['executionId'] ?? null];
        foreach ($executionIds as $id) {
            if (is_string($id) && isset($activeExecutions[$id])) $live[$node] = true;
        }
        if ($pending) $live[$node] = true;
    };
    foreach ($activity['pendingAttacks'] ?? [] as $attack) if (is_array($attack)) $register($attack, [], true);
    foreach ($activity['attackReceipts'] ?? [] as $position => $receipt) {
        $attack = $receipt['attack'] ?? [];
        if (!applicationResourceReceiptKey($attack['id'] ?? null, 120)) continue;
        $eligible = applicationReceiptGroupTerminal($attack)
            && applicationResourceReceiptKey($receipt['requestId'] ?? null, 80);
        $register($attack, is_array($receipt['areaAttackIds'] ?? null) ? $receipt['areaAttackIds'] : [], !$eligible);
        $indexed['attack:' . $attack['id']][] = $position;
    }
    $visited = [];
    $groups = [];
    foreach ($indexed as $start => $_) {
        if (isset($visited[$start])) continue;
        $positions = [];
        $open = false;
        $queue = [$start];
        $visited[$start] = true;
        while ($queue !== []) {
            $node = array_pop($queue);
            if (isset($live[$node])) $open = true;
            foreach ($indexed[$node] ?? [] as $position) $positions[] = $position;
            foreach ($links[$node] ?? [] as $next => $_) {
                if (isset($visited[$next])) continue;
                $visited[$next] = true;
                $queue[] = $next;
            }
        }
        if ($open) continue;
        sort($positions, SORT_NUMERIC);
        $groups[] = $positions;
    }
    return $groups;
}

function applicationPreserveArchivedAttackJournalGroups(array $entries, array $archived, array $receipts = []): array
{
    if ($archived === []) return $entries;
    $attacks = $archived;
    foreach ($receipts as $receipt) {
        $attack = $receipt['attack'] ?? [];
        if (is_string($attack['id'] ?? null)) $attacks[$attack['id']] = $attack;
    }

    $attackKeys = [];
    $attackKey = static function (string $id) use ($attacks, &$attackKeys): string {
        if (isset($attackKeys[$id])) return $attackKeys[$id];
        $original = $id;
        $seen = [];
        $attack = $attacks[$id] ?? [];
        while (is_string($attack['echoParentId'] ?? null) && $attack['echoParentId'] !== '' && !isset($seen[$id])) {
            $seen[$id] = true;
            $id = $attack['echoParentId'];
            $attack = $attacks[$id] ?? [];
        }
        return $attackKeys[$original] = applicationResourceReceiptKey($attack['complexExecutionId'] ?? null, 80)
            ? 'execution:' . $attack['complexExecutionId'] : 'attack:' . $id;
    };

    $parents = [];
    $firstByAlias = [];
    $archivedRows = [];
    $aliasesByRow = [];
    $find = static function (int $index) use (&$parents): int {
        while ($parents[$index] !== $index) {
            $parents[$index] = $parents[$parents[$index]];
            $index = $parents[$index];
        }
        return $index;
    };
    foreach ($entries as $index => $entry) {
        $parents[$index] = $index;
        $scope = (is_string($entry['sceneId'] ?? null) ? $entry['sceneId'] : '') . ':';
        $aliases = [];
        foreach (['groupId' => 'group:', 'combatActionId' => 'combat:'] as $field => $prefix) {
            if (is_string($entry[$field] ?? null) && $entry[$field] !== '') $aliases[] = $scope . $prefix . $entry[$field];
        }
        $request = $entry['operation']['requestId'] ?? '';
        $attackId = ($entry['attackId'] ?? '') ?: (is_string($request) && str_starts_with($request, 'damage-') ? substr($request, 7) : '');
        if (is_string($attackId) && $attackId !== '') {
            $aliases[] = $scope . $attackKey($attackId);
            if (isset($archived[$attackId])) $archivedRows[] = $index;
        }
        $aliasesByRow[$index] = $aliases;
        foreach ($aliases as $alias) {
            $firstByAlias[$alias] ??= $index;
            $parents[$find($index)] = $find($firstByAlias[$alias]);
        }
    }

    // Preserve whole journal components while the client loses the receipt
    // links, including transaction rows with no direct reference to an attack.
    $components = [];
    foreach ($archivedRows as $index) $components[$find($index)] = [];
    foreach ($entries as $index => $_) {
        $root = $find($index);
        if (isset($components[$root])) $components[$root][] = $index;
    }
    foreach ($components as $members) {
        $priorGroups = [];
        $aliases = [];
        foreach ($members as $index) {
            $group = $entries[$index]['groupId'] ?? '';
            if (is_string($group) && preg_match('/^archived-attack-[a-f0-9]{64}$/D', $group)) $priorGroups[] = $group;
            $aliases = [...$aliases, ...$aliasesByRow[$index]];
        }
        $group = $priorGroups !== [] ? min($priorGroups) : 'archived-attack-' . hash('sha256', min($aliases));
        foreach ($members as $index) $entries[$index]['groupId'] = $group;
    }
    return $entries;
}

function applicationArchiveClosedAttackGroups(PDO $connection, array $activity, int $now): array
{
    if (!applicationGroupedAttackLedgerEnabled($connection) || empty($activity['attackReceipts'])) return $activity;
    $groups = applicationClosedAttackReceiptGroups($activity);
    if ($groups === []) return $activity;
    $receipts = $activity['attackReceipts'];
    $removed = [];
    $archivedAttacks = [];
    $rows = [];
    $key = static fn(mixed $id, int $length): ?string => applicationResourceReceiptKey($id, $length) ? $id : null;
    foreach ($groups as $positions) {
        $first = $receipts[$positions[0]];
        $groupId = hash('sha256', $first['requestId']);
        $expires = max(array_map(static fn(int $position): int => (int) ($receipts[$position]['expiresAt'] ?? 0), $positions));
        foreach ($positions as $index => $position) {
            $receipt = $receipts[$position];
            $attack = $receipt['attack'];
            if ($expires > $now) {
                $rows[] = [$receipt['requestId'], $attack['id'], $key($attack['opposition']['requestId'] ?? null, 80),
                    $expires, json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    $groupId, $index, $key($attack['complexExecutionId'] ?? null, 80), $key($attack['originExecutionId'] ?? null, 80)];
            }
            $archivedAttacks[$attack['id']] = $attack;
            $removed[$position] = true;
        }
    }
    foreach (array_chunk($rows, 64) as $batch) {
        $insert = $connection->prepare('INSERT INTO application_direct_attack_receipts '
            . '(request_id,attack_id,opposition_request_id,expires_at,payload,group_id,group_position,complex_execution_id,origin_execution_id) '
            . 'VALUES ' . implode(',', array_fill(0, count($batch), '(?,?,?,?,?,?,?,?,?)'))
            . ' ON DUPLICATE KEY UPDATE request_id=VALUES(request_id)');
        $insert->execute(array_merge(...$batch));
    }
    if (!empty($activity['abilityExecutions'])) {
        $activity['abilityExecutions'] = applicationComplexAbilityResultsFromReceipts($activity['abilityExecutions'],
            array_intersect_key($receipts, $removed));
    }
    if (!empty($activity['playerActions'])) {
        $pendingReceipts = array_map(static fn(array $attack): array => ['attack'=>$attack], $activity['pendingAttacks'] ?? []);
        $activity['playerActions'] = applicationPreserveArchivedAttackJournalGroups(
            $activity['playerActions'], $archivedAttacks, [...$receipts, ...$pendingReceipts]);
    }
    $activity['attackReceipts'] = array_values(array_diff_key($receipts, $removed));
    return $activity;
}

function migrateApplicationGroupedAttackReceiptLedger(PDO $connection): void
{
    $changes = [];
    $columns = array_fill_keys($connection->query('SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema=DATABASE() '
        . 'AND table_name=\'application_direct_attack_receipts\'')->fetchAll(PDO::FETCH_COLUMN), true);
    foreach ([
        'group_id' => 'CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL',
        'group_position' => 'SMALLINT UNSIGNED NULL',
        'complex_execution_id' => 'VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL',
        'origin_execution_id' => 'VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL',
    ] as $column => $definition) {
        if (!isset($columns[$column])) $changes[] = 'ADD COLUMN ' . $column . ' ' . $definition;
    }
    $indexes = array_fill_keys($connection->query('SELECT DISTINCT INDEX_NAME FROM information_schema.statistics WHERE table_schema=DATABASE() '
        . 'AND table_name=\'application_direct_attack_receipts\'')->fetchAll(PDO::FETCH_COLUMN), true);
    foreach ([
        'grouped_attack_order' => 'group_id,group_position',
        'grouped_attack_execution' => 'complex_execution_id,expires_at',
        'grouped_attack_origin' => 'origin_execution_id,expires_at',
    ] as $index => $fields) {
        if (!isset($indexes[$index])) $changes[] = 'ADD INDEX ' . $index . ' (' . $fields . ')';
    }
    if ($changes !== []) $connection->exec('ALTER TABLE application_direct_attack_receipts ' . implode(', ', $changes));
    applicationDirectAttackLedgerEnabled($connection, true);
    applicationGroupedAttackLedgerEnabled($connection, true);
    $connection->beginTransaction();
    try {
        $clock = domainClockRecord($connection, true);
        $records = applicationDomainRecords($connection, ['activity']);
        if (isset($records['activity'])) {
            $activity = applicationArchiveClosedAttackGroups($connection, $records['activity']['payload'], applicationResourceReceiptClock());
            $prepared = prepareApplicationDomainUpsert('activity', $activity, $records['activity']);
            if ($prepared !== null) persistDomainChangesInTransaction($connection, [], $clock, [$prepared]);
        }
        $migration = $connection->prepare('INSERT IGNORE INTO schema_migrations (version,name,checksum) VALUES (28,:name,:checksum)');
        $migration->execute([':name'=>'authoritative_grouped_attack_receipt_ledger',
            ':checksum'=>hash('sha256', 'application_direct_attack_receipts|linked-groups|28')]);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $error;
    }
}
