<?php
declare(strict_types=1);
require __DIR__ . '/tactical-lifecycle.php';

function counterDefenseFixture(bool $noDefense = false): array {
    $db = fixture();
    $character = $db->payload('character:character-player');
    $character['abilities'] = [['id' => 'orb-defense', 'name' => 'Orbes', 'effect' => 'complex', 'formula' => '0',
        'castingStatId' => '', 'restRecharge' => 'long', 'usesPerRest' => 1,
        ...($noDefense ? ['noDefensePossible' => true] : []),
        'workflow' => ['version' => 7, 'steps' => [['id' => 'orbs', 'type' => 'counter', 'initial' => 2, 'maximum' => 3,
            'gainAmount' => 1, 'gainTrigger' => 'hp-loss-or-condition', 'gainCondition' => 'Hémorragie', 'radiusCells' => 20,
            'combatPersistent' => true, 'spendOnSourceTurn' => true,
            'spendOptions' => [['id' => 'explosion', 'label' => 'Explosion', 'cost' => 1,
                'effect' => ['kind' => 'damage', 'formula' => '3', 'damageType' => 'ignore']]]]]]]];
    $db->put('character:character-player', $character);
    $db->put('map:scene-one', ['gridSize' => 50, 'naturalWidth' => 1000, 'naturalHeight' => 1000]);
    $db->put('initiative:scene-one', ['active' => true, 'combatId' => 'counter-defense-combat', 'round' => 1,
        'order' => ['token-player', 'token-monster'], 'currentIndex' => 0, 'turnSerial' => 1]);
    $target = $db->payload('token:scene-one:token-monster'); $target['conditions'] = ['Hémorragie'];
    $db->put('token:scene-one:token-monster', $target);
    $start = runCommand($db, 'ability.complex', ['action' => 'start', 'sceneId' => 'scene-one', 'layerId' => 'ground',
        'sourceTokenId' => 'token-player', 'abilityId' => 'orb-defense', 'requestId' => 'counter-defense-start-0001']);
    requireTactical($start->status === 200 && isset($start->body['execution']), 'Counter starts without another casting roll: '.$start->getMessage());
    return [$db, $start->body['execution']];
}
function counterDefenseSpend(MemoryConnection $db, array $execution, ?bool $opposed = true): array {
    $body = ['action' => 'command', 'executionId' => $execution['id'], 'expectedRevision' => $execution['revision'],
        'requestId' => 'counter-defense-spend-0001', 'command' => ['action' => 'counter-spend',
            'optionId' => 'explosion', 'targetTokenId' => 'token-monster', ...($opposed === null ? [] : ['opposed' => $opposed])]];
    return [$body, runCommand($db, 'ability.complex', $body)];
}

[$db, $execution] = counterDefenseFixture();
[$body, $spend] = counterDefenseSpend($db, $execution);
requireTactical($spend->status === 200 && $spend->body['chargeEffect']['status'] === 'awaiting-opposition', 'Defense creates a real pending attack: '.$spend->getMessage());
$attack = $db->payload('activity')['pendingAttacks'][0];
requireTactical($attack['hit']['outcome']['automatic'] && !isset($attack['damageRoll']) && $attack['damage'] === [], 'No invented hit or premature damage roll.');
requireTactical($db->payload('activity')['abilityExecutions'][0]['stepStates']['orbs']['value'] === 1
    && (float) $db->payload('token:scene-one:token-monster')['hp'] === 40.0, 'One orb is lost at launch, without wound or recharge.');
$revision = $db->revision;
$again = runCommand($db, 'ability.complex', $body);
requireTactical($again->body['deduplicated'] && $db->revision === $revision && count($db->payload('activity')['pendingAttacks']) === 1, 'Replay spends no second orb and creates no second invitation.');
requireTactical(runCommand($db, 'ability.complex', [...$body, 'command' => [...$body['command'], 'opposed' => false]])->status === 409, 'Replay cannot change the defense choice.');
requireTactical(runCommand($db, 'token.attack.oppose', ['attackId' => $attack['id'], 'requestId' => 'counter-intruder-defense'], false, 'intruder')->status === 403, 'Only the target controller or GM can defend.');
$cancelled = runCommand($db, 'token.attack.oppose', ['attackId' => $attack['id'], 'requestId' => 'counter-cancel-defense', 'decision' => 'cancel'], true, 'account-gm');
requireTactical($cancelled->status === 200 && $db->payload('activity')['abilityExecutions'][0]['stepStates']['orbs']['value'] === 1, 'Cancelling the pending attack does not refund its orb.');

// Select an ordinary real d100 success without adding a client-side dice override.
$defended = null;
for ($trial = 0; $trial < 100 && $defended === null; $trial++) {
    [$candidate, $execution] = counterDefenseFixture();
    [, $spent] = counterDefenseSpend($candidate, $execution);
    $attackId = $spent->body['chargeEffect']['attackId'];
    $result = runCommand($candidate, 'token.attack.oppose', ['attackId' => $attackId, 'requestId' => 'counter-success-defense',
        'statId' => 'monster-force', 'modifier' => -100, 'modifierMode' => 'result'], true, 'account-gm');
    if ($result->status === 200 && ($result->body['attack']['status'] ?? '') === 'defended') $defended = [$candidate, $result];
}
requireTactical($defended !== null, 'A successful defense defeats an automatic orb attack.');
[$db, $defense] = $defended;
requireTactical((float) $db->payload('token:scene-one:token-monster')['hp'] === 40.0
    && $db->payload('activity')['abilityExecutions'][0]['stepStates']['orbs']['value'] === 1
    && !isset($defense->body['attack']['damageRoll']), 'Winning defense causes no damage, refund or hemorrhage recharge.');
$revision = $db->revision;
$again = runCommand($db, 'token.attack.oppose', ['attackId' => $defense->body['attack']['id'], 'requestId' => 'counter-success-defense',
    'statId' => 'monster-force', 'modifier' => -100, 'modifierMode' => 'result'], true, 'account-gm');
requireTactical($again->body['deduplicated'] && $db->revision === $revision, 'Defense replay keeps the spent orb and the exact original roll.');

[$db, $execution] = counterDefenseFixture();
[, $spent] = counterDefenseSpend($db, $execution, false);
requireTactical($spent->body['chargeEffect']['status'] === 'applied' && (float) $db->payload('token:scene-one:token-monster')['hp'] === 37.0
    && $db->payload('activity')['abilityExecutions'][0]['stepStates']['orbs']['value'] === 2, 'Explicitly omitted defense applies damage and one legitimate hemorrhage recharge: '.json_encode([$spent->body, $db->payload('activity')['abilityExecutions'][0]['stepStates']['orbs'], $db->payload('token:scene-one:token-monster')['hp']]));

[$db, $execution] = counterDefenseFixture(true);
[, $forbidden] = counterDefenseSpend($db, $execution, true);
requireTactical($forbidden->status === 400 && $db->payload('activity')['abilityExecutions'][0]['stepStates']['orbs']['value'] === 2, 'The explicit skill rule rejects a forged defense invitation without spending.');
[, $spent] = counterDefenseSpend($db, $execution, null);
requireTactical($spent->body['chargeEffect']['status'] === 'applied' && $db->payload('activity')['pendingAttacks'] === [], 'An unblockable counter attack needs no manual defense choice.');
requireTactical($db->payload('character:character-player')['abilities'][0]['restRecharge'] === 'long'
    && $db->payload('character:character-player')['abilities'][0]['usesPerRest'] === 1, 'Counter uses preserve one activation per long rest.');

$db = fixture(); $character = $db->payload('character:character-player');
$character['abilities'] = [['id' => 'unblockable', 'name' => 'Inévitable', 'effect' => 'damage', 'formula' => '5', 'damageType' => 'ignore',
    'castingStatId' => '', 'noDefensePossible' => true]]; $db->put('character:character-player', $character);
$result = runCommand($db, 'token.attack', ['sceneId' => 'scene-one', 'sourceTokenId' => 'token-player', 'targetTokenId' => 'token-monster',
    'attackKind' => 'ability', 'attackId' => 'unblockable', 'statId' => '', 'opposed' => true, 'requestId' => 'unblockable-classic-0001']);
requireTactical($result->status === 200 && $db->payload('activity')['attackReceipts'][0]['attack']['opposed'] === false
    && $result->body['attack']['status'] === 'applied', 'Classic abilities enforce the same explicit no-defense rule.');
echo "counter-defense-contract: ok\n";
