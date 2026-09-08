<?php

declare(strict_types=1);

// Older maps may have no fog. Computed vision is always an array; enabled
// malformed masks continue to fail closed below.
function onlineVisiblePathPointTester(?array $fog, array $vision): Closure
{
    $masks = [];
    foreach ([$fog, $vision] as $mask) {
        if (($mask['enabled'] ?? false) !== true) continue;
        $bytes = applicationFogMaskBytes(['version' => XAR_FOG_MASK_VERSION, 'enabled' => true, 'width' => $mask['width'] ?? null, 'height' => $mask['height'] ?? null, 'mask' => $mask['mask'] ?? null]);
        if ($bytes === null) return static fn (float $x, float $y): bool => false;
        $rows = [];
        for ($index = 0, $length = $mask['width'] * $mask['height']; $index < $length; $index++) {
            if (applicationMaskBit($bytes, $index)) $rows[intdiv($index, $mask['width'])][] = $index % $mask['width'];
        }
        $masks[] = [$mask, $rows];
    }
    // The optional radius covers every hidden cell intersecting the portrait,
    // not only its centre. Walls and fog never get the decorative edge margin.
    return static function (float $x, float $y, float $radius = 0.0, float $naturalWidth = 1600.0, float $naturalHeight = 900.0) use ($masks): bool {
        if (!is_finite($x) || !is_finite($y) || $x < 0 || $x > 100 || $y < 0 || $y > 100) return false;
        foreach ($masks as [$mask, $rows]) {
            $cx = $x / 100 * ($mask['width'] - 1); $cy = $y / 100 * ($mask['height'] - 1);
            $rx = $radius / $naturalWidth * ($mask['width'] - 1); $ry = $radius / $naturalHeight * ($mask['height'] - 1);
            for ($row = max(0, (int) floor($cy - $ry - 0.5)), $end = min($mask['height'] - 1, (int) ceil($cy + $ry + 0.5)); $row <= $end; $row++) {
                if (!isset($rows[$row])) continue;
                $ny = max(0.0, abs($row - $cy) - 0.5) / max($ry, 1e-12);
                if ($ny > 1.0) continue;
                $columns = $rows[$row]; $lo = 0; $hi = count($columns);
                while ($lo < $hi) { $mid = ($lo + $hi) >> 1; if ($columns[$mid] < $cx) $lo = $mid + 1; else $hi = $mid; }
                $distance = min(isset($columns[$lo]) ? abs($columns[$lo] - $cx) : INF, $lo > 0 ? abs($columns[$lo - 1] - $cx) : INF);
                $nx = max(0.0, $distance - 0.5) / max($rx, 1e-12);
                if ($nx * $nx + $ny * $ny <= 1.0) return false;
            }
        }
        return true;
    };
}

// Exact ellipse collision, indexed by occupied row. Testing the nearest wall
// cell on each row is equivalent to scanning every cell in the footprint.
function applicationPathPositionValidator(array $walls, float $size, float $naturalWidth, float $naturalHeight): Closure
{
    $bytes = applicationWallMaskBytes($walls); $rows = [];
    $width = (int) $walls['width']; $height = (int) $walls['height'];
    if ($bytes !== null) for ($i = 0, $length = strlen($bytes); $i < $length; $i++) {
        $byte = ord($bytes[$i]); if ($byte === 0) continue;
        for ($bit = 0; $bit < 8; $bit++) if ($byte & (1 << $bit)) {
            $index = $i * 8 + $bit; $rows[intdiv($index, $width)][] = $index % $width;
        }
    }
    $radius = max(10.0, min(220.0, $size > 0 ? $size : 40.0)) / 2;
    $rx = max(0.75, $radius / $naturalWidth * ($width - 1));
    $ry = max(0.75, $radius / $naturalHeight * ($height - 1));
    return static function(array $point) use ($rows, $width, $height, $rx, $ry): bool {
        $cx = $point['x'] / 100 * ($width - 1); $cy = $point['y'] / 100 * ($height - 1);
        for ($y = max(0, (int) floor($cy - $ry - 1)), $end = min($height - 1, (int) ceil($cy + $ry + 1)); $y <= $end; $y++) {
            if (!isset($rows[$y])) continue;
            $ny = ($y - $cy) / ($ry + 0.5); if ($ny * $ny > 1.0) continue;
            $columns = $rows[$y]; $lo = 0; $hi = count($columns);
            while ($lo < $hi) { $mid = ($lo + $hi) >> 1; if ($columns[$mid] < $cx) $lo = $mid + 1; else $hi = $mid; }
            $distance = min(isset($columns[$lo]) ? abs($columns[$lo] - $cx) : INF, $lo > 0 ? abs($columns[$lo - 1] - $cx) : INF);
            $nx = $distance / ($rx + 0.5);
            if ($nx * $nx + $ny * $ny <= 1.0) return false;
        }
        return true;
    };
}

// One straight segment or two segments with a single bend. There is no A*
// waypoint chain, and no destination adjustment into another room.
function findApplicationVisibleTokenPath(array $walls, array $origin, array $goal, float $tokenSize, float $naturalWidth, float $naturalHeight, Closure $visible, int $maximumVisited = 65536): array
{
    $deadline = hrtime(true) + 125000000;
    $failed = static fn (string $reason): array => [...$origin, 'blocked' => true, 'path' => [], 'reason' => $reason];
    foreach ([$origin['x'], $origin['y'], $goal['x'], $goal['y']] as $value) if (!is_numeric($value) || !is_finite((float) $value) || $value < 0 || $value > 100) return $failed('invalid-position');
    if (!is_finite($naturalWidth) || !is_finite($naturalHeight) || $naturalWidth <= 0 || $naturalHeight <= 0) return $failed('invalid-position');
    $width = (int) $walls['width']; $height = (int) $walls['height'];
    $diameter = max(10.0, min(220.0, $tokenSize > 0 ? $tokenSize : 40.0)); $radius = $diameter / 2;
    // One natural pixel at the portrait edge; never shrink the wall raster or
    // the visible disk. This only removes decorative edge snagging.
    $wallFree = applicationPathPositionValidator($walls, $diameter - 2.0, $naturalWidth, $naturalHeight);
    $visibleDisk = static fn (array $point): bool => $visible($point['x'], $point['y'], $radius, $naturalWidth, $naturalHeight);
    $inBounds = static fn (array $point): bool => $point['x'] * $naturalWidth / 100 >= $radius && $point['x'] * $naturalWidth / 100 <= $naturalWidth - $radius
        && $point['y'] * $naturalHeight / 100 >= $radius && $point['y'] * $naturalHeight / 100 <= $naturalHeight - $radius;
    $free = static fn (array $point): bool => $inBounds($point) && $wallFree($point) && $visibleDisk($point);
    $wallBytes = applicationWallMaskBytes($walls);
    $goalWallIndex = (int) round($goal['y'] / 100 * ($height - 1)) * $width + (int) round($goal['x'] / 100 * ($width - 1));
    if (!$visibleDisk($goal) || ($wallBytes !== null && applicationMaskBit($wallBytes, $goalWallIndex))) return $failed('destination-hidden-or-wall');
    if (!$free($goal)) return $failed('destination-too-narrow');
    $distance = static fn (array $a, array $b): float => hypot(($a['x'] - $b['x']) * $naturalWidth / 100, ($a['y'] - $b['y']) * $naturalHeight / 100);
    $recoveryOrigin = $origin;
    if (!$free($origin)) {
        $rasterDistance = hypot(($goal['x'] - $origin['x']) * ($width - 1) / 100, ($goal['y'] - $origin['y']) * ($height - 1) / 100);
        $progress = $rasterDistance > 0 ? min(1.0, 0.02 / $rasterDistance) : 0.0;
        $point = ['x' => $origin['x'] + ($goal['x'] - $origin['x']) * $progress, 'y' => $origin['y'] + ($goal['y'] - $origin['y']) * $progress];
        $recovery = applicationResolveWallCollision($walls, $origin, $point, $diameter - 2.0, $naturalWidth, $naturalHeight, false);
        if (!$progress || !$inBounds($origin) || !$visibleDisk($origin) || $recovery['blocked'] || !$free($point)) return $failed('start-overlaps-wall');
        $recoveryOrigin = $point;
    }
    $segmentFree = static function (array $a, array $b) use ($width, $height, $free): bool {
        $requiredSteps = max(1, (int) ceil(max(abs($a['x'] - $b['x']) * ($width - 1), abs($a['y'] - $b['y']) * ($height - 1)) / 100 * 2));
        if (!$free($a) || !$free($b)) return false;
        // Midpoint first for cheap rejection of a separating wall, then refine
        // until every accepted segment is sampled at least every half-cell.
        for ($divisions = 2; $divisions < $requiredSteps * 2; $divisions *= 2) {
            for ($i = 1; $i < $divisions; $i += 2) if (!$free(['x' => $a['x'] + ($b['x'] - $a['x']) * $i / $divisions, 'y' => $a['y'] + ($b['y'] - $a['y']) * $i / $divisions])) return false;
        }
        return true;
    };
    $completed = static function (?array $bend) use ($origin, $goal, $recoveryOrigin, $free, $segmentFree, $failed): array {
        $path = array_map(static fn (array $point): array => ['x' => round($point['x'], 6), 'y' => round($point['y'], 6)], $bend === null ? [$origin, $goal] : [$origin, $bend, $goal]);
        $checkedStart = $recoveryOrigin === $origin ? $path[0] : $recoveryOrigin;
        if (!$free($path[count($path) - 1]) || ($bend !== null && !$free($path[1])) || !$segmentFree($checkedStart, $path[1]) || ($bend !== null && !$segmentFree($path[1], $path[2]))) return $failed('unsafe-rounding');
        return [...$path[count($path) - 1], 'blocked' => false, 'path' => $path, 'adjusted' => false];
    };
    if ($segmentFree($recoveryOrigin, $goal)) return $completed(null);
    if ($recoveryOrigin !== $origin) return $failed('start-overlaps-wall');
    // Preserve sub-cell corridor alignments before raster candidates.
    foreach ([['x' => $origin['x'], 'y' => $goal['y']], ['x' => $goal['x'], 'y' => $origin['y']]] as $bend) {
        if ($free($bend) && $segmentFree($origin, $bend) && $segmentFree($bend, $goal)) return $completed($bend);
    }
    $seen = str_repeat("\0", $width * $height); $visited = 0; $limit = max(1, min(65536, $maximumVisited));
    // Keep the shared table transaction bounded when many players try an
    // impossible route at once. A further move can use an intermediate point.
    $bucketScale = 512 / hypot($naturalWidth, $naturalHeight);
    foreach ([16, 8, 4, 2, 1] as $stride) {
        $candidates = [];
        for ($y = 0; $y < $height; $y += $stride) {
            if (hrtime(true) > $deadline) return $failed('search-limit');
            for ($x = 0; $x < $width; $x += $stride) {
            $id = $y * $width + $x;
            if ($seen[$id] !== "\0") continue;
            $seen[$id] = "\1";
            $point = ['x' => $x / ($width - 1) * 100, 'y' => $y / ($height - 1) * 100];
            $bucket = (int) floor(($distance($origin, $point) + $distance($point, $goal)) * $bucketScale);
            $candidates[$bucket][] = $point;
            }
        }
        // At most 1,025 numeric buckets; never sort tens of thousands of cells.
        ksort($candidates, SORT_NUMERIC);
        foreach ($candidates as $bucket) foreach ($bucket as $point) {
            if (++$visited > $limit || hrtime(true) > $deadline) return $failed('search-limit');
            if ($free($point) && $segmentFree($origin, $point) && $segmentFree($point, $goal)) return $completed($point);
        }
    }
    return $failed('no-two-segment-path');
}
