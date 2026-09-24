<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/v1/online.php';

final class HeartbeatResponse extends RuntimeException {
    public function __construct(public int $status, public array $body) { parent::__construct('HTTP ' . $status); }
}
function sendJson(int $status, array $body): never { throw new HeartbeatResponse($status, $body); }
function sendError(int $status, string $message, string $code = ''): never { sendJson($status, ['code' => $code]); }
function resolveSession(PDO $connection, string $token): array { return ['id' => 'fixture-player']; }
function requestSessionToken(): string { return str_repeat('t', 43); }
function tokenHash(string $token): string { return hash('sha256', $token, true); }
function readJsonBody(int $maximum): array { return ['connectionId' => str_repeat('A', 22)]; }
function utcAfter(int $seconds): string { return '2026-09-24 00:00:00.000'; }
final class HeartbeatStatement extends PDOStatement {
    private array $params = [];
    public function __construct(private HeartbeatConnection $db, private string $sql) {}
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool { $this->params[$param] = $value; return true; }
    public function execute(?array $params = null): bool {
        if (($this->params[':token_hash'] ?? '') !== tokenHash(requestSessionToken())) throw new RuntimeException('Heartbeat lost session ownership.');
        $this->db->queries[] = $this->sql; return true;
    }
    public function rowCount(): int { return $this->db->changed ? 1 : 0; }
    public function fetchColumn(int $column = 0): mixed { return $this->db->exists ? 1 : false; }
}
final class HeartbeatConnection extends PDO {
    public array $queries = [];
    public function __construct(public bool $changed, public bool $exists) {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new HeartbeatStatement($this, $query); }
}
foreach ([[true, true, 200], [false, true, 200], [false, false, 404]] as [$changed, $exists, $expected]) {
    $db = new HeartbeatConnection($changed, $exists);
    try { touchOnlineConnection($db); } catch (HeartbeatResponse $response) {
        if ($response->status !== $expected) throw new RuntimeException('Wrong heartbeat status for changed=' . (int) $changed . ', exists=' . (int) $exists);
        if (!$changed && count($db->queries) !== 2) throw new RuntimeException('Unchanged heartbeat did not verify ownership and existence.');
    }
}
echo "Heartbeat accepts an unchanged millisecond touch and rejects a missing or foreign connection.\n";
