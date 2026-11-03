<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);
require __DIR__.'/tactical-special-effects.php';

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
$awakenings=static fn()=>array_values(array_filter($db->payload('activity')['abilityCueEvents']??[],static fn($e)=>($e['completionCue']['spell']['presetId']??'')==='blood-awakening'));
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
echo 'blood-activation: '.$specialChecks." checks passed\n";
