<?php
declare(strict_types=1);
require_once __DIR__.'/tactical-lifecycle.php';
$db=fixture();$db->put('scene:scene-one',['metadata'=>['id'=>'scene-one','name'=>'Marais 1']]);$db->put('scene:scene-two',['metadata'=>['id'=>'scene-two','name'=>'Marais 2']]);
$db->put('scene-index',['order'=>['scene-one','scene-two']]);$map=['gridSize'=>50,'naturalWidth'=>1000,'naturalHeight'=>1000,'activeLayerId'=>'ground'];$db->put('map:scene-one',$map);$db->put('map:scene-two',$map);
$db->put('initiative:scene-one',['active'=>false,'order'=>[]]);$token=$db->payload('token:scene-one:token-player');$token['x']=21;$token['y']=20;$db->put('token:scene-one:token-player',$token);
$command=['requestId'=>'passage-create-00000001','endpoints'=>[['sceneId'=>'scene-one','id'=>'entrance','kind'=>'stairs','name'=>'Départ','layerId'=>'ground','x'=>20,'y'=>20],['sceneId'=>'scene-two','id'=>'exit','kind'=>'portal','name'=>'Arrivée','layerId'=>'upper','x'=>70,'y'=>75]]];
$created=runCommand($db,'passage.create',$command,true);requireTactical($created->status===200,'Passage creation: '.json_encode($created->body));
$published=publicPlayerState(domainsToApplicationState(applicationDomainRecords($db),0),['id'=>'account-player'],[]);
requireTactical(count($published['map']['passages'])===1&&str_contains($published['map']['passages'][0]['destinationLabel'],'Marais 2'),'Projection exposes only a visible departure and a readable destination');
$args=['requestId'=>'passage-travel-00000001','sceneId'=>'scene-one','passageId'=>'entrance','expectedRevision'=>1,'tokenId'=>'token-player','expectedX'=>21,'expectedY'=>20];
$foreign=runCommand($db,'passage.use',$args,false,'foreign');requireTactical($foreign->status===403,'Foreign traversal is refused');
$travel=runCommand($db,'passage.use',$args);requireTactical($travel->status===200,'Native traversal: '.json_encode($travel->body));
requireTactical($db->payload('table')['activeSceneId']==='scene-one'&&$db->payload('map:scene-one')['activeLayerId']==='ground','Traversal never changes the scene broadcast by the GM');
$arrived=$db->payload('token:scene-two:token-player');requireTactical(($arrived['layerId']??'')==='upper'&&$arrived['x']>65&&$arrived['x']<80&&$db->payload('token:scene-one:token-player')===[],'Only the unit moves near the linked endpoint');
$replay=runCommand($db,'passage.use',$args);requireTactical($replay->status===200&&($replay->body['deduplicated']??false),'Traversal remains idempotent after transfer to another scene');
$removed=runCommand($db,'passage.remove',['requestId'=>'passage-remove-00000001','sceneId'=>'scene-one','passageId'=>'entrance','expectedRevision'=>1],true);
requireTactical($removed->status===200&&$db->payload('map:scene-two')['passages'][0]['destination']===null,'Removing an endpoint removes its backlink atomically');
$valid=$command['endpoints'][0];$valid['revision']=1;$valid['destination']=null;
foreach(['12',NAN,INF] as $invalid){try{normalizePassages([[...$valid,'x'=>$invalid]]);throw new LogicException('Invalid coordinate accepted');}catch(RuntimeException $error){requireTactical($error->getCode()===400,'Passage coordinates require finite JSON numbers');}}
requireTactical(normalizeApplicationMapLights([['id'=>'flame','x'=>20,'y'=>30,'name'=>'Flamme','icon'=>'flame']])[0]['icon']==='flame','The native light normalizer preserves the crackling flame model');
echo json_encode(['ok'=>true,'passageChecks'=>12]),"\n";
