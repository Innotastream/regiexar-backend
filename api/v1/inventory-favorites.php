<?php
declare(strict_types=1);
function normalizeInventoryFavorites(mixed $value, ?array $templateIds=null): array {
    if(!is_array($value)||!array_is_list($value)||count($value)>20000)throw new RuntimeException('La liste des favoris est invalide.',400);
    $seen=[];$result=[];
    foreach($value as $entry){
        foreach(['accountId','unitId','templateId'] as $key)if(!is_array($entry)||!is_string($entry[$key]??null)||preg_match('/^[A-Za-z0-9_-]{1,180}$/D',$entry[$key])!==1)throw new RuntimeException('Ce favori est invalide.',400);
        $key=json_encode([$entry['accountId'],$entry['unitId'],$entry['templateId']],JSON_THROW_ON_ERROR);
        if(isset($seen[$key]))throw new RuntimeException('Ce favori est déjà présent.',400);$seen[$key]=true;
        if($templateIds===null||isset($templateIds[$entry['templateId']]))$result[]=array_intersect_key($entry,array_flip(['accountId','unitId','templateId']));
    }
    return $result;
}
