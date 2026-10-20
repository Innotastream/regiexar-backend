<?php
declare(strict_types=1);
function customItemCategoryId(mixed $id):bool{return is_string($id)&&preg_match('/^category-[A-Za-z0-9_-]{1,100}$/D',$id)===1;}
function itemCategoryNameKey(string $name):string{return strtolower(strtr(trim($name),['À'=>'a','Â'=>'a','Ä'=>'a','à'=>'a','â'=>'a','ä'=>'a','É'=>'e','È'=>'e','Ê'=>'e','Ë'=>'e','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','Î'=>'i','Ï'=>'i','î'=>'i','ï'=>'i','Ô'=>'o','Ö'=>'o','ô'=>'o','ö'=>'o','Ù'=>'u','Û'=>'u','Ü'=>'u','ù'=>'u','û'=>'u','ü'=>'u','Ç'=>'c','ç'=>'c']));}
function itemNameKey(string $name):string{$name=strtr($name,['Œ'=>'oe','œ'=>'oe','Æ'=>'ae','æ'=>'ae']);$name=preg_replace('/\p{Mn}/u','',$name)??$name;return itemCategoryNameKey(preg_replace('/\s+/u',' ',$name)??$name);}
function namedItemTemplate(array $items,string $name,string $exceptId=''):?array{foreach($items['templates']??[] as $template)if($template['id']!==$exceptId&&itemNameKey($template['name'])===itemNameKey($name))return $template;return null;}
function availableItemName(array $items,string $name):string{$characters=array_slice(preg_split('//u',trim($name),-1,PREG_SPLIT_NO_EMPTY),0,120);$base=implode('',$characters);if(!namedItemTemplate($items,$base))return $base;$n=2;do{$suffix=' · '.$n++;$candidate=implode('',array_slice($characters,0,120-count(preg_split('//u',$suffix,-1,PREG_SPLIT_NO_EMPTY)))).$suffix;}while(namedItemTemplate($items,$candidate));return $candidate;}
function normalizeItemCategories(mixed $value=[]):array {
    if(!is_array($value)||!array_is_list($value)||count($value)>60)itemFailure('Les catégories doivent avoir un nom unique de 1 à 60 caractères.');
    $ids=[];$names=array_fill_keys(array_map('itemCategoryNameKey',['Liés aux capacités','Utilisables','Lectures','Équipements','Utilitaires','Objets rapides']),true);$result=[];
    foreach($value as $c){if(!is_array($c)||!customItemCategoryId($c['id']??null)||!is_string($c['name']??null)||trim($c['name'])===''||count(preg_split('//u',trim($c['name']),-1,PREG_SPLIT_NO_EMPTY))>60||isset($ids[$c['id']])||isset($names[itemCategoryNameKey($c['name'])]))itemFailure('Les catégories doivent avoir un nom unique de 1 à 60 caractères.');
        $ids[$c['id']]=true;$names[itemCategoryNameKey($c['name'])]=true;$result[]=['id'=>$c['id'],'name'=>trim($c['name'])];}
    return $result;
}
function deleteItemTemplate(array &$items,array &$state,array $args):array {
    $index=array_search($args['templateId']??'',array_column($items['templates'],'id'),true);
    if($index===false)itemFailure('Ce modèle n’existe plus.',404);$template=$items['templates'][$index];
    if(normalizeItemTemplate(is_array($args['expectedTemplate']??null)?$args['expectedTemplate']:[])!==$template)itemFailure('Ce modèle a changé. Relisez la confirmation de suppression.',409);
    $copies=array_values(array_filter($items['instances'],static fn($i)=>$i['templateId']===$template['id']));$expected=$args['expectedInstanceRevisions']??null;
    if(!is_array($expected)||count($expected)!==count($copies))itemFailure('Les exemplaires ont changé. Relisez leurs emplacements avant de supprimer.',409);
    foreach($copies as $i)if(($expected[$i['id']]??null)!==$i['revision'])itemFailure('Les exemplaires ont changé. Relisez leurs emplacements avant de supprimer.',409);
    $activity=itemDeletionActivitySnapshot([...$state,'items'=>$items],$template['id']);if(((array)$activity['devices']||$activity['uses']||isset($args['expectedActivity']))&&!sameItemDefinition(['devices'=>(array)($args['expectedActivity']['devices']??[]),'uses'=>$args['expectedActivity']['uses']??[]],['devices'=>(array)$activity['devices'],'uses'=>$activity['uses']]))itemFailure('Une machine ou une activation a changé. Relisez la confirmation de suppression.',409);
    $ids=array_fill_keys(array_column($copies,'id'),true);if(isset($items['pendingUses']))$items['pendingUses']=array_values(array_filter($items['pendingUses'],static fn($u)=>!isset($ids[$u['itemId']])));
    $items['templates']=array_values(array_filter($items['templates'],static fn($t)=>$t['id']!==$template['id']));
    $items['instances']=array_values(array_filter($items['instances'],static fn($i)=>!isset($ids[$i['id']])));
    foreach($items['inventoryCatalog']??[] as $unit=>$catalog)$items['inventoryCatalog'][$unit]=array_values(array_filter($catalog,static fn($id)=>$id!==$template['id']));
    foreach($items['equipmentLoadouts']??[] as $unit=>$loadout){
        $loadout['sets']=array_values(array_filter($loadout['sets'],static fn($set)=>!array_intersect($set['itemIds'],array_keys($ids))));
        $loadout['equippedItemIds']=array_values(array_filter($loadout['equippedItemIds'],static fn($id)=>!isset($ids[$id])));
        if(!in_array($loadout['activeSetId'],array_column($loadout['sets'],'id'),true))$loadout['activeSetId']='';
        $items['equipmentLoadouts'][$unit]=$loadout;
    }
    if(isset($state['devices'])){$devices=array_fill_keys(array_column(array_filter($state['devices']['devices'],static fn($d)=>isset($ids[$d['itemId']??''])),'id'),true);
        $state['devices']['devices']=array_values(array_filter($state['devices']['devices'],static fn($d)=>!isset($devices[$d['id']])));
        $state['devices']['pendingRolls']=array_values(array_filter($state['devices']['pendingRolls']??[],static fn($r)=>!isset($devices[$r['deviceId']??''])));}
    return ['deletedTemplateId'=>$template['id'],'deletedInstances'=>count($copies)];
}

function itemDeletionActivitySnapshot(array $state,string $templateId):array {$ids=[];foreach($state['items']['instances']??[] as $i)if($i['templateId']===$templateId)$ids[$i['id']]=true;$devices=[];foreach($state['devices']['devices']??[] as $d)if(isset($ids[$d['itemId']??'']))$devices[$d['id']]=$d['revision'];$uses=[];foreach($state['items']['pendingUses']??[] as $u)if(isset($ids[$u['itemId']]))$uses[]=['id'=>$u['id'],'phase'=>$u['phase'],'targets'=>array_map(static fn($t)=>['tokenId'=>$t['tokenId'],'status'=>$t['status']],$u['targets'])];return ['devices'=>$devices?:new stdClass(),'uses'=>$uses];}
