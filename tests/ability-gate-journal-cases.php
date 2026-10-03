<?php
declare(strict_types=1);

// Regression shape from a retained two-step execution: cast gate has no
// breakdown, while its original roll still exists in the activity journal.
foreach (['normal', 'disadvantage'] as $gateMode) {
    $gateDb = fixture();
    $gateAbility = ['id'=>'legacy-gate-ability', 'name'=>'Frappes enregistrées', 'effect'=>'complex', 'castingStatId'=>'character-stat-force', 'formula'=>'0',
        'workflow'=>['version'=>8, 'steps'=>[
            ['id'=>'targets', 'type'=>'targets', 'minTargets'=>1, 'maxTargets'=>2],
            ['id'=>'strikes', 'type'=>'allocated-attacks', 'sourceStepId'=>'targets', 'statId'=>'character-stat-force', 'hitMode'=>'cast', 'awarenessMode'=>'none', 'damageMode'=>'configured', 'damageComponents'=>[['type'=>'ignore','formula'=>'2d40']]],
        ]]];
    $gateSheet = $gateDb->payload('character:character-player');
    $gateSheet['abilities'] = [$gateAbility]; $gateSheet['stats']['force'] = 80;
    $gateDb->put('character:character-player', $gateSheet);
    $gateFoe = $gateDb->payload('token:scene-one:token-monster');
    $gateFoe['hp']=1000; $gateFoe['maxHp']=1000;
    $gateDb->put('token:scene-one:token-monster', $gateFoe);
    $gateOutcome = classifyOnlineD100Outcome(68,80,0,0,true,true);
    $gateRoll = onlineRollEntry(['display_name'=>'MJ test'], ['formula'=>'1d100','total'=>68,'breakdown'=>'[68]', 'rollMode'=>$gateMode, 'selectedIndex'=>$gateMode==='normal'?0:1,
        ...($gateMode==='normal'?[]:['attempts'=>[['total'=>42,'breakdown'=>'[42]','rawD100'=>42],['total'=>68,'breakdown'=>'[68]','rawD100'=>68]]])], 'Lancement', 'Personnage', $gateOutcome);
    $gateExecution = createApplicationComplexAbilityExecution(['id'=>'execution-legacy-gate-journal','sceneId'=>'scene-one','sourceTokenId'=>'token-player','characterId'=>'character-player','controllerAccountId'=>'account-player',
        'ability'=>$gateAbility, 'cast'=>['success'=>true,'statId'=>'character-stat-force','outcome'=>$gateOutcome,'roll'=>$gateRoll]]);
    requireTactical($gateExecution['castGate']['roll'] === $gateRoll, 'New gates snapshot the entire original roll.');
    unset($gateExecution['castGate']['roll']); // Exact legacy gate shape.
    $gateActivity = $gateDb->payload('activity');
    $gateActivity['rolls']=[$gateRoll]; $gateActivity['abilityExecutions']=[$gateExecution];
    $gateDb->put('activity',$gateActivity);
    foreach ([['action'=>'select-targets','allocations'=>[['tokenId'=>'token-monster','count'=>1]],'attackPlan'=>[['tokenId'=>'token-monster','attackId'=>$gateAbility['id'],'statId'=>'character-stat-force','opposed'=>false]]],
        ['action'=>'prepare-target','targetTokenId'=>'token-monster']] as $gateCommand) {
        $gateResponse=runCommand($gateDb,'ability.complex',['action'=>'command','executionId'=>$gateExecution['id'],'expectedRevision'=>$gateExecution['revision'],'requestId'=>'gate-command-'.bin2hex(random_bytes(8)),'command'=>$gateCommand]);
        requireTactical($gateResponse->status===200,'Legacy gate setup: '.$gateResponse->getMessage());
        $gateExecution=$gateResponse->body['execution'];
    }
    $gateAttackBody=['sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','attackKind'=>'ability','attackId'=>$gateAbility['id'],'statId'=>'character-stat-force','opposed'=>false,'complexExecutionId'=>$gateExecution['id'],'requestId'=>'legacy-gate-attack-'.$gateMode];
    $gateAttack=runCommand($gateDb,'token.attack',$gateAttackBody);
    requireTactical($gateAttack->status===200,'Legacy allocated attack must save valid activity: '.$gateAttack->getMessage());
    requireTactical($gateAttack->body['hitRoll']['id']===$gateRoll['id'] && $gateAttack->body['hitRoll']['breakdown']==='[68]' && $gateAttack->body['hitRoll']['total']===68,'The acquired d100 is reused without a new roll.');
    requireTactical($gateAttack->body['hitRoll']['rollMode']===$gateMode && ($gateAttack->body['hitRoll']['attempts']??null)===($gateRoll['attempts']??null),'Both original attempts survive reuse.');
    requireTactical(validApplicationRollList($gateDb->payload('activity')['rolls'],100),'The journal still passes the strict native validator.');
    $gateResumed=new MemoryConnection(array_map(fn($record)=>$record['payload'],$gateDb->domains));
    $gateHp=$gateResumed->payload('token:scene-one:token-monster')['hp'];
    $gateRetry=runCommand($gateResumed,'token.attack',$gateAttackBody);
    requireTactical($gateRetry->status===200 && ($gateRetry->body['deduplicated']??false) && $gateResumed->payload('token:scene-one:token-monster')['hp']===$gateHp,'Rehydrated receipt cannot apply damage a second time.');
    requireTactical(applicationComplexAbilityGateRoll($gateExecution['castGate'])['breakdown']==='[68]','An expired original roll falls back to the acquired raw value without rerolling.');
}
