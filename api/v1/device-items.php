<?php
declare(strict_types=1);
require_once __DIR__.'/unit-authority.php';
require_once __DIR__.'/dynamic-items.php';

function physicalDevice(array $device):bool { return in_array($device['definition']['kind']??'', ['battery','launcher','beam'],true); }
function ensureDeviceItems(array &$state,callable $id):void {
    $state['items']=normalizeItemStore($state['items']??null);
    foreach($state['devices']['devices']??[] as $index=>$entry) {
        if(!physicalDevice($entry)||!in_array($entry['status'],['active','packed','inventory'],true))continue;
        $device=&$state['devices']['devices'][$index];
        if($device['status']==='packed')$device['status']='inventory';
        if(!empty($device['itemId'])&&in_array($device['itemId'],array_column($state['items']['instances'],'id'),true))continue;
        if(count($state['items']['instances'])>=2000)itemFailure('La collection d’objets est pleine.',429);
        $template=null;
        foreach($state['items']['templates'] as $entry)if($entry['kind']==='object'&&($entry['sourceAbilityId']??'')===$device['abilityId']&&($entry['creatorCharacterId']??'')===$device['creatorCharacterId']){$template=$entry;break;}
        if(!$template){
            if(count($state['items']['templates'])>=200)itemFailure('La collection de modèles est pleine.',429);
            $template=['id'=>$id('template'),'kind'=>'object','name'=>$device['name'],'description'=>'Dispositif transportable.','image'=>$device['image']??'','sourceAbilityId'=>$device['abilityId'],'creatorCharacterId'=>$device['creatorCharacterId']];
            $state['items']['templates'][]=$template;
        }
        $device['itemId']=$id('item');$device['ownerUnitId']??=$device['creatorCharacterId'];
        $location=$device['status']==='active'?['kind'=>'ground','sceneId'=>$device['sceneId'],'layerId'=>$device['layerId'],'x'=>$device['x'],'y'=>$device['y']]:['kind'=>'inventory','characterId'=>$device['ownerUnitId']];
        $state['items']['instances'][]=['id'=>$device['itemId'],'templateId'=>$template['id'],'revision'=>1,'location'=>$location];
        rememberLinkedItemStock($state['items'],unitById($state,$device['ownerUnitId'],false),$template['id']);
        unset($device);
    }
}
function synchronizeDeviceItemLocations(array &$state,string $itemId,array $context=[]):void {
    $item=null;foreach($state['items']['instances']??[] as $entry)if($entry['id']===$itemId){$item=$entry;break;}
    if(!$item)return;
    foreach($state['devices']['devices']??[] as $index=>$entry)if(($entry['itemId']??'')===$itemId&&physicalDevice($entry)){
        $device=&$state['devices']['devices'][$index];
        if($device['definition']['kind']==='beam'&&!in_array($device['status'],['active','inventory','packed'],true))itemFailure('Ce rayon a déjà été déclenché. Utilisez sa compétence après le repos requis.',409);
        if(($device['definition']['canPack']??true)===false&&$item['location']['kind']==='inventory')itemFailure('Ce dispositif ne peut pas être récupéré.',409);
        if($item['location']['kind']==='inventory'){$device['status']='inventory';$device['ownerUnitId']=$item['location']['characterId'];}
        elseif($item['location']['kind']==='ground'){
            if($device['definition']['kind']==='beam'){
                $mark=$device['mark']??null;
                if(!is_array($mark)||!is_numeric($mark['x']??null)||!is_numeric($mark['y']??null)||!is_finite((float)$mark['x'])||!is_finite((float)$mark['y'])||$mark['x']<0||$mark['x']>100||$mark['y']<0||$mark['y']>100||($mark['x']==$item['location']['x']&&$mark['y']==$item['location']['y']))itemFailure('Choisissez une marque distincte du dispositif avec « Gérer le dispositif », puis « Poser ».',400);
                $device['elapsedTurns']=0;$device['elapsedTicks']=0;
            }
            $device=array_replace($device,$item['location'],['status'=>'active']);unset($device['kind']);
            if(!empty($context['unitId']))$device['ownerUnitId']=$context['unitId'];
            if(!empty($context['tokenId']))$device['sourceTokenId']=$context['tokenId'];
        }
        $device['revision']++;unset($device);
    }
}
function copyUnitInventory(array $state,array $source,array $target,callable $id):array {
    ensureDeviceItems($state,$id);$sourceId=unitIdForToken($source);$targetId=unitIdForToken($target);
    if($targetId===''||in_array($targetId,array_column($state['characters']??[],'id'),true))itemFailure('L’identité du nouvel inventaire est invalide.',409);
    $inventory=array_values(array_filter($state['items']['instances'],static fn($item)=>$item['location']['kind']==='inventory'&&$item['location']['characterId']===$sourceId));
    if(count($state['items']['instances'])+count($inventory)>2000)itemFailure('Le stock copié dépasserait la limite d’objets.',429);
    $unit=[...$target,'id'=>$targetId,'abilities'=>$target['abilities']??[]];$itemIds=[];$itemMap=[];
    foreach($inventory as $item){
        $copy=[...$item,'id'=>$id('item'),'revision'=>1,'location'=>['kind'=>'inventory','characterId'=>$targetId]];
        $state['items']['instances'][]=$copy;$itemIds[]=$copy['id'];$itemMap[$item['id']]=$copy['id'];rememberLinkedItemStock($state['items'],$unit,$copy['templateId']);
        foreach($state['devices']['devices']??[] as $device)if(($device['itemId']??'')===$item['id']){
            if(count($state['devices']['devices'])>=1000)itemFailure('Le registre des dispositifs est plein.',429);
            $state['devices']['devices'][]=[...$device,'id'=>$id('device'),'itemId'=>$copy['id'],'revision'=>1,'status'=>'inventory',
                'creatorCharacterId'=>$targetId,'ownerUnitId'=>$targetId,'operatorCharacterId'=>$targetId,'controllerAccountId'=>$target['controllerPlayerId']??'','sourceTokenId'=>'',
                'sceneId'=>$state['activeSceneId'],'layerId'=>$target['layerId']??'ground','x'=>$target['x'],'y'=>$target['y']];break;
        }
    }
    $weaponAttacks=cloneEquipment($state['items'],$sourceId,$targetId,$itemMap,$target['weaponAttacks']??[],$id);
    return ['items'=>normalizeItemStore($state['items']),'devices'=>empty($state['devices'])?null:$state['devices'],'itemIds'=>$itemIds,'weaponAttacks'=>$weaponAttacks];
}
