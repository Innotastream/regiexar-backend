<?php

declare(strict_types=1);

// Cache geometry only. Authorization and the public/private projections are
// recomputed for every request and never stored here.
function applicationVisionCacheWithin(string $path, string $directory): bool
{
    $path = rtrim(str_replace('\\', '/', $path), '/');
    $directory = rtrim(str_replace('\\', '/', $directory), '/');
    if (PHP_OS_FAMILY === 'Windows') {
        $path = strtolower($path);
        $directory = strtolower($directory);
    }
    return $path === $directory || str_starts_with($path, $directory . '/');
}

function applicationVisionCacheDirectory(): ?string
{
    static $resolved = false, $directory = null;
    if ($resolved) return $directory;
    $resolved = true;
    if (!function_exists('privateConfigPath')) return null;
    try {
        $documentRoot = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $config = privateConfigPath();
        $public = $documentRoot === '' ? false : realpath($documentRoot);
        $private = is_string($config) && $config !== '' ? realpath(dirname($config)) : false;
        if ($private === false || $public === false || applicationVisionCacheWithin($private, $public)) return null;
        $wanted = $private . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'vision-v1';
        if (!is_dir($wanted) && !@mkdir($wanted, 0700, true) && !is_dir($wanted)) return null;
        $real = realpath($wanted);
        if ($real === false || !applicationVisionCacheWithin($real, $private)
            || applicationVisionCacheWithin($real, $public) || !is_writable($real)) return null;
        return $directory = $real;
    } catch (Throwable) {
        return null;
    }
}

function applicationVisionCacheMaskValid(mixed $mask, string $kind): bool
{
    if (!is_array($mask) || !is_bool($mask['enabled'] ?? null) || applicationWallMaskBytes($mask) === null) return false;
    $allowed = ['version', 'enabled', 'width', 'height', 'mask'];
    if ($kind === 'render') array_push($allowed, 'opacity', 'occludedMask', 'tokenMask');
    if (array_diff(array_keys($mask), $allowed) !== []) return false;
    $bits = (int) ceil($mask['width'] * $mask['height'] / 8);
    foreach (['opacity' => $mask['width'] * $mask['height'], 'occludedMask' => $bits, 'tokenMask' => $bits] as $key => $length) {
        if (!array_key_exists($key, $mask)) continue;
        $value = $mask[$key];
        if (!is_string($value) || preg_match('/^[A-Za-z0-9_-]*$/D', $value) !== 1) return false;
        if ($value === '' && $key !== 'opacity') continue;
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if (!is_string($decoded) || strlen($decoded) !== $length) return false;
    }
    return true;
}

function applicationMemoizedVisionMask(string $kind, array $inputs, callable $compute): array
{
    static $memory = [], $memoryBytes = 0;
    if (!in_array($kind, ['base', 'render'], true)) return $compute();
    $encoded = json_encode([
        'engine' => 1,
        'backend' => defined('XAR_BACKEND_VERSION') ? XAR_BACKEND_VERSION : 'fixture',
        'kind' => $kind,
        'inputs' => $inputs,
    ], JSON_PRESERVE_ZERO_FRACTION);
    if (!is_string($encoded)) return $compute();
    $key = hash('sha256', $encoded);
    unset($encoded);
    if (isset($memory[$key])) return $memory[$key]['mask'];
    $remember = static function (array $mask) use (&$memory, &$memoryBytes, $key): array {
        $size = array_sum(array_map(static fn ($value): int => is_string($value) ? strlen($value) : 16, $mask));
        while ($memory !== [] && (count($memory) >= 256 || $memoryBytes + $size > 8 * 1024 * 1024)) {
            $removed = array_shift($memory);
            $memoryBytes -= $removed['bytes'];
        }
        if ($size <= 8 * 1024 * 1024) {
            $memory[$key] = ['mask' => $mask, 'bytes' => $size];
            $memoryBytes += $size;
        }
        return $mask;
    };
    $directory = applicationVisionCacheDirectory();
    $file = null;
    $limit = $kind === 'base' ? 65536 : 786432;
    // Fixed slots bound storage to at most 40 MiB. A slot collision is a
    // cache miss: the complete geometry hash must match before reuse.
    if ($directory !== null) {
        $slot = $kind === 'base' ? substr($key, 0, 2) : sprintf('%02x', hexdec(substr($key, 0, 2)) % 32);
        $file = $directory . DIRECTORY_SEPARATOR . $kind . '-' . $slot . '.json';
        try {
            clearstatcache(true, $file);
            if (!is_link($file) && is_file($file) && @filesize($file) <= $limit && @filemtime($file) >= time() - 600) {
                $text = @file_get_contents($file, false, null, 0, $limit + 1);
                $envelope = is_string($text) && strlen($text) <= $limit ? json_decode($text, true) : null;
                if (is_array($envelope) && ($envelope['version'] ?? null) === 1
                    && ($envelope['key'] ?? null) === $key && applicationVisionCacheMaskValid($envelope['mask'] ?? null, $kind)) {
                    $body = json_encode($envelope['mask']);
                    if (is_string($body) && is_string($envelope['hash'] ?? null)
                        && hash_equals($envelope['hash'], hash('sha256', $body))) return $remember($envelope['mask']);
                }
            }
        } catch (Throwable) {
            // An absent, refused or damaged cache cannot break the game.
        }
    }
    $mask = $compute();
    if (!applicationVisionCacheMaskValid($mask, $kind)) return $mask;
    $remember($mask);
    if ($file !== null) {
        $temporary = null;
        try {
            $body = json_encode($mask, JSON_THROW_ON_ERROR);
            $payload = json_encode(['version' => 1, 'key' => $key, 'hash' => hash('sha256', $body), 'mask' => $mask], JSON_THROW_ON_ERROR);
            if (strlen($payload) <= $limit) {
                $temporary = @tempnam($directory, '.vision-');
                if (is_string($temporary) && realpath(dirname($temporary)) === $directory) {
                    @chmod($temporary, 0600);
                    if (@file_put_contents($temporary, $payload, LOCK_EX) === strlen($payload)
                        && @rename($temporary, $file)) $temporary = null;
                }
            }
        } catch (Throwable) {
            // Recompute safely on the next request if storage is unavailable.
        } finally {
            if (is_string($temporary) && is_file($temporary)) @unlink($temporary);
        }
    }
    return $mask;
}
