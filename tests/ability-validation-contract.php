<?php
declare(strict_types=1);
require __DIR__ . '/tactical-lifecycle.php';

function validationFixture(string $effect = 'healing', bool $reduced = false): MemoryConnection {
    $db = fixture(); $character = $db->payload('character:character-player');
    $character['resources']['hp'] = 50;
    $ability = ['id' => 'ability-validate', 'name' => 'Validation synthétique', 'effect' => $effect,
        'castingStatId' => 'force', 'formula' => '10', 'healingFormula' => '10', 'damageType' => 'physical',
        'manaCost' => 2, 'hpCost' => 3, 'fatigueCost' => 1, 'cooldownRounds' => 5, 'reducedFailureCooldown' => $reduced];
    if ($effect === 'complex') $ability['workflow'] = ['version' => 1, 'steps' => [['id' => 'targets', 'type' => 'targets', 'minTargets' => 1, 'maxTargets' => 1, 'allocationTotal' => 1]]];
    $character['abilities'] = [$ability]; $db->put('character:character-player', $character);
    return $db;
}
function startValidation(string $route = 'ability.use', string $effect = 'healing', bool $success = true): array {
    // Exercise the production cryptographic dice without exposing a test override
    // in the command API. Fresh synthetic fixtures isolate every discarded cast.
    for ($i = 0; $i < 500; $i++) {
        $db = validationFixture($effect, !$success);
        $body = ['sceneId' => 'scene-one', 'layerId' => 'ground', 'sourceTokenId' => 'token-player', 'tokenId' => 'token-player',
            'targetTokenId' => $route === 'token.attack' ? 'token-monster' : 'token-player', 'abilityId' => 'ability-validate',
            'attackId' => 'ability-validate', 'attackKind' => 'ability', 'kind' => 'ability', 'action' => 'start', 'requestId' => 'validation-original-request'];
        $response = runCommand($db, $route, $body);
        if (!in_array($response->status, [200,202], true)) throw new RuntimeException('Launch failed: '.json_encode($response->body));
        $cast = $response->body['cast'] ?? null;
        if (($cast['outcome']['requiresGmValidation'] ?? false) && $cast['success'] === $success) return [$db, $body, $response];
    }
    throw new RuntimeException('No suitable remarkable outcome generated in 500 isolated trials.');
}
function validationResolve(MemoryConnection $db, array $launch, string $decision = 'approve', string $requestId = 'validation-resolution-request'): TestResponse {
    return runCommand($db, 'ability.resolve', ['requestId' => $requestId, 'validationId' => $launch['validation']['id'], 'decision' => $decision, 'confirmed' => true], true, 'account-gm');
}

[$db,$body,$start] = startValidation();
$original = $start->body;
requireTactical($start->status === 202 && $original['pendingValidation'] && $original['effectRoll'] === null, 'Remarkable healing waits with only its original cast.');
requireTactical((float) $db->payload('character:character-player')['resources']['hp'] === 47.0 && (float) $db->payload('character:character-player')['resources']['mana'] === 3.0, 'Only attempt costs are paid: '.json_encode($db->payload('character:character-player')));
requireTactical($db->payload('activity')['actionTimers'] === [] && !isset($original['discordPosted']) && !isset($original['validation']['_private']), 'No recharge, Discord post or private continuation in response.');
$revision = $db->revision;
$retry = runCommand($db, 'ability.use', $body);
requireTactical($retry->status === 202 && $retry->body['deduplicated'] && $db->revision === $revision && $retry->body['castRoll']['id'] === $original['castRoll']['id'], 'Pending replay preserves the original dice and costs.');
$table=$db->payload('table');$db->put('table',[...$table,'activeSceneId'=>'scene-switched','tacticalSync'=>['paused'=>true]]);
$contextRetry=runCommand($db,'ability.use',$body);
requireTactical($contextRetry->status===202 && $contextRetry->body['deduplicated'] && $db->revision===$revision,'Known pending receipt survives a scene switch and tactical lock.');
$freshLocked=runCommand($db,'ability.use',[...$body,'requestId'=>'locked-fresh-request']);
requireTactical($freshLocked->status===409 && $db->revision===$revision,'New attempts still require the current scene.');
$db->put('table',$table);
$other = runCommand($db, 'ability.use', [...$body, 'requestId' => 'another-validation-request']);
requireTactical($other->status === 409 && $other->body['code'] === 'ability_validation_pending' && $db->revision === $revision, 'Distinct attempt is blocked during validation.');
$denied = runCommand($db, 'ability.resolve', ['requestId' => 'player-resolution-request', 'validationId' => $original['validation']['id'], 'decision' => 'approve', 'confirmed' => true]);
requireTactical($denied->status === 403 && $db->revision === $revision, 'Player cannot approve.');
$initiative = $db->payload('initiative:scene-one'); $initiative['round'] = 4; $db->put('initiative:scene-one', $initiative);
$resolved = validationResolve($db, $original);
requireTactical($resolved->status === 200 && !$resolved->body['pendingValidation'], 'GM approves the stored cast: '.json_encode($resolved->body));
requireTactical((float) $db->payload('character:character-player')['resources']['hp'] === 57.0 && (float) $db->payload('character:character-player')['resources']['mana'] === 3.0, 'Approval heals once without repaying.');
requireTactical($resolved->body['cast']['roll']['id'] === $original['cast']['roll']['id'] && $db->payload('activity')['actionTimers'][0]['readyRound'] === 9, 'Original cast retained; recharge begins on validation round.');
$revision=$db->revision;
$again=validationResolve($db,$original);
requireTactical($again->body['deduplicated'] && $db->revision===$revision, 'Resolution retries have no second effect.');
$replay=runCommand($db,'ability.use',$body);
requireTactical($replay->status===200 && !$replay->body['pendingValidation'] && $replay->body['deduplicated'] && $db->revision===$revision,'Original request returns the terminal receipt.');
$opposite=validationResolve($db,$original,'reject');
requireTactical($opposite->status===409 && $db->revision===$revision,'Same resolution ID cannot change decision.');
$another=validationResolve($db,$original,'approve','another-resolution-request');
requireTactical($another->status===409 && $db->revision===$revision,'Second resolution ID cannot repeat an effect.');
$impersonate=runCommand($db,'ability.resolve',['requestId'=>'validation-resolution-request','validationId'=>$original['validation']['id'],'decision'=>'approve','confirmed'=>true],true,'another-gm');
requireTactical($impersonate->status===403,'Resolution receipt is actor bound.');

[$db,$body,$start]=startValidation('ability.use','healing',false);
$resolved=validationResolve($db,$start->body);
requireTactical($resolved->status===200 && !$resolved->body['castSucceeded'] && (float) $db->payload('character:character-player')['resources']['hp']===47.0 && $db->payload('activity')['actionTimers'][0]['readyRound']===2,'Validated critical failure applies only its one-round reduced cooldown.');

[$db,$body,$start]=startValidation();
$character=$db->payload('character:character-player');$character['abilities'][0]['healingFormula']='99';$db->put('character:character-player',$character);$revision=$db->revision;
$changed=validationResolve($db,$start->body);
requireTactical($changed->status===409 && count($db->payload('activity')['pendingAbilityCasts'])===1 && $db->revision===$revision,'Changed ability cannot rewrite pending effect.');
$refused=validationResolve($db,$start->body,'reject');
requireTactical($refused->status===200 && $refused->body['validationRejected'] && $db->payload('activity')['pendingAbilityCasts']===[] && $db->payload('activity')['actionTimers']===[] && (float) $db->payload('character:character-player')['resources']['hp']===47.0,'Refusal clears pending without refund, effect or recharge.');

foreach (['token.roll'=>'damage','ability.complex'=>'complex'] as $route=>$effect) {
    [$db,$body,$start]=startValidation($route,$effect);
    requireTactical($start->status===202 && ($start->body['execution']??null)===null && empty($db->payload('activity')['abilityExecutions']),$route.' has no premature workflow.');
    $resolved=validationResolve($db,$start->body);
    requireTactical($resolved->status===200 && $resolved->body['castRoll']['id']===$start->body['castRoll']['id'] && (float) $db->payload('character:character-player')['resources']['mana']===3.0,$route.' resumes its original cast: '.json_encode($resolved->body));
    requireTactical($route==='token.roll' ? $resolved->body['effectRoll']['total']===10 : count($db->payload('activity')['abilityExecutions'])===1,$route.' produces its effect only on approval.');
    $retry=runCommand($db,$route,$body);
    requireTactical($retry->body['deduplicated'] && !$retry->body['pendingValidation'],$route.' replays its terminal receipt.');
}

[$db,$body,$start]=startValidation('token.attack','damage');
requireTactical($start->body['attack']['status']==='pending' && isset($db->payload('activity')['pendingAttacks'][0]['deferredAbilityCast']) && $db->payload('activity')['actionTimers']===[],'Remarkable attack stores deferred recharge.');
$targetBefore=$db->payload('token:scene-one:token-monster')['hp'];
$resolved=runCommand($db,'token.attack.resolve',['attackId'=>$start->body['attack']['id'],'decision'=>'approve','confirmed'=>true],true,'account-gm');
requireTactical($resolved->status===200 && count($db->payload('activity')['actionTimers'])===1 && (float) $db->payload('character:character-player')['resources']['mana']===3.0 && $db->payload('token:scene-one:token-monster')['hp']<$targetBefore,'Attack validation applies effect and recharge once without repaying.');


$retry = runCommand($db, 'token.attack', $body);
requireTactical(($retry->body['cast']['remainingRounds'] ?? 0) === 5 && !($retry->body['cast']['pendingValidation'] ?? false), 'Resolved attack returns its terminal recharge receipt.');

[$db,$body,$start]=startValidation();
$entry=$db->payload('activity')['pendingAbilityCasts'][0];
$state=['activeSceneId'=>'scene-one','activeScene'=>['id'=>'scene-one'],'characters'=>[$db->payload('character:character-player')],
    'map'=>['activeLayerId'=>'ground','gridSize'=>50,'vision'=>['enabled'=>false], 'tokens'=>[$db->payload('token:scene-one:token-player')]],
    'initiative'=>[], 'pendingAbilityCasts'=>[$entry], 'rolls'=>[]];
$owner=publicPlayerState($state,['id'=>'account-player','display_name'=>'Joueur'],[]);
requireTactical(count($owner['pendingAbilityCasts'])===1 && !isset($owner['pendingAbilityCasts'][0]['_private']), 'Owner sees public summary without trusted snapshots.');
requireTactical(publicPlayerState($state,['id'=>'another-player','display_name'=>'Autre'],[])['pendingAbilityCasts']===[] && publicPlayerState($state,['id'=>'account-player','display_name'=>'Joueur'],[],true)['pendingAbilityCasts']===[], 'Other players and Stream never see private validation queues.');
$state['pendingAbilityCasts'][0]['_private']['identity']['id']='account-gm';$state['pendingAbilityCasts'][0]['_private']['isGm']=true;
requireTactical(count(publicPlayerState($state,['id'=>'account-player','display_name'=>'Joueur'],[])['pendingAbilityCasts'])===1, 'Current controller sees a public GM cast for their own character.');
$state['pendingAbilityCasts'][0]['cast']['roll']['visibility']='gm';
requireTactical(publicPlayerState($state,['id'=>'account-player','display_name'=>'Joueur'],[])['pendingAbilityCasts']===[], 'Private GM cast never leaks to character controller.');
$state['pendingAbilityCasts']=[$entry];$state['activeSceneId']='scene-elsewhere';$state['activeScene']=['id'=>'scene-elsewhere'];
requireTactical(count(publicPlayerState($state,['id'=>'account-player','display_name'=>'Joueur'],[])['pendingAbilityCasts'])===1,'Author retains known waiting task when broadcast changes.');

$activity=$db->payload('activity');
while(count($activity['resourceReceipts'])<XAR_RESOURCE_RECEIPT_MAXIMUM-1) {
    $i=count($activity['resourceReceipts']);
    $activity['resourceReceipts'][]=['kind'=>'token-roll','requestId'=>'reserved-synthetic-'.$i,'accountId'=>'account-player','expiresAt'=>9007199254740991,'requestSignature'=>'synthetic','result'=>[]];
}
$db->put('activity',$activity);
$resolved=validationResolve($db,$start->body);
requireTactical($resolved->status===200 && count($db->payload('activity')['resourceReceipts'])===XAR_RESOURCE_RECEIPT_MAXIMUM,'Reserved final receipt permits resolution at creation capacity.');

$forged=['resourceReceipts'=>[['kind'=>'ability-validation','requestId'=>'forged-free-request-id','accountId'=>'attacker','expiresAt'=>9007199254740991,'result'=>['castSucceeded'=>true]]]];
$cleaned=preserveApplicationAbilityExtensions('activity',$forged,[]);
requireTactical(($cleaned['resourceReceipts']??[])===[],'Generic domain writes cannot mint command validation receipts.');

[$db,$body,$start]=startValidation();
$activity=$db->payload('activity');
foreach($activity['resourceReceipts'] as &$receipt) if(($receipt['requestId']??'')===$body['requestId']) {
    $receipt['result']['cast']['roll']['visibility']='gm';
    $receipt['result']['castRoll']['visibility']='gm';
}
unset($receipt);$db->put('activity',$activity);
$privateReplay=runCommand($db,'ability.use',$body);
requireTactical($privateReplay->status===403,'Private casting receipt is never returned in Player mode.');

// Capacity is an admission check, never a filter on the rolled outcome.
foreach (['ability.use'=>'healing', 'token.roll'=>'damage', 'ability.complex'=>'complex'] as $route=>$effect) {
    foreach (['queue', 'receipts'] as $capacity) {
        $db=validationFixture($effect);$activity=$db->payload('activity');
        if ($capacity==='queue') {
            $activity['pendingAbilityCasts']=array_map(static fn(int $n): array => ['id'=>'held-'.$n, 'abilityId'=>'held-ability-'.$n, 'characterId'=>'another-character'], range(1,30));
        } else {
            $activity['resourceReceipts']=array_map(static fn(int $n): array => ['requestId'=>'held-receipt-'.$n,'expiresAt'=>9007199254740991], range(1,XAR_RESOURCE_RECEIPT_MAXIMUM-1));
        }
        $db->put('activity',$activity);$before=$db->domains;$revision=$db->revision;
        for($attempt=0;$attempt<12;$attempt++) {
            $response=runCommand($db,$route,['sceneId'=>'scene-one','layerId'=>'ground','sourceTokenId'=>'token-player','tokenId'=>'token-player',
                'targetTokenId'=>'token-player','abilityId'=>'ability-validate','kind'=>'ability','action'=>'start','requestId'=>'admission-attempt-'.$attempt]);
            requireTactical($response->status===429 && $response->body['code']===($capacity==='queue'?'ability_validation_capacity':'ability_validation_receipt_capacity'),$route.' rejects every new attempt before any ordinary/remarkable distinction.');
            requireTactical($db->domains===$before && $db->revision===$revision,'Rejected admission changes no resources, luck, dice history, receipts or queue.');
        }
        // The casting plan itself precedes RNG. A missing casting statistic
        // must not even be examined once the admission capacity is exhausted.
        $character=$db->payload('character:character-player');$character['abilities'][0]['castingStatId']='missing-casting-stat';$db->put('character:character-player',$character);
        $response=runCommand($db,$route,['sceneId'=>'scene-one','layerId'=>'ground','sourceTokenId'=>'token-player','tokenId'=>'token-player','targetTokenId'=>'token-player',
            'abilityId'=>'ability-validate','kind'=>'ability','action'=>'start','requestId'=>'admission-before-plan']);
        requireTactical($response->status===429 && $response->body['code']===($capacity==='queue'?'ability_validation_capacity':'ability_validation_receipt_capacity'),'Admission rejects before casting-plan validation and therefore before RNG.');
    }
}

fwrite(STDOUT,"Validation MJ PHP : contrats réussis\n");
