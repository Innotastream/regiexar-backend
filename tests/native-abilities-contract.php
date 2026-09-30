<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/v1/domains.php';
require_once __DIR__ . '/../api/v1/online.php';
function nativeCheck(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
$source = ['id'=>'caster','x'=>20,'y'=>50,'layerId'=>'ground','stats'=>[['id'=>'character-stat-agility','value'=>70]]];
$target = ['id'=>'target','x'=>24,'y'=>50,'layerId'=>'ground','conditions'=>['Saignement']];
$ability = ['id'=>'ability','effect'=>'complex','workflow'=>['version'=>7,'steps'=>[['id'=>'orbs','type'=>'counter','maximum'=>3,'initial'=>0,'gainAmount'=>1,'gainTrigger'=>'hp-loss-or-condition','gainCondition'=>'Hémorragie','radiusCells'=>4,'combatPersistent'=>true,'spendOnSourceTurn'=>true,'visual'=>'orbs','spendOptions'=>[['id'=>'shield','label'=>'Durcir','cost'=>1,'effect'=>['kind'=>'guard','percent'=>20,'stackable'=>true,'trigger'=>'damage','persistAfterEnd'=>true]]]]]]];
$execution = createApplicationComplexAbilityExecution(['id'=>'execution','sceneId'=>'scene','sourceTokenId'=>'caster','controllerAccountId'=>'owner','combatId'=>'combat','ability'=>$ability]);
$gain = ['sceneId'=>'scene','layerId'=>'ground','combatId'=>'combat','target'=>$target,'tokens'=>[$source,$target],'map'=>['naturalWidth'=>1000,'naturalHeight'=>1000,'gridSize'=>10]];
$execution=applicationComplexAbilityGainBleedingCharges([$execution],[...$gain,'hpLost'=>5,'addedConditions'=>['Hémorragie']])[0];
nativeCheck($execution['stepStates']['orbs']['value']===1,'A wound plus new hemorrhage is one event, including canonical aliases.');
$execution=applicationComplexAbilityGainBleedingCharges([$execution],[...$gain,'addedConditions'=>['Hémorragie']])[0];
nativeCheck($execution['stepStates']['orbs']['value']===2,'A new condition without HP damage gains a charge.');
nativeCheck(applicationComplexAbilityGainBleedingCharges([$execution],$gain)[0]['stepStates']['orbs']['value']===2,'No passive regeneration.');
nativeCheck(applicationComplexAbilityGainBleedingCharges([$execution],[...$gain,'hpLost'=>5,'target'=>[...$target,'x'=>24.01]])[0]['stepStates']['orbs']['value']===2,'Four cells is the exact radius.');
$command=['action'=>'counter-spend','expectedRevision'=>$execution['revision'],'optionId'=>'shield','targetTokenId'=>'target'];
$context=['actor'=>['id'=>'owner','role'=>'player'],'tokens'=>[$source,$target],'combatActive'=>true,'currentTokenId'=>'target'];
try { applyApplicationComplexAbilityCommand($execution,$command,$context);throw new RuntimeException('Other turns must refuse spending.'); }
catch(ApplicationComplexAbilityException $e){nativeCheck($e->errorCode==='complex_ability_source_turn_required','Only the caster turn can consume orbs.');}
$execution=applyApplicationComplexAbilityCommand($execution,$command,[...$context,'currentTokenId'=>'caster']);
nativeCheck($execution['stepStates']['orbs']['value']===1,'Spending consumes a charge without recasting.');
nativeCheck(count(applicationComplexAbilityPlacedMarkers([$execution],'scene','ground',[$source,$target]))===1,'Exactly one visual orb remains.');
$guards=[['sceneId'=>'scene','targetTokenId'=>'target','combatId'=>'combat','percent'=>20,'stackGroup'=>'spell'],['sceneId'=>'scene','targetTokenId'=>'target','combatId'=>'combat','percent'=>20,'stackGroup'=>'spell']];
$markers=applicationComplexAbilityPlacedMarkers([],'scene','ground',[$source,$target],$guards,['active'=>true,'combatId'=>'combat']);
nativeCheck($markers[0]['percent']===40,'Public guard presentation reflects the additive stack.');
$castAbility=['id'=>'once','usesPerCombat'=>1,'castingStatId'=>''];
$caster=[...$source,'hp'=>100,'maxHp'=>100,'mana'=>0,'maxMana'=>0];
$initiative=['active'=>true,'combatId'=>'combat'];
$plan=applicationAbilityCastingPlan($castAbility,$caster,'scene',$initiative,[]);
nativeCheck($plan['combatUseLimit']===1,'Combat quota is independent from rest recharge.');
try {applicationAbilityCastingPlan($castAbility,$caster,'scene',$initiative,[['sceneId'=>'scene','abilityId'=>'once','characterId'=>'','tokenId'=>'caster','combatId'=>'combat','combatUseCount'=>1,'cooldownActive'=>false]]);throw new RuntimeException('Quota must block a second activation.');}
catch(RuntimeException $e){nativeCheck($e->getMessage()==='ability_combat_exhausted','Quota survives cooldown completion.');}
$orbGuard=['percent'=>20,'damageTypes'=>['physical','magical'],'armorStacking'=>'add'];
foreach([['physical',30,50],['magical',0,80],['magical',30,50],['ignore',0,100]] as [$type,$armor,$expected]){
    $basis=['rawDamage'=>100,'finalDamage'=>100-$armor,'armorPercent'=>$armor,'damageType'=>$type];
    $covers=applicationGuardCoversDamage($orbGuard,$basis);
    $mitigated=applicationMitigateGuardedDamage(100-$armor,$covers?[$orbGuard]:[],$basis);
    nativeCheck($mitigated['damage']===$expected,'Orb percentages add to the existing armor or magical exception.');
    nativeCheck($covers===($type!=='ignore'),'Ignore damage leaves the physical/magical protection waiting.');
}
$mixed=['rawDamage'=>300,'finalDamage'=>270,'components'=>[['type'=>'physical','rawDamage'=>100,'finalDamage'=>70,'armorPercent'=>30],['type'=>'magical','rawDamage'=>100,'finalDamage'=>100,'armorPercent'=>0],['type'=>'ignore','rawDamage'=>100,'finalDamage'=>100,'armorPercent'=>0]]];
nativeCheck(applicationMitigateGuardedDamage(270,[$orbGuard],$mixed)['damage']===230,'Mixed damage mitigates only eligible components.');
nativeCheck(applicationMitigateGuardedDamage(270,array_fill(0,6,$orbGuard),$mixed)['damage']===100,'At most 100 percent protection, ignore remains intact.');
$reflected=applicationReflectedDamageBasis($mixed,'physical',135);
nativeCheck(array_sum(array_column($reflected['components'],'finalDamage'))===135,'Reflection retains its total and damage types.');
nativeCheck(applicationMitigateGuardedDamage(135,[$orbGuard],$reflected)['damage']===118,'Reflection respects component protection without inventing armor.');
$visualState=['activeSceneId'=>'scene','characters'=>[],'map'=>['activeLayerId'=>'ground','naturalWidth'=>1000,'naturalHeight'=>1000,'gridSize'=>10,'vision'=>['enabled'=>false],'lightingMode'=>'bright','tokens'=>[$source,$target]],'initiative'=>['active'=>true,'combatId'=>'combat'],'abilityExecutions'=>[$execution],'nextAttackGuards'=>$guards];
$visualView=publicPlayerState($visualState,['id'=>'viewer','display_name'=>'Viewer'],[]);
nativeCheck(count($visualView['abilityMarkers'])===2,'Visible orb and guard appear to another player, using visibility IDs rather than values.');
$visualState['map']['tokens'][0]['hidden']=true;
$visualView=publicPlayerState($visualState,['id'=>'viewer','display_name'=>'Viewer'],[]);
nativeCheck(count(array_filter($visualView['abilityMarkers'],static fn(array $marker): bool=>($marker['visual'] ?? '')==='orb'))===0,'Hidden caster orbs do not leak into another player view.');
echo "native-abilities-contract: ok\n";
