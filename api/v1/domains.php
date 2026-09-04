<?php

declare(strict_types=1);

const XAR_DOMAIN_SCHEMA_VERSION = 1;
const XAR_SESSION_SCHEMA_VERSION = 13;
const XAR_DOMAIN_MAXIMUM_BYTES = 8 * 1024 * 1024;
const XAR_DOMAIN_MAXIMUM_CHANGES = 4096;
const XAR_DOMAIN_MAINTENANCE_BATCH_SIZE = 500;
const XAR_FOG_MASK_VERSION = 1;
const XAR_FOG_RASTER_MINIMUM = 32;
const XAR_FOG_RASTER_MAXIMUM = 512;
const XAR_FOG_MASK_MAXIMUM_LENGTH = 44000;
const XAR_WALL_MASK_VERSION = 1;
const XAR_VISION_SETTINGS_VERSION = 1;
const XAR_VISION_DEFAULT_DISTANCE = 8;
const XAR_VISION_MAXIMUM_DISTANCE = 40;

function validApplicationDomainKey(string $key): bool
{
    if (in_array($key, ['table', 'scene-index', 'roster', 'activity', 'library', 'audio', 'forge', 'detached-combat'], true)) {
        return true;
    }
    return preg_match('/^(?:scene|map|initiative|presentation|token-index):[A-Za-z0-9_-]{1,80}$/D', $key) === 1
        || preg_match('/^character:[A-Za-z0-9_-]{1,180}$/D', $key) === 1
        || preg_match('/^token:[A-Za-z0-9_-]{1,80}:[A-Za-z0-9_-]{1,80}$/D', $key) === 1;
}

function validApplicationDomainPrefix(string $prefix): bool
{
    return in_array($prefix, [
        'scene:',
        'map:',
        'initiative:',
        'presentation:',
        'token-index:',
        'character:',
        'token:',
    ], true);
}

function applicationDomainArrayIsList(array $value): bool
{
    $expected = 0;
    foreach ($value as $key => $_entry) {
        if ($key !== $expected) {
            return false;
        }
        $expected++;
    }
    return true;
}

function validApplicationDomainIdentifierList(mixed $value, int $maximumCount, int $maximumLength = 80): bool
{
    if (!is_array($value) || !applicationDomainArrayIsList($value) || count($value) > $maximumCount) {
        return false;
    }
    $seen = [];
    foreach ($value as $identifier) {
        if (!is_string($identifier)
            || strlen($identifier) < 1
            || strlen($identifier) > $maximumLength
            || preg_match('/^[A-Za-z0-9_-]+$/D', $identifier) !== 1
            || isset($seen[$identifier])) {
            return false;
        }
        $seen[$identifier] = true;
    }
    return true;
}

function validApplicationDomainList(mixed $value, int $maximumCount): bool
{
    return is_array($value) && applicationDomainArrayIsList($value) && count($value) <= $maximumCount;
}

function validApplicationDomainObjectList(mixed $value, int $maximumCount): bool
{
    if (!validApplicationDomainList($value, $maximumCount)) {
        return false;
    }
    foreach ($value as $entry) {
        if (!is_array($entry)) {
            return false;
        }
    }
    return true;
}

function validApplicationDomainMap(mixed $value, int $maximumCount, int $maximumKeyLength = 180): bool
{
    if (!is_array($value) || count($value) > $maximumCount) {
        return false;
    }
    foreach (array_keys($value) as $key) {
        if (!is_string($key) || strlen($key) < 1 || strlen($key) > $maximumKeyLength
            || preg_match('/^[A-Za-z0-9_-]+$/D', $key) !== 1) {
            return false;
        }
    }
    return true;
}

function validApplicationMovementOverrides(mixed $value): bool
{
    if (!validApplicationDomainMap($value, 2000, 80)) {
        return false;
    }
    foreach ($value as $allowed) {
        if ($allowed !== true) {
            return false;
        }
    }
    return true;
}

function validApplicationDomainStringList(mixed $value, int $maximumCount, int $maximumLength): bool
{
    if (!validApplicationDomainList($value, $maximumCount)) {
        return false;
    }
    foreach ($value as $entry) {
        if (!is_string($entry) || strlen($entry) > $maximumLength || preg_match('/[\x00]/', $entry) === 1) {
            return false;
        }
    }
    return true;
}

function validApplicationDomainText(mixed $value, int $maximumLength, bool $allowEmpty = true): bool
{
    return is_string($value)
        && ($allowEmpty || $value !== '')
        && strlen($value) <= $maximumLength
        && preg_match('/[\x00]/', $value) !== 1;
}

function validApplicationDomainIdentifier(mixed $value, int $maximumLength = 180, bool $nullable = false): bool
{
    if ($nullable && $value === null) {
        return true;
    }
    return is_string($value)
        && strlen($value) >= 1
        && strlen($value) <= $maximumLength
        && preg_match('/^[A-Za-z0-9_-]+$/D', $value) === 1;
}

function validApplicationDomainNumber(mixed $value, float $minimum = -1000000000, float $maximum = 1000000000): bool
{
    return (is_int($value) || is_float($value))
        && is_finite((float) $value)
        && (float) $value >= $minimum
        && (float) $value <= $maximum;
}

function applicationFogMaskBytes(mixed $value): ?string
{
    if (!is_array($value)
        || ($value['version'] ?? null) !== XAR_FOG_MASK_VERSION
        || !is_bool($value['enabled'] ?? null)
        || !is_int($value['width'] ?? null)
        || !is_int($value['height'] ?? null)
        || $value['width'] < XAR_FOG_RASTER_MINIMUM
        || $value['width'] > XAR_FOG_RASTER_MAXIMUM
        || $value['height'] < XAR_FOG_RASTER_MINIMUM
        || $value['height'] > XAR_FOG_RASTER_MAXIMUM
        || !is_string($value['mask'] ?? null)
        || strlen($value['mask']) > XAR_FOG_MASK_MAXIMUM_LENGTH
        || preg_match('/^[A-Za-z0-9_-]*$/D', $value['mask']) !== 1) {
        return null;
    }
    $expectedLength = (int) ceil(($value['width'] * $value['height']) / 8);
    if ($value['mask'] === '') {
        return str_repeat("\0", $expectedLength);
    }
    $base64 = strtr($value['mask'], '-_', '+/');
    $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
    $decoded = base64_decode($base64, true);
    return is_string($decoded) && strlen($decoded) === $expectedLength ? $decoded : null;
}

function validApplicationFogState(mixed $value): bool
{
    return applicationFogMaskBytes($value) !== null;
}

function validApplicationMapFogState(array $map): bool
{
    if (array_key_exists('fog', $map) && !validApplicationFogState($map['fog'])) {
        return false;
    }
    if (array_key_exists('walls', $map) && !validApplicationWallState($map['walls'])) {
        return false;
    }
    if (array_key_exists('vision', $map) && !validApplicationVisionSettings($map['vision'])) {
        return false;
    }
    if (!array_key_exists('layers', $map)) {
        return true;
    }
    if (!is_array($map['layers']) || count($map['layers']) > 3) {
        return false;
    }
    foreach ($map['layers'] as $layerId => $layer) {
        if (!in_array($layerId, ['basement', 'ground', 'upper'], true)
            || !is_array($layer)
            || (array_key_exists('fog', $layer) && !validApplicationFogState($layer['fog']))
            || (array_key_exists('walls', $layer) && !validApplicationWallState($layer['walls']))
            || (array_key_exists('vision', $layer) && !validApplicationVisionSettings($layer['vision']))) {
            return false;
        }
    }
    return true;
}

function applicationActiveMapFogState(array $map): mixed
{
    $activeLayerId = in_array($map['activeLayerId'] ?? null, ['basement', 'ground', 'upper'], true)
        ? $map['activeLayerId']
        : 'ground';
    $activeLayer = is_array($map['layers'][$activeLayerId] ?? null) ? $map['layers'][$activeLayerId] : [];
    return array_key_exists('fog', $activeLayer) ? $activeLayer['fog'] : ($map['fog'] ?? null);
}

function applicationFogCoversPoint(mixed $value, mixed $xPercent, mixed $yPercent): bool
{
    if (!is_array($value) || ($value['enabled'] ?? false) !== true || ($value['mask'] ?? '') === '') {
        return false;
    }
    $bytes = applicationFogMaskBytes($value);
    if ($bytes === null) {
        return false;
    }
    $x = max(0.0, min(100.0, is_numeric($xPercent) ? (float) $xPercent : 0.0));
    $y = max(0.0, min(100.0, is_numeric($yPercent) ? (float) $yPercent : 0.0));
    $column = (int) round(($x / 100) * ($value['width'] - 1));
    $row = (int) round(($y / 100) * ($value['height'] - 1));
    $index = $row * $value['width'] + $column;
    return (ord($bytes[intdiv($index, 8)]) & (1 << ($index % 8))) !== 0;
}

function applicationWallMaskBytes(mixed $value): ?string
{
    if (!is_array($value)
        || ($value['version'] ?? null) !== XAR_WALL_MASK_VERSION
        || !is_int($value['width'] ?? null)
        || !is_int($value['height'] ?? null)
        || $value['width'] < XAR_FOG_RASTER_MINIMUM
        || $value['width'] > XAR_FOG_RASTER_MAXIMUM
        || $value['height'] < XAR_FOG_RASTER_MINIMUM
        || $value['height'] > XAR_FOG_RASTER_MAXIMUM
        || !is_string($value['mask'] ?? null)
        || strlen($value['mask']) > XAR_FOG_MASK_MAXIMUM_LENGTH
        || preg_match('/^[A-Za-z0-9_-]*$/D', $value['mask']) !== 1) {
        return null;
    }
    $expectedLength = (int) ceil(($value['width'] * $value['height']) / 8);
    if ($value['mask'] === '') {
        return str_repeat("\0", $expectedLength);
    }
    $base64 = strtr($value['mask'], '-_', '+/');
    $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
    $decoded = base64_decode($base64, true);
    return is_string($decoded) && strlen($decoded) === $expectedLength ? $decoded : null;
}

function validApplicationWallState(mixed $value): bool
{
    return applicationWallMaskBytes($value) !== null;
}

function normalizeApplicationVisionSettings(mixed $value): array
{
    $distance = is_numeric(is_array($value) ? ($value['distance'] ?? null) : null)
        ? (int) round((float) $value['distance'])
        : XAR_VISION_DEFAULT_DISTANCE;
    $isolatedPlayerIds = [];
    foreach (is_array($value) && is_array($value['isolatedPlayerIds'] ?? null) ? $value['isolatedPlayerIds'] : [] as $playerId) {
        $playerId = is_string($playerId) ? trim($playerId) : '';
        if ($playerId !== '' && strlen($playerId) <= 128
            && preg_match('/^[A-Za-z0-9_-]+$/D', $playerId) === 1
            && !in_array($playerId, $isolatedPlayerIds, true)) {
            $isolatedPlayerIds[] = $playerId;
            if (count($isolatedPlayerIds) >= 200) {
                break;
            }
        }
    }
    return [
        'version' => XAR_VISION_SETTINGS_VERSION,
        'enabled' => is_array($value) && ($value['enabled'] ?? false) === true,
        'distance' => max(1, min(XAR_VISION_MAXIMUM_DISTANCE, $distance)),
        'shared' => is_array($value) && ($value['shared'] ?? false) === true,
        'isolatedPlayerIds' => $isolatedPlayerIds,
    ];
}

function validApplicationVisionSettings(mixed $value): bool
{
    return is_array($value)
        && ($value['version'] ?? null) === XAR_VISION_SETTINGS_VERSION
        && is_bool($value['enabled'] ?? null)
        && is_int($value['distance'] ?? null)
        && $value['distance'] >= 1
        && $value['distance'] <= XAR_VISION_MAXIMUM_DISTANCE
        && (!array_key_exists('shared', $value) || is_bool($value['shared']))
        && (!array_key_exists('isolatedPlayerIds', $value)
            || validApplicationDomainIdentifierList($value['isolatedPlayerIds'], 200, 128));
}

function validApplicationMapEffectPresets(mixed $value): bool
{
    if (!validApplicationDomainObjectList($value, 40)) {
        return false;
    }
    $seen = [];
    foreach ($value as $preset) {
        $id = $preset['id'] ?? null;
        if (($preset['version'] ?? null) !== 1
            || !validApplicationDomainIdentifier($id, 120)
            || isset($seen[$id])
            || !validApplicationDomainText($preset['name'] ?? null, 120, false)
            || !is_int($preset['naturalWidth'] ?? null)
            || !is_int($preset['naturalHeight'] ?? null)
            || $preset['naturalWidth'] < 1 || $preset['naturalWidth'] > 8192
            || $preset['naturalHeight'] < 1 || $preset['naturalHeight'] > 8192
            || !validApplicationFogState($preset['fog'] ?? null)
            || !validApplicationWallState($preset['walls'] ?? null)
            || !validApplicationVisionSettings($preset['vision'] ?? null)
            || !is_bool($preset['automatic'] ?? null)
            || !validApplicationDomainText($preset['createdAt'] ?? null, 80, false)
            || !validApplicationDomainText($preset['updatedAt'] ?? null, 80, false)
            || (array_key_exists('sourceSceneId', $preset)
                && !validApplicationDomainIdentifier($preset['sourceSceneId'], 80, true))
            || (array_key_exists('sourceLayerId', $preset)
                && !validApplicationDomainIdentifier($preset['sourceLayerId'], 80, true))) {
            return false;
        }
        $seen[$id] = true;
    }
    return true;
}

function applicationRasterDimensions(mixed $naturalWidth, mixed $naturalHeight): array
{
    $width = is_numeric($naturalWidth) && (float) $naturalWidth > 0 ? (float) $naturalWidth : 1600.0;
    $height = is_numeric($naturalHeight) && (float) $naturalHeight > 0 ? (float) $naturalHeight : 900.0;
    if ($width >= $height) {
        return [
            'width' => XAR_FOG_RASTER_MAXIMUM,
            'height' => max(XAR_FOG_RASTER_MINIMUM, min(XAR_FOG_RASTER_MAXIMUM, (int) round(XAR_FOG_RASTER_MAXIMUM * $height / $width))),
        ];
    }
    return [
        'width' => max(XAR_FOG_RASTER_MINIMUM, min(XAR_FOG_RASTER_MAXIMUM, (int) round(XAR_FOG_RASTER_MAXIMUM * $width / $height))),
        'height' => XAR_FOG_RASTER_MAXIMUM,
    ];
}

function emptyApplicationWallState(mixed $naturalWidth = 1600, mixed $naturalHeight = 900): array
{
    $dimensions = applicationRasterDimensions($naturalWidth, $naturalHeight);
    return ['version' => XAR_WALL_MASK_VERSION, 'width' => $dimensions['width'], 'height' => $dimensions['height'], 'mask' => ''];
}

function applicationActiveMapOcclusionState(array $map): array
{
    $activeLayerId = in_array($map['activeLayerId'] ?? null, ['basement', 'ground', 'upper'], true)
        ? $map['activeLayerId']
        : 'ground';
    $activeLayer = is_array($map['layers'][$activeLayerId] ?? null) ? $map['layers'][$activeLayerId] : [];
    $naturalWidth = $activeLayer['naturalWidth'] ?? ($map['naturalWidth'] ?? 1600);
    $naturalHeight = $activeLayer['naturalHeight'] ?? ($map['naturalHeight'] ?? 900);
    $walls = $activeLayer['walls'] ?? ($map['walls'] ?? null);
    if (!validApplicationWallState($walls)) {
        $walls = emptyApplicationWallState($naturalWidth, $naturalHeight);
    }
    return [
        'walls' => $walls,
        'vision' => normalizeApplicationVisionSettings($activeLayer['vision'] ?? ($map['vision'] ?? null)),
        'naturalWidth' => is_numeric($naturalWidth) && (float) $naturalWidth > 0 ? (float) $naturalWidth : 1600.0,
        'naturalHeight' => is_numeric($naturalHeight) && (float) $naturalHeight > 0 ? (float) $naturalHeight : 900.0,
    ];
}

function applicationMaskBit(string $bytes, int $index): bool
{
    $byteIndex = intdiv($index, 8);
    return isset($bytes[$byteIndex]) && (ord($bytes[$byteIndex]) & (1 << ($index % 8))) !== 0;
}

function applicationSetMaskBit(string &$bytes, int $index, bool $value): void
{
    $byteIndex = intdiv($index, 8);
    if (!isset($bytes[$byteIndex])) {
        return;
    }
    $bit = 1 << ($index % 8);
    $current = ord($bytes[$byteIndex]);
    $bytes[$byteIndex] = chr($value ? ($current | $bit) : ($current & ~$bit));
}

function applicationWallCollisionAt(
    string $bytes,
    array $walls,
    float $centerX,
    float $centerY,
    float $radiusX,
    float $radiusY
): bool {
    $effectiveRadiusX = max(0.75, $radiusX);
    $effectiveRadiusY = max(0.75, $radiusY);
    $minimumX = max(0, (int) floor($centerX - $effectiveRadiusX - 1));
    $maximumX = min((int) $walls['width'] - 1, (int) ceil($centerX + $effectiveRadiusX + 1));
    $minimumY = max(0, (int) floor($centerY - $effectiveRadiusY - 1));
    $maximumY = min((int) $walls['height'] - 1, (int) ceil($centerY + $effectiveRadiusY + 1));
    for ($y = $minimumY; $y <= $maximumY; $y += 1) {
        for ($x = $minimumX; $x <= $maximumX; $x += 1) {
            if (!applicationMaskBit($bytes, $y * (int) $walls['width'] + $x)) {
                continue;
            }
            $normalizedX = ($x - $centerX) / ($effectiveRadiusX + 0.5);
            $normalizedY = ($y - $centerY) / ($effectiveRadiusY + 0.5);
            if ($normalizedX * $normalizedX + $normalizedY * $normalizedY <= 1.0) {
                return true;
            }
        }
    }
    return false;
}

function applicationResolveWallCollision(
    mixed $rawWalls,
    array $start,
    array $desired,
    mixed $tokenSize,
    mixed $naturalWidth,
    mixed $naturalHeight,
    bool $allowEscape = true
): array {
    if (!validApplicationWallState($rawWalls) || (string) ($rawWalls['mask'] ?? '') === '') {
        return ['x' => (float) $desired['x'], 'y' => (float) $desired['y'], 'blocked' => false];
    }
    $bytes = applicationWallMaskBytes($rawWalls);
    if ($bytes === null) {
        return ['x' => (float) $desired['x'], 'y' => (float) $desired['y'], 'blocked' => false];
    }
    $width = (int) $rawWalls['width'];
    $height = (int) $rawWalls['height'];
    $safeNaturalWidth = is_numeric($naturalWidth) && (float) $naturalWidth > 0 ? (float) $naturalWidth : 1600.0;
    $safeNaturalHeight = is_numeric($naturalHeight) && (float) $naturalHeight > 0 ? (float) $naturalHeight : 900.0;
    $diameter = max(10.0, min(220.0, is_numeric($tokenSize) ? (float) $tokenSize : 50.0));
    $startX = max(0.0, min(100.0, (float) ($start['x'] ?? 0)));
    $startY = max(0.0, min(100.0, (float) ($start['y'] ?? 0)));
    $desiredX = max(0.0, min(100.0, (float) ($desired['x'] ?? 0)));
    $desiredY = max(0.0, min(100.0, (float) ($desired['y'] ?? 0)));
    $startRasterX = $startX / 100 * max(1, $width - 1);
    $startRasterY = $startY / 100 * max(1, $height - 1);
    $desiredRasterX = $desiredX / 100 * max(1, $width - 1);
    $desiredRasterY = $desiredY / 100 * max(1, $height - 1);
    $radiusX = $diameter / 2 / $safeNaturalWidth * max(1, $width - 1);
    $radiusY = $diameter / 2 / $safeNaturalHeight * max(1, $height - 1);
    $collides = static function (float $progress) use (
        $bytes, $rawWalls, $startRasterX, $startRasterY, $desiredRasterX, $desiredRasterY, $radiusX, $radiusY
    ): bool {
        return applicationWallCollisionAt(
            $bytes,
            $rawWalls,
            $startRasterX + ($desiredRasterX - $startRasterX) * $progress,
            $startRasterY + ($desiredRasterY - $startRasterY) * $progress,
            $radiusX,
            $radiusY
        );
    };
    $pointAt = static function (float $progress, ?int $precision = null) use (
        $startX, $startY, $desiredX, $desiredY
    ): array {
        $point = [
            'x' => $startX + ($desiredX - $startX) * $progress,
            'y' => $startY + ($desiredY - $startY) * $progress,
        ];
        if ($precision === null) {
            return $point;
        }
        return ['x' => round($point['x'], $precision), 'y' => round($point['y'], $precision)];
    };
    $collidesPoint = static function (array $point) use (
        $bytes, $rawWalls, $width, $height, $radiusX, $radiusY
    ): bool {
        return applicationWallCollisionAt(
            $bytes,
            $rawWalls,
            (float) $point['x'] / 100 * max(1, $width - 1),
            (float) $point['y'] / 100 * max(1, $height - 1),
            $radiusX,
            $radiusY
        );
    };
    $steps = max(1, min(2048, (int) ceil(max(abs($desiredRasterX - $startRasterX), abs($desiredRasterY - $startRasterY)) * 2)));
    $startsInsideWall = $collides(0.0);
    $rasterDistance = hypot($desiredRasterX - $startRasterX, $desiredRasterY - $startRasterY);
    $shallowRecoveryProgress = $startsInsideWall && !$allowEscape && $rasterDistance > 0
        ? min(1.0, 0.02 / $rasterDistance)
        : 0.0;
    // Compatibilité 0.14.3 : seules les pénétrations numériques superficielles
    // créées par l'ancien arrondi peuvent ressortir dans le sens immédiatement
    // libre. Un token réellement couvert reste sous contrôle exclusif du MJ.
    $recoversShallowContact = $shallowRecoveryProgress > 0.0 && !$collides($shallowRecoveryProgress);
    if ($startsInsideWall && !$allowEscape && !$recoversShallowContact) {
        return ['x' => $startX, 'y' => $startY, 'blocked' => true];
    }
    $escapedInitialWall = !$startsInsideWall || $recoversShallowContact;
    $safeProgress = $recoversShallowContact ? $shallowRecoveryProgress : 0.0;
    for ($index = 1; $index <= $steps; $index += 1) {
        $progress = $index / $steps;
        $blocked = $collides($progress);
        if (!$escapedInitialWall) {
            if (!$blocked) {
                $escapedInitialWall = true;
                $safeProgress = $progress;
            }
            continue;
        }
        if (!$blocked) {
            $safeProgress = $progress;
            continue;
        }
        $low = $safeProgress;
        $high = $progress;
        for ($iteration = 0; $iteration < 10; $iteration += 1) {
            $middle = ($low + $high) / 2;
            if ($collides($middle)) {
                $high = $middle;
            } else {
                $low = $middle;
            }
        }
        // La valeur enregistrée doit rester hors du mur après son passage en
        // JSON. Un arrondi à trois décimales pouvait transformer le dernier
        // point sûr en position piégée et interdire ensuite tout recul Joueur.
        $safePoint = $pointAt($low, 6);
        if ($collidesPoint($safePoint)) {
            $safePoint = $pointAt($safeProgress, 6);
        }
        if ($collidesPoint($safePoint)) {
            $safePoint = $pointAt($safeProgress);
        }
        if ($collidesPoint($safePoint) && !$startsInsideWall) {
            $safePoint = ['x' => $startX, 'y' => $startY];
        }
        return [
            'x' => $safePoint['x'],
            'y' => $safePoint['y'],
            'blocked' => true,
        ];
    }
    if ($startsInsideWall && !$escapedInitialWall) {
        return ['x' => $startX, 'y' => $startY, 'blocked' => true];
    }
    return ['x' => $desiredX, 'y' => $desiredY, 'blocked' => false];
}

function applicationComputeVisionMask(array $occlusion, array $origins, mixed $gridSize): array
{
    $walls = $occlusion['walls'];
    $vision = normalizeApplicationVisionSettings($occlusion['vision'] ?? null);
    $width = (int) $walls['width'];
    $height = (int) $walls['height'];
    if (!$vision['enabled']) {
        return ['version' => XAR_WALL_MASK_VERSION, 'enabled' => false, 'width' => $width, 'height' => $height, 'mask' => ''];
    }
    $wallBytes = applicationWallMaskBytes($walls) ?? str_repeat("\0", (int) ceil(($width * $height) / 8));
    $hiddenBytes = str_repeat("\xff", (int) ceil(($width * $height) / 8));
    $naturalWidth = max(1.0, (float) ($occlusion['naturalWidth'] ?? 1600));
    $naturalHeight = max(1.0, (float) ($occlusion['naturalHeight'] ?? 900));
    $safeGridSize = max(12.0, min(240.0, is_numeric($gridSize) ? (float) $gridSize : 50.0));
    $radiusNatural = $vision['distance'] * $safeGridSize;
    $radiusX = max(1.0, $radiusNatural / $naturalWidth * max(1, $width - 1));
    $radiusY = max(1.0, $radiusNatural / $naturalHeight * max(1, $height - 1));
    $clearCell = static function (string &$bytes, float $x, float $y) use ($width, $height): void {
        $column = (int) round($x);
        $row = (int) round($y);
        if ($column < 0 || $column >= $width || $row < 0 || $row >= $height) {
            return;
        }
        applicationSetMaskBit($bytes, $row * $width + $column, false);
    };
    foreach (array_slice($origins, 0, 40) as $origin) {
        if (!is_array($origin) || !is_numeric($origin['x'] ?? null) || !is_numeric($origin['y'] ?? null)) {
            continue;
        }
        $centerX = max(0.0, min(100.0, (float) $origin['x'])) / 100 * max(1, $width - 1);
        $centerY = max(0.0, min(100.0, (float) $origin['y'])) / 100 * max(1, $height - 1);
        $clearCell($hiddenBytes, $centerX, $centerY);
        $originColumn = (int) round($centerX);
        $originRow = (int) round($centerY);
        if (applicationMaskBit($wallBytes, $originRow * $width + $originColumn)) {
            continue;
        }
        if (($walls['mask'] ?? '') === '') {
            $minimumX = max(0, (int) floor($centerX - $radiusX));
            $maximumX = min($width - 1, (int) ceil($centerX + $radiusX));
            $minimumY = max(0, (int) floor($centerY - $radiusY));
            $maximumY = min($height - 1, (int) ceil($centerY + $radiusY));
            for ($y = $minimumY; $y <= $maximumY; $y += 1) {
                for ($x = $minimumX; $x <= $maximumX; $x += 1) {
                    $deltaX = ($x - $centerX) / $radiusX;
                    $deltaY = ($y - $centerY) / $radiusY;
                    if ($deltaX * $deltaX + $deltaY * $deltaY <= 1.0) {
                        $clearCell($hiddenBytes, (float) $x, (float) $y);
                    }
                }
            }
            continue;
        }
        $maximumRadius = min(max($radiusX, $radiusY), sqrt($width * $width + $height * $height));
        $rayCount = max(96, min(2048, (int) ceil(M_PI * 2 * $maximumRadius * 1.8)));
        for ($ray = 0; $ray < $rayCount; $ray += 1) {
            $angle = $ray / $rayCount * M_PI * 2;
            $cosine = cos($angle);
            $sine = sin($angle);
            $fullDeltaX = $cosine * $radiusX;
            $fullDeltaY = $sine * $radiusY;
            $visibleProgress = 1.0;
            if ($fullDeltaX > 0) {
                $visibleProgress = min($visibleProgress, ($width - 1 - $centerX) / $fullDeltaX);
            } elseif ($fullDeltaX < 0) {
                $visibleProgress = min($visibleProgress, $centerX / -$fullDeltaX);
            }
            if ($fullDeltaY > 0) {
                $visibleProgress = min($visibleProgress, ($height - 1 - $centerY) / $fullDeltaY);
            } elseif ($fullDeltaY < 0) {
                $visibleProgress = min($visibleProgress, $centerY / -$fullDeltaY);
            }
            $visibleProgress = max(0.0, min(1.0, $visibleProgress));
            $deltaX = $fullDeltaX * $visibleProgress;
            $deltaY = $fullDeltaY * $visibleProgress;
            $raySteps = max(1, (int) ceil(max(abs($deltaX), abs($deltaY)) * 2));
            $previousIndex = -1;
            for ($step = 0; $step <= $raySteps; $step += 1) {
                $progress = $step / $raySteps;
                $column = (int) round($centerX + $deltaX * $progress);
                $row = (int) round($centerY + $deltaY * $progress);
                if ($column < 0 || $column >= $width || $row < 0 || $row >= $height) {
                    break;
                }
                $cellIndex = $row * $width + $column;
                if ($cellIndex === $previousIndex) {
                    continue;
                }
                $previousIndex = $cellIndex;
                applicationSetMaskBit($hiddenBytes, $cellIndex, false);
                if ($step > 0 && applicationMaskBit($wallBytes, $cellIndex)) {
                    break;
                }
            }
        }
    }
    return [
        'version' => XAR_WALL_MASK_VERSION,
        'enabled' => true,
        'width' => $width,
        'height' => $height,
        'mask' => rtrim(strtr(base64_encode($hiddenBytes), '+/', '-_'), '='),
    ];
}

function applicationVisionCoversPoint(mixed $value, mixed $xPercent, mixed $yPercent): bool
{
    if (!is_array($value) || ($value['enabled'] ?? false) !== true) {
        return false;
    }
    $bytes = applicationFogMaskBytes([
        'version' => XAR_FOG_MASK_VERSION,
        'enabled' => true,
        'width' => $value['width'] ?? null,
        'height' => $value['height'] ?? null,
        'mask' => $value['mask'] ?? null,
    ]);
    if ($bytes === null) {
        return false;
    }
    $x = max(0.0, min(100.0, is_numeric($xPercent) ? (float) $xPercent : 0.0));
    $y = max(0.0, min(100.0, is_numeric($yPercent) ? (float) $yPercent : 0.0));
    $column = (int) round(($x / 100) * ($value['width'] - 1));
    $row = (int) round(($y / 100) * ($value['height'] - 1));
    return applicationMaskBit($bytes, $row * (int) $value['width'] + $column);
}

function validApplicationTokenStats(mixed $value): bool
{
    if (!validApplicationDomainObjectList($value, 20)) {
        return false;
    }
    foreach ($value as $entry) {
        if ((array_key_exists('id', $entry) && !validApplicationDomainIdentifier($entry['id'], 120))
            || (array_key_exists('label', $entry) && !validApplicationDomainText($entry['label'], 40))
            || (array_key_exists('value', $entry) && !validApplicationDomainText($entry['value'], 80))) {
            return false;
        }
    }
    return true;
}

function validApplicationAbilityFormula(mixed $value): bool
{
    if (!is_string($value)) {
        return false;
    }
    $formula = strtolower(str_replace(' ', '', $value));
    if ($formula === '' || strlen($formula) > 100
        || preg_match('/^[+-]?((\d*)d\d+|\d+)([+-]((\d*)d\d+|\d+))*$/D', $formula) !== 1) {
        return false;
    }
    preg_match_all('/[+-]?[^+-]+/', $formula, $matches);
    if (count($matches[0]) > 30) {
        return false;
    }
    foreach ($matches[0] as $term) {
        $clean = ltrim($term, '+-');
        if (str_contains($clean, 'd')) {
            [$countText, $sidesText] = explode('d', $clean, 2);
            $count = $countText === '' ? 1 : (int) $countText;
            if ($count < 1 || $count > 100 || (int) $sidesText < 2 || (int) $sidesText > 1000) {
                return false;
            }
        } elseif (strlen($clean) > 10 || (int) $clean > 1000000000) {
            return false;
        }
    }
    return true;
}

function validApplicationAbilities(mixed $value): bool
{
    if (!validApplicationDomainObjectList($value, 200)) {
        return false;
    }
    foreach ($value as $entry) {
        if (!validApplicationDomainIdentifier($entry['id'] ?? null, 120)
            || !validApplicationDomainText($entry['name'] ?? null, 120, false)
            || !validApplicationDomainText($entry['formula'] ?? null, 100, false)
            || !validApplicationAbilityFormula($entry['formula'] ?? null)
            || (array_key_exists('description', $entry) && !validApplicationDomainText($entry['description'], 2000))) {
            return false;
        }
    }
    return true;
}

function validApplicationTokenDomain(array $payload): bool
{
    if (!validApplicationDomainIdentifier($payload['id'] ?? null, 80)) {
        return false;
    }
    foreach (['libraryTemplateId' => 120, 'characterId' => 180, 'controllerPlayerId' => 128, 'linkedTokenId' => 180] as $key => $maximum) {
        if (array_key_exists($key, $payload) && !validApplicationDomainIdentifier($payload[$key], $maximum, true)) {
            return false;
        }
    }
    foreach (['name' => 120, 'damageDice' => 80, 'condition' => 200, 'bonuses' => 1000, 'penalties' => 1000, 'notes' => 4000, 'gmNotes' => 4000] as $key => $maximum) {
        if (array_key_exists($key, $payload) && !validApplicationDomainText($payload[$key], $maximum)) {
            return false;
        }
    }
    if (array_key_exists('image', $payload)
        && $payload['image'] !== null
        && !validApplicationDomainText($payload['image'], 4096)) {
        return false;
    }
    if (array_key_exists('color', $payload) && (!is_string($payload['color']) || preg_match('/^#[0-9A-Fa-f]{6}$/D', $payload['color']) !== 1)) {
        return false;
    }
    if (array_key_exists('frameVariant', $payload)
        && (!is_string($payload['frameVariant'])
            || !in_array($payload['frameVariant'], ['player', 'creature', 'elite', 'boss', 'apostle'], true))) {
        return false;
    }
    foreach (['hp', 'maxHp', 'mana', 'maxMana', 'armor', 'speed', 'initiativeBonus'] as $key) {
        if (array_key_exists($key, $payload) && !validApplicationDomainNumber($payload[$key])) {
            return false;
        }
    }
    if (array_key_exists('hitThreshold', $payload)
        && $payload['hitThreshold'] !== null
        && !validApplicationDomainNumber($payload['hitThreshold'], 0, 100)) {
        return false;
    }
    foreach (['x' => [0, 100], 'y' => [0, 100], 'size' => [10, 220], '_updatedAt' => [0, 9007199254740991], '_movedAt' => [0, 9007199254740991]] as $key => [$minimum, $maximum]) {
        if (array_key_exists($key, $payload) && !validApplicationDomainNumber($payload[$key], $minimum, $maximum)) {
            return false;
        }
    }
    if (array_key_exists('initiative', $payload)
        && $payload['initiative'] !== null
        && !validApplicationDomainNumber($payload['initiative'])) {
        return false;
    }
    foreach (['followCharacter', 'hidden', 'revealDetailsToPlayers'] as $key) {
        if (array_key_exists($key, $payload) && !is_bool($payload[$key])) {
            return false;
        }
    }
    if (array_key_exists('resourcePulse', $payload) && $payload['resourcePulse'] !== null) {
        $pulse = $payload['resourcePulse'];
        if (!is_array($pulse)
            || !validApplicationDomainIdentifier($pulse['id'] ?? null, 120)
            || !in_array($pulse['resource'] ?? null, ['hp', 'mana'], true)
            || !validApplicationDomainNumber($pulse['delta'] ?? null, -1000000000, 1000000000)
            || (float) ($pulse['delta'] ?? 0) === 0.0
            || !validApplicationDomainNumber($pulse['at'] ?? null, 1, 9007199254740991)) {
            return false;
        }
    }
    return (!array_key_exists('stats', $payload) || validApplicationTokenStats($payload['stats']))
        && (!array_key_exists('abilities', $payload) || validApplicationAbilities($payload['abilities']));
}

function validApplicationTokenList(mixed $value, int $maximumCount): bool
{
    if (!validApplicationDomainObjectList($value, $maximumCount)) {
        return false;
    }
    foreach ($value as $token) {
        if (!validApplicationTokenDomain($token)) {
            return false;
        }
    }
    return true;
}

function validApplicationMediaFolders(mixed $value): bool
{
    if (!validApplicationDomainObjectList($value, 200)) {
        return false;
    }
    $ids = [];
    foreach ($value as $folder) {
        $id = (string) ($folder['id'] ?? '');
        if (!validApplicationDomainIdentifier($id, 180)
            || isset($ids[$id])
            || !validApplicationDomainText($folder['name'] ?? null, 120, false)
            || !in_array($folder['channel'] ?? null, ['music', 'ambience'], true)
            || (array_key_exists('createdAt', $folder) && !validApplicationDomainText($folder['createdAt'], 80))) {
            return false;
        }
        $ids[$id] = (string) $folder['channel'];
    }
    return true;
}

function validApplicationAudioTracks(mixed $tracks, mixed $folders): bool
{
    if (!validApplicationDomainObjectList($tracks, 2000) || !validApplicationMediaFolders($folders)) {
        return false;
    }
    $folderChannels = [];
    foreach ($folders as $folder) {
        $folderChannels[(string) $folder['id']] = (string) $folder['channel'];
    }
    foreach ($tracks as $track) {
        $folderId = $track['folderId'] ?? null;
        if ($folderId === null || $folderId === '') {
            continue;
        }
        $channel = $track['channel'] ?? null;
        if (!validApplicationDomainIdentifier($folderId, 180)
            || !in_array($channel, ['music', 'ambience'], true)
            || ($folderChannels[(string) $folderId] ?? null) !== $channel) {
            return false;
        }
    }
    return true;
}

function validApplicationRollDomain(array $payload): bool
{
    if (!validApplicationDomainIdentifier($payload['id'] ?? null, 180)
        || !array_key_exists('total', $payload)
        || !validApplicationDomainNumber($payload['total'])) {
        return false;
    }
    foreach (['label' => 160, 'characterName' => 120, 'formula' => 120, 'breakdown' => 2000, 'rollerName' => 120, 'createdAt' => 80] as $key => $maximum) {
        if (array_key_exists($key, $payload) && !validApplicationDomainText($payload[$key], $maximum)) {
            return false;
        }
    }
    if (array_key_exists('visibility', $payload) && !in_array($payload['visibility'], ['public', 'gm', 'queued'], true)) {
        return false;
    }
    if (array_key_exists('rollerRole', $payload) && !in_array($payload['rollerRole'], ['gm', 'player'], true)) {
        return false;
    }
    if (array_key_exists('revealed', $payload) && !is_bool($payload['revealed'])) {
        return false;
    }
    $rollMode = (string) ($payload['rollMode'] ?? 'normal');
    if (!in_array($rollMode, ['normal', 'advantage', 'disadvantage'], true)) {
        return false;
    }
    if (array_key_exists('selectedIndex', $payload)
        && !validApplicationDomainNumber($payload['selectedIndex'], 0, 1)) {
        return false;
    }
    if (array_key_exists('attempts', $payload)) {
        if (!is_array($payload['attempts']) || count($payload['attempts']) < 1 || count($payload['attempts']) > 2) {
            return false;
        }
        if ($rollMode !== 'normal' && count($payload['attempts']) !== 2) {
            return false;
        }
        foreach ($payload['attempts'] as $attempt) {
            if (!is_array($attempt)
                || !validApplicationDomainNumber($attempt['total'] ?? null)
                || !validApplicationDomainText($attempt['breakdown'] ?? null, 2000)
                || (array_key_exists('rawD100', $attempt)
                    && $attempt['rawD100'] !== null
                    && !validApplicationDomainNumber($attempt['rawD100'], 1, 100))) {
                return false;
            }
        }
    } elseif ($rollMode !== 'normal') {
        return false;
    }
    if (!array_key_exists('outcome', $payload)) {
        return true;
    }
    $outcome = $payload['outcome'];
    return is_array($outcome)
        && validApplicationDomainNumber($outcome['raw'] ?? null, 1, 100)
        && (!array_key_exists('baseThreshold', $outcome) || $outcome['baseThreshold'] === null || validApplicationDomainNumber($outcome['baseThreshold'], 0, 100))
        && validApplicationDomainNumber($outcome['modifier'] ?? null, -100, 100)
        && (!array_key_exists('resultModifier', $outcome) || validApplicationDomainNumber($outcome['resultModifier'], -100, 100))
        && (!array_key_exists('result', $outcome) || validApplicationDomainNumber($outcome['result'], -99, 200))
        && (!array_key_exists('threshold', $outcome) || $outcome['threshold'] === null || validApplicationDomainNumber($outcome['threshold'], 0, 100))
        && in_array(($outcome['code'] ?? ''), ['critical-success', 'special-success', 'critical-failure', 'success', 'failure'], true)
        && validApplicationDomainText($outcome['label'] ?? null, 40, false)
        && is_bool($outcome['success'] ?? null)
        && is_bool($outcome['effect'] ?? null);
}

function validApplicationRollList(mixed $value, int $maximumCount): bool
{
    if (!validApplicationDomainObjectList($value, $maximumCount)) {
        return false;
    }
    foreach ($value as $roll) {
        if (!validApplicationRollDomain($roll)) {
            return false;
        }
    }
    return true;
}

function applicationDomainNodeShapeIsValid(mixed $value, int $depth, int &$nodes): bool
{
    $nodes++;
    if ($nodes > 50000 || $depth > 24) {
        return false;
    }
    if (is_string($value)) {
        return strlen($value) <= 250000 && preg_match('/[\x00]/', $value) !== 1;
    }
    if (!is_array($value)) {
        return is_null($value) || is_bool($value) || is_int($value) || is_float($value);
    }
    if (count($value) > 10000) {
        return false;
    }
    foreach ($value as $child) {
        if (!applicationDomainNodeShapeIsValid($child, $depth + 1, $nodes)) {
            return false;
        }
    }
    return true;
}

function validApplicationDomainShape(mixed $value): bool
{
    $nodes = 0;
    return applicationDomainNodeShapeIsValid($value, 0, $nodes);
}

function validApplicationCharacterDomain(array $payload): bool
{
    if (isset($payload['characterSchema']) && (string) $payload['characterSchema'] !== 'xar-tsaroth.character-sheet') {
        return false;
    }
    if (isset($payload['characterSchemaVersion'])
        && (!is_int($payload['characterSchemaVersion']) || $payload['characterSchemaVersion'] < 0 || $payload['characterSchemaVersion'] > 3)) {
        return false;
    }
    if (isset($payload['conditions']) && !validApplicationDomainStringList($payload['conditions'], 100, 240)) {
        return false;
    }
    if (array_key_exists('hitThreshold', $payload)
        && $payload['hitThreshold'] !== null
        && !validApplicationDomainNumber($payload['hitThreshold'], 0, 100)) {
        return false;
    }
    foreach (['shortcuts' => 200, 'linkedTokens' => 200, 'abilities' => 200] as $key => $maximum) {
        if (isset($payload[$key]) && !validApplicationDomainObjectList($payload[$key], $maximum)) {
            return false;
        }
    }
    if (isset($payload['abilities']) && !validApplicationAbilities($payload['abilities'])) {
        return false;
    }
    foreach (['resources', 'stats', 'fatigue', 'secret'] as $key) {
        if (isset($payload[$key]) && (!is_array($payload[$key]) || count($payload[$key]) > 64)) {
            return false;
        }
    }
    return true;
}

function domainPayloadContainsInlineImage(mixed $value, int $depth = 0): bool
{
    if ($depth > 64) {
        return true;
    }
    if (is_string($value)) {
        return str_starts_with(strtolower($value), 'data:image/');
    }
    if (!is_array($value)) {
        return false;
    }
    foreach ($value as $key => $child) {
        if (is_string($key) && strcasecmp($key, 'imageDataUrl') === 0) {
            return true;
        }
        if (domainPayloadContainsInlineImage($child, $depth + 1)) {
            return true;
        }
    }
    return false;
}

function removeLegacyInlineImages(mixed $value, int $depth = 0): mixed
{
    if ($depth > 64) {
        return null;
    }
    if (is_string($value) && str_starts_with(strtolower($value), 'data:image/')) {
        return null;
    }
    if (!is_array($value)) {
        return $value;
    }
    $clean = [];
    foreach ($value as $key => $child) {
        if (is_string($key) && strcasecmp($key, 'imageDataUrl') === 0) {
            continue;
        }
        $clean[$key] = removeLegacyInlineImages($child, $depth + 1);
    }
    return $clean;
}

function domainReferencedMediaIds(mixed $value, int $depth = 0): array
{
    if ($depth > 64) {
        return [];
    }
    if (is_string($value) && preg_match('#^/media/([A-Za-z0-9_-]{24})$#D', $value, $match) === 1) {
        return [$match[1]];
    }
    if (!is_array($value)) {
        return [];
    }
    $ids = [];
    foreach ($value as $child) {
        foreach (domainReferencedMediaIds($child, $depth + 1) as $id) {
            $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}

function reactivateDomainMedia(PDO $connection, array $payload): void
{
    $statement = null;
    foreach (domainReferencedMediaIds($payload) as $id) {
        $statement ??= $connection->prepare(
            'UPDATE media_objects SET pending_delete_at = NULL WHERE id = :id AND pending_delete_at IS NOT NULL'
        );
        $statement->execute([':id' => $id]);
    }
}

function validatedDomainPayload(string $key, mixed $payload): array
{
    if (!validApplicationDomainKey($key) || !is_array($payload)) {
        sendError(400, 'Document de domaine invalide.', 'invalid_domain');
    }
    if (stateContainsForbiddenSecret($payload)) {
        sendError(400, 'Le domaine contient une donnée secrète interdite.', 'secret_in_domain');
    }
    if (domainPayloadContainsInlineImage($payload)) {
        sendError(400, 'Les images intégrées ne sont plus acceptées. Téléversez le média séparément.', 'inline_media_forbidden');
    }
    if (!validApplicationDomainShape($payload)) {
        sendError(400, 'Le domaine contient une structure hors limites.', 'invalid_domain_shape');
    }
    $payload = sanitizeStateImageReferences($payload);
    if ($key === 'table') {
        $activeSceneId = $payload['activeSceneId'] ?? null;
        if ($activeSceneId !== null
            && (!is_string($activeSceneId) || preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $activeSceneId) !== 1)) {
            sendError(400, 'Document de table incohérent.', 'invalid_table_domain');
        }
    }
    if ($key === 'scene-index'
        && !validApplicationDomainIdentifierList($payload['order'] ?? null, 256)) {
        sendError(400, 'Index de scènes incohérent.', 'invalid_scene_index_domain');
    }
    if ($key === 'roster'
        && (!validApplicationDomainObjectList($payload['players'] ?? null, 1000)
            || !validApplicationDomainIdentifierList($payload['characterOrder'] ?? null, 1000, 180)
            || !validApplicationDomainMap($payload['playerPreferences'] ?? [], 1000)
            || !validApplicationDomainObjectList($payload['playerTombstones'] ?? [], 2000)
            || !validApplicationDomainObjectList($payload['characterTombstones'] ?? [], 2000))) {
        sendError(400, 'Registre des participants incohérent.', 'invalid_roster_domain');
    }
    if ($key === 'activity'
        && (!validApplicationDomainObjectList($payload['actionTimers'] ?? null, 300)
            || !validApplicationDomainObjectList($payload['actionTimerTombstones'] ?? null, 1000)
            || !validApplicationDomainObjectList($payload['mapPings'] ?? null, 20)
            || !validApplicationDomainObjectList($payload['pingReceipts'] ?? [], 256)
            || !validApplicationDomainObjectList($payload['shortcuts'] ?? null, 500)
            || !validApplicationRollList($payload['rolls'] ?? null, 100))) {
        sendError(400, 'Journal d’activité incohérent.', 'invalid_activity_domain');
    }
    if ($key === 'library'
        && (!validApplicationTokenList($payload['tokenLibrary'] ?? null, 200)
            || !validApplicationMapEffectPresets($payload['mapEffectPresets'] ?? []))) {
        sendError(400, 'Bestiaire incohérent.', 'invalid_library_domain');
    }
    if ($key === 'audio'
        && !validApplicationAudioTracks($payload['tracks'] ?? null, $payload['folders'] ?? [])) {
        sendError(400, 'Playlist incohérente.', 'invalid_audio_domain');
    }
    if (str_starts_with($key, 'scene:')) {
        $expected = substr($key, strlen('scene:'));
        if ((string) ($payload['metadata']['id'] ?? '') !== $expected) {
            sendError(400, 'Document de scène incohérent.', 'invalid_scene_domain');
        }
    }
    if (str_starts_with($key, 'character:')) {
        $expected = substr($key, strlen('character:'));
        if ((string) ($payload['id'] ?? '') !== $expected || !validApplicationCharacterDomain($payload)) {
            sendError(400, 'Document de personnage incohérent.', 'invalid_character_domain');
        }
    }
    if (str_starts_with($key, 'token:')) {
        $segments = explode(':', $key, 3);
        if (count($segments) !== 3
            || (string) ($payload['id'] ?? '') !== $segments[2]
            || !validApplicationTokenDomain($payload)) {
            sendError(400, 'Document de token incohérent.', 'invalid_token_domain');
        }
    }
    if (str_starts_with($key, 'token-index:')) {
        $order = $payload['order'] ?? null;
        if (!validApplicationDomainIdentifierList($order, 2000)) {
            sendError(400, 'Index de tokens incohérent.', 'invalid_token_index_domain');
        }
    }
    if (str_starts_with($key, 'map:') && !validApplicationMapFogState($payload)) {
        sendError(400, 'Brouillard de carte incohérent.', 'invalid_map_fog');
    }
    if (str_starts_with($key, 'initiative:')
        && (!validApplicationDomainIdentifierList($payload['order'] ?? [], 2000)
            || (array_key_exists('movementOverrides', $payload)
                && !validApplicationMovementOverrides($payload['movementOverrides'])))) {
        sendError(400, 'Initiative incohérente.', 'invalid_initiative_domain');
    }
    if (str_starts_with($key, 'presentation:')
        && (!is_array($payload['map'] ?? null)
            || !validApplicationMapFogState($payload['map'])
            || !validApplicationTokenList($payload['map']['tokens'] ?? [], 2000)
            || !is_array($payload['initiative'] ?? null)
            || !validApplicationDomainIdentifierList($payload['initiative']['order'] ?? [], 2000)
            || (array_key_exists('movementOverrides', $payload['initiative'])
                && !validApplicationMovementOverrides($payload['initiative']['movementOverrides'])))) {
        sendError(400, 'Instantané de présentation incohérent.', 'invalid_presentation_domain');
    }
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (strlen($encoded) > XAR_DOMAIN_MAXIMUM_BYTES) {
        sendError(413, 'Ce domaine dépasse 8 Mo.', 'domain_too_large');
    }
    return $payload;
}

function domainClockRecord(PDO $connection, bool $forUpdate = false): array
{
    $statement = $connection->query(
        'SELECT global_revision, state_schema_version, domain_schema_version, legacy_revision, initialized_at '
        . 'FROM application_domain_clock WHERE singleton_id = 1' . ($forUpdate ? ' FOR UPDATE' : '')
    );
    $record = $statement === false ? false : $statement->fetch();
    if (!is_array($record)) {
        throw new RuntimeException('domain_clock_missing');
    }
    return [
        'globalRevision' => (int) $record['global_revision'],
        'stateSchemaVersion' => (int) $record['state_schema_version'],
        'domainSchemaVersion' => (int) $record['domain_schema_version'],
        'legacyRevision' => $record['legacy_revision'] === null ? null : (int) $record['legacy_revision'],
        'initializedAt' => $record['initialized_at'],
    ];
}

function applicationDomainRecords(PDO $connection, ?array $keys = null): array
{
    if (is_array($keys)) {
        $keys = array_values(array_unique(array_filter($keys, 'validApplicationDomainKey')));
        if ($keys === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $statement = $connection->prepare(
            'SELECT domain_key, schema_version, revision, payload, updated_at FROM application_domains '
            . 'WHERE domain_key IN (' . $placeholders . ') ORDER BY domain_key'
        );
        $statement->execute($keys);
    } else {
        $statement = $connection->query(
            'SELECT domain_key, schema_version, revision, payload, updated_at FROM application_domains ORDER BY domain_key'
        );
    }
    $records = [];
    foreach ($statement === false ? [] : $statement->fetchAll() as $record) {
        $key = (string) ($record['domain_key'] ?? '');
        if (!validApplicationDomainKey($key)) {
            continue;
        }
        $records[$key] = [
            'key' => $key,
            'schemaVersion' => (int) $record['schema_version'],
            'revision' => (int) $record['revision'],
            'payload' => jsonColumn($record['payload'] ?? null),
            'updatedAt' => (string) ($record['updated_at'] ?? ''),
        ];
    }
    return $records;
}

function applicationDomainRecordsByPrefix(PDO $connection, string $prefix): array
{
    if (!validApplicationDomainPrefix($prefix)) {
        return [];
    }
    $statement = $connection->prepare(
        'SELECT domain_key, schema_version, revision, payload, updated_at FROM application_domains '
        . 'WHERE domain_key LIKE :prefix ORDER BY domain_key'
    );
    $statement->execute([':prefix' => $prefix . '%']);
    $records = [];
    foreach ($statement->fetchAll() as $record) {
        $key = (string) ($record['domain_key'] ?? '');
        if (!validApplicationDomainKey($key) || !str_starts_with($key, $prefix)) {
            continue;
        }
        $records[$key] = [
            'key' => $key,
            'schemaVersion' => (int) $record['schema_version'],
            'revision' => (int) $record['revision'],
            'payload' => jsonColumn($record['payload'] ?? null),
            'updatedAt' => (string) ($record['updated_at'] ?? ''),
        ];
    }
    return $records;
}

function applicationCharacterTokenDomainRecords(PDO $connection, string $characterId): array
{
    $statement = $connection->prepare(
        "SELECT domain_key, schema_version, revision, payload, updated_at FROM application_domains "
        . "WHERE domain_key LIKE 'token:%' AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.characterId')) = :character_id "
        . 'ORDER BY domain_key'
    );
    $statement->execute([':character_id' => $characterId]);
    $records = [];
    foreach ($statement->fetchAll() as $record) {
        $key = (string) ($record['domain_key'] ?? '');
        if (!validApplicationDomainKey($key)) {
            continue;
        }
        $records[$key] = [
            'key' => $key,
            'schemaVersion' => (int) $record['schema_version'],
            'revision' => (int) $record['revision'],
            'payload' => jsonColumn($record['payload'] ?? null),
            'updatedAt' => (string) ($record['updated_at'] ?? ''),
        ];
    }
    return $records;
}

function applicationDomainPayload(array $records, string $key, array $fallback = []): array
{
    $payload = $records[$key]['payload'] ?? null;
    return is_array($payload) ? $payload : $fallback;
}

function canonicalApplicationDomainValue(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('canonicalApplicationDomainValue', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $entry) {
        $value[$key] = canonicalApplicationDomainValue($entry);
    }
    return $value;
}

function applicationDomainPayloadForComparison(string $key, array $payload): array
{
    if (str_starts_with($key, 'token:') && ($payload['resourcePulse'] ?? null) === null) {
        unset($payload['resourcePulse']);
    }
    if ($key === 'audio') {
        if (($payload['folders'] ?? null) === []) {
            unset($payload['folders']);
        }
        if (is_array($payload['tracks'] ?? null)) {
            foreach ($payload['tracks'] as $index => $track) {
                if (!is_array($track) || !array_key_exists('folderId', $track)) {
                    continue;
                }
                if ($track['folderId'] === null || $track['folderId'] === '') {
                    unset($payload['tracks'][$index]['folderId']);
                }
            }
        }
    }
    return canonicalApplicationDomainValue($payload);
}

function applicationDomainEntityTimestamp(array $payload, string $field): int
{
    $value = $payload[$field] ?? 0;
    if (!is_numeric($value) || !is_finite((float) $value)) {
        return 0;
    }
    return max(0, (int) $value);
}

function protectApplicationDomainAgainstStaleEntityWrite(string $key, array $payload, ?array $current): array
{
    if (!is_array($current) || !is_array($current['payload'] ?? null)) {
        return $payload;
    }
    $currentPayload = $current['payload'];
    $incomingUpdatedAt = applicationDomainEntityTimestamp($payload, '_updatedAt');
    $currentUpdatedAt = applicationDomainEntityTimestamp($currentPayload, '_updatedAt');
    if (str_starts_with($key, 'character:')) {
        return $currentUpdatedAt > $incomingUpdatedAt ? $currentPayload : $payload;
    }
    if (!str_starts_with($key, 'token:')) {
        return $payload;
    }

    $incomingMovedAt = applicationDomainEntityTimestamp($payload, '_movedAt');
    $currentMovedAt = applicationDomainEntityTimestamp($currentPayload, '_movedAt');
    if ($currentUpdatedAt > $incomingUpdatedAt) {
        $protected = $currentPayload;
        if ($incomingMovedAt > $currentMovedAt) {
            foreach (['x', 'y', '_movedAt'] as $field) {
                if (array_key_exists($field, $payload)) {
                    $protected[$field] = $payload[$field];
                }
            }
        }
        return $protected;
    }
    if ($currentMovedAt > $incomingMovedAt) {
        foreach (['x', 'y', '_movedAt'] as $field) {
            if (array_key_exists($field, $currentPayload)) {
                $payload[$field] = $currentPayload[$field];
            } else {
                unset($payload[$field]);
            }
        }
    }
    return $payload;
}

function prepareApplicationDomainUpsert(
    string $key,
    mixed $payload,
    ?array $current,
    bool $protectAgainstStaleEntityWrite = false
): ?array
{
    $payload = validatedDomainPayload($key, $payload);
    if ($protectAgainstStaleEntityWrite) {
        $payload = protectApplicationDomainAgainstStaleEntityWrite($key, $payload, $current);
    }
    $encoded = json_encode(
        applicationDomainPayloadForComparison($key, $payload),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    $currentEncoded = is_array($current)
        ? json_encode(
            applicationDomainPayloadForComparison($key, is_array($current['payload'] ?? null) ? $current['payload'] : []),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        )
        : null;
    if ($encoded === $currentEncoded) {
        return null;
    }
    return ['key' => $key, 'operation' => 'upsert', 'payload' => $payload, 'current' => $current];
}

function prepareApplicationDomainDelete(string $key, ?array $current): ?array
{
    if (!validApplicationDomainKey($key) || !is_array($current)) {
        return null;
    }
    return ['key' => $key, 'operation' => 'delete', 'current' => $current];
}

function metadataFromLegacyScene(array $scene): array
{
    unset($scene['combat']);
    return $scene;
}

function mapSettingsForDomain(array $map): array
{
    unset($map['tokens']);
    return $map;
}

function tacticalSyncForDomain(array $sync): array
{
    $paused = ($sync['paused'] ?? false) === true;
    return [
        'paused' => $paused,
        'pausedAt' => $paused && is_string($sync['pausedAt'] ?? null) ? $sync['pausedAt'] : null,
        'playerMovementMode' => ($sync['playerMovementMode'] ?? '') === 'combat' ? 'combat' : 'free',
    ];
}

function splitCombatIntoDomains(array &$domains, string $sceneId, array $combat): void
{
    $map = is_array($combat['map'] ?? null) ? $combat['map'] : [];
    $tokens = is_array($map['tokens'] ?? null) ? $map['tokens'] : [];
    $domains['map:' . $sceneId] = mapSettingsForDomain($map);
    $domains['token-index:' . $sceneId] = ['order' => []];
    foreach ($tokens as $token) {
        if (!is_array($token)) {
            continue;
        }
        $tokenId = (string) ($token['id'] ?? '');
        $key = 'token:' . $sceneId . ':' . $tokenId;
        if ($tokenId === '' || !validApplicationDomainKey($key)) {
            continue;
        }
        $domains['token-index:' . $sceneId]['order'][] = $tokenId;
        $domains[$key] = $token;
    }
    $domains['initiative:' . $sceneId] = is_array($combat['initiative'] ?? null) ? $combat['initiative'] : [];
}

function legacyStateToDomains(array $state): array
{
    $scenes = is_array($state['scenes'] ?? null) ? $state['scenes'] : [];
    $characters = is_array($state['characters'] ?? null) ? $state['characters'] : [];
    $activeSceneId = (string) ($state['activeSceneId'] ?? '');
    $rawTacticalSync = is_array($state['tacticalSync'] ?? null) ? $state['tacticalSync'] : [];
    $domains = [
        'table' => [
            'session' => is_array($state['session'] ?? null) ? $state['session'] : [],
            'activeSceneId' => $activeSceneId !== '' ? $activeSceneId : null,
            'discordCapture' => is_array($state['discordCapture'] ?? null) ? $state['discordCapture'] : [],
            'tacticalSync' => tacticalSyncForDomain($rawTacticalSync),
        ],
        'scene-index' => ['order' => []],
        'roster' => [
            'players' => is_array($state['players'] ?? null) ? $state['players'] : [],
            'characterOrder' => [],
            'playerPreferences' => is_array($state['playerPreferences'] ?? null) ? $state['playerPreferences'] : [],
            'playerTombstones' => is_array($state['playerTombstones'] ?? null) ? $state['playerTombstones'] : [],
            'characterTombstones' => is_array($state['characterTombstones'] ?? null) ? $state['characterTombstones'] : [],
        ],
        'activity' => [
            'actionTimers' => is_array($state['actionTimers'] ?? null) ? $state['actionTimers'] : [],
            'actionTimerTombstones' => is_array($state['actionTimerTombstones'] ?? null) ? $state['actionTimerTombstones'] : [],
            'mapPings' => is_array($state['mapPings'] ?? null) ? $state['mapPings'] : [],
            'pingReceipts' => is_array($state['pingReceipts'] ?? null) ? $state['pingReceipts'] : [],
            'shortcuts' => is_array($state['shortcuts'] ?? null) ? $state['shortcuts'] : [],
            'rolls' => is_array($state['rolls'] ?? null) ? $state['rolls'] : [],
        ],
        'library' => [
            'tokenLibrary' => is_array($state['tokenLibrary'] ?? null) ? $state['tokenLibrary'] : [],
            'mapEffectPresets' => is_array($state['mapEffectPresets'] ?? null) ? $state['mapEffectPresets'] : [],
        ],
        'audio' => [
            'folders' => is_array($state['mediaFolders'] ?? null) ? $state['mediaFolders'] : [],
            'tracks' => is_array($state['tracks'] ?? null) ? $state['tracks'] : [],
            'playback' => is_array($state['audio'] ?? null) ? $state['audio'] : [],
        ],
        'forge' => [],
    ];

    $forge = is_array($state['forge'] ?? null) ? $state['forge'] : [];
    unset($forge['imageDataUrl']);
    $domains['forge'] = $forge;

    foreach ($scenes as $scene) {
        if (!is_array($scene)) {
            continue;
        }
        $id = (string) ($scene['id'] ?? '');
        $key = 'scene:' . $id;
        if ($id === '' || !validApplicationDomainKey($key)) {
            continue;
        }
        $combat = is_array($scene['combat'] ?? null) ? $scene['combat'] : [];
        if ($id === $activeSceneId && is_array($state['map'] ?? null) && is_array($state['initiative'] ?? null)) {
            $combat = ['map' => $state['map'], 'initiative' => $state['initiative']];
        }
        $domains['scene-index']['order'][] = $id;
        $domains[$key] = ['metadata' => metadataFromLegacyScene($scene)];
        splitCombatIntoDomains($domains, $id, $combat);
    }

    foreach ($characters as $character) {
        if (!is_array($character)) {
            continue;
        }
        $id = (string) ($character['id'] ?? '');
        $key = 'character:' . $id;
        if ($id === '' || !validApplicationDomainKey($key)) {
            continue;
        }
        $domains['roster']['characterOrder'][] = $id;
        $domains[$key] = $character;
    }

    $hasActiveScene = $activeSceneId !== '' && in_array($activeSceneId, $domains['scene-index']['order'], true);
    if (!$hasActiveScene && (is_array($state['map'] ?? null) || is_array($state['initiative'] ?? null))) {
        $domains['detached-combat'] = [
            'map' => is_array($state['map'] ?? null) ? $state['map'] : [],
            'initiative' => is_array($state['initiative'] ?? null) ? $state['initiative'] : [],
        ];
    }
    if (($rawTacticalSync['paused'] ?? false) === true && $hasActiveScene) {
        $activeMetadata = [];
        foreach ($scenes as $scene) {
            if (is_array($scene) && (string) ($scene['id'] ?? '') === $activeSceneId) {
                $activeMetadata = metadataFromLegacyScene($scene);
                break;
            }
        }
        $domains['presentation:' . $activeSceneId] = [
            'map' => is_array($rawTacticalSync['publishedMap'] ?? null)
                ? $rawTacticalSync['publishedMap']
                : (is_array($state['map'] ?? null) ? $state['map'] : []),
            'initiative' => is_array($rawTacticalSync['publishedInitiative'] ?? null)
                ? $rawTacticalSync['publishedInitiative']
                : (is_array($state['initiative'] ?? null) ? $state['initiative'] : []),
            'activeScene' => is_array($rawTacticalSync['publishedActiveScene'] ?? null)
                ? $rawTacticalSync['publishedActiveScene']
                : $activeMetadata,
        ];
    }
    return $domains;
}

function domainsToApplicationState(array $records, int $revision, ?string $updatedAt = null): array
{
    $payload = static fn (string $key, array $fallback = []): array => is_array($records[$key]['payload'] ?? null)
        ? $records[$key]['payload']
        : $fallback;
    $table = $payload('table');
    $sceneIndex = $payload('scene-index');
    $roster = $payload('roster');
    $activity = $payload('activity');
    $library = $payload('library');
    $audio = $payload('audio');
    $scenes = [];
    foreach (is_array($sceneIndex['order'] ?? null) ? $sceneIndex['order'] : [] as $id) {
        $sceneId = (string) $id;
        $entry = $payload('scene:' . $sceneId);
        if (!is_array($entry['metadata'] ?? null)) {
            continue;
        }
        if (is_array($entry['combat'] ?? null)) {
            $combat = $entry['combat'];
        } else {
            $map = $payload('map:' . $sceneId);
            $tokenIndex = $payload('token-index:' . $sceneId);
            $tokens = [];
            foreach (is_array($tokenIndex['order'] ?? null) ? $tokenIndex['order'] : [] as $tokenId) {
                $token = $payload('token:' . $sceneId . ':' . (string) $tokenId);
                if ($token !== []) {
                    $tokens[] = $token;
                }
            }
            $map['tokens'] = $tokens;
            $combat = [
                'map' => $map,
                'initiative' => $payload('initiative:' . $sceneId),
            ];
        }
        $scenes[] = [...$entry['metadata'], 'combat' => $combat];
    }
    $characters = [];
    foreach (is_array($roster['characterOrder'] ?? null) ? $roster['characterOrder'] : [] as $id) {
        $character = $payload('character:' . (string) $id);
        if ($character !== []) {
            $characters[] = $character;
        }
    }
    $activeSceneId = (string) ($table['activeSceneId'] ?? '');
    $activeScene = null;
    $combat = $payload('detached-combat');
    foreach ($scenes as $scene) {
        if ((string) ($scene['id'] ?? '') !== $activeSceneId) {
            continue;
        }
        $activeScene = metadataFromLegacyScene($scene);
        $combat = is_array($scene['combat'] ?? null) ? $scene['combat'] : [];
        break;
    }
    if ($activeScene === null) {
        $activeSceneId = '';
    }
    $rawTacticalSync = is_array($table['tacticalSync'] ?? null) ? $table['tacticalSync'] : [];
    $tacticalSync = tacticalSyncForDomain($rawTacticalSync);
    if ($tacticalSync['paused'] && $activeSceneId !== '') {
        $presentation = $payload('presentation:' . $activeSceneId);
        $tacticalSync['publishedMap'] = is_array($presentation['map'] ?? null)
            ? $presentation['map']
            : (is_array($combat['map'] ?? null) ? $combat['map'] : []);
        $tacticalSync['publishedInitiative'] = is_array($presentation['initiative'] ?? null)
            ? $presentation['initiative']
            : (is_array($combat['initiative'] ?? null) ? $combat['initiative'] : []);
        $tacticalSync['publishedActiveScene'] = is_array($presentation['activeScene'] ?? null)
            ? $presentation['activeScene']
            : $activeScene;
    } else {
        $tacticalSync['publishedMap'] = null;
        $tacticalSync['publishedInitiative'] = null;
        $tacticalSync['publishedActiveScene'] = null;
    }
    return [
        'schemaVersion' => XAR_SESSION_SCHEMA_VERSION,
        'revision' => $revision,
        'updatedAt' => $updatedAt ?? gmdate('c'),
        'session' => is_array($table['session'] ?? null) ? $table['session'] : [],
        'scenes' => $scenes,
        'activeSceneId' => $activeSceneId !== '' ? $activeSceneId : null,
        'activeScene' => $activeScene,
        'players' => is_array($roster['players'] ?? null) ? $roster['players'] : [],
        'characters' => $characters,
        'playerPreferences' => is_array($roster['playerPreferences'] ?? null) ? $roster['playerPreferences'] : [],
        'playerTombstones' => is_array($roster['playerTombstones'] ?? null) ? $roster['playerTombstones'] : [],
        'characterTombstones' => is_array($roster['characterTombstones'] ?? null) ? $roster['characterTombstones'] : [],
        'map' => is_array($combat['map'] ?? null) ? $combat['map'] : [],
        'initiative' => is_array($combat['initiative'] ?? null) ? $combat['initiative'] : [],
        'discordCapture' => is_array($table['discordCapture'] ?? null) ? $table['discordCapture'] : [],
        'tacticalSync' => $tacticalSync,
        'actionTimers' => is_array($activity['actionTimers'] ?? null) ? $activity['actionTimers'] : [],
        'actionTimerTombstones' => is_array($activity['actionTimerTombstones'] ?? null) ? $activity['actionTimerTombstones'] : [],
        'mapPings' => is_array($activity['mapPings'] ?? null) ? $activity['mapPings'] : [],
        'pingReceipts' => is_array($activity['pingReceipts'] ?? null) ? $activity['pingReceipts'] : [],
        'tokenLibrary' => is_array($library['tokenLibrary'] ?? null) ? $library['tokenLibrary'] : [],
        'mapEffectPresets' => is_array($library['mapEffectPresets'] ?? null) ? $library['mapEffectPresets'] : [],
        'shortcuts' => is_array($activity['shortcuts'] ?? null) ? $activity['shortcuts'] : [],
        'rolls' => is_array($activity['rolls'] ?? null) ? $activity['rolls'] : [],
        'mediaFolders' => is_array($audio['folders'] ?? null) ? $audio['folders'] : [],
        'tracks' => is_array($audio['tracks'] ?? null) ? $audio['tracks'] : [],
        'audio' => is_array($audio['playback'] ?? null) ? $audio['playback'] : [],
        'forge' => $payload('forge'),
    ];
}

function insertDomainChange(
    PDO $connection,
    int $globalRevision,
    string $key,
    int $domainRevision,
    string $operation
): void {
    $statement = $connection->prepare(
        'INSERT INTO application_domain_changes (global_revision, domain_key, domain_revision, operation) '
        . 'VALUES (:global_revision, :domain_key, :domain_revision, :operation)'
    );
    $statement->execute([
        ':global_revision' => $globalRevision,
        ':domain_key' => $key,
        ':domain_revision' => $domainRevision,
        ':operation' => $operation,
    ]);
}

function upsertApplicationDomain(
    PDO $connection,
    string $key,
    array $payload,
    int $revision,
    ?string $accountId
): void {
    reactivateDomainMedia($connection, $payload);
    $statement = $connection->prepare(
        'INSERT INTO application_domains '
        . '(domain_key, schema_version, revision, payload, updated_by_account_id) '
        . 'VALUES (:domain_key, :schema_version, :revision, :payload, :updated_by) '
        . 'ON DUPLICATE KEY UPDATE schema_version = VALUES(schema_version), revision = VALUES(revision), '
        . 'payload = VALUES(payload), updated_by_account_id = VALUES(updated_by_account_id)'
    );
    $statement->execute([
        ':domain_key' => $key,
        ':schema_version' => XAR_DOMAIN_SCHEMA_VERSION,
        ':revision' => $revision,
        ':payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ':updated_by' => $accountId,
    ]);
}

function ensureDomainStoreInitialized(PDO $connection): void
{
    $clock = domainClockRecord($connection);
    if ($clock['initializedAt'] !== null) {
        return;
    }
    $connection->beginTransaction();
    try {
        $clock = domainClockRecord($connection, true);
        if ($clock['initializedAt'] !== null) {
            $connection->commit();
            return;
        }
        $legacy = legacyApplicationStateRecord($connection, true);
        $legacyState = is_array($legacy['state'] ?? null) ? $legacy['state'] : [];
        if ((int) ($legacy['schemaVersion'] ?? 0) > XAR_SESSION_SCHEMA_VERSION) {
            throw new RuntimeException('future_legacy_state');
        }
        $domains = legacyStateToDomains($legacyState);
        $globalRevision = max(1, (int) ($legacy['revision'] ?? 0));
        foreach ($domains as $key => $payload) {
            if (!validApplicationDomainKey($key)) {
                continue;
            }
            $payload = validatedDomainPayload($key, removeLegacyInlineImages($payload));
            upsertApplicationDomain($connection, $key, $payload, 1, null);
            insertDomainChange($connection, $globalRevision, $key, 1, 'upsert');
        }
        $update = $connection->prepare(
            'UPDATE application_domain_clock SET global_revision = :global_revision, '
            . 'state_schema_version = :state_schema_version, domain_schema_version = :domain_schema_version, '
            . 'legacy_revision = :legacy_revision, initialized_at = UTC_TIMESTAMP(3) WHERE singleton_id = 1'
        );
        $update->execute([
            ':global_revision' => $globalRevision,
            ':state_schema_version' => XAR_SESSION_SCHEMA_VERSION,
            ':domain_schema_version' => XAR_DOMAIN_SCHEMA_VERSION,
            ':legacy_revision' => (int) ($legacy['revision'] ?? 0),
        ]);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
}

function domainApplicationStateRecord(PDO $connection): array
{
    ensureDomainStoreInitialized($connection);
    $clock = domainClockRecord($connection);
    $records = applicationDomainRecords($connection);
    $state = domainsToApplicationState($records, $clock['globalRevision']);
    return [
        'schemaVersion' => XAR_SESSION_SCHEMA_VERSION,
        'revision' => $clock['globalRevision'],
        'state' => $state,
    ];
}

function playerApplicationStateRecord(PDO $connection): array
{
    ensureDomainStoreInitialized($connection);
    $clock = domainClockRecord($connection);
    $records = applicationDomainRecords($connection, ['table', 'roster', 'activity', 'audio', 'detached-combat']);
    $table = applicationDomainPayload($records, 'table');
    $roster = applicationDomainPayload($records, 'roster');
    $sceneId = (string) ($table['activeSceneId'] ?? '');
    $sceneKey = 'scene:' . $sceneId;
    $hasActiveScene = validApplicationDomainKey($sceneKey);

    $characterKeys = [];
    foreach (is_array($roster['characterOrder'] ?? null) ? $roster['characterOrder'] : [] as $characterId) {
        $key = 'character:' . (string) $characterId;
        if (validApplicationDomainKey($key)) {
            $characterKeys[] = $key;
        }
    }
    if ($characterKeys !== []) {
        $records = array_replace($records, applicationDomainRecords($connection, $characterKeys));
    }

    if ($hasActiveScene) {
        $paused = ($table['tacticalSync']['paused'] ?? false) === true;
        $combatKeys = $paused
            ? [$sceneKey, 'presentation:' . $sceneId]
            : [$sceneKey, 'map:' . $sceneId, 'token-index:' . $sceneId, 'initiative:' . $sceneId];
        $records = array_replace($records, applicationDomainRecords($connection, $combatKeys));
        $presentationAvailable = isset($records['presentation:' . $sceneId]);
        if ($paused && !$presentationAvailable) {
            $records = array_replace($records, applicationDomainRecords($connection, [
                'map:' . $sceneId,
                'token-index:' . $sceneId,
                'initiative:' . $sceneId,
            ]));
        }
        if (!$paused || !$presentationAvailable) {
            $tokenIndex = applicationDomainPayload($records, 'token-index:' . $sceneId, ['order' => []]);
            $tokenKeys = [];
            foreach (is_array($tokenIndex['order'] ?? null) ? $tokenIndex['order'] : [] as $tokenId) {
                $key = 'token:' . $sceneId . ':' . (string) $tokenId;
                if (validApplicationDomainKey($key)) {
                    $tokenKeys[] = $key;
                }
            }
            if ($tokenKeys !== []) {
                $records = array_replace($records, applicationDomainRecords($connection, $tokenKeys));
            }
        }
    }

    $records['scene-index'] = [
        'key' => 'scene-index',
        'schemaVersion' => XAR_DOMAIN_SCHEMA_VERSION,
        'revision' => 0,
        'payload' => ['order' => $hasActiveScene ? [$sceneId] : []],
        'updatedAt' => '',
    ];
    $state = domainsToApplicationState($records, $clock['globalRevision']);
    return [
        'schemaVersion' => XAR_SESSION_SCHEMA_VERSION,
        'revision' => $clock['globalRevision'],
        'state' => $state,
    ];
}

function persistDomainChangesInTransaction(PDO $connection, array $identity, array $clock, array $pending): int
{
    $globalRevision = (int) $clock['globalRevision'] + 1;
    $accountId = (string) ($identity['id'] ?? '');
    foreach ($pending as $change) {
        $key = (string) $change['key'];
        $current = is_array($change['current'] ?? null) ? $change['current'] : null;
        $nextRevision = (int) ($current['revision'] ?? 0) + 1;
        if ($current !== null) {
            $history = $connection->prepare(
                'INSERT INTO application_domain_history '
                . '(domain_key, domain_revision, global_revision, operation, payload, changed_by_account_id) '
                . 'VALUES (:domain_key, :domain_revision, :global_revision, :operation, :payload, :changed_by)'
            );
            $history->execute([
                ':domain_key' => $key,
                ':domain_revision' => (int) $current['revision'],
                ':global_revision' => $globalRevision,
                ':operation' => (string) $change['operation'],
                ':payload' => json_encode($current['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ':changed_by' => $accountId !== '' ? $accountId : null,
            ]);
        }
        if ($change['operation'] === 'delete') {
            $delete = $connection->prepare('DELETE FROM application_domains WHERE domain_key = :domain_key');
            $delete->execute([':domain_key' => $key]);
        } else {
            upsertApplicationDomain($connection, $key, $change['payload'], $nextRevision, $accountId !== '' ? $accountId : null);
        }
        insertDomainChange($connection, $globalRevision, $key, $nextRevision, (string) $change['operation']);
    }
    $update = $connection->prepare(
        'UPDATE application_domain_clock SET global_revision = :global_revision, '
        . 'state_schema_version = :state_schema_version, domain_schema_version = :domain_schema_version '
        . 'WHERE singleton_id = 1'
    );
    $update->execute([
        ':global_revision' => $globalRevision,
        ':state_schema_version' => XAR_SESSION_SCHEMA_VERSION,
        ':domain_schema_version' => XAR_DOMAIN_SCHEMA_VERSION,
    ]);
    return $globalRevision;
}

function readApplicationDomains(PDO $connection, bool $headOnly = false): never
{
    requireGmIdentity($connection);
    ensureDomainStoreInitialized($connection);
    $clock = domainClockRecord($connection);
    $since = max(0, (int) ($_GET['since'] ?? 0));
    $prefix = trim((string) ($_GET['prefix'] ?? ''));
    $includePresence = (string) ($_GET['presence'] ?? '1') !== '0';
    if ($prefix !== '' && (!validApplicationDomainPrefix($prefix) || $since !== 0)) {
        sendError(400, 'Sélection de domaines invalide.', 'invalid_domain_selection');
    }
    $reset = $since === 0;
    $wanted = [];
    $deleted = [];
    if (!$reset && $since < $clock['globalRevision']) {
        $minimum = (int) ($connection->query('SELECT COALESCE(MIN(global_revision), 0) FROM application_domain_changes')->fetchColumn() ?: 0);
        if ($minimum > 0 && $since < $minimum - 1) {
            $reset = true;
        } else {
            $changes = $connection->prepare(
                'SELECT global_revision, domain_key, domain_revision, operation FROM application_domain_changes '
                . 'WHERE global_revision > :since ORDER BY global_revision, domain_key'
            );
            $changes->execute([':since' => $since]);
            $latest = [];
            foreach ($changes->fetchAll() as $change) {
                $latest[(string) $change['domain_key']] = $change;
            }
            $wanted = array_keys(array_filter($latest, static fn (array $entry): bool => $entry['operation'] === 'upsert'));
            $deleted = array_keys(array_filter($latest, static fn (array $entry): bool => $entry['operation'] === 'delete'));
        }
    }
    $records = $prefix !== ''
        ? applicationDomainRecordsByPrefix($connection, $prefix)
        : applicationDomainRecords($connection, $reset ? null : $wanted);
    if ($reset) {
        $wanted = array_keys($records);
    }
    $entries = [];
    foreach ($wanted as $key) {
        if (!isset($records[$key])) {
            continue;
        }
        $entries[] = [
            'key' => $key,
            'schemaVersion' => $records[$key]['schemaVersion'],
            'revision' => $records[$key]['revision'],
            'payload' => $records[$key]['payload'],
        ];
    }
    sendJson(200, [
        'ok' => true,
        'architecture' => 'revisioned-domains',
        'stateSchemaVersion' => $clock['stateSchemaVersion'],
        'domainSchemaVersion' => $clock['domainSchemaVersion'],
        'revision' => $clock['globalRevision'],
        'reset' => $reset,
        'domains' => $entries,
        'deleted' => array_values($deleted),
        ...($prefix !== '' ? ['selection' => ['prefix' => $prefix]] : []),
        ...($includePresence ? ['presence' => liveOnlinePresence($connection)] : []),
    ], $headOnly);
}

function patchApplicationDomains(PDO $connection): never
{
    $identity = requireGmIdentity($connection);
    ensureDomainStoreInitialized($connection);
    $body = readJsonBody(16 * 1024 * 1024);
    if ((int) ($body['stateSchemaVersion'] ?? 0) !== XAR_SESSION_SCHEMA_VERSION
        || (int) ($body['domainSchemaVersion'] ?? 0) !== XAR_DOMAIN_SCHEMA_VERSION) {
        sendError(409, 'Le contrat de données de cette application est incompatible.', 'domain_schema_mismatch');
    }
    $changes = $body['changes'] ?? null;
    if (!is_array($changes) || $changes === [] || count($changes) > XAR_DOMAIN_MAXIMUM_CHANGES) {
        sendError(400, 'Liste de changements de domaines invalide.', 'invalid_domain_changes');
    }
    $keys = [];
    $seen = [];
    foreach ($changes as $change) {
        if (!is_array($change)) {
            sendError(400, 'Changement de domaine invalide.', 'invalid_domain_change');
        }
        $key = trim((string) ($change['key'] ?? ''));
        if (!validApplicationDomainKey($key) || isset($seen[$key])) {
            sendError(400, 'Clé de domaine invalide ou dupliquée.', 'invalid_domain_key');
        }
        $seen[$key] = true;
        $keys[] = $key;
    }
    $connection->beginTransaction();
    try {
        $clock = domainClockRecord($connection, true);
        $records = applicationDomainRecords($connection, $keys);
        $pending = [];
        $conflicts = [];
        foreach ($changes as $change) {
            $key = trim((string) ($change['key'] ?? ''));
            $operation = ($change['operation'] ?? 'upsert') === 'delete' ? 'delete' : 'upsert';
            $current = $records[$key] ?? null;
            $prepared = $operation === 'upsert'
                ? prepareApplicationDomainUpsert($key, $change['payload'] ?? null, $current, true)
                : prepareApplicationDomainDelete($key, $current);
            if ($prepared === null) {
                continue;
            }
            $expected = max(0, (int) ($change['expectedRevision'] ?? 0));
            $currentRevision = (int) ($current['revision'] ?? 0);
            if ($expected !== $currentRevision) {
                $conflicts[] = ['key' => $key, 'expectedRevision' => $expected, 'currentRevision' => $currentRevision];
                continue;
            }
            $pending[] = $prepared;
        }
        if ($conflicts !== []) {
            $connection->rollBack();
            sendJson(409, [
                'ok' => false,
                'error' => 'Un autre MJ a modifié un des domaines concernés.',
                'code' => 'domain_revision_conflict',
                'revision' => $clock['globalRevision'],
                'conflicts' => $conflicts,
            ]);
        }
        if ($pending === []) {
            $connection->commit();
            sendJson(200, ['ok' => true, 'revision' => $clock['globalRevision'], 'domains' => []]);
        }
        $revision = persistDomainChangesInTransaction($connection, $identity, $clock, $pending);
        $responseDomains = array_map(static fn (array $entry): array => [
            'key' => $entry['key'],
            'revision' => (int) ($entry['current']['revision'] ?? 0) + 1,
            'operation' => $entry['operation'],
        ], $pending);
        $connection->commit();
        cleanupApplicationDomainHistory($connection);
        sendJson(200, ['ok' => true, 'revision' => $revision, 'domains' => $responseDomains]);
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
}

function cleanupApplicationDomainHistory(PDO $connection): void
{
    $lockName = 'xar-regie-domain-cleanup';
    try {
        if (random_int(1, 25) !== 1 || !acquireMaintenanceLock($connection, $lockName)) {
            return;
        }
        try {
            $connection->exec(
                'DELETE FROM application_domain_changes WHERE global_revision < '
                . '(SELECT cutoff FROM (SELECT GREATEST(0, global_revision - 2000) AS cutoff '
                . 'FROM application_domain_clock WHERE singleton_id = 1) AS retained) '
                . 'LIMIT ' . XAR_DOMAIN_MAINTENANCE_BATCH_SIZE
            );
            $connection->exec(
                'DELETE FROM application_domain_history '
                . 'WHERE created_at < DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 30 DAY) '
                . 'LIMIT ' . XAR_DOMAIN_MAINTENANCE_BATCH_SIZE
            );
            cleanupExpiredMediaRetention($connection);
        } finally {
            releaseMaintenanceLock($connection, $lockName);
        }
    } catch (Throwable $error) {
        error_log('[xar-regie-api] domain history cleanup failed: ' . get_class($error));
    }
}

function listApplicationDomainHistory(PDO $connection, bool $headOnly = false): never
{
    requireAdministratorIdentity($connection);
    ensureDomainStoreInitialized($connection);
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!validApplicationDomainKey($key)) {
        sendError(400, 'Clé de domaine invalide.', 'invalid_domain_key');
    }
    $statement = $connection->prepare(
        'SELECT history_id, domain_revision, global_revision, operation, payload, created_at '
        . 'FROM application_domain_history WHERE domain_key = :domain_key ORDER BY history_id DESC LIMIT 25'
    );
    $statement->execute([':domain_key' => $key]);
    $entries = array_map(static fn (array $entry): array => [
        'historyId' => (int) $entry['history_id'],
        'domainRevision' => (int) $entry['domain_revision'],
        'globalRevision' => (int) $entry['global_revision'],
        'operation' => (string) $entry['operation'],
        'payload' => jsonColumn($entry['payload'] ?? null),
        'createdAt' => (string) $entry['created_at'],
    ], $statement->fetchAll());
    sendJson(200, ['ok' => true, 'key' => $key, 'history' => $entries], $headOnly);
}

function restoreApplicationDomainHistory(PDO $connection): never
{
    $identity = requireAdministratorIdentity($connection);
    ensureDomainStoreInitialized($connection);
    $body = readJsonBody(16 * 1024);
    $key = trim((string) ($body['key'] ?? ''));
    $historyId = max(0, (int) ($body['historyId'] ?? 0));
    $expectedRevision = max(0, (int) ($body['expectedRevision'] ?? 0));
    if (!validApplicationDomainKey($key) || $historyId <= 0) {
        sendError(400, 'Révision historique invalide.', 'invalid_domain_history');
    }
    $connection->beginTransaction();
    try {
        $clock = domainClockRecord($connection, true);
        $records = applicationDomainRecords($connection, [$key]);
        $current = $records[$key] ?? null;
        $currentRevision = (int) ($current['revision'] ?? 0);
        if ($expectedRevision !== $currentRevision) {
            $connection->rollBack();
            sendJson(409, [
                'ok' => false,
                'error' => 'Le domaine a changé depuis l’ouverture de son historique.',
                'code' => 'domain_revision_conflict',
                'revision' => $clock['globalRevision'],
                'conflicts' => [[
                    'key' => $key,
                    'expectedRevision' => $expectedRevision,
                    'currentRevision' => $currentRevision,
                ]],
            ]);
        }
        $select = $connection->prepare(
            'SELECT payload FROM application_domain_history '
            . 'WHERE history_id = :history_id AND domain_key = :domain_key LIMIT 1 FOR UPDATE'
        );
        $select->execute([':history_id' => $historyId, ':domain_key' => $key]);
        $record = $select->fetch();
        if (!is_array($record)) {
            $connection->rollBack();
            sendError(404, 'Cette révision historique n’existe plus.', 'domain_history_missing');
        }
        $payload = validatedDomainPayload($key, jsonColumn($record['payload'] ?? null));
        $pending = [['key' => $key, 'operation' => 'upsert', 'payload' => $payload, 'current' => $current]];
        $revision = persistDomainChangesInTransaction($connection, $identity, $clock, $pending);
        $domainRevision = $currentRevision + 1;
        $connection->commit();
        cleanupApplicationDomainHistory($connection);
        sendJson(200, [
            'ok' => true,
            'revision' => $revision,
            'domains' => [[
                'key' => $key,
                'revision' => $domainRevision,
                'operation' => 'upsert',
            ]],
        ]);
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
}

function handleDomainRoute(PDO $connection, string $route, string $method, bool $headOnly): bool
{
    if ($route === '/api/v1/state/domains') {
        if ($method === 'GET' || $method === 'HEAD') {
            readApplicationDomains($connection, $headOnly);
        }
        if ($method === 'PATCH') {
            patchApplicationDomains($connection);
        }
        requireMethod($method, ['GET', 'HEAD', 'PATCH']);
    }
    if ($route === '/api/v1/state/domains/history') {
        requireMethod($method, ['GET', 'HEAD']);
        listApplicationDomainHistory($connection, $headOnly);
    }
    if ($route === '/api/v1/state/domains/history/restore') {
        requireMethod($method, ['POST']);
        restoreApplicationDomainHistory($connection);
    }
    return false;
}
