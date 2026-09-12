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

requireCasting(
    applicationRoundCountLabel(0) === '0 rounds'
        && applicationRoundCountLabel(1) === '1 round'
        && applicationRoundCountLabel(3) === '3 rounds',
    'Le vocabulaire utilisateur des recharges doit refléter initiative.round sans parler de tours.'
);

$signatureBase = [
    'sourceTokenId' => 'token-one', 'characterId' => 'character-one', 'abilityId' => 'ability-one',
    'targetTokenId' => 'token-two',
];
$plainAbilitySignature = applicationAbilityRequestSignature('ability.use', 'scene-one', $signatureBase, false);
requireCasting(
    $plainAbilitySignature === applicationAbilityRequestSignature('ability.use', 'scene-one', [
        ...$signatureBase, 'rollMode' => 'disadvantage', 'modifier' => 37, 'modifierMode' => 'result',
        'hitModifier' => -12, 'hitModifierMode' => 'result',
    ], false),
    'Ignored d100 options must not alter an unchecked ability receipt'
);
$checkedAbilitySignature = applicationAbilityRequestSignature('ability.use', 'scene-one', [
    ...$signatureBase, 'rollMode' => 'advantage', 'modifier' => 12, 'modifierMode' => 'threshold',
], true);
requireCasting(
    $checkedAbilitySignature !== applicationAbilityRequestSignature('ability.use', 'scene-one', [
        ...$signatureBase, 'rollMode' => 'disadvantage', 'modifier' => 12, 'modifierMode' => 'threshold',
    ], true)
    && $checkedAbilitySignature !== applicationAbilityRequestSignature('ability.use', 'scene-one', [
        ...$signatureBase, 'rollMode' => 'advantage', 'modifier' => 13, 'modifierMode' => 'threshold',
    ], true)
    && $checkedAbilitySignature !== applicationAbilityRequestSignature('ability.use', 'scene-one', [
        ...$signatureBase, 'rollMode' => 'advantage', 'modifier' => 12, 'modifierMode' => 'result',
    ], true),
    'A checked ability receipt must bind its effective d100 mode, modifier and modifier mode'
);
$uncheckedEffectSignature = applicationAbilityRequestSignature('token.roll', 'scene-one', [
    ...$signatureBase, 'modifier' => 5, 'rollMode' => 'advantage',
], false);
requireCasting(
    $uncheckedEffectSignature === applicationAbilityRequestSignature('token.roll', 'scene-one', [
        ...$signatureBase, 'modifier' => 5, 'rollMode' => 'disadvantage',
    ], false)
    && $uncheckedEffectSignature !== applicationAbilityRequestSignature('token.roll', 'scene-one', [
        ...$signatureBase, 'modifier' => 6, 'rollMode' => 'advantage',
    ], false),
    'An unchecked simple ability ignores roll mode but binds its effective formula modifier'
);
$uncheckedAttack = [
    ...$signatureBase, 'attackKind' => 'ability', 'attackId' => 'ability-one',
    'rollMode' => 'advantage', 'hitModifier' => 20, 'hitModifierMode' => 'result',
    'damageModifier' => 4, 'opposed' => true,
];
$uncheckedAttackSignature = applicationAbilityRequestSignature('token.attack', 'scene-one', $uncheckedAttack, false);
requireCasting(
    $uncheckedAttackSignature === applicationAbilityRequestSignature('token.attack', 'scene-one', [
        ...$uncheckedAttack, 'rollMode' => 'disadvantage', 'hitModifier' => -50, 'hitModifierMode' => 'threshold',
    ], false)
    && $uncheckedAttackSignature !== applicationAbilityRequestSignature('token.attack', 'scene-one', [
        ...$uncheckedAttack, 'damageModifier' => 5,
    ], false)
    && $uncheckedAttackSignature !== applicationAbilityRequestSignature('token.attack', 'scene-one', [
        ...$uncheckedAttack, 'opposed' => false,
    ], false),
    'An unchecked ability attack ignores d100 options but binds damage and opposition'
);
requireCasting(
    onlineAttackRequestSignature('scene-one', $uncheckedAttack, false) === onlineAttackRequestSignature('scene-one', [
        ...$uncheckedAttack, 'rollMode' => 'disadvantage', 'hitModifier' => -50, 'hitModifierMode' => 'threshold',
    ], false)
    && onlineAttackRequestSignature('scene-one', $uncheckedAttack, true) !== onlineAttackRequestSignature('scene-one', [
        ...$uncheckedAttack, 'rollMode' => 'disadvantage',
    ], true),
    'The attack receipt must apply the same checked-versus-ignored d100 distinction'
);

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
foreach ([['code' => 'failure', 'success' => false, 'result' => 1], ['code' => 'critical-failure', 'success' => false, 'result' => 100], null] as $defence) {
    requireCasting(!onlineDefenderWinsOpposition($automaticOutcome, $defence), 'An absent or failed defence must not defeat an attack with no casting die');
}

foreach ([1, 11, 22, 33, 44] as $raw) {
    $outcome = classifyOnlineD100Outcome($raw, 0);
    requireCasting($outcome['code'] === 'critical-success' && $outcome['success'] === true, "Critical success $raw must override the threshold");
    requireCasting($outcome['immediate'] === true && $outcome['breaksOpposition'] === true && $outcome['requiresGmValidation'] === true, "Critical success $raw must await explicit GM validation");
}
$special = classifyOnlineD100Outcome(55, 0);
requireCasting($special['code'] === 'special-success' && $special['success'] === true && $special['breaksOpposition'] === true && $special['requiresGmValidation'] === true, 'The special 55 result must immediately break opposition and await the GM');
foreach ([66, 77, 88, 99, 100] as $raw) {
    $outcome = classifyOnlineD100Outcome($raw, 100);
    requireCasting($outcome['code'] === 'critical-failure' && $outcome['success'] === false, "Critical failure $raw must override the threshold");
    requireCasting($outcome['immediate'] === true && $outcome['breaksOpposition'] === true && $outcome['requiresGmValidation'] === true, "Critical failure $raw must await explicit GM validation");
}
$ordinaryTen = classifyOnlineD100Outcome(10, 50);
requireCasting($ordinaryTen['code'] === 'success' && ($ordinaryTen['immediate'] ?? false) === false, 'A raw 10 is now an ordinary statistic success');
$genericOne = classifyOnlineD100Outcome(1, 50, 0, 0, false);
requireCasting($genericOne['code'] === 'success' && ($genericOne['immediate'] ?? false) === false, 'Generic d100 rolls must not acquire remarkable effects');
requireCasting(classifyOnlineD100Outcome(42) === null, 'Ordinary Chance results without a threshold keep no success label');

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
$castRoll = [
    'id' => 'cast-roll-one', 'characterName' => 'Inho', 'label' => 'Onde · Lancement · Force',
    'formula' => '1d100', 'total' => 42, 'rollMode' => 'normal', 'visibility' => 'public', 'revealed' => true,
    'outcome' => ['label' => 'Réussite', 'success' => true],
];
$effectRoll = [
    'id' => 'effect-roll-one', 'characterName' => 'Inho', 'label' => 'Onde',
    'formula' => '1d6', 'total' => 5, 'rollMode' => 'normal', 'visibility' => 'public', 'revealed' => true,
];
$successfulBundle = onlineAbilityRollBundle(['success' => true, 'roll' => $castRoll], $effectRoll);
requireCasting(
    $successfulBundle['roll'] === $effectRoll
        && $successfulBundle['castRoll'] === $castRoll
        && $successfulBundle['effectRoll'] === $effectRoll
        && array_column($successfulBundle['rolls'], 'id') === ['cast-roll-one', 'effect-roll-one'],
    'A successful checked ability exposes cast then effect while preserving the historical effect roll'
);
$failedBundle = onlineAbilityRollBundle(['success' => false, 'roll' => $castRoll]);
requireCasting(
    $failedBundle['roll'] === $castRoll
        && $failedBundle['castRoll'] === $castRoll
        && $failedBundle['effectRoll'] === null
        && array_column($failedBundle['rolls'], 'id') === ['cast-roll-one'],
    'A failed checked ability exposes only its canonical casting roll'
);
$deduplicatedBundle = onlineAbilityRollBundle(['success' => true, 'roll' => $castRoll], $castRoll);
requireCasting(array_column($deduplicatedBundle['rolls'], 'id') === ['cast-roll-one'], 'A repeated roll id cannot be exposed or journaled twice');
$discordBundle = onlineDiscordResultRollContent($successfulBundle);
requireCasting(
    substr_count($discordBundle, '**Inho**') === 2
        && strpos($discordBundle, 'Onde · Lancement · Force') < strpos($discordBundle, "\n\n**Inho**\nOnde\n")
        && substr_count($discordBundle, '1d100 : 42') === 1
        && substr_count($discordBundle, '1d6 : 5') === 1,
    'Discord renders each public ability roll once and in cast/effect order'
);
$legacyTimer = $timer; unset($legacyTimer['abilityId'], $legacyTimer['tokenId']);
$keptTimer = preserveApplicationAbilityExtensions('activity', ['actionTimers' => [$legacyTimer]], ['actionTimers' => [$timer]])['actionTimers'][0];
requireCasting($keptTimer['abilityId'] === $timer['abilityId'] && $keptTimer['tokenId'] === '', 'Old timer normalization must retain its managed ability association');
$remarkableAttack = [
    'id' => 'attack-remarkable-old-client',
    'damageComponents' => [['type' => 'physical', 'formula' => '1d6']],
    'damageModifier' => 7, 'validationKind' => 'outcome', 'provisionalStatus' => 'applied',
    'hit' => [
        'raw' => 8, 'outcome' => ['success' => true], 'label' => 'Onde · Force', 'characterName' => 'Inho',
        'formula' => '1d100+3', 'total' => 11, 'rollMode' => 'advantage', 'selectedIndex' => 0,
        'attempts' => [['rawD100' => 8, 'total' => 11], ['rawD100' => 42, 'total' => 45]],
    ],
    'opposition' => [
        'raw' => 80, 'outcome' => ['success' => false], 'label' => 'Opposition · Agilité', 'characterName' => 'Garde',
        'formula' => '1d100', 'total' => 80, 'rollMode' => 'disadvantage', 'selectedIndex' => 1,
        'attempts' => [['rawD100' => 60, 'total' => 60], ['rawD100' => 80, 'total' => 80]],
    ],
];
$oldClientAttack = [
    'id' => $remarkableAttack['id'],
    'hit' => ['raw' => 8, 'outcome' => ['success' => true]],
    'opposition' => ['raw' => 80, 'outcome' => ['success' => false]],
];
$preservedAttack = preserveApplicationAbilityExtensions('activity', ['pendingAttacks' => [$oldClientAttack]], ['pendingAttacks' => [$remarkableAttack]])['pendingAttacks'][0];
foreach (['damageComponents', 'damageModifier', 'validationKind', 'provisionalStatus'] as $field) requireCasting($preservedAttack[$field] === $remarkableAttack[$field], 'Old clients must preserve pending attack field ' . $field);
foreach (['hit', 'opposition'] as $rollKey) foreach (['label', 'characterName', 'formula', 'total', 'rollMode', 'selectedIndex', 'attempts'] as $field) {
    requireCasting($preservedAttack[$rollKey][$field] === $remarkableAttack[$rollKey][$field], 'Old clients must preserve ' . $rollKey . ' roll field ' . $field);
}

$damage = onlineRollAttackDamage(['damageComponents' => $ability['damageComponents'], 'damageRollMode' => 'normal'], ['armorCategory' => 'heavy', 'armor' => 50, 'magicArmor' => 25]);
requireCasting($damage['damage']['rawDamage'] === 37, 'Mixed raw damage must sum all components');
$parts = $damage['damage']['components'];
requireCasting($parts[0]['armorPercent'] === onlineAttackArmorPercent(['armorCategory' => 'heavy', 'armor' => 50], 'physical'), 'Physical damage must use physical armor');
requireCasting($parts[1]['finalDamage'] === 15 && $parts[1]['armorPercent'] === 25, 'Magical damage must use its own percentage');
requireCasting($parts[2]['finalDamage'] === 7 && $parts[2]['armorPercent'] === 0, 'Ignoring armor must bypass both armor types');
requireCasting($damage['damage']['finalDamage'] === array_sum(array_column($parts, 'finalDamage')), 'Mixed damage must sum separately reduced values');
$crossedParts = [
    ['type' => 'physical', 'formula' => '1d6'],
    ['type' => 'magical', 'formula' => '1d6'],
];
$crossedValues = [6, 1, 1, 5];
$crossedIndex = 0;
$crossedDamage = onlineRollAttackDamage(
    ['damageComponents' => $crossedParts, 'damageRollMode' => 'advantage'],
    ['armor' => 0, 'magicArmor' => 0],
    static function (string $formula) use (&$crossedValues, &$crossedIndex): array {
        $value = $crossedValues[$crossedIndex++];
        return ['formula' => $formula, 'total' => $value, 'breakdown' => '[' . $value . ']', 'rawD100' => null];
    }
);
requireCasting(
    ($crossedDamage['rolled']['rollMode'] ?? '') === 'normal'
        && ($crossedDamage['rolled']['selectedIndex'] ?? -1) === 0
        && array_column($crossedDamage['rolled']['attempts'] ?? [], 'total') === [7]
        && array_column($crossedDamage['damage']['components'] ?? [], 'rawDamage') === [6, 1]
        && ($crossedDamage['damage']['rawDamage'] ?? 0) === 7,
    'Mixed damage ignores a forged advantage and rolls every component exactly once'
);
$crossedValues = [6, 1, 1, 5];
$crossedIndex = 0;
$crossedDisadvantage = onlineRollAttackDamage(
    ['damageComponents' => $crossedParts, 'damageRollMode' => 'disadvantage'],
    ['armor' => 0, 'magicArmor' => 0],
    static function (string $formula) use (&$crossedValues, &$crossedIndex): array {
        $value = $crossedValues[$crossedIndex++];
        return ['formula' => $formula, 'total' => $value, 'breakdown' => '[' . $value . ']', 'rawD100' => null];
    }
);
requireCasting(
    ($crossedDisadvantage['rolled']['rollMode'] ?? '') === 'normal'
        && ($crossedDisadvantage['rolled']['selectedIndex'] ?? -1) === 0
        && array_column($crossedDisadvantage['rolled']['attempts'] ?? [], 'total') === [7]
        && array_column($crossedDisadvantage['damage']['components'] ?? [], 'rawDamage') === [6, 1]
        && ($crossedDisadvantage['damage']['rawDamage'] ?? 0) === 7,
    'Mixed damage ignores a forged disadvantage before applying armor'
);

$calculatedAttack = [
    'sourceName' => 'Héros', 'targetName' => 'Garde', 'attackName' => 'Lame', 'status' => 'applied',
    'damageType' => 'physical', 'damageModifier' => 3, 'appliedDamage' => 12,
    'hit' => ['raw' => 38, 'statLabel' => 'Force', 'outcome' => ['raw' => 38, 'result' => 36, 'resultModifier' => -2, 'baseThreshold' => 50, 'modifier' => 5, 'threshold' => 55, 'code' => 'success', 'label' => 'RÉUSSITE', 'success' => true]],
    'opposition' => ['raw' => 71, 'statLabel' => 'Agilité', 'outcome' => ['raw' => 71, 'baseThreshold' => 45, 'modifier' => -5, 'threshold' => 40, 'code' => 'failure', 'label' => 'ÉCHEC', 'success' => false]],
    'damage' => ['formula' => '2d6+3', 'rawDamage' => 18, 'preventedDamage' => 6, 'finalDamage' => 12, 'components' => [['type' => 'physical', 'formula' => '2d6+3', 'breakdown' => '6 + 9 + 3', 'rawDamage' => 18, 'armorPercent' => 35, 'preventedDamage' => 6, 'finalDamage' => 12]]],
];
$history = onlineAttackHistoryDetail($calculatedAttack);
requireCasting(str_contains($history, 'Jet ATK · Force') && str_contains($history, 'Jet OPP · Agilité')
    && str_contains($history, 'dé brut 38 −2 = 36') && str_contains($history, 'seuil 50 +5 = 55'),
    'GM history must show the roll types and complete hit calculation');
requireCasting(str_contains($history, 'armure 35 % : −6') && str_contains($history, 'total brut 18') && str_contains($history, 'final 12'), 'GM history must show armor absorption and final damage');
$discord = onlineAttackDiscordContent($calculatedAttack);
requireCasting(str_contains($discord, 'Modificateurs') && str_contains($discord, 'bonus de seuil +5') && str_contains($discord, 'bonus de résultat −2') && str_contains($discord, 'bonus de dégâts +3'), 'Discord must show the simplified modifiers');
requireCasting(str_contains($discord, 'Jet DMG **12** — PV perdus') && !str_contains($discord, '2d6+3') && !str_contains($discord, 'Jet DMG **18**'), 'Discord must show only the applied public damage, never the raw roll used to infer armor');
requireCasting(!str_contains($discord, 'armure') && !str_contains($discord, '35 %') && !str_contains($discord, 'maximumHp'), 'Discord must not expose armor or private health state');

echo json_encode(['checks' => $checks, 'status' => 'ok', 'scope' => 'pure PHP casting authority; no Windows, OVH or real-account acceptance'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL;
