<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/v1/online.php';

// Execute the native stream with a fictitious SQL adapter. No account or
// database connection is loaded; elapsed time still comes from the real loop.
function requireMethod(string $method, array $allowed): void { if (!in_array($method, $allowed, true)) throw new RuntimeException('Unexpected method'); }
function requestSessionToken(): string { return str_repeat('F', 43); }
function resolveSession(PDO $connection, string $token, bool $refresh = true): ?array {
    return ($GLOBALS['sseCase'] ?? '') === 'unauthenticated' ? null : ['id'=>'fixture','takeover_requested_at'=>null];
}
function tokenHash(string $token): string { return hash('sha256', $token, true); }
function utcAfter(int $seconds): string { return gmdate('Y-m-d H:i:s', time()+$seconds); }
function ensureDomainStoreInitialized(PDO $connection): void {}
function domainClockRecord(PDO $connection): array { return ['globalRevision'=>7]; }
function dateTimestamp(mixed $value): int { return 0; }
function backendSessionNeedsHandoff(array $identity): bool { return ($GLOBALS['sseCase'] ?? '') === 'handoff'; }
function sendError(int $status, string $message, string $code=''): never { echo 'ERROR '.$status.' '.$code; exit(0); }
final class LeaseStatement extends PDOStatement {
    public function __construct() {}
    public function execute(?array $params=null): bool { return true; }
    public function bindValue(string|int $param, mixed $value, int $type=PDO::PARAM_STR): bool { return true; }
    public function fetchColumn(int $column=0): mixed { return 8; }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT, mixed ...$args): array { return []; }
    public function rowCount(): int { return 1; }
}
final class LeasePdo extends PDO {
    public function __construct() {}
    public function prepare(string $query, array $options=[]): PDOStatement|false { return new LeaseStatement(); }
    public function query(string $query, ?int $fetchMode=null, mixed ...$fetchModeArgs): PDOStatement|false { return new LeaseStatement(); }
}
if (($argv[1] ?? '') === '--stream') {
    $GLOBALS['sseCase'] = $argv[2] ?? 'idle';
    $_GET=['connectionId'=>rtrim(strtr(base64_encode(str_repeat('f',16)),'+/','-_'),'='),'revision'=>'7'];
    $_SERVER['REQUEST_METHOD']='GET';
    streamOnlineEvents(new LeasePdo());
}
$checks=0;
function checkLease(bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message); $GLOBALS['checks']++;
}
checkLease(XAR_SSE_STREAM_LIFETIME_SECONDS <= 0.75, 'FastCGI lease is bounded independently of reconnect jitter.');
foreach (['idle','handoff','unauthenticated'] as $case) {
    $started=microtime(true);
    $process=proc_open([PHP_BINARY,__FILE__,'--stream',$case],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Fixture child unavailable');
    fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    checkLease($exit===0 && $error==='', 'The native stream runs without warnings.');
    checkLease(microtime(true)-$started < 5, 'The lease releases its worker before the interactive deadline.');
    if ($case==='unauthenticated') {
        checkLease(str_contains($output,'ERROR 401 authentication_required') && !str_contains($output,'event:'),'An invalid session cannot open the stream.');
        continue;
    }
    checkLease(str_contains($output,'event: heartbeat') && str_contains($output,'event: reconnect'),'The relay can keep downstream connected while rotating upstream.');
    checkLease(substr_count($output,'event: revision')===1 && str_contains($output,'id: 8'),'A revision during the lease is delivered once with its read cursor.');
    if ($case==='handoff') checkLease(str_contains($output,'event: session-takeover'),'Backend handoff remains visible immediately.');
}
echo 'Baux SSE PHP : '.$checks." contrôles réussis.\n";
