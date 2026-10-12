<?php
declare(strict_types=1);
if (getenv('XAR_TEST_MYSQL_ALLOW_LOCAL') !== '1') { echo "MySQL Chance reset test skipped: explicit local opt-in required.\n"; exit(0); }
$dsn = (string) getenv('XAR_TEST_MYSQL_DSN');
if (preg_match('/^mysql:host=127\.0\.0\.1;port=([1-9][0-9]{3,4});charset=utf8mb4$/D', $dsn, $port) !== 1 || (int) $port[1] > 65535) throw new RuntimeException('Explicit loopback DSN required.');
require_once __DIR__ . '/../api/v1/domains.php';
require_once __DIR__ . '/../api/v1/online.php';
function randomToken(int $bytes = 32): string { return bin2hex(random_bytes($bytes)); }
function sendError(int $status, string $message, string $code = ''): never { throw new RuntimeException($code, $status); }
function luckSqlConnection(?string $schema = null): PDO {
    $db = new PDO((string)getenv('XAR_TEST_MYSQL_DSN'), (string)getenv('XAR_TEST_MYSQL_USER'), (string)getenv('XAR_TEST_MYSQL_PASSWORD'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    $db->exec("SET time_zone='+00:00', SESSION innodb_lock_wait_timeout=8");
    if ($schema !== null) { if (preg_match('/^regie_luck_reset_[a-f0-9]{16}$/D', $schema) !== 1) throw new RuntimeException('Invalid disposable schema.'); $db->exec('USE `'.$schema.'`'); }
    return $db;
}
function luckSqlCheck(bool $condition, string $label): void { if (!$condition) throw new RuntimeException($label); echo "PASS $label\n"; }
function luckSqlSnapshot(PDO $db): array {
    return [$db->query('SELECT * FROM application_domain_clock')->fetchAll(), $db->query('SELECT * FROM application_domains ORDER BY domain_key')->fetchAll(),
        $db->query('SELECT * FROM application_domain_history ORDER BY history_id')->fetchAll(), $db->query('SELECT * FROM application_domain_changes ORDER BY global_revision, domain_key')->fetchAll()];
}
function luckSqlCommand(PDO $db, string $kind, array $args, array $identity): array {
    $db->beginTransaction();
    try {
        $clock=domainClockRecord($db,true); $records=applicationDomainRecords($db); $pending=[];
        if ($kind==='reset') $result=onlineResetCharacterLuck($db,$records,$pending,$identity,$args,'fixture-scene');
        else { $result=['recorded'=>onlineRecordCharacterLuckD100($db,$records,$pending,$identity,'fixture-a',$args['raw'])]; }
        $revision=$pending===[]?$clock['globalRevision']:persistDomainChangesInTransaction($db,$identity,$clock,array_values($pending));
        $db->commit(); return ['result'=>$result,'revision'=>$revision];
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}
$gm=['id'=>'fixture-gm','display_name'=>'MJ fictif','permanent_role'=>'gm','effective_mode'=>'gm'];
$owner=['id'=>'fixture-owner-a','display_name'=>'Joueur fictif','permanent_role'=>'player','effective_mode'=>'player'];
if (($argv[1]??'')==='worker') {
    $db=luckSqlConnection((string)getenv('XAR_LUCK_RESET_TEST_SCHEMA'));
    echo json_encode(['ready'=>(int)$db->query('SELECT CONNECTION_ID()')->fetchColumn()])."\n"; flush();
    if (trim((string)fgets(STDIN))!=='GO') throw new RuntimeException('Missing worker barrier.');
    try { echo json_encode(luckSqlCommand($db,$argv[2],$argv[2]==='reset'?['requestId'=>$argv[3]]:['raw'=>(int)$argv[3]],$argv[2]==='reset'?$gm:$owner))."\n"; }
    catch(Throwable $error) { echo json_encode(['error'=>$error->getMessage(),'status'=>$error->getCode()])."\n"; } exit(0);
}
function luckSqlWorker(string $schema, string $kind, string $argument): array {
    $environment=getenv(); $environment['XAR_LUCK_RESET_TEST_SCHEMA']=$schema;
    $process=proc_open([PHP_BINARY,__FILE__,'worker',$kind,$argument],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__,$environment);
    if (!is_resource($process)) throw new RuntimeException('Worker unavailable.'); stream_set_timeout($pipes[1],12);
    $ready=json_decode((string)fgets($pipes[1]),true);
    if (!is_int($ready['ready']??null)) { proc_terminate($process); throw new RuntimeException('Worker did not connect.'); }
    return [$process,$pipes];
}
function luckSqlWorkerResult(array $worker): array {
    [$process,$pipes]=$worker; $result=json_decode((string)fgets($pipes[1]),true); $error=stream_get_contents($pipes[2]);
    foreach($pipes as $pipe) fclose($pipe); $status=proc_close($process);
    if ($status!==0 || !is_array($result) || $error!=='') throw new RuntimeException('Worker failed: '.$error); return $result;
}
$schema='regie_luck_reset_'.bin2hex(random_bytes(8)); $admin=luckSqlConnection(); $workers=[];
try {
    $admin->exec('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4'); $db=luckSqlConnection($schema);
    $db->exec('CREATE TABLE application_domain_clock (singleton_id INT PRIMARY KEY,global_revision BIGINT,state_schema_version INT,domain_schema_version INT,legacy_revision BIGINT NULL,initialized_at DATETIME(3)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domains (domain_key VARCHAR(200) PRIMARY KEY,schema_version INT,revision BIGINT,payload JSON,updated_by_account_id VARCHAR(128) NULL,updated_at DATETIME(3)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domain_history (history_id BIGINT AUTO_INCREMENT PRIMARY KEY,domain_key VARCHAR(200),domain_revision BIGINT,global_revision BIGINT,operation VARCHAR(10),payload JSON,changed_by_account_id VARCHAR(128) NULL,created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domain_changes (global_revision BIGINT,domain_key VARCHAR(200),domain_revision BIGINT,operation VARCHAR(10),PRIMARY KEY(global_revision,domain_key)) ENGINE=InnoDB');
    $db->exec('INSERT INTO application_domain_clock VALUES(1,73,21,1,NULL,UTC_TIMESTAMP(3))');
    foreach (['a','b','unassigned'] as $id) upsertApplicationDomain($db,'character:fixture-'.$id,['id'=>'fixture-'.$id,'ownerPlayerId'=>$id==='unassigned'?'':'fixture-owner-'.$id,
        'name'=>'Fiche fictive '.$id,'lore'=>'Récit privé intact','secret'=>['notes'=>'Notes conservées'],'resources'=>['hp'=>42,'mana'=>12.5],'abilities'=>[]],7,null);
    $oldRecord=['rollCount'=>2,'rawTotal'=>139,'startedAt'=>'2026-10-01T00:00:00Z','updatedAt'=>'2026-10-01T00:00:00Z'];
    upsertApplicationDomain($db,'luck',['characters'=>['fixture-a'=>$oldRecord,'fixture-orphan'=>$oldRecord]],4,null);
    upsertApplicationDomain($db,'activity',['rolls'=>[['id'=>'resolved-raw-failure','total'=>51,'raw'=>51,'success'=>false]],'attackReceipts'=>[['id'=>'historical-receipt']],
        'actionTimers'=>[],'actionTimerTombstones'=>[],'mapPings'=>[],'shortcuts'=>[],'playerActions'=>[],'resourceReceipts'=>[]],3,null);
    $characterRows=$db->query("SELECT * FROM application_domains WHERE domain_key LIKE 'character:%' ORDER BY domain_key")->fetchAll();
    $request=['requestId'=>'luck-sql-reset-request-0001'];
    foreach ([$owner,[...$gm,'effective_mode'=>'player'],[...$owner,'effective_mode'=>'gm']] as $unauthorized) {
        $before=luckSqlSnapshot($db); $status=0;
        try { luckSqlCommand($db,'reset',$request,$unauthorized); } catch(RuntimeException $error) { $status=$error->getCode(); }
        luckSqlCheck($status===403 && luckSqlSnapshot($db)===$before, 'Effective and permanent MJ rights are required before any SQL write.');
    }
    $before=luckSqlSnapshot($db);
    try { luckSqlCommand($db,'reset',[...$request,'value'=>75],$gm); } catch(RuntimeException $error) { $status=$error->getCode(); }
    luckSqlCheck($status===400 && luckSqlSnapshot($db)===$before, 'Unknown reset arguments are rejected atomically.');
    $reset=luckSqlCommand($db,'reset',$request,$gm); $records=applicationDomainRecords($db); $statistics=applicationDomainPayload($records,'luck')['characters'];
    luckSqlCheck($reset['revision']===74 && $reset['result']['characterCount']===3 && count($statistics)===3 && !isset($statistics['fixture-orphan']), 'The global reset covers all current sheets and clears the old aggregate.');
    foreach (['a','b','unassigned'] as $id) luckSqlCheck($statistics['fixture-'.$id]['rollCount']===0 && $statistics['fixture-'.$id]['rawTotal']===0
        && onlineCharacterLuckThreshold($db,$records,[],'fixture-'.$id)===50, 'An existing sheet starts at 50 with no synthetic roll.');
    luckSqlCheck($db->query("SELECT * FROM application_domains WHERE domain_key LIKE 'character:%' ORDER BY domain_key")->fetchAll()===$characterRows, 'No character data or revision is rewritten.');
    $activity=applicationDomainPayload($records,'activity');
    luckSqlCheck($activity['rolls'][0]['raw']===51 && $activity['attackReceipts'][0]['id']==='historical-receipt', 'Already resolved rolls and attack receipts are preserved.');
    luckSqlCheck(count($activity['playerActions'])===1 && $activity['resourceReceipts'][0]['actionId']===$activity['playerActions'][0]['id'], 'One private action and its durable receipt refer to the same reset.');
    $history=json_decode((string)$db->query("SELECT payload FROM application_domain_history WHERE domain_key='luck' ORDER BY history_id LIMIT 1")->fetchColumn(),true);
    luckSqlCheck($history['characters']['fixture-a']===$oldRecord, 'The native history retains the aggregate preimage in the same transaction.');
    luckSqlCommand($db,'roll',['raw'=>80],$owner); $before=luckSqlSnapshot($db);
    $replay=luckSqlCommand(luckSqlConnection($schema),'reset',$request,$gm);
    luckSqlCheck($replay['result']['deduplicated']===true && luckSqlSnapshot($db)===$before, 'A replay on a new SQL connection cannot erase later rolls or bump the revision.');
    $records=[]; luckSqlCheck(onlineCharacterLuckThreshold($db,$records,[],'fixture-a')===80, 'The first actual future roll becomes the current threshold.');
    $status=0; try { luckSqlCommand($db,'reset',$request,[...$gm,'id'=>'other-fixture-gm']); } catch(RuntimeException $error) { $status=$error->getCode(); }
    luckSqlCheck($status===403 && luckSqlSnapshot($db)===$before, 'Another MJ cannot reuse the owner receipt.');
    // Start two identical reset requests behind the actual clock lock.
    $db->beginTransaction(); domainClockRecord($db,true); $historyCount=(int)$db->query('SELECT COUNT(*) FROM application_domain_history')->fetchColumn();
    $workers=[luckSqlWorker($schema,'reset','luck-sql-concurrent-reset-0002'),luckSqlWorker($schema,'reset','luck-sql-concurrent-reset-0002')];
    foreach($workers as $worker){fwrite($worker[1][0],"GO\n");fflush($worker[1][0]);}
    usleep(150000); luckSqlCheck((int)$db->query('SELECT COUNT(*) FROM application_domain_history')->fetchColumn()===$historyCount, 'Competing resets wait for the actual SQL clock.'); $db->commit();
    $results=array_map('luckSqlWorkerResult',$workers); $workers=[];
    luckSqlCheck(count(array_filter($results,static fn($result)=>isset($result['result'])))===2 && count(array_filter($results,static fn($result)=>$result['result']['deduplicated']??false))===1, 'Two identical concurrent requests commit a single reset.');
    luckSqlCheck((int)$db->query('SELECT COUNT(*) FROM application_domain_history')->fetchColumn()===$historyCount+2, 'The replay creates no extra Chance or activity preimage.');
    $workers=[luckSqlWorker($schema,'roll','80'),luckSqlWorker($schema,'roll','20')];
    foreach($workers as $worker){fwrite($worker[1][0],"GO\n");fflush($worker[1][0]);}
    $results=array_map('luckSqlWorkerResult',$workers); $workers=[]; $records=applicationDomainRecords($db); $statistics=applicationDomainPayload($records,'luck')['characters'];
    luckSqlCheck(count(array_filter($results,static fn($result)=>$result['result']['recorded']??false))===2 && $statistics['fixture-a']['rollCount']===2 && $statistics['fixture-a']['rawTotal']===100
        && onlineCharacterLuckThreshold($db,$records,[],'fixture-a')===50, 'Concurrent future rolls both accumulate after the reset with no lost update.');
    echo "Local MySQL Chance reset integration passed.\n";
} finally {
    foreach($workers as [$process,$pipes]){foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);if(is_resource($process)){proc_terminate($process);proc_close($process);}}
    if(isset($db)&&$db->inTransaction())$db->rollBack();
    if(preg_match('/^regie_luck_reset_[a-f0-9]{16}$/D',$schema)!==1)throw new RuntimeException('Unsafe fixture cleanup target.');
    $admin->exec('DROP DATABASE IF EXISTS `'.$schema.'`');
}
