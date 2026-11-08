<?php
declare(strict_types=1);
// Frozen pre-optimization authority for byte-for-byte relay regression tests.
function referenceVisionRelays(array $occlusion, array $origins, mixed $gridSize): array
{
    $occlusion['walls'] = applicationVisionWallsWithFog($occlusion);
    $origins = applicationPreparedVisionOrigins($occlusion, $origins);
    $mask = applicationComputeBaseVisionMask($occlusion, $origins, $gridSize);
    // The halo detects an anonymous presence for every observer; only the
    // strict mask permits the full token and its sheet to be projected.
    $viewerSeesFade = $origins !== [];
    $result = ['mask' => $mask, 'origins' => $origins, 'activatedLightIds' => [], 'viewerSeesFade' => $viewerSeesFade, 'sightWalls' => $occlusion['walls']];
    if (!$mask['enabled'] || $origins === []) return $result;
    $bytes = applicationWallMaskBytes($mask);
    if ($bytes === null) return $result;
    $remaining = array_values(array_filter(normalizeApplicationMapLights($occlusion['lights'] ?? []),
        static fn (array $light): bool => $light['enabled'] && !applicationLightCenterBlocked($occlusion, $light)));
    $remoteMasks = [];
    $lightingMode = normalizeApplicationMapLightingMode($occlusion['lightingMode'] ?? null);
    if (array_key_exists('lightingMode', $occlusion) && in_array($lightingMode, ['dark', 'normal'], true)) {
        foreach ($origins as $origin) {
            $remoteMasks[] = applicationComputeBaseVisionMask($occlusion, [[
                ...$origin,
                'visionDistance' => applicationRemoteLightDetectionDistance($origin['visionDistance'] ?? null, $lightingMode),
                'environmentAdjusted' => true,
            ]], $gridSize);
        }
    }
    // Hidden bits combine with AND: each new relay contributes exactly once.
    $distantBytes = null;
    if (array_filter($remaining,static fn(array $light):bool=>($light['visibleFromAfar']??false)===true)) {
        $distance = (int) ceil(hypot((float)($occlusion['naturalWidth']??1600),(float)($occlusion['naturalHeight']??900))/max(12,(float)($gridSize?:50)))+1;
        $distantBytes = applicationWallMaskBytes(applicationComputeBaseVisionMask($occlusion,$origins,$gridSize,$distance));
    }
    // Initial token origins keep their own forty slots; lights never displace them.
    do {
        $changed = false;
        foreach ($remaining as $index => $light) {
            $column = (int) round($light['x'] / 100 * ($mask['width'] - 1));
            $row = (int) round($light['y'] / 100 * ($mask['height'] - 1));
            $remotelyVisible = ($light['visibleFromAfar']??false) === true && $distantBytes !== null && !applicationMaskBit($distantBytes,$row*$mask['width']+$column);
            foreach ($remoteMasks as $remoteMask) {
                $remoteBytes = applicationWallMaskBytes($remoteMask);
                if ($remoteBytes !== null && !applicationMaskBit($remoteBytes, $row * $mask['width'] + $column)) {
                    $remotelyVisible = true;
                    break;
                }
            }
            if (applicationMaskBit($bytes, $row * $mask['width'] + $column) && !$remotelyVisible) continue;
            $origin = [
                'x' => $light['x'], 'y' => $light['y'], 'visionDistance' => $light['visionDistance'],
                'darkVision' => 'none', 'source' => 'light', 'environmentAdjusted' => true,
            ];
            $addition = applicationComputeBaseVisionMask($occlusion, [$origin], $gridSize);
            $additionBytes = applicationWallMaskBytes($addition);
            if ($additionBytes === null) continue;
            $bytes = $bytes & $additionBytes;
            $result['origins'][] = $origin;
            $result['activatedLightIds'][] = $light['id'];
            unset($remaining[$index]);
            $changed = true;
        }
    } while ($changed && $remaining !== []);
    $mask['mask'] = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    $result['mask'] = $mask;
    return $result;
}
