<?php

declare(strict_types=1);

const XAR_API_ORIGIN = 'https://regie-xar-tsaroth.fr';
const XAR_API_HOST = 'regie-xar-tsaroth.fr';

function sendJson(int $status, array $payload, bool $headOnly = false): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Content-Security-Policy: default-src \'none\'; frame-ancestors \'none\'');
    if (!$headOnly) {
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    exit;
}

function requestIsSecure(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off') {
        return true;
    }
    $forwarded = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    return $forwarded === 'https';
}

function requestHost(): string
{
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    return preg_replace('/:\d+$/', '', $host) ?? '';
}

function privateConfigPath(): ?string
{
    $override = trim((string) getenv('XAR_REGIE_CONFIG'));
    if ($override !== '') {
        return $override;
    }

    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($documentRoot === false) {
        return null;
    }

    $candidate = dirname($documentRoot) . DIRECTORY_SEPARATOR . 'regie-private' . DIRECTORY_SEPARATOR . 'config.php';
    $documentPrefix = rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (str_starts_with($candidate, $documentPrefix)) {
        return null;
    }
    return $candidate;
}

function privateConfig(): ?array
{
    $path = privateConfigPath();
    if ($path !== null && is_file($path) && is_readable($path)) {
        $configuration = require $path;
        if (is_array($configuration)) {
            return $configuration;
        }
    }

    $dsn = trim((string) getenv('XAR_REGIE_DB_DSN'));
    $username = trim((string) getenv('XAR_REGIE_DB_USER'));
    $password = (string) getenv('XAR_REGIE_DB_PASSWORD');
    if ($dsn === '' && $username === '' && $password === '') {
        return null;
    }

    return [
        'database' => [
            'dsn' => $dsn,
            'username' => $username,
            'password' => $password,
        ],
    ];
}

function databaseConnection(array $configuration): PDO
{
    $database = $configuration['database'] ?? null;
    if (!is_array($database)) {
        throw new RuntimeException('configuration_required');
    }

    $dsn = trim((string) ($database['dsn'] ?? ''));
    $username = trim((string) ($database['username'] ?? ''));
    $password = (string) ($database['password'] ?? '');
    if ($dsn === '' || $username === '' || $password === '' || $password === 'REMPLACER_LOCALEMENT') {
        throw new RuntimeException('configuration_required');
    }

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$headOnly = $method === 'HEAD';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    sendJson(405, ['status' => 'method_not_allowed']);
}

if (!requestIsSecure()) {
    sendJson(426, ['status' => 'https_required'], $headOnly);
}

if (requestHost() !== XAR_API_HOST) {
    sendJson(421, ['status' => 'host_rejected'], $headOnly);
}

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$route = rtrim($path, '/');
if ($route === '/api/v1') {
    sendJson(200, [
        'status' => 'ok',
        'service' => 'xar-tsaroth-regie',
        'api' => 'v1',
    ], $headOnly);
}

if ($route !== '/api/v1/health') {
    sendJson(404, ['status' => 'not_found'], $headOnly);
}

$configuration = privateConfig();
if ($configuration === null) {
    sendJson(503, ['status' => 'unavailable', 'code' => 'configuration_required'], $headOnly);
}

try {
    $connection = databaseConnection($configuration);
    $statement = $connection->query('SELECT 1');
    if ($statement === false || (int) $statement->fetchColumn() !== 1) {
        throw new RuntimeException('database_unreachable');
    }
    sendJson(200, [
        'status' => 'ok',
        'service' => 'xar-tsaroth-regie',
        'api' => 'v1',
    ], $headOnly);
} catch (Throwable $error) {
    error_log('[xar-regie-api] database health check failed: ' . get_class($error));
    $code = $error instanceof RuntimeException && $error->getMessage() === 'configuration_required'
        ? 'configuration_required'
        : 'database_unreachable';
    sendJson(503, ['status' => 'unavailable', 'code' => $code], $headOnly);
}
