<?php
declare(strict_types=1);
require_once __DIR__.'/path-strike.php';
require_once __DIR__.'/contact-effect-zones-online.php';
function onlineEnsurePathStrikeZone(PDO $db,array &$records,array &$pending,array &$execution):void {
    $movement=applicationPathStrikeMovementFor($execution);if($movement===null||empty($movement['step']['pathStrike']['contactZone'])||!empty($movement['state']['contactZoneId']))return;
    $sceneId=$execution['sceneId'];$source=$pending[onlineTokenDomainKey($sceneId,$execution['sourceTokenId'])]['payload']??applicationDomainPayload($records,onlineTokenDomainKey($sceneId,$execution['sourceTokenId']));
    $map=$pending['map:'.$sceneId]['payload']??applicationDomainPayload($records,'map:'.$sceneId);$map['tokens']=onlineSceneTokensForLights($db,$records,$pending,$sceneId);$origin=applicationAbilityMovementSource($source,$map,($movement['step']['mountedMovement']??false)===true);
    $clock=$pending['initiative:'.$sceneId]['payload']??applicationDomainPayload($records,'initiative:'.$sceneId);$id='contact-zone-'.$execution['id'];
    $zone=applicationCreateContactZone($movement['step']['pathStrike']['contactZone'],['id'=>$id,'sceneId'=>$sceneId,'layerId'=>$execution['layerId'],'sourceTokenId'=>$source['id'],'sourceUnitId'=>unitIdForToken($source),'sourceControllerId'=>onlineTokenControllerIdFromRecords($db,$records,$source),'originExecutionId'=>$execution['id'],'initiative'=>$clock,'now'=>(int)floor(microtime(true)*1000),'origin'=>$origin,'friendlyFire'=>!empty($source['friendlyFire'])]);
    $store=$pending['devices']['payload']??applicationDomainPayload($records,'devices',dsEmpty());$store['contactZones']=applicationContactZones([...($store['contactZones']??[]),$zone]);queueOnlineDomainUpsert($pending,$records,'devices',$store);$execution['stepStates'][$movement['step']['id']]['contactZoneId']=$id;
}
function onlineCommitPathStrikeTravel(PDO $db,array &$records,array &$pending,array $identity,array &$execution,array &$activity,array $travel):void {
    $sceneId=$execution['sceneId'];$movement=applicationPathStrikeMovementFor($execution);if($movement===null)return;$step=$movement['step'];$now=(int)floor(microtime(true)*1000);
    $source=$pending[onlineTokenDomainKey($sceneId,$execution['sourceTokenId'])]['payload']??applicationDomainPayload($records,onlineTokenDomainKey($sceneId,$execution['sourceTokenId']));
    $map=$pending['map:'.$sceneId]['payload']??applicationDomainPayload($records,'map:'.$sceneId);$tokens=onlineSceneTokensForLights($db,$records,$pending,$sceneId);
    $path=$travel['path'];$moved=!applicationChargeSame($travel['from'],$travel['to']);
    if($moved){
        $opportunities=applicationMovementAvoidsOpportunity($activity['abilityExecutions']??[],$sceneId,$source['id'])?[]:applicationOpportunityRequests([...$map,'tokens'=>$tokens],$source,$travel['from'],$travel['to'],$path,$sceneId,static fn($t)=>onlineTokenControllerIdFromRecords($db,$records,$t),static function($t)use($records){$owner=applicationAbilityCastingOwner($t)['characterId'];return $owner!==''?(applicationDomainPayload($records,'character:'.$owner)['passiveAbilities']??$t['passiveAbilities']??null):($t['passiveAbilities']??null);});
        if($opportunities!==[]){$store=$pending['devices']['payload']??applicationDomainPayload($records,'devices',dsEmpty());if(count($store['pendingRolls'])+count($opportunities)>1000)throw new RuntimeException('Traitez les réactions en attente.',429);$store['pendingRolls']=[...$store['pendingRolls'],...$opportunities];queueOnlineDomainUpsert($pending,$records,'devices',$store);}
        $trap=onlineDeviceSelectScene(onlineDeviceState($db,$records,$pending),$sceneId);if(queueItemTrapPassages($trap,$source,$travel['from'],$travel['to'],$path,static fn($p)=>$p.'-'.randomToken(16)))queueOnlineDomainUpsert($pending,$records,'items',$trap['items']);
        foreach($travel['moves']as$move){$key=onlineTokenDomainKey($sceneId,$move['tokenId']);$token=$pending[$key]['payload']??applicationDomainPayload($records,$key);if($token===[])continue;if(in_array($token['id'],$travel['dismountTokenIds']??[],true))unset($token['mountedOnTokenId'],$token['mountedAt']);queueOnlineDomainUpsert($pending,$records,$key,[...$token,...$move['to'],'_movedAt'=>$now,'_updatedAt'=>$now]);}
        $leaseId='path-strike-'.$execution['id'];$activity['abilityMovementLeases']=normalizeAbilityMovementLeases([...array_values(array_filter($activity['abilityMovementLeases']??[],static fn($lease)=>$lease['expiresAt']>$now&&$lease['id']!==$leaseId)),['id'=>$leaseId,'sceneId'=>$sceneId,'sourceTokenId'=>$execution['sourceTokenId'],'tokenIds'=>array_column($travel['moves'],'tokenId'),'expiresAt'=>$now+2810]]);
        $execution['stepStates'][$step['id']]['movementLockUntil']=$now+1210;$execution['stepStates'][$step['id']]['pathStrikeReadyAt']=$now+360;
        $done=0;foreach($execution['stepStates']as$state)foreach($state['targets']??[]as$t)$done+=count($t['attacks']??[]);
        $spell=applicationTokenDash(['version'=>1,'placement'=>'dash','presetId'=>'token-fulgurant-dash','sceneId'=>$sceneId,'layerId'=>$execution['layerId'],'sourceTokenId'=>$execution['sourceTokenId'],'source'=>[...$travel['from'],'size'=>$travel['size']],'path'=>$path,'startedAt'=>$now,...(($step['mountedMovement']??false)===true?['mountedMovement'=>true]:[])]);
        if($spell!==null)appendApplicationAbilityCueEvent($activity,normalizeApplicationAbilityCompletionCue(['version'=>1,'spell'=>$spell]),$sceneId,$execution['layerId'],$execution['sourceTokenId'],!empty($source['hidden'])?'gm':'public','path-dash-'.$execution['id'].'-'.$done);
        if(isset($step['pathStrike']['contactZone'])){
            $store=$pending['devices']['payload']??applicationDomainPayload($records,'devices',dsEmpty());$zones=applicationContactZones($store['contactZones']??[]);$index=-1;foreach($zones as$i=>$zone)if($zone['originExecutionId']===$execution['id'])$index=$i;
            if($index>=0){$zones[$index]=applicationAppendContactZonePath($zones[$index],$path);$store['contactZones']=applicationContactZones($zones);queueOnlineDomainUpsert($pending,$records,'devices',$store);}
        }
    }
}
