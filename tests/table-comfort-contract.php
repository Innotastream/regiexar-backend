<?php
declare(strict_types=1);
require __DIR__.'/tactical-lifecycle.php';
$db=fixture();
$request=['action'=>'request','requestId'=>'table-player-request-0001'];
$asked=runCommand($db,'table-tokens',$request);requireTactical($asked->status===200,'Request: '.$asked->getMessage());
requireTactical($db->payload('table')['tableTokens']['pools']['players']['current']===6,'Asking does not spend');
requireTactical(runCommand($db,'table-tokens',['action'=>'use','side'=>'gm','requestId'=>'table-player-denied-0001'])->status===403,'Players cannot spend directly');
$approve=['action'=>'approve','id'=>$request['requestId'],'requestId'=>'table-gm-approve-0001'];
$approved=runCommand($db,'table-tokens',$approve,true,'account-gm');requireTactical($approved->status===200,'Approve: '.$approved->getMessage());
requireTactical($db->payload('table')['tableTokens']['pools']['players']['current']===5,'One coin spent');
requireTactical(runCommand($db,'table-tokens',$approve,true,'account-gm')->body['deduplicated'],'Approval replay only once');
requireTactical(runCommand($db,'table-tokens',[...$approve,'requestId'=>'table-gm-approve-0002'],true,'account-gm')->status===409,'A second approval is stale');
$use=['action'=>'use','side'=>'gm','requestId'=>'table-gm-use-00000001'];
requireTactical(runCommand($db,'table-tokens',$use,true,'account-gm')->status===200,'GM dark coin');
requireTactical(runCommand($db,'table-tokens',$use,true,'account-gm')->body['deduplicated'],'Dark replay once');
$current=$db->payload('table')['tableTokens'];
$configure=['action'=>'configure','requestId'=>'table-gm-config-00001','expectedRevision'=>$current['revision'],'pools'=>['gm'=>['min'=>2,'max'=>8,'current'=>2],'players'=>['min'=>0,'max'=>0,'current'=>0]]];
requireTactical(runCommand($db,'table-tokens',$configure,true,'account-gm')->status===200,'Configure bounds');
requireTactical(runCommand($db,'table-tokens',[...$use,'requestId'=>'table-gm-empty-000001'],true,'account-gm')->status===409,'Minimum enforced');

// A private MJ view uses the token's actual level without publishing that view.
$db=fixture();$token=$db->payload('token:scene-one:token-player');$token['layerId']='upper';$db->put('token:scene-one:token-player',$token);
$map=$db->payload('map:scene-one');$map['activeLayerId']='ground';$map['layers']['upper']=['naturalWidth'=>1000,'naturalHeight'=>1000];$db->put('map:scene-one',$map);
$move=['sceneId'=>'scene-one','layerId'=>'upper','tokenId'=>'token-player','x'=>55,'y'=>55];
requireTactical(runCommand($db,'token.move',$move)->status===409,'Player cannot move on an unpublished floor');
$moved=runCommand($db,'token.move',$move,true,'account-gm');requireTactical($moved->status===200,'MJ private movement: '.$moved->getMessage());
requireTactical($db->payload('map:scene-one')['activeLayerId']==='ground','Movement keeps broadcast floor');
requireTactical(runCommand($db,'token.move',[...$move,'layerId'=>'ground'],true,'account-gm')->status===409,'Stale actual token floor rejected');

// The center cannot escape across an adjacent wall just because the footprint touches it.
$bytes=str_repeat("\0",512*512/8);for($y=0;$y<512;$y++)applicationSetMaskBit($bytes,$y*512+261,true);
$walls=['version'=>1,'width'=>512,'height'=>512,'mask'=>rtrim(strtr(base64_encode($bytes),'+/','-_'),'=')];
$arrivalMap=['activeLayerId'=>'upper','naturalWidth'=>1000,'naturalHeight'=>1000,'walls'=>$walls];$start=['x'=>50,'y'=>50];
foreach([40,80,140,220] as $size){$position=nearestOnlineGroupPosition(['x'=>55,'y'=>50],['size'=>$size],$arrivalMap,[],$start,0,passageArrivalValidator($arrivalMap,$start),true,50);requireTactical($position!==null&&$position['x']<51,'Same side of adjacent wall');}
$arrivalMap['walls']=null;$arrivalMap['fog']=[...$walls,'enabled'=>true];
$position=nearestOnlineGroupPosition(['x'=>55,'y'=>50],['size'=>40],$arrivalMap,[],$start,0,passageArrivalValidator($arrivalMap,$start),true,50);
requireTactical($position!==null&&$position['x']<51,'No landing behind or in painted fog');

$db=fixture();$character=$db->payload('character:character-player');$character['stats']['force']=40;$character['fatigue']=['current'=>60];
$character['abilities']=[['id'=>'stat-buff','name'=>'Concentration fictive','effect'=>'enhancement','formula'=>'0','castingStatId'=>'','manaCost'=>4,'attackEnhancement'=>['mode'=>'stats','turns'=>2,'bonuses'=>['force'=>25]]]];
$db->put('character:character-player',$character);
$cast=runCommand($db,'ability.use',['requestId'=>'stat-buff-cast-000001','sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-player','abilityId'=>'stat-buff']);
requireTactical($cast->status===200,'Stat buff native cast: '.$cast->getMessage());
$rolled=runCommand($db,'token.roll',['requestId'=>'stat-buff-roll-000001','sceneId'=>'scene-one','tokenId'=>'token-player','kind'=>'stat','statId'=>'character-stat-force']);
requireTactical($rolled->status===200&&$rolled->body['roll']['outcome']['threshold']===55,'Stat buff used by authoritative native roll');
requireTactical($db->payload('character:character-player')['stats']['force']===40,'Base statistic unchanged');
$activity=$db->payload('activity');$before=$db->payload('initiative:scene-one');
$expired=applicationAdvanceAttackEnhancements($activity['attackEnhancements'],'scene-one',[...$before,'active'=>true],[...$before,'active'=>false]);
requireTactical($expired===[],'Native stat bonus expires');

$character=$db->payload('character:character-player');$character['specialSuccessStock']=1;$db->put('character:character-player',$character);
$joker=runCommand($db,'token.roll',['requestId'=>'public-joker-gm-000001','layerId'=>'ground','sceneId'=>'scene-one','tokenId'=>'token-player','kind'=>'joker'],true,'account-gm');
requireTactical($joker->status===200&&$joker->body['roll']['visibility']==='public','GM Joker is public: '.json_encode($joker->body));
// Blockers use the native command authority but never enter the vision mask.
$blockers=[...$walls,'mask'=>'','blockerMask'=>$walls['mask']];
requireTactical(validApplicationWallState($blockers),'Blocker state accepted');
requireTactical(!validApplicationWallState([...$blockers,'blockerMask'=>'invalid!']),'Malformed blocker rejected');
$db=fixture();$map=['activeLayerId'=>'ground','viewLocked'=>true,'naturalWidth'=>1000,'naturalHeight'=>1000,'walls'=>$blockers];
$db->put('map:scene-one',$map);$db->put('initiative:scene-one',['active'=>false]);
$token=$db->payload('token:scene-one:token-player');$token['x']=20;$token['y']=50;$token['size']=40;$token['layerId']='ground';$db->put('token:scene-one:token-player',$token);
foreach([false,true] as $gm) {
    $move=runCommand($db,'token.move',['sceneId'=>'scene-one','layerId'=>'ground','tokenId'=>'token-player','x'=>51,'y'=>50],$gm,'account-player');
    requireTactical($move->status===200&&$move->body['blockedByWall']===true&&(float)$move->body['token']['x']===20.0,'Neither player nor GM can land on a blocker');
}
$arrivalMap=['activeLayerId'=>'upper','naturalWidth'=>1000,'naturalHeight'=>1000,'walls'=>$blockers];
foreach([40,80,140,220] as $size) {
    $position=nearestOnlineGroupPosition(['x'=>55,'y'=>50],['size'=>$size],$arrivalMap,[],$start,0,passageArrivalValidator($arrivalMap,$start),true,50);
    requireTactical($position!==null&&$position['x']<51&&onlineGroupPositionValidator($arrivalMap)($position,['size'=>$size]),'Passage arrival stays clear and on its blocker side');
}
echo "table-comfort-contract: ok\n";
