<?php
declare(strict_types=1);
require_once __DIR__.'/item-catalog.php';
function applicationPotionCatalogueKey(string $name):string{return str_replace(['’',"'"],' ',itemNameKey($name));}
function applicationMarkConfiguredPotionTemplate(array $template):array {
    $rules=json_decode((string)file_get_contents(__DIR__.'/potion-catalog-presets.json'),true,512,JSON_THROW_ON_ERROR);
    if(in_array($template['kind']??'',['potion','object'],true))foreach($rules as $rule)foreach($rule['names'] as $name)if(applicationPotionCatalogueKey($name)===applicationPotionCatalogueKey($template['name']??''))return [...$template,'potionCatalogRevision'=>$template['potionCatalogRevision']??1];
    return $template;
}
function applicationPotionCatalogPresets(array $store):array {
    if(!is_array($store['templates']??null)||!is_array($store['instances']??null))return $store;
    static $rules=null;$rules??=json_decode((string)file_get_contents(__DIR__.'/potion-catalog-presets.json'),true,512,JSON_THROW_ON_ERROR);
    $pending=[];foreach($store['pendingUses']??[] as $use)foreach($store['instances'] as $item)if(($item['id']??'')===($use['itemId']??''))$pending[$item['templateId']]=true;
    foreach(array_unique(array_column($rules,'family')) as $family){
        $familyRules=array_values(array_filter($rules,static fn($rule)=>$rule['family']===$family));
        $matches=static function($rule)use(&$store){$result=[];foreach($store['templates'] as $i=>$template)if(in_array($template['kind']??'',['object','potion'],true)&&empty($template['equipment'])&&empty($template['sourceAbilityId'])&&empty($template['potionVessel']))foreach($rule['names'] as $name)if(applicationPotionCatalogueKey($name)===applicationPotionCatalogueKey($template['name']??'')){$result[$i]=$template;break;}return $result;};
        $needed=false;foreach($familyRules as $rule)foreach($matches($rule) as $template)if(empty($template['potionCatalogRevision'])&&!isset($pending[$template['id']]))$needed=true;
        if(!$needed)continue;
        foreach($familyRules as $rule){
            $existing=$matches($rule);
            foreach($existing as $i=>$template)if(empty($template['potionCatalogRevision'])&&!isset($pending[$template['id']]))$store['templates'][$i]=[...$template,'kind'=>$rule['kind'],'active'=>$rule['active'],'potionCatalogRevision'=>1,...($rule['kind']==='potion'?['potion'=>['hp'=>0,'mana'=>0,'fatigue'=>0]]:[])];
            if(!$existing&&count($store['templates'])<200&&!in_array($rule['id'],array_column($store['templates'],'id'),true))$store['templates'][]=['id'=>$rule['id'],'name'=>$rule['names'][0],'kind'=>$rule['kind'],'description'=>$rule['description']??'','active'=>$rule['active'],'potionCatalogRevision'=>1,...($rule['kind']==='potion'?['potion'=>['hp'=>0,'mana'=>0,'fatigue'=>0]]:[])];
        }
    }
    return $store;
}
