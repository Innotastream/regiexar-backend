<?php
declare(strict_types=1);
function applicationMoraleConditionKey(string $v):string{return strtolower(strtr(trim($v),['é'=>'e','É'=>'e']));}
function applicationActivateMoraleCondition(array &$state,array $actor,array $args):array {
    if($actor['role']!=='gm')dsError('Seul le MJ peut activer cet effet sur le moral.',403);
    $ti=dsIndex($state['map']['tokens'],$args['tokenId']??'');if($ti<0)dsError('Ce pion n’existe plus.');$token=$state['map']['tokens'][$ti];$linked=unitUsesCharacterSheet($token);
    if($linked){$ci=dsIndex($state['characters'],$token['characterId']);if($ci<0)dsError('Cette unité n’existe plus.');$entity=&$state['characters'][$ci];}else $entity=&$state['map']['tokens'][$ti];
    $key=applicationMoraleConditionKey($args['condition']??'');$delta=$key==='doute'?-1:($key==='motive'?1:0);$conditions=$entity['conditions']??array_map('trim',explode(',',$entity['condition']??''));
    if(!$delta||!in_array($key,array_map('applicationMoraleConditionKey',$conditions),true))dsError('Cet effet doit être appliqué à l’unité avant son activation.');
    $levels=['extreme-low','Angoissé','Stressé','Normal','Détendu','Confiant','extreme-high'];$before=trim((string)($entity['morale']??'Normal'))?:'Normal';$i=array_search($before,$levels,true);
    if($i===false)dsError('Choisissez un niveau de moral dans la liste avant d’activer cet effet.');$after=$levels[max(0,min(count($levels)-1,$i+$delta))];
    $entity['morale']=$after;$entity['conditions']=array_values(array_filter($conditions,static fn($c)=>applicationMoraleConditionKey($c)!==$key));$entity['condition']=implode(', ',$entity['conditions']);$entity['_updatedAt']=(int)floor(microtime(true)*1000);$snapshot=$entity;unset($entity);
    if($linked){foreach($state['map']['tokens'] as &$t)if(unitUsesCharacterSheet($t)&&$t['characterId']===$token['characterId']){$t['morale']=$after;$t['conditions']=$snapshot['conditions'];$t['condition']=$snapshot['condition'];}unset($t);
        foreach(array_keys($state['scenes']??[]) as $si)if(isset($state['scenes'][$si]['combat']['map']['tokens']))foreach($state['scenes'][$si]['combat']['map']['tokens'] as &$t)if(unitUsesCharacterSheet($t)&&$t['characterId']===$token['characterId']){$t['morale']=$after;$t['conditions']=$snapshot['conditions'];$t['condition']=$snapshot['condition'];}unset($t);}
    $state['devices']['timedConditions']=array_values(array_filter($state['devices']['timedConditions'],static fn($c)=>!(($c['targetTokenId']??'')===$token['id']||$linked&&($c['targetCharacterId']??'')===$token['characterId'])||applicationMoraleConditionKey($c['label'])!==$key));
    return ['activated'=>true,'condition'=>$args['condition'],'tokenId'=>$token['id'],'unitId'=>unitIdForToken($token),'previousMorale'=>$before,'morale'=>$after,'delta'=>$delta];
}
