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
final class DiagnosticStatement extends PDOStatement {
    private array $rows = [];
    public function __construct(private DiagnosticConnection $db, private string $sql) {}
    public function execute(?array $params = null): bool { $this->rows = $this->db->run($this->sql, $params ?? []); return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $orientation = PDO::FETCH_ORI_NEXT, int $offset = 0): mixed { return array_shift($this->rows) ?? false; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
    public function fetchColumn(int $column = 0): mixed { return array_values($this->fetch() ?: [0])[$column] ?? false; }
}
final class DiagnosticConnection extends PDO {
    public array $events = []; public ?string $shareHash = null; public bool $expired = false;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new DiagnosticStatement($this, $query); }
    public function run(string $sql, array $p): array {
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
echo "Diagnostics PHP : $checks contrôles réussis.\n";
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
echo "Diagnostics renforcés PHP : $checks contrôles réussis.\n";
