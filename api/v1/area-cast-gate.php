<?php
declare(strict_types=1);
require_once __DIR__.'/cone-area.php';
require_once __DIR__.'/unit-authority.php';

function applicationChanceCastGate(mixed $value): ?array {
    if ($value === null) return null;
    if (!is_array($value) || ($value['version']??1)!==1 || ($value['kind']??'')!=='chance' || ($value['blockOn']??'critical-failure')!=='critical-failure' || ($value['defenseSelection']??'gm-agility')!=='gm-agility') throw new RuntimeException('Le contrôle de Chance de la zone est invalide.',400);
    return ['version'=>1,'kind'=>'chance','blockOn'=>'critical-failure','defenseSelection'=>'gm-agility'];
}
function applicationDamageVariants(mixed $value): array {
    if ($value === null) return [];
    if (!is_array($value) || !array_is_list($value) || count($value)<1 || count($value)>12) throw new RuntimeException('Les variantes de dégâts sont invalides.',400);
    $result=[];$seen=[];
    foreach($value as $row){if(!is_array($row)||!is_string($row['id']??null)||preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$row['id'])!==1||isset($seen[$row['id']])||!is_string($row['label']??null)||trim($row['label'])===''||preg_match('/^.{1,80}$/us',$row['label'])!==1||!in_array($row['damageType']??'', ['physical','magical','ignore'],true))throw new RuntimeException('Une variante de dégâts est invalide.',400);$seen[$row['id']]=true;$result[]=['id'=>$row['id'],'label'=>trim($row['label']),'damageType'=>$row['damageType']];}
    return $result;
}
function applicationAbilityItemRequirement(mixed $value): ?array {
    if($value===null)return null;
    if(!is_array($value)||!is_string($value['templateId']??null)||preg_match('/^[A-Za-z0-9_-]{1,180}$/D',$value['templateId'])!==1||!is_int($value['quantity']??1)||($value['quantity']??1)<1||($value['quantity']??1)>10||($value['consumeOn']??'attempt')!=='attempt')throw new RuntimeException('Le composant requis est invalide.',400);
    return ['templateId'=>$value['templateId'],'quantity'=>$value['quantity']??1,'consumeOn'=>'attempt'];
}
function applicationSelectedDamageVariant(array $ability,mixed $id): ?array {
    $variants=applicationDamageVariants($ability['damageVariants']??null);
    if($variants===[]){if($id!==null&&$id!=='')throw new RuntimeException('Cette capacité ne possède pas de variante.',400);return null;}
    foreach($variants as $variant)if($variant['id']===$id)return $variant;
    throw new RuntimeException('Choisissez une nature de dégâts prévue par cette capacité.',400);
}
function applicationRequiredAbilityItems(array $store,array $source,?array $requirement): array {
    if($requirement===null)return [];
    $template=null;foreach($store['templates']??[] as $row)if(($row['id']??'')===$requirement['templateId'])$template=$row;
    if($template===null)throw new RuntimeException('Le modèle du composant requis n’existe plus.',409);
    $unitId=unitIdForToken($source);$reserved=[];
    foreach($store['pendingUses']??[] as $use)foreach($use['itemIds']??[($use['itemId']??'')] as $id)$reserved[$id]=true;
    $ids=[];foreach($store['instances']??[] as $item)if(($item['templateId']??'')===$requirement['templateId']&&($item['location']['kind']??'')==='inventory'&&($item['location']['characterId']??'')===$unitId&&!isset($reserved[$item['id']]))$ids[]=$item['id'];
    if(count($ids)<$requirement['quantity'])throw new RuntimeException('Le porteur ne possède pas assez de composants requis dans son inventaire.',409);
    return array_slice($ids,0,$requirement['quantity']);
}
function applicationConsumeAbilityItems(array &$store,array $source,?array $requirement): array {
    $ids=applicationRequiredAbilityItems($store,$source,$requirement);
    foreach($store['instances']??[] as $i=>$item)if(in_array($item['id'],$ids,true)){$store['instances'][$i]['location']=['kind'=>'consumed','characterId'=>unitIdForToken($source)];$store['instances'][$i]['revision']++;}
    return $ids;
}
function onlineConsumeAbilityItems(PDO $connection,array &$records,array &$pending,array $source,?array $requirement): array {
    if($requirement===null)return [];
    $records=array_replace(applicationDomainRecords($connection,['items']),$records);
    $store=$pending['items']['payload']??applicationDomainPayload($records,'items');
    $ids=applicationConsumeAbilityItems($store,$source,$requirement);
    queueOnlineDomainUpsert($pending,$records,'items',$store);
    return $ids;
}
function applicationApprovedAreaDefenseIds(array $gate,mixed $values): array {
    if(!is_array($values)||!array_is_list($values)||count($values)>100||count(array_unique($values,SORT_REGULAR))!==count($values))throw new RuntimeException('La sélection des esquives est invalide.',400);
    foreach($values as $id)if(!is_string($id)||!in_array($id,$gate['targetTokenIds']??[],true))throw new RuntimeException('Seules les unités prises dans le cône peuvent recevoir une esquive.',400);
    sort($values,SORT_STRING);return $values;
}
