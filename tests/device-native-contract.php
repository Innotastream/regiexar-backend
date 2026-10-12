<?php
declare(strict_types=1);
require_once __DIR__.'/tactical-lifecycle.php';
$deviceChecks=0;
function requireDevice(bool $value,string $message):void {if(!$value)throw new RuntimeException($message);$GLOBALS['deviceChecks']++;}
function deviceCommand(MemoryConnection $db,string $command,array $args,array $identity=[]):TestResponse {
    return runCommand($db,'mechanics.command',['command'=>$command,'arguments'=>['requestId'=>'device-native-'.randomToken(16),'sceneId'=>'scene-one',...$args]],($identity['effective_mode']??'')==='gm',$identity['id']??'account-player');
}
$db=fixture();
$db->put('scene-index',['order'=>['scene-one']]);$db->put('scene:scene-one',['metadata'=>['id'=>'scene-one','name'=>'Recette des dispositifs']]);
$db->put('roster',['players'=>[['id'=>'account-player','name'=>'Joueur fictif']],'characterOrder'=>['character-player']]);
$db->put('map:scene-one',['gridSize'=>50,'naturalWidth'=>1000,'naturalHeight'=>1000,'activeLayerId'=>'ground']);
$db->put('initiative:scene-one',['active'=>true,'order'=>['token-player','token-monster'],'currentIndex'=>0,'round'=>1,'turnSerial'=>0,'combatId'=>'device-test-combat']);
$c=$db->payload('character:character-player');$c['resources']['mana']=100;$c['resources']['maxMana']=200;
$c['abilities']=[['id'=>'native-bomb','name'=>'Bombe de recette','effect'=>'device','formula'=>'0','castingStatId'=>'','manaCost'=>0,'fatigueCost'=>5,'cooldownRounds'=>5,'device'=>dsDefinition(['kind'=>'explosive'])],
    ['id'=>'native-battery','name'=>'Station de recette','effect'=>'device','formula'=>'0','castingStatId'=>'','manaCost'=>0,'fatigueCost'=>0,'cooldownRounds'=>0,'device'=>dsDefinition(['kind'=>'battery'])]];
$c['fatigue']=['current'=>0,'max'=>150];$db->put('character:character-player',$c);
$request=deviceCommand($db,'craft.request',['characterId'=>'character-player','sourceTokenId'=>'token-player','abilityId'=>'native-bomb','quantity'=>2]);
requireDevice($request->status===200,'Native craft request succeeds: '.json_encode($request->body));requireDevice($db->payload('character:character-player')['resources']['mana']==100,'Request does not spend before GM approval');
$requestId=$request->body['craftRequestId'];
$forbidden=deviceCommand($db,'craft.resolve',['craftRequestId'=>$requestId,'approve'=>true]);requireDevice($forbidden->status===403,'Player cannot approve craft');
$gm=['id'=>'account-gm','permanent_role'=>'gm','effective_mode'=>'gm','display_name'=>'MJ recette'];
$approved=deviceCommand($db,'craft.resolve',['craftRequestId'=>$requestId,'approve'=>true],$gm);
requireDevice($approved->status===200&&count($approved->body['itemIds'])===2,'Native approved batch has two unique items');
requireDevice($db->payload('character:character-player')['resources']['mana']==80&&$db->payload('character:character-player')['fatigue']['current']==0,'Only creation mana is charged');
requireDevice(count($db->payload('activity')['actionTimers']??[])===0,'Creation starts no cooldown');
$cacheDb=new MemoryConnection(array_map(static fn(array $entry): array=>$entry['payload'],$db->domains));
$nextBatch=deviceCommand($cacheDb,'craft.request',['characterId'=>'character-player','sourceTokenId'=>'token-player','abilityId'=>'native-bomb','quantity'=>1]);
$nextApproved=deviceCommand($cacheDb,'craft.resolve',['craftRequestId'=>$nextBatch->body['craftRequestId'],'approve'=>true],$gm);
$cachedItems=$cacheDb->payload('items');
requireDevice($nextApproved->status===200 && count($cachedItems['templates'])===1 && count($cachedItems['instances'])===3
    && count(array_unique(array_column($cachedItems['instances'],'id')))===3 && count(array_unique(array_column($cachedItems['instances'],'templateId')))===1,'Successive approved batches reuse one model and keep three unique instances');
requireDevice($cachedItems['templates'][0]['creatorCharacterId']==='character-player' && $cachedItems['templates'][0]['sourceAbilityId']==='native-bomb'
    && $cacheDb->payload('character:character-player')['resources']['mana']==70,'The stable model keeps its exact maker and ability with one creation payment per bomb');
$store=$db->payload('devices');$bomb=$store['devices'][0];
$launch=deviceCommand($db,'bomb.launch',['characterId'=>'character-player','sourceTokenId'=>'token-player','deviceId'=>$bomb['id'],'delay'=>2,'x'=>45,'y'=>50]);
requireDevice($launch->status===200,'Native bomb launch succeeds');
requireDevice($db->payload('character:character-player')['fatigue']['current']==5&&$db->payload('character:character-player')['resources']['mana']==80,'Launch charges fatigue without a second creation mana payment');
requireDevice(($db->payload('activity')['actionTimers'][0]['readyRound']??0)===6,'Launch cooldown is five rounds');
$station=deviceCommand($db,'device.deploy',['characterId'=>'character-player','sourceTokenId'=>'token-player','abilityId'=>'native-battery']);
requireDevice($station->status===200&&isset($station->body['deviceId']),'Native station deployment succeeds');
$charge=deviceCommand($db,'battery.charge',['deviceId'=>$station->body['deviceId'],'sourceTokenId'=>'token-player','mana'=>10]);
requireDevice($charge->status===200&&$charge->body['batteryMana']===20,'Native conversion is one personal mana for two battery mana');
requireDevice($db->payload('character:character-player')['resources']['mana']==70,'Station charging uses personal mana');
$luck=['rollCount'=>5,'rawTotal'=>252,'startedAt'=>'2026-10-12T00:00:00Z','updatedAt'=>'2026-10-12T00:00:00Z'];$db->put('luck',['characters'=>['character-player'=>$luck]]);
$records=applicationDomainRecords($db);$pending=[];$threshold=onlineCharacterLuckThreshold($db,$records,$pending,'character-player');
requireDevice($threshold===50,'Native Chance rounds the current average');$outcome=classifyOnlineD100Outcome(51,$threshold,0,-40);
requireDevice($outcome['success']===false&&$outcome['result']===51,'Native raw failure is unchanged by minus forty');
$pending=['luck'=>['payload'=>['characters'=>['character-player'=>[...$luck,'rollCount'=>6,'rawTotal'=>303]]]]];
requireDevice(onlineCharacterLuckThreshold($db,$records,$pending,'character-player')===51,'Pending authoritative statistics take precedence');
// Geometry and native dice: assertions use the actual result, with no RNG
// override in the product and no production configuration.
$beam=['id'=>'beam-native','sceneId'=>'scene-one','layerId'=>'ground','x'=>10,'y'=>50,'sourceTokenId'=>'caster','mark'=>['x'=>90,'y'=>50],'definition'=>dsDefinition(['kind'=>'beam'])];
$beamMap=['gridSize'=>50,'naturalWidth'=>1000,'naturalHeight'=>1000,'tokens'=>[
 ['id'=>'first','x'=>20,'y'=>50,'layerId'=>'ground','hp'=>100,'maxHp'=>100],
 ['id'=>'second','x'=>30,'y'=>50,'layerId'=>'ground','hp'=>0,'maxHp'=>100],
 ['id'=>'caster','x'=>40,'y'=>50,'layerId'=>'ground','hp'=>100,'maxHp'=>100],
 ['id'=>'outside','x'=>30,'y'=>60,'layerId'=>'ground','hp'=>100,'maxHp'=>100],
]];
requireDevice(onlineDeviceTargets($beam,$beamMap,true)===[['tokenId'=>'first','damagePercent'=>100],['tokenId'=>'second','damagePercent'=>90],['tokenId'=>'caster','damagePercent'=>80]],'Native beam is 100/90/80, includes KO units and a caster who entered its line');
requireDevice(count(onlineDeviceTargets(['layerId'=>'ground','x'=>10,'y'=>50,'definition'=>dsDefinition(['kind'=>'explosive'])],$beamMap,false))===2,'Native bomb radius includes its fourth cell, measured from its centre');
$future=dsEmpty();$future['version']=2;$refused=false;try{dsStore($future);}catch(RuntimeException $error){$refused=true;}
requireDevice($refused&&$future['version']===2,'A future store is refused without discarding its data');
$owner=$db->payload('character:character-player');$owner['stats']['dexterity']=60;$db->put('character:character-player',$owner);
$records=applicationDomainRecords($db);$state=onlineDeviceState($db,$records,[]);$source=$state['map']['tokens'][dsIndex($state['map']['tokens'],'token-player')];$source['x']=20;$source['y']=50;
$target=['id'=>'native-test-target','x'=>80,'y'=>50,'layerId'=>'ground'];
$dome=['id'=>'native-dome','revision'=>1,'status'=>'active','creatorCharacterId'=>'character-player','controllerAccountId'=>'account-player','sourceTokenId'=>'native-centre','abilityId'=>'native-echo','ability'=>['id'=>'native-echo'],'name'=>'Dôme natif','sceneId'=>'scene-one','layerId'=>'ground','x'=>50,'y'=>50,'definition'=>dsDefinition(['kind'=>'dome'])];
$stored=$db->payload('devices');$stored['devices'][]=$dome;$db->put('devices',$stored);
$db->put('token:scene-one:native-centre',['id'=>'native-centre','x'=>50,'y'=>50,'layerId'=>'ground','hidden'=>false,'hp'=>1,'maxHp'=>1]);
$db->put('token-index:scene-one',['order'=>['token-player','token-monster','token-monster-two','native-centre']]);
$records=applicationDomainRecords($db);$pending=[];$player=['id'=>'account-player','display_name'=>'Joueur fictif','effective_mode'=>'player','permanent_role'=>'player'];
$gate=onlineDeviceProjectile($db,$records,$pending,$player,$source,$target,true);
requireDevice(count($gate['checks'])===1,'An outside-to-outside projectile that crosses the dome has a native Dex check');
requireDevice($gate['percent']===($gate['checks'][0]['cast']['success']?50:0),'The native Dex result gives exactly half damage or cancels the projectile');
requireDevice(onlineDeviceProjectile($db,$records,$pending,$player,$source,$target,false)['checks']===[],'A native contact attack has no projectile gate');
requireDevice(onlineDeviceProjectile($db,$records,$pending,$player,$source,[...$target,'x'=>21],true)['checks']===[],'Native point-blank shots remain excluded');
$projection=publicPlayerState(onlineDeviceState($db,$records,$pending),$player,[]);
requireDevice(!isset($projection['devices']['receipts'][0]),'The player projection strips private device receipts');
requireDevice(onlineCharacterLuckThreshold($db,$records,['luck'=>['payload'=>['characters'=>['character-player'=>[...$luck,'rollCount'=>200,'rawTotal'=>10099]]]]],'character-player')===51,'Native displayed 50.50 is rounded to a live Chance threshold of 51');
echo json_encode(['ok'=>true,'checks'=>$deviceChecks],JSON_UNESCAPED_UNICODE).PHP_EOL;
