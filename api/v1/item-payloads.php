<?php
declare(strict_types=1);
require_once __DIR__.'/token-map-scale.php';
require_once __DIR__.'/impact-bleeding.php';
function applicationItemPayload(array &$state,array $template,array &$unit,array $token,array $a,int $index,array $context,array &$result):void {
 $scene=$state['activeSceneId'];$clock=$state['initiative']??[];$id=$context['id'];
 $family=applicationEffectFamily($a)?:applicationEffectFamily($template);
 $legacyElement=applicationDamageElement($family);
 if(($a['timing']??'')==='on-hit'){
  if($a['kind']==='damage')foreach($a['components'] as $n=>$part){
   $element=applicationDamageElement($part['element']??$part['type'])??(count($a['components'])===1?$legacyElement:null);
   applicationActivateAttackEnhancement($state,['id'=>'item-'.$template['id'].'-'.$index.'-'.$n,'name'=>$template['name'],'image'=>$template['image']??'','attackEnhancement'=>['mode'=>'echo','formula'=>$part['formula'],'allyFormula'=>$part['formula'],'damageType'=>$part['type'],...($element!==null?['damageElement'=>$element]:[]),'turns'=>$a['buffTurns'],'trigger'=>$a['trigger'],'requiresHit'=>true]],$token,$scene,$clock,$id('item-buff'),$token);
  }
  else applicationActivateAttackEnhancement($state,['id'=>'item-'.$template['id'].'-'.$index,'name'=>$template['name'],'image'=>$template['image']??'','attackEnhancement'=>['mode'=>'on-hit-effects','turns'=>$a['buffTurns'],'trigger'=>$a['trigger'],'payload'=>$a]],$token,$scene,$clock,$id('item-buff'),$token);return;
 }
 if($a['kind']!=='damage'&&($effectFamily=applicationEffectFamily($a))!==''&&applicationHasEffectImmunity($token,$state,$scene,$effectFamily))return;
 if($a['kind']==='damage'){
  $components=$a['components'];
  foreach ($components as &$part) {
   if ($legacyElement!==null && applicationDamageElement($part['element']??$part['type'])===null
       && ($part['type']!=='ignore'||count($components)===1)) $part['element']=$legacyElement;
  }
  unset($part);
  $rules=[...$token,...$unit['resources']];foreach(['armor','armorCategory','magicArmor','magicArmorCategory','elementalResistances'] as $key)if(isset($unit[$key]))$rules[$key]=$unit[$key];
  $rolled=onlineRollAttackDamage(['damageComponents'=>$components],$rules,static fn($formula)=>($context['rollFormula'])($formula,$template['name'],$unit['name']),$state);$damage=$rolled['damage'];
  $guards=[];$indices=[];$first=null;foreach($state['nextAttackGuards']??[] as $i=>$g)if($g['sceneId']===$scene&&$g['targetTokenId']===$token['id']&&(($g['lifecycle']??'')==='status'||!empty($clock['active'])&&($g['combatId']??'')===($clock['combatId']??''))&&applicationGuardCoversDamage($g,$damage)){if($first===null)$first=$g;if($g===$first||!empty($first['stackGroup'])&&($g['stackGroup']??'')===$first['stackGroup']){$guards[]=$g;$indices[]=$i;}}
  $guarded=applicationMitigateGuardedDamage($damage['finalDamage'],$guards,$damage);$passive=applicationPassiveDamageReduction($unit,$guarded['damage'],['family'=>$family,'sourceItemKind'=>$template['kind']??''],$state);$reduced=['damage'=>$passive['damage']];$state['nextAttackGuards']=applicationConsumeGuardIndices($state['nextAttackGuards']??[],$indices);
  $before=$unit['resources']['hp'];$next=max(-1000000000,min($unit['resources']['maxHp'],$before-$reduced['damage']));
  if($next!==$before){$unit['resources']['hp']=$next;persistUnitResources($state,$unit);}
  $applied=$before-$next;
  if (unitUsesCharacterSheet($token)) {
   $rules['elementalResistances']=$unit['elementalResistances']??[];
   $rules['conditionImmunities']=$unit['conditionImmunities']??[];
   $rules['healthOverride']=onlineManualDeath($unit)?'dead':null;
  }
  if (applicationQualifiesForImpactBleeding($rules,$before,$next,$unit['resources']['maxHp'],applicationUnitControllerId($token,$state)!=='')) {
   $impactPulseId=$id('resource');
   if (applicationApplyImpactBleeding($state,$rules,$scene,$clock,(int)($state['devices']['manualTurns'][$scene]??0),$impactPulseId)) {
    foreach ($state['map']['tokens'] as $targetIndex => $mapToken) {
     if (($mapToken['id']??'') !== $token['id']) continue;
     $state['map']['tokens'][$targetIndex]['resourcePulse']=[
      'id'=>$impactPulseId,'resource'=>'hp','delta'=>-$applied,'at'=>(int)floor(microtime(true)*1000),'impactBleed'=>true,
     ];
     break;
    }
   }
  }
  $roll=isset($context['rollRecord'])?($context['rollRecord'])($rolled['rolled'],$template['name'],$unit['name']):[...$rolled['rolled'],'id'=>$id('roll'),'label'=>$template['name'],'characterName'=>$unit['name']];
  $roll['damageMitigation']=[...$damage,'finalDamage'=>$reduced['damage'],'passivePercent'=>$passive['percent'],'passivePreventedDamage'=>$passive['prevented']];$roll['mapEvent']=['kind'=>'damage','applied'=>$applied>0,'value'=>$applied,'label'=>$template['name'],'attackId'=>$id('item-damage'),'sceneId'=>$scene,'layerId'=>$token['layerId']??'ground','tokenId'=>$token['id'],'targetTokenId'=>$token['id'],'sourceTokenId'=>($context['sourceTokenId']??'')?:$token['id'],'anchorTokenId'=>$token['id']];$result['rolls'][]=$roll;$result['damage']=($result['damage']??0)+$applied;
 }elseif($a['kind']==='dot'){
  if (applicationElementalPeriodicDamageSuppressed($unit, $a)) return;
  $turns=applicationPassiveEffectTurns($unit,$a['turns'],['family'=>applicationEffectFamily($a),'sourceItemKind'=>$template['kind']??'','harmful'=>true],$state);if($turns<=0)return;
  $type = $a['damageType']==='ignore' && applicationPeriodicDamageElement($a)==='poison' ? 'poison' : $a['damageType'];
  $state['damageOverTime'][]=['id'=>$id('dot'),'sceneId'=>$scene,'combatId'=>!empty($clock['active'])?($clock['combatId']??''):'','sourceName'=>$template['name'],'targetTokenId'=>$token['id'],'label'=>$a['label'],'formula'=>$a['formula'],'damageType'=>$type,...applicationOptionalDamageElement($a),...(!empty($a['family'])?['family'=>$a['family']]:[]),'remainingTurns'=>$turns,...(($template['kind']??'')==='potion'?['sourceItemKind'=>'potion']:[]),'lastTurnSerial'=>(int)($clock['turnSerial']??0),'lastManualTurn'=>(int)($state['devices']['manualTurns'][$scene]??0)];
 }elseif($a['kind']==='condition')onlineDeviceTimedCondition($state,['sceneId'=>$scene,'targetTokenId'=>$token['id'],'sourceTokenId'=>$context['sourceTokenId']??'','rider'=>$a['rider'],'resisted'=>false,'sourceItemKind'=>$template['kind']??'']);
}
function validateItemSummonPlacement(array $state,array $actions,array $point):void {
 $map=$state['map'];$level=$map['layers'][$point['layerId']??$map['activeLayerId']??'ground']??[];
 $w=$level['naturalWidth']??$map['naturalWidth']??1600;$h=$level['naturalHeight']??$map['naturalHeight']??900;
 foreach($actions as $action){
  $model=null;foreach($state['tokenLibrary']??[] as $t)if($t['id']===$action['templateId']){$model=$t;break;}
  if(!$model)itemFailure('Le modèle de cette invocation n’existe plus dans le bestiaire.',409);
  $model=applicationScaleUnitForMap($model,$map,$point['layerId']??$map['activeLayerId']??'ground');
  $radius=max(10,min(1000,$model['size']??40))/2;$x=$point['x']*$w/100;$y=$point['y']*$h/100;
  if($x<$radius||$x>$w-$radius||$y<$radius||$y>$h-$radius)itemFailure('Le pion invoqué doit rester entièrement dans la carte.',409);
  $checked=applicationResolveGmTokenPlacement($level['walls']??$map['walls']??null,$point,$point,$model['size']??40,$w,$h);
  if($checked['blocked'])itemFailure('Le pion invoqué doit arriver entièrement hors des murs.',409);
 }
}
function applicationSummonItemToken(array &$state,array $a,array $point,string $ownerId,string $id):array {
 $model=null;foreach($state['tokenLibrary']??[] as $t)if($t['id']===$a['templateId']){$model=$t;break;}if(!$model)itemFailure('Le modèle de cette invocation n’existe plus dans le bestiaire.',409);
 if(count($state['map']['tokens'])>=2000)itemFailure('La limite de pions de cette scène est atteinte.',429);
 $model=applicationScaleUnitForMap($model,$state['map'],$point['layerId']??'ground');
 $now=(int)floor(microtime(true)*1000);$token=[...$model,'id'=>$id,'libraryTemplateId'=>$model['id'],'characterId'=>null,'linkedTokenId'=>null,'followCharacter'=>false,'usesCharacterSheet'=>false,'rollUsesCharacterSheet'=>false,'independent'=>true,'controllerPlayerId'=>!empty($a['controllable'])&&empty($a['nonCombatant'])?($ownerId?:null):null,'x'=>$point['x'],'y'=>$point['y'],'layerId'=>$point['layerId']??'ground','initiative'=>null,'hidden'=>false,'artworkOnly'=>true,'nonCombatant'=>!empty($a['nonCombatant']),'immovable'=>!empty($a['nonCombatant']),'_updatedAt'=>$now,'_movedAt'=>$now];$state['map']['tokens'][]=$token;return $token;
}
