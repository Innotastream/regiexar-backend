<?php
declare(strict_types=1);

function activeItemTag(mixed $v):bool{return is_string($v)&&preg_match('/^[a-z][a-z0-9-]{0,59}$/D',$v)===1;}
function activeItemInt(mixed $v,int $min,int $max):bool{return is_int($v)&&$v>=$min&&$v<=$max;}
function activeItemStats():array{return ['force','dexterity','agility','spiritSocial','intelligence','instinct','mentalResistance'];}
function normalizeActiveItem(mixed $v):?array {
 if($v===null)return null;if(!is_array($v)||($v['version']??0)!==1)itemFailure('Ces effets d’objet proviennent d’une version plus récente.');
 if(!is_array($v['effects']??null)||count($v['effects'])>12)itemFailure('Choisissez au maximum douze effets pour cet objet.');$effects=[];
 foreach($v['effects'] as $e){$kind=$e['kind']??'';
  if($kind==='heal-max-die'){if(!activeItemInt($e['percent']??null,1,100))itemFailure('Pourcentage de soin invalide.');$effects[]=['kind'=>$kind,'percent'=>$e['percent']];}
  elseif($kind==='cleanse'){if(!is_array($e['families']??null)||count($e['families'])<1||count($e['families'])>10||array_filter($e['families'],static fn($f)=>!activeItemTag($f)))itemFailure('Familles d’effets invalides.');$effects[]=['kind'=>$kind,'families'=>array_values(array_unique($e['families']))];}
  elseif($kind==='immunity'){if(!activeItemTag($e['family']??null)||!activeItemInt($e['turns']??null,1,999))itemFailure('Durée ou famille d’immunité invalide.');$effects[]=['kind'=>$kind,'family'=>$e['family'],'turns'=>$e['turns']];}
  elseif($kind==='stats'){
   if(!is_array($e['bonuses']??null))itemFailure('Statistiques invalides.');$bonuses=[];foreach($e['bonuses'] as $k=>$n){if(!in_array($k,activeItemStats(),true)||!activeItemInt($n,-100,100))itemFailure('Bonus de statistique invalide.');if($n!==0)$bonuses[$k]=$n;}if(!$bonuses)itemFailure('Renseignez au moins un bonus.');$d=$e['duration']??[];
   if(($d['kind']??'')==='rounds'&&activeItemInt($d['turns']??null,1,999))$duration=['kind'=>'rounds','turns'=>$d['turns']];
   elseif(($d['kind']??'')==='rests'&&activeItemInt($d['shortRests']??null,1,20))$duration=['kind'=>'rests','shortRests'=>$d['shortRests'],'longRest'=>true];else itemFailure('Durée du bonus invalide.');
   $effects[]=['kind'=>$kind,'bonuses'=>$bonuses,'duration'=>$duration];
  }
  elseif($kind==='light'){if(!activeItemInt($e['distance']??null,1,40)||preg_match('/^#[a-f0-9]{6}$/Di',$e['color']??'')!==1)itemFailure('Lumière invalide.');$effects[]=['kind'=>$kind,'distance'=>$e['distance'],'color'=>strtolower($e['color'])];}
  elseif($kind==='message'){if(!is_string($e['text']??null)||trim($e['text'])===''||count(preg_split('//u',$e['text'],-1,PREG_SPLIT_NO_EMPTY))>240||!activeItemInt($e['durationMs']??null,500,30000)||!in_array($e['theme']??'',['neutral','poison','divine','verdant-metal','infernal'],true))itemFailure('Message invalide.');$effects[]=['kind'=>$kind,'text'=>trim($e['text']),'durationMs'=>$e['durationMs'],'theme'=>$e['theme']];}
  else itemFailure('Cet effet d’objet n’est pas pris en charge.');
 }
 if(!empty($v['ingredientTag'])&&!activeItemTag($v['ingredientTag']))itemFailure('Catégorie d’ingrédient invalide.');
 return ['version'=>1,'useLabel'=>itemText($v['useLabel']??'Utiliser',40),'authority'=>($v['authority']??'')==='gm'?'gm':'owner','effects'=>$effects,...(!empty($v['ingredientTag'])?['ingredientTag'=>$v['ingredientTag']]:[])];
}
function applicationEffectFamily(mixed $v):string {
 if(is_array($v)&&!empty($v['family']??$v['effectFamily']??''))return (string)($v['family']??$v['effectFamily']);
 $text=is_string($v)?$v:implode(' ',array_filter([$v['label']??'',$v['name']??'',$v['abilityName']??'',$v['immunityLabel']??'']));
 $text=strtolower(strtr($text,['É'=>'e','é'=>'e','è'=>'e','ê'=>'e','à'=>'a','â'=>'a','ô'=>'o','î'=>'i']));
 return preg_match('/poison|empoison|venin/',$text)?'poison':(preg_match('/saign|hemorrag/',$text)?'bleeding':'');
}
function applicationHasEffectImmunity(array $source,array $state,string $sceneId,string $family):bool {
 foreach($source['conditionImmunities']??[] as $v)if(applicationEffectFamily($v)===$family)return true;$holder=applicationEnhancementHolder($source);
 foreach($state['attackEnhancements']??[] as $e)if(($e['mode']??'')==='immunity'&&($e['family']??'')===$family&&($e['holder']??'')===$holder&&($e['sceneId']??'')===$sceneId&&($e['remainingTurns']??0)>0)return true;return false;
}
function applicationCleanseUnitEffects(array &$state,array &$unit,array $families):void {
 $ids=[];$clean=static fn($v)=>!in_array(applicationEffectFamily($v),$families,true);
 $unit['conditions']=array_values(array_filter($unit['conditions']??[],$clean));
 $cleanMap=static function(array &$map)use(&$ids,$unit,$clean):void{foreach($map['tokens']??[] as $i=>$t)if(unitIdForToken($t)===$unit['id']){$ids[]=$t['id'];$t['conditions']=array_values(array_filter($t['conditions']??[],$clean));$t['condition']=implode(', ',$t['conditions']);$map['tokens'][$i]=$t;}};
 $cleanMap($state['map']);foreach($state['scenes']??[] as $i=>$scene)if(isset($scene['combat']['map'])){$cleanMap($scene['combat']['map']);$state['scenes'][$i]=$scene;}
 foreach(['damageOverTime','pendingDotResolutions'] as $key)$state[$key]=array_values(array_filter($state[$key]??[],static fn($e)=>!in_array($e['targetTokenId']??'',$ids,true)||$clean($e)));
 $holder=($unit['unitKind']??'')==='token'?'token:'.$unit['unitTokenId']:'character:'.$unit['id'];
 $state['attackEnhancements']=array_values(array_filter($state['attackEnhancements']??[],static fn($e)=>($e['holder']??'')!==$holder||$clean($e)));
 if(isset($state['devices'])){$state['devices']['timedConditions']=array_values(array_filter($state['devices']['timedConditions']??[],static fn($e)=>!in_array($e['targetTokenId']??'',$ids,true)||$clean($e)));$state['devices']['pendingRolls']=array_values(array_filter($state['devices']['pendingRolls']??[],static fn($p)=>!in_array($p['effect']['targetTokenId']??'',$ids,true)||$clean($p['effect']['rider']??$p['effect']??[])));}
 foreach($state['abilityExecutions']??[] as $i=>$e)if(($e['status']??'')==='active'&&in_array($e['sourceTokenId']??'',$ids,true)&&!$clean($e))$state['abilityExecutions'][$i]=[...$e,'status'=>'completed','endedByCure'=>true,'revision'=>$e['revision']+1];
}
function applicationApplyActiveItem(array &$state,array $template,array &$unit,?array $token,array $identity,array $context):array {
 $active=normalizeActiveItem($template['active']??null);if(empty($active['effects']))itemFailure('Cet objet est un prérequis de compétence, il ne s’utilise pas directement.',409);
 if($active['authority']==='gm'&&$identity['role']!=='gm')itemFailure('Cette fiole doit être utilisée par le MJ pour son arbitrage.',403);
 $sceneId=$state['activeSceneId'];$holder=($unit['unitKind']??'')==='token'?'token:'.$unit['unitTokenId']:'character:'.$unit['id'];$clock=$state['initiative']??[];$result=['used'=>true,'itemName'=>$template['name'],'healing'=>0,'rolls'=>[],'messages'=>[]];
 foreach($active['effects'] as $a){
  if($a['kind']==='cleanse')applicationCleanseUnitEffects($state,$unit,$a['families']);
  elseif($a['kind']==='heal-max-die'){$sides=max(1,(int)floor(($unit['resources']['maxHp']??0)*$a['percent']/100));$roll=($context['rollDie'])($sides,$template['name'],$unit['name']);$result['rolls'][]=$roll;$old=$unit['resources']['hp'];$unit['resources']['hp']=min($unit['resources']['maxHp'],$old+$roll['total']);$result['healing']+=$unit['resources']['hp']-$old;}
  elseif(in_array($a['kind'],['stats','immunity'],true)){
   $d=$a['kind']==='stats'?$a['duration']:['kind'=>'rounds','turns'=>$a['turns']];$mode=$a['kind']==='stats'?'stats':'immunity';
   $e=['id'=>($context['id'])('item-effect'),'abilityId'=>'item-'.$template['id'],'holder'=>$holder,'label'=>$template['name'],'sceneId'=>$sceneId,'targetTokenId'=>$token['id']??'','casterTokenId'=>$token['id']??'','abilityImage'=>$template['image']??'','combatId'=>!empty($clock['active'])&&$d['kind']==='rounds'?($clock['combatId']??''):'','lastTurnSerial'=>$clock['turnSerial']??0,'mode'=>$mode,'turns'=>$d['turns']??1,'remainingTurns'=>$d['turns']??1,'stackGroup'=>'item-'.$template['id'],...($mode==='stats'?['bonuses'=>$a['bonuses']]:['family'=>$a['family']]),...($d['kind']==='rests'?['expiry'=>'rests','remainingShortRests'=>$d['shortRests']]:[])];
   $effects=array_values(array_filter($state['attackEnhancements']??[],static fn($old)=>($old['holder']??'')!==$holder||($old['stackGroup']??'')!==$e['stackGroup']||($old['mode']??'')!==$mode));if(count($effects)>=100)itemFailure('Trop d’effets actifs.',409);$effects[]=$e;$state['attackEnhancements']=$effects;
  }
  elseif($a['kind']==='light'){
   if(!$token)itemFailure('Placez le pion sur la carte pour allumer le flambeau.',409);$layerId=$token['layerId']??$state['map']['activeLayerId']??'ground';$map=&$state['map'];$layer=&$map;if(isset($map['layers'][$layerId]))$layer=&$map['layers'][$layerId];$lights=$layer['lights']??[];if(count($lights)>=40)itemFailure('Ce niveau contient déjà trop de lumières.',409);
   $light=['id'=>($context['id'])('light'),'name'=>$template['name'].' allumé','x'=>$token['x'],'y'=>$token['y'],'visionDistance'=>$a['distance'],'enabled'=>true,'portable'=>true,'carrierTokenId'=>null,'color'=>$a['color'],'icon'=>'torch'];$lights[]=$light;$layer['lights']=$lights;if($layerId===($map['activeLayerId']??'ground'))$map['lights']=$lights;$result['lightId']=$light['id'];unset($layer,$map);
  }
  elseif($a['kind']==='message')$result['messages'][]=$a;
 }
 return $result;
}
function applicationItemEffectRest(array &$state,array $ids,string $kind):array {
 $holders=array_map(static fn($id)=>str_starts_with($id,'unit_')?'token:'.substr($id,5):'character:'.$id,$ids);$next=[];$expired=0;
 foreach($state['attackEnhancements']??[] as $e){if(($e['expiry']??'')!=='rests'||!in_array($e['holder'],$holders,true)){$next[]=$e;continue;}$left=$kind==='long'?0:($e['remainingShortRests']??1)-1;if($left<=0)$expired++;else{$e['remainingShortRests']=$left;$next[]=$e;}}
 $state['attackEnhancements']=$next;return ['expired'=>$expired];
}
function applicationAttackPoisonStacks(array &$state,array &$attack,array $source,array $target,callable $id):void {
 if($source===[])return;
 if(!empty($attack['poisonStacksProcessed'])||!empty($attack['echoParentId'])||empty($attack['hit']['outcome']['success'])||!in_array($attack['status']??'',['applied','blocked'],true))return;$attack['poisonStacksProcessed']=true;
 if(applicationHasEffectImmunity($target,$state,$attack['sceneId'],'poison'))return;$clock=$state['initiative']??[];$key=!empty($clock['active'])?($clock['combatId']??'').':'.($clock['turnSerial']??0):'free:'.($state['devices']['manualTurns'][$attack['sceneId']]??0);$holder=applicationEnhancementHolder($source);
 foreach($state['attackEnhancements']??[] as $i=>$e){if(($e['mode']??'')!=='on-attack-dot'||$e['holder']!==$holder||$e['sceneId']!==$attack['sceneId']||$e['remainingTurns']<=0||($e['appliedTargets'][$target['id']]??'')===$key)continue;
  if(count($state['damageOverTime']??[])>=100)itemFailure('La liste des dégâts périodiques est pleine.',409);$d=$e['dot'];$state['damageOverTime'][]=['id'=>$id('dot'),'sceneId'=>$attack['sceneId'],'combatId'=>!empty($clock['active'])?($clock['combatId']??''):'','sourceName'=>$source['name']??'','sourceTokenId'=>$source['id'],'targetTokenId'=>$target['id'],'label'=>$d['label'],'family'=>'poison','formula'=>$d['formula'],'damageType'=>'ignore','remainingTurns'=>$d['turns'],'lastTurnSerial'=>$clock['turnSerial']??0];$state['attackEnhancements'][$i]['appliedTargets'][$target['id']]=$key;
 }
}
