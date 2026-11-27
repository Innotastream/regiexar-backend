<?php
declare(strict_types=1);

const XAR_UNIT_SIZE_CATEGORIES = ['small', 'normal', 'large', 'huge', 'custom'];
const XAR_UNIT_SIZE_MAXIMUM = 1000;
const XAR_MAP_SCALE_PRESETS = ['outdoor' => 40, 'indoor' => 60, 'wide' => 25];

function applicationMapScalePresetValid(mixed $value): bool {
    return is_string($value) && ($value === 'custom' || isset(XAR_MAP_SCALE_PRESETS[$value]));
}

function preserveApplicationLegacyMapScaleSettings(array $incoming, array $previous): array {
    // 4.4.5 sends a fully normalized map after any MJ edit. It only recognizes
    // the former 50/75 px presets and does not serialize the new sizing fields.
    $preserve = static function (array $level, array $current): array {
        if (!isset($level['gridSize'], $current['gridSize']) || $level['gridSize'] != $current['gridSize']) return $level;
        $preset = $level['unitScalePreset'] ?? null;
        if ($preset !== null && $preset !== 'custom' && $preset !== ($current['unitScalePreset'] ?? null)) return $level;
        foreach (['unitScalePreset', 'unitScaleAuto', 'unitScaleProfile'] as $field) {
            if (array_key_exists($field, $current)) $level[$field] = $current[$field];
        }
        return $level;
    };
    $active = $incoming['activeLayerId'] ?? 'ground';
    $activeBefore = $previous['activeLayerId'] ?? 'ground';
    $currentRoot = $active === $activeBefore ? $previous : ($previous['layers'][$active] ?? null);
    if (is_array($currentRoot)) $incoming = $preserve($incoming, $currentRoot);
    foreach ($incoming['layers'] ?? [] as $id => $level) {
        $current = $previous['layers'][$id] ?? null;
        if (is_array($level) && is_array($current)) $incoming['layers'][$id] = $preserve($level, $current);
    }
    return $incoming;
}

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
    $layer = is_array($map['layers'][$layerId] ?? null) ? $map['layers'][$layerId] : $map;
    $number = static fn(mixed $value): bool => (is_int($value) || is_float($value)) && is_finite((float)$value);
    $layerGrid = $number($layer['gridSize'] ?? null) && $layer['gridSize'] > 0 ? $layer['gridSize'] : null;
    $mapGrid = $number($map['gridSize'] ?? null) && $map['gridSize'] > 0 ? $map['gridSize'] : null;
    $ownPreset = applicationMapScalePresetValid($layer['unitScalePreset'] ?? null) ? $layer['unitScalePreset'] : null;
    $mapPreset = applicationMapScalePresetValid($map['unitScalePreset'] ?? null) ? $map['unitScalePreset'] : null;
    $preset = $ownPreset ?? ($layerGrid === null ? $mapPreset : null);
    $grid = $layerGrid ?? XAR_MAP_SCALE_PRESETS[$ownPreset] ?? $mapGrid ?? XAR_MAP_SCALE_PRESETS[$preset] ?? XAR_MAP_SCALE_PRESETS['outdoor'];
    $grid = round(max(12, min(240, $grid)), 3);
    if ($preset === null) {
        $preset = array_search((int)$grid, XAR_MAP_SCALE_PRESETS, true) ?: 'custom';
    }
    if ($preset !== 'custom' && $grid != XAR_MAP_SCALE_PRESETS[$preset]) $preset = 'custom';
    $settings = ['gridSize' => $grid];
    foreach (['gridOffsetX', 'gridOffsetY'] as $key) $settings[$key] = round(max(-240, min(240, $number($layer[$key] ?? null) ? $layer[$key] : ($number($map[$key] ?? null) ? $map[$key] : 0))), 3);
    $settings['unitScalePreset'] = $preset;
    $settings['unitScaleAuto'] = ($layer['unitScaleAuto'] ?? false) === true;
    $profile = $layer['unitScaleProfile'] ?? null;
    if ($preset === 'custom' && $profile === 'wide') $settings['unitScaleProfile'] = 'wide';
    return $settings;
}

function applicationMapForUnitLayer(array $map, ?string $layerId = null): array {
    $layerId ??= (string)($map['activeLayerId'] ?? 'ground');
    $settings = applicationMapScaleSettings($map, $layerId);
    $level = array_replace($map, is_array($map['layers'][$layerId] ?? null) ? $map['layers'][$layerId] : [], $settings, ['activeLayerId' => $layerId]);
    if (!isset($settings['unitScaleProfile'])) unset($level['unitScaleProfile']);
    return $level;
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
    $settings = applicationMapScaleSettings($map, $layerId);
    $wide = $settings['unitScalePreset'] === 'wide' || ($settings['unitScaleProfile'] ?? null) === 'wide';
    $multiplier = ($wide ? ['small' => 0.8, 'normal' => 1, 'large' => 1.4, 'huge' => 2.5]
        : ['small' => 0.75, 'normal' => 1, 'large' => 1.5, 'huge' => 2.5])[$category];
    $token['size'] = max(10, min(XAR_UNIT_SIZE_MAXIMUM, round($settings['gridSize'] * $multiplier, 3)));
    return $token;
}

function applicationValidMapScaleSettings(array $value): bool {
    if (array_key_exists('unitScalePreset', $value) && !applicationMapScalePresetValid($value['unitScalePreset'])) return false;
    if (array_key_exists('unitScaleAuto', $value) && !is_bool($value['unitScaleAuto'])) return false;
    if (array_key_exists('unitScaleProfile', $value) && $value['unitScaleProfile'] !== 'wide') return false;
    foreach (['gridSize', 'gridOffsetX', 'gridOffsetY'] as $key) {
        if (!array_key_exists($key, $value)) continue;
        $number = $value[$key];
        if ((!is_int($number) && !is_float($number)) || !is_finite((float)$number)) return false;
        if ($key === 'gridSize' && ($number < 12 || $number > 240)) return false;
        if ($key !== 'gridSize' && abs($number) > 240) return false;
    }
    return true;
}
