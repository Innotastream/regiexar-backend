<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/v1/diagnostics.php';
const XAR_BACKEND_VERSION = 'test';
const XAR_API_HOST = 'regie-xar-tsaroth.fr';
final class DiagnosticResponse extends RuntimeException {
    public function __construct(public int $status, public array $body) { parent::__construct(json_encode($body)); }
}
function sendJson(int $status, array $body, bool $head = false): never { throw new DiagnosticResponse($status, $body); }
function sendError(int $status, string $message, string $code = ''): never { sendJson($status, ['error' => $message, 'code' => $code]); }
function requireIdentity(PDO $db): array { return $GLOBALS['identity']; }
function readJsonBody(): array { return $GLOBALS['body']; }
function randomToken(int $bytes): string { return str_repeat('a', 43); }
function requireMethod(string $method, array $allowed): void { if (!in_array($method, $allowed, true)) sendError(405, 'method'); }
function backendDiagnosticRows(int $limit = 500, ?int $since = null): array {
    return array_slice(array_values(array_filter(
        $GLOBALS['readerServerEvents'] ?? [],
        static fn(array $event): bool => $since === null || strtotime($event['at']) >= $since,
    )), 0, $limit);
}
final class DiagnosticStatement extends PDOStatement {
    private array $rows = [];
    private array $bindings = [];
    public function __construct(private DiagnosticConnection $db, private string $sql) {}
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool {
        $this->bindings[$param] = $value;
        return true;
    }
    public function execute(?array $params = null): bool { $this->rows = $this->db->run($this->sql, $params ?? $this->bindings); return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $orientation = PDO::FETCH_ORI_NEXT, int $offset = 0): mixed { return array_shift($this->rows) ?? false; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function fetchColumn(int $column = 0): mixed { return array_values($this->fetch() ?: [0])[$column] ?? false; }
}
final class DiagnosticConnection extends PDO {
    public array $events = []; public ?string $shareHash = null; public bool $expired = false;
    public array $readerRows = [];
    public array $reads = [];
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new DiagnosticStatement($this, $query); }
    public function run(string $sql, array $p): array {
        if (str_starts_with($sql, 'SELECT e.effective_mode')) {
            $this->reads[] = [$sql, $p];
            $rows = array_filter(array_slice($this->readerRows, 0, 500), static fn(array $row): bool =>
                $row['created_at'] >= $p[':since'] && (!isset($p[':username']) || strcasecmp($p[':username'], $row['username']) === 0
                    || strcasecmp($p[':display_name'], $row['display_name']) === 0));
            return array_map(static fn(array $row): array =>
                array_intersect_key($row, array_flip(['effective_mode', 'client_version', 'payload_json', 'created_at'])),
                array_slice(array_values($rows), 0, $p[':limit']));
        }
        if (str_starts_with($sql, 'SELECT COUNT')) return [[count($this->events)]];
        if (str_starts_with($sql, 'INSERT IGNORE INTO client_error_events')) { $this->events[$p[':account'] . ':' . $p[':event']] ??= $p; return []; }
        if (str_starts_with($sql, 'INSERT INTO diagnostic_shares')) { $this->shareHash = $p[':hash']; return []; }
        if (str_starts_with($sql, 'SELECT id FROM diagnostic_shares')) return !$this->expired && $this->shareHash === $p[':hash'] ? [['id' => 1]] : [];
        if (str_starts_with($sql, 'SELECT sequence_id')) return array_map(static fn(array $event): array => ['event_id' => $event[':event'], 'account_id' => $event[':account'], 'payload_json' => $event[':payload']], array_values($this->events));
        throw new RuntimeException('SQL inattendu : ' . $sql);
    }
}
$checks = 0;
function checkDiagnostic(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); $GLOBALS['checks']++; }
function diagnosticCall(DiagnosticConnection $db, string $route, string $method = 'GET', array $body = []): DiagnosticResponse {
    $GLOBALS['body'] = $body;
    try { if (str_contains($route, '/shared/')) handlePublicDiagnosticShare($db, $route, $method, false); else handleDiagnosticRoute($db, $route, $method, false); }
    catch (DiagnosticResponse $response) { return $response; }
    throw new RuntimeException('Réponse absente');
}
$db = new DiagnosticConnection();
$GLOBALS['identity'] = ['id' => 'player', 'effective_mode' => 'player', 'permanent_role' => 'player'];
$event = ['id' => 'diagnostic-event-0001', 'message' => 'Bearer secret-credential', 'authorization' => 'sensitive', 'nested' => ['cookie' => 'private', 'revision' => 42], 'unicode' => str_repeat('é', 9000)];
$result = diagnosticCall($db, '/api/v1/diagnostics/errors', 'POST', ['events' => [$event]]);
checkDiagnostic($result->status === 200 && $result->body['accepted'] === [$event['id']], 'Player error reaches the private central journal.');
diagnosticCall($db, '/api/v1/diagnostics/errors', 'POST', ['events' => [$event]]);
checkDiagnostic(count($db->events) === 1, 'Retry inserts exactly one event.');
checkDiagnostic(!str_contains(json_encode($db->events), 'secret-credential') && !str_contains(json_encode($db->events), 'sensitive') && !str_contains(json_encode($db->events), 'private'), 'Credentials are stripped on the server.');
checkDiagnostic(diagnosticCall($db, '/api/v1/diagnostics/errors')->status === 403 && diagnosticCall($db, '/api/v1/diagnostics/share', 'POST')->status === 403, 'Players cannot read or share other clients errors.');
$GLOBALS['identity'] = ['id' => 'gm', 'effective_mode' => 'gm', 'permanent_role' => 'gm'];
checkDiagnostic(count(diagnosticCall($db, '/api/v1/diagnostics/errors')->body['errors']) === 1, 'Every MJ can inspect the central journal.');
$share = diagnosticCall($db, '/api/v1/diagnostics/share', 'POST');
checkDiagnostic($share->status === 200 && $share->body['expiresInSeconds'] === 86400 && $db->shareHash === hash('sha256', str_repeat('a', 43)), 'Sharing stores a hash and expires after one day.');
$route = '/api/v1/diagnostics/shared/' . str_repeat('a', 43);
checkDiagnostic(diagnosticCall($db, $route)->status === 200, 'The explicitly created read-only link is usable.');
checkDiagnostic(diagnosticCall($db, $route, 'POST')->status === 405, 'A diagnostic share cannot write.');
$db->expired = true; checkDiagnostic(diagnosticCall($db, $route)->status === 404, 'An expired share exposes nothing.');
// Defense in depth against arbitrary client payloads, not only a few secret keys.
$clean = sanitizeApplicationDiagnostic(['id' => 'diagnostic-event-0002', 'notes' => 'CANARY', 'character' => ['name' => 'CANARY'], 'headers' => ['authorization' => 'CANARY'], 'request' => ['body' => ['password' => 'CANARY'], 'command' => 'token.roll'], 'message' => 'password=CANARY secret=CANARY cookie="CANARY" https://user:CANARY@example.invalid/path?key=CANARY', 'stack' => 'C:\\Users\\Private\\CANARY.txt']);
checkDiagnostic(!str_contains(json_encode($clean), 'CANARY'), 'Unknown content and textual credentials are excluded.');
$GLOBALS['identity'] = ['id' => 'gm', 'effective_mode' => 'player', 'permanent_role' => 'gm'];
checkDiagnostic(diagnosticCall($db, '/api/v1/diagnostics/errors')->status === 403, 'An MJ in player mode cannot inspect private logs.');
checkDiagnostic(diagnosticCall($db, '/api/v1/diagnostics/share', 'POST')->status === 403, 'An MJ in player mode cannot grant diagnostic access.');
$forged = diagnosticCall($db, '/api/v1/diagnostics/errors', 'POST', ['events' => [['id' => 'diagnostic-forged-0001', 'role' => 'gm', 'accountId' => 'someone-else', 'clientVersion' => '99.99.99']]]);
checkDiagnostic($forged->status === 200, 'An authenticated player can still submit diagnostics.');
$stored = json_decode($db->events['gm:diagnostic-forged-0001'][':payload'], true);
checkDiagnostic($stored['role'] === 'player' && !isset($stored['accountId']) && $stored['clientVersion'] !== '99.99.99', 'Attribution and version are server-authoritative.');

function readerDiagnosticCall(?array $configuration, string $method, array $query, array $headers, callable $connect): DiagnosticResponse {
    try { serveDiagnosticReader($configuration, $method, $query, $headers, $connect); }
    catch (DiagnosticResponse $response) { return $response; }
}

$token = rtrim(strtr(base64_encode(str_repeat("\x19", 32)), '+/', '-_'), '=');
$wrongToken = rtrim(strtr(base64_encode(str_repeat("\x18", 32)), '+/', '-_'), '=');
$configuration = ['diagnostics' => ['reader_token_sha256' => strtoupper(hash('sha256', $token))]];
$headers = ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
$connectCalls = 0;
$connect = static function() use ($db, &$connectCalls): PDO { $connectCalls++; return $db; };

foreach ([null, [], ['diagnostics' => ['reader_token_sha256' => '']], ['diagnostics' => ['reader_token_sha256' => $token]]] as $disabled) {
    checkDiagnostic(readerDiagnosticCall($disabled, 'GET', [], $headers, $connect)->status === 404, 'Reader access is disabled without a private SHA-256 key.');
}
$_COOKIE['xar_session'] = $token;
$_SERVER['HTTP_X_XAR_SESSION'] = $token;
foreach ([[], ['HTTP_X_XAR_SESSION' => $token], ['HTTP_AUTHORIZATION' => 'Bearer ' . $wrongToken], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token . '=']] as $untrusted) {
    checkDiagnostic(readerDiagnosticCall($configuration, 'GET', [], $untrusted, $connect)->status === 401, 'No cookie, normal session header, wrong or malformed token authorizes the reader.');
}
$nonCanonical = str_repeat('A', 42) . 'B';
$nonCanonicalConfiguration = ['diagnostics' => ['reader_token_sha256' => hash('sha256', $nonCanonical)]];
checkDiagnostic(readerDiagnosticCall($nonCanonicalConfiguration, 'GET', [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $nonCanonical], $connect)->status === 401, 'Reject a noncanonical base64url representation even if the digest matches.');
foreach (['HEAD', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS'] as $method) {
    checkDiagnostic(readerDiagnosticCall($configuration, $method, [], $headers, $connect)->status === 405, 'Only GET is accepted.');
}
foreach ([['hours' => '169'], ['limit' => '201'], ['hours' => '0'], ['limit' => ['2']], ['account' => 'Goldark'], ['token' => $token]] as $query) {
    checkDiagnostic(readerDiagnosticCall($configuration, 'GET', $query, $headers, $connect)->status === 400, 'Only the bounded time and count options may be in the query.');
}
foreach (['', '%ZZ', '%0a', str_repeat('x', 97), '%FF'] as $account) {
    checkDiagnostic(readerDiagnosticCall($configuration, 'GET', [], $headers + ['HTTP_X_XAR_DIAGNOSTIC_ACCOUNT' => $account], $connect)->status === 400, 'Malformed, invalid UTF-8 and oversized account headers are rejected.');
}
checkDiagnostic($connectCalls === 0, 'Disabled, unauthenticated and invalid requests do not connect or read.');

$recent = gmdate('Y-m-d H:i:s', time() - 60);
$old = gmdate('Y-m-d H:i:s', time() - 48 * 3600);
$db->readerRows = [];
foreach ([
    ['Un autre compte', 'Another', $recent, 'other'],
    ['Goldark', 'Nom affiché', $recent, 'recent'],
    ['Goldark', 'Nom affiché', $old, 'old'],
    ['Goldark-suffix', 'Autre nom', $recent, 'prefix'],
] as [$username, $displayName, $created, $code]) {
    $db->readerRows[] = [
        'username' => $username, 'display_name' => $displayName, 'created_at' => $created,
        'effective_mode' => 'player', 'client_version' => '4.3.0',
        'payload_json' => json_encode([
            'id' => 'private-diagnostic-event-id', 'accountId' => 'CANARY-ACCOUNT', 'at' => gmdate('c'),
            'source' => 'client', 'status' => 503, 'code' => $code, 'message' => 'CANARY-FREE-FORM',
            'stack' => '/home/private/CANARY-PATH', 'route' => '/api/v1/session?token=CANARY-QUERY',
            'request' => ['command' => 'attack.resolve', 'domainChanges' => [['key' => 'CANARY-DOMAIN']]],
        ], JSON_THROW_ON_ERROR),
    ];
}
$GLOBALS['readerServerEvents'] = [
    ['at' => gmdate('c'), 'source' => 'php-exception', 'code' => 'exception', 'accountId' => 'CANARY-ID'],
    ['at' => gmdate('c', time() - 30), 'source' => 'php-http', 'code' => 'unavailable'],
    ['at' => gmdate('c', time() - 48 * 3600), 'source' => 'php-http', 'code' => 'old'],
];
$result = readerDiagnosticCall($configuration, 'GET', ['hours' => '1', 'limit' => '2'], [
    'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer ' . $token,
    'HTTP_X_XAR_DIAGNOSTIC_ACCOUNT' => rawurlencode('Nom affiché'),
], $connect);
checkDiagnostic($result->status === 200 && $result->body['clientJournalAvailable'] === true, 'The capability works without starting a normal session.');
checkDiagnostic(array_column(array_column($result->body['errors'], 'details'), 'code') === ['recent'], 'The account and time window match only recent exact account logs.');
checkDiagnostic(array_column($result->body['serverErrors'], 'code') === ['exception', 'unavailable'], 'General PHP events remain visible without account filtering.');
$safeBody = json_encode($result->body, JSON_THROW_ON_ERROR);
checkDiagnostic(!str_contains($safeBody, 'CANARY') && !str_contains($safeBody, 'private-diagnostic-event-id') && !str_contains($safeBody, $token)
    && !str_contains($safeBody, 'Nom affiché') && !str_contains($safeBody, 'Goldark'), 'Identifiers, request body, private path and secrets stay outside the response.');
checkDiagnostic($result->body['errors'][0]['details']['request'] === ['command' => 'attack.resolve'], 'Only the technical command is projected from request metadata.');
[$sql, $bound] = $db->reads[0];
checkDiagnostic(str_contains($sql, 'LIMIT 500') && str_contains($sql, 'CAST(LOWER(a.username) AS BINARY) = CAST(LOWER(:username) AS BINARY)')
    && str_contains($sql, 'CAST(LOWER(a.display_name) AS BINARY) = CAST(LOWER(:display_name) AS BINARY)') && !str_contains($sql, 'LIKE')
    && $bound[':username'] === 'Nom affiché' && $bound[':display_name'] === 'Nom affiché' && $bound[':limit'] === 2,
    'SQL is a bounded SELECT and account values are bound and matched exactly.');
$result = readerDiagnosticCall($configuration, 'GET', ['limit' => '1'], $headers, $connect);
checkDiagnostic(count($result->body['errors']) === 1 && count($result->body['serverErrors']) === 1, 'Each source independently respects the requested limit.');
$result = readerDiagnosticCall($configuration, 'GET', [], $headers + ['HTTP_X_XAR_DIAGNOSTIC_ACCOUNT' => 'goldark'], $connect);
checkDiagnostic(count($result->body['errors']) === 1 && $result->body['errors'][0]['details']['code'] === 'recent', 'Exact usernames also match case insensitively.');
$result = readerDiagnosticCall($configuration, 'GET', [], $headers + ['HTTP_X_XAR_DIAGNOSTIC_ACCOUNT' => 'Absent'], $connect);
checkDiagnostic($result->status === 200 && $result->body['errors'] === [], 'An unmatched account provides no directory or explicit existence result.');
$result = readerDiagnosticCall($configuration, 'GET', [], $headers, static fn(): PDO => throw new RuntimeException('CANARY-DB'));
checkDiagnostic($result->status === 200 && $result->body['clientJournalAvailable'] === false && $result->body['errors'] === []
    && count($result->body['serverErrors']) === 2 && !str_contains(json_encode($result->body), 'CANARY-DB'),
    'PHP diagnostics remain available during a database outage without leaking connection errors.');
echo "Diagnostics renforcés PHP : $checks contrôles réussis.\n";
