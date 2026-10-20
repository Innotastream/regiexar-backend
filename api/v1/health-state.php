<?php
declare(strict_types=1);

// Shared health rules: map, overlays and item interactions keep one authority.
function onlineConditionKey(string $value): string
{
    $value = preg_replace('/[\x{0300}-\x{036f}]/u', '', $value) ?? '';
    return trim(preg_replace('/\s+/u', ' ', preg_replace("/[’'_-]/u", ' ', strtolower(strtr(trim($value), [
        'À' => 'a', 'Â' => 'a', 'Ä' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a',
        'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'Î' => 'i', 'Ï' => 'i', 'î' => 'i', 'ï' => 'i', 'Ô' => 'o', 'Ö' => 'o', 'ô' => 'o', 'ö' => 'o',
        'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'Ç' => 'c', 'ç' => 'c',
    ]))) ?? '') ?? '');
}

function onlineManualDeath(array $entity): bool
{
    if (array_key_exists('healthOverride', $entity)) return $entity['healthOverride'] === 'dead';
    $conditions = is_array($entity['conditions'] ?? null) ? $entity['conditions'] : preg_split('/[,;\r\n]+/u', (string) ($entity['condition'] ?? ''));
    foreach (is_array($conditions) ? $conditions : [] as $condition) {
        if (is_string($condition) && in_array(onlineConditionKey($condition), ['mort', 'morte', 'dead'], true)) return true;
    }
    return false;
}

function onlineHealthState(mixed $current, mixed $maximum, bool $playerControlled = false, bool $manualDeath = false): array
{
    $hp = is_numeric($current) && is_finite((float) $current) ? (float) $current : 0.0;
    $maxHp = is_numeric($maximum) && is_finite((float) $maximum) ? max(0.0, (float) $maximum) : 0.0;
    $percentage = $maxHp > 0 ? max(0.0, min(100.0, $hp / $maxHp * 100)) : 0.0;
    if ($manualDeath || ($playerControlled ? $hp < -$maxHp / 4 : $hp < 0)) {
        return ['code' => 'dead', 'effect' => 'Mort', 'percentage' => $percentage];
    }
    if ($hp <= 0) return ['code' => 'down', 'effect' => 'KO', 'percentage' => $percentage];
    return $maxHp > 0 && $percentage <= 10
        ? ['code' => 'critical', 'effect' => 'Critique', 'percentage' => $percentage]
        : ['code' => 'normal', 'effect' => '', 'percentage' => $percentage];
}
