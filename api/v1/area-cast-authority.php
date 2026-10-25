<?php
declare(strict_types=1);
require_once __DIR__.'/area-cast-gate.php';
require_once __DIR__.'/spell-visuals.php';

function onlineBeginAreaCastGate(PDO $connection,array &$records,array &$pending,array $identity,array $arguments,array $context,?callable $rollChance=null): array {
    extract($context,EXTR_SKIP);
    applicationChanceCastGate($ability['castGate']??null);
    if(($ability['damageTargeting']['shape']??'')!=='cone'||applicationRequestsSpecialSuccess($arguments))throw new RuntimeException('Ce cône exige son véritable jet de Chance.',400);
    if(count($activity['pendingAttacks']??[])>=XAR_PENDING_ATTACK_MAXIMUM||count($receipts)>=XAR_ATTACK_RECEIPT_MAXIMUM)throw new RuntimeException('La file des attaques est pleine.',409);
    $castingPlan['deferCue']=true;
    // Commit the attempt and its rest quota before Chance. Neither a critical
    // failure nor a later refusal restores a component or resource.
    $cast=onlineCommitAbilityCasting($connection,$records,$pending,$castingPlan,['success'=>true,'statId'=>'','statLabel'=>'','roll'=>null,'outcome'=>null],$source,$identity,false);
    $luckId=(string)($castingPlan['characterId']?:unitIdForToken($source));
    $threshold=onlineCharacterLuckThreshold($connection,$records,$pending,$luckId);
    $rolled=$rollChance!==null?$rollChance():onlineRollFormulaWithMode('1d100',normalizeOnlineRollMode($arguments['rollMode']??'normal'),$threshold,0,true);
    $outcome=classifyOnlineD100Outcome($rolled['rawD100']??null,$threshold,0,0,true,true,!empty($castingPlan['criticalFailureAsFailure']))??['raw'=>$rolled['rawD100'],'result'=>$rolled['rawD100'],'baseThreshold'=>null,'threshold'=>null,'modifier'=>0,'resultModifier'=>0,'code'=>'failure','label'=>'CHANCE · SANS SEUIL','success'=>false,'effect'=>false];
    applyOnlineD100ResultToRoll($rolled,$outcome);
    $outcome['result']??=$rolled['rawD100']??$rolled['total'];
    $roll=onlineRollEntry($identity,$rolled,$ability['name'].' · Chance',(string)$source['name'],$outcome);
    $roll=onlineAbilityRollVisibility($roll,$source,$identity,$visible,$sceneId,$source['id']);
    $roll['mapEvent']=['kind'=>'roll','label'=>'Chance','value'=>$outcome['result'],'sceneId'=>$sceneId,'layerId'=>$layerId,'tokenId'=>$source['id'],'sourceTokenId'=>$source['id'],'anchorTokenId'=>$source['id']];
    onlineRecordCharacterLuckD100($connection,$records,$pending,$identity,$luckId,$rolled);
    onlineAppendAbilityEffectRoll($records,$pending,$roll);
    $cast=[...$cast,'statId'=>'luck','statLabel'=>'Chance','outcome'=>$outcome,'roll'=>$roll,'success'=>$outcome['code']!=='critical-failure','gateAllowsEffect'=>$outcome['code']!=='critical-failure'];
    $modifier=normalizeOnlineD100Modifier($arguments['damageModifier']??0);
    if($modifier!==0){$damageFormula.=($modifier>0?'+':'').$modifier;if($damageComponents!==[])$damageComponents[0]['formula'].=($modifier>0?'+':'').$modifier;}
    $now=(int)floor(microtime(true)*1000);
    $attack=['id'=>'attack-'.randomToken(12),'requestId'=>$arguments['requestId'],'sceneId'=>$sceneId,'layerId'=>$layerId,'sourceTokenId'=>$source['id'],'sourceName'=>$source['name'],'targetTokenId'=>$source['id'],'targetName'=>'Zone du souffle','accountId'=>$identity['id'],'attackerRole'=>$isGm?'gm':'player','playerName'=>$identity['display_name']??'Joueur','visibility'=>$isGm&&!$visible?'gm':'public','attackId'=>$ability['id'],'attackName'=>$ability['name'],'attackKind'=>'ability','status'=>'pending','validationKind'=>'area-gate','areaAnchorOnly'=>true,'areaRadiusCells'=>$ability['damageTargeting']['lengthCells'],'targetPoint'=>$targetPoint,'damageType'=>$damageType,'damageFormula'=>$damageFormula,...($damageComponents!==[]?['damageComponents'=>$damageComponents]:[]),'damagePercent'=>100,'damageRollMode'=>'normal','damage'=>[],'finalDamage'=>0,'opposed'=>false,'hit'=>['raw'=>$rolled['rawD100'],'total'=>$rolled['total'],'formula'=>'1d100','statId'=>'luck','statLabel'=>'Chance','outcome'=>$outcome,'rollId'=>$roll['id']],'hitRoll'=>$roll,'cast'=>$cast,'createdAt'=>$now,'areaGate'=>['version'=>1,'ability'=>$ability,'variantId'=>$arguments['variantId']??'','sourcePoint'=>applicationTokenSpatialFootprint($source,$map),'targetPoint'=>$targetPoint,'targetTokenIds'=>array_column($targets,'id'),'chanceOutcome'=>$outcome]];
    $attack['areaGate']['sourceUnitId']=unitIdForToken($source);$attack['areaGate']['sourceControllerId']=onlineTokenControllerIdFromRecords($connection,$records,$source);
    $attack['areaGate']['targetUnitIds']=[];foreach($targets as $target)$attack['areaGate']['targetUnitIds'][$target['id']]=unitIdForToken($target);
    $activity=$pending['activity']['payload']??$activity;$activity['pendingAttacks'][]=$attack;
    $receipts[]=['requestId'=>$arguments['requestId'],'accountId'=>$identity['id'],'requestSignature'=>onlineAttackRequestSignature($sceneId,$arguments,true),'expiresAt'=>$now+XAR_ATTACK_RECEIPT_TTL_MILLISECONDS,'attack'=>$attack];$activity['attackReceipts']=$receipts;
    queueOnlineDomainUpsert($pending,$records,'activity',$activity);
    $action=onlineAppendPlayerAction($connection,$records,$pending,$identity,$sceneId,['kind'=>'attack','attackId'=>$attack['id'],'characterName'=>$source['name'],'targetName'=>'Cône visé','summary'=>$source['name'].' prépare '.$ability['name'],'detail'=>'Chance résolue · choix des esquives et validation MJ en attente']);
    $result=['attack'=>$isGm?$attack:publicOnlineAttackResult($attack,true,false),'cast'=>$cast,'castSucceeded'=>$cast['success'],'pendingAreaGate'=>true,...onlineAbilityRollBundle($cast),'effectRoll'=>null,'damageRoll'=>null,'discordContent'=>''];
    onlineStoreAbilityReceipt($records,$pending,$arguments['requestId'],$identity['id'],applicationAbilityRequestSignature('token.attack',$sceneId,$arguments,true),[...$result,'attackId'=>$attack['id']],$action['id']);
    return $result;
}

function onlineResolveAreaCastGate(PDO $connection,array &$records,array &$pending,array $identity,array $arguments,bool $isGm): array {
    if(!$isGm)throw new RuntimeException('Seul le MJ choisit les esquives de la zone.',403);
    if(($arguments['confirmed']??false)!==true||!in_array($arguments['decision']??'',['approve','reject'],true))throw new RuntimeException('Confirmez la décision de la zone.',400);
    $records=array_replace($records,applicationDomainRecords($connection,['activity']));
    $activity=$pending['activity']['payload']??applicationDomainPayload($records,'activity');$receiptIndex=null;$attack=null;
    foreach($activity['attackReceipts']??[] as $i=>$receipt)if(($receipt['attack']['id']??'')===($arguments['attackId']??'')){$receiptIndex=$i;$attack=$receipt['attack'];break;}
    if(!is_array($attack['areaGate']??null))throw new RuntimeException('Cette zone n’attend pas de décision.',404);
    $allowed=applicationApprovedAreaDefenseIds($attack['areaGate'],$arguments['defenseTokenIds']??[]);$signature=json_encode([$arguments['decision'],$allowed],JSON_THROW_ON_ERROR);
    if(isset($attack['areaGate']['resolutionSignature'])){if($attack['areaGate']['resolutionSignature']!==$signature)throw new RuntimeException('Cette zone a déjà été résolue avec un autre choix.',409);$children=[];foreach($activity['attackReceipts'] as $receipt)if(($receipt['attack']['areaGateParentId']??'')===$attack['id'])$children[]=$receipt['attack'];return ['attack'=>$attack,'areaAttacks'=>$children,'deduplicated'=>true];}
    $sceneId=$attack['sceneId'];$children=[];$canFire=$arguments['decision']==='approve'&&($attack['areaGate']['chanceOutcome']['code']??'')!=='critical-failure';
    if($canFire){
        $records=array_replace($records,applicationDomainRecords($connection,['map:'.$sceneId,'initiative:'.$sceneId]));$map=$pending['map:'.$sceneId]['payload']??applicationDomainPayload($records,'map:'.$sceneId);
        $tokens=onlineSceneTokensForLights($connection,$records,$pending,$sceneId);$source=null;foreach($tokens as $token)if($token['id']===$attack['sourceTokenId'])$source=$token;
        if(!$source)throw new RuntimeException('Le lanceur n’existe plus. Refusez cette tentative.',409);
        if(unitUsesCharacterSheet($source)){$key='character:'.$source['characterId'];$records=array_replace($records,applicationDomainRecords($connection,[$key]));$source=synchronizeOnlineCharacterToken($source,$pending[$key]['payload']??applicationDomainPayload($records,$key));}
        $ability=null;foreach(normalizeOnlineAbilities($source['abilities']??[]) as $row)if($row['id']===$attack['attackId'])$ability=$row;
        if(!$ability||canonicalApplicationDomainValue($ability)!==canonicalApplicationDomainValue($attack['areaGate']['ability']))throw new RuntimeException('La compétence du lanceur a changé. Refusez cette tentative.',409);
        if(onlineTokenLayerId($source)!==$attack['layerId']||unitIdForToken($source)!==$attack['areaGate']['sourceUnitId']||onlineTokenControllerIdFromRecords($connection,$records,$source)!==$attack['areaGate']['sourceControllerId']||(float)($source['hp']??0)<=0)throw new RuntimeException('Le porteur du souffle a changé ou ne peut plus agir. Refusez cette tentative.',409);
        $targets=[];foreach($attack['areaGate']['targetTokenIds'] as $id){$target=null;foreach($tokens as $token)if($token['id']===$id)$target=$token;if(!$target||onlineTokenLayerId($target)!==$attack['layerId'])throw new RuntimeException('Une unité du cône a quitté ce niveau. Refusez cette tentative.',409);$targets[]=$target;}
        foreach($targets as $target)if(unitIdForToken($target)!==($attack['areaGate']['targetUnitIds'][$target['id']]??null))throw new RuntimeException('Une cible du cône a changé de fiche. Refusez cette tentative.',409);
        if(count($activity['pendingAttacks']??[])-1+count($allowed)>XAR_PENDING_ATTACK_MAXIMUM||count($activity['attackReceipts']??[])+count($targets)>XAR_ATTACK_RECEIPT_MAXIMUM)throw new RuntimeException('La file des attaques est pleine.',409);
        foreach($targets as $target){
            $controller=onlineTokenControllerIdFromRecords($connection,$records,$target);
            if(unitUsesCharacterSheet($target)){$key='character:'.$target['characterId'];$records=array_replace($records,applicationDomainRecords($connection,[$key]));$target=synchronizeOnlineCharacterToken($target,$pending[$key]['payload']??applicationDomainPayload($records,$key));}
            if(onlineTokenIsDead($target,$controller!=='')||(float)($target['maxHp']??0)<=0)continue;
            $child=$attack;foreach(['areaGate','cast','areaAnchorOnly','validationKind','deferredAbilityCast','hitRoll'] as $key)unset($child[$key]);
            $child['areaGateParties']=['sourceUnitId'=>$attack['areaGate']['sourceUnitId'],'targetUnitId'=>$attack['areaGate']['targetUnitIds'][$target['id']]];
            $child=[...$child,'id'=>'attack-'.randomToken(12),'requestId'=>'area-'.randomToken(12),'targetTokenId'=>$target['id'],'targetName'=>$target['name'],'defenderAccountId'=>$controller,'areaGateParentId'=>$attack['id'],'opposed'=>in_array($target['id'],$allowed,true)&&(float)($target['hp']??0)>0,'defenseStatId'=>'character-stat-agility','defenseRollMode'=>'normal','hit'=>['outcome'=>['automatic'=>true,'success'=>true,'code'=>'success','label'=>'SOUFFLE','requiresGmValidation'=>false]],'damage'=>[],'finalDamage'=>0];
            if($child['opposed']){$child['status']='awaiting-opposition';$activity['pendingAttacks'][]=$child;}
            else{
                $result=onlineRollAttackDamage($child,$target,null,onlineAttackEnhancementState($connection,$records,$pending,$sceneId));$summary=$result['damage'];$rolled=$result['rolled'];$roll=onlineRollEntry($identity,$rolled,$ability['name'].' · Dégâts',$source['name']);$roll['visibility']='gm';$roll['revealed']=false;
                $child['damageRoll']=$roll;$child['damage']=[...$summary,'formula'=>$roll['formula'],'breakdown'=>$roll['breakdown'],'rollId'=>$roll['id'],'total'=>$roll['total'],'rollMode'=>$roll['rollMode'],'selectedIndex'=>$roll['selectedIndex']];$child['finalDamage']=$summary['finalDamage'];
                if($child['finalDamage']>0){$health=applyOnlineAttackDamage($connection,$records,$pending,onlineTokenDomainKey($sceneId,$target['id']),$target,$child['finalDamage'],true,[...$summary,'damageType'=>$child['damageType']]);$child=onlineRecordAttackGuard($child,$health);$child['status']=$health['appliedDamage']>0?'applied':'blocked';}else $child['status']='blocked';
                $child=onlineFinalizeAttackDamageRoll($child);$activity['nextAttackGuards']=$pending['activity']['payload']['nextAttackGuards']??$activity['nextAttackGuards']??[];$activity=onlineMergeAttackActivityRolls($activity,$child,applicationDomainPayload($records,'initiative:'.$sceneId));
            }
            $activity['attackReceipts'][]=['requestId'=>$child['requestId'],'accountId'=>$attack['accountId'],'requestSignature'=>$signature,'expiresAt'=>(int)floor(microtime(true)*1000)+XAR_ATTACK_RECEIPT_TTL_MILLISECONDS,'attack'=>$child];$children[]=$child;
        }
        $cue=normalizeApplicationAbilityCompletionCue($ability['completionCue']??null);
        if($spell=applicationTrustedSpellVisual($ability,['sceneId'=>$sceneId,'source'=>$attack['areaGate']['sourcePoint'],'targetPoint'=>$attack['areaGate']['targetPoint'],'variantId'=>$attack['areaGate']['variantId'],'map'=>$map]))$cue['spell']=$spell;
        $attack['areaGate']['visualCueEventId']=appendApplicationAbilityCueEvent($activity,$cue,$sceneId,$attack['layerId'],$source['id'],$attack['visibility']);
    }
    $attack['areaGate']['resolutionSignature']=$signature;$attack['areaGate']['defenseTokenIds']=$allowed;$attack['status']=$canFire?'resolved':($arguments['decision']==='reject'?'rejected':'missed');$attack['resolvedAt']=(int)floor(microtime(true)*1000);
    $activity['pendingAttacks']=array_values(array_filter($activity['pendingAttacks']??[],static fn($entry)=>($entry['id']??'')!==$attack['id']));$activity['attackReceipts'][$receiptIndex]['attack']=$attack;
    queueOnlineDomainUpsert($pending,$records,'activity',$activity);
    onlineAppendPlayerAction($connection,$records,$pending,$identity,$sceneId,['kind'=>'attack','attackId'=>$attack['id'],'characterName'=>$attack['sourceName'],'summary'=>$attack['sourceName'].' · '.$attack['attackName'],'detail'=>$canFire?'Cône confirmé · esquive individuelle selon le tableau MJ':'Souffle annulé · coûts conservés']);
    foreach($children as $child)if($child['status']==='applied')onlineAppendAppliedDamageAction($connection,$records,$pending,$identity,$child);
    return ['attack'=>$attack,'areaAttacks'=>$children,'deduplicated'=>false];
}
