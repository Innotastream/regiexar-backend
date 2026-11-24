<?php

declare(strict_types=1);

define('XAR_TACTICAL_FIXTURE_ONLY', true);
require __DIR__ . '/tactical-lifecycle.php';

requireTactical(normalizeApplicationElementalResistances(null) === ['fire'=>0, 'ice'=>0, 'poison'=>0, 'bleeding'=>0], 'Legacy saves are neutral.');
requireTactical(normalizeApplicationElementalResistances(['fire'=>120, 'ice'=>-250, 'poison'=>'-50']) === ['fire'=>100, 'ice'=>-250, 'poison'=>-50, 'bleeding'=>0], 'Normalization bounds only the permitted range.');
requireTactical(validApplicationElementalResistances(['ice'=>-250]) && !validApplicationElementalResistances(['ice'=>101]), 'Persisted resistance accepts vulnerability and rejects values above immunity.');
requireTactical(applicationDamageType(' Hémorragie ') === 'bleeding' && applicationDamageType('Givre') === 'ice', 'Element names normalize without altering the underlying armor class.');
$previous = ['id'=>'resistance-test','elementalResistances'=>['fire'=>25,'poison'=>-50]];
$change = prepareApplicationDomainUpsert('character:resistance-test', ['id'=>'resistance-test','elementalResistances'=>['ice'=>10]],
    ['payload'=>$previous], true);
requireTactical($change['payload']['elementalResistances'] === ['fire'=>25, 'ice'=>10, 'poison'=>-50, 'bleeding'=>0],
    'GM partial updates preserve the other saved resistances.');
$legacy = prepareApplicationDomainUpsert('character:resistance-test', ['id'=>'resistance-test'], null, true);
requireTactical(!array_key_exists('elementalResistances', $legacy['payload']),
    'Legacy domains without a profile stay sparse.');

$target = ['armor'=>30, 'magicArmor'=>20, 'elementalResistances'=>['fire'=>25, 'ice'=>-50, 'poison'=>50, 'bleeding'=>-50]];
$fire = onlineRollAttackDamage(['damageType'=>'fire','damageFormula'=>'100'], $target)['damage'];
requireTactical($fire['finalDamage'] === 60 && $fire['armorPercent'] === 20 && $fire['elementalPreventedDamage'] === 20, 'Fire applies magical armor and then resistance.');
$ice = onlineRollAttackDamage(['damageType'=>'ice','damageFormula'=>'100'], $target)['damage'];
requireTactical($ice['finalDamage'] === 120 && $ice['armorPercent'] === 20 && $ice['elementalPreventedDamage'] === -40, 'Negative ice resistance amplifies the armored result.');
$damage = onlineRollAttackDamage(['damageComponents'=>[
    ['type'=>'physical','formula'=>'100'],
    ['type'=>'magical','element'=>'ice','formula'=>'100'],
    ['type'=>'poison','formula'=>'100'],
    ['type'=>'bleeding','formula'=>'100'],
]], $target)['damage'];
requireTactical(array_column($damage['components'],'finalDamage') === [70,120,50,150] && $damage['armorPercent'] === 13 && $damage['finalDamage'] === 390,
    'A mixed attack applies only the relevant resistance to each component.');
$repeatedFire = ['damageType'=>'physical','damageFormula'=>'99','damageComponents'=>[
    ['type'=>'fire','formula'=>'10'], ['type'=>'fire','element'=>'fire','formula'=>'20'],
]];
requireTactical(array_column(onlineRollAttackDamage($repeatedFire,['elementalResistances'=>['fire'=>100]])['damage']['components'],'finalDamage') === [0,0],
    'Explicit and implicit fire components both respect immunity without falling back to physical damage.');
$repeatedFire['damageComponents'][1]['ignoreArmor'] = true;
requireTactical(array_column(onlineRollAttackDamage($repeatedFire,['magicArmor'=>50])['damage']['components'],'finalDamage') === [5,20],
    'An armor-bypassing component remains distinct from the armored fire component.');
$guard = ['damageTypes'=>['magical'], 'percent'=>30, 'armorStacking'=>'add'];
requireTactical(applicationMitigateGuardedDamage(120, [$guard], [...$ice, 'damageType'=>'ice'])['damage'] === 75,
    'Armor-based guards preserve vulnerability and treat ice as magical armor.');
requireTactical(applicationReflectedDamageBasis($fire,'fire',30,['magicArmor'=>99,'elementalResistances'=>['fire'=>50]])['finalDamage'] === 15,
    'Reflected damage retains its element and preserves the armor bypass.');
requireTactical(applicationInfusedDamageComponents([['type'=>'ignore','element'=>'poison','formula'=>'40']], '40', 'ignore', 20) ===
    [['type'=>'ignore','element'=>'poison','formula'=>'40'],['type'=>'ignore','formula'=>'20']], 'Pure mana infusion stays separate from an armor-ignoring poison component.');

$variant = applicationSelectedDamageVariant(['id'=>'ability-mania-elemental-breath','damageVariants'=>[['id'=>'fire','label'=>'Feu','damageType'=>'physical']]], 'fire');
requireTactical($variant['damageType'] === 'physical' && $variant['damageElement'] === 'fire', 'The original breath variant retains its configured armor type.');
$wall = applicationConfiguredAbilityRules(['id'=>'ability-28849018-287a-4ce4-b1fa-7ec786d00807','effect'=>'damage',
    'damageComponents'=>[['type'=>'magical','formula'=>'1d30+10'],['type'=>'ignore','formula'=>'1d10+10']]]);
requireTactical(array_column($wall['damageComponents'],'type') === ['physical','poison']
    && $wall['damageOverTime']['damageType'] === 'poison' && $wall['damageOverTime']['turns'] === 2,
    'The thorn wall uses a physical impact and explicitly applies poison both now and later.');
$wall['damageType']='magical';
$wall['damageComponents'][0]['type']='magical';
$wall['damageComponents'][]=['type'=>'ignore','formula'=>'1d4'];
$wall['rulePreset']['revision']=1;
$wall['damageTargeting']['affectAllies']=false;
$wall['description']='1d30+10 dégâts magiques. Riposte de 1d30+10 dégâts magiques.';
$wall = applicationConfiguredAbilityRules($wall);
requireTactical($wall['damageType']==='physical' && array_column($wall['damageComponents'],'type')===['physical','poison','ignore']
    && $wall['damageTargeting']['affectAllies']===false && $wall['rulePreset']['revision']===2
    && $wall['description']==='1d30+10 dégâts physiques. Riposte de 1d30+10 dégâts physiques.',
    'Old normalized walls keep their targeting and update their damage and description.');
$wall['damageComponents']=[['type'=>'magical','formula'=>'1d4']];
requireTactical(applicationConfiguredAbilityRules($wall)===$wall,'Later edits survive the wall upgrade.');

$blade = ['kind'=>'weapon','formula'=>'1d20','damageType'=>'magical'];
$templateId = 'v370-template-82f161523d5dbfa66b734000';
$catalog = applicationWeaponCatalogPresets(['templates'=>[['id'=>$templateId,'equipment'=>$blade]],
    'instances'=>[['id'=>'fixture-blade','revision'=>1,'templateId'=>$templateId,'equipment'=>$blade]]]);
requireTactical($catalog['templates'][0]['equipment']['damageType'] === 'ice' && $catalog['instances'][0]['equipment']['damageType'] === 'ice'
    && $catalog['instances'][0]['revision'] === 2, 'An existing Arvin sabre changes from its legacy type to ice.');
$catalog['instances'][0]['equipment']['damageType'] = 'physical';
requireTactical(applicationWeaponCatalogPresets($catalog) === $catalog, 'Catalog upgrade is idempotent and preserves later edits.');

$db = fixture();
$monsterKey = 'token:scene-one:token-monster';
$monster = [...$db->payload($monsterKey), 'elementalResistances'=>['poison'=>50], 'conditions'=>['Empoisonné']];
$db->put($monsterKey, $monster);
$poisonBefore = ['id'=>'legacy-poison','sceneId'=>'scene-one','targetTokenId'=>'token-monster','label'=>'Poison mortel',
    'family'=>'poison','formula'=>'10','damageType'=>'ignore','remainingTurns'=>2];
$buff = ['id'=>'self-poison-buff','abilityId'=>'varska-poison','holder'=>'token:token-monster','sceneId'=>'scene-one','targetTokenId'=>'token-monster',
    'casterTokenId'=>'token-monster','label'=>'Poison mortel','mode'=>'on-attack-dot','family'=>'poison',
    'turns'=>5,'remainingTurns'=>5,'dot'=>['label'=>'Poison mortel','formula'=>'1d20','turns'=>5]];
$activity = [...$db->payload('activity'), 'damageOverTime'=>[$poisonBefore], 'attackEnhancements'=>[$buff]];
$db->put('activity', $activity);
$records = applicationDomainRecords($db);
$pending = [];
$identity = ['id'=>'account-gm','display_name'=>'MJ','effective_mode'=>'gm','permanent_role'=>'gm'];
applyApplicationDamageOverTimeOnTurn($db, $records, $pending, $identity, ['sceneId'=>'scene-one', 'turn'=>1]);
requireTactical($pending[$monsterKey]['payload']['hp'] === 35, 'A legacy poison DOT applies one 50% reduction through the manual turn path.');

$db->put('items', ['version'=>1,'templates'=>[['id'=>'antidote-template','name'=>'Antidote','kind'=>'object',
    'active'=>['version'=>1,'useLabel'=>'Boire','authority'=>'owner','effects'=>[
        ['kind'=>'cleanse','families'=>['poison']], ['kind'=>'immunity','family'=>'poison','turns'=>5],
    ]]]], 'instances'=>[['id'=>'antidote-item','templateId'=>'antidote-template','revision'=>1,
        'location'=>['kind'=>'inventory','characterId'=>'unit_token-monster']]]]);
$used = runCommand($db, 'item.consume', ['requestId'=>'antidote-elemental-contract-0001','sceneId'=>'scene-one','itemId'=>'antidote-item',
    'characterId'=>'unit_token-monster','tokenId'=>'token-monster','expectedItemRevision'=>1], true, 'account-gm');
requireTactical($used->status === 200, 'GM uses antidote through the item command.');
$activity = $db->payload('activity');
$monster = $db->payload($monsterKey);
requireTactical($activity['damageOverTime'] === [] && !in_array('Empoisonné',$monster['conditions'],true)
    && in_array('self-poison-buff',array_column($activity['attackEnhancements'],'id'),true), 'Antidote cleanses received poison and preserves the offensive poison buff.');
requireTactical($monster['elementalResistances']['poison'] === 50
    && onlineRollAttackDamage(['damageFormula'=>'10','damageType'=>'poison','sceneId'=>'scene-one'],$monster,null,$activity)['damage']['finalDamage'] === 0,
    'Antidote grants total direct poison resistance without changing the stored resistance.');
$coating = ['id'=>'hira-poison-test','name'=>'Enduit de poison','attackEnhancement'=>['mode'=>'echo','formula'=>'5d5',
    'allyFormula'=>'5d5','damageType'=>'ignore','immunityLabel'=>'Poison','turns'=>5,'trigger'=>'enchanted-weapon','requiresHit'=>true]];
applicationActivateAttackEnhancement($activity, $coating, $monster, 'scene-one', [], 'hira-coating', $monster, ['enchantedItemId'=>'hira-blade']);
$coatingEffect = array_column($activity['attackEnhancements'], null, 'id')['hira-coating'] ?? [];
requireTactical($coatingEffect !== [] && applicationEnhancementDamageType($coatingEffect, ['damageType'=>'physical']) === 'poison',
    'An antidote bearer may coat a weapon, and legacy coating damage is explicitly poison.');
$before = $activity['attackEnhancements'];
requireTactical(onlineRollAttackDamage(['damageFormula'=>'20','damageType'=>'poison','sceneId'=>'scene-one'],$monster,null,$activity)['damage']['finalDamage'] === 0
    && $activity['attackEnhancements'] === $before, 'A hit against an immunized target leaves the coating intact.');
$clock = ['active'=>true,'combatId'=>'combat-test','turnSerial'=>2];
$activity['attackEnhancements'] = applicationAdvanceAttackEnhancements($activity['attackEnhancements'],'scene-one',$clock,['active'=>false]);
requireTactical(applicationEffectiveElementalResistances($monster,$activity,'scene-one')['poison'] === 100, 'Leaving combat retains the remaining antidote turns.');
for ($turn = 0; $turn < 4; $turn++) $activity['attackEnhancements'] = applicationAdvanceAttackEnhancements($activity['attackEnhancements'],'scene-one',[],[],true);
requireTactical(applicationEffectiveElementalResistances($monster,$activity,'scene-one')['poison'] === 100, 'Antidote still protects before the fifth advance.');
$activity['attackEnhancements'] = applicationAdvanceAttackEnhancements($activity['attackEnhancements'],'scene-one',[],[],true);
requireTactical(applicationEffectiveElementalResistances($monster,$activity,'scene-one')['poison'] === 50, 'The fifth advance restores the saved poison resistance.');

$sheet = [...$db->payload('character:character-player'), 'elementalResistances'=>['fire'=>50]];
$player = synchronizeOnlineCharacterToken($db->payload('token:scene-one:token-player'), $sheet);
$projection = publicPlayerState(['characters'=>[$sheet], 'activeSceneId'=>'scene-one', 'map'=>['tokens'=>[$player,
    [...$monster, 'revealDetailsToPlayers'=>true]]], 'initiative'=>[], 'attackEnhancements'=>[
    ['holder'=>'character:character-player','sceneId'=>'scene-one','mode'=>'immunity','family'=>'poison','remainingTurns'=>2],
]], ['id'=>'account-player','display_name'=>'Joueur'], []);
$tokens = array_column($projection['map']['tokens'], null, 'id');
requireTactical($tokens['token-player']['elementalResistances']['fire'] === 50 && $tokens['token-player']['elementalResistanceOverrides'] === ['poison'=>100]
    && $projection['myCharacters'][0]['elementalResistanceOverrides'] === ['poison'=>100]
    && !isset($tokens['token-monster']['elementalResistances']) && !isset($tokens['token-monster']['elementalResistanceOverrides']),
    'Only the owner sees base resistances and the temporary poison override.');

$db = fixture();
$gm = ['id'=>'account-gm','display_name'=>'MJ','effective_mode'=>'gm','permanent_role'=>'gm'];
$afflictions = ['Brûlure', 'Glacée', 'Hémorragie', 'Empoisonné', 'Étourdi'];
$monster = [...$db->payload($monsterKey), 'elementalResistances'=>['fire'=>100,'ice'=>100,'bleeding'=>100,'poison'=>100], 'conditions'=>[]];
$db->put($monsterKey, $monster);
requireTactical(applicationUnsuppressedConditions($monster, $afflictions) === ['Empoisonné', 'Étourdi'],
    'Full immunity is specific to burning, frozen and bleeding conditions.');
requireTactical(!applicationElementalConditionSuppressed(['elementalResistances'=>['fire'=>99]], 'Brûlé')
    && applicationPeriodicDamageElement(['label'=>'Enflammée','damageType'=>'magical']) === 'fire'
    && applicationPeriodicDamageElement(['label'=>'Gelée','damageType'=>'physical']) === 'ice'
    && !applicationElementalPeriodicDamageSuppressed($monster, ['label'=>'Brûlure','damageType'=>'fire','manualHp'=>true]),
    'An exact 100% is required, legacy labels resolve, and manual HP adjustments are untyped.');
$add = ['sceneId'=>'scene-one','tokenId'=>'token-monster','active'=>true];
foreach (array_slice($afflictions, 0, 3) as $label) {
    requireTactical(runCommand($db, 'token.conditions.update', [...$add,'condition'=>$label], true, 'account-gm')->status === 409,
        'An immune manual condition is refused.');
}
$records = applicationDomainRecords($db);
$pending = [];
applyOnlineAttackConditions($db, $records, $pending, [
    'id'=>'elemental-status-hit','status'=>'applied','sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster',
    'onHitConditions'=>$afflictions, 'damageOverTime'=>['label'=>'Glacé','formula'=>'10','damageType'=>'ice','turns'=>2],
]);
requireTactical(($pending[$monsterKey]['payload']['conditions'] ?? []) === ['Empoisonné', 'Étourdi']
    && empty($pending['activity']['payload']['damageOverTime']), 'An impact keeps unrelated afflictions and refuses an immune elemental DOT.');

$deviceState = ['map'=>['tokens'=>[$monster]], 'characters'=>[], 'devices'=>dsEmpty(), 'initiative'=>[]];
$timed = ['sceneId'=>'scene-one','targetTokenId'=>'token-monster','sourceTokenId'=>'token-player','resisted'=>false,
    'rider'=>dsRider(['version'=>1,'label'=>'Glacé','turns'=>2,'blocksMovement'=>true,'blocksActions'=>true,'attackPenalty'=>20])];
onlineDeviceTimedCondition($deviceState, $timed);
requireTactical($deviceState['devices']['timedConditions'] === [], 'Immunity prevents a new timed status before it is persisted.');
$deviceState['map']['tokens'][0]['elementalResistances']['ice'] = 99;
onlineDeviceTimedCondition($deviceState, $timed);
$conditions = $deviceState['devices']['timedConditions'];
$withIce = $deviceState['map']['tokens'][0];
requireTactical(count($conditions) === 1 && applicationAbilityMovementIsBlocked($withIce,$deviceState['map'],$conditions,'scene-one')
    && applicationTimedAttackPenalty($deviceState,$withIce,'token-player','scene-one') === 20,
    'At 99%, a timed condition applies its movement block and attack penalty.');
$deviceState['map']['tokens'][0]['elementalResistances']['ice'] = 100;
$withIce = $deviceState['map']['tokens'][0];
dsAssertActions($deviceState,$withIce,'scene-one');
requireTactical(!applicationAbilityMovementIsBlocked($withIce,$deviceState['map'],$conditions,'scene-one')
    && applicationTimedAttackPenalty($deviceState,$withIce,'token-player','scene-one') === 0
    && $deviceState['devices']['timedConditions'] === $conditions && in_array('Glacé',$withIce['conditions'],true),
    'An already present status is neutralized by 100% resistance without erasing the saved record.');
$projected = publicPlayerState(['characters'=>[], 'activeSceneId'=>'scene-one', 'initiative'=>[], 'map'=>$deviceState['map'],
    'devices'=>$deviceState['devices']], ['id'=>'account-gm'], []);
requireTactical(!in_array('Glacé',$projected['map']['tokens'][0]['conditions'] ?? [],true)
    && $projected['devices']['timedConditions'] === [], 'The public projection hides the neutralized timed and ordinary condition.');

$legacyBurn = ['id'=>'cold-dot','sceneId'=>'scene-one','targetTokenId'=>'token-monster','label'=>'Brûlure',
    'damageType'=>'physical','formula'=>'10','remainingTurns'=>2];
$db->put('activity', [...$db->payload('activity'), 'damageOverTime'=>[$legacyBurn]]);
$records = applicationDomainRecords($db);
$pending = [];
applyApplicationDamageOverTimeOnTurn($db,$records,$pending,$gm,['sceneId'=>'scene-one','turn'=>1]);
requireTactical(!isset($pending[$monsterKey]) && ($pending['activity']['payload']['damageOverTime'][0]['remainingTurns'] ?? 0) === 1,
    'A legacy burn on an immune target expires normally without losing HP.');

$db = fixture();
$db->put('initiative:scene-one', ['active'=>false,'order'=>[],'turnSerial'=>0]);
$db->put($monsterKey, [...$db->payload($monsterKey), 'allied'=>true, 'elementalResistances'=>['bleeding'=>50]]);
$command = ['sceneId'=>'scene-one', 'tokenId'=>'token-monster', 'resource'=>'hp', 'impactDamage'=>true];
foreach (['first', 'second'] as $case) {
    $result = runCommand($db, 'token.resource.adjust', [...$command,'delta'=>-9,'requestId'=>'small-impact-threshold-' . $case], true, 'account-gm');
    requireTactical($result->status === 200 && empty($db->payload('activity')['damageOverTime']),
        'Separate sources losing less than a quarter of max HP do not accumulate.');
}
$diceImpact = [...$command, 'formula'=>'1d2+9', 'direction'=>-1, 'requestId'=>'quarter-impact-rolled-0001'];
$result = runCommand($db, 'token.resource.adjust', $diceImpact, true, 'account-gm');
$effect = $db->payload('activity')['damageOverTime'][0] ?? [];
$firstPulse = $db->payload($monsterKey)['resourcePulse'] ?? [];
requireTactical($result->status === 200 && in_array($db->payload($monsterKey)['hp'], [11, 12], true) && ($firstPulse['impactBleed'] ?? false)
    && ($effect['id'] ?? '') === 'impact-bleed-' . substr(hash('sha256', 'scene-one|token:token-monster'), 0, 32)
    && ($effect['impactPulseId'] ?? '') === ($firstPulse['id'] ?? null)
    && ($effect['damageType'] ?? '') === 'bleeding' && ($effect['formula'] ?? '') === '5' && ($effect['remainingTurns'] ?? 0) === 3,
    'At least one quarter of maximum HP applies three ticks, even on an ally and in the dice version of manual damage. ' . $result->getMessage());
$retried = runCommand($db, 'token.resource.adjust', $diceImpact, true, 'account-gm');
requireTactical($retried->status === 200 && ($retried->body['deduplicated'] ?? false)
    && $db->payload($monsterKey)['resourcePulse'] === $firstPulse && $db->payload('activity')['damageOverTime'] === [$effect],
    'Retrying an explicit impact cannot double the loss, refresh, or replay the graphic.');
requireTactical(runCommand($db, 'token.resource.adjust', [...$diceImpact, 'impactDamage'=>false], true, 'account-gm')->status === 409,
    'Changing the signed impact intention makes it a different request.');
$refresh = runCommand($db, 'token.resource.adjust', [...$command,'delta'=>-10,'requestId'=>'quarter-impact-repeat-0001'], true, 'account-gm');
requireTactical($refresh->status === 200 && count($db->payload('activity')['damageOverTime']) === 1
    && ($db->payload($monsterKey)['resourcePulse']['impactBleed'] ?? false)
    && $db->payload($monsterKey)['resourcePulse']['id'] !== $firstPulse['id'],
    'Another large impact refreshes one injury and produces a fresh graphic.');
$viewToken = [...$db->payload($monsterKey),'revealDetailsToPlayers'=>false];
$public = publicPlayerState(['activeSceneId'=>'scene-one','map'=>['tokens'=>[$viewToken]],'characters'=>[],'initiative'=>[]], ['id'=>'account-player'], []);
$publicToken = $public['map']['tokens'][0] ?? [];
requireTactical(($publicToken['resourcePulse']['impactBleed'] ?? false) && !isset($publicToken['hp']),
    'A visible enemy or ally can show the impact graphic with private HP still absent.');
$public = publicPlayerState(['activeSceneId'=>'scene-one','map'=>['tokens'=>[[...$viewToken,'hidden'=>true]]],'characters'=>[],'initiative'=>[]], ['id'=>'account-player'], []);
requireTactical($public['map']['tokens'] === [], 'A hidden target does not reveal its graphic.');

$db->put($monsterKey, [...$db->payload($monsterKey), 'hp'=>30]);
for ($turn = 1; $turn <= 3; $turn++) {
    $records = applicationDomainRecords($db);
    $pending = [];
    applyApplicationDamageOverTimeOnTurn($db,$records,$pending,$gm,['sceneId'=>'scene-one','turn'=>$turn]);
    requireTactical(($pending[$monsterKey]['payload']['hp'] ?? -1) === 30 - 3 * $turn
        && empty($pending[$monsterKey]['payload']['resourcePulse']['impactBleed']),
        'Each periodic tick uses resistance and does not replay the impact.');
    foreach ($pending as $key => $change) $db->put($key, $change['payload']);
}
requireTactical($db->payload('activity')['damageOverTime'] === [], 'The third tick consumes the effect.');

$db = fixture();
$sheetKey = 'character:character-player';
$playerKey = 'token:scene-one:token-player';
$db->put($sheetKey, [...$db->payload($sheetKey), 'resources'=>['hp'=>100,'maxHp'=>100,'mana'=>5,'maxMana'=>10]]);
$playerArgs = ['sceneId'=>'scene-one','tokenId'=>'token-player','resource'=>'hp','delta'=>-25];
requireTactical(runCommand($db, 'token.resource.adjust', [...$playerArgs,'requestId'=>'quarter-raw-adjustment-0001'])->status === 200
    && empty($db->payload('activity')['damageOverTime']), 'Ordinary administrative HP adjustments create no injury.');
$result = runCommand($db, 'token.resource.adjust', [...$playerArgs,'impactDamage'=>true,'requestId'=>'quarter-player-impact-0001']);
requireTactical($result->status === 200 && $db->payload($sheetKey)['resources']['hp'] === 50
    && ($db->payload($playerKey)['resourcePulse']['impactBleed'] ?? false)
    && empty($db->payload('token:scene-two:token-copy')['resourcePulse']['impactBleed'])
    && ($db->payload('activity')['damageOverTime'][0]['targetIdentity'] ?? '') === 'character:character-player',
    'Players receive the same injury on their real canonical HP and only the hit representation gets the graphic.');
$firstImpactActionId = $result->body['action']['id'];
$firstImpactDot = $db->payload('activity')['damageOverTime'][0];
$journalEntry = $db->payload('activity')['playerActions'][0];
$publicRetry = runCommand($db, 'token.resource.adjust', [...$playerArgs,'impactDamage'=>true,'requestId'=>'quarter-player-impact-0001']);
requireTactical($journalEntry['id'] === $firstImpactActionId
    && $journalEntry['operation']['impactBleeding'] === ['before'=>null, 'after'=>$firstImpactDot]
    && !isset($result->body['action']['operation']['impactBleeding']) && !isset($publicRetry->body['action']['operation']['impactBleeding']),
    'The authoritative undo context lasts across reloads and never appears in a player response.');
$staleActivity = $db->payload('activity');
$staleActivity['playerActions'][0]['detail'] .= ' · conservation du reçu interne';
unset($staleActivity['playerActions'][0]['operation']['impactBleeding']);
$staleActivity['playerActions'][0]['undo'] = ['requestId'=>'not-authoritative'];
$staleActivity['playerActions'][] = [...$journalEntry, 'id'=>'action-forged-impact-0001'];
$accepted = prepareApplicationDomainUpsert('activity', $staleActivity, ['payload'=>$db->payload('activity')], true);
requireTactical(($accepted['payload']['playerActions'][0]['operation']['impactBleeding'] ?? null) === $journalEntry['operation']['impactBleeding']
    && !isset($accepted['payload']['playerActions'][0]['undo']) && !isset(end($accepted['payload']['playerActions'])['operation']['impactBleeding']),
    'Legacy activity synchronization neither erases nor fabricates authoritative impact reversal data.');
$second = runCommand($db, 'token.resource.adjust', [...$playerArgs,'impactDamage'=>true,'requestId'=>'quarter-player-impact-0002']);
$secondImpactActionId = $second->body['action']['id'];
$secondImpactDot = $db->payload('activity')['damageOverTime'][0];
requireTactical($second->status === 200 && $secondImpactDot['impactPulseId'] !== $firstImpactDot['impactPulseId'],
    'A second manual action has independent bleeding undo provenance.');
$undoFirst = runCommand($db, 'action.undo', ['actionId'=>$firstImpactActionId,'requestId'=>'undo-first-explicit-impact-0001'], true, 'account-gm');
requireTactical($undoFirst->status === 200 && $db->payload($sheetKey)['resources']['hp'] === 50
    && $db->payload('activity')['damageOverTime'] === [$secondImpactDot],
    'Refunding an older manual injury leaves the current generation intact.');
$undoSecond = runCommand($db, 'action.undo', ['actionId'=>$secondImpactActionId,'requestId'=>'undo-second-explicit-impact-0001'], true, 'account-gm');
requireTactical($undoSecond->status === 200 && $db->payload($sheetKey)['resources']['hp'] === 75
    && $db->payload('activity')['damageOverTime'] === [],
    'Refunding the current manual injury never resurrects an already refunded earlier injury.');
$undoRetry = runCommand($db, 'action.undo', ['actionId'=>$secondImpactActionId,'requestId'=>'undo-second-explicit-impact-0001'], true, 'account-gm');
requireTactical($undoRetry->status === 200 && ($undoRetry->body['deduplicated'] ?? false)
    && $db->payload($sheetKey)['resources']['hp'] === 75 && $db->payload('activity')['damageOverTime'] === [],
    'A replay of the refund cannot grant another resource credit or injury change.');
$db->put('initiative:scene-one', ['active'=>false,'order'=>[],'turnSerial'=>0]);
$first = runCommand($db, 'token.resource.adjust', [...$playerArgs,'impactDamage'=>true,'requestId'=>'quarter-player-before-tick-0001']);
$priorDot = $db->payload('activity')['damageOverTime'][0];
$second = runCommand($db, 'token.resource.adjust', [...$playerArgs,'impactDamage'=>true,'requestId'=>'quarter-player-before-tick-0002']);
$records = applicationDomainRecords($db);
$pending = [];
applyApplicationDamageOverTimeOnTurn($db,$records,$pending,$gm,['sceneId'=>'scene-one','turn'=>1]);
foreach ($pending as $key => $change) $db->put($key, $change['payload']);
$undoLatest = runCommand($db, 'action.undo', ['actionId'=>$second->body['action']['id'],'requestId'=>'undo-impact-after-tick-0001'], true, 'account-gm');
$priorDot['remainingTurns'] = 2;
$priorDot['lastManualTurn'] = 1;
requireTactical($first->status === 200 && $undoLatest->status === 200 && $db->payload($sheetKey)['resources']['hp'] == 45
    && $db->payload('activity')['damageOverTime'] === [$priorDot],
    'Refunding a refresh preserves damage already ticked and restores only the earlier injury’s remaining turns.');

$db = fixture();
$db->put($monsterKey, [...$db->payload($monsterKey), 'elementalResistances'=>['bleeding'=>100]]);
$records = applicationDomainRecords($db);
$pending = [];
$health = applyOnlineAttackDamage($db,$records,$pending,$monsterKey,$db->payload($monsterKey),10);
requireTactical($health['appliedDamage'] === 10 && empty($health['resourcePulse']['impactBleed'])
    && empty($pending['activity']['payload']['damageOverTime']), 'An immune enemy receives the impact damage without the bleeding injury.');
$inanimate = [...$db->payload($monsterKey), 'elementalResistances'=>[], 'nonCombatant'=>true];
$db->put($monsterKey, $inanimate);
$records = applicationDomainRecords($db);
$pending = [];
$health = applyOnlineAttackDamage($db,$records,$pending,$monsterKey,$inanimate,10);
requireTactical($health['appliedDamage'] === 10 && empty($health['resourcePulse']['impactBleed'])
    && empty($pending['activity']['payload']['damageOverTime']), 'An inanimate object takes damage without bleeding.');
$repelled = [...$db->payload($monsterKey), 'nonCombatant'=>false, 'armorCategory'=>'special', 'armor'=>50];
$db->put($monsterKey, $repelled);
$records = applicationDomainRecords($db);
$pending = [];
$reducedDamage = onlineRollAttackDamage(['damageType'=>'physical','damageFormula'=>'10'], $repelled)['damage']['finalDamage'];
$health = applyOnlineAttackDamage($db,$records,$pending,$monsterKey,$repelled,$reducedDamage);
requireTactical($health['appliedDamage'] === 5 && empty($health['resourcePulse']['impactBleed'])
    && empty($pending['activity']['payload']['damageOverTime']), 'Armor can reduce a source below the actual HP loss threshold.');
$db->put($monsterKey, [...$db->payload($monsterKey),'hp'=>15,'maxHp'=>20,'armor'=>0,'elementalResistances'=>[]]);
$db->put('activity', [...$db->payload('activity'), 'damageOverTime'=>[
    ['id'=>'prior-periodic-source','sceneId'=>'scene-one','targetTokenId'=>'token-monster',
        'label'=>'Épreuve','damageType'=>'physical','formula'=>'5','remainingTurns'=>2],
]]);
$records = applicationDomainRecords($db);
$pending = [];
applyApplicationDamageOverTimeOnTurn($db,$records,$pending,$gm,['sceneId'=>'scene-one','turn'=>1]);
requireTactical(($pending[$monsterKey]['payload']['hp'] ?? 0) === 10
    && empty($pending[$monsterKey]['payload']['resourcePulse']['impactBleed'])
    && array_column($pending['activity']['payload']['damageOverTime'], 'id') === ['prior-periodic-source'],
    'A periodic tick reaching the threshold cannot create a new injury.');

$db = fixture();
$hitArgs = ['sourceTokenId'=>'token-monster','targetTokenId'=>'token-monster-two','requestId'=>'impact-real-attack-route-0001',
    'attackKind'=>'custom','customAttack'=>['name'=>'Impact de recette','customStat'=>true,'threshold'=>100,'statLabel'=>'Force',
        'damageComponents'=>[['type'=>'physical','formula'=>'9']]],'rollMode'=>'advantage','opposed'=>false];
for ($attempt = 0; $attempt < 15; $attempt++) {
    $db = fixture();
    $hit = runCommand($db, 'token.attack', $hitArgs, true, 'account-gm');
    if ($hit->status === 200 && ($hit->body['attack']['status'] ?? '') === 'applied') break;
}
$directTarget = $db->payload('token:scene-one:token-monster-two');
requireTactical($directTarget['hp'] === 26 && ($directTarget['resourcePulse']['impactBleed'] ?? false)
    && count($db->payload('activity')['damageOverTime'] ?? []) === 1,
    'A confirmed attack uses actual damage, persists the enemy injury and publishes the graphic with the token. '
        . json_encode(['status'=>$hit->status,'attackStatus'=>$hit->body['attack']['status']??null,'hp'=>$directTarget['hp'],
            'pulse'=>$directTarget['resourcePulse']??null,'dots'=>$db->payload('activity')['damageOverTime']??null]));

echo "Elemental resistance, weapon catalog, periodic damage and antidote contracts passed.\n";
