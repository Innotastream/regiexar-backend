<?php
declare(strict_types=1);
require __DIR__ . '/tactical-lifecycle.php';

function echoFixture(): MemoryConnection {
    $db = fixture();
    $character = $db->payload('character:character-player');
    $character['resources']['mana'] = $character['resources']['maxMana'] = 100;
    $character['stats']['force'] = 100; $character['specialSuccessStock'] = 20;
    $character['weaponText'] = 'Arme 1d6+2';
    $character['weaponAttacks'] = [['id'=>'weapon-1','formula'=>'1d6+2','damageType'=>'physical']];
    $character['abilities'] = [
        ['id'=>'club','name'=>'Gourdin de recette','effect'=>'enhancement','formula'=>'0','castingStatId'=>'','manaCost'=>20,
            'cooldownRounds'=>5,'restRecharge'=>'short','usesPerRest'=>3,
            'attackEnhancement'=>['mode'=>'echo','formula'=>'1d40+6','allyFormula'=>'1d20+3','damageType'=>'magical','turns'=>5]],
        ['id'=>'spell','name'=>'Sort ordinaire','effect'=>'damage','formula'=>'7','damageType'=>'physical','castingStatId'=>'']];
    $db->put('character:character-player', $character);
    $enemy = $db->payload('token:scene-one:token-monster');$enemy['hp']=$enemy['maxHp']=10000;$enemy['magicArmor']=50;
    $db->put('token:scene-one:token-monster',$enemy);
    return $db;
}
function echoCast(MemoryConnection $db, string $target='token-player'): TestResponse {
    $response=runCommand($db,'ability.use',['requestId'=>'echo-cast-request-0001','sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>$target,'abilityId'=>'club']);
    requireTactical($response->status===200,'Buff cast: '.$response->getMessage());return $response;
}
function echoReceipt(MemoryConnection $db,string $requestId): array {
    foreach($db->payload('activity')['attackReceipts'] as $receipt)if($receipt['requestId']===$requestId)return $receipt['attack'];
    throw new RuntimeException('Missing attack receipt');
}
$db=echoFixture();echoCast($db);
requireTactical((float)$db->payload('token:scene-one:token-monster')['hp']===10000.0,'Buff has no initial damage');
requireTactical($db->payload('activity')['attackEnhancements'][0]['formula']==='1d40+6','Self formula');
requireTactical(echoCast($db)->body['deduplicated']===true && count($db->payload('activity')['attackEnhancements'])===1,'Buff retry does not stack');
foreach(['weapon','ability'] as $kind){
    $request=['requestId'=>'echo-strike-'.$kind.'-0001','sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster',
        'attackKind'=>$kind,'attackId'=>$kind==='weapon'?'weapon-1':'spell','statId'=>'character-stat-force',...($kind==='weapon'?['useJoker'=>true]:[])];
    $hp=$db->payload('token:scene-one:token-monster')['hp'];
    $response=runCommand($db,'token.attack',$request);requireTactical($response->status===200,'Attack: '.$response->getMessage());
    $attack=echoReceipt($db,$request['requestId']);$echo=$attack['echoAttacks'][0]??[];
    requireTactical(count($attack['echoAttacks']??[])===1 && $echo['damageFormula']==='1d40+6','One echo per offensive action');
    requireTactical($echo['hit']['outcome']['automatic'] && !$echo['opposed'] && empty($echo['echoAttacks']),'Auto hit, no recursive echo');
    requireTactical($echo['damageType']==='magical' && $echo['damage']['armorPercent']===50,'Magic armor separately');
    requireTactical($hp-$db->payload('token:scene-one:token-monster')['hp']===$attack['appliedDamage']+$echo['appliedDamage'],'Both damages applied to current HP');
    requireTactical(count($response->body['rolls'])===($kind==='weapon'?3:2),'Player receives main and echo dice');
    $hp=$db->payload('token:scene-one:token-monster')['hp'];
    requireTactical(runCommand($db,'token.attack',$request)->body['deduplicated'] && $hp===$db->payload('token:scene-one:token-monster')['hp'],'Repeated request does not damage twice');
}
requireTactical((float)$db->payload('character:character-player')['resources']['mana']===80.0 && $db->payload('activity')['actionTimers'][0]['restUseCount']===1,'No extra mana or use');

// A failed attack uses the same committed transaction primitive, with no reliance
// on cryptographic d100 luck. Only the main attack result is supplied by fixture.
$attack=echoReceipt($db,'echo-strike-ability-0001');
unset($attack['echoAttacks'],$attack['echoProcessed'],$attack['echoDefinitions']);
$attack['id']='attack-failed-fixture-0001';$attack['status']='missed';$attack['hit']['outcome']['success']=false;
$records=applicationDomainRecords($db);$pending=[];$hp=$db->payload('token:scene-one:token-monster')['hp'];
$failed=onlineApplyAttackEchoes($db,$records,$pending,$attack);
requireTactical(count($failed['echoAttacks'])===1 && $failed['echoAttacks'][0]['appliedDamage']>0,'Echo despite miss');
requireTactical($pending['token:scene-one:token-monster']['payload']['hp']===$hp-$failed['echoAttacks'][0]['appliedDamage'],'Failed main attack only applies echo');
$retry=onlineApplyAttackEchoes($db,$records,$pending,$failed);
requireTactical($retry===$failed,'Primitive does not reapply echo');

$db=echoFixture();
$ally=$db->payload('character:character-player');$ally['id']='ally';$ally['ownerPlayerId']='ally-account';$ally['abilities']=[];
$db->put('character:ally',$ally);
$token=$db->payload('token:scene-one:token-player');$token['id']='ally-token';$token['characterId']='ally';$token['controllerPlayerId']='ally-account';$token['x']=30;
$db->put('token:scene-one:ally-token',$token);$index=$db->payload('token-index:scene-one');$index['order'][]='ally-token';$db->put('token-index:scene-one',$index);
echoCast($db,'ally-token');
$effect=$db->payload('activity')['attackEnhancements'][0];requireTactical($effect['holder']==='character:ally' && $effect['formula']==='1d20+3','Ally formula and holder');
$request=['requestId'=>'echo-ally-weapon-0001','sceneId'=>'scene-one','sourceTokenId'=>'ally-token','targetTokenId'=>'token-monster','attackKind'=>'weapon','attackId'=>'weapon-1','statId'=>'character-stat-force','useJoker'=>true];
$response=runCommand($db,'token.attack',$request,false,'ally-account');requireTactical($response->status===200,'Ally attack: '.$response->getMessage());
requireTactical(echoReceipt($db,$request['requestId'])['echoAttacks'][0]['damageFormula']==='1d20+3','Ally dice rolled directly');
requireTactical((float)$db->payload('character:ally')['resources']['mana']===100.0,'Ally pays no buff cost');
$moved=applicationTransferAttackEnhancements([$effect],$token,'scene-one','scene-two',['active'=>true,'combatId'=>'new','turnSerial'=>8]);
requireTactical($moved[0]['remainingTurns']===5 && $moved[0]['formula']==='1d20+3' && $moved[0]['sceneId']==='scene-two','Portal preserves ally buff and duration');
// An automatic main spell can be defended, but its echo cannot be defended.
$db=echoFixture();echoCast($db);
$enemy=$db->payload('token:scene-one:token-monster');$enemy['specialSuccessStock']=1;$db->put('token:scene-one:token-monster',$enemy);
$response=runCommand($db,'token.attack',['requestId'=>'echo-opposed-spell-0001','sceneId'=>'scene-one','sourceTokenId'=>'token-player',
    'targetTokenId'=>'token-monster','attackKind'=>'ability','attackId'=>'spell','opposed'=>true]);
requireTactical($response->status===200 && $response->body['attack']['status']==='awaiting-opposition','Main attack awaits defense');
$body=['attackId'=>$response->body['attack']['id'],'requestId'=>'echo-opposition-00001','statId'=>'monster-force','useJoker'=>true];
$response=runCommand($db,'token.attack.oppose',$body,true,'account-gm');
requireTactical($response->status===200 && $response->body['attack']['status']==='defended','Main attack defended: '.$response->getMessage());
requireTactical($response->body['attack']['echoAttacks'][0]['status']==='applied' && $db->payload('activity')['pendingAttacks']===[],'Echo applies after successful defense');
$hp=$db->payload('token:scene-one:token-monster')['hp'];
requireTactical(runCommand($db,'token.attack.oppose',$body,true,'account-gm')->body['deduplicated'] && $db->payload('token:scene-one:token-monster')['hp']===$hp,'Defense retry does not repeat echo');

$db=echoFixture();$initiative=$db->payload('initiative:scene-one');$initiative['active']=false;$db->put('initiative:scene-one',$initiative);echoCast($db);
$response=runCommand($db,'token.attack',['requestId'=>'echo-exploration-0001','sceneId'=>'scene-one','sourceTokenId'=>'token-player',
    'targetTokenId'=>'token-monster','attackKind'=>'ability','attackId'=>'spell']);
requireTactical($response->status===200 && $response->body['attack']['status']==='pending','Exploration awaits one MJ validation');
$response=runCommand($db,'token.attack.resolve',['attackId'=>$response->body['attack']['id'],'decision'=>'approve','confirmed'=>true],true,'account-gm');
requireTactical($response->status===200 && $response->body['attack']['echoAttacks'][0]['status']==='applied' && $db->payload('activity')['pendingAttacks']===[],'One approval applies main and echo');
$summary=applicationComplexAbilityAttackResult(echoReceipt($db,'echo-exploration-0001'));
requireTactical(isset($summary['echoAttacks'][0]['appliedDamage']) && !isset($summary['echoAttacks'][0]['damage']) && !isset($summary['echoDefinitions']),'Public combo result keeps echo HP, no armor or private snapshot');

// Prepared batch rolls may fail before token.attack is dispatched.
$db=echoFixture();echoCast($db);$records=applicationDomainRecords($db);$pending=[];
$current=['id'=>'combo-echo-fixture','revision'=>1,'sceneId'=>'scene-one','layerId'=>'ground','sourceTokenId'=>'token-player',
    'abilityId'=>'combo','abilityName'=>'Série de recette','currentStepIndex'=>0,'workflow'=>['steps'=>[['id'=>'chain','type'=>'attack-chain','statId'=>'character-stat-force']]],'stepStates'=>[]];
$next=$current;$next['revision']=2;$next['stepStates']['chain']=['plan'=>['targetTokenId'=>'token-monster'],
    'rolls'=>[['rawD100'=>50,'total'=>50,'success'=>true,'outcomeDetails'=>classifyOnlineD100Outcome(50,60)],
        ['rawD100'=>50,'total'=>50,'success'=>false,'outcomeDetails'=>classifyOnlineD100Outcome(50,40)]]];
$rolls=onlineFailedChainEchoes($db,$records,$pending,$current,$next,'prepare-chain',['id'=>'account-player','display_name'=>'Joueur test'],false);
requireTactical(count($rolls)===1 && count($pending['activity']['payload']['attackReceipts'])===2,'One failed batch gate creates its main miss receipt and echo');
$hp=$pending['token:scene-one:token-monster']['payload']['hp'];
requireTactical(onlineFailedChainEchoes($db,$records,$pending,$next,$next,'prepare-chain',['id'=>'account-player'],false)===[]
    && $pending['token:scene-one:token-monster']['payload']['hp']===$hp,'Previously processed batch gates do not repeat');

foreach([true,false] as $affectCaster) {
    $db=echoFixture();echoCast($db);$character=$db->payload('character:character-player');$character['resources']['hp']=$character['resources']['maxHp']=1000;
    $db->put('map:scene-one',['naturalWidth'=>1000,'naturalHeight'=>1000,'gridSize'=>50]);
    $character['abilities'][]=['id'=>'area','name'=>'Zone de recette','effect'=>'damage','formula'=>'7','damageType'=>'physical','castingStatId'=>'',
        'damageTargeting'=>['mode'=>'area','radiusCells'=>3,'origin'=>'caster','affectCaster'=>$affectCaster,'affectAllies'=>true,'affectEnemies'=>true]];
    $db->put('character:character-player',$character);
    foreach(['token-player','token-monster','token-monster-two'] as $id){$token=$db->payload('token:scene-one:'.$id);$token['x']=40;$token['y']=50;$db->put('token:scene-one:'.$id,$token);}
    $response=runCommand($db,'token.attack',['requestId'=>'echo-area-spell-00001','sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-player','attackKind'=>'ability','attackId'=>'area']);
    requireTactical($response->status===200,'Area cast: '.$response->getMessage());
    $echoes=array_values(array_filter(array_column($db->payload('activity')['attackReceipts'],'attack'),static fn($a)=>!empty($a['echoParentId'])));
    requireTactical(count($echoes)>1 && in_array('token-player',array_column($echoes,'targetTokenId'),true)===$affectCaster,'Area echo preserves caster inclusion: '.json_encode([$affectCaster,array_column($echoes,'targetTokenId'),array_map(static fn($r)=>[$r['attack']['targetTokenId'],$r['attack']['status'],$r['attack']['areaAnchorOnly']??false],$db->payload('activity')['attackReceipts'])]));
    foreach($echoes as $echo) requireTactical($echo['status']==='applied','Area echo damage applied');
}

echo "attack-echoes-lifecycle: ok\n";
