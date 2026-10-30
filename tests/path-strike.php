<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);require __DIR__.'/tactical-special-effects.php';
if(($argv[1]??'')==='--cases'){$v=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$out=[];foreach($v['cases']??[]as$c)try{$out[]=applicationPlanPathStrike($c['execution'],$c['step'],$c['source'],$c['tokens'],$c['map'],$c['selection'],$c['now']??2);}catch(Throwable){$out[]=['error'=>true];}echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
$specialChecks=0;$definition=['version'=>1,'order'=>'distance-to-end-desc','staged'=>true];
$ability=['id'=>'path-strike-fixture','name'=>'Trajet de recette','effect'=>'complex','formula'=>'0','castingStatId'=>'','usesPerCombat'=>1,'manaCost'=>0,'cooldownRounds'=>0,'workflow'=>['version'=>8,'steps'=>[
    ['id'=>'move','type'=>'movement','maximumMeters'=>20,'pathStrike'=>$definition],
    ['id'=>'targets','type'=>'targets','minTargets'=>0,'maxTargets'=>20,'maximumPerTarget'=>1,'allocationTotal'=>0,'rangeCells'=>0,'sourceMovementStepId'=>'move','targetRelation'=>'enemies'],
    ['id'=>'strikes','type'=>'allocated-attacks','sourceStepId'=>'targets','awarenessMode'=>'none','damageMode'=>'configured','hitMode'=>'automatic','statId'=>'character-stat-agility','damageComponents'=>[['type'=>'magical','formula'=>'1d30+6','ignoreArmor'=>true]],'defenseStatId'=>'character-stat-agility','defenseRollMode'=>'disadvantage']
]]];
$source=['id'=>'hero','name'=>'Acteur','x'=>20,'y'=>50,'size'=>40,'hp'=>100,'maxHp'=>100,'layerId'=>'ground','controllerPlayerId'=>'owner'];
$enemy=static fn($id,$x,$y=50)=>['id'=>$id,'name'=>'Même nom','x'=>$x,'y'=>$y,'size'=>40,'hp'=>100,'maxHp'=>100,'layerId'=>'ground'];
$tokens=[$source,$enemy('near',30),$enemy('far',40),$enemy('outside',35,80)];$map=['naturalWidth'=>1000,'naturalHeight'=>600,'gridSize'=>50,'grid'=>false,'activeLayerId'=>'ground','tokens'=>$tokens];
$execution=createApplicationComplexAbilityExecution(['id'=>'execution','sceneId'=>'scene','layerId'=>'ground','sourceTokenId'=>'hero','controllerAccountId'=>'owner','sourcePosition'=>$source,'ability'=>$ability,'now'=>1]);$step=$execution['workflow']['steps'][0];$selection=['destination'=>['x'=>50,'y'=>50],'path'=>[['x'=>20,'y'=>50],['x'=>50,'y'=>50]],'targetTokenIds'=>['far','near']];
specialCheck(applicationPathStrike($definition)===$definition,'Explicit path strike config is stable');specialCheck(applicationPathStrike(null)===null,'Legacy movement stays unchanged');
$plan=applicationPlanPathStrike($execution,$step,$source,$tokens,$map,$selection,2);
specialCheck(array_column($plan['targets'],'tokenId')===['near','far'],'Order is farthest from final point first, without using names');specialCheck($plan['distanceMeters']>12&&$plan['distanceMeters']<=20,'Actual cumulative route includes lateral contacts and final travel');
specialCheck(abs($plan['distanceMeters']-applicationPathStrikeMeters($plan['points'],$map))<1e-8,'Every real segment contributes to the limit');
foreach($plan['targets']as$stop){$t=applicationComplexAbilityTokenById($tokens,$stop['tokenId']);specialCheck(applicationPathStrikeDistance($stop['contactPoint'],$t,applicationComplexMovementDimensions($map))>=40,'Contact never enters an enemy body');}
foreach($tokens as$t)if($t['id']!=='hero')for($i=1;$i<count($plan['points']);$i++)specialCheck(applicationPathStrikeSegmentSafe(applicationPathStrikeContext($execution,$step,$source,$tokens,$map),$plan['points'][$i-1],$plan['points'][$i],[$t]),'Every accepted segment respects physical bodies');
$none=applicationPlanPathStrike($execution,$step,$source,$tokens,$map,[...$selection,'targetTokenIds'=>[]],2);specialCheck($none['targets']===[]&&$none['attackPlan']===[],'Zero targets preserves a valid movement to the chosen destination');
foreach([['outside'],['near','near'],['missing']]as$ids)try{applicationPlanPathStrike($execution,$step,$source,$tokens,$map,[...$selection,'targetTokenIds'=>$ids],2);specialCheck(false,'Invalid targets accepted');}catch(RuntimeException){specialCheck(true,'Only distinct eligible chosen enemies are accepted');}
$command=static function(array $e,array $c,array $currentTokens)use($map){return applyApplicationComplexAbilityCommand($e,[...$c,'expectedRevision'=>$e['revision']],['actor'=>['id'=>'gm','role'=>'gm'],'tokens'=>$currentTokens,'map'=>[...$map,'tokens'=>$currentTokens],'now'=>4]);};
$execution=$command($execution,['action'=>'plan-path-strike','selection'=>$selection],$tokens);specialCheck($tokens[0]['x']===20,'Planning never moves the source');
$execution=$command($execution,['action'=>'confirm-movement'],$tokens);specialCheck($execution['workflow']['steps'][$execution['currentStepIndex']]['id']==='targets','Confirm prepares the target step without physical movement');
$execution=$command($execution,['action'=>'select-targets','allocations'=>[['tokenId'=>'far','count'=>1],['tokenId'=>'near','count'=>1]],'defenseTokenIds'=>[]],$tokens);
specialCheck(array_column($execution['stepStates']['targets']['attackPlan'],'tokenId')===['near','far'],'Selection cannot override canonical route order');
try{$command($execution,['action'=>'prepare-target','targetTokenId'=>'near'],$tokens);specialCheck(false,'Attack prepared before physical hop');}catch(RuntimeException){specialCheck(true,'Actual travel is mandatory before the next attack');}
$travel=applicationPathStrikeTravel($execution,['action'=>'prepare-target','targetTokenId'=>'near'],$tokens,$map);$moved=applicationChargeTravelTokens($tokens,$travel);$prepared=$command($execution,['action'=>'prepare-target','targetTokenId'=>'near'],$moved);
specialCheck($prepared['stepStates']['strikes']['pendingTargetTokenId']==='near','True source at contact enables the existing configured strike');
specialCheck(applicationAbilityMovementLock([$execution],'scene','hero',$tokens)!==null,'Active staged path forbids ordinary dragging');
$changed=$tokens;$changed[1]['x']++;try{applicationPathStrikeTravel($execution,['action'=>'prepare-target','targetTokenId'=>'near'],$changed,$map);specialCheck(false,'Moved target allowed');}catch(RuntimeException){specialCheck(true,'A changed target invalidates the next physical hop');}
$completed=$execution;$completed['status']='completed';$last=$plan['attackPlan'][array_key_last($plan['attackPlan'])]['movePoint'];$finalTokens=$tokens;$finalTokens[0]=[...$source,...$last];$final=applicationPathStrikeFinalTravel($completed,$finalTokens,$map);specialCheck($final['to']===$selection['destination'],'Last hop reaches the chosen final point');
$legacy=$ability;$legacy['id']='ability-ae84d88e-2469-45f5-b22e-b56971892753';unset($legacy['workflow']['steps'][0]['pathStrike']);$legacy['manaCost']=17;$legacy['fatigueCost']=8;$upgraded=applicationConfiguredPathStrikeAbility($legacy);
specialCheck($upgraded['manaCost']===17&&$upgraded['fatigueCost']===8&&$upgraded['workflow']['steps'][2]===$legacy['workflow']['steps'][2],'Targeted migration preserves all existing costs and initial damage/defense');
specialCheck($upgraded['workflow']['steps'][0]['pathStrike']['contactZone']['damage']===['formula'=>'1d20','damageType'=>'magical'],'Silver contact uses the separately configured normal magical damage');
echo json_encode(['ok'=>true,'checks'=>$specialChecks,'productionAccess'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
