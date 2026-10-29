<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);
require __DIR__.'/tactical-special-effects.php';
$specialChecks=0;
function dashFixture():MemoryConnection {
    $db=specialFixture();$c=$db->payload('character:character-player');$ability=null;
    foreach($c['abilities']as$candidate)if($candidate['id']==='retreat')$ability=$candidate;
    $ability['id']='ability-9832cd85-12b9-48b6-8908-5ad6f19b944a';$ability['fatigueCost']=5;$ability['workflow']['steps'][0]['avoidsOpportunity']=true;
    $c['abilities']=[$ability];$db->put('character:character-player',$c);return $db;
}
function dashStart(MemoryConnection $db):TestResponse {return runCommand($db,'ability.complex',['action'=>'start','requestId'=>'dash-'.randomToken(20),'sceneId'=>'scene-one','layerId'=>'ground','sourceTokenId'=>'token-player','abilityId'=>'ability-9832cd85-12b9-48b6-8908-5ad6f19b944a']);}
function dashCommand(MemoryConnection $db,string $action):array {$e=$db->payload('activity')['abilityExecutions'][0];return ['action'=>'command','requestId'=>'dash-'.randomToken(20),'executionId'=>$e['id'],'expectedRevision'=>$e['revision'],'command'=>['action'=>$action,'path'=>[['x'=>0,'y'=>0],['x'=>100,'y'=>100]]]];}
function dashCues(MemoryConnection $db):array {return array_values(array_filter($db->payload('activity')['abilityCueEvents']??[],static fn($e)=>($e['completionCue']['spell']['placement']??'')==='dash'));}
$db=dashFixture();$r=dashStart($db);specialCheck($r->status===200,'Native start: '.json_encode($r->body));specialCheck(dashCues($db)===[],'No premature animation');
$r=runCommand($db,'ability.complex',dashCommand($db,'confirm-movement'));specialCheck($r->status===409,'No movement cannot confirm');
$r=specialMove($db,65);specialCheck($r->status===200,'Accepted movement');$r=specialMove($db,85);specialCheck($r->status===409,'Over five cells refused');specialCheck($db->payload('token:scene-one:token-player')['x']==65,'Rejected movement preserves position');
$r=specialMove($db,80);specialCheck($r->status===200,'Exactly five cells');$args=dashCommand($db,'confirm-movement');$r=runCommand($db,'ability.complex',$args);specialCheck($r->status===200&&$r->body['execution']['status']==='completed','Confirmed native movement: '.json_encode($r->body));
$events=dashCues($db);specialCheck(count($events)===1,'One dash cue');$spell=$events[0]['completionCue']['spell'];specialCheck($spell['source']==['x'=>55,'y'=>50,'size'=>40],'Original footprint');specialCheck($spell['path']==[['x'=>55,'y'=>50],['x'=>65,'y'=>50],['x'=>80,'y'=>50]],'Stored path ignores submitted forged points');specialCheck(applicationSpellVisualDuration($spell)===2500,'Polling delivery window');
specialCheck($db->payload('character:character-player')['fatigue']['current']==5,'One cost');$r=runCommand($db,'ability.complex',$args);specialCheck($r->status===200&&!empty($r->body['deduplicated']),'Replay receipt');specialCheck(count(dashCues($db))===1&&$db->payload('character:character-player')['fatigue']['current']==5,'Replay has no extra cue or cost');
$db=dashFixture();dashStart($db);specialMove($db,65);$r=runCommand($db,'ability.complex',dashCommand($db,'cancel'));specialCheck($r->status===200&&dashCues($db)===[],'Cancellation emits no dash');
echo json_encode(['ok'=>true,'checks'=>$specialChecks,'productionAccess'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
