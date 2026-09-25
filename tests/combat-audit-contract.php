<?php
declare(strict_types=1);

// Exercise the real command authority with the in-memory transaction fixture.
require __DIR__ . '/tactical-lifecycle.php';

$auditFailures = [];
$auditChecks = 0;
function auditCombat(string $name, callable $check): void {
    try { $check(); $GLOBALS['auditChecks'] += 1; }
    catch (Throwable $error) { $GLOBALS['auditFailures'][] = $name . ': ' . $error->getMessage(); }
}
function auditPreparedFixture(): MemoryConnection {
    $db = fixture();
    $db->put('scene:scene-two', ['id' => 'scene-two', 'name' => 'Préparation privée']);
    $db->put('map:scene-two', ['activeLayerId' => 'ground', 'gridSize' => 50]);
    $db->put('initiative:scene-two', ['active' => false, 'round' => 1]);
    $db->put('token:scene-two:token-monster', $db->payload('token:scene-one:token-monster'));
    $character = $db->payload('character:character-player');
    $character['abilities'] = [[
        'id' => 'ability-private', 'name' => 'Capacité privée', 'effect' => 'damage', 'formula' => '2',
        'castingStatId' => '', 'damageType' => 'physical', 'description' => '',
    ]];
    $db->put('character:character-player', $character);
    return $db;
}
function auditPreparedAttack(MemoryConnection $db, bool $opposed = false): TestResponse {
    return runCommand($db, 'token.attack', [
        'sceneId' => 'scene-two', 'layerId' => 'ground', 'sourceTokenId' => 'token-copy',
        'targetTokenId' => 'token-monster', 'attackKind' => 'ability', 'attackId' => 'ability-private',
        'requestId' => 'combat-audit-prepared-attack', 'opposed' => $opposed,
    ], true, 'account-gm');
}

auditCombat('Prepared attack resolves privately', function (): void {
    $db = auditPreparedFixture();
    $created = auditPreparedAttack($db);
    requireTactical($created->status === 200 && $created->body['attack']['status'] === 'pending', 'Creation must reach pending resolution');
    $resolved = runCommand($db, 'token.attack.resolve', [
        'attackId' => $created->body['attack']['id'], 'decision' => 'approve', 'confirmed' => true,
    ], true, 'account-gm');
    requireTactical($resolved->status === 200, 'GM resolution refused: ' . ($resolved->body['code'] ?? 'unknown'));
    requireTactical((float) $db->payload('token:scene-two:token-monster')['hp'] === 38.0, 'The prepared target must receive damage exactly once');
    requireTactical($db->payload('table')['activeSceneId'] === 'scene-one', 'Resolution must retain the broadcast scene');
    requireTactical(applicationPublicResultRolls($resolved->body) === [] && onlineAttackDiscordContent($resolved->body['attack']) === '', 'Prepared damage and summary must remain private');
});

auditCombat('Prepared opposition resolves privately', function (): void {
    $db = auditPreparedFixture();
    $created = auditPreparedAttack($db, true);
    requireTactical($created->status === 200 && $created->body['attack']['status'] === 'awaiting-opposition', 'Creation must reach opposition');
    $opposed = runCommand($db, 'token.attack.oppose', [
        'attackId' => $created->body['attack']['id'], 'requestId' => 'combat-audit-prepared-defense',
        'statId' => 'monster-force',
    ], true, 'account-gm');
    requireTactical($opposed->status === 200, 'GM opposition refused: ' . ($opposed->body['code'] ?? 'unknown'));
    requireTactical(applicationPublicResultRolls($opposed->body) === [] && onlineAttackDiscordContent($opposed->body['attack']) === '', 'Prepared opposition must remain private');
});

auditCombat('Prepared simple ability does not escape to Discord', function (): void {
    $db = auditPreparedFixture();
    $response = runCommand($db, 'token.roll', [
        'sceneId' => 'scene-two', 'layerId' => 'ground', 'tokenId' => 'token-copy', 'kind' => 'ability',
        'abilityId' => 'ability-private', 'requestId' => 'combat-audit-prepared-ability',
    ], true, 'account-gm');
    requireTactical($response->status === 200, 'Prepared ability must succeed');
    requireTactical(applicationPublicResultRolls($response->body) === [] && onlineDiscordResultRollContent($response->body) === '', 'The effect formula currently escapes from a nonbroadcast scene');
});

auditCombat('Reduced failure blocks a reuse even while retaining a longer cooldown', function (): void {
    $db = fixture();
    $source = synchronizeOnlineCharacterToken($db->payload('token:scene-one:token-player'), $db->payload('character:character-player'));
    $initiative = ['active' => true, 'turnSerial' => 3, 'round' => 4, 'order' => ['token-player'], 'currentIndex' => 0];
    $ability = ['id' => 'ability-audit', 'name' => 'Échec amoindri', 'castingStatId' => 'force', 'cooldownRounds' => 5, 'reusableInTurn' => true, 'reducedFailureCooldown' => true];
    $activity = $db->payload('activity');
    $activity['actionTimers'] = [[
        'id' => 'timer-audit', 'sceneId' => 'scene-one', 'abilityId' => 'ability-audit', 'characterId' => 'character-player', 'tokenId' => '',
        'turnKey' => applicationAbilityTurnKey($initiative), 'useCount' => 1, 'reusableInTurn' => true,
        'cooldownActive' => true, 'cooldown' => 5, 'readyRound' => 9, 'usedRound' => 4, 'restRecharge' => 'none',
    ]];
    $db->put('activity', $activity);
    $plan = applicationAbilityCastingPlan($ability, $source, 'scene-one', $initiative, $activity['actionTimers']);
    $records = applicationDomainRecords($db); $pending = [];
    onlineCommitAbilityCasting($db, $records, $pending, $plan, ['success' => false, 'statId' => 'character-stat-force', 'roll' => null], $source, ['id' => 'account-player']);
    $timers = $pending['activity']['payload']['actionTimers'];
    requireTactical($timers[0]['readyRound'] === 9 && $timers[0]['reusableInTurn'] === false, 'The long cooldown must survive while further reuse is blocked');
    try { applicationAbilityCastingPlan($ability, $source, 'scene-one', $initiative, $timers); }
    catch (RuntimeException $error) { requireTactical($error->getMessage() === 'ability_on_cooldown', 'Expected cooldown rejection'); return; }
    throw new RuntimeException('A third cast was still permitted');
});

auditCombat('Complex defenses refresh the linked authoritative statistics', function (): void {
    $db = fixture();
    $character = $db->payload('character:character-player');
    $character['stats']['force'] = 73; $character['temporaryStats']['force'] = 41;
    $character['fatigue'] = ['current' => 99, 'max' => 100];
    $db->put('character:character-player', $character);
    $stale = $db->payload('token:scene-one:token-player');
    $stale['stats'] = [['id' => 'character-stat-force', 'label' => 'Force', 'value' => '73']];
    $db->put('token:scene-one:token-player', $stale);
    $records = applicationDomainRecords($db);
    $tokens = onlineComplexAbilitySceneTokens($db, $records, 'scene-one', $db->payload('map:scene-one'), 'account-player', false);
    $target = applicationComplexAbilityTokenById($tokens, 'token-player');
    requireTactical(applicationComplexAbilityTokenThreshold($target, ['thresholdMode' => 'stat', 'targetStatId' => 'force']) === 0, 'Defense must clamp temporary 41 minus fatigue 49, not use stale token 73');
});

auditCombat('Self-healing pays HP and mana before the bounded effect', function (): void {
    $db = fixture(); $character = $db->payload('character:character-player');
    $character['resources']['hp'] = 100;
    $character['abilities'] = [[
        'id' => 'self-heal', 'name' => 'Soin coûteux', 'effect' => 'healing', 'formula' => '10', 'healingFormula' => '10',
        'castingStatId' => '', 'hpCost' => 10, 'manaCost' => 2, 'fatigueCost' => 1,
    ]];
    $db->put('character:character-player', $character);
    $payload = ['sceneId' => 'scene-one', 'sourceTokenId' => 'token-player', 'targetTokenId' => 'token-player',
        'abilityId' => 'self-heal', 'requestId' => 'combat-audit-self-heal'];
    $response = runCommand($db, 'ability.use', $payload);
    requireTactical($response->status === 200, 'Self-heal must complete');
    $current = $db->payload('character:character-player');
    requireTactical((float) $current['resources']['hp'] === 100.0 && (float) $current['resources']['mana'] === 3.0
        && (float) $current['fatigue']['current'] === 1.0 && (float) $response->body['appliedDelta'] === 10.0,
        'Pay 10 HP and 2 mana, then heal 10 HP: final 100 HP / 3 mana');
    requireTactical((float) $db->payload('token:scene-two:token-copy')['hp'] === 100.0
        && (float) $db->payload('token:scene-two:token-copy')['mana'] === 3.0, 'All linked tokens retain both cost and healing');
    $revision = $db->revision;
    $retry = runCommand($db, 'ability.use', $payload);
    requireTactical($retry->body['deduplicated'] === true && $db->revision === $revision, 'Retry neither repays nor heals');
});

auditCombat('An attack receipt cannot be reused by another GM', function (): void {
    $db = auditPreparedFixture();
    $payload = ['sceneId' => 'scene-two', 'layerId' => 'ground', 'sourceTokenId' => 'token-monster', 'targetTokenId' => 'token-copy',
        'attackKind' => 'weapon', 'attackId' => 'monster-claw', 'statId' => 'monster-force', 'requestId' => 'combat-audit-weapon-owner'];
    $created = runCommand($db, 'token.attack', $payload, true, 'account-gm'); $revision = $db->revision;
    $other = runCommand($db, 'token.attack', $payload, true, 'another-gm');
    requireTactical($created->status === 200 && $other->status === 403 && $db->revision === $revision, 'Same request ID owned by another actor must not create another attack');
});

auditCombat('Reduced failure retains a partial rest quota and enforces its one-round floor', function (): void {
    $db = fixture();
    $source = synchronizeOnlineCharacterToken($db->payload('token:scene-one:token-player'), $db->payload('character:character-player'));
    $initiative = ['active' => true, 'turnSerial' => 3, 'round' => 4, 'order' => ['token-player'], 'currentIndex' => 0];
    $ability = ['id' => 'rest-audit', 'name' => 'Repos partiel', 'castingStatId' => 'force', 'cooldownRounds' => 0,
        'restRecharge' => 'short', 'usesPerRest' => 3, 'reusableInTurn' => true, 'reducedFailureCooldown' => true];
    $activity = $db->payload('activity');
    $activity['actionTimers'] = [[
        'id' => 'timer-rest-audit', 'sceneId' => 'scene-one', 'abilityId' => 'rest-audit', 'characterId' => 'character-player', 'tokenId' => '',
        'turnKey' => applicationAbilityTurnKey($initiative), 'useCount' => 1, 'reusableInTurn' => true, 'cooldownActive' => true,
        'cooldown' => 0, 'readyRound' => 4, 'usedRound' => 4, 'restRecharge' => 'short', 'restUseCount' => 1, 'restUseLimit' => 3,
    ]];
    $db->put('activity', $activity); $records = applicationDomainRecords($db); $pending = [];
    $plan = applicationAbilityCastingPlan($ability, $source, 'scene-one', $initiative, $activity['actionTimers']);
    $cast = onlineCommitAbilityCasting($db, $records, $pending, $plan, ['success' => false, 'statId' => 'force', 'roll' => null], $source, ['id' => 'account-player']);
    $timers = $pending['activity']['payload']['actionTimers'];
    requireTactical($cast['remainingRounds'] === 1 && $timers[0]['readyRound'] === 5 && $timers[0]['restUseCount'] === 1, 'Failure adds one round without consuming or discarding the partial rest quota');
    try { applicationAbilityCastingPlan($ability, $source, 'scene-one', $initiative, $timers); }
    catch (RuntimeException $error) {
        requireTactical($error->getMessage() === 'ability_on_cooldown', 'The current round is blocked');
        requireTactical(applicationAbilityCastingPlan($ability, $source, 'scene-one', [...$initiative, 'round' => 5], $timers)['restUseCount'] === 1, 'The next round frees the failure cooldown while retaining quota');
        return;
    }
    throw new RuntimeException('Partial rest quota bypassed reduced failure');
});

auditCombat('An opposition receipt is bound to the responding GM', function (): void {
    $db = auditPreparedFixture(); $created = auditPreparedAttack($db, true);
    $payload = ['attackId' => $created->body['attack']['id'], 'requestId' => 'combat-audit-opposition-owner', 'decision' => 'cancel'];
    $cancelled = runCommand($db, 'token.attack.oppose', $payload, true, 'account-gm');
    $revision = $db->revision;
    $other = runCommand($db, 'token.attack.oppose', $payload, true, 'another-gm');
    requireTactical($cancelled->status === 200 && $other->status === 403 && $db->revision === $revision, 'Another GM cannot impersonate the responder on replay');
    $retry = runCommand($db, 'token.attack.oppose', $payload, true, 'account-gm');
    requireTactical($retry->status === 200 && $retry->body['deduplicated'] === true, 'Original GM retains the receipt');
});

auditCombat('Complex casting cannot answer its own opponent', function (): void {
    $ability = ['id' => 'complex-audit', 'name' => 'Rafale', 'effect' => 'complex', 'workflow' => ['version' => 1, 'steps' => [
        ['id' => 'targets', 'type' => 'targets', 'minTargets' => 1, 'maxTargets' => 1, 'allocationTotal' => 1],
        ['id' => 'defense', 'type' => 'defense-series', 'sourceStepId' => 'targets', 'thresholdMode' => 'fixed', 'fixedThreshold' => 50],
    ]]];
    $execution = createApplicationComplexAbilityExecution(['id' => 'execution-audit', 'sceneId' => 'scene-one', 'sourceTokenId' => 'caster',
        'controllerAccountId' => 'account-caster', 'ability' => $ability]);
    $tokens = [['id' => 'target', 'controllerAccountId' => 'account-defender']];
    $context = ['actor' => ['id' => 'account-caster', 'role' => 'player'], 'tokens' => $tokens];
    $execution = applyApplicationComplexAbilityCommand($execution, ['action' => 'select-targets', 'expectedRevision' => 1, 'allocations' => [['tokenId' => 'target', 'count' => 1]]], $context);
    $rolled = false; $context['roll'] = static function () use (&$rolled): array { $rolled = true; return ['rawD100' => 25, 'total' => 25]; };
    try { applyApplicationComplexAbilityCommand($execution, ['action' => 'defense-roll', 'expectedRevision' => 2, 'targetTokenId' => 'target'], $context); }
    catch (ApplicationComplexAbilityException $error) { requireTactical($error->httpStatus === 403 && !$rolled, 'Unauthorized defense is refused before dice'); return; }
    throw new RuntimeException('Caster answered another player’s defense');
});

auditCombat('Prepared attack stays private after the scene becomes public', function (): void {
    $db = auditPreparedFixture();
    $attack = ['id' => 'attack-private-projection', 'sceneId' => 'scene-two', 'layerId' => 'ground', 'status' => 'awaiting-opposition',
        'sourceTokenId' => 'token-monster', 'targetTokenId' => 'token-copy', 'attackerRole' => 'gm', 'visibility' => 'gm',
        'accountId' => 'account-gm', 'hit' => ['outcome' => ['threshold' => 72]],
        'cast' => ['roll' => ['formula' => 'private-formula']], 'damageRoll' => ['formula' => 'private-damage']];
    $state = ['activeSceneId' => 'scene-two', 'activeScene' => ['id' => 'scene-two'],
        'characters' => [$db->payload('character:character-player')],
        'map' => ['activeLayerId' => 'ground', 'gridSize' => 50, 'tokens' => [$db->payload('token:scene-two:token-copy'), $db->payload('token:scene-two:token-monster')]],
        'initiative' => [], 'pendingAttacks' => [$attack], 'rolls' => []];
    foreach ([false, true] as $stream) {
        $view = publicPlayerState($state, ['id' => 'account-player', 'display_name' => 'Joueur'], [], $stream);
        requireTactical($view['pendingMapAttacks'] === [] && $view['pendingOppositions'] === [], 'Prepared secrets never become public invites');
        requireTactical(!str_contains(json_encode($view), 'private-formula') && !str_contains(json_encode($view), 'private-damage'), 'Canonical GM receipt fields remain excluded from public state');
    }
});

auditCombat('Fatigue boundary and temporary zero preserve the exact d100 classification', function (): void {
    foreach ([[50,100,0],[51,100,1],[98,200,48],[100,200,50],[101,200,51]] as [$fatigue,$maximum,$penalty]) {
        $character = ['stats' => ['force' => 73], 'temporaryStats' => ['force' => 41],
            'resources' => ['mentalResistance' => 61], 'fatigue' => ['current' => $fatigue, 'max' => $maximum]];
        $token = synchronizeOnlineCharacterToken(['characterId' => 'hero'], $character);
        requireTactical((int) applicationAbilityCastingStat($token['stats'], 'force')['value'] === max(0, 41-$penalty)
            && (int) applicationAbilityCastingStat($token['stats'], 'character-stat-mentalResistance')['value'] === max(0, 61-$penalty),
            'Fatigue subtracts every whole point beyond absolute 50, independently of its maximum');
        $character['temporaryStats']['force'] = 0;
        $zero = synchronizeOnlineCharacterToken(['characterId' => 'hero'], $character);
        requireTactical((int) applicationAbilityCastingStat($zero['stats'], 'force')['value'] === 0, 'Temporary zero is authoritative');
        if ($penalty) {
            $plan = applicationAbilityCastingPlan(['id' => 'zero', 'name' => 'Seuil nul', 'castingStatId' => 'force'], $zero, 'scene-one', [], []);
            $cast = onlineAbilityCastingRoll($plan, $zero, ['id' => 'account-player', 'display_name' => 'Joueur'], [], 'ground', $character);
            requireTactical($cast['outcome']['fatigue']['before'] === 0, 'Casting displays the original temporary zero before fatigue without guessing');
        }
    }
    $character = ['stats' => ['intelligence' => 50], 'resources' => ['hp' => 10, 'maxHp' => 10], 'fatigue' => ['current' => 51, 'max' => 100]];
    $source = synchronizeOnlineCharacterToken(['id' => 'token-player', 'characterId' => 'character-player'], $character);
    $plan = applicationAbilityCastingPlan(['id' => 'fatigue-proof', 'name' => 'Fatigue', 'castingStatId' => 'intelligence', 'fatigueCost' => 1], $source, 'scene-one', [], []);
    $cast = onlineAbilityCastingRoll($plan, $source, ['id' => 'account-player', 'display_name' => 'Joueur'], [], 'ground', $character);
    requireTactical($cast['outcome']['threshold'] === 49 && $cast['outcome']['fatigue']['before'] === 50
        && (float) $cast['outcome']['fatigue']['current'] === 51.0 && (float) $cast['outcome']['fatigue']['max'] === 100.0,
        'INT 50 at fatigue 51/100 preserves before 50, after 49 and pre-cost fatigue');
    foreach ([1,11,22,33,44] as $raw) requireTactical(classifyOnlineD100Outcome($raw, 0, 0, 100)['code'] === 'critical-success', 'Critical successes classify on raw dice');
    foreach ([66,77,88,99,100] as $raw) requireTactical(classifyOnlineD100Outcome($raw, 100, 0, -100)['code'] === 'critical-failure', 'Critical failures cannot be fabricated away with result adjustment');
    requireTactical(classifyOnlineD100Outcome(10, 40)['code'] === 'success' && classifyOnlineD100Outcome(55, 0)['code'] === 'special-success', 'Ten is ordinary and 55 special');
});

if ($auditFailures !== []) throw new RuntimeException(implode("\n", $auditFailures));
fwrite(STDOUT, 'Audit combat PHP : ' . $auditChecks . " scénarios réussis\n");
