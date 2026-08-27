<?php

declare(strict_types=1);

const XAR_HEALTH_OVERLAY_ORIGIN = 'https://regie-xar-tsaroth.fr';

function healthOverlayState(mixed $current, mixed $maximum): array
{
    $hp = is_numeric($current) && is_finite((float) $current) ? (float) $current : 0.0;
    $maxHp = is_numeric($maximum) && is_finite((float) $maximum) ? max(0.0, (float) $maximum) : 0.0;
    $percentage = $maxHp > 0.0 ? min(100.0, max(0.0, ($hp / $maxHp) * 100.0)) : 0.0;
    if ($maxHp <= 0.0) {
        return ['code' => 'normal', 'effect' => '', 'percentage' => $percentage];
    }
    if ($hp <= 0.0) {
        return ['code' => 'down', 'effect' => 'Syncope', 'percentage' => $percentage];
    }
    if ($percentage < 10.0) {
        return ['code' => 'critical', 'effect' => 'Critique', 'percentage' => $percentage];
    }
    return ['code' => 'normal', 'effect' => '', 'percentage' => $percentage];
}

function healthOverlayCharacter(PDO $connection, string $slug): ?array
{
    $statement = $connection->prepare(
        'SELECT o.character_id, d.payload, d.revision, d.updated_at '
        . 'FROM character_health_overlays o '
        . "INNER JOIN application_domains d ON d.domain_key = CONCAT('character:', o.character_id) "
        . 'WHERE o.public_slug = :public_slug LIMIT 1'
    );
    $statement->execute([':public_slug' => $slug]);
    $record = $statement->fetch();
    if (!is_array($record)) {
        return null;
    }
    $character = jsonColumn($record['payload'] ?? null);
    $characterId = (string) ($record['character_id'] ?? '');
    $ownerPlayerId = trim((string) ($character['ownerPlayerId'] ?? ''));
    if ($characterId === '' || (string) ($character['id'] ?? '') !== $characterId || $ownerPlayerId === '') {
        return null;
    }
    $resources = is_array($character['resources'] ?? null) ? $character['resources'] : [];
    $hp = is_numeric($resources['hp'] ?? null) ? (float) $resources['hp'] : 0.0;
    $maxHp = is_numeric($resources['maxHp'] ?? null) ? max(0.0, (float) $resources['maxHp']) : 0.0;
    $state = healthOverlayState($hp, $maxHp);
    return [
        'name' => substr(trim((string) ($character['name'] ?? 'Personnage')), 0, 120) ?: 'Personnage',
        'hp' => $hp,
        'maxHp' => $maxHp,
        'percentage' => $state['percentage'],
        'state' => $state['code'],
        'effect' => $state['effect'],
        'revision' => (int) ($record['revision'] ?? 0),
        'updatedAt' => (string) ($record['updated_at'] ?? ''),
    ];
}

function healthOverlayJson(PDO $connection, string $slug, bool $headOnly): never
{
    $character = healthOverlayCharacter($connection, $slug);
    if (!is_array($character)) {
        sendJson(404, ['ok' => false, 'error' => 'Calque introuvable.', 'code' => 'overlay_not_found'], $headOnly);
    }
    sendJson(200, ['ok' => true, 'health' => $character], $headOnly);
}

function healthOverlayHtml(PDO $connection, string $slug, bool $headOnly): never
{
    if (!is_array(healthOverlayCharacter($connection, $slug))) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo $headOnly ? '' : 'Calque introuvable.';
        exit;
    }
    $nonce = base64_encode(random_bytes(18));
    $endpoint = '/api/v1/health-overlay/' . $slug;
    $endpointJson = json_encode($endpoint, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $nonceHtml = htmlspecialchars($nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>PV · Xar Tsaroth</title><style nonce="' . $nonceHtml . '">'
        . ':root{color-scheme:dark}*{box-sizing:border-box}html,body{width:100%;height:100%;margin:0;overflow:hidden;background:transparent}'
        . 'body{display:flex;align-items:center;justify-content:center;padding:18px;font-family:Inter,Segoe UI,sans-serif}'
        . '.overlay{--tone:#f4ead6;--bar-a:#8d354e;--bar-b:#df727b;min-width:310px;max-width:720px;padding:14px 18px;border:1px solid rgba(212,170,106,.42);border-radius:14px;background:linear-gradient(135deg,rgba(7,8,17,.92),rgba(18,12,27,.86));box-shadow:0 10px 35px rgba(0,0,0,.55);color:var(--tone);text-shadow:0 2px 5px #000}'
        . '.heading{display:flex;align-items:end;justify-content:space-between;gap:20px}.name{overflow:hidden;font:700 21px Georgia,serif;text-overflow:ellipsis;white-space:nowrap}.value{font-size:22px;font-weight:900;white-space:nowrap}.bar{height:8px;margin-top:9px;overflow:hidden;border-radius:99px;background:rgba(255,255,255,.1)}.bar i{display:block;width:var(--hp,0%);height:100%;border-radius:inherit;background:linear-gradient(90deg,var(--bar-a),var(--bar-b));box-shadow:0 0 12px var(--bar-b);transition:width .35s ease}.effect{display:none;margin-top:7px;font-size:11px;font-weight:900;letter-spacing:.14em;text-align:right;text-transform:uppercase}'
        . '.overlay.critical{--tone:#ff6070;--bar-a:#97142d;--bar-b:#ff4d65}.overlay.down{--tone:#bd9aff;--bar-a:#4b2687;--bar-b:#aa70ff}.overlay.critical .effect,.overlay.down .effect{display:block}'
        . '</style></head><body><main id="overlay" class="overlay" aria-live="polite"><div class="heading"><strong id="name" class="name">Personnage</strong><span id="value" class="value">— / —</span></div><div class="bar"><i id="bar"></i></div><div id="effect" class="effect"></div></main>'
        . '<script nonce="' . $nonceHtml . '">const endpoint=' . $endpointJson . ';const root=document.getElementById("overlay"),nameNode=document.getElementById("name"),valueNode=document.getElementById("value"),bar=document.getElementById("bar"),effect=document.getElementById("effect");const display=n=>Number.isInteger(n)?String(n):String(Math.round(n*100)/100);async function refresh(){try{const response=await fetch(endpoint,{cache:"no-store",credentials:"omit",headers:{Accept:"application/json"}});if(!response.ok)throw new Error();const payload=await response.json();const health=payload.health||{};nameNode.textContent=String(health.name||"Personnage");valueNode.textContent=display(Number(health.hp)||0)+" / "+display(Number(health.maxHp)||0);bar.style.width=Math.max(0,Math.min(100,Number(health.percentage)||0))+"%";root.className="overlay "+(["critical","down"].includes(health.state)?health.state:"");effect.textContent=String(health.effect||"");root.hidden=false}catch{root.hidden=true}}refresh();setInterval(refresh,2500);</script></body></html>';
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header("Content-Security-Policy: default-src 'none'; connect-src 'self'; style-src 'nonce-{$nonce}'; script-src 'nonce-{$nonce}'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
    header('Content-Length: ' . strlen($html));
    if (!$headOnly) {
        echo $html;
    }
    exit;
}

function handlePublicHealthOverlayRoute(PDO $connection, string $route, string $method, bool $headOnly): bool
{
    if (preg_match('#^/api/v1/health-overlay/([A-Za-z0-9_-]{43})$#', $route, $match) === 1) {
        requireMethod($method, ['GET', 'HEAD']);
        healthOverlayJson($connection, $match[1], $headOnly);
    }
    if (preg_match('#^/health/([A-Za-z0-9_-]{43})$#', $route, $match) === 1) {
        requireMethod($method, ['GET', 'HEAD']);
        healthOverlayHtml($connection, $match[1], $headOnly);
    }
    return false;
}

function healthOverlayRecord(array $record): array
{
    $slug = (string) ($record['public_slug'] ?? '');
    return [
        'characterId' => (string) ($record['character_id'] ?? ''),
        'shareUrl' => XAR_HEALTH_OVERLAY_ORIGIN . '/health/' . $slug,
        'createdAt' => (string) ($record['created_at'] ?? ''),
        'regeneratedAt' => $record['regenerated_at'] === null ? null : (string) $record['regenerated_at'],
    ];
}

function listHealthOverlays(PDO $connection, bool $headOnly): never
{
    requireGmIdentity($connection);
    $statement = $connection->query(
        'SELECT o.character_id, o.public_slug, o.created_at, o.regenerated_at '
        . 'FROM character_health_overlays o '
        . "INNER JOIN application_domains d ON d.domain_key = CONCAT('character:', o.character_id) "
        . "WHERE JSON_UNQUOTE(JSON_EXTRACT(d.payload, '$.ownerPlayerId')) <> '' "
        . 'ORDER BY o.created_at, o.character_id'
    );
    $records = $statement === false ? [] : $statement->fetchAll();
    sendJson(200, ['ok' => true, 'overlays' => array_map('healthOverlayRecord', $records)], $headOnly);
}

function manageHealthOverlay(PDO $connection): never
{
    $identity = requireGmIdentity($connection);
    $payload = readJsonBody(8192);
    $characterId = trim((string) ($payload['characterId'] ?? ''));
    $action = (string) ($payload['action'] ?? 'ensure');
    if (preg_match('/^[A-Za-z0-9_-]{1,180}$/D', $characterId) !== 1) {
        sendError(400, 'Référence de personnage invalide.', 'invalid_character');
    }
    if (!in_array($action, ['ensure', 'regenerate'], true)) {
        sendError(400, 'Action de calque invalide.', 'invalid_overlay_action');
    }
    $domain = $connection->prepare('SELECT payload FROM application_domains WHERE domain_key = :domain_key LIMIT 1');
    $domain->execute([':domain_key' => 'character:' . $characterId]);
    $character = jsonColumn($domain->fetchColumn());
    if ((string) ($character['id'] ?? '') !== $characterId || trim((string) ($character['ownerPlayerId'] ?? '')) === '') {
        sendError(404, 'Ce personnage joueur est introuvable.', 'character_not_found');
    }
    $connection->beginTransaction();
    try {
        $select = $connection->prepare(
            'SELECT character_id, public_slug, created_at, regenerated_at '
            . 'FROM character_health_overlays WHERE character_id = :character_id FOR UPDATE'
        );
        $select->execute([':character_id' => $characterId]);
        $record = $select->fetch();
        if (!is_array($record)) {
            $slug = randomToken();
            $insert = $connection->prepare(
                'INSERT IGNORE INTO character_health_overlays '
                . '(character_id, public_slug, created_by_account_id) VALUES (:character_id, :public_slug, :created_by)'
            );
            $insert->execute([
                ':character_id' => $characterId,
                ':public_slug' => $slug,
                ':created_by' => (string) $identity['id'],
            ]);
        } elseif ($action === 'regenerate') {
            $slug = randomToken();
            $update = $connection->prepare(
                'UPDATE character_health_overlays SET public_slug = :public_slug, regenerated_at = UTC_TIMESTAMP(3) '
                . 'WHERE character_id = :character_id'
            );
            $update->execute([':public_slug' => $slug, ':character_id' => $characterId]);
        }
        $select->execute([':character_id' => $characterId]);
        $saved = $select->fetch();
        if (!is_array($saved)) {
            throw new RuntimeException('health_overlay_missing');
        }
        $connection->commit();
        sendJson(is_array($record) ? 200 : 201, ['ok' => true, 'overlay' => healthOverlayRecord($saved)]);
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }
}

function handleHealthOverlayManagementRoute(PDO $connection, string $route, string $method, bool $headOnly): bool
{
    if ($route !== '/api/v1/health-overlays') {
        return false;
    }
    if ($method === 'GET' || $method === 'HEAD') {
        listHealthOverlays($connection, $headOnly);
    }
    if ($method === 'POST') {
        manageHealthOverlay($connection);
    }
    requireMethod($method, ['GET', 'HEAD', 'POST']);
    return true;
}
