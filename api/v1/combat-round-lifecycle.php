<?php
declare(strict_types=1);

function applicationEffectiveRoundCooldown(array $ability,array $initiative):int {
    return ($initiative['active']??false)===true?max(0,(int)($ability['cooldownRounds']??0)):0;
}
function applicationFinishRoundTimers(array $state,string $sceneId,?string $now=null):array {
    $now??=gmdate('c');$kept=[];$removed=[];
    foreach($state['actionTimers']??[] as $timer){
        if(($timer['sceneId']??'')!==$sceneId){$kept[]=$timer;continue;}
        if(!in_array($timer['restRecharge']??'',['short','long'],true)){$removed[]=$timer['id'];continue;}
        $kept[]=[...$timer,'cooldown'=>0,'cooldownActive'=>false,'usedRound'=>1,'readyRound'=>1,'turnKey'=>'','useCount'=>0,'combatId'=>'','combatUseCount'=>0,'updatedAt'=>$now];
    }
    $state['actionTimers']=$kept;
    if($removed!==[]){$tombstones=array_column($state['actionTimerTombstones']??[],null,'id');foreach($removed as $id)$tombstones[$id]=['id'=>$id,'deletedAt'=>$now];$state['actionTimerTombstones']=array_slice(array_values($tombstones),-1000);}
    return $state;
}
function applicationFinishCombatRounds(array $state,string $sceneId,array $previous,?string $now=null):array {
    $state=applicationFinishRoundTimers($state,$sceneId,$now);
    foreach(['damageOverTime','pendingDotResolutions','nextAttackGuards','attackEnhancements'] as $key)if(isset($state[$key]))$state[$key]=array_values(array_filter($state[$key],static fn($e)=>($e['sceneId']??'')!==$sceneId||($e['expiry']??'')==='rests'));
    $state['abilityExecutions']=applicationEndComplexCombatEffects($state['abilityExecutions']??[],$sceneId,(string)($previous['combatId']??''));
    return $state;
}
