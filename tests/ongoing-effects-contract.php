<?php
declare(strict_types=1);
define('XAR_TACTICAL_FIXTURE_ONLY',true);
require __DIR__ . '/tactical-lifecycle.php';

function effectFixture(array $dots): MemoryConnection {
    $db = fixture();
    $activity = $db->payload('activity');
    $activity['damageOverTime'] = $dots;
    $db->put('activity', $activity);
    return $db;
}
function requireGmIdentity(PDO $connection): array {
    return $GLOBALS['testIdentity'];
}
function visitEffectCombatTurn(MemoryConnection $db, array $initiative): void {
    $key = 'initiative:scene-one';
    $GLOBALS['testIdentity'] = ['id'=>'account-gm', 'display_name'=>'MJ fictif', 'effective_mode'=>'gm', 'permanent_role'=>'gm'];
    $GLOBALS['testBody'] = [
        'stateSchemaVersion'=>XAR_SESSION_SCHEMA_VERSION, 'domainSchemaVersion'=>XAR_DOMAIN_SCHEMA_VERSION,
        'changes'=>[['key'=>$key, 'operation'=>'upsert', 'expectedRevision'=>$db->domains[$key]['revision'], 'payload'=>$initiative]],
    ];
    try {
        patchApplicationDomains($db);
    } catch (TestResponse $response) {
        requireTactical($response->status === 200, 'The exact MJ turn update is accepted: ' . json_encode($response->body));
        return;
    }
    throw new RuntimeException('Missing initiative response.');
}
function endEffectCombat(MemoryConnection $db): array {
    $records = applicationDomainRecords($db);
    $pending = [];
    queueOnlineDomainUpsert($pending, $records, 'initiative:scene-one', [...$db->payload('initiative:scene-one'), 'active' => false]);
    applyApplicationDamageOverTimeOnTurn($db, $records, $pending, ['id' => 'account-gm', 'display_name' => 'MJ', 'effective_mode' => 'gm', 'permanent_role' => 'gm']);
    foreach ($pending as $key => $change) $db->put($key, $change['payload']);
    return $db->payload('activity');
}
// Compatibility fixture for remainder choices stored by an older version.
// New combat endings must never create these entries.
function historicalRemainder(MemoryConnection $db): void {
    $records=applicationDomainRecords($db);$pending=[];$activity=$db->payload('activity');
    onlineCaptureEndedDots($db,$records,$pending,$activity,['scene-one'=>true]);
    $activity['damageOverTime']=[];$db->put('activity',$activity);
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
requireTactical($activity['damageOverTime'] === [] && $activity['pendingDotResolutions'] === [] && (int)$db->payload('token:scene-one:token-monster')['hp']===40, 'Combat end expires DOT without a remaining-damage prompt or HP change');
$stale = preserveApplicationAbilityExtensions('activity', ['damageOverTime' => [$dot], 'pendingDotResolutions' => [], 'resourceReceipts' => []], $activity);
requireTactical($stale['damageOverTime'] === [] && $stale['pendingDotResolutions'] === [] && count($stale['resourceReceipts']) === 1, 'Stale activity cannot restore expired DOT or erase receipts');
$db=effectFixture([[...$dot,'id'=>'dot-contract-two']]);historicalRemainder($db);
$body = ['effectId' => 'dot-contract-two', 'decision' => 'roll', 'requestId' => 'resolve-effect-contract-0001'];
$resolved = runCommand($db, 'combat.dot.resolve', $body, true, 'account-gm');
requireTactical($resolved->status === 200 && $resolved->body['roll']['formula'] === '20' && $resolved->body['appliedDamage'] === 20 && (int) $db->payload('token:scene-one:token-monster')['hp'] === 20, 'Calculated remainder applies once to current HP');
requireTactical(runCommand($db, 'combat.dot.resolve', $body, true, 'account-gm')->body['deduplicated'] === true && (int) $db->payload('token:scene-one:token-monster')['hp'] === 20, 'Damage retry never applies twice');
requireTactical(runCommand($db, 'combat.dot.resolve', [...$body, 'decision' => 'custom', 'formula' => '7'], true, 'account-gm')->status === 409, 'Reusing an id for different damage is rejected');
$db = effectFixture([[...$dot, 'label' => 'Brûlure', 'damageType' => 'magical']]);
$target = $db->payload('token:scene-one:token-monster');
$target['elementalResistances'] = ['fire' => 100];
$db->put('token:scene-one:token-monster', $target);
historicalRemainder($db);
$body = ['effectId' => $dot['id'], 'decision' => 'roll', 'requestId' => 'resolve-fire-immune-0001'];
$response = runCommand($db, 'combat.dot.resolve', $body, true, 'account-gm');
requireTactical($response->status === 200 && $response->body['appliedDamage'] === 0
    && $response->body['roll']['mapEvent']['applied'] === false
    && $db->payload('token:scene-one:token-monster')['hp'] === 40
    && $db->payload('activity')['pendingDotResolutions'] === [], 'An immune target clears the legacy remainder without a damage write.');
requireTactical(runCommand($db, 'combat.dot.resolve', $body, true, 'account-gm')->body['deduplicated'] === true, 'Fully resisted damage is deduplicated.');
foreach (['custom', 'skip'] as $decision) {
    $db = effectFixture([$dot]); historicalRemainder($db);
    $response = runCommand($db, 'combat.dot.resolve', ['effectId' => $dot['id'], 'decision' => $decision, 'formula' => '7', 'requestId' => 'resolve-' . $decision . '-contract-0001'], true, 'account-gm');
    requireTactical($response->status === 200 && (int) $db->payload('token:scene-one:token-monster')['hp'] === ($decision === 'custom' ? 33 : 40) && $db->payload('activity')['pendingDotResolutions'] === [], 'Custom/skip resolves only the selected remainder');
}
$db = effectFixture([$dot]);
$target = $db->payload('token:scene-one:token-monster'); $target['hp'] = -1; $db->put('token:scene-one:token-monster', $target);
requireTactical(endEffectCombat($db)['pendingDotResolutions'] === [], 'Dead creatures produce no remaining-damage prompt');
$db = effectFixture([[...$dot, 'targetTokenId' => 'token-player']]);
$character = $db->payload('character:character-player'); $character['resources']['hp'] = 0; $db->put('character:character-player', $character);
requireTactical(endEffectCombat($db)['pendingDotResolutions'] === [] && (int)$db->payload('character:character-player')['resources']['hp']===0, 'A KO player also loses round effects without receiving damage or healing');
$db = effectFixture([$dot]); historicalRemainder($db);
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

$db = fixture();
$before = $db->payload('initiative:scene-one');
$periodic = ['sceneId'=>'scene-one','layerId'=>'ground','tokenId'=>'token-monster','direction'=>-1,
    'label'=>'Plaie de recette','formula'=>'1d2+3','turns'=>2,'requestId'=>'manual-hp-php-proof-0001'];
requireTactical(runCommand($db,'combat.effect.periodic-hp',$periodic)->status===403,'Only the GM creates recurring HP effects');
$created = runCommand($db,'combat.effect.periodic-hp',$periodic,true,'account-gm');
requireTactical($created->status===200 && $created->body['effect']['remainingTurns']===2, 'Manual effect is created for exactly two turns: '.json_encode($created->body));
requireTactical(runCommand($db,'combat.effect.periodic-hp',$periodic,true,'account-gm')->body['deduplicated']===true,'The same request creates one effect');
requireTactical(runCommand($db,'combat.effect.periodic-hp',[...$periodic,'startNextTurn'=>true],true,'account-gm')->body['deduplicated']===true,'Explicit default is compatible with an older receipt');
requireTactical((int)$db->payload('token:scene-one:token-monster')['hp']===40,'Creating the effect leaves HP until the target turn');
$progress = static function(MemoryConnection $db,array $initiative):void {
    $records=applicationDomainRecords($db);$pending=[];
    queueOnlineDomainUpsert($pending,$records,'initiative:scene-one',$initiative);
    applyApplicationDamageOverTimeOnTurn($db,$records,$pending,['id'=>'account-gm','display_name'=>'MJ','effective_mode'=>'gm','permanent_role'=>'gm']);
    foreach($pending as $id=>$change)$db->put($id,$change['payload']);
};
$clock = [...$before,'active'=>true,'turnsStarted'=>true,'order'=>['token-player','token-monster'],'currentIndex'=>1,'round'=>1];
$progress($db,[...$clock,'turnSerial'=>1,'rewoundTurnSerial'=>1]);
requireTactical((int)$db->payload('token:scene-one:token-monster')['hp']===40 && $db->payload('activity')['damageOverTime'][0]['remainingTurns']===2,'A backward visit does not apply the effect');
$progress($db,[...$clock,'turnSerial'=>2]);
requireTactical((int)$db->payload('token:scene-one:token-monster')['hp']===40 && $db->payload('activity')['damageOverTime'][0]['remainingTurns']===2,'Redisplaying the placement turn does not apply the effect');
$progress($db,[...$clock,'turnSerial'=>3,'round'=>2]);
requireTactical(in_array((int)$db->payload('token:scene-one:token-monster')['hp'],[35,36],true),'The target loses the rolled HP on its next turn');
requireTactical($db->payload('activity')['damageOverTime'][0]['remainingTurns']===1,'One turn was consumed');
$removed=runCommand($db,'combat.effect.remove',['kind'=>'dot','effectId'=>$created->body['effect']['id'],'requestId'=>'manual-hp-remove-php-0001'],true,'account-gm');
requireTactical($removed->status===200 && $db->payload('activity')['damageOverTime']===[],'The GM can remove the remaining periodic effect');

$db=fixture();
$db->put('initiative:scene-one',[...$db->payload('initiative:scene-one'),'active'=>false]);
$now=[...$periodic,'startNextTurn'=>false,'tokenId'=>'token-player','direction'=>1,'formula'=>'4','label'=>'Soin de recette','requestId'=>'manual-hp-php-immediate-0001'];
$immediate=runCommand($db,'combat.effect.periodic-hp',$now,true,'account-gm');
requireTactical($immediate->status===200 && $immediate->body['appliedDelta']===4 && $immediate->body['effect']['remainingTurns']===1
    && $db->payload('character:character-player')['resources']['hp']===14,'Immediate healing makes one roll and leaves one of the two turns');
$activity=$db->payload('activity');
$merge=preserveApplicationAbilityExtensions('activity',['resourceReceipts'=>[]],$activity);
requireTactical(count($merge['resourceReceipts'])===1,'A stale activity update cannot remove the receipt for the immediate roll');
requireTactical(runCommand($db,'combat.effect.periodic-hp',$now,true,'account-gm')->body['deduplicated']===true
    && $db->payload('character:character-player')['resources']['hp']===14,'Retry never rolls immediate healing twice');
requireTactical(runCommand($db,'combat.effect.periodic-hp',[...$now,'startNextTurn'=>true],true,'account-gm')->status===409,'The same reference cannot change the start timing');
$advanced=runCommand($db,'mechanics.command',['command'=>'scene.advance','arguments'=>['sceneId'=>'scene-one','requestId'=>'manual-hp-php-turn-0001']],true,'account-gm');
requireTactical($advanced->status===200 && $db->payload('character:character-player')['resources']['hp']===18
    && $db->payload('activity')['damageOverTime']===[],'One manual advance completes the immediate two-turn effect');

$existing = array_map(static fn(int $index): array => [...$dot, 'id'=>'existing-dot-'.$index], range(1, 100));
$db = effectFixture($existing);
$scheduled = runCommand($db, 'combat.effect.periodic-hp', $periodic, true, 'account-gm');
requireTactical($scheduled->status === 200 && count($db->payload('activity')['damageOverTime']) === 101,
    'A full old list does not reject the next scheduled effect.');
$records = applicationDomainRecords($db);
$pending = [];
$attack = applyOnlineAttackConditions($db, $records, $pending, [
    'id'=>'ongoing-capacity-attack', 'sceneId'=>'scene-one', 'targetTokenId'=>'token-monster', 'status'=>'applied',
    'damageOverTime'=>['label'=>'Poison de recette', 'formula'=>'7', 'damageType'=>'poison', 'turns'=>2],
]);
$activity = validatedDomainPayload('activity', $pending['activity']['payload']);
requireTactical(($attack['dotApplied'] ?? false) && count($activity['damageOverTime']) === 102,
    'The attack keeps its poison and the resulting activity remains a valid domain.');

$db = fixture();
$targetKey = 'token:scene-one:token-monster';
$db->put($targetKey, [...$db->payload($targetKey), 'hp'=>100, 'maxHp'=>100]);
$sheetKey = 'character:character-player';
$rule = ['id'=>'clock-probe-poison', 'name'=>'Poison de recette', 'effect'=>'damage', 'formula'=>'1', 'damageType'=>'ignore',
    'castingStatId'=>'', 'rangeCells'=>4,
    'damageOverTime'=>['label'=>'Poison de recette', 'formula'=>'7', 'damageType'=>'poison', 'turns'=>3]];
$db->put($sheetKey, [...$db->payload($sheetKey), 'abilities'=>[$rule]]);
$baseOrder = ['token-player', 'token-monster', 'token-monster-two'];
$clock = ['active'=>true, 'turnsStarted'=>true, 'combatId'=>'combat-dot-navigation',
    'turnSerial'=>1, 'round'=>1, 'order'=>$baseOrder, 'currentIndex'=>0];
$db->put('initiative:scene-one', $clock);
$hit = runCommand($db, 'token.attack', ['sceneId'=>'scene-one', 'sourceTokenId'=>'token-player', 'targetTokenId'=>'token-monster',
    'attackKind'=>'ability', 'attackId'=>$rule['id'], 'opposed'=>false, 'requestId'=>'clock-probe-poison-0001']);
requireTactical($hit->status === 200 && $hit->body['attack']['status'] === 'applied'
    && $db->payload($targetKey)['hp'] === 99, 'The ordinary attack natively applies a three-turn poison.');
$manual = runCommand($db, 'combat.effect.periodic-hp', [...$periodic, 'formula'=>'3', 'requestId'=>'clock-probe-manual-0001'],
    true, 'account-gm');
requireTactical($manual->status === 200, 'The same target also gets a manual DOT waiting for its next turn.');
$delayed = [...$clock, 'order'=>['token-monster','token-player','token-monster-two'],
    'roundBaseOrder'=>$baseOrder, 'delayedTurnIds'=>['token-player']];
foreach ([
    ['label'=>'delay passes to the target', 'clock'=>[...$delayed, 'turnSerial'=>2], 'hp'=>89, 'durations'=>[2,1]],
    ['label'=>'resume the delayed turn', 'clock'=>[...$delayed, 'currentIndex'=>1, 'turnSerial'=>3], 'hp'=>89, 'durations'=>[2,1]],
    ['label'=>'previous shows the target again', 'clock'=>[...$delayed, 'turnSerial'=>4, 'rewoundTurnSerial'=>4], 'hp'=>89, 'durations'=>[2,1]],
    ['label'=>'forward to the postponed token', 'clock'=>[...$delayed, 'currentIndex'=>1, 'turnSerial'=>5], 'hp'=>89, 'durations'=>[2,1]],
    ['label'=>'last creature completes the round', 'clock'=>[...$delayed, 'currentIndex'=>2, 'turnSerial'=>6], 'hp'=>89, 'durations'=>[2,1]],
    ['label'=>'restore the original order next round', 'clock'=>[...$clock, 'round'=>2, 'turnSerial'=>7], 'hp'=>89, 'durations'=>[2,1]],
    ['label'=>'the next genuine target turn', 'clock'=>[...$clock, 'round'=>2, 'currentIndex'=>1, 'turnSerial'=>8], 'hp'=>79, 'durations'=>[1]],
] as $step) {
    visitEffectCombatTurn($db, $step['clock']);
    requireTactical((int) $db->payload($targetKey)['hp'] === $step['hp']
        && array_column($db->payload('activity')['damageOverTime'], 'remainingTurns') === $step['durations'],
        'Only the real turn applies one instance of each active effect: ' . $step['label']);
}
requireTactical(applicationDotPlacementRound($delayed, 'token-player') === ['lastTargetRound'=>1]
    && applicationDotPlacementRound($clock, 'token-monster') === [], 'When an effect is added, a postponed target has already begun its turn.');

$stacked = [...$dot, 'id'=>'dot-audit-parked', 'formula'=>'7', 'family'=>'poison', 'damageType'=>'poison',
    'sourceEffectId'=>'poison-audit', 'combatId'=>'combat-audit-old', 'lastTurnSerial'=>15, 'lastTargetRound'=>4];
$db = effectFixture([$stacked]);
$db->put('initiative:scene-one', [...$clock, 'combatId'=>'combat-audit-old', 'round'=>4, 'turnSerial'=>15]);
visitEffectCombatTurn($db, [...$clock, 'active'=>false, 'combatId'=>'combat-audit-old', 'round'=>4, 'turnSerial'=>15]);
$advance = runCommand($db, 'mechanics.command', ['command'=>'scene.advance',
    'arguments'=>['sceneId'=>'scene-one', 'requestId'=>'clock-probe-manual-phase-0001']], true, 'account-gm');
requireTactical($advance->status === 200 && $db->payload($targetKey)['hp'] === 33
    && $db->payload('activity')['damageOverTime'][0]['remainingTurns'] === 1,
    'A parked stack consumes exactly one tick on free-mode advance.');
visitEffectCombatTurn($db, [...$clock, 'combatId'=>'combat-audit-new', 'turnSerial'=>16]);
visitEffectCombatTurn($db, [...$clock, 'combatId'=>'combat-audit-new', 'currentIndex'=>1, 'turnSerial'=>17]);
requireTactical($db->payload($targetKey)['hp'] === 26 && $db->payload('activity')['damageOverTime'] === [],
    'A renewed combat ticks the preserved poison without comparing its rounds to the prior combat.');

$db = fixture();
$clock = [...$clock, 'combatId'=>'combat-enhancement-navigation'];
$db->put('initiative:scene-one', $clock);
$activity = $db->payload('activity');
$source = $db->payload('token:scene-one:token-player');
$target = $db->payload($targetKey);
$statBuff = ['id'=>'clock-probe-buff', 'name'=>'Buff de durée',
    'attackEnhancement'=>['mode'=>'stats', 'turns'=>3, 'bonuses'=>['force'=>10]]];
$nextTurn = ['id'=>'clock-probe-echo', 'name'=>'Buff du prochain tour',
    'attackEnhancement'=>['mode'=>'echo', 'turns'=>1, 'formula'=>'5', 'allyFormula'=>'5', 'damageType'=>'ignore',
        'expiry'=>'after-next-turn']];
applicationActivateAttackEnhancement($activity, $statBuff, $source, 'scene-one', $clock, 'clock-duration', $target);
applicationActivateAttackEnhancement($activity, $nextTurn, $source, 'scene-one', $clock, 'clock-until-turn-end', $target);
$db->put('activity', $activity);
visitEffectCombatTurn($db, [...$clock, 'currentIndex'=>1, 'turnSerial'=>2]);
$effects = $db->payload('activity')['attackEnhancements'];
requireTactical($effects[0]['remainingTurns'] === 2 && $effects[1]['hasStarted'] === true,
    'Both buffs use the first real start of the target turn.');
visitEffectCombatTurn($db, [...$clock, 'turnSerial'=>3, 'rewoundTurnSerial'=>3]);
requireTactical($db->payload('activity')['attackEnhancements'] === $effects,
    'Going backward from the target does not wear down a buff or end its active turn.');
visitEffectCombatTurn($db, [...$clock, 'currentIndex'=>1, 'turnSerial'=>4]);
requireTactical($db->payload('activity')['attackEnhancements'] === $effects,
    'Re-entering the same target turn does not count for either buff.');
$postponed = [...$clock, 'order'=>['token-player','token-monster-two','token-monster'],
    'roundBaseOrder'=>$baseOrder, 'delayedTurnIds'=>['token-monster'], 'currentIndex'=>1, 'turnSerial'=>5];
visitEffectCombatTurn($db, $postponed);
requireTactical($db->payload('activity')['attackEnhancements'] === $effects, 'Postponing the target does not end its turn.');
visitEffectCombatTurn($db, [...$postponed, 'currentIndex'=>2, 'turnSerial'=>6]);
requireTactical($db->payload('activity')['attackEnhancements'] === $effects, 'Resuming the delayed turn does not spend another buff turn.');
visitEffectCombatTurn($db, [...$clock, 'round'=>2, 'turnSerial'=>7]);
$effects = $db->payload('activity')['attackEnhancements'];
requireTactical(array_column($effects, 'id') === ['clock-duration'] && $effects[0]['remainingTurns'] === 2,
    'The echo expires only when the delayed turn actually ends.');
visitEffectCombatTurn($db, [...$clock, 'round'=>2, 'currentIndex'=>1, 'turnSerial'=>8]);
requireTactical($db->payload('activity')['attackEnhancements'][0]['remainingTurns'] === 1,
    'The ordinary buff loses its next turn in the next round.');

$timedRule = ['id'=>'clock-probe-stun', 'name'=>'Étourdissement de recette', 'effect'=>'movement', 'formula'=>'0',
    'castingStatId'=>'', 'rangeCells'=>4, 'cooldownRounds'=>0,
    'conditionRider'=>['version'=>1, 'label'=>'Étourdi', 'turns'=>3, 'resistance'=>false,
        'blocksActions'=>true, 'blocksMovement'=>true]];
foreach ([true, false] as $inCombat) {
    $db = fixture();
    $clock = [...$clock, 'combatId'=>'combat-audit-condition', 'active'=>$inCombat];
    $db->put('initiative:scene-one', $clock);
    $db->put($sheetKey, [...$db->payload($sheetKey), 'abilities'=>[$timedRule]]);
    $cast = runCommand($db, 'ability.use', ['sceneId'=>'scene-one', 'sourceTokenId'=>'token-player', 'targetTokenId'=>'token-monster',
        'abilityId'=>$timedRule['id'], 'requestId'=>'clock-probe-condition-0001']);
    requireTactical($cast->status === 200 && in_array('Étourdi', $db->payload($targetKey)['conditions'], true),
        'The native skill adds its timed stun.');
    if (!$inCombat) {
        foreach ([2,1,0] as $remaining) {
            $advance = runCommand($db, 'mechanics.command', ['command'=>'scene.advance',
                'arguments'=>['sceneId'=>'scene-one', 'requestId'=>'clock-probe-stun-phase-000'.$remaining]], true, 'account-gm');
            requireTactical($advance->status === 200 && ($db->payload('devices')['timedConditions'][0]['remainingTurns'] ?? 0) === $remaining,
                'A free-mode turn counts exactly once for a timed condition.');
        }
    } else {
        foreach ([
            [['currentIndex'=>1, 'turnSerial'=>2], 3],
            [['currentIndex'=>2, 'turnSerial'=>3], 2],
            [['currentIndex'=>1, 'turnSerial'=>4, 'rewoundTurnSerial'=>4], 2],
            [['currentIndex'=>0, 'turnSerial'=>5, 'rewoundTurnSerial'=>5], 2],
            [['currentIndex'=>1, 'turnSerial'=>6], 2],
            [['currentIndex'=>2, 'turnSerial'=>7], 2],
            [['currentIndex'=>0, 'turnSerial'=>8, 'round'=>2], 2],
            [['currentIndex'=>1, 'turnSerial'=>9, 'round'=>2], 2],
            [['currentIndex'=>2, 'turnSerial'=>10, 'round'=>2], 1],
            [['currentIndex'=>0, 'turnSerial'=>11, 'round'=>3], 1],
            [['currentIndex'=>1, 'turnSerial'=>12, 'round'=>3], 1],
            [['currentIndex'=>2, 'turnSerial'=>13, 'round'=>3], 0],
        ] as [$fields, $remaining]) {
            visitEffectCombatTurn($db, [...$clock, ...$fields]);
            requireTactical(($db->payload('devices')['timedConditions'][0]['remainingTurns'] ?? 0) === $remaining
                && in_array('Étourdi', $db->payload($targetKey)['conditions'] ?? [], true) === ($remaining > 0),
                'Only a real target departure wears down the stun at clock ' . $fields['turnSerial']);
        }
    }
    requireTactical(!in_array('Étourdi', $db->payload($targetKey)['conditions'] ?? [], true),
        'Expiration of the final tick removes the condition badge.');
}

$db = fixture();
$clock = ['active'=>true, 'turnsStarted'=>true, 'combatId'=>'combat-audit-channel', 'round'=>1, 'turnSerial'=>1,
    'order'=>$baseOrder, 'currentIndex'=>0];
$db->put('initiative:scene-one', $clock);
$sheet = $db->payload($sheetKey);
$sheet['resources']['mana'] = $sheet['resources']['maxMana'] = 100;
$sheet['abilities'] = [['id'=>'clock-probe-dome', 'name'=>'Dôme de recette', 'effect'=>'device', 'formula'=>'0', 'castingStatId'=>'',
    'manaCost'=>0, 'fatigueCost'=>0, 'device'=>dsDefinition(['kind'=>'dome', 'channelStartTurn'=>2, 'channelManaCost'=>5, 'penaltyIncrement'=>0])]];
$db->put($sheetKey, $sheet);
$launched = runCommand($db, 'mechanics.command', ['command'=>'device.deploy', 'arguments'=>[
    'sceneId'=>'scene-one', 'abilityId'=>'clock-probe-dome', 'characterId'=>'character-player', 'sourceTokenId'=>'token-player',
    'requestId'=>'clock-probe-channel-0001',
]]);
requireTactical($launched->status === 200, 'The player really deploys the dome through the tactical command.');
$records = applicationDomainRecords($db);
$pending = [];
$state = onlineDeviceSelectScene(onlineDeviceState($db, $records, $pending), 'scene-one');
$identity = ['id'=>'account-player', 'display_name'=>'Joueur', 'effective_mode'=>'player', 'permanent_role'=>'player'];
$context = onlineDeviceContext($db, $records, $pending, $identity);
$rollCount = 0;
$context['roll'] = static function () use (&$rollCount): array {
    $rollCount++;
    return ['success'=>true, 'pendingGmApproval'=>false];
};
$dome = $state['devices']['devices'][0];
foreach (['explosive','beam'] as $kind) {
    $state['devices']['devices'][] = [...$dome, 'id'=>'device-clock-'.$kind, 'elapsedTurns'=>0, 'elapsedTicks'=>0,
        'definition'=>dsDefinition(['kind'=>$kind, 'delayTurns'=>60]), 'remainingTurns'=>60, 'waitingForConsent'=>false,
        'itemId'=>'clock-fixture-item-'.$kind];
}
$advanceClock = static function (array $state, array $next) use ($context): array {
    $previous = $state['initiative'];
    $state['initiative'] = $next;
    return applicationAdvanceDevices($state, $previous, $context)['state'];
};
$state = $advanceClock($state, [...$clock, 'currentIndex'=>1, 'turnSerial'=>2]);
$before = array_column($state['devices']['devices'], null, 'id');
$state = $advanceClock($state, [...$clock, 'turnSerial'=>3, 'rewoundTurnSerial'=>3]);
requireTactical(array_column($state['devices']['devices'], null, 'id') === $before && $rollCount === 0
    && unitById($state, 'character-player', false)['resources']['mana'] === 100,
    'Going backward neither pays a dome channel nor moves the fuse or beam preparation.');
$state = $advanceClock($state, [...$clock, 'currentIndex'=>1, 'turnSerial'=>4]);
requireTactical(array_column($state['devices']['devices'], null, 'id') === $before && $rollCount === 0,
    'Suivant after Précédent does not reduce the fuse or move the beam preparation.');
$state = $advanceClock($state, [...$clock, 'currentIndex'=>2, 'turnSerial'=>5]);
$secondRound = [...$clock, 'turnSerial'=>6, 'round'=>2];
$state = $advanceClock($state, $secondRound);
$devicesAfterRound = array_column($state['devices']['devices'], null, 'id');
foreach (['explosive', 'beam'] as $kind) {
    $device = $devicesAfterRound['device-clock-'.$kind];
    requireTactical($device['elapsedTurns'] === 1 && $device['elapsedTicks'] === 0,
        'Only the three genuine turn entries make one full cycle for '.$kind.'.');
}
requireTactical($state['devices']['devices'][0]['channelCount'] === 1 && $rollCount === 1
    && (float) unitById($state, 'character-player', false)['resources']['mana'] === 95.0,
    'The new genuine turn pays for exactly one dome roll.');
$state = $advanceClock($state, [...$clock, 'currentIndex'=>2, 'turnSerial'=>7, 'rewoundTurnSerial'=>7]);
$state = $advanceClock($state, [...$secondRound, 'turnSerial'=>8]);
requireTactical($state['devices']['devices'][0]['channelCount'] === 1 && $rollCount === 1
    && (float) unitById($state, 'character-player', false)['resources']['mana'] === 95.0,
    'Replaying the same round cannot pay for or roll a second channel.');
requireTactical(array_column($state['devices']['devices'], null, 'id') === $devicesAfterRound,
    'A rewind across the round boundary and its replay do not tick either timer.');

$clock = [...$clock, 'combatId'=>'combat-audit-escape', 'order'=>['token-monster','token-player','token-monster-two'],
    'currentIndex'=>1, 'turnSerial'=>10];
$state['initiative'] = $clock;
$state['devices']['devices'] = [];
$state['devices']['timedConditions'][] = ['id'=>'clock-escape-test', 'sceneId'=>'scene-one', 'targetTokenId'=>'token-player',
    'label'=>'Entravé', 'expiry'=>'end-next-turn', 'createdTurnSerial'=>0, 'startedTargetRound'=>1, 'hasStarted'=>true,
    'escapeWindowClosed'=>false, 'remainingTurns'=>2, 'initialTurns'=>2, 'clockCombatId'=>'combat-audit-escape',
    'lastTurnSerial'=>0, 'escape'=>['statId'=>'character-stat-dexterity', 'penalties'=>[0,5], 'atTurnStart'=>true]];
$context['roll'] = static function () use (&$rollCount): array {
    $rollCount++;
    return ['success'=>false, 'pendingGmApproval'=>false];
};
$rollCount = 0;
$actor = ['id'=>'account-player', 'role'=>'player'];
$args = ['conditionId'=>'clock-escape-test', 'requestId'=>'clock-escape-test-0001'];
$first = applicationApplyDeviceCommand($state, $actor, 'condition.escape', $args, $context);
requireTactical($first['result']['escaped'] === false && $rollCount === 1, 'The first actual escape test fails once.');
$state = $advanceClock($first['state'], [...$clock, 'currentIndex'=>0, 'turnSerial'=>11, 'rewoundTurnSerial'=>11]);
$state = $advanceClock($state, [...$clock, 'turnSerial'=>12]);
try {
    applicationApplyDeviceCommand($state, $actor, 'condition.escape', [...$args, 'requestId'=>'clock-escape-test-0002'], $context);
    throw new RuntimeException('A replayed turn accepted a second escape.');
} catch (RuntimeException $error) {
    requireTactical($error->getCode() === 409 && str_contains($error->getMessage(), 'déjà été faite') && $rollCount === 1,
        'A failed escape remains spent when the GM revisits the same turn.');
}
echo "Ongoing effects, residual damage and roll revelation contracts passed.\n";
