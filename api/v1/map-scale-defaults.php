<?php
declare(strict_types=1);

require_once __DIR__ . '/token-map-scale.php';

function applicationMapWithDefaultScalePresets(array $map, string $sceneId, string $lobbySceneId, string $deuillrouteSceneId): array
{
    if ($sceneId === $lobbySceneId) return $map;

    $scaleForLayer = static function (string $layerId) use ($sceneId, $deuillrouteSceneId): array {
        $preset = $sceneId === $deuillrouteSceneId && $layerId === 'upper' ? 'indoor' : 'outdoor';
        return ['unitScalePreset' => $preset, 'gridSize' => $preset === 'indoor' ? 75 : 50];
    };
    $activeLayerId = $map['activeLayerId'] ?? 'ground';
    if (!in_array($activeLayerId, ['basement', 'ground', 'upper'], true)) $activeLayerId = 'ground';
    $map = array_replace($map, $scaleForLayer($activeLayerId));
    foreach ($map['layers'] ?? [] as $layerId => $layer) {
        if (is_array($layer) && in_array($layerId, ['basement', 'ground', 'upper'], true)) {
            $map['layers'][$layerId] = array_replace($layer, $scaleForLayer($layerId));
        }
    }
    return $map;
}

function applicationDefaultMapScaleChanges(array $records, string $lobbySceneId, string $deuillrouteSceneId): array
{
    $pending = [];
    foreach ($records['scene-index']['payload']['order'] ?? [] as $sceneId) {
        $key = 'map:' . $sceneId;
        $record = $records[$key] ?? null;
        if (!is_array($record['payload'] ?? null)) continue;
        $before = $record['payload'];
        $after = applicationMapWithDefaultScalePresets($before, $sceneId, $lobbySceneId, $deuillrouteSceneId);
        if ($after === $before) continue;
        $change = prepareApplicationDomainUpsert($key, $after, $record);
        if ($change !== null) $pending[] = $change;
    }
    return $pending;
}

function migrateApplicationDefaultMapScales(PDO $connection): void
{
    $connection->beginTransaction();
    try {
        $clock = domainClockRecord($connection, true);
        $records = applicationDomainRecords($connection, ['scene-index']);
        $sceneKeys = [];
        foreach (applicationDomainPayload($records, 'scene-index')['order'] ?? [] as $sceneId) {
            $sceneKeys[] = 'scene:' . $sceneId;
            $sceneKeys[] = 'map:' . $sceneId;
        }
        $records += applicationDomainRecords($connection, $sceneKeys);
        $lobby = '';
        $deuilroute = '';
        foreach (applicationDomainPayload($records, 'scene-index')['order'] ?? [] as $sceneId) {
            $scene = applicationDomainPayload($records, 'scene:' . $sceneId);
            $name = strtolower(trim((string) ($scene['metadata']['name'] ?? '')));
            if ($name === 'lobby') $lobby = $sceneId;
            if (in_array($name, ['village de deuilroute', 'deuilroute', 'deuillroute'], true)) $deuilroute = $sceneId;
        }
        $pending = applicationDefaultMapScaleChanges($records, $lobby, $deuilroute);
        if ($pending !== []) persistDomainChangesInTransaction($connection, [], $clock, $pending);
        $connection->prepare('INSERT INTO schema_migrations (version,name,checksum) VALUES (25,:name,:checksum)')
            ->execute([':name' => 'map_scale_defaults_legacy_campaign',
                ':checksum' => hash('sha256', 'map-scale|outdoor|preserve-lobby|deuilroute-upper-indoor')]);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $error;
    }
}

function applicationMapWithUpdatedPresetGrid(array $map): array
{
    $update = static function (array $level): array {
        $previous = ['outdoor' => 50, 'indoor' => 75];
        $upgraded = ['outdoor' => 40, 'indoor' => 60];
        $preset = $level['unitScalePreset'] ?? null;
        $size = $level['gridSize'] ?? null;
        if (is_string($preset) && isset($previous[$preset])
            && (is_int($size) || is_float($size)) && $size == $previous[$preset]) {
            $level['gridSize'] = $upgraded[$preset];
        }
        return $level;
    };
    $map = $update($map);
    foreach ($map['layers'] ?? [] as $layerId => $level) {
        if (is_array($level) && in_array($layerId, ['basement', 'ground', 'upper'], true)) {
            $map['layers'][$layerId] = $update($level);
        }
    }
    return $map;
}

function applicationUpdatedPresetGridChanges(array $records): array
{
    $pending = [];
    foreach (applicationDomainPayload($records, 'scene-index')['order'] ?? [] as $sceneId) {
        $scene = applicationDomainPayload($records, 'scene:' . $sceneId);
        if (strtolower(trim((string) ($scene['metadata']['name'] ?? ''))) === 'lobby') continue;
        $key = 'map:' . $sceneId;
        $record = $records[$key] ?? null;
        if (!is_array($record['payload'] ?? null)) continue;
        $before = $record['payload'];
        $after = applicationMapWithUpdatedPresetGrid($before);
        if ($after === $before) continue;
        // The stored map is already authoritative. Rewrite only the known grid values,
        // without reprocessing the rest of its historical calibration or media.
        $pending[] = ['key'=>$key, 'operation'=>'upsert', 'payload'=>$after, 'current'=>$record];
    }
    return $pending;
}

function migrateApplicationPresetGridSizes(PDO $connection): void
{
    $connection->beginTransaction();
    try {
        $clock = domainClockRecord($connection, true);
        $records = applicationDomainRecords($connection, ['scene-index']);
        $keys = [];
        foreach (applicationDomainPayload($records, 'scene-index')['order'] ?? [] as $sceneId) {
            $keys[] = 'scene:' . $sceneId;
            $keys[] = 'map:' . $sceneId;
        }
        if ($keys !== []) $records += applicationDomainRecords($connection, $keys);
        $pending = applicationUpdatedPresetGridChanges($records);
        if ($pending !== []) persistDomainChangesInTransaction($connection, [], $clock, $pending);
        $connection->prepare('INSERT INTO schema_migrations (version,name,checksum) VALUES (29,:name,:checksum)')
            ->execute([':name' => 'map_preset_grid_sizes',
                ':checksum' => hash('sha256', 'map-preset-grid|outdoor:50:40|indoor:75:60|preserve-lobby')]);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $error;
    }
}
