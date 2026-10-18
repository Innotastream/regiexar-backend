<?php
declare(strict_types=1);
// Explicit loopback only; no existing application schema is ever selected.
if(getenv('XAR_TEST_MYSQL_ALLOW_LOCAL')!=='1'){echo "MySQL assistant test skipped: explicit local opt-in required.\n";exit(0);}
$dsn=(string)getenv('XAR_TEST_MYSQL_DSN');
if(preg_match('/^mysql:host=127\.0\.0\.1;port=([1-9][0-9]{3,4});charset=utf8mb4$/D',$dsn,$port)!==1||(int)$port[1]>65535)throw new RuntimeException('Explicit loopback DSN required.');
require_once __DIR__.'/../api/v1/domains.php';require_once __DIR__.'/../api/v1/online.php';require_once __DIR__.'/../api/v1/ability-assistant.php';
function sendError(int $status,string $message,string $code=''):never{throw new RuntimeException($message,$status);}
function randomToken(int $bytes=32):string{return rtrim(strtr(base64_encode(random_bytes($bytes)),'+/','-_'),'=');}
function assistantSqlConnection(?string $schema=null):PDO{
 $db=new PDO((string)getenv('XAR_TEST_MYSQL_DSN'),(string)getenv('XAR_TEST_MYSQL_USER'),(string)getenv('XAR_TEST_MYSQL_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
 $db->exec("SET time_zone='+00:00', SESSION innodb_lock_wait_timeout=8");
 if($schema!==null){if(preg_match('/^regie_assistant_audit_[a-f0-9]{16}$/D',$schema)!==1)throw new RuntimeException('Invalid disposable schema.');$db->exec('USE `'.$schema.'`');}return $db;
}
function assistantSqlCheck(bool $condition,string $label):void{if(!$condition)throw new RuntimeException($label);echo "PASS $label\n";}
$gm=['id'=>'gm-fixture','permanent_role'=>'gm','effective_mode'=>'gm'];
if(($argv[1]??'')==='worker'){
 $db=assistantSqlConnection((string)getenv('XAR_ASSISTANT_TEST_SCHEMA'));
 echo json_encode(['ready'=>(int)$db->query('SELECT CONNECTION_ID()')->fetchColumn()])."\n";flush();if(trim((string)fgets(STDIN))!=='GO')throw new RuntimeException('Missing worker barrier.');
 try{$result=executeAbilityAssistantObjectApplication($db,$gm,(string)$argv[2],(string)$argv[3]);echo json_encode(['result'=>$result],JSON_UNESCAPED_UNICODE)."\n";}
 catch(Throwable $error){echo json_encode(['error'=>$error->getMessage(),'status'=>$error->getCode()])."\n";}exit(0);
}
function assistantWorker(string $schema,array $proposal):array{
 $env=getenv();$env['XAR_ASSISTANT_TEST_SCHEMA']=$schema;
 $process=proc_open([PHP_BINARY,__FILE__,'worker',$proposal['conversation'],$proposal['message']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__,$env);if(!is_resource($process))throw new RuntimeException('Worker unavailable.');
 stream_set_timeout($pipes[1],15);$ready=json_decode((string)fgets($pipes[1]),true);if(!is_int($ready['ready']??null)){proc_terminate($process);throw new RuntimeException('Worker not ready.');}return [$process,$pipes];
}
function finishAssistantWorker(array $worker):array{[$p,$pipes]=$worker;$result=json_decode((string)fgets($pipes[1]),true);$error=stream_get_contents($pipes[2]);foreach($pipes as $pipe)fclose($pipe);$exit=proc_close($p);if($exit!==0||!is_array($result)||$error!=='')throw new RuntimeException('Worker failed: '.$error);return $result;}
function sqlAssistantProposal(PDO $db,array $gm,?string $templateId=null):array{
 $records=applicationDomainRecords($db,['character:hero','items','devices']);$character=applicationDomainPayload($records,'character:hero');
 $context=['character'=>['id'=>'hero'],'existingAbility'=>null,'linkedTokens'=>[],...abilityAssistantObjectContext($records,$character,$gm)];
 $plan=['version'=>1,'objects'=>[['ref'=>'ammo',...($templateId?['templateId'=>$templateId]:['template'=>['kind'=>'object','name'=>'Objet de recette']])]],'links'=>[['kind'=>'launcher-ammunition','objectRef'=>'ammo']]];
 $draft=normalizeAbilityAssistantDraft(['context_json'=>json_encode($context,JSON_THROW_ON_ERROR)],['name'=>'Compétence fictive','effect'=>'device','device'=>['kind'=>'launcher'],'objectPlan'=>$plan]);
 $conversation=randomToken(16);$message=randomToken(18);
 $db->prepare('INSERT INTO ability_assistant_conversations (id,owner_account_id,character_id,context_json) VALUES (?,?,?,?)')->execute([$conversation,$gm['id'],'hero',json_encode($context,JSON_THROW_ON_ERROR)]);
 $db->prepare('INSERT INTO ability_assistant_messages (id,conversation_id,status,assistant_status,draft_json) VALUES (?,?,?,?,?)')->execute([$message,$conversation,'succeeded','proposal',json_encode($draft,JSON_THROW_ON_ERROR)]);
 return ['conversation'=>$conversation,'message'=>$message];
}
function sqlAssistantSnapshot(PDO $db):string{return serialize([$db->query('SELECT * FROM application_domains ORDER BY domain_key')->fetchAll(),$db->query('SELECT * FROM application_domain_clock')->fetchAll(),$db->query('SELECT COUNT(*) FROM application_domain_history')->fetchColumn(),$db->query('SELECT COUNT(*) FROM application_domain_changes')->fetchColumn()]);}
function sqlAssistantRejected(callable $call,int $status,string $label):void{try{$call();}catch(Throwable $e){assistantSqlCheck((int)$e->getCode()===$status,$label);return;}throw new RuntimeException('Accepted: '.$label);}
$schema='regie_assistant_audit_'.bin2hex(random_bytes(8));$admin=assistantSqlConnection();$workers=[];
try{
 $admin->exec('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4');$db=assistantSqlConnection($schema);
 $db->exec('CREATE TABLE application_domain_clock (singleton_id INT PRIMARY KEY,global_revision BIGINT,state_schema_version INT,domain_schema_version INT,legacy_revision BIGINT NULL,initialized_at DATETIME(3)) ENGINE=InnoDB');
 $db->exec('CREATE TABLE application_domains (domain_key VARCHAR(200) PRIMARY KEY,schema_version INT,revision BIGINT,payload JSON,updated_by_account_id VARCHAR(128) NULL,updated_at DATETIME(3)) ENGINE=InnoDB');
 $db->exec('CREATE TABLE application_domain_history (history_id BIGINT AUTO_INCREMENT PRIMARY KEY,domain_key VARCHAR(200),domain_revision BIGINT,global_revision BIGINT,operation VARCHAR(10),payload JSON,changed_by_account_id VARCHAR(128) NULL,created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3)) ENGINE=InnoDB');
 $db->exec('CREATE TABLE application_domain_changes (global_revision BIGINT,domain_key VARCHAR(200),domain_revision BIGINT,operation VARCHAR(10),PRIMARY KEY(global_revision,domain_key)) ENGINE=InnoDB');
 $db->exec('CREATE TABLE accounts (id VARCHAR(128) PRIMARY KEY,username VARCHAR(128),display_name VARCHAR(128)) ENGINE=InnoDB');
 $db->exec('CREATE TABLE ability_assistant_conversations (id VARCHAR(22) PRIMARY KEY,owner_account_id VARCHAR(128),character_id VARCHAR(180),context_json JSON) ENGINE=InnoDB');
 $db->exec('CREATE TABLE ability_assistant_messages (id VARCHAR(24) PRIMARY KEY,conversation_id VARCHAR(22),status VARCHAR(30),assistant_status VARCHAR(30),draft_json JSON) ENGINE=InnoDB');
 $db->exec("INSERT INTO application_domain_clock VALUES(1,1,21,1,NULL,UTC_TIMESTAMP(3)); INSERT INTO accounts VALUES('gm-fixture','fixture','MJ fictif')");
 $character=['id'=>'hero','ownerPlayerId'=>'owner','name'=>'Fictif','abilities'=>[],'resources'=>['hp'=>31,'maxHp'=>40,'mana'=>20,'maxMana'=>30],'privateNotes'=>'PRIVATE'];
 $items=['version'=>1,'templates'=>[['id'=>'bolt','kind'=>'object','name'=>'Munition existante','image'=>'','description'=>'']],'instances'=>[['id'=>'clone-item','templateId'=>'bolt','revision'=>7,'location'=>['kind'=>'inventory','characterId'=>'unit-clone']]]];
 foreach(['character:hero'=>$character,'items'=>$items,'devices'=>['version'=>1,'devices'=>[]]] as $key=>$payload)upsertApplicationDomain($db,$key,$payload,1,null);
 $proposal=sqlAssistantProposal($db,$gm);$first=executeAbilityAssistantObjectApplication($db,$gm,$proposal['conversation'],$proposal['message']);
 $records=applicationDomainRecords($db);$persisted=applicationDomainPayload($records,'character:hero');$store=applicationDomainPayload($records,'items');
 assistantSqlCheck(count($persisted['abilities'])===1&&count($store['templates'])===2&&$persisted['abilities'][0]['device']['ammoTemplateId']===$store['templates'][1]['id'],'Ability and new model are committed with the same real identity');
 assistantSqlCheck($store['instances']===$items['instances']&&$persisted['resources']===$character['resources']&&$persisted['privateNotes']==='PRIVATE','No item is granted; clone stock, resources and private notes remain intact');
 $before=sqlAssistantSnapshot($db);$fresh=assistantSqlConnection($schema);$again=executeAbilityAssistantObjectApplication($fresh,$gm,$proposal['conversation'],$proposal['message']);
 assistantSqlCheck($again['deduplicated']&&$again['abilityId']===$first['abilityId']&&$again['templateIds']===$first['templateIds']&&sqlAssistantSnapshot($fresh)===$before,'Lost ACK replay from a fresh SQL connection causes no second write');
 $proposal=sqlAssistantProposal($db,$gm);$db->beginTransaction();domainClockRecord($db,true);$workers=[assistantWorker($schema,$proposal),assistantWorker($schema,$proposal)];foreach($workers as $w){fwrite($w[1][0],"GO\n");fflush($w[1][0]);}
 usleep(150000);assistantSqlCheck(count(applicationDomainPayload(applicationDomainRecords($db),'character:hero')['abilities'])===1,'Concurrent applications wait for the real domain clock');$db->commit();
 $results=array_map('finishAssistantWorker',$workers);$workers=[];
 assistantSqlCheck(count(array_filter($results,static fn($r)=>isset($r['result'])))===2&&count(array_filter($results,static fn($r)=>$r['result']['deduplicated']??false))===1,'Two simultaneous applications of one message yield exactly one write and one receipt replay');
 $records=applicationDomainRecords($db);assistantSqlCheck(count(applicationDomainPayload($records,'items')['templates'])===3&&count(applicationDomainPayload($records,'character:hero')['abilities'])===2,'Concurrent replay creates exactly one model and ability');
 $one=sqlAssistantProposal($db,$gm);$two=sqlAssistantProposal($db,$gm);$db->beginTransaction();domainClockRecord($db,true);$workers=[assistantWorker($schema,$one),assistantWorker($schema,$two)];foreach($workers as $w){fwrite($w[1][0],"GO\n");fflush($w[1][0]);}$db->commit();$results=array_map('finishAssistantWorker',$workers);$workers=[];
 assistantSqlCheck(count(array_filter($results,static fn($r)=>isset($r['result'])))===1&&count(array_filter($results,static fn($r)=>($r['status']??0)===409))===1,'Different proposals racing on the same sheet have one winner and one revision conflict');
 $records=applicationDomainRecords($db);assistantSqlCheck(count(applicationDomainPayload($records,'items')['templates'])===4&&count(applicationDomainPayload($records,'character:hero')['abilities'])===3,'Losing proposal leaves no orphan model');
 $proposal=sqlAssistantProposal($db,$gm,'bolt');$store=applicationDomainPayload(applicationDomainRecords($db),'items');$store['templates'][0]['name']='Changed concurrently';upsertApplicationDomain($db,'items',$store,9,null);$before=sqlAssistantSnapshot($db);
 sqlAssistantRejected(fn()=>executeAbilityAssistantObjectApplication($db,$gm,$proposal['conversation'],$proposal['message']),409,'Concurrent shared template edit is refused');assistantSqlCheck(sqlAssistantSnapshot($db)===$before,'Template conflict rolls back all pending ability changes');
 $proposal=sqlAssistantProposal($db,$gm);$before=sqlAssistantSnapshot($db);
 $db->exec("CREATE TRIGGER fixture_receipt_failure BEFORE UPDATE ON ability_assistant_messages FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture rollback after domain writes'");
 try{executeAbilityAssistantObjectApplication($db,$gm,$proposal['conversation'],$proposal['message']);throw new RuntimeException('Failure injection did not fire.');}catch(PDOException $expected){assistantSqlCheck(str_contains($expected->getMessage(),'fixture rollback'),'Injected receipt failure occurs after domain writes');}
 assistantSqlCheck(sqlAssistantSnapshot($db)===$before,'Receipt failure rolls back model, ability, history and global revision together');
 $db->exec('DROP TRIGGER fixture_receipt_failure');$retry=executeAbilityAssistantObjectApplication($db,$gm,$proposal['conversation'],$proposal['message']);assistantSqlCheck(!$retry['deduplicated'],'The same message is safely retryable after a real rollback');
 $proposal=sqlAssistantProposal($db,$gm);$before=sqlAssistantSnapshot($db);
 foreach([[...$gm,'effective_mode'=>'player'],[...$gm,'permanent_role'=>'player'],['id'=>'owner','permanent_role'=>'player','effective_mode'=>'player']] as $actor)sqlAssistantRejected(fn()=>executeAbilityAssistantObjectApplication($db,$actor,$proposal['conversation'],$proposal['message']),403,'Direct native call cannot bypass effective MJ rights');
 sqlAssistantRejected(fn()=>executeAbilityAssistantObjectApplication($db,[...$gm,'id'=>'other-gm'],$proposal['conversation'],$proposal['message']),403,'Another MJ cannot read or apply a private assistant conversation');
 assistantSqlCheck(sqlAssistantSnapshot($db)===$before,'All forbidden requests leave the database unchanged');
 $db->prepare('UPDATE ability_assistant_messages SET draft_json=? WHERE id=?')->execute([json_encode(['id'=>'forged','name'=>'Forged','effect'=>'device','device'=>['kind'=>'launcher'],'objectPlan'=>['version'=>1,'objects'=>[['ref'=>'ammo','template'=>['kind'=>'object','name'=>'Forged','quantity'=>999]]],'links'=>[['kind'=>'launcher-ammunition','objectRef'=>'ammo']]]],JSON_THROW_ON_ERROR),$proposal['message']]);
 sqlAssistantRejected(fn()=>executeAbilityAssistantObjectApplication($db,$gm,$proposal['conversation'],$proposal['message']),400,'Malformed stored plan cannot grant stock');assistantSqlCheck(sqlAssistantSnapshot($db)===$before,'Malformed proposal rolls back without a library or sheet write');
}finally{
 foreach($workers as [$process,$pipes]){proc_terminate($process);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);}
 if($db??null)unset($db);if($fresh??null)unset($fresh);$admin->exec('DROP DATABASE IF EXISTS `'.$schema.'`');
}
echo "Local MySQL assistant object plan integration passed.\n";
