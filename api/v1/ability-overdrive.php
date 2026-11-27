<?php
declare(strict_types=1);
require_once __DIR__.'/unit-authority.php';

function applicationOverdrive(mixed $value): ?array {
    if(!is_array($value)||($value['mode']??'')!=='overdrive'||($value['target']??'')!=='self'
      ||($value['turns']??null)!==1||($value['expiry']??'')!=='start-next-turn'
      ||!is_int($value['hpPercent']??null)||$value['hpPercent']<0||$value['hpPercent']>100
      ||!is_array($value['advantageStatIds']??null)||!array_is_list($value['advantageStatIds'])||count($value['advantageStatIds'])>20)return null;
    foreach($value['advantageStatIds'] as $id)if(!is_string($id)||preg_match('/^character-stat-[A-Za-z]+$/D',$id)!==1)return null;
    $result=['mode'=>'overdrive','target'=>'self','turns'=>1,'expiry'=>'start-next-turn','hpPercent'=>$value['hpPercent'],'advantageStatIds'=>array_values(array_unique($value['advantageStatIds']))];
    foreach(['deferFatigue','resetCooldowns','resetRestUses','resetCombatUses'] as $key){if(!is_bool($value[$key]??null))return null;$result[$key]=$value[$key];}
    return $result;
}
function applicationOverdriveHolder(array $source):string {
    return isset($source['resources'])&&empty($source['characterId'])&&($source['unitKind']??'')!=='token'?'character:'.$source['id']
      :(unitUsesCharacterSheet($source)?'character:'.$source['characterId']:'token:'.($source['id']??''));
}
function applicationActiveOverdrive(array $source,array $state,string $sceneId):?array {
    $holder=applicationOverdriveHolder($source);
    foreach($state['attackEnhancements']??[] as $effect)
        if(($effect['holder']??'')===$holder&&($effect['sceneId']??'')===$sceneId&&($effect['remainingTurns']??0)>0&&applicationOverdrive($effect)!==null)return $effect;
    return null;
}
function applicationOverdriveRollMode(array $source,array $state,string $sceneId,string $statId,string $requested='normal'):string {
    return in_array($statId,applicationActiveOverdrive($source,$state,$sceneId)['advantageStatIds']??[],true)?'advantage':$requested;
}
function applicationAbilityHpPayment(array $ability,array $source):int {
    $d=applicationOverdrive($ability['attackEnhancement']??null);
    return $d?(int)ceil(max(0,(float)($source['resources']['maxHp']??$source['maxHp']??0))*$d['hpPercent']/100):max(0,(int)($ability['hpCost']??0));
}
function applicationReservedOverdriveFatigue(array $source,array $state,string $sceneId):int|float {
    $effect=applicationActiveOverdrive($source,$state,$sceneId);
    return !empty($effect['deferFatigue'])?max(0,min(150,$effect['deferredFatigue']??0)):0;
}
function applicationChangeDeferredFatigue(array $source,array &$state,string $sceneId,int|float $amount,string $effectId=''):?array {
    $active=$effectId?:applicationActiveOverdrive($source,$state,$sceneId)['id']??'';
    foreach($state['attackEnhancements']??[] as $i=>$effect)if(($effect['id']??'')===$active&&($effect['holder']??'')===applicationOverdriveHolder($source)&&!empty($effect['deferFatigue'])){
        $before=max(0,$effect['deferredFatigue']??0);$after=max(0,min(150,$before+$amount));
        $state['attackEnhancements'][$i]['deferredFatigue']=$after;return ['effectId'=>$active,'amount'=>$after-$before];
    }
    return null;
}
function applicationRechargeOverdriveTimers(array &$state,array $source,array $definition):void {
    $holder=applicationOverdriveHolder($source);
    foreach($state['actionTimers']??[] as $i=>$timer){
        if(($timer['kind']??'')==='weapon-turn-limit')continue;
        $owner=!empty($timer['characterId'])?'character:'.$timer['characterId']:'token:'.($timer['tokenId']??'');
        if($owner!==$holder)continue;
        if($definition['resetCooldowns'])$timer=array_replace($timer,['cooldown'=>0,'cooldownActive'=>false,'readyRound'=>0,'useCount'=>0,'turnKey'=>'']);
        if($definition['resetRestUses'])$timer=array_replace($timer,['restUseCount'=>0,'restRecharge'=>'none']);
        if($definition['resetCombatUses'])$timer['combatUseCount']=0;
        $state['actionTimers'][$i]=$timer;
    }
}
function applicationExpiredOverdriveDebts(array $before,array $after):array {
    $ids=array_column($after,'id');
    return array_values(array_filter($before,static fn($effect)=>($effect['mode']??'')==='overdrive'&&!empty($effect['deferFatigue'])&&($effect['deferredFatigue']??0)>0&&!in_array($effect['id']??'',$ids,true)));
}
function applicationPublicOverdriveEffect(array $effect,bool $ownsDetails=false):array {
    if(($effect['mode']??'')==='overdrive'&&!$ownsDetails)unset($effect['deferredFatigue']);
    return $effect;
}
function applicationActiveAbilityDefaults(array $character):array {
    static $defaults=null;
    static $previousMixtureVersions=null;
    $defaults??=json_decode(file_get_contents(__DIR__.'/active-ability-defaults.json'),true,512,JSON_THROW_ON_ERROR);
    $preset=$defaults[$character['id']??'']??null;
    $applied=is_array($character['activeAbilityDefaults']??null)?array_slice(array_values(array_filter($character['activeAbilityDefaults'],'is_string')),-20):[];
    if(!$preset)return $character;
    if(in_array($preset['revision'],$applied,true)){
        if(!isset($preset['previousMixtureDescription'])||!is_array($character['abilities']??null))return $character;
        if($previousMixtureVersions===null){
            $previousMixtureVersions=[];
            foreach([$preset['previousMixtureDescription'],...($preset['previousDescriptions']??[])] as $previous){
                if(!is_string($previous))continue;
                preg_match('/\A.{2000}/us',$previous,$clientPrefix);
                array_push($previousMixtureVersions,$previous,substr($previous,0,2000),$clientPrefix[0]??$previous);
            }
        }
        foreach($character['abilities'] as $index=>$entry){
            if(($entry['id']??null)!=='ability-mania-experimental-mixture')continue;
            if(in_array($entry['description']??null,$previousMixtureVersions,true)){
                $character['abilities'][$index]['description']=$preset['abilities'][0]['description'];
            }
            $character['abilities'][$index]['manaCost']=$preset['abilities'][0]['manaCost'];
            break;
        }
        return $character;
    }
    $character['abilities']??=[];
    foreach($preset['abilities'] as $ability)if(!in_array($ability['id'],array_column($character['abilities'],'id'),true))$character['abilities'][]=$ability;
    $character['activeAbilityDefaults']=[...$applied,$preset['revision']];
    return $character;
}
function applicationDeferNewFatigue(PDO $connection,array &$pending,array &$baseRecords=[]):void {
    $changes=array_column($pending,null,'key');$gains=[];
    foreach($changes as $key=>$change){
        if(($change['operation']??'')==='delete'||!str_starts_with($key,'character:')&&!str_starts_with($key,'token:'))continue;
        $owner=$change['payload'];$previous=$change['current']['payload']??[];
        if($previous===[]||str_starts_with($key,'token:')&&unitUsesCharacterSheet($owner))continue;
        $before=normalizeApplicationFatigue($previous['fatigue']??null)['current'];
        $gain=normalizeApplicationFatigue($owner['fatigue']??null)['current']-$before;
        if($gain>0)$gains[$key]=[$owner,$before,$gain];
    }
    if($gains===[])return;
    if(!isset($baseRecords['activity'])&&!isset($changes['activity']))$baseRecords=array_replace($baseRecords,applicationDomainRecords($connection,['activity']));
    $activity=$changes['activity']['payload']??applicationDomainPayload($baseRecords,'activity');
    $effects=$activity['attackEnhancements']??[];if($effects===[])return;
    foreach($gains as $key=>[$owner,$before,$gain]){
        $holder=applicationOverdriveHolder($owner);$effect=null;
        foreach($effects as $row)if(($row['holder']??'')===$holder&&applicationOverdrive($row)!==null&&!empty($row['deferFatigue'])&&($row['remainingTurns']??0)>0){$effect=$row;break;}
        if($effect===null)continue;
        if($before+applicationReservedOverdriveFatigue($owner,$activity,$effect['sceneId'])+$gain>150)throw new RuntimeException('Fatigue trop élevée, fatigue reportée comprise.',409);
        applicationChangeDeferredFatigue($owner,$activity,$effect['sceneId'],$gain,$effect['id']);
        $owner['fatigue']=normalizeApplicationFatigue(['current'=>$before]);
        $records=[$key=>$changes[$key]['current']];$baseRecords=array_replace($baseRecords,$records);
        // The existing queued write must be replaced even when the restored
        // fatigue equals storage; other item/resource changes still persist.
        $changes[$key]['payload']=$owner;$pending=$changes;
        queueOnlineDomainUpsert($pending,$baseRecords,'activity',$activity);
        if(str_starts_with($key,'character:')){
            $related=applicationCharacterTokenDomainRecords($connection,$owner['id']);$baseRecords=array_replace($baseRecords,$related);
            foreach($related as $tokenKey=>$record){
                if(($pending[$tokenKey]['operation']??'')==='delete')continue;
                $token=$pending[$tokenKey]['payload']??applicationDomainPayload($baseRecords,$tokenKey);
                if(unitUsesCharacterSheet($token)&&($token['characterId']??'')===$owner['id'])
                    queueOnlineDomainUpsert($pending,$baseRecords,$tokenKey,synchronizeOnlineCharacterToken($token,$owner));
            }
        }
        $changes=$pending;
    }
}
// A removed effect settles in the same domain transaction as its expiration,
// dispel or combat end. No extra query occurs while there is no debt to settle.
function applicationSettleOverdriveDebts(PDO $connection,array &$pending,array &$baseRecords=[]):void {
    $change=null;
    foreach($pending as $candidate)if(($candidate['key']??'')==='activity'){$change=$candidate;break;}
    if($change===null)return;
    $before=$change['current']['payload']['attackEnhancements']??[];
    $after=$change['payload']['attackEnhancements']??[];
    $settled=$change['settledOverdriveDebtIds']??[];
    $debts=array_values(array_filter(applicationExpiredOverdriveDebts($before,$after),static fn($effect)=>!in_array($effect['id'],$settled,true)));
    if($debts===[])return;
    $pending=array_column($pending,null,'key');
    foreach($debts as $effect){
        $holder=$effect['holder']??'';
        $characterId=str_starts_with($holder,'character:')?substr($holder,10):'';
        $key=$characterId!==''?$holder:onlineTokenDomainKey((string)$effect['sceneId'],(string)$effect['targetTokenId']);
        if(($pending[$key]['operation']??'')==='delete')continue;
        $records=applicationDomainRecords($connection,[$key]);
        $baseRecords=array_replace($baseRecords,$records);
        $owner=$pending[$key]['payload']??applicationDomainPayload($records,$key);
        if($owner===[])continue;
        $owner['fatigue']=normalizeApplicationFatigue(['current'=>normalizeApplicationFatigue($owner['fatigue']??null)['current']+min(150,$effect['deferredFatigue'])]);
        $owner['_updatedAt']=(int)floor(microtime(true)*1000);
        queueOnlineDomainUpsert($pending,$records,$key,$owner);
        if($characterId!==''){
            $related=applicationCharacterTokenDomainRecords($connection,$characterId);$records=array_replace($records,$related);
            $baseRecords=array_replace($baseRecords,$related);
            foreach($related as $tokenKey=>$record){
                if(($pending[$tokenKey]['operation']??'')==='delete')continue;
                $token=$pending[$tokenKey]['payload']??applicationDomainPayload($records,$tokenKey);
                if(unitUsesCharacterSheet($token)&&($token['characterId']??'')===$characterId)
                    queueOnlineDomainUpsert($pending,$records,$tokenKey,synchronizeOnlineCharacterToken($token,$owner));
            }
        }
        $pending['activity']['settledOverdriveDebtIds'][]=$effect['id'];
    }
}
