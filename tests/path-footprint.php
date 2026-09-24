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
if (!$blocked['blocked'] || $blocked['path'] !== []) throw new RuntimeException('A deep wall overlap was treated as decorative contact.');
$hiddenEdge = static fn (float $x, float $y, float $radius = 0): bool => $radius === 0 || $x < 45;
$blocked = findApplicationVisibleTokenPath($walls, $touch, $clear, 40, 1600, 900, $hiddenEdge);
if (!$blocked['blocked']) throw new RuntimeException('Contact recovery crossed a hidden footprint.');
echo "Full path footprints are enforced; only visible, shallow existing contacts may escape.\n";
