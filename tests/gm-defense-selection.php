<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);
require __DIR__.'/tactical-special-effects.php';
$specialChecks=0;$db=specialFixture();
$character=$db->payload('character:character-player');$character['abilities']=[['id'=>'area-defense-native','name'=>'Zone native MJ','effect'=>'damage','formula'=>'7','damageType'=>'ignore','castingStatId'=>'','manaCost'=>3,'fatigueCost'=>2,'damageTargeting'=>['mode'=>'area','origin'=>'caster','radiusCells'=>4,'affectCaster'=>false,'affectAllies'=>true,'affectEnemies'=>true]]];$db->put('character:character-player',$character);
$map=$db->payload('map:scene-one');$map['naturalWidth']=1000;$map['naturalHeight']=1000;$map['gridSize']=50;$db->put('map:scene-one',$map);
foreach(['token-player'=>20,'token-monster'=>25,'token-monster-two'=>27]as$id=>$x){$key='token:scene-one:'.$id;$token=$db->payload($key);$token['x']=$x;$token['y']=50;$db->put($key,$token);}
$independent=$db->payload('token:scene-one:token-independent');$independent['x']=90;$independent['y']=90;$db->put('token:scene-one:token-independent',$independent);
$before=$db->payload('character:character-player');$monsterBefore=$db->payload('token:scene-one:token-monster');$otherBefore=$db->payload('token:scene-one:token-monster-two');
$args=['sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-player','attackKind'=>'ability','attackId'=>'area-defense-native','statId'=>'','opposed'=>false,'requestId'=>'defense-native-'.randomToken(16)];
$prepared=runCommand($db,'token.attack',$args);specialCheck($prepared->status===200,'Player preparation accepted: '.json_encode($prepared->body));specialCheck(($prepared->body['pendingAreaGate']??false)===true,'Preparation waits for individual MJ choices');
$activity=$db->payload('activity');$parent=$activity['pendingAttacks'][0];specialCheck($parent['areaGate']['kind']==='defense-selection','Common area gate reused');specialCheck($db->payload('character:character-player')===$before,'No resource spent before decision');specialCheck($db->payload('token:scene-one:token-monster')===$monsterBefore&&$db->payload('token:scene-one:token-monster-two')===$otherBefore,'No target damaged before decision');
$decision=['requestId'=>'defense-approval-'.randomToken(12),'attackId'=>$parent['id'],'confirmed'=>true,'decision'=>'approve','defenseTokenIds'=>['token-monster']];
specialCheck(runCommand($db,'token.attack.area.resolve',$decision)->status===403,'Player cannot decide defenses');specialCheck(runCommand($db,'token.attack.area.resolve',[...$decision,'defenseTokenIds'=>['token-player']],true)->status===400,'Foreign or ineligible target refused');specialCheck($db->payload('character:character-player')===$before,'Rejected choices spend nothing');
$approved=runCommand($db,'token.attack.area.resolve',$decision,true);specialCheck($approved->status===200,'MJ decision succeeds: '.json_encode($approved->body));$children=$approved->body['areaAttacks']??[];$byId=[];foreach($children as$child)$byId[$child['targetTokenId']]=$child;
specialCheck(($byId['token-monster']['status']??'')==='awaiting-opposition'&&$byId['token-monster']['opposed']===true,'Chosen target gets an opposition');specialCheck(($byId['token-monster-two']['opposed']??true)===false,'Other target cannot choose its own opposition');specialCheck($db->payload('token:scene-one:token-monster')['hp']===$monsterBefore['hp'],'Defense waits before damage');specialCheck($db->payload('token:scene-one:token-monster-two')['hp']===$otherBefore['hp']-7,'Unopposed damage applies after MJ only');specialCheck($db->payload('character:character-player')['resources']['mana']===$before['resources']['mana']-3,'Mana spent exactly once on approval');
$committed=$db->payload('character:character-player');specialCheck((runCommand($db,'token.attack.area.resolve',$decision,true)->body['deduplicated']??false)===true,'MJ receipt replay deduplicated');specialCheck((runCommand($db,'token.attack',$args)->body['deduplicated']??false)===true,'Original preparation replay deduplicated');specialCheck($db->payload('character:character-player')===$committed,'Neither replay spends twice');

$groupDb = specialFixture();
$groupDb->put('character:character-player', $before);
$groupDb->put('map:scene-one', $map);
foreach (['token-player'=>20, 'token-monster'=>25, 'token-monster-two'=>27, 'token-independent'=>90] as $id => $x) {
    $key = 'token:scene-one:' . $id;
    $token = [...$groupDb->payload($key), 'x'=>$x, 'y'=>$x === 90 ? 90 : 50];
    if (in_array($id, ['token-monster', 'token-monster-two'], true)) {
        $token['stats'] = [['id'=>'character-stat-agility', 'label'=>'Agilité', 'value'=>'50'],
            ['id'=>'character-stat-force', 'label'=>'Force', 'value'=>'65']];
    }
    $groupDb->put($key, $token);
}
$groupParent = runCommand($groupDb, 'token.attack', [...$args, 'requestId'=>'group-area-launch-' . randomToken(16)])->body['attack'];
$groupApproved = runCommand($groupDb, 'token.attack.area.resolve', [...$decision, 'attackId'=>$groupParent['id'],
    'requestId'=>'group-area-approve-' . randomToken(16), 'defenseTokenIds'=>['token-monster', 'token-monster-two']], true);
$groupActivity = $groupDb->payload('activity');
$groupChildren = array_values(array_filter($groupActivity['pendingAttacks'], static fn($child) =>
    ($child['areaGateParentId'] ?? '') === $groupParent['id'] && $child['status'] === 'awaiting-opposition'));
specialCheck($groupApproved->status === 200 && count($groupChildren) === 2, 'The prepared zone authors both pending children with the same parent');
$batch = ['requestId'=>'group-area-oppositions-'.randomToken(16), 'attackId'=>$groupParent['id'], 'confirmed'=>true,
    'defenses'=>[['attackId'=>$groupChildren[0]['id'], 'statId'=>'character-stat-agility'],
        ['attackId'=>$groupChildren[1]['id'], 'statId'=>'character-stat-force']]];
specialCheck(runCommand($groupDb, 'token.attack.area.oppose', $batch)->status === 403, 'Only a GM can resolve area defenses in one command');
$staleBatch = [...$batch, 'defenses'=>[$batch['defenses'][0], ['attackId'=>$groupParent['id'], 'statId'=>'character-stat-force']]];
specialCheck(runCommand($groupDb, 'token.attack.area.oppose', $staleBatch, true)->status === 409, 'The batch may not contain a non-child attack');
$badBatch = $batch;
$badBatch['defenses'][1]['statId'] = 'obsolete-stat';
$beforeHp = $groupDb->payload('token:scene-one:token-monster')['hp'];
$badResult = runCommand($groupDb, 'token.attack.area.oppose', $badBatch, true);
specialCheck($badResult->status === 404
    && $groupDb->payload('activity') === $groupActivity && $groupDb->payload('token:scene-one:token-monster')['hp'] === $beforeHp,
    'An invalid second defense rolls back the first and publishes no result: ' . json_encode($badResult->body));
// The original PHP path kept the correct linkage on the receipt alone.
foreach ($groupActivity['pendingAttacks'] as &$pendingAttack) unset($pendingAttack['areaGateParentId'], $pendingAttack['areaGateParties']);
unset($pendingAttack);
$groupDb->put('activity', $groupActivity);
$groupRoll = runCommand($groupDb, 'token.attack.area.oppose', $batch, true);
$groupOppositions = array_column($groupRoll->body['attacks'] ?? [], 'opposition');
specialCheck($groupRoll->status === 200 && array_column($groupOppositions, 'statId') === ['character-stat-agility', 'character-stat-force']
    && count(array_unique(array_column($groupOppositions, 'rollId'))) === 2, 'One batch resolves two separate selected defenses, including the legacy pending copies: '
    . json_encode($groupRoll->status === 200 ? $groupOppositions : $groupRoll->body, JSON_INVALID_UTF8_SUBSTITUTE));
$replayedBatch = runCommand($groupDb, 'token.attack.area.oppose', $batch, true);
specialCheck(($replayedBatch->body['deduplicated'] ?? false) === true && array_column($replayedBatch->body['attacks'], 'opposition') === $groupOppositions,
    'An exact retry returns the authoritative dice without a second roll');
specialCheck($groupDb->payload('character:character-player')['resources']['mana'] === $before['resources']['mana'] - 3,
    'Grouped opposition never charges the cast again');

// A legacy pending zone whose preparation receipt disappeared can be cleared by its MJ.
$orphanId='orphan-area-preparation';
$activity=$db->payload('activity');
$activity['pendingAttacks'][]=[...$parent,'id'=>$orphanId];
$db->put('activity',$activity);
$orphanDecision=['requestId'=>'orphan-area-rejection-'.randomToken(12),'attackId'=>$orphanId,'confirmed'=>true,'decision'=>'reject','defenseTokenIds'=>[]];
specialCheck(runCommand($db,'token.attack.area.resolve',$orphanDecision)->status===403,'Only the MJ can clear the orphan');
$rejected=runCommand($db,'token.attack.area.resolve',$orphanDecision,true);
specialCheck($rejected->status===200&&($rejected->body['attack']['status']??'')==='rejected','The orphan can be rejected without recovering a missing receipt');
specialCheck(array_column($db->payload('activity')['pendingAttacks'],'id')===
    array_values(array_filter(array_column($activity['pendingAttacks'],'id'),static fn(string $id):bool=>$id!==$orphanId)),'Other pending attacks are retained');
specialCheck($db->payload('character:character-player')===$committed,'Clearing the orphan does not change resources');

// Two independent MJ accounts can prepare and approve in a non-broadcast scene.
// Old tokens without a layerId inherit the authoritatively selected upper floor.
$floorDb = specialFixture();
$floorDb->put('table', [...$floorDb->payload('table'), 'activeSceneId' => 'scene-two']);
$floorDb->put('scene-index', ['order' => ['scene-one', 'scene-two']]);
$floorDb->put('scene:scene-one', ['metadata' => ['id' => 'scene-one', 'name' => 'Démo technique']]);
$floorDb->put('map:scene-one', [...$map, 'activeLayerId' => 'upper']);
$floorCharacter = $floorDb->payload('character:character-player');
$floorCharacter['abilities'] = [$before['abilities'][0]];
$floorDb->put('character:character-player', $floorCharacter);
foreach (['token-player' => 20, 'token-monster' => 25, 'token-monster-two' => 27, 'token-independent' => 90] as $tokenId => $x) {
    $key = 'token:scene-one:' . $tokenId;
    $token = [...$floorDb->payload($key), 'x' => $x, 'y' => $x === 90 ? 90 : 50];
    unset($token['layerId']);
    $floorDb->put($key, $token);
}
$floorArgs = [...$args, 'layerId' => 'upper', 'requestId' => 'floor-defense-' . randomToken(16)];
$floorPrepared = runCommand($floorDb, 'token.attack', $floorArgs, true, 'account-gm-one');
specialCheck($floorPrepared->status === 200 && !empty($floorPrepared->body['pendingAreaGate']),
    'The first MJ prepares the zone in the upper floor: ' . json_encode($floorPrepared->body));
$floorAttack = $floorPrepared->body['attack'];
specialCheck($floorAttack['layerId'] === 'upper' && $floorAttack['areaGate']['sourceParty']['layerId'] === 'upper',
    'The prepared footprint uses the actual floor for a legacy token');
$floorDecision = ['requestId' => 'floor-approve-' . randomToken(16), 'attackId' => $floorAttack['id'],
    'confirmed' => true, 'decision' => 'approve', 'defenseTokenIds' => ['token-monster']];
$floorTarget = [...$floorDb->payload('token:scene-one:token-monster'),
    'stats' => [['id' => 'character-stat-agility', 'label' => 'Agilité', 'value' => '65']],
    'specialSuccessStock' => 1,
];
$floorDb->put('token:scene-one:token-monster', [...$floorTarget, 'layerId' => 'ground']);
$staleFloor = runCommand($floorDb, 'token.attack.area.resolve', $floorDecision, true, 'account-gm-two');
specialCheck($staleFloor->status === 409 && $staleFloor->body['code'] === 'area_gate_refused'
    && str_contains($staleFloor->body['error'], 'Une cible a changé'),
    'An actual target floor change is still rejected');
$floorDb->put('token:scene-one:token-monster', $floorTarget);
$floorResult = runCommand($floorDb, 'token.attack.area.resolve', $floorDecision, true, 'account-gm-two');
specialCheck($floorResult->status === 200, 'The second MJ can approve on the prepared floor from another published scene: ' . json_encode($floorResult->body));
$floorChild = array_values(array_filter($floorResult->body['areaAttacks'] ?? [], static fn (array $child): bool =>
    ($child['targetTokenId'] ?? '') === 'token-monster'))[0];
specialCheck($floorChild['status'] === 'awaiting-opposition', 'The selected creature can defend in the unbroadcast scene');
$floorActivity = $floorDb->payload('activity');
$overdrive = json_decode(file_get_contents(__DIR__ . '/../api/v1/active-ability-defaults.json'), true, 512, JSON_THROW_ON_ERROR);
$floorActivity['attackEnhancements'][] = [
    ...$overdrive['character-inho']['abilities'][0]['attackEnhancement'],
    'id' => 'floor-defense-overdrive', 'holder' => 'token:token-monster',
    'sceneId' => 'scene-one', 'targetTokenId' => 'token-monster', 'remainingTurns' => 1,
];
$floorDb->put('activity', $floorActivity);
$activeToken = [...$floorTarget, 'name' => 'Créature de la scène diffusée', 'specialSuccessStock' => 4];
$floorDb->put('token:scene-two:token-monster', $activeToken);
$defenseArgs = [
    'attackId' => $floorChild['id'], 'decision' => 'roll', 'statId' => 'character-stat-agility',
    'rollMode' => 'normal', 'requestId' => 'floor-opposition-' . randomToken(16),
];
$ordinaryDb = new MemoryConnection([]);
$ordinaryDb->domains = $floorDb->domains;
$ordinaryDb->revision = $floorDb->revision;
$ordinaryDefense = runCommand($ordinaryDb, 'token.attack.oppose', $defenseArgs, true, 'account-gm-two');
specialCheck($ordinaryDefense->status === 200 && ($ordinaryDefense->body['attack']['opposition']['rollMode'] ?? '') === 'advantage'
    && count($ordinaryDefense->body['attack']['opposition']['attempts'] ?? []) === 2,
    'A defense uses the enhancement from its own unpublished scene');
$jokerDefense = runCommand($floorDb, 'token.attack.oppose', [...$defenseArgs, 'useJoker' => true], true, 'account-gm-two');
specialCheck($jokerDefense->status === 200 && ($jokerDefense->body['attack']['opposition']['outcome']['joker'] ?? false) === true,
    'A GM can use the defender’s special success in the unpublished scene');
specialCheck($floorDb->payload('token:scene-one:token-monster')['specialSuccessStock'] === 0
    && $floorDb->payload('token:scene-two:token-monster')['specialSuccessStock'] === 4
    && $floorDb->payload('table')['activeSceneId'] === 'scene-two',
    'A successful defense debits only the actual defender, independently of the published scene');

$conditionDb = specialFixture();
$conditionDb->put('table', [...$conditionDb->payload('table'), 'activeSceneId' => 'scene-two']);
$conditionDb->put('scene-index', ['order' => ['scene-one', 'scene-two']]);
$conditionDb->put('initiative:scene-one', [...$conditionDb->payload('initiative:scene-one'), 'currentIndex' => 1]);
$conditionDb->put('initiative:scene-two', ['active' => false, 'combatId' => 'unrelated-clock', 'turnSerial' => 99]);
$stun = ['id' => 'private-scene-stun', 'name' => 'Étourdissement de recette', 'effect' => 'damage',
    'castingStatId' => '', 'formula' => '1', 'damageType' => 'ignore', 'rangeCells' => 8,
    'conditionRider' => ['version' => 1, 'label' => 'Étourdi', 'turns' => 2, 'resistance' => false, 'blocksActions' => true]];
$privateAttacker = 'token:scene-one:token-monster';
$privateDefender = 'token:scene-one:token-monster-two';
$publishedDefender = 'token:scene-two:token-monster-two';
$conditionDb->put($privateAttacker, [...$conditionDb->payload($privateAttacker), 'abilities' => [$stun]]);
$conditionDb->put($privateDefender, [...$conditionDb->payload($privateDefender), 'x' => 52, 'y' => 50, 'layerId' => 'ground']);
$conditionDb->put($publishedDefender, $conditionDb->payload($privateDefender));
$conditionDb->put('token-index:scene-two', ['order' => ['token-monster-two']]);
$stunned = runCommand($conditionDb, 'token.attack', ['requestId' => 'private-scene-stun-001', 'sceneId' => 'scene-one',
    'sourceTokenId' => 'token-monster', 'targetTokenId' => 'token-monster-two', 'attackKind' => 'ability',
    'attackId' => $stun['id'], 'opposed' => false], true, 'account-gm-two');
specialCheck($stunned->status === 200 && ($stunned->body['attack']['status'] ?? '') === 'applied',
    'The GM can hit a creature in the unpublished scene: '.json_encode($stunned->body));
$condition = $conditionDb->payload('devices')['timedConditions'][0] ?? [];
specialCheck(in_array('Étourdi', $conditionDb->payload($privateDefender)['conditions'] ?? [], true)
    && !in_array('Étourdi', $conditionDb->payload($publishedDefender)['conditions'] ?? [], true)
    && ($condition['sceneId'] ?? '') === 'scene-one' && ($condition['clockCombatId'] ?? '') === 'special-test',
    'A timed condition affects only the actual target and uses the clock of the attack scene');

// A real stat roll is prepared once and its exact outcome drives the table.
$c=$db->payload('character:character-player');$c['abilities'][0]['castingStatId']='character-stat-force';$db->put('character:character-player',$c);$rollArgs=[...$args,'requestId'=>'native-prepared-roll-'.randomToken(16)];$before=$db->payload('character:character-player');$rolled=runCommand($db,'token.attack',$rollArgs);specialCheck($rolled->status===200,'Stat area preparation accepted');$parent=array_values(array_filter($db->payload('activity')['pendingAttacks'],static fn($a)=>$a['id']===$rolled->body['attack']['id']))[0];$raw=$parent['hit']['raw'];specialCheck(is_int($raw)&&$raw>=1&&$raw<=100,'One real d100 stored before the table');specialCheck($db->payload('character:character-player')===$before,'Prepared hit spends nothing');$expected=[];foreach($parent['areaGate']['targetTokenIds']as$id){$outcome=$parent['areaGate']['preparedHit']['outcomes'][$id];if(!empty($outcome['success'])&&empty($outcome['breaksOpposition']))$expected[]=$id;}specialCheck($expected===$parent['areaGate']['defenseEligibleTokenIds'],'Eligibility follows the real hit outcome, including remarkable results');$approved=runCommand($db,'token.attack.area.resolve',['requestId'=>'native-real-roll-'.randomToken(16),'attackId'=>$parent['id'],'confirmed'=>true,'decision'=>'approve','defenseTokenIds'=>$expected],true);specialCheck($approved->status===200,'Prepared hit can be approved');$issued=$approved->body['areaAttacks'];$expectedReceiptCount=!empty($parent['hit']['outcome']['success'])?count($parent['areaGate']['targetTokenIds'])+1:1;specialCheck(count($issued)===$expectedReceiptCount,'Approval keeps its anchor and exactly the target receipts allowed by the prepared outcome');specialCheck(array_filter($issued,static fn($child)=>($child['hit']['raw']??null)!==$raw)===[],'Every approval receipt reuses the exact prepared d100');

// The allocated charge is requested only after a preview and stays unpaid until MJ approval.
$db=specialFixture();$id='ability-566f321d-ca05-4b02-92ad-8149076076e6';$preset=json_decode(file_get_contents(__DIR__.'/../api/v1/ability-simple-presets.json'),true,512,JSON_THROW_ON_ERROR)[$id];$c=$db->payload('character:character-player');$c['abilities']=[['id'=>$id,'name'=>'Charge native MJ',...$preset['values'],'rulePreset'=>['id'=>$preset['presetId'],'revision'=>$preset['revision']]]];$c['weaponAttacks'][0]['naturalWeapon']=true;$db->put('character:character-player',$c);
foreach(['token-player'=>20,'token-monster'=>40,'token-monster-two'=>90,'token-independent'=>95]as$tokenId=>$x){$key='token:scene-one:'.$tokenId;$token=$db->payload($key);$token['x']=$x;$token['y']=$tokenId==='token-independent'?90:50;$db->put($key,$token);}
$selection=['allocations'=>[['tokenId'=>'token-monster','count'=>1]]];$before=$db->payload('character:character-player');$from=$db->payload('token:scene-one:token-player');$started=runCommand($db,'ability.complex',['action'=>'start','requestId'=>'native-charge-selection-'.randomToken(16),'sceneId'=>'scene-one','sourceTokenId'=>'token-player','abilityId'=>$id,'initialSelection'=>$selection,'chargeSelection'=>$selection]);specialCheck($started->status===200,'Charge prepares authoritative targets: '.json_encode($started->body));specialCheck(!empty($started->body['pendingAreaGate'])&&$started->body['execution']===null,'Charge waits with no execution');specialCheck($db->payload('character:character-player')===$before&&$db->payload('token:scene-one:token-player')===$from,'Charge preview spends and moves nothing');$approved=runCommand($db,'token.attack.area.resolve',['requestId'=>'native-charge-approved-'.randomToken(16),'attackId'=>$started->body['attack']['id'],'confirmed'=>true,'decision'=>'approve','defenseTokenIds'=>['token-monster']],true);specialCheck($approved->status===200&&!empty($approved->body['execution']),'MJ approval starts real charge: '.json_encode($approved->body));specialCheck($db->payload('character:character-player')['fatigue']['current']===$before['fatigue']['current']+10,'Charge fatigue paid once after MJ only');specialCheck($db->payload('token:scene-one:token-player')['x']===$from['x'],'Charge travel waits for its real attack step');
fwrite(STDOUT,json_encode(['ok'=>true,'checks'=>$specialChecks],JSON_UNESCAPED_UNICODE)."\n");
