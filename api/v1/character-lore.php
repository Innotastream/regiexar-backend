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

// Resolve only exact declared character names against current authoritative
// records. Preserve the actual owner, including an unassigned MJ-only sheet.
function planCharacterLoreImport(array $records, array $accounts, array $names, string $text, ?string $ownerAlias = null): array
{
    $accountId = $ownerAlias === null ? '' : onlineUniqueAccountIdForAlias($accounts, $ownerAlias);
    if ($ownerAlias !== null && $accountId === '') return ['status' => 'owner_missing_or_ambiguous'];
    $names = array_map(static fn ($name) => strtolower(trim((string) $name)), $names);
    $matches = [];
    foreach ($records as $key => $record) {
        if (!str_starts_with((string) $key, 'character:')) continue;
        $character = $record['payload'] ?? null;
        if (!is_array($character)) continue;
        if (in_array(strtolower(trim((string) ($character['name'] ?? ''))), $names, true)) $matches[$key] = $character;
    }
    if (count($matches) !== 1) return ['status' => count($matches) === 0 ? 'character_missing' : 'character_ambiguous'];
    $key = array_key_first($matches);
    $before = $matches[$key];
    $actualOwner = (string) ($before['ownerPlayerId'] ?? '');
    if (($ownerAlias !== null && $actualOwner !== $accountId) || $key !== 'character:' . ($before['id'] ?? '')) {
        return ['status' => 'owner_mismatch'];
    }
    if ($actualOwner !== '' && count(array_filter($accounts, static fn ($account) => ($account['id'] ?? '') === $actualOwner)) !== 1) {
        return ['status' => 'owner_missing_or_ambiguous'];
    }
    $after = $before;
    $after['lore'] = normalizeOnlineCharacterLore($text);
    return ['status' => 'ready', 'key' => $key, 'before' => $before, 'after' => $after];
}

function planAdaOriginLoreImport(array $records, array $accounts, string $text): array
{
    return planCharacterLoreImport($records, $accounts, ['Ada'], $text, 'ada');
}

function siteCharacterLoreCatalog(): array
{
    static $catalog = null;
    if ($catalog !== null) return $catalog;
    $catalog = json_decode(file_get_contents(__DIR__ . '/lore-catalog/site-character-lores.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ($catalog['imports'] as $spec) {
        if (hash('sha256', $spec['text']) !== $spec['sha256'] || normalizeOnlineCharacterLore($spec['text']) !== $spec['text']) {
            throw new RuntimeException('character_lore_catalog_invalid');
        }
    }
    return $catalog;
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

function characterLoreImportStatus(PDO $connection, string $importKey = XAR_ADA_LORE_IMPORT_KEY): array
{
    $statement = $connection->prepare('SELECT lore_sha256 FROM character_lore_imports WHERE import_key = :key');
    $statement->execute([':key' => $importKey]);
    $hash = $statement->fetchColumn();
    return ['import' => $importKey, 'applied' => is_string($hash), 'backupPreserved' => is_string($hash)];
}

function importCharacterLoreOnce(PDO $connection, array $spec): array
{
    $importKey = (string) $spec['importKey'];
    if (preg_match('/^[a-z0-9-]{1,96}$/D', $importKey) !== 1) throw new RuntimeException('character_lore_import_key_invalid');
    if (characterLoreImportStatus($connection, $importKey)['applied']) return ['status' => 'already_applied'];
    $connection->beginTransaction();
    try {
        // All domain writers serialize through this same clock. Recheck the
        // import after acquiring it, so two requests cannot overwrite an edit.
        $clock = domainClockRecord($connection, true);
        if (characterLoreImportStatus($connection, $importKey)['applied']) {
            $connection->commit();
            return ['status' => 'already_applied'];
        }
        $records = applicationDomainRecordsByPrefix($connection, 'character:');
        $statement = $connection->query('SELECT id, username, display_name FROM accounts WHERE revoked_at IS NULL ORDER BY id');
        $accounts = $statement === false ? [] : $statement->fetchAll();
        $plan = planCharacterLoreImport($records, $accounts, $spec['names'], $spec['text'], $spec['ownerAlias'] ?? null);
        if ($plan['status'] !== 'ready') {
            $connection->commit();
            return ['status' => $plan['status']];
        }
        $after = $plan['after'];
        $after['_updatedAt'] = (int) floor(microtime(true) * 1000);
        $change = prepareApplicationDomainUpsert($plan['key'], $after, $records[$plan['key']]);
        if ($change === null) throw new RuntimeException('character_lore_import_not_prepared');
        $protectedBefore = $plan['before'];
        $protectedAfter = $after;
        unset($protectedBefore['lore'], $protectedBefore['_updatedAt'], $protectedAfter['lore'], $protectedAfter['_updatedAt']);
        if ($protectedBefore !== $protectedAfter) throw new RuntimeException('character_lore_import_unrelated_change');
        // The validator may add defaults to historical sheets. Compare the
        // actual payload to be persisted, preserving every original field and
        // its type; only lore and its timestamp belong to this import.
        $change['payload'] = $after;
        $revision = persistDomainChangesInTransaction($connection, [], $clock, [$change]);
        $backup = $connection->prepare('INSERT INTO character_lore_imports '
            . '(import_key, character_id, source_domain_revision, committed_global_revision, before_payload, lore_sha256) '
            . 'VALUES (:key, :character, :source, :committed, :before, :hash)');
        $backup->execute([
            ':key' => $importKey, ':character' => $plan['before']['id'],
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

function importAdaOriginLoreOnRead(PDO $connection): array
{
    return importCharacterLoreOnce($connection, ['importKey' => XAR_ADA_LORE_IMPORT_KEY, 'names' => ['Ada'],
        'ownerAlias' => 'ada', 'text' => require __DIR__ . '/lore-catalog/ada-origin.php']);
}

function importSiteCharacterLoresOnRead(PDO $connection): array
{
    $results = [];
    foreach (siteCharacterLoreCatalog()['imports'] as $spec) {
        $results[$spec['character']] = importCharacterLoreOnce($connection, $spec)['status'];
    }
    return $results;
}

function siteCharacterLoreImportStatus(PDO $connection, array $results = []): array
{
    $characters = []; $applied = 0;
    foreach (siteCharacterLoreCatalog()['imports'] as $spec) {
        $status = characterLoreImportStatus($connection, $spec['importKey']);
        if ($status['applied']) $applied++;
        $characters[] = ['character' => $spec['character'], 'applied' => $status['applied'],
            'backupPreserved' => $status['backupPreserved'],
            'status' => $results[$spec['character']] ?? ($status['applied'] ? 'already_applied' : 'pending')];
    }
    return ['expected' => count($characters), 'applied' => $applied, 'characters' => $characters];
}
