<?php
declare(strict_types=1);

// No SQL server or application code is needed for this process-launch contract.
require_once __DIR__ . '/fixtures/atelier-gpt-worker.php';

$checks = 0;
function workerCheck(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException($label);
    $GLOBALS['checks']++;
    echo 'PASS ' . $label . "\n";
}

$arguments = ['/synthetic/fixture.php', 'claim-worker', 'synthetic-schema', 'synthetic-lease'];
$linux = gptFixturePhpCommand($arguments, 'Linux', '/synthetic/php.ini', '/synthetic/extensions');
workerCheck($linux === [PHP_BINARY, '-c', '/synthetic/php.ini', ...$arguments], 'Linux inherits its configured ini without disabling shared PDO dependencies');
workerCheck(gptFixturePhpCommand($arguments, 'Linux', '') === [PHP_BINARY, ...$arguments], 'Linux uses default ini discovery when no primary ini is loaded');
$windows = gptFixturePhpCommand($arguments, 'Windows', '', 'C:/synthetic/ext');
workerCheck($windows === [PHP_BINARY, '-n', '-d', 'extension_dir=C:/synthetic/ext', '-d', 'extension=pdo_mysql', '-d', 'extension=mbstring', ...$arguments], 'portable Windows command retains its explicit extensions');

$previous = [];
try {
    foreach (['PHPRC' => '/synthetic/php-config', 'PHP_INI_SCAN_DIR' => '/synthetic/php-conf.d'] as $name => $value) {
        $previous[$name] = getenv($name);
        putenv($name . '=' . $value);
    }
    $environment = gptFixtureWorkerEnvironment();
    workerCheck($environment['PHPRC'] === '/synthetic/php-config' && $environment['PHP_INI_SCAN_DIR'] === '/synthetic/php-conf.d', 'child preserves both PHP configuration environment overrides');
} finally {
    foreach ($previous as $name => $value) putenv($value === false ? $name : $name . '=' . $value);
}

// These harmless children need no extensions, even with a custom Windows PHP.
[$process, $pipes] = gptFixtureStartWorker([PHP_BINARY, '-n', '-r', 'echo "READY\n"; flush(); if (trim((string) fgets(STDIN)) !== "GO") exit(2); echo "DONE\n";'], __DIR__);
fwrite($pipes[0], "GO\n"); fflush($pipes[0]); fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
workerCheck($exit === 0 && $stdout === "DONE\n" && $stderr === '', 'ready worker completes its barrier without changing the claim protocol');

$processCount = count(get_resources('process'));
$streamCount = count(get_resources('stream'));
$started = microtime(true);
try {
    gptFixtureStartWorker([PHP_BINARY, '-n', '-r', 'fwrite(STDERR, "fixture-stderr-marker\n"); fflush(STDERR); echo "fixture-stdout-marker\n"; flush(); sleep(60);'], __DIR__);
    throw new LogicException('Expected missing READY failure.');
} catch (RuntimeException $error) {
    workerCheck(str_contains($error->getMessage(), 'fixture-stdout-marker') && str_contains($error->getMessage(), 'fixture-stderr-marker'), 'missing READY reports both stdout and stderr');
}
workerCheck(count(get_resources('process')) === $processCount && count(get_resources('stream')) === $streamCount && microtime(true) - $started < 5, 'missing READY terminates and reaps the waiting worker and closes all pipes');

echo 'Atelier GPT worker contract: ' . $checks . " checks passed.\n";
