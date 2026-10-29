<?php
declare(strict_types=1);
function applicationTokenDash(mixed $value): ?array {
    if(!is_array($value)||($value['version']??null)!==1||($value['placement']??'')!=='dash'||!in_array($value['presetId']??'',['token-afterimage-dash','token-body-charge'],true)||!in_array($value['layerId']??'',['ground','upper','basement'],true))return null;
    foreach(['sceneId','sourceTokenId']as$key)if(!is_string($value[$key]??null)||preg_match('/^[A-Za-z0-9_-]{1,180}$/D',$value[$key])!==1)return null;
    $point=static fn($p)=>is_array($p)&&array_reduce(['x','y'],static fn($ok,$key)=>$ok&&(is_int($p[$key]??null)||is_float($p[$key]??null))&&is_finite((float)$p[$key])&&$p[$key]>=0&&$p[$key]<=100,true);
    $source=$value['source']??null;$path=$value['path']??null;
    if(!$point($source)||(!is_int($source['size']??null)&&!is_float($source['size']??null))||!is_finite((float)$source['size'])||$source['size']<10||$source['size']>10000||!is_array($path)||!array_is_list($path)||count($path)<2||count($path)>256)return null;
    foreach($path as$p)if(!$point($p))return null;
    $impact=($value['presetId']??'')==='token-body-charge'?($value['impact']??null):null;
    if($impact!==null&&(!is_array($impact)||!is_string($impact['targetTokenId']??null)||preg_match('/^[A-Za-z0-9_-]{1,180}$/D',$impact['targetTokenId'])!==1||$impact['targetTokenId']===$value['sourceTokenId']||!$point($impact['point']??null)||!is_numeric($impact['point']['size']??null)||$impact['point']['size']<10||$impact['point']['size']>10000))return null;
    if(abs($source['x']-$path[0]['x'])>1e-5||abs($source['y']-$path[0]['y'])>1e-5||$impact===null&&!array_filter($path,static fn($p)=>abs($p['x']-$source['x'])>1e-5||abs($p['y']-$source['y'])>1e-5))return null;
    return ['version'=>1,'placement'=>'dash','presetId'=>$value['presetId'],'sceneId'=>$value['sceneId'],'layerId'=>$value['layerId'],'sourceTokenId'=>$value['sourceTokenId'],
        'source'=>['x'=>$source['x'],'y'=>$source['y'],'size'=>$source['size']],'path'=>array_map(static fn($p)=>['x'=>$p['x'],'y'=>$p['y']],$path),...($impact!==null?['impact'=>['targetTokenId'=>$impact['targetTokenId'],'point'=>['x'=>$impact['point']['x'],'y'=>$impact['point']['y'],'size'=>$impact['point']['size']]]]:[])];
}
function applicationTrustedMovementSpellVisual(array $execution,array $context): ?array {
    $binding=applicationSpellVisualBindings()[$execution['abilityId']??'']??null;$stepId=$context['stepId']??'';$movement=$execution['stepStates'][$stepId]??null;$source=$context['source']??null;$map=$context['map']??null;$step=null;
    foreach($execution['workflow']['steps']??[]as$candidate)if(($candidate['id']??'')===$stepId)$step=$candidate;
    if(empty($binding['movementStepId'])||$binding['movementStepId']!==$stepId||($step['type']??'')!=='movement'||($movement['status']??'')!=='completed'||empty($movement['moved'])||!is_array($source)||($source['id']??'')!==($execution['sourceTokenId']??null)||!is_array($map)||!empty($execution['endedByFailure'])||!in_array($execution['status']??'',['active','completed'],true))return null;
    $footprint=function_exists('applicationTokenSpatialFootprint')?applicationTokenSpatialFootprint($source,$map):$source;$path=$movement['points']??[];$end=$path===[]?null:$path[array_key_last($path)];
    if($end===null||($footprint['layerId']??'ground')!==($execution['layerId']??'')||abs(($footprint['x']??-1000)-$end['x'])>1e-5||abs(($footprint['y']??-1000)-$end['y'])>1e-5)return null;
    return applicationTokenDash(['version'=>1,'placement'=>'dash','presetId'=>$binding['presetId']??'','sceneId'=>$execution['sceneId'],'layerId'=>$execution['layerId'],'sourceTokenId'=>$source['id'],
        'source'=>[...($movement['origin']??[]),'size'=>$movement['sourceSize']??$footprint['size']??40],'path'=>$path]);
}

function appendApplicationChargeTravelCue(array &$activity,array $execution,array $travel):void {
 foreach($travel['moves']as$move){$source=$move['tokenId']===$travel['sourceTokenId'];$impact=$source&&!empty($travel['finalContact']);if(!$impact&&applicationChargeSame($move['from'],$move['to']))continue;$spell=applicationTokenDash(['version'=>1,'placement'=>'dash','presetId'=>$source?'token-body-charge':'token-afterimage-dash','sceneId'=>$execution['sceneId'],'layerId'=>$execution['layerId'],'sourceTokenId'=>$move['tokenId'],'source'=>[...$move['from'],'size'=>$source?$travel['size']:($move['size']??40)],'path'=>[$move['from'],$move['to']],...($impact?['impact'=>['targetTokenId'=>$travel['targetTokenId'],'point'=>$travel['targetPoint']]]:[])]);if($spell!==null)appendApplicationAbilityCueEvent($activity,['version'=>1,'spell'=>$spell],$execution['sceneId'],$execution['layerId'],$move['tokenId'],'public');}
}
