<?php
declare(strict_types=1);

// Pure PHP authority checks only: no real account, network or database.
require_once __DIR__ . '/../api/v1/domains.php';
require_once __DIR__ . '/../api/v1/online.php';

$checks = 0;
function requireCasting(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    $GLOBALS['checks'] += 1;
}
function rejectsCasting(callable $action, string $code): void {
    try { $action(); }
    catch (RuntimeException $error) { requireCasting($error->getMessage() === $code, 'Wrong rejection: ' . $error->getMessage()); return; }
    throw new RuntimeException('Missing rejection: ' . $code);
}

$ability = ['id' => 'ability-mixed', 'name' => 'Flamme tranchante', 'formula' => '10+20+7', 'damageType' => 'physical', 'description' => '', 'effect' => 'damage',
    'damageComponents' => [['type' => 'physical', 'formula' => '10'], ['type' => 'magical', 'formula' => '20'], ['type' => 'ignore', 'formula' => '7']],
    'manaCost' => 8, 'cooldownRounds' => 3, 'castingStatId' => 'intelligence', 'image' => '/media/ability-test.png'];
$source = ['id' => 'token-one', 'characterId' => 'character-one', 'followCharacter' => true, 'mana' => 20, 'maxMana' => 30,
    'stats' => [['id' => 'intelligence', 'label' => 'Intelligence', 'value' => 62], ['id' => 'agility', 'label' => 'Agilité', 'value' => 47]]];
foreach ([['hp' => 0], ['hp' => -1], ['hp' => INF], [], ['hp' => 50, 'healthOverride' => 'dead'], ['hp' => 50, 'conditions' => ['Mort']]] as $defeated) {
    requireCasting(applicationAbilitySourceDefeated($defeated), 'KO, explicit death and invalid HP prohibit every ability, including legacy free abilities');
}
requireCasting(!applicationAbilitySourceDefeated(['hp' => 1, 'maxHp' => 100, 'healthOverride' => null]), 'A living critical source can cast an otherwise valid ability');
requireCasting(!applicationAbilitySourceDefeated(['hp' => 50, 'maxHp' => 100, 'healthOverride' => null, 'conditions' => ['Mort']]), 'An explicit cleared death override prevails over obsolete text');
$initiative = ['active' => true, 'round' => 4];
$plan = applicationAbilityCastingPlan($ability, $source, 'scene-one', $initiative, []);
requireCasting($plan['manaCost'] === 8 && $plan['cooldownRounds'] === 3 && $plan['usedRound'] === 4, 'Casting cost and round were lost');
requireCasting($plan['statId'] === 'intelligence' && $plan['statLabel'] === 'Intelligence' && $plan['threshold'] === 62, 'Casting statistic must come from the authoritative source');
requireCasting($plan['characterId'] === 'character-one' && $plan['tokenId'] === '', 'Placed occurrences must share the sheet cooldown');
requireCasting(applicationAbilityCastingPlan(array_replace($ability, ['castingStatId' => 'character-stat-intelligence']), $source, 'scene-one', $initiative, [])['threshold'] === 62, 'Canonical sheet statistic must resolve on a creature short id');
$oldStats = array_replace($source, ['stats' => [['id' => 'token-stat-old-random', 'label' => 'Intelligence', 'value' => '73']]]);
requireCasting(applicationAbilityCastingPlan(array_replace($ability, ['castingStatId' => 'character-stat-intelligence']), $oldStats, 'scene-one', $initiative, [])['threshold'] === 73, 'Legacy creature statistic ids must resolve by the recognized characteristic label');
requireCasting(applicationAbilityCastingStat([['id' => 'custom-stat', 'label' => 'Intelligence', 'value' => 10]], 'made-up-stat') === null, 'Unknown statistic ids must not silently choose a named characteristic');
requireCasting(validApplicationAbilities([$ability]), 'The mixed ability with casting rules should be valid');
$withoutImage = array_replace($ability, ['image' => null]);
requireCasting(validApplicationAbilities([$withoutImage]), 'An explicitly absent ability image must remain valid');
requireCasting(normalizeOnlineAbilities([$withoutImage])[0]['image'] === null, 'Normalization must retain the explicit absence of an image');
$publicRoll = ['visibility' => 'public', 'revealed' => true];
$gm = ['id' => 'gm-test', 'effective_mode' => 'gm', 'permanent_role' => 'gm'];
$privateMonsterRoll = onlineAbilityRollVisibility($publicRoll, [], $gm);
requireCasting($privateMonsterRoll['visibility'] === 'gm' && $privateMonsterRoll['revealed'] === false && $privateMonsterRoll['rollerRole'] === 'gm', 'A private monster ability must not expose its formula or statistic');
$revealedMonsterRoll = onlineAbilityRollVisibility($publicRoll, ['revealDetailsToPlayers' => true], $gm);
requireCasting($revealedMonsterRoll['visibility'] === 'public' && $revealedMonsterRoll['revealed'] === true, 'An explicitly revealed monster can expose the requested roll');
foreach ([['hidden' => true, 'controllerPlayerId' => 'player-test'], ['hidden' => true, 'revealDetailsToPlayers' => true]] as $hiddenSource) {
    $hiddenRoll = onlineAbilityRollVisibility($publicRoll, $hiddenSource, $gm);
    requireCasting($hiddenRoll['visibility'] === 'gm' && $hiddenRoll['revealed'] === false, 'A hidden source ability must stay private even if owned or its statistics were previously revealed');
}
requireCasting(onlineAbilityRollVisibility($publicRoll, [], ['id' => 'player-test', 'effective_mode' => 'player', 'permanent_role' => 'gm']) === $publicRoll, 'A GM account playing in player mode must retain player roll handling');
$normalized = normalizeOnlineAbilities([$ability])[0];
foreach (['manaCost', 'cooldownRounds', 'castingStatId', 'image', 'damageComponents'] as $key) requireCasting($normalized[$key] === $ability[$key], 'Normalization lost ' . $key);

foreach ([['manaCost', -1], ['manaCost', 1000000001], ['manaCost', 1.5], ['manaCost', '8'], ['cooldownRounds', -1], ['cooldownRounds', 1000], ['cooldownRounds', 1.5], ['castingStatId', '../intelligence'], ['castingStatId', []], ['image', str_repeat('x', 4097)]] as [$key, $value]) {
    $invalid = array_replace($ability, [$key => $value]);
    requireCasting(!validApplicationAbilities([$invalid]), 'Invalid casting field was accepted: ' . $key);
    rejectsCasting(fn() => applicationAbilityCastingPlan($invalid, $source, 'scene-one', $initiative, []), 'ability_cast_invalid');
}

$lowMana = array_replace($source, ['mana' => 7]);
rejectsCasting(fn() => applicationAbilityCastingPlan($ability, $lowMana, 'scene-one', $initiative, []), 'ability_mana_insufficient');
$exactMana = applicationAbilityCastingPlan($ability, array_replace($source, ['mana' => 8]), 'scene-one', $initiative, []);
requireCasting($exactMana['manaCost'] === 8, 'Exactly sufficient mana must permit a cast');
rejectsCasting(fn() => applicationAbilityCastingPlan($ability, array_replace($source, ['mana' => 100, 'maxMana' => 7]), 'scene-one', $initiative, []), 'ability_mana_insufficient');
$freeAbility = array_replace($ability, ['manaCost' => 0, 'cooldownRounds' => 0, 'castingStatId' => '']);
$freePlan = applicationAbilityCastingPlan($freeAbility, array_replace($source, ['mana' => 0]), 'scene-one', $initiative, []);
requireCasting($freePlan['manaCost'] === 0 && $freePlan['cooldownRounds'] === 0 && $freePlan['threshold'] === null, 'A free ability with no check must remain usable');
$noCheck = onlineAbilityCastingRoll($freePlan, [], []);
requireCasting($noCheck['success'] === true && $noCheck['roll'] === null, 'An optional empty statistic must not launch a die');
$automaticOutcome = ['code' => 'success', 'label' => 'SANS JET', 'success' => true, 'automatic' => true];
foreach ([['code' => 'success', 'success' => true, 'result' => 91], ['code' => 'critical-success', 'success' => true, 'result' => 44], ['code' => 'special-success', 'success' => true, 'result' => 55]] as $defence) {
    requireCasting(onlineDefenderWinsOpposition($automaticOutcome, $defence), 'A successful defence must defeat an attack with no casting die');
}
foreach ([['code' => 'failure', 'success' => false, 'result' => 1], ['code' => 'critical-failure', 'success' => false, 'result' => 10], null] as $defence) {
    requireCasting(!onlineDefenderWinsOpposition($automaticOutcome, $defence), 'An absent or failed defence must not defeat an attack with no casting die');
}

$missing = array_replace($ability, ['castingStatId' => 'removed-stat']);
rejectsCasting(fn() => applicationAbilityCastingPlan($missing, $source, 'scene-one', $initiative, []), 'ability_cast_stat_missing');
$legacy = $ability;
unset($legacy['castingStatId']);
$legacyPlan = applicationAbilityCastingPlan($legacy, $source, 'scene-one', $initiative, [], 'agility');
requireCasting($legacyPlan['statId'] === 'agility' && $legacyPlan['threshold'] === 47, 'A legacy attack retains its selected statistic');
$explicitNone = applicationAbilityCastingPlan(array_replace($ability, ['castingStatId' => '']), $source, 'scene-one', $initiative, [], 'agility');
requireCasting($explicitNone['statId'] === '' && $explicitNone['threshold'] === null, 'An explicitly absent check must override legacy attack selection');

$timer = ['id' => 'timer-one', 'sceneId' => 'scene-one', 'abilityId' => 'ability-mixed', 'characterId' => 'character-one', 'tokenId' => '', 'readyRound' => 7];
rejectsCasting(fn() => applicationAbilityCastingPlan($ability, $source, 'scene-one', $initiative, [$timer]), 'ability_on_cooldown');
rejectsCasting(fn() => applicationAbilityCastingPlan($ability, array_replace($source, ['id' => 'token-second-occurrence']), 'scene-one', $initiative, [$timer]), 'ability_on_cooldown');
rejectsCasting(fn() => applicationAbilityCastingPlan($ability, $source, 'scene-one', ['active' => false, 'round' => 4], [$timer]), 'ability_on_cooldown');
$ready = applicationAbilityCastingPlan($ability, $source, 'scene-one', ['active' => true, 'round' => 7], [$timer]);
requireCasting($ready['usedRound'] === 7, 'Cooldown must expire exactly at its ready round');
requireCasting(applicationAbilityCastingPlan($ability, $source, 'scene-one', $initiative, [array_replace($timer, ['abilityId' => 'another-ability'])])['abilityId'] === 'ability-mixed', 'Other abilities must not share a recharge');
requireCasting(applicationAbilityCastingPlan($ability, $source, 'scene-one', $initiative, [array_replace($timer, ['characterId' => 'another-character'])])['characterId'] === 'character-one', 'Other sheets must not share a recharge');

$clone = array_replace($source, ['id' => 'token-clone', 'followCharacter' => false]);
$clonePlan = applicationAbilityCastingPlan($ability, $clone, 'scene-one', $initiative, [$timer]);
requireCasting($clonePlan['characterId'] === '' && $clonePlan['tokenId'] === 'token-clone', 'An independent clone must not inherit the original sheet recharge');
$cloneTimer = array_replace($timer, ['characterId' => '', 'tokenId' => 'token-clone']);
rejectsCasting(fn() => applicationAbilityCastingPlan($ability, $clone, 'scene-one', $initiative, [$cloneTimer]), 'ability_on_cooldown');
$familiar = array_replace($source, ['id' => 'token-familiar', 'linkedTokenId' => 'familiar-one']);
requireCasting(applicationAbilityCastingPlan($ability, $familiar, 'scene-one', $initiative, [$timer])['tokenId'] === 'token-familiar', 'A familiar must keep its own recharge');

$oldRow = ['id' => $ability['id'], 'name' => 'Nom modifié', 'formula' => '1d6', 'damageType' => 'physical', 'description' => 'Description modifiée'];
$preserved = preserveApplicationAbilityRows([$oldRow], [$ability])[0];
foreach (['manaCost', 'cooldownRounds', 'castingStatId', 'image', 'damageComponents'] as $key) requireCasting($preserved[$key] === $ability[$key], 'Old client write erased ' . $key);
requireCasting($preserved['name'] === 'Nom modifié' && $preserved['description'] === 'Description modifiée', 'Old client edits must survive extension preservation');
$cleared = preserveApplicationAbilityRows([array_replace($ability, ['manaCost' => 0, 'cooldownRounds' => 0, 'castingStatId' => '', 'image' => ''])], [$ability])[0];
requireCasting($cleared['manaCost'] === 0 && $cleared['cooldownRounds'] === 0 && $cleared['castingStatId'] === '' && $cleared['image'] === '', 'Explicit resets must not restore previous metadata');
requireCasting(preserveApplicationAbilityRows([], [$ability]) === [], 'Deleting an ability must remain possible');

$receipt = ['kind' => 'ability-cast', 'requestId' => 'ability-request-kept', 'accountId' => 'player-test', 'actionId' => 'action-real-cast', 'sourceKey' => 'token-one', 'abilityId' => $ability['id'], 'expiresAt' => (int) floor(microtime(true) * 1000) + 60000, 'requestSignature' => '["token.roll"]', 'result' => ['castSucceeded' => false]];
$preservedActivity = preserveApplicationAbilityExtensions('activity', ['resourceReceipts' => [], 'actionTimers' => []], ['resourceReceipts' => [$receipt]]);
requireCasting($preservedActivity['resourceReceipts'] === [$receipt], 'Old client snapshots must retain authoritative, unexpired casting receipts');
$mutatedReceipt = array_replace($receipt, ['result' => ['castSucceeded' => true]]);
$preservedActivity = preserveApplicationAbilityExtensions('activity', ['resourceReceipts' => [$mutatedReceipt]], ['resourceReceipts' => [$receipt]]);
requireCasting($preservedActivity['resourceReceipts'] === [$receipt], 'The result of an already receipted attempt is immutable');
$expiredReceipt = array_replace($receipt, ['expiresAt' => 1]);
requireCasting(preserveApplicationAbilityExtensions('activity', ['resourceReceipts' => []], ['resourceReceipts' => [$expiredReceipt]])['resourceReceipts'] === [], 'Expired receipts must not be resurrected');
$legacyTimer = $timer; unset($legacyTimer['abilityId'], $legacyTimer['tokenId']);
$keptTimer = preserveApplicationAbilityExtensions('activity', ['actionTimers' => [$legacyTimer]], ['actionTimers' => [$timer]])['actionTimers'][0];
requireCasting($keptTimer['abilityId'] === $timer['abilityId'] && $keptTimer['tokenId'] === '', 'Old timer normalization must retain its managed ability association');

$damage = onlineRollAttackDamage(['damageComponents' => $ability['damageComponents'], 'damageRollMode' => 'normal'], ['armorCategory' => 'heavy', 'armor' => 50, 'magicArmor' => 25]);
requireCasting($damage['damage']['rawDamage'] === 37, 'Mixed raw damage must sum all components');
$parts = $damage['damage']['components'];
requireCasting($parts[0]['armorPercent'] === onlineAttackArmorPercent(['armorCategory' => 'heavy', 'armor' => 50], 'physical'), 'Physical damage must use physical armor');
requireCasting($parts[1]['finalDamage'] === 15 && $parts[1]['armorPercent'] === 25, 'Magical damage must use its own percentage');
requireCasting($parts[2]['finalDamage'] === 7 && $parts[2]['armorPercent'] === 0, 'Ignoring armor must bypass both armor types');
requireCasting($damage['damage']['finalDamage'] === array_sum(array_column($parts, 'finalDamage')), 'Mixed damage must sum separately reduced values');

echo json_encode(['checks' => $checks, 'status' => 'ok', 'scope' => 'pure PHP casting authority; no Windows, OVH or real-account acceptance'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL;
