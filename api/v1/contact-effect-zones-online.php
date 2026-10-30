<?php
declare(strict_types=1);
require_once __DIR__.'/contact-effect-zones.php';

function onlineContactZoneDomains(array $records,array $pending):array {$out=[];foreach(onlineDeviceOverlayRecords($records,$pending)as$key=>$record)$out[$key]=$record['payload']??[];return $out;}
function applicationContactUndoDelta(array $before,array $after):array {
    $before=combatUndoView($before);$after=combatUndoView($after);$patches=[];
    foreach(array_unique([...array_keys($before),...array_keys($after)])as$key)combatUndoDifference($before[$key]??null,$after[$key]??null,$key,[],$patches,array_key_exists($key,$before),array_key_exists($key,$after));
    return $patches;
}
function applicationExcludeContactUndoEffects(array $after,array $patches,array $attackIds,array $playerActionIds=[]):array {
    // This is a virtual snapshot used only to record the enclosing action.
    // Never persist it: HP may temporarily exceed its cap while removing a
    // contact delta from a later heal or cost in that same transaction.
    foreach(array_reverse($patches)as$p){
        if(combatUndoNumeric($p)){[$exists,$value]=combatUndoRead($after,$p);if($exists&&(is_int($value)||is_float($value)))combatUndoPut($after,$p,$value+$p['before']-$p['after'],true);}
        else combatUndoRestore($after,$p);
    }
    foreach(['attackReceipts','pendingAttacks']as$field)$after['activity'][$field]=array_values(array_filter($after['activity'][$field]??[],static fn($v)=>!in_array($field==='attackReceipts'?($v['attack']['id']??''):($v['id']??''),$attackIds,true)));
    $after['activity']['playerActions']=array_values(array_filter($after['activity']['playerActions']??[],static fn($entry)=>!in_array($entry['id']??'',$playerActionIds,true)));
    return $after;
}
function onlineResetContactUndoExclusions(PDO $db):void {$GLOBALS['onlineContactUndoExclusions'][spl_object_id($db)]=['patches'=>[],'attackIds'=>[],'playerActionIds'=>[]];}
function onlineRecordContactUndo(PDO $db,array &$records,array &$pending,array $before,array $identity,array $events,array $attacks,bool $excludeFromEnclosing):void {
    if($attacks===[])return;$after=onlineContactZoneDomains($records,$pending);$patches=applicationContactUndoDelta($before,$after);
    if(combatUndoRecord($before,$after,'mechanics.command',['sceneId'=>$events[0]['sceneId'],'requestId'=>$events[0]['requestId']],['attacks'=>$attacks])!==null)queueOnlineDomainUpsert($pending,$records,'activity',$after['activity']);
    if($excludeFromEnclosing){$key=spl_object_id($db);$old=$GLOBALS['onlineContactUndoExclusions'][$key]??['patches'=>[],'attackIds'=>[],'playerActionIds'=>[]];$GLOBALS['onlineContactUndoExclusions'][$key]=['patches'=>[...$old['patches'],...$patches],'attackIds'=>[...$old['attackIds'],...array_column($attacks,'id')],'playerActionIds'=>[...($old['playerActionIds']??[]),...array_values(array_diff(array_column($after['activity']['playerActions']??[],'id'),array_column($before['activity']['playerActions']??[],'id')))]];}
}
function onlineApplyContactZoneEvents(PDO $db,array &$records,array &$pending,array $identity,array $events):array {
    $attacks=[];
    foreach($events as$event){
        $activity=$pending['activity']['payload']??applicationDomainPayload($records,'activity');$existing=null;
        foreach($activity['attackReceipts']??[]as$r)if(($r['requestId']??'')===$event['requestId']){$existing=$r['attack'];break;}
        if($existing!==null){$attacks[]=$existing;continue;}
        $store=$pending['devices']['payload']??applicationDomainPayload($records,'devices',dsEmpty());$zone=null;foreach($store['contactZones']??[]as$z)if($z['id']===$event['zoneId']){$zone=$z;break;}if($zone===null)continue;
        $rolled=onlineRollFormula($event['damage']['formula']);
        $device=['id'=>$zone['id'],'abilityId'=>'contact-effect-zone','name'=>'Zone de contact','sceneId'=>$event['sceneId'],'layerId'=>$event['layerId'],'definition'=>['radiusCells'=>$zone['definition']['radiusCells'],'damageType'=>$event['damage']['damageType']]];
        $attack=onlineDeviceAutomaticAttack($db,$records,$pending,$identity,$device,$event['targetTokenId'],max(0,(int)$rolled['total']),false,$event['requestId']);if($attack===[])continue;
        $attack['originExecutionId']=$event['originExecutionId'];$attack['contactZoneId']=$event['zoneId'];$attack['sourceTokenId']=$event['sourceTokenId'];$attack['attackName']='Zone de contact';
        $roll=onlineRollEntry($identity,$rolled,'Zone de contact · Dégâts',(string)($attack['targetName']??'Cible'));$roll['visibility']='gm';$roll['revealed']=false;
        $attack['damageFormula']=$event['damage']['formula'];$attack['damageRoll']=$roll;$attack['damage']=[...($attack['damage']??[]),'formula'=>$event['damage']['formula'],'breakdown'=>$rolled['breakdown']??'','rollId'=>$roll['id']];
        $activity=$pending['activity']['payload']??applicationDomainPayload($records,'activity');
        foreach($activity['attackReceipts']??[]as$i=>$receipt)if($receipt['attack']['id']===$attack['id'])$activity['attackReceipts'][$i]['attack']=$attack;
        $activity=onlineMergeAttackActivityRolls($activity,$attack,$pending['initiative:'.$event['sceneId']]['payload']??applicationDomainPayload($records,'initiative:'.$event['sceneId']));
        queueOnlineDomainUpsert($pending,$records,'activity',$activity);
        if(($attack['status']??'')==='applied')onlineAppendAppliedDamageAction($db,$records,$pending,$identity,$attack);
        $attacks[]=$attack;
    }
    return $attacks;
}
function onlineContactZoneMovement(PDO $db,array &$records,array &$pending,array $identity,string $sceneId,array $mover,array $start,array $destination,array $path=[]):array {
    $store=$pending['devices']['payload']??applicationDomainPayload($records,'devices',dsEmpty());if(empty($store['contactZones']))return [];$attacks=[];
    // Keep each environmental origin in its own causal undo action.
    foreach(array_column(applicationContactZones($store['contactZones']),'id')as$zoneId){
        $state=onlineDeviceSelectScene(onlineDeviceState($db,$records,$pending),$sceneId);$before=onlineContactZoneDomains($records,$pending);
        $events=applicationCollectContactZoneMovement($state,$mover,$start,$destination,$path,['zoneIds'=>[$zoneId]]);
        if($events===[])continue;queueOnlineDomainUpsert($pending,$records,'devices',$state['devices']);
        $children=onlineApplyContactZoneEvents($db,$records,$pending,$identity,$events);onlineRecordContactUndo($db,$records,$pending,$before,$identity,$events,$children,true);$attacks=[...$attacks,...$children];
    }
    return $attacks;
}
function onlineAdvanceContactZoneClock(PDO $db,array &$records,array &$pending,array $identity,string $sceneId,array $previous,array $next):array {
    $store=$pending['devices']['payload']??applicationDomainPayload($records,'devices',dsEmpty());if(empty($store['contactZones']))return [];
    $state=onlineDeviceSelectScene(onlineDeviceState($db,$records,$pending),$sceneId);$state['initiative']=$next;
    $state['devices']['contactZones']=applicationAdvanceContactZones($store['contactZones'],$sceneId,$previous,$next,['tokens'=>$state['map']['tokens']]);
    if($state['devices']['contactZones']!==$store['contactZones'])queueOnlineDomainUpsert($pending,$records,'devices',$state['devices']);
    if(($next['turnSerial']??0)<=($previous['turnSerial']??0)||!($next['active']??false))return [];
    $id=$next['order'][$next['currentIndex']??0]??'';$token=null;foreach($state['map']['tokens']??[]as$t)if($t['id']===$id){$token=$t;break;}if($token===null)return [];
    $body=applicationTokenSpatialFootprint($token,$state['map']);$point=['x'=>$body['x'],'y'=>$body['y']];$attacks=[];
    foreach(array_column($state['devices']['contactZones'],'id')as$zoneId){
        // Expiry has already been persisted, before this effect's snapshot.
        $state=onlineDeviceSelectScene(onlineDeviceState($db,$records,$pending),$sceneId);$state['initiative']=$next;$before=onlineContactZoneDomains($records,$pending);
        $events=applicationCollectContactZoneMovement($state,$token,$point,$point,[$point],['zoneIds'=>[$zoneId]]);if($events===[])continue;
        queueOnlineDomainUpsert($pending,$records,'devices',$state['devices']);$children=onlineApplyContactZoneEvents($db,$records,$pending,$identity,$events);
        onlineRecordContactUndo($db,$records,$pending,$before,$identity,$events,$children,false);$attacks=[...$attacks,...$children];
    }
    return $attacks;
}
