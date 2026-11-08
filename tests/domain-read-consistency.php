<?php
declare(strict_types=1);
define('XAR_TACTICAL_FIXTURE_ONLY', true);
require __DIR__.'/tactical-lifecycle.php';
function requireGmIdentity(PDO $connection): array { return $GLOBALS['testIdentity']; }

// Deterministic second-writer interleaving. No real database or network.
final class SnapshotReadStatement extends PDOStatement {
    private array $rows = [];
    public function __construct(private SnapshotReadConnection $db, private string $sql) {}
    public function execute(?array $params = null): bool { $this->rows=$this->db->read($this->sql,$params??[]); return true; }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed { return array_shift($this->rows)??false; }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array { return $this->rows; }
    public function fetchColumn(int $column=0):mixed { $row=$this->fetch();return is_array($row)?array_values($row)[$column]??false:false; }
}
final class SnapshotReadConnection extends PDO {
    public ?MemoryConnection $snapshot = null;
    public int $clockReads=0, $begins=0, $commits=0, $rollbacks=0;
    public bool $isolationSet=false, $failDomainRead=false;
    public array $changes=[['global_revision'=>10,'domain_key'=>'character:character-player','domain_revision'=>1,'operation'=>'upsert']];
    private ?array $snapshotChanges=null;
    public function __construct(public MemoryConnection $live, public string $interleave='clock') { $live->revision=10; }
    public function inTransaction():bool { return $this->snapshot!==null; }
    public function exec(string $statement):int|false {
        if($statement!=='SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'||$this->inTransaction())throw new RuntimeException('Unexpected transaction setting');
        $this->isolationSet=true;return 0;
    }
    public function beginTransaction():bool {
        if($this->inTransaction())throw new RuntimeException('Nested transaction');
        $this->snapshot=new MemoryConnection([]);$this->snapshot->domains=$this->live->domains;$this->snapshot->revision=$this->live->revision;
        $this->snapshotChanges=$this->changes;$this->begins++;return true;
    }
    public function commit():bool { $this->snapshot=null;$this->snapshotChanges=null;$this->commits++;return true; }
    public function rollBack():bool { $this->snapshot=null;$this->snapshotChanges=null;$this->rollbacks++;return true; }
    public function prepare(string $query,array $options=[]):PDOStatement|false { return new SnapshotReadStatement($this,$query); }
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false { $s=$this->prepare($query);$s->execute();return $s; }
    public function read(string $sql,array $params):array {
        $view=$this->snapshot??$this->live;
        if(str_starts_with($sql,'SELECT COALESCE(MIN(global_revision)'))return [[10]];
        if(str_contains($sql,'FROM application_domain_changes'))return array_values(array_filter($this->snapshotChanges??$this->changes,static fn($c)=>$c['global_revision']>$params[':since']));
        if(str_starts_with($sql,'SELECT domain_key, schema_version')&&$this->failDomainRead)throw new RuntimeException('fixture_read_failure');
        $rows=$view->executeSql($sql,$params);
        $clock=str_starts_with($sql,'SELECT global_revision, state_schema_version');
        if($clock)$this->clockReads++;
        $firstDocuments=str_starts_with($sql,'SELECT domain_key, schema_version')&&in_array('table',$params,true);
        if(($this->interleave==='clock'&&$clock&&$this->clockReads===2)||($this->interleave==='documents'&&$firstDocuments)){
            $this->interleave='done';
            $character=$this->live->payload('character:character-player');$character['resources']['hp']=77;
            $this->live->put('character:character-player',$character,2);$this->live->revision=11;
            $this->changes[]=['global_revision'=>11,'domain_key'=>'character:character-player','domain_revision'=>2,'operation'=>'upsert'];
        }
        return $rows;
    }
}
function snapshotCheck(bool $condition,string $message):void { if(!$condition)throw new RuntimeException($message);$GLOBALS['snapshotChecks']=($GLOBALS['snapshotChecks']??0)+1; }
function readSnapshotDomains(SnapshotReadConnection $db,int $since=0):array {
    $GLOBALS['testIdentity']=['id'=>'account-gm','effective_mode'=>'gm','permanent_role'=>'gm'];
    $_GET=['since'=>(string)$since,'presence'=>'0'];
    try { readApplicationDomains($db); } catch(TestResponse $response) {
        snapshotCheck($response->status===200,'Domain read succeeds');return $response->body;
    }
}
foreach(['private','player','delta','reset'] as $mode){
    $db=new SnapshotReadConnection(fixture());
    $result=match($mode){'private'=>domainApplicationStateRecord($db),'player'=>playerApplicationStateRecord($db),default=>readSnapshotDomains($db,$mode==='delta'?9:0)};
    $characters=$result['state']['characters']??array_column($result['domains']??[],'payload','key');
    $character=$result['state']['characters'][0]??$characters['character:character-player'];
    snapshotCheck($result['revision']===10&&$character['resources']['hp']===10,$mode.': the snapshot cannot combine revision 10 with a revision-11 character');
    snapshotCheck($db->live->revision===11&&$db->live->payload('character:character-player')['resources']['hp']===77,$mode.': concurrent writer really committed');
    snapshotCheck($db->begins===1&&$db->commits===1&&!$db->inTransaction()&&$db->isolationSet,$mode.': owned snapshot closes before transport');
}
// A write between the table/index fetch and character fetch must not tear a player snapshot either.
$db=new SnapshotReadConnection(fixture(),'documents');$state=playerApplicationStateRecord($db);
snapshotCheck($state['revision']===10&&$state['state']['characters'][0]['resources']['hp']===10,'The multi-query player read stays on one committed state');

// The read must not commit or roll back a transaction owned by its caller.
$db=new SnapshotReadConnection(fixture(),'none');$db->beginTransaction();
domainApplicationStateRecord($db);
snapshotCheck($db->begins===1&&$db->commits===0&&$db->inTransaction(),'Caller transaction remains open');$db->rollBack();

$db=new SnapshotReadConnection(fixture(),'none');$db->failDomainRead=true;
try { playerApplicationStateRecord($db);throw new RuntimeException('Read failure was swallowed'); }
catch(RuntimeException $error){snapshotCheck($error->getMessage()==='fixture_read_failure','Original read failure is preserved');}
snapshotCheck($db->rollbacks===1&&!$db->inTransaction(),'Owned read transaction is cleaned up on failure');
echo json_encode(['ok'=>true,'checks'=>$GLOBALS['snapshotChecks'],'productionAccess'=>false],JSON_THROW_ON_ERROR)."\n";
