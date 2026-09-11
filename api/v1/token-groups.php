<?php

declare(strict_types=1);

function onlineTokenLayerId(array $token, array $map = []): string
{
    $id = $token['layerId'] ?? ($map['activeLayerId'] ?? 'ground');
    return in_array($id, ['basement', 'ground', 'upper'], true) ? $id : 'ground';
}

function onlineTokenOnActiveLayer(array $token, array $map): bool
{
    return onlineTokenLayerId($token, $map) === onlineTokenLayerId([], $map);
}

function onlineReconcileTokenTargetsForScene(PDO $connection, array &$records, array &$pending, string $sceneId): array
{
    if (!validApplicationDomainKey('map:' . $sceneId)) return [];
    $sceneRecords = onlineSceneTokenRecords($connection, $sceneId);
    $records = array_replace($records, $sceneRecords);
    $mapKey = 'map:' . $sceneId;
    if (!isset($records[$mapKey])) {
        $records = array_replace($records, applicationDomainRecords($connection, [$mapKey]));
    }
    $map = is_array($pending[$mapKey]['payload'] ?? null)
        ? $pending[$mapKey]['payload'] : applicationDomainPayload($records, $mapKey);
    $prefix = 'token:' . $sceneId . ':';
    $keys = [];
    foreach ($records as $key => $_record) if (str_starts_with((string) $key, $prefix)) $keys[$key] = true;
    foreach ($pending as $key => $_entry) if (str_starts_with((string) $key, $prefix)) $keys[$key] = true;
    $tokens = [];
    foreach (array_keys($keys) as $key) {
        $entry = $pending[$key] ?? null;
        if (is_array($entry) && ($entry['operation'] ?? '') === 'delete') continue;
        $token = is_array($entry['payload'] ?? null) ? $entry['payload'] : applicationDomainPayload($records, $key);
        if ($token === []) continue;
        $tokens[(string) ($token['id'] ?? substr($key, strlen($prefix)))] = ['key' => $key, 'payload' => $token];
    }
    $changed = [];
    foreach ($tokens as $id => $entry) {
        $targetId = trim((string) ($entry['payload']['targetTokenId'] ?? ''));
        if ($targetId === '') continue;
        $target = $tokens[$targetId]['payload'] ?? null;
        if ($targetId !== $id && is_array($target)
            && onlineTokenLayerId($entry['payload'], $map) === onlineTokenLayerId($target, $map)) continue;
        $token = $entry['payload'];
        $token['targetTokenId'] = null;
        queueOnlineDomainUpsert($pending, $records, $entry['key'], $token);
        $changed[] = $entry['key'];
    }
    return $changed;
}

function normalizeOnlineSceneTokenIdentity(array $token, array $map): array
{
    $token['layerId'] = onlineTokenLayerId($token, $map);
    $token['visionDistance'] = normalizeApplicationVisionDistance($token['visionDistance'] ?? null);
    if (!empty($token['characterId']) && ($token['followCharacter'] ?? true) === false && empty($token['linkedTokenId'])) {
        $token['cloneSourceCharacterId'] = $token['cloneSourceCharacterId'] ?? $token['characterId'];
    }
    return $token;
}

function onlineGroupTokenSize(array $token): float
{
    return max(10.0, min(220.0, (float) ($token['size'] ?? 40)));
}

function onlineGroupTranslation(array $tokens, float $dx, float $dy, array $map): array
{
    $axis = static function (string $key, float $delta, float $dimension) use ($tokens): float {
        $lower = -min(array_column($tokens, $key));
        $upper = 100 - max(array_column($tokens, $key));
        // Round margins inward before serializing centers at 1/10000 percent.
        $insideLower = max(array_map(static fn (array $token): float => ceil(onlineGroupTokenSize($token) / 2 / $dimension * 100 * 10000) / 10000 - $token[$key], $tokens));
        $insideUpper = min(array_map(static fn (array $token): float => floor((100 - onlineGroupTokenSize($token) / 2 / $dimension * 100) * 10000) / 10000 - $token[$key], $tokens));
        // An oversized formation is handled by the per-token bounded relocation.
        if ($insideLower <= $insideUpper) { $lower = $insideLower; $upper = $insideUpper; }
        return max($lower, min($delta, $upper));
    };
    return ['x' => $axis('x', $dx, $map['naturalWidth']), 'y' => $axis('y', $dy, $map['naturalHeight'])];
}

function onlineGroupPositionValidator(array $map): Closure
{
    $walls = $map['walls'] ?? null;
    $bytes = validApplicationWallState($walls) && ($walls['mask'] ?? '') !== '' ? applicationWallMaskBytes($walls) : null;
    $width = (float) ($map['naturalWidth'] ?? 1600);
    $height = (float) ($map['naturalHeight'] ?? 900);
    return static function (array $point, array $token) use ($walls, $bytes, $width, $height): bool {
        return $bytes === null || !applicationWallCollisionAt($bytes, $walls,
            (float) $point['x'] / 100 * ($walls['width'] - 1), (float) $point['y'] / 100 * ($walls['height'] - 1),
            onlineGroupTokenSize($token) / 2 / $width * ($walls['width'] - 1),
            onlineGroupTokenSize($token) / 2 / $height * ($walls['height'] - 1));
    };
}

function onlineGroupPositionIsFree(array $point, array $token, float $width, float $height, array $occupied, Closure $wallFree): bool
{
    $radius = onlineGroupTokenSize($token) / 2;
    if ($point['x'] * $width / 100 < $radius || $point['x'] * $width / 100 > $width - $radius
        || $point['y'] * $height / 100 < $radius || $point['y'] * $height / 100 > $height - $radius || !$wallFree($point, $token)) return false;
    foreach ($occupied as $other) {
        if (hypot(($point['x'] - $other['x']) * $width / 100, ($point['y'] - $other['y']) * $height / 100)
            < $radius + onlineGroupTokenSize($other) / 2 + 0.1) return false;
    }
    return true;
}

function nearestOnlineGroupPosition(array $desired, array $token, array $map, array $occupied = [], ?array $start = null): ?array
{
    $width = (float) ($map['naturalWidth'] ?? 1600) ?: 1600.0;
    $height = (float) ($map['naturalHeight'] ?? 900) ?: 900.0;
    $step = max(1.0, min($width / 511, $height / 511));
    $wallFree = onlineGroupPositionValidator($map);
    $valid = static function (array $point) use ($width, $height, $wallFree, $occupied, $token, $start, $map): bool {
        if (!onlineGroupPositionIsFree($point, $token, $width, $height, $occupied, $wallFree)) return false;
        if ($start === null) return true;
        $resolved = applicationResolveWallCollision($map['walls'] ?? null, $start, $point, $token['size'] ?? 40, $width, $height, true);
        return !$resolved['blocked'] && abs($resolved['x'] - $point['x']) < 0.000001 && abs($resolved['y'] - $point['y']) < 0.000001;
    };
    $heap = new SplPriorityQueue();
    $seen = [];
    $push = static function (int $i, int $j) use ($heap, &$seen): void {
        $key = $i . ',' . $j;
        if (isset($seen[$key])) return;
        $seen[$key] = true;
        $heap->insert([$i, $j], [-($i * $i + $j * $j), -$j, -$i]);
    };
    $push(0, 0);
    for ($count = 0; $count < 4096 && !$heap->isEmpty(); ++$count) {
        [$i, $j] = $heap->extract();
        $point = ['x' => round($desired['x'] + $i * $step / $width * 100, 4), 'y' => round($desired['y'] + $j * $step / $height * 100, 4)];
        if ($valid($point)) return $point;
        $push($i - 1, $j); $push($i + 1, $j); $push($i, $j - 1); $push($i, $j + 1);
    }
    return null;
}

function planApplicationTokenGroupTransform(array $map, array $request): array
{
    if (($map['viewLocked'] ?? false) !== true) throw new DomainException('Verrouillez le cadrage de la carte pour agir sur un groupe.', 423);
    $ids = $request['tokenIds'] ?? null;
    if (!is_array($ids) || !applicationDomainArrayIsList($ids) || count($ids) < 1 || count($ids) > 2000) throw new DomainException('Sélection de pions invalide.', 400);
    foreach ($ids as $id) if (!is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $id) !== 1) throw new DomainException('Identifiant de pion invalide.', 400);
    if (count(array_unique($ids)) !== count($ids)) throw new DomainException('Pions dupliqués.', 400);
    $layerId = $request['layerId'] ?? '';
    if (!in_array($layerId, ['basement', 'ground', 'upper'], true) || $layerId !== onlineTokenLayerId([], $map)) throw new DomainException('Le niveau actif a changé.', 409);
    $targetLayerId = $request['targetLayerId'] ?? $layerId;
    if (!in_array($targetLayerId, ['basement', 'ground', 'upper'], true)) throw new DomainException('Niveau de destination invalide.', 400);
    foreach (['dx', 'dy'] as $key) if ((!is_int($request[$key] ?? null) && !is_float($request[$key] ?? null)) || !is_finite((float) $request[$key]) || abs($request[$key]) > 100) throw new DomainException('Déplacement de groupe invalide.', 400);
    $index = [];
    foreach ($map['tokens'] ?? [] as $token) $index[$token['id']] = $token;
    $tokens = [];
    foreach ($ids as $id) {
        $token = $index[$id] ?? null; $base = $request['base'][$id] ?? null;
        if ($token === null || !onlineTokenOnActiveLayer($token, $map) || !is_array($base)) throw new DomainException('La sélection a changé.', 409);
        foreach (['x', 'y'] as $key) if ((!is_int($base[$key] ?? null) && !is_float($base[$key] ?? null)) || !is_finite((float) $base[$key]) || (($request['rebasePositions'] ?? false) !== true && abs((float) ($token[$key] ?? 50) - $base[$key]) > 0.000001)) throw new DomainException('Un pion a changé de position.', 409);
        $tokens[] = $token;
    }
    $destination = $map['layers'][$targetLayerId] ?? ($targetLayerId === $layerId ? $map : []);
    $destination['naturalWidth'] = (float) ($destination['naturalWidth'] ?? 1600) ?: 1600.0;
    $destination['naturalHeight'] = (float) ($destination['naturalHeight'] ?? 900) ?: 900.0;
    $wallFree = onlineGroupPositionValidator($destination);
    $delta = onlineGroupTranslation($tokens, (float) $request['dx'], (float) $request['dy'], $destination);
    $occupied = array_values(array_filter($map['tokens'] ?? [], static fn (array $t): bool => !in_array($t['id'], $ids, true) && onlineTokenLayerId($t, $map) === $targetLayerId));
    $placements = []; $blocked = [];
    foreach ($tokens as $token) {
        $desired = ['x' => round($token['x'] + $delta['x'], 4), 'y' => round($token['y'] + $delta['y'], 4)];
        // MJ-only placement ignores the path, never a wall at the destination.
        if (onlineGroupPositionIsFree($desired, $token, $destination['naturalWidth'], $destination['naturalHeight'], $occupied, $wallFree)) {
            $placements[$token['id']] = ['id' => $token['id'], ...$desired, 'layerId' => $targetLayerId, 'relocated' => false];
            $occupied[] = array_replace($token, $desired);
        } else $blocked[] = [$token, $desired];
    }
    foreach ($blocked as [$token, $desired]) {
        $position = nearestOnlineGroupPosition($desired, $token, $destination, $occupied);
        if ($position === null) throw new DomainException('Aucune place libre et accessible près du groupe. Aucun pion n’a été déplacé.', 409);
        $placements[$token['id']] = ['id' => $token['id'], ...$position, 'layerId' => $targetLayerId, 'relocated' => true];
        $occupied[] = array_replace($token, $position);
    }
    return array_map(static fn (string $id): array => $placements[$id], $ids);
}

function applyOnlineTokenGroupCommand(PDO $connection, array &$records, array &$pending, array $arguments, bool $isGm): array
{
    if (!$isGm) rejectOnlineCommand($connection, 403, 'La sélection groupée est réservée au MJ.', 'gm_mode_required');
    $sceneId = (string) ($arguments['sceneId'] ?? '');
    if (!validApplicationDomainKey('map:' . $sceneId)) rejectOnlineCommand($connection, 400, 'Scène invalide.', 'invalid_scene');
    $mapKey = 'map:' . $sceneId;
    $records = array_replace($records, applicationDomainRecords($connection, [$mapKey]), onlineSceneTokenRecords($connection, $sceneId));
    $map = applicationDomainPayload($records, $mapKey);
    if (!is_int($arguments['mapRevision'] ?? null) || $arguments['mapRevision'] !== (int) ($records[$mapKey]['revision'] ?? 0)) rejectOnlineCommand($connection, 409, 'La carte ou ses murs ont changé. Réessayez après actualisation.', 'stale_map');
    $map['tokens'] = [];
    foreach ($records as $key => $record) if (str_starts_with($key, 'token:' . $sceneId . ':')) $map['tokens'][] = applicationDomainPayload($records, $key);
    try { $placements = planApplicationTokenGroupTransform($map, $arguments); }
    catch (DomainException $error) { rejectOnlineCommand($connection, $error->getCode(), $error->getMessage(), 'group_transform_refused'); }
    $tokens = []; $domains = [];
    foreach ($placements as $placement) {
        $key = onlineTokenDomainKey($sceneId, $placement['id']);
        $token = applicationDomainPayload($records, $key);
        $token = array_replace($token, array_intersect_key($placement, array_flip(['x', 'y', 'layerId'])));
        $token['_movedAt'] = (int) floor(microtime(true) * 1000);
        queueOnlineDomainUpsert($pending, $records, $key, $token);
        $tokens[] = $token;
        $domains[] = ['key' => $key, 'revision' => (int) ($records[$key]['revision'] ?? 0) + (isset($pending[$key]) ? 1 : 0)];
    }
    return ['sceneId' => $sceneId, 'tokens' => $tokens, 'tokenDomains' => $domains, 'relocatedCount' => count(array_filter($placements, static fn (array $p): bool => $p['relocated']))];
}

function planApplicationTokenLayers(array $map, array $arguments): array
{
    $requests = $arguments['tokens'] ?? null;
    $targetLayer = $arguments['targetLayerId'] ?? null;
    if (!in_array($targetLayer, ['basement', 'ground', 'upper'], true)) throw new DomainException('Niveau de destination invalide.', 400);
    if (!is_array($requests) || !applicationDomainArrayIsList($requests) || count($requests) < 1 || count($requests) > 200) throw new DomainException('Sélection de pions invalide.', 400);
    $index = []; $ids = [];
    foreach ($map['tokens'] ?? [] as $token) $index[$token['id']] = $token;
    foreach ($requests as $request) {
        $id = is_array($request) ? ($request['id'] ?? null) : null;
        if (!is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $id) !== 1 || isset($ids[$id])) throw new DomainException('Identifiant de pion invalide ou dupliqué.', 400);
        if (!in_array($request['layerId'] ?? null, ['basement', 'ground', 'upper'], true)) throw new DomainException('Niveau de départ invalide.', 400);
        $ids[$id] = true;
        $token = $index[$id] ?? null;
        if ($token === null || onlineTokenLayerId($token, $map) !== $request['layerId']) throw new DomainException('Un pion a changé de niveau.', 409);
        foreach (['x', 'y'] as $axis) {
            if ((!is_int($request[$axis] ?? null) && !is_float($request[$axis] ?? null)) || !is_finite((float) $request[$axis]) || $request[$axis] < 0 || $request[$axis] > 100) throw new DomainException('Position de départ invalide.', 400);
            if (($arguments['rebasePositions'] ?? false) !== true && abs((float) ($token[$axis] ?? 50) - $request[$axis]) > 0.000001) throw new DomainException('Un pion a changé de position.', 409);
        }
    }
    $destination = $map['layers'][$targetLayer] ?? ($targetLayer === onlineTokenLayerId([], $map) ? $map : []);
    $destination['naturalWidth'] = (float) ($destination['naturalWidth'] ?? 1600) ?: 1600.0;
    $destination['naturalHeight'] = (float) ($destination['naturalHeight'] ?? 900) ?: 900.0;
    $occupied = array_values(array_filter($map['tokens'] ?? [], static fn (array $token): bool => onlineTokenLayerId($token, $map) === $targetLayer));
    $placements = [];
    foreach ($requests as $request) {
        $token = $index[$request['id']];
        $desired = ($arguments['rebasePositions'] ?? false) === true ? ['x' => $token['x'], 'y' => $token['y']] : ['x' => $request['x'], 'y' => $request['y']];
        if ($request['layerId'] === $targetLayer) {
            $placements[] = ['id' => $token['id'], ...$desired, 'layerId' => $targetLayer, 'relocated' => false];
            continue;
        }
        $position = nearestOnlineGroupPosition($desired, $token, $destination, $occupied);
        if ($position === null) throw new DomainException('Aucune place libre près du groupe. Aucun pion n’a changé de niveau.', 409);
        $placements[] = ['id' => $token['id'], ...$position, 'layerId' => $targetLayer, 'relocated' => abs($position['x'] - $desired['x']) > 0.000001 || abs($position['y'] - $desired['y']) > 0.000001];
        $occupied[] = array_replace($token, $position);
    }
    return $placements;
}

function applyOnlineTokenLayersCommand(PDO $connection, array &$records, array &$pending, array $arguments, bool $isGm): array
{
    if (!$isGm) rejectOnlineCommand($connection, 403, 'Les changements de niveau sont réservés au MJ.', 'gm_mode_required');
    $sceneId = (string) ($arguments['sceneId'] ?? '');
    if (!validApplicationDomainKey('map:' . $sceneId)) rejectOnlineCommand($connection, 400, 'Scène invalide.', 'invalid_scene');
    $mapKey = 'map:' . $sceneId;
    $records = array_replace($records, applicationDomainRecords($connection, [$mapKey]), onlineSceneTokenRecords($connection, $sceneId));
    $map = applicationDomainPayload($records, $mapKey);
    if (!is_int($arguments['mapRevision'] ?? null) || $arguments['mapRevision'] !== (int) ($records[$mapKey]['revision'] ?? 0)) rejectOnlineCommand($connection, 409, 'La carte ou ses murs ont changé. Réessayez après actualisation.', 'stale_map');
    $map['tokens'] = [];
    foreach ($records as $key => $record) if (str_starts_with($key, 'token:' . $sceneId . ':')) $map['tokens'][] = applicationDomainPayload($records, $key);
    try { $placements = planApplicationTokenLayers($map, $arguments); }
    catch (DomainException $error) { rejectOnlineCommand($connection, $error->getCode(), $error->getMessage(), 'token_layers_refused'); }
    $tokens = []; $domains = [];
    foreach ($placements as $placement) {
        $key = onlineTokenDomainKey($sceneId, $placement['id']);
        $token = applicationDomainPayload($records, $key);
        if (onlineTokenLayerId($token, $map) !== $placement['layerId'] || abs(($token['x'] ?? 50) - $placement['x']) > 0.000001 || abs(($token['y'] ?? 50) - $placement['y']) > 0.000001) {
            $token = array_replace($token, array_intersect_key($placement, array_flip(['x', 'y', 'layerId'])));
            $token['_movedAt'] = (int) floor(microtime(true) * 1000);
            queueOnlineDomainUpsert($pending, $records, $key, $token);
        }
        $tokens[] = [...$placement, '_movedAt' => $token['_movedAt'] ?? null];
        $domains[] = ['key' => $key, 'revision' => (int) ($records[$key]['revision'] ?? 0) + (isset($pending[$key]) ? 1 : 0)];
    }
    return ['sceneId' => $sceneId, 'mapRevision' => (int) ($records[$mapKey]['revision'] ?? 0), 'tokens' => $tokens, 'tokenDomains' => $domains,
        'relocatedCount' => count(array_filter($placements, static fn (array $p): bool => $p['relocated']))];
}

function applyOnlineTokenCloneCommand(PDO $connection, array &$records, array &$pending, array $arguments, bool $isGm): array
{
    if (!$isGm) rejectOnlineCommand($connection, 403, 'La création de clones est réservée au MJ.', 'gm_mode_required');
    $sceneId = (string) ($arguments['sceneId'] ?? '');
    if (!validApplicationDomainKey('map:' . $sceneId)) rejectOnlineCommand($connection, 400, 'Scène invalide.', 'invalid_scene');
    $mapKey = 'map:' . $sceneId; $indexKey = 'token-index:' . $sceneId;
    $records = array_replace($records, applicationDomainRecords($connection, [$mapKey, $indexKey]), onlineSceneTokenRecords($connection, $sceneId));
    $map = applicationDomainPayload($records, $mapKey);
    $sourceKey = onlineTokenDomainKey($sceneId, $arguments['tokenId'] ?? '');
    $source = applicationDomainPayload($records, $sourceKey);
    if ($source === [] || !onlineTokenOnActiveLayer($source, $map)) rejectOnlineCommand($connection, 409, 'Ce pion n’est plus sur le niveau actif.', 'stale_token');
    if (!empty($source['characterId'])) {
        $characterKey = 'character:' . $source['characterId'];
        $records = array_replace($records, applicationDomainRecords($connection, [$characterKey]));
        $character = applicationDomainPayload($records, $characterKey);
        if ($character !== []) $source = synchronizeOnlineCharacterToken($source, $character);
    }
    $index = applicationDomainPayload($records, $indexKey, ['order' => []]);
    $occupied = [];
    foreach ($records as $key => $record) if (str_starts_with($key, 'token:' . $sceneId . ':')) $occupied[] = applicationDomainPayload($records, $key);
    if (count($occupied) >= 2000) rejectOnlineCommand($connection, 409, 'La scène a atteint sa limite de pions.', 'token_limit');
    $clone = array_replace($source, ['id' => 'token-' . randomToken(16), 'characterId' => null, 'linkedTokenId' => null, 'followCharacter' => false, 'targetTokenId' => null,
        'cloneSourceCharacterId' => $source['characterId'] ?? ($source['cloneSourceCharacterId'] ?? null),
        'cloneSourceTokenId' => $source['id'], 'layerId' => onlineTokenLayerId($source, $map), 'libraryTemplateId' => null,
        'initiative' => null, 'resourcePulse' => null, '_updatedAt' => (int) floor(microtime(true) * 1000), '_movedAt' => (int) floor(microtime(true) * 1000)]);
    unset($clone['transformation']);
    $layer = $map['layers'][$clone['layerId']] ?? $map;
    $layer['naturalWidth'] = (float) ($layer['naturalWidth'] ?? 1600) ?: 1600.0;
    $layer['naturalHeight'] = (float) ($layer['naturalHeight'] ?? 900) ?: 900.0;
    $desired = ['x' => $source['x'] + (onlineGroupTokenSize($source) + 2) / $layer['naturalWidth'] * 100, 'y' => $source['y']];
    $position = nearestOnlineGroupPosition($desired, $clone, $layer, array_values(array_filter($occupied, static fn (array $t): bool => onlineTokenLayerId($t, $map) === $clone['layerId'])));
    if ($position === null) rejectOnlineCommand($connection, 409, 'Aucune place libre près de ce pion pour son clone.', 'clone_position_unavailable');
    $clone = array_replace($clone, $position);
    $index['order'][] = $clone['id'];
    queueOnlineDomainUpsert($pending, $records, onlineTokenDomainKey($sceneId, $clone['id']), $clone);
    queueOnlineDomainUpsert($pending, $records, $indexKey, $index);
    return ['sceneId' => $sceneId, 'token' => $clone];
}

// Generic MJ domain writes must preserve the same character authority as commands.
// Stamp legacy floor identities BEFORE a map changes levels, without a schema reset.
function prepareOnlineSceneTokenChanges(PDO $connection, array &$records, array $pending): array
{
    $byKey = [];
    foreach ($pending as $entry) $byKey[$entry['key']] = $entry;
    $characterIds = [];
    foreach ($pending as $entry) {
        if ($entry['operation'] !== 'upsert') continue;
        $key = $entry['key'];
        if (str_starts_with($key, 'map:')) {
            $sceneId = substr($key, 4);
            $tokens = onlineSceneTokenRecords($connection, $sceneId);
            $records = array_replace($records, $tokens);
            foreach ($tokens as $tokenKey => $record) {
                if (!str_starts_with($tokenKey, 'token:')) continue;
                $token = applicationDomainPayload($records, $tokenKey);
                if (!isset($token['layerId']) && !isset($byKey[$tokenKey])) {
                    $token['layerId'] = onlineTokenLayerId($token, applicationDomainPayload($records, $key));
                    $prepared = prepareApplicationDomainUpsert($tokenKey, $token, $records[$tokenKey]);
                    if ($prepared !== null) $byKey[$tokenKey] = $prepared;
                }
            }
        }
        if (str_starts_with($key, 'character:')) $characterIds[] = substr($key, 10);
    }
    foreach (array_unique($characterIds) as $id) {
        $tokens = applicationCharacterTokenDomainRecords($connection, $id);
        $records = array_replace($records, $tokens);
        foreach ($tokens as $key => $record) if (!isset($byKey[$key])) $byKey[$key] = ['key' => $key, 'operation' => 'upsert', 'payload' => applicationDomainPayload($records, $key), 'current' => $record];
    }
    foreach ($byKey as $key => $entry) {
        if ($entry['operation'] !== 'upsert' || !str_starts_with($key, 'token:')) continue;
        $token = $entry['payload'];
        $sceneId = explode(':', $key)[1]; $mapKey = 'map:' . $sceneId;
        $characterKey = empty($token['characterId']) ? '' : 'character:' . $token['characterId'];
        $wanted = [$mapKey]; if ($characterKey !== '') $wanted[] = $characterKey;
        $records = array_replace($records, applicationDomainRecords($connection, array_values(array_filter($wanted, static fn (string $k): bool => !isset($records[$k])))));
        $token = normalizeOnlineSceneTokenIdentity($token, applicationDomainPayload($records, $mapKey));
        if (!empty($token['characterId']) && ($byKey[$characterKey]['operation'] ?? '') !== 'delete') {
            $character = $byKey[$characterKey]['payload'] ?? applicationDomainPayload($records, $characterKey);
            if ($character !== []) $token = synchronizeOnlineCharacterToken($token, $character);
        }
        $prepared = prepareApplicationDomainUpsert($key, $token, $records[$key] ?? null);
        if ($prepared === null) unset($byKey[$key]); else $byKey[$key] = $prepared;
    }
    $affectedScenes = [];
    foreach ($byKey as $key => $entry) {
        if (str_starts_with($key, 'map:')) $affectedScenes[substr($key, 4)] = true;
        if (str_starts_with($key, 'token:')) {
            $segments = explode(':', $key, 3);
            if (count($segments) === 3) $affectedScenes[$segments[1]] = true;
        }
    }
    foreach (array_keys($affectedScenes) as $sceneId) {
        onlineReconcileTokenTargetsForScene($connection, $records, $byKey, (string) $sceneId);
        if (function_exists('onlineReconcileCarriedLightsForScene')) {
            onlineReconcileCarriedLightsForScene($connection, $records, $byKey, (string) $sceneId);
        }
    }
    return array_values($byKey);
}
