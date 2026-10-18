<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);
require __DIR__.'/tactical-special-effects.php';
$checks=0;
function returnCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);$GLOBALS['checks']++;}
function returnCommand(MemoryConnection $db,string $command,array $args,bool $gm=true):TestResponse{return specialMechanics($db,$command,$args,$gm);}
function returnFixture(string $kind):array{
 $db=specialFixture();$c=$db->payload('character:character-player');
 $c['abilities']=[['id'=>'return-device','name'=>'Machine de recette','effect'=>'device','formula'=>'0','castingStatId'=>'','manaCost'=>0,'fatigueCost'=>0,'cooldownRounds'=>0,'device'=>dsDefinition(['kind'=>$kind,'ammoTemplateId'=>'return-bolt'])]];
 $db->put('character:character-player',$c);
 $db->put('scene-index',['order'=>['scene-one','forgotten-scene']]);
 $db->put('scene:forgotten-scene',['metadata'=>['id'=>'forgotten-scene','name'=>'Atelier oublié']]);
 $db->put('map:forgotten-scene',['activeLayerId'=>'upper','gridSize'=>50,'naturalWidth'=>1000,'naturalHeight'=>1000,'layers'=>['upper'=>['naturalWidth'=>1000,'naturalHeight'=>1000]]]);
 $db->put('initiative:forgotten-scene',['active'=>false,'round'=>1,'turnSerial'=>0,'order'=>[],'currentIndex'=>0]);
 $token=$db->payload('token:scene-one:token-player');$token['id']='forgotten-source';$token['layerId']='upper';
 $db->put('token:forgotten-scene:forgotten-source',$token);$db->put('token-index:forgotten-scene',['order'=>['forgotten-source']]);
 $r=returnCommand($db,'device.deploy',['sceneId'=>'forgotten-scene','layerId'=>'upper','characterId'=>'character-player','sourceTokenId'=>'forgotten-source','abilityId'=>'return-device','mark'=>['x'=>90,'y'=>50],'participantCharacterIds'=>['character-player']]);
 returnCheck($r->status===200,'Offscene native deploy: '.json_encode($r->body));
 return [$db,$r->body['deviceId']];
}
foreach(['launcher','battery','beam'] as $kind){
 [$db,$id]=returnFixture($kind);$store=$db->payload('devices');$index=dsDevice($store,$id,false);$store['devices'][$index]['mana']=37;$db->put('devices',$store);
 $device=$store['devices'][$index];$itemId=$device['itemId'];$before=$db->domains;$character=$db->payload('character:character-player');$count=count($db->payload('items')['instances']);
 returnCheck(returnCommand($db,'device.remove-token',['deviceId'=>$id,'expectedRevision'=>$device['revision']],false)->status===403,'Player cannot remove token');
 $r=returnCommand($db,'device.remove-token',['deviceId'=>$id,'expectedRevision'=>$device['revision']]);returnCheck($r->status===200,'Remove actual native token');
 $store=$db->payload('devices');$device=$store['devices'][dsDevice($store,$id,false)];returnCheck($device['status']==='detached'&&$device['mana']===37,'Detached machine conserves reserve');
 returnCheck(returnCommand($db,'device.deploy',['characterId'=>'character-player','sourceTokenId'=>'token-player','abilityId'=>'return-device','mark'=>['x'=>90,'y'=>50],'participantCharacterIds'=>['character-player']],false)->status===409,'Removed token still counts in machine limit');
 returnCheck(returnCommand($db,'device.pack',['deviceId'=>$id,'expectedRevision'=>$device['revision'],'returnToCreator'=>true],false)->status===403,'Player cannot invoke remote return');
 $args=['deviceId'=>$id,'expectedRevision'=>$device['revision'],'returnToCreator'=>true,'requestId'=>'return-replay-'.randomToken(20)];
 returnCheck(returnCommand($db,'device.pack',$args)->status===200,'Return removed machine on another scene');
 $items=$db->payload('items');$actual=$db->payload('devices')['devices'][dsDevice($db->payload('devices'),$id,false)];$item=$items['instances'][dsIndex($items['instances'],$itemId)];
 returnCheck($item['location']===['kind'=>'inventory','characterId'=>'character-player']&&$actual['status']==='inventory'&&$actual['mana']===37,'Same instance and reserve in creator inventory');
 returnCheck(count($items['instances'])===$count&&$actual['itemId']===$itemId&&$db->payload('character:character-player')===$character,'No extra item or cost');
 returnCheck(returnCommand($db,'device.pack',$args)->status===200&&$db->payload('items')===$items,'Receipt survives replay without another mutation');
 foreach($before as $key=>$domain)if(str_starts_with($key,'map:')||str_starts_with($key,'initiative:')||str_starts_with($key,'token:')||str_starts_with($key,'scene:'))returnCheck($db->payload($key)===$domain['payload'],'Unchanged scene domain '.$key);
 $transfer=['requestId'=>'return-transfer-'.randomToken(20),'itemId'=>$itemId,'expectedItemRevision'=>$item['revision'],'characterId'=>'character-player','targetCharacterId'=>'unit_token-monster'];
 returnCheck(runCommand($db,'item.transfer',$transfer,true,'account-gm')->status===403,'Even MJ cannot transfer a bound machine to another unit');
 returnCheck(runCommand($db,'item.spawn',['requestId'=>'return-copy-'.randomToken(20),'templateId'=>$item['templateId'],'itemId'=>'fake-machine','location'=>['kind'=>'inventory','characterId'=>'character-player']],true,'account-gm')->status===409,'Library cannot duplicate machine');
 $r=returnCommand($db,'device.place',['deviceId'=>$id,'expectedRevision'=>$actual['revision'],'sourceTokenId'=>'token-player','x'=>55,'y'=>50,'mark'=>['x'=>90,'y'=>50]],false);
 returnCheck($r->status===200,'Same machine can be placed again: '.json_encode($r->body));
}
[$db,$id]=returnFixture('beam');$store=$db->payload('devices');$index=dsDevice($store,$id,false);$store['devices'][$index]['status']='broken';$store['devices'][$index]['mana']=73;$db->put('devices',$store);
$d=$store['devices'][$index];returnCheck(returnCommand($db,'device.remove-token',['deviceId'=>$id,'expectedRevision'=>$d['revision']])->status===200,'Broken token can be removed');
$d=$db->payload('devices')['devices'][dsDevice($db->payload('devices'),$id,false)];
$c=$db->payload('character:character-player');$db->put('token-index:scene-one',['order'=>['token-monster']]);$db->put('token-index:forgotten-scene',['order'=>[]]);
returnCheck(returnCommand($db,'device.pack',['deviceId'=>$id,'expectedRevision'=>$d['revision'],'returnToCreator'=>true])->status===200,'Creator does not need a token to receive the machine');
$d=$db->payload('devices')['devices'][dsDevice($db->payload('devices'),$id,false)];returnCheck($d['status']==='broken'&&$d['mana']===73&&$db->payload('character:character-player')===$c,'Returning broken apparatus never repairs or refills it');
foreach(['battery','launcher','beam'] as $kind)foreach([false,true] as $cloned){
 $holder=$cloned?'unit_carrier-clone':'maker';$device=[...$d,'id'=>'returned-machine','itemId'=>'returned-item','creatorCharacterId'=>$holder,'definition'=>dsDefinition(['kind'=>$kind]),'ability'=>['name'=>'Private'],'controllerAccountId'=>'private-controller'];
 $characters=[['id'=>'maker','ownerPlayerId'=>$cloned?'former-controller':'owner']];
 $tokens=$cloned?[['id'=>'carrier-clone','characterId'=>null,'followCharacter'=>false,'controllerPlayerId'=>'owner']]:[];
 $items=['instances'=>[['id'=>'returned-item','location'=>['kind'=>'inventory','characterId'=>$holder]]]];
 $context=['items'=>$items,'tokens'=>$tokens,'visibleTokenIds'=>array_column($tokens,'id')];$store=['devices'=>[$device]];
 foreach([['sceneId'=>'current','layerId'=>'ground'],['sceneId'=>'forgotten-scene','layerId'=>'ground'],['sceneId'=>'forgotten-scene','layerId'=>'upper']] as $view)foreach([false,true] as $paused){
  $options=[...$context,...$view,'paused'=>$paused];$projected=publicDeviceStore($store,['id'=>'owner'],$characters,$options,static fn()=>false)['devices'];
  returnCheck(count($projected)===1&&$projected[0]['id']===$device['id']&&$projected[0]['status']==='broken'&&$projected[0]['mana']===73,'Returned broken apparatus remains available to effective owner across scenes, levels and pause');
  returnCheck(!isset($projected[0]['ability'],$projected[0]['controllerAccountId']),'Private device authoring fields remain stripped');
  foreach([['id'=>'other'],['id'=>'former-controller'],null] as $identity)returnCheck(publicDeviceStore($store,$identity,$characters,$options,static fn()=>true)['devices']===[],'Carried broken apparatus does not leak to another viewer on its former map');
 }
 $ground=['instances'=>[['id'=>'returned-item','location'=>['kind'=>'ground','sceneId'=>'forgotten-scene','layerId'=>'upper','x'=>50,'y'=>50]]]];
 $mapContext=[...$context,'items'=>$ground,'sceneId'=>'forgotten-scene','layerId'=>'upper'];
 returnCheck(count(publicDeviceStore($store,['id'=>'other'],$characters,$mapContext,static fn()=>true)['devices'])===1,'Broken apparatus on the ground retains ordinary map visibility');
 returnCheck(publicDeviceStore($store,['id'=>'owner'],$characters,[...$mapContext,'sceneId'=>'current','layerId'=>'ground'],static fn()=>true)['devices']===[],'Broken apparatus on another map is not remotely exposed while on the ground');
 returnCheck(publicDeviceStore($store,['id'=>'owner'],$characters,[...$mapContext,'paused'=>true],static fn()=>true)['devices']===[],'Paused map keeps ground apparatus hidden');
 returnCheck($store['devices']===[$device]&&$items['instances'][0]['location']['characterId']===$holder,'Projection never mutates device or inventory');
}
$anonymousStore=['devices'=>[['id'=>'unowned','itemId'=>'unowned-item','creatorCharacterId'=>'unowned-unit','status'=>'broken','definition'=>['kind'=>'launcher']]]];
returnCheck(publicDeviceStore($anonymousStore,null,[['id'=>'unowned-unit']],['items'=>['instances'=>[['id'=>'unowned-item','location'=>['kind'=>'inventory','characterId'=>'unowned-unit']]]]],static fn()=>true)['devices']===[],'Anonymous stream never receives a carried broken apparatus');
echo json_encode(['ok'=>true,'checks'=>$checks,'productionAccess'=>false]).PHP_EOL;
