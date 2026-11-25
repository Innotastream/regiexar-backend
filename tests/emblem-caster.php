<?php
declare(strict_types=1);
define('XAR_TACTICAL_FIXTURE_ONLY',true);
require_once __DIR__.'/tactical-lifecycle.php';
$checks=0;
function emblemCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);$GLOBALS['checks']++;}
$id='ability-5f30d8c5-4efc-47d5-bf03-ea5cb192222b';
$elementalBonus=['fireResistance'=>10,'iceResistance'=>10,'poisonResistance'=>10,'bleedingResistance'=>10];
$legacy=['id'=>$id,'name'=>'Nom modifiable','description'=>'Les alliés présents dans le cercle gagnent les bonus. Le lanceur n’en bénéficie pas. Note personnelle conservée.','effect'=>'enhancement','castingStatId'=>'','fatigueCost'=>10,'manaCost'=>0,'usesPerRest'=>1,'restRecharge'=>'long','attackEnhancement'=>['mode'=>'bonus','formula'=>'10','damageType'=>'inherit','turns'=>5,'target'=>'self','trigger'=>'all-attacks','bonuses'=>['physicalResistance'=>10,'magicalResistance'=>10,'attackResultBonus'=>10],'aura'=>['radiusCells'=>8,'affectCaster'=>false,'affectAllies'=>true,'affectEnemies'=>false]]];
$ability=normalizeOnlineAbilities([$legacy])[0];
emblemCheck($ability['attackEnhancement']['aura']['affectCaster']===true,'Includes caster');
$expected=$legacy['attackEnhancement'];$expected['aura']['affectCaster']=true;$expected['bonuses']+=$elementalBonus;
emblemCheck($ability['attackEnhancement']==$expected,'Only the caster flag and four elemental bonuses were added');
emblemCheck(str_contains($ability['description'],'Pendant 5 tours du lanceur, un cercle de 8 cases de rayon')&&str_contains($ability['description'],'au lanceur et aux alliés dans le cercle'),'Description states duration, radius and recipients');
emblemCheck(str_contains($ability['description'],'d100 est réduit de 10 pour les attaques et les compétences offensives')&&str_contains($ability['description'],'+10 dégâts du même type que l’attaque sur une touche'),'Description states result and damage modifiers');
emblemCheck(str_contains($ability['description'],'physique +10, magique +10, feu +10, glace +10, poison +10, saignement +10')&&str_contains($ability['description'],'Note personnelle conservée.'),'Description gives the six bonuses and keeps notes');
emblemCheck(applicationConfiguredAbilityRules($ability)===$ability,'Migration idempotent');
$later=$ability;$later['attackEnhancement']['aura']['affectCaster']=false;
emblemCheck(applicationConfiguredAbilityRules($later)===$later,'Later explicit configuration remains editable');
$revision1=[...$legacy,'rulePreset'=>['id'=>$ability['rulePreset']['id'],'revision'=>1]];
$revision1['attackEnhancement']['bonuses']['fireResistance']=5;
$upgraded=applicationConfiguredAbilityRules($revision1)['attackEnhancement'];
emblemCheck($upgraded['aura']['affectCaster']===false&&$upgraded['bonuses']['fireResistance']===5&&$upgraded['bonuses']['iceResistance']===10,'A previous manual setting survives the migration');
$revision2=[...$ability,'attackEnhancement'=>$upgraded,'rulePreset'=>['id'=>$ability['rulePreset']['id'],'revision'=>2],
    'description'=>"Note personnelle.\n\nDans le cercle, chacun gagne aussi 10 points de résistance au feu, à la glace, au poison et au saignement."];
$clarified=applicationConfiguredAbilityRules($revision2);
emblemCheck($clarified['attackEnhancement']===$upgraded&&str_contains($clarified['description'],'aux alliés dans le cercle')&&str_contains($clarified['description'],'feu +5, glace +10')&&str_contains($clarified['description'],'Note personnelle')&&!str_contains($clarified['description'],'chacun gagne aussi'),'The revised description migrates without changing custom bonuses or removing notes');
$other=$legacy;$other['id']='another-aura';emblemCheck(applicationConfiguredAbilityRules($other)===$other,'Other auras unchanged');
$caster=['id'=>'caster','characterId'=>'sheet','followCharacter'=>true,'controllerPlayerId'=>'owner','x'=>20,'y'=>50,'size'=>40,'layerId'=>'ground'];
$ally=[...$caster,'id'=>'ally','characterId'=>'ally-sheet','x'=>40];$enemy=[...$ally,'id'=>'enemy','controllerPlayerId'=>'','characterId'=>'enemy-sheet'];
$ally['elementalResistances']=['fire'=>90,'ice'=>-50,'poison'=>40,'bleeding'=>92];
$state=['activeSceneId'=>'scene','map'=>['naturalWidth'=>1000,'naturalHeight'=>1000,'gridSize'=>50,'tokens'=>[$caster,$ally,$enemy]],'attackEnhancements'=>[]];
applicationActivateAttackEnhancement($state,$ability,$caster,'scene',[],'emblem');
foreach([$caster,$ally] as $recipient){
 emblemCheck(applicationAttackResultBonus($recipient,$state,'scene')===10,'Attack result bonus on caster and ally');
 emblemCheck(applicationEnhancedBasicAttack(['formula'=>'1d2+3','damageType'=>'physical'],$recipient,$state,'scene')['formula']==='1d2+3+10','Weapon damage on caster and ally');
 emblemCheck(applicationEnhancedAttackDamage(['formula'=>'4','damageType'=>'magical'],$recipient,$state,'scene')['formula']==='4+10','Spell damage on caster and ally');
 emblemCheck(applicationEnhancedResistanceTarget($recipient,$state,'scene')['resistanceBonuses']===['physical'=>10,'magical'=>10],'Both resistances on caster and ally');
}
emblemCheck(applicationAttackResultBonus($enemy,$state,'scene')===0,'Enemy excluded');
emblemCheck(applicationAttackResultBonus([...$ally,'x'=>99],$state,'scene')===0,'Leaving loses bonus');
emblemCheck(applicationAttackResultBonus($ally,$state,'scene')===10,'Returning regains bonus');
emblemCheck(applicationAttackResultBonus([...$ally,'layerId'=>'upper'],$state,'scene')===0,'Other floor excluded');
$resistanceTarget=applicationEnhancedResistanceTarget($ally,$state,'scene');
$desired=['fire'=>100,'ice'=>-40,'poison'=>50,'bleeding'=>100];
emblemCheck(applicationEffectiveElementalResistances($resistanceTarget,[],'scene')===$desired,'Adds ten percentage points, caps immunity and keeps vulnerabilities');
emblemCheck(applicationElementalConditionSuppressed($resistanceTarget,'Brûlure')&&applicationElementalPeriodicDamageSuppressed($resistanceTarget,['damageType'=>'bleeding']),'At 100 percent, status and periodic damage are suppressed');
$damage=onlineRollAttackDamage(['damageComponents'=>[['type'=>'ice','formula'=>'100','ignoreArmor'=>true]]],$ally,null,$state);
emblemCheck($damage['damage']['finalDamage']===140,'Damage calculator uses the real effective elemental resistance exactly once');
foreach([$enemy,[...$ally,'x'=>99],[...$ally,'layerId'=>'upper']] as $excluded)
    emblemCheck(applicationEffectiveElementalResistanceOverrides($excluded,$state,'scene')===[],'Enemy, distant unit and other floor are excluded');
$clone=[...$ally,'id'=>'clone','followCharacter'=>false];
emblemCheck(applicationEffectiveElementalResistanceOverrides($clone,$state,'scene')===$desired,'Independent allied clone is resolved by its own position and resistance');
$effect=$state['attackEnhancements'][0];unset($effect['rulePreset']);$effect['bonuses']=$legacy['attackEnhancement']['bonuses'];$effect['aura']=$legacy['attackEnhancement']['aura'];
emblemCheck(applicationEffectiveElementalResistanceOverrides($ally,[...$state,'attackEnhancements'=>[$effect]],'scene')===$desired,'Already active legacy aura upgrades');
emblemCheck(applicationEffectiveElementalResistanceOverrides($caster,[...$state,'attackEnhancements'=>[$effect]],'scene')===array_fill_keys(XAR_ELEMENTAL_RESISTANCE_TYPES,10),'Already active pre-migration aura benefits the caster');
$antidote=$state;applicationActivateAttackEnhancement($antidote,['id'=>'antidote','name'=>'Antidote','attackEnhancement'=>['mode'=>'immunity','family'=>'poison','turns'=>5]],$ally,'scene',[],'antidote');
emblemCheck(applicationEffectiveElementalResistanceOverrides($ally,$antidote,'scene')===[...$desired,'poison'=>100],'Antidote keeps poison immunity');
$edited=$ability;unset($edited['attackEnhancement']['bonuses']['fireResistance']);
$editedState=$state;applicationActivateAttackEnhancement($editedState,$edited,$caster,'scene',[],'edited');
emblemCheck(applicationEffectiveElementalResistanceOverrides($ally,$editedState,'scene')===['ice'=>-40,'poison'=>50,'bleeding'=>100],'Deliberately removed bonus will not migrate back');
$db=fixture();$character=$db->payload('character:character-player');$character['resources']=['hp'=>100,'maxHp'=>100,'mana'=>100,'maxMana'=>100];$character['fatigue']=['current'=>0,'max'=>150];$character['elementalResistances']=array_fill_keys(XAR_ELEMENTAL_RESISTANCE_TYPES,90);$character['abilities']=normalizeOnlineAbilities([$legacy]);$db->put('character:character-player',$character);
$request=['requestId'=>'emblem-caster-request-0001','sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-player','layerId'=>'ground','abilityId'=>$id];
$r=runCommand($db,'ability.use',$request);emblemCheck($r->status===200,'Canonical activation');
$effect=$db->payload('activity')['attackEnhancements'][0];emblemCheck($effect['aura']['affectCaster']&&$effect['remainingTurns']===5&&$effect['aura']['radiusCells']===8,'Authoritative effect includes caster, same duration and radius');
emblemCheck(array_intersect_key($effect['bonuses'],$elementalBonus)===$elementalBonus,'Native command stores all four bonuses');
emblemCheck($db->payload('character:character-player')['fatigue']['current']===10,'Ten fatigue');
$again=runCommand($db,'ability.use',$request);emblemCheck($again->status===200&&($again->body['deduplicated']??false),'Idempotent replay');
emblemCheck($db->payload('activity')['attackEnhancements'][0]===$effect&&$db->payload('character:character-player')['fatigue']['current']===10,'Single effect and payment');
$effectCommand=['sceneId'=>'scene-one','tokenId'=>'token-player','condition'=>'Glacé','active'=>true];
emblemCheck(runCommand($db,'token.conditions.update',$effectCommand,true,'account-gm')->status===409,'Manual timed condition is refused while caster is shielded');
$damageCommand=['sceneId'=>'scene-one','tokenId'=>'token-player','resource'=>'hp','delta'=>-30,'requestId'=>'emblem-impact-resource-001','impactDamage'=>true];
emblemCheck(runCommand($db,'token.resource.adjust',$damageCommand,true,'account-gm')->status===200,'Direct impact is accepted');
emblemCheck(empty($db->payload('activity')['damageOverTime'])&&$db->payload('character:character-player')['resources']['hp']===70,'Impact removes PV without an immune bleed');
emblemCheck($db->payload('character:character-player')['elementalResistances']===array_fill_keys(XAR_ELEMENTAL_RESISTANCE_TYPES,90),'Temporary bonuses never change the sheet');
$records=applicationDomainRecords($db);$pending=[];
applyOnlineAttackConditions($db,$records,$pending,['id'=>'emblem-verified-afflictions','status'=>'applied','sceneId'=>'scene-one','sourceTokenId'=>'token-monster','targetTokenId'=>'token-player',
    'onHitConditions'=>['Brûlure','Glacé','Hémorragie','Désarmé'],'damageOverTime'=>['label'=>'Brûlure','formula'=>'5','damageType'=>'fire','turns'=>2]]);
emblemCheck(($pending['character:character-player']['payload']['conditions']??[])===['Désarmé']&&empty($pending['activity']['payload']['damageOverTime']),
    'Native hit retains unrelated effects and suppresses matching DOT');
for($i=0;$i<5;$i++)$state['attackEnhancements']=applicationAdvanceAttackEnhancements($state['attackEnhancements'],'scene',[],[],true);
emblemCheck(applicationAttackResultBonus($caster,$state,'scene')===0,'Expiration removes caster bonus');
emblemCheck(applicationEffectiveElementalResistanceOverrides($ally,$state,'scene')===[],'Expiration also removes the four elemental bonuses');
echo json_encode(['ok'=>true,'checks'=>$checks,'productionAccess'=>false],JSON_THROW_ON_ERROR);
