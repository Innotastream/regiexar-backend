<?php
declare(strict_types=1);
// Owner-confirmed legacy alias only; instances keep identity, place and revision.
const APPLICATION_LARGE_HEALING_ALIAS_ID='v370-template-2c015b24b53f455626f889ee';
const APPLICATION_LARGE_HEALING_TEMPLATE_ID='item-template-23001d85-298d-414c-8be9-2405e7a288e5';
function applicationLargeHealingAliasId(mixed $id):mixed{return $id===APPLICATION_LARGE_HEALING_ALIAS_ID?APPLICATION_LARGE_HEALING_TEMPLATE_ID:$id;}
function applicationRecognizedLargeHealingAlias(array $template):bool{
    $permissions=['read'=>true,'pickup'=>true,'destroy'=>false,'transfer'=>true,'boundToCarrier'=>false];
    if(($template['id']??'')!==APPLICATION_LARGE_HEALING_ALIAS_ID||($template['name']??'')!=='Potion supérieure'||($template['kind']??'')!=='object'
        ||($template['description']??'')!=='Effet indiqué : 1d30 %. Type non précisé, à arbitrer par le MJ.'||!empty($template['image'])
        ||array_key_exists('size',$template)&&(!is_int($template['size'])&&!is_float($template['size'])||$template['size']!=40)||array_key_exists('category',$template)&&!in_array($template['category'],['usable','utility'],true)
        ||array_diff(array_keys($template),['id','name','kind','description','image','size','permissions','category']))return false;
    if(array_key_exists('permissions',$template)){if(!is_array($template['permissions']))return false;foreach($template['permissions']as$key=>$value)if(!array_key_exists($key,$permissions)||$permissions[$key]!==$value)return false;}
    return true;
}
function applicationRecognizedLargeHealingCanonical(array $template):bool{
    $active=$template['active']??null;$effects=$active['effects']??null;
    return ($template['id']??'')===APPLICATION_LARGE_HEALING_TEMPLATE_ID&&($template['name']??'')==='Potion de soin grande'&&($template['kind']??'')==='potion'
        &&(!array_key_exists('size',$template)||(is_int($template['size'])||is_float($template['size']))&&$template['size']==40)&&is_array($active)&&is_array($effects)&&count($effects)===1&&($effects[0]['kind']??'')==='heal-max-die'&&(is_int($effects[0]['percent']??null)||is_float($effects[0]['percent']??null))&&$effects[0]['percent']==50
        &&!array_diff(array_keys($active),['version','effects','useLabel','authority'])
        &&($template['potion']['hp']??0)===0&&($template['potion']['mana']??0)===0&&($template['potion']['fatigue']??0)===0;
}
function applicationRemapLargeHealingIds(mixed $ids):mixed{
    if(!is_array($ids))return $ids;$result=[];$seen=[];
    foreach($ids as$id){$mapped=applicationLargeHealingAliasId($id);if(!is_string($mapped)){$result[]=$mapped;continue;}if(array_key_exists($mapped,$seen)&&$seen[$mapped]!==$id)continue;$seen[$mapped]=$id;$result[]=$mapped;}
    return $result;
}
function mergeKnownItemTemplateAliases(?array $value):?array{
    if($value===null||($value['version']??1)!==1||!is_array($value['templates']??null)||!is_array($value['instances']??null))return $value;
    $aliases=array_values(array_filter($value['templates'],static fn($t)=>is_array($t)&&($t['id']??'')===APPLICATION_LARGE_HEALING_ALIAS_ID));
    $targets=array_values(array_filter($value['templates'],static fn($t)=>is_array($t)&&($t['id']??'')===APPLICATION_LARGE_HEALING_TEMPLATE_ID));
    if(count($aliases)!==1||count($targets)!==1||!applicationRecognizedLargeHealingAlias($aliases[0])||!applicationRecognizedLargeHealingCanonical($targets[0]))return $value;
    $aliasItemIds=[];foreach($value['instances']as$item)if(is_array($item)&&($item['templateId']??'')===APPLICATION_LARGE_HEALING_ALIAS_ID)$aliasItemIds[$item['id']??'']=true;
    foreach(is_array($value['pendingUses']??null)?$value['pendingUses']:[]as$use)if(is_array($use)&&isset($aliasItemIds[$use['itemId']??'']))return $value;
    $value['templates']=array_values(array_filter($value['templates'],static fn($t)=>!is_array($t)||($t['id']??'')!==APPLICATION_LARGE_HEALING_ALIAS_ID));
    $value['instances']=array_map(static function($item){if(!is_array($item))return $item;if(isset($item['templateId']))$item['templateId']=applicationLargeHealingAliasId($item['templateId']);
        if(($item['vesselContentTemplateId']??'')===APPLICATION_LARGE_HEALING_ALIAS_ID)$item['vesselContentTemplateId']=APPLICATION_LARGE_HEALING_TEMPLATE_ID;
        if(is_array($item['vesselRequest']??null)&&($item['vesselRequest']['potionTemplateId']??'')===APPLICATION_LARGE_HEALING_ALIAS_ID)$item['vesselRequest']['potionTemplateId']=APPLICATION_LARGE_HEALING_TEMPLATE_ID;
        return $item;},$value['instances']);
    if(is_array($value['inventoryCatalog']??null))foreach($value['inventoryCatalog']as$unit=>$ids)$value['inventoryCatalog'][$unit]=applicationRemapLargeHealingIds($ids);
    if(is_array($value['favorites']??null)){$favorites=[];$seen=[];foreach($value['favorites']as$entry){if(!is_array($entry)){$favorites[]=$entry;continue;}
        $original=$entry['templateId']??null;$mapped=applicationLargeHealingAliasId($original);$key=json_encode([$entry['accountId']??null,$entry['unitId']??null,$mapped]);
        if(array_key_exists($key,$seen)&&$seen[$key]!==$original)continue;$seen[$key]=$original;if(array_key_exists('templateId',$entry))$entry['templateId']=$mapped;$favorites[]=$entry;}$value['favorites']=$favorites;}
    return $value;
}
