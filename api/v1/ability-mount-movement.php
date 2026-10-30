<?php
declare(strict_types=1);
function applicationActiveAbilityMovementStep(array $executions,string $sceneId,string $tokenId):?array{foreach($executions as $execution)if(($execution['status']??'')==='active'&&($execution['sceneId']??'')===$sceneId&&($execution['sourceTokenId']??'')===$tokenId&&($execution['workflow']['steps'][$execution['currentStepIndex']]['type']??'')==='movement')return $execution['workflow']['steps'][$execution['currentStepIndex']];return null;}
function applicationAbilityMovementSource(array $source,array $map=[],bool $mountedMovement=false):array{
 $mount=null;foreach($map['tokens']??[] as $token)if(!empty($source['mountedOnTokenId'])&&$token['id']===$source['mountedOnTokenId']){$mount=$token;break;}
 if($mount===null)return $source;if($mountedMovement)return $mount;
 $source['x']=$mount['x'];$source['y']=$mount['y'];$source['layerId']=$mount['layerId']??$source['layerId']??'ground';unset($source['mountedOnTokenId'],$source['mountedAt']);return $source;
}
function applicationAbilityMovementTokenIds(array $source,array $map=[],bool $mountedMovement=false):array{
 if(!empty($source['mountedOnTokenId'])&&!$mountedMovement)return [$source['id']];$body=applicationAbilityMovementSource($source,$map,$mountedMovement);$ids=[$body['id']];foreach($map['tokens']??[] as $token)if(($token['mountedOnTokenId']??'')===$body['id'])$ids[]=$token['id'];return $ids;
}
function applicationAbilityMovementIsBlocked(array $source,array $map,array $conditions,string $sceneId,bool $mountedMovement=false):bool{$group=applicationAbilityMovementTokenIds($source,$map,$mountedMovement);foreach($map['tokens']??[] as $token)if(in_array($token['id'],$group,true))foreach($conditions as $condition)if(($condition['sceneId']??'')===$sceneId&&!empty($condition['blocksMovement'])&&($condition['remainingTurns']??0)>0&&(($condition['targetTokenId']??'')===$token['id']||unitUsesCharacterSheet($token)&&($condition['targetCharacterId']??'')===$token['characterId']))return true;return false;}
