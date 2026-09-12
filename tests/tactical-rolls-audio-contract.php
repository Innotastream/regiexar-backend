<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/v1/domains.php';
require_once __DIR__ . '/../api/v1/online.php';

$checks = 0;
function requireTactical(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    $GLOBALS['checks'] += 1;
}
function rejectsTactical(callable $action, string $code): void {
    try { $action(); }
    catch (RuntimeException $error) { requireTactical($error->getMessage() === $code, 'Unexpected rejection: ' . $error->getMessage()); return; }
    throw new RuntimeException('Missing rejection: ' . $code);
}

$creature = ['id' => 'monster-one', 'name' => 'Créature', 'stats' => [['id' => 'force', 'label' => 'Force', 'value' => 63]], 'hitThreshold' => 42,
    'damageDice' => '2d6+3', 'weaponText' => 'Griffes 3d4', 'weaponAttacks' => [['id' => 'claws', 'formula' => '3d4', 'damageType' => 'physical']]];
$stat = applicationTacticalRollSpecification($creature, ['kind' => 'stat', 'statId' => 'character-stat-force', 'modifier' => 10, 'modifierMode' => 'threshold']);
requireTactical($stat['threshold'] === 63 && $stat['modifier'] === 10 && $stat['formula'] === '1d100', 'Stored stat and threshold modifier must be authoritative');
$customStat = applicationTacticalRollSpecification($creature, ['kind' => 'custom-stat', 'label' => 'Résistance', 'threshold' => 71, 'modifier' => -12, 'modifierMode' => 'result']);
requireTactical($customStat['threshold'] === 71 && $customStat['formula'] === '1d100-12' && $customStat['resultModifier'] === -12 && $customStat['modifier'] === 0, 'Custom stat result modifiers must preserve the raw die');
$outcome = classifyOnlineD100Outcome(55, $customStat['threshold'], $customStat['modifier'], $customStat['resultModifier']);
requireTactical($outcome['raw'] === 55 && $outcome['result'] === 43 && $outcome['code'] === 'special-success', 'Special outcomes use the raw result with personalized result shown separately');
requireTactical(applicationTacticalRollSpecification($creature, ['kind' => 'hit'])['threshold'] === 42, 'Hit uses the stored threshold');
$luck = applicationTacticalRollSpecification($creature, ['kind' => 'luck', 'formula' => '8d20', 'modifier' => 99, 'rollMode' => 'advantage']);
requireTactical($luck['formula'] === '1d100' && $luck['rollMode'] === 'normal', 'Luck remains a single unmodified d100');
requireTactical(applicationTacticalRollSpecification($creature, ['kind' => 'damage'])['formula'] === '3d4'
    && applicationTacticalRollSpecification($creature, ['kind' => 'damage'])['label'] === 'Dégâts',
    'Default damage uses the first stored weapon and the same label as the player route');
requireTactical(applicationTacticalRollSpecification([...$creature, 'weaponAttacks' => [], 'weaponText' => ''], ['kind' => 'damage'])['formula'] === '2d6+3', 'Damage falls back to its stored base formula without a weapon');
requireTactical(applicationTacticalRollSpecification($creature, ['kind' => 'damage', 'weaponId' => 'claws'])['formula'] === '3d4', 'A chosen weapon uses its stored formula');
requireTactical(applicationTacticalRollSpecification($creature, ['kind' => 'custom-damage', 'formula' => '3d8+4', 'modifier' => 2])['formula'] === '3d8+4', 'Custom damage retains the complete bounded formula without adding the UI modifier twice');
requireTactical(applicationTacticalRollSpecification($creature, ['kind' => 'custom', 'formula' => '2d10'])['formula'] === '2d10'
    && applicationTacticalRollSpecification($creature, ['kind' => 'custom', 'formula' => '2d10'])['label'] === 'Test personnalisé',
    'Custom dice remain supported with the role-independent label');
requireTactical(applicationTacticalRollSpecification($creature, ['kind' => 'custom-damage', 'formula' => '2d10'])['label'] === 'Dégâts personnalisés',
    'Custom damage uses the same vocabulary regardless of the roller role');
foreach ([-1, 101, 70.5, '70', null] as $threshold) rejectsTactical(fn() => applicationTacticalRollSpecification($creature, ['kind' => 'custom-stat', 'threshold' => $threshold]), 'invalid_tactical_roll_threshold');
rejectsTactical(fn() => applicationTacticalRollSpecification($creature, ['kind' => 'custom', 'formula' => str_repeat('1+', 60) . '1']), 'invalid_tactical_roll_formula');
rejectsTactical(fn() => applicationTacticalRollSpecification($creature, ['kind' => 'custom', 'formula' => 'phpinfo()']), 'invalid_tactical_roll_formula');
rejectsTactical(fn() => applicationTacticalRollSpecification($creature, ['kind' => 'custom', 'formula' => '1d20', 'label' => str_repeat('x', 121)]), 'invalid_tactical_roll_label');
rejectsTactical(fn() => applicationTacticalRollSpecification($creature, ['kind' => 'stat', 'statId' => 'missing']), 'token_stat_missing');
rejectsTactical(fn() => applicationTacticalRollSpecification($creature, ['kind' => 'damage', 'weaponId' => 'missing']), 'attack_weapon_missing');
rejectsTactical(fn() => applicationTacticalRollSpecification($creature, ['kind' => 'initiative']), 'invalid_tactical_roll_kind');

$roll = ['formula' => '1d100', 'total' => 42, 'mapEvent' => ['kind' => 'damage']];
foreach (['damage', 'custom-damage'] as $kind) {
    $private = applicationTacticalRollVisibility($roll, ['controllerPlayerId' => 'player-one', 'revealDetailsToPlayers' => true], $kind);
    requireTactical($private['visibility'] === 'gm' && !$private['revealed'] && !isset($private['mapEvent']), 'Presumed damage never becomes a public damage event');
}
requireTactical(applicationTacticalRollVisibility($roll, $creature, 'stat')['visibility'] === 'gm', 'Unrevealed monster stats stay private');
requireTactical(applicationTacticalRollVisibility($roll, ['revealDetailsToPlayers' => true], 'custom')['visibility'] === 'public', 'Explicit revelation permits a public general roll');
foreach (['gm', 'queued'] as $visibility) {
    $spec = applicationTacticalRollSpecification($creature, ['kind' => 'custom', 'formula' => '1d20', 'visibility' => $visibility]);
    $visible = applicationTacticalRollVisibility($roll, ['revealDetailsToPlayers' => true], 'custom', $spec['visibility']);
    requireTactical($visible['visibility'] === $visibility && $visible['revealed'] === false, 'Explicit private or queued visibility must survive on a public source');
}
requireTactical(applicationTacticalRollVisibility($roll, ['controllerPlayerId' => 'player-one', 'hidden' => true], 'luck')['visibility'] === 'gm', 'A hidden token never emits a public roll');

$presentedRoll = [
    'rollerName' => 'Innota', 'characterName' => 'Inho', 'label' => 'Force', 'formula' => '1d100+15',
    'total' => 75, 'rollMode' => 'advantage', 'selectedIndex' => 0,
    'attempts' => [['total' => 75, 'breakdown' => '[60] +15'], ['total' => 42, 'breakdown' => '[27] +15']],
    'outcome' => ['label' => 'Réussite'],
];
$presentation = applicationRollPresentation($presentedRoll);
requireTactical($presentation['character'] === 'Inho' && $presentation['type'] === 'Force (Avantage)'
    && $presentation['calculations'][0]['total'] === '75' && !$presentation['calculations'][0]['ignored']
    && $presentation['calculations'][1]['total'] === '42' && $presentation['calculations'][1]['ignored']
    && $presentation['outcome'] === 'RÉUSSITE', 'MJ and player rolls must share the canonical presentation');
$activityFields = applicationRollActivityFields($presentedRoll);
requireTactical($activityFields === [
    'characterName' => 'Inho',
    'summary' => 'Force (Avantage)',
    'detail' => "1d100+15 : 75\n1d100+15 : 42 (jet ignoré)\nRÉUSSITE",
], 'The private activity log must retain both attempts and the final outcome');
$castRoll = [...$presentedRoll, 'id' => 'cast-roll-one', 'label' => 'Frappe · Lancement · Force', 'visibility' => 'public', 'revealed' => true];
$effectRoll = [...$presentedRoll, 'id' => 'effect-roll-one', 'label' => 'Frappe', 'formula' => '2d6', 'outcome' => null];
$bundle = onlineAbilityRollBundle(['success' => true, 'roll' => $castRoll], $effectRoll);
requireTactical($bundle['roll'] === $effectRoll && $bundle['castRoll'] === $castRoll && $bundle['effectRoll'] === $effectRoll
    && array_column($bundle['rolls'], 'id') === ['cast-roll-one', 'effect-roll-one'],
    'A successful checked ability exposes cast then effect while retaining the historical effect roll');
$failedBundle = onlineAbilityRollBundle(['success' => false, 'roll' => $castRoll]);
requireTactical($failedBundle['roll'] === $castRoll && $failedBundle['castRoll'] === $castRoll && $failedBundle['effectRoll'] === null
    && array_column($failedBundle['rolls'], 'id') === ['cast-roll-one'],
    'A failed checked ability exposes only its canonical casting roll');
requireTactical(array_column(applicationUniqueRolls([$castRoll, $castRoll, null, $effectRoll]), 'id') === ['cast-roll-one', 'effect-roll-one'],
    'Roll bundles filter null values and duplicate ids without changing order');
requireTactical(array_column(applicationPublicResultRolls(['roll' => $effectRoll, 'rolls' => [$castRoll, [...$effectRoll, 'visibility' => 'gm', 'revealed' => false]]]), 'id') === ['cast-roll-one'],
    'Discord selection keeps every public bundled roll and withholds private effects');
$legacyPresentation = applicationRollPresentation([
    'rollerName' => 'Innota', 'characterName' => '', 'label' => 'Chance', 'formula' => '1d100', 'total' => 38,
]);
requireTactical($legacyPresentation['character'] === 'Innota', 'An empty legacy character name must fall back to the roller name, as in the client formatter');

$args = ['kind' => 'stat', 'tokenId' => 'monster-one', 'statId' => 'force'];
$signature = applicationTacticalRollSignature('scene-one', $args);
requireTactical($signature === '["token.roll","scene-one","monster-one","","stat","force",""]', 'The PHP signature must match the Node contract');
requireTactical(applicationTacticalRollSignature('scene-one', [...$args, 'formula' => '1d2', 'modifier' => 50]) === $signature, 'A changed modifier cannot reroll the same request');
$receipt = ['kind' => 'token-roll', 'requestId' => 'tactical-roll-request-0001', 'accountId' => 'gm-one', 'actionId' => 'action-one', 'sourceKey' => 'monster-one', 'expiresAt' => 5000, 'requestSignature' => $signature, 'result' => ['roll' => ['id' => 'real-roll-one', 'total' => 42], 'initiativeUpdated' => false]];
$replayed = applicationTacticalRollReceipt(['resourceReceipts' => [$receipt]], $receipt['requestId'], 'gm-one', $signature, 1000);
requireTactical($replayed['roll']['id'] === 'real-roll-one' && $replayed['roll']['total'] === 42 && $replayed['deduplicated'], 'A retry must return the original roll without rerolling');
rejectsTactical(fn() => applicationTacticalRollReceipt(['resourceReceipts' => [$receipt]], $receipt['requestId'], 'gm-two', $signature, 1000), 'tactical_roll_receipt_forbidden');
rejectsTactical(fn() => applicationTacticalRollReceipt(['resourceReceipts' => [$receipt]], $receipt['requestId'], 'gm-one', applicationTacticalRollSignature('scene-one', [...$args, 'statId' => 'agility']), 1000), 'tactical_roll_request_mismatch');
requireTactical(applicationTacticalRollReceipt(['resourceReceipts' => [$receipt]], $receipt['requestId'], 'gm-one', $signature, 6000) === null, 'Expired receipts are pruned');
$receipt['expiresAt'] = (int) floor(microtime(true) * 1000) + 60000;
requireTactical(preserveApplicationAbilityExtensions('activity', ['resourceReceipts' => []], ['resourceReceipts' => [$receipt]])['resourceReceipts'] === [$receipt], 'Old client normalizers cannot erase an authoritative tactical receipt');

requireTactical(validApplicationAudioPlayback([]), 'Historical empty playback remains valid');
requireTactical(validApplicationAudioPlayback(['music' => ['loop' => true], 'ambience' => ['loop' => false]]), 'Each channel can choose its own loop');
foreach ([false, ['music' => ['loop' => 'true']], ['ambience' => ['loop' => 1]], ['music' => ['loop' => null]], ['music' => false], ['music' => ['bad-list']]] as $invalid) requireTactical(!validApplicationAudioPlayback($invalid), 'Malformed playback or nonboolean loop must be rejected');
$defaults = preserveApplicationAudioLoops(['playback' => ['muted' => false]]);
requireTactical($defaults['playback']['music']['loop'] === false && $defaults['playback']['ambience']['loop'] === true, 'Historical defaults are music off and ambience on');
$previous = ['playback' => ['music' => ['loop' => true], 'ambience' => ['loop' => false]]];
$oldClient = preserveApplicationAudioLoops(['playback' => ['music' => ['playing' => true], 'ambience' => ['playing' => true]]], $previous);
requireTactical($oldClient['playback']['music']['loop'] === true && $oldClient['playback']['ambience']['loop'] === false, 'Omission by an old client preserves both current loop values');
$explicit = preserveApplicationAudioLoops(['playback' => ['music' => ['loop' => false], 'ambience' => ['loop' => true]]], $previous);
requireTactical($explicit['playback']['music']['loop'] === false && $explicit['playback']['ambience']['loop'] === true, 'Explicit toggles override old loop values');
requireTactical(applicationDomainPayloadForComparison('audio', ['tracks' => [], 'playback' => []]) === applicationDomainPayloadForComparison('audio', ['tracks' => [], 'playback' => ['music' => ['loop' => false], 'ambience' => ['loop' => true]]]), 'Default loop normalization does not cause false revisions');

echo json_encode(['status' => 'ok', 'checks' => $checks, 'scope' => 'pure PHP contracts; no OVH, Windows or real-account acceptance'], JSON_THROW_ON_ERROR) . PHP_EOL;
