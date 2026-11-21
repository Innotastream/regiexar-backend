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
$useOrb = static function (string $option, bool $opposed = false) use ($db, $readExecution): TestResponse {
    $execution = $readExecution();
    return runCommand($db, 'ability.complex', ['action'=>'command','executionId'=>$execution['id'],'expectedRevision'=>$execution['revision'],
        'requestId'=>'blood-free-spend-' . randomToken(20),
        'command'=>['action'=>'counter-spend','optionId'=>$option,'targetTokenId'=>'token-monster','opposed'=>$opposed]]);
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
$spent = $useOrb('burst');
$freeAttack = array_values(array_filter($db->payload('activity')['pendingAttacks'], static fn($attack) =>
    $attack['id'] === ($spent->body['chargeEffect']['attackId'] ?? '')));
specialCheck($spent->status === 200 && $orbCount() === 0 && ($freeAttack[0]['damageFormula'] ?? '') === '1d25+5'
    && $freeAttack[0]['status'] === 'pending', 'In free mode the damage formula is upgraded and the MJ keeps their damage validation');
$hp = $db->payload('token:scene-one:token-monster')['hp'];
$approved = runCommand($db, 'token.attack.resolve', ['attackId'=>$freeAttack[0]['id'],'confirmed'=>true,'decision'=>'approve'], true, 'account-gm');
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
$spent = $useOrb('burst', true);
$opposedAttack = array_values(array_filter($db->payload('activity')['pendingAttacks'], static fn($attack) =>
    $attack['id'] === ($spent->body['chargeEffect']['attackId'] ?? '')));
specialCheck($spent->status === 200 && $orbCount() === 0 && ($opposedAttack[0]['damageFormula'] ?? '') === '1d25+5'
    && $opposedAttack[0]['status'] === 'awaiting-opposition', 'An orb launched out of turn stays open to the selected defense');
$wound = runCommand($db, 'token.resource.adjust', ['sceneId'=>'scene-one','tokenId'=>'token-monster','resource'=>'hp','delta'=>-1,
    'requestId'=>'blood-wound-' . randomToken(20)], true, 'account-gm');
specialCheck($wound->status === 200 && $orbCount() === 1, 'Wounds continue to grant charges in the associated combat: ' .
    json_encode([$db->payload('token:scene-one:token-monster')['conditions'] ?? [],$db->payload('initiative:scene-one'),$readExecution()['combatId'],$readExecution()['stepStates']['emosphere-orbes']]));
specialCheck(bloodClockPatch($db, 'scene-one', ['active'=>false])->status === 200 && $readExecution()['status'] === 'completed'
    && $readExecution()['endedByCombat'] === true && $orbCount() === 0, 'The associated combat ends the effect and clears its charges');
specialCheck(runCommand($db, 'ability.complex', [...$request,'requestId'=>'blood-rest-' . randomToken(20)])->status === 409
    && $db->payload('character:character-player')['fatigue']['current'] === 15, 'Combat and its end neither restart the cast nor refund its rest use');
echo 'blood-activation: '.$specialChecks." checks passed\n";
