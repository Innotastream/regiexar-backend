<?php
declare(strict_types=1);

const XAR_DIAGNOSTIC_COLLECTION_TARGETS = 32;
const XAR_DIAGNOSTIC_COLLECTION_SECONDS = 60;
const XAR_DIAGNOSTIC_COLLECTION_COOLDOWN = 15;
const XAR_DIAGNOSTIC_COLLECTION_RETENTION = 86400;
const XAR_DIAGNOSTIC_COLLECTION_UPLOAD_BYTES = 8 * 1024;
const XAR_DIAGNOSTIC_COLLECTION_MANIFEST_BYTES = 4096;

function diagnosticCollectionNow(): int {
    return (int) floor(microtime(true) * 1000);
}

function diagnosticCollectionOwner(string $accountId, string $mode): string {
    return hash('sha256', "diagnostic-collection-v1\0" . $accountId . "\0" . $mode);
}

function diagnosticCollectionPath(): ?string {
    $directory = function_exists('backendDiagnosticDirectory') ? backendDiagnosticDirectory() : null;
    if ($directory === null) return null;
    return $directory . DIRECTORY_SEPARATOR . 'collection-v1.ndjson';
}

function diagnosticCollectionId(mixed $id): bool {
    return is_string($id) && preg_match('/\A[0-9a-f]{32}\z/', $id) === 1;
}

function diagnosticCollectionRows(mixed $rows): bool {
    if (!is_array($rows) || !array_is_list($rows) || count($rows) < 1 || count($rows) > 4) return false;
    $categories = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !array_is_list($row) || count($row) !== 11
            || !in_array($row[0], ['state', 'command', 'session', 'events'], true) || isset($categories[$row[0]])) return false;
        foreach (array_slice($row, 1) as $metric) if (!is_int($metric) || $metric < 0) return false;
        [$category, $count, $failed, $timedOut, $slow, $sum, $max, $headerCount, $headerSum, $serverCount, $serverSum] = $row;
        if ($count < 1 || $count > 3600 || $timedOut > $failed || $failed > $count || $slow > $count
            || $max > 180000 || $sum < $max || $sum > $count * $max
            || $headerCount > $count || $headerSum > min($sum, $headerCount * 180000)
            || $serverCount > $count || $serverSum > $serverCount * 180000) return false;
        $categories[$category] = true;
    }
    return true;
}

function validDiagnosticCollectionSample(mixed $sample): bool {
    if (!is_array($sample) || count($sample) !== 4 || ($sample['v'] ?? null) !== 1
        || ($sample['bucketSeconds'] ?? null) !== 60 || ($sample['windowSeconds'] ?? null) !== 900
        || !is_array($sample['buckets'] ?? null) || !array_is_list($sample['buckets']) || count($sample['buckets']) > 15) return false;
    $minutes = [];
    foreach ($sample['buckets'] as $bucket) {
        $minute = $bucket['agoMinutes'] ?? null;
        if (!is_array($bucket) || count($bucket) !== 2 || !is_int($minute) || $minute < 0 || $minute > 14
            || isset($minutes[$minute]) || !diagnosticCollectionRows($bucket['rows'] ?? null)) return false;
        $minutes[$minute] = true;
    }
    return true;
}

function diagnosticCollectionReadManifest($stream): ?array {
    $line = fgets($stream, XAR_DIAGNOSTIC_COLLECTION_MANIFEST_BYTES);
    if (!is_string($line) || !str_ends_with($line, "\n")) return null;
    $manifest = json_decode($line, true, 8);
    if (!diagnosticCollectionId($manifest['id'] ?? null) || !is_int($manifest['expiresAt'] ?? null)
        || !is_array($manifest['targets'] ?? null) || !array_is_list($manifest['targets'])
        || count($manifest['targets']) < 1 || count($manifest['targets']) > XAR_DIAGNOSTIC_COLLECTION_TARGETS) return null;
    return $manifest;
}

function diagnosticCollectionSignal(array $identity): ?array {
    $path = diagnosticCollectionPath();
    if ($path === null || is_link($path)) return null;
    $modified = @filemtime($path);
    if ($modified === false || $modified < time() - XAR_DIAGNOSTIC_COLLECTION_SECONDS - 1) return null;
    $stream = @fopen($path, 'rb');
    if ($stream === false) return null;
    try { $manifest = diagnosticCollectionReadManifest($stream); }
    finally { fclose($stream); }
    if ($manifest === null || $manifest['expiresAt'] <= diagnosticCollectionNow()) return null;
    $owner = diagnosticCollectionOwner((string) ($identity['id'] ?? ''), (string) ($identity['effective_mode'] ?? ''));
    return in_array($owner, $manifest['targets'], true) ? ['id' => $manifest['id'], 'expiresAt' => $manifest['expiresAt']] : null;
}

function diagnosticCollectionRead(?string $path, int $now): ?array {
    if ($path === null || is_link($path)) return null;
    $stream = @fopen($path, 'rb');
    if ($stream === false) return null;
    try {
        $manifest = diagnosticCollectionReadManifest($stream);
        if ($manifest === null || $manifest['expiresAt'] + XAR_DIAGNOSTIC_COLLECTION_RETENTION * 1000 <= $now) return null;
        $reports = [];
        for ($lineIndex = 0; $lineIndex < XAR_DIAGNOSTIC_COLLECTION_TARGETS; $lineIndex++) {
            $line = fgets($stream, XAR_DIAGNOSTIC_COLLECTION_UPLOAD_BYTES + 512);
            if (!is_string($line) || !str_ends_with($line, "\n")) break;
            $report = json_decode($line, true, 10);
            $owner = $report['owner'] ?? null;
            if (!is_string($owner) || !in_array($owner, $manifest['targets'], true) || isset($reports[$owner])
                || !is_int($report['receivedAt'] ?? null) || !in_array($report['role'] ?? null, ['gm', 'player'], true)
                || !is_string($report['clientVersion'] ?? null) || !preg_match('/\A[0-9]{1,3}(?:\.[0-9]{1,3}){2}\z/', $report['clientVersion'])
                || !validDiagnosticCollectionSample($report['sample'] ?? null)) continue;
            $reports[$owner] = $report;
        }
        return [...$manifest, 'reports' => $reports];
    } finally { fclose($stream); }
}

function diagnosticCollectionSummary(?array $collection): ?array {
    return $collection === null ? null : [
        'id' => $collection['id'], 'expected' => count($collection['targets']),
        'received' => count($collection['reports']), 'expiresAt' => $collection['expiresAt'],
    ];
}

function diagnosticCollectionReports(?array $owners = null, ?int $since = null, int $limit = XAR_DIAGNOSTIC_COLLECTION_TARGETS): array {
    if ($owners === []) return [];
    $collection = diagnosticCollectionRead(diagnosticCollectionPath(), diagnosticCollectionNow());
    if ($collection === null) return [];
    $output = [];
    foreach ($collection['reports'] as $owner => $report) {
        if (($owners !== null && !in_array($owner, $owners, true))
            || ($since !== null && $report['receivedAt'] < $since * 1000)) continue;
        $output[] = [
            'at' => gmdate('c', intdiv($report['receivedAt'], 1000)), 'role' => $report['role'],
            'clientVersion' => $report['clientVersion'], 'sample' => $report['sample'],
        ];
        if (count($output) >= $limit) break;
    }
    return $output;
}

function diagnosticCollectionLocked(callable $callback): mixed {
    $path = diagnosticCollectionPath();
    if ($path === null) throw new RuntimeException('diagnostic_collection_unavailable', 503);
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('diagnostic_collection_unavailable', 503);
    }
    $lockPath = $path . '.lock';
    if (is_link($lockPath) || is_link($path)) throw new RuntimeException('diagnostic_collection_unavailable', 503);
    $lock = @fopen($lockPath, 'c+b');
    if ($lock === false) throw new RuntimeException('diagnostic_collection_unavailable', 503);
    try {
        @chmod($lockPath, 0600);
        $deadline = hrtime(true) + 200000000;
        while (!@flock($lock, LOCK_EX | LOCK_NB)) {
            if (hrtime(true) >= $deadline) throw new RuntimeException('diagnostic_collection_busy', 503);
            usleep(5000);
        }
        return $callback($path, diagnosticCollectionNow());
    } finally { fclose($lock); }
}

function startDiagnosticCollection(array $identity, callable $loadPresence): array {
    $path = diagnosticCollectionPath();
    $now = diagnosticCollectionNow();
    $existing = diagnosticCollectionRead($path, $now);
    if ($existing !== null && $existing['expiresAt'] > $now) return diagnosticCollectionSummary($existing);
    if ($existing !== null && $existing['expiresAt'] + XAR_DIAGNOSTIC_COLLECTION_COOLDOWN * 1000 > $now) {
        throw new RuntimeException('diagnostic_collection_cooldown', 429);
    }
    $presence = $loadPresence();
    return diagnosticCollectionLocked(static function(string $path, int $now) use ($identity, $presence): array {
        $existing = diagnosticCollectionRead($path, $now);
        if ($existing !== null && $existing['expiresAt'] > $now) return diagnosticCollectionSummary($existing);
        if ($existing !== null && $existing['expiresAt'] + XAR_DIAGNOSTIC_COLLECTION_COOLDOWN * 1000 > $now) {
            throw new RuntimeException('diagnostic_collection_cooldown', 429);
        }
        $expected = [diagnosticCollectionOwner((string) $identity['id'], (string) $identity['effective_mode']) => true];
        foreach ($presence as $entry) {
            if (!is_string($entry['accountId'] ?? null) || $entry['accountId'] === ''
                || !in_array($entry['mode'] ?? null, ['gm', 'player'], true)) continue;
            $expected[diagnosticCollectionOwner($entry['accountId'], $entry['mode'])] = true;
            if (count($expected) === XAR_DIAGNOSTIC_COLLECTION_TARGETS) break;
        }
        $manifest = [
            'id' => bin2hex(random_bytes(16)), 'expiresAt' => $now + XAR_DIAGNOSTIC_COLLECTION_SECONDS * 1000,
            'targets' => array_keys($expected),
        ];
        $line = json_encode($manifest, JSON_THROW_ON_ERROR) . "\n";
        $temporary = $path . '.next';
        if (is_link($temporary) || @file_put_contents($temporary, $line) !== strlen($line)) {
            throw new RuntimeException('diagnostic_collection_unavailable', 503);
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $path)) throw new RuntimeException('diagnostic_collection_unavailable', 503);
        return diagnosticCollectionSummary([...$manifest, 'reports' => []]);
    });
}

function storeDiagnosticCollectionSample(array $identity, string $version, array $body): bool {
    $id = $body['id'] ?? null;
    $sample = $body['sample'] ?? null;
    $role = $identity['effective_mode'] ?? null;
    if (!in_array($role, ['gm', 'player'], true) || count($body) !== 2 || !diagnosticCollectionId($id)
        || !validDiagnosticCollectionSample($sample) || !preg_match('/\A[0-9]{1,3}(?:\.[0-9]{1,3}){2}\z/', $version)) {
        throw new RuntimeException('diagnostic_collection_sample_invalid', 400);
    }
    $owner = diagnosticCollectionOwner((string) $identity['id'], $role);
    return diagnosticCollectionLocked(static function(string $path, int $now) use ($id, $owner, $role, $version, $sample): bool {
        $collection = diagnosticCollectionRead($path, $now);
        if ($collection === null || $collection['id'] !== $id) throw new RuntimeException('diagnostic_collection_missing', 404);
        if (!in_array($owner, $collection['targets'], true)) throw new RuntimeException('diagnostic_collection_not_expected', 403);
        if (isset($collection['reports'][$owner])) return true;
        if ($collection['expiresAt'] <= $now) throw new RuntimeException('diagnostic_collection_expired', 410);
        $line = json_encode([
            'owner' => $owner, 'receivedAt' => $now, 'role' => $role, 'clientVersion' => $version, 'sample' => $sample,
        ], JSON_THROW_ON_ERROR) . "\n";
        $stream = @fopen($path, 'c+b');
        if ($stream === false) throw new RuntimeException('diagnostic_collection_unavailable', 503);
        try {
            // Recover a partial final append before adding a complete report.
            $position = 0;
            for ($index = 0; $index <= XAR_DIAGNOSTIC_COLLECTION_TARGETS; $index++) {
                $previous = fgets($stream, XAR_DIAGNOSTIC_COLLECTION_UPLOAD_BYTES + 512);
                if (!is_string($previous) || !str_ends_with($previous, "\n")) break;
                if ($index === XAR_DIAGNOSTIC_COLLECTION_TARGETS) {
                    throw new RuntimeException('diagnostic_collection_unavailable', 503);
                }
                $position += strlen($previous);
            }
            if (@fseek($stream, $position) !== 0 || !@ftruncate($stream, $position)) {
                throw new RuntimeException('diagnostic_collection_unavailable', 503);
            }
            if (@fwrite($stream, $line) !== strlen($line) || !@fflush($stream)) {
                @ftruncate($stream, $position);
                throw new RuntimeException('diagnostic_collection_unavailable', 503);
            }
        } finally { fclose($stream); }
        // Keep the SSE fast path anchored to the campaign start, not the latest upload.
        @touch($path, intdiv($collection['expiresAt'], 1000) - XAR_DIAGNOSTIC_COLLECTION_SECONDS);
        return false;
    });
}
