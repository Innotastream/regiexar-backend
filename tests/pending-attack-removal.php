<?php
declare(strict_types=1);

define('XAR_SPECIAL_FIXTURE_ONLY', true);
require __DIR__ . '/tactical-special-effects.php';

function requireGmIdentity(PDO $connection): array
{
    return ['id' => 'account-gm', 'display_name' => 'MJ test', 'effective_mode' => 'gm', 'permanent_role' => 'gm'];
}

function removeAttackToken(MemoryConnection $connection, array $changes): TestResponse
{
    $GLOBALS['testBody'] = ['stateSchemaVersion' => XAR_SESSION_SCHEMA_VERSION,
        'domainSchemaVersion' => XAR_DOMAIN_SCHEMA_VERSION, 'changes' => $changes];
    try {
        patchApplicationDomains($connection);
    } catch (TestResponse $response) {
        return $response;
    }
    throw new RuntimeException('No domain response');
}

function pendingRemovalAttack(string $name, string $status, string $target = 'token-monster', string $scene = 'scene-one'): array
{
    return [
        'id' => 'attack-removal-' . $name, 'requestId' => 'request-removal-' . $name,
        'sceneId' => $scene, 'layerId' => 'ground', 'sourceTokenId' => 'token-player',
        'targetTokenId' => $target, 'status' => $status, 'accountId' => 'account-player',
        'attackKind' => 'weapon', 'attackId' => 'weapon-1', 'damageFormula' => '5',
        'damageType' => 'physical', 'damage' => ['finalDamage' => 5], 'finalDamage' => 5,
        'createdAt' => (int) floor(microtime(true) * 1000),
    ];
}

function pendingRemovalReceipt(array $attack): array
{
    return [
        'requestId' => $attack['requestId'], 'accountId' => $attack['accountId'],
        'requestSignature' => 'recorded-' . $attack['requestId'],
        'expiresAt' => $attack['createdAt'] + XAR_ATTACK_RECEIPT_TTL_MILLISECONDS,
        'attack' => $attack,
    ];
}

$db = specialFixture();
$waiting = pendingRemovalAttack('opposition', 'awaiting-opposition');
$decision = pendingRemovalAttack('validation', 'pending');
$neighbor = pendingRemovalAttack('other-target', 'pending', 'token-monster-two');
$otherScene = pendingRemovalAttack('other-scene', 'awaiting-opposition', 'token-monster', 'scene-two');
$completed = [...pendingRemovalAttack('historical', 'applied'), 'appliedDamage' => 5];
$zone = [...pendingRemovalAttack('area-decision', 'pending', 'token-player'),
    'areaGate' => ['version' => 1, 'targetTokenIds' => ['token-monster', 'token-monster-two']],
    'validationKind' => 'area-gate'];
$struckDot = ['id'=>'dot-delete-defender','sceneId'=>'scene-one','targetTokenId'=>'token-monster','label'=>'Plaie','formula'=>'5','remainingTurns'=>3];
$neighborDot = [...$struckDot, 'id'=>'dot-delete-neighbor', 'targetTokenId'=>'token-monster-two'];
$otherSceneDot = [...$struckDot, 'id'=>'dot-delete-homonym', 'sceneId'=>'scene-two'];
$before = [
    ...$db->payload('activity'),
    'pendingAttacks' => [$waiting, $decision, $neighbor, $otherScene, $zone],
    'attackReceipts' => array_map('pendingRemovalReceipt', [$waiting, $decision, $neighbor, $otherScene, $completed, $zone]),
    'actionTimers' => [['id' => 'timer-removal-case', 'characterId' => 'character-player', 'abilityId' => 'used-ability',
        'remainingRounds' => 2, 'restUseCount' => 1]],
    'damageOverTime'=>[$struckDot, $neighborDot, $otherSceneDot],
    'pendingDotResolutions'=>[$struckDot, $otherSceneDot],
];
$db->put('activity', $before);
$partyKey = 'token:scene-one:token-monster';
$neighborKey = 'token:scene-one:token-monster-two';
$neighborBefore = $db->payload($neighborKey);
$sourceBefore = $db->payload('character:character-player');
$delete = ['key' => $partyKey, 'operation' => 'delete', 'expectedRevision' => $db->domains[$partyKey]['revision']];
$stale = removeAttackToken($db, [[...$delete, 'expectedRevision' => $delete['expectedRevision'] - 1]]);
specialCheck($stale->status === 409 && $db->payload('activity') === $before && $db->payload($partyKey) !== [],
    'A stale token revision cannot remove the token or its pending attacks.');

// The Store client may send an activity snapshot after already stripping the
// selected token from its local pending list and from its replay receipts.
$clientSnapshot = [...$before,
    'pendingAttacks' => [$neighbor, $zone],
    'attackReceipts' => array_map('pendingRemovalReceipt', [$neighbor, $zone])];
$result = removeAttackToken($db, [$delete, ['key' => 'activity', 'operation' => 'upsert',
    'expectedRevision' => $db->domains['activity']['revision'], 'payload' => $clientSnapshot]]);
specialCheck($result->status === 200 && $db->payload($partyKey) === [],
    'The real MJ patch removes the token: ' . json_encode($result->body));
specialCheck(in_array('activity', array_column($result->body['domains'], 'key'), true),
    'The authoritative activity delta is committed atomically with the deletion.');
$after = $db->payload('activity');
specialCheck(array_column($after['pendingAttacks'], 'id') === [$neighbor['id'], $otherScene['id']],
    'Only the removed party’s opposition, validation and unconfirmed area decision leave the queue.');
specialCheck($after['damageOverTime'] === [$neighborDot, $otherSceneDot]
    && $after['pendingDotResolutions'] === [$otherSceneDot],
    'Deleting the exact target retires its active and pending periodics while preserving other scenes.');
$receipts = array_column($after['attackReceipts'], null, 'requestId');
foreach ([$waiting, $decision, $zone] as $orig) {
    $receipt = $receipts[$orig['requestId']] ?? [];
    specialCheck(($receipt['attack']['status'] ?? '') === 'cancelled'
        && $receipt['requestSignature'] === 'recorded-' . $orig['requestId']
        && $receipt['expiresAt'] > $orig['createdAt'], 'Cancellation keeps the original replay identity.');
}
specialCheck(($receipts[$zone['requestId']]['attack']['areaGate']['resolutionSignature'] ?? '') === '["reject",[]]',
    'An immutable rejection receipt protects a prepared area from release after deletion.');
specialCheck($receipts[$completed['requestId']] === pendingRemovalReceipt($completed),
    'Completed damage remains exactly as recorded in the original receipt.');
specialCheck($receipts[$neighbor['requestId']] === pendingRemovalReceipt($neighbor)
    && $receipts[$otherScene['requestId']] === pendingRemovalReceipt($otherScene),
    'A different target and the same token identifier on another scene retain their original requests.');
specialCheck($db->payload('character:character-player') === $sourceBefore
    && $db->payload($neighborKey) === $neighborBefore && $after['actionTimers'] === $before['actionTimers'],
    'Deleting the defender never refunds an action or harms another token.');

$lateDefense = runCommand($db, 'token.attack.oppose', ['attackId' => $waiting['id'],
    'decision' => 'skip', 'requestId' => 'pending-removal-defense-replay'], true, 'account-gm');
specialCheck($lateDefense->status === 404, 'The deleted defender cannot be handed a new defense.');
$lateApproval = runCommand($db, 'token.attack.resolve', ['attackId' => $decision['id'],
    'decision' => 'approve', 'confirmed' => true], true, 'account-gm');
specialCheck($lateApproval->status === 404 || ($lateApproval->body['attack']['status'] ?? '') === 'cancelled',
    'A late validation cannot recreate damage to a deleted defender.');
$lateArea = runCommand($db, 'token.attack.area.resolve', ['attackId' => $zone['id'],
    'decision' => 'approve', 'confirmed' => true, 'defenseTokenIds' => []], true, 'account-gm');
specialCheck($lateArea->status === 409 && $db->payload($neighborKey) === $neighborBefore,
    'A late area approval cannot redirect damage or reinterpret the original targets.');

$historic = specialFixture();
$historicBefore = [...$historic->payload('activity'), 'pendingAttacks' => [],
    'attackReceipts' => [pendingRemovalReceipt($completed)]];
$historic->put('activity', $historicBefore);
$historicDelete = [...$delete, 'expectedRevision' => $historic->domains[$partyKey]['revision']];
$result = removeAttackToken($historic, [$historicDelete, ['key' => 'activity', 'operation' => 'upsert',
    'expectedRevision' => $historic->domains['activity']['revision'],
    'payload' => [...$historicBefore, 'attackReceipts' => []]]]);
specialCheck($result->status === 200
    && ($historic->payload('activity')['attackReceipts'] ?? []) === $historicBefore['attackReceipts']
    && ($historic->payload('activity')['pendingAttacks'] ?? []) === [],
    'A delete affecting only an old receipt leaves that receipt unchanged.');

$emptyScene = specialFixture();
$orphanDot = [...$struckDot, 'id'=>'dot-no-token-left', 'sceneId'=>'scene-two', 'targetTokenId'=>'removed-long-ago'];
$emptyScene->put('activity', [...$emptyScene->payload('activity'), 'damageOverTime'=>[$orphanDot, $neighborDot],
    'pendingDotResolutions'=>[$orphanDot]]);
$result = removeAttackToken($emptyScene, [['key'=>'scene:scene-two','operation'=>'delete',
    'expectedRevision'=>$emptyScene->domains['scene:scene-two']['revision']]]);
specialCheck($result->status === 200 && $emptyScene->payload('scene:scene-two') === []
    && $emptyScene->payload('activity')['damageOverTime'] === [$neighborDot]
    && $emptyScene->payload('activity')['pendingDotResolutions'] === [],
    'Deleting an inactive scene retires even effects whose original token was removed long ago.');

$transit = applicationCancelPendingAttacksForRemovedTokens(
    ['damageOverTime'=>[$struckDot, $neighborDot]], ['scene-one'=>['token-monster'=>true]],
    (int) floor(microtime(true) * 1000), null, [], ['token-monster'=>true]
);
specialCheck($transit['damageOverTime'] === [$struckDot, $neighborDot],
    'The deletion cleanup preserves the effects of a token reappearing atomically in another scene.');

fwrite(STDOUT, json_encode(['ok' => true, 'checks' => $specialChecks], JSON_UNESCAPED_UNICODE) . PHP_EOL);
