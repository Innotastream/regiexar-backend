<?php
declare(strict_types=1);

function applicationDoorPickCheckpoint(array $door): array {
    $barrier=$door['terrainBarrier'];
    return [$door['x'],$door['y'],$door['layerId']??'ground',$barrier['rotation'],applicationObstacleDoorWidth($door),
        ($barrier['open']??false)===true,($barrier['locked']??false)===true,$barrier['pickDifficulty']??0,$barrier['swungAt']??0,
        ...(!empty($barrier['configuredAt'])?[$barrier['configuredAt']]:[])];
}

function applicationResolveDoorPick(array &$state,array $pending,bool $approve): array {
    $map=$state['map'];$tokens=array_column($map['tokens'],null,'id');
    $door=$tokens[$pending['doorId']]??[];$source=$tokens[$pending['tokenId']]??[];
    $toolIndex=array_search($pending['toolId'],array_column($state['items']['instances']??[],'id'),true);
    $tool=$toolIndex===false?[]:$state['items']['instances'][$toolIndex];
    $owner=$source?unitById($state,unitIdForToken($source),false):null;
    if($owner!==null&&unitUsesCharacterSheet($source))$source=synchronizeOnlineCharacterToken($source,$owner);
    $current=$state['activeSceneId']===$pending['sceneId']&&applicationIsObstacleDoor($door)&&applicationTerrainBarrierActive($door)&&empty($door['hidden'])
        &&$source&&empty($source['hidden'])&&!onlineTokenIsDead($source,true)&&($owner['resources']['hp']??0)>0
        &&applicationUnitControllerId($source,$state)===$pending['accountId']
        &&applicationDoorPickCheckpoint($door)==$pending['doorCheckpoint']
        &&applicationKeyholeMovementCheckpoint($source,$map)==$pending['sourceCheckpoint']
        &&($tool['revision']??null)===$pending['toolRevision']&&($tool['location']['kind']??'')==='inventory'
        &&($tool['location']['characterId']??'')===$pending['ownerId'];
    $applied=$approve&&$current;
    foreach($state['map']['tokens'] as &$token)if($token['id']===$pending['doorId']){
        if($applied){
            if($pending['cast']['success'])$token['terrainBarrier']['locked']=false;
            else{
                $state['items']['instances'][$toolIndex]['revision']++;
                $state['items']['instances'][$toolIndex]['location']=['kind'=>'destroyed'];
                $token['terrainBarrier']['pickBrokeAt']=max((int)floor(microtime(true)*1000),($token['terrainBarrier']['pickBrokeAt']??0)+1);
            }
        }
        foreach($token['terrainBarrier']['pickReceipts']??[] as $index=>$receipt)if($receipt['id']===$pending['requestId'])
            $token['terrainBarrier']['pickReceipts'][$index]['attempt']=[...$receipt['attempt'],'pendingValidation'=>false,'applied'=>$applied,'cancelled'=>!$applied];
    }unset($token);
    return ['applied'=>$applied,'obsolete'=>$approve&&!$current];
}
