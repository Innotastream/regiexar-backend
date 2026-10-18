<?php
declare(strict_types=1);
require_once __DIR__.'/passive-abilities.php';
function applicationTacticalEffect(mixed $v):?array {
    if($v===null||empty($v['kind']))return null;
    if(!is_array($v)||($v['version']??0)!==1||!in_array($v['kind']??'',['appearance','control','mark','coating'],true))throw new RuntimeException('Version ou mécanique d’effet ciblé non prise en charge.',400);
    $count=static fn($n,$fallback,$min=0)=>is_int($n)&&$n>=$min&&$n<=100?$n:$fallback;
    return match($v['kind']){
        'mark'=>['version'=>1,'kind'=>'mark','attackBonus'=>$count($v['attackBonus']??null,20),'dodgeBonus'=>$count($v['dodgeBonus']??null,20),'trigger'=>($v['trigger']??'')==='after-target-attacks'?'after-target-attacks':'against-target'],
        'control'=>['version'=>1,'kind'=>'control','turns'=>$count($v['turns']??null,1,1)],
        'coating'=>['version'=>1,'kind'=>'coating','craftStatId'=>in_array($v['craftStatId']??'',applicationStatIds(),true)?$v['craftStatId']:'character-stat-intelligence'],
        default=>['version'=>1,'kind'=>'appearance']};
}
function applicationTacticalController(array $token,string $fallback=''):string {return $token['controlEffect']['controllerId']??$fallback;}
function applicationTacticalImage(array $token,mixed $base):mixed {return isset($token['appearanceState'])?(($token['appearanceState']['active']??false)?$token['appearanceState']['image']:$token['appearanceState']['baseImage']):$base;}
function applicationMaximumFormula(string $formula):int {
    if(!validApplicationAbilityFormula($formula))throw new RuntimeException('Formule d’arme invalide.',400);
    preg_match_all('/[+-]?(?:\d*d\d+|\d+)/',strtolower(preg_replace('/\s+/','',$formula)),$matches);$sum=0;
    foreach($matches[0] as $term){$sign=$term[0]==='-'?-1:1;$v=ltrim($term,'+-');$dice=[];if(preg_match('/^(\d*)d(\d+)$/',$v,$dice))$sum+=$sign*(int)($dice[1]?:1)*($sign<0?1:(int)$dice[2]);else $sum+=$sign*(int)$v;}return $sum;
}
function applicationApplyTacticalEffect(array $source,array $target,array $ability,string $controller,array $clock,string $id):array {
    $e=applicationTacticalEffect($ability['tacticalEffect']??null);if(!$e)return [$source,$target];
    if($e['kind']==='appearance')$source['appearanceState']=['version'=>1,'id'=>$id,'label'=>$ability['name'],'abilityId'=>$ability['id'],'active'=>true,
        'baseImage'=>isset($source['appearanceState'])?$source['appearanceState']['baseImage']:($source['image']??null),'image'=>$target['image']??null,'targetTokenId'=>$target['id']];
    if($e['kind']==='control'&&applicationHasPassive($target,'mental-control-resistance'))return [$source,$target,['influenceTurns'=>$e['turns']]];
    if($e['kind']==='control')$target['controlEffect']=['version'=>1,'id'=>$id,'label'=>$ability['name'],'abilityId'=>$ability['id'],'sourceTokenId'=>$source['id'],'controllerId'=>$controller,
        'createdTurnSerial'=>$clock['turnSerial']??0,'combatId'=>($clock['active']??false)?($clock['combatId']??''):'','hasStarted'=>false,'remainingTurns'=>$e['turns']];
    if($e['kind']==='mark'){$marks=array_values(array_filter($source['predatoryMarks']??[],static fn($m)=>$m['abilityId']!==$ability['id']));
        $marks[]=[...$e,'id'=>$id,'label'=>$ability['name'],'abilityId'=>$ability['id'],'targetTokenId'=>$target['id'],'active'=>$e['trigger']!=='after-target-attacks','combatId'=>($clock['active']??false)?($clock['combatId']??''):''];$source['predatoryMarks']=$marks;}
    return [$source,$target];
}
function applicationAdvanceTacticalTokens(array $tokens,array $before,array $clock,bool $manual=false):array {
    $ended=($before['active']??false)&&!($clock['active']??false);$advanced=($clock['active']??false)&&($before['active']??false)&&($clock['turnSerial']??0)>($before['turnSerial']??0);
    if(!$ended&&!$advanced&&!$manual)return $tokens;
    foreach($tokens as &$token){if($ended)unset($token['predatoryMarks'],$token['controlEffect']);if(($advanced||$manual)&&!empty($token['controlEffect'])){
        $c=$token['controlEffect'];$left=$before['order'][$before['currentIndex']??0]??'';$entered=$clock['order'][$clock['currentIndex']??0]??'';
        if($manual||($c['hasStarted']??false)&&$left===$token['id'])$c['remainingTurns']=max(0,($c['remainingTurns']??1)-1);if($c['remainingTurns']<=0)unset($token['controlEffect']);else{if($manual||$entered===$token['id'])$c['hasStarted']=true;$token['controlEffect']=$c;}
    }}unset($token);return $tokens;
}
function applicationMarkAttackedTarget(array $target,string $attacker):array {foreach($target['predatoryMarks']??[] as $i=>$m)if($m['trigger']==='after-target-attacks'&&$m['targetTokenId']===$attacker)$target['predatoryMarks'][$i]['active']=true;return $target;}
function applicationPredatoryResultBonus(array $source,string $opponent,string $phase,string $statId=''):int {
    $sum=0;foreach($source['predatoryMarks']??[] as $m)if(($m['active']??false)&&($m['trigger']==='after-target-attacks'||$m['targetTokenId']===$opponent))$sum+=$phase==='attack'?$m['attackBonus']:($phase==='defense'&&$statId==='character-stat-agility'?$m['dodgeBonus']:0);return $sum;
}
function applicationTacticalEffectsSummary(array $t):array {
    $r=[];if($t['appearanceState']['active']??false)$r[]=['id'=>$t['appearanceState']['id'],'kind'=>'appearance','label'=>$t['appearanceState']['label'],'detail'=>'Apparence empruntée · jusqu’à révocation','ownerRevoke'=>true];
    if(!empty($t['controlEffect']))$r[]=['id'=>$t['controlEffect']['id'],'kind'=>'control','label'=>$t['controlEffect']['label'],'detail'=>'Contrôle jusqu’à la fin du prochain tour complet joué par cette unité · révocable par le MJ'];
    foreach($t['predatoryMarks']??[] as $m)$r[]=['id'=>$m['id'],'kind'=>'mark','label'=>$m['label'],'detail'=>'Attaques +'.$m['attackBonus'].' · esquives +'.$m['dodgeBonus'].' au résultat · '.($m['active']?'actif':'attend une attaque de la cible').' · jusqu’à la fin du combat ou au retrait MJ'];return $r;
}
