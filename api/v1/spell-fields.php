<?php
declare(strict_types=1);
require_once __DIR__.'/vision-zones.php';
require_once __DIR__.'/unit-authority.php';
require_once __DIR__.'/terrain-effects.php';
const APPLICATION_MAX_SPELL_FIELDS=64;
function applicationSpellFieldFail():never{throw new RuntimeException('La zone persistante ou sa durée est invalide.',400);}
function applicationSpellFieldEpoch(mixed $value=null):int{if($value===null)return(int)floor(microtime(true)*1000);if(is_int($value)&&$value>=0)return$value;if(is_string($value)&&($seconds=strtotime($value))!==false&&$seconds>=0)return$seconds*1000;applicationSpellFieldFail();}
function applicationPersistentArea(mixed $v):?array{
 if($v===null)return null;if(!is_array($v)||($v['version']??null)!==1||($v['kind']??'')!=='roots'||!is_int($v['turns']??null)||$v['turns']<1||$v['turns']>20)applicationSpellFieldFail();return ['version'=>1,'kind'=>'roots','turns'=>$v['turns']];
}
function applicationSpellFields(mixed $value=[]):array{
 if(!is_array($value)||!array_is_list($value)||count($value)>128)applicationSpellFieldFail();$result=[];$seen=[];
 foreach($value as$f){
  if(!is_array($f)||($f['kind']??'')!=='roots'||!in_array($f['shape']??'', ['square','circle'],true)||!in_array($f['status']??'', ['active','ending'],true)||isset($seen[$f['id']??''])||!applicationVisionZoneId($f['sourceUnitId']??null)||!applicationVisionZoneId($f['abilityId']??null)||!applicationVisionZoneId($f['castId']??null)||!is_string($f['label']??null)||(preg_match_all('/./us',$f['label'])+preg_match_all('/[\x{10000}-\x{10FFFF}]/u',$f['label']))>120)applicationSpellFieldFail();
  $remaining=$f['remainingTurns']??null;if(!is_int($remaining)||$remaining<0||$remaining>($f['turns']??0)||$f['status']==='active'&&$remaining<1||$f['status']==='ending'&&$remaining!==0)applicationSpellFieldFail();
  $base=normalizeApplicationVisionZones([[...$f,'kind'=>'mist','remainingTurns'=>max(1,$remaining)]])[0];
  if($f['status']==='ending'&&(!is_int($f['endedAt']??null)||$f['endedAt']<$f['createdAt']))applicationSpellFieldFail();
  $seen[$f['id']]=true;$result[]=[...$base,'kind'=>'roots','shape'=>$f['shape'],'status'=>$f['status'],'remainingTurns'=>$remaining,'sourceUnitId'=>$f['sourceUnitId'],'abilityId'=>$f['abilityId'],'castId'=>$f['castId'],'label'=>$f['label'],...($f['status']==='ending'?['endedAt'=>$f['endedAt']]:[])];
 }return $result;
}
function applicationSpellFieldCapacity(array $store,?int $now=null):array{
 $now??=(int)floor(microtime(true)*1000);$fields=array_values(array_filter(applicationSpellFields($store['spellFields']??[]),static fn($f)=>$f['status']==='active'||$now-$f['endedAt']<60000));
 if(count(array_filter($fields,static fn($f)=>$f['status']==='active'))>=64||count($fields)>=128)throw new RuntimeException('Trop de zones persistantes actives.',429);return $fields;
}
function applicationCreateSpellField(array &$store,array $context):?array{
 $ability=$context['ability'];$definition=applicationPersistentArea($ability['persistentArea']??null);if($definition===null)return null;
 foreach($store['spellFields']??[]as$f)if($f['castId']===$context['castId']&&$f['abilityId']===$ability['id']&&$f['sceneId']===$context['sceneId'])return $f;
 $now=$context['now']??(int)floor(microtime(true)*1000);$fields=applicationSpellFieldCapacity($store,$now);$source=$context['source'];$targeting=$ability['damageTargeting']??[];
 if(($targeting['mode']??'')!=='area'||($targeting['shape']??'')==='cone'||empty($context['targetPoint']))applicationSpellFieldFail();
 $base=applicationCreateVisionZone(['id'=>$context['fieldId'],'sceneId'=>$context['sceneId'],'layerId'=>$source['layerId']??$context['map']['activeLayerId']??'ground','sourceTokenId'=>$source['id'],'targetPoint'=>$context['targetPoint'],'initiative'=>$context['initiative']??[],'now'=>$now,'radiusCells'=>$targeting['radiusCells'],'turns'=>$definition['turns'],'manualTurn'=>$store['manualTurns'][$context['sceneId']]??0]);
 $field=applicationSpellFields([[...$base,'kind'=>'roots','shape'=>($targeting['shape']??'')==='square'?'square':'circle','status'=>'active','sourceUnitId'=>unitIdForToken($source),'abilityId'=>$ability['id'],'castId'=>$context['castId'],'label'=>$ability['name']??'Zone de racines']])[0];$store['spellFields']=[...$fields,$field];return $field;
}
function applicationSpellFieldActive(array $store,string $id):bool{foreach($store['spellFields']??[]as$f)if($f['id']===$id&&$f['status']==='active'&&$f['remainingTurns']>0)return true;return false;}
function applicationEndSpellField(array &$store,string $id,array &$effects,mixed $now=null):bool{
 $now=applicationSpellFieldEpoch($now);$found=false;foreach($store['spellFields']??[]as$i=>$field)if($field['id']===$id&&$field['status']==='active'){$store['spellFields'][$i]=[...$field,'status'=>'ending','remainingTurns'=>0,'endedAt'=>max($field['createdAt'],$now)];$found=true;break;}if(!$found)return false;
 $removed=array_values(array_filter($store['timedConditions']??[],static fn($c)=>($c['fieldId']??'')===$id));$store['timedConditions']=array_values(array_filter($store['timedConditions']??[],static fn($c)=>($c['fieldId']??'')!==$id));$ids=array_column($removed,'id');
 $store['pendingRolls']=array_values(array_filter($store['pendingRolls']??[],static fn($r)=>($r['effect']['fieldId']??'')!==$id&&!in_array($r['effect']['conditionId']??'',$ids,true)));foreach($removed as$c)$effects[]=['type'=>'condition.expire','condition'=>$c];return true;
}
function applicationAdvanceSpellFields(array &$state,array $previous,array $context,array &$effects):void{
 if(!array_key_exists('spellFields',$state['devices']))return;$store=&$state['devices'];$scene=$state['activeSceneId'];$current=$state['initiative']??[];$now=applicationSpellFieldEpoch($context['nowMs']??$context['now']??null);
 $store['spellFields']=array_values(array_filter(applicationSpellFields($store['spellFields']),static fn($f)=>$f['status']==='active'||$now-$f['endedAt']<60000));
 if(!empty($previous['active'])&&empty($current['active'])){foreach($store['spellFields']as$f)if($f['sceneId']===$scene)applicationEndSpellField($store,$f['id'],$effects,$now);return;}
 foreach($store['spellFields']as$i=>$field){if($field['sceneId']!==$scene||$field['status']!=='active')continue;
  foreach($state['map']['tokens']??[]as$t)if(unitIdForToken($t)===$field['sourceUnitId']&&in_array($t['id'],$current['order']??[],true)){$field['sourceTokenId']=$t['id'];break;}
  $next=applicationAdvanceVisionZones([[...$field,'kind'=>'mist']],$scene,$previous,$current,['manual'=>($context['manual']??false)===true,'manualTurn'=>($store['manualTurns'][$scene]??0)+1,'tokens'=>$state['map']['tokens']??[]]);
  if($next===[])applicationEndSpellField($store,$field['id'],$effects,$now);else foreach(['sourceTokenId','remainingTurns','combatId','lastTurnSerial','lastRound','lastManualTurn']as$key)$store['spellFields'][$i][$key]=$key==='sourceTokenId'?$field[$key]:$next[0][$key];
 }
}
function applicationSpellFieldVisible(array $field,array $map,callable $visible):bool{
 if($visible($field['x'],$field['y']))return true;
 $width=(float)($map['naturalWidth']??0);$height=(float)($map['naturalHeight']??0);$radius=$field['radiusCells']*(float)($map['gridSize']??0);
 if(!($width>0&&$height>0&&$radius>0))return false;
 $raster=applicationTerrainRasterDimensions($map);$x=$field['x']*$width/100;$y=$field['y']*$height/100;
 $left=max(0,(int)floor(($x-$radius)*$raster['width']/$width));$right=min($raster['width']-1,(int)floor(($x+$radius)*$raster['width']/$width));
 $top=max(0,(int)floor(($y-$radius)*$raster['height']/$height));$bottom=min($raster['height']-1,(int)floor(($y+$radius)*$raster['height']/$height));
 for($row=$top;$row<=$bottom;$row++)for($col=$left;$col<=$right;$col++){
  $px=($col+.5)*$width/$raster['width'];$py=($row+.5)*$height/$raster['height'];
  if(abs($px-$x)>$radius||abs($py-$y)>$radius||$field['shape']==='circle'&&hypot($px-$x,$py-$y)>$radius)continue;
  if($visible($px/$width*100,$py/$height*100))return true;
 }
 return false;
}
function applicationPublicSpellFields(mixed $value,array $context=[]):array{
 if(!empty($context['paused']))return [];$visible=$context['visible']??static fn()=>false;$result=[];
 foreach(applicationSpellFields($value??[])as$f)if($f['sceneId']===($context['sceneId']??'')&&$f['layerId']===($context['layerId']??'')&&applicationSpellFieldVisible($f,$context['map']??[],$visible))$result[]=array_intersect_key($f,array_flip(['id','kind','shape','status','sceneId','layerId','x','y','radiusCells','remainingTurns','createdAt','endedAt']));return $result;
}
