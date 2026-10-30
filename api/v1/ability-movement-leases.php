<?php
declare(strict_types=1);
const APPLICATION_ABILITY_MOVEMENT_LEASE_LIMIT=1000;
function normalizeAbilityMovementLeases(mixed $value=[]):array{
    $fail=static function():never{throw new RuntimeException('Les verrous de déplacement des compétences sont invalides.',400);};
    $validId=static fn($id)=>is_string($id)&&preg_match('/^[A-Za-z0-9_-]{1,180}$/D',$id)===1;
    if(!is_array($value)||!array_is_list($value)||count($value)>APPLICATION_ABILITY_MOVEMENT_LEASE_LIMIT)$fail();
    $result=[];$seen=[];
    foreach($value as$lease){
        if(!is_array($lease)||!$validId($lease['id']??null)||isset($seen[$lease['id']])||!$validId($lease['sceneId']??null)||!$validId($lease['sourceTokenId']??null)
            ||!is_array($lease['tokenIds']??null)||!array_is_list($lease['tokenIds'])||count($lease['tokenIds'])<1||count($lease['tokenIds'])>100
            ||count(array_filter($lease['tokenIds'],$validId))!==count($lease['tokenIds'])||count(array_unique($lease['tokenIds']))!==count($lease['tokenIds'])||!in_array($lease['sourceTokenId'],$lease['tokenIds'],true)
            ||!is_int($lease['expiresAt']??null)||$lease['expiresAt']<0||$lease['expiresAt']>9007199254740991)$fail();
        $seen[$lease['id']]=true;$result[]=['id'=>$lease['id'],'sceneId'=>$lease['sceneId'],'sourceTokenId'=>$lease['sourceTokenId'],'tokenIds'=>$lease['tokenIds'],'expiresAt'=>$lease['expiresAt']];
    }
    return $result;
}
function validApplicationAbilityMovementLeases(mixed $value):bool{try{normalizeAbilityMovementLeases($value);return true;}catch(RuntimeException){return false;}}
function publicAbilityMovementLeases(mixed $value,array $context=[]):array{
    $visible=array_fill_keys($context['visibleTokenIds']??[],true);$now=$context['now']??(int)floor(microtime(true)*1000);$result=[];
    foreach(normalizeAbilityMovementLeases($value??[])as$lease){if($lease['sceneId']!==($context['sceneId']??'')||$lease['expiresAt']<=$now)continue;
        $tokenIds=array_values(array_filter($lease['tokenIds'],static fn($id)=>isset($visible[$id])));if(!$tokenIds)continue;
        $result[]=[...$lease,'sourceTokenId'=>in_array($lease['sourceTokenId'],$tokenIds,true)?$lease['sourceTokenId']:$tokenIds[0],'tokenIds'=>$tokenIds];}
    return $result;
}
