<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);
require __DIR__.'/tactical-special-effects.php';

function requireGmIdentity(PDO $connection): array
{
    $identity = $GLOBALS['testIdentity'];
    if (($identity['effective_mode'] ?? '') !== 'gm') sendError(403, 'MJ requis');
    return $identity;
}

function bloodClockPatch(MemoryConnection $db, string $sceneId, array $fields): TestResponse
{
    $key = 'initiative:' . $sceneId;
    $GLOBALS['testIdentity'] = ['id'=>'account-gm','display_name'=>'MJ fictif','effective_mode'=>'gm','permanent_role'=>'gm'];
    $GLOBALS['testBody'] = ['stateSchemaVersion'=>XAR_SESSION_SCHEMA_VERSION,'domainSchemaVersion'=>XAR_DOMAIN_SCHEMA_VERSION,
        'changes'=>[['key'=>$key,'operation'=>'upsert','expectedRevision'=>$db->domains[$key]['revision'],
            'payload'=>[...$db->payload($key),...$fields]]]];
    try { patchApplicationDomains($db); } catch (TestResponse $response) { return $response; }
    throw new RuntimeException('No domain response');
}

$db=specialFixture();$id='ability-emosphere-6a5b9f30-448f-4096-9c4b-49e02941ead2';
$character=$db->payload('character:character-player');
$character['abilities']=[['id'=>$id,'name'=>'Éveil fictif','effect'=>'complex','formula'=>'0','castingStatId'=>'','fatigueCost'=>5,'noDefensePossible'=>true,'workflow'=>['version'=>8,'steps'=>[
    ['id'=>'orbs','type'=>'counter','initial'=>2,'maximum'=>3,'gainTrigger'=>'none','spendOnSourceTurn'=>false,'spendOptions'=>[
        ['id'=>'guard','label'=>'Protection','cost'=>1,'effect'=>['kind'=>'guard','percent'=>25,'trigger'=>'damage','persistAfterEnd'=>true]]
    ]]
]]]];
$db->put('character:character-player',$character);
$request=['action'=>'start','requestId'=>'blood-start-'.randomToken(20),'sceneId'=>'scene-one','sourceTokenId'=>'token-player','abilityId'=>$id];
$started=runCommand($db,'ability.complex',$request);
specialCheck($started->status===200,'Blood activation starts: '.json_encode($started->body));
$execution=$db->payload('activity')['abilityExecutions'][0];
$awakenings=static fn()=>array_values(array_filter($db->payload('activity')['abilityCueEvents']??[],static fn($e)=>($e['completionCue']['spell']['presetId']??'')==='arvin-blood-invocation'));
specialCheck(count($awakenings())===1,'Initial activation produces exactly one blood ascent');
specialCheck($awakenings()[0]['id']==='activation-'.$execution['id'],'Activation event has a stable execution identity');
specialCheck($awakenings()[0]['completionCue']['spell']['placement']==='source','Blood climbs on the actual caster');
specialCheck($awakenings()[0]['completionCue']['spell']['followSource']===true,'Blood follows the caster footprint');
$fatigue=$db->payload('character:character-player')['fatigue']['current'];
specialCheck(runCommand($db,'ability.complex',$request)->status===200&&count($awakenings())===1,'Replay never duplicates activation');
$spent=runCommand($db,'ability.complex',['action'=>'command','requestId'=>'blood-spend-'.randomToken(20),'executionId'=>$execution['id'],'expectedRevision'=>$execution['revision'],'command'=>['action'=>'counter-spend','optionId'=>'guard','targetTokenId'=>'token-monster','opposed'=>false]]);
specialCheck($spent->status===200,'An orb can still be spent normally: '.json_encode($spent->body));
specialCheck(count($awakenings())===1,'Spending an orb does not replay the activation');
specialCheck($db->payload('activity')['abilityExecutions'][0]['stepStates']['orbs']['value']===1,'One orb is consumed exactly once');
specialCheck($db->payload('character:character-player')['fatigue']['current']===$fatigue,'The visual spends no additional fatigue');

$db = specialFixture();
$request['requestId'] = 'blood-free-' . randomToken(20);
$initiative = $db->payload('initiative:scene-one');
$db->put('initiative:scene-one', [...$initiative,'active'=>false]);
$db->put('scene-index', ['order'=>['scene-one','scene-two']]);
$db->put('initiative:scene-two', ['active'=>false,'turnSerial'=>0,'order'=>[],'currentIndex'=>0,'round'=>1]);
$character = $db->payload('character:character-player');
$ability = ['id'=>$id,'name'=>'Hémo-sphère flottante','effect'=>'complex','castingStatId'=>'','formula'=>'0',
    'fatigueCost'=>15,'restRecharge'=>'long','usesPerRest'=>1,'cooldownRounds'=>3,
    'workflow'=>['version'=>8,'steps'=>[[
        'id'=>'emosphere-orbes','type'=>'counter','initial'=>0,'maximum'=>3,'radiusCells'=>4,'visual'=>'orbs',
        'combatPersistent'=>true,'gainTrigger'=>'hp-loss-or-condition','gainCondition'=>'Hémorragie','spendOnSourceTurn'=>true,
        'spendOptions'=>[['id'=>'shield','label'=>'Durcir','cost'=>1,'effect'=>['kind'=>'guard','percent'=>25,'trigger'=>'damage','persistAfterEnd'=>true]],
            ['id'=>'burst','label'=>'Explosion','cost'=>1,'effect'=>['kind'=>'damage','formula'=>'1d2','damageType'=>'physical']]],
    ]]]];
$character['abilities'] = [$ability,[...$ability,'id'=>'combat-only-counter']];
$db->put('character:character-player', $character);
$monster = $db->payload('token:scene-one:token-monster');
$db->put('token:scene-one:token-monster', [...$monster,'conditions'=>['Hémorragie']]);
$otherCounter = runCommand($db, 'ability.complex', [...$request,'abilityId'=>'combat-only-counter','requestId'=>'blood-other-' . randomToken(20)]);
specialCheck($otherCounter->status === 409 && ($otherCounter->body['code'] ?? '') === 'complex_ability_combat_required',
    'Other persistent counters still require combat');
$started = runCommand($db, 'ability.complex', $request);
specialCheck($started->status === 200 && ($started->body['cast']['cooldownRounds'] ?? null) === 0,
    'Legacy sheet can activate without a round cooldown in free mode: ' . json_encode($started->body));
$readExecution = static fn() => $db->payload('activity')['abilityExecutions'][0];
specialCheck($readExecution()['combatId'] === '' && $readExecution()['status'] === 'active', 'The stale combat ID is ignored');
specialCheck($db->payload('character:character-player')['fatigue']['current'] === 15, 'Activation pays 15 fatigue once');
specialCheck((runCommand($db, 'ability.complex', $request)->body['deduplicated'] ?? false) && count($awakenings()) === 1,
    'A replay in free mode does not repeat the launch or the cinematic');
$alreadyActive = runCommand($db, 'ability.complex', [...$request,'requestId'=>'blood-again-' . randomToken(20)]);
specialCheck($alreadyActive->status === 409 && ($alreadyActive->body['code'] ?? '') === 'complex_ability_already_active',
    'A new request cannot create a second precombat activation');
$wound = runCommand($db, 'token.resource.adjust', ['sceneId'=>'scene-one','tokenId'=>'token-monster','resource'=>'hp','delta'=>-1,
    'requestId'=>'blood-wound-' . randomToken(20)], true, 'account-gm');
$orbCount = static fn() => $readExecution()['stepStates']['emosphere-orbes']['value'];
specialCheck($wound->status === 200 && $orbCount() === 1, 'An actual wound under Hémorragie grants a charge in free mode: '.json_encode([$wound->body,$orbCount()]));
$assisted = new MemoryConnection([]);
$assisted->domains = $db->domains;
$addOrb = static fn(array $execution, string $requestId): array => [
    'action'=>'command','executionId'=>$execution['id'],'expectedRevision'=>$execution['revision'],
    'requestId'=>$requestId,'command'=>['action'=>'counter-gain'],
];
$gain = $addOrb($readExecution(), 'blood-mj-grant-0001');
$beforeGrant = $assisted->payload('activity');
$denied = runCommand($assisted, 'ability.complex', $gain);
specialCheck($denied->status === 403 && ($denied->body['code'] ?? '') === 'complex_ability_orb_grant_forbidden'
    && $assisted->payload('activity') === $beforeGrant, 'The character owner cannot grant themselves an orb.');
$beforeResources = $assisted->payload('character:character-player');
$granted = runCommand($assisted, 'ability.complex', $gain, true, 'account-gm');
$orbState = $granted->body['execution']['stepStates']['emosphere-orbes'] ?? [];
$lastGain = $orbState['events'][array_key_last($orbState['events'] ?? [])] ?? [];
specialCheck($granted->status === 200 && ($orbState['value'] ?? null) === 2
    && ($lastGain['type'] ?? '') === 'gain' && ($lastGain['amount'] ?? 0) === 1 && ($lastGain['actorId'] ?? '') === 'account-gm',
    'The effective MJ adds one ordinary orb to an active sphere in free mode.');
specialCheck($assisted->payload('character:character-player') === $beforeResources
    && $assisted->payload('activity')['actionTimers'] === $beforeGrant['actionTimers']
    && $assisted->payload('activity')['abilityCueEvents'] === $beforeGrant['abilityCueEvents'],
    'Granting uses no resources, timer, or new activation cinematic.');
$again = runCommand($assisted, 'ability.complex', $gain, true, 'account-gm');
specialCheck($again->status === 200 && ($again->body['deduplicated'] ?? false)
    && $assisted->payload('activity')['abilityExecutions'][0]['stepStates']['emosphere-orbes']['value'] === 2,
    'Replaying a grant never creates another orb.');
$stale = runCommand($assisted, 'ability.complex', [...$gain,'requestId'=>'blood-mj-stale-0001'], true, 'account-gm');
specialCheck($stale->status === 409 && ($stale->body['code'] ?? '') === 'complex_ability_revision_conflict',
    'A competing click must use the latest orb count.');
$third = runCommand($assisted, 'ability.complex', $addOrb($granted->body['execution'], 'blood-mj-third-0001'), true, 'account-gm');
$capped = runCommand($assisted, 'ability.complex', $addOrb($third->body['execution'], 'blood-mj-cap-0001'), true, 'account-gm');
specialCheck($third->status === 200 && $capped->status === 409 && ($capped->body['code'] ?? '') === 'complex_ability_counter_maximum'
    && $assisted->payload('activity')['abilityExecutions'][0]['stepStates']['emosphere-orbes']['value'] === 3,
    'The configured maximum remains authoritative.');

$clone = [...$assisted->payload('token:scene-one:token-player'),'id'=>'token-arvin-clone','followCharacter'=>false,
    'cloneSourceCharacterId'=>'character-player','abilities'=>[$ability],'fatigue'=>['current'=>0,'max'=>150]];
$assisted->put('token:scene-one:token-arvin-clone',$clone);
$tokenIndex = $assisted->payload('token-index:scene-one');
$assisted->put('token-index:scene-one',['order'=>[...$tokenIndex['order'],'token-arvin-clone']]);
$cloneCast = runCommand($assisted, 'ability.complex', [...$request,'requestId'=>'blood-clone-start-0001','sourceTokenId'=>'token-arvin-clone']);
specialCheck($cloneCast->status === 200 && ($cloneCast->body['execution']['sourceTokenId'] ?? '') === 'token-arvin-clone',
    'A clone can activate its own sphere: '.$cloneCast->getMessage());
$cloneGrant = runCommand($assisted, 'ability.complex', $addOrb($cloneCast->body['execution'], 'blood-clone-grant-0001'), true, 'account-gm');
$ownExecutions = array_column($assisted->payload('activity')['abilityExecutions'],null,'sourceTokenId');
specialCheck($cloneGrant->status === 200 && $ownExecutions['token-arvin-clone']['stepStates']['emosphere-orbes']['value'] === 1
    && $ownExecutions['token-player']['stepStates']['emosphere-orbes']['value'] === 3
    && $assisted->payload('token:scene-one:token-arvin-clone')['fatigue']['current'] === 15,
    'Granting to the independent clone changes only the clone sphere and charges no extra fatigue.');

$orbRequest = static function (string $option, bool $opposed = false, string $mode = '') use ($readExecution): array {
    $execution = $readExecution();
    return ['action'=>'command','executionId'=>$execution['id'],'expectedRevision'=>$execution['revision'],
        'requestId'=>'blood-free-spend-' . randomToken(20),
        'command'=>['action'=>'counter-spend','optionId'=>$option,'targetTokenId'=>'token-monster',
            'opposed'=>$opposed,...($mode === '' ? [] : ['oppositionMode'=>$mode])]];
};
$useOrb = static fn(string $option): TestResponse => runCommand($db, 'ability.complex', $orbRequest($option));
$orbAttack = static function (TestResponse $response) use ($db): array {
    $attackId = $response->body['chargeEffect']['attackId'] ?? '';
    foreach ($db->payload('activity')['attackReceipts'] ?? [] as $receipt) {
        if (($receipt['attack']['id'] ?? '') === $attackId) return $receipt['attack'];
    }
    return [];
};
$snapshot = static fn(): array => [$db->domains,$db->revision,$db->mediaObjects,$db->resourceReceiptLedger,$db->healthOverlays];
$restore = static function (array $state) use ($db): void {
    [$db->domains,$db->revision,$db->mediaObjects,$db->resourceReceiptLedger,$db->healthOverlays] = $state;
};
$testOrbOutcome = static function (string $code, bool $opposed = false, string $mode = '') use ($db,$orbRequest,$orbAttack,$snapshot,$restore): array {
    $before = $snapshot();
    for ($attempt = 0; $attempt < 400; $attempt++) {
        $request = $orbRequest('burst',$opposed,$mode);
        $response = runCommand($db,'ability.complex',$request);
        if ($response->status !== 200) throw new RuntimeException('Offensive orb launch failed: ' . json_encode($response->body));
        $attack = $orbAttack($response);
        if (($attack['hit']['outcome']['code'] ?? '') === $code) return [$response,$request,$attack];
        $restore($before);
    }
    throw new RuntimeException('Offensive orb did not reach roll outcome ' . $code);
};
$legacy = $readExecution();
unset($legacy['rulePreset'], $legacy['workflow']['steps'][0]['spendOutsideCombat']);
$legacy['workflow']['steps'][0]['spendOnSourceTurn'] = true;
$legacy['workflow']['steps'][0]['spendOptions'][1]['effect']['formula'] = '1d2';
$db->put('activity', [...$db->payload('activity'), 'abilityExecutions'=>[$legacy]]);
$spent = $useOrb('shield');
specialCheck($spent->status === 200 && $orbCount() === 0 && $spent->body['chargeEffect']['percent'] === 25,
    'A legacy active counter uses the configured protection in free mode');
$guards = $db->payload('activity')['nextAttackGuards'];
specialCheck(count($guards) === 1 && $guards[0]['effectId'] === 'protection' && $guards[0]['combatId'] === '',
    'The existing status protection starts without fabricating combat');
$crystals = array_values(array_filter($db->payload('activity')['abilityCueEvents'] ?? [], static fn($event) =>
    ($event['completionCue']['spell']['presetId'] ?? '') === 'blood-crystal'));
$visual = $crystals[count($crystals)-1]['completionCue']['spell'] ?? [];
specialCheck(($visual['source']['x'] ?? -1) == $db->payload('token:scene-one:token-player')['x']
    && ($visual['targetPoint']['x'] ?? -1) == $db->payload('token:scene-one:token-monster')['x'],
    'The crystal event carries the real caster and destination points: ' . json_encode([$visual, array_map(static fn($event) =>
        $event['completionCue']['spell']['presetId'] ?? '', $db->payload('activity')['abilityCueEvents'] ?? [])]));
$wound = runCommand($db, 'token.resource.adjust', ['sceneId'=>'scene-one','tokenId'=>'token-monster','resource'=>'hp','delta'=>-1,
    'requestId'=>'blood-wound-' . randomToken(20)], true, 'account-gm');
specialCheck($wound->status === 200 && $orbCount() === 1, 'Another actual wound grants an orb');
$baseAttackState = $snapshot();
$map=$db->payload('map:scene-one');
$fog=str_repeat("\0",512);
applicationSetMaskBit($fog,32*64+32,true);
$db->put('map:scene-one',[...$map,'fog'=>['version'=>1,'enabled'=>true,'width'=>64,'height'=>64,
    'mask'=>rtrim(strtr(base64_encode($fog),'+/','-_'),'=')]]);
foreach ([false,true] as $gm) foreach (['burst','shield'] as $projection) {
    $before=$db->domains;
    $hidden=runCommand($db,'ability.complex',$orbRequest($projection),$gm,$gm?'account-gm':'account-player');
    specialCheck($hidden->status===403&&($hidden->body['code']??'')==='attack_target_hidden'
        &&$orbCount()===1&&$db->domains===$before,'An unseen target never spends a damage or protection orb: '.json_encode($hidden->body));
}
$restore($baseAttackState);

$arvin = $db->payload('character:character-player');
$arvin['stats'] = ['force'=>26,'agility'=>44,'intelligence'=>62,'spiritSocial'=>13];
$arvin['temporaryStats'] = ['agility'=>91];
$arvin['fatigue']['current'] = 60;
$db->put('character:character-player', $arvin);
$modifiedStatsState = $snapshot();
$orbTargetHp = $db->payload('token:scene-one:token-monster')['hp'];
[$spent,$orbLaunch,$attack] = $testOrbOutcome('success');
$hit = $attack['hit'];
$publicOrb = publicOnlineAttackRoll($attack['hitRoll'],false);
specialCheck($orbCount() === 0 && $hit['statId'] === 'character-stat-agility'
    && ($hit['outcome']['baseThreshold'] ?? null) === 81 && ($hit['outcome']['bestStat'] ?? false) === true
    && $hit['formula'] === '1d100' && $hit['raw'] >= 1 && $hit['raw'] <= 100,
    'The offensive orb rolls an actual d100 against the current, fatigue-adjusted best stat: ' . json_encode($hit));
specialCheck(($publicOrb['outcome']['bestStat'] ?? false) === true && !isset($publicOrb['outcome']['baseThreshold']),
    'A public offensive orb preserves the best-stat cue without leaking the private threshold');
$replayOrb = runCommand($db,'ability.complex',$orbLaunch);
specialCheck($replayOrb->status === 200 && ($replayOrb->body['deduplicated'] ?? false) && $orbCount() === 0
    && ($replayOrb->body['rolls'][0]['id'] ?? null) === ($spent->body['rolls'][0]['id'] ?? '')
    && $db->payload('character:character-player')['fatigue']['current'] === 60,
    'The original hit survives request replay without another charge or activation fatigue: ' . json_encode($replayOrb->body));

$restore($modifiedStatsState);
[,,$attack] = $testOrbOutcome('failure',true,'total');
specialCheck($attack['status'] === 'missed' && empty($attack['opposition']) && empty($attack['damageRoll'])
    && $orbCount() === 0 && $db->payload('token:scene-one:token-monster')['hp'] === $orbTargetHp,
    'An ordinary miss consumes the orb without opposition or damage');

$restore($modifiedStatsState);
[,,$attack] = $testOrbOutcome('critical-success',true,'total');
specialCheck($attack['status'] === 'pending' && $attack['validationKind'] === 'outcome'
    && ($attack['opposition']['reason'] ?? '') === 'immediate-outcome' && $orbCount() === 0
    && $db->payload('token:scene-one:token-monster')['hp'] === $orbTargetHp,
    'A critical hit skips total opposition but still waits for the MJ before applying damage');
$refused = runCommand($db,'token.attack.resolve',['attackId'=>$attack['id'],'confirmed'=>true,'decision'=>'reject'],true,'account-gm');
specialCheck($refused->status === 200 && $orbCount() === 0 && $db->payload('token:scene-one:token-monster')['hp'] === $orbTargetHp,
    'Rejecting a remarkable result leaves the projected orb spent and the target unharmed: ' . json_encode($refused->body));

$restore($baseAttackState);
[,,$freeAttack] = $testOrbOutcome('success');
specialCheck($orbCount() === 0 && $freeAttack['damageFormula'] === '1d25+5'
    && $freeAttack['status'] === 'pending', 'In free mode the damage formula is upgraded and the MJ keeps their damage validation');
$hp = $db->payload('token:scene-one:token-monster')['hp'];
$approved = runCommand($db, 'token.attack.resolve', ['attackId'=>$freeAttack['id'],'confirmed'=>true,'decision'=>'approve'], true, 'account-gm');
specialCheck($approved->status === 200 && ($approved->body['attack']['guardPercent'] ?? null) === 25
    && $db->payload('token:scene-one:token-monster')['hp'] === $hp - $approved->body['attack']['appliedDamage']
    && $db->payload('activity')['nextAttackGuards'] === [], 'The shield protects against actual damage and is consumed in free mode: ' . json_encode($approved->body));
specialCheck($orbCount() === 1, 'Actual damage under Hémorragie grants the replacement charge');
specialCheck(bloodClockPatch($db, 'scene-two', ['active'=>true,'combatId'=>'other-fight','turnSerial'=>1])->status === 200
    && $readExecution()['combatId'] === '', 'Combat in another scene does not bind the execution');
specialCheck(bloodClockPatch($db, 'scene-two', ['active'=>false])->status === 200 && $readExecution()['status'] === 'active',
    'Ending another scene combat does not expire the effect');
specialCheck(bloodClockPatch($db, 'scene-one', ['active'=>true,'combatId'=>'orb-fight','turnSerial'=>11,'currentIndex'=>1])->status === 200
    && $readExecution()['combatId'] === 'orb-fight' && $orbCount() === 1, 'The encounter binds the existing execution and keeps its charge');
$combatOrbState = $snapshot();
[,,$totalAttack] = $testOrbOutcome('success',true,'total');
specialCheck(($totalAttack['opposition']['automatic'] ?? false) && $totalAttack['status'] !== 'awaiting-opposition'
    && $orbCount() <= 1, 'Total opposition resolves the NPC defense in the launch command: ' . json_encode($totalAttack));
$restore($combatOrbState);
[,,$opposedAttack] = $testOrbOutcome('success',true);
specialCheck($orbCount() === 0 && $opposedAttack['damageFormula'] === '1d25+5'
    && $opposedAttack['status'] === 'awaiting-opposition', 'An orb launched out of turn stays open to the selected defense');
$wound = runCommand($db, 'token.resource.adjust', ['sceneId'=>'scene-one','tokenId'=>'token-monster','resource'=>'hp','delta'=>-1,
    'requestId'=>'blood-wound-' . randomToken(20)], true, 'account-gm');
specialCheck($wound->status === 200 && $orbCount() === 1, 'Wounds continue to grant charges in the associated combat: ' .
    json_encode([$db->payload('token:scene-one:token-monster')['conditions'] ?? [],$db->payload('initiative:scene-one'),$readExecution()['combatId'],$readExecution()['stepStates']['emosphere-orbes']]));
specialCheck(bloodClockPatch($db, 'scene-one', ['active'=>false])->status === 200 && $readExecution()['status'] === 'completed'
    && $readExecution()['endedByCombat'] === true && $orbCount() === 0, 'The associated combat ends the effect and clears its charges');
specialCheck(runCommand($db, 'ability.complex', [...$request,'requestId'=>'blood-rest-' . randomToken(20)])->status === 409
    && $db->payload('character:character-player')['fatigue']['current'] === 15, 'Combat and its end neither restart the cast nor refund its rest use');
$inactive = runCommand($db,'ability.complex',$addOrb($readExecution(),'blood-mj-expired-0001'),true,'account-gm');
specialCheck($inactive->status === 409 && ($inactive->body['code'] ?? '') === 'complex_ability_terminal'
    && $orbCount() === 0, 'The grant never reactivates an expired sphere.');
echo 'blood-activation: '.$specialChecks." checks passed\n";
