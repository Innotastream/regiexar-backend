<?php
declare(strict_types=1);
require_once __DIR__.'/device-native-contract.php';
$deviceChecks=0;
function manaFixture():MemoryConnection {
    global $gmFixture;
    $db=new MemoryConnection(array_map(static fn(array $entry):array=>$entry['payload'],$gmFixture->domains));
    $db->mediaObjects=$gmFixture->mediaObjects;return $db;
}
function manaStation(MemoryConnection $db):array {
    global $stationId;
    $store=$db->payload('devices');return $store['devices'][dsDevice($store,$stationId,false)];
}
$db=manaFixture();$station=manaStation($db);
$args=['deviceId'=>$stationId,'expectedRevision'=>$station['revision'],'mana'=>37.5,'requestId'=>'mana-manual-primary-0001'];
$beforeItems=$db->payload('items');$beforeSheet=$db->payload('character:character-player');
requireDevice(deviceCommand($db,'device.mana.set',$args)->status===403,'Players cannot manually change even their own station mana');
requireDevice(manaStation($db)===$station&&$db->payload('items')===$beforeItems,'Rejected player command changes neither reserve nor inventory');
$changed=deviceCommand($db,'device.mana.set',$args,$gm);
requireDevice($changed->status===200&&$changed->body['mana']===37.5&&$changed->body['previousMana']===$station['mana'],'GM edits the existing reserve without a caster or source token');
requireDevice(manaStation($db)['revision']===$station['revision']+1,'Manual edit advances authoritative revision');
$replay=deviceCommand($db,'device.mana.set',$args,$gm);
requireDevice($replay->status===200&&!empty($replay->body['deduplicated'])&&manaStation($db)['revision']===$station['revision']+1,'Retries reuse the first receipt without another mutation');
requireDevice(deviceCommand($db,'device.mana.set',[...$args,'requestId'=>'mana-manual-stale-00001','mana'=>20],$gm)->status===409,'Stale menu cannot overwrite newer mana');
requireDevice($db->payload('items')===$beforeItems&&$db->payload('character:character-player')===$beforeSheet,'Manual reload consumes no personal resources and never alters or duplicates items');

$station=manaStation($db);
requireDevice(deviceCommand($db,'device.pack',['deviceId'=>$stationId,'sourceTokenId'=>'token-player','expectedRevision'=>$station['revision']])->status===200,'Charged station can be packed normally');
$station=manaStation($db);$token=$db->payload('token:scene-one:token-player');$db->put('token-index:scene-one',['order'=>['token-monster']]);unset($db->domains['token:scene-one:token-player']);
requireDevice(deviceCommand($db,'device.mana.set',['deviceId'=>$stationId,'expectedRevision'=>$station['revision'],'mana'=>73.25],$gm)->status===200,'GM reloads a packed object when its owner has no token on the map');
requireDevice(manaStation($db)['status']==='inventory'&&manaStation($db)['mana']===73.25,'Inventory recharge keeps the same stored device');
$station=manaStation($db);$db->put('token:scene-one:token-player',$token);$db->put('token-index:scene-one',['order'=>['token-player','token-monster']]);
requireDevice(deviceCommand($db,'device.place',['deviceId'=>$stationId,'sourceTokenId'=>'token-player','expectedRevision'=>$station['revision'],'x'=>$token['x']+1,'y'=>$token['y']])->status===200,'Reloaded object can be placed again');
requireDevice(manaStation($db)['mana']===73.25&&manaStation($db)['itemId']===$station['itemId']&&count($db->payload('items')['instances'])===count($beforeItems['instances']),'Placement preserves the edited reserve and unique physical item');
requireDevice($db->payload('character:character-player')===$beforeSheet,'Packing and replacement never charge the owner for the manual reserve');

foreach([-1,1000000001,'40',null,true,[]] as $invalid){
    $trial=manaFixture();$station=manaStation($trial);
    // Invalid values cannot reach persistence through the command implementation.
    $records=applicationDomainRecords($trial);$state=onlineDeviceState($trial,$records,[]);$failed=false;
    try{applicationApplyDeviceCommand($state,['id'=>'account-gm','role'=>'gm'],'device.mana.set',['requestId'=>'mana-invalid-value-0001','deviceId'=>$stationId,'expectedRevision'=>$station['revision'],'mana'=>$invalid],['now'=>'2026-11-17T00:00:00Z','id'=>static fn($prefix)=>$prefix.'-mana-test']);}catch(RuntimeException $e){$failed=$e->getCode()===400;}
    requireDevice($failed,'Invalid manual reserve rejected before persistence');
}
foreach([INF,NAN] as $nonfinite){$store=manaFixture()->payload('devices');$store['devices'][dsDevice($store,$stationId,false)]['mana']=$nonfinite;$rejected=false;try{dsStore($store);}catch(RuntimeException $e){$rejected=true;}requireDevice($rejected,'Nonfinite persisted reserve is rejected by store validation');}
foreach([0,1000000000] as $boundary){$trial=manaFixture();$station=manaStation($trial);requireDevice(deviceCommand($trial,'device.mana.set',['deviceId'=>$stationId,'expectedRevision'=>$station['revision'],'mana'=>$boundary],$gm)->status===200,'Inclusive mana boundary accepted');}
foreach(['spent','stopped','failed'] as $status){
    $trial=manaFixture();$store=$trial->payload('devices');$i=dsDevice($store,$stationId,false);$store['devices'][$i]['status']=$status;$trial->put('devices',$store);
    requireDevice(deviceCommand($trial,'device.mana.set',['deviceId'=>$stationId,'expectedRevision'=>$store['devices'][$i]['revision'],'mana'=>10],$gm)->status===409,'Ended device cannot be resurrected by reserve editing');
}
foreach(['consumed','destroyed'] as $location){
    $trial=manaFixture();$station=manaStation($trial);$items=$trial->payload('items');$i=dsIndex($items['instances'],$station['itemId']);$items['instances'][$i]['location']=['kind'=>$location,...($location==='consumed'?['characterId'=>'character-player']:[])];$trial->put('items',$items);
    requireDevice(deviceCommand($trial,'device.mana.set',['deviceId'=>$stationId,'expectedRevision'=>$station['revision'],'mana'=>10],$gm)->status===409,'Consumed or destroyed physical object cannot be recharged');
}
$trial=manaFixture();$station=manaStation($trial);$items=$trial->payload('items');$items['instances']=array_values(array_filter($items['instances'],static fn($item)=>$item['id']!==$station['itemId']));$trial->put('items',$items);
requireDevice(deviceCommand($trial,'device.mana.set',['deviceId'=>$stationId,'expectedRevision'=>$station['revision'],'mana'=>10],$gm)->status===409,'Missing linked item is not recreated by a mana edit');
requireDevice($trial->payload('items')===$items&&manaStation($trial)===$station,'Missing-item refusal cannot create a replacement or change reserve');
$trial=manaFixture();$station=manaStation($trial);$store=$trial->payload('devices');$i=dsDevice($store,$stationId,false);$store['devices'][$i]['definition']=dsDefinition(['kind'=>'launcher']);$trial->put('devices',$store);
requireDevice(deviceCommand($trial,'device.mana.set',['deviceId'=>$stationId,'expectedRevision'=>$station['revision'],'mana'=>17.75],$gm)->status===200&&manaStation($trial)['mana']===17.75,'Existing mana reserves remain generic across device kinds and preserve decimals');
$trial=manaFixture();$station=manaStation($trial);$store=$trial->payload('devices');unset($store['devices'][dsDevice($store,$stationId,false)]['mana']);$trial->put('devices',$store);
requireDevice(deviceCommand($trial,'device.mana.set',['deviceId'=>$stationId,'expectedRevision'=>$station['revision'],'mana'=>10],$gm)->status===409,'Command never invents a reserve on an object that has none');
$trial=manaFixture();$station=manaStation($trial);$store=$trial->payload('devices');$legacy=[...$station,'id'=>'unrelated-old-station','abilityId'=>'old-station-ability','status'=>'packed'];unset($legacy['itemId']);$store['devices'][]=$legacy;$trial->put('devices',$store);$items=$trial->payload('items');
requireDevice(deviceCommand($trial,'device.mana.set',['deviceId'=>$stationId,'expectedRevision'=>$station['revision'],'mana'=>10],$gm)->status===200,'Manual edit works alongside an unrelated unmigrated device');
requireDevice($trial->payload('items')===$items&&$trial->payload('devices')['devices'][dsDevice($trial->payload('devices'),$legacy['id'],false)]===$legacy,'Mana editing neither creates a legacy object nor changes an unrelated packed state');
echo json_encode(['ok'=>true,'checks'=>$deviceChecks,'productionAccess'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
