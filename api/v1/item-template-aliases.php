<?php
declare(strict_types=1);
// Owner-confirmed aliases only; instances keep identity, place and revision.
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
const APPLICATION_POISON_ALIAS_ID='v370-template-8e0a02fe1713a545f9d1aba3';
const APPLICATION_POISON_TEMPLATE_ID='v370-template-fad0fbf7691b2b40a2a9a7dc';
function applicationRecognizedPoisonTemplate(array $template,bool $alias):bool{
    $permissions=['read'=>true,'pickup'=>true,'destroy'=>false,'transfer'=>true,'boundToCarrier'=>false];
    if(($template['id']??'')!==($alias?APPLICATION_POISON_ALIAS_ID:APPLICATION_POISON_TEMPLATE_ID)
        ||($template['name']??'')!==($alias?'Poison rudimentaire':'Fiole de poison mortel')||($template['kind']??'')!=='object'
        ||$alias&&array_key_exists('image',$template)&&$template['image']!==null&&$template['image']!==''
        ||($template['description']??'')!==''||array_key_exists('size',$template)&&(!is_int($template['size'])&&!is_float($template['size'])||$template['size']!=40)
        ||array_key_exists('category',$template)&&!in_array($template['category'],['usable','utility'],true)
        ||array_diff(array_keys($template),['id','name','kind','description','image','size','permissions','category','active','potionColor'])
        ||$alias&&array_key_exists('potionColor',$template)&&$template['potionColor']!=='#83e31d')return false;
    if(array_key_exists('permissions',$template)){
        if(!is_array($template['permissions']))return false;
        foreach($template['permissions']as$key=>$value)if(!array_key_exists($key,$permissions)||$permissions[$key]!==$value)return false;
    }
    $active=$template['active']??null;$effects=$active['effects']??null;$effect=is_array($effects)?($effects[0]??null):null;
    return is_array($active)&&($active['version']??null)===1&&($active['authority']??'')==='gm'
        &&($active['useLabel']??'')==='Utiliser · arbitrage MJ'&&($active['ingredientTag']??'')==='poison-vial'
        &&!array_diff(array_keys($active),['version','effects','useLabel','authority','ingredientTag'])
        &&is_array($effects)&&count($effects)===1&&is_array($effect)&&($effect['kind']??'')==='message'
        &&($effect['text']??'')==='Fiole de poison'&&($effect['theme']??'')==='poison'&&($effect['durationMs']??null)===1500
        &&!array_diff(array_keys($effect),['kind','text','theme','durationMs']);
}
function applicationRemapKnownItemIds(mixed $ids,string $aliasId,string $targetId):mixed{
    if(!is_array($ids))return $ids;$result=[];$seen=[];
    foreach($ids as$id){$mapped=$id===$aliasId?$targetId:$id;if(!is_string($mapped)){$result[]=$mapped;continue;}if(array_key_exists($mapped,$seen)&&$seen[$mapped]!==$id)continue;$seen[$mapped]=$id;$result[]=$mapped;}
    return $result;
}
function applicationRemapLargeHealingIds(mixed $ids):mixed{
    return applicationRemapKnownItemIds($ids,APPLICATION_LARGE_HEALING_ALIAS_ID,APPLICATION_LARGE_HEALING_TEMPLATE_ID);
}
function applicationMergeKnownItemTemplateAlias(array $value,string $aliasId,string $targetId,callable $recognizeAlias,callable $recognizeTarget):array{
    $aliases=array_values(array_filter($value['templates'],static fn($t)=>is_array($t)&&($t['id']??'')===$aliasId));
    $targets=array_values(array_filter($value['templates'],static fn($t)=>is_array($t)&&($t['id']??'')===$targetId));
    if(count($aliases)!==1||count($targets)!==1||!$recognizeAlias($aliases[0])||!$recognizeTarget($targets[0]))return $value;
    $aliasItemIds=[];
    foreach($value['instances']as$item)if(is_array($item)&&(($item['templateId']??'')===$aliasId||($item['vesselContentTemplateId']??'')===$aliasId||($item['vesselRequest']['potionTemplateId']??'')===$aliasId))$aliasItemIds[$item['id']??'']=true;
    foreach(is_array($value['pendingUses']??null)?$value['pendingUses']:[]as$use)if(is_array($use)&&(isset($aliasItemIds[$use['itemId']??''])||($use['templateId']??'')===$aliasId||($use['potionTemplateId']??'')===$aliasId))return $value;
    $value['templates']=array_values(array_filter($value['templates'],static fn($t)=>!is_array($t)||($t['id']??'')!==$aliasId));
    $value['instances']=array_map(static function($item)use($aliasId,$targetId){
        if(!is_array($item))return $item;
        if(($item['templateId']??null)===$aliasId)$item['templateId']=$targetId;
        if(($item['vesselContentTemplateId']??'')===$aliasId)$item['vesselContentTemplateId']=$targetId;
        if(is_array($item['vesselRequest']??null)&&($item['vesselRequest']['potionTemplateId']??'')===$aliasId)$item['vesselRequest']['potionTemplateId']=$targetId;
        return $item;
    },$value['instances']);
    if(is_array($value['inventoryCatalog']??null))foreach($value['inventoryCatalog']as$unit=>$ids)$value['inventoryCatalog'][$unit]=applicationRemapKnownItemIds($ids,$aliasId,$targetId);
    if(is_array($value['favorites']??null)){
        $favorites=[];$seen=[];
        foreach($value['favorites']as$entry){
            if(!is_array($entry)){$favorites[]=$entry;continue;}
            $original=$entry['templateId']??null;$mapped=$original===$aliasId?$targetId:$original;$key=json_encode([$entry['accountId']??null,$entry['unitId']??null,$mapped]);
            if(array_key_exists($key,$seen)&&$seen[$key]!==$original)continue;$seen[$key]=$original;
            if(array_key_exists('templateId',$entry))$entry['templateId']=$mapped;$favorites[]=$entry;
        }
        $value['favorites']=$favorites;
    }
    return $value;
}
function mergeKnownItemTemplateAliases(?array $value):?array{
    if($value===null||($value['version']??1)!==1||!is_array($value['templates']??null)||!is_array($value['instances']??null))return $value;
    $value=applicationMergeKnownItemTemplateAlias($value,APPLICATION_LARGE_HEALING_ALIAS_ID,APPLICATION_LARGE_HEALING_TEMPLATE_ID,'applicationRecognizedLargeHealingAlias','applicationRecognizedLargeHealingCanonical');
    return applicationMergeKnownItemTemplateAlias($value,APPLICATION_POISON_ALIAS_ID,APPLICATION_POISON_TEMPLATE_ID,
        static fn(array $t):bool=>applicationRecognizedPoisonTemplate($t,true),static fn(array $t):bool=>applicationRecognizedPoisonTemplate($t,false));
}
