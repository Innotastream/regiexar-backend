<?php
declare(strict_types=1);
require __DIR__ . '/tactical-lifecycle.php';

function effectFixture(array $dots): MemoryConnection {
    $db = fixture();
    $activity = $db->payload('activity');
    $activity['damageOverTime'] = $dots;
    $db->put('activity', $activity);
    return $db;
}
function endEffectCombat(MemoryConnection $db): array {
    $records = applicationDomainRecords($db);
    $pending = [];
    queueOnlineDomainUpsert($pending, $records, 'initiative:scene-one', [...$db->payload('initiative:scene-one'), 'active' => false]);
    applyApplicationDamageOverTimeOnTurn($db, $records, $pending, ['id' => 'account-gm', 'display_name' => 'MJ', 'effective_mode' => 'gm', 'permanent_role' => 'gm']);
    foreach ($pending as $key => $change) $db->put($key, $change['payload']);
    return $db->payload('activity');
}
$dot = ['id' => 'dot-contract-one', 'sceneId' => 'scene-one', 'targetTokenId' => 'token-monster', 'label' => 'Poison', 'formula' => '10', 'damageType' => 'ignore', 'remainingTurns' => 2];
requireTactical(onlineRemainingDotFormula('1d10', 2) === '2d10' && onlineRemainingDotFormula('2d6+3-1d4', 3) === '6d6+9-3d4', 'Remaining dice and constants are multiplied');
$db = effectFixture([$dot, [...$dot, 'id' => 'dot-contract-two']]);
$body = ['effectId' => $dot['id'], 'kind' => 'dot', 'requestId' => 'remove-effect-contract-0001'];
requireTactical(runCommand($db, 'combat.effect.remove', $body)->status === 403, 'Players cannot remove server effects');
requireTactical(runCommand($db, 'combat.effect.remove', $body, true, 'account-gm')->status === 200, 'GM removes exactly one effect');
requireTactical(count($db->payload('activity')['damageOverTime']) === 1, 'Other DOT remains untouched');
requireTactical(runCommand($db, 'combat.effect.remove', $body, true, 'account-gm')->body['deduplicated'] === true, 'Removal retry is idempotent');
$activity = endEffectCombat($db);
requireTactical($activity['damageOverTime'] === [] && count($activity['pendingDotResolutions']) === 1, 'Combat end removes active DOT and retains one pending choice');
$stale = preserveApplicationAbilityExtensions('activity', ['damageOverTime' => [$dot], 'pendingDotResolutions' => [], 'resourceReceipts' => []], $activity);
requireTactical($stale['damageOverTime'] === [] && count($stale['pendingDotResolutions']) === 1 && count($stale['resourceReceipts']) === 1, 'Stale activity cannot restore removed DOT or erase pending choices and receipts');
$body = ['effectId' => 'dot-contract-two', 'decision' => 'roll', 'requestId' => 'resolve-effect-contract-0001'];
$resolved = runCommand($db, 'combat.dot.resolve', $body, true, 'account-gm');
requireTactical($resolved->status === 200 && $resolved->body['roll']['formula'] === '20' && $resolved->body['appliedDamage'] === 20 && (int) $db->payload('token:scene-one:token-monster')['hp'] === 20, 'Calculated remainder applies once to current HP');
requireTactical(runCommand($db, 'combat.dot.resolve', $body, true, 'account-gm')->body['deduplicated'] === true && (int) $db->payload('token:scene-one:token-monster')['hp'] === 20, 'Damage retry never applies twice');
requireTactical(runCommand($db, 'combat.dot.resolve', [...$body, 'decision' => 'custom', 'formula' => '7'], true, 'account-gm')->status === 409, 'Reusing an id for different damage is rejected');
foreach (['custom', 'skip'] as $decision) {
    $db = effectFixture([$dot]); endEffectCombat($db);
    $response = runCommand($db, 'combat.dot.resolve', ['effectId' => $dot['id'], 'decision' => $decision, 'formula' => '7', 'requestId' => 'resolve-' . $decision . '-contract-0001'], true, 'account-gm');
    requireTactical($response->status === 200 && (int) $db->payload('token:scene-one:token-monster')['hp'] === ($decision === 'custom' ? 33 : 40) && $db->payload('activity')['pendingDotResolutions'] === [], 'Custom/skip resolves only the selected remainder');
}
$db = effectFixture([$dot]);
$target = $db->payload('token:scene-one:token-monster'); $target['hp'] = -1; $db->put('token:scene-one:token-monster', $target);
requireTactical(endEffectCombat($db)['pendingDotResolutions'] === [], 'Dead creatures produce no remaining-damage prompt');
$db = effectFixture([[...$dot, 'targetTokenId' => 'token-player']]);
$character = $db->payload('character:character-player'); $character['resources']['hp'] = 0; $db->put('character:character-player', $character);
requireTactical(count(endEffectCombat($db)['pendingDotResolutions']) === 1, 'A KO player remains alive and retains the choice');
$db = effectFixture([$dot]); endEffectCombat($db);
$target = $db->payload('token:scene-one:token-monster'); $target['characterId'] = 'character-player'; $db->put('token:scene-one:token-monster', $target);
$response = runCommand($db, 'combat.dot.resolve', ['effectId' => $dot['id'], 'decision' => 'roll', 'requestId' => 'rebound-effect-contract-0001'], true, 'account-gm');
requireTactical($response->status === 200 && $response->body['targetUnavailable'] === true && (int) $db->payload('character:character-player')['resources']['hp'] === 10, 'Rebinding a token never damages the replacement character');

$db = fixture();
$target = $db->payload('token:scene-one:token-monster'); $target['hidden'] = true; $db->put('token:scene-one:token-monster', $target);
$created = runCommand($db, 'token.roll', ['sceneId' => 'scene-one', 'layerId' => 'ground', 'tokenId' => 'token-monster', 'kind' => 'stat', 'statId' => 'monster-force', 'requestId' => 'reveal-creature-contract-0001'], true, 'account-gm');
requireTactical($created->status === 200, 'Hidden creature roll is created');
$private = $created->body['roll'];
$activity = $db->payload('activity'); $activity['rolls'] = [$private]; $db->put('activity', $activity);
requireTactical(runCommand($db, 'roll.reveal', ['rollId' => $private['id']])->status === 403, 'Revelation requires GM authority');
$response = runCommand($db, 'roll.reveal', ['rollId' => $private['id']], true, 'account-gm');
requireTactical($response->status === 200 && $response->body['roll']['total'] === $private['total'] && !isset($response->body['roll']['outcome']['threshold'], $response->body['roll']['sourceTokenId']), 'Revealing a creature publishes the score without its threshold or position: ' . json_encode($response->body));
requireTactical(runCommand($db, 'roll.reveal', ['rollId' => $private['id']], true, 'account-gm')->body['deduplicated'] === true, 'Revelation is idempotent');
$activity = $db->payload('activity'); $activity['rolls'][] = [...$private, 'id' => 'private-scene-roll', 'sourceSceneId' => 'scene-two', 'mapEvent' => [...($private['mapEvent'] ?? []), 'sceneId' => 'scene-two']]; $activity['rolls'][] = [...$private, 'id' => 'unapplied-damage-roll', 'rollKind' => 'damage']; $db->put('activity', $activity);
requireTactical(runCommand($db, 'roll.reveal', ['rollId' => 'private-scene-roll'], true, 'account-gm')->status === 409, 'An undistributed scene remains private');
requireTactical(runCommand($db, 'roll.reveal', ['rollId' => 'unapplied-damage-roll'], true, 'account-gm')->status === 409, 'Unapplied damage remains private');
echo "Ongoing effects, residual damage and roll revelation contracts passed.\n";
