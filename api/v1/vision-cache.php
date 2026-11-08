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
    if ($kind === 'glow') {
        if (!is_array($mask) || ($mask['version'] ?? null) !== 1
            || !is_int($mask['width'] ?? null) || !is_int($mask['height'] ?? null)
            || $mask['width'] < 32 || $mask['width'] > 512 || $mask['height'] < 32 || $mask['height'] > 512
            || array_diff(array_keys($mask), ['version', 'width', 'height', 'png']) !== []
            || !is_string($mask['png'] ?? null) || strlen($mask['png']) > 1400000) return false;
        if ($mask['png'] === '') return true;
        $png = base64_decode($mask['png'], true);
        if (!is_string($png) || !str_starts_with($png, "\x89PNG\r\n\x1a\n\0\0\0\rIHDR")
            || strlen($png) < 57 || !str_ends_with($png, "\0\0\0\0IEND\xae\x42\x60\x82")) return false;
        $dimensions = unpack('Nwidth/Nheight', substr($png, 16, 8));
        return $dimensions['width'] === $mask['width'] && $dimensions['height'] === $mask['height']
            && substr($png, 24, 5) === "\x08\x02\0\0\0";
    }
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

function applicationReadVisionCacheMask(string $file, string $kind, string $key, int $limit): ?array
{
    try {
        clearstatcache(true, $file);
        if (is_link($file) || !is_file($file) || @filesize($file) > $limit || @filemtime($file) < time() - 600) return null;
        $text = @file_get_contents($file, false, null, 0, $limit + 1);
        $envelope = is_string($text) && strlen($text) <= $limit ? json_decode($text, true) : null;
        if (!is_array($envelope) || ($envelope['version'] ?? null) !== 1
            || ($envelope['key'] ?? null) !== $key || !applicationVisionCacheMaskValid($envelope['mask'] ?? null, $kind)) return null;
        $body = json_encode($envelope['mask']);
        return is_string($body) && is_string($envelope['hash'] ?? null)
            && hash_equals($envelope['hash'], hash('sha256', $body)) ? $envelope['mask'] : null;
    } catch (Throwable) {
        return null;
    }
}

function applicationAcquireVisionCacheLease(string $file, string $key, int $maximumWaitMilliseconds): mixed
{
    $lease = null;
    try {
        $lockFile = $file . '.lock';
        $ownerFile = $file . '.owner';
        if (is_link($lockFile) || is_link($ownerFile)) return null;
        $lease = @fopen($lockFile, 'c+b');
        if (!is_resource($lease)) return null;
        @chmod($lockFile, 0600);
        $deadline = hrtime(true) + $maximumWaitMilliseconds * 1000000;
        while (!@flock($lease, LOCK_EX | LOCK_NB)) {
            // A slot collision concerns different geometry: never queue it
            // behind unrelated work. A stalled owner cannot hold this request
            // indefinitely either; a miss safely computes its own exact mask.
            // Windows locks prohibit reads of the locked file itself. Keep
            // its bounded geometry identity in a separate private sidecar.
            $owner = @file_get_contents($ownerFile, false, null, 0, 65);
            if ($owner !== $key || hrtime(true) >= $deadline) {
                fclose($lease);
                return null;
            }
            usleep(10000);
        }
        @file_put_contents($ownerFile, $key, LOCK_EX);
        @chmod($ownerFile, 0600);
        return $lease;
    } catch (Throwable) {
        if (is_resource($lease)) fclose($lease);
        return null;
    }
}

function applicationMemoizedVisionMask(string $kind, array $inputs, callable $compute): array
{
    static $memory = [], $memoryBytes = 0;
    if (!in_array($kind, ['base', 'render', 'glow'], true)) return $compute();
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
    $lease = null;
    $limit = match ($kind) {'base' => 65536, 'glow' => 1572864, default => 786432};
    // Fixed slots bound storage to at most 64 MiB plus small lease files. A slot collision is a
    // cache miss: the complete geometry hash must match before reuse.
    if ($directory !== null) {
        $slot = $kind === 'base' ? substr($key, 0, 2) : sprintf('%02x', hexdec(substr($key, 0, 2)) % ($kind === 'glow' ? 16 : 32));
        $file = $directory . DIRECTORY_SEPARATOR . $kind . '-' . $slot . '.json';
        $cached = applicationReadVisionCacheMask($file, $kind, $key, $limit);
        if ($cached !== null) return $remember($cached);
        $lease = applicationAcquireVisionCacheLease($file, $key, $kind === 'render' ? 1500 : 300);
    }
    try {
        // Another reader may have filled this exact geometry while we waited.
        // Never reuse a neighbouring slot's or an expired mask as a substitute.
        if ($file !== null) {
            $cached = applicationReadVisionCacheMask($file, $kind, $key, $limit);
            if ($cached !== null) return $remember($cached);
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
    } finally {
        if (is_resource($lease)) {
            flock($lease, LOCK_UN);
            fclose($lease);
        }
    }
}
