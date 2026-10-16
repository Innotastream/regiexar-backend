<?php
declare(strict_types=1);
require __DIR__.'/tactical-lifecycle.php';
$db=fixture();$character=$db->payload('character:character-player');$character['specialSuccessStock']=4;
$character['stats']['force']=0;$character['weaponText']='Gourdin 1d6+2';$character['weaponAttacks']=[['id'=>'weapon-1','formula'=>'1d6+2','damageType'=>'physical']];
$character['resources']['mana']=50;$character['resources']['maxMana']=50;
$character['abilities']=normalizeOnlineAbilities([['id'=>'joker-heal','name'=>'Soin fictif','effect'=>'healing','healingFormula'=>'5','manaCost'=>7,'castingStatId'=>'character-stat-force']]);
$db->put('character:character-player',$character);
$target=$db->payload('token:scene-one:token-monster');$target['specialSuccessStock']=2;$target['hp']=10000;$target['maxHp']=10000;$db->put('token:scene-one:token-monster',$target);
$rollArgs=['requestId'=>'joker-direct-request-0001','sceneId'=>'scene-one','layerId'=>'ground','tokenId'=>'token-player','kind'=>'joker'];
$roll=runCommand($db,'token.roll',$rollArgs);requireTactical($roll->status===200,'Native Joker roll: '.$roll->getMessage());
requireTactical($roll->body['roll']['outcome']['code']==='success'&&$roll->body['roll']['outcome']['joker']===true&&$roll->body['roll']['outcome']['raw']===null,'Joker is an ordinary success without natural d100');
requireTactical($db->payload('character:character-player')['specialSuccessStock']===3,'Native Joker consumes the sheet stock once');
requireTactical(runCommand($db,'token.roll',$rollArgs)->body['deduplicated']&&$db->payload('character:character-player')['specialSuccessStock']===3,'Native Joker retry is idempotent');
requireTactical(runCommand($db,'token.roll',[...$rollArgs,'requestId'=>'joker-stolen-request-01'],false,'foreign')->status===403,'A foreign player cannot consume the stock');
$attackArgs=['requestId'=>'joker-attack-request-0001','sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','attackKind'=>'weapon','attackId'=>'weapon-1','statId'=>'character-stat-force','useJoker'=>true,'opposed'=>true];
$attack=runCommand($db,'token.attack',$attackArgs);requireTactical($attack->status===200,'Native Joker attack: '.$attack->getMessage());
requireTactical($attack->body['attack']['status']==='awaiting-opposition'&&$attack->body['attack']['hit']['outcome']['joker']===true,'Joker attack still requires opposition');
requireTactical($db->payload('character:character-player')['specialSuccessStock']===2,'Native attack consumes one Joker');
requireTactical(runCommand($db,'token.attack',$attackArgs)->body['deduplicated']&&$db->payload('character:character-player')['specialSuccessStock']===2,'Native attack retry does not consume twice');
$defenseArgs=['requestId'=>'joker-defense-request-0001','attackId'=>$attack->body['attack']['id'],'decision'=>'roll','statId'=>'monster-force','useJoker'=>true];
$defense=runCommand($db,'token.attack.oppose',$defenseArgs,true);requireTactical($defense->status===200,'Native Joker defense: '.$defense->getMessage());
requireTactical($defense->body['attack']['opposition']['outcome']['joker']===true&&$db->payload('token:scene-one:token-monster')['specialSuccessStock']===1,'Native GM response consumes the autonomous defender stock: '.json_encode(['stock'=>$db->payload('token:scene-one:token-monster')['specialSuccessStock'],'outcome'=>$defense->body['attack']['opposition']['outcome']]));
requireTactical(runCommand($db,'token.attack.oppose',$defenseArgs,true)->body['deduplicated']&&$db->payload('token:scene-one:token-monster')['specialSuccessStock']===1,'Native defense retry is idempotent');
$beforeMana=$db->payload('character:character-player')['resources']['mana'];
$castArgs=['requestId'=>'joker-casting-request-0001','sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-player','abilityId'=>'joker-heal','useJoker'=>true];
$cast=runCommand($db,'ability.use',$castArgs);requireTactical($cast->status===200,'Native Joker cast: '.$cast->getMessage());
requireTactical($cast->body['castSucceeded']&&$db->payload('character:character-player')['specialSuccessStock']===1,'Native casting consumes one Joker');
requireTactical((float)$db->payload('character:character-player')['resources']['mana']===(float)$beforeMana-7,'Native casting still pays its mana cost');
requireTactical(runCommand($db,'ability.use',$castArgs)->body['deduplicated']&&$db->payload('character:character-player')['specialSuccessStock']===1,'Native casting retry preserves costs and stock');
$last=runCommand($db,'token.roll',[...$rollArgs,'requestId'=>'joker-last-request-0001']);requireTactical($last->status===200&&$db->payload('character:character-player')['specialSuccessStock']===0,'Last native Joker is available');
requireTactical(runCommand($db,'token.roll',[...$rollArgs,'requestId'=>'joker-empty-request-001'])->status===409,'Empty stock refuses another Joker');
$ordinary=applicationSpecialSuccessOutcome();
requireTactical(!onlineDefenderWinsOpposition($ordinary,$ordinary),'Equal fixed Jokers favor attacker');
requireTactical(onlineDefenderWinsOpposition($ordinary,['code'=>'special-success','success'=>true,'raw'=>55]),'A natural special outcome beats the ordinary Joker');
requireTactical(validApplicationRollDomain($roll->body['roll']),'Joker activity is a valid stored roll');
$comboDb=fixture();$character=$comboDb->payload('character:character-player');$character['specialSuccessStock']=3;$character['stats']['force']=0;
$character['resources']['mana']=$character['resources']['maxMana']=50;
$character['abilities']=normalizeOnlineAbilities([['id'=>'joker-combo','name'=>'Combo Joker fictif','effect'=>'complex','formula'=>'0','manaCost'=>3,'castingStatId'=>'character-stat-force',
    'workflow'=>['version'=>6,'steps'=>[['id'=>'chain','type'=>'attack-chain','resolutionMode'=>'batch','firstGateAtCast'=>true,'statId'=>'character-stat-force','count'=>3,
        'penaltyPerSuccess'=>10,'stopOnFailure'=>true,'damageMode'=>'configured','damageComponents'=>[['type'=>'physical','formula'=>'1d6+2']],'allowOpposition'=>true]]]]]);
$comboDb->put('character:character-player',$character);
$started=runCommand($comboDb,'ability.complex',['requestId'=>'joker-combo-start-0001','action'=>'start','sceneId'=>'scene-one','layerId'=>'ground',
    'sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','abilityId'=>'joker-combo','useJoker'=>true]);
requireTactical($started->status===200,'Native Joker combo start: '.$started->getMessage());$execution=$started->body['execution'];
requireTactical($execution['castGate']['total']===1&&$execution['stepStates']['chain']['rolls'][0]['outcomeDetails']['joker']===true,'Native combo retains the fixed ordinary cast');
$prepArgs=['requestId'=>'joker-combo-prepare-01','action'=>'command','executionId'=>$execution['id'],'expectedRevision'=>$execution['revision'],
    'command'=>['action'=>'prepare-chain','targetTokenId'=>'token-monster','attackId'=>'joker-combo','statId'=>'character-stat-force','opposed'=>true,'rollMode'=>'disadvantage','useJoker'=>true]];
$prepared=runCommand($comboDb,'ability.complex',$prepArgs);requireTactical($prepared->status===200,'Native Joker combo prepare: '.$prepared->getMessage());
requireTactical(count($prepared->body['rolls'])===2&&$comboDb->payload('character:character-player')['specialSuccessStock']===0,'Native combo spends one Joker per new roll');
requireTactical(runCommand($comboDb,'ability.complex',$prepArgs)->body['deduplicated']===true,'Native combo retry preserves the stock');
$strike=runCommand($comboDb,'token.attack',['requestId'=>'joker-combo-strike-01','sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster',
    'attackKind'=>'ability','attackId'=>'joker-combo','statId'=>'character-stat-force','opposed'=>true,'complexExecutionId'=>$execution['id']]);
requireTactical($strike->status===200,'Native Joker strike: '.$strike->getMessage());
requireTactical($strike->body['attack']['hit']['outcome']['joker']===true&&$strike->body['attack']['status']==='awaiting-opposition','Native reused Joker stays ordinary without a second debit');
$partialDb=fixture();$partial=$partialDb->payload('character:character-player');$partial['specialSuccessStock']=1;$partial['stats']['force']=100;
$partial['abilities']=$character['abilities'];$partial['abilities'][0]['castingStatId']='';$partial['abilities'][0]['workflow']['steps'][0]['firstGateAtCast']=false;
$partial['abilities'][0]['workflow']['steps'][0]['penaltyPerSuccess']=0;$partialDb->put('character:character-player',$partial);
$partialStart=runCommand($partialDb,'ability.complex',['requestId'=>'joker-partial-start-01','action'=>'start','sceneId'=>'scene-one','layerId'=>'ground',
    'sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','abilityId'=>'joker-combo']);
requireTactical($partialStart->status===200,'Native partial series starts: '.$partialStart->getMessage());
$partialPrep=runCommand($partialDb,'ability.complex',[...$prepArgs,'requestId'=>'joker-partial-prepare','executionId'=>$partialStart->body['execution']['id'],
    'command'=>[...$prepArgs['command'],'jokerRollCount'=>1,'rollMode'=>'normal']]);
requireTactical($partialPrep->status===200,'Native mixed Joker series: '.$partialPrep->getMessage());
requireTactical(count(array_filter($partialPrep->body['rolls'],static fn($roll)=>($roll['outcome']['joker']??false)===true))===1
    &&$partialDb->payload('character:character-player')['specialSuccessStock']===0,'Native series consumes only the selected number of Jokers');
$cloneDb=fixture();$owner=$cloneDb->payload('character:character-player');$owner['specialSuccessStock']=2;$cloneDb->put('character:character-player',$owner);
$created=runCommand($cloneDb,'token.clone',['sceneId'=>'scene-one','tokenId'=>'token-player','requestId'=>'joker-clone-request-01'],true);
requireTactical(in_array($created->status,[200,201],true)&&$created->body['token']['specialSuccessStock']===2,'Native independent clone copies the current stock');
$cloneRoll=runCommand($cloneDb,'token.roll',['requestId'=>'joker-clone-roll-0001','sceneId'=>'scene-one','layerId'=>'ground','tokenId'=>$created->body['token']['id'],'kind'=>'joker']);
requireTactical($cloneRoll->status===200&&$cloneDb->payload('character:character-player')['specialSuccessStock']===2,'Native clone consumes its own stock');
echo "special-success-contract: ok\n";
