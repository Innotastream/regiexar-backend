<?php
declare(strict_types=1);
require_once __DIR__.'/device-native-contract.php';
$db=fixture();$db->put('scene-index',['order'=>['scene-one']]);$db->put('scene:scene-one',['metadata'=>['id'=>'scene-one','name'=>'Clones de recette']]);
$db->put('initiative:scene-one',['active'=>false,'order'=>[]]);
$character=$db->payload('character:character-player');$character['resources']=['hp'=>100,'maxHp'=>100,'mana'=>100,'maxMana'=>200];$character['fatigue']=['current'=>0,'max'=>150];
$character['abilities']=[['id'=>'copy-launcher','name'=>'Lanceur autonome','effect'=>'device','formula'=>'0','castingStatId'=>'','manaCost'=>0,'fatigueCost'=>0,'cooldownRounds'=>0,'device'=>dsDefinition(['kind'=>'launcher','ammoTemplateId'=>'copy-ammo'])],
    ['id'=>'copy-dome','name'=>'Bouclier autonome','effect'=>'device','formula'=>'0','castingStatId'=>'','manaCost'=>0,'fatigueCost'=>10,'cooldownRounds'=>0,'device'=>dsDefinition(['kind'=>'dome'])]];
$db->put('character:character-player',$character);
$items=['version'=>1,'templates'=>[['id'=>'copy-ammo','name'=>'Carreau de copie','kind'=>'object']],'instances'=>[]];
for($i=0;$i<12;$i++)$items['instances'][]=['id'=>'copy-ammo-'.$i,'templateId'=>'copy-ammo','revision'=>1,'location'=>['kind'=>'inventory','characterId'=>'character-player']];
$db->put('items',$items);
$source=$db->payload('token:scene-one:token-player');$old=[...$source,'id'=>'old-independent','characterId'=>null,'followCharacter'=>false,'controllerPlayerId'=>'account-player'];$db->put('token:scene-one:old-independent',$old);
$clone=runCommand($db,'token.clone',['requestId'=>'unit-copy-request-000001','sceneId'=>'scene-one','tokenId'=>'token-player'],true);
requireDevice($clone->status===200,'Native clone creation: '.json_encode($clone->body));$token=$clone->body['token'];$unitId=unitIdForToken($token);
$newItems=$db->payload('items');$mine=array_values(array_filter($newItems['instances'],static fn($item)=>($item['location']['characterId']??'')===$unitId));
requireDevice(count($mine)===12&&count($newItems['instances'])===24&&count(array_unique(array_column($newItems['instances'],'id')))===24,'A new clone receives twelve distinct current-stock instances');
requireDevice(count(array_filter($newItems['instances'],static fn($item)=>($item['location']['characterId']??'')==='unit_old-independent'))===0,'Existing clones receive no retrospective stock');
$replay=runCommand($db,'token.clone',['requestId'=>'unit-copy-request-000001','sceneId'=>'scene-one','tokenId'=>'token-player'],true);
requireDevice($replay->status===200&&($replay->body['deduplicated']??false)&&count($db->payload('items')['instances'])===24,'Replay does not clone the stock a second time');
$dome=deviceCommand($db,'device.deploy',['characterId'=>$unitId,'sourceTokenId'=>$token['id'],'abilityId'=>'copy-dome']);
requireDevice($dome->status===200,'The clone launches its own device: '.json_encode($dome->body));
requireDevice($db->payload('token:scene-one:'.$token['id'])['fatigue']['current']==10&&$db->payload('character:character-player')['fatigue']['current']==0,'Only the clone pays casting fatigue');
$foreign=deviceCommand($db,'device.deploy',['characterId'=>$unitId,'sourceTokenId'=>$token['id'],'abilityId'=>'copy-launcher'],['id'=>'account-other','effective_mode'=>'player']);requireDevice($foreign->status===403,'Foreign players cannot use clone resources');
$dropped=runCommand($db,'item.drop',['requestId'=>'unit-drop-request-000001','characterId'=>$unitId,'tokenId'=>$token['id'],'sceneId'=>'scene-one','itemId'=>$mine[0]['id'],'expectedItemRevision'=>1]);
requireDevice($dropped->status===200&&$dropped->body['item']['location']['kind']==='ground','The clone manipulates its own inventory');
echo json_encode(['ok'=>true,'unitChecks'=>8]),"\n";
