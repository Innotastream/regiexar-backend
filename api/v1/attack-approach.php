<?php
declare(strict_types=1);
require_once __DIR__.'/displacement-schema.php';
require_once __DIR__.'/charge-travel.php';
require_once __DIR__.'/ability-movement-lock.php';
require_once __DIR__.'/combat-range-policy.php';
require_once __DIR__.'/ability-mount-movement.php';
function applicationAttackApproachFailure(string $message):never{throw new RuntimeException($message,409);}
function applicationAttackApproachMovementSource(array $source,array $map,bool $mountedMovement=false):array{
    return applicationAbilityMovementSource($source,$map,$mountedMovement);
}
function applicationPlanAttackApproach(array $context,array $map,array $executions=[],array $leases=[],?int $now=null):?array {
    $config=applicationAttackApproach($context['attackApproach']??null);if($config===null)return null;$now??=(int)floor(microtime(true)*1000);
    $tokens=$map['tokens']??[];$byId=array_column($tokens,null,'id');$source=$byId[$context['sourceTokenId']??'']??null;$target=$byId[$context['targetTokenId']??'']??null;$chosen=$context['approachPoint']??null;
    $point=static fn($p)=>is_array($p)&&isset($p['x'],$p['y'])&&(is_int($p['x'])||is_float($p['x']))&&(is_int($p['y'])||is_float($p['y']))&&is_finite((float)$p['x'])&&is_finite((float)$p['y'])&&$p['x']>=0&&$p['x']<=100&&$p['y']>=0&&$p['y']<=100;
    if($source===null||$target===null||$source['id']===$target['id']||!$point($chosen))applicationAttackApproachFailure('Choisissez une case libre au contact de la cible.');
    $mount=!empty($source['mountedOnTokenId'])?($byId[$source['mountedOnTokenId']]??null):null;$dismount=$mount!==null&&($context['mountedMovement']??false)!==true;$body=applicationAttackApproachMovementSource($source,$map,($context['mountedMovement']??false)===true);
    if(!empty($source['mountedOnTokenId'])&&($mount===null||!empty($mount['mountedOnTokenId'])))applicationAttackApproachFailure('Cette monture ne permet pas un déplacement de compétence.');
    $mountId=$mount['id']??$body['id'];if(!empty($body['mountedOnTokenId'])||($target['mountedOnTokenId']??'')===$mountId||$target['id']===$mountId)applicationAttackApproachFailure('Cette cible ne permet pas une approche au contact.');
    $ids=array_fill_keys(applicationAbilityMovementTokenIds($source,$map,($context['mountedMovement']??false)===true),true);$group=array_values(array_filter($tokens,static fn($token)=>isset($ids[$token['id']])));
    foreach($group as $t)if(!empty($t['immovable'])||!empty($t['terrainBarrier'])||!empty($t['fixedStructure'])||applicationAbilityMovementLock($executions,$context['sceneId'],$t['id'],$tokens,$now,$leases)!==null)applicationAttackApproachFailure('Le pion est immobilisé ou un déplacement de compétence est déjà en cours.');
    $layer=$body['layerId']??'ground';if(($source['layerId']??$layer)!==$layer||($target['layerId']??'ground')!==$layer)applicationAttackApproachFailure('La cible a changé de niveau.');
    $level=[...$map,...($map['layers'][$layer]??[]),'tokens'=>$tokens,'activeLayerId'=>$layer];$physicalTarget=applicationTokenSpatialFootprint($target,$level);$destination=[...$body,'x'=>$chosen['x'],'y'=>$chosen['y']];$d=applicationComplexMovementDimensions($level);$radius=(($body['size']??40)+($physicalTarget['size']??40))/2;
    $distance=hypot(($chosen['x']-$physicalTarget['x'])*$d['width']/100,($chosen['y']-$physicalTarget['y'])*$d['height']/100);
    if(applicationMapDistanceInCells($body,$destination,$level,true,false)>applicationChargeRangeCells()+1e-7)applicationAttackApproachFailure('La destination dépasse la portée À distance de la charge.');
    if($distance<$radius-1e-6||applicationMapDistanceInCells($destination,$physicalTarget,$level,($map['grid']??false)===true,true)>1+1e-7)applicationAttackApproachFailure('Choisissez une case libre dans la première couronne au contact de la cible.');
    if(!applicationChargeTravelAllowed($body,$body,$destination,$level))applicationAttackApproachFailure('Le trajet vers cette case traverse un obstacle ou une zone non visible.');
    $ax=$body['x']*$d['width']/100;$ay=$body['y']*$d['height']/100;$dx=($chosen['x']-$body['x'])*$d['width']/100;$dy=($chosen['y']-$body['y'])*$d['height']/100;
    foreach($tokens as $other){if(isset($ids[$other['id']])||!empty($other['mountedOnTokenId'])||($other['layerId']??'ground')!==$layer||!is_numeric($other['x']??null)||!is_numeric($other['y']??null))continue;
        $ox=$other['x']*$d['width']/100-$ax;$oy=$other['y']*$d['height']/100-$ay;$t=max(0,min(1,($ox*$dx+$oy*$dy)/(($dx*$dx+$dy*$dy)?:1)));$needed=(($body['size']??40)+($other['size']??40))/2;$start=hypot($ox,$oy);$closest=hypot($ox-$t*$dx,$oy-$t*$dy);$end=hypot($ox-$dx,$oy-$dy);
        if($end<$needed-1e-6||$closest<min($needed,$start)-1e-6)applicationAttackApproachFailure('Cette case ou son trajet n’est pas libre.');
    }
    $copy=static fn($t)=>['x'=>$t['x'],'y'=>$t['y'],'size'=>(float)($t['size']??40)];
    return ['from'=>$copy($body),'to'=>$copy($destination),'path'=>[['x'=>$body['x'],'y'=>$body['y']],['x'=>$chosen['x'],'y'=>$chosen['y']]],'moved'=>hypot($dx,$dy)>1e-6,'durationMs'=>$config['durationMs'],'physicalTokenId'=>$body['id'],...(($context['mountedMovement']??false)===true?['mountedMovement'=>true]:[]),...($dismount?['dismountTokenIds'=>[$source['id']]]:[]),'moves'=>array_map(static fn($t)=>['tokenId'=>$t['id'],'from'=>$copy($dismount?$body:$t),'to'=>$copy([...$t,'x'=>$chosen['x'],'y'=>$chosen['y']])],$group)];
}
