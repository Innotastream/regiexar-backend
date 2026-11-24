<?php
declare(strict_types=1);

require_once __DIR__ . '/grouped-attack-receipt-ledger.php';

function applicationDirectAttackLedgerEnabled(PDO $connection, bool $enable = false): bool
{
    static $connections;
    $connections ??= new WeakMap();
    if ($enable) $connections[$connection] = true;
    return isset($connections[$connection]);
}

function applicationIsIndependentFinalAttackReceipt(array $receipt, array $linked): bool
{
    $attack = $receipt['attack'] ?? null;
    if (!applicationAttackIsFinalized($attack) || !applicationResourceReceiptKey($attack['id'] ?? null, 120)
        || isset($linked[$attack['id']]) || !applicationResourceReceiptKey($receipt['requestId'] ?? null, 80)
        || ($attack['requestId'] ?? null) !== $receipt['requestId']
        || ($attack['accountId'] ?? null) !== ($receipt['accountId'] ?? null)
        || !applicationResourceReceiptKey($attack['sourceTokenId'] ?? null, 128)
        || !applicationResourceReceiptKey($attack['targetTokenId'] ?? null, 128)
        || !in_array($attack['attackKind'] ?? '', ['weapon', 'ability'], true)) return false;
    foreach (['areaGate', 'areaGateParentId', 'attackAnchorOnly', 'areaAnchorOnly', 'areaRadiusCells',
        'weaponAttackParentId', 'echoParentId', 'echoDefinitions', 'echoAttacks', 'echoProcessed',
        'complexExecutionId', 'originExecutionId', 'counterExecutionId', 'sourceExecutionId',
        'deviceOrigin', 'deferredAbilityCast'] as $field) {
        if (!empty($attack[$field])) return false;
    }
    if (!empty($receipt['areaAttackIds'])) return false;
    // A standalone attack must originate from the targeted command, rather
    // than an automatically generated device, area, damage or reaction event.
    $signature = is_string($receipt['requestSignature'] ?? null)
        ? json_decode($receipt['requestSignature'], true) : null;
    return is_array($signature) && array_slice($signature, 0, 4) === [
        'token.attack', $attack['sceneId'] ?? null, $attack['sourceTokenId'], $attack['targetTokenId'],
    ];
}

function applicationDirectAttackLinkedIds(array $activity): array
{
    $linked = [];
    $register = static function (array $attack, bool $pending = false) use (&$linked): void {
        if ($pending && is_string($attack['id'] ?? null)) $linked[$attack['id']] = true;
        foreach (['areaGateParentId', 'weaponAttackParentId', 'echoParentId'] as $field) {
            if (is_string($attack[$field] ?? null) && $attack[$field] !== '') $linked[$attack[$field]] = true;
        }
        foreach (is_array($attack['echoAttacks'] ?? null) ? $attack['echoAttacks'] : [] as $echo) {
            $id = is_array($echo) ? ($echo['id'] ?? null) : $echo;
            if (is_string($id)) $linked[$id] = true;
        }
    };
    foreach ($activity['pendingAttacks'] ?? [] as $attack) if (is_array($attack)) $register($attack, true);
    foreach ($activity['attackReceipts'] ?? [] as $receipt) {
        if (is_array($receipt['attack'] ?? null)) $register($receipt['attack']);
        foreach (is_array($receipt['areaAttackIds'] ?? null) ? $receipt['areaAttackIds'] : [] as $id) {
            if (is_string($id)) $linked[$id] = true;
        }
    }
    foreach ($activity['abilityExecutions'] ?? [] as $execution) {
        if (is_string($execution['echoParentId'] ?? null)) $linked[$execution['echoParentId']] = true;
    }
    return $linked;
}

function applicationWriteDirectAttackReceipts(PDO $connection, array $receipts): void
{
    if ($receipts === [] || !applicationDirectAttackLedgerEnabled($connection)) return;
    $statement = $connection->prepare(
        'INSERT INTO application_direct_attack_receipts '
        . '(request_id,attack_id,opposition_request_id,expires_at,payload) VALUES (?,?,?,?,?) '
        . 'ON DUPLICATE KEY UPDATE opposition_request_id=VALUES(opposition_request_id),'
        . 'expires_at=GREATEST(expires_at,VALUES(expires_at)),payload=VALUES(payload)'
    );
    foreach ($receipts as $receipt) {
        $attack = $receipt['attack'];
        $opposition = $attack['opposition']['requestId'] ?? null;
        $statement->execute([$receipt['requestId'], $attack['id'],
            applicationResourceReceiptKey($opposition, 80) ? $opposition : null,
            $receipt['expiresAt'],
            json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
    }
}

function applicationArchiveIndependentAttacks(PDO $connection, array $activity, int $now): array
{
    if (!applicationDirectAttackLedgerEnabled($connection) || empty($activity['attackReceipts'])) return $activity;
    $linked = applicationDirectAttackLinkedIds($activity);
    $archive = [];
    $retained = [];
    foreach ($activity['attackReceipts'] as $receipt) {
        if (is_array($receipt) && (int) ($receipt['expiresAt'] ?? 0) > $now
            && applicationIsIndependentFinalAttackReceipt($receipt, $linked)) $archive[] = $receipt;
        else $retained[] = $receipt;
    }
    applicationWriteDirectAttackReceipts($connection, $archive);
    $activity['attackReceipts'] = $retained;
    return $activity;
}

function applicationReadDirectAttackReceipt(PDO $connection, string $requestId, int $now, ?string $attackId = null): ?array
{
    if (!applicationDirectAttackLedgerEnabled($connection) || !applicationResourceReceiptKey($requestId, 80)) return null;
    $statement = $connection->prepare('SELECT payload FROM application_direct_attack_receipts WHERE '
        . ($attackId === null ? 'request_id=?' : 'opposition_request_id=? AND attack_id=?') . ' AND expires_at>?');
    $statement->execute($attackId === null ? [$requestId, $now] : [$requestId, $attackId, $now]);
    $payload = $statement->fetchColumn();
    return $payload === false ? null : json_decode((string) $payload, true, 512, JSON_THROW_ON_ERROR);
}

function applicationReadUndoDirectAttackReceipts(PDO $connection, array $action, int $now): array
{
    if (!applicationDirectAttackLedgerEnabled($connection)) return [];
    $ids = [];
    $executions = [];
    foreach ($action['aliases'] ?? [] as $alias) {
        if (!is_string($alias)) continue;
        if (str_starts_with($alias, 'attack:')) {
            $id = substr($alias, 7);
            if (applicationResourceReceiptKey($id, 120)) $ids[$id] = true;
        } elseif (str_starts_with($alias, 'execution:')) {
            $id = substr($alias, 10);
            if (applicationResourceReceiptKey($id, 80)) $executions[$id] = true;
        }
    }
    $records = [];
    $indexes = ['attack_id' => $ids];
    if (applicationGroupedAttackLedgerEnabled($connection) && $executions !== []) {
        $indexes['complex_execution_id'] = $executions;
        $indexes['origin_execution_id'] = $executions;
    }
    foreach ($indexes as $index => $keys) {
        foreach (array_chunk(array_keys($keys), 128) as $batch) {
            foreach (applicationSelectArchivedAttackReceipts($connection, $index, $batch, $now) as $receipt) {
                $records[$receipt['requestId']] = $receipt;
            }
        }
    }
    return $records;
}

function applicationProtectPrivateDirectAttackReceipts(PDO $connection, mixed $payload, array $previous): mixed
{
    if (!applicationDirectAttackLedgerEnabled($connection) || !is_array($payload)) return $payload;
    $incoming = $payload['attackReceipts'] ?? null;
    $pending = $payload['pendingAttacks'] ?? null;
    $known = array_fill_keys(array_filter(array_column($previous['attackReceipts'] ?? [], 'requestId'), 'is_string'), true);
    $knownAttacks = array_fill_keys(array_filter(array_column($previous['pendingAttacks'] ?? [], 'id'), 'is_string'), true);
    $unknownIds = [];
    $unknownRequests = [];
    if (is_array($incoming) && array_is_list($incoming) && count($incoming) <= XAR_ATTACK_RECEIPT_MAXIMUM) {
        // Legacy writes may preserve an existing receipt. They cannot recreate
        // private final outcomes or archive new results from a snapshot.
        $payload['attackReceipts'] = array_values(array_filter($incoming, static function ($receipt) use ($known, &$unknownIds, &$unknownRequests): bool {
            if (!is_array($receipt)) return true;
            $requestId = $receipt['requestId'] ?? null;
            if (is_string($requestId) && isset($known[$requestId])) return true;
            if (applicationIsIndependentFinalAttackReceipt($receipt, [])) return false;
            $id = $receipt['attack']['id'] ?? null;
            if (applicationResourceReceiptKey($id, 120)) $unknownIds[$id] = true;
            if (applicationResourceReceiptKey($requestId, 80)) $unknownRequests[$requestId] = true;
            return true;
        }));
    }
    $validPending = is_array($pending) && array_is_list($pending) && count($pending) <= XAR_PENDING_ATTACK_MAXIMUM;
    if ($validPending) {
        foreach ($pending as $attack) {
            $id = $attack['id'] ?? null;
            if (applicationResourceReceiptKey($id, 120) && isset($knownAttacks[$id])) continue;
            if (applicationResourceReceiptKey($id, 120)) $unknownIds[$id] = true;
            $requestId = $attack['requestId'] ?? null;
            if (applicationResourceReceiptKey($requestId, 80)) $unknownRequests[$requestId] = true;
        }
    }
    $archivedIds = [];
    $archivedRequests = [];
    foreach (['attack_id' => $unknownIds, 'request_id' => $unknownRequests] as $field => $ids) {
        foreach (array_chunk(array_keys($ids), 128) as $batch) {
            $query = $connection->prepare('SELECT attack_id,request_id FROM application_direct_attack_receipts WHERE '
                . $field . ' IN (' . implode(',', array_fill(0, count($batch), '?')) . ')');
            $query->execute($batch);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $archivedIds[$row['attack_id']] = true;
                $archivedRequests[$row['request_id']] = true;
            }
        }
    }
    $isArchived = static fn(mixed $attackId, mixed $requestId): bool =>
        (is_string($attackId) && isset($archivedIds[$attackId])) || (is_string($requestId) && isset($archivedRequests[$requestId]));
    if ($archivedIds !== []) {
        if ($validPending) $payload['pendingAttacks'] = array_values(array_filter($pending, static fn($attack): bool =>
            !$isArchived($attack['id'] ?? null, $attack['requestId'] ?? null)));
        if (is_array($incoming) && array_is_list($incoming) && count($incoming) <= XAR_ATTACK_RECEIPT_MAXIMUM) {
            $payload['attackReceipts'] = array_values(array_filter($payload['attackReceipts'], static fn($receipt): bool =>
                !$isArchived($receipt['attack']['id'] ?? null, $receipt['requestId'] ?? null)));
        }
    }
    return $payload;
}

function applicationPruneDirectAttackLedger(PDO $connection, int $now): void
{
    if (!applicationDirectAttackLedgerEnabled($connection) || $connection->inTransaction()) return;
    $delete = $connection->prepare('DELETE FROM application_direct_attack_receipts WHERE expires_at<=? '
        . 'ORDER BY expires_at,request_id LIMIT ' . XAR_DOMAIN_MAINTENANCE_BATCH_SIZE);
    $delete->execute([$now]);
}

function migrateApplicationDirectAttackReceiptLedger(PDO $connection): void
{
    $connection->exec('CREATE TABLE IF NOT EXISTS application_direct_attack_receipts ('
        . 'request_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
        . 'attack_id VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
        . 'opposition_request_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL, '
        . 'expires_at BIGINT UNSIGNED NOT NULL, '
        . 'payload JSON NOT NULL, PRIMARY KEY (request_id), KEY attack_id_lookup (attack_id), '
        . 'KEY opposition_lookup (opposition_request_id,attack_id), '
        . 'KEY direct_expiry_lookup (expires_at,request_id)) ENGINE=InnoDB');
    applicationDirectAttackLedgerEnabled($connection, true);
    $connection->beginTransaction();
    try {
        $clock = domainClockRecord($connection, true);
        $records = applicationDomainRecords($connection, ['activity']);
        if (isset($records['activity'])) {
            $activity = applicationArchiveIndependentAttacks($connection, $records['activity']['payload'], applicationResourceReceiptClock());
            $prepared = prepareApplicationDomainUpsert('activity', $activity, $records['activity']);
            if ($prepared !== null) persistDomainChangesInTransaction($connection, [], $clock, [$prepared]);
        }
        $migration = $connection->prepare('INSERT IGNORE INTO schema_migrations (version,name,checksum) VALUES (27,:name,:checksum)');
        $migration->execute([':name'=>'authoritative_direct_attack_receipt_ledger',
            ':checksum'=>hash('sha256', 'application_direct_attack_receipts|targeted-replay|27')]);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $error;
    }
}
