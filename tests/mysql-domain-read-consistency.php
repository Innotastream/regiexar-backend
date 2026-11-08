<?php
declare(strict_types=1);
if(getenv('XAR_TEST_MYSQL_ALLOW_LOCAL')!=='1'){
    echo "MySQL consistent domain read test skipped: explicit local opt-in required.\n";exit(0);
}
$dsn=(string)getenv('XAR_TEST_MYSQL_DSN');
if(preg_match('/^mysql:host=127\.0\.0\.1;port=([1-9][0-9]{3,4});charset=utf8mb4$/D',$dsn,$port)!==1||(int)$port[1]>65535)
    throw new RuntimeException('Explicit loopback DSN required.');
define('XAR_TACTICAL_FIXTURE_ONLY',true);
require __DIR__.'/tactical-lifecycle.php';
function requireGmIdentity(PDO $connection):array { return ['id'=>'fixture-gm','permanent_role'=>'gm','effective_mode'=>'gm']; }

// Commit a real second connection immediately after the reader fetched its
// revision. This is deterministic and does not require sleeps or a worker race.
final class InterleavedSqlConnection extends PDO {
    public ?PDO $writer=null;
    public int $clockReads=0;
    public bool $armed=false, $writerCommitted=false;
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false {
        $result=$fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$fetchModeArgs);
        if($this->armed&&str_starts_with($query,'SELECT global_revision, state_schema_version')&&++$this->clockReads===2){
            $this->armed=false;$writer=$this->writer;
            $writer->beginTransaction();
            try {
                $writer->exec("UPDATE application_domains SET payload=JSON_SET(payload,'$.resources.hp',77), revision=2 WHERE domain_key='character:character-player'");
                $writer->exec('UPDATE application_domain_clock SET global_revision=11 WHERE singleton_id=1');
                $writer->exec("INSERT INTO application_domain_changes VALUES(11,'character:character-player',2,'upsert')");
                $writer->commit();$this->writerCommitted=true;
            }catch(Throwable $error){if($writer->inTransaction())$writer->rollBack();throw $error;}
        }
        return $result;
    }
}
function sqlSnapshotCheck(bool $condition,string $message):void {
    if(!$condition)throw new RuntimeException($message);
    $GLOBALS['sqlSnapshotChecks']=($GLOBALS['sqlSnapshotChecks']??0)+1;
}
function sqlSnapshotResponse(PDO $db,int $since):array {
    $_GET=['since'=>(string)$since,'presence'=>'0'];
    try { readApplicationDomains($db); }catch(TestResponse $response){
        sqlSnapshotCheck($response->status===200,'Domain response succeeds');return $response->body;
    }
}
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$writer=new PDO($dsn,(string)getenv('XAR_TEST_MYSQL_USER'),(string)getenv('XAR_TEST_MYSQL_PASSWORD'),$options);
$reader=new InterleavedSqlConnection($dsn,(string)getenv('XAR_TEST_MYSQL_USER'),(string)getenv('XAR_TEST_MYSQL_PASSWORD'),$options);
$schema='regie_read_consistency_'.bin2hex(random_bytes(8));
try {
    $writer->exec('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4');
    $writer->exec('USE `'.$schema.'`');$reader->exec('USE `'.$schema.'`');
    $reader->writer=$writer;
    $reader->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $writer->exec('CREATE TABLE application_domain_clock(singleton_id INT PRIMARY KEY,global_revision BIGINT,state_schema_version INT,domain_schema_version INT,legacy_revision BIGINT NULL,initialized_at DATETIME(3)) ENGINE=InnoDB');
    $writer->exec('CREATE TABLE application_domains(domain_key VARCHAR(200) PRIMARY KEY,schema_version INT,revision BIGINT,payload JSON,updated_at DATETIME(3)) ENGINE=InnoDB');
    $writer->exec('CREATE TABLE application_domain_changes(global_revision BIGINT,domain_key VARCHAR(200),domain_revision BIGINT,operation VARCHAR(10),PRIMARY KEY(global_revision,domain_key)) ENGINE=InnoDB');
    $insert=$writer->prepare('INSERT INTO application_domains VALUES(?,1,1,?,UTC_TIMESTAMP(3))');
    foreach(fixture()->domains as $key=>$row)$insert->execute([$key,json_encode($row['payload'],JSON_THROW_ON_ERROR)]);
    $writer->exec('INSERT INTO application_domain_clock VALUES(1,10,'.XAR_SESSION_SCHEMA_VERSION.','.XAR_DOMAIN_SCHEMA_VERSION.",NULL,'2026-11-07 00:00:00')");
    foreach(['private','player','reset','delta'] as $mode){
        $writer->exec("UPDATE application_domains SET payload=JSON_SET(payload,'$.resources.hp',10), revision=1 WHERE domain_key='character:character-player'");
        $writer->exec('UPDATE application_domain_clock SET global_revision=10');
        $writer->exec('DELETE FROM application_domain_changes');
        $writer->exec("INSERT INTO application_domain_changes VALUES(10,'character:character-player',1,'upsert')");
        $reader->clockReads=0;$reader->armed=true;$reader->writerCommitted=false;
        $result=match($mode){'private'=>domainApplicationStateRecord($reader),'player'=>playerApplicationStateRecord($reader),default=>sqlSnapshotResponse($reader,$mode==='delta'?9:0)};
        $character=$result['state']['characters'][0]??array_column($result['domains'],'payload','key')['character:character-player'];
        sqlSnapshotCheck($result['revision']===10&&$character['resources']['hp']===10,$mode.': one real SQL snapshot keeps revision and payload coherent');
        sqlSnapshotCheck($reader->writerCommitted&&(int)$writer->query('SELECT global_revision FROM application_domain_clock')->fetchColumn()===11,$mode.': writer commits while reader holds its MVCC view');
        sqlSnapshotCheck(!$reader->inTransaction(),$mode.': read transaction ends before response');
        // MariaDB uses this variable; the starting session policy remains intact.
        sqlSnapshotCheck($reader->query('SELECT @@tx_isolation')->fetchColumn()==='READ-COMMITTED',$mode.': isolation override does not change the session default');
    }
    $reader->beginTransaction();domainApplicationStateRecord($reader);
    sqlSnapshotCheck($reader->inTransaction(),'Caller-owned transaction is preserved');$reader->rollBack();
    echo json_encode(['ok'=>true,'checks'=>$GLOBALS['sqlSnapshotChecks'],'productionAccess'=>false],JSON_THROW_ON_ERROR)."\n";
    echo "Local MySQL consistent domain read integration passed.\n";
}finally{
    if($reader->inTransaction())$reader->rollBack();
    if($writer->inTransaction())$writer->rollBack();
    $writer->exec('DROP DATABASE IF EXISTS `'.$schema.'`');
}
