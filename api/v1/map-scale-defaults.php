<?php
declare(strict_types=1);

require_once __DIR__ . '/token-map-scale.php';

function applicationMapWithDefaultScalePresets(array $map, string $sceneId, string $lobbySceneId, string $deuillrouteSceneId): array
{
    if ($sceneId === $lobbySceneId) return $map;

    $scaleForLayer = static function (string $layerId) use ($sceneId, $deuillrouteSceneId): array {
        $preset = $sceneId === $deuillrouteSceneId && $layerId === 'upper' ? 'indoor' : 'outdoor';
        $settings = applicationMapScaleSettings(['unitScalePreset' => $preset]);
        return ['unitScalePreset' => $preset, 'gridSize' => $settings['gridSize']];
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
