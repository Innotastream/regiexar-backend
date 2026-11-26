<?php
declare(strict_types=1);
require_once __DIR__.'/passive-abilities.php';
function applicationPassiveSource(array $source,array $state=[]):array {
 if(!unitUsesCharacterSheet($source))return $source;
 foreach([...($state['characters']??[]),...($state['myCharacters']??[])] as $character)if($character['id']===($source['characterId']??''))return $character;
 return $source;
}
function applicationEnabledPassives(array $source,array $state,string $kind):array{return array_values(array_filter(applicationConfiguredPassiveAbilities(applicationPassiveSource($source,$state)),static fn($p)=>$p['enabled']&&$p['kind']===$kind));}
function applicationStatRollOptions(array $source,string $statId,array $state=[]):array{return array_values(array_filter(applicationEnabledPassives($source,$state,'stat-roll-option'),static fn($p)=>$p['statId']===$statId));}
function applicationResolvePassiveStatRoll(array $source,string $kind,string $statId,mixed $optionId,array $state=[]):?array {
 if($optionId===null||$optionId==='')return null;
 if(!is_string($optionId)||!preg_match('/^[A-Za-z0-9_-]{1,120}$/D',$optionId))throw new RuntimeException('Option de jet invalide.',400);
 if($kind==='stat')foreach(applicationStatRollOptions($source,$statId,$state) as $option)if($option['id']===$optionId)return $option;
 throw new RuntimeException('Ce passif n’est pas disponible pour cette caractéristique.',403);
}
function applicationApplyPassiveStatRoll(array $spec,array $source,array $arguments,array $state=[]):array {
 $option=applicationResolvePassiveStatRoll($source,(string)($arguments['kind']??$spec['kind']),(string)($spec['statId']??$arguments['statId']??''),$arguments['rollOptionId']??null,$state);if($option===null)return $spec;
 if(applicationRequestsSpecialSuccess($arguments)||normalizeOnlineD100Modifier($arguments['modifier']??0)!==0)throw new RuntimeException('Choisissez ce passif seul, sans autre modificateur ni Joker.',400);
 $bonus=-$option['resultBonus'];return [...$spec,'label'=>$option['rollLabel'],'formula'=>'1d100'.($bonus!==0?($bonus>0?'+':'').$bonus:''),'modifier'=>0,'resultModifier'=>$bonus,'modifierMode'=>$bonus!==0?'result':'threshold','rollMode'=>$option['rollMode']!=='normal'?$option['rollMode']:$spec['rollMode']];
}
function applicationPassiveEffectMatches(array $passive,array $context):bool {
 $family=$context['family']??applicationEffectFamily($context);
 if(($passive['id']??'')==='mania-self-experimentation'&&$family==='poison')return false;
 return in_array($family,$passive['families'],true)||in_array($context['sourceItemKind']??'',$passive['itemKinds'],true);
}
function applicationPassiveInfusion(?array $definition,array $source=[],array $state=[]):?array {
 if(empty($definition['enabled']))return $definition;
 $ratio=$definition['ratio']??1;foreach(applicationEnabledPassives($source,$state,'infusion-efficiency') as $p)$ratio=max($ratio,$p['ratio']);return [...$definition,'ratio'=>$ratio];
}
function applicationCombatInfusion(?array $definition, array $source = [], array $state = []): ?array {
 return applicationEnabledPassives($source,$state,'infusion-forbidden')!==[] ? null : applicationPassiveInfusion($definition,$source,$state);
}
function applicationPassiveRangedAttackPenalty(array $target,array $attack=[],array $state=[]):int {
 if(($attack['reach']??'')!=='ranged'&&($attack['projectile']??false)!==true)return 0;
 $penalty=0;foreach(applicationEnabledPassives($target,$state,'ranged-target-evasion') as $p)$penalty=max($penalty,$p['thresholdPenalty']);return $penalty;
}
function applicationPassiveEffectTurns(array $source,int $turns,array $context=[],array $state=[]):int {
 $reduction=0;foreach(applicationEnabledPassives($source,$state,'effect-resistance') as $p)if(applicationPassiveEffectMatches($p,$context)&&(($context['harmful']??true)!==false||$p['affectBeneficial']))$reduction=max($reduction,$p['durationReduction']);
 if($turns>0&&($context['harmful']??true)!==false&&($context['family']??applicationEffectFamily($context))==='poison'
    &&applicationEnabledPassives($source,$state,'poison-resilience')!==[])return max(1,$turns-max(1,$reduction));
 return max(0,$turns-$reduction);
}
function applicationPassiveDamageReduction(array $source,int $damage,array $context=[],array $state=[]):array {
 $percent=0;foreach(applicationEnabledPassives($source,$state,'effect-resistance') as $p)if(applicationPassiveEffectMatches($p,$context))$percent=max($percent,$p['percent']);$prevented=min($damage,(int)round($damage*$percent/100));return ['damage'=>$damage-$prevented,'percent'=>$percent,'prevented'=>$prevented];
}
function applicationPoisonHeals(array $source, array $state = []): bool {
 return applicationEnabledPassives($source, $state, 'poison-damage-healing') !== [];
}
function applicationPoisonHealthSplit(array $source, int $damage, ?array $basis, array $state = []): array {
 $unchanged = ['damage'=>$damage,'healing'=>0,'basis'=>$basis];
 if ($damage <= 0 || $basis === null) return $unchanged;
 $parts = applicationGuardDamageParts($damage,$basis);
 $remaining = [];
 $healing = 0;
 foreach ($parts as $part) {
  $element = applicationDamageElement($part['damageElement'] ?? $part['type']);
  if ($element === 'poison' || $element === null && count($parts) === 1 && ($basis['family'] ?? '') === 'poison') $healing += $part['finalDamage'];
  else $remaining[] = $part;
 }
 if ($healing <= 0 || !applicationPoisonHeals($source,$state)) return $unchanged;
 $healing = min($damage,$healing);
 return ['damage'=>$damage-$healing,'healing'=>$healing,'basis'=>[...$basis,'components'=>$remaining,'finalDamage'=>$damage-$healing]];
}
function applicationPassiveItemDuration(array $action,array $source,array $template,array $state):?array {
 $context=['sourceItemKind'=>$template['kind']??'','harmful'=>$action['kind']==='armor-reduction'||$action['kind']==='stats'&&min(array_values($action['bonuses']))<0];
 $path=($action['timing']??'')==='on-hit'?'buffTurns':($action['kind']==='stats'&&$action['duration']['kind']==='rounds'?'duration':(in_array($action['kind'],['immunity','armor-reduction'],true)&&($action['scope']??'')!=='zone'?'turns':''));
 if($path==='')return $action;
 $turns=applicationPassiveEffectTurns($source,$path==='duration'?$action['duration']['turns']:$action[$path],$context,$state);if($turns<=0)return null;
 if($path==='duration')$action['duration']['turns']=$turns;else $action[$path]=$turns;return $action;
}
