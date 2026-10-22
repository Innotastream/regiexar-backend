<?php
declare(strict_types=1);
function applicationItemPayload(array &$state,array $template,array &$unit,array $token,array $a,int $index,array $context,array &$result):void {
 $scene=$state['activeSceneId'];$clock=$state['initiative']??[];$id=$context['id'];
 if(($a['timing']??'')==='on-hit'){
  if($a['kind']==='damage')foreach($a['components'] as $n=>$part)applicationActivateAttackEnhancement($state,['id'=>'item-'.$template['id'].'-'.$index.'-'.$n,'name'=>$template['name'],'image'=>$template['image']??'','attackEnhancement'=>['mode'=>'echo','formula'=>$part['formula'],'allyFormula'=>$part['formula'],'damageType'=>$part['type'],'turns'=>$a['buffTurns'],'trigger'=>$a['trigger'],'requiresHit'=>true]],$token,$scene,$clock,$id('item-buff'),$token);
  else applicationActivateAttackEnhancement($state,['id'=>'item-'.$template['id'].'-'.$index,'name'=>$template['name'],'image'=>$template['image']??'','attackEnhancement'=>['mode'=>'on-hit-effects','turns'=>$a['buffTurns'],'trigger'=>$a['trigger'],'payload'=>$a]],$token,$scene,$clock,$id('item-buff'),$token);return;
 }
 $family=applicationEffectFamily($a);if($family!==''&&applicationHasEffectImmunity($token,$state,$scene,$family))return;
 if($a['kind']==='damage'){
  $rules=[...$token,...$unit['resources']];foreach(['armor','armorCategory','magicArmor','magicArmorCategory'] as $key)if(isset($unit[$key]))$rules[$key]=$unit[$key];
  $rolled=onlineRollAttackDamage(['damageComponents'=>$a['components']],$rules,static fn($formula)=>($context['rollFormula'])($formula,$template['name'],$unit['name']),$state);$damage=$rolled['damage'];
  $guards=[];$first=null;foreach($state['nextAttackGuards']??[] as $g)if($g['sceneId']===$scene&&$g['targetTokenId']===$token['id']&&!empty($clock['active'])&&($g['combatId']??'')===($clock['combatId']??'')&&applicationGuardCoversDamage($g,$damage)){if($first===null)$first=$g;if($g===$first||!empty($first['stackGroup'])&&($g['stackGroup']??'')===$first['stackGroup'])$guards[]=$g;}
  $reduced=applicationMitigateGuardedDamage($damage['finalDamage'],$guards,$damage);$state['nextAttackGuards']=array_values(array_filter($state['nextAttackGuards']??[],static fn($g)=>!in_array($g,$guards,true)));
  $before=$unit['resources']['hp'];$unit['resources']['hp']=max(-1000000000,min($unit['resources']['maxHp'],$before-$reduced['damage']));persistUnitResources($state,$unit);$applied=$before-$unit['resources']['hp'];
  $roll=isset($context['rollRecord'])?($context['rollRecord'])($rolled['rolled'],$template['name'],$unit['name']):[...$rolled['rolled'],'id'=>$id('roll'),'label'=>$template['name'],'characterName'=>$unit['name']];
  $roll['damageMitigation']=[...$damage,'finalDamage'=>$reduced['damage']];$roll['mapEvent']=['kind'=>'damage','applied'=>true,'value'=>$applied,'label'=>$template['name'],'attackId'=>$id('item-damage'),'sceneId'=>$scene,'layerId'=>$token['layerId']??'ground','tokenId'=>$token['id'],'targetTokenId'=>$token['id'],'anchorTokenId'=>$token['id']];$result['rolls'][]=$roll;$result['damage']=($result['damage']??0)+$applied;
 }elseif($a['kind']==='dot'){
  if(count($state['damageOverTime']??[])>=100)itemFailure('La liste des dégâts périodiques est pleine.',409);
  $state['damageOverTime'][]=['id'=>$id('dot'),'sceneId'=>$scene,'combatId'=>!empty($clock['active'])?($clock['combatId']??''):'','sourceName'=>$template['name'],'targetTokenId'=>$token['id'],'label'=>$a['label'],'formula'=>$a['formula'],'damageType'=>$a['damageType'],...(!empty($a['family'])?['family'=>$a['family']]:[]),'remainingTurns'=>$a['turns'],'lastTurnSerial'=>(int)($clock['turnSerial']??0),'lastManualTurn'=>(int)($state['devices']['manualTurns'][$scene]??0)];
 }elseif($a['kind']==='condition')onlineDeviceTimedCondition($state,['sceneId'=>$scene,'targetTokenId'=>$token['id'],'sourceTokenId'=>$context['sourceTokenId']??'','rider'=>$a['rider'],'resisted'=>false]);
}
function validateItemSummonPlacement(array $state,array $actions,array $point):void {
 $map=$state['map'];$level=$map['layers'][$point['layerId']??$map['activeLayerId']??'ground']??[];
 $w=$level['naturalWidth']??$map['naturalWidth']??1600;$h=$level['naturalHeight']??$map['naturalHeight']??900;
 foreach($actions as $action){
  $model=null;foreach($state['tokenLibrary']??[] as $t)if($t['id']===$action['templateId']){$model=$t;break;}
  if(!$model)itemFailure('Le modèle de cette invocation n’existe plus dans le bestiaire.',409);
  $radius=max(10,min(220,$model['size']??40))/2;$x=$point['x']*$w/100;$y=$point['y']*$h/100;
  if($x<$radius||$x>$w-$radius||$y<$radius||$y>$h-$radius)itemFailure('Le pion invoqué doit rester entièrement dans la carte.',409);
  $checked=applicationResolveGmTokenPlacement($level['walls']??$map['walls']??null,$point,$point,$model['size']??40,$w,$h);
  if($checked['blocked'])itemFailure('Le pion invoqué doit arriver entièrement hors des murs.',409);
 }
}
function applicationSummonItemToken(array &$state,array $a,array $point,string $ownerId,string $id):array {
 $model=null;foreach($state['tokenLibrary']??[] as $t)if($t['id']===$a['templateId']){$model=$t;break;}if(!$model)itemFailure('Le modèle de cette invocation n’existe plus dans le bestiaire.',409);
 if(count($state['map']['tokens'])>=2000)itemFailure('La limite de pions de cette scène est atteinte.',429);
 $now=(int)floor(microtime(true)*1000);$token=[...$model,'id'=>$id,'libraryTemplateId'=>$model['id'],'characterId'=>null,'linkedTokenId'=>null,'followCharacter'=>false,'usesCharacterSheet'=>false,'rollUsesCharacterSheet'=>false,'independent'=>true,'controllerPlayerId'=>!empty($a['controllable'])&&empty($a['nonCombatant'])?($ownerId?:null):null,'x'=>$point['x'],'y'=>$point['y'],'layerId'=>$point['layerId']??'ground','initiative'=>null,'hidden'=>false,'artworkOnly'=>true,'nonCombatant'=>!empty($a['nonCombatant']),'immovable'=>!empty($a['nonCombatant']),'_updatedAt'=>$now,'_movedAt'=>$now];$state['map']['tokens'][]=$token;return $token;
}
