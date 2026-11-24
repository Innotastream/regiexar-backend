<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/v1/domains.php';
require_once __DIR__ . '/../api/v1/token-pathfinding.php';

$walls = ['version' => 1, 'width' => 512, 'height' => 288];
$bytes = str_repeat("\0", 512 * 288 / 8);
for ($y = 0; $y < 288; $y++) applicationSetMaskBit($bytes, $y * 512 + 256, true);
$walls['mask'] = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
$strict = applicationPathPositionValidator($walls, 40, 1600, 900);
$margin = applicationPathPositionValidator($walls, 38, 1600, 900);
$touch = ['x' => 48.791066394620515, 'y' => 50.0];
if ($strict($touch) || !$margin($touch)) throw new RuntimeException('Fixture must overlap the wall by less than one natural pixel.');
$clear = ['x' => 40.0, 'y' => 50.0];
$visible = static fn (float $x, float $y): bool => true;
$blocked = findApplicationVisibleTokenPath($walls, $clear, $touch, 40, 1600, 900, $visible);
if (!$blocked['blocked'] || $blocked['path'] !== [] || $blocked['x'] !== $clear['x']) throw new RuntimeException('A destination partly inside a wall was accepted.');
$escaped = findApplicationVisibleTokenPath($walls, $touch, $clear, 40, 1600, 900, $visible);
if ($escaped['blocked'] || $escaped['x'] !== $clear['x']) throw new RuntimeException('An existing shallow contact cannot escape.');
$deep = ['x' => $touch['x'] + 0.125, 'y' => 50.0];
$blocked = findApplicationVisibleTokenPath($walls, $deep, $clear, 40, 1600, 900, $visible);
if ($blocked['blocked'] || $blocked['x'] !== $clear['x']) throw new RuntimeException('An inherited overlap must be able to retreat on the same side.');
$hiddenEdge = static fn (float $x, float $y, float $radius = 0): bool => $radius === 0 || $x < 45;
$blocked = findApplicationVisibleTokenPath($walls, $touch, $clear, 40, 1600, 900, $hiddenEdge);
if (!$blocked['blocked']) throw new RuntimeException('Contact recovery crossed a hidden footprint.');
$openWalls = ['version' => 1, 'width' => 512, 'height' => 288, 'mask' => ''];
$edgeBytes = str_repeat("\0", 512 * 288 / 8);
for ($y = 0; $y < 288; ++$y) for ($x = 264; $x < 512; ++$x) applicationSetMaskBit($edgeBytes, $y * 512 + $x, true);
$edge = ['version' => 1, 'enabled' => true, 'width' => 512, 'height' => 288, 'mask' => rtrim(strtr(base64_encode($edgeBytes), '+/', '-_'), '=')];
$start = ['x' => 47.0, 'y' => 50.0]; $goal = ['x' => 50.0, 'y' => 50.0];
$visionEdge = onlineVisiblePathPointTester(null, $edge);
$allowed = findApplicationVisibleTokenPath($openWalls, $start, $goal, 60, 1600, 900, $visionEdge);
if ($allowed['blocked']) throw new RuntimeException('A shallow vision fringe rejected a slightly oversize portrait.');
$paintedEdge = onlineVisiblePathPointTester($edge, ['enabled' => false]);
$blocked = findApplicationVisibleTokenPath($openWalls, $start, $goal, 60, 1600, 900, $paintedEdge);
if (!$blocked['blocked']) throw new RuntimeException('The vision fringe allowance leaked into painted fog.');

// The row stride may cross byte boundaries. A hidden cell at the beginning
// of the next row must not be picked up by the right edge of the current row.
$rowBytes = str_repeat("\0", (int) ceil(33 * 35 / 8));
applicationSetMaskBit($rowBytes, 17 * 33, true);
$rowFog = ['version'=>1,'enabled'=>true,'width'=>33,'height'=>35,
    'mask'=>rtrim(strtr(base64_encode($rowBytes), '+/', '-_'), '=')];
$edgeCoverage = onlineVisiblePathPointTester($rowFog, ['enabled'=>false]);
if (!$edgeCoverage(100, 16 / 34 * 100) || $edgeCoverage(0, 17 / 34 * 100)) {
    throw new RuntimeException('Visibility search crossed a non-byte-aligned raster row.');
}
$touchesBoth = onlineVisiblePathPointTester($rowFog, ['enabled'=>false]);
if ($touchesBoth(0, 16.5 / 34 * 100)) {
    throw new RuntimeException('A centre at two cell boundaries must still detect painted fog.');
}
echo "Full destinations are enforced; inherited contacts may recede without crossing walls or fog.\n";
