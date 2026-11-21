<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/v1/diagnostic-collection.php';

function backendDiagnosticDirectory(): ?string { return $GLOBALS['fixtureDirectory'] ?? null; }
function collectionIdentity(int $number, string $mode = 'player'): array {
    return ['id' => 'private-account-fixture-' . $number, 'effective_mode' => $mode];
}
function collectionPresence(): array {
    $presence = [];
    for ($number = 1; $number <= 38; $number++) {
        $presence[] = ['accountId' => collectionIdentity($number)['id'], 'mode' => 'player'];
        if ($number === 1) $presence[] = $presence[0];
    }
    return $presence;
}
function collectionSample(): array {
    return ['v' => 1, 'bucketSeconds' => 60, 'windowSeconds' => 900, 'buckets' => [
        ['agoMinutes' => 0, 'rows' => [['command', 3, 1, 0, 1, 300, 200, 3, 205, 2, 85]]],
        ['agoMinutes' => 5, 'rows' => [['events', 2, 1, 1, 0, 50, 30, 0, 0, 0, 0]]],
    ]];
}
if (($argv[1] ?? '') === '--worker') {
    $GLOBALS['fixtureDirectory'] = $argv[2];
    if ($argv[3] === 'start') {
        echo json_encode(startDiagnosticCollection(collectionIdentity(0, 'gm'), 'collectionPresence'), JSON_THROW_ON_ERROR);
    } else {
        $body = ['id' => $argv[4], 'sample' => collectionSample()];
        echo json_encode(storeDiagnosticCollectionSample(collectionIdentity((int) $argv[5]), '4.3.0', $body), JSON_THROW_ON_ERROR);
    }
    exit;
}

function checkCollection(bool $passed, string $message): void {
    if (!$passed) throw new RuntimeException($message);
    $GLOBALS['checks'] = ($GLOBALS['checks'] ?? 0) + 1;
}
function checkCollectionRejection(callable $callback, int $expected, string $message): void {
    try { $callback(); }
    catch (RuntimeException $error) {
        checkCollection($error->getCode() === $expected, $message);
        return;
    }
    throw new RuntimeException($message);
}
function collectionWorkers(string $directory, string $operation, string $id = '', array $owners = []): array {
    $workers = [];
    try {
        foreach ($owners as $owner) {
            $process = proc_open([PHP_BINARY, __FILE__, '--worker', $directory, $operation, $id, (string) $owner],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Diagnostic fixture worker unavailable');
            fclose($pipes[0]);
            $workers[] = [$process, $pipes[1], $pipes[2]];
        }
        $results = [];
        foreach ($workers as [$process, $output, $stderr]) {
            $body = stream_get_contents($output);
            $warnings = stream_get_contents($stderr);
            fclose($output);
            fclose($stderr);
            checkCollection(proc_close($process) === 0 && $warnings === '', 'Concurrent process succeeds cleanly.');
            $results[] = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        }
        return $results;
    } finally {
        foreach ($workers as [$process, $output, $stderr]) {
            if (is_resource($output)) fclose($output);
            if (is_resource($stderr)) fclose($stderr);
            if (is_resource($process)) proc_close($process);
        }
    }
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'regie-collection-contract-' . bin2hex(random_bytes(12));
mkdir($directory, 0700);
$GLOBALS['fixtureDirectory'] = $directory;
$path = diagnosticCollectionPath();
try {
    checkCollection(diagnosticCollectionSignal(collectionIdentity(1)) === null, 'No active campaign sends a signal.');
    checkCollection(diagnosticCollectionRead($path, diagnosticCollectionNow()) === null, 'No campaign has no summary.');
    $starts = collectionWorkers($directory, 'start', '', range(1, 7));
    $first = $starts[0];
    checkCollection(diagnosticCollectionId($first['id']) && $first['expected'] === 32 && $first['received'] === 0, 'Only 32 distinct targets including the MJ are captured.');
    foreach ($starts as $start) checkCollection($start === $first, 'Seven concurrent starts share one campaign.');
    $id = $first['id'];
    checkCollection(diagnosticCollectionSignal(collectionIdentity(1)) === ['id' => $id, 'expiresAt' => $first['expiresAt']], 'Only a campaign reference appears on SSE.');
    checkCollection(diagnosticCollectionSignal(collectionIdentity(1, 'gm')) === null
        && diagnosticCollectionSignal(collectionIdentity(38)) === null, 'Different account mode and untargeted accounts receive no signal.');
    $body = ['id' => $id, 'sample' => collectionSample()];
    checkCollectionRejection(static fn() => storeDiagnosticCollectionSample(collectionIdentity(38), '4.3.0', $body), 403, 'An untargeted identity cannot submit.');
    checkCollectionRejection(static fn() => storeDiagnosticCollectionSample(collectionIdentity(1), 'unknown', $body), 400, 'API client version is required and structured.');
    checkCollectionRejection(static fn() => storeDiagnosticCollectionSample(collectionIdentity(1), '4.3.0', [...$body, 'name' => 'other']), 400, 'Unknown report fields are rejected.');
    $invalid = collectionSample();
    $invalid['buckets'][0]['rows'][0][3] = 2;
    checkCollection(!validDiagnosticCollectionSample($invalid), 'A timeout count exceeding failures is rejected.');
    $invalid = collectionSample();
    $invalid['buckets'][0]['rows'][] = $invalid['buckets'][0]['rows'][0];
    checkCollection(!validDiagnosticCollectionSample($invalid), 'Duplicate categories are rejected.');
    $invalid = collectionSample();
    $invalid['buckets'][] = $invalid['buckets'][0];
    checkCollection(!validDiagnosticCollectionSample($invalid), 'Duplicate minute buckets are rejected.');
    checkCollection(validDiagnosticCollectionSample(['v' => 1, 'bucketSeconds' => 60, 'windowSeconds' => 900, 'buckets' => []]), 'A connected but idle client can report an empty window.');
    $results = collectionWorkers($directory, 'sample', $id, [1, 1, 2, 3, 4, 5, 6, 7]);
    checkCollection(count(array_filter($results)) === 1, 'A concurrent duplicate is acknowledged exactly once as a replay.');
    $collection = diagnosticCollectionRead($path, diagnosticCollectionNow());
    checkCollection(diagnosticCollectionSummary($collection)['received'] === 7, 'All seven distinct sample uploads are preserved.');
    $reports = diagnosticCollectionReports();
    checkCollection(count($reports) === 7 && $reports[0]['role'] === 'player' && $reports[0]['clientVersion'] === '4.3.0', 'Public network sample rows preserve server-bound version and mode.');
    checkCollection(!str_contains(json_encode($reports), 'owner') && !str_contains(json_encode($reports), 'private-account-fixture')
        && !str_contains(json_encode($reports), $id), 'The share DTO exposes no account identities or file bookkeeping.');
    $filter = diagnosticCollectionOwner(collectionIdentity(2)['id'], 'player');
    checkCollection(count(diagnosticCollectionReports([$filter])) === 1 && diagnosticCollectionReports([]) === [], 'Account filtering isolates reports and fails closed.');
    checkCollection(diagnosticCollectionSignal(collectionIdentity(2)) !== null, 'A target retains the same SSE campaign ID while collection is open.');
    checkCollection(diagnosticCollectionRead($path, $first['expiresAt'] + 86400001) === null, 'Campaign results cannot be read after retention.');
    $collection['expiresAt'] = diagnosticCollectionNow() - 1000;
    unset($collection['reports']);
    file_put_contents($path, json_encode($collection, JSON_THROW_ON_ERROR) . "\n");
    checkCollection(diagnosticCollectionSignal(collectionIdentity(1)) === null, 'The SSE signal expires.');
    checkCollectionRejection(static fn() => storeDiagnosticCollectionSample(collectionIdentity(1), '4.3.0', $body), 410, 'An expired campaign refuses an unreceived upload.');
    checkCollectionRejection(static fn() => startDiagnosticCollection(collectionIdentity(0, 'gm'), 'collectionPresence'), 429, 'The global cooldown applies to repeated requests.');
    $collection['expiresAt'] -= 16000;
    file_put_contents($path, json_encode($collection, JSON_THROW_ON_ERROR) . "\n");
    $next = startDiagnosticCollection(collectionIdentity(0, 'gm'), 'collectionPresence');
    checkCollection($next['id'] !== $id && $next['received'] === 0 && count(glob($directory . DIRECTORY_SEPARATOR . '*')) === 2, 'The next campaign replaces the previous file without accumulating history.');
    checkCollectionRejection(static fn() => storeDiagnosticCollectionSample(collectionIdentity(1), '4.3.0', $body), 404, 'A report cannot enter a new campaign with the old ID.');
} finally {
    foreach ([$path, $path . '.lock', $path . '.next'] as $file) if (is_file($file)) unlink($file);
    rmdir($directory);
}
echo 'Collecte PHP : ' . $GLOBALS['checks'] . " contrôles réussis.\n";
