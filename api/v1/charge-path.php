<?php
declare(strict_types=1);
require_once __DIR__ . '/character-statistics.php';

function applicationCharge(mixed $v): ?array {
    if (!$v) return null;
    if (($v['version'] ?? 1) !== 1) applicationComplexAbilityFail('Cette charge provient d’une version plus récente.', 'complex_ability_definition_invalid', 400);
    $integer = static fn($value, $min, $max, $fallback) => is_int($value) && $value >= $min && $value <= $max ? $value : $fallback;
    return ['version'=>1,'targetStepId'=>substr(preg_replace('/[^A-Za-z0-9_-]/','',(string)($v['targetStepId']??'')),0,80),
        'statId'=>preg_match('/^character-stat-(force|dexterity|agility|spiritSocial|intelligence|spirit|instinct)$/D',(string)($v['statId']??'')) ? $v['statId'] : 'character-stat-force',
        'pathPenalty'=>$integer($v['pathPenalty']??null,0,100,10),'finalAttacks'=>$integer($v['finalAttacks']??null,1,12,2),'allowOpposition'=>($v['allowOpposition']??true)!==false,
        'finalCondition'=>dsRider($v['finalCondition']??null)];
}
function applicationChargeFail(string $message): never { applicationComplexAbilityFail($message,'complex_ability_charge_invalid'); }
function applicationChargeDistanceMeters(array $a,array $b,array $map): float {
    $d=applicationComplexMovementDimensions($map);$x=abs($b['x']-$a['x'])*$d['width']/(100*$d['grid']);$y=abs($b['y']-$a['y'])*$d['height']/(100*$d['grid']);
    return 2*max($x,$y)+min($x,$y);
}
function applicationChargeFinalTarget(array $execution,array $step,array $tokens): array {
    $allocations=$execution['stepStates'][$step['charge']['targetStepId']]['allocations']??[];
    if(count($allocations)!==1)applicationChargeFail('Choisissez une cible finale avant de charger.');
    $target=null;foreach($tokens as $t)if($t['id']===$allocations[0]['tokenId']){$target=$t;break;}
    if(!$target||!is_numeric($target['x']??null)||!is_numeric($target['y']??null)||$target['id']===$execution['sourceTokenId']||!empty($target['hidden'])||($target['hp']??0)<=0||($target['layerId']??'ground')!==$execution['layerId'])applicationChargeFail('La cible finale n’est plus disponible sur ce niveau.');
    return $target;
}
function applicationValidateChargePath(array $state,array $target,array $map,bool $atDestination=false,?array $source=null): void {
    $target=applicationTokenSpatialFootprint($target,$map);if($source!==null)$source=applicationTokenSpatialFootprint($source,$map);
    $d=applicationComplexMovementDimensions($map);$origin=$state['origin']??null;if(!$origin)applicationChargeFail('Le point de départ de la charge est absent.');
    $anchor=$state['chargeTarget']??$target;
    if(abs($anchor['x']-$target['x'])>1e-5||abs($anchor['y']-$target['y'])>1e-5)applicationChargeFail('La cible finale a bougé. Arrêtez cette charge et choisissez à nouveau sa destination.');
    $dx=($target['x']-$origin['x'])*$d['width']/100;$dy=($target['y']-$origin['y'])*$d['height']/100;$length=hypot($dx,$dy);
    if($length<1e-5)applicationChargeFail('La charge demande une cible située devant le lanceur.');$previous=0;
    foreach($state['points']??[] as $p){$x=($p['x']-$origin['x'])*$d['width']/100;$y=($p['y']-$origin['y'])*$d['height']/100;$progress=($x*$dx+$y*$dy)/$length;$offset=abs($x*$dy-$y*$dx)/$length;
        if($progress<$previous-1e-5||$progress>$length+1e-5||$offset>$d['grid']/2+1e-5)applicationChargeFail('La charge doit avancer en ligne droite vers sa cible finale.');$previous=$progress;}
    if($atDestination){$points=$state['points']??[];$end=$points[count($points)-1]??null;$reach=max($d['grid'],($source['size']??40)/2+($target['size']??40)/2);
        if(!$end||hypot(($end['x']-$target['x'])*$d['width']/100,($end['y']-$target['y'])*$d['height']/100)>$reach+1e-5)applicationChargeFail('Terminez la charge au contact de sa cible finale avant de confirmer.');}
}
function applicationChargeFirstContact(array $target,array $state,array $map): float {
    $target=applicationTokenSpatialFootprint($target,$map);
    $d=applicationComplexMovementDimensions($map);$radius=applicationComplexMovementAttackWidth($state,$map)/2+($target['size']??40)/2;$distance=0;$points=$state['points'];
    for($i=1;$i<count($points);$i++){$a=$points[$i-1];$b=$points[$i];$dx=($b['x']-$a['x'])*$d['width']/100;$dy=($b['y']-$a['y'])*$d['height']/100;$length=hypot($dx,$dy);if(!$length)continue;
        $x=($target['x']-$a['x'])*$d['width']/100;$y=($target['y']-$a['y'])*$d['height']/100;$projection=($x*$dx+$y*$dy)/$length;$perpendicular2=max(0,$x*$x+$y*$y-$projection*$projection);
        if($perpendicular2<=$radius*$radius+1e-7){$half=sqrt(max(0,$radius*$radius-$perpendicular2));$enter=$projection-$half;$exit=$projection+$half;
            if($exit>=-1e-7&&$enter<=$length+1e-7)return $distance+max(0,$enter);}$distance+=$length;}
    return INF;
}
function applicationChargeAttackPlan(array $execution,array $step,array $state,array $source,array $tokens,array $map): array {
    $final=applicationChargeFinalTarget($execution,$step,$tokens);applicationValidateChargePath($state,$final,$map,true,$source);$weapon=$source['weaponAttacks'][0]??null;
    if(empty($weapon['id']))applicationChargeFail('L’unité qui charge doit avoir une arme principale configurée.');
    if(!array_filter($source['stats']??[],static fn($s)=>($s['id']??'')===$step['charge']['statId']&&is_numeric($s['value']??null)))applicationChargeFail('La statistique de la charge est absente de cette unité.');
    $crossed=[];foreach($tokens as $t){if($t['id']===$source['id']||$t['id']===$final['id']||!is_numeric($t['x']??null)||!is_numeric($t['y']??null)||!empty($t['hidden'])||!empty($t['dimSilhouette'])||($t['layerId']??'ground')!==$execution['layerId']||($t['hp']??0)<=0||($t['maxHp']??0)<=0)continue;
        $distance=applicationChargeFirstContact($t,$state,$map);if(is_finite($distance))$crossed[]=['token'=>$t,'distance'=>$distance];}
    usort($crossed,static fn($a,$b)=>($a['distance']<=>$b['distance'])?:strcmp($a['token']['id'],$b['token']['id']));
    if(count($crossed)+$step['charge']['finalAttacks']>100)applicationChargeFail('Ce trajet contient trop de frappes pour une seule charge.');
    $opposed=empty($execution['noDefensePossible'])&&$step['charge']['allowOpposition']&&($execution['stepStates'][$step['charge']['targetStepId']]['opposed']??true)!==false;
    $allocations=[];$plan=[];foreach($crossed as $i=>$row){$t=$row['token'];$allocations[]=['tokenId'=>$t['id'],'targetName'=>$t['name']??'Cible','count'=>1];$plan[]=['tokenId'=>$t['id'],'attackId'=>$weapon['id'],'statId'=>$step['charge']['statId'],'opposed'=>$opposed,'thresholdPenalty'=>min(100,($i+1)*$step['charge']['pathPenalty']),'charge'=>true];}
    $allocations[]=['tokenId'=>$final['id'],'targetName'=>$final['name']??'Cible','count'=>$step['charge']['finalAttacks']];
    for($i=0;$i<$step['charge']['finalAttacks'];$i++)$plan[]=['tokenId'=>$final['id'],'attackId'=>$weapon['id'],'statId'=>$step['charge']['statId'],'opposed'=>$opposed,'thresholdPenalty'=>0,'charge'=>true,
        ...($i===$step['charge']['finalAttacks']-1&&!empty($step['charge']['finalCondition'])?['conditionRider'=>$step['charge']['finalCondition']]:[])];
    return ['allocations'=>$allocations,'attackPlan'=>$plan];
}
