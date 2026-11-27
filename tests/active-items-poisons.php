<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);require __DIR__.'/tactical-special-effects.php';
$specialChecks=0;
function itemCommandTest(MemoryConnection $db,string $command,array $args,bool $gm=true):TestResponse{return runCommand($db,$command,['requestId'=>'active-'.randomToken(20),'sceneId'=>'scene-one',...$args],$gm);}
function itemSeed(MemoryConnection $db,string $id,array $active,string $unit='character-player'):void{
 $items=$db->payload('items')?:['version'=>1,'templates'=>[],'instances'=>[]];$items['templates'][]=['id'=>'t-'.$id,'name'=>'Objet fictif '.$id,'kind'=>'object','active'=>$active];$items['instances'][]=['id'=>$id,'templateId'=>'t-'.$id,'revision'=>1,'location'=>['kind'=>'inventory','characterId'=>$unit]];$db->put('items',$items);
}
function itemActive(array $effects,array $extra=[]):array{return ['version'=>1,'useLabel'=>'Utiliser','authority'=>'owner','effects'=>$effects,...$extra];}
$db=specialFixture();$c=$db->payload('character:character-player');$c['resources']['hp']=1;$c['resources']['maxHp']=103;$db->put('character:character-player',$c);
itemSeed($db,'heal',itemActive([['kind'=>'heal-max-die','percent'=>30]]));$args=['requestId'=>'same-item-request-123456','itemId'=>'heal','characterId'=>'character-player','tokenId'=>'token-player','expectedItemRevision'=>1];
$r=itemCommandTest($db,'item.consume',$args,false);specialCheck($r->status===200,'Native potion: '.json_encode($r->body));specialCheck($r->body['rolls'][0]['formula']==='1d30','Native max HP die');$hp=$db->payload('character:character-player')['resources']['hp'];specialCheck($hp>=2&&$hp<=31,'Native heal applied');$again=itemCommandTest($db,'item.consume',$args,false);specialCheck($again->status===200&&!empty($again->body['deduplicated'])&&$db->payload('character:character-player')['resources']['hp']===$hp,'Replay consumes/rolls once');
itemSeed($db,'bor',itemActive([['kind'=>'stats','bonuses'=>['mentalResistance'=>70],'duration'=>['kind'=>'rests','shortRests'=>2,'longRest'=>true]]]));$r=itemCommandTest($db,'item.consume',['itemId'=>'bor','characterId'=>'character-player','tokenId'=>'token-player','expectedItemRevision'=>1]);specialCheck($r->status===200,'Boramole: '.json_encode($r->body));$a=$db->payload('activity');specialCheck($a['attackEnhancements'][0]['remainingShortRests']===2,'Native rest effect saved');
$state=domainsToApplicationState($db->domains,0);$state=applicationFinishCombatRounds($state,'scene-one',$state['initiative']);specialCheck(count($state['attackEnhancements'])===1,'End combat preserves Boramole');
$r=itemCommandTest($db,'item.effects.rest',['unitIds'=>['character-player'],'kind'=>'short'],false);specialCheck($r->status===403,'Only GM applies rest');$r=itemCommandTest($db,'item.effects.rest',['unitIds'=>['character-player'],'kind'=>'short']);specialCheck($r->status===200,'Short rest: '.json_encode($r->body));specialCheck($db->payload('activity')['attackEnhancements'][0]['remainingShortRests']===1,'One short rest remains');$r=itemCommandTest($db,'item.effects.rest',['unitIds'=>['character-player'],'kind'=>'long']);specialCheck($r->status===200&&$db->payload('activity')['attackEnhancements']===[],'Long rest expires boost');
itemSeed($db,'torch',itemActive([['kind'=>'light','distance'=>8,'color'=>'#ffd36a']]));$r=itemCommandTest($db,'item.consume',['itemId'=>'torch','characterId'=>'character-player','tokenId'=>'token-player','expectedItemRevision'=>1]);specialCheck($r->status===200,'Torch: '.json_encode($r->body));specialCheck(count($db->payload('map:scene-one')['lights'])===1&&$db->payload('map:scene-one')['lights'][0]['portable'],'Native torch map and pickup flag');
itemSeed($db,'vial',itemActive([['kind'=>'message','text'=>'Fiole fictive','durationMs'=>1500,'theme'=>'poison']],['authority'=>'gm','ingredientTag'=>'poison-vial']));$args=['itemId'=>'vial','characterId'=>'character-player','tokenId'=>'token-player','expectedItemRevision'=>1];specialCheck(itemCommandTest($db,'item.consume',$args,false)->status===403,'Vial reserved to GM');$r=itemCommandTest($db,'item.consume',$args);specialCheck($r->status===200,'Native message: '.json_encode($r->body));specialCheck($db->payload('activity')['abilityCueEvents'][0]['completionCue']['visual']['theme']==='poison','Message persisted in activity');
$db=specialFixture();$c=$db->payload('character:character-player');$c['specialSuccessStock']=10;$c['abilities']=[['id'=>'perfide','name'=>'Empoisonneuse fictive','effect'=>'enhancement','formula'=>'0','castingStatId'=>'','manaCost'=>0,'fatigueCost'=>10,'cooldownRounds'=>0,'restRecharge'=>'long','usesPerRest'=>1,'attackEnhancement'=>['mode'=>'on-attack-dot','turns'=>5,'dot'=>['label'=>'Poison mortel','formula'=>'1d20','turns'=>5]]]];$db->put('character:character-player',$c);
$r=specialUse($db,'perfide','token-player');specialCheck($r->status===200,'Native buff cast: '.json_encode($r->body));specialCheck($db->payload('character:character-player')['fatigue']['current']===10,'Activation costs ten fatigue');
$hit=static fn()=>runCommand($db,'token.attack',['requestId'=>'hit-'.randomToken(20),'sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','attackKind'=>'weapon','attackId'=>'main','statId'=>'character-stat-force','opposed'=>false,'useJoker'=>true]);
specialCheck($hit()->status===200,'First hit');specialCheck($hit()->status===200,'Second hit');specialCheck(count($db->payload('activity')['damageOverTime'])===1,'One charge per target per turn');$clock=$db->payload('initiative:scene-one');$clock['turnSerial']++;$db->put('initiative:scene-one',$clock);$hit();specialCheck(count($db->payload('activity')['damageOverTime'])===2,'Next turn adds independent charge');
itemSeed($db,'antidote',itemActive([['kind'=>'cleanse','families'=>['poison']],['kind'=>'immunity','family'=>'poison','turns'=>5]]),'unit_token-monster');$r=itemCommandTest($db,'item.consume',['itemId'=>'antidote','characterId'=>'unit_token-monster','tokenId'=>'token-monster','expectedItemRevision'=>1]);specialCheck($r->status===200,'Native monster antidote: '.json_encode($r->body));specialCheck($db->payload('activity')['damageOverTime']===[],'All poison charges removed');$clock['turnSerial']++;$db->put('initiative:scene-one',$clock);$hit();specialCheck($db->payload('activity')['damageOverTime']===[],'Native immunity prevents later poison');
$activity=$db->payload('activity');$monster=$db->payload('token:scene-one:token-monster');$before=count($activity['attackEnhancements']);applicationActivateAttackEnhancement($activity,['id'=>'late-poison','name'=>'Autre poison','attackEnhancement'=>['mode'=>'on-attack-dot','turns'=>5,'dot'=>['formula'=>'1d20','turns'=>5,'label'=>'Poison']]],$monster,'scene-one',$clock,'late');specialCheck(count($activity['attackEnhancements'])===$before+1,'Offensive poison remains available to an immune bearer');
$rows=applicationEnhancedStatRows(['id'=>'token-player','characterId'=>'character-player','stats'=>[['id'=>'character-stat-mentalResistance','value'=>'1']]],['attackEnhancements'=>[['id'=>'bor','holder'=>'character:character-player','sceneId'=>'old-scene','mode'=>'stats','turns'=>1,'remainingTurns'=>1,'expiry'=>'rests','remainingShortRests'=>2,'bonuses'=>['mentalResistance'=>70]]]],'new-scene',['stats'=>[],'resources'=>['mentalResistance'=>10],'fatigue'=>['current'=>60]]);specialCheck(array_column($rows,'value','id')['character-stat-mentalResistance']==='70','Rest-based mental boost follows the unit across scenes with fatigue');
$c=$db->payload('character:character-player');$c['sheetKind']='notes';$c['publicNotes']='Bloc-notes fictif';specialCheck(validApplicationCharacterDomain($c),'Native notebook schema accepted');$state=domainsToApplicationState($db->domains,0);$state['characterGroups']=[['id'=>'inactive','name'=>'Inactifs','entryIds'=>['character-player']]];$state['characterDirectoryOrder']=['character-player'];$domains=legacyStateToDomains($state);specialCheck($domains['library']['characterGroups'][0]['name']==='Inactifs'&&$domains['library']['characterDirectoryOrder']===['character-player'],'Character categories stored independently of roster and preferences');
$db = specialFixture();
$varskaId = 'character-X4kmpvnbF9rQgNGc';
$sourceKey = 'token:scene-one:token-player';
$targetKey = 'token:scene-one:token-monster';
$paw = ['id'=>'weapon-2', 'name'=>'Pattes acérées empoisonnées', 'formula'=>'1d2+8', 'damageType'=>'physical',
    'naturalWeapon'=>true, 'onHitConditions'=>['Paralysé','Désarmé']];
$varska = [...$db->payload('character:character-player'), 'id'=>$varskaId, 'specialSuccessStock'=>10,
    'weaponText'=>$paw['name'].' / '.$paw['formula'], 'weaponAttacks'=>[$paw], 'abilities'=>[
        ['id'=>'perfidious-test', 'name'=>'Empoisonneuse perfide', 'effect'=>'enhancement', 'formula'=>'0', 'castingStatId'=>'',
            'attackEnhancement'=>['mode'=>'on-attack-dot', 'turns'=>5, 'trigger'=>'melee-weapon', 'weaponIds'=>['weapon-2'],
                'dot'=>['label'=>'Poison mortel','formula'=>'1d20','damageType'=>'poison','turns'=>5]]],
    ]];
$db->put('character:'.$varskaId, $varska);
$db->put($sourceKey, synchronizeOnlineCharacterToken([...$db->payload($sourceKey), 'characterId'=>$varskaId], $varska));
$db->put('initiative:scene-one', [...$db->payload('initiative:scene-one'), 'active'=>false]);
$db->put($targetKey, [...$db->payload($targetKey), 'conditions'=>['Paralysé'], 'elementalResistances'=>['poison'=>100]]);
specialCheck(specialUse($db, 'perfidious-test', 'token-player')->status === 200, 'Varska’s offensive poison activates.');
$pawStrike = static function() use ($db): TestResponse {
    $hit = runCommand($db, 'token.attack', ['requestId'=>'paw-hit-'.randomToken(20), 'sceneId'=>'scene-one',
        'sourceTokenId'=>'token-player', 'targetTokenId'=>'token-monster', 'attackKind'=>'weapon', 'attackId'=>'weapon-2',
        'statId'=>'character-stat-force', 'opposed'=>false, 'useJoker'=>true], true, 'account-gm');
    if (($hit->body['attack']['status'] ?? '') !== 'pending') return $hit;
    return runCommand($db, 'token.attack.resolve', ['attackId'=>$hit->body['attack']['id'], 'decision'=>'approve',
        'confirmed'=>true], true, 'account-gm');
};
$immuneStrike = $pawStrike();
$immuneTarget = $db->payload($targetKey);
specialCheck($immuneStrike->status === 200 && $immuneTarget['hp'] < 500
    && $immuneTarget['conditions'] === ['Paralysé','Désarmé'] && empty($db->payload('activity')['damageOverTime']),
    'At 100% poison the physical hit and unrelated states still apply, with no venom status or DOT.');
$immuneTarget['elementalResistances']['poison'] = 99;
$db->put($targetKey, $immuneTarget);
$partialStrike=$pawStrike();
$save=array_values(array_filter($db->payload('activity')['rolls'],static fn(array $roll):bool=>
    ($roll['label']??'')==='Résistance · '.XAR_VENOM_PARALYSIS_LABEL));
$dotSave=array_values(array_filter($db->payload('activity')['rolls'],static fn(array $roll):bool=>
    ($roll['label']??'')==='Résistance · Poison mortel'));
specialCheck($partialStrike->status === 200 && count($save)===1 && ($save[0]['outcome']['threshold']??null)===99
    && in_array(XAR_VENOM_PARALYSIS_LABEL, $db->payload($targetKey)['conditions'], true)===($save[0]['total']===100)
    && count($dotSave)===1 && ($dotSave[0]['outcome']['threshold']??null)===99
    && (($db->payload('activity')['damageOverTime'][0]['damageType']??'') === 'poison')===($dotSave[0]['total']===100),
    'At 99% outside combat venom paralysis and the poison DOT each receive their own roll.');
itemSeed($db, 'venom-antidote', itemActive([['kind'=>'cleanse','families'=>['poison']],['kind'=>'immunity','family'=>'poison','turns'=>5]]),
    'unit_token-monster');
specialCheck(itemCommandTest($db, 'item.consume', ['itemId'=>'venom-antidote', 'characterId'=>'unit_token-monster',
    'tokenId'=>'token-monster', 'expectedItemRevision'=>1])->status === 200, 'The antidote can cleanse the venom affliction.');
specialCheck($pawStrike()->status === 200 && $db->payload($targetKey)['conditions'] === ['Paralysé','Désarmé']
    && $db->payload('activity')['damageOverTime'] === []
    && in_array('on-attack-dot', array_column($db->payload('activity')['attackEnhancements'], 'mode'), true),
    'The antidote cleanses and blocks venom while preserving other paralysis and Varska’s buff.');
specialCheck(applicationWeaponPoisonConditions($paw, ['id'=>'different-unit']) === ['Paralysé','Désarmé'],
    'Unrelated weapon-2 attacks do not gain a venom status.');

$db = specialFixture();
$halberd = ['id'=>'weapon-1','name'=>'Hallebarde','formula'=>'1d2+3','damageType'=>'physical','reach'=>'reach'];
$varska['weaponAttacks'] = [$halberd, $paw];
$varska['weaponText'] = $halberd['name'].' / '.$halberd['formula']."\n".$paw['name'].' / '.$paw['formula'];
$varska['abilities'][0]['restRecharge'] = 'long';
$varska['abilities'][0]['usesPerRest'] = 1;
$db->put('character:'.$varskaId, $varska);
$db->put($sourceKey, synchronizeOnlineCharacterToken([...$db->payload($sourceKey), 'characterId'=>$varskaId], $varska));
specialCheck(specialUse($db, 'perfidious-test', 'token-player')->status === 200, 'Varska activates in battle.');

$strike = static function(string $weaponId) use ($db): void {
    $hit = runCommand($db, 'token.attack', ['requestId'=>'venom-rounds-'.randomToken(20),'sceneId'=>'scene-one',
        'sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','attackKind'=>'weapon','attackId'=>$weaponId,
        'statId'=>'character-stat-force','opposed'=>false,'useJoker'=>true], true, 'account-gm');
    if (($hit->body['attack']['status'] ?? '') === 'pending') {
        $hit = runCommand($db, 'token.attack.resolve', ['attackId'=>$hit->body['attack']['id'],'decision'=>'approve',
            'confirmed'=>true], true, 'account-gm');
    }
    specialCheck($hit->status === 200 && ($hit->body['attack']['appliedDamage'] ?? 0) > 0,
        'Venom test resolves a real hit. '.json_encode([$hit->status,$hit->body['code']??null,$hit->body['error']??null,$hit->body['attack']['status']??null]));
};
$dots = static fn(): array => $db->payload('activity')['damageOverTime'] ?? [];
$turns = static function() use ($dots): array {
    $values = array_column($dots(), 'remainingTurns');
    sort($values);
    return $values;
};
$buff = static fn(): array => $db->payload('activity')['attackEnhancements'][0] ?? [];
$changeClock = static function(array $change) use ($db): void {
    $records = applicationDomainRecords($db);
    $pending = [];
    $identity = ['id'=>'account-gm','display_name'=>'MJ','effective_mode'=>'gm','permanent_role'=>'gm'];
    queueOnlineDomainUpsert($pending, $records, 'initiative:scene-one', [...$db->payload('initiative:scene-one'), ...$change]);
    applyApplicationDamageOverTimeOnTurn($db, $records, $pending, $identity);
    foreach (onlineAdvanceDevicesOnChanges($db, $records, array_values($pending), $identity) as $entry) {
        $db->put($entry['key'], $entry['payload']);
    }
};

$strike('weapon-1');
specialCheck($dots() === [], 'Halberd never transfers the buff.');
$strike('weapon-2');
specialCheck($turns() === [5], 'The paw hit produces one charge in combat.');
$firstDotId = $dots()[0]['id'];
$buffId = $buff()['id'];

$changeClock(['active'=>false,'turnsStarted'=>false,'combatId'=>'','order'=>[]]);
specialCheck($turns() === [5] && $dots()[0]['id'] === $firstDotId && $buff()['id'] === $buffId
    && $buff()['remainingTurns'] === 5, 'Ending combat preserves remaining charges and the buff.');
$strike('weapon-2');
$strike('weapon-2');
specialCheck($turns() === [5,5], 'Free play admits only one new charge in the interval.');
$health = $db->payload($targetKey)['hp'];
specialCheck(specialMechanics($db, 'scene.advance', [])->status === 200, 'Advance in free play.');
specialCheck($turns() === [4,4] && $buff()['remainingTurns'] === 4 && $db->payload($targetKey)['hp'] < $health, 'Free turns tick each charge and the buff.');
$strike('weapon-2');
specialCheck($turns() === [4,4,5], 'The next interval accepts a new charge without resetting existing ones.');

$changeClock(['active'=>true,'turnsStarted'=>true,'combatId'=>'special-venom-next','order'=>['token-player','token-monster'],'currentIndex'=>0,'turnSerial'=>11]);
specialCheck($turns() === [4,4,5] && $buff()['remainingTurns'] === 4, 'Starting a new combat does not tick the poison.');
$strike('weapon-2');
specialCheck($turns() === [4,4,5,5], 'A paw strike applies a new charge in the restarted combat.');
$changeClock(['currentIndex'=>1,'turnSerial'=>12]);
specialCheck($turns() === [3,3,4,4], 'Each charge ticks on the victim’s next combat turn.');

fwrite(STDOUT,json_encode(['ok'=>true,'checks'=>$specialChecks],JSON_UNESCAPED_UNICODE)."\n");
