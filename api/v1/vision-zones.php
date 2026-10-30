<?php
declare(strict_types=1);
const APPLICATION_MAX_VISION_ZONES=64;
function applicationVisionZoneFail():never{throw new RuntimeException('La zone de vision ou sa durée est invalide.',400);}
function applicationVisionZoneId(mixed $v):bool{return is_string($v)&&preg_match('/^[A-Za-z0-9_-]{1,180}$/D',$v)===1;}
function applicationVisionZoneInteger(mixed $v,int $min=0,int $max=9007199254740991):bool{return is_int($v)&&$v>=$min&&$v<=$max;}
function applicationVisionZoneDefinition(mixed $value):?array{
 if($value===null)return null;
 if(!is_array($value)||($value['version']??1)!==1||($value['kind']??'')!=='mist'||(!is_int($value['radiusCells']??null)&&!is_float($value['radiusCells']??null))||!is_finite((float)$value['radiusCells'])||$value['radiusCells']<.5||$value['radiusCells']>20||floor($value['radiusCells']*2)!=$value['radiusCells']*2||!applicationVisionZoneInteger($value['turns']??null,1,100))applicationVisionZoneFail();
 return ['version'=>1,'kind'=>'mist','radiusCells'=>$value['radiusCells'],'turns'=>$value['turns']];
}
function normalizeApplicationVisionZones(mixed $value=[]):array{
 if(!is_array($value)||!array_is_list($value)||count($value)>APPLICATION_MAX_VISION_ZONES)applicationVisionZoneFail();$result=[];$seen=[];
 foreach($value as$zone){
  if(!is_array($zone)||!applicationVisionZoneId($zone['id']??null)||isset($seen[$zone['id']])||!applicationVisionZoneId($zone['sceneId']??null)||!applicationVisionZoneId($zone['sourceTokenId']??null)||!in_array($zone['layerId']??null,['ground','upper','basement'],true))applicationVisionZoneFail();
  foreach(['x','y']as$key)if((!is_int($zone[$key]??null)&&!is_float($zone[$key]??null))||!is_finite((float)$zone[$key])||$zone[$key]<0||$zone[$key]>100)applicationVisionZoneFail();
  $definition=applicationVisionZoneDefinition($zone);if($definition===null||!applicationVisionZoneInteger($zone['remainingTurns']??null,1,$definition['turns'])||!applicationVisionZoneInteger($zone['createdAt']??null))applicationVisionZoneFail();
  foreach(['lastTurnSerial','lastRound','lastManualTurn']as$key)if(!applicationVisionZoneInteger($zone[$key]??0))applicationVisionZoneFail();
  if(isset($zone['combatId'])&&$zone['combatId']!==''&&!applicationVisionZoneId($zone['combatId']))applicationVisionZoneFail();
  $seen[$zone['id']]=true;$result[]=['id'=>$zone['id'],'kind'=>'mist','sceneId'=>$zone['sceneId'],'layerId'=>$zone['layerId'],'x'=>$zone['x'],'y'=>$zone['y'],'radiusCells'=>$definition['radiusCells'],'turns'=>$definition['turns'],'remainingTurns'=>$zone['remainingTurns'],'sourceTokenId'=>$zone['sourceTokenId'],'createdAt'=>$zone['createdAt'],'combatId'=>$zone['combatId']??'','lastTurnSerial'=>$zone['lastTurnSerial']??0,'lastRound'=>$zone['lastRound']??0,'lastManualTurn'=>$zone['lastManualTurn']??0];
 }return $result;
}
function applicationCreateVisionZone(array $context):array{
 $clock=$context['initiative']??[];$turns=$context['turns']??3;
 return normalizeApplicationVisionZones([['id'=>$context['id']??null,'kind'=>'mist','sceneId'=>$context['sceneId']??null,'layerId'=>$context['layerId']??null,'sourceTokenId'=>$context['sourceTokenId']??null,'x'=>$context['targetPoint']['x']??null,'y'=>$context['targetPoint']['y']??null,'radiusCells'=>$context['radiusCells']??4,'turns'=>$turns,'remainingTurns'=>$turns,'createdAt'=>$context['now']??(int)floor(microtime(true)*1000),'combatId'=>!empty($clock['active'])?($clock['combatId']??''):'','lastTurnSerial'=>(int)($clock['turnSerial']??0),'lastRound'=>!empty($clock['active'])?(int)($clock['round']??0):0,'lastManualTurn'=>$context['manualTurn']??0]])[0];
}
function applicationAdvanceVisionZones(array $value,string $sceneId,array $previous,array $initiative,array $options=[]):array{
 $zones=normalizeApplicationVisionZones($value);$result=[];
 if(!empty($options['manual'])){$manualTurn=$options['manualTurn']??null;if(!applicationVisionZoneInteger($manualTurn,1))applicationVisionZoneFail();foreach($zones as$zone){if($zone['sceneId']===$sceneId&&$zone['lastManualTurn']<$manualTurn){$zone['remainingTurns']--;$zone['lastManualTurn']=$manualTurn;}if($zone['remainingTurns']>0)$result[]=$zone;}return $result;}
 if(!empty($previous['active'])&&empty($initiative['active']))return array_map(static fn($zone)=>$zone['sceneId']===$sceneId?[...$zone,'combatId'=>'','lastTurnSerial'=>0,'lastRound'=>0]:$zone,$zones);
 if(empty($initiative['active'])||($initiative['turnSerial']??0)<=($previous['turnSerial']??0))return $zones;
 foreach($zones as$zone){if($zone['sceneId']!==$sceneId){$result[]=$zone;continue;}$serial=(int)($initiative['turnSerial']??0);$round=(int)($initiative['round']??0);
  if(empty($previous['active'])||$zone['combatId']!==($initiative['combatId']??'')){$result[]=[...$zone,'combatId'=>$initiative['combatId']??'','lastTurnSerial'=>$serial,'lastRound'=>$round];continue;}
  if($zone['lastTurnSerial']>=$serial){$result[]=$zone;continue;}
  $tokens=$options['tokens']??null;$source=null;if(is_array($tokens))foreach($tokens as$t)if($t['id']===$zone['sourceTokenId']){$source=$t;break;}
  $absent=!in_array($zone['sourceTokenId'],$initiative['order']??[],true)||is_array($tokens)&&($source===null||($source['hp']??1)<=0||in_array($source['healthOverride']??'',['dead','down'],true));
  $count=$absent?max(0,$round-max($zone['lastRound'],(int)($previous['round']??0))):((($initiative['order'][$initiative['currentIndex']??0]??'')===$zone['sourceTokenId'])?1:0);
  $zone=[...$zone,'remainingTurns'=>$zone['remainingTurns']-$count,'lastRound'=>$round,'lastTurnSerial'=>$serial];if($zone['remainingTurns']>0)$result[]=$zone;
 }return $result;
}
function applicationPublicVisionZones(mixed $value,array $context=[]):array{
 $result=[];$visible=$context['visible']??static fn()=>false;foreach(normalizeApplicationVisionZones($value??[])as$zone)if($zone['sceneId']===($context['sceneId']??'')&&$zone['layerId']===($context['layerId']??'')&&$visible($zone['x'],$zone['y']))$result[]=['id'=>$zone['id'],'kind'=>'mist','sceneId'=>$zone['sceneId'],'layerId'=>$zone['layerId'],'x'=>$zone['x'],'y'=>$zone['y'],'radiusCells'=>$zone['radiusCells'],'remainingTurns'=>$zone['remainingTurns']];return $result;
}
