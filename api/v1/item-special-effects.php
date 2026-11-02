<?php
declare(strict_types=1);
require_once __DIR__.'/stackable-effects.php';
require_once __DIR__.'/armor-reduction.php';
function applicationSpecialItemEffect(array $value):?array {
    if(($value['kind']??'')==='stackable-effect'){
        if(!isset(applicationStackableEffects()[$value['effectId']??''])||!is_int($value['stacks']??null)||$value['stacks']<1||$value['stacks']>60)throw new RuntimeException('Choisissez un effet cumulable et de 1 à 60 stacks.',400);
        return ['kind'=>'stackable-effect','effectId'=>$value['effectId'],'stacks'=>$value['stacks']];
    }
    if(($value['kind']??'')==='arbitration-zone'){
        if(!is_string($value['label']??null)||trim($value['label'])===''||count(preg_split('//u',$value['label'],-1,PREG_SPLIT_NO_EMPTY))>120||!is_int($value['turns']??null)||$value['turns']<1||$value['turns']>999||preg_match('/^#[a-f0-9]{6}$/iD',$value['color']??'')!==1)throw new RuntimeException('Réglez le nom, la couleur et la durée de la zone à arbitrer.',400);
        return ['kind'=>'arbitration-zone','label'=>trim($value['label']),'turns'=>$value['turns'],'color'=>strtolower($value['color'])];
    }
    return null;
}
function applicationApplyStackableItemEffect(array &$state,array $action,?array $token,array $context):void {
    if(!$token)throw new RuntimeException('Placez le pion sur la carte pour lui accorder cette protection.',409);
    if(count($state['nextAttackGuards']??[])+$action['stacks']>60)throw new RuntimeException('La liste des protections est pleine.',409);
    for($i=0;$i<$action['stacks'];$i++)$state['nextAttackGuards'][]=applicationMakeStackableEffect($action['effectId'],$state['activeSceneId'],$token['id'],($context['id'])('item-protection'));
}
function applicationAppendItemArbitrationZone(array &$state,array $template,array $action,int $index,array $point,string $sourceId,array $context):void {
    $area=applicationFixedEffectArea([...$point,'radiusCells'=>$template['active']['targeting']['radiusCells']??null]);
    if($area===null)throw new RuntimeException('Choisissez une zone valide.',409);
    if(count($state['attackEnhancements']??[])>=100)throw new RuntimeException('Trop d’effets actifs.',409);
    $id=($context['id'])('item-zone');$clock=$state['initiative']??[];
    $state['attackEnhancements'][]=['id'=>$id,'mode'=>'arbitration-zone','holder'=>'zone:'.$id,'abilityId'=>'item-'.$template['id'],'label'=>$action['label'],'color'=>$action['color'],'sceneId'=>$state['activeSceneId'],'fixedArea'=>$area,'targetTokenId'=>'','casterTokenId'=>$sourceId,'turns'=>$action['turns'],'remainingTurns'=>$action['turns'],'combatId'=>!empty($clock['active'])?($clock['combatId']??''):'','lastTurnSerial'=>(int)($clock['turnSerial']??0),'lastRound'=>(int)($clock['round']??0),'stackGroup'=>'item-'.$template['id'],'effectIndex'=>$index];
}
function applicationPublicItemArbitrationZone(array $effect):?array {
    if(($effect['mode']??'')!=='arbitration-zone')return null;
    try{$definition=applicationSpecialItemEffect([...$effect,'kind'=>'arbitration-zone']);}catch(RuntimeException){return null;}
    $area=applicationFixedEffectArea($effect['fixedArea']??null);$remaining=$effect['remainingTurns']??0;
    return $area&&$definition&&is_int($remaining)&&$remaining>0&&$remaining<=$definition['turns']?['id'=>$effect['id'],'sceneId'=>$effect['sceneId'],...$area,'kind'=>'arbitration-zone','label'=>$definition['label'],'color'=>$definition['color'],'remainingTurns'=>$remaining]:null;
}
