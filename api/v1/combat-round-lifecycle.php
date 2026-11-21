<?php
declare(strict_types=1);

function applicationInitiativeEffectClocks(array $previous,array $current):array {
 $delayed=$current['delayedTurnIds']??[];
 if(!$delayed||empty($previous['active'])||empty($current['active'])||($previous['combatId']??null)!==($current['combatId']??null)
     ||($previous['round']??null)!==($current['round']??null))return [$previous,$current];
 $index=$current['currentIndex']??0;
 $leaving=$previous['order'][$previous['currentIndex']??0]??null;
 $entering=$current['order'][$index]??null;
 if(in_array($leaving,$delayed,true)&&($previous['currentIndex']??0)===$index&&($current['order'][$index+1]??null)===$leaving&&$leaving!==$entering)$previous['currentIndex']=-1;
 if(in_array($entering,$delayed,true))$current['currentIndex']=-1;
 return [$previous,$current];
}
function applicationSetPreparedInitiativeOrder(array $initiative,array $order,?string $currentId):array {
 if(!empty($initiative['active'])&&isset($initiative['roundBaseOrder'])){
  $initiative['roundBaseOrder']=$order;
  $initiative['order']=array_values(array_unique([...array_intersect($initiative['order']??[],$order),...$order]));
 }else $initiative['order']=$order;
 $initiative['currentIndex']=$currentId===null?0:(int)array_search($currentId,$initiative['order'],true);
 return $initiative;
}

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
    foreach(['damageOverTime','pendingDotResolutions','nextAttackGuards','attackEnhancements'] as $key)if(isset($state[$key]))$state[$key]=array_values(array_filter($state[$key],static fn($e)=>($e['sceneId']??'')!==$sceneId||($e['expiry']??'')==='rests'||($key==='damageOverTime'&&($e['manualHp']??false)===true)));
    $state['abilityExecutions']=applicationEndComplexCombatEffects($state['abilityExecutions']??[],$sceneId,(string)($previous['combatId']??''));
    return $state;
}
