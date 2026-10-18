<?php
declare(strict_types=1);

function unitFailure(string $message,int $status=409):never { throw new RuntimeException($message,$status); }
function unitUsesCharacterSheet(array $token):bool {
    return !empty($token['characterId'])&&($token['followCharacter']??true)!==false&&($token['usesCharacterSheet']??true)!==false
        &&empty($token['independentClone'])&&empty($token['linkedTokenId']);
}
function unitIdForToken(array $token):string { return unitUsesCharacterSheet($token)?$token['characterId']:(!empty($token['id'])?'unit_'.$token['id']:''); }
function unitMaps(array $state):array {
    $sceneId=$state['activeSceneId']??'';$maps=[['sceneId'=>$sceneId,'map'=>$state['map']??[]]];
    foreach($state['scenes']??[] as $scene)if($scene['id']!==$sceneId)$maps[]=['sceneId'=>$scene['id'],'map'=>$scene['combat']['map']??[]];
    return $maps;
}
function unitById(array $state,string $id,bool $required=true):?array {
    foreach($state['characters']??[] as $sheet)if($sheet['id']===$id)return $sheet;
    $matches=[];foreach(unitMaps($state) as $entry)foreach($entry['map']['tokens']??[] as $token)if(!unitUsesCharacterSheet($token)&&unitIdForToken($token)===$id)$matches[]=['token'=>$token,'sceneId'=>$entry['sceneId']];
    if(count($matches)>1)unitFailure('Plusieurs unités ont la même identité. Le MJ doit corriger ces pions.');
    if(!$matches){if($required)unitFailure('Cette unité n’existe plus.',404);return null;}
    $token=$matches[0]['token'];$resources=[];foreach(['hp','maxHp','mana','maxMana'] as $field)$resources[$field]=$token[$field]??0;
    return [...$token,'id'=>$id,'unitKind'=>'token','unitTokenId'=>$token['id'],'unitSceneId'=>$matches[0]['sceneId'],
        'ownerPlayerId'=>$token['controlEffect']['controllerId']??$token['controllerPlayerId']??'','resources'=>$resources,'fatigue'=>$token['fatigue']??['current'=>0,'max'=>150]];
}
function ownedUnit(array $state,array $identity,string $id):array {
    $unit=unitById($state,$id);
    $controlled=false;foreach($state['map']['tokens']??[] as $t)if(unitIdForToken($t)===$id&&($t['controlEffect']['controllerId']??'')===($identity['id']??''))$controlled=true;
    if(($identity['role']??'')!=='gm'&&($unit['ownerPlayerId']??'')!==($identity['id']??'')&&!$controlled)unitFailure('Cette unité ne vous appartient pas.',403);
    return [...$unit,'effectiveRole'=>$identity['role']??'player'];
}
function persistUnitResources(array &$state,array $unit):void {
    $found=false;
    if(($unit['unitKind']??'')==='token') {
        if(($state['activeSceneId']??'')===$unit['unitSceneId'])foreach($state['map']['tokens'] as &$token)if($token['id']===$unit['unitTokenId']){$token=array_replace($token,$unit['resources'],['fatigue'=>$unit['fatigue'],'_updatedAt'=>(int)round(microtime(true)*1000)]);$found=true;}unset($token);
        foreach(array_keys($state['scenes']??[]) as $index)if($state['scenes'][$index]['id']===$unit['unitSceneId']&&isset($state['scenes'][$index]['combat']['map']['tokens']))foreach($state['scenes'][$index]['combat']['map']['tokens'] as &$token)if($token['id']===$unit['unitTokenId']){$token=array_replace($token,$unit['resources'],['fatigue'=>$unit['fatigue']]);$found=true;}unset($token);
    }else {
        foreach($state['characters'] as &$sheet)if($sheet['id']===$unit['id']){$sheet['resources']=$unit['resources'];$sheet['fatigue']=$unit['fatigue'];$sheet['_updatedAt']=(int)round(microtime(true)*1000);$found=true;}unset($sheet);
        foreach($state['map']['tokens'] as &$token)if(unitUsesCharacterSheet($token)&&$token['characterId']===$unit['id'])$token=array_replace($token,$unit['resources'],['fatigue'=>$unit['fatigue']]);unset($token);
        foreach(array_keys($state['scenes']??[]) as $index)if(isset($state['scenes'][$index]['combat']['map']['tokens']))foreach($state['scenes'][$index]['combat']['map']['tokens'] as &$token)if(unitUsesCharacterSheet($token)&&$token['characterId']===$unit['id'])$token=array_replace($token,$unit['resources'],['fatigue'=>$unit['fatigue']]);unset($token);
    }
    if(!$found)unitFailure('Cette unité n’existe plus.',404);
}
function refreshUnitResources(array $state,array &$unit):void {
    $current=unitById($state,$unit['id']);$unit['resources']=$current['resources'];$unit['fatigue']=$current['fatigue']??['current'=>0,'max'=>150];
}
