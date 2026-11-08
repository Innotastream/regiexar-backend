<?php
declare(strict_types=1);
// Frozen renderer before the byte-cell optimization, used only as a test oracle.
function referenceVisionRenderMask(array $occlusion, array $origins, mixed $gridSize): array
{
    $resolved = applicationResolveVisionRelays($occlusion, $origins, $gridSize);
    $sightOcclusion = [...$occlusion, 'walls' => $resolved['sightWalls']];
    $strict = $resolved['mask'];
    if (!$strict['enabled']) return $strict;
    $width = $strict['width']; $height = $strict['height'];
    $strictBytes = applicationWallMaskBytes($strict);
    if ($strictBytes === null) return $strict;
    $tokenBytes = $strictBytes;
    $opacity = str_repeat("\xff", $width * $height);
    for ($index = 0; $index < $width * $height; ++$index) {
        if (!applicationMaskBit($strictBytes, $index)) $opacity[$index] = "\0";
    }
    $naturalWidth = max(1.0, (float) ($occlusion['naturalWidth'] ?? 1600));
    $naturalHeight = max(1.0, (float) ($occlusion['naturalHeight'] ?? 900));
    $grid = max(12.0, min(240.0, is_numeric($gridSize) ? (float) $gridSize : 50.0));
    $vision = normalizeApplicationVisionSettings($occlusion['vision'] ?? null);
    $environmentLighting = array_key_exists('lightingMode', $occlusion);
    foreach ($resolved['origins'] as $origin) {
        if (!is_array($origin) || !is_numeric($origin['x'] ?? null) || !is_numeric($origin['y'] ?? null)) continue;
        $originDistance = applicationOriginVisionDistance($origin, $vision);
        $fadeDistance = $environmentLighting ? applicationVisionFadeDistance($originDistance) : 6;
        $extended = applicationComputeBaseVisionMask($sightOcclusion, [$origin], $grid, $fadeDistance);
        $extendedBytes = applicationWallMaskBytes($extended);
        if ($extendedBytes === null) continue;
        if (($resolved['viewerSeesFade'] ?? false) === true) $tokenBytes = $tokenBytes & $extendedBytes;
        $radius = $originDistance * $grid;
        $centerX = max(0.0, min(100.0, (float) $origin['x'])) / 100 * max(1, $width - 1);
        $centerY = max(0.0, min(100.0, (float) $origin['y'])) / 100 * max(1, $height - 1);
        for ($index = 0; $index < $width * $height; ++$index) {
            if ($opacity[$index] === "\0" || applicationMaskBit($extendedBytes, $index)) continue;
            $distance = hypot(($index % $width - $centerX) / max(1, $width - 1) * $naturalWidth,
                (intdiv($index, $width) - $centerY) / max(1, $height - 1) * $naturalHeight);
            $t = max(0.0, min(1.0, ($distance - $radius) / ($fadeDistance * $grid)));
            $alpha = (int) round(255 * $t * $t * (3 - 2 * $t));
            if ($alpha < ord($opacity[$index])) $opacity[$index] = chr($alpha);
        }
    }
    $strict['opacity'] = rtrim(strtr(base64_encode($opacity), '+/', '-_'), '=');
    // Visual fog cannot override wall occlusion. This second mask reaches the
    // map edge so distant open ground remains translucent while the space
    // behind a wall stays completely black, irrespective of vision distance.
    $walls = $occlusion['walls'] ?? [];
    $strict['occludedMask'] = ($walls['mask'] ?? '') === '' ? ''
        : ($resolved['origins'] === [] ? $strict['mask']
            : applicationComputeBaseVisionMask($occlusion, $resolved['origins'], $grid,
                (int) ceil(hypot($naturalWidth, $naturalHeight) / $grid) + 1)['mask']);
    if (($resolved['viewerSeesFade'] ?? false) === true) {
        $strict['tokenMask'] = rtrim(strtr(base64_encode($tokenBytes), '+/', '-_'), '=');
    }
    return $strict;
}
