<?php
declare(strict_types=1);

function applicationArmorReductionDefinition(mixed $value): ?array {
    if (!is_array($value) || ($value['kind'] ?? '') !== 'armor-reduction') return null;
    $physical=array_key_exists('physical',$value)?$value['physical']:0;$magical=array_key_exists('magical',$value)?$value['magical']:0;
    $turns=$value['turns']??null;$scope=array_key_exists('scope',$value)?$value['scope']:'target';
    if (!is_int($physical)||$physical<0||$physical>100||!is_int($magical)||$magical<0||$magical>100||$physical+$magical===0
        ||!is_int($turns)||$turns<1||$turns>999||!in_array($scope,['target','zone'],true)) return null;
    return ['kind'=>'armor-reduction','physical'=>$physical,'magical'=>$magical,'turns'=>$turns,'scope'=>$scope];
}
function applicationArmorReductionBonuses(array $action): array {
    $result=[];if($action['physical']>0)$result['physicalResistance']=-$action['physical'];if($action['magical']>0)$result['magicalResistance']=-$action['magical'];return $result;
}
function applicationFixedEffectArea(mixed $value): ?array {
    if(!is_array($value))return null;
    foreach(['x','y','radiusCells'] as $key)if(!(is_int($value[$key]??null)||is_float($value[$key]??null))||!is_finite((float)$value[$key]))return null;
    if($value['x']<0||$value['x']>100||$value['y']<0||$value['y']>100||$value['radiusCells']<0.5||$value['radiusCells']>20
        ||floor($value['radiusCells']*2)!=$value['radiusCells']*2||!in_array($value['layerId']??'', ['ground','upper','basement'],true))return null;
    return array_intersect_key($value,array_flip(['x','y','layerId','radiusCells']));
}
function applicationHasArmorReductionZone(array $active): bool {
    foreach($active['effects']??[] as $action)if(($action['kind']??'')==='armor-reduction'&&($action['scope']??'target')==='zone')return true;return false;
}
function applicationAppendArmorReductionZone(array &$state,array $template,array $action,int $index,array $point,string $sourceId,array $context): void {
    $area=applicationFixedEffectArea([...$point,'radiusCells'=>$template['active']['targeting']['radiusCells']??null]);
    if($area===null)itemFailure('La position de cette zone a changé. Rouvrez l’objet.',409);
    $effects=$state['attackEnhancements']??[];if(count($effects)>=100)itemFailure('Trop d’effets actifs.',409);
    $id=($context['id'])('item-zone');$clock=$state['initiative']??[];
    $effects[]=['id'=>$id,'holder'=>'zone:'.$id,'abilityId'=>'item-'.$template['id'],'label'=>$template['name'],
        'sceneId'=>$state['activeSceneId'],'targetTokenId'=>'','casterTokenId'=>$sourceId,'mode'=>'stats','bonuses'=>applicationArmorReductionBonuses($action),
        'turns'=>$action['turns'],'remainingTurns'=>$action['turns'],'fixedArea'=>$area,'abilityImage'=>$template['image']??'',
        'combatId'=>!empty($clock['active'])?($clock['combatId']??''):'','lastTurnSerial'=>(int)($clock['turnSerial']??0),
        'lastRound'=>!empty($clock['active'])?max(1,(int)($clock['round']??1)):0,'stackGroup'=>'item-'.$template['id'],'effectIndex'=>$index];
    $state['attackEnhancements']=$effects;
}
function applicationFixedAreaAffects(array $effect,array $source,array $state,string $sceneId): bool {
    $area=applicationFixedEffectArea($effect['fixedArea']??null);if($area===null)return false;
    $map=($state['activeSceneId']??$state['activeScene']['id']??'')===$sceneId?($state['map']??[]):[];
    if($map===[])foreach($state['scenes']??[] as $scene)if(($scene['id']??'')===$sceneId)$map=$scene['combat']['map']??[];
    $tokens=$map['tokens']??[];$recipients=isset($source['resources'])?array_values(array_filter($tokens,static fn($token)=>applicationEnhancementHolder($token)===applicationEnhancementHolder($source))):[$source];
    // Hidden units still occupy the ground; visibility must not grant immunity.
    $recipients=array_map(static fn($token)=>[...$token,'hidden'=>false],$recipients);
    return onlineDamageAreaTargets($recipients,$area,$map,(float)$area['radiusCells'])!==[];
}
function applicationPublicArmorReductionZones(array $effects,string $sceneId,string $layerId,callable $visible): array {
    $result=[];foreach($effects as $effect){
        $area=applicationFixedEffectArea($effect['fixedArea']??null);$remaining=$effect['remainingTurns']??null;$bonuses=$effect['bonuses']??[];
        if($area===null||($effect['sceneId']??'')!==$sceneId||$area['layerId']!==$layerId||!is_int($remaining)||$remaining<1||$remaining>($effect['turns']??0)
            ||!is_string($effect['id']??null)||$effect['id']===''||strlen($effect['id'])>180
            ||($effect['mode']??'')!=='stats'||!is_array($bonuses)||!$bonuses||applicationAttackEnhancement($effect)===null)continue;
        $negative=false;foreach($bonuses as $key=>$amount){if(!in_array($key,['physicalResistance','magicalResistance'],true)||!is_int($amount)||$amount>0||$amount < -100)continue 2;if($amount<0)$negative=true;}
        if(!$negative)continue;
        if(!$visible($area['x'],$area['y']))continue;
        $result[]=['id'=>$effect['id'],'sceneId'=>$sceneId,...$area,'label'=>'Zone d’affaiblissement d’armure','remainingTurns'=>$remaining];
    }return array_slice($result,0,100);
}
