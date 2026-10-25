<?php
declare(strict_types=1);

function destroyInventorySelection(array $state,array &$items,array $identity,array $args,callable $owned,callable $rememberStock): ?array {
    $indices=array_flip(array_column($items['instances'],'id'));
    $initial=isset($indices[$args['itemId']??''])?$items['instances'][$indices[$args['itemId']]]:null;
    $batch=array_key_exists('itemIds',$args);
    if(!$batch&&($initial['location']['kind']??'')!=='inventory')return null;
    $ids=$batch?$args['itemIds']:[$args['itemId']??''];
    if(!is_array($ids)||!array_is_list($ids)||count($ids)<1||count($ids)>2000||$ids[0]!==($args['itemId']??'')||count(array_filter($ids,'itemId'))!==count($ids)||count(array_unique($ids))!==count($ids))itemFailure('Choisissez les exemplaires à supprimer.',400);
    $revisions=$batch?($args['expectedItemRevisions']??null):[($args['itemId']??'')=>$args['expectedItemRevision']??null];
    if(!is_array($revisions)||count($revisions)!==count($ids))itemFailure('Le stock a changé. Rouvrez la suppression.',409);
    foreach($ids as $id)if(!isset($revisions[$id])||!is_int($revisions[$id])||$revisions[$id]<1)itemFailure('Le stock a changé. Rouvrez la suppression.',409);
    $gm=($identity['role']??'')==='gm';$source=$owned($args['characterId']??null);
    if(!$gm&&!empty($state['tacticalSync']['paused']))itemFailure('La table est temporairement verrouillée par le MJ.',423);
    $models=array_column($items['templates'],null,'id');$selected=[];$pending=array_fill_keys(array_column($items['pendingUses']??[],'itemId'),true);
    foreach($ids as $id){
        if(!isset($indices[$id]))itemFailure('Objet introuvable.',404);
        $item=$items['instances'][$indices[$id]];
        if($item['revision']!==$revisions[$id]||$item['location']['kind']!=='inventory'||$item['location']['characterId']!==$source['id'])itemFailure('Cet objet a changé ou n’est plus dans cet inventaire.',409);
        if($item['templateId']!==($initial['templateId']??null))itemFailure('Cette quantité doit concerner le même objet.',400);
        if(!$gm&&!empty($models[$item['templateId']]['permissions']['boundToCarrier']))itemFailure('Cet objet est lié à son porteur. Seul le MJ peut le supprimer.',403);
        foreach($state['devices']['devices']??[] as $device)if(($device['itemId']??'')===$id)itemFailure('Utilisez les actions du dispositif pour le retirer.',409);
        if(!$gm&&isset($pending[$id]))itemFailure('Cet objet attend la résolution de son effet.',409);
        $selected[]=$item;
    }
    foreach(array_unique(array_column($selected,'templateId')) as $templateId)$rememberStock($items,$source,$templateId);
    if($gm&&$pending)$items['pendingUses']=array_values(array_filter($items['pendingUses']??[],static fn($use)=>!in_array($use['itemId'],$ids,true)));
    foreach($selected as &$item){$item['location']=['kind'=>'destroyed'];if(isset($item['health']))$item['health']['current']=0;$item['revision']++;$items['instances'][$indices[$item['id']]]=$item;}unset($item);
    return ['item'=>$selected[0],'items'=>$selected,'quantity'=>count($selected)];
}
