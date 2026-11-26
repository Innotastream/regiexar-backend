<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);
require __DIR__.'/tactical-special-effects.php';
$specialChecks=0;

$db=specialFixture();
$varskaId='character-X4kmpvnbF9rQgNGc';
$varskaToken='token-varska';
$varskaKey='token:scene-one:'.$varskaToken;
$varska=[...$db->payload('character:character-player'),'id'=>$varskaId,'ownerPlayerId'=>'account-varska','name'=>'Varska',
    'resources'=>['hp'=>50,'maxHp'=>100,'mana'=>0,'maxMana'=>0]];
$db->put('character:'.$varskaId,$varska);
$donor=synchronizeOnlineCharacterToken([...$db->payload('token:scene-one:token-player'),'id'=>$varskaToken,'characterId'=>$varskaId,'x'=>58,'y'=>50],$varska);
$db->put($varskaKey,$donor);
$db->put('token-index:scene-one',['order'=>['token-player','token-monster',$varskaToken]]);
$targetKey='token:scene-one:token-monster';
$db->put($targetKey,[...$db->payload($targetKey),'x'=>50,'y'=>50,'frameVariant'=>'creature',
    'stats'=>[['id'=>'character-stat-force','label'=>'Force','value'=>'80'],['id'=>'character-stat-agility','label'=>'Agilité','value'=>'80'],
    ['id'=>'character-stat-dexterity','label'=>'Dextérité','value'=>'80'],['id'=>'character-stat-intelligence','label'=>'Intelligence','value'=>'80']]]);
$player=$db->payload('character:character-player');$player['specialSuccessStock']=30;
$player['weaponAttacks']=[['id'=>'main','name'=>'Sabre','formula'=>'1d2+4','damageType'=>'physical','reach'=>'melee'],
    ['id'=>'other','name'=>'Épée','formula'=>'1d2+4','damageType'=>'physical','reach'=>'reach'],
    ['id'=>'arrow','name'=>'Arc','formula'=>'1d2+4','damageType'=>'physical','projectile'=>true,'reach'=>'ranged']];
$player['weaponText']="Sabre / 1d2+4\nÉpée / 1d2+4\nArc / 1d2+4";
$db->put('character:character-player',$player);
$db->put('token:scene-one:token-player',synchronizeOnlineCharacterToken($db->payload('token:scene-one:token-player'),$player));
$input=['sceneId'=>'scene-one','layerId'=>'ground','sourceTokenId'=>'token-player','targetTokenId'=>$varskaToken,'weaponAttackId'=>'main'];
$coat=static fn(array $extra=[]):TestResponse=>specialMechanics($db,'varska.drow-coat',[...$input,...$extra],false);
$strike=static function(string $weapon='main',array $extra=[])use($db):TestResponse {
    return runCommand($db,'token.attack',['requestId'=>'drow-attack-'.randomToken(16),'sceneId'=>'scene-one','sourceTokenId'=>'token-player',
        'targetTokenId'=>'token-monster','attackKind'=>'weapon','attackId'=>$weapon,'statId'=>'character-stat-force','opposed'=>false,
        'useJoker'=>true,...$extra],true,'account-gm');
};
$first=$coat(['requestId'=>'drow-coat-test-0001']);
specialCheck($first->status===200&&!empty($first->body['drowCoating']), 'Player can coat an eligible weapon next to Varska: '.json_encode($first->body));
specialCheck($coat(['requestId'=>'drow-coat-test-0001'])->body['deduplicated']===true, 'Retry of the coat is idempotent');
specialCheck($coat(['weaponAttackId'=>'arrow'])->status===200 && $coat(['targetTokenId'=>'token-monster'])->status===409,
    'Both real weapon types can be coated, but only Varska supplies the poison');
$otherAttack=$strike('other');
specialCheck($otherAttack->status===200 && count($db->payload('devices')['drowCoatings'])===2,
    'A different weapon does not consume the coating: '.json_encode([$otherAttack->body,$db->payload('devices')['drowCoatings']]));
$firstAttack=$strike();
specialCheck($firstAttack->status===200 && array_column($db->payload('devices')['drowCoatings']??[],'weaponAttackId')===['arrow'],
    'The authorized attack consumes the coating: '.json_encode($firstAttack->body));
$condition=$db->payload('devices')['timedConditions'][0];
specialCheck($condition['drowPoisonStacks']===1 && $condition['remainingTurns']===5 && $condition['label']==='Fatigue musculaire',
    'A real hit applies the first five-turn Drow state');
$names=['Fatigue musculaire','Relâchement nerveux','Paralysie partielle','Insensibilité des membres','Paralysie complète'];
for($i=1;$i<=5;$i++){
    $store=$db->payload('devices');$store['timedConditions'][0]['remainingTurns']=2;$db->put('devices',$store);
    specialCheck($coat()->status===200 && $strike()->status===200,'Repeat authorized Drow hit');
    $entry=$db->payload('devices')['timedConditions'][0];
    $stack=min(5,$i+1);$state=$db->payload('token:scene-one:token-monster');
    specialCheck($entry['id']===$condition['id'] && $entry['drowPoisonStacks']===$stack && $entry['remainingTurns']===5
        && $entry['label']===$names[$stack-1] && $entry['blocksActions']===($stack===5)
        && array_values(array_intersect($names,$state['conditions']))===[$names[$stack-1]], 'Stacks replace the prior label and refresh at cap: '.json_encode([$condition,$entry,$state['conditions']]));
}
$target=$db->payload($targetKey);
$stats=array_column(applicationEnhancedStatRows($target,['devices'=>$db->payload('devices')],'scene-one'), 'value','id');
specialCheck($stats['character-stat-force']==='60' && $stats['character-stat-agility']==='60' && $stats['character-stat-dexterity']==='60'
    && $stats['character-stat-intelligence']==='80','The timed poison reduces only the three requested characteristics');
$db->put($targetKey,[...$target,'frameVariant'=>'boss']);
specialCheck($coat()->status===200 && $strike()->status===200
    && $db->payload('devices')['timedConditions'][0]['drowPoisonStacks']===4
    && !$db->payload('devices')['timedConditions'][0]['blocksMovement'],'A boss is capped at Insensibilité des membres');

$advance=static function(array $changes)use($db):void{
    $records=applicationDomainRecords($db);$pending=[];
    $identity=['id'=>'account-gm','display_name'=>'MJ','permanent_role'=>'gm','effective_mode'=>'gm'];
    queueOnlineDomainUpsert($pending,$records,'initiative:scene-one',[...$db->payload('initiative:scene-one'),...$changes]);
    applyApplicationDamageOverTimeOnTurn($db,$records,$pending,$identity);
    foreach(onlineAdvanceDevicesOnChanges($db,$records,array_values($pending),$identity) as $change)$db->put($change['key'],$change['payload']);
};
$db->put($targetKey,[...$db->payload($targetKey),'elementalResistances'=>['poison'=>99]]);
$devices=$db->payload('devices');
$devices['timedConditions'][]=[...$devices['timedConditions'][0],'id'=>'condition-other-venom','label'=>'Venin de guêpe',
    'drowPoisonStacks'=>0,'family'=>'poison','sourceAbilityId'=>'venom-wasp'];
$db->put('devices',$devices);
$activity=$db->payload('activity');
$dot=['sceneId'=>'scene-one','combatId'=>'special-test','targetTokenId'=>'token-monster',
    'label'=>'Venin source A','formula'=>'1d2','damageType'=>'poison','remainingTurns'=>5,'lastTurnSerial'=>10,'sourceAbilityId'=>'stack-source-a'];
$activity['damageOverTime']=[
    [...$dot,'id'=>'dot-a1'],[...$dot,'id'=>'dot-a2'],
    [...$dot,'id'=>'dot-b','label'=>'Venin source B','sourceAbilityId'=>'other-poison-b'],
];
$db->put('activity',$activity);
$advance(['currentIndex'=>1,'turnSerial'=>11,'turnsStarted'=>true]);
$resistanceRolls=array_column(array_filter($db->payload('activity')['rolls'],
    static fn($row)=>str_starts_with($row['label']??'', 'Résistance · ')),null,'label');
specialCheck(count($resistanceRolls)===4 && isset($resistanceRolls['Résistance · Poison drow'],$resistanceRolls['Résistance · Venin source A'],
    $resistanceRolls['Résistance · Venin source B'],$resistanceRolls['Résistance · Venin de guêpe']),
    'The unit rolls once per distinct poison, including non-damaging ones, regardless of stacks');
$remaining=array_column($db->payload('activity')['damageOverTime'],'id');
specialCheck((in_array('dot-a1',$remaining,true) && in_array('dot-a2',$remaining,true)) === ($resistanceRolls['Résistance · Venin source A']['total']===100)
    && in_array('dot-b',$remaining,true)===($resistanceRolls['Résistance · Venin source B']['total']===100),
    'A successful save removes every stack of that poison and does not affect a different failed save');
$remainingConditions=array_column($db->payload('devices')['timedConditions'],'id');
specialCheck(in_array('condition-other-venom',$remainingConditions,true)===($resistanceRolls['Résistance · Venin de guêpe']['total']===100),
    'A generic timed poison clears independently on its own successful save');
$db->put($targetKey,[...$db->payload($targetKey),'elementalResistances'=>['poison'=>0]]);
$advance(['currentIndex'=>0,'turnSerial'=>12]);
specialCheck($coat()->status===200 && $strike()->status===200,'Reapply the drow condition before leaving combat');
$drowOnly=static fn(array $entry):bool=>!empty($entry['drowPoisonStacks']);
$beforeEnd=array_values(array_filter($db->payload('devices')['timedConditions'],$drowOnly))[0];
$advance(['active'=>false,'turnsStarted'=>false]);
$afterEnd=array_values(array_filter($db->payload('devices')['timedConditions'],$drowOnly))[0];
specialCheck($afterEnd['id']===$beforeEnd['id'] && $afterEnd['remainingTurns']===$beforeEnd['remainingTurns'],
    'Leaving combat keeps the Drow condition and its remaining duration');
$db->put($targetKey,[...$db->payload($targetKey),'elementalResistances'=>['poison'=>100]]);
specialCheck(specialMechanics($db,'scene.advance',[])->status===200&&$db->payload('devices')['timedConditions']===[],
    'A free turn checks poison resistance and cleanses the Drow status');
$db->put($targetKey,[...$db->payload($targetKey),'elementalResistances'=>['poison'=>0]]);
$pendingShot=$strike('arrow');
specialCheck($pendingShot->status===200 && ($pendingShot->body['attack']['status']??'')==='pending'
    && empty($db->payload('devices')['drowCoatings']) && empty($db->payload('devices')['timedConditions']),
    'The accepted shot consumes its own coating and leaves poison pending until resolved: '.json_encode($pendingShot->body));
$approved=runCommand($db,'token.attack.resolve',['attackId'=>$pendingShot->body['attack']['id'],
    'decision'=>'approve','confirmed'=>true],true,'account-gm');
specialCheck($approved->status===200&&($db->payload('devices')['timedConditions'][0]['drowPoisonStacks']??null)===1,
    'Confirming the ranged hit applies one stage, exactly once: '.json_encode($approved->body));

$records=applicationDomainRecords($db);$pending=[];
$mixed=['damageComponents'=>[['type'=>'physical','formula'=>'8'],['type'=>'poison','formula'=>'15']]];
$summary=onlineRollAttackDamage($mixed,$donor)['damage'];
$health=applyOnlineAttackDamage($db,$records,$pending,$varskaKey,$donor,$summary['finalDamage'],true,$summary);
specialCheck(($summary['poisonHealing']??null)===15 && $health['appliedDamage']===8 && $health['appliedHealing']===15
    && $pending['character:'.$varskaId]['payload']['resources']['hp']===57, 'Toxic Body heals the poison component and keeps physical damage independent');
specialCheck(applicationBaseElementalResistances([...$donor,'elementalResistances'=>['poison'=>65]])['poison']===0,
    'Toxic Body leaves base poison resistance at zero');
$potion=normalizeItemStore(['version'=>1,'templates'=>[['id'=>'sample-venom','name'=>'Potion de poison','kind'=>'potion',
    'potion'=>['hp'=>-9]]],'instances'=>[]])['templates'][0];
specialCheck($potion['potion']['hp']===0 && $potion['active']['effects'][0]['formula']==='1d20'
    && $potion['active']['effects'][0]['turns']===5, 'A common poison potion becomes a five-turn poison DOT');
$activity=$db->payload('activity');
$activity['damageOverTime'][]=[...$dot,'id'=>'dot-toxic-body','combatId'=>'','targetTokenId'=>$varskaToken,'formula'=>'1d2+8','lastManualTurn'=>0];
$db->put('activity',$activity);
specialCheck(specialMechanics($db,'scene.advance',[])->status===200,'A free turn advances the poison on Varska');
$healedHp=$db->payload('character:'.$varskaId)['resources']['hp'];
specialCheck($healedHp>=59 && $healedHp<=60,'A periodic source heals at the poison dice result in free play');
$mania=['id'=>'character-amxWfdDZFvFZjLzT','resources'=>['hp'=>50,'maxHp'=>100]];
specialCheck(applicationBaseElementalResistances($mania)['poison']===25
    && applicationPassiveEffectTurns($mania,5,['family'=>'poison'])===4
    && applicationPassiveEffectTurns($mania,1,['family'=>'poison'])===1
    && applicationPassiveDamageReduction($mania,20,['family'=>'poison','sourceItemKind'=>'potion'])['damage']===20
    && applicationPassiveDamageReduction($mania,20,['family'=>'other','sourceItemKind'=>'potion'])['damage']===15,'Mania has one poison resistance and a one-turn floor, keeping the other potion passive');
fwrite(STDOUT,json_encode(['ok'=>true,'checks'=>$specialChecks],JSON_UNESCAPED_UNICODE).PHP_EOL);
