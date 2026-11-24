<?php
declare(strict_types=1);

const XAR_ANIMATED_MAP_LAYERS = ['basement', 'ground', 'upper'];
const XAR_ANIMATED_MAP_KINDS = ['water', 'lava', 'light'];
const XAR_ANIMATED_MAP_MAX_MASK_LENGTH = 240000;

function applicationMapAnimationId(mixed $value): bool
{
    return is_string($value) && preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $value) === 1;
}

function applicationMapAnimationSource(mixed $value): bool
{
    if (!is_string($value) || $value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x20]/', $value) === 1) return false;
    if (preg_match('#^/media/[A-Za-z0-9_-]{24}$#D', $value) === 1) return true;
    $url = parse_url($value);
    return is_array($url) && strtolower($url['scheme'] ?? '') === 'https'
        && strtolower($url['host'] ?? '') === 'regie-xar-tsaroth.fr'
        && !isset($url['port']) && !isset($url['user']) && !isset($url['pass']);
}

function applicationMapAnimationNumber(mixed $value, int $maximum): bool
{
    return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value <= $maximum;
}

function applicationMapAnimationMask(mixed $mask): ?array
{
    if (!is_array($mask)) return null;
    $width = $mask['width'] ?? null;
    $height = $mask['height'] ?? null;
    $rle = $mask['rle'] ?? null;
    if (!is_int($width) || $width < 1 || $width > 512 || !is_int($height) || $height < 1 || $height > 512
        || !is_string($rle) || strlen($rle) > XAR_ANIMATED_MAP_MAX_MASK_LENGTH || strlen($rle) % 4 !== 0) return null;
    $bytes = base64_decode($rle, true);
    if ($bytes === false || strlen($bytes) % 2 !== 0 || base64_encode($bytes) !== $rle) return null;
    $size = $width * $height;
    $length = strlen($bytes);
    $pixels = 0;
    $visible = false;
    for ($i = 0; $i < $length; $i += 2) {
        $run = ord($bytes[$i + 1]);
        $pixels += $run;
        if ($run === 0 || $pixels > $size) return null;
        $visible = $visible || ord($bytes[$i]) > 0;
    }
    return $pixels === $size && $visible ? ['width' => $width, 'height' => $height, 'rle' => $rle] : null;
}

function applicationMapAnimationPolygons(mixed $raw): ?array
{
    if (!is_array($raw) || !array_is_list($raw) || count($raw) < 1 || count($raw) > 20) return null;
    foreach ($raw as $polygon) {
        if (!is_array($polygon) || !array_is_list($polygon) || count($polygon) < 3 || count($polygon) > 96) return null;
        foreach ($polygon as $point) {
            if (!is_array($point) || !array_is_list($point) || count($point) !== 2
                || !applicationMapAnimationNumber($point[0], 1) || !applicationMapAnimationNumber($point[1], 1)) return null;
        }
    }
    return $raw;
}

function applicationMapAnimationPlan(mixed $raw): ?array
{
    if (!is_array($raw) || ($raw['version'] ?? null) !== 1) return null;
    $input = $raw['layers'] ?? null;
    if (!is_array($input) || !array_is_list($input) || count($input) < 1 || count($input) > count(XAR_ANIMATED_MAP_KINDS)) return null;
    $seen = [];
    $layers = [];
    $maskLength = 0;
    foreach ($input as $layer) {
        $kind = $layer['kind'] ?? null;
        if (!is_array($layer) || !in_array($kind, XAR_ANIMATED_MAP_KINDS, true) || isset($seen[$kind])
            || !applicationMapAnimationNumber($layer['intensity'] ?? null, 1)
            || !applicationMapAnimationNumber($layer['speed'] ?? null, 1)
            || !applicationMapAnimationNumber($layer['direction'] ?? null, 360)
            || !applicationMapAnimationNumber($layer['fish'] ?? null, 1)) return null;
        if (array_key_exists('mask', $layer) === array_key_exists('polygons', $layer)) return null;
        $mask = applicationMapAnimationMask($layer['mask'] ?? null);
        $polygons = $mask === null ? applicationMapAnimationPolygons($layer['polygons'] ?? null) : null;
        if ($mask === null && $polygons === null) return null;
        $maskLength += strlen($mask['rle'] ?? '');
        $hasTint = array_key_exists('tint', $layer);
        if ($maskLength > XAR_ANIMATED_MAP_MAX_MASK_LENGTH || $hasTint && (
            !is_string($layer['tint']) || preg_match('/^#[0-9a-f]{6}$/iD', $layer['tint']) !== 1)) return null;
        $seen[$kind] = true;
        $layers[] = [
            'kind' => $kind,
            'intensity' => $layer['intensity'],
            'speed' => $layer['speed'],
            'direction' => $layer['direction'],
            'fish' => $kind === 'water' ? $layer['fish'] : 0,
            ...($hasTint ? ['tint' => strtolower($layer['tint'])] : []),
            ...($mask !== null ? ['mask' => $mask] : ['polygons' => $polygons]),
        ];
    }
    return ['version' => 1, 'layers' => $layers];
}

function applicationMapAnimation(mixed $raw, mixed $background): ?array
{
    if (!is_array($raw) || ($raw['version'] ?? null) !== 1 || !applicationMapAnimationId($raw['id'] ?? null)
        || !applicationMapAnimationSource($background) || ($raw['sourceBackground'] ?? null) !== $background) return null;
    $comment = $raw['comment'] ?? '';
    if (array_key_exists('comment', $raw) && (!is_string($raw['comment']) || preg_match('/^[^\x00]{0,1200}$/uD', $comment) !== 1)) return null;
    $plan = applicationMapAnimationPlan($raw['plan'] ?? null);
    return $plan === null ? null : ['version' => 1, 'id' => $raw['id'], 'sourceBackground' => $background, 'plan' => $plan,
        ...($comment !== '' ? ['comment' => $comment] : [])];
}

function applicationMapAnimationLayer(array $map, string $id): array
{
    $layer = $map['layers'][$id] ?? null;
    if (is_array($layer)) return $layer;
    return ($map['activeLayerId'] ?? 'ground') === $id ? $map : [];
}

function applicationMapAnimationAssetReference(string $reference): string
{
    return preg_replace('#^https://regie-xar-tsaroth\.fr/(?:api/v1/)?(?=media/[A-Za-z0-9_-]{24}$)#iD', '/', $reference) ?? $reference;
}

function applicationMapAnimationAnchor(array $layer, mixed $reference): ?string
{
    $background = $layer['background'] ?? null;
    if (!applicationMapAnimationSource($background) || !applicationMapAnimationSource($reference)) return null;
    $registry = $layer['mapTimeWeather'] ?? null;
    $normal = $registry['normal']['background'] ?? null;
    if (($registry['version'] ?? null) !== 1 || !applicationMapAnimationSource($normal)) {
        return $background === $reference ? $background : null;
    }
    $variants = is_array($registry['variants'] ?? null) ? $registry['variants'] : [];
    $normalRef = applicationMapAnimationAssetReference($normal);
    $belongs = static function (string $candidate) use ($normalRef, $variants): bool {
        if (applicationMapAnimationAssetReference($candidate) === $normalRef) return true;
        foreach ($variants as $variant) {
            if (($variant['background'] ?? null) === $candidate && is_string($variant['id'] ?? null)
                && $variant['id'] === applicationMapTimeWeatherVariantId($variant['time'] ?? null, $variant['weather'] ?? null)) return true;
        }
        return false;
    };
    if ($belongs($background) && $belongs($reference)) return $normal;
    return $background === $reference ? $background : null;
}

function applicationNormalizeLayerMapAnimation(array $layer): ?array
{
    $raw = $layer['mapAnimation'] ?? null;
    $source = $raw['sourceBackground'] ?? null;
    if ($raw === null || ($anchor = applicationMapAnimationAnchor($layer, $source)) === null) return null;
    $animation = applicationMapAnimation($raw, $source);
    if ($animation !== null) $animation['sourceBackground'] = $anchor;
    return $animation;
}

function applicationNormalizeMapAnimationFields(array $map): array
{
    unset($map['mapAnimation'], $map['mapAnimationRevision']);
    if (!is_array($map['layers'] ?? null)) return $map;
    foreach ($map['layers'] as $id => $layer) {
        if (!is_array($layer) || (!array_key_exists('mapAnimation', $layer) && !array_key_exists('mapAnimationRevision', $layer))) continue;
        $validLayer = in_array($id, XAR_ANIMATED_MAP_LAYERS, true) && applicationMapAnimationSource($layer['background'] ?? null);
        $animation = $validLayer ? applicationNormalizeLayerMapAnimation($layer) : null;
        if ($animation === null) unset($map['layers'][$id]['mapAnimation']);
        else $map['layers'][$id]['mapAnimation'] = $animation;
        if (!$validLayer || !applicationMapAnimationId($layer['mapAnimationRevision'] ?? null)) {
            unset($map['layers'][$id]['mapAnimationRevision']);
        }
    }
    return $map;
}

function preserveApplicationMapAnimation(array $incoming, array $previous): array
{
    unset($incoming['mapAnimation'], $incoming['mapAnimationRevision']);
    foreach (XAR_ANIMATED_MAP_LAYERS as $id) {
        $layer = $incoming['layers'][$id] ?? null;
        if (!is_array($layer)) continue;
        $previousLayer = applicationMapAnimationLayer($previous, $id);
        $revision = $previousLayer['mapAnimationRevision'] ?? null;
        if (!isset($previousLayer['mapAnimation']) && !applicationMapAnimationId($revision)) {
            unset($incoming['layers'][$id]['mapAnimation'], $incoming['layers'][$id]['mapAnimationRevision']);
            continue;
        }
        $previousBackground = $previousLayer['background'] ?? null;
        $currentBackground = $layer['background'] ?? null;
        $previousAnchor = applicationMapAnimationAnchor($previousLayer, $previousBackground);
        $sameGeometry = $previousBackground === $currentBackground || ($previousAnchor !== null
            && applicationMapAnimationAnchor($layer, $previousAnchor) !== null);
        $animation = $sameGeometry ? applicationNormalizeLayerMapAnimation($previousLayer) : null;
        $anchor = $animation === null ? null : applicationMapAnimationAnchor($layer, $animation['sourceBackground']);
        if ($anchor === null) unset($incoming['layers'][$id]['mapAnimation']);
        else $incoming['layers'][$id]['mapAnimation'] = [...$animation, 'sourceBackground' => $anchor];
        if ($sameGeometry && applicationMapAnimationId($revision)) {
            $incoming['layers'][$id]['mapAnimationRevision'] = $revision;
        } else unset($incoming['layers'][$id]['mapAnimationRevision']);
    }
    return $incoming;
}

function publicApplicationMapAnimation(array $map, string $visibleLayerId): ?array
{
    $layer = applicationMapAnimationLayer($map, $visibleLayerId);
    $background = $map['background'] ?? null;
    $animation = applicationNormalizeLayerMapAnimation($layer);
    if ($animation === null || applicationMapAnimationAnchor($layer, $background) === null) return null;
    $animation['sourceBackground'] = $background;
    unset($animation['comment']);
    return $animation;
}

function applyApplicationMapAnimationChange(array $map, array $args): array
{
    $layerId = $args['layerId'] ?? null;
    $action = $args['action'] ?? null;
    $expectedBackground = $args['expectedBackground'] ?? null;
    $expectedAnimationId = $args['expectedAnimationId'] ?? null;
    $expectedAnimationRevision = $args['expectedAnimationRevision'] ?? null;
    $requestId = $args['requestId'] ?? null;
    if (!in_array($layerId, XAR_ANIMATED_MAP_LAYERS, true) || !in_array($action, ['apply', 'remove'], true)
        || !applicationMapAnimationId($requestId) || !applicationMapAnimationSource($expectedBackground)
        || !array_key_exists('expectedAnimationId', $args) || !array_key_exists('expectedAnimationRevision', $args)
        || $expectedAnimationId !== null && !applicationMapAnimationId($expectedAnimationId)
        || $expectedAnimationRevision !== null && !applicationMapAnimationId($expectedAnimationRevision)) {
        throw new RuntimeException('Référence de carte ou d’animation invalide.', 400);
    }
    $layer = applicationMapAnimationLayer($map, $layerId);
    if (($layer['background'] ?? null) !== $expectedBackground) {
        throw new RuntimeException('Le fond a changé. Reprenez l’aperçu sur cette carte.', 409);
    }
    $current = applicationNormalizeLayerMapAnimation($layer);
    $revision = applicationMapAnimationId($layer['mapAnimationRevision'] ?? null) ? $layer['mapAnimationRevision'] : null;
    $next = $action === 'apply' ? applicationMapAnimation($args['animation'] ?? null, $expectedBackground) : null;
    if ($action === 'apply' && ($next === null || $next['id'] !== $requestId)) {
        throw new RuntimeException('La description de l’animation est invalide.', 400);
    }
    if ($next !== null) $next['sourceBackground'] = applicationMapAnimationAnchor($layer, $expectedBackground);
    if ($revision === $requestId && $current === $next) {
        return ['map' => $map, 'animationId' => $next['id'] ?? null, 'deduplicated' => true];
    }
    if ($revision !== $expectedAnimationRevision || ($current['id'] ?? null) !== $expectedAnimationId || $revision === $requestId) {
        throw new RuntimeException('L’animation a changé. Rouvrez son aperçu avant de la modifier.', 409);
    }
    $map['layers'][$layerId] ??= ['background' => $expectedBackground,
        ...(isset($layer['mapTimeWeather']) ? ['mapTimeWeather' => $layer['mapTimeWeather']] : [])];
    $map['layers'][$layerId]['mapAnimationRevision'] = $requestId;
    if ($next !== null) $map['layers'][$layerId]['mapAnimation'] = $next;
    else unset($map['layers'][$layerId]['mapAnimation']);
    unset($map['mapAnimation'], $map['mapAnimationRevision']);
    return ['map' => $map, 'animationId' => $next['id'] ?? null, 'deduplicated' => false];
}

function onlineMapAnimationCommand(PDO $db, array &$records, array &$pending, array $identity, array $args): array
{
    if (!onlineIdentityIsGm($identity)) throw new RuntimeException('L’animation de la carte est réservée au MJ.', 403);
    $scene = $args['sceneId'] ?? null;
    $request = $args['requestId'] ?? null;
    if (!is_string($scene) || !validApplicationDomainKey('map:' . $scene) || !applicationMapAnimationId($request)) {
        throw new RuntimeException('Référence de scène ou de demande invalide.', 400);
    }
    $key = 'map:' . $scene;
    $records = array_replace($records, applicationDomainRecords($db, [$key, 'scene:' . $scene]));
    $map = applicationDomainPayload($records, $key);
    if ($map === [] || applicationDomainPayload($records, 'scene:' . $scene) === []) {
        throw new RuntimeException('Cette scène n’existe plus.', 409);
    }
    $change = applyApplicationMapAnimationChange($map, $args);
    $prepared = prepareApplicationDomainUpsert($key, $change['map'], $records[$key] ?? null);
    if ($prepared !== null) $pending[$key] = $prepared;
    unset($change['map']);
    return $change;
}
