<?php
declare(strict_types=1);
require_once __DIR__.'/effect-formula.php';
function applicationItemUseEnhancementDefinition(mixed $v):?array {
 if(!is_array($v)||($v['mode']??'')!=='next-item-use'||!in_array($v['itemKind']??'',['potion','object'],true)||!is_array($v['multipliers']??null))return null;
 $m=[];foreach(['healing','damage','area','duration'] as $k){$n=$v['multipliers'][$k]??1;if(!is_int($n)||$n<1||$n>4)return null;$m[$k]=$n;}
 if(!array_filter($m,static fn($n)=>$n>1))return null;
 return ['mode'=>'next-item-use','itemKind'=>$v['itemKind'],'multipliers'=>$m,'turns'=>1,'target'=>'self','expiry'=>($v['expiry']??'')==='combat-or-rest'?'combat-or-rest':'long-rest'];
}
function applicationItemEnhancementHolder(string $unitId):string{return str_starts_with($unitId,'unit_')?'token:'.substr($unitId,5):'character:'.$unitId;}
function applicationItemUseEnhancement(array $state,array $template,string $unitId):?array {
 foreach($state['attackEnhancements']??[] as $e)if(($e['holder']??'')===applicationItemEnhancementHolder($unitId)&&($e['mode']??'')==='next-item-use'&&($e['itemKind']??'')===($template['kind']??'')&&($e['remainingTurns']??0)>0&&($d=applicationItemUseEnhancementDefinition($e))!==null){
  foreach($state['items']['pendingUses']??[] as $u)if(($u['itemEnhancement']['id']??'')===$e['id'])throw new RuntimeException('Cette préparation attend déjà la résolution d’une potion. Terminez ou annulez cette utilisation.',409);
  return ['id'=>$e['id'],...$d];
 }return null;
}
function applicationAssertExpectedItemEnhancement(array $args,?array $snapshot):void {
 if(array_key_exists('expectedItemEnhancementId',$args)&&($args['expectedItemEnhancementId']?:null)!==($snapshot['id']??null))throw new RuntimeException('La préparation de votre prochaine potion a changé. Rouvrez son utilisation.',409);
}
function applicationValidateItemUseEnhancement(array $state,array $template,string $unitId,?array $snapshot):void {
 if($snapshot===null)return;
 foreach($state['attackEnhancements']??[] as $e)if($e['id']===$snapshot['id']&&($e['holder']??'')===applicationItemEnhancementHolder($unitId)&&($e['remainingTurns']??0)>0&&($e['itemKind']??'')===$template['kind']&&applicationItemUseEnhancementDefinition($e)===applicationItemUseEnhancementDefinition($snapshot))return;
 throw new RuntimeException('La préparation de cette potion a changé. Annulez cette utilisation puis recommencez.',409);
}
function applicationConsumeItemUseEnhancement(array &$state,?array $snapshot):void {if($snapshot!==null)$state['attackEnhancements']=array_values(array_filter($state['attackEnhancements']??[],static fn($e)=>$e['id']!==$snapshot['id']));}
function applicationEnhancedItemTargeting(array $active,?array $snapshot):?array {
 $t=$active['targeting']??null;if($t===null||$t['mode']!=='area'||$snapshot===null)return $t;
 return [...$t,'radiusCells'=>$t['radiusCells']*$snapshot['multipliers']['area'],'expandedItemArea'=>true];
}
function applicationScaleItemFormula(string $formula,int $n):string {
 if($n===1)return $formula;$scaled=$formula.'*'.$n;
 if(!validEffectFormula($scaled))throw new RuntimeException('Le calcul de cette potion est trop complexe pour son renforcement. Simplifiez sa formule.',409);return $scaled;
}
function applicationEnhancedItemAction(array $a,?array $snapshot):array {
 if($snapshot===null)return $a;$m=$snapshot['multipliers'];
 if($a['kind']==='damage')foreach($a['components'] as &$p)$p['formula']=applicationScaleItemFormula($p['formula'],$m['damage']);unset($p);
 if($a['kind']==='dot'){$a['formula']=applicationScaleItemFormula($a['formula'],$m['damage']);$a['turns']*=$m['duration'];}
 if($a['kind']==='condition')foreach(['turns','fallbackTurns'] as $k)if(!empty($a['rider'][$k]))$a['rider'][$k]*=$m['duration'];
 if(!empty($a['buffTurns']))$a['buffTurns']*=$m['duration'];
 if(in_array($a['kind'],['immunity','armor-reduction'],true))$a['turns']*=$m['duration'];
 if($a['kind']==='stackable-effect')$a['stacks']*=$m['duration'];
 if($a['kind']==='arbitration-zone')$a['turns']*=$m['duration'];
 if($a['kind']==='stats'){if(!empty($a['duration']['turns']))$a['duration']['turns']*=$m['duration'];if(!empty($a['duration']['shortRests']))$a['duration']['shortRests']*=$m['duration'];}
 return $a;
}
function applicationPreflightItemEnhancement(?array $active,?array $snapshot):void {foreach($active['effects']??[] as $a)applicationEnhancedItemAction($a,$snapshot);}
