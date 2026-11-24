<?php
declare(strict_types=1);
if(getenv('XAR_TEST_MYSQL_ALLOW_LOCAL')!=='1'){echo "MySQL grouped attack receipt test skipped: explicit loopback opt-in required.\n";exit(0);}
$dsn=(string)getenv('XAR_TEST_MYSQL_DSN');
if(preg_match('/^mysql:host=127\\.0\\.0\\.1;port=([1-9][0-9]{3,4});charset=utf8mb4$/D',$dsn,$port)!==1||(int)$port[1]>65535)throw new RuntimeException('Explicit loopback DSN required.');
define('XAR_SPECIAL_FIXTURE_ONLY',true);require __DIR__.'/tactical-special-effects.php';require __DIR__.'/fixtures/grouped-attack-history.php';
function groupedSqlDb(?string $schema=null):PDO {
    $db=new PDO((string)getenv('XAR_TEST_MYSQL_DSN'),(string)(getenv('XAR_TEST_MYSQL_USER')?:'root'),(string)getenv('XAR_TEST_MYSQL_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);if($schema!==null)$db->exec('USE `'.$schema.'`');return $db;
}
function groupedSqlCommand(PDO $db,string $command,array $args,bool $gm=false,string $actor='account-player'):TestResponse {
    $GLOBALS['testIdentity']=['id'=>$actor,'display_name'=>'Recette groupes SQL','effective_mode'=>$gm?'gm':'player','permanent_role'=>$gm?'gm':'player'];$GLOBALS['testBody']=['command'=>$command,'payload'=>$args];try{commandOnlineState($db,[]);}catch(TestResponse $response){return $response;}
}
function groupedSqlCheck(bool $ok,string $label,array $details=[]):void {if(!$ok)throw new RuntimeException($label.' '.json_encode($details));echo 'PASS '.$label.PHP_EOL;}
function groupedSqlPayload(PDO $db,string $key):array {return applicationDomainPayload(applicationDomainRecords($db,[$key]),$key);}
function groupedSqlSnapshot(PDO $db):string {return hash('sha256',json_encode([$db->query('SELECT * FROM application_domain_clock')->fetchAll(),$db->query('SELECT * FROM application_domains ORDER BY domain_key')->fetchAll(),$db->query('SELECT * FROM application_direct_attack_receipts ORDER BY request_id')->fetchAll()],JSON_THROW_ON_ERROR));}
$sample=groupedReceiptSample();$history=groupedReceiptHistory($sample['receipts'],256);$fixture=groupedReceiptFixture();$activity=[...$fixture->payload('activity'),'attackReceipts'=>$history];$admin=groupedSqlDb();$schema='regie_groups_'.bin2hex(random_bytes(8));$db=null;$now=applicationResourceReceiptClock();
try {
    $admin->exec('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4');$db=groupedSqlDb($schema);
    $db->exec('CREATE TABLE schema_migrations (version INT PRIMARY KEY,name VARCHAR(200),checksum CHAR(64)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domain_clock (singleton_id INT PRIMARY KEY,global_revision BIGINT,state_schema_version INT,domain_schema_version INT,legacy_revision BIGINT NULL,initialized_at DATETIME(3)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domains (domain_key VARCHAR(200) PRIMARY KEY,schema_version INT,revision BIGINT,payload JSON,updated_by_account_id VARCHAR(128) NULL,updated_at DATETIME(3)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domain_history (history_id BIGINT AUTO_INCREMENT PRIMARY KEY,domain_key VARCHAR(200),domain_revision BIGINT,global_revision BIGINT,operation VARCHAR(10),payload JSON,changed_by_account_id VARCHAR(128) NULL,created_at DATETIME(3) DEFAULT CURRENT_TIMESTAMP(3)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE application_domain_changes (global_revision BIGINT,domain_key VARCHAR(200),domain_revision BIGINT,operation VARCHAR(10),PRIMARY KEY(global_revision,domain_key)) ENGINE=InnoDB');
    $db->exec('INSERT INTO application_domain_clock VALUES(1,1,'.XAR_SESSION_SCHEMA_VERSION.',1,NULL,UTC_TIMESTAMP(3))');
    foreach($fixture->domains as$key=>$record)upsertApplicationDomain($db,$key,$key==='activity'?$activity:$record['payload'],1,null);
    groupedSqlCheck(count($history)===1024,'256 true parent-child families reproduce 1024 terminal receipts');
    $newArgs=groupedReceiptArguments('group-ledger-new-area');$blocked=groupedSqlCommand($db,'token.attack',$newArgs);
    groupedSqlCheck($blocked->status===429&&($blocked->body['code']??'')==='attack_receipt_capacity_reached','Before migration, a new area is refused by the historical grouped limit',['status'=>$blocked->status,'body'=>$blocked->body]);
    migrateApplicationDirectAttackReceiptLedger($db);
    groupedSqlCheck(count(groupedSqlPayload($db,'activity')['attackReceipts'])===1024,'The direct-only migration leaves connected families intact');
    migrateApplicationGroupedAttackReceiptLedger($db);
    $observer=groupedSqlDb($schema);applicationDirectAttackLedgerEnabled($observer,true);applicationGroupedAttackLedgerEnabled($observer,true);
    groupedSqlCheck((int)$observer->query('SELECT COUNT(*) FROM application_direct_attack_receipts')->fetchColumn()===1024&&groupedSqlPayload($observer,'activity')['attackReceipts']===[],'Migration archives all 1024 outcomes without leaving parent or child behind');
    groupedSqlCheck((int)$observer->query('SELECT COUNT(DISTINCT group_id) FROM application_direct_attack_receipts')->fetchColumn()===256,'Each closed family has its own stable archive identity');
    $oldParent=$history[0];$snapshot=groupedSqlSnapshot($observer);$replay=groupedSqlCommand($observer,'token.attack',groupedReceiptArguments($oldParent['requestId']));
    groupedSqlCheck($replay->status===200&&($replay->body['deduplicated']??false)&&$replay->body['attack']['id']===$oldParent['attack']['id']&&groupedSqlSnapshot($observer)===$snapshot,'A historical parent replays on another connection without modifying state',['status'=>$replay->status,'body'=>$replay->body]);
    $wrong=groupedSqlCommand($observer,'token.attack',groupedReceiptArguments($oldParent['requestId']),false,'account-other');$altered=groupedSqlCommand($observer,'token.attack',[...groupedReceiptArguments($oldParent['requestId']),'opposed'=>true]);
    groupedSqlCheck($wrong->status===403&&$altered->status===409&&groupedSqlSnapshot($observer)===$snapshot,'Archived group replay still binds the original author and request content');
    $opened=groupedSqlCommand($db,'token.attack',$newArgs);
    groupedSqlCheck($opened->status===200&&($opened->body['pendingAreaGate']??false),'The same new area is admitted after group migration',['body'=>$opened->body]);
    $pending=groupedSqlPayload($observer,'activity');
    groupedSqlCheck(count($pending['pendingAttacks'])===1&&applicationReadDirectAttackReceipt($observer,$newArgs['requestId'],$now)===null,'An undecided area gate remains in the synchronized active domain');
    $resolveArgs=['requestId'=>'group-ledger-area-approval','attackId'=>$opened->body['attack']['id'],'confirmed'=>true,'decision'=>'approve','defenseTokenIds'=>[]];
    $beforeResources=groupedSqlPayload($observer,'character:character-player');$beforeTarget=groupedSqlPayload($observer,'token:scene-one:token-monster');
    $released=groupedSqlCommand($db,'token.attack.area.resolve',$resolveArgs,true,'account-gm');
    groupedSqlCheck($released->status===200&&count($released->body['areaAttacks']??[])>=2,'Actual area resolution creates the child outcomes after 1024 archived rows',['body'=>$released->body]);
    $children=array_column($released->body['areaAttacks'],'id');$parent=applicationReadDirectAttackReceipt($observer,$newArgs['requestId'],$now);
    groupedSqlCheck((int)$observer->query('SELECT COUNT(*) FROM application_direct_attack_receipts')->fetchColumn()>1024&&groupedSqlPayload($observer,'activity')['attackReceipts']===[],'More than 1024 terminal outcomes coexist in the private ledger while activity stays bounded');
    $snapshot=groupedSqlSnapshot($observer);$resolveReplay=groupedSqlCommand($observer,'token.attack.area.resolve',$resolveArgs,true,'account-gm');
    groupedSqlCheck($resolveReplay->status===200&&($resolveReplay->body['deduplicated']??false)&&array_column($resolveReplay->body['areaAttacks'],'id')===$children&&groupedSqlSnapshot($observer)===$snapshot,'Resolved area replay restores the exact children in order without double costs or damage',['body'=>$resolveReplay->body]);
    $activityNow=groupedSqlPayload($observer,'activity');$actions=array_values(array_filter($activityNow['combatActions']??[],static fn($action)=>in_array('attack:'.$opened->body['attack']['id'],$action['aliases']??[],true)));$actionId=$actions[0]['actionIds'][0]??'';
    groupedSqlCheck($actionId!=='','The archived group retains its causal undo action');
    $undo=groupedSqlCommand($db,'action.undo',['actionId'=>$actionId,'scope'=>'action','requestId'=>'group-ledger-undo-area'],true,'account-gm');
    $afterUndo=groupedSqlPayload($observer,'token:scene-one:token-monster');$character=groupedSqlPayload($observer,'character:character-player');
    groupedSqlCheck($undo->status===200&&(float)$afterUndo['hp']===(float)$beforeTarget['hp']&&(float)$character['resources']['mana']===10000.0,'Whole-group undo restores target HP and the single caster payment',['undo'=>$undo->body,'hp'=>$afterUndo['hp'],'mana'=>$character['resources']['mana']]);
    $snapshot=groupedSqlSnapshot($observer);$cancelled=groupedSqlCommand($observer,'token.attack',$newArgs);$cancelledGroup=groupedSqlCommand($observer,'token.attack.area.resolve',$resolveArgs,true,'account-gm');
    groupedSqlCheck($cancelled->status===200&&($cancelled->body['attack']['status']??'')==='cancelled'&&$cancelledGroup->status===200&&($cancelledGroup->body['deduplicated']??false)&&groupedSqlSnapshot($observer)===$snapshot,'Cancelled group stays cancelled on both entry point replays',['parentStatus'=>$cancelled->status,'parentAttackStatus'=>$cancelled->body['attack']['status']??null,'groupStatus'=>$cancelledGroup->status,'groupDedupe'=>$cancelledGroup->body['deduplicated']??null,'unchanged'=>groupedSqlSnapshot($observer)===$snapshot]);
    $stale=['attackReceipts'=>array_slice($history,0,8),'pendingAttacks'=>array_map(static fn($row)=>[...$row['attack'],'status'=>'pending'],array_slice($history,0,4))];
    $clean=applicationProtectPrivateDirectAttackReceipts($observer,$stale,[]);
    groupedSqlCheck($clean['attackReceipts']===[]&&$clean['pendingAttacks']===[],'Restoring an old snapshot cannot reintroduce any archived member or its pending decision');
    foreach(['token-monster','token-monster-two']as$id){$key='token:scene-one:'.$id;$payload=groupedSqlPayload($db,$key);$payload['stats']=[['id'=>'character-stat-agility','label'=>'Agilité','value'=>'40']];upsertApplicationDomain($db,$key,$payload,1,null);}
    $totalArgs=[...groupedReceiptArguments('group-ledger-total-opposition'),'opposed'=>true,'oppositionMode'=>'total'];$total=groupedSqlCommand($db,'token.attack',$totalArgs,true,'account-gm');
    $defenders=array_values(array_filter($total->body['areaAttacks']??[],static fn($attack)=>in_array($attack['targetTokenId'],['token-monster','token-monster-two'],true)));
    groupedSqlCheck($total->status===200&&count($defenders)===2&&count(array_filter($defenders,static fn($attack)=>!empty($attack['opposition']['rollId'])&&!empty($attack['opposition']['automatic'])))===2,'Two actual NPC automatic opposition rolls are executed in one grouped command',['status'=>$total->status]);
    foreach($defenders as$defender)if($defender['status']==='pending'){$settled=groupedSqlCommand($db,'token.attack.resolve',['attackId'=>$defender['id'],'decision'=>'approve'],true,'account-gm');groupedSqlCheck($settled->status===200,'Remarkable automatic opposition can be explicitly settled');}
    $snapshot=groupedSqlSnapshot($observer);$replayedTotal=groupedSqlCommand($observer,'token.attack',$totalArgs,true,'account-gm');$replayedDefenders=array_values(array_filter($replayedTotal->body['areaAttacks']??[],static fn($attack)=>in_array($attack['targetTokenId'],['token-monster','token-monster-two'],true)));
    groupedSqlCheck($replayedTotal->status===200&&($replayedTotal->body['deduplicated']??false)&&array_column($replayedDefenders,'id')===array_column($defenders,'id')&&array_map(static fn($attack)=>$attack['opposition']['rollId'],$replayedDefenders)===array_map(static fn($attack)=>$attack['opposition']['rollId'],$defenders)&&groupedSqlSnapshot($observer)===$snapshot,'Archived total-opposition replay preserves both child IDs, both original rolls and the exact state revision',['status'=>$replayedTotal->status]);
    $manualArgs=groupedReceiptArguments('group-ledger-manual-opposition');$manual=groupedSqlCommand($db,'token.attack',$manualArgs);
    groupedSqlCheck($manual->status===200&&($manual->body['pendingAreaGate']??false),'A manual opposition family is prepared through the player command');
    $manualApproval=groupedSqlCommand($db,'token.attack.area.resolve',['requestId'=>'group-ledger-manual-approval','attackId'=>$manual->body['attack']['id'],'confirmed'=>true,'decision'=>'approve','defenseTokenIds'=>['token-monster','token-monster-two']],true,'account-gm');
    $manualChildren=array_values(array_filter($manualApproval->body['areaAttacks']??[],static fn($attack)=>$attack['status']==='awaiting-opposition'));
    groupedSqlCheck($manualApproval->status===200&&count($manualChildren)===2,'Two selected NPC defenses remain active before the manual batch');
    $manualBatch=['requestId'=>'group-ledger-manual-batch','attackId'=>$manual->body['attack']['id'],'confirmed'=>true,'defenses'=>array_map(static fn($attack)=>['attackId'=>$attack['id'],'statId'=>'character-stat-agility'],$manualChildren)];
    $beforeManualResources=groupedSqlPayload($observer,'character:character-player');
    $manualResult=groupedSqlCommand($db,'token.attack.area.oppose',$manualBatch,true,'account-gm');
    $manualRolls=array_column($manualResult->body['attacks']??[],'opposition');
    groupedSqlCheck($manualResult->status===200&&count($manualRolls)===2&&count(array_unique(array_column($manualRolls,'rollId')))===2,'onlinePerformAreaOppositions executes the real manual two-NPC batch',['status'=>$manualResult->status,'body'=>$manualResult->status===200?null:$manualResult->body]);
    foreach($manualResult->body['attacks'] as$defender)if($defender['status']==='pending'){$settled=groupedSqlCommand($db,'token.attack.resolve',['attackId'=>$defender['id'],'decision'=>'approve'],true,'account-gm');groupedSqlCheck($settled->status===200,'Remarkable manual opposition can be explicitly settled');}
    groupedSqlCheck(applicationReadDirectAttackReceipt($observer,$manualArgs['requestId'],$now)!==null&&groupedSqlPayload($observer,'character:character-player')===$beforeManualResources,'The manual family is archived without charging the caster again');
    $snapshot=groupedSqlSnapshot($observer);$manualReplay=groupedSqlCommand($observer,'token.attack.area.oppose',$manualBatch,true,'account-gm');
    groupedSqlCheck($manualReplay->status===200&&($manualReplay->body['deduplicated']??false)&&array_column($manualReplay->body['attacks'],'id')===array_column($manualResult->body['attacks'],'id')&&array_column($manualReplay->body['attacks'],'opposition')===$manualRolls&&groupedSqlSnapshot($observer)===$snapshot,'Archived manual batch replay preserves child order, original dice, resources and exact revision',['status'=>$manualReplay->status,'body'=>$manualReplay->status===200?null:$manualReplay->body]);
    $active=groupedReceiptHistory($sample['receipts'],1,'active-family');$active[1]['attack']['status']='awaiting-opposition';
    $db->beginTransaction();$retained=applicationArchiveClosedAttackGroups($db,['attackReceipts'=>$active,'pendingAttacks'=>[$active[1]['attack']]],$now);$db->rollBack();
    groupedSqlCheck($retained['attackReceipts']===$active,'A pending child retains its whole connected family, including the resolved parent');
    $executing=groupedReceiptHistory($sample['receipts'],1,'executing-family');$executing[1]['attack']['complexExecutionId']='active-group-execution';
    $db->beginTransaction();$retained=applicationArchiveClosedAttackGroups($db,['attackReceipts'=>$executing,'pendingAttacks'=>[],'abilityExecutions'=>[['id'=>'active-group-execution','status'=>'active']]],$now);$db->rollBack();
    groupedSqlCheck($retained['attackReceipts']===$executing,'An active execution retains every connected member for later branches');
    $rollback=groupedReceiptHistory($sample['receipts'],1,'rollback-family');$count=(int)$observer->query('SELECT COUNT(*) FROM application_direct_attack_receipts')->fetchColumn();
    $db->beginTransaction();$removed=applicationArchiveClosedAttackGroups($db,['attackReceipts'=>$rollback,'pendingAttacks'=>[]],$now);$db->rollBack();
    groupedSqlCheck($removed['attackReceipts']===[]&&(int)$observer->query('SELECT COUNT(*) FROM application_direct_attack_receipts')->fetchColumn()===$count,'Rolling back an archive removes the complete family from the ledger');
    $expiry=groupedReceiptHistory($sample['receipts'],1,'expiry-family');$expiry[0]['expiresAt']=$now-1;foreach(array_keys($expiry)as$i)if($i!==0)$expiry[$i]['expiresAt']=$now+120000;
    $db->beginTransaction();$expiredState=applicationArchiveClosedAttackGroups($db,['attackReceipts'=>$expiry,'pendingAttacks'=>[]],$now);$db->commit();
    applicationPruneDirectAttackLedger($db,$now+1);
    groupedSqlCheck($expiredState['attackReceipts']===[]&&applicationReadDirectAttackReceipt($observer,$expiry[0]['requestId'],$now+1)!==null,'The parent remains replayable until the last member of its family expires');
    applicationPruneDirectAttackLedger($db,$now+120001);
    groupedSqlCheck(applicationReadDirectAttackReceipt($observer,$expiry[0]['requestId'],$now+120001)===null,'Terminal family expires as a unit after its last member');
    echo "Local MySQL grouped attack receipt integration passed.\n";
} finally {
    if($db?->inTransaction())$db->rollBack();
    if(preg_match('/^regie_groups_[a-f0-9]{16}$/D',$schema)!==1)throw new RuntimeException('Unsafe fixture cleanup.');$admin->exec('DROP DATABASE IF EXISTS `'.$schema.'`');
}
