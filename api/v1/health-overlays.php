<?php

declare(strict_types=1);

const XAR_HEALTH_OVERLAY_ORIGIN = 'https://regie-xar-tsaroth.fr';

function healthOverlayState(mixed $current, mixed $maximum, bool $manualDeath = false): array
{
    return onlineHealthState($current, $maximum, true, $manualDeath);
}

function healthOverlayProjection(array $character, array $record): array
{
    $resources = is_array($character['resources'] ?? null) ? $character['resources'] : [];
    $hp = is_numeric($resources['hp'] ?? null) && is_finite((float) $resources['hp']) ? (float) $resources['hp'] : 0.0;
    $maxHp = is_numeric($resources['maxHp'] ?? null) && is_finite((float) $resources['maxHp'])
        ? max(0.0, (float) $resources['maxHp'])
        : 0.0;
    $mana = is_numeric($resources['mana'] ?? null) && is_finite((float) $resources['mana'])
        ? max(0.0, (float) $resources['mana'])
        : 0.0;
    $maxMana = is_numeric($resources['maxMana'] ?? null) && is_finite((float) $resources['maxMana'])
        ? max(0.0, (float) $resources['maxMana'])
        : 0.0;
    $hasMana = $maxMana > 0;
    $color = is_string($character['color'] ?? null) ? $character['color'] : '';
    if (preg_match('/^#[0-9a-f]{6}$/iD', $color) !== 1) {
        $color = '#8d72cb';
    }
    $state = healthOverlayState($hp, $maxHp, onlineManualDeath($character));
    return [
        'name' => substr(trim((string) ($character['name'] ?? 'Personnage')), 0, 120) ?: 'Personnage',
        'color' => strtolower($color),
        'hp' => $hp,
        'maxHp' => $maxHp,
        'percentage' => $state['percentage'],
        'mana' => $mana,
        'maxMana' => $maxMana,
        'hasMana' => $hasMana,
        'manaPercentage' => $hasMana ? max(0.0, min(100.0, $mana / $maxMana * 100)) : 0.0,
        'state' => $state['code'],
        'effect' => $state['effect'],
        'revision' => (int) ($record['revision'] ?? 0),
        'updatedAt' => (string) ($record['updated_at'] ?? ''),
    ];
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
    return healthOverlayProjection($character, $record);
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
        . '.overlay{--character-color:#8d72cb;--tone:#f4ead6;--bar-a:#8d354e;--bar-b:#df727b;width:100%;min-width:155px;max-width:360px;padding:14px 18px;border:1px solid rgba(212,170,106,.42);border-radius:14px;background:linear-gradient(135deg,rgba(7,8,17,.92),rgba(18,12,27,.86));box-shadow:0 10px 35px rgba(0,0,0,.55);color:var(--tone);text-shadow:0 2px 5px #000}'
        . '.name{display:block;overflow:hidden;margin-bottom:12px;color:var(--character-color);font:700 42px/1 Georgia,serif;text-overflow:ellipsis;white-space:nowrap;-webkit-text-stroke:1px #000;text-shadow:-2px -2px 0 #000,2px -2px 0 #000,-2px 2px 0 #000,2px 2px 0 #000,0 3px 7px #000}.resource+.resource{margin-top:10px}.resource-heading{display:flex;align-items:end;justify-content:space-between;gap:12px}.resource-label{font-size:12px;font-weight:900;letter-spacing:.12em}.value{font-size:22px;font-weight:900;white-space:nowrap}.bar{height:8px;margin-top:7px;overflow:hidden;border-radius:99px;background:rgba(255,255,255,.12)}.bar i{display:block;width:0;height:100%;border-radius:inherit;background:linear-gradient(90deg,var(--bar-a),var(--bar-b));box-shadow:0 0 12px var(--bar-b);transition:width .35s ease}.mana{--bar-a:#3156a8;--bar-b:#75a8ff}.effect{display:none;margin-top:10px;font-size:14px;font-weight:900;letter-spacing:.14em;text-align:center;text-transform:uppercase}'
        . '.overlay.critical{--tone:#ff6070;--bar-a:#97142d;--bar-b:#ff4d65}.overlay.down{--tone:#bd9aff;--bar-a:#4b2687;--bar-b:#aa70ff}.overlay.dead{--tone:#b1a9b8;--bar-a:#4b4650;--bar-b:#888}.overlay.critical .effect,.overlay.down .effect,.overlay.dead .effect{display:block}'
        . '</style></head><body><main id="overlay" class="overlay" aria-live="polite"><strong id="name" class="name">Personnage</strong><div class="resource"><div class="resource-heading"><span class="resource-label">PV</span><span id="health-value" class="value">— / —</span></div><div class="bar"><i id="health-bar"></i></div></div><div id="mana" class="resource mana" hidden><div class="resource-heading"><span class="resource-label">Mana</span><span id="mana-value" class="value">— / —</span></div><div class="bar"><i id="mana-bar"></i></div></div><div id="effect" class="effect"></div></main>'
        . '<script nonce="' . $nonceHtml . '">const endpoint=' . $endpointJson . ';const root=document.getElementById("overlay"),nameNode=document.getElementById("name"),healthValue=document.getElementById("health-value"),healthBar=document.getElementById("health-bar"),manaRow=document.getElementById("mana"),manaValue=document.getElementById("mana-value"),manaBar=document.getElementById("mana-bar"),effect=document.getElementById("effect");const display=n=>Number.isInteger(n)?String(n):String(Math.round(n*100)/100);const percent=n=>Math.max(0,Math.min(100,Number(n)||0))+"%";async function refresh(){try{const response=await fetch(endpoint,{cache:"no-store",credentials:"omit",headers:{Accept:"application/json"}});if(!response.ok)throw new Error();const payload=await response.json();const health=payload.health||{},color=String(health.color||"");nameNode.textContent=String(health.name||"Personnage");root.style.setProperty("--character-color",/^#[0-9a-f]{6}$/i.test(color)?color:"#8d72cb");healthValue.textContent=display(Number(health.hp)||0)+" / "+display(Number(health.maxHp)||0);healthBar.style.width=percent(health.percentage);manaRow.hidden=health.hasMana!==true;if(health.hasMana===true){manaValue.textContent=display(Number(health.mana)||0)+" / "+display(Number(health.maxMana)||0);manaBar.style.width=percent(health.manaPercentage)}root.className="overlay "+(["critical","down","dead"].includes(health.state)?health.state:"");effect.textContent=String(health.effect||"");root.hidden=false}catch{root.hidden=true}}refresh();setInterval(refresh,2500);</script></body></html>';
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
