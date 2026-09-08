<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/v1/domains.php';
require_once __DIR__ . '/../api/v1/online.php';
$cases = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$results = [];
foreach ($cases as $case) {
    try {
        if ($case['kind'] === 'collision-index') {
            $result = true;
            foreach ([20, 40, 60, 80] as $size) {
                $indexed = applicationPathPositionValidator($case['walls'], $size, 1600.0, 900.0);
                $original = onlineGroupPositionValidator(['walls' => $case['walls'], 'naturalWidth' => 1600, 'naturalHeight' => 900]);
                for ($x = 35.25; $x < 65; $x += 1.5) for ($y = 50.25; $y < 70; $y += 0.7) {
                    $point = ['x' => $x, 'y' => $y];
                    if ($indexed($point) !== $original($point, ['size' => $size])) throw new RuntimeException('Collision indexée différente : ' . json_encode([$point, $size]));
                }
            }
        } elseif ($case['kind'] === 'path') {
            $visible = onlineVisiblePathPointTester($case['fog'] ?? [], $case['vision'] ?? []);
            $result = findApplicationVisibleTokenPath($case['walls'], $case['start'], $case['goal'], $case['size'], 1600.0, 900.0, $visible, 8000);
        } elseif ($case['kind'] === 'damage') $result = onlineRollAttackDamage($case['attack'], $case['target']);
        elseif ($case['kind'] === 'ability') $result = ['valid' => validApplicationAbilities([$case['ability']]), 'normalized' => normalizeOnlineAbilities([$case['ability']])];
        elseif ($case['kind'] === 'form') $result = planApplicationMetamorphosis($case['source'], $case['characters'], $case['tokens'], $case['ability'], $case['request'], 'owner', false, $case['pendingAttacks'] ?? []);
        elseif ($case['kind'] === 'layers') $result = planApplicationTokenLayers($case['map'], $case['request']);
        elseif ($case['kind'] === 'legacy') $result = preserveApplicationAbilityExtensions($case['key'], $case['payload'], $case['previous']);
        else throw new RuntimeException('Cas inconnu');
        $results[] = ['name' => $case['name'], 'result' => $result];
    } catch (Throwable $error) { $results[] = ['name' => $case['name'], 'error' => $error->getMessage()]; }
}
echo json_encode($results, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
