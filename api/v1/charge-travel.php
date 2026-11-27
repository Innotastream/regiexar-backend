<?php
declare(strict_types=1);
require_once __DIR__.'/combat-range-policy.php';
require_once __DIR__.'/ability-mount-movement.php';
require_once __DIR__.'/ability-movement-lock.php';
function applicationChargeSame(array $a,array $b):bool {return abs($a['x']-$b['x'])<1e-5&&abs($a['y']-$b['y'])<1e-5;}
function applicationChargeTravelMap(array $map,string $layer):array {return [...$map,...($map['layers'][$layer]??[]),'activeLayerId'=>$layer,'tokens'=>$map['tokens']??[]];}
function applicationChargeTravelBlocker(array $token,array $from,array $to,array $map,bool $identifyTerrain=false):?string {
 $d=applicationComplexMovementDimensions($map);$r=($token['size']??40)/2;
 if($to['x']*$d['width']/100<$r||$to['x']*$d['width']/100>$d['width']-$r||$to['y']*$d['height']/100<$r||$to['y']*$d['height']/100>$d['height']-$r)return 'edge';
 $rawWalls=$map['walls']??null;
 $paintedWalls=validApplicationWallState($rawWalls)?$rawWalls:emptyApplicationWallState($d['width'],$d['height']);
 $walls=applicationTerrainBarrierWalls($paintedWalls,$map);
 $route=applicationResolveWallCollision($walls,$from,$to,$token['size']??40,$d['width'],$d['height'],false);
 if(!empty($route['blocked'])||!applicationChargeSame($route,$to)){
  if($identifyTerrain&&$walls!==$paintedWalls){
   $painted=applicationResolveWallCollision($paintedWalls,$from,$to,$token['size']??40,$d['width'],$d['height'],false);
   if(empty($painted['blocked'])&&applicationChargeSame($painted,$to))return 'terrain';
  }
  return 'wall';
 }
 $visible=is_callable($map['_chargeVisible']??null)?$map['_chargeVisible']:onlineVisiblePathPointTester($map['fog']??null,$map['visionMask']??['enabled'=>false]);$n=max(1,(int)ceil(hypot(($to['x']-$from['x'])*$d['width']/100,($to['y']-$from['y'])*$d['height']/100)/2));
 for($i=0;$i<=$n;$i++)if(!$visible($from['x']+($to['x']-$from['x'])*$i/$n,$from['y']+($to['y']-$from['y'])*$i/$n))return 'vision';
 return null;
}
function applicationChargeTravelAllowed(array $token,array $from,array $to,array $map):bool {
 return applicationChargeTravelBlocker($token,$from,$to,$map)===null;
}
function applicationBodyChargeLandingFree(array $source, array $point, array $tokens, array $map, array $ignored): bool
{
    $ignored = array_fill_keys([...$ignored, $source['id']], true);
    $d = applicationComplexMovementDimensions($map);
    foreach ($tokens as $token) {
        if (isset($ignored[$token['id']]) || !empty($token['mountedOnTokenId'])
            || ($token['layerId'] ?? 'ground') !== ($map['activeLayerId'] ?? 'ground')
            || !is_numeric($token['x'] ?? null) || !is_numeric($token['y'] ?? null)) continue;
        if (is_numeric($token['hp'] ?? null) && (float) $token['hp'] <= 0
            && empty($token['terrainBarrier']) && empty($token['fixedStructure']) && empty($token['immovable'])) continue;
        $distance = hypot(($point['x'] - $token['x']) * $d['width'] / 100, ($point['y'] - $token['y']) * $d['height'] / 100);
        if ($distance < (($source['size'] ?? 40) + ($token['size'] ?? 40)) / 2 - 1e-5) return false;
    }
    return true;
}
function applicationBodyChargeDestination(array $source, array $final, array $tokens, array $map, array $ignored): array
{
    $d = applicationComplexMovementDimensions($map);
    $dx = ($final['x'] - $source['x']) * $d['width'] / 100;
    $dy = ($final['y'] - $source['y']) * $d['height'] / 100;
    $distance = hypot($dx, $dy);
    if ($distance < 1e-5) applicationChargeFail('La charge demande une cible située devant le lanceur.');
    $contact = (($source['size'] ?? 40) + ($final['size'] ?? 40)) / 2;
    $reach = max($d['grid'], $contact);
    $ux = $dx / $distance; $uy = $dy / $distance;
    $free = function (float $back, float $side) use ($d, $source, $final, $tokens, $map, $ignored, $distance, $ux, $uy): ?array {
        if ($back > $distance + 1e-5) return null;
        $point = ['x'=>$final['x']-($ux*$back+$uy*$side)*100/$d['width'],
            'y'=>$final['y']-($uy*$back-$ux*$side)*100/$d['height']];
        return applicationBodyChargeLandingFree($source, $point, $tokens, $map, $ignored)
            && applicationChargeTravelAllowed($source, $source, $point, $map) ? $point : null;
    };
    foreach ([0, $d['grid']/8, -$d['grid']/8, $d['grid']/4, -$d['grid']/4, $d['grid']/2, -$d['grid']/2] as $side) {
        foreach ([$reach, ($reach+$contact)/2, $contact] as $radius) {
            if (abs($side) > $radius) continue;
            $back = min($distance, sqrt(max(0, $radius*$radius-$side*$side)));
            $point = $free($back, $side);
            if ($point !== null) return $point;
        }
    }
    $nominal = ['x'=>$final['x']-$ux*min($distance,$reach)*100/$d['width'],
        'y'=>$final['y']-$uy*min($distance,$reach)*100/$d['height']];
    $blocker = applicationChargeTravelBlocker($source, $source, $nominal, $map, true);
    applicationChargeFail(match ($blocker) {
        'wall', 'terrain' => 'Un obstacle coupe la ligne de charge.',
        'vision' => 'La ligne de charge traverse une zone non visible.',
        'edge' => 'Le point de contact dépasse le bord de la carte.',
        default => 'Aucune place libre au contact de la cible finale.',
    });
}
function applicationChargeClosest(array $p,array $a,array $b,array $d):array {$dx=($b['x']-$a['x'])*$d['width']/100;$dy=($b['y']-$a['y'])*$d['height']/100;$x=($p['x']-$a['x'])*$d['width']/100;$y=($p['y']-$a['y'])*$d['height']/100;$t=max(0,min(1,($x*$dx+$y*$dy)/(($dx*$dx+$dy*$dy)?:1)));return ['x'=>$a['x']+($b['x']-$a['x'])*$t,'y'=>$a['y']+($b['y']-$a['y'])*$t,'distance'=>hypot($x-$dx*$t,$y-$dy*$t),'progress'=>$t];}
function applicationChargePushAside(array $source,array $from,array $to,array $tokens,array $map,array $options=[]):array {
 if(empty($options['enabled'])||applicationChargeSame($from,$to))return [];
 $d=applicationComplexMovementDimensions($map);$dx=($to['x']-$from['x'])*$d['width']/100;$dy=($to['y']-$from['y'])*$d['height']/100;$length=hypot($dx,$dy);$nx=-$dy/$length;$ny=$dx/$length;$positions=[];$hits=[];$moves=[];
 foreach($tokens as$t)if($t['id']!==$source['id']&&empty($t['mountedOnTokenId'])&&($t['layerId']??'ground')===($source['layerId']??'ground')&&is_numeric($t['x']??null)&&is_numeric($t['y']??null)){$positions[$t['id']]=$t;if($t['id']===($options['finalTokenId']??''))continue;$p=applicationChargeClosest($t,$from,$to,$d);if($p['distance']<(($source['size']??40)+($t['size']??40))/2+2)$hits[]=['token'=>$t,'point'=>$p];}
 usort($hits,static fn($a,$b)=>($a['point']['progress']<=>$b['point']['progress'])?:strcmp($a['token']['id'],$b['token']['id']));if(count($hits)>100)applicationChargeFail('Trop de pions bloquent ce trajet.');
 foreach($hits as$hit){$t=$hit['token'];$p=$hit['point'];if(empty($options['allowHidden'])&&(!empty($t['hidden'])||!empty($t['dimSilhouette']))||!empty($t['immovable'])||!empty($t['terrainBarrier'])||!empty($t['fixedStructure'])||applicationAbilityMovementLock($map['_abilityExecutions']??[],$map['sceneId']??'',$t['id'],$tokens,null,$map['_abilityMovementLeases']??[])!==null)applicationChargeFail('Le trajet de la charge est bloqué.');
  $radius=(($source['size']??40)+($t['size']??40))/2+4;$side=(($t['x']-$p['x'])*$d['width']/100*$nx+($t['y']-$p['y'])*$d['height']/100*$ny)>=0?1:-1;$destination=null;
  for($i=0;$i<9&&$destination===null;$i++)foreach([$side,-$side]as$sign){$offset=$sign*(max($radius,($options['minimumSideCells']??0)*$d['grid'])+$i*$d['grid']*.5);$base=!empty($options['fromCurrentPosition'])?$t:$p;$candidate=['x'=>$base['x']+$nx*$offset*100/$d['width'],'y'=>$base['y']+$ny*$offset*100/$d['height']];if(!applicationChargeTravelAllowed($t,$t,$candidate,$map))continue;$blocked=false;foreach($positions as$other)if($other['id']!==$t['id']){$needed=(($t['size']??40)+($other['size']??40))/2+4;$start=hypot(($t['x']-$other['x'])*$d['width']/100,($t['y']-$other['y'])*$d['height']/100);$minimum=applicationChargeClosest($other,$t,$candidate,$d)['distance'];if(hypot(($candidate['x']-$other['x'])*$d['width']/100,($candidate['y']-$other['y'])*$d['height']/100)<$needed-1e-7||$minimum<min($needed,$start)-1e-7){$blocked=true;break;}}if($blocked||applicationChargeClosest($candidate,$from,$to,$d)['distance']<$radius-1e-7)continue;$destination=$candidate;break;}
  if($destination===null)applicationChargeFail('Aucun côté libre ne permet d’écarter un pion sans traverser un obstacle.');$positions[$t['id']]=[...$t,...$destination];$moves[]=['tokenId'=>$t['id'],'size'=>$t['size']??40,'from'=>['x'=>$t['x'],'y'=>$t['y']],'to'=>$destination];
  foreach($tokens as$rider)if(($rider['mountedOnTokenId']??'')===$t['id'])$moves[]=['tokenId'=>$rider['id'],'size'=>$rider['size']??40,'from'=>['x'=>$rider['x'],'y'=>$rider['y']],'to'=>$destination];
 }return $moves;
}
function applicationPlanStagedCharge(array $execution,array $step,array $source,array $tokens,array $map,int $now):array {
 if(empty($step['charge']['staged']))applicationChargeFail('Cette étape ne prépare pas de charge.');$stored=$execution['stepStates'][$step['id']]??[];
 if(applicationAbilityMovementIsBlocked($source,[...$map,'tokens'=>$tokens],$map['_abilityTimedConditions']??[],$execution['sceneId'],($step['mountedMovement']??false)===true))applicationChargeFail('Ce personnage est immobilisé jusqu’à la fin de son effet.');
 $logical=$source;$physical=applicationAbilityMovementSource($source,[...$map,'tokens'=>$tokens],($step['mountedMovement']??false)===true);$source=[...$source,'x'=>$physical['x'],'y'=>$physical['y'],'size'=>$physical['size']??40];unset($source['mountedOnTokenId'],$source['mountedAt']);$movementTokens=!empty($logical['mountedOnTokenId'])?array_values(array_filter($tokens,static fn($t)=>$t['id']!==$logical['mountedOnTokenId'])):$tokens;
 if(isset($stored['origin'])&&!applicationChargeSame($stored['origin'],$source))applicationChargeFail('Le lanceur a changé de position. Reprenez la charge depuis son départ.');
 $map=applicationChargeTravelMap([...$map,'tokens'=>$tokens,'sceneId'=>$execution['sceneId']],$execution['layerId']);$state=initialApplicationComplexMovementState($source,$now,$step['attackWidthCells']??0);$final=applicationTokenSpatialFootprint(applicationChargeFinalTarget($execution,$step,$tokens),$map);$state['chargeTarget']=['id'=>$final['id'],'x'=>$final['x'],'y'=>$final['y']];
 $body=applicationIsBodyCharge($execution);
 if($body&&array_key_exists('pathTargetTokenIds',$stored))$state['pathTargetTokenIds']=$stored['pathTargetTokenIds'];
 $d=applicationComplexMovementDimensions($map);$dx=($final['x']-$state['origin']['x'])*$d['width']/100;$dy=($final['y']-$state['origin']['y'])*$d['height']/100;$length=hypot($dx,$dy);$reach=max($d['grid'],($state['sourceSize']??40)/2+($final['size']??40)/2);$fraction=$length>0?max(0,($length-$reach)/$length):0;$destination=['x'=>$source['x']+($final['x']-$source['x'])*$fraction,'y'=>$source['y']+($final['y']-$source['y'])*$fraction];
 $ridingGroup=!empty($logical['mountedOnTokenId'])?applicationAbilityMovementTokenIds($logical,[...$map,'tokens'=>$tokens],true):[];
 if(in_array($final['id'],$ridingGroup,true))applicationChargeFail('Choisissez une cible hors de votre monture.');
 if($body)$destination=applicationBodyChargeDestination($source,$final,$movementTokens,$map,$ridingGroup);
 if(!applicationChargeTravelAllowed($source,$source,$destination,$map))applicationChargeFail('La ligne de charge traverse un obstacle ou une zone non visible.');$movement=appendApplicationComplexMovementPath($state,$source,$destination,[['x'=>$source['x'],'y'=>$source['y']],$destination],$map,$step['maximumMeters'],$now,min($step['maximumCells']??applicationChargeRangeCells(),applicationChargeRangeCells()));applicationValidateChargePath($movement,$final,$map,true,$source);
 $pushed=applicationChargePushAside($source,$source,$destination,$movementTokens,$map,['enabled'=>!$body&&($step['charge']['pushAside']??false),'finalTokenId'=>$final['id']]);$attackTokens=array_values(array_filter($tokens,static fn($t)=>$t['id']===$logical['id']||!in_array($t['id'],$ridingGroup,true)));return [...$movement,'planned'=>true,...applicationChargeAttackPlan($execution,$step,$movement,$source,$attackTokens,$map),'sideMoves'=>$pushed];
}
function applicationBodyChargeTargetAvailable(array $execution,array $strike,array $source,array $tokens): bool
{
    $target=applicationComplexAbilityTokenById($tokens,$strike['tokenId']);
    return $target!==null && empty($target['hidden']) && empty($target['dimSilhouette'])
        && ($target['layerId']??'ground')===$execution['layerId'] && ($target['hp']??0)>0
        && ($target['maxHp']??0)>0 && applicationChargeEnemy($source,$target);
}

function applicationStagedChargeTravel(array $execution,array $command,array $tokens,array $map):?array {
 $step=$execution['workflow']['steps'][$execution['currentStepIndex']]??[];$origin=null;foreach($execution['workflow']['steps']as$s)if(($s['id']??'')===($step['sourceStepId']??''))$origin=$s;
 if(($command['action']??'')!=='prepare-target'||($step['type']??'')!=='allocated-attacks'||empty($origin['charge']['staged']))return null;
 $state=$execution['stepStates'][$step['id']]??[];$movement=$execution['stepStates'][$origin['id']]??[];$done=0;foreach($state['targets']??[]as$t)$done+=count($t['attacks']??[]);$strike=$movement['attackPlan'][$done]??null;
 if(!empty($state['pendingTargetTokenId'])||!$strike||$strike['tokenId']!==($command['targetTokenId']??''))applicationChargeFail('La prochaine frappe de charge a changé.');
 try {
 $source=applicationComplexAbilityTokenById($tokens,$execution['sourceTokenId']);$expected=($movement['skippedAt']??null)===$done?$movement['skippedFrom']:($done?$movement['attackPlan'][$done-1]['movePoint']:$movement['origin']);
 if($source!==null&&applicationIsBodyCharge($execution)&&!applicationBodyChargeTargetAvailable($execution,$strike,$source,$tokens)){
  if(empty($strike['finalContact']))return null;
  applicationChargeFail('La cible finale n’est plus visible ou disponible au moment de la percussion.');
 }
 if(!$source)applicationChargeFail('Le lanceur ne se trouve plus sur son trajet de charge.');if(applicationAbilityMovementIsBlocked($source,[...$map,'tokens'=>$tokens],$map['_abilityTimedConditions']??[],$execution['sceneId'],($origin['mountedMovement']??false)===true))applicationChargeFail('Ce personnage est immobilisé jusqu’à la fin de son effet.');$logical=$source;$physical=applicationAbilityMovementSource($source,[...$map,'tokens'=>$tokens],($origin['mountedMovement']??false)===true);$source=[...$source,'x'=>$physical['x'],'y'=>$physical['y'],'size'=>$physical['size']??40];unset($source['mountedOnTokenId'],$source['mountedAt']);if(!empty($source['immovable'])||!empty($physical['immovable'])||!applicationChargeSame($source,$expected))applicationChargeFail('Le lanceur ne se trouve plus sur son trajet de charge.');
 $map=applicationChargeTravelMap([...$map,'tokens'=>$tokens],$execution['layerId']);$final=applicationChargeFinalTarget($execution,$origin,$tokens);applicationValidateChargePath($movement,$final,$map);if(!applicationChargeTravelAllowed($source,$source,$strike['movePoint'],$map))applicationChargeFail('Le prochain segment de charge est bloqué.');
 $group=applicationAbilityMovementTokenIds($logical,[...$map,'tokens'=>$tokens],($origin['mountedMovement']??false)===true);$others=array_values(array_filter($tokens,static fn($t)=>!in_array($t['id'],$group,true)&&$t['id']!==($logical['mountedOnTokenId']??'')));$body=applicationIsBodyCharge($execution);
 if($body&&!empty($strike['finalContact'])&&!applicationBodyChargeLandingFree($source,$strike['movePoint'],$others,$map,$group))applicationChargeFail('Le point de contact de la charge est désormais occupé.');
 $moves=applicationChargePushAside($source,$source,$strike['movePoint'],$others,$map,['enabled'=>!$body&&($origin['charge']['pushAside']??false),'finalTokenId'=>$final['id']]);foreach($group as $groupId){$t=applicationComplexAbilityTokenById($tokens,$groupId);$moves[]=['tokenId'=>$t['id'],'size'=>$t['size']??40,'from'=>['x'=>$t['x'],'y'=>$t['y']],'to'=>$strike['movePoint']];}
 return ['sourceTokenId'=>$source['id'],'from'=>['x'=>$source['x'],'y'=>$source['y']],'to'=>$strike['movePoint'],'size'=>$source['size']??40,'finalContact'=>$strike['finalContact'],'targetTokenId'=>$final['id'],'targetPoint'=>['x'=>$final['x'],'y'=>$final['y'],'size'=>$final['size']??40],...(!applicationChargeSame($source,$strike['movePoint'])&&!empty($logical['mountedOnTokenId'])&&($origin['mountedMovement']??false)!==true?['dismountTokenIds'=>[$logical['id']]]:[]),'moves'=>$moves];
 } catch (ApplicationComplexAbilityException $error) {
  if ($error->errorCode !== 'complex_ability_charge_invalid') throw $error;
  applicationComplexAbilityFail($error->getMessage(), 'complex_ability_charge_interrupted');
 }
}
function applicationChargeTravelTokens(array $tokens,?array $travel):array {if($travel===null)return $tokens;return array_map(static function($t)use($travel){foreach($travel['moves']as$m)if($m['tokenId']===$t['id']){$moved=[...$t,...$m['to']];if(in_array($t['id'],$travel['dismountTokenIds']??[],true))unset($moved['mountedOnTokenId'],$moved['mountedAt']);return $moved;}return $t;},$tokens);}
