<?php
declare(strict_types=1);

// Actual PHP commands and transaction boundaries, backed only by fictional memory domains.
define('XAR_SPECIAL_FIXTURE_ONLY', true);
require __DIR__ . '/tactical-special-effects.php';

function singleWeaponFixture(?bool $allowWeaponSets = false): MemoryConnection {
    $db = specialFixture();
    $step = ['id'=>'strikes','type'=>'allocated-attacks','sourceStepId'=>'distribution',
        'awarenessMode'=>'once','awarenessStatId'=>'character-stat-instinct','damagePercent'=>50];
    if ($allowWeaponSets !== null) $step['allowWeaponSets'] = $allowWeaponSets;
    $ability = ['id'=>'single-weapon-series','name'=>'Série fictive','effect'=>'complex','formula'=>'0',
        'castingStatId'=>'','fatigueCost'=>5,'workflow'=>['version'=>8,'steps'=>[
            ['id'=>'distribution','type'=>'targets','minTargets'=>1,'maxTargets'=>5,'allocationTotal'=>5],$step]]];
    $character = $db->payload('character:character-player');
    $character['abilities'] = [$ability];$character['specialSuccessStock'] = 20;
    $character['weaponText'] = 'Ancien texte / 2d48+20';
    $character['weaponAttacks'] = [['id'=>'paired-sabres','formula'=>'2d48+20','damageType'=>'physical',
        'equipmentBinding'=>['kind'=>'set','id'=>'pair']]];
    $db->put('character:character-player', $character);
    $items = ['version'=>1,'templates'=>[],'instances'=>[],'equipmentLoadouts'=>[
        'character-player'=>['sets'=>[['id'=>'pair','name'=>'Paire fictive','itemIds'=>['fire','ice']]],
            'equippedItemIds'=>['fire','ice'],'activeSetId'=>'pair']]];
    foreach (['fire'=>44,'ice'=>10] as $id=>$formula) {
        $equipment = ['version'=>1,'kind'=>'weapon','hands'=>1,'formula'=>(string)$formula,
            'damageType'=>'magical','reach'=>'melee','finesse'=>true];
        $items['templates'][] = ['id'=>'template-'.$id,'name'=>'Sabre fictif '.$id,'kind'=>'object','equipment'=>$equipment];
        $items['instances'][] = ['id'=>$id,'templateId'=>'template-'.$id,'revision'=>1,
            'location'=>['kind'=>'inventory','characterId'=>'character-player'],'equipment'=>$equipment];
    }
    $db->put('items', normalizeItemStore($items));
    $target = $db->payload('token:scene-one:token-monster');
    $target['hp']=$target['maxHp']=2000;$target['armor']=90;$target['magicArmor']=0;
    $target['stats']=[['id'=>'character-stat-instinct','label'=>'Instinct','value'=>'0']];
    $db->put('token:scene-one:token-monster', $target);
    specialCheck($db->payload('character:character-player')['fatigue']['current']===0, 'The pending selection has no cost before the MJ decision');
    return $db;
}
function singleWeaponCommand(MemoryConnection $db, string $action, array $extra=[]): TestResponse {
    $execution=$db->payload('activity')['abilityExecutions'][0];
    return runCommand($db,'ability.complex',['action'=>'command','requestId'=>'single-command-'.randomToken(20),
        'executionId'=>$execution['id'],'expectedRevision'=>$execution['revision'],'command'=>['action'=>$action,...$extra]]);
}
function singleWeaponPlan(MemoryConnection $db,string $id): TestResponse {
    $selection=['allocations'=>[['tokenId'=>'token-monster','count'=>5]],'attackPlan'=>array_fill(0,5,['tokenId'=>'token-monster','attackId'=>$id,'statId'=>'character-stat-agility','opposed'=>false])];
    $response=runCommand($db,'ability.complex',['action'=>'start','requestId'=>'single-start-'.randomToken(20),'sceneId'=>'scene-one','sourceTokenId'=>'token-player','abilityId'=>'single-weapon-series','initialSelection'=>$selection]);
    if($response->status===200){$response=fixtureApproveDefenseGate($db,$response,false);specialCheck($db->payload('character:character-player')['fatigue']['current']===5,'The approved start pays five fatigue once');}
    return $response;
}
function singleWeaponStrike(MemoryConnection $db,string $id,string $requestId=''): TestResponse {
    $execution=$db->payload('activity')['abilityExecutions'][0];
    return runCommand($db,'token.attack',['sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster',
        'attackKind'=>'weapon','attackId'=>$id,'statId'=>'character-stat-agility','opposed'=>false,'useJoker'=>true,
        'complexExecutionId'=>$execution['id'],'requestId'=>$requestId?:'single-attack-'.randomToken(20)]);
}

$db=singleWeaponFixture();$before=serialize([$db->domains,$db->revision]);$rejected=singleWeaponPlan($db,'paired-sabres');
specialCheck($rejected->status===409&&$rejected->body['code']==='complex_ability_single_weapon_required','Plan rejects a set with a clear reason');
specialCheck(serialize([$db->domains,$db->revision])===$before,'Rejected plan changes no resources, revision, allocation or receipts');
$valid=singleWeaponPlan($db,'equipment-fire');specialCheck($valid->status===200,'The same execution still accepts an individual weapon');
$execution=$db->payload('activity')['abilityExecutions'][0];
specialCheck($execution['workflow']['steps'][1]['allowWeaponSets']===false&&$execution['workflow']['steps'][1]['damagePercent']===50,'Stored execution retains the restriction and fifty percent');
specialCheck(count($execution['stepStates']['strikes']['targets'][0]['awarenessRolls'])===1,'Awareness is resolved once per target');
specialCheck(singleWeaponCommand($db,'prepare-target',['targetTokenId'=>'token-monster'])->status===200,'Individual strike can be prepared');

// Keep the planned attack ID but change its authoritative binding after selection.
$character=$db->payload('character:character-player');$original=$character['weaponAttacks'];
$character['weaponAttacks']=[['id'=>'equipment-fire','formula'=>'2d48+20','equipmentBinding'=>['kind'=>'set','id'=>'pair']]];
$db->put('character:character-player',$character);$before=serialize([$db->domains,$db->revision]);
$requestId='single-retry-'.randomToken(20);$rejected=singleWeaponStrike($db,'equipment-fire',$requestId);
specialCheck($rejected->status===409&&$rejected->body['code']==='complex_ability_single_weapon_required','Each POST checks current equipment even when the plan used to be valid');
specialCheck(serialize([$db->domains,$db->revision])===$before,'Rejected strike reserves nothing, spends no Joker or fatigue and applies no damage');
specialCheck(($db->payload('activity')['abilityExecutions'][0]['stepStates']['strikes']['attackRequestId']??'')==='','No failed reservation blocks a later valid strike');
$character['weaponAttacks']=$original;$db->put('character:character-player',$character);
$accepted=singleWeaponStrike($db,'equipment-fire',$requestId);
specialCheck($accepted->status===200,'Restoring an individual weapon allows the exact failed request to proceed: '.json_encode($accepted->body));
$receipts=$db->payload('activity')['attackReceipts'];$attack=$receipts[count($receipts)-1]['attack'];
specialCheck($attack['damagePercent']===50&&$attack['damageFormula']==='44'&&$attack['damageType']==='magical','Attack uses current item damage and type despite obsolete text');
specialCheck($attack['appliedDamage']===22,'Actual damage engine applies half of forty-four with zero magical armor');
specialCheck($db->payload('character:character-player')['fatigue']['current']===5,'Refusal and retry never repay the skill');

foreach ([null,true] as $default) {
    $db=singleWeaponFixture($default);specialCheck(singleWeaponPlan($db,'paired-sabres')->status===200,'Absent or true setting preserves old set plans');
    specialCheck(singleWeaponCommand($db,'prepare-target',['targetTokenId'=>'token-monster'])->status===200,'Compatible set strike can be prepared');
    $accepted=singleWeaponStrike($db,'paired-sabres');specialCheck($accepted->status===200,'Compatible set can still attack');
    $receipts=$db->payload('activity')['attackReceipts'];$attack=$receipts[count($receipts)-1]['attack'];
    specialCheck($attack['damageFormula']==='44+10'&&$attack['appliedDamage']===27,'Default set keeps two current weapons at fifty percent');
}

// An old saved set plan must not bypass a restriction added to that execution.
$db=singleWeaponFixture(true);singleWeaponPlan($db,'paired-sabres');singleWeaponCommand($db,'prepare-target',['targetTokenId'=>'token-monster']);
$activity=$db->payload('activity');$activity['abilityExecutions'][0]['workflow']['steps'][1]['allowWeaponSets']=false;$db->put('activity',$activity);
$before=serialize([$db->domains,$db->revision]);$rejected=singleWeaponStrike($db,'paired-sabres');
specialCheck($rejected->status===409&&$rejected->body['code']==='complex_ability_single_weapon_required','An old saved set plan is rechecked at attack time');
specialCheck(serialize([$db->domains,$db->revision])===$before,'Rejected legacy plan does not advance its strike or mutate any domain');

$restricted=['allowWeaponSets'=>false];
specialCheck(!applicationAllocatedAttackAllowsWeapon($restricted,['equipmentBinding'=>['kind'=>'set'],'equipmentItemIds'=>['fire']]),'A torch-suspended set remains a set even with only one current item');
specialCheck(!applicationAllocatedAttackAllowsWeapon($restricted,['equipmentItemIds'=>['fire','ice']]),'Multiple resolved item identities require permission even without a set binding');
foreach ([['equipmentBinding'=>['kind'=>'item','id'=>'greatsword'],'equipmentItemIds'=>['greatsword'],'formula'=>'2d30+8'],['naturalWeapon'=>true,'equipmentItemIds'=>[],'formula'=>'2d30+8'],['formula'=>'3d10+5']] as $weapon) {
    specialCheck(applicationAllocatedAttackAllowsWeapon($restricted,$weapon),'Two-handed, natural and direct creature attacks remain individual regardless of dice count');
}
echo 'allocated-single-weapon: '.$specialChecks." checks passed\n";
