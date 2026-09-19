<?php
declare(strict_types=1);

function sanitizeApplicationDiagnostic(mixed $value, int $depth = 0): mixed {
    if ($depth > 8) return '[profondeur limitée]';
    if (is_array($value)) {
        $clean = [];
        foreach (array_slice($value, 0, 80, true) as $key => $child) {
            if (is_string($key) && preg_match('/password|authorization|cookie|sessionToken|accessToken|refreshToken|apiKey|clientSecret|webhook|privateKey|secretKey/i', $key)) continue;
            $clean[$key] = sanitizeApplicationDiagnostic($child, $depth + 1);
        }
        return $clean;
    }
    if (is_string($value)) return substr(preg_replace('/Bearer\s+\S+|([?&](?:token|key|secret|signature)=)[^\s&]+/i', '[masqué]', $value), 0, 12000);
    return is_scalar($value) || $value === null ? $value : null;
}

function requireDiagnosticGm(array $identity): void {
    if (($identity['permanent_role'] ?? '') !== 'gm' || ($identity['effective_mode'] ?? '') !== 'gm') sendError(403, 'Le journal central est réservé aux MJ.', 'diagnostic_forbidden');
}

function applicationDiagnosticRows(PDO $connection, int $after = 0): array {
    $statement = $connection->prepare('SELECT sequence_id, event_id, account_id, effective_mode, client_version, payload_json, created_at FROM client_error_events WHERE sequence_id > :after ORDER BY sequence_id DESC LIMIT 500');
    $statement->execute([':after' => max(0, $after)]);
    return array_map(static function(array $row): array {
        $payload = json_decode((string) $row['payload_json'], true); unset($row['payload_json']);
        return [...$row, 'details' => is_array($payload) ? $payload : []];
    }, $statement->fetchAll());
}

function handlePublicDiagnosticShare(PDO $connection, string $route, string $method, bool $headOnly): bool {
    if (!preg_match('#^/api/v1/diagnostics/shared/([A-Za-z0-9_-]{43})$#D', $route, $match)) return false;
    requireMethod($method, ['GET', 'HEAD']);
    $statement = $connection->prepare('SELECT id FROM diagnostic_shares WHERE token_hash = :hash AND expires_at > UTC_TIMESTAMP(3) LIMIT 1');
    $statement->execute([':hash' => hash('sha256', $match[1])]);
    if (!$statement->fetch()) sendError(404, 'Ce lien de diagnostic a expiré ou a été révoqué.', 'diagnostic_share_missing');
    sendJson(200, ['ok' => true, 'generatedAt' => gmdate('c'), 'backendVersion' => XAR_BACKEND_VERSION, 'errors' => applicationDiagnosticRows($connection)], $headOnly);
}

function handleDiagnosticRoute(PDO $connection, string $route, string $method, bool $headOnly): bool {
    if (!in_array($route, ['/api/v1/diagnostics/errors', '/api/v1/diagnostics/share'], true)) return false;
    $identity = requireIdentity($connection);
    if ($route === '/api/v1/diagnostics/share') {
        requireMethod($method, ['POST']); requireDiagnosticGm($identity);
        $token = randomToken(32);
        $statement = $connection->prepare('INSERT INTO diagnostic_shares (token_hash, account_id, expires_at) VALUES (:hash, :account, DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 1 DAY))');
        $statement->execute([':hash' => hash('sha256', $token), ':account' => $identity['id']]);
        sendJson(200, ['ok' => true, 'url' => 'https://' . XAR_API_HOST . '/api/v1/diagnostics/shared/' . $token, 'expiresInSeconds' => 86400]);
    }
    if ($method === 'GET' || $method === 'HEAD') {
        requireDiagnosticGm($identity);
        sendJson(200, ['ok' => true, 'errors' => applicationDiagnosticRows($connection, (int) ($_GET['after'] ?? 0))], $headOnly);
    }
    requireMethod($method, ['POST']);
    $body = readJsonBody(); $events = $body['events'] ?? null;
    if (!is_array($events) || !array_is_list($events) || count($events) > 20) sendError(400, 'Lot de diagnostics invalide.', 'invalid_diagnostics');
    $rate = $connection->prepare('SELECT COUNT(*) FROM client_error_events WHERE account_id = :account AND created_at > DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 MINUTE)');
    $rate->execute([':account' => $identity['id']]);
    if ((int) $rate->fetchColumn() > 200) sendError(429, 'Le journal reçoit trop de diagnostics. Ils seront repris plus tard.', 'diagnostic_rate_limit');
    $insert = $connection->prepare('INSERT IGNORE INTO client_error_events (event_id, account_id, effective_mode, client_version, payload_json) VALUES (:event, :account, :mode, :version, :payload)');
    $accepted = [];
    foreach ($events as $event) {
        if (!is_array($event) || !preg_match('/^[A-Za-z0-9_-]{16,80}$/D', (string) ($event['id'] ?? ''))) continue;
        $clean = sanitizeApplicationDiagnostic($event);
        $encoded = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 32000) continue;
        $insert->execute([':event' => $event['id'], ':account' => $identity['id'], ':mode' => $identity['effective_mode'], ':version' => substr((string) ($_SERVER['HTTP_X_XAR_CLIENT_VERSION'] ?? ''), 0, 40), ':payload' => $encoded]);
        $accepted[] = $event['id'];
    }
    sendJson(200, ['ok' => true, 'accepted' => $accepted]);
}
