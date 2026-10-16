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
        ['actor'=>['id'=>'account-player','role'=>'player'],'now'=>$now,'tokens'=>[['id'=>'token-player','hp'=>100,'stats'=>[['id'=>'character-stat-agility','value'=>95]]],['id'=>'token-monster','hp'=>10000,'maxHp'=>10000]],
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
