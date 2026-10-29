<?php
declare(strict_types=1);

function applicationEffectZonePolarity(array $effect): string {
    $values=array_values(is_array($effect['bonuses']??null)?$effect['bonuses']:[]);
    if(!empty($effect['criticalFailureAsFailure'])||in_array($effect['mode']??'',['immunity','echo','on-hit-effects','on-attack-dot'],true)||($effect['mode']??'')==='bonus'&&(float)($effect['formula']??0)>0)$values[]=1;
    $positive=false;$negative=false;foreach($values as $value){if(!is_int($value)&&!is_float($value))continue;$positive=$positive||$value>0;$negative=$negative||$value<0;}
    return $positive&&$negative?'mixed':($negative?'negative':($positive?'positive':'neutral'));
}

function applicationPlayerMoveMapProjection(array $projection,string $sceneId): array {
    $map=is_array($projection['map']??null)?$projection['map']:[];
    return ['sceneId'=>$sceneId,'layerId'=>(string)($map['activeLayerId']??'ground'),
        'visionMask'=>is_array($map['visionMask']??null)?$map['visionMask']:[],
        'lights'=>is_array($map['lights']??null)?$map['lights']:[],'lightGlow'=>$map['lightGlow']??null,
        'movementEffectZones'=>is_array($projection['movementEffectZones']??null)?$projection['movementEffectZones']:[]];
}

// The player receives geometry and eligibility for their own controllable
// units only. Names, numerical bonuses, artwork and hidden casters stay private.
function applicationPublicMovementEffectZones(array $state,array $sourceMap,array $publicTokens,string $sceneId,string $layerId,callable $visible,bool $paused=false): array {
    if($paused||$sceneId===''||$layerId==='')return [];
    $own=[];$clear=[];foreach($publicTokens as $token){if(!empty($token['dimSilhouette']))continue;$clear[$token['id']]=true;if(!empty($token['ownedByYou'])||!empty($token['controllable']))$own[$token['id']]=true;}
    if($own===[])return [];
    $controller=static fn($token)=>applicationUnitControllerId($token,$state);
    $tokens=$sourceMap['tokens']??[];$candidates=array_values(array_filter($tokens,static fn($token)=>isset($own[$token['id']??''])&&(applicationTokenSpatialFootprint($token,$sourceMap)['layerId']??'ground')===$layerId));
    $point=static function(?array $token)use($sourceMap):?array{if($token===null)return null;$position=applicationTokenSpatialFootprint($token,$sourceMap);foreach(['x','y']as $key)if((!is_int($position[$key]??null)&&!is_float($position[$key]??null))||!is_finite((float)$position[$key])||$position[$key]<0||$position[$key]>100)return null;return ['x'=>$position['x'],'y'=>$position['y'],'layerId'=>$position['layerId']??'ground'];};
    $result=[];
    foreach($state['attackEnhancements']??[] as $effect){
        if(!is_array($effect)||empty($effect['id'])||($effect['sceneId']??'')!==$sceneId||!is_int($effect['remainingTurns']??null)||$effect['remainingTurns']<1||$effect['remainingTurns']>($effect['turns']??0)||applicationAttackEnhancement($effect)===null)continue;
        $fixed=applicationFixedEffectArea($effect['fixedArea']??null);$caster=null;
        if(isset($effect['aura']))foreach($tokens as $token)if(($token['id']??'')===($effect['casterTokenId']??'')){$caster=$token;break;}
        $center=$fixed??$point($caster);if($center===null||$center['layerId']!==$layerId||$fixed===null&&!isset($effect['aura'])||!$visible($center['x'],$center['y'])||$fixed===null&&!isset($clear[$caster['id']??'']))continue;
        $ids=[];foreach($candidates as $token)if($fixed!==null||applicationDamageAreaAffects([...$effect['aura'],'affectAllies'=>!empty($caster['friendlyFire'])||$effect['aura']['affectAllies']],$caster['id'],$controller($caster),$token['id'],$controller($token)))$ids[]=$token['id'];
        if($ids===[])continue;
        $result[]=['id'=>$effect['id'],'sceneId'=>$sceneId,...$center,'radiusCells'=>$fixed['radiusCells']??$effect['aura']['radiusCells'],'origin'=>$fixed!==null?'fixed':'token','geometry'=>'footprints',...($fixed===null?['sourceTokenId'=>$caster['id']]:[]),'polarity'=>applicationEffectZonePolarity($effect),'recipientTokenIds'=>$ids];
    }
    foreach($state['devices']['devices']??[] as $device){
        if(!is_array($device)||empty($device['id'])||($device['status']??'')!=='active'||($device['definition']['kind']??'')!=='dome'||($device['sceneId']??'')!==$sceneId)continue;
        $radius=$device['definition']['radiusCells']??null;if(!is_int($radius)||$radius<1||$radius>100)continue;
        $carried=($device['mode']??'carried')!=='placed';$caster=null;
        if($carried){foreach($tokens as $token)if(($token['id']??'')===($device['sourceTokenId']??'')&&unitIdForToken($token)===($device['creatorCharacterId']??'')){$caster=$token;break;}
            if($caster===null)foreach($tokens as $token)if(unitIdForToken($token)===($device['creatorCharacterId']??'')&&($token['layerId']??'ground')===($device['layerId']??'ground')){$caster=$token;break;}}
        $center=$point($carried?$caster:$device);
        if($center===null||$center['layerId']!==$layerId||!$visible($center['x'],$center['y'])||$carried&&(!empty($caster['hidden'])||!isset($clear[$caster['id']??''])))continue;
        $ids=array_column($candidates,'id');if($ids===[])continue;
        $result[]=['id'=>'device-'.$device['id'],'sceneId'=>$sceneId,...$center,'radiusCells'=>$radius,'origin'=>$carried?'token':'fixed','geometry'=>'centers',...($carried?['sourceTokenId'=>$caster['id']]:[]),'polarity'=>'positive','recipientTokenIds'=>$ids];
    }
    return $result;
}
