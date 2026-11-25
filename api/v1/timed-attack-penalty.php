<?php
declare(strict_types=1);
require_once __DIR__ . '/elemental-resistances.php';
function applicationTimedAttackPenalty(array $state,array $source,string $opponent,string $sceneId):int {
    $penalty=0;$owner=applicationAbilityCastingOwner($source)['characterId'];$resistanceTarget=null;
    foreach($state['devices']['timedConditions']??[] as $e)if(($e['sceneId']??'')===$sceneId&&($e['remainingTurns']??0)>0
        &&($e['sourceTokenId']??'')===$opponent&&(($e['targetTokenId']??'')===$source['id']||$owner!==''&&($e['targetCharacterId']??'')===$owner)
        &&!applicationElementalConditionSuppressed($resistanceTarget??=applicationElementalResistanceTarget($source,$state,$sceneId),$e['label']??null)
        &&is_int($e['attackPenalty']??null)&&$e['attackPenalty']>0&&$e['attackPenalty']<=100)$penalty=max($penalty,$e['attackPenalty']);
    return $penalty;
}
