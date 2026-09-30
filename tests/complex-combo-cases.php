<?php
declare(strict_types=1);

// Run the real dispatcher and cryptographic dice against isolated fixtures.
// No production test flag or real account is involved.
for ($attempt = 0; $attempt < 100; $attempt++) {
    $comboDb = fixture();
    $character = $comboDb->payload('character:character-player');
    $character['stats'] = ['force' => 0, 'agility' => 100];
    $character['abilities'] = [['id' => 'complete-combo', 'name' => 'Combo complet', 'effect' => 'complex', 'formula' => '0',
        'manaCost' => 2, 'fatigueCost' => 5, 'castingStatId' => '', 'workflow' => ['version' => 6, 'steps' => [[
            'id' => 'chain', 'type' => 'attack-chain', 'statId' => 'character-stat-agility', 'resolutionMode' => 'batch',
            'count' => 2, 'penaltyPerSuccess' => 10, 'damageMode' => 'configured',
            'damageComponents' => [['type' => 'ignore', 'formula' => '2d40+12']], 'allowOpposition' => true,
        ]]]]];
    $comboDb->put('character:character-player', $character);
    $target = $comboDb->payload('token:scene-one:token-monster');
    $target['hp'] = $target['maxHp'] = 1000; $target['armor'] = $target['magicArmor'] = 100;
    $target['armorCategory'] = $target['magicArmorCategory'] = 'special';
    $comboDb->put('token:scene-one:token-monster', $target);
    $started = runCommand($comboDb, 'ability.complex', ['action' => 'start', 'sceneId' => 'scene-one', 'layerId' => 'ground',
        'sourceTokenId' => 'token-player', 'targetTokenId' => 'token-monster', 'abilityId' => 'complete-combo', 'requestId' => 'combo-start-request']);
    requireTactical($started->status === 200 && is_array($started->body['execution'] ?? null), 'PHP starts the configured combo.');
    $execution = $started->body['execution'];
    $prepareBody = ['action' => 'command', 'executionId' => $execution['id'], 'expectedRevision' => $execution['revision'],
        'requestId' => 'combo-prepare-request', 'command' => ['action' => 'prepare-chain', 'targetTokenId' => 'token-monster',
            'attackId' => 'complete-combo', 'statId' => 'character-stat-agility', 'opposed' => true]];
    $prepared = runCommand($comboDb, 'ability.complex', $prepareBody);
    requireTactical($prepared->status === 200, 'PHP prepares the combo: ' . $prepared->getMessage());
    $execution = $prepared->body['execution'];
    $gates = $execution['stepStates']['chain']['rolls'];
    if (($execution['stepStates']['chain']['queuedAttackCount'] ?? 0) === 2
        && count(array_filter($gates, static fn(array $gate): bool => ($gate['outcomeDetails']['requiresGmValidation'] ?? false))) === 0) break;
}
requireTactical($attempt < 100, 'An ordinary complete combo was obtained within the isolated bound.');
$replayed = runCommand($comboDb, 'ability.complex', $prepareBody);
requireTactical($replayed->status === 200 && $replayed->body['deduplicated'] && $replayed->body['rolls'] === $prepared->body['rolls'], 'Batch replay retains every original gate.');
$comboAttacks = [];
for ($index = 0; $index < 2; $index++) {
    $attackBody = ['sceneId' => 'scene-one', 'sourceTokenId' => 'token-player', 'targetTokenId' => 'token-monster',
        'attackKind' => 'ability', 'attackId' => 'complete-combo', 'statId' => 'character-stat-agility',
        'complexExecutionId' => $execution['id'], 'opposed' => true, 'requestId' => 'combo-strike-request-' . $index];
    $attack = runCommand($comboDb, 'token.attack', $attackBody);
    requireTactical($attack->status === 200 && $attack->body['attack']['status'] === 'awaiting-opposition', 'PHP queues the authorized strike: ' . $attack->getMessage());
    requireTactical(count($comboDb->payload('activity')['pendingAttacks'] ?? []) === $index + 1, 'The actual dispatcher persists every pending strike.');
    requireTactical($attack->body['attack']['hit']['raw'] === $gates[$index]['total']
        && $attack->body['attack']['hit']['rollId'] === $gates[$index]['rollId'], 'PHP reuses the gate as ATK, with no new Force or Agility roll.');
    $comboAttacks[] = $attack->body['attack'];
    $confirmed = runCommand($comboDb, 'ability.complex', ['action' => 'command', 'executionId' => $execution['id'],
        'expectedRevision' => $execution['revision'], 'requestId' => 'combo-confirm-request-' . $index,
        'command' => ['action' => 'confirm-attack', 'attackRequestId' => $attackBody['requestId']]]);
    requireTactical($confirmed->status === 200, 'PHP confirms the strike.');
    $execution = $confirmed->body['execution'];
    requireTactical(count($comboDb->payload('activity')['pendingAttacks'] ?? []) === $index + 1, 'Pending strikes survive workflow confirmation: ' . json_encode(array_column($comboDb->payload('activity')['pendingAttacks'] ?? [], 'status')));
}
requireTactical($execution['status'] === 'completed', 'PHP submits the whole series without a final manual damage step.');
$applied = 0;
foreach ($comboAttacks as $index => $attack) {
    $skip = ['attackId' => $attack['id'], 'decision' => 'skip', 'requestId' => 'combo-defense-request-' . $index];
    $denied = runCommand($comboDb, 'token.attack.oppose', $skip);
    requireTactical($denied->status === 403, 'Only the MJ can deny opposition for the series: ' . $denied->status . ' ' . $denied->getMessage());
    $resolved = runCommand($comboDb, 'token.attack.oppose', $skip, true, 'account-gm');
    requireTactical($resolved->status === 200 && $resolved->body['attack']['status'] === 'applied', 'Denied counter applies the configured damage: ' . $resolved->getMessage());
    $delta = $resolved->body['attack']['appliedDamage'];
    requireTactical($delta >= 14 && $delta <= 92 && $resolved->body['attack']['damage']['armorPercent'] === 0, '2d40+12 bypasses armor exactly.');
    $applied += $delta;
    requireTactical(runCommand($comboDb, 'token.attack.oppose', $skip, true, 'account-gm')->body['deduplicated'], 'Counter decision replay does not apply damage twice.');
}
requireTactical($comboDb->payload('token:scene-one:token-monster')['hp'] == 1000 - $applied
    && $comboDb->payload('character:character-player')['resources']['mana'] == 3
    && $comboDb->payload('character:character-player')['fatigue']['current'] === 5, 'PHP applies actual HP loss and pays mana/fatigue once.');
foreach ([0,50,100] as $brightness) requireTactical(validApplicationFogState(['version' => 1, 'enabled' => true, 'width' => 32, 'height' => 32, 'mask' => '', 'brightness' => $brightness]), 'Fog brightness is accepted without a schema migration.');
foreach ([-1,101,null,'0'] as $brightness) requireTactical(!validApplicationFogState(['version' => 1, 'enabled' => true, 'width' => 32, 'height' => 32, 'mask' => '', 'brightness' => $brightness]), 'Malformed brightness is rejected atomically.');

foreach ([['physical',30,1,50],['physical',30,2,30],['magical',0,1,80],['magical',30,1,50],['ignore',0,1,100]] as [$type,$armor,$count,$expected]) {
    $guardDb=fixture();$guardToken=$guardDb->payload('token:scene-one:token-monster');
    $guardToken['hp']=$guardToken['maxHp']=1000;$guardDb->put('token:scene-one:token-monster',$guardToken);
    $guardDb->put('initiative:scene-one',['active'=>true,'combatId'=>'guard-combat']);
    $activity=$guardDb->payload('activity');$activity['nextAttackGuards']=array_fill(0,$count,['sceneId'=>'scene-one','targetTokenId'=>'token-monster','combatId'=>'guard-combat','percent'=>20,'stackGroup'=>'orbs','trigger'=>'damage','damageTypes'=>['physical','magical'],'armorStacking'=>'add']);
    $guardDb->put('activity',$activity);$records=$guardDb->domains;$pending=[];
    $basis=['rawDamage'=>100,'finalDamage'=>100-$armor,'armorPercent'=>$armor,'damageType'=>$type];
    $health=applyOnlineAttackDamage($guardDb,$records,$pending,'token:scene-one:token-monster',$guardToken,100-$armor,true,$basis);
    requireTactical($health['appliedDamage']===$expected && $pending['token:scene-one:token-monster']['payload']['hp']===1000-$expected,'PHP applies additive armor/orbs to actual HP with per-type exceptions.');
    requireTactical($type==='ignore' ? !isset($pending['activity']) : $pending['activity']['payload']['nextAttackGuards']===[],'Only eligible damage consumes the entire orb guard group.');
}
