<?php
declare(strict_types=1);

// Test-process support only: no database, application session or remote service.
function gptFixturePhpCommand(array $arguments, ?string $osFamily = null, ?string $loadedIni = null, ?string $extensionDir = null): array {
    $command = [PHP_BINARY];
    if (($osFamily ?? PHP_OS_FAMILY) === 'Windows') {
        // The portable Windows runtime has no php.ini and includes PDO itself.
        array_push($command, '-n', '-d', 'extension_dir=' . ($extensionDir ?? ini_get('extension_dir')), '-d', 'extension=pdo_mysql', '-d', 'extension=mbstring');
    } else {
        // Linux packages load PDO/mysqlnd before pdo_mysql through php.ini/conf.d.
        // Disabling that configuration with -n breaks their shared dependencies.
        $loadedIni ??= php_ini_loaded_file() ?: '';
        if ($loadedIni !== '') array_push($command, '-c', $loadedIni);
    }
    return [...$command, ...$arguments];
}

function gptFixtureWorkerEnvironment(): array {
    $environment = [];
    foreach (['XAR_TEST_MYSQL_ALLOW_LOCAL', 'XAR_TEST_MYSQL_DSN', 'XAR_TEST_MYSQL_USER', 'XAR_TEST_MYSQL_PASSWORD', 'SYSTEMROOT', 'TEMP', 'TMP', 'PHPRC', 'PHP_INI_SCAN_DIR'] as $name) {
        $value = getenv($name); if ($value !== false) $environment[$name] = $value;
    }
    return $environment;
}

function gptFixtureStartWorker(array $command, string $workingDirectory, ?array $environment = null): array {
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workingDirectory, $environment);
    if (!is_resource($process)) throw new RuntimeException('Could not start fixture worker.');
    stream_set_timeout($pipes[1], 15);
    $firstLine = (string) fgets($pipes[1]);
    if (trim($firstLine) === 'READY') return [$process, $pipes];

    // Stop a child which may still be waiting at its barrier before draining
    // its pipes. Otherwise an early warning can leave both parent and child stuck.
    fclose($pipes[0]);
    $status = proc_get_status($process);
    if ($status['running']) proc_terminate($process);
    $output = [];
    foreach ([1, 2] as $index) {
        stream_set_timeout($pipes[$index], 2);
        $output[$index] = (string) stream_get_contents($pipes[$index], 65536);
        fclose($pipes[$index]);
    }
    $exit = proc_close($process);
    if ($exit === -1 && !$status['running']) $exit = $status['exitcode'];
    throw new RuntimeException('Fixture worker not ready (exit ' . $exit . '): stdout=' . trim($firstLine . $output[1]) . '; stderr=' . trim($output[2]));
}
