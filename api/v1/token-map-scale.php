<?php
declare(strict_types=1);

const XAR_UNIT_SIZE_CATEGORIES = ['small', 'normal', 'large', 'huge', 'custom'];
const XAR_UNIT_SIZE_MAXIMUM = 1000;

function applicationUnitSizeCategoryValid(mixed $value): bool {
    return is_string($value) && in_array($value, XAR_UNIT_SIZE_CATEGORIES, true);
}

function applicationUnitScalesWithMap(array $token): bool {
    return empty($token['terrainBarrier']) && empty($token['fixedStructure']);
}

function applicationUnitSizeCategory(array $token): string {
    if (!applicationUnitScalesWithMap($token)) return 'custom';
    if (applicationUnitSizeCategoryValid($token['sizeCategory'] ?? null)) return $token['sizeCategory'];
    static $defaults = null;
    $defaults ??= json_decode(file_get_contents(__DIR__ . '/character-size-presets.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach (empty($token['linkedTokenId']) ? array_map(static fn($key)=>(string)($token[$key]??''), ['characterId','libraryTemplateId','id','cloneSourceCharacterId','cloneSourceTokenId']) : [] as $id) {
        if (isset($defaults[$id])) return $defaults[$id];
    }
    $size = is_numeric($token['size'] ?? null) && is_finite((float)$token['size']) && $token['size']>0 ? (float)$token['size'] : 40.0;
    return match ($size) { 20.0, 30.0 => 'small', 40.0 => 'normal', 60.0 => 'large', 80.0, 100.0 => 'huge', default => 'custom' };
}

function applicationMapScaleSettings(array $map, ?string $layerId = null): array {
    $layerId ??= (string)($map['activeLayerId'] ?? 'ground');
    $layer = is_array($map['layers'][$layerId] ?? null) ? $map['layers'][$layerId] : [];
    $number = static fn(mixed $value): bool => (is_int($value) || is_float($value)) && is_finite((float)$value);
    $preset = $layer['unitScalePreset'] ?? $map['unitScalePreset'] ?? 'outdoor';
    $grid = $number($layer['gridSize'] ?? null) && $layer['gridSize'] > 0 ? $layer['gridSize'] : ($number($map['gridSize'] ?? null) && $map['gridSize'] > 0 ? $map['gridSize'] : ($preset === 'indoor' ? 75 : 50));
    $settings = ['gridSize' => max(12, min(240, $grid))];
    foreach (['gridOffsetX', 'gridOffsetY'] as $key) $settings[$key] = max(-240, min(240, $number($layer[$key] ?? null) ? $layer[$key] : ($number($map[$key] ?? null) ? $map[$key] : 0)));
    $settings['gridSize']=round($settings['gridSize'],3);
    $settings['unitScalePreset'] = $preset==='custom' ? 'custom' : ($settings['gridSize']===50||$settings['gridSize']===50.0?'outdoor':($settings['gridSize']===75||$settings['gridSize']===75.0?'indoor':'custom'));
    return $settings;
}

function applicationMapForUnitLayer(array $map, ?string $layerId = null): array {
    $layerId ??= (string)($map['activeLayerId'] ?? 'ground');
    return array_replace($map, is_array($map['layers'][$layerId] ?? null) ? $map['layers'][$layerId] : [], applicationMapScaleSettings($map, $layerId), ['activeLayerId' => $layerId]);
}

// Only creation and arrival call this; ordinary reads and movements keep stored size.
function applicationScaleUnitForMap(array $token, array $map, ?string $layerId = null): array {
    if (!applicationUnitScalesWithMap($token)) return $token;
    $category = applicationUnitSizeCategory($token);
    $token['sizeCategory'] = $category;
    if ($category === 'custom') {
        $token['size'] = max(10, min(XAR_UNIT_SIZE_MAXIMUM, is_numeric($token['size'] ?? null) && is_finite((float)$token['size']) ? (float)$token['size'] : 40));
        return $token;
    }
    $base = ['small' => 30, 'normal' => 40, 'large' => 60, 'huge' => 100][$category];
    $token['size'] = max(10, min(XAR_UNIT_SIZE_MAXIMUM, round($base * applicationMapScaleSettings($map, $layerId)['gridSize'] / 50, 3)));
    return $token;
}

function applicationValidMapScaleSettings(array $value): bool {
    if (array_key_exists('unitScalePreset', $value) && !in_array($value['unitScalePreset'], ['outdoor', 'indoor', 'custom'], true)) return false;
    foreach (['gridSize', 'gridOffsetX', 'gridOffsetY'] as $key) {
        if (!array_key_exists($key, $value)) continue;
        $number = $value[$key];
        if ((!is_int($number) && !is_float($number)) || !is_finite((float)$number)) return false;
        if ($key === 'gridSize' && ($number < 12 || $number > 240)) return false;
        if ($key !== 'gridSize' && abs($number) > 240) return false;
    }
    return true;
}
