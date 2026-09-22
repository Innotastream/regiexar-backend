<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/v1/diagnostics.php';
require_once __DIR__ . '/../api/v1/runtime-diagnostics.php';
$testDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'regie-private-diagnostic-test-' . bin2hex(random_bytes(12));
mkdir($testDirectory, 0700);
$GLOBALS['diagnosticTestConfigPath'] = $testDirectory . DIRECTORY_SEPARATOR . 'config.php';
function privateConfigPath(): ?string { return $GLOBALS['diagnosticTestConfigPath']; }
$checks = 0;
function checkRuntimeDiagnostic(bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message); $GLOBALS['checks']++;
}
try {
    $_SERVER['REQUEST_URI'] = '/api/v1/image-studio/messages/' . str_repeat('a', 24) . '?token=CANARY-QUERY';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_X_XAR_CLIENT_VERSION'] = '3.3.6';
    recordBackendDiagnostic(['source' => 'php-http', 'status' => 503, 'code' => 'test_failure', 'password' => 'CANARY-PASSWORD', 'message' => 'password=CANARY-MESSAGE', 'stack' => '/home/private/CANARY-FILE.php']);
    $rows = backendDiagnosticRows();
    checkRuntimeDiagnostic(count($rows) === 1, 'The private fallback survives an unavailable database.');
    checkRuntimeDiagnostic($rows[0]['status'] === 503 && $rows[0]['code'] === 'test_failure', 'Technical failure context is retained.');
    checkRuntimeDiagnostic(!str_contains(json_encode($rows), 'CANARY'), 'Neither credentials, private paths nor URL capabilities are persisted.');
    $directory = backendDiagnosticDirectory();
    checkRuntimeDiagnostic($directory === $testDirectory . DIRECTORY_SEPARATOR . 'diagnostics', 'Storage remains outside the public root.');
    $file = $directory . DIRECTORY_SEPARATOR . 'server-' . gmdate('Y-m-d') . '.ndjson';
    if (DIRECTORY_SEPARATOR === '/') checkRuntimeDiagnostic((fileperms($file) & 0777) === 0600, 'Diagnostic file permissions are private.');
    file_put_contents($file, "malformed diagnostic\n", FILE_APPEND);
    checkRuntimeDiagnostic(count(backendDiagnosticRows()) === 1, 'A malformed line does not hide the valid records.');
    installBackendDiagnostics();
    trigger_error('CANARY-WARNING-CONTENT', E_USER_WARNING);
    restore_error_handler();
    $rows = backendDiagnosticRows();
    checkRuntimeDiagnostic(count($rows) === 2 && $rows[0]['source'] === 'php-warning', 'Handled PHP warnings enter the private journal.');
    checkRuntimeDiagnostic(!str_contains(file_get_contents($file), 'CANARY'), 'No raw PHP warning or request content is stored.');
    $GLOBALS['diagnosticTestConfigPath'] = __DIR__ . '/../config.php';
    checkRuntimeDiagnostic(backendDiagnosticDirectory() === null, 'A private path inside the public backend is rejected.');
} finally {
    // Only this test's explicitly created disposable directory is removed.
    foreach (glob($testDirectory . '/diagnostics/server-*.ndjson') ?: [] as $file) unlink($file);
    if (is_dir($testDirectory . '/diagnostics')) rmdir($testDirectory . '/diagnostics');
    rmdir($testDirectory);
}
echo "Diagnostics runtime PHP : $checks contrôles réussis.\n";
