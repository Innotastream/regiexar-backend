<?php
declare(strict_types=1);

// Included by tactical-lifecycle.php: synthetic accounts and in-memory SQL only.
$db = fixture();
$db->put('map:scene-one', ['naturalWidth' => 1000, 'naturalHeight' => 1000, 'gridSize' => 50]);
$character = $db->payload('character:character-player');
$character['resources']['hp'] = 0;
$db->put('character:character-player', $character);
$monster = $db->payload('token:scene-one:token-monster');
$monster['x'] = 21;
$monster['abilities'] = [['id' => 'audit-area', 'name' => 'Onde', 'effect' => 'damage', 'formula' => '4',
    'damageType' => 'ignore', 'castingStatId' => '', 'damageTargeting' => ['mode' => 'area', 'radiusCells' => 1]]];
$db->put('token:scene-one:token-monster', $monster);
$second = $db->payload('token:scene-one:token-monster-two');
$second['x'] = 22;
$db->put('token:scene-one:token-monster-two', $second);
$response = runCommand($db, 'token.attack', ['sourceTokenId' => 'token-monster', 'targetTokenId' => 'token-player',
    'attackKind' => 'ability', 'attackId' => 'audit-area', 'opposed' => true, 'requestId' => 'audit-area-ko-center-0001'], true, 'account-gm');
requireTactical($response->status === 200, 'The synthetic area attack is accepted: ' . $response->getMessage());
$area = array_column($response->body['areaAttacks'] ?? [], null, 'targetTokenId');
requireTactical(($area['token-monster-two']['status'] ?? '') === 'awaiting-opposition', 'A KO center must not disable another area target opposition.');
$sibling = $area['token-monster-two'];
$opposition = ['attackId' => $sibling['id'], 'requestId' => 'audit-area-opposition-0001', 'statId' => 'monster-two-force'];
$response = runCommand($db, 'token.attack.oppose', $opposition, true, 'account-gm');
requireTactical($response->status === 200, 'A secondary area target resolves opposition: ' . $response->getMessage());
$revision = $db->revision;
$retry = runCommand($db, 'token.attack.oppose', $opposition, true, 'account-gm');
requireTactical($retry->status === 200 && ($retry->body['deduplicated'] ?? false) && $db->revision === $revision,
    'Each area target has its own idempotent opposition receipt.');

$targeting = ['mode' => 'area', 'radiusCells' => 1, 'origin' => 'caster',
    'affectCaster' => false, 'affectAllies' => false, 'affectEnemies' => true];
requireTactical(validApplicationDamageTargeting($targeting), 'A caster-centered hostile area is valid.');
requireTactical(!validApplicationDamageTargeting([...$targeting, 'affectEnemies' => false])
    && !validApplicationDamageTargeting([...$targeting, 'origin' => 'unknown']), 'An empty or unknown area is rejected.');
$db = fixture();
$map = $db->payload('map:scene-one');
$map['naturalWidth'] = 1000; $map['naturalHeight'] = 1000; $map['gridSize'] = 50;
$db->put('map:scene-one', $map);
$character = $db->payload('character:character-player');
$character['abilities'] = [['id' => 'audit-caster-area', 'name' => 'Onde hostile', 'effect' => 'damage', 'formula' => '7',
    'damageType' => 'ignore', 'castingStatId' => '', 'onHitConditions' => ['Influencé'], 'damageTargeting' => $targeting]];
$db->put('character:character-player', $character);
$monster = $db->payload('token:scene-one:token-monster');
$monster['x'] = 21; $db->put('token:scene-one:token-monster', $monster);
$ally = $db->payload('token:scene-one:token-monster-two');
$ally['x'] = 22; $ally['controllerPlayerId'] = 'account-player';
$db->put('token:scene-one:token-monster-two', $ally);
$request = ['sourceTokenId' => 'token-player', 'targetTokenId' => 'token-player',
    'attackKind' => 'ability', 'attackId' => 'audit-caster-area', 'opposed' => false,
    'requestId' => 'audit-caster-area-0001'];
$response = runCommand($db, 'token.attack', $request, true, 'account-gm');
requireTactical($response->status === 200, 'A caster-centered area accepts its source as anchor: ' . $response->getMessage());
requireTactical(($response->body['attack']['areaAnchorOnly'] ?? false) === true
    && array_column($response->body['areaAttacks'] ?? [], 'targetTokenId') === ['token-monster'],
    'Only the enemy receives an area attack; the source is an inert anchor.');
requireTactical(str_contains(onlineAttackDiscordContent($response->body['attack']), 'lance Onde hostile autour de lui'),
    'The area announcement does not present the source as its own victim.');
requireTactical(($db->payload('character:character-player')['resources']['hp'] ?? null) === $character['resources']['hp']
    && ($db->payload('token:scene-one:token-monster-two')['hp'] ?? null) === $ally['hp']
    && ($db->payload('token:scene-one:token-monster')['hp'] ?? null) === $monster['hp'] - 7,
    'Self and ally keep their HP; the enemy loses seven.');
$beforeRetry = $db->revision;
$retry = runCommand($db, 'token.attack', $request, true, 'account-gm');
requireTactical($retry->status === 200 && ($retry->body['deduplicated'] ?? false) && $db->revision === $beforeRetry,
    'Caster-centered area deduplicates its launch and damage.');

$db = fixture();
$character = $db->payload('character:character-player');
$character['resources']['hp'] = 100;
$character['armorCategory'] = 'special';
$character['armor'] = 50;
$db->put('character:character-player', $character);
$initiative = [...$db->payload('initiative:scene-one'), 'combatId' => 'audit-combat', 'turnSerial' => 1];
$db->put('initiative:scene-one', $initiative);
$activity = $db->payload('activity');
$activity['damageOverTime'] = [];
foreach (['first', 'second'] as $name) $activity['damageOverTime'][] = [
    'id' => 'dot-audit-' . $name, 'sceneId' => 'scene-one', 'combatId' => 'audit-combat',
    'targetTokenId' => 'token-player', 'label' => 'Brûlure', 'formula' => '10', 'damageType' => 'physical',
    'remainingTurns' => 2, 'lastTurnSerial' => 1];
$activity['nextAttackGuards'] = [
    ['id' => 'guard-survives', 'sceneId' => 'scene-one', 'combatId' => 'audit-combat', 'targetTokenId' => 'token-player', 'percent' => 50],
    ['id' => 'guard-ended', 'sceneId' => 'scene-two', 'combatId' => 'other-combat', 'targetTokenId' => 'token-copy', 'percent' => 25]];
$db->put('activity', $activity);
$db->put('initiative:scene-two', ['active' => true, 'order' => ['token-copy'], 'currentIndex' => 0, 'combatId' => 'other-combat', 'turnSerial' => 1]);
$records = applicationDomainRecords($db, array_keys($db->domains));
$pending = [];
queueOnlineDomainUpsert($pending, $records, 'initiative:scene-one', [...$initiative, 'turnSerial' => 2, 'currentIndex' => 1]);
queueOnlineDomainUpsert($pending, $records, 'initiative:scene-two', [...$db->payload('initiative:scene-two'), 'active' => false]);
applyApplicationDamageOverTimeOnTurn($db, $records, $pending, ['id' => 'account-gm', 'display_name' => 'MJ test', 'effective_mode' => 'gm']);
requireTactical(($pending['character:character-player']['payload']['resources']['hp'] ?? null) === 90,
    'Two DOTs use current character armor and accumulate their actual HP loss in the same transaction.');
requireTactical(array_column($pending['activity']['payload']['damageOverTime'], 'remainingTurns') === [1, 1],
    'DOT duration decreases exactly once when its target starts its turn.');
requireTactical(array_column($pending['activity']['payload']['nextAttackGuards'], 'id') === ['guard-survives'],
    'DOTs do not consume one-attack protection, and a simultaneous combat ending cannot restore old guards.');
$privateRoll = $pending['activity']['payload']['rolls'][0];
$publicRoll = publicOnlineAttackRoll($privateRoll, false, 'damage');
requireTactical($publicRoll['total'] === 5 && $publicRoll['characterName'] === 'Personnage',
    'A public DOT shows the victim and actual HP loss without exposing armor.');

$attack = ['id' => 'attack-audit-resume', 'areaRadiusCells' => 2, 'validationKind' => 'reaction',
    'provisionalStatus' => 'defended', 'oppositionAbility' => ['abilityId' => 'parry'],
    'damageOverTime' => ['label' => 'Effet', 'formula' => '1', 'damageType' => 'ignore', 'turns' => 2]];
$restored = preserveApplicationAbilityExtensions('activity', ['pendingAttacks' => [['id' => $attack['id']]]], ['pendingAttacks' => [$attack]]);
foreach (['areaRadiusCells', 'validationKind', 'oppositionAbility', 'damageOverTime'] as $field) {
    requireTactical($restored['pendingAttacks'][0][$field] === $attack[$field], 'An older snapshot preserves ' . $field . '.');
}

$defended = null;
for ($attempt = 0; $attempt < 200 && $defended === null; $attempt++) {
    $candidate = fixture();
    $character = $candidate->payload('character:character-player');
    $character['resources']['mana'] = 30;
    $character['resources']['maxMana'] = 30;
    $character['stats']['force'] = 100;
    $character['abilities'] = [['id' => 'audit-counter', 'name' => 'Renvoi', 'effect' => 'opposition', 'formula' => '0', 'cooldownRounds' => 3,
        'opposition' => ['statId' => 'character-stat-force', 'options' => [['id' => 'mana', 'label' => 'Renvoi renforcé', 'manaCost' => 10, 'reflectPercent' => 50]]]]];
    $candidate->put('character:character-player', $character);
    $attack = ['id' => 'attack-audit-counter-0001', 'requestId' => 'audit-counter-hit-0001', 'sceneId' => 'scene-one',
        'sourceTokenId' => 'token-monster', 'targetTokenId' => 'token-player', 'sourceName' => 'Créature', 'targetName' => 'Personnage',
        'accountId' => 'account-gm', 'attackerRole' => 'gm', 'playerName' => 'MJ test', 'attackName' => 'Griffe',
        'status' => 'awaiting-opposition', 'damageType' => 'ignore', 'damageFormula' => '20',
        'hit' => ['raw' => 75, 'outcome' => ['raw' => 75, 'result' => 75, 'code' => 'success', 'success' => true, 'threshold' => 90]]];
    $activity = $candidate->payload('activity');
    $activity['pendingAttacks'] = [$attack];
    $activity['attackReceipts'] = [['requestId' => $attack['requestId'], 'accountId' => 'account-gm', 'expiresAt' => PHP_INT_MAX, 'attack' => $attack]];
    $candidate->put('activity', $activity);
    $payload = ['attackId' => $attack['id'], 'requestId' => 'audit-counter-defense-0001', 'abilityId' => 'audit-counter', 'optionId' => 'mana'];
    $response = runCommand($candidate, 'token.attack.oppose', $payload);
    requireTactical($response->status === 200, 'A configured opposition ability resolves: ' . $response->getMessage());
    if (($response->body['attack']['status'] ?? '') === 'defended') $defended = [$candidate, $response, $payload];
}
requireTactical(is_array($defended), 'The configured parry reaches an ordinary winning result.');
[$db, $response, $payload] = $defended;
requireTactical(($response->body['attack']['reflection']['appliedDamage'] ?? null) === 10
    && $db->payload('token:scene-one:token-monster')['hp'] === 30
    && $db->payload('character:character-player')['resources']['mana'] === 20,
    'The selected option reflects 50 percent, spends 10 mana and applies actual damage.');
requireTactical(in_array('audit-counter', array_column($db->payload('activity')['actionTimers'], 'abilityId'), true), 'Parry starts its configured cooldown.');
$revision = $db->revision;
$retry = runCommand($db, 'token.attack.oppose', $payload);
requireTactical($retry->status === 200 && ($retry->body['deduplicated'] ?? false) && $revision === $db->revision,
    'A parry retry cannot spend mana or reflect damage again.');
