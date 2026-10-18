<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/v1/domains.php';
require_once __DIR__ . '/../api/v1/online.php';
$checks = 0;
function requireEnhancement(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); $GLOBALS['checks']++; }
$source = ['id' => 'keyrie-token', 'characterId' => 'keyrie-fiction', 'followCharacter' => true];
$weapon = ['id' => 'weapon-1', 'formula' => '1d6+2', 'damageType' => 'physical'];
$initiative = ['active' => true, 'combatId' => 'combat-fixture', 'turnSerial' => 10, 'currentIndex' => 0, 'order' => ['keyrie-token', 'other']];
$definition = ['mode' => 'replace', 'formula' => '1d70+12', 'damageType' => 'magical', 'turns' => 5];
$ability = ['id' => 'club', 'name' => 'Nom éditable', 'effect' => 'damage', 'formula' => '1d70+12', 'damageType' => 'magical', 'attackEnhancement' => $definition];
requireEnhancement(applicationAttackEnhancement($definition) === $definition, 'Normalization');
requireEnhancement(validApplicationAbilityEffects($ability), 'Ability accepted');
requireEnhancement(applicationAbilityEffectFields($ability)['attackEnhancement'] === $definition, 'Roundtrip');
foreach ([['mode'=>'bonus','formula'=>'1d6'], ['mode'=>'bonus','formula'=>'-12'], ['turns'=>0], ['damageType'=>'fake']] as $invalid)
    requireEnhancement(applicationAttackEnhancement([...$definition, ...$invalid]) === null, 'Invalid rejected');
$activity = [];
applicationActivateAttackEnhancement($activity, $ability, $source, 'scene', $initiative, 'effect');
$enhanced = applicationEnhancedBasicAttack($weapon, $source, $activity, 'scene');
requireEnhancement($enhanced['formula'] === '1d70+12' && $enhanced['damageType'] === 'magical', 'Replacement');
requireEnhancement($weapon['formula'] === '1d6+2', 'Original unchanged');
requireEnhancement(applicationEnhancedBasicAttack($weapon, [...$source,'id'=>'alias'], $activity, 'scene')['formula'] === '1d70+12', 'Linked unit');
requireEnhancement(applicationEnhancedBasicAttack($weapon, [...$source,'followCharacter'=>false], $activity, 'scene') === $weapon, 'Independent clone');
requireEnhancement(applicationEnhancedBasicAttack($weapon, [...$source,'linkedTokenId'=>'pet'], $activity, 'scene') === $weapon, 'Linked pet distinct');
requireEnhancement(applicationEnhancedBasicAttack($weapon, $source, $activity, 'elsewhere') === $weapon, 'Scene isolation');
$bonus = ['id'=>'bonus','name'=>'Bonus fixe','attackEnhancement'=>['mode'=>'bonus','formula'=>'12','damageType'=>'physical','turns'=>5]];
applicationActivateAttackEnhancement($activity, $bonus, $source, 'scene', $initiative, 'bonus');
requireEnhancement(applicationEnhancedBasicAttack($weapon, $source, $activity, 'scene')['damageComponents'] === [['type'=>'magical','formula'=>'1d70+12'],['type'=>'physical','formula'=>'12']], 'Armor per component');
applicationActivateAttackEnhancement($activity, $ability, $source, 'scene', $initiative, 'refreshed');
requireEnhancement(count($activity['attackEnhancements']) === 2, 'Refresh without duplication');
$effects = applicationAdvanceAttackEnhancements($activity['attackEnhancements'], 'scene', $initiative, [...$initiative,'turnSerial'=>11,'currentIndex'=>1]);
requireEnhancement($effects[0]['remainingTurns'] === 5, 'Other turn does not tick');
for ($i=1;$i<=5;$i++) {
    $next = [...$initiative,'turnSerial'=>11+$i];
    $effects = applicationAdvanceAttackEnhancements($effects,'scene',[...$initiative,'turnSerial'=>$next['turnSerial']-1],$next);
    requireEnhancement(($effects[0]['remainingTurns']??0) === 5-$i, 'Caster turn');
    requireEnhancement(applicationAdvanceAttackEnhancements($effects,'scene',[...$initiative,'turnSerial'=>$next['turnSerial']-1],$next) === $effects, 'Idempotent snapshot');
}
requireEnhancement(applicationEnhancedBasicAttack($weapon,$source,['attackEnhancements'=>$effects],'scene') === $weapon, 'Expiry restores weapon');
requireEnhancement(applicationAdvanceAttackEnhancements($activity['attackEnhancements'],'scene',$initiative,['active'=>false]) === [], 'End combat');
$preserved = preserveApplicationAbilityExtensions('activity', ['attackEnhancements'=>[]], $activity);
requireEnhancement($preserved['attackEnhancements'] === $activity['attackEnhancements'], 'Client cannot forge active effects');
$visualAbility = [...$ability, 'image' => '/media/' . str_repeat('a', 24),
    'attackEnhancement' => ['mode'=>'echo','formula'=>'1d40+6','allyFormula'=>'1d20+3','trigger'=>'melee-weapon','damageType'=>'magical','turns'=>5]];
$beneficiary = ['id'=>'beneficiary','characterId'=>'ally','followCharacter'=>true];
$visualState = ['activeSceneId'=>'scene','map'=>['tokens'=>[$source,$beneficiary]],
    'characters'=>[['id'=>$source['characterId'],'abilities'=>[$visualAbility],'privateNotes'=>'SECRET']]];
applicationActivateAttackEnhancement($visualState,$visualAbility,$source,'scene',$initiative,'visual-buff',$beneficiary);
$beforeVisual = $visualState;
$expectedVisual = [['id'=>'visual-buff','label'=>'Nom éditable','remainingTurns'=>5,'image'=>$visualAbility['image']]];
requireEnhancement(applicationTokenBuffVisuals($beneficiary,$visualState,'scene') === $expectedVisual, 'Public visual descriptor excludes rules and private sheet data');
requireEnhancement(applicationTokenBuffVisuals([...$beneficiary,'id'=>'linked-alias'],$visualState,'scene') === $expectedVisual, 'Linked alias shares its visual');
requireEnhancement(applicationTokenBuffVisuals([...$beneficiary,'followCharacter'=>false],$visualState,'scene') === [], 'Independent clone does not inherit model visual');
requireEnhancement(applicationTokenBuffVisuals([...$beneficiary,'dimSilhouette'=>true],$visualState,'scene') === [], 'Anonymous silhouette reveals no buff');
requireEnhancement(applicationTokenBuffVisuals($beneficiary,$visualState,'elsewhere') === [], 'Visual stays in its scene');
requireEnhancement($visualState === $beforeVisual, 'Visual lookup preserves active effect and duration');
unset($visualState['attackEnhancements'][0]['abilityImage']);
requireEnhancement(applicationTokenBuffVisuals($beneficiary,$visualState,'scene') === $expectedVisual, 'An existing active effect finds its skill icon');
$visualState['attackEnhancements'][0]['abilityImage'] = 'javascript:alert(1)';
requireEnhancement(applicationTokenBuffVisuals($beneficiary,$visualState,'scene')[0]['image'] === null, 'Unsafe icon reference rejected');
$visualState['attackEnhancements'][0]['remainingTurns'] = 0;
requireEnhancement(applicationTokenBuffVisuals($beneficiary,$visualState,'scene') === [], 'Expired effect loses visual');

$normalAttack = ['id'=>'normal-attack','status'=>'pending','echoAttackContext'=>['weaponBased'=>true,'projectile'=>false]];
$tampered = preserveApplicationPendingAbilityValidation(['pendingAttacks'=>[[...$normalAttack,'echoAttackContext'=>['weaponBased'=>false,'projectile'=>true]]]],['pendingAttacks'=>[$normalAttack]]);
requireEnhancement($tampered['pendingAttacks'][0]['echoAttackContext'] === $normalAttack['echoAttackContext'], 'Attack origin remains authoritative');
$removed = preserveApplicationPendingAbilityValidation(['pendingAttacks'=>[],'attackReceipts'=>[]],['pendingAttacks'=>[$normalAttack]]);
requireEnhancement($removed['pendingAttacks'] === [], 'Origin metadata does not change ordinary queue retention');

echo json_encode(['ok'=>true,'enhancementChecks'=>$checks,'replacement'=>$enhanced], JSON_UNESCAPED_UNICODE) . PHP_EOL;
