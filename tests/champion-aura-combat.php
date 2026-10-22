<?php
declare(strict_types=1);
require_once __DIR__.'/../api/v1/domains.php';
require_once __DIR__.'/../api/v1/online.php';
$checks=0;
function checkChampion(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);$GLOBALS['checks']++;}
$scene='scene';$source=['id'=>'caster','characterId'=>'sheet','followCharacter'=>true,'controllerPlayerId'=>'owner','x'=>20,'y'=>50,'size'=>40,'hp'=>10,'maxHp'=>100,'mana'=>0,'maxMana'=>0,'layerId'=>'ground'];
$enemy=['id'=>'enemy','x'=>30,'y'=>50,'size'=>40,'hp'=>100,'maxHp'=>100,'armor'=>20,'magicArmor'=>50,'layerId'=>'ground'];
$ally=[...$source,'id'=>'ally','characterId'=>'other-sheet','x'=>40];
$clock=['active'=>true,'combatId'=>'combat','turnSerial'=>1,'order'=>['caster','enemy'],'currentIndex'=>0];
$weapon=['id'=>'chain','formula'=>'1d50+20','damageType'=>'physical','reach'=>'reach'];
$champion=['id'=>'low-hp','name'=>'Buff de recette','effect'=>'enhancement','castingStatId'=>'','fatigueCost'=>5,'activationHpMaxPercent'=>10,'attackEnhancement'=>['mode'=>'bonus','formula'=>'30','damageType'=>'inherit','turns'=>5,'target'=>'self','trigger'=>'all-attacks']];
$stigma=['id'=>'stigma','name'=>'Bénédiction de recette','effect'=>'enhancement','castingStatId'=>'','fatigueCost'=>10,'restRecharge'=>'long','usesPerRest'=>1,'attackEnhancement'=>['mode'=>'bonus','formula'=>'15','damageType'=>'inherit','turns'=>5,'target'=>'self','bonuses'=>['force'=>15,'agility'=>15],'criticalFailureAsFailure'=>true]];
$aura=['id'=>'circle','name'=>'Cercle de recette','effect'=>'enhancement','castingStatId'=>'','fatigueCost'=>10,'restRecharge'=>'long','usesPerRest'=>1,'attackEnhancement'=>['mode'=>'bonus','formula'=>'10','damageType'=>'inherit','turns'=>5,'target'=>'self','trigger'=>'all-attacks','bonuses'=>['physicalResistance'=>10,'magicalResistance'=>10,'attackResultBonus'=>10],'aura'=>['radiusCells'=>8,'affectCaster'=>false,'affectAllies'=>true,'affectEnemies'=>false]]];
$state=['activeSceneId'=>$scene,'map'=>['naturalWidth'=>1000,'naturalHeight'=>1000,'gridSize'=>50,'tokens'=>[$source,$enemy,$ally]],'attackEnhancements'=>[]];
foreach([$champion,$stigma,$aura] as $ability){checkChampion(validApplicationAbilityEffects($ability),'Definition accepted');checkChampion(applicationAbilityEffectFields($ability)['attackEnhancement']==$ability['attackEnhancement'],'Definition roundtrip');}
foreach([1,10] as $hp)checkChampion(applicationAbilityActivationHealthReason($champion,[...$source,'hp'=>$hp])==='','HP accepted');
foreach([10.001,11,100] as $hp)checkChampion(applicationAbilityActivationHealthReason($champion,[...$source,'hp'=>$hp])!=='','HP blocked');
checkChampion(applicationAbilityActivationHealthReason($champion,[...$source,'maxHp'=>0])!=='','Invalid max HP blocked');
applicationActivateAttackEnhancement($state,$champion,$source,$scene,$clock,'champion');
applicationActivateAttackEnhancement($state,$stigma,$source,$scene,$clock,'stigma');
checkChampion(applicationEnhancedBasicAttack($weapon,$source,$state,$scene)['formula']==='1d50+20+30+15','Weapon bonuses combine once');
foreach(['physical','magical','ignore'] as $type){$spell=applicationEnhancedAttackDamage(['formula'=>'1d6','damageType'=>$type],$source,$state,$scene);checkChampion($spell['formula']==='1d6+30'&&$spell['damageType']===$type,'Spell bonus inherits type, no weapon-only bonus');}
checkChampion(applicationEnhancedBasicAttack($weapon,[...$source,'followCharacter'=>false],$state,$scene)===$weapon,'Clone has no inherited personal buff');
checkChampion(applicationRollCriticalFailureProtected($source,$state,$scene),'Protection on actual unit');
checkChampion(!applicationRollCriticalFailureProtected([...$source,'followCharacter'=>false],$state,$scene),'No protection leak to clone');
foreach([66,77,88,99,100] as $raw)foreach([1,65,100] as $threshold){$o=classifyOnlineD100Outcome($raw,$threshold,0,0,true,false,true);checkChampion($o['code']==='failure'&&!$o['success']&&$o['raw']===$raw&&!isset($o['requiresGmValidation'])&&!empty($o['criticalFailurePrevented']),'Critical becomes ordinary failure with original raw');}
foreach([1,11,22,33,44,55] as $raw)checkChampion(classifyOnlineD100Outcome($raw,100,0,0,true,false,true)===classifyOnlineD100Outcome($raw,100),'Remarkable successes unchanged');
checkChampion(applicationManualD100Outcome(66,'critical-failure',true)['code']==='failure','Manual result protection');
applicationActivateAttackEnhancement($state,$aura,$source,$scene,$clock,'circle');
checkChampion(applicationAttackResultBonus($source,$state,$scene)===0,'Caster excluded from aura');
checkChampion(applicationAttackResultBonus($ally,$state,$scene)===10,'Ally inside');
checkChampion(applicationAttackResultBonus($enemy,$state,$scene)===0,'Enemy excluded');
checkChampion(applicationAttackResultBonus([...$ally,'x'=>64],$state,$scene)===10,'Recipient edge exactly inside');
checkChampion(applicationAttackResultBonus([...$ally,'x'=>64.001],$state,$scene)===0,'Outside immediately loses buff');
checkChampion(applicationAttackResultBonus([...$ally,'layerId'=>'upper'],$state,$scene)===0,'Other level excluded');
checkChampion(applicationAttackResultBonus($ally,$state,$scene)===10,'Returning restores buff');
checkChampion(applicationActiveAttackEnhancements([],$state,$scene)===[],'Synthetic effects without source cannot gain an aura');
$poisonState=$state;$poisonState['initiative']=$clock;$poison=['id'=>'poison-aura','name'=>'Poison de recette','attackEnhancement'=>['mode'=>'on-attack-dot','turns'=>5,'dot'=>['label'=>'Venin de recette','formula'=>'1d2','turns'=>3],'aura'=>$aura['attackEnhancement']['aura']]];applicationActivateAttackEnhancement($poisonState,$poison,$source,$scene,$clock,'poison-aura');
$strike=['sceneId'=>$scene,'status'=>'applied','hit'=>['outcome'=>['success'=>true]]];$first=$strike;applicationAttackPoisonStacks($poisonState,$first,$ally,$enemy,static fn($prefix)=>$prefix.randomToken(4));$again=$strike;applicationAttackPoisonStacks($poisonState,$again,$ally,$enemy,static fn($prefix)=>$prefix.randomToken(4));
checkChampion(count($poisonState['damageOverTime']??[])===1,'Aura poison stores one charge per target and turn');
$poisonState['initiative']['turnSerial']=2;$outsideStrike=$strike;applicationAttackPoisonStacks($poisonState,$outsideStrike,[...$ally,'x'=>99],$enemy,static fn($prefix)=>$prefix.randomToken(4));checkChampion(count($poisonState['damageOverTime'])===1,'Outside aura cannot apply poison');
$poisonState['initiative']['turnSerial']=3;$returnedStrike=$strike;applicationAttackPoisonStacks($poisonState,$returnedStrike,$ally,$enemy,static fn($prefix)=>$prefix.randomToken(4));checkChampion(count($poisonState['damageOverTime'])===2&&$poisonState['damageOverTime'][0]['remainingTurns']===3,'Returning applies fresh independent charge');
$initiativeState=$state;$initiativeAura=$aura;$initiativeAura['id']='initiative-circle';$initiativeAura['attackEnhancement']['bonuses']=['initiativeBonus'=>12];applicationActivateAttackEnhancement($initiativeState,$initiativeAura,$source,$scene,$clock,'initiative-circle');
checkChampion(applicationEffectiveInitiativeBonus([...$ally,'initiativeBonus'=>3],$initiativeState,$scene)===15,'Generic initiative aura combines with base');
checkChampion(applicationEffectiveInitiativeBonus([...$ally,'x'=>99,'initiativeBonus'=>3],$initiativeState,$scene)===3,'Generic initiative aura disappears outside');
checkChampion(applicationEffectiveInitiativeBonus($source,$initiativeState,$scene)===0,'Generic initiative aura excludes its emitter');
$protected=applicationEnhancedResistanceTarget([...$ally,'armor'=>95,'magicArmor'=>20],$state,$scene);
checkChampion(onlineAttackArmorPercent($protected,'physical')===100,'Physical resistance capped');
checkChampion(onlineAttackArmorPercent($protected,'magical')===30,'Magic resistance additive');
checkChampion(onlineAttackArmorPercent($protected,'ignore')===0,'Ignore bypasses armour');
$visual=applicationTokenBuffVisuals($source,$state,$scene);checkChampion(count(array_filter($visual,static fn($entry)=>(float)($entry['auraScale']??0)===21.0))===1,'Emitter projected with public scaled contour');
$controller=static fn($token)=>$token['controllerPlayerId']??'';
$single=['useWeaponDamage'=>true,'requiredWeaponAttackId'=>'chain','singleEnemy'=>['damageRepetitions'=>2,'conditionRider'=>['version'=>1,'label'=>'Restreint','turns'=>1,'resistance'=>false]]];
checkChampion(applicationAbilityRangeCells($single,[['id'=>'main','reach'=>'melee'],$weapon],'main')===4.0,'Required weapon controls fallback range');
checkChampion(applicationSimpleWeaponDamage($single,[$weapon])['damageFormula']==='1d50+20','Actual required weapon formula');
try{applicationSimpleWeaponDamage($single,[$weapon],'other');throw new RuntimeException('Wrong weapon accepted');}catch(RuntimeException $e){checkChampion(str_contains($e->getMessage(),'configurée'),'Wrong weapon blocked');}
checkChampion(applicationSingleEnemyAttackFields($single,[$ally,$enemy],$source,$enemy,$controller)['damageRepetitions']===2,'One enemy repeats');
checkChampion(applicationSingleEnemyAttackFields($single,[$enemy,[...$enemy,'id'=>'other']],$source,$enemy,$controller)===[],'Two enemies do not repeat');
$calls=0;$rolled=onlineRollAttackDamage(['damageFormula'=>'1d50+20','damageType'=>'physical','damageRepetitions'=>2],$enemy,static function($formula)use(&$calls){$calls++;return ['formula'=>$formula,'total'=>$calls===1?11:17,'breakdown'=>(string)$calls];});
checkChampion($calls===2&&count($rolled['damage']['strikes'])===2,'Separate damage rolls');
checkChampion($rolled['damage']['rawDamage']===28&&$rolled['damage']['finalDamage']===23,'Per-strike armour rounding');
$base=applicationEnhancedBasicAttack($weapon,$source,$state,$scene,false);$multiplied=applicationSimpleWeaponDamage(['useWeaponDamage'=>true,'requiredWeaponAttackId'=>'chain','weaponDamageMultiplier'=>2],[$base]);
$withFixed=applicationEnhancedAttackDamage(['formula'=>$multiplied['damageFormula'],'damageType'=>$multiplied['damageType'],'damageComponents'=>$multiplied['damageComponents']],$source,$state,$scene,true,true,false);
checkChampion($withFixed['formula']==='1d50+20+1d50+20+30+15','Fixed bonuses apply after dice multiplier');
checkChampion(applicationEnhancedAttackDamage(['formula'=>'0','damageType'=>'physical'],$source,$state,$scene)['formula']==='0','Zero damage control never becomes an offensive damage spell');
$previous=$clock;$effects=$state['attackEnhancements'];
for($turn=1;$turn<=5;$turn++){$next=[...$clock,'turnSerial'=>1+$turn];$effects=applicationAdvanceAttackEnhancements($effects,$scene,$previous,$next);checkChampion(applicationAdvanceAttackEnhancements($effects,$scene,$previous,$next)===$effects,'Idempotent turn');checkChampion(($effects[0]['remainingTurns']??0)===5-$turn,'Five recipient turns');$previous=$next;}
checkChampion($effects===[],'All combined modifiers expire');
echo json_encode(['ok'=>true,'checks'=>$checks,'scope'=>'generic combat modifiers, moving aura, required weapon and repeated damage; pure authority contract'],JSON_UNESCAPED_UNICODE).PHP_EOL;
