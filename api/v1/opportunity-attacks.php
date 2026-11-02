<?php
declare(strict_types=1);
require_once __DIR__.'/passive-abilities.php';
require_once __DIR__.'/unit-authority.php';
require_once __DIR__.'/equipment.php';
require_once __DIR__.'/complex-movement.php';
require_once __DIR__.'/combat-range-policy.php';
require_once __DIR__.'/token-mounts.php';
require_once __DIR__.'/weapon-turn-limits.php';

function applicationOpportunityOwner(array $token,array $state):array {
 if(unitUsesCharacterSheet($token))foreach([...($state['characters']??[]),...($state['myCharacters']??[])] as $c)if($c['id']===$token['characterId'])return $c;
 return $token;
}
function applicationOpportunityControllerId(array $token,array $state=[]):string {
 $owner=applicationUnitControllerId($token,$state);if($owner!=='')return $owner;
 $pilot=applicationMountPilot($state['map']['tokens']??[],$token);return $pilot?applicationUnitControllerId($pilot,$state):'';
}
function applicationOpportunityPlayerSide(array $token,array $state=[]):bool{return ($token['allied']??false)===true||applicationOpportunityControllerId($token,$state)!=='';}
function applicationOpportunityAlive(array $token,array $state):bool{$o=applicationOpportunityOwner($token,$state);return ($o['resources']['hp']??$o['hp']??$token['hp']??0)>0&&($o['healthOverride']??$token['healthOverride']??'')!=='dead';}
function applicationOpportunityWeapon(array $token,array $state=[]):?array{
 $o=applicationOpportunityOwner($token,$state);$best=null;$range=-1;$ranges=applicationSharedReachPolicy();
 foreach(equipmentAttackRows($o['weaponAttacks']??$token['weaponAttacks']??[],$o,$state) as $w){
  if(!empty($w['disabled'])||($w['projectile']??false)===true||($w['reach']??'')==='ranged'||weaponAttackLimitReason($state,$token,$w)!=='')continue;
  $r=$ranges[$w['reach']??'melee']??$ranges['melee'];if($r>$range){$best=$w;$range=$r;}
 }
 return $best;
}
function applicationOpportunityPassive(array $token,array $state):?array{
 foreach(applicationConfiguredPassiveAbilities(applicationOpportunityOwner($token,$state)) as $p)if($p['enabled']&&$p['kind']==='opportunity-attack')return $p;
 return null;
}
function applicationOpportunityExitPoint(array $map,array $source,array $mover,array $route,float $reach):?array{
 $a=applicationTokenSpatialFootprint($source,$map);$b=applicationTokenSpatialFootprint($mover,$map);$level=$map['layers'][$a['layerId']??'ground']??$map;
 $grid=(float)($map['gridSize']??0);$width=(float)($level['naturalWidth']??$map['naturalWidth']??0);$height=(float)($level['naturalHeight']??$map['naturalHeight']??0);
 if(!($grid>0&&$width>0&&$height>0))return null;
 $radius=((float)($a['size']??40)+(float)($b['size']??40))/2+$reach*$grid;$radii=['x'=>$radius*100/$width,'y'=>$radius*100/$height];
 for($i=1;$i<count($route);$i++){
  $p=$route[$i-1];$q=$route[$i];$lo=0.0;$hi=1.0;$miss=false;
  foreach($radii as $key=>$r){$d=$q[$key]-$p[$key];$min=$a[$key]-$r;$max=$a[$key]+$r;
   if(abs($d)<1e-12){if($p[$key]<$min-1e-9||$p[$key]>$max+1e-9)$miss=true;continue;}
   $t1=($min-$p[$key])/$d;$t2=($max-$p[$key])/$d;$lo=max($lo,min($t1,$t2));$hi=min($hi,max($t1,$t2));
  }
  if(!$miss&&$hi>=max(0,$lo)-1e-9&&$hi>=0&&$hi<1-1e-9)return ['x'=>$p['x']+($q['x']-$p['x'])*$hi,'y'=>$p['y']+($q['y']-$p['y'])*$hi];
 }
 return null;
}
function applicationOpportunityRequests(array $map,array $mover,array $start,array $destination,mixed $path,string $sceneId,?callable $controller=null,?callable $passives=null,array $state=[],?array $initiative=null):array {
 $initiative??=$state['initiative']??[];if(($initiative['active']??false)!==true||($mover['hidden']??false)||!empty($mover['terrainBarrier'])||!applicationOpportunityAlive($mover,$state))return [];
 $state=[...$state,'map'=>$map,'initiative'=>$initiative,'activeSceneId'=>$sceneId];
 $controller??=static fn($t)=>applicationUnitControllerId($t,$state);$side=static fn($t)=>($t['allied']??false)===true||$controller($t)!==''||applicationOpportunityControllerId($t,$state)!=='';
 $layer=applicationTokenSpatialFootprint($mover,$map)['layerId']??'ground';$route=[$start,...(is_array($path)?$path:[]),$destination];$out=[];
 foreach($map['tokens']??[] as $source){
  if($source['id']===$mover['id']||($source['hidden']??false)||!empty($source['terrainBarrier'])||(applicationTokenSpatialFootprint($source,$map)['layerId']??'ground')!==$layer||!applicationOpportunityAlive($source,$state)||$side($source)===$side($mover))continue;
  $passive=applicationOpportunityPassive($source,$state);$weapon=$passive?applicationOpportunityWeapon($source,$state):null;if(!$passive||!$weapon)continue;
  $reach=$passive['reachMode']==='fixed'?$passive['reachCells']:(applicationSharedReachPolicy()[$weapon['reach']??'melee']??applicationSharedReachPolicy()['melee']);
  $strike=applicationOpportunityExitPoint($map,$source,$mover,$route,$reach);if(!$strike)continue;
  foreach($state['devices']['pendingRolls']??[] as $p)if(($p['type']??'')==='opportunity'&&($p['sceneId']??'')===$sceneId&&($p['sourceTokenId']??'')===$source['id']&&($p['targetTokenId']??'')===$mover['id']&&applicationOpportunityIsLive($p,[...$state,'map'=>$map,'initiative'=>$initiative,'activeSceneId'=>$sceneId]))continue 2;
  $out[]=['id'=>'opportunity-'.randomToken(16),'type'=>'opportunity','sceneId'=>$sceneId,'layerId'=>$layer,'combatId'=>$initiative['combatId']??'','sourceTokenId'=>$source['id'],'tokenId'=>$source['id'],'targetTokenId'=>$mover['id'],'passiveId'=>$passive['id'],'label'=>$passive['name'],'weaponAttackId'=>$weapon['id'],'statId'=>$passive['statId'],
   'sourcePosition'=>['x'=>applicationTokenSpatialFootprint($source,$map)['x'],'y'=>applicationTokenSpatialFootprint($source,$map)['y']],'targetPosition'=>['x'=>$destination['x'],'y'=>$destination['y']],'strikeTargetPosition'=>$strike,'createdAt'=>(int)floor(microtime(true)*1000)];
 }
 return $out;
}
function applicationOpportunityIsLive(array $p,array $state=[]):bool{
 $map=$state['map']??[];$initiative=$state['initiative']??[];$tokens=array_column($map['tokens']??[],null,'id');$source=$tokens[$p['sourceTokenId']??'']??null;$target=$tokens[$p['targetTokenId']??'']??null;
 if(($initiative['active']??false)!==true||!empty($p['combatId'])&&$p['combatId']!==($initiative['combatId']??'')||($p['sceneId']??'')!==($state['activeSceneId']??'')||!$source||!$target)return false;
 if(($source['hidden']??false)||!empty($source['terrainBarrier'])||($target['hidden']??false)||!empty($target['terrainBarrier'])||!applicationOpportunityAlive($source,$state)||!applicationOpportunityAlive($target,$state)||applicationOpportunityPlayerSide($source,$state)===applicationOpportunityPlayerSide($target,$state))return false;
 $passive=applicationOpportunityPassive($source,$state);$weapon=applicationOpportunityWeapon($source,$state);$physicalSource=applicationTokenSpatialFootprint($source,$map);
 return $passive&&$passive['id']===($p['passiveId']??'')&&$weapon&&(empty($p['weaponAttackId'])||$weapon['id']===$p['weaponAttackId'])&&($physicalSource['layerId']??'ground')===($p['layerId']??'')&&(applicationTokenSpatialFootprint($target,$map)['layerId']??'ground')===($p['layerId']??'')&&(float)$physicalSource['x']===(float)($p['sourcePosition']['x']??-1)&&(float)$physicalSource['y']===(float)($p['sourcePosition']['y']??-1);
}
function applicationPublicOpportunityAlerts(array $store,?array $identity,array $characters,array $context,callable $visible):array{
 $initiative=$context['initiative']??[];if(empty($identity['id'])||($context['paused']??false)||($initiative['active']??false)!==true)return [];
 $tokens=$context['tokens']??[];$indexed=array_column($tokens,null,'id');$authority=$context['authorityState']??[];$sceneId=$context['sceneId']??'';$authorityMap=($authority['activeSceneId']??'')===$sceneId?($authority['map']??null):null;
 if($authorityMap===null)foreach($authority['scenes']??[] as $scene)if($scene['id']===$sceneId){$authorityMap=$scene['combat']['map']??null;break;}
 $state=[...$authority,'characters'=>$authority['characters']??$characters,'map'=>$authorityMap??[...($context['map']??[]),'tokens'=>$tokens],'initiative'=>$initiative,'activeSceneId'=>$sceneId];
 $authorityTokens=array_column($state['map']['tokens']??[],null,'id');
 $owned=static fn($t)=>applicationOpportunityControllerId($authorityTokens[$t['id']]??$t,$state)===$identity['id']||$authorityMap===null&&($t['ownedByYou']??false)===true;
 $out=[];foreach($store['pendingRolls']??[] as $p){
  if(($p['type']??'')!=='opportunity'||($p['sceneId']??'')!==($context['sceneId']??'')||($p['layerId']??'')!==($context['layerId']??'')||!applicationOpportunityIsLive($p,$state))continue;
  if(!isset($indexed[$p['sourceTokenId']],$indexed[$p['targetTokenId']]))continue;
  $a=$indexed[$p['sourceTokenId']];$b=$indexed[$p['targetTokenId']];if(!$owned($a)&&!$owned($b))continue;
  foreach([$a,$b] as $t)if(!in_array($t['id'],$context['visibleTokenIds']??[],true)||!$visible($t))continue 2;
  $out[]=[...array_intersect_key($p,array_fill_keys(['id','sceneId','layerId','sourceTokenId','targetTokenId','createdAt'],true)),'label'=>'Attaque d’opportunité'];
 }
 return $out;
}
