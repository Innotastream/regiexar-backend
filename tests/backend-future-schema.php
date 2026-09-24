<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/v1/domains.php';
$source = file_get_contents(__DIR__ . '/../api/v1/index.php');
$constantsStart = strpos($source, 'const XAR_API_HOST');
$constantsEnd = strpos($source, 'date_default_timezone_set(');
$schemaStart = strpos($source, 'function ensureCurrentSchema(');
$schemaEnd = strpos($source, 'function backendReleaseState(');
eval(substr($source, $constantsStart, $constantsEnd - $constantsStart));
eval(substr($source, $schemaStart, $schemaEnd - $schemaStart));

final class SchemaStatement extends PDOStatement {
    public function __construct(private mixed $value) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetchColumn(int $column = 0): mixed { return $this->value; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->value; }
}
final class SchemaConnection extends PDO {
    public array $queries = [];
    public function __construct(private array $versions, public array $clock = []) {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $this->queries[] = $query;
        if (str_contains($query, 'schema_migrations')) return new SchemaStatement(array_shift($this->versions));
        if (str_contains($query, 'application_domain_clock')) return new SchemaStatement($this->clock);
        throw new RuntimeException('Unexpected query: ' . $query);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $this->queries[] = $query;
        if (str_contains($query, 'GET_LOCK') || str_contains($query, 'RELEASE_LOCK')) return new SchemaStatement(1);
        throw new RuntimeException('Unexpected mutation: ' . $query);
    }
    public function exec(string $query): int|false { throw new RuntimeException('Unexpected schema mutation: ' . $query); }
}
function expectSchemaRefusal(callable $action, string $code): void {
    try { $action(); } catch (RuntimeException $error) {
        if ($error->getMessage() === $code) return;
        throw $error;
    }
    throw new RuntimeException('Future schema was accepted: ' . $code);
}

ensureCurrentSchema(new SchemaConnection([XAR_DATABASE_SCHEMA_VERSION]));
expectSchemaRefusal(fn () => ensureCurrentSchema(new SchemaConnection([XAR_DATABASE_SCHEMA_VERSION + 1])), 'future_database_schema');
expectSchemaRefusal(fn () => ensureCurrentSchema(new SchemaConnection([XAR_DATABASE_SCHEMA_VERSION - 1, XAR_DATABASE_SCHEMA_VERSION + 1])), 'future_database_schema');
$currentClock = ['global_revision' => 19, 'state_schema_version' => XAR_SESSION_SCHEMA_VERSION,
    'domain_schema_version' => XAR_DOMAIN_SCHEMA_VERSION, 'legacy_revision' => null, 'initialized_at' => 'done'];
foreach (['state_schema_version', 'domain_schema_version'] as $field) {
    $clock = $currentClock; $clock[$field]++;
    expectSchemaRefusal(fn () => ensureDomainStoreInitialized(new SchemaConnection([], $clock)), 'future_domain_schema');
    expectSchemaRefusal(fn () => domainClockRecord(new SchemaConnection([], $clock), true), 'future_domain_schema');
}
if (domainClockRecord(new SchemaConnection([], $currentClock))['globalRevision'] !== 19) throw new RuntimeException('Current schema changed.');
echo "Future database and domain schemas are refused before writes, including a migration race.\n";
