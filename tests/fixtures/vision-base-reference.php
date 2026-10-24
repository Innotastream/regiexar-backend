<?php
// Baseline visibility engine from backend 69114b710b4e898d77a24af49e7a8b6ed71eb1b4.
// Test-only reference; never loaded by the application.
function referenceVisionBaseMask(array $occlusion, array $origins, mixed $gridSize, int $distanceExtension = 0): array
{
    $walls = $occlusion['walls'];
    $vision = normalizeApplicationVisionSettings($occlusion['vision'] ?? null);
    $width = (int) $walls['width'];
    $height = (int) $walls['height'];
    if (!$vision['enabled']) {
        return ['version' => XAR_WALL_MASK_VERSION, 'enabled' => false, 'width' => $width, 'height' => $height, 'mask' => ''];
    }
    $wallBytes = applicationWallMaskBytes($walls) ?? str_repeat("\0", (int) ceil(($width * $height) / 8));
    $wallCellIndexes = [];
    if (($walls['mask'] ?? '') !== '') {
        for ($index = 0; $index < $width * $height; $index += 1) {
            if (applicationMaskBit($wallBytes, $index)) {
                $wallCellIndexes[] = $index;
            }
        }
    }
    $hiddenBytes = str_repeat("\xff", (int) ceil(($width * $height) / 8));
    $naturalWidth = max(1.0, (float) ($occlusion['naturalWidth'] ?? 1600));
    $naturalHeight = max(1.0, (float) ($occlusion['naturalHeight'] ?? 900));
    $safeGridSize = max(12.0, min(240.0, is_numeric($gridSize) ? (float) $gridSize : 50.0));
    $firstWallBodyReachesCell = static function (
        float $centerX,
        float $centerY,
        int $targetColumn,
        int $targetRow
    ) use ($wallBytes, $width, $height): bool {
        $deltaX = $targetColumn - $centerX;
        $deltaY = $targetRow - $centerY;
        $steps = max(1, (int) ceil(max(abs($deltaX), abs($deltaY)) * 2));
        $previousIndex = -1;
        $wallEncountered = false;
        for ($step = 1; $step <= $steps; $step += 1) {
            $progress = $step / $steps;
            $column = (int) round($centerX + $deltaX * $progress);
            $row = (int) round($centerY + $deltaY * $progress);
            if ($column < 0 || $column >= $width || $row < 0 || $row >= $height) {
                return false;
            }
            $cellIndex = $row * $width + $column;
            if ($cellIndex === $previousIndex) {
                continue;
            }
            $previousIndex = $cellIndex;
            $cellIsWall = applicationMaskBit($wallBytes, $cellIndex);
            if ($wallEncountered && !$cellIsWall) {
                return false;
            }
            if ($cellIsWall) {
                $wallEncountered = true;
            }
            if ($column === $targetColumn && $row === $targetRow) {
                return $cellIsWall;
            }
        }
        return false;
    };
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
        $radiusNatural = (applicationOriginVisionDistance($origin, $vision) + $distanceExtension) * $safeGridSize;
        $radiusX = max(1.0, $radiusNatural / $naturalWidth * max(1, $width - 1));
        $radiusY = max(1.0, $radiusNatural / $naturalHeight * max(1, $height - 1));
        $ellipseContainsCell = static function (int $column, int $row, float $centerX, float $centerY) use ($radiusX, $radiusY): bool {
            $deltaX = ($column - $centerX) / $radiusX;
            $deltaY = ($row - $centerY) / $radiusY;
            return $deltaX * $deltaX + $deltaY * $deltaY <= 1.0 + 1.0e-12;
        };
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
            $wallEncountered = false;
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
                if (!$ellipseContainsCell($column, $row, $centerX, $centerY)) {
                    break;
                }
                $cellIsWall = applicationMaskBit($wallBytes, $cellIndex);
                if ($wallEncountered && !$cellIsWall) {
                    break;
                }
                applicationSetMaskBit($hiddenBytes, $cellIndex, false);
                if ($step > 0 && $cellIsWall) {
                    $wallEncountered = true;
                }
            }
        }
        // Le balayage angulaire garde le coût faible pour l'espace libre. Les
        // cellules du mur encore noires sont ensuite testées sur leur segment
        // exact : seul le premier corps solide rencontré est révélé, jamais un
        // second mur séparé par une cellule libre ni l'espace situé derrière.
        foreach ($wallCellIndexes as $cellIndex) {
            if (!applicationMaskBit($hiddenBytes, $cellIndex)) {
                continue;
            }
            $column = $cellIndex % $width;
            $row = intdiv($cellIndex, $width);
            if (!$ellipseContainsCell($column, $row, $centerX, $centerY)) {
                continue;
            }
            if ($firstWallBodyReachesCell($centerX, $centerY, $column, $row)) {
                applicationSetMaskBit($hiddenBytes, $cellIndex, false);
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

