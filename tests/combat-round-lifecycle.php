<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);require __DIR__.'/tactical-special-effects.php';
$specialChecks=0;
function requireGmIdentity(PDO $connection):array {
 $identity=$GLOBALS['testIdentity'];
 if(($identity['effective_mode']??'')!=='gm')sendError(403,'MJ requis');
 return $identity;
}
function roundsFixture():MemoryConnection {
 $db=specialFixture();$c=$db->payload('character:character-player');$base=['name'=>'Capacité fictive','effect'=>'movement','formula'=>'0','castingStatId'=>'','manaCost'=>2,'fatigueCost'=>1,'cooldownRounds'=>5,'restRecharge'=>'none'];
 $c['abilities']=[['id'=>'ordinary',...$base],['id'=>'short',...$base,'restRecharge'=>'short','usesPerRest'=>3],['id'=>'long',...$base,'restRecharge'=>'long','usesPerRest'=>1],['id'=>'stun',...$base,'manaCost'=>0,'fatigueCost'=>0,'cooldownRounds'=>0,'conditionRider'=>['version'=>1,'label'=>'Étourdi','turns'=>3,'resistance'=>false,'blocksActions'=>true,'blocksMovement'=>true]]];$db->put('character:character-player',$c);return $db;
}
function roundClockPatch(MemoryConnection $db,array $fields,bool $gm=true):TestResponse {
 $key='initiative:scene-one';$GLOBALS['testIdentity']=['id'=>'account-gm','display_name'=>'MJ fictif','effective_mode'=>$gm?'gm':'player','permanent_role'=>'gm'];
 $GLOBALS['testBody']=['stateSchemaVersion'=>XAR_SESSION_SCHEMA_VERSION,'domainSchemaVersion'=>XAR_DOMAIN_SCHEMA_VERSION,'changes'=>[['key'=>$key,'operation'=>'upsert','expectedRevision'=>$db->domains[$key]['revision'],'payload'=>[...$db->payload($key),...$fields]]]];
 try{patchApplicationDomains($db);}catch(TestResponse $r){return $r;}throw new RuntimeException('No domain response');
}
$db=roundsFixture();foreach(['ordinary','short','long'] as $id){$r=specialUse($db,$id,'token-player');specialCheck($r->status===200,'Native cast '.$id.': '.json_encode($r->body));}
$r=specialUse($db,'stun');specialCheck($r->status===200,'Native timed state');$c=$db->payload('character:character-player');$hp=$db->payload('token:scene-one:token-monster')['hp'];$timerBefore=$db->payload('activity')['actionTimers'];
$r=roundClockPatch($db,['active'=>false],false);specialCheck($r->status===403,'A player cannot end combat');
$r=roundClockPatch($db,['active'=>false]);specialCheck($r->status===200,'Native authoritative end: '.json_encode($r->body));$a=$db->payload('activity');
specialCheck(count($a['actionTimers'])===2,'Only two rest quota records survive');foreach(['short','long'] as $id){$old=array_values(array_filter($timerBefore,static fn($t)=>$t['abilityId']===$id))[0];$now=array_values(array_filter($a['actionTimers'],static fn($t)=>$t['abilityId']===$id))[0];specialCheck($now['id']===$old['id']&&$now['restUseCount']===$old['restUseCount']&&$now['restUseLimit']===$old['restUseLimit'],'Rest identity and count preserved');specialCheck($now['cooldown']===0&&$now['cooldownActive']===false&&$now['readyRound']===1,'Round cooldown expired');}
specialCheck($db->payload('devices')['timedConditions']===[],'Timed condition store cleared');specialCheck(!in_array('Étourdi',$db->payload('token:scene-one:token-monster')['conditions'],true),'Token badge removed');specialCheck($db->payload('character:character-player')['resources']===$c['resources']&&$db->payload('character:character-player')['fatigue']===$c['fatigue'],'Ending combat gives no resource or fatigue recovery');
specialCheck($db->payload('token:scene-one:token-monster')['hp']===$hp,'No damage on expiry');
foreach(['ordinary','ordinary','short','short'] as $id){$r=specialUse($db,$id,'token-player');specialCheck($r->status===200,'Immediate out-of-combat cast: '.json_encode($r->body));specialCheck($r->body['cast']['remainingRounds']===0&&$r->body['cast']['cooldownRounds']===0,'No rounds outside combat');}
specialCheck(specialUse($db,'short','token-player')->status===409,'Short quota still blocks at 3/3');specialCheck(specialUse($db,'long','token-player')->status===409,'Long quota still blocks');
$r=roundClockPatch($db,['active'=>true,'combatId'=>'rounds-next','round'=>1,'turnSerial'=>11]);specialCheck($r->status===200,'New combat');specialCheck(specialUse($db,'long','token-player')->status===409,'New combat grants no long rest');specialCheck(specialUse($db,'short','token-player')->status===409,'New combat grants no short rest');
// Scoped cleanup and shared-sheet state ownership use the same native pipeline.
$db=roundsFixture();$s=$db->payload('token:scene-one:token-player');$other=[...$s,'id'=>'other-token','layerId'=>'ground'];$db->put('token:scene-two:other-token',$other);
$store=dsEmpty();$store['timedConditions']=[['id'=>'one','sceneId'=>'scene-one','targetTokenId'=>'token-player','targetCharacterId'=>'character-player','label'=>'Influencé','remainingTurns'=>3,'introduced'=>true],['id'=>'two','sceneId'=>'scene-two','targetTokenId'=>'other-token','targetCharacterId'=>'character-player','label'=>'Influencé','remainingTurns'=>3,'introduced'=>false]];$db->put('devices',$store);$c=$db->payload('character:character-player');$c['conditions']=['Influencé','Doute'];$db->put('character:character-player',$c);$db->put('token:scene-one:token-player',synchronizeOnlineCharacterToken($s,$c));
$r=roundClockPatch($db,['active'=>false]);specialCheck($r->status===200,'Scoped end with another scene condition: '.json_encode($r->body));specialCheck(count($db->payload('devices')['timedConditions'])===1&&$db->payload('devices')['timedConditions'][0]['introduced']===true,'Condition ownership transferred to the other scene');specialCheck(in_array('Influencé',$db->payload('character:character-player')['conditions'],true)&&in_array('Doute',$db->payload('character:character-player')['conditions'],true),'Other scene and manual states preserved');
$pure=['actionTimers'=>[['id'=>'short','sceneId'=>'one','restRecharge'=>'short','restUseCount'=>2,'restUseLimit'=>3,'readyRound'=>99],['id'=>'elsewhere','sceneId'=>'two','readyRound'=>99]],'abilityExecutions'=>[]];$after=applicationFinishCombatRounds($pure,'one',['combatId'=>'c']);specialCheck($after['actionTimers'][0]['restUseCount']===2&&$after['actionTimers'][1]===$pure['actionTimers'][1],'Pure cleanup scoped and partial quota intact');
$raw=applicationAbilityCastingPlan(['id'=>'raw','castingStatId'=>'force','cooldownRounds'=>5,'reducedFailureCooldown'=>true],['id'=>'unit','hp'=>10,'maxHp'=>10,'mana'=>10,'maxMana'=>10,'followCharacter'=>false,'stats'=>[['id'=>'force','value'=>'0']]],'one',['active'=>false,'round'=>7],[]);
specialCheck($raw['cooldownRounds']===0&&!$raw['reducedFailureEnabled'],'Failed casts outside combat have no reduced recharge');

function roundMachineCommand(MemoryConnection $db,string $command,array $args=[],bool $gm=false):TestResponse {
 return runCommand($db,'mechanics.command',['command'=>$command,'arguments'=>['sceneId'=>'scene-one','characterId'=>'character-player','sourceTokenId'=>'token-player','requestId'=>'machine-rounds-'.randomToken(16),...$args]],$gm,$gm?'account-gm':'account-player');
}
$db=roundsFixture();$c=$db->payload('character:character-player');$c['resources']['mana']=200;$c['resources']['maxMana']=300;$c['abilities']=[];
foreach(['battery','launcher','dome','beam'] as $kind)$c['abilities'][]=['id'=>$kind,'name'=>'Machine fictive '.$kind,'effect'=>'device','formula'=>'0','castingStatId'=>'','manaCost'=>0,'fatigueCost'=>1,'cooldownRounds'=>5,'restRecharge'=>'short','usesPerRest'=>3,'device'=>dsDefinition(['kind'=>$kind,'ammoTemplateId'=>'round-bolt'])];
$db->put('character:character-player',$c);$ids=[];
foreach(['battery','launcher','dome','beam'] as $kind){$r=roundMachineCommand($db,'device.deploy',['abilityId'=>$kind,'mark'=>['x'=>90,'y'=>80]]);specialCheck($r->status===200,'Native machine deploy '.$kind.': '.json_encode($r->body));$ids[$kind]=$r->body['deviceId'];}
$r=roundMachineCommand($db,'battery.charge',['deviceId'=>$ids['battery'],'mana'=>10]);specialCheck($r->status===200,'Native station charged');
$machines=$db->payload('devices')['devices'];$stock=$db->payload('items')['instances'];$sheet=$db->payload('character:character-player');
specialCheck(roundClockPatch($db,['active'=>false])->status===200,'Native end with four machine types');
specialCheck($db->payload('devices')['devices']===$machines&&$db->payload('items')['instances']===$stock,'Native end leaves device identities, reserve, channel and preparation untouched');
foreach(['battery','launcher','dome','beam'] as $kind){$r=roundMachineCommand($db,'device.deploy',['abilityId'=>$kind,'mark'=>['x'=>90,'y'=>80]]);specialCheck($r->status===409&&str_contains($r->body['error'],'simultanée'),'Native default one machine: '.$kind);}
specialCheck($db->payload('character:character-player')===$sheet,'Rejected duplicate machines pay nothing');
foreach($db->payload('activity')['actionTimers'] as $timer)specialCheck($timer['cooldown']===0&&$timer['restUseCount']===1,'Device cooldown expired without a short rest');
$d=$db->payload('devices')['devices'][dsDevice($db->payload('devices'),$ids['battery'])];
specialCheck(roundMachineCommand($db,'device.pack',['deviceId'=>$d['id'],'expectedRevision'=>$d['revision']])->status===200,'Native station recovered outside combat');
specialCheck(roundMachineCommand($db,'device.deploy',['abilityId'=>'battery'])->status===409,'An inventory machine still takes its unique slot');
$d=$db->payload('devices')['devices'][dsDevice($db->payload('devices'),$ids['battery'],false)];$source=$db->payload('token:scene-one:token-player');
specialCheck(roundMachineCommand($db,'device.place',['deviceId'=>$d['id'],'expectedRevision'=>$d['revision'],'x'=>$source['x'],'y'=>$source['y']])->status===200,'Native existing station can be placed again');
specialCheck($db->payload('devices')['devices'][dsDevice($db->payload('devices'),$d['id'])]['mana']===20&&$db->payload('character:character-player')===$sheet,'Native reuse keeps charge and pays no new installation');
$c=$db->payload('character:character-player');$c['abilities'][]=['id'=>'double','name'=>'Double fictive','effect'=>'device','castingStatId'=>'','formula'=>'0','cooldownRounds'=>5,'device'=>dsDefinition(['kind'=>'battery','maximumInstances'=>2])];$db->put('character:character-player',$c);
for($n=0;$n<2;$n++)specialCheck(roundMachineCommand($db,'device.deploy',['abilityId'=>'double'])->status===200,'Explicit native multiple-machine definition works outside combat');
specialCheck(roundMachineCommand($db,'device.deploy',['abilityId'=>'double'])->status===409,'Native configured maximum is enforced');
$store=$db->payload('devices');$store['devices'][dsDevice($store,$ids['beam'])]['status']='broken';$db->put('devices',$store);
specialCheck(roundMachineCommand($db,'device.deploy',['abilityId'=>'beam','mark'=>['x'=>90,'y'=>80]])->status===409,'A broken native beam cannot be bypassed by a new installation');
specialCheck(roundMachineCommand($db,'device.repair',['deviceId'=>$ids['beam']],true)->status===200,'GM repairs the native beam');
$again=roundMachineCommand($db,'device.deploy',['abilityId'=>'beam','mark'=>['x'=>90,'y'=>80]]);specialCheck($again->status===200&&count(array_filter($db->payload('devices')['devices'],static fn($d)=>$d['abilityId']==='beam'&&$d['status']==='active'))===1,'Native repaired history permits the next paid installation without two active beams');
$full=domainsToApplicationState(applicationDomainRecords($db),0);$full['actionTimers'][]=['id'=>'projection-quota','sceneId'=>'scene-one','abilityId'=>'projection','ownerPlayerId'=>'account-player','visibility'=>'private','label'=>'Quota privé','ownerLabel'=>'Fictif','cooldown'=>0,'cooldownActive'=>false,'usedRound'=>1,'readyRound'=>1,'restRecharge'=>'short','restUseCount'=>3,'restUseLimit'=>3,'combatId'=>'c','combatUseCount'=>2,'combatUseLimit'=>2];
$view=publicPlayerState($full,['id'=>'account-player','effective_mode'=>'player','permanent_role'=>'player'],[]);$timer=array_values(array_filter($view['actionTimers'],static fn($t)=>$t['id']==='projection-quota'))[0];
specialCheck($timer['restUseCount']===3&&$timer['restUseLimit']===3&&$timer['combatUseCount']===2&&$timer['combatUseLimit']===2,'Native Player projection retains the exact private usage quotas');
$otherView=publicPlayerState($full,['id'=>'unrelated','effective_mode'=>'player','permanent_role'=>'player'],[]);specialCheck(!in_array('projection-quota',array_column($otherView['actionTimers'],'id'),true),'Unrelated accounts receive no private quota');

$adjust = static function (MemoryConnection $db, string $id, int $delta): TestResponse {
    return runCommand($db, 'token.resource.adjust', ['requestId'=>'initiative-hp-'.randomToken(16),
        'sceneId'=>'scene-one','tokenId'=>$id,'resource'=>'hp','delta'=>$delta], true, 'account-gm');
};
$db = roundsFixture();
$monsterKey = 'token:scene-one:token-monster';
$db->put($monsterKey, [...$db->payload($monsterKey), 'initiative'=>7, 'hp'=>10]);
$clock = ['active'=>true,'turnsStarted'=>true,'combatId'=>'initiative-death','turnSerial'=>11,'round'=>2,'currentIndex'=>1,
    'order'=>['token-player','token-monster'],
    'roundBaseOrder'=>['token-monster','token-player'],'delayedTurnIds'=>['token-monster'],'movementOverrides'=>['token-player'=>true],
    'previousRoundOrder'=>['order'=>['token-monster','token-player'],'delayedTurnIds'=>['token-monster']]];
$db->put('initiative:scene-one', $clock);
$db->put('initiative:scene-two', [...$clock, 'order'=>['unrelated-token']]);
specialCheck($adjust($db, 'token-monster', -10)->status === 200 && $db->payload('initiative:scene-one') === $clock,
    'An NPC at zero HP is KO and stays in initiative');
$result = $adjust($db, 'token-monster', -1);
$initiative = $db->payload('initiative:scene-one');
specialCheck($result->status === 200 && $initiative['active'] && $initiative['order'] === ['token-player'] && $initiative['currentIndex'] === 0
    && $initiative['turnSerial'] === 11 && $initiative['round'] === 2
    && $initiative['movementOverrides'] === [] && $initiative['delayedTurnIds'] === [] && $initiative['roundBaseOrder'] === ['token-player']
    && $initiative['previousRoundOrder'] === ['order'=>['token-player'],'delayedTurnIds'=>[]]
    && $db->payload($monsterKey)['initiative'] === null, 'Death prunes all roster snapshots and selects the next living turn without replaying it');
specialCheck($db->payload('initiative:scene-two')['order'] === ['unrelated-token'], 'Initiative changes stay on the affected scene');
$batch = runCommand($db, 'token.initiative.batch', ['requestId'=>'dead-initiative-'.randomToken(16),
    'sceneId'=>'scene-one','layerId'=>'ground','tokenIds'=>['token-monster']], true, 'account-gm');
specialCheck($batch->status === 409 && $batch->body['code'] === 'dead_initiative', 'Batch initiative explicitly refuses the dead unit');
specialCheck($adjust($db, 'token-monster', 50)->status === 200 && $db->payload('initiative:scene-one')['order'] === ['token-player'],
    'Restoring HP does not silently enroll the unit again');
$batch = runCommand($db, 'token.initiative.batch', ['requestId'=>'alive-initiative-'.randomToken(16),
    'sceneId'=>'scene-one','layerId'=>'ground','tokenIds'=>['token-monster']], true, 'account-gm');
specialCheck($batch->status === 200 && ($db->payload('initiative:scene-one')['order'][$db->payload('initiative:scene-one')['currentIndex']] ?? '') === 'token-player',
    'A revived unit can roll initiative without changing the active survivor');
specialCheck($adjust($db, 'token-player', -125)->status === 200 && in_array('token-player',$db->payload('initiative:scene-one')['order'],true),
    'An owned character at minus one quarter of maximum HP remains KO');
specialCheck($adjust($db, 'token-player', -1)->status === 200 && $db->payload('initiative:scene-one')['order'] === ['token-monster']
    && $db->payload('character:character-player')['resources']['hp'] === -26, 'Shared-sheet HP death uses the players’ real threshold');
specialCheck($adjust($db, 'token-monster', -80)->status === 200 && $db->payload('initiative:scene-one')['order'] === []
    && $db->payload('initiative:scene-one')['active'] === false && $db->payload('initiative:scene-one')['turnSerial'] === 11,
    'Losing the last participant ends combat without an extra round or turn');

$db = roundsFixture();
$db->put($monsterKey, [...$db->payload($monsterKey), 'initiative'=>21,'hp'=>1]);
$periodic = runCommand($db, 'combat.effect.periodic-hp', ['requestId'=>'initiative-dot-'.randomToken(16),
    'sceneId'=>'scene-one','layerId'=>'ground','tokenId'=>'token-monster','label'=>'Poison mortel',
    'direction'=>-1,'formula'=>'1d2+3','turns'=>1,'startNextTurn'=>true], true, 'account-gm');
specialCheck($periodic->status === 200, 'The GM sets a scheduled wound: ' . json_encode($periodic->body));
$advance = roundClockPatch($db, ['currentIndex'=>1,'turnSerial'=>11]);
specialCheck($advance->status === 200 && $db->payload($monsterKey)['hp'] >= -4 && $db->payload($monsterKey)['hp'] <= -3
    && $db->payload('initiative:scene-one')['order'] === ['token-player'] && $db->payload('initiative:scene-one')['turnSerial'] === 11,
    'A lethal turn-start effect prunes the roster in the same patch without repeating its roll');
$db = roundsFixture();
$creature = [...$db->payload($monsterKey), 'hp'=>1,'initiative'=>10];
$db->put($monsterKey, $creature);
$sheet = $db->payload('character:character-player');
$sheet['specialSuccessStock'] = 1;
$db->put('character:character-player', $sheet);
$strike = runCommand($db, 'token.attack', ['requestId'=>'initiative-strike-'.randomToken(16),'sceneId'=>'scene-one',
    'sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','attackKind'=>'weapon','attackId'=>'main',
    'statId'=>'character-stat-force','opposed'=>false,'useJoker'=>true], true, 'account-gm');
specialCheck($strike->status === 200 && $db->payload($monsterKey)['hp'] < 0
    && $db->payload('initiative:scene-one')['order'] === ['token-player'], 'A lethal attack updates initiative without requiring a separate GM edit');
$sheetKey = 'character:character-player';
$sourceKey = 'token:scene-one:token-player';
$db->put($sourceKey, [...$db->payload($sourceKey), 'initiative'=>12]);
$GLOBALS['testIdentity'] = ['id'=>'account-gm','display_name'=>'MJ fictif','effective_mode'=>'gm','permanent_role'=>'gm'];
$GLOBALS['testBody'] = ['stateSchemaVersion'=>XAR_SESSION_SCHEMA_VERSION,'domainSchemaVersion'=>XAR_DOMAIN_SCHEMA_VERSION,
    'changes'=>[['key'=>$sheetKey,'operation'=>'upsert','expectedRevision'=>$db->domains[$sheetKey]['revision'],
        'payload'=>[...$db->payload($sheetKey),'healthOverride'=>'dead']]]];
try { patchApplicationDomains($db); } catch(TestResponse $response) {
    specialCheck($response->status === 200 && $db->payload($sourceKey)['hp'] > 0
        && $db->payload($sourceKey)['initiative'] === null && $db->payload('initiative:scene-one')['order'] === [],
        'Manual mortality on a followed sheet removes its token despite positive HP');
}
fwrite(STDOUT,json_encode(['ok'=>true,'checks'=>$specialChecks],JSON_UNESCAPED_UNICODE)."\n");
