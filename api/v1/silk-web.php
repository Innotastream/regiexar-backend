<?php
declare(strict_types=1);
// Cosmetic binding only; all structure rules remain in their existing modules.
function applicationIsSilkWeb(array $token):bool {
 $fixed=$token['fixedStructure']??null;
 return is_array($fixed)&&(($fixed['definition']['artworkPreset']??'')==='silk-web'||($fixed['abilityId']??'')==='ability-c5b5d747-8018-4ace-b05d-d9c59508935b');
}
function applicationSilkWebMediaReference(mixed $value):string {
 return is_string($value)?preg_replace('#^https://regie-xar-tsaroth\.fr(?=/media/)#i','',trim($value)):'';
}
function applicationHasCustomSilkWebArtwork(array $token,array $device):bool {
 $image=applicationSilkWebMediaReference($token['image']??null);
 if($image===''||$image==='/assets/device-vfx/silk-web-v1.png')return false;
 if(($token['artworkOnly']??false)===true||($device['imageCustomized']??false)===true&&$image===applicationSilkWebMediaReference($device['image']??null))return true;
 return isset($device['ability'])&&$image!==applicationSilkWebMediaReference($device['ability']['image']??null);
}
function applicationSilkWebConditionVisual(array $map,string $sourceId,?int $now=null):array {
 foreach($map['tokens']??[] as $source)if(($source['id']??'')===$sourceId&&applicationIsSilkWeb($source))return ['visualPreset'=>'silk-web','structureTokenId'=>$sourceId,'visualCreatedAt'=>$now??(int)floor(microtime(true)*1000)];
 return [];
}
function applicationSilkWebVisuals(array $store,array $map,array $context):array {
 $result=['version'=>1,'webs'=>[],'bindings'=>[]];if(!empty($context['paused'])||empty($context['sceneId']))return $result;
 $sceneId=$context['sceneId'];$layerId=$context['layerId']??'ground';$shown=isset($context['clearlyVisibleTokenIds'])?array_fill_keys($context['clearlyVisibleTokenIds'],true):(isset($context['visibleTokenIds'])?array_fill_keys($context['visibleTokenIds'],true):null);
 $tokens=[];foreach($map['tokens']??[] as $token)if(empty($token['hidden'])&&empty($token['dimSilhouette'])&&($token['layerId']??'ground')===$layerId&&($shown===null||isset($shown[$token['id']])))$tokens[$token['id']]=$token;
 $devices=array_column($store['devices']??[],null,'id');
 foreach($tokens as $token){
  if(!applicationIsSilkWeb($token))continue;$fixed=$token['fixedStructure'];$device=$devices[$fixed['deviceId']??'']??[];
  $row=['id'=>$token['id'],'sceneId'=>$sceneId,'layerId'=>$layerId,'x'=>$token['x'],'y'=>$token['y'],'size'=>$token['size'],'createdAt'=>is_int($fixed['createdAt']??null)&&$fixed['createdAt']>0?$fixed['createdAt']:0,'alive'=>($token['hp']??0)>0,'customImage'=>applicationHasCustomSilkWebArtwork($token,$device)];
  if(isset($tokens[$fixed['creatorTokenId']??'']))$row['sourceTokenId']=$fixed['creatorTokenId'];$result['webs'][]=$row;
 }
 $webIds=array_fill_keys(array_column($result['webs'],'id'),true);
 foreach($store['timedConditions']??[] as $condition){
  if(($condition['visualPreset']??'')!=='silk-web'||($condition['sceneId']??'')!==$sceneId||($condition['remainingTurns']??0)<=0||($condition['blocksMovement']??false)!==true||!isset($tokens[$condition['targetTokenId']??'']))continue;
  $row=['id'=>$condition['id'],'tokenId'=>$condition['targetTokenId'],'sceneId'=>$sceneId,'layerId'=>$layerId,'createdAt'=>is_int($condition['visualCreatedAt']??null)&&$condition['visualCreatedAt']>0?$condition['visualCreatedAt']:0];
  if(isset($webIds[$condition['structureTokenId']??'']))$row['webTokenId']=$condition['structureTokenId'];$result['bindings'][]=$row;
 }
 return $result;
}
