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
    $writer->exec('CREATE TABLE application_domains(domain_key VARCHAR(200) PRIMARY KEY,schema_version INT,revision BIGINT,payload JSON,updated_at DATETIME(3),created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3),updated_by_account_id VARCHAR(128) NULL) ENGINE=InnoDB');
    $writer->exec('CREATE TABLE application_domain_changes(global_revision BIGINT,domain_key VARCHAR(200),domain_revision BIGINT,operation VARCHAR(10),PRIMARY KEY(global_revision,domain_key)) ENGINE=InnoDB');
    $writer->exec('CREATE TABLE application_domain_history(domain_key VARCHAR(200),domain_revision BIGINT,global_revision BIGINT,operation VARCHAR(10),payload JSON,changed_by_account_id VARCHAR(128) NULL) ENGINE=InnoDB');
    $insert=$writer->prepare('INSERT INTO application_domains(domain_key,schema_version,revision,payload,updated_at) VALUES(?,1,1,?,UTC_TIMESTAMP(3))');
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
    $departure = ['id' => 'departure', 'revision' => 1, 'kind' => 'walk', 'name' => 'Vers le col',
        'layerId' => 'ground', 'x' => 25, 'y' => 50, 'destination' => ['sceneId' => 'scene-two', 'passageId' => 'arrival']];
    $arrival = ['id' => 'arrival', 'revision' => 1, 'kind' => 'walk', 'name' => 'Le col',
        'layerId' => 'upper', 'x' => 75, 'y' => 50, 'destination' => ['sceneId' => 'scene-one', 'passageId' => 'departure']];
    $sourceMap = ['activeLayerId' => 'ground', 'naturalWidth' => 1600, 'naturalHeight' => 900, 'passages' => [$departure]];
    $targetMap = ['activeLayerId' => 'ground', 'background' => '/private-arrival-bitmap',
        'passages' => [$arrival, [...$arrival, 'id' => 'another-private-entrance', 'name' => 'Private marker sentinel']]];
    $replace = $writer->prepare('UPDATE application_domains SET payload=? WHERE domain_key=?');
    $put = static function(string $key, array $payload) use ($replace): void {
        $replace->execute([json_encode($payload, JSON_THROW_ON_ERROR), $key]);
    };
    $project = static fn(array $state, bool $stream = false): array => publicPlayerState($state, ['id' => 'account-player'], [], $stream);
    $put('map:scene-one', $sourceMap);
    $put('map:scene-two', $targetMap);
    $detachedMap = ['activeLayerId'=>'ground','naturalWidth'=>1600,'naturalHeight'=>900,'background'=>'/detached-only'];
    $insert->execute(['detached-combat',json_encode(['map'=>$detachedMap,'initiative'=>[]],JSON_THROW_ON_ERROR)]);
    [, $scoped] = applicationConsistentDomainRead($reader, static fn(): array => playerApplicationDomainRecords($reader));
    sqlSnapshotCheck(!isset($scoped['detached-combat']), 'A published scene avoids loading the retained detached map document');
    $put('table', ['activeSceneId'=>'missing-scene']);
    sqlSnapshotCheck(playerApplicationStateRecord($reader)['state']['map']['background']==='/detached-only',
        'Missing publication still falls back to a previously saved detached map');
    $put('table', ['activeSceneId'=>'scene-one','tacticalSync'=>['paused'=>false]]);
    $visible = playerApplicationStateRecord($reader)['state'];
    foreach ([false, true] as $stream) {
        $projection = $project($visible, $stream);
        $exit = $projection['map']['passages'][0] ?? [];
        sqlSnapshotCheck($exit['destinationLabel'] === 'Scène préparée · Étage · Le col'
            && $exit['destinationLayerId'] === 'upper', 'Cross-scene passage resolves in the scoped player and Stream reads');
        sqlSnapshotCheck(!str_contains(json_encode($projection, JSON_THROW_ON_ERROR), 'private-arrival-bitmap')
            && !str_contains(json_encode($projection, JSON_THROW_ON_ERROR), 'Private marker sentinel'), 'The projection contains only the linked arrival descriptor');
    }
    $linkedMap = passageMap($visible, 'scene-two');
    sqlSnapshotCheck(!isset($linkedMap['background']) && $linkedMap['tokens'] === []
        && array_column($linkedMap['passages'], 'id') === ['arrival'], 'No full arrival map or unrelated endpoint is loaded into the projection');
    $put('map:scene-two', ['passages' => []]);
    sqlSnapshotCheck($project(playerApplicationStateRecord($reader)['state'])['map']['passages'][0]['destinationLabel']
        === 'Liaison indisponible', 'An actually deleted endpoint still displays as unavailable');
    $put('scene:scene-two', ['metadata' => ['id' => 'scene-two', 'name' => 'Col ancien'], 'combat' => ['map' => $targetMap]]);
    sqlSnapshotCheck($project(playerApplicationStateRecord($reader)['state'])['map']['passages'][0]['destinationLabel']
        === 'Col ancien · Étage · Le col', 'Legacy embedded scenes resolve without a map-domain endpoint');
    $put('table', ['activeSceneId' => 'scene-one', 'tacticalSync' => ['paused' => true]]);
    $insert->execute(['presentation:scene-one', json_encode(['map' => $sourceMap], JSON_THROW_ON_ERROR)]);
    $put('map:scene-one', ['activeLayerId' => 'upper', 'passages' => []]);
    sqlSnapshotCheck($project(playerApplicationStateRecord($reader)['state'])['map']['passages'][0]['destinationLabel']
        === 'Col ancien · Étage · Le col', 'Secret preparation resolves the departure from the published map');
    require_once __DIR__.'/../api/v1/legacy-scene-identities.php';
    $guard = ['id'=>'legacy-guard','name'=>'Guard','layerId'=>'ground','followCharacter'=>false,'x'=>10,'y'=>20,'size'=>40,'hp'=>30,'maxHp'=>40];
    $put('scene-index', ['order'=>['scene-one','scene-copy','scene-pending','scene-invalid']]);
    $put('token-index:scene-one', ['order'=>['token-player','token-monster','legacy-guard']]);
    foreach ([
        'scene:scene-copy' => ['metadata'=>['id'=>'scene-copy','name'=>'Copie']],
        'scene:scene-pending' => ['metadata'=>['id'=>'scene-pending','name'=>'Action en cours']],
        'scene:scene-invalid' => ['metadata'=>['id'=>'scene-invalid','name'=>'Ancien pion invalide']],
        'token:scene-one:legacy-guard' => $guard,
        'token:scene-copy:legacy-guard' => [...$guard,'hp'=>5],
        'token:scene-copy:rider' => [...$guard,'id'=>'rider','targetTokenId'=>'legacy-guard'],
        'token-index:scene-copy' => ['order'=>['legacy-guard','rider']],
        'map:scene-copy' => ['activeLayerId'=>'ground','naturalWidth'=>1600,'naturalHeight'=>900],
        'initiative:scene-copy' => ['active'=>true,'order'=>['legacy-guard','rider'],'round'=>1,'currentIndex'=>0],
        'token-index:scene-pending' => ['order'=>['legacy-guard']],
        'token:scene-pending:legacy-guard' => $guard,
        'token:scene-orphan:legacy-guard' => [...$guard,'hp'=>1],
        'token:scene-invalid:legacy-guard' => [...$guard,'sizeCategory'=>'broken-old-preset'],
        'token-index:scene-invalid' => ['order'=>['legacy-guard']],
        'map:scene-invalid' => ['activeLayerId'=>'ground','naturalWidth'=>1600,'naturalHeight'=>900],
    ] as $key=>$payload) $insert->execute([$key,json_encode($payload, JSON_THROW_ON_ERROR)]);
    $writer->exec("UPDATE application_domains SET created_at='2026-11-01 00:00:00' WHERE domain_key='token:scene-one:legacy-guard'");
    $writer->exec("UPDATE application_domains SET created_at='2026-10-01 00:00:00' WHERE domain_key='token:scene-orphan:legacy-guard'");
    $put('activity', ['pendingAttacks'=>[['sceneId'=>'scene-pending']]]);
    $beforeRepair = domainClockRecord($writer)['globalRevision'];
    sqlSnapshotCheck(sqlSnapshotResponse($writer,0)['revision'] === $beforeRepair + 1, 'Initial authorized MJ read commits the idle copy repair');
    $afterRepair = applicationDomainRecords($writer);
    $copy = applicationDomainPayload($afterRepair,'token-index:scene-copy')['order'][0];
    sqlSnapshotCheck(str_starts_with($copy, 'token-recovered-')
        && applicationDomainPayload($afterRepair, 'token:scene-copy:'.$copy)['hp'] === 5
        && applicationDomainPayload($afterRepair, 'token:scene-one:legacy-guard') === $guard
        && applicationDomainPayload($afterRepair, 'token:scene-pending:legacy-guard') === $guard, 'HP is retained and the original and running action remain intact');
    sqlSnapshotCheck(applicationDomainPayload($afterRepair, 'token:scene-orphan:legacy-guard')['hp'] === 1,
        'An unlisted historical token cannot steal the identity of a token in a current scene');
    sqlSnapshotCheck(applicationDomainPayload($afterRepair, 'token:scene-copy:rider')['targetTokenId'] === $copy
        && applicationDomainPayload($afterRepair, 'initiative:scene-copy')['order'][0] === $copy
        && applicationDomainPayload($afterRepair, 'token:scene-copy:legacy-guard') === [], 'Copy, tactical references and deletion are committed together');
    sqlSnapshotCheck(applicationDomainPayload($afterRepair, 'token:scene-invalid:legacy-guard')['sizeCategory'] === 'broken-old-preset'
        && applicationDomainPayload($afterRepair,'token-index:scene-invalid')['order'] === ['legacy-guard'],
        'An incompatible legacy document is preserved and cannot block repairs in other scenes');
    sqlSnapshotCheck(repairLegacySceneIdentities($writer,[]) === 0 && domainClockRecord($writer)['globalRevision'] === $beforeRepair+1,
        'Reopening the same table does not change its identity again');
    $writer->beginTransaction();
    $clock = domainClockRecord($writer,true);
    $scoped = applicationDomainRecords($writer,['scene:scene-copy','scene-index','token-index:scene-copy']);
    $changes = [
        prepareApplicationDomainDelete('scene:scene-copy',$scoped['scene:scene-copy']),
        prepareApplicationDomainDelete('token-index:scene-copy',$scoped['token-index:scene-copy']),
        prepareApplicationDomainUpsert('scene-index',['order'=>['scene-one','scene-pending','scene-invalid']],$scoped['scene-index']),
    ];
    $removed = reconcileApplicationSceneDomains($writer,$scoped,$changes);
    persistDomainChangesInTransaction($writer,[],$clock,$removed);
    $writer->commit();
    [, $freshRecords] = applicationConsistentDomainRead($reader, static fn(): array => [
        domainClockRecord($reader), applicationDomainRecords($reader),
    ]);
    sqlSnapshotCheck(!isset($freshRecords['map:scene-copy'],$freshRecords['initiative:scene-copy'],
        $freshRecords['token:scene-copy:'.$copy],$freshRecords['token:scene-copy:rider'])
        && isset($freshRecords['token:scene-one:legacy-guard'],$freshRecords['token:scene-pending:legacy-guard']),
        'Real SQL cascade deletes even unsubmitted token documents while preserving other scenes');
    echo json_encode(['ok'=>true,'checks'=>$GLOBALS['sqlSnapshotChecks'],'productionAccess'=>false],JSON_THROW_ON_ERROR)."\n";
    echo "Local MySQL consistent domain read integration passed.\n";
}finally{
    if($reader->inTransaction())$reader->rollBack();
    if($writer->inTransaction())$writer->rollBack();
    $writer->exec('DROP DATABASE IF EXISTS `'.$schema.'`');
}
