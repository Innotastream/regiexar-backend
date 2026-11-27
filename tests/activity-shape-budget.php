<?php
declare(strict_types=1);

define('XAR_SPECIAL_FIXTURE_ONLY', true);
require __DIR__ . '/tactical-special-effects.php';

$serpentId = 'ability-ae84d88e-2469-45f5-b22e-b56971892753';
$serpent = [
    'id' => $serpentId, 'name' => 'Bottes du Serpent Céleste', 'effect' => 'complex',
    'formula' => '0', 'castingStatId' => '', 'usesPerCombat' => 1, 'manaCost' => 0,
    'workflow' => ['version' => 8, 'steps' => [
        ['id' => 'move', 'type' => 'movement', 'maximumMeters' => 20],
        ['id' => 'targets', 'type' => 'targets', 'minTargets' => 0, 'maxTargets' => 20,
            'maximumPerTarget' => 1, 'allocationTotal' => 0, 'rangeCells' => 0,
            'sourceMovementStepId' => 'move', 'targetRelation' => 'enemies'],
        ['id' => 'strikes', 'type' => 'allocated-attacks', 'sourceStepId' => 'targets',
            'awarenessMode' => 'none', 'damageMode' => 'configured', 'hitMode' => 'automatic',
            'statId' => 'character-stat-agility', 'damageComponents' => [
                ['type' => 'magical', 'formula' => '1d30+6', 'ignoreArmor' => true],
            ], 'defenseStatId' => 'character-stat-agility', 'defenseRollMode' => 'disadvantage'],
    ]],
];
$moonId = 'ability-1e0ff514-d775-46cb-89c9-81c3cdded8d5';
$moon = [
    'id' => $moonId, 'name' => 'Rayon de Lune', 'effect' => 'damage', 'formula' => '1d40+10',
    'damageType' => 'magical', 'castingStatId' => '', 'manaCost' => 20, 'fatigueCost' => 5,
    'cooldownRounds' => 5, 'usesPerRest' => 1, 'restRecharge' => 'short',
    'damageOverTime' => ['formula' => '1d20', 'turns' => 5, 'damageType' => 'magical'],
    'damageTargeting' => ['mode' => 'area', 'origin' => 'point', 'radiusCells' => 2,
        'affectCaster' => false, 'affectAllies' => false, 'affectEnemies' => true],
];

function activityShapeFixture(array $ability): MemoryConnection
{
    $connection = specialFixture();
    $character = $connection->payload('character:character-player');
    $character['abilities'] = [$ability];
    $connection->put('character:character-player', $character);
    $source = synchronizeOnlineCharacterToken($connection->payload('token:scene-one:token-player'), $character);
    $connection->put('token:scene-one:token-player', [...$source, 'x' => 20, 'y' => 50]);
    $connection->put('map:scene-one', [...$connection->payload('map:scene-one'), 'naturalHeight' => 600]);
    foreach (['token-monster' => 30, 'token-monster-two' => 40] as $tokenId => $x) {
        $key = 'token:scene-one:' . $tokenId;
        $target = $connection->payload($key);
        $connection->put($key, [...$target, 'x' => $x, 'y' => 50, 'size' => 40, 'hp' => 500, 'maxHp' => 500]);
    }
    return $connection;
}

function activityShapeNodes(mixed $value): int
{
    return is_array($value) ? 1 + array_sum(array_map('activityShapeNodes', $value)) : 1;
}

function fillActivityNearLegacyBudget(MemoryConnection $connection, array $resolvedReceipt): int
{
    $activity = $connection->payload('activity');
    $nodes = activityShapeNodes($activity);
    $receiptNodes = activityShapeNodes($resolvedReceipt);
    for ($index = 0; $nodes + $receiptNodes <= 49990; $index++) {
        $receipt = $resolvedReceipt;
        $receipt['requestId'] = 'activity-shape-history-request-' . $index;
        $receipt['attack']['id'] = 'activity-shape-history-attack-' . $index;
        $receipt['attack']['requestId'] = $receipt['requestId'];
        $activity['attackReceipts'][] = $receipt;
        $nodes += $receiptNodes;
    }

    // The detailed receipt size can change with the workflow schema. Fill the
    // remainder with completed single-hit receipts so this test stays near
    // the old limit even when the detailed receipt size changes by one node.
    $compactReceipt = ['requestId'=>'', 'accountId'=>$resolvedReceipt['accountId'],
        'expiresAt'=>$resolvedReceipt['expiresAt'], 'attack'=>['id'=>'','requestId'=>'','status'=>'applied']];
    $compactNodes = activityShapeNodes($compactReceipt);
    for ($index = 0; $nodes + $compactNodes <= 49990; $index++) {
        $receipt = $compactReceipt;
        $receipt['requestId'] = 'activity-shape-compact-request-' . $index;
        $receipt['attack']['id'] = 'activity-shape-compact-attack-' . $index;
        $receipt['attack']['requestId'] = $receipt['requestId'];
        $activity['attackReceipts'][] = $receipt;
        $nodes += $compactNodes;
    }
    $connection->put('activity', validatedDomainPayload('activity', $activity));
    return activityShapeNodes($activity);
}

$source = ['sceneId' => 'scene-one', 'layerId' => 'ground', 'sourceTokenId' => 'token-player'];
$serpentArgs = [...$source, 'action' => 'start', 'requestId' => 'activity-shape-serpent-new',
    'abilityId' => $serpentId, 'initialSelection' => ['allocations' => [
        ['tokenId' => 'token-monster', 'count' => 1], ['tokenId' => 'token-monster-two', 'count' => 1],
    ]], 'movementSelection' => [
        'destination' => ['x' => 50, 'y' => 50], 'path' => [['x' => 20, 'y' => 50], ['x' => 50, 'y' => 50]],
    ],
];
$moonArgs = [...$source, 'requestId' => 'activity-shape-moon-new', 'targetTokenId' => 'token-player',
    'attackKind' => 'ability', 'attackId' => $moonId, 'targetPoint' => ['x' => 35, 'y' => 50], 'opposed' => false,
];

$seed = activityShapeFixture($serpent);
$prepared = runCommand($seed, 'ability.complex', $serpentArgs);
specialCheck($prepared->status === 200 && !empty($prepared->body['pendingAreaGate']), 'La route dégagée se prépare à vide.');
$approved = fixtureApproveDefenseGate($seed, $prepared);
specialCheck($approved->status === 200, 'Le journal de départ provient d’une commande réelle.');
$resolvedReceipt = $seed->payload('activity')['attackReceipts'][0] ?? [];
specialCheck(!empty($resolvedReceipt['attack']['areaGate']) && $resolvedReceipt['attack']['status'] !== 'pending',
    'Le journal historique imite des reçus résolus encore disponibles pour le rejeu.');

$testReceipt = static fn (string $id, int $expiresAt, array $attack = [], array $receipt = []): array => [
    'requestId' => 'request-' . $id, 'accountId' => 'account-player', 'requestSignature' => '',
    'expiresAt' => $expiresAt, 'attack' => [...$attack, 'id' => $id, 'status' => 'resolved'], ...$receipt,
];
$expired = [];
for ($index = 0; $index < XAR_ATTACK_RECEIPT_MAXIMUM; $index++) {
    $expired[] = $testReceipt('expired-' . $index, 1);
}
$connection = activityShapeFixture($serpent);
$activity = $connection->payload('activity');
$connection->put('activity', validatedDomainPayload('activity', [...$activity, 'attackReceipts' => $expired]));
$response = runCommand($connection, 'ability.complex', $serpentArgs);
specialCheck($response->status === 200 && !empty($response->body['pendingAreaGate']) &&
    array_column($connection->payload('activity')['attackReceipts'], 'requestId') === [$serpentArgs['requestId']],
    'Les 1 024 reçus résolus expirés libèrent une place pour une nouvelle préparation.');
$revision = $connection->revision;
$retry = runCommand($connection, 'ability.complex', $serpentArgs);
specialCheck($retry->status === 200 && !empty($retry->body['deduplicated']) && $connection->revision === $revision,
    'La même préparation reste dédupliquée après la purge.');
$activity = $connection->payload('activity');
$activity['attackReceipts'][0]['expiresAt'] = 1;
$activity['attackReceipts'] = [...$activity['attackReceipts'], ...array_slice($expired, 1)];
$connection->put('activity', validatedDomainPayload('activity', $activity));
$approved = fixtureApproveDefenseGate($connection, $response);
specialCheck($approved->status === 200 && !empty($approved->body['execution']) &&
    !in_array($expired[1]['requestId'], array_column($connection->payload('activity')['attackReceipts'], 'requestId'), true),
    'Un reçu expiré reste utilisable pour la validation MJ en attente malgré 1 023 vieux reçus.');
$revision = $connection->revision;
$retriedApproval = fixtureApproveDefenseGate($connection, $response);
specialCheck($retriedApproval->status === 200 && !empty($retriedApproval->body['deduplicated']) &&
    $connection->revision === $revision, 'Une validation reste dédupliquée après la résolution du reçu expiré.');

$protected = onlineRetainedAttackReceipts([
    'pendingAttacks' => [['id' => 'pending', 'areaGateParentId' => 'parent']],
    'abilityExecutions' => [['id' => 'execution-live', 'status' => 'active', 'echoParentId' => 'echo-parent'],
        ['id' => 'execution-complete', 'status' => 'completed']],
    'attackReceipts' => [
        $testReceipt('parent', 1),
        $testReceipt('pending', 1),
        $testReceipt('sibling', 1, ['areaGateParentId' => 'parent']),
        $testReceipt('child', 1, ['weaponAttackParentId' => 'sibling']),
        $testReceipt('execution-receipt', 1, ['complexExecutionId' => 'execution-live']),
        $testReceipt('completed-receipt', 1, ['complexExecutionId' => 'execution-complete']),
        $testReceipt('echo-parent', 1),
        $testReceipt('echo-child', 1, ['echoParentId' => 'echo-parent']),
        $testReceipt('live', PHP_INT_MAX, [], ['areaAttackIds' => ['older-child']]),
        $testReceipt('older-child', 1),
        $testReceipt('orphan', 1),
    ],
], 2);
specialCheck(array_column($protected, 'requestId') === array_map(static fn(string $id): string => 'request-' . $id, [
    'parent', 'pending', 'sibling', 'child', 'execution-receipt', 'echo-parent', 'echo-child', 'live', 'older-child',
]), 'Les groupes en cours, les exécutions actives et les reçus encore valides gardent leurs liens.');
specialCheck(count(onlineRetainedAttackReceipts([
    'attackReceipts' => array_map(static fn (array $receipt): array => [...$receipt, 'expiresAt' => PHP_INT_MAX], $expired),
], 2)) === XAR_ATTACK_RECEIPT_MAXIMUM, 'Le stock de reçus encore valides reste intact à sa limite.');

foreach ([[$serpent, 'ability.complex', $serpentArgs], [$moon, 'token.attack', $moonArgs]] as [$ability, $command, $args]) {
    $connection = activityShapeFixture($ability);
    $nodes = fillActivityNearLegacyBudget($connection, $resolvedReceipt);
    $before = $connection->payload('activity')['attackReceipts'];
    specialCheck($nodes > 49500 && $nodes < 50000 && count($before) < XAR_ATTACK_RECEIPT_MAXIMUM,
        'Le journal initial approche la limite historique sans dépasser ses propres quotas.');
    $response = runCommand($connection, $command, $args);
    specialCheck($response->status === 200 && !empty($response->body['pendingAreaGate']),
        $ability['name'] . ' reste utilisable après une longue partie : ' . $response->getMessage());
    $activity = $connection->payload('activity');
    specialCheck(activityShapeNodes($activity) > 50000,
        'La commande franchit réellement l’ancienne limite à 50 000 nœuds.');
    specialCheck(array_intersect(array_column($before, 'requestId'), array_column($activity['attackReceipts'], 'requestId'))
        === array_column($before, 'requestId'), 'Les reçus historiques encore valides restent disponibles.');
    $revision = $connection->revision;
    $retry = runCommand($connection, $command, $args);
    specialCheck($retry->status === 200 && !empty($retry->body['deduplicated']) && $connection->revision === $revision,
        'Un rejeu confirmé reste dédupliqué et ne modifie aucun domaine.');
}

$connection = activityShapeFixture($serpent);
$map = $connection->payload('map:scene-one');
$connection->put('map:scene-one', [...$map, 'background' => '/media/abcdefghijklmnopqrstuvwx']);
$web = specialMechanics($connection, 'structure.web.place', [
    'layerId' => 'ground', 'x' => 60, 'y' => 60, 'structureSize' => 109,
]);
specialCheck($web->status === 200, 'Le MJ place la toile de la recette.');
$webId = $web->body['tokenId'];
$webKey = 'token:scene-one:' . $webId;
$webToken = $connection->payload($webKey);
$connection->put($webKey, [...$webToken, 'hp' => 68]);
$attack = [
    'id' => 'attack-activity-shape-web', 'requestId' => 'activity-shape-web-attack-request',
    'sceneId' => 'scene-one', 'layerId' => 'ground', 'sourceTokenId' => 'token-player', 'targetTokenId' => $webId,
    'sourceName' => 'Personnage', 'targetName' => 'Toile', 'attackName' => 'Frappe de recette',
    'accountId' => 'account-gm', 'attackerRole' => 'gm', 'playerName' => 'MJ test',
    'status' => 'pending', 'validationKind' => 'outcome', 'provisionalStatus' => 'applied', 'finalDamage' => 5,
    'damageType' => 'ignore', 'damageFormula' => '5', 'damageRollMode' => 'normal',
    'hit' => ['raw' => 11, 'outcome' => classifyOnlineD100Outcome(11, 0)],
    'damage' => ['formula' => '5', 'breakdown' => '5', 'rawDamage' => 5, 'armorPercent' => 0,
        'preventedDamage' => 0, 'finalDamage' => 5],
];
$activity = $connection->payload('activity');
$activity['pendingAttacks'] = [$attack];
$activity['attackReceipts'] = [['requestId' => $attack['requestId'], 'accountId' => 'account-gm',
    'expiresAt' => PHP_INT_MAX, 'attack' => $attack]];
$connection->put('activity', $activity);
$nodes = fillActivityNearLegacyBudget($connection, $resolvedReceipt);
specialCheck($nodes > 49500 && $nodes < 50000, 'La validation simple rencontre aussi un journal proche de la limite.');
$approval = ['attackId' => $attack['id'], 'decision' => 'approve', 'confirmed' => true,
    'requestId' => 'activity-shape-web-approval'];
$response = runCommand($connection, 'token.attack.resolve', $approval, true, 'account-gm');
specialCheck($response->status === 200 && $response->body['attack']['status'] === 'applied'
    && (float) $connection->payload($webKey)['hp'] === 63.0,
    'Le clic Valider applique 5 dégâts à la toile qui avait 68 PV : '
    . json_encode([$response->status, $response->body['code'] ?? $response->body['attack']['status'] ?? null, $connection->payload($webKey)['hp']]));
specialCheck(activityShapeNodes($connection->payload('activity')) > 50000,
    'La validation simple franchit réellement l’ancienne limite.');
$retry = runCommand($connection, 'token.attack.resolve', $approval, true, 'account-gm');
specialCheck($retry->status === 404 && ($retry->body['code'] ?? '') === 'pending_attack_missing'
    && (float) $connection->payload($webKey)['hp'] === 63.0,
    'Rejouer la validation ne retire pas les PV deux fois.');

function assertActivityShapeRejection(string $key, array $payload, string $code): void
{
    try {
        validatedDomainPayload($key, $payload);
    } catch (TestResponse $response) {
        specialCheck($response->status === 400 && ($response->body['code'] ?? '') === $code,
            'Le plafond de ' . $key . ' continue de s’appliquer.');
        return;
    }
    throw new RuntimeException('Un document hors limites a été accepté : ' . $key);
}

$nested = null;
for ($level = 0; $level < 25; $level++) $nested = ['child' => $nested];
assertActivityShapeRejection('activity', [...$connection->payload('activity'), 'deep' => $nested], 'invalid_domain_shape');
assertActivityShapeRejection('scene:scene-one', ['metadata' => ['id' => 'scene-one'],
    'fixture' => array_fill(0, 1000, array_fill(0, 50, 0))], 'invalid_domain_shape');
assertActivityShapeRejection('activity', [...$connection->payload('activity'),
    'attackReceipts' => array_fill(0, XAR_ATTACK_RECEIPT_MAXIMUM + 1, $resolvedReceipt)], 'invalid_activity_domain');

echo json_encode(['ok' => true, 'checks' => $specialChecks], JSON_THROW_ON_ERROR) . PHP_EOL;
