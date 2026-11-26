<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);require __DIR__.'/tactical-special-effects.php';$specialChecks=0;
foreach(['critical-success'=>'divine','success'=>'verdant-metal','critical-failure'=>'infernal','failure'=>null] as $code=>$theme){
 $db=specialFixture();$c=$db->payload('character:character-player');$c['abilities']=[['id'=>'message-test','name'=>'Appel fictif','effect'=>'movement','formula'=>'0','castingStatId'=>'','manualD100'=>true,'fatigueCost'=>5,'manaCost'=>0,'cooldownRounds'=>0,'restRecharge'=>'short','usesPerRest'=>2,'outcomeMessages'=>['version'=>1,'entries'=>['success'=>['kind'=>'message','text'=>'Réussite de recette','theme'=>'verdant-metal','durationMs'=>5000],'critical-success'=>['kind'=>'message','text'=>'Autre réussite critique','theme'=>'divine','durationMs'=>5000],'critical-failure'=>['kind'=>'message','text'=>'Autre échec critique','theme'=>'infernal','durationMs'=>5000]]]]];$db->put('character:character-player',$c);
 $r=specialUse($db,'message-test','token-player',['modifier'=>99,'modifierMode'=>'result','rollMode'=>'advantage']);specialCheck($r->status===202&&!empty($r->body['pendingValidation']),'Native d100 pending for '.$code.': '.json_encode($r->body));$entry=$db->payload('activity')['pendingAbilityCasts'][0];specialCheck($entry['cast']['roll']['formula']==='1d100'&&$entry['cast']['outcome']['code']==='pending','Unmodified raw d100, no automatic verdict');specialCheck($db->payload('character:character-player')['fatigue']['current']===5,'Attempt costs five fatigue');specialCheck(empty($db->payload('activity')['abilityCueEvents']),'No message before MJ arbitration');
 $args=['requestId'=>'decision-'.randomToken(18),'validationId'=>$entry['id'],'decision'=>'approve','confirmed'=>true,'outcomeCode'=>$code];$r=runCommand($db,'ability.resolve',$args,false);specialCheck($r->status===403,'Player cannot arbitrate');$r=runCommand($db,'ability.resolve',$args,true);specialCheck($r->status===200,'Native decision: '.json_encode($r->body));specialCheck($r->body['cast']['outcome']['raw']===$entry['cast']['outcome']['raw']&&$r->body['cast']['outcome']['code']===$code,'Raw dice conserved with MJ outcome');$events=$db->payload('activity')['abilityCueEvents']??[];specialCheck(count($events)===($theme?1:0),'Selected message only');if($theme){specialCheck($events[0]['completionCue']['visual']['theme']===$theme&&$events[0]['expiresAt']-$events[0]['createdAt']===5000,'Configured theme and five-second lifetime');}
 specialCheck($db->payload('activity')['actionTimers'][0]['restUseCount']===1,'Every adjudicated outcome consumes one use');$again=runCommand($db,'ability.resolve',$args,true);specialCheck($again->status===200&&!empty($again->body['deduplicated']),'Receipt replay idempotent');specialCheck($db->payload('character:character-player')['fatigue']['current']===5,'No duplicate cost');
 $r=specialUse($db,'message-test','token-player');$entry=$db->payload('activity')['pendingAbilityCasts'][0];$r=runCommand($db,'ability.resolve',['requestId'=>'second-'.randomToken(18),'validationId'=>$entry['id'],'decision'=>'approve','confirmed'=>true,'outcomeCode'=>'failure'],true);specialCheck($r->status===200&&$db->payload('activity')['actionTimers'][0]['restUseCount']===2,'Second failure also consumes use');specialCheck(specialUse($db,'message-test','token-player')->status===409,'Third native attempt refused');
}
$prayerId = 'ability-2cd21fa7-4e03-4167-b6e0-ccd87c3f4f43';
$prayer = ['id' => $prayerId, 'name' => 'Prière de Varska', 'effect' => 'movement', 'formula' => '0',
    'castingStatId' => '', 'manualD100' => true, 'fatigueCost' => 5, 'manaCost' => 0,
    'cooldownRounds' => 0, 'restRecharge' => 'short', 'usesPerRest' => 2,
    'outcomeMessages' => ['version' => 1, 'entries' => ['success' =>
        ['kind' => 'message', 'text' => 'Effet propre à la table', 'theme' => 'verdant-metal', 'durationMs' => 7500]]]];
$setupPrayer = static function (array $ability) : MemoryConnection {
    $db = specialFixture();
    $character = $db->payload('character:character-player');
    $character['abilities'] = [$ability];
    $db->put('character:character-player', $character);
    return $db;
};
foreach (['fantastic' => 'prayer-divine', 'good' => 'neutral', 'bad' => 'prayer-dread'] as $choice => $theme) {
    $ability = $prayer;
    if ($choice === 'good') {
        unset($ability['manualD100']);
        $ability['id'] = 'test-explicit-visual-d75';
        $ability['visualD75'] = true;
        $ability['outcomeMessages']['entries']['success']['theme'] = 'neutral';
    }
    $db = $setupPrayer($ability);
    $arguments = ['requestId' => 'prayer-test-' . randomToken(18), 'modifier'=>99, 'modifierMode'=>'result',
        'rollMode'=>'advantage', 'visualChoice'=>'bad', 'result'=>999];
    $pending = specialUse($db, $ability['id'], 'token-player', $arguments);
    specialCheck($pending->status === 202 && $pending->body['pendingValidation'] === true, "Prayer requires only a visual choice: " . json_encode($pending->body));
    $cast = $pending->body['cast'];
    $roll = $cast['roll'];
    specialCheck($cast['visualD75'] === true && $cast['outcome'] === null && $cast['success'] === null
        && !array_key_exists('manualOutcome', $cast) && !array_key_exists('outcome', $roll)
        && !array_key_exists('rawD100', $roll) && !array_key_exists('mapEvent', $roll)
        && $roll['label'] === $ability['name'] && $roll['formula'] === '1d75' && $roll['total'] >= 1 && $roll['total'] <= 75
        && $roll['sourceTokenId'] === 'token-player' && $roll['sourceSceneId'] === 'scene-one',
        'The authoritative raw roll has no grade, modifier, or standard dice overlay');
    $activity = $db->payload('activity');
    $entry = $activity['pendingAbilityCasts'][0];
    $event = $activity['abilityCueEvents'][0];
    specialCheck(count($activity['rolls']) === 1 && $activity['rolls'][0] === $roll
        && $event['id'] === $cast['completionCueEventId'] && $event['visibility'] === 'public'
        && $event['sourceTokenId'] === 'token-player' && $event['layerId'] === 'ground'
        && $event['completionCue']['visual']['theme'] === 'prayer-parchment'
        && $event['completionCue']['visual']['text'] === (string) $roll['total'], 'The journal and public parchment are available before the GM decision');
    $fullState = domainsToApplicationState($db->domains, $db->revision);
    $player = ['id'=>'account-player','effective_mode'=>'player','permanent_role'=>'player'];
    $playerView = publicPlayerState($fullState, $player, []);
    $streamView = publicPlayerState($fullState, $player, [], true);
    specialCheck(($playerView['pendingAbilityCasts'][0]['id'] ?? '') === $entry['id'] && $streamView['pendingAbilityCasts'] === []
        && in_array($event['id'], array_column($playerView['abilityCueEvents'], 'id'), true)
        && in_array($event['id'], array_column($streamView['abilityCueEvents'], 'id'), true),
        'Player and Stream receive the synchronized result; only its player sees the pending control');
    $retry = specialUse($db, $ability['id'], 'token-player', $arguments);
    specialCheck($retry->status === 202 && $retry->body['deduplicated'] === true && $retry->body['cast'] === $cast
        && count($db->payload('activity')['abilityCueEvents']) === 1, 'Retry keeps the exact die and parchment event');
    $decision = ['requestId' => 'prayer-decision-' . randomToken(16), 'validationId' => $entry['id'], 'decision' => 'approve', 'confirmed' => true];
    specialCheck(runCommand($db, 'ability.resolve', [...$decision, 'visualChoice' => $choice])->status === 403, 'Player cannot choose the visual');
    specialCheck(runCommand($db, 'ability.resolve', [...$decision, 'outcomeCode' => 'critical-success'], true)->status === 400
        && runCommand($db, 'ability.resolve', [...$decision, 'visualChoice' => 'invalid'], true)->status === 400, 'Legacy verdicts and unknown visual choices cannot settle the prayer');
    $resolved = runCommand($db, 'ability.resolve', [...$decision, 'visualChoice' => $choice], true);
    specialCheck($resolved->status === 200 && $resolved->body['castSucceeded'] === null && $resolved->body['cast']['outcome'] === null
        && $resolved->body['cast']['visualChoice'] === $choice && $resolved->body['cast']['roll'] === $roll
        && $resolved->body['appliedDelta'] === 0, 'The selected animation cannot recalculate the die or apply a game outcome: ' . json_encode($resolved->body));
    $activity = $db->payload('activity');
    $final = $activity['abilityCueEvents'][1];
    specialCheck(count($activity['abilityCueEvents']) === 2 && $final['id'] !== $event['id']
        && $final['completionCue']['visual']['theme'] === $theme
        && $final['completionCue']['visual']['afterPrayerRollId'] === $event['id']
        && $final['completionCue']['visual'] === $resolved->body['cast']['completionCue']['visual'],
        'One distinct final effect plays after its parchment');
    $playerView = publicPlayerState(domainsToApplicationState($db->domains, $db->revision), $player, []);
    $publicFinal = array_column($playerView['abilityCueEvents'], null, 'id')[$final['id']]['completionCue']['visual'] ?? [];
    specialCheck(($publicFinal['afterPrayerRollId'] ?? '') === $event['id'], 'The public visual keeps the parchment ordering reference');
    if ($choice === 'good') specialCheck($final['completionCue']['visual']['text'] === 'Effet propre à la table'
        && $final['expiresAt'] - $final['createdAt'] === 7500, 'A table’s existing custom message is preserved');
    specialCheck(count($activity['rolls']) === 1 && $activity['pendingAbilityCasts'] === [] && $activity['actionTimers'][0]['restUseCount'] === 1
        && $db->payload('character:character-player')['fatigue']['current'] === 5
        && $db->payload('character:character-player')['resources']['hp'] === 100, 'Finalizing consumes one use, keeps the fatigue paid once and changes no HP');
    $again = runCommand($db, 'ability.resolve', [...$decision, 'visualChoice' => $choice], true);
    specialCheck($again->status === 200 && $again->body['deduplicated'] === true, 'Visual approval retry deduplicates');
    $different = $choice === 'bad' ? 'good' : 'bad';
    specialCheck(runCommand($db, 'ability.resolve', [...$decision, 'visualChoice' => $different], true)->status === 409,
        'One decision identifier cannot select a different visual');
    $second = specialUse($db, $ability['id'], 'token-player');
    $reject = runCommand($db, 'ability.resolve', ['requestId'=>'prayer-reject-' . randomToken(16),
        'validationId'=>$second->body['validation']['id'], 'decision'=>'reject'], true);
    specialCheck($reject->status === 200 && $reject->body['validationRejected'] && $reject->body['castSucceeded'] === null
        && $reject->body['refundedCosts']['fatigue'] == 5
        && !isset($reject->body['cast']['completionCueEventId']) && count($db->payload('activity')['abilityCueEvents']) === 3
        && $db->payload('activity')['actionTimers'][0]['restUseCount'] === 1
        && $db->payload('character:character-player')['fatigue']['current'] === 5, 'Cancellation leaves the rolled number, plays no final cue, and refunds');
}
$historical = $prayer['outcomeMessages'];
$historical['entries']['success']['text'] = 'Magie sauvage';
$recovered = preserveApplicationAbilityRows([array_diff_key($prayer, array_flip(['manualD100', 'outcomeMessages']))],
    [[...$prayer, 'outcomeMessages' => $historical]])[0];
specialCheck(($recovered['visualD75'] ?? false) === true && $recovered['outcomeMessages'] === $historical, 'An editing client omitting the newer rules keeps the prayer and its configured messages');
$cue = applicationOutcomeCompletionCue(['outcomeMessages' => $historical], ['visualD75' => true, 'visualChoice' => 'good']);
specialCheck($cue['visual']['theme'] === 'prayer-serene' && $cue['visual']['durationMs'] === 7500, 'The historical visual gains its dedicated aura while keeping the configured time');
$db = $setupPrayer($prayer);
$source = $db->payload('token:scene-one:token-player');
$source['hidden'] = true;
$db->put('token:scene-one:token-player', $source);
$secret = runCommand($db, 'ability.use', ['requestId'=>'private-prayer-' . randomToken(16),
    'sceneId'=>'scene-one', 'sourceTokenId'=>'token-player', 'abilityId'=>$prayerId], true);
$privateEvent = $db->payload('activity')['abilityCueEvents'][0];
$fullState = domainsToApplicationState($db->domains, $db->revision);
specialCheck($secret->status === 202 && $secret->body['cast']['roll']['visibility'] === 'gm'
    && $privateEvent['visibility'] === 'gm' && publicPlayerState($fullState, $player, [])['abilityCueEvents'] === []
    && publicPlayerState($fullState, $player, [], true)['abilityCueEvents'] === [], 'A hidden GM prayer is not exposed to Player or Stream');
$db = $setupPrayer($prayer);
$launchArgs = ['requestId'=>'prayer-undo-launch-' . randomToken(16)];
$played = specialUse($db, $prayerId, 'token-player', $launchArgs);
$approveArgs = ['requestId'=>'prayer-undo-decision-' . randomToken(16),
    'validationId'=>$played->body['validation']['id'], 'decision'=>'approve', 'confirmed'=>true, 'visualChoice'=>'good'];
$resolved = runCommand($db, 'ability.resolve', $approveArgs, true);
$action = $db->payload('activity')['combatActions'][0];
$undo = runCommand($db, 'action.undo', ['requestId'=>'prayer-undo-' . randomToken(16),
    'actionId'=>$action['actionIds'][0]], true);
$retry = specialUse($db, $prayerId, 'token-player', $launchArgs);
$retryApproval = runCommand($db, 'ability.resolve', $approveArgs, true);
specialCheck($resolved->status === 200 && $undo->status === 200
    && $db->payload('character:character-player')['fatigue']['current'] === 0
    && $db->payload('activity')['abilityCueEvents'] === [] && $db->payload('activity')['actionTimers'] === [],
    'Undo clears the completed prayer’s visual events and restores its costs and uses');
foreach ([$retry, $retryApproval] as $undone) {
    specialCheck($undone->status === 200 && $undone->body['deduplicated'] === true && $undone->body['actionUndone'] === true
        && $undone->body['castSucceeded'] === null && $undone->body['cast']['roll'] === $played->body['cast']['roll']
        && !isset($undone->body['cast']['completionCue']) && $undone->body['validation']['status'] === 'rejected',
        'An undone prayer receipt preserves the raw roll and cannot start either animation');
}
$db = $setupPrayer([...$prayer, 'cooldownRounds' => 5]);
$approvePrayer = static function (MemoryConnection $db, TestResponse $cast) : TestResponse {
    return runCommand($db, 'ability.resolve', ['requestId'=>'prayer-recharge-' . randomToken(16),
        'validationId'=>$cast->body['validation']['id'], 'decision'=>'approve', 'confirmed'=>true, 'visualChoice'=>'bad'], true);
};
$resolved = $approvePrayer($db, specialUse($db, $prayerId, 'token-player'));
specialCheck($resolved->status === 200 && $resolved->body['cast']['remainingRounds'] === 5
    && specialUse($db, $prayerId, 'token-player')->status === 409, 'The existing five-round cooldown starts after the visual choice, including Mauvais');
$initiative = $db->payload('initiative:scene-one');
$db->put('initiative:scene-one', [...$initiative, 'active'=>false]);
$resolved = $approvePrayer($db, specialUse($db, $prayerId, 'token-player'));
specialCheck($resolved->status === 200 && $resolved->body['cast']['remainingRounds'] === 0
    && $resolved->body['cast']['restUseCount'] === 2 && specialUse($db, $prayerId, 'token-player')->status === 409,
    'Outside combat the round cooldown does not apply, but the per-rest allowance still does');
$v=applicationOutcomeMessages(['version'=>1,'entries'=>['success'=>['kind'=>'message','text'=>'<script>Texte libre</script>','theme'=>'neutral','durationMs'=>7000]]]);specialCheck($v['entries']['success']['durationMs']===7000,'Other skills may configure text and duration');
fwrite(STDOUT,json_encode(['ok'=>true,'checks'=>$specialChecks],JSON_UNESCAPED_UNICODE)."\n");
