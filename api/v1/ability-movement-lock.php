<?php
declare(strict_types=1);
function applicationAbilityMovementLock(array $executions,string $sceneId,string $tokenId,array $tokens=[],?int $now=null):?array {
 $now??=(int)floor(microtime(true)*1000);$physicalId=$tokenId;foreach($tokens as$t)if($t['id']===$tokenId)$physicalId=($t['mountedOnTokenId']??'')?:$tokenId;$ids=[$physicalId];foreach($tokens as$t)if(($t['mountedOnTokenId']??'')===$physicalId)$ids[]=$t['id'];
 foreach($executions as$e){if(($e['sceneId']??'')!==$sceneId||!in_array($e['sourceTokenId']??'',$ids,true)||in_array($e['status']??'',['cancelled','rejected'],true))continue;$staged=($e['status']??'')==='active'&&array_filter($e['workflow']['steps']??[],static fn($s)=>!empty($s['charge']['staged']));$until=0;foreach($e['stepStates']??[]as$s)$until=max($until,(int)($s['movementLockUntil']??0));
  if($staged||$until>$now)return ['executionId'=>$e['id'],'until'=>$staged?null:$until,'label'=>$staged?'Charge en cours : terminez-la ou arrêtez-la avant de déplacer ce pion.':'Déplacement de compétence en cours. Attendez la fin du mouvement.'];
 }return null;
}
