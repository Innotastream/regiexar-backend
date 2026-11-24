<?php
declare(strict_types=1);

function applicationResourceReceiptLedgerEnabled(PDO $connection, bool $enable = false): bool
{
    static $connections;
    $connections ??= new WeakMap();
    if ($enable) $connections[$connection] = true;
    return isset($connections[$connection]);
}

function applicationCreateResourceReceiptLedger(PDO $connection): void
{
    $connection->exec(
        'CREATE TABLE IF NOT EXISTS application_resource_receipts ('
        . 'request_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
        . 'action_id VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
        . 'removed_guard_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL, '
        . 'expires_at BIGINT UNSIGNED NOT NULL, payload MEDIUMTEXT NOT NULL, '
        . 'PRIMARY KEY (request_id), KEY receipt_action (action_id, expires_at), '
        . 'KEY receipt_guard (removed_guard_id, expires_at), KEY receipt_expiry (expires_at, request_id)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function applicationResourceReceiptClock(): int
{
    return (int) floor(microtime(true) * 1000);
}

function applicationResourceReceiptKey(mixed $value, int $limit): bool
{
    return is_string($value) && strlen($value) <= $limit
        && preg_match('/^[A-Za-z0-9_-]+$/D', $value) === 1;
}

function applicationResourceReceiptRemovedGuard(array $receipt): ?string
{
    $result = $receipt['result'] ?? null;
    if (($receipt['kind'] ?? '') !== 'combat-effect' || ($result['kind'] ?? '') !== 'guard'
        || ($result['decision'] ?? '') !== 'remove' || !empty($result['actionUndone'])) return null;
    $id = $result['effectId'] ?? null;
    return applicationResourceReceiptKey($id, 128) ? $id : null;
}

function applicationWriteResourceReceiptLedger(PDO $connection, array $receipts): void
{
    if ($receipts === []) return;
    if (!$connection->inTransaction()) throw new LogicException('Resource receipts require an open transaction.');
    foreach (array_chunk($receipts, 32) as $batch) {
        $values = [];
        foreach ($batch as $receipt) {
            $values[] = $receipt['requestId'];
            $values[] = applicationResourceReceiptKey($receipt['actionId'] ?? null, 120) ? $receipt['actionId'] : '';
            $values[] = applicationResourceReceiptRemovedGuard($receipt);
            $values[] = (int) $receipt['expiresAt'];
            $values[] = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        $insert = $connection->prepare(
            'INSERT INTO application_resource_receipts (request_id,action_id,removed_guard_id,expires_at,payload) VALUES '
            . implode(',', array_fill(0, count($batch), '(?,?,?,?,?)'))
            . ' ON DUPLICATE KEY UPDATE action_id=VALUES(action_id), removed_guard_id=VALUES(removed_guard_id), '
            . 'expires_at=VALUES(expires_at), payload=VALUES(payload)'
        );
        $insert->execute($values);
    }
}

function applicationArchiveFinalResourceReceipts(PDO $connection, array $activity, int $now): array
{
    if (!applicationResourceReceiptLedgerEnabled($connection)) return $activity;
    $receipts = $activity['resourceReceipts'] ?? [];
    if ($receipts === []) return $activity;
    $waiting = array_fill_keys(array_filter([
        ...array_column($activity['pendingAbilityCasts'] ?? [], 'requestId'),
        ...array_column($activity['pendingAttacks'] ?? [], 'requestId'),
    ], 'is_string'), true);
    $archived = [];
    $inline = [];
    foreach ($receipts as $receipt) {
        $requestId = $receipt['requestId'] ?? null;
        if (!applicationResourceReceiptKey($requestId, 80)) {
            // An old malformed entry cannot match a valid client command.
            continue;
        }
        if (isset($waiting[$requestId])) {
            $receipt['expiresAt'] = 9007199254740991;
            $inline[] = $receipt;
        } elseif ((int) ($receipt['expiresAt'] ?? 0) > $now) {
            if ($receipt['expiresAt'] >= 9007199254740991) {
                $receipt['expiresAt'] = $now + XAR_RESOURCE_RECEIPT_TTL_MILLISECONDS;
            }
            $archived[] = $receipt;
        }
    }
    applicationWriteResourceReceiptLedger($connection, $archived);
    $activity['resourceReceipts'] = $inline;
    return $activity;
}

function applicationReadResourceReceiptLedger(PDO $connection, string $requestId, int $now): ?array
{
    if (!applicationResourceReceiptLedgerEnabled($connection) || !applicationResourceReceiptKey($requestId, 80)) return null;
    $statement = $connection->prepare(
        'SELECT payload FROM application_resource_receipts WHERE request_id=? AND expires_at>?'
    );
    $statement->execute([$requestId, $now]);
    $payload = $statement->fetchColumn();
    return $payload === false ? null : json_decode((string) $payload, true, 512, JSON_THROW_ON_ERROR);
}

function applicationFindResourceReceipt(PDO $connection, array $receipts, string $requestId, int $now): ?array
{
    foreach ($receipts as $receipt) {
        if (($receipt['requestId'] ?? '') === $requestId && (int) ($receipt['expiresAt'] ?? 0) > $now) return $receipt;
    }
    return applicationReadResourceReceiptLedger($connection, $requestId, $now);
}

function applicationReadUndoResourceReceipts(PDO $connection, array $action, int $now): array
{
    if (!applicationResourceReceiptLedgerEnabled($connection)) return [];
    $requestIds = [];
    foreach ($action['aliases'] ?? [] as $alias) {
        if (is_string($alias) && str_starts_with($alias, 'request:')) $requestIds[] = substr($alias, 8);
    }
    $result = [];
    foreach (['request_id' => [$requestIds, 80], 'action_id' => [$action['actionIds'] ?? [], 120]] as $field => [$ids, $limit]) {
        $valid = array_values(array_unique(array_filter($ids, static fn($id): bool => applicationResourceReceiptKey($id, $limit))));
        foreach (array_chunk($valid, 128) as $batch) {
            $list = implode(',', array_fill(0, count($batch), '?'));
            $query = $connection->prepare('SELECT payload FROM application_resource_receipts WHERE '
                . $field . ' IN (' . $list . ') AND expires_at>?');
            $query->execute([...$batch, $now]);
            foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $payload) {
                $receipt = json_decode((string) $payload, true, 512, JSON_THROW_ON_ERROR);
                $result[$receipt['requestId']] = $receipt;
            }
        }
    }
    return $result;
}

function applicationProtectPrivateResourceReceipts(PDO $connection, mixed $payload, array $previous): mixed
{
    if (!applicationResourceReceiptLedgerEnabled($connection) || !is_array($payload)) return $payload;
    $payload['resourceReceipts'] = $previous['resourceReceipts'] ?? [];
    $guards = $payload['nextAttackGuards'] ?? null;
    if (!is_array($guards) || count($guards) > 60) return $payload;
    $ids = array_values(array_unique(array_filter(array_column($guards, 'id'),
        static fn($id): bool => applicationResourceReceiptKey($id, 128))));
    if ($ids === []) return $payload;
    $list = implode(',', array_fill(0, count($ids), '?'));
    $query = $connection->prepare(
        'SELECT removed_guard_id FROM application_resource_receipts WHERE removed_guard_id IN ('
        . $list . ') AND expires_at>?'
    );
    $query->execute([...$ids, applicationResourceReceiptClock()]);
    $discard = array_fill_keys($query->fetchAll(PDO::FETCH_COLUMN), true);
    $payload['nextAttackGuards'] = array_values(array_filter($guards, static fn($guard): bool =>
        !is_string($guard['id'] ?? null) || !isset($discard[$guard['id']])));
    return $payload;
}

function applicationPruneResourceReceiptLedger(PDO $connection, int $now): void
{
    if (!applicationResourceReceiptLedgerEnabled($connection) || $connection->inTransaction()) return;
    $delete = $connection->prepare('DELETE FROM application_resource_receipts WHERE expires_at<=? '
        . 'ORDER BY expires_at,request_id LIMIT ' . XAR_DOMAIN_MAINTENANCE_BATCH_SIZE);
    $delete->execute([$now]);
}

function migrateApplicationResourceReceiptLedger(PDO $connection): void
{
    applicationCreateResourceReceiptLedger($connection);
    applicationResourceReceiptLedgerEnabled($connection, true);
    $connection->beginTransaction();
    try {
        $clock = domainClockRecord($connection, true);
        $records = applicationDomainRecords($connection, ['activity']);
        if (isset($records['activity'])) {
            $activity = applicationArchiveFinalResourceReceipts($connection, $records['activity']['payload'], applicationResourceReceiptClock());
            $prepared = prepareApplicationDomainUpsert('activity', $activity, $records['activity']);
            if ($prepared !== null) persistDomainChangesInTransaction($connection, [], $clock, [$prepared]);
        }
        $migration = $connection->prepare('INSERT IGNORE INTO schema_migrations (version,name,checksum) VALUES (26,:name,:checksum)');
        $migration->execute([':name' => 'authoritative_resource_receipt_ledger',
            ':checksum' => hash('sha256', 'application_resource_receipts|indexed-replay|26')]);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $error;
    }
}
