<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/v1/domains.php';
require_once __DIR__ . '/../api/v1/online.php';
function allocatedCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$ability = ['id'=>'new-sweep','name'=>'Rafale inédite','effect'=>'complex','formula'=>'0','castingStatId'=>'','manaCost'=>3,
    'workflow'=>['version'=>8,'steps'=>[
        ['id'=>'targets','type'=>'targets','minTargets'=>1,'maxTargets'=>3,'maximumPerTarget'=>1,'allocationTotal'=>0],
        ['id'=>'strikes','type'=>'allocated-attacks','sourceStepId'=>'targets','awarenessMode'=>'none','damageMode'=>'configured','hitMode'=>'automatic','statId'=>'',
            'damageComponents'=>[['type'=>'magical','formula'=>'1d6+4','ignoreArmor'=>true]],'defenseStatId'=>'character-stat-agility','defenseRollMode'=>'disadvantage']
    ]]];
allocatedCheck(applicationComplexAbilityWorkflowError($ability['workflow']) === '', 'Automatic strikes require no unused attack statistic.');
$invalid=$ability['workflow']; $invalid['steps'][1]['hitMode']='cast';
allocatedCheck(str_contains(applicationComplexAbilityWorkflowError($invalid),'statistique'), 'A cast still requires a real statistic.');
$invalid=$ability['workflow']; $invalid['steps'][1]['damageComponents'][]=$invalid['steps'][1]['damageComponents'][0];
allocatedCheck(str_contains(applicationComplexAbilityWorkflowError($invalid),'dégâts'), 'Duplicate damage types remain invalid.');
$tokens=[['id'=>'source','name'=>'Source','stats'=>[]],...array_map(static fn(string $id):array=>['id'=>$id,'name'=>$id,'hp'=>1000,'maxHp'=>1000],['third','first','second'])];
$execution=createApplicationComplexAbilityExecution(['id'=>'execution-generic','sceneId'=>'scene','sourceTokenId'=>'source','controllerAccountId'=>'owner','ability'=>$ability,'now'=>1]);
$context=['actor'=>['id'=>'owner','name'=>'Owner'],'tokens'=>$tokens,'now'=>4];
$advance=static function(array $command,?array $attack=null) use (&$execution,$context):void {
    $execution=applyApplicationComplexAbilityCommand($execution,[...$command,'expectedRevision'=>$execution['revision']],[...$context,...($attack===null?[]:['attack'=>$attack])]);
};
$advance(['action'=>'select-targets','allocations'=>array_map(static fn(string $id):array=>['tokenId'=>$id,'count'=>1],['third','first','second']),
    'attackPlan'=>array_map(static fn(string $id):array=>['tokenId'=>$id,'attackId'=>'new-sweep','statId'=>'','opposed'=>false],['third','first','second'])]);
$attacks=[];
foreach (['third','first','second'] as $i=>$id) {
    $advance(['action'=>'prepare-target','targetTokenId'=>$id]);
    $request='request-'.$i; $execution['stepStates']['strikes']['attackRequestId']=$request;
    $attack=['id'=>'attack-'.$i,'requestId'=>$request,'accountId'=>'owner','attackKind'=>'ability','complexExecutionId'=>$execution['id'],'sceneId'=>'scene','sourceTokenId'=>'source',
        'targetTokenId'=>$id,'targetName'=>$id,'opposed'=>false,'damagePercent'=>100,'status'=>$i===1?'awaiting-opposition':'applied','appliedDamage'=>8+$i,'createdAt'=>4,
        'hit'=>['outcome'=>['automatic'=>true,'success'=>true,'threshold'=>91],'statId'=>'secret','secret'=>'do-not-project']];
    $advance(['action'=>'confirm-attack','attackRequestId'=>$request],$attack);
    allocatedCheck($execution['stepStates']['strikes']['attackRequestId']==='', 'A confirmed receipt never becomes the next strike.');
    $attacks[]=$attack;
}
allocatedCheck($execution['status']==='completed', 'All three independent targets complete.');
$execution['compactDiscordSent']=true; $execution['stepStates']['strikes']['concurrentNote']='keep';
$late=[...$attacks[1],'status'=>'applied','appliedDamage'=>17,'opposition'=>['raw'=>40,'statId'=>'private-agility','outcome'=>['threshold'=>51,'success'=>false]]];
$updated=applicationComplexAbilityUpdateAttackResult([$execution],$late,10)[0];
allocatedCheck($updated['stepStates']['strikes']['targets'][1]['attacks'][0]['appliedDamage']===17, 'Late damage updates the correct target.');
allocatedCheck($updated['revision']===$execution['revision']+1, 'A changed result advances the execution revision.');
allocatedCheck($updated['compactDiscordSent'] && $updated['stepStates']['strikes']['concurrentNote']==='keep', 'Concurrent metadata and Discord acknowledgements remain.');
allocatedCheck(applicationComplexAbilityUpdateAttackResult([$updated],$late,11)[0]===$updated, 'Repeated receipt synchronization is inert.');
$record=$updated['stepStates']['strikes']['targets'][1]['attacks'][0];
allocatedCheck(!isset($record['hit']['statId']) && !isset($record['hit']['outcome']['threshold']) && !isset($record['opposition']['statId']) && !isset($record['opposition']['outcome']['threshold']), 'Public results never expose private stats or thresholds.');
$read=applicationComplexAbilityResultsFromReceipts([$execution],[['attack'=>$late]])[0];
allocatedCheck($read['revision']===$execution['revision'] && $read['updatedAt']===$execution['updatedAt'], 'Legacy results enrich reads without changing command revisions.');
allocatedCheck($read['stepStates']['strikes']['targets'][1]['attacks'][0]['appliedDamage']===17, 'Legacy completed results use acquired receipts.');
allocatedCheck(applicationComplexAbilityUpdateAttackResult([$updated],[...$late,'sceneId'=>'other-scene'],12)[0]===$updated, 'A receipt from another scene cannot change the execution.');
echo "allocated-attacks-contract: ok\n";
