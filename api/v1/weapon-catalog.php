<?php
declare(strict_types=1);
function applicationWeaponCatalogPresetsData():array{
 return [
  'v370-template-d29417860cce105b93bb7f17'=>['revision'=>1,'formula'=>'2d20+10','legacyFormulas'=>['1d30+6'],
   'damageOverTime'=>['label'=>'Saignement · Fouet lacérant','formula'=>'1d5','turns'=>1,'damageType'=>'bleeding','family'=>'bleeding']],
  'v370-template-d810e782aa535bc6422e6ebf'=>['revision'=>1,'damageType'=>'fire'],
  'v370-template-6aacb2d60e4cfeec7227d9b6'=>['revision'=>1,'damageType'=>'fire'],
  'v370-template-82f161523d5dbfa66b734000'=>['revision'=>1,'damageType'=>'ice'],
  'v370-template-871109782031b043f4adb048'=>['revision'=>1,'damageType'=>'ice'],
 ];
}
function applicationWeaponCatalogDamageOverTime(string $templateId): ?array {
 return applicationWeaponCatalogPresetsData()[$templateId]['damageOverTime']??null;
}
function applicationWeaponCatalogPresets(array $store):array{
 if(!is_array($store['templates']??null)||!is_array($store['instances']??null))return $store;
 $rules=applicationWeaponCatalogPresetsData();$upgraded=[];$pending=[];
 foreach($store['pendingUses']??[] as $use)foreach($store['instances'] as $item)if(($item['id']??null)===($use['itemId']??null))$pending[$item['templateId']]=true;
 $key=static fn($v)=>strtolower(preg_replace('/\s+/','',(string)$v));
 foreach($store['templates'] as &$template){
  $rule=$rules[$template['id']??'']??null;
  if(!$rule||($template['equipment']['kind']??'')!=='weapon'||($template['weaponCatalogRevision']??0)>=$rule['revision']||isset($pending[$template['id']]))continue;
  $upgraded[$template['id']]=['previous'=>$key($template['equipment']['formula']??''),'rule'=>$rule];
  $template['weaponCatalogRevision']=$rule['revision'];
  if(isset($rule['damageType']))$template['equipment']['damageType']=$rule['damageType'];
  if(isset($rule['formula'])){
   $template['equipment']['formula']=$rule['formula'];
   if(is_string($template['description']??null))$template['description']=preg_replace('/\b1d30\s*\+\s*6\b/i',$rule['formula'],$template['description']);
  }
 }unset($template);
 if(!$upgraded)return $store;
 foreach($store['instances'] as &$item){
  $change=$upgraded[$item['templateId']??'']??null;
  if(!$change||($item['equipment']['kind']??'')!=='weapon'||!is_int($item['revision']??null)||$item['revision']<1)continue;
  if(isset($change['rule']['damageType'])){
   if(($item['equipment']['damageType']??null)!==$change['rule']['damageType']){
    $item['equipment']['damageType']=$change['rule']['damageType'];
    $item['revision']++;
   }
   continue;
  }
  $current=$key($item['equipment']['formula']??'');
  if($current!==$change['previous']&&!in_array($current,$change['rule']['legacyFormulas'],true)||$current===$change['rule']['formula'])continue;
  $item['equipment']['formula']=$change['rule']['formula'];$item['revision']++;
 }unset($item);return $store;
}
