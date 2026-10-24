<?php
declare(strict_types=1);
if(getenv('XAR_TEST_MYSQL_ALLOW_LOCAL')!=='1'){echo "MySQL stat-check test requires explicit local opt-in.\n";exit(0);}
$dsn=(string)getenv('XAR_TEST_MYSQL_DSN');
if(!preg_match('/^mysql:host=127\.0\.0\.1;port=([1-9][0-9]{3,4});charset=utf8mb4$/D',$dsn,$port)||(int)$port[1]>65535)throw new RuntimeException('Loopback DSN required.');
require_once __DIR__.'/../api/v1/domains.php';require_once __DIR__.'/../api/v1/online.php';
function randomToken(int $bytes=32):string{return bin2hex(random_bytes($bytes));}
function sendError(int $status,string $message,string $code=''):never{throw new RuntimeException($code?:$message,$status);}
function duelDb(?string $schema=null):PDO{
    $db=new PDO((string)getenv('XAR_TEST_MYSQL_DSN'),(string)getenv('XAR_TEST_MYSQL_USER'),(string)getenv('XAR_TEST_MYSQL_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $db->exec("SET time_zone='+00:00'");
    if($schema!==null){if(!preg_match('/^regie_duel_[a-f0-9]{16}$/D',$schema))throw new RuntimeException('Disposable schema only');$db->exec('USE `'.$schema.'`');}return $db;
}
function duelAssert(bool $condition,string $label):void{if(!$condition)throw new RuntimeException($label);echo "PASS ".$label."\n";}
function duelCommand(PDO $db,array $actor,array $args):array{
    $db->beginTransaction();
    try{$clock=domainClockRecord($db,true);$records=applicationDomainRecords($db);$pending=[];$table=applicationDomainPayload($records,'table');
        $result=($args['action']??'')==='cast'?onlineUseAbility($db,$records,$pending,$table,$actor,$args,$actor['effective_mode']==='gm')
            :onlineRespondStatCheck($db,$records,$pending,$table,$actor,$args,$actor['effective_mode']==='gm');
        if($pending!==[])persistDomainChangesInTransaction($db,$actor,$clock,array_values($pending));$db->commit();return $result;
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
}
$admin=duelDb();$schema='regie_duel_'.bin2hex(random_bytes(8));$db=null;
try{
    $admin->exec('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4');$db=duelDb($schema);
    $db->exec('CREATE TABLE application_domain_clock (singleton_id INT PRIMARY KEY,global_revision BIGINT,state_schema_version INT,domain_schema_version INT,legacy_revision BIGINT NULL,initialized_at DATETIME(3)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domains (domain_key VARCHAR(200) PRIMARY KEY,schema_version INT,revision BIGINT,payload JSON,updated_by_account_id VARCHAR(128) NULL,updated_at DATETIME(3)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domain_history (history_id BIGINT AUTO_INCREMENT PRIMARY KEY,domain_key VARCHAR(200),domain_revision BIGINT,global_revision BIGINT,operation VARCHAR(10),payload JSON,changed_by_account_id VARCHAR(128) NULL,created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domain_changes (global_revision BIGINT,domain_key VARCHAR(200),domain_revision BIGINT,operation VARCHAR(10),PRIMARY KEY(global_revision,domain_key)) ENGINE=InnoDB');
    $db->exec('INSERT INTO application_domain_clock VALUES(1,1,22,1,NULL,UTC_TIMESTAMP(3))');
    $owner=['id'=>'duel-owner','display_name'=>'Joueur fictif','permanent_role'=>'player','effective_mode'=>'player'];
    $other=[...$owner,'id'=>'duel-other'];$gm=[...$owner,'id'=>'duel-gm','permanent_role'=>'gm','effective_mode'=>'gm'];
    $social='character-stat-spiritSocial';
    $ability=['id'=>'duel-ability','name'=>'Duel social SQL','effect'=>'check','formula'=>'0','manaCost'=>0,'hpCost'=>0,'fatigueCost'=>5,'cooldownRounds'=>3,'castingStatId'=>$social,'reducedFailureCooldown'=>true,
        'statCheck'=>['version'=>1,'defenseStatId'=>$social,'oppositionMode'=>'optional','resultModifier'=>-25]];
    $source=['id'=>'duel-source','name'=>'Source fictive','followCharacter'=>false,'controllerPlayerId'=>$owner['id'],'hp'=>100,'maxHp'=>100,'mana'=>0,'maxMana'=>0,'fatigue'=>['current'=>0],
        'x'=>30,'y'=>50,'size'=>40,'layerId'=>'ground','stats'=>[['id'=>$social,'label'=>'Social','value'=>100]],'abilities'=>[$ability]];
    $target=[...$source,'id'=>'duel-target','name'=>'Cible fictive','controllerPlayerId'=>$other['id'],'x'=>40,'abilities'=>[]];
    foreach(['table'=>['activeSceneId'=>'duel-scene','tacticalSync'=>['paused'=>false]],'map:duel-scene'=>['activeLayerId'=>'ground','grid'=>['size'=>50],'naturalWidth'=>1600,'naturalHeight'=>900,'occlusion'=>['vision'=>['enabled'=>false]],'tokens'=>[$source,$target]],
        'token-index:duel-scene'=>['tokenIds'=>[$source['id'],$target['id']]],'token:duel-scene:duel-source'=>$source,'token:duel-scene:duel-target'=>$target,
        'initiative:duel-scene'=>['active'=>true,'round'=>1,'order'=>[$source['id'],$target['id']]],
        'activity'=>['actionTimers'=>[],'actionTimerTombstones'=>[],'mapPings'=>[],'shortcuts'=>[],'rolls'=>[],'playerActions'=>[],'resourceReceipts'=>[]]] as $key=>$value)upsertApplicationDomain($db,$key,$value,1,null);
    // A prevalidated ordinary cast avoids random outcome assumptions; costs are checked separately in Node and pure PHP.
    $cast=['success'=>true,'roll'=>['id'=>'initial-roll','total'=>35,'outcome'=>classifyOnlineD100Outcome(60,80,0,-25,true,true,false)],'outcome'=>classifyOnlineD100Outcome(60,80,0,-25,true,true,false)];
    $check=applicationCreateStatCheck('sql-check','duel-scene','ground',$source,$target,onlineStatCheckUnitKey($source),onlineStatCheckUnitKey($target),$ability,$cast,(int)floor(microtime(true)*1000));
    $activity=applicationDomainPayload(applicationDomainRecords($db),'activity');$activity['statChecks']=[$check];upsertApplicationDomain($db,'activity',$activity,2,null);
    $args=['requestId'=>'duel-choice-request-0001','checkId'=>$check['id'],'expectedRevision'=>1,'action'=>'choose-opposition','opposed'=>true];
    $chosen=duelCommand($db,$owner,$args);duelAssert($chosen['check']['status']==='awaiting-opposition','SQL stage transition persisted');
    $before=$db->query('SELECT global_revision FROM application_domain_clock')->fetchColumn();
    $replay=duelCommand(duelDb($schema),$owner,$args);
    duelAssert($replay['deduplicated']===true&&$db->query('SELECT global_revision FROM application_domain_clock')->fetchColumn()===$before,'New SQL connection replays the durable receipt without mutation');
    $status=0;try{duelCommand($db,$owner,['requestId'=>'duel-defense-forbidden-0001','checkId'=>$check['id'],'expectedRevision'=>2,'action'=>'defend']);}catch(Throwable $e){$status=$e->getCode();}
    duelAssert($status===403,'Target owner rights checked before rolling');
    $sourceAfter=applicationDomainPayload(applicationDomainRecords($db),'token:duel-scene:duel-source');
    $defense=duelCommand($db,$other,['requestId'=>'duel-defense-request-0001','checkId'=>$check['id'],'expectedRevision'=>2,'action'=>'defend']);
    duelAssert(in_array($defense['check']['status'],['completed','awaiting-validation'],true)&&count($defense['rolls'])===1,'Native PHP defense uses current Social and a single roll');
    $records=applicationDomainRecords($db);$activity=applicationDomainPayload($records,'activity');
    duelAssert(validApplicationStatChecks($activity['statChecks'])&&count($activity['statChecks'])===1,'Persisted SQL duel remains valid');
    duelAssert(applicationDomainPayload($records,'token:duel-scene:duel-source')===$sourceAfter,'Defense cannot repay, heal, damage or overwrite the source');
    if($defense['check']['status']==='awaiting-validation'){
        $validated=duelCommand($db,$gm,['requestId'=>'duel-validation-request-0001','checkId'=>$check['id'],'expectedRevision'=>3,'action'=>'validate','confirmed'=>true]);
        duelAssert($validated['check']['status']==='completed','Remarkable defense requires MJ confirmation');
    }
    $activity=applicationDomainPayload(applicationDomainRecords($db),'activity');
    $receipt=array_values(array_filter($activity['resourceReceipts'],static fn($value)=>$value['kind']==='ability-check'));
    duelAssert(count($receipt)>=2&&isset($receipt[0]['actionId']),'Stage receipts survive independent transactions');
    echo "Local MySQL stat check integration passed.\n";
}finally{if($db?->inTransaction())$db->rollBack();if(!preg_match('/^regie_duel_[a-f0-9]{16}$/D',$schema))throw new RuntimeException('Unsafe fixture cleanup');$admin->exec('DROP DATABASE IF EXISTS `'.$schema.'`');}
