<?php
declare(strict_types=1);

foreach (['token-monster', 'token-independent', 'token-player'] as $id) {
    $db = fixture();
    $db->put('scene:scene-one', ['id' => 'scene-one', 'name' => 'Marais forêt']);
    $token = $db->payload('token:scene-one:' . $id); $token['initiativeBonus'] = 7;
    $db->put('token:scene-one:' . $id, $token);
    if ($id === 'token-player') { $c = $db->payload('character:character-player'); $c['initiativeBonus'] = 7; $db->put('character:character-player', $c); }
    $arguments = ['sceneId' => 'scene-one', 'layerId' => 'ground', 'tokenId' => $id, 'kind' => 'initiative', 'requestId' => 'initiative-patch-' . $id];
    $response = runCommand($db, 'token.roll', $arguments, true, 'account-gm');
    requireTactical($response->status === 200 && $response->body['initiativeUpdated'] && $response->body['roll']['formula'] === '1d100-7', 'MJ initiative: ' . $id . ' ' . json_encode($response->body));
    requireTactical($db->payload('token:scene-one:' . $id)['initiative'] === $response->body['roll']['total'], 'The same authoritative roll is stored in initiative.');
    $revision = $db->revision; $retry = runCommand($db, 'token.roll', $arguments, true, 'account-gm');
    requireTactical($retry->status === 200 && $retry->body['deduplicated'] && $revision === $db->revision, 'MJ initiative retry cannot reroll or revise state.');
}
$db = fixture();
$records = [];
foreach (['a' => 60, 'b' => -7, 'c' => 12, 'd' => null] as $id => $score) $records['token:scene-one:' . $id] = ['payload' => ['id' => $id, 'name' => $id, 'initiative' => $score]];
requireTactical(sortedOnlineInitiativeOrder(['a', 'b', 'c', 'd'], $records, 'scene-one') === ['b', 'c', 'a', 'd'], 'PHP keeps lowest initiative first, including negative bonuses and unrolled tokens.');

$c = $db->payload('character:character-player');
$c['stats'] = ['force' => 70, 'agility' => 20]; $c['temporaryStats'] = ['force' => 0, 'agility' => 60];
$c['fatigue'] = ['current' => 51, 'max' => 100]; $c['secret'] = ['mentalResistance' => 55, 'mentalResistanceMax' => 100, 'notes' => 'secret'];
$visible = visibleCharacter($c);
requireTactical($visible['resources']['mentalResistance'] === 55.0 && !isset($visible['secret']), 'Mental resistance migrates into public resources without exposing MJ notes.');
$rules = synchronizeOnlineCharacterToken(['characterId' => $c['id']], $c);
$values = array_column($rules['stats'], 'value', 'id');
requireTactical($values['character-stat-force'] === '0' && $values['character-stat-agility'] === '59' && $values['character-stat-mentalResistance'] === '54', 'Temporary zero overrides base; fatigue beyond 50 affects statistic thresholds.');
requireTactical(applicationTacticalRollSpecification($rules, ['kind' => 'luck'])['formula'] === '1d100', 'Fatigue never modifies Chance.');

function patchAbilityFixture(array $ability): MemoryConnection {
    $db = fixture(); $c = $db->payload('character:character-player');
    $c['abilities'] = [array_replace(['id' => 'patch-ability', 'name' => 'Capacité test', 'effect' => 'movement', 'formula' => '0', 'castingStatId' => '', 'manaCost' => 1, 'hpCost' => 1, 'fatigueCost' => 2, 'cooldownRounds' => 3, 'reusableInTurn' => true, 'difficultyIncrement' => 10], $ability)];
    $c['fatigue'] = ['current' => 0, 'max' => 100];
    $c['linkedTokens'] = [['id' => 'summon-wolf', 'name' => 'Loup', 'size' => 40, 'color' => '#22aa33', 'hp' => 15, 'maxHp' => 15, 'mana' => 2, 'maxMana' => 2, 'damageDice' => '1d6', 'hitThreshold' => 50]];
    $db->put('character:character-player', $c); return $db;
}
$base = ['sceneId' => 'scene-one', 'layerId' => 'ground', 'sourceTokenId' => 'token-player', 'targetTokenId' => 'token-player', 'abilityId' => 'patch-ability'];
$db = patchAbilityFixture([]);
for ($i = 0; $i < 3; $i++) {
    $payload = [...$base, 'requestId' => 'patch-movement-use-000' . $i];
    $response = runCommand($db, 'ability.use', $payload);
    requireTactical($response->status === 200 && $response->body['effect'] === 'movement' && count($response->body['rolls']) === 0, 'Movement consumes costs without inventing damage dice.');
    requireTactical($response->body['cast']['difficultyPenalty'] === 10 * $i, 'Difficulty increments +10, +20 cumulatively inside one turn.');
    $revision = $db->revision; $retry = runCommand($db, 'ability.use', $payload);
    requireTactical($retry->status === 200 && $retry->body['deduplicated'] && $db->revision === $revision, 'An ability retry spends no additional resource.');
}
$c = $db->payload('character:character-player');
requireTactical($c['resources']['hp'] == 7 && $c['resources']['mana'] == 2 && $c['fatigue']['current'] == 6, 'Every accepted use pays HP, mana and fatigue exactly once.');
$initiative = $db->payload('initiative:scene-one'); $initiative['currentIndex'] = 1; $initiative['turnSerial'] = 1; $db->put('initiative:scene-one', $initiative);
$response = runCommand($db, 'ability.use', [...$base, 'requestId' => 'patch-movement-next-turn']);
requireTactical($response->status === 409 && ($response->body['code'] ?? '') === 'ability_on_cooldown', 'Reusable ability enters cooldown after the turn ends.');
$initiative['round'] = 4; $initiative['turnSerial'] = 6; $db->put('initiative:scene-one', $initiative);
$response = runCommand($db, 'ability.use', [...$base, 'requestId' => 'patch-movement-ready-round']);
requireTactical($response->status === 200 && $response->body['cast']['difficultyPenalty'] === 0, 'The ready round is counted and the next-turn difficulty starts at zero.');
$db = patchAbilityFixture(['effect' => 'summoning', 'summonLinkedTokenId' => 'summon-wolf']);
$payload = [...$base, 'requestId' => 'patch-summoning-use-0001']; $response = runCommand($db, 'ability.use', $payload);
requireTactical($response->status === 200 && !empty($response->body['summonedTokenId']), 'Summoning creates a token through the real command.');
$id = $response->body['summonedTokenId']; $summon = $db->payload('token:scene-one:' . $id);
requireTactical($summon['hp'] == 15 && $summon['followCharacter'] === false && $summon['controllerPlayerId'] === 'account-player' && in_array($id, $db->payload('token-index:scene-one')['order'], true), 'The summoned unit has independent resources, player control and a scene index entry.');
$revision = $db->revision; $retry = runCommand($db, 'ability.use', $payload);
requireTactical($retry->status === 200 && $retry->body['summonedTokenId'] === $id && $db->revision === $revision, 'Retrying an invocation does not create a second unit.');
$db = patchAbilityFixture(['hpCost' => 100]); $revision = $db->revision;
$response = runCommand($db, 'ability.use', [...$base, 'requestId' => 'patch-too-expensive-0001']);
requireTactical($response->status === 409 && $db->revision === $revision && $db->payload('character:character-player')['resources']['mana'] == 5, 'A rejected cost changes no resource or journal.');

// A failed reuse must never erase the cooldown acquired by an earlier success.
$db = patchAbilityFixture(['manaCost' => 0, 'hpCost' => 0, 'fatigueCost' => 0]);
runCommand($db, 'ability.use', [...$base, 'requestId' => 'patch-before-failure-001']);
$records = applicationDomainRecords($db); $pending = [];
$character = $db->payload('character:character-player');
$source = synchronizeOnlineCharacterToken($db->payload('token:scene-one:token-player'), $character);
$plan = applicationAbilityCastingPlan($character['abilities'][0], $source, 'scene-one', $db->payload('initiative:scene-one'), $db->payload('activity')['actionTimers']);
onlineCommitAbilityCasting($db, $records, $pending, $plan, ['success' => false, 'roll' => null], $source, $GLOBALS['testIdentity']);
$timer = $pending['activity']['payload']['actionTimers'][0];
requireTactical($timer['cooldownActive'] === true && $timer['readyRound'] === 4 && $timer['useCount'] === 2, 'Failure retains earlier cooldown while counting the reuse.');

// A reduced classic failure pays its costs, resolves no effect here, and
// creates a non-reusable one-round timer even when the normal rule is longer.
$db = patchAbilityFixture(['castingStatId' => 'character-stat-intelligence', 'reducedFailureCooldown' => true,
    'manaCost' => 1, 'hpCost' => 0, 'fatigueCost' => 0, 'cooldownRounds' => 9, 'restRecharge' => 'long']);
$records = applicationDomainRecords($db); $pending = [];
$character = $db->payload('character:character-player');
$source = synchronizeOnlineCharacterToken($db->payload('token:scene-one:token-player'), $character);
$initiative = $db->payload('initiative:scene-one');
$plan = applicationAbilityCastingPlan($character['abilities'][0], $source, 'scene-one', $initiative, $db->payload('activity')['actionTimers']);
$cast = onlineCommitAbilityCasting($db, $records, $pending, $plan, ['success' => false, 'roll' => null], $source, $GLOBALS['testIdentity']);
$timer = $pending['activity']['payload']['actionTimers'][0];
requireTactical($cast['remainingRounds'] === 1 && $cast['reducedFailureApplied'] === true, 'Reduced failure reports one round instead of the configured nine rounds or long rest.');
requireTactical($timer['cooldownActive'] === true && $timer['cooldown'] === 1 && $timer['readyRound'] === $plan['usedRound'] + 1, 'Reduced failure creates exactly one round of cooldown.');
requireTactical($timer['reusableInTurn'] === false && $timer['restRecharge'] === 'none', 'Reusable-in-turn and rest recharge cannot bypass or extend the reduced failure.');
requireTactical($pending['character:character-player']['payload']['resources']['mana'] == 4, 'Reduced failure spends the authoritative mana cost exactly once.');

// On-hit conditions are delayed with damage and committed exactly once.
foreach ([true, false] as $combat) {
    $db = patchAbilityFixture(['effect' => 'damage', 'formula' => '2', 'onHitConditions' => ['Endormi'], 'cooldownRounds' => 0, 'manaCost' => 0, 'hpCost' => 0, 'fatigueCost' => 0]);
    $initiative = $db->payload('initiative:scene-one'); $initiative['active'] = $combat; $db->put('initiative:scene-one', $initiative);
    $payload = ['sceneId' => 'scene-one', 'sourceTokenId' => 'token-player', 'targetTokenId' => 'token-monster', 'attackKind' => 'ability', 'attackId' => 'patch-ability', 'requestId' => 'patch-on-hit-' . ($combat ? 'combat' : 'pending') . '-001'];
    $response = runCommand($db, 'token.attack', $payload);
    requireTactical($response->status === 200, 'Condition attack accepted: ' . json_encode($response->body));
    $attack = $response->body['attack'];
    if (!$combat) {
        requireTactical(!in_array('Endormi', $db->payload('token:scene-one:token-monster')['conditions'] ?? [], true), 'No effect before MJ approval.');
        $activity = $db->payload('activity');
        requireTactical(patchGroupDomains($db, ['activity' => $activity])->status === 200, 'The second MJ can synchronize the pending effect.');
        $response = runCommand($db, 'token.attack.resolve', ['attackId' => $attack['id'], 'decision' => 'approve', 'confirmed' => true], true, 'account-gm');
        requireTactical($response->status === 200, 'MJ approval commits the effect.');
    }
    requireTactical($db->payload('token:scene-one:token-monster')['conditions'] === ['Endormi'], 'Successful attack applies ordinary token conditions.');
    $hp = $db->payload('token:scene-one:token-monster')['hp'];
    runCommand($db, 'token.attack', $payload);
    requireTactical($db->payload('token:scene-one:token-monster')['hp'] === $hp && $db->payload('token:scene-one:token-monster')['conditions'] === ['Endormi'], 'Retry cannot duplicate damage or effects.');
}

// Invocations retain their own HP when controlled by their summoner.
$db = patchAbilityFixture(['effect' => 'summoning', 'summonLinkedTokenId' => 'summon-wolf']);
$response = runCommand($db, 'ability.use', [...$base, 'requestId' => 'patch-summon-control-001']);
$summonedId = $response->body['summonedTokenId']; $before = $db->payload('character:character-player')['resources']['hp'];
$response = runCommand($db, 'token.resource.adjust', ['sceneId' => 'scene-one', 'tokenId' => $summonedId, 'resource' => 'hp', 'delta' => -3, 'requestId' => 'patch-summon-resource-001']);
requireTactical($response->status === 200 && $db->payload('token:scene-one:' . $summonedId)['hp'] == 12 && $db->payload('character:character-player')['resources']['hp'] === $before, 'A summoner edits invocation HP without touching the base sheet.');

// Every route obeys an immovable flag, including MJ domain writes.
$db = fixture(); $token = $db->payload('token:scene-one:token-player'); $token['immovable'] = true; $db->put('token:scene-one:token-player', $token);
foreach ([false, true] as $gm) {
    $revision = $db->revision;
    $response = runCommand($db, 'token.move', ['sceneId' => 'scene-one', 'tokenId' => 'token-player', 'x' => 30, 'y' => 50], $gm, $gm ? 'account-gm' : 'account-player');
    requireTactical($response->status === 409 && $db->revision === $revision, 'An immovable token cannot move for either role.');
}
$response = patchGroupDomains($db, ['token:scene-one:token-player' => [...$token, 'x' => 40]]);
requireTactical($response->status === 409 && $db->payload('token:scene-one:token-player')['x'] === 20, 'Raw domain movement also respects immovability.');
$response = patchGroupDomains($db, ['token:scene-one:token-player' => [...$token, 'immovable' => false, 'x' => 40]]);
requireTactical($response->status === 200, 'The MJ can explicitly release an immovable token.');

foreach ([1, 7, 16, 40] as $range) {
    requireTactical(applicationEffectiveVisionDistance(8, 'custom', 'full', ['distance' => $range, 'night' => false]) === $range, 'Custom day ignores night sight.');
    requireTactical(applicationEffectiveVisionDistance(8, 'custom', 'full', ['distance' => $range, 'night' => true]) === $range * 2, 'Custom night respects full night sight.');
}
requireTactical(!validApplicationMapFogState(['customLighting' => ['distance' => 200, 'night' => false]]), 'Custom lighting remains bounded on writes.');


// Stream always combines party vision, even when its GM account is playing.
$map = ['activeLayerId' => 'ground', 'naturalWidth' => 2000, 'naturalHeight' => 1000, 'gridSize' => 50,
    'vision' => ['enabled' => true, 'distance' => 4, 'shared' => false, 'isolatedPlayerIds' => ['player-b']],
    'fog' => ['version' => 1, 'enabled' => false, 'width' => 32, 'height' => 32, 'mask' => ''],
    'tokens' => [
        ['id' => 'a', 'name' => 'Inho', 'x' => 15, 'y' => 50, 'layerId' => 'ground', 'controllerPlayerId' => 'player-a', 'visionDistance' => 4],
        ['id' => 'b', 'name' => 'Autre joueur', 'x' => 85, 'y' => 50, 'layerId' => 'ground', 'controllerPlayerId' => 'player-b', 'visionDistance' => 4],
        ['id' => 'enemy', 'name' => 'Visible par le second', 'x' => 87, 'y' => 50, 'layerId' => 'ground'],
        ['id' => 'secret', 'name' => 'Secret MJ', 'x' => 85, 'y' => 50, 'layerId' => 'ground', 'hidden' => true],
    ]];
$state = ['map' => $map, 'characters' => [], 'initiative' => []];
$identity = ['id' => 'player-a', 'display_name' => 'MJ en mode Joueur', 'permanent_role' => 'gm', 'effective_mode' => 'player'];
$view = publicPlayerState($state, $identity, []);
$stream = publicPlayerState($state, $identity, [], true);
requireTactical(!in_array('enemy', array_column($view['map']['tokens'], 'id'), true), 'Personal vision still excludes the distant party member.');
requireTactical(in_array('enemy', array_column($stream['map']['tokens'], 'id'), true) && !in_array('secret', array_column($stream['map']['tokens'], 'id'), true), 'Stream combines isolated party members immediately without disclosing hidden tokens.');
requireTactical(($stream['myCharacters'] ?? []) === [] && !applicationVisionCoversPoint($stream['map']['visionMask'], 87, 50), 'Stream remains a public projection without personal character sheets.');
