<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY', true);
require __DIR__.'/tactical-special-effects.php';
set_error_handler(static function(int $severity,string $message,string $file,int $line):never {
    throw new ErrorException($message,0,$severity,$file,$line);
});

$auditChecks=0;
function auditDeviceCheck(bool $condition,string $message):void {
    if(!$condition)throw new RuntimeException($message);
    $GLOBALS['auditChecks']++;
}
function auditDeviceFixture(array $tokenFields=[]):MemoryConnection {
    $db=specialFixture();
    $ability=['id'=>'audit-battery','name'=>'Station fictive','effect'=>'device','formula'=>'0',
        'castingStatId'=>'','manaCost'=>0,'fatigueCost'=>0,'cooldownRounds'=>0,
        'device'=>dsDefinition(['kind'=>'battery'])];
    $sheet=$db->payload('character:character-player');$sheet['abilities']=[$ability];
    $db->put('character:character-player',$sheet);
    $token=array_replace($db->payload('token:scene-one:token-player'),['abilities'=>[$ability]],$tokenFields);
    $db->put('token:scene-one:token-player',$token);
    return $db;
}
function auditDeviceDeploy(MemoryConnection $db):TestResponse {
    $source=$db->payload('token:scene-one:token-player');
    return specialMechanics($db,'device.deploy',['characterId'=>unitIdForToken($source),
        'sourceTokenId'=>$source['id'],'abilityId'=>'audit-battery'],false);
}

foreach([
    ['name'=>'independent clone','token'=>['followCharacter'=>false,'independentClone'=>true]],
    ['name'=>'linked companion','token'=>['linkedTokenId'=>'companion-fictif']],
] as $case){
    $db=auditDeviceFixture($case['token']);$store=dsEmpty();
    $store['timedConditions'][]=['id'=>'parent-stun','sceneId'=>'scene-one','targetTokenId'=>'another-parent-occurrence',
        'targetCharacterId'=>'character-player','remainingTurns'=>2,'blocksActions'=>true,'label'=>'Étourdi'];
    $db->put('devices',$store);
    $result=auditDeviceDeploy($db);
    auditDeviceCheck($result->status===200,'The '.$case['name'].' is not stunned by its parent condition: '.json_encode($result->body));
}

$db=auditDeviceFixture();$store=dsEmpty();
$store['timedConditions'][]=['id'=>'sheet-stun','sceneId'=>'scene-one','targetTokenId'=>'another-sheet-occurrence',
    'targetCharacterId'=>'character-player','remainingTurns'=>2,'blocksActions'=>true,'label'=>'Étourdi'];
$db->put('devices',$store);$before=$db->domains;
auditDeviceCheck(auditDeviceDeploy($db)->status===423,'A linked occurrence still shares its sheet action block');
auditDeviceCheck($db->domains===$before,'Refused deployment preserves all domains');

$db=auditDeviceFixture(['followCharacter'=>false,'independentClone'=>true]);$store=dsEmpty();
$store['timedConditions'][]=['id'=>'clone-stun','sceneId'=>'scene-one','targetTokenId'=>'token-player',
    'targetCharacterId'=>'','remainingTurns'=>2,'blocksActions'=>true,'label'=>'Étourdi'];
$db->put('devices',$store);
auditDeviceCheck(auditDeviceDeploy($db)->status===423,'An independent clone still obeys its own action block');

$db=auditDeviceFixture(['healthOverride'=>'dead','hp'=>0]);
$result=auditDeviceDeploy($db);
auditDeviceCheck($result->status===200,'A recovered sheet can deploy despite stale death on its linked token: '.json_encode($result->body));
$db=auditDeviceFixture(['followCharacter'=>false,'independentClone'=>true,'healthOverride'=>'dead']);
auditDeviceCheck(auditDeviceDeploy($db)->status===409,'An independent dead clone cannot deploy');
$db=auditDeviceFixture();$sheet=$db->payload('character:character-player');$sheet['healthOverride']='dead';$db->put('character:character-player',$sheet);
auditDeviceCheck(auditDeviceDeploy($db)->status===409,'A manually dead sheet cannot deploy');
$db=auditDeviceFixture();$token=$db->payload('token:scene-one:token-player');
unset($token['healthOverride']);$token['conditions']=['Mort'];$db->put('token:scene-one:token-player',$token);
auditDeviceCheck(auditDeviceDeploy($db)->status===200,'Legacy death on the token does not override the recovered sheet');
$db=auditDeviceFixture();$sheet=$db->payload('character:character-player');
unset($sheet['healthOverride']);$sheet['conditions']=['Mort'];$db->put('character:character-player',$sheet);
auditDeviceCheck(auditDeviceDeploy($db)->status===409,'Legacy death on the actual sheet still blocks deployment');

// A queued remarkable result must remain dismissible after its unit disappears.
foreach(['token','owner'] as $removed){
    $db=auditDeviceFixture();$store=dsEmpty();
    $store['pendingRolls'][]=['id'=>'audit-deferred-deploy','type'=>'deploy','sceneId'=>'scene-one',
        'ownerId'=>'character-player','tokenId'=>'token-player','ability'=>$db->payload('character:character-player')['abilities'][0],
        'args'=>[],'cast'=>['success'=>true]];
    $db->put('devices',$store);
    unset($db->domains[$removed==='token'?'token:scene-one:token-player':'character:character-player']);
    $beforeItems=$db->payload('items');
    $result=specialMechanics($db,'roll.resolve',['validationId'=>'audit-deferred-deploy','approve'=>false]);
    auditDeviceCheck($result->status===200,'The GM can refuse a pending deploy after its '.$removed.' disappears: '.json_encode($result->body));
    auditDeviceCheck($db->payload('devices')['pendingRolls']===[],'The stale remarkable result is cleared');
    auditDeviceCheck(($db->payload('items')['instances']??[])===($beforeItems['instances']??[]),'Dismissing an obsolete result creates no item');
}

foreach(['changed-unit','wrong-scene'] as $change){
    $db=auditDeviceFixture();$store=dsEmpty();
    $store['pendingRolls'][]=['id'=>'audit-deferred-deploy','type'=>'deploy','sceneId'=>'scene-one',
        'ownerId'=>'character-player','tokenId'=>'token-player','ability'=>$db->payload('character:character-player')['abilities'][0],
        'args'=>[],'cast'=>['success'=>true]];
    if($change==='changed-unit'){$token=$db->payload('token:scene-one:token-player');$token['independentClone']=true;$token['followCharacter']=false;$db->put('token:scene-one:token-player',$token);}
    if($change==='wrong-scene')$store['pendingRolls'][0]['sceneId']='other-scene';
    $db->put('devices',$store);$before=$db->domains;
    $result=specialMechanics($db,'roll.resolve',['validationId'=>'audit-deferred-deploy','approve'=>true]);
    auditDeviceCheck($result->status===409,'A pending deploy rejects changed context '.$change.': '.json_encode($result->body));
    auditDeviceCheck($db->domains===$before,'Refusing stale approval preserves all domains for '.$change);
    $cancel=['validationId'=>'audit-deferred-deploy','approve'=>false,'requestId'=>'audit-cancel-deploy-'.$change];
    $cancelled=specialMechanics($db,'roll.resolve',$cancel);
    auditDeviceCheck($cancelled->status===200,'The GM can dismiss the changed-context result '.$change);
    $before=$db->domains;$replay=specialMechanics($db,'roll.resolve',$cancel);
    auditDeviceCheck($replay->status===200&&!empty($replay->body['deduplicated'])&&$db->domains===$before,'Refusal replay is idempotent for '.$change);
}

foreach(['bomb'=>['explosive','awaiting-launch','failed'],'channel'=>['dome','active','stopped'],'luck'=>['beam','spent','broken']] as $type=>$setup){
    $db=auditDeviceFixture();$store=dsEmpty();
    $ability=$db->payload('character:character-player')['abilities'][0];$ability['device']=dsDefinition(['kind'=>$setup[0]]);
    $store['devices'][]=['id'=>'audit-waiting-device','itemId'=>'audit-device-item','revision'=>1,'status'=>$setup[1],
        'creatorCharacterId'=>'character-player','controllerAccountId'=>'account-player','sourceTokenId'=>'token-player',
        'abilityId'=>$ability['id'],'ability'=>$ability,'name'=>'Dispositif fictif','sceneId'=>'scene-one','layerId'=>'ground',
        'x'=>50,'y'=>50,'definition'=>$ability['device'],'mana'=>0,'mark'=>['x'=>70,'y'=>50]];
    $store['pendingRolls'][]=['id'=>'audit-device-result','type'=>$type,'sceneId'=>'scene-one','ownerId'=>'character-player',
        'tokenId'=>'token-player','deviceId'=>'audit-waiting-device','cast'=>['success'=>true]];
    $db->put('devices',$store);unset($db->domains['token:scene-one:token-player']);
    $cancel=['validationId'=>'audit-device-result','approve'=>false,'requestId'=>'audit-cancel-device-'.$type];
    $result=specialMechanics($db,'roll.resolve',$cancel);
    auditDeviceCheck($result->status===200,'A missing pawn does not prevent cancelling '.$type.': '.json_encode($result->body));
    $device=$db->payload('devices')['devices'][0];
    auditDeviceCheck($device['status']===$setup[2]&&$device['revision']===2,'Cancellation keeps the existing failure semantics for '.$type);
    auditDeviceCheck($db->payload('devices')['pendingRolls']===[],'The '.$type.' request is removed');
    $before=$db->domains;$replay=specialMechanics($db,'roll.resolve',$cancel);
    auditDeviceCheck($replay->status===200&&!empty($replay->body['deduplicated'])&&$db->domains===$before,'The '.$type.' cancellation cannot be applied twice');
}

echo json_encode(['ok'=>true,'checks'=>$auditChecks],JSON_THROW_ON_ERROR)."\n";
