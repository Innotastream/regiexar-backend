<?php
declare(strict_types=1);
require __DIR__ . '/tactical-lifecycle.php';

function criticalTestAbility(): array {
    return ['id'=>'fiction-critical-chain','name'=>'Chaîne critique fictive','effect'=>'complex','formula'=>'0','fatigueCost'=>5,
        'manaCost'=>0,'usesPerRest'=>1,'restRecharge'=>'short','cooldownRounds'=>5,'castingStatId'=>'character-stat-agility',
        'workflow'=>['version'=>8,'steps'=>[['id'=>'chain','type'=>'attack-chain','count'=>5,'statId'=>'character-stat-agility','penaltyPerSuccess'=>10,
            'firstGateAtCast'=>true,'criticalSuccessCompletesChain'=>true,'stopOnFailure'=>true,'resolutionMode'=>'batch','targetMode'=>'once',
            'damageMode'=>'configured','damageComponents'=>[['type'=>'ignore','formula'=>'2d40+12']],'allowOpposition'=>true]]]];
}
function criticalTestDb(): MemoryConnection {
    $db=fixture();$character=$db->payload('character:character-player');$character['abilities']=[criticalTestAbility()];$character['stats']['agility']=95;$db->put('character:character-player',$character);
    $target=$db->payload('token:scene-one:token-monster');$target['hp']=$target['maxHp']=10000;$db->put('token:scene-one:token-monster',$target);
    return $db;
}
for($position=1;$position<=5;$position++){
    $db=criticalTestDb();$ability=criticalTestAbility();$now=(int)floor(microtime(true)*1000);
    $outcome=classifyOnlineD100Outcome($position===1?11:12,95);$outcome['requiresGmValidation']=false;
    $execution=createApplicationComplexAbilityExecution(['id'=>'execution-critical-fixture','sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','controllerAccountId'=>'account-player',
        'ability'=>$ability,'now'=>$now,'cast'=>['success'=>true,'statId'=>'character-stat-agility','outcome'=>$outcome,'roll'=>['id'=>'first-critical-gate']]]);
    $dice=0;$sequence=[...array_fill(0,max(0,$position-2),12),11,98];
    $execution=applyApplicationComplexAbilityCommand($execution,['action'=>'prepare-chain','expectedRevision'=>1,'targetTokenId'=>'token-monster','attackId'=>$ability['id'],'statId'=>'character-stat-agility','opposed'=>true],
        ['actor'=>['id'=>'account-player','role'=>'player'],'now'=>$now,'map'=>['naturalWidth'=>1600,'naturalHeight'=>900,'gridSize'=>50],'tokens'=>[['id'=>'token-player','x'=>20,'y'=>50,'hp'=>100,'stats'=>[['id'=>'character-stat-agility','value'=>95]]],['id'=>'token-monster','x'=>28.5,'y'=>50,'hp'=>10000,'maxHp'=>10000]],
        'roll'=>function()use(&$dice,$sequence){$raw=$sequence[$dice++]??98;return ['total'=>$raw,'rawD100'=>$raw,'formula'=>'1d100'];}]);
    requireTactical($execution['stepStates']['chain']['queuedAttackCount']===5 && count($execution['stepStates']['chain']['rolls'])===$position && $dice===$position-1,'Critical at '.$position.' ends dice and authorizes exactly five strikes');
    $activity=$db->payload('activity');$activity['abilityExecutions']=[$execution];$db->put('activity',$activity);$damage=0;
    for($strike=0;$strike<5;$strike++){
        $execution=$db->payload('activity')['abilityExecutions'][0];
        $body=['sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','attackKind'=>'ability','attackId'=>$ability['id'],'statId'=>'character-stat-agility','complexExecutionId'=>$execution['id'],'opposed'=>true,'requestId'=>'critical-strike-request-'.$strike];
        $response=runCommand($db,'token.attack',$body);requireTactical($response->status===200,'Strike: '.$response->getMessage());$attack=$response->body['attack'];
        $confirmed=runCommand($db,'ability.complex',['action'=>'command','executionId'=>$execution['id'],'expectedRevision'=>$execution['revision'],'requestId'=>'critical-confirm-request-'.$strike,'command'=>['action'=>'confirm-attack','attackRequestId'=>$body['requestId']]]);
        requireTactical($confirmed->status===200,'Confirm: '.$confirmed->getMessage());
        if($attack['status']==='pending'){
            if($strike<4)requireTactical(runCommand($db,'token.attack',[...$body,'requestId'=>'critical-premature-request'])->status===409,'No extra strike before MJ validation');
            $response=runCommand($db,'token.attack.resolve',['attackId'=>$attack['id'],'decision'=>'approve','confirmed'=>true,'requestId'=>'critical-approve-request-'.$strike],true,'account-gm');
            requireTactical($response->status===200,'Approve: '.$response->getMessage());$attack=$response->body['attack'];
        }
        if($attack['status']==='awaiting-opposition'){
            $response=runCommand($db,'token.attack.oppose',['attackId'=>$attack['id'],'decision'=>'skip','requestId'=>'critical-defense-request-'.$strike],true,'account-gm');
            requireTactical($response->status===200,'Counter: '.$response->getMessage());$attack=$response->body['attack'];
        }
        requireTactical($attack['status']==='applied' && $attack['appliedDamage']>=14 && $attack['appliedDamage']<=92,'Real configured damage for each strike');
        $damage+=$attack['appliedDamage'];$hp=$db->payload('token:scene-one:token-monster')['hp'];
        requireTactical(runCommand($db,'token.attack',$body)->body['deduplicated'] && $db->payload('token:scene-one:token-monster')['hp']===$hp,'Replay cannot damage twice');
    }
    requireTactical($db->payload('activity')['abilityExecutions'][0]['status']==='completed' && $db->payload('token:scene-one:token-monster')['hp']==10000-$damage,'Five strikes completed with actual damage');
}
// Exercise the real casting/approval continuation, without a production dice override.
for($attempt=0;$attempt<500;$attempt++){
    $db=criticalTestDb();$start=runCommand($db,'ability.complex',['action'=>'start','requestId'=>'critical-real-start','sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','abilityId'=>'fiction-critical-chain']);
    if(($start->body['cast']['outcome']['code']??'')==='critical-success' && !empty($start->body['pendingValidation']))break;
}
requireTactical($attempt<500,'Critical casting obtained in a bounded isolated fixture');
$approved=runCommand($db,'ability.resolve',['requestId'=>'critical-real-approval','validationId'=>$start->body['validation']['id'],'decision'=>'approve','confirmed'=>true],true,'account-gm');
requireTactical($approved->status===200,'Approved critical casting: '.$approved->getMessage());
$execution=$approved->body['execution'];
requireTactical($execution['stepStates']['chain']['rolls'][0]['outcomeDetails']['requiresGmValidation']===false,'Approved cast is not pending a second time');
requireTactical($db->payload('character:character-player')['fatigue']['current']===5,'Casting fatigue paid only once');
fwrite(STDOUT,"Chaîne critique PHP : cinq positions, cinq frappes, validation différée et rejeux vérifiés.\n");

// The once policy is data, independent of the historical five-strike fixture.
$db=criticalTestDb();$ability=criticalTestAbility();$ability['restRecharge']='none';$ability['reducedFailureCooldown']=true;
$ability['workflow']['steps'][0]['count']=4;$ability['workflow']['steps'][0]['oppositionMode']='once';
$character=$db->payload('character:character-player');$character['abilities']=[$ability];$character['fatigue']=['current'=>5,'max'=>150];$db->put('character:character-player',$character);
$now=(int)floor(microtime(true)*1000);$outcome=classifyOnlineD100Outcome(51,95);
$execution=createApplicationComplexAbilityExecution(['id'=>'execution-single-opposition','sceneId'=>'scene-one','sourceTokenId'=>'token-player','characterId'=>'character-player','targetTokenId'=>'token-monster','controllerAccountId'=>'account-player',
    'ability'=>$ability,'now'=>$now,'cast'=>['success'=>true,'statId'=>'character-stat-agility','outcome'=>$outcome,'roll'=>['id'=>'single-first-gate']]]);
$sequence=[12,13,14];$execution=applyApplicationComplexAbilityCommand($execution,['action'=>'prepare-chain','expectedRevision'=>1,'targetTokenId'=>'token-monster','attackId'=>$ability['id'],'statId'=>'character-stat-agility','opposed'=>true],
    ['actor'=>['id'=>'account-player','role'=>'player'],'now'=>$now,'map'=>['naturalWidth'=>1600,'naturalHeight'=>900,'gridSize'=>50],
    'tokens'=>[['id'=>'token-player','x'=>20,'y'=>50,'hp'=>100,'stats'=>[['id'=>'character-stat-agility','value'=>95]]],['id'=>'token-monster','x'=>28.5,'y'=>50,'hp'=>10000,'maxHp'=>10000]],
    'roll'=>function()use(&$sequence){$raw=array_shift($sequence);return ['total'=>$raw,'rawD100'=>$raw,'formula'=>'1d100'];}]);
requireTactical(array_column($execution['stepStates']['chain']['rolls'],'threshold')===[95,85,75,65],'Four authoritative cumulative thresholds');
$timer=['id'=>'single-timer','sceneId'=>'scene-one','abilityId'=>$ability['id'],'characterId'=>'character-player','tokenId'=>'','usedRound'=>1,'readyRound'=>6,'cooldown'=>5,'cooldownActive'=>true,'restRecharge'=>'none','restUseCount'=>0];
$initiative=$db->payload('initiative:scene-one');applicationCaptureChainFailureTimer($execution,$ability,[],[$timer],$initiative);
$activity=$db->payload('activity');$activity['abilityExecutions']=[$execution];$activity['actionTimers']=[$timer];$db->put('activity',$activity);
$body=['sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','attackKind'=>'ability','attackId'=>$ability['id'],'statId'=>'character-stat-agility','complexExecutionId'=>$execution['id'],'opposed'=>true,'requestId'=>'single-first-strike'];
$first=runCommand($db,'token.attack',$body);requireTactical($first->status===200&&$first->body['attack']['status']==='awaiting-opposition','First strike waits for its only defense');
$execution=$db->payload('activity')['abilityExecutions'][0];$confirm=runCommand($db,'ability.complex',['action'=>'command','executionId'=>$execution['id'],'expectedRevision'=>$execution['revision'],'requestId'=>'single-first-confirm','command'=>['action'=>'confirm-attack','attackRequestId'=>$body['requestId']]]);
requireTactical($confirm->status===200,'First pending strike recorded');
requireTactical(runCommand($db,'token.attack',[...$body,'requestId'=>'single-premature','opposed'=>false])->status===409,'Cannot bypass an unresolved defense');
$waiting=$db->payload('activity');$defended=[...$first->body['attack'],'sceneId'=>'scene-one','sourceTokenId'=>'token-player','complexExecutionId'=>$execution['id'],'status'=>'defended','opposed'=>true];
$stopped=onlineMergeAttackActivityRolls($waiting,$defended,$initiative);requireTactical(!empty($stopped['abilityExecutions'][0]['endedByOpposition'])&&$stopped['actionTimers'][0]['cooldown']===1&&$stopped['actionTimers'][0]['restRecharge']==='none','Native defender win ends the chain and reduces only its own cooldown');
$newer=$waiting;$newer['actionTimers'][0]['readyRound']=20;$stopped=onlineMergeAttackActivityRolls($newer,$defended,$initiative);requireTactical($stopped['actionTimers'][0]['readyRound']===20,'A newer timer survives late opposition');
$ended=onlineMergeAttackActivityRolls($waiting,$defended,[...$initiative,'active'=>false]);requireTactical($ended['actionTimers'][0]['cooldown']===5,'Ended combat does not acquire a new failure cooldown');
$winDb=fixture();$winDb->domains=$db->domains;$defender=$winDb->payload('token:scene-one:token-monster');$defender['specialSuccessStock']=1;$defender['stats']=[['id'=>'character-stat-force','label'=>'Force fictive','value'=>'100']];$winDb->put('token:scene-one:token-monster',$defender);
$won=runCommand($winDb,'token.attack.oppose',['attackId'=>$first->body['attack']['id'],'statId'=>'character-stat-force','useJoker'=>true,'requestId'=>'single-winning-defense-request'],true,'account-gm');
requireTactical($won->status===200&&($won->body['attack']['status']??'')==='defended','Real native defender win: '.$won->getMessage());
requireTactical(!empty($winDb->payload('activity')['abilityExecutions'][0]['endedByOpposition'])&&$winDb->payload('activity')['actionTimers'][0]['cooldown']===1,'Real defense transaction commits reduced failure');
requireTactical($winDb->payload('token:scene-one:token-monster')['hp']===10000&&$winDb->payload('token:scene-one:token-monster')['specialSuccessStock']===0&&$winDb->payload('character:character-player')['fatigue']['current']===5,'Defense and casting costs paid once, no damage');
$resolved=runCommand($db,'token.attack.oppose',['attackId'=>$first->body['attack']['id'],'decision'=>'skip','requestId'=>'single-defense-skipped'],true,'account-gm');requireTactical($resolved->status===200&&$resolved->body['attack']['status']==='applied','MJ can forgo the one defense');
$damage=$resolved->body['attack']['appliedDamage'];
for($i=1;$i<4;$i++){
    $execution=$db->payload('activity')['abilityExecutions'][0];
    requireTactical(runCommand($db,'token.attack',[...$body,'requestId'=>'single-forged-request-'.$i])->status===409,'Second defense cannot be forged');
    $hit=runCommand($db,'token.attack',[...$body,'requestId'=>'single-strike-request-'.$i,'opposed'=>false]);requireTactical($hit->status===200&&$hit->body['attack']['status']==='applied','Following strike has no new defense: '.$hit->getMessage());$damage+=$hit->body['attack']['appliedDamage'];
    $confirmed=runCommand($db,'ability.complex',['action'=>'command','executionId'=>$execution['id'],'expectedRevision'=>$execution['revision'],'requestId'=>'single-confirm-'.$i,'command'=>['action'=>'confirm-attack','attackRequestId'=>'single-strike-request-'.$i]]);requireTactical($confirmed->status===200,'Following strike acknowledged');
}
requireTactical($db->payload('activity')['abilityExecutions'][0]['status']==='completed'&&$db->payload('token:scene-one:token-monster')['hp']==10000-$damage,'Four actual strikes complete');
requireTactical($db->payload('activity')['actionTimers'][0]['cooldown']===5&&$db->payload('character:character-player')['fatigue']['current']===5,'Five rounds and fatigue once, without rest');
fwrite(STDOUT,"Opposition unique PHP : attente, arrêt, quatre frappes, garde de recharge et absence de repos vérifiés.\n");
$initiative['round']=6;$db->put('initiative:scene-one',$initiative);$activity=$db->payload('activity');$activity['actionTimers'][0]['restRecharge']='short';$activity['actionTimers'][0]['restUseCount']=1;$activity['actionTimers'][0]['restUseLimit']=1;$db->put('activity',$activity);
$character=$db->payload('character:character-player');$character['specialSuccessStock']=1;$db->put('character:character-player',$character);
$retry=runCommand($db,'ability.complex',['action'=>'start','requestId'=>'single-without-legacy-rest','sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','abilityId'=>$ability['id'],'useJoker'=>true]);
requireTactical($retry->status===200&&(float)$db->payload('character:character-player')['fatigue']['current']===10.0&&$db->payload('character:character-player')['specialSuccessStock']===0,'Explicit no-rest definition supersedes an exhausted historical rest quota: '.$retry->getMessage());
