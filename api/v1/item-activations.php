<?php
declare(strict_types=1);
require_once __DIR__.'/item-use-schema.php';
function itemDefinitionCanonical(mixed $v):mixed {if(!is_array($v))return $v;if(!array_is_list($v))ksort($v);return array_map('itemDefinitionCanonical',$v);}
function sameItemDefinition(mixed $a,mixed $b):bool{return itemDefinitionCanonical($a)===itemDefinitionCanonical($b);}
function normalizeItemUses(mixed $value=[]):array {
 if(!is_array($value)||!array_is_list($value)||count($value)>100)itemFailure('Trop d’activations d’objets en attente.',409);$seen=[];
 foreach($value as &$u){if(!is_array($u)||!itemId($u['id']??null)||isset($seen[$u['id']])||!itemId($u['itemId']??null)||!itemId($u['sceneId']??null)||!in_array($u['layerId']??'',['ground','upper','basement'],true)||!in_array($u['phase']??'',['trigger','opposition'],true)||!is_int($u['itemRevision']??null)||$u['itemRevision']<1||!is_array($u['targets']??null)||count($u['targets'])<1||count($u['targets'])>100)itemFailure('Activation d’objet invalide.',409);
 $targets=[];foreach($u['targets'] as $t){if(!itemId($t['tokenId']??null)||isset($targets[$t['tokenId']])||!in_array($t['status']??'',['pending','validation','affected','defended'],true))itemFailure('Cible d’activation invalide.',409);$targets[$t['tokenId']]=true;}$seen[$u['id']]=true;if(isset($u['definition']))$u['definition']=normalizeActiveItem($u['definition']);}unset($u);return $value;
}
function itemUseSource(array $state,array $item,array $args,array $actor):array {
 $map=$state['map'];$layer=$map['activeLayerId']??'ground';if(($args['sceneId']??'')!==$state['activeSceneId'])itemFailure('La scène a changé.',409);
 if($item['location']['kind']==='ground'){
  if($actor['role']!=='gm')itemFailure('Seul le MJ déclenche un objet posé.',403);
  if($item['location']['sceneId']!==$state['activeSceneId']||$item['location']['layerId']!==$layer)itemFailure('Cet objet n’est plus sur le niveau courant.',409);
  $template=[];foreach($state['items']['templates'] as $t)if($t['id']===$item['templateId']){$template=$t;break;}
  return ['id'=>'item-origin-'.$item['id'],'name'=>$template['name']??'Objet',...$item['location'],'size'=>$item['size']??$template['size']??40];
 }
 if($item['location']['kind']!=='inventory'||$item['location']['characterId']!==($args['characterId']??''))itemFailure('Cet objet n’est plus dans cet inventaire.',409);
 $unit=ownedUnit($state,$actor,$args['characterId']);foreach($map['tokens']??[] as $t)if(unitIdForToken($t)===$unit['id']&&(empty($args['tokenId'])||$t['id']===$args['tokenId'])&&($t['layerId']??'ground')===$layer&&empty($t['hidden']))return $t;
 return ['id'=>'item-owner-'.$unit['id'],'name'=>$unit['name'],'unitId'=>$unit['id'],'layerId'=>$layer];
}
function itemUseTargets(array $state,array $active,array $source,array $args,array $context=[]):array {
 $mode=$active['targeting']['mode']??'self';$map=$state['map'];$layer=$map['activeLayerId']??'ground';$target=null;foreach($map['tokens'] as $t)if($t['id']===($args['targetTokenId']??'')){$target=$t;break;}
 if($mode==='self'&&!str_starts_with($source['id'],'item-origin-'))return [$source];
 if(!isset($source['x'],$source['y']))itemFailure('Placez le pion sur le niveau courant pour choisir une cible.',409);
 $harmful=(bool)array_filter($active['effects'],static fn($e)=>in_array($e['kind'],['damage','dot','condition'],true)&&($e['timing']??'')!=='on-hit');
 $valid=static function($t)use($state,$layer,$harmful){if(!$t||!empty($t['hidden'])||($t['layerId']??'ground')!==$layer)return false;$unit=unitById($state,unitIdForToken($t));return $harmful?($unit['resources']['hp']??0)>0:($unit['resources']['maxHp']??0)>0&&($unit['healthOverride']??'')!=='dead';};
 $point=$mode==='area'&&($active['targeting']['origin']??'')==='source'?$source:($mode==='area'?applicationAreaTargetPoint($args['targetPoint']??null,$layer):$target);
 if(!$point||$mode!=='area'&&!$valid($target))itemFailure('Choisissez une cible présente sur ce niveau.',409);
 if(!applicationAbilityTargetInRange(['rangeCells'=>$active['targeting']['rangeCells']??15],$source,$point,$map))itemFailure('Cette cible ou cette zone est hors de portée.',409);
 if(isset($context['visible'])&&!($context['visible'])($point))itemFailure('Choisissez une cible ou une zone visible.',403);
 $targets=$mode==='area'?array_values(array_filter(onlineDamageAreaTargets($map['tokens'],$point,$map,(float)$active['targeting']['radiusCells'],($active['targeting']['origin']??'')==='source'),$valid)):[$target];
 if(!$targets||count($targets)>100)itemFailure('La zone ne touche aucune unité ou contient trop d’unités.',409);return $targets;
}
function finishItemUse(array &$state,array &$items,array $use,array $actor,array $context):array {
 $ii=-1;$template=null;foreach($items['instances'] as $i=>$item)if($item['id']===$use['itemId']){$ii=$i;break;}if($ii<0)itemFailure('Cet objet n’existe plus.',409);$item=$items['instances'][$ii];
 foreach($items['templates'] as $t)if($t['id']===$item['templateId']){$template=$t;break;}
 if($item['revision']!==$use['itemRevision']||!$template||!sameItemDefinition($template['active']??null,$use['definition']??null))itemFailure('L’objet ou ses effets ont changé. Annulez cette activation et rouvrez-la.',409);
 $result=['used'=>true,'itemName'=>$template['name'],'rolls'=>[],'messages'=>[],'healing'=>0,'damage'=>0];$targets=[];
 foreach($use['targets'] as $entry)if($entry['status']==='affected'){$token=null;foreach($state['map']['tokens'] as $t)if($t['id']===$entry['tokenId']&&($t['layerId']??'ground')===$use['layerId']){$token=$t;break;}if(!$token)itemFailure('Une cible a quitté ce niveau. Annulez puis relancez l’objet.',409);$targets[]=$token;}
 $summons=array_values(array_filter($template['active']['effects'],static fn($e)=>$e['kind']==='summon'));foreach($summons as $a){$found=false;foreach($state['tokenLibrary']??[] as $model)if($model['id']===$a['templateId'])$found=true;if(!$found)itemFailure('Le modèle de cette invocation n’existe plus dans le bestiaire.',409);}
 if($summons)validateItemSummonPlacement($state,$summons,$use['summonPoint']??$use['sourcePoint']);
 $effectTemplate=$template;$effectTemplate['active']['effects']=array_values(array_filter($template['active']['effects'],static fn($e)=>$e['kind']!=='summon'));
 foreach($targets as $token){$unit=unitById($state,unitIdForToken($token));$r=$effectTemplate['active']['effects']?applicationApplyActiveItem($state,$effectTemplate,$unit,$token,[...$actor,'role'=>'gm'],[...$context,'sourceTokenId'=>$use['sourceTokenId']??'']):['rolls'=>[],'messages'=>[],'healing'=>0];persistUnitResources($state,$unit);$result['rolls']=[...$result['rolls'],...$r['rolls']];$result['messages']=[...$result['messages'],...$r['messages']];$result['healing']+=$r['healing'];$result['damage']+=$r['damage']??0;}
 foreach($summons as $a){$owner=unitById($state,$use['sourceUnitId']??'',false);$new=applicationSummonItemToken($state,$a,$use['summonPoint']??$targets[0]??$use['sourcePoint'],$owner['ownerPlayerId']??$use['controllerAccountId']??'',($context['id'])('token'));$result['summonedTokenIds'][]=$new['id'];}
 $messages=[];foreach($result['messages'] as $m)$messages[json_encode($m)]=$m;$result['messages']=array_values($messages);
 if($item['location']['kind']==='ground'&&!empty($use['definition']['trap']['destroyAfterTrigger'])){$item['location']=['kind'=>'destroyed'];if(isset($item['health']))$item['health']['current']=0;}
 elseif($item['location']['kind']==='inventory'&&empty($use['definition']['reusable']))$item['location']=['kind'=>'consumed','characterId'=>$item['location']['characterId']];
 $item['revision']++;$items['instances'][$ii]=$item;$items['pendingUses']=array_values(array_filter($items['pendingUses']??[],static fn($u)=>$u['id']!==$use['id']));return [...$result,'item'=>$item];
}
function applyItemUseCommand(array &$state,array &$items,array $actor,string $command,array $args,array $context):array {
 $state['items']=$items;$items['pendingUses']??=[];$gm=$actor['role']==='gm';
 if(!empty($state['tacticalSync']['paused'])&&!$gm)itemFailure('La table est temporairement verrouillée par le MJ.',423);
 if($command==='item.activate'){
  $item=null;foreach($items['instances'] as $i)if($i['id']===($args['itemId']??'')){$item=$i;break;}if(!$item||$item['revision']!==($args['expectedItemRevision']??null))itemFailure('Cet objet a changé.',409);
  $template=null;foreach($items['templates'] as $t)if($t['id']===$item['templateId']){$template=$t;break;}$active=normalizeActiveItem($template['active']??null);if(empty($active['effects']))itemFailure('Cet objet n’a pas d’action utilisable.',409);
  if($active['authority']==='gm'&&!$gm)itemFailure('L’utilisation de cet objet est réservée au MJ.',403);
  foreach($items['pendingUses'] as $u)if($u['itemId']===$item['id'])itemFailure('Cet exemplaire attend déjà la résolution de son effet.',409);
  $source=itemUseSource($state,$item,$args,$actor);$summons=array_filter($active['effects'],static fn($e)=>$e['kind']==='summon');$point=null;
  if($summons){$point=applicationAreaTargetPoint($args['summonPoint']??null,$source['layerId']??'ground');if(!$point||!applicationAbilityTargetInRange(['rangeCells'=>$active['targeting']['rangeCells']??15],$source,$point,$state['map'])||isset($context['visible'])&&!($context['visible'])($point))itemFailure('Choisissez une position d’invocation visible à portée.',409);validateItemSummonPlacement($state,$summons,$point);}
  $targets=count($summons)===count($active['effects'])?[]:itemUseTargets($state,$active,$source,$args,$context);
  if($item['location']['kind']==='ground'&&!is_bool($args['opposed']??null))itemFailure('Choisissez avec ou sans opposition.',400);$opposed=($args['opposed']??false)===true&&count($targets)>0;
  if(count($items['pendingUses'])>=100)itemFailure('Traitez les activations d’objets en attente.',429);
  $use=['id'=>($context['id'])('item-use'),'itemId'=>$item['id'],'itemRevision'=>$item['revision'],'sceneId'=>$state['activeSceneId'],'layerId'=>$state['map']['activeLayerId']??'ground','name'=>$template['name'],'definition'=>$active,'sourceTokenId'=>$source['id'],'sourceUnitId'=>$args['characterId']??'','controllerAccountId'=>$gm?'':$actor['id'],'sourcePoint'=>['x'=>$source['x']??null,'y'=>$source['y']??null,'layerId'=>$source['layerId']??'ground'],...($point?['summonPoint'=>$point]:[]),'phase'=>'opposition','accountId'=>$actor['id'],'role'=>$actor['role'],'targets'=>array_map(static fn($t)=>['tokenId'=>$t['id'],'status'=>$opposed?'pending':'affected'],$targets)];
  foreach($targets as $t)if(str_starts_with($t['id'],'item-owner-'))itemFailure('Placez le pion de cette unité pour cette action.',409);
  $items['pendingUses'][]=$use;return $opposed?['pendingUse'=>$use['id'],'pendingOpposition'=>true]:finishItemUse($state,$items,$use,$actor,$context);
 }
 $ui=-1;foreach($items['pendingUses'] as $i=>$u)if($u['id']===($args['useId']??'')){$ui=$i;break;}if($ui<0)itemFailure('Cette activation n’est plus en attente.',404);$use=$items['pendingUses'][$ui];
 if($use['sceneId']!==$state['activeSceneId']||$use['layerId']!==($state['map']['activeLayerId']??'ground'))itemFailure('Revenez sur la scène et le niveau de cette activation.',409);
 if($command==='item.use.cancel'){if(!$gm)itemFailure('Seul le MJ peut annuler cette activation.',403);array_splice($items['pendingUses'],$ui,1);return ['cancelled'=>true];}
 if($command==='item.trap.resolve'){
  if(!$gm)itemFailure('Seul le MJ peut valider le passage sur un piège.',403);if($use['phase']!=='trigger')itemFailure('Ce piège a déjà été validé.',409);if(!is_bool($args['opposed']??null))itemFailure('Choisissez avec ou sans opposition.',400);
  $item=null;foreach($items['instances'] as $i)if($i['id']===$use['itemId']){$item=$i;break;}if(!$item)itemFailure('Ce piège n’existe plus.',409);
  $source=itemUseSource($state,$item,['sceneId'=>$state['activeSceneId']],['role'=>'gm']);
  if(($use['definition']['targeting']['mode']??'')==='area')$targets=itemUseTargets($state,$use['definition'],$source,['targetPoint'=>$use['crossingPoint']],$context);
  else{$targets=[];foreach($state['map']['tokens'] as $t)if($t['id']===$use['targets'][0]['tokenId']&&($t['layerId']??'ground')===$use['layerId']){$targets[]=$t;break;}if(!$targets)itemFailure('La cible qui a traversé le piège a quitté ce niveau.',409);}
  $use['phase']='opposition';$use['targets']=array_map(static fn($t)=>['tokenId'=>$t['id'],'status'=>$args['opposed']?'pending':'affected'],$targets);$items['pendingUses'][$ui]=$use;
  return $args['opposed']?['pendingUse'=>$use['id'],'pendingOpposition'=>true]:finishItemUse($state,$items,$use,$actor,$context);
 }
 $ti=-1;foreach($use['targets'] as $i=>$t)if($t['tokenId']===($args['targetTokenId']??'')){$ti=$i;break;}if($ti<0||$use['phase']!=='opposition')itemFailure('Cette cible n’attend pas d’opposition.',409);
 $entry=$use['targets'][$ti];$token=null;foreach($state['map']['tokens'] as $t)if($t['id']===$entry['tokenId']){$token=$t;break;}if(!$token||($token['layerId']??'ground')!==$use['layerId'])itemFailure('Cette cible n’est plus présente.',409);$unit=ownedUnit($state,$actor,unitIdForToken($token));$rolls=[];
 if($command==='item.opposition'){
  if($entry['status']!=='pending')itemFailure('Cette opposition a déjà été lancée.',409);$stat=null;foreach(applicationEnhancedStatRows(($unit['unitKind']??'')==='token'?$token:synchronizeOnlineCharacterToken($token,$unit),$state,$use['sceneId'],($unit['unitKind']??'')==='token'?null:$unit) as $s)if($s['id']===($args['statId']??'')){$stat=$s;break;}if(!$stat||!is_numeric($stat['value']))itemFailure('Choisissez une caractéristique de défense disponible.',400);
  $roll=($context['rollFormula'])('1d100',($use['name']??'Objet').' · '.$stat['label'],$unit['name']);$outcome=classifyOnlineD100Outcome($roll['rawD100']??$roll['total'],$stat['value'],0,0,true,true,applicationRollCriticalFailureProtected($token,$state,$use['sceneId']));
  $entry['outcome']=$outcome;$entry['rollId']=$roll['id'];$entry['status']=!empty($outcome['requiresGmValidation'])?'validation':(onlineDefenderWinsOpposition(['automatic'=>true,'success'=>true],$outcome)?'defended':'affected');$roll['outcome']=$outcome;$rolls[]=$roll;
 }elseif($command==='item.opposition.validate'){
  if(!$gm)itemFailure('Ce résultat remarquable attend la validation du MJ.',403);if($entry['status']!=='validation'||!is_bool($args['approve']??null))itemFailure('Ce résultat n’attend plus de validation.',409);
  if(!$args['approve']){$entry['status']='pending';unset($entry['outcome'],$entry['rollId']);$use['targets'][$ti]=$entry;$items['pendingUses'][$ui]=$use;return ['pendingUse'=>$use['id']];}$entry['status']=$entry['outcome']['success']?'defended':'affected';
 }else itemFailure('Action d’objet inconnue.',400);
 $use['targets'][$ti]=$entry;$items['pendingUses'][$ui]=$use;if(array_filter($use['targets'],static fn($t)=>in_array($t['status'],['pending','validation'],true)))return ['pendingUse'=>$use['id'],'rolls'=>$rolls];
 $result=finishItemUse($state,$items,$use,$actor,$context);$result['rolls']=[...$rolls,...$result['rolls']];return $result;
}
function queueItemTrapPassages(array &$state,array $mover,array $start,array $destination,?array $path,callable $id):array {
 if(empty($state['initiative']['active'])||empty($state['items']))return [];$layer=$mover['layerId']??'ground';$map=$state['map'];$level=$map['layers'][$layer]??$map;$w=$level['naturalWidth']??$map['naturalWidth']??0;$h=$level['naturalHeight']??$map['naturalHeight']??0;$grid=$map['gridSize']??0;if(!($w>0&&$h>0&&$grid>0))return [];
 $points=[$start,...($path??[]),$destination];$queued=[];$pendingUses=$state['items']['pendingUses']??[];
 $distance=static function($p,$a,$b)use($w,$h){$x=($p['x']-$a['x'])*$w/100;$y=($p['y']-$a['y'])*$h/100;$dx=($b['x']-$a['x'])*$w/100;$dy=($b['y']-$a['y'])*$h/100;$t=max(0,min(1,($x*$dx+$y*$dy)/($dx*$dx+$dy*$dy?:1)));return hypot($x-$dx*$t,$y-$dy*$t);};
 foreach($state['items']['instances'] as $item){if($item['location']['kind']!=='ground'||$item['location']['sceneId']!==$state['activeSceneId']||$item['location']['layerId']!==$layer||array_filter($pendingUses,static fn($u)=>$u['itemId']===$item['id']))continue;
  $template=null;foreach($state['items']['templates'] as $t)if($t['id']===$item['templateId']){$template=$t;break;}$active=$template['active']??null;$trap=$active['trap']??null;if(empty($trap['onPassage'])||empty($active['effects']))continue;
  $radius=(($item['size']??$template['size']??40)+($mover['size']??40))/2+$trap['radiusCells']*$grid;if($distance($item['location'],$start,$start)<=$radius+1e-8)continue;$cross=false;foreach($points as $n=>$p)if($n>0&&$distance($item['location'],$points[$n-1],$p)<=$radius+1e-8){$cross=true;break;}if(!$cross)continue;
  if(count($pendingUses)+count($queued)>=100)itemFailure('Traitez les déclenchements de pièges en attente.',429);$use=['id'=>$id('item-use'),'itemId'=>$item['id'],'itemRevision'=>$item['revision'],'sceneId'=>$state['activeSceneId'],'layerId'=>$layer,'name'=>$template['name'],'definition'=>$active,'sourceTokenId'=>'item-origin-'.$item['id'],'phase'=>'trigger','accountId'=>'','role'=>'gm','crossingPoint'=>['x'=>$item['location']['x'],'y'=>$item['location']['y']],'targets'=>[['tokenId'=>$mover['id'],'status'=>'pending']]];$queued[]=$use;
 }if($queued)$state['items']['pendingUses']=[...$pendingUses,...$queued];return array_column($queued,'id');
}
