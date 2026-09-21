<?php
declare(strict_types=1);

function backendDiagnosticDirectory(): ?string {
    if (!function_exists('privateConfigPath')) return null;
    $configurationPath = privateConfigPath();
    if (!is_string($configurationPath) || $configurationPath === '') return null;
    $private = realpath(dirname($configurationPath));
    $public = realpath(dirname(__DIR__, 2));
    if ($private === false || $public === false || $private === $public || str_starts_with($private . DIRECTORY_SEPARATOR, $public . DIRECTORY_SEPARATOR)) return null;
    $directory = $private . DIRECTORY_SEPARATOR . 'diagnostics';
    $resolved = realpath($directory);
    if ($resolved !== false && ($resolved === $public || str_starts_with($resolved . DIRECTORY_SEPARATOR, $public . DIRECTORY_SEPARATOR))) return null;
    return $directory;
}

function recordBackendDiagnostic(array $event): void {
    static $busy = false;
    static $recorded = 0;
    if ($busy || $recorded >= 5) return;
    $busy = true; $recorded++;
    try {
        $clean = sanitizeApplicationDiagnostic([
            ...$event, 'id' => bin2hex(random_bytes(16)), 'at' => gmdate('c'),
            'route' => (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH),
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            'clientVersion' => (string) ($_SERVER['HTTP_X_XAR_CLIENT_VERSION'] ?? ''),
        ]);
        $encoded = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
        $directory = backendDiagnosticDirectory();
        if ($directory === null || (!is_dir($directory) && !@mkdir($directory, 0700, true))) {
            error_log('[xar-regie-diagnostic] private journal unavailable'); return;
        }
        $file = $directory . DIRECTORY_SEPARATOR . 'server-' . gmdate('Y-m-d') . '.ndjson';
        if (is_link($file)) return;
        $stream = @fopen($file, 'ab');
        if ($stream === false) { error_log('[xar-regie-diagnostic] private journal unwritable'); return; }
        @chmod($file, 0600);
        try {
            if (!flock($stream, LOCK_EX | LOCK_NB)) return;
            // Bound disk amplification without deleting historical diagnostics.
            if ((int) (fstat($stream)['size'] ?? 0) + strlen($encoded) <= 8 * 1024 * 1024) fwrite($stream, $encoded);
            else error_log('[xar-regie-diagnostic] daily journal capacity reached');
            flock($stream, LOCK_UN);
        } finally { fclose($stream); }
    } catch (Throwable) { error_log('[xar-regie-diagnostic] capture unavailable'); }
    finally { $busy = false; }
}

function backendDiagnosticRows(): array {
    $directory = backendDiagnosticDirectory(); if ($directory === null) return [];
    $rows = [];
    for ($day = 0; $day < 7 && count($rows) < 500; $day++) {
        $file = $directory . DIRECTORY_SEPARATOR . 'server-' . gmdate('Y-m-d', time() - $day * 86400) . '.ndjson';
        if (!is_file($file) || is_link($file)) continue;
        $stream = @fopen($file, 'rb'); if ($stream === false) continue;
        try {
            $size = (int) (fstat($stream)['size'] ?? 0); $offset = max(0, $size - 256 * 1024);
            if ($offset) { fseek($stream, $offset); fgets($stream); }
            $content = stream_get_contents($stream, 256 * 1024);
            foreach (array_reverse(explode("\n", (string) $content)) as $line) {
                if (count($rows) >= 500) break;
                $event = json_decode($line, true); if (!is_array($event)) continue;
                $rows[] = sanitizeApplicationDiagnostic($event);
            }
        } finally { fclose($stream); }
    }
    return $rows;
}

function installBackendDiagnostics(): void {
    set_error_handler(static function(int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) return false;
        recordBackendDiagnostic(['source' => 'php-warning', 'code' => 'php_' . $severity, 'file' => basename($file), 'line' => $line]);
        return true; // No raw PHP message, arguments, SQL or private paths in secondary logs.
    }, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED);
    register_shutdown_function(static function(): void {
        $error = error_get_last();
        if (!is_array($error) || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
        recordBackendDiagnostic(['source' => 'php-fatal', 'code' => 'php_' . $error['type'], 'file' => basename((string) $error['file']), 'line' => (int) $error['line']]);
    });
}
