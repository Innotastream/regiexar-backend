<?php
declare(strict_types=1);

const XAR_CHARACTER_LORE_MAX_BYTES = 200000;
const XAR_ADA_LORE_IMPORT_KEY = 'ada-origin-20261005';

function normalizeOnlineCharacterLore(mixed $value): string
{
    $text = is_string($value) ? str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $value) : '';
    $text = preg_replace('/^\s+|\s+$/u', '', $text) ?? '';
    if (strlen($text) > XAR_CHARACTER_LORE_MAX_BYTES) throw new RuntimeException('character_lore_too_large');
    return $text;
}

// The UI is generic. This one-time import resolves the requested character
// against live records and the active owner; it never creates or reassigns one.
function planAdaOriginLoreImport(array $records, array $accounts, string $text): array
{
    $accountId = onlineUniqueAccountIdForAlias($accounts, 'ada');
    if ($accountId === '') return ['status' => 'owner_missing_or_ambiguous'];
    $matches = [];
    foreach ($records as $key => $record) {
        if (!str_starts_with((string) $key, 'character:')) continue;
        $character = $record['payload'] ?? null;
        if (!is_array($character)) continue;
        if (strtolower(trim((string) ($character['name'] ?? ''))) === 'ada') $matches[$key] = $character;
    }
    if (count($matches) !== 1) return ['status' => count($matches) === 0 ? 'character_missing' : 'character_ambiguous'];
    $key = array_key_first($matches);
    $before = $matches[$key];
    if (($before['ownerPlayerId'] ?? '') !== $accountId || $key !== 'character:' . ($before['id'] ?? '')) {
        return ['status' => 'owner_mismatch'];
    }
    $after = $before;
    $after['lore'] = normalizeOnlineCharacterLore($text);
    return ['status' => 'ready', 'key' => $key, 'before' => $before, 'after' => $after];
}

function ensureCharacterLoreImportTable(PDO $connection): void
{
    $connection->exec('CREATE TABLE IF NOT EXISTS character_lore_imports ('
        . 'import_key VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
        . 'character_id VARCHAR(180) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
        . 'source_domain_revision BIGINT UNSIGNED NOT NULL, committed_global_revision BIGINT UNSIGNED NOT NULL, '
        . 'before_payload JSON NOT NULL, lore_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
        . 'created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3), PRIMARY KEY (import_key)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function characterLoreImportStatus(PDO $connection): array
{
    $statement = $connection->prepare('SELECT lore_sha256 FROM character_lore_imports WHERE import_key = :key');
    $statement->execute([':key' => XAR_ADA_LORE_IMPORT_KEY]);
    $hash = $statement->fetchColumn();
    return ['import' => XAR_ADA_LORE_IMPORT_KEY, 'applied' => is_string($hash), 'backupPreserved' => is_string($hash)];
}

function importAdaOriginLoreOnRead(PDO $connection): array
{
    if (characterLoreImportStatus($connection)['applied']) return ['status' => 'already_applied'];
    $connection->beginTransaction();
    try {
        // All domain writers serialize through this same clock. Recheck the
        // import after acquiring it, so two requests cannot overwrite an edit.
        $clock = domainClockRecord($connection, true);
        if (characterLoreImportStatus($connection)['applied']) {
            $connection->commit();
            return ['status' => 'already_applied'];
        }
        $records = applicationDomainRecordsByPrefix($connection, 'character:');
        $statement = $connection->query('SELECT id, username, display_name FROM accounts WHERE revoked_at IS NULL ORDER BY id');
        $accounts = $statement === false ? [] : $statement->fetchAll();
        $text = require __DIR__ . '/data/ada-origin.php';
        $plan = planAdaOriginLoreImport($records, $accounts, $text);
        if ($plan['status'] !== 'ready') {
            $connection->commit();
            return ['status' => $plan['status']];
        }
        $after = $plan['after'];
        $after['_updatedAt'] = (int) floor(microtime(true) * 1000);
        $change = prepareApplicationDomainUpsert($plan['key'], $after, $records[$plan['key']]);
        if ($change === null) throw new RuntimeException('character_lore_import_not_prepared');
        $protectedBefore = $plan['before'];
        $protectedAfter = $change['payload'];
        unset($protectedBefore['lore'], $protectedBefore['_updatedAt'], $protectedAfter['lore'], $protectedAfter['_updatedAt']);
        if ($protectedBefore != $protectedAfter) throw new RuntimeException('character_lore_import_unrelated_change');
        $revision = persistDomainChangesInTransaction($connection, [], $clock, [$change]);
        $backup = $connection->prepare('INSERT INTO character_lore_imports '
            . '(import_key, character_id, source_domain_revision, committed_global_revision, before_payload, lore_sha256) '
            . 'VALUES (:key, :character, :source, :committed, :before, :hash)');
        $backup->execute([
            ':key' => XAR_ADA_LORE_IMPORT_KEY, ':character' => $plan['before']['id'],
            ':source' => $records[$plan['key']]['revision'], ':committed' => $revision,
            ':before' => json_encode($plan['before'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ':hash' => hash('sha256', $plan['after']['lore']),
        ]);
        $connection->commit();
        return ['status' => 'applied'];
    } catch (Throwable $error) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $error;
    }
}
