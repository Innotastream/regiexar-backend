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
echo json_encode(['ok'=>true,'checks'=>$checks,'productionAccess'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
