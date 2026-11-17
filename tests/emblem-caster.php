<?php
declare(strict_types=1);
define('XAR_TACTICAL_FIXTURE_ONLY',true);
require_once __DIR__.'/tactical-lifecycle.php';
$checks=0;
function emblemCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);$GLOBALS['checks']++;}
$id='ability-5f30d8c5-4efc-47d5-bf03-ea5cb192222b';
$legacy=['id'=>$id,'name'=>'Nom modifiable','description'=>'Les alliés présents dans le cercle gagnent les bonus. Le lanceur n’en bénéficie pas. Note personnelle conservée.','effect'=>'enhancement','castingStatId'=>'','fatigueCost'=>10,'manaCost'=>0,'usesPerRest'=>1,'restRecharge'=>'long','attackEnhancement'=>['mode'=>'bonus','formula'=>'10','damageType'=>'inherit','turns'=>5,'target'=>'self','trigger'=>'all-attacks','bonuses'=>['physicalResistance'=>10,'magicalResistance'=>10,'attackResultBonus'=>10],'aura'=>['radiusCells'=>8,'affectCaster'=>false,'affectAllies'=>true,'affectEnemies'=>false]]];
$ability=normalizeOnlineAbilities([$legacy])[0];
emblemCheck($ability['attackEnhancement']['aura']['affectCaster']===true,'Includes caster');
$expected=$legacy['attackEnhancement'];$expected['aura']['affectCaster']=true;
emblemCheck($ability['attackEnhancement']==$expected,'Only caster flag changed');
emblemCheck(str_contains($ability['description'],'Le lanceur et les alliés')&&str_contains($ability['description'],'Note personnelle conservée.'),'Description corrected without losing notes');
emblemCheck(applicationConfiguredAbilityRules($ability)===$ability,'Migration idempotent');
$later=$ability;$later['attackEnhancement']['aura']['affectCaster']=false;
emblemCheck(applicationConfiguredAbilityRules($later)===$later,'Later explicit configuration remains editable');
$other=$legacy;$other['id']='another-aura';emblemCheck(applicationConfiguredAbilityRules($other)===$other,'Other auras unchanged');
$caster=['id'=>'caster','characterId'=>'sheet','followCharacter'=>true,'controllerPlayerId'=>'owner','x'=>20,'y'=>50,'size'=>40,'layerId'=>'ground'];
$ally=[...$caster,'id'=>'ally','characterId'=>'ally-sheet','x'=>40];$enemy=[...$ally,'id'=>'enemy','controllerPlayerId'=>'','characterId'=>'enemy-sheet'];
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
$db=fixture();$character=$db->payload('character:character-player');$character['resources']=['hp'=>100,'maxHp'=>100,'mana'=>100,'maxMana'=>100];$character['fatigue']=['current'=>0,'max'=>150];$character['abilities']=normalizeOnlineAbilities([$legacy]);$db->put('character:character-player',$character);
$request=['requestId'=>'emblem-caster-request-0001','sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-player','layerId'=>'ground','abilityId'=>$id];
$r=runCommand($db,'ability.use',$request);emblemCheck($r->status===200,'Canonical activation');
$effect=$db->payload('activity')['attackEnhancements'][0];emblemCheck($effect['aura']['affectCaster']&&$effect['remainingTurns']===5&&$effect['aura']['radiusCells']===8,'Authoritative effect includes caster, same duration and radius');
emblemCheck($db->payload('character:character-player')['fatigue']['current']===10,'Ten fatigue');
$again=runCommand($db,'ability.use',$request);emblemCheck($again->status===200&&($again->body['deduplicated']??false),'Idempotent replay');
emblemCheck($db->payload('activity')['attackEnhancements'][0]===$effect&&$db->payload('character:character-player')['fatigue']['current']===10,'Single effect and payment');
for($i=0;$i<5;$i++)$state['attackEnhancements']=applicationAdvanceAttackEnhancements($state['attackEnhancements'],'scene',[],[],true);
emblemCheck(applicationAttackResultBonus($caster,$state,'scene')===0,'Expiration removes caster bonus');
echo json_encode(['ok'=>true,'checks'=>$checks,'productionAccess'=>false],JSON_THROW_ON_ERROR);
