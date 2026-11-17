<?php
declare(strict_types=1);
define('XAR_TACTICAL_FIXTURE_ONLY',true);
require_once __DIR__.'/tactical-lifecycle.php';
$checks=0;function metabolicCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);$GLOBALS['checks']++;}
$ultimate=json_decode(file_get_contents(__DIR__.'/../api/v1/active-ability-defaults.json'),true,512,JSON_THROW_ON_ERROR)['character-inho']['abilities'][0];$id=$ultimate['id'];
$fatigue=['id'=>'fatigue-spend','name'=>'Dépense de fatigue','effect'=>'enhancement','castingStatId'=>'','fatigueCost'=>17,'manaCost'=>0,'cooldownRounds'=>0,'reusableInTurn'=>true,'attackEnhancement'=>['mode'=>'bonus','turns'=>1,'formula'=>'1','damageType'=>'inherit','target'=>'self']];
$db=fixture();$c=$db->payload('character:character-player');$c['resources']=['hp'=>100,'maxHp'=>100,'mana'=>100,'maxMana'=>100];$c['fatigue']=['current'=>23,'max'=>150];$c['stats']['agility']=70;$c['abilities']=normalizeOnlineAbilities([$ultimate,$fatigue]);$db->put('character:character-player',$c);
function useMetabolic(MemoryConnection $db,string $id,string $request=''):TestResponse {return runCommand($db,'ability.use',['requestId'=>$request?:'metabolic-'.randomToken(16),'sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-player','layerId'=>'ground','abilityId'=>$id]);}
$r=useMetabolic($db,$id,'metabolic-initial-00001');metabolicCheck($r->status===200,'Activation PHP: '.json_encode($r->body));
metabolicCheck($db->payload('character:character-player')['resources']['hp']===90,'10% paid immediately');
metabolicCheck($db->payload('character:character-player')['fatigue']['current']===23,'Initial fatigue preserved');
$effect=$db->payload('activity')['attackEnhancements'][0];metabolicCheck($effect['mode']==='overdrive'&&$effect['remainingTurns']===1,'Effect stored');
metabolicCheck(useMetabolic($db,$id,'metabolic-initial-00001')->body['deduplicated']===true,'Replay deduplicated');
metabolicCheck($db->payload('character:character-player')['resources']['hp']===90,'No second payment for same request');
$r=useMetabolic($db,$fatigue['id']);metabolicCheck($r->status===200,'Deferred paid ability: '.json_encode($r->body));
$a=$db->payload('activity');$e=array_values(array_filter($a['attackEnhancements'],static fn($e)=>$e['mode']==='overdrive'))[0];
metabolicCheck($e['deferredFatigue']===17&&$db->payload('character:character-player')['fatigue']['current']===23,'Debt and current separated');
metabolicCheck(!empty($r->body['cast']['deferredFatigueEffectId'])||!empty($r->body['result']['cast']['deferredFatigueEffectId'])||$e['deferredFatigue']===17,'Authority owns debt');
$r=useMetabolic($db,$id);metabolicCheck($r->status===200,'Repeat activation');
$a=$db->payload('activity');$e=array_values(array_filter($a['attackEnhancements'],static fn($e)=>$e['mode']==='overdrive'))[0];
metabolicCheck($e['id']===$effect['id']&&$e['deferredFatigue']===17&&$db->payload('character:character-player')['resources']['hp']===80,'Reactivation preserves debt and lifetime');
$token=$db->payload('token:scene-one:token-player');
metabolicCheck(applicationOverdriveRollMode($token,$a,'scene-one','character-stat-agility')==='advantage','Agility forced advantage');
metabolicCheck(applicationOverdriveRollMode($token,$a,'scene-one','character-stat-force')==='normal','Other stats unchanged');
metabolicCheck(applicationActiveOverdrive([...$token,'followCharacter'=>false],$a,'scene-one')===null,'Independent clone isolated');
$visual=applicationTokenBuffVisuals($token,$a,'scene-one');$v=array_values(array_filter($visual,static fn($v)=>($v['skin']??'')==='metabolic-overdrive'))[0];
metabolicCheck(!isset($v['deferredFatigue'])&&!isset($v['hpPercent'])&&isset($v['startedAt']),'Public cosmetic DTO excludes debt and private rules');

$roll=runCommand($db,'token.roll',['requestId'=>'metabolic-agility-0001','sceneId'=>'scene-one','tokenId'=>'token-player','kind'=>'stat','statId'=>'character-stat-agility','layerId'=>'ground','rollMode'=>'normal']);
metabolicCheck($roll->status===200&&($roll->body['roll']['rollMode']??'')==='advantage'&&count($roll->body['roll']['attempts']??[])===2,'Real agility roll: '.json_encode($roll->body));
$roll=runCommand($db,'token.roll',['requestId'=>'metabolic-force-00001','sceneId'=>'scene-one','tokenId'=>'token-player','kind'=>'stat','statId'=>'character-stat-force','layerId'=>'ground','rollMode'=>'normal']);
metabolicCheck($roll->status===200&&($roll->body['roll']['rollMode']??'')==='normal','Other roll stays normal');


$remove=['requestId'=>'metabolic-remove-00001','kind'=>'enhancement','effectId'=>$e['id']];
metabolicCheck(runCommand($db,'combat.effect.remove',$remove)->status===403,'Player cannot dispel');
$r=runCommand($db,'combat.effect.remove',$remove,true,'account-gm');metabolicCheck($r->status===200,'Dispel: '.json_encode($r->body));
metabolicCheck($db->payload('character:character-player')['fatigue']['current']===40,'Debt settled atomically on dispel: '.json_encode($db->payload('character:character-player')['fatigue']));
metabolicCheck($db->payload('token:scene-one:token-player')['fatigue']['current']===40,'Linked tokens synchronized');
metabolicCheck(runCommand($db,'combat.effect.remove',$remove,true,'account-gm')->body['deduplicated']===true,'Dispel replay');
metabolicCheck($db->payload('character:character-player')['fatigue']['current']===40,'Debt paid once');
$defaults=applicationActiveAbilityDefaults(['id'=>'character-inho','abilities'=>[]]);
metabolicCheck(count($defaults['abilities'])===1&&applicationActiveAbilityDefaults([...$defaults,'abilities'=>[]])['abilities']===[],'One-time default and intentional deletion');

// A domain batch may remove one occurrence while the linked character pays
// or settles deferred fatigue. The removed occurrence must not be recreated.
foreach(['defer','settle'] as $operation){
    $case=fixture();$character=$case->payload('character:character-player');
    $character['fatigue']=['current'=>23,'max'=>150];$case->put('character:character-player',$character);
    $active=[...$ultimate['attackEnhancement'],'id'=>'overdrive-delete-test','holder'=>'character:character-player','sceneId'=>'scene-one','targetTokenId'=>'token-player','remainingTurns'=>1,'deferredFatigue'=>17];
    $activity=$case->payload('activity');$activity['attackEnhancements']=[$active];$case->put('activity',$activity);
    $records=applicationDomainRecords($case,['activity','character:character-player','token:scene-one:token-player']);$pending=[];
    $tokenKey='token:scene-one:token-player';
    $pending[$tokenKey]=['key'=>$tokenKey,'operation'=>'delete','current'=>$records[$tokenKey]];
    if($operation==='defer'){
        queueOnlineDomainUpsert($pending,$records,'character:character-player',[...$character,'fatigue'=>['current'=>30,'max'=>150]]);
        applicationDeferNewFatigue($case,$pending,$records);
        metabolicCheck((float)$pending['activity']['payload']['attackEnhancements'][0]['deferredFatigue']===24.0,'Debt gains only the new seven fatigue: '.json_encode($pending['activity']['payload']['attackEnhancements']));
    }else{
        queueOnlineDomainUpsert($pending,$records,'activity',[...$activity,'attackEnhancements'=>[]]);
        applicationSettleOverdriveDebts($case,$pending,$records);
        metabolicCheck((float)$pending['character:character-player']['payload']['fatigue']['current']===40.0,'Settlement remains on the character');
    }
    metabolicCheck(($pending[$tokenKey]['operation']??'')==='delete',$operation.': deleted linked occurrence stays deleted');
}

echo json_encode(['ok'=>true,'checks'=>$checks,'productionAccess'=>false],JSON_THROW_ON_ERROR);
