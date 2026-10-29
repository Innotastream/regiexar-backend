<?php
declare(strict_types=1);
require_once __DIR__.'/../api/v1/domains.php';
require_once __DIR__.'/../api/v1/online.php';
if(($argv[1]??'')==='--cases'){
    $input=json_decode((string)stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    echo json_encode(['normalized'=>array_map('applicationSpellVisual',$input['cues']),
        'durations'=>array_map('applicationSpellVisualDuration',$input['cues']),
        'trusted'=>array_map(static fn($c)=>applicationTrustedMeleeVisual($c['attack'],$c['context']),$input['attacks'])],JSON_THROW_ON_ERROR);exit;
}
$checks=0;$check=static function($ok,$message)use(&$checks){if(!$ok)throw new RuntimeException($message);$checks++;};
$cue=['version'=>1,'placement'=>'melee','presetId'=>'barbed-chain-sweep','sceneId'=>'scene','layerId'=>'ground','sourceTokenId'=>'source','targetTokenId'=>'target',
    'source'=>['x'=>20,'y'=>50,'size'=>200],'targetPoint'=>['x'=>50,'y'=>50,'size'=>40],'strikeIndex'=>0];
$check(applicationSpellVisual($cue)===$cue,'Melee DTO');
$check(applicationSpellVisualDuration($cue)===2860,'Delivery window without changing animation cadence');
$public=['sceneId'=>'scene','layerId'=>'ground','sourceTokenId'=>'source','visibleTokenIds'=>['source','target'],'visible'=>static fn()=>true];
$check(applicationPublicSpellVisual($cue,$public)===$cue,'Both visible');
foreach([['visibleTokenIds'=>['source']],['sourceTokenId'=>'other'],['paused'=>true],['visible'=>static fn($x,$y)=>$x<40]] as $patch)$check(applicationPublicSpellVisual($cue,[...$public,...$patch])===null,'No hidden target disclosure');
$activity=[];appendApplicationAbilityCueEvent($activity,normalizeApplicationAbilityCompletionCue(['spell'=>$cue]),'scene','ground','source');
$check(validApplicationAbilityCueEvents($activity['abilityCueEvents']),'Authority journal accepts melee');
$check($activity['abilityCueEvents'][0]['completionCue']['sound']===null,'No audio added');
$baseAttack=['sceneId'=>'scene','sourceTokenId'=>'source','targetTokenId'=>'target','attackKind'=>'weapon','attackId'=>'paired',
    'hit'=>['outcome'=>['success'=>true]],'echoAttackContext'=>['weaponBased'=>true,'reach'=>'melee','projectile'=>false,'equipmentItemIds'=>['left','right']]];
$map=['tokens'=>[['id'=>'source','x'=>20,'y'=>50,'size'=>40],['id'=>'target','x'=>50,'y'=>50,'size'=>72]]];
foreach([
    ['v370-template-d810e782aa535bc6422e6ebf','v370-template-82f161523d5dbfa66b734000','elemental-sabre-cross'],
    ['v370-template-82f161523d5dbfa66b734000','v370-template-d810e782aa535bc6422e6ebf','elemental-sabre-cross'],
    ['v370-template-6aacb2d60e4cfeec7227d9b6','v370-template-871109782031b043f4adb048','elemental-sabre-cross'],
    ['v370-template-75838b12ba6af869c39b8483','v370-template-c79714649db68628fb7a5655','ethereal-weapon-cross'],
    ['v370-template-928081e83ab067b4c9186111','v370-template-f43cc10b70c24ca180211282','ethereal-weapon-cross'],
    ['v370-template-d810e782aa535bc6422e6ebf','v370-template-d810e782aa535bc6422e6ebf',null],
    ['v370-template-d810e782aa535bc6422e6ebf','unknown',null]
] as [$left,$right,$expected]){
    $context=['map'=>$map,'items'=>['instances'=>[['id'=>'left','templateId'=>$left],['id'=>'right','templateId'=>$right]]]];
    $actual=applicationTrustedMeleeVisual($baseAttack,$context);
    $check(($actual['presetId']??null)===$expected,'Actual weapon pair chooses its preset without guessing from names');
    if($expected!==null){
        $check($actual['targetTokenId']==='target'&&$actual['strikeIndex']===0,'One target and one resolved strike');
        $check(applicationMeleeVisualCatalogue()[$actual['presetId']]===770,'Both blades belong to the same animation');
    }
}
$duplicate=$baseAttack;$duplicate['echoAttackContext']['equipmentItemIds']=['left','left'];
$check(applicationTrustedMeleeVisual($duplicate,['map'=>$map,'items'=>['instances'=>[['id'=>'left','templateId'=>'v370-template-75838b12ba6af869c39b8483']]]])===null,'One physical blade cannot become two');
$execution=['id'=>'valse-execution','abilityId'=>'ability-7BG1hkmR6vBEbTLL','currentStepIndex'=>0,'workflow'=>['steps'=>[['id'=>'strikes','type'=>'allocated-attacks']]],'stepStates'=>['strikes'=>['targets'=>[]]]];
$activity=['abilityExecutions'=>[$execution],'attackReceipts'=>[
    ['attack'=>['id'=>'first','complexExecutionId'=>'valse-execution']],
    ['attack'=>['id'=>'echo','complexExecutionId'=>'valse-execution','echoParentId'=>'first']]]];
$valseAttack=[...$baseAttack,'id'=>'second','status'=>'applied','complexExecutionId'=>'valse-execution','layerId'=>'ground','visibility'=>'public',
    'echoAttackContext'=>['weaponBased'=>true,'reach'=>'melee','projectile'=>false,'equipmentItemIds'=>['ice']]];
$items=['instances'=>[['id'=>'ice','templateId'=>'v370-template-82f161523d5dbfa66b734000']]];
appendApplicationAttackVisualEvent($activity,$valseAttack,$map,$items);
$event=$activity['abilityCueEvents'][0]['completionCue'];
$check($event['spell']['presetId']==='sabre-frost-flurry'&&$event['spell']['strikeIndex']===1,'Allocated strikes count actual previous attacks, excluding echoes');
$check($event['spell']['targetTokenId']==='target'&&$event['sound']===null,'Actual recipient, no sound');
$check(applicationSpellVisualDuration($event['spell'])===2640,'Delivery window also includes five queued strikes without slowing their animation');
$activity['attackReceipts'][]=['attack'=>$valseAttack];$activity['abilityExecutions'][0]['currentStepIndex']=1;
$valseAttack['hit']['outcome']['requiresGmValidation']=true;
appendApplicationAttackVisualEvent($activity,$valseAttack,$map,$items,[],true);
$check($activity['abilityCueEvents'][1]['completionCue']['spell']['strikeIndex']===1,'Delayed GM approval preserves the original strike ordinal');
$valseAttack['hit']['outcome']['success']=false;
appendApplicationAttackVisualEvent($activity,$valseAttack,$map,$items);
$check(count($activity['abilityCueEvents'])===2,'No visual for a failed hit');
echo json_encode(['ok'=>true,'checks'=>$checks,'productionAccess'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
