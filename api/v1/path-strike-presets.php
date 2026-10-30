<?php
declare(strict_types=1);
function applicationConfiguredPathStrikeAbility(array $source):array {
    if(($source['id']??'')!=='ability-ae84d88e-2469-45f5-b22e-b56971892753'||($source['effect']??'')!=='complex'||($source['rulePreset']['id']??'')==='celestial-path-strike'&&($source['rulePreset']['revision']??0)>=1)return $source;
    $steps=$source['workflow']['steps']??null;if(!is_array($steps)||count($steps)!==3)return $source;[$move,$targets,$strikes]=$steps;
    if(($move['type']??'')!=='movement'||($move['maximumMeters']??null)!==20||!empty($move['maximumCells'])||!empty($move['charge'])||!empty($move['pathStrike'])||($targets['type']??'')!=='targets'||($targets['sourceMovementStepId']??'')!==$move['id']||($targets['minTargets']??null)!==0||($targets['maxTargets']??null)!==20||($targets['maximumPerTarget']??null)!==1||($targets['targetRelation']??'')!=='enemies'||($strikes['type']??'')!=='allocated-attacks'||($strikes['sourceStepId']??'')!==$targets['id']||($strikes['damageMode']??'')!=='configured'||($strikes['hitMode']??'')!=='automatic')return $source;
    $source['workflow']['steps'][0]['pathStrike']=['version'=>1,'order'=>'distance-to-end-desc','staged'=>true,'contactZone'=>['version'=>1,'geometry'=>'path','radiusCells'=>.08,'damage'=>['formula'=>'1d20','damageType'=>'magical'],'affectCaster'=>false,'affectAllies'=>false,'affectEnemies'=>true,'trigger'=>'contact-during-target-turn','maximumPerTargetTurn'=>1,'expiry'=>'source-next-turn-start','style'=>'silver-filament']];
    $source['rulePreset']=['id'=>'celestial-path-strike','revision'=>1];return $source;
}
