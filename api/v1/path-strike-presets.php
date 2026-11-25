<?php
declare(strict_types=1);
function applicationConfiguredPathStrikeAbility(array $source):array {
    if(($source['id']??'')!=='ability-ae84d88e-2469-45f5-b22e-b56971892753'||($source['effect']??'')!=='complex')return $source;
    $presetId='celestial-path-strike';
    $revision=($source['rulePreset']['id']??'')===$presetId?($source['rulePreset']['revision']??0):0;
    if($revision>=2)return $source;
    $steps=$source['workflow']['steps']??null;
    if(!is_array($steps)||count($steps)!==3)return $source;
    [$move,$targets,$strikes]=$steps;
    if(($move['type']??'')!=='movement'||($targets['type']??'')!=='targets'||($targets['sourceMovementStepId']??'')!==($move['id']??null)
        ||($strikes['type']??'')!=='allocated-attacks'||($strikes['sourceStepId']??'')!==($targets['id']??null)
        ||($strikes['damageMode']??'')!=='configured'||($strikes['hitMode']??'')!=='automatic')return $source;
    if($revision!==1){
        if(($move['maximumMeters']??null)!==20||!empty($move['maximumCells'])||!empty($move['charge'])||!empty($move['pathStrike'])
            ||($targets['minTargets']??null)!==0||($targets['maxTargets']??null)!==20||($targets['maximumPerTarget']??null)!==1
            ||($targets['targetRelation']??'')!=='enemies')return $source;
        $source['workflow']['steps'][0]['pathStrike']=['version'=>1,'order'=>'distance-to-end-desc','staged'=>true,'contactZone'=>['version'=>1,'geometry'=>'path','radiusCells'=>.08,'damage'=>['formula'=>'1d20','damageType'=>'magical'],'affectCaster'=>false,'affectAllies'=>false,'affectEnemies'=>true,'trigger'=>'contact-during-target-turn','maximumPerTargetTurn'=>1,'expiry'=>'source-next-turn-start','style'=>'silver-filament']];
    }
    $components=$strikes['damageComponents']??[];
    if(is_array($components)){
        foreach($components as $index=>$component){
            if(($component['type']??'')==='magical'&&empty($component['element']))$source['workflow']['steps'][2]['damageComponents'][$index]['type']='fire';
        }
    }
    $source['rulePreset']=['id'=>$presetId,'revision'=>2];
    return $source;
}
