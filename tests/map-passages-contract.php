<?php
declare(strict_types=1);
require_once __DIR__.'/tactical-lifecycle.php';
$db=fixture();$db->put('scene:scene-one',['metadata'=>['id'=>'scene-one','name'=>'Marais 1']]);$db->put('scene:scene-two',['metadata'=>['id'=>'scene-two','name'=>'Marais 2']]);
$db->put('scene-index',['order'=>['scene-one','scene-two']]);$map=['gridSize'=>50,'naturalWidth'=>1000,'naturalHeight'=>1000,'activeLayerId'=>'ground'];$db->put('map:scene-one',$map);$db->put('map:scene-two',$map);
$db->put('initiative:scene-one',['active'=>false,'order'=>[]]);$token=$db->payload('token:scene-one:token-player');$token['x']=32.5;$token['y']=20;$db->put('token:scene-one:token-player',$token);
$command=['requestId'=>'passage-create-00000001','endpoints'=>[['sceneId'=>'scene-one','id'=>'entrance','kind'=>'stairs','name'=>'Départ','layerId'=>'ground','x'=>20,'y'=>20],['sceneId'=>'scene-two','id'=>'exit','kind'=>'portal','name'=>'Arrivée','layerId'=>'upper','x'=>70,'y'=>75]]];
$created=runCommand($db,'passage.create',$command,true);requireTactical($created->status===200,'Passage creation: '.json_encode($created->body));
$published=publicPlayerState(domainsToApplicationState(applicationDomainRecords($db),0),['id'=>'account-player'],[]);
requireTactical(count($published['map']['passages'])===1&&str_contains($published['map']['passages'][0]['destinationLabel'],'Marais 2'),'Projection exposes only a visible departure and a readable destination');
$visibility=['requestId'=>'passage-visibility-00001','sceneId'=>'scene-one','layerId'=>'ground','hidden'=>true,'expectedPassages'=>[['id'=>'entrance','revision'=>1]]];
requireTactical(runCommand($db,'passage.visibility',$visibility)->status===403,'Only the MJ can hide the level passages');
requireTactical(runCommand($db,'passage.visibility',$visibility,true)->status===200,'The native dispatcher hides the passages of this level');
$published=publicPlayerState(domainsToApplicationState(applicationDomainRecords($db),0),['id'=>'account-player'],[]);requireTactical($published['map']['passages']===[],'Hidden passage markers are absent from the player projection');
requireTactical(runCommand($db,'passage.use',['requestId'=>'passage-hidden-use-00001','sceneId'=>'scene-one','passageId'=>'entrance','expectedRevision'=>2,'tokenId'=>'token-player','expectedX'=>32.5,'expectedY'=>20])->status===403,'Guessing a hidden passage identity cannot traverse it');
requireTactical(runCommand($db,'passage.visibility',[...$visibility,'requestId'=>'passage-visibility-00002','hidden'=>false,'expectedPassages'=>[['id'=>'entrance','revision'=>2]]],true)->status===200,'The MJ can reveal the same linked endpoints again');
$args=['requestId'=>'passage-travel-00000001','sceneId'=>'scene-one','passageId'=>'entrance','expectedRevision'=>3,'tokenId'=>'token-player','expectedX'=>32.5,'expectedY'=>20];
$foreign=runCommand($db,'passage.use',$args,false,'foreign');requireTactical($foreign->status===403,'Foreign traversal is refused');
$tooFar=$token;$tooFar['x']=32.501;$db->put('token:scene-one:token-player',$tooFar);
requireTactical(runCommand($db,'passage.use',[...$args,'requestId'=>'passage-too-far-000001','expectedX'=>32.501])->status===409,'The native dispatcher refuses traversal beyond 2.5 cells');
$db->put('token:scene-one:token-player',$token);
$activity=$db->payload('activity');$buff=['id'=>'travelling-buff','abilityId'=>'club','label'=>'Gourdin','holder'=>applicationEnhancementHolder($token),'sceneId'=>'scene-one','targetTokenId'=>'token-player',
    'mode'=>'replace','formula'=>'1d70+12','damageType'=>'magical','turns'=>5,'remainingTurns'=>3,'combatId'=>'source-combat','lastTurnSerial'=>900];
$activity['attackEnhancements']=[$buff,[...$buff,'id'=>'independent-buff','holder'=>'token:independent-clone']];$db->put('activity',$activity);
$arrivalInitiative=['active'=>true,'combatId'=>'arrival-combat','turnSerial'=>2,'order'=>['token-player'],'currentIndex'=>0];$db->put('initiative:scene-two',$arrivalInitiative);
$travel=runCommand($db,'passage.use',$args);requireTactical($travel->status===200,'Native traversal from 2.5 cells: '.json_encode($travel->body));
$effects=$db->payload('activity')['attackEnhancements'];
requireTactical($effects[0]['sceneId']==='scene-two'&&$effects[0]['remainingTurns']===3&&$effects[0]['combatId']==='arrival-combat'&&$effects[0]['lastTurnSerial']===2,'Native traversal persists the buff and rebases its clock without spending a turn');
requireTactical($effects[1]['sceneId']==='scene-one','Independent clones keep their own effects');
$effects=applicationAdvanceAttackEnhancements($effects,'scene-one',['active'=>true],['active'=>false]);
requireTactical(count($effects)===1&&$effects[0]['id']==='travelling-buff','Ending the departure combat leaves the travelling buff intact');
$effects=applicationAdvanceAttackEnhancements($effects,'scene-two',$arrivalInitiative,[...$arrivalInitiative,'turnSerial'=>3]);
requireTactical($effects[0]['remainingTurns']===2,'The next destination turn consumes exactly one remaining turn');
requireTactical($db->payload('table')['activeSceneId']==='scene-one'&&$db->payload('map:scene-one')['activeLayerId']==='ground','Traversal never changes the scene broadcast by the GM');
$arrived=$db->payload('token:scene-two:token-player');requireTactical(($arrived['layerId']??'')==='upper'&&$arrived['x']>65&&$arrived['x']<80&&$db->payload('token:scene-one:token-player')===[],'Only the unit moves near the linked endpoint');
$replay=runCommand($db,'passage.use',$args);requireTactical($replay->status===200&&($replay->body['deduplicated']??false),'Traversal remains idempotent after transfer to another scene');
$removed=runCommand($db,'passage.remove',['requestId'=>'passage-remove-00000001','sceneId'=>'scene-one','passageId'=>'entrance','expectedRevision'=>3],true);
requireTactical($removed->status===200&&$db->payload('map:scene-two')['passages'][0]['destination']===null,'Removing an endpoint removes its backlink atomically');
$valid=$command['endpoints'][0];$valid['revision']=1;$valid['destination']=null;
foreach(['12',NAN,INF] as $invalid){try{normalizePassages([[...$valid,'x'=>$invalid]]);throw new LogicException('Invalid coordinate accepted');}catch(RuntimeException $error){requireTactical($error->getCode()===400,'Passage coordinates require finite JSON numbers');}}
requireTactical(normalizeApplicationMapLights([['id'=>'flame','x'=>20,'y'=>30,'name'=>'Flamme','icon'=>'flame']])[0]['icon']==='flame','The native light normalizer preserves the crackling flame model');
$spacingMap=['activeLayerId'=>'ground','naturalWidth'=>1000,'naturalHeight'=>1000,'gridSize'=>50];
$reachEntry=['layerId'=>'ground','x'=>20,'y'=>20];$rangeChecks=1;
foreach([[32.5,20,true],[20,32.5,true],[30,27.5,true],[32.501,20,false],[30.001,27.5,false]] as [$x,$y,$expected]){
    requireTactical(passageWithinReach(['layerId'=>'ground','x'=>$x,'y'=>$y],$reachEntry,$spacingMap)===$expected,'Passage reach respects the 2.5-cell boundary on axes and diagonals');$rangeChecks++;
}
$reachMap=[...$spacingMap,'naturalWidth'=>100,'naturalHeight'=>100,'gridSize'=>5,'layers'=>['upper'=>['naturalWidth'=>1000,'naturalHeight'=>2000,'gridSize'=>50]]];
foreach([28,80,140] as $size){
    requireTactical(passageWithinReach(['layerId'=>'upper','x'=>20,'y'=>26.25,'size'=>$size],[...$reachEntry,'layerId'=>'upper'],$reachMap),'Passage reach uses level dimensions without changing with portrait size');$rangeChecks++;
    requireTactical(!passageWithinReach(['layerId'=>'upper','x'=>20,'y'=>26.251,'size'=>$size],[...$reachEntry,'layerId'=>'upper'],$reachMap),'Passage reach remains bounded with a large portrait');$rangeChecks++;
}
requireTactical(!passageWithinReach(['layerId'=>'upper','x'=>20,'y'=>20],$reachEntry,$spacingMap),'A nearby passage on another level remains unreachable');$rangeChecks++;
$spacingToken=['id'=>'moving','size'=>40,'x'=>20,'y'=>50,'layerId'=>'ground'];
$spacingOther=['id'=>'other','size'=>40,'x'=>50,'y'=>50,'layerId'=>'ground'];
$spacingChecks=0;
foreach([[80,80],[80,40],[40,80],[140,60],[60,140]] as [$movingSize,$otherSize]){
    $moving=[...$spacingToken,'size'=>$movingSize];$other=[...$spacingOther,'size'=>$otherSize];$desired=['x'=>55,'y'=>50];
    requireTactical(separateOnlineTokenPosition($moving,$desired,$spacingMap,[$moving,$other])===$desired,'A one-cell offset preserves large, partially overlapping portraits');$spacingChecks++;
}
foreach([[40,40],[80,40]] as [$movingSize,$otherSize]){
    $moving=[...$spacingToken,'size'=>$movingSize];$other=[...$spacingOther,'size'=>$otherSize];$desired=['x'=>53,'y'=>50];
    requireTactical(separateOnlineTokenPosition($moving,$desired,$spacingMap,[$moving,$other])===$desired,'Partial overlap is not an automatic movement');$spacingChecks++;
}
foreach([[40,80],[80,40],[40,40]] as [$movingSize,$otherSize]){
    $moving=[...$spacingToken,'size'=>$movingSize];$other=[...$spacingOther,'size'=>$otherSize];$desired=['x'=>$movingSize===$otherSize?50:51,'y'=>50];
    $separated=separateOnlineTokenPosition($moving,$desired,$spacingMap,[$moving,$other]);
    requireTactical(($separated['separated']??false)&&hypot(($separated['x']-$other['x'])*10,($separated['y']-$other['y'])*10)>abs($movingSize-$otherSize)/2,'Complete cover is still separated for either size order');$spacingChecks++;
}
$levelMap=[...$spacingMap,'naturalWidth'=>100,'naturalHeight'=>100,'layers'=>['ground'=>['naturalWidth'=>1000,'naturalHeight'=>2000]]];
foreach([['x'=>55,'y'=>50],['x'=>50,'y'=>52.5]] as $desired){
    $moving=[...$spacingToken,'size'=>80];
    requireTactical(separateOnlineTokenPosition($moving,$desired,$levelMap,[$moving,$spacingOther])===$desired,'Cover uses destination-level dimensions');$spacingChecks++;
}
$fogBytes=str_repeat("\0",(int)ceil(512*512/8));applicationSetMaskBit($fogBytes,256*512+256,true);
$fog=['version'=>1,'enabled'=>true,'width'=>512,'height'=>512,'mask'=>rtrim(strtr(base64_encode($fogBytes),'+/','-_'),'=')];
$moving=[...$spacingToken,'size'=>40];$other=[...$spacingOther,'size'=>80];$desired=['x'=>51,'y'=>50];
requireTactical(separateOnlineTokenPosition($moving,$desired,[...$spacingMap,'fog'=>$fog],[$moving,$other])===$desired,'A hidden token centre never pushes the moving unit even if its portrait exceeds the painted fog');$spacingChecks++;
requireTactical(separateOnlineTokenPosition($moving,$desired,$spacingMap,[$moving,[...$other,'hidden'=>true]])===$desired,'An explicitly hidden token cannot push another unit');$spacingChecks++;
$carriedLightChecks=0;
foreach(['one','two'] as $destinationScene)foreach([false,true] as $legacy){
    $light=['id'=>'carried-flame','name'=>'Flamme portée','x'=>21,'y'=>20,'visionDistance'=>9,'color'=>'#12ab34','icon'=>'flame','enabled'=>true,'portable'=>true,'visibleFromAfar'=>true,'iconHidden'=>true,'carrierTokenId'=>'unit'];
    $map=['activeLayerId'=>'ground','naturalWidth'=>1000,'naturalHeight'=>1000,'gridSize'=>50,'lights'=>[$light],'tokens'=>[['id'=>'unit','name'=>'Unité','x'=>21,'y'=>20,'size'=>40,'hp'=>100,'maxHp'=>100,'layerId'=>'ground','controllerPlayerId'=>'player']],
        'layers'=>['ground'=>['naturalWidth'=>1000,'naturalHeight'=>1000,'lights'=>[$light]],'upper'=>['naturalWidth'=>1000,'naturalHeight'=>1000,'lights'=>[]]]];
    $otherMap=$map;$otherMap['tokens']=[];$otherMap['lights']=[];$otherMap['layers']['ground']['lights']=[];
    if($legacy)unset($map['layers']);
    $state=['activeSceneId'=>'one','map'=>$map,'characters'=>[],'initiative'=>['active'=>false],'devices'=>['devices'=>[],'timedConditions'=>[]],'scenes'=>[['id'=>'one','name'=>'Départ','combat'=>['map'=>$map]],['id'=>'two','name'=>'Arrivée','combat'=>['map'=>$otherMap]]]];
    $created=applicationApplyPassageCommand($state,['id'=>'gm','role'=>'gm'],'passage.create',['endpoints'=>[['sceneId'=>'one','id'=>'a','kind'=>'walk','name'=>'Départ','layerId'=>'ground','x'=>20,'y'=>20],['sceneId'=>$destinationScene,'id'=>'b','kind'=>'portal','name'=>'Arrivée','layerId'=>'upper','x'=>70,'y'=>75]]]);
    $travel=applicationApplyPassageCommand($created['state'],['id'=>'player','role'=>'player'],'passage.use',['sceneId'=>'one','passageId'=>'a','expectedRevision'=>1,'tokenId'=>'unit','expectedX'=>21,'expectedY'=>20]);
    $arrivedMap=$destinationScene==='one'?$travel['state']['map']:$travel['state']['scenes'][1]['combat']['map'];
    $lights=applicationMapLightsForLayer($arrivedMap,'upper',$arrivedMap['tokens']);
    requireTactical(count($lights)===1&&$lights[0]['id']==='carried-flame'&&$lights[0]['carrierTokenId']==='unit'&&$lights[0]['color']==='#12ab34'&&$lights[0]['visionDistance']===9&&$lights[0]['visibleFromAfar']===true&&$lights[0]['iconHidden']===true,'Carried light identity and settings survive portals, including legacy maps');$carriedLightChecks++;
    requireTactical($travel['state']['activeSceneId']==='one'&&$travel['state']['map']['activeLayerId']==='ground','Carried light transfer does not change the broadcast');$carriedLightChecks++;
}
$exitSpacingChecks=0;
foreach(['adjacent','fog','flag','covered'] as $kind){
    $case=$created['state'];$case['map']['tokens'][0]['size']=80;$destinationMap=&passageMap($case,'two');
    $destinationMap['tokens'][]=['id'=>'neighbour','size'=>140,'x'=>$kind==='adjacent'?80:75,'y'=>75,'layerId'=>'upper','hidden'=>$kind==='flag'];
    if($kind==='fog'){
        $bytes=str_repeat("\0",(int)ceil(512*512/8));applicationSetMaskBit($bytes,383*512+383,true);
        $destinationMap['layers']['upper']['fog']=['version'=>1,'enabled'=>true,'width'=>512,'height'=>512,'mask'=>rtrim(strtr(base64_encode($bytes),'+/','-_'),'=')];
    }unset($destinationMap);
    $result=applicationApplyPassageCommand($case,['id'=>'player','role'=>'player'],'passage.use',['sceneId'=>'one','passageId'=>'a','expectedRevision'=>1,'tokenId'=>'unit','expectedX'=>21,'expectedY'=>20]);
    $arrived=$result['state']['scenes'][1]['combat']['map']['tokens'][1];
    $safeArrival=$kind==='covered'?hypot(($arrived['x']-75)*10,($arrived['y']-75)*10)>30
        :($kind==='fog'?!applicationFogCoversPoint($case['scenes'][1]['combat']['map']['layers']['upper']['fog'],$arrived['x'],$arrived['y'])
            :abs($arrived['x']-75)<0.00001&&abs($arrived['y']-75)<0.00001);
    requireTactical($safeArrival,'Passage exits preserve nearby portraits, separate complete covers and never land in painted fog');$exitSpacingChecks++;
}
$convoyChecks=0;
foreach(['scene-one','scene-two'] as $targetScene)foreach([false,true] as $edge){
    $convoy=fixture();$convoy->put('scene:scene-one',['metadata'=>['id'=>'scene-one','name'=>'Départ fictif']]);$convoy->put('scene:scene-two',['metadata'=>['id'=>'scene-two','name'=>'Arrivée fictive']]);
    $convoy->put('scene-index',['order'=>['scene-one','scene-two']]);
    $level=['naturalWidth'=>1000,'naturalHeight'=>1000,'gridSize'=>50];
    $map=[...$level,'activeLayerId'=>'ground','layers'=>['ground'=>$level,'upper'=>$level]];
    $convoy->put('map:scene-one',$map);$convoy->put('map:scene-two',$map);$convoy->put('initiative:scene-one',['active'=>false,'order'=>[]]);
    $model=$convoy->payload('token:scene-one:token-player');
    for($i=0;$i<24;$i++)$convoy->put('token:scene-one:convoy-'.$i,[...$model,'id'=>'convoy-'.$i,'followCharacter'=>false,'controllerPlayerId'=>'account-player','size'=>[220,20,80,40,140,32][$i%6],'x'=>21,'y'=>20,'layerId'=>'ground']);
    $convoy->put('token-index:scene-one',['order'=>array_map(static fn($i)=>'convoy-'.$i,range(0,23))]);$convoy->put('token-index:scene-two',['order'=>[]]);
    $exit=['x'=>$edge?98:60,'y'=>$edge?98:60];
    $created=runCommand($convoy,'passage.create',['requestId'=>'convoy-create-000001','endpoints'=>[
        ['sceneId'=>'scene-one','id'=>'convoy-from','kind'=>'walk','name'=>'Départ','layerId'=>'ground','x'=>20,'y'=>20],
        ['sceneId'=>$targetScene,'id'=>'convoy-to','kind'=>'walk','name'=>'Arrivée','layerId'=>'upper',...$exit]]],true);
    requireTactical($created->status===200,'Create the isolated convoy passage');$previous=[];$expanded=false;
    for($i=0;$i<24;$i++){
        $response=runCommand($convoy,'passage.use',['requestId'=>'convoy-travel-'.str_pad((string)$i,8,'0',STR_PAD_LEFT),'sceneId'=>'scene-one','passageId'=>'convoy-from','expectedRevision'=>1,'tokenId'=>'convoy-'.$i,'expectedX'=>21,'expectedY'=>20]);
        requireTactical($response->status===200,'Twenty-four native player traversals must pass without clearing the exit: '.json_encode($response->body));
        $arrived=$convoy->payload('token:'.$targetScene.':convoy-'.$i);$size=$arrived['size'];
        foreach($previous as $old){
            requireTactical($convoy->payload('token:'.$targetScene.':'.$old['id'])===$old,'The native dispatcher never moves previous arrivals');
            requireTactical(hypot(($arrived['x']-$old['x'])*10,($arrived['y']-$old['y'])*10)>abs($size-$old['size'])/2,'No complete visible cover at a crowded exit');
        }
        requireTactical($arrived['x']>=$size/20&&$arrived['x']<=100-$size/20&&$arrived['y']>=$size/20&&$arrived['y']<=100-$size/20,'Native arrivals respect the entire map footprint');
        $expanded=$expanded||hypot($arrived['x']-$exit['x'],$arrived['y']-$exit['y'])>10;$previous[]=$arrived;$convoyChecks++;
    }
    requireTactical($expanded&&$convoy->payload('table')['activeSceneId']==='scene-one'&&$convoy->payload('map:scene-one')['activeLayerId']==='ground','The arrival expands beyond two cells without changing the broadcast');
}

function mountedPassageCase(string $targetScene='two',int $count=2):array {
    $level=['naturalWidth'=>1000,'naturalHeight'=>1000,'gridSize'=>50,'lights'=>[]];
    $mount=['id'=>'mount','name'=>'Monture fictive','x'=>21,'y'=>20,'size'=>100,'layerId'=>'ground','hp'=>80,'maxHp'=>100,'mana'=>55,'mountable'=>true,'mountControllable'=>true,'maxRiders'=>20];
    $tokens=[$mount];for($i=0;$i<$count;$i++)$tokens[]=[...$mount,'id'=>'rider-'.$i,'name'=>'Cavalier '.$i,'size'=>40,'mountable'=>false,'controllerPlayerId'=>$i===0?'player':'other','mountedOnTokenId'=>'mount','mountedAt'=>100+$i,'mana'=>20+$i];
    $lights=array_map(static fn($unit)=>['id'=>'light-'.$unit['id'],'name'=>'Lumière portée','x'=>21,'y'=>20,'carrierTokenId'=>$unit['id'],'enabled'=>true,'portable'=>true,'visibleFromAfar'=>true,'icon'=>'flame','color'=>'#ffac52','visionDistance'=>9],$tokens);
    $map=[...$level,'tokens'=>$tokens,'activeLayerId'=>'ground','lights'=>$lights,'layers'=>['ground'=>[...$level,'lights'=>$lights],'upper'=>$level]];
    $arrival=[...$map,'tokens'=>[],'lights'=>[],'layers'=>['ground'=>$level,'upper'=>$level]];
    $state=['activeSceneId'=>'one','map'=>$map,'characters'=>[],'initiative'=>['active'=>true,'order'=>['rider-0'],'currentIndex'=>0],
        'scenes'=>[['id'=>'one','name'=>'Départ','combat'=>['map'=>$map]],['id'=>'two','name'=>'Arrivée','combat'=>['map'=>$arrival]]],
        'devices'=>['timedConditions'=>[],'devices'=>array_map(static fn($unit)=>['id'=>'dome-'.$unit['id'],'sceneId'=>'one','sourceTokenId'=>$unit['id'],'definition'=>['kind'=>'dome'],'mode'=>'carried','layerId'=>'ground','x'=>21,'y'=>20,'revision'=>4],$tokens)],
        'actionTimers'=>array_map(static fn($unit)=>['id'=>'timer-'.$unit['id'],'sceneId'=>'one','tokenId'=>$unit['id'],'remainingRounds'=>3],$tokens),
        'attackEnhancements'=>array_map(static fn($unit)=>['id'=>'buff-'.$unit['id'],'holder'=>applicationEnhancementHolder($unit),'sceneId'=>'one','targetTokenId'=>$unit['id'],'remainingTurns'=>3,'combatId'=>'old','lastTurnSerial'=>900],$tokens)];
    return applicationApplyPassageCommand($state,['id'=>'gm','role'=>'gm'],'passage.create',['endpoints'=>[
        ['sceneId'=>'one','id'=>'from','kind'=>'portal','name'=>'Départ','x'=>20,'y'=>20,'layerId'=>'ground'],
        ['sceneId'=>$targetScene,'id'=>'to','kind'=>'portal','name'=>'Arrivée','x'=>70,'y'=>75,'layerId'=>'upper']]])['state'];
}
$mountedArgs=['sceneId'=>'one','passageId'=>'from','expectedRevision'=>1,'tokenId'=>'mount','expectedX'=>21,'expectedY'=>20];$mountedChecks=0;
foreach(['one','two'] as $targetScene)foreach([1,2,20] as $count){
    $case=mountedPassageCase($targetScene,$count);$before=$case;$result=applicationApplyPassageCommand($case,['id'=>'player','role'=>'player'],'passage.use',$mountedArgs);
    $arrivedMap=passageMap($result['state'],$targetScene);$mount=$arrivedMap['tokens'][0];
    requireTactical($case===$before&&$result['result']['tokenId']==='mount'&&$result['result']['riderCount']===$count,'Mounted traversal preserves its input and moves the complete party');
    requireTactical(count($arrivedMap['tokens'])===$count+1&&$result['state']['activeSceneId']==='one'&&$result['state']['map']['activeLayerId']==='ground','Mounted traversal leaves the broadcast unchanged');
    foreach($before['map']['tokens'] as $i=>$old){$unit=$arrivedMap['tokens'][$i];
        requireTactical($unit['x']===$mount['x']&&$unit['y']===$mount['y']&&$unit['layerId']==='upper','Every rider arrives at the mount position');
        foreach(['hp','maxHp','mana','mountedOnTokenId','mountedAt','controllerPlayerId'] as $field)requireTactical(($unit[$field]??null)===($old[$field]??null),'Resources, controller and mount attachment are preserved');
    }
    requireTactical(count(applicationMapLightsForLayer($arrivedMap,'upper',$arrivedMap['tokens']))===$count+1,'Every carried lamp follows its mount or rider');
    foreach($result['state']['actionTimers'] as $timer)requireTactical($timer['sceneId']===$targetScene&&$timer['remainingRounds']===3,'Rider timers follow without a new turn');
    foreach($result['state']['attackEnhancements'] as $buff)requireTactical($buff['sceneId']===$targetScene&&$buff['remainingTurns']===3,'Rider buffs follow without spending a turn');
    foreach($result['state']['devices']['devices'] as $dome)requireTactical($dome['sceneId']===$targetScene&&$dome['layerId']==='upper'&&$dome['revision']===5,'Carried rider domes follow once');
    $mountedChecks++;
}
foreach(['foreign','off-turn','empty-turn','not-controllable','owned-by-other','immovable-rider','duplicate-rider','capacity','wrong-level','nested','rider-alone'] as $failure){
    $case=mountedPassageCase();$args=$mountedArgs;$actor=['id'=>'player','role'=>'player'];
    if($failure==='foreign')$actor['id']='other';
    if($failure==='off-turn')$case['initiative']['order']=['rider-1'];
    if($failure==='empty-turn')$case['initiative']['order']=[];
    if($failure==='not-controllable')$case['map']['tokens'][0]['mountControllable']=false;
    if($failure==='owned-by-other')$case['map']['tokens'][0]['controllerPlayerId']='other';
    if($failure==='immovable-rider')$case['map']['tokens'][1]['immovable']=true;
    if($failure==='wrong-level')$case['map']['tokens'][1]['layerId']='upper';
    if($failure==='duplicate-rider')$case['scenes'][1]['combat']['map']['tokens'][]=$case['map']['tokens'][1];
    if($failure==='capacity')$case['scenes'][1]['combat']['map']['tokens']=array_map(static fn($i)=>['id'=>'other-'.$i,'hidden'=>true],range(0,1997));
    if($failure==='nested')$case['map']['tokens'][]=[...$case['map']['tokens'][1],'id'=>'nested','mountedOnTokenId'=>'rider-0'];
    if($failure==='rider-alone')$args['tokenId']='rider-0';
    $before=$case;
    try{applicationApplyPassageCommand($case,$actor,'passage.use',$args);throw new LogicException('Mounted traversal unexpectedly accepted '.$failure);}catch(RuntimeException $error){requireTactical(in_array($error->getCode(),[403,409,429],true),'Mounted traversal refuses '.$failure);}
    requireTactical($case===$before,'A refused mounted crossing changes no unit or attachment');$mountedChecks++;
}

// Exercise the real command dispatcher, domain reconciliation and persisted receipt.
$mountedDb=fixture();$case=mountedPassageCase();$case['map']['tokens'][1]['controllerPlayerId']='account-player';
$case['activeSceneId']='scene-one';
foreach($case['map']['passages'] as &$passage)if($passage['id']==='from')$passage['destination']['sceneId']='scene-two';unset($passage);
$arrive=$case['scenes'][1]['combat']['map'];$arrive['passages'][0]['destination']['sceneId']='scene-one';
$mountedDb->put('scene:scene-one',['metadata'=>['id'=>'scene-one','name'=>'Départ monté']]);$mountedDb->put('scene:scene-two',['metadata'=>['id'=>'scene-two','name'=>'Arrivée montée']]);
$mountedDb->put('scene-index',['order'=>['scene-one','scene-two']]);$mountedDb->put('map:scene-one',$case['map']);$mountedDb->put('map:scene-two',$arrive);
$mountedDb->put('initiative:scene-one',$case['initiative']);$mountedDb->put('token-index:scene-one',['order'=>['mount','rider-0','rider-1']]);$mountedDb->put('token-index:scene-two',['order'=>[]]);
foreach($case['map']['tokens'] as $unit)$mountedDb->put('token:scene-one:'.$unit['id'],$unit);
$nativeArgs=[...$mountedArgs,'sceneId'=>'scene-one','requestId'=>'mounted-native-travel-0001'];
$arrived=runCommand($mountedDb,'passage.use',$nativeArgs);requireTactical($arrived->status===200&&$arrived->body['riderCount']===2,'Native pilot traversal commits both riders: '.json_encode($arrived->body));
$after=$mountedDb->domains;
for($retry=0;$retry<12;$retry++){$replay=runCommand($mountedDb,'passage.use',$nativeArgs);requireTactical($replay->status===200&&!empty($replay->body['deduplicated']),'Native mounted traversal replay is durable');}
requireTactical($mountedDb->domains===$after,'Twelve native replays do not write duplicate units or lights');
foreach(['mount','rider-0','rider-1'] as $id){$unit=$mountedDb->payload('token:scene-two:'.$id);requireTactical($unit!==[]&&$unit['layerId']==='upper'&&$mountedDb->payload('token:scene-one:'.$id)===[],'Native transfer removes the source copy atomically');if($id!=='mount')requireTactical($unit['mountedOnTokenId']==='mount','Domain reconciliation preserves the saddle attachment');}
$mountedChecks++;
echo json_encode(['ok'=>true,'passageChecks'=>17,'rangeChecks'=>$rangeChecks,'spacingChecks'=>$spacingChecks,'exitSpacingChecks'=>$exitSpacingChecks,'carriedLightChecks'=>$carriedLightChecks,'convoyTraversals'=>$convoyChecks,'mountedCases'=>$mountedChecks]),"\n";
