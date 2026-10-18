<?php
declare(strict_types=1);
// This fixture creates only a validated, randomly named schema on loopback.
// It exercises the native resource/item commands under the actual domain lock.
if(getenv('XAR_TEST_MYSQL_ALLOW_LOCAL')!=='1'){echo "MySQL device test skipped: explicit local opt-in required.\n";exit(0);}
$dsn=(string)getenv('XAR_TEST_MYSQL_DSN');
if(preg_match('/^mysql:host=127\.0\.0\.1;port=([1-9][0-9]{3,4});charset=utf8mb4$/D',$dsn,$port)!==1||(int)$port[1]>65535)throw new RuntimeException('Explicit loopback DSN required.');
require_once __DIR__.'/../api/v1/domains.php';
require_once __DIR__.'/../api/v1/online.php';
require_once __DIR__.'/../api/v1/dynamic-items.php';
function sendError(int $status,string $message,string $code=''):never{throw new RuntimeException($message,$status);}
function randomToken(int $bytes=32):string{return rtrim(strtr(base64_encode(random_bytes($bytes)),'+/','-_'),'=');}
function devicesSqlConnection(?string $schema=null):PDO{
 $db=new PDO((string)getenv('XAR_TEST_MYSQL_DSN'),(string)getenv('XAR_TEST_MYSQL_USER'),(string)getenv('XAR_TEST_MYSQL_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
 $db->exec("SET time_zone='+00:00', SESSION innodb_lock_wait_timeout=8");
 if($schema!==null){if(preg_match('/^regie_devices_audit_[a-f0-9]{16}$/D',$schema)!==1)throw new RuntimeException('Invalid disposable schema.');$db->exec('USE `'.$schema.'`');}return $db;
}
function deviceSqlCheck(bool $condition,string $label):void{if(!$condition)throw new RuntimeException($label);echo "PASS $label\n";}
$identity=['id'=>'fixture-user','display_name'=>'Utilisateur fictif','permanent_role'=>'player','effective_mode'=>'player'];
if(($argv[1]??'')==='worker'){
 $db=devicesSqlConnection((string)getenv('XAR_DEVICES_TEST_SCHEMA'));
 echo json_encode(['ready'=>(int)$db->query('SELECT CONNECTION_ID()')->fetchColumn()])."\n";flush();if(trim((string)fgets(STDIN))!=='GO')throw new RuntimeException('Missing worker barrier.');
 try{
  $db->beginTransaction();$clock=domainClockRecord($db,true);$records=applicationDomainRecords($db);$pending=[];
  if(isset($argv[3])){
   $command=json_decode($argv[3],true,512,JSON_THROW_ON_ERROR);
   $applied=['result'=>onlineItemCommand($db,$records,$pending,applicationDomainPayload($records,'table'),$identity,$command['command'],['requestId'=>$argv[2],'characterId'=>'fixture-user-character',...$command['arguments']],false),'effects'=>[]];
  }else $applied=onlineDeviceCommands($db,$records,$pending,$identity,['command'=>'launcher.fire','arguments'=>['requestId'=>$argv[2],'sceneId'=>'fixture-scene','characterId'=>'fixture-user-character','sourceTokenId'=>'fixture-user-token','deviceId'=>'fixture-launcher','targetTokenId'=>'fixture-maker-token','statId'=>'character-stat-dexterity','mana'=>5,'opposed'=>false]]);
  $revision=$pending===[]?$clock['globalRevision']:persistDomainChangesInTransaction($db,$identity,$clock,array_values($pending));$db->commit();
  echo json_encode(['result'=>$applied['result'],'effects'=>$applied['effects'],'revision'=>$revision],JSON_UNESCAPED_UNICODE)."\n";
 }catch(Throwable $error){if($db->inTransaction())$db->rollBack();echo json_encode(['error'=>$error->getMessage(),'status'=>$error->getCode()])."\n";}exit(0);
}
function startDeviceSqlWorker(string $schema,string $request,?array $command=null):array{
 $environment=getenv();$environment['XAR_DEVICES_TEST_SCHEMA']=$schema;
 $process=proc_open([PHP_BINARY,__FILE__,'worker',$request,...($command!==null?[json_encode($command,JSON_THROW_ON_ERROR)]:[])],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__,$environment);if(!is_resource($process))throw new RuntimeException('Worker unavailable.');
 stream_set_timeout($pipes[1],12);$ready=json_decode((string)fgets($pipes[1]),true);if(!is_int($ready['ready']??null)){proc_terminate($process);throw new RuntimeException('Worker not connected: '.stream_get_contents($pipes[2]));}
 return [$process,$pipes];
}
function finishDeviceSqlWorker(array $worker):array{[$process,$pipes]=$worker;$result=json_decode((string)fgets($pipes[1]),true);$error=stream_get_contents($pipes[2]);foreach($pipes as $pipe)fclose($pipe);$exit=proc_close($process);if($exit!==0||!is_array($result)||$error!=='')throw new RuntimeException('Worker failed: '.$error);return $result;}
$schema='regie_devices_audit_'.bin2hex(random_bytes(8));$admin=devicesSqlConnection();$workers=[];
try{
 $admin->exec('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4');$db=devicesSqlConnection($schema);
 $db->exec('CREATE TABLE application_domain_clock (singleton_id INT PRIMARY KEY,global_revision BIGINT,state_schema_version INT,domain_schema_version INT,legacy_revision BIGINT NULL,initialized_at DATETIME(3)) ENGINE=InnoDB');
 $db->exec('CREATE TABLE application_domains (domain_key VARCHAR(200) PRIMARY KEY,schema_version INT,revision BIGINT,payload JSON,updated_by_account_id VARCHAR(128) NULL,updated_at DATETIME(3)) ENGINE=InnoDB');
 $db->exec('CREATE TABLE application_domain_history (history_id BIGINT AUTO_INCREMENT PRIMARY KEY,domain_key VARCHAR(200),domain_revision BIGINT,global_revision BIGINT,operation VARCHAR(10),payload JSON,changed_by_account_id VARCHAR(128) NULL,created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3)) ENGINE=InnoDB');
 $db->exec('CREATE TABLE application_domain_changes (global_revision BIGINT,domain_key VARCHAR(200),domain_revision BIGINT,operation VARCHAR(10),PRIMARY KEY(global_revision,domain_key)) ENGINE=InnoDB');
 $db->exec('INSERT INTO application_domain_clock VALUES(1,1,21,1,NULL,UTC_TIMESTAMP(3))');
 $maker=['id'=>'fixture-maker-character','ownerPlayerId'=>'fixture-maker','name'=>'Créateur fictif','resources'=>['hp'=>50,'maxHp'=>50,'mana'=>30,'maxMana'=>100],'stats'=>['force'=>50,'intelligence'=>100,'dexterity'=>100],'fatigue'=>['current'=>0,'max'=>150],'abilities'=>[]];
 $user=[...$maker,'id'=>'fixture-user-character','ownerPlayerId'=>'fixture-user','name'=>'Utilisateur fictif','resources'=>['hp'=>50,'maxHp'=>50,'mana'=>9,'maxMana'=>100]];
 $asToken=static fn($character,$id,$x)=>['id'=>$id,'characterId'=>$character['id'],'followCharacter'=>true,'controllerPlayerId'=>$character['ownerPlayerId'],'name'=>$character['name'],'x'=>$x,'y'=>50,'layerId'=>'ground','hidden'=>false,...$character['resources']];
 $ability=['id'=>'fixture-install','name'=>'Lanceur de recette','effect'=>'device','formula'=>'0','manaCost'=>0,'device'=>dsDefinition(['kind'=>'launcher','ammoTemplateId'=>'fixture-bolt'])];
 $store=dsEmpty();$store['devices'][]=['id'=>'fixture-launcher','revision'=>1,'status'=>'active','creatorCharacterId'=>$maker['id'],'controllerAccountId'=>$maker['ownerPlayerId'],'sourceTokenId'=>'fixture-maker-token','abilityId'=>$ability['id'],'ability'=>$ability,'name'=>$ability['name'],'sceneId'=>'fixture-scene','layerId'=>'ground','x'=>50,'y'=>50,'definition'=>$ability['device']];
 $map=['naturalWidth'=>1000,'naturalHeight'=>1000,'gridSize'=>50,'activeLayerId'=>'ground','tokens'=>[$asToken($maker,'fixture-maker-token',1),$asToken($user,'fixture-user-token',55)]];
 $items=['version'=>1,'templates'=>[['id'=>'fixture-bolt','kind'=>'object','name'=>'Carreau fictif','description'=>'','image'=>'']],'instances'=>[]];for($i=1;$i<=20;$i++)$items['instances'][]=['id'=>'fixture-bolt-'.$i,'templateId'=>'fixture-bolt','revision'=>1,'location'=>['kind'=>'inventory','characterId'=>$maker['id']]];
 $state=['schemaVersion'=>21,'session'=>['name'=>'Recette SQL isolée'],'activeSceneId'=>'fixture-scene','characters'=>[$maker,$user],'players'=>[],'scenes'=>[['id'=>'fixture-scene','name'=>'Recette SQL','combat'=>['map'=>$map,'initiative'=>['active'=>false,'round'=>1,'order'=>[],'currentIndex'=>0]]]],'map'=>$map,'initiative'=>['active'=>false,'round'=>1,'order'=>[],'currentIndex'=>0],'devices'=>$store,'items'=>$items,'actionTimers'=>[],'rolls'=>[]];
 foreach(legacyStateToDomains($state) as $key=>$payload)upsertApplicationDomain($db,$key,$payload,1,null);
 // Distinct competing requests cannot both spend the nine available mana.
 $db->beginTransaction();domainClockRecord($db,true);
 $workers=[startDeviceSqlWorker($schema,'device-sql-distinct-request-one'),startDeviceSqlWorker($schema,'device-sql-distinct-request-two')];foreach($workers as $worker){fwrite($worker[1][0],"GO\n");fflush($worker[1][0]);}
 usleep(200000);deviceSqlCheck((int)$db->query('SELECT COUNT(*) FROM application_domain_history')->fetchColumn()===0,'No competing command writes while the actual domain clock is held.');$db->commit();
 $results=array_map('finishDeviceSqlWorker',$workers);$workers=[];$success=array_values(array_filter($results,static fn($result)=>isset($result['result'])));$errors=array_values(array_filter($results,static fn($result)=>isset($result['error'])));
 deviceSqlCheck(count($success)===1&&count($errors)===1&&$errors[0]['status']===409,'Only one competing five-mana shot succeeds; the other is refused. '.json_encode($results));
 $records=applicationDomainRecords($db);deviceSqlCheck(applicationDomainPayload($records,'character:fixture-user-character')['resources']['mana']==4,'The authoritative personal reserve contains exactly four mana.');
 deviceSqlCheck(count(array_filter(applicationDomainPayload($records,'items')['instances'],static fn($item)=>$item['location']['kind']==='inventory'))===19,'Exactly one of the creator’s twenty distant bolts is consumed.');
 deviceSqlCheck($success[0]['effects'][0]['sourceCharacterId']===$user['id']&&$success[0]['effects'][0]['statId']==='character-stat-dexterity','The canonical firing effect belongs to the actual user and chosen statistic.');
 $history=$db->query("SELECT payload FROM application_domain_history WHERE domain_key='character:fixture-user-character' ORDER BY history_id LIMIT 1")->fetchColumn();deviceSqlCheck(json_decode($history,true)['resources']['mana']==9,'The history preimage preserves nine mana instead of the post-command balance.');
 // Restore this disposable fixture and race two identical persistent receipts.
 $user['resources']['mana']=10;upsertApplicationDomain($db,'character:fixture-user-character',$user,10,null);upsertApplicationDomain($db,'token:fixture-scene:fixture-user-token',$asToken($user,'fixture-user-token',55),10,null);upsertApplicationDomain($db,'items',$items,10,null);upsertApplicationDomain($db,'devices',$store,10,null);
 $db->beginTransaction();domainClockRecord($db,true);$workers=[startDeviceSqlWorker($schema,'device-sql-same-request-0001'),startDeviceSqlWorker($schema,'device-sql-same-request-0001')];foreach($workers as $worker){fwrite($worker[1][0],"GO\n");fflush($worker[1][0]);}$db->commit();$results=array_map('finishDeviceSqlWorker',$workers);$workers=[];
 deviceSqlCheck(count(array_filter($results,static fn($result)=>isset($result['result'])))===2&&count(array_filter($results,static fn($result)=>$result['result']['deduplicated']??false))===1,'Both identical calls succeed and one is a durable receipt replay.');
 $fresh=devicesSqlConnection($schema);$records=applicationDomainRecords($fresh);deviceSqlCheck(applicationDomainPayload($records,'character:fixture-user-character')['resources']['mana']==5&&count(array_filter(applicationDomainPayload($records,'items')['instances'],static fn($item)=>$item['location']['kind']==='inventory'))===19,'A new SQL connection observes one payment and one bolt after the concurrent replay.');
 // Exercise the same transaction clock for a passage into another scene.
 $destinationMap=[...$map,'tokens'=>[],'layers'=>['upper'=>['naturalWidth'=>2000,'naturalHeight'=>1200,'lights'=>[]]]];
 upsertApplicationDomain($db,'scene-index',['order'=>['fixture-scene','fixture-arrival']],10,null);
 upsertApplicationDomain($db,'scene:fixture-arrival',['metadata'=>['id'=>'fixture-arrival','name'=>'Arrivée SQL']],10,null);
 upsertApplicationDomain($db,'map:fixture-arrival',$destinationMap,10,null);
 $gmIdentity=[...$identity,'permanent_role'=>'gm','effective_mode'=>'gm'];
 $passageCommand=static function(string $command,array $args,array $actor)use($db):array {
   $db->beginTransaction();try{$clock=domainClockRecord($db,true);$records=applicationDomainRecords($db);$pending=[];
     $result=onlinePassageCommand($db,$records,$pending,$actor,$command,$args);
     if($pending)persistDomainChangesInTransaction($db,$actor,$clock,array_values($pending));$db->commit();return $result;
   }catch(Throwable $error){$db->rollBack();throw $error;}
 };
 $passageCommand('passage.create',['requestId'=>'sql-passage-create-000001','endpoints'=>[
   ['sceneId'=>'fixture-scene','id'=>'sql-departure','name'=>'Escalier SQL','kind'=>'stairs','layerId'=>'ground','x'=>56,'y'=>50],
   ['sceneId'=>'fixture-arrival','id'=>'sql-arrival','name'=>'Portail SQL','kind'=>'portal','layerId'=>'upper','x'=>70,'y'=>60],
 ]],$gmIdentity);
 $travelArgs=['requestId'=>'sql-passage-travel-000001','sceneId'=>'fixture-scene','passageId'=>'sql-departure','expectedRevision'=>1,'tokenId'=>'fixture-user-token','expectedX'=>55,'expectedY'=>50];
 $passageCommand('passage.use',$travelArgs,$identity);$again=$passageCommand('passage.use',$travelArgs,$identity);
 $records=applicationDomainRecords(devicesSqlConnection($schema));
 deviceSqlCheck(($again['deduplicated']??false)&&applicationDomainPayload($records,'token:fixture-scene:fixture-user-token')===[],'A persisted SQL passage removes the departure token once and accepts a receipt replay.');
 $arrived=applicationDomainPayload($records,'token:fixture-arrival:fixture-user-token');
 deviceSqlCheck(($arrived['layerId']??'')==='upper'&&$arrived['x']>65&&$arrived['x']<80&&applicationDomainPayload($records,'table')['activeSceneId']==='fixture-scene','A new SQL connection sees the linked arrival without a broadcast change.');
 deviceSqlCheck(applicationDomainPayload($records,'character:fixture-user-character')['resources']['mana']==5,'Travelling and replaying a passage leave resources intact.');
 // Equipment mutations compete under the same real database clock.
 $gear=applicationDomainPayload($records,'items');$emptyLoadout=['sets'=>[],'equippedItemIds'=>[],'activeSetId'=>''];
 $gear['equipmentLoadouts']=['fixture-user-character'=>$emptyLoadout];
 foreach(['sword','spear'] as $id){$profile=['version'=>1,'kind'=>'weapon','hands'=>1,'formula'=>'7','damageType'=>'physical','reach'=>'melee'];$gear['templates'][]=['id'=>'gear-'.$id,'name'=>'Arme fictive '.$id,'kind'=>'object','equipment'=>$profile];$gear['instances'][]=['id'=>'gear-'.$id,'templateId'=>'gear-'.$id,'revision'=>1,'location'=>['kind'=>'inventory','characterId'=>'fixture-user-character']];}
 upsertApplicationDomain($db,'items',normalizeItemStore($gear),20,null);
 $commands=array_map(static fn($id)=>['command'=>'item.equipment.equip','arguments'=>['itemIds'=>['gear-'.$id],'expectedLoadout'=>$emptyLoadout]],['sword','spear']);
 $db->beginTransaction();domainClockRecord($db,true);$workers=[startDeviceSqlWorker($schema,'equipment-race-distinct-01',$commands[0]),startDeviceSqlWorker($schema,'equipment-race-distinct-02',$commands[1])];foreach($workers as $worker){fwrite($worker[1][0],"GO\n");fflush($worker[1][0]);}$db->commit();$results=array_map('finishDeviceSqlWorker',$workers);$workers=[];
 deviceSqlCheck(count(array_filter($results,static fn($r)=>isset($r['result'])))===1&&count(array_filter($results,static fn($r)=>($r['status']??0)===409))===1,'Concurrent equipment changes never overwrite a newer loadout. '.json_encode($results));
 $records=applicationDomainRecords(devicesSqlConnection($schema));$held=applicationDomainPayload($records,'items');deviceSqlCheck(count($held['equipmentLoadouts']['fixture-user-character']['equippedItemIds'])===1&&count($held['instances'])===count($gear['instances'])&&array_values(array_filter($held['instances'],static fn($i)=>$i['templateId']==='fixture-bolt'))===array_values(array_filter($gear['instances'],static fn($i)=>$i['templateId']==='fixture-bolt')),'The winner is durable; equipment never duplicates or consumes unrelated ammunition.');
 $quick=['command'=>'item.quick.create','arguments'=>['name'=>'Corde fictive','quantity'=>2,'templateId'=>'sql-quick-model','itemIds'=>['sql-quick-1','sql-quick-2'],'description'=>'Recette uniquement']];
 $db->beginTransaction();domainClockRecord($db,true);$workers=[startDeviceSqlWorker($schema,'equipment-quick-same-0001',$quick),startDeviceSqlWorker($schema,'equipment-quick-same-0001',$quick)];foreach($workers as $worker){fwrite($worker[1][0],"GO\n");fflush($worker[1][0]);}$db->commit();$results=array_map('finishDeviceSqlWorker',$workers);$workers=[];
 deviceSqlCheck(count(array_filter($results,static fn($r)=>isset($r['result'])))===2&&count(array_filter($results,static fn($r)=>$r['result']['deduplicated']??false))===1,'Concurrent quick-object retries share one persistent receipt. '.json_encode($results));
 $records=applicationDomainRecords(devicesSqlConnection($schema));$held=applicationDomainPayload($records,'items');deviceSqlCheck(count(array_filter($held['instances'],static fn($i)=>$i['templateId']==='sql-quick-model'))===2,'A fresh SQL connection sees exactly two requested objects, never four.');
 echo "Local MySQL device concurrency integration passed.\n";
}finally{
 foreach($workers as [$process,$pipes]){foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);if(is_resource($process)){proc_terminate($process);proc_close($process);}}
 if(isset($db)&&$db->inTransaction())$db->rollBack();if(preg_match('/^regie_devices_audit_[a-f0-9]{16}$/D',$schema)!==1)throw new RuntimeException('Unsafe fixture cleanup target.');$admin->exec('DROP DATABASE IF EXISTS `'.$schema.'`');
}
