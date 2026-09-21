<?php
declare(strict_types=1);

function diagnosticSafeText(mixed $value, int $limit = 2000): string {
    if (!is_scalar($value) && $value !== null) return '';
    $text = substr((string) $value, 0, min(12000, max(1000, $limit * 2)));
    $patterns = [
        '~(?:Bearer|Basic)\s+\S+~i',
        '~\b(?:password|passwd|authorization|cookie|session[-_]?token|access[-_]?token|refresh[-_]?token|api[-_]?key|client[-_]?secret|webhook|token|secret|signature)\b["\x27]?\s*[:=]\s*(?:"[^"\r\n]*"|\x27[^\x27\r\n]*\x27|[^\s,;}]+)~i',
        '~https?://[^\s)"\x27<>]+~i',
        '~(?:[A-Z]:[\\\\/]|file:///|/(?:home|Users|root|tmp|workspace)/)[^\s)"\x27<>]+~i',
        '~\b[\w.+-]{1,128}@[\w.-]{1,128}\.[A-Za-z]{2,20}~',
        '~[A-Za-z0-9_=-]{32,}~',
        '~"[^"\r\n]*"|\x27[^\x27\r\n]{2,}\x27~'
    ];
    return substr((string) preg_replace($patterns, '[masqué]', $text), 0, $limit);
}

function sanitizeApplicationDiagnostic(mixed $value, int $depth = 0): array {
    if (!is_array($value)) return [];
    $clean = [];
    foreach (['id', 'at', 'lastAt', 'source', 'code', 'errorName', 'method', 'role', 'clientVersion', 'operation'] as $key) {
        if (!isset($value[$key]) || !is_string($value[$key])) continue;
        $clean[$key] = $key === 'id' && preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $value[$key]) ? $value[$key] : diagnosticSafeText($value[$key], 120);
    }
    foreach (['message' => 1500, 'stack' => 4000, 'file' => 1500] as $key => $limit) {
        if (isset($value[$key]) && is_string($value[$key])) $clean[$key] = diagnosticSafeText($value[$key], $limit);
    }
    if (isset($value['route']) && is_string($value['route'])) {
        $route = explode('?', explode('#', $value['route'])[0])[0];
        $clean['route'] = diagnosticSafeText(preg_replace('~/[A-Za-z0-9_-]{22,}(?=/|$)~', '/[identifiant]', $route), 500);
    }
    foreach (['status', 'line', 'column', 'revision', 'occurrences', 'dropped'] as $key) {
        if (isset($value[$key]) && is_int($value[$key]) && $value[$key] >= 0) $clean[$key] = min(1000000000, $value[$key]);
    }
    foreach (['online', 'preAuth'] as $key) if (isset($value[$key]) && is_bool($value[$key])) $clean[$key] = $value[$key];
    if (isset($value['request']) && is_array($value['request'])) {
        $request = $value['request'];
        $clean['request'] = ['command' => diagnosticSafeText($request['command'] ?? '', 100), 'domainChanges' => []];
        foreach (array_slice(is_array($request['domainChanges'] ?? null) ? $request['domainChanges'] : [], 0, 10) as $change) {
            if (!is_array($change)) continue;
            $clean['request']['domainChanges'][] = [
                'key' => diagnosticSafeText(explode(':', (string) ($change['key'] ?? ''))[0], 80),
                'operation' => in_array($change['operation'] ?? '', ['upsert', 'delete'], true) ? $change['operation'] : '',
                'expectedRevision' => is_int($change['expectedRevision'] ?? null) ? $change['expectedRevision'] : null,
                'fields' => array_map(static fn(mixed $field): string => diagnosticSafeText($field, 40), array_slice(is_array($change['fields'] ?? null) ? $change['fields'] : [], 0, 12)),
            ];
        }
    }
    return $clean;
}

function requireDiagnosticGm(array $identity): void {
    if (($identity['permanent_role'] ?? '') !== 'gm' || ($identity['effective_mode'] ?? '') !== 'gm') sendError(403, 'Le journal central est réservé aux MJ.', 'diagnostic_forbidden');
}

function applicationDiagnosticRows(PDO $connection, int $after = 0): array {
    $statement = $connection->prepare('SELECT sequence_id, event_id, account_id, effective_mode, client_version, payload_json, created_at FROM client_error_events WHERE sequence_id > :after ORDER BY sequence_id DESC LIMIT 500');
    $statement->execute([':after' => max(0, $after)]);
    return array_map(static function(array $row): array {
        $payload = json_decode((string) $row['payload_json'], true); unset($row['payload_json']);
        return [...$row, 'details' => sanitizeApplicationDiagnostic($payload)];
    }, $statement->fetchAll());
}

function handlePublicDiagnosticShare(PDO $connection, string $route, string $method, bool $headOnly): bool {
    if (!preg_match('#^/api/v1/diagnostics/shared/([A-Za-z0-9_-]{43})$#D', $route, $match)) return false;
    requireMethod($method, ['GET', 'HEAD']);
    $statement = $connection->prepare('SELECT id FROM diagnostic_shares WHERE token_hash = :hash AND expires_at > UTC_TIMESTAMP(3) LIMIT 1');
    $statement->execute([':hash' => hash('sha256', $match[1])]);
    if (!$statement->fetch()) sendError(404, 'Ce lien de diagnostic a expiré ou a été révoqué.', 'diagnostic_share_missing');
    sendJson(200, ['ok' => true, 'generatedAt' => gmdate('c'), 'backendVersion' => XAR_BACKEND_VERSION, 'errors' => applicationDiagnosticRows($connection), 'serverErrors' => function_exists('backendDiagnosticRows') ? backendDiagnosticRows() : []], $headOnly);
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
        sendJson(200, ['ok' => true, 'errors' => applicationDiagnosticRows($connection, (int) ($_GET['after'] ?? 0)), 'serverErrors' => function_exists('backendDiagnosticRows') ? backendDiagnosticRows() : []], $headOnly);
    }
    requireMethod($method, ['POST']);
    $body = readJsonBody(); $events = $body['events'] ?? null;
    if (!is_array($events) || !array_is_list($events) || count($events) > 20) sendError(400, 'Lot de diagnostics invalide.', 'invalid_diagnostics');
    $rate = $connection->prepare('SELECT COUNT(*) FROM client_error_events WHERE account_id = :account AND created_at > DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 1 MINUTE)');
    $rate->execute([':account' => $identity['id']]);
    if ((int) $rate->fetchColumn() + count($events) > 200) sendError(429, 'Le journal reçoit trop de diagnostics. Ils seront repris plus tard.', 'diagnostic_rate_limit');
    $insert = $connection->prepare('INSERT IGNORE INTO client_error_events (event_id, account_id, effective_mode, client_version, payload_json) VALUES (:event, :account, :mode, :version, :payload)');
    $accepted = [];
    foreach ($events as $event) {
        if (!is_array($event) || !preg_match('/^[A-Za-z0-9_-]{16,80}$/D', (string) ($event['id'] ?? ''))) continue;
        $clean = sanitizeApplicationDiagnostic($event);
        $clean['role'] = $identity['effective_mode'];
        $clean['clientVersion'] = diagnosticSafeText($_SERVER['HTTP_X_XAR_CLIENT_VERSION'] ?? '', 40);
        $encoded = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 32000) continue;
        $insert->execute([':event' => $event['id'], ':account' => $identity['id'], ':mode' => $identity['effective_mode'], ':version' => substr((string) ($_SERVER['HTTP_X_XAR_CLIENT_VERSION'] ?? ''), 0, 40), ':payload' => $encoded]);
        $accepted[] = $event['id'];
    }
    sendJson(200, ['ok' => true, 'accepted' => $accepted]);
}
