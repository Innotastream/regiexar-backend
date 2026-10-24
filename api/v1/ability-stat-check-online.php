<?php
declare(strict_types=1);
require_once __DIR__ . '/ability-stat-check.php';

function onlineStatCheckUnitKey(array $token): string {
    $owner=applicationAbilityCastingOwner($token);
    return $owner['characterId']!==''?'character-'.$owner['characterId']:'token-'.$owner['tokenId'];
}
function onlineAppendStatCheck(array &$records,array &$pending,array $check): void {
    $activity=$pending['activity']['payload']??applicationDomainPayload($records,'activity');
    $values=applicationTrimStatChecks($activity['statChecks']??[],(int)floor(microtime(true)*1000));
    if(count(array_filter($values,'applicationStatCheckPending'))>=XAR_STAT_CHECK_MAXIMUM)throw new RuntimeException('Le MJ doit terminer les duels en attente.',429);
    $activity['statChecks']=applicationTrimStatChecks([...$values,$check],(int)floor(microtime(true)*1000));
    queueOnlineDomainUpsert($pending,$records,'activity',$activity);
}
function onlineStatCheckScope(PDO $connection,array &$records,array &$pending,array $table,array $identity,array $check,bool $isGm,string $action): array {
    $sceneId=$check['sceneId'];$accountId=(string)$identity['id'];
    if(!$isGm&&$sceneId!==onlineActiveSceneId($table))rejectOnlineCommand($connection,409,'La scène du duel a changé.','stat_check_scene_changed');
    if(($table['tacticalSync']['paused']??false)&&!($isGm&&$action==='cancel'))rejectOnlineCommand($connection,423,'La table est verrouillée.','table_locked');
    $map=applicationDomainPayload($records,'map:'.$sceneId);
    $source=$pending[onlineTokenDomainKey($sceneId,$check['sourceTokenId'])]['payload']??applicationDomainPayload($records,onlineTokenDomainKey($sceneId,$check['sourceTokenId']));
    $target=$pending[onlineTokenDomainKey($sceneId,$check['targetTokenId'])]['payload']??applicationDomainPayload($records,onlineTokenDomainKey($sceneId,$check['targetTokenId']));
    $sourceOwned=$source!==[]&&onlineTokenControllerIdFromRecords($connection,$records,$source)===$accountId;
    $targetOwned=$target!==[]&&onlineTokenControllerIdFromRecords($connection,$records,$target)===$accountId;
    if($isGm&&$action==='cancel')return compact('source','target','map','sourceOwned','targetOwned');
    foreach([[$source,$check['sourceUnitKey']],[$target,$check['targetUnitKey']]] as [$token,$key]){
        if($token===[]||onlineStatCheckUnitKey($token)!==$key||applicationIsNonCombatant($token)
            ||onlineTokenLayerId($token,$map)!==$check['layerId']||!onlineTokenOnActiveLayer($token,$map)
            ||(!$isGm&&(($token['hidden']??false)||!onlineAttackTargetVisible($connection,$records,$map,$token,$accountId,$sceneId))))
            rejectOnlineCommand($connection,409,'Une unité du duel a changé ou n’est plus visible.','stat_check_unit_changed');
        $owner=applicationAbilityCastingOwner($token);
        $sheet=$owner['characterId']!==''?applicationDomainPayload($records,'character:'.$owner['characterId']):[];
        $rules=$sheet!==[]?synchronizeOnlineCharacterToken($token,$sheet):$token;
        $rules['controllerPlayerId']=onlineTokenControllerIdFromRecords($connection,$records,$token);
        if(applicationAbilitySourceDefeated($rules))rejectOnlineCommand($connection,409,'Une unité du duel est KO ou morte.','stat_check_unit_defeated');
    }
    $allowed=match($action){
        'choose-opposition'=>$isGm||$sourceOwned,'defend'=>$isGm||$targetOwned,
        'validate','cancel'=>$isGm,default=>false};
    if(!$allowed)rejectOnlineCommand($connection,403,'Cette étape appartient à un autre participant.','stat_check_actor_forbidden');
    return compact('source','target','map','sourceOwned','targetOwned');
}
function onlineRespondStatCheck(PDO $connection,array &$records,array &$pending,array $table,array $identity,array $arguments,bool $isGm): array {
    $requestId=(string)($arguments['requestId']??'');$accountId=(string)$identity['id'];
    if(!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$requestId))rejectOnlineCommand($connection,400,'Référence de duel invalide.','stat_check_request_invalid');
    $signature=applicationStatCheckRequestSignature($arguments);
    $context=onlinePersistentCommandReceipt($connection,$records,'ability-check',$requestId,$accountId,$signature,'stat_check');
    $checks=$context['activity']['statChecks']??[];
    $index=findEntryIndex($checks,(string)($arguments['checkId']??''));
    if($index<0)rejectOnlineCommand($connection,404,'Ce duel n’est plus disponible.','stat_check_missing');
    $check=$checks[$index];$action=(string)($arguments['action']??'');
    $scope=onlineStatCheckScope($connection,$records,$pending,$table,$identity,$check,$isGm,$action);
    if($context['receipt']!==null){
        if(($context['receipt']['authoritySignature']??'')==='gm'&&!$isGm)rejectOnlineCommand($connection,403,'Ce reçu est réservé au mode MJ.','stat_check_receipt_gm');
        return [...$context['receipt']['result'],'deduplicated'=>true];
    }
    // Validate the stage before rolling or consuming a Joker.
    if(!applicationStatCheckPending($check)||($arguments['expectedRevision']??null)!==$check['revision'])
        rejectOnlineCommand($connection,409,'Le duel a changé. Actualisez son résultat.','stat_check_revision_changed');
    $roll=null;
    if($action==='defend'){
        if($check['status']!=='awaiting-opposition')rejectOnlineCommand($connection,409,'Aucune opposition n’est attendue.','stat_check_stage_changed');
        $target=$scope['target'];$owner=applicationAbilityCastingOwner($target);
        $character=$owner['characterId']!==''?applicationDomainPayload($records,'character:'.$owner['characterId']):[];
        $rules=$character!==[]?synchronizeOnlineCharacterToken($target,$character):$target;
        $rules['controllerPlayerId']=onlineTokenControllerIdFromRecords($connection,$records,$target);
        $ability=['id'=>'duel-'.$check['id'],'name'=>$check['abilityName'],'effect'=>'movement',
            'castingStatId'=>$check['definition']['defenseStatId'],'manaCost'=>0,'fatigueCost'=>0,'hpCost'=>0,'cooldownRounds'=>0];
        $plan=onlinePrepareAbilityCasting($connection,$ability,$rules,$check['sceneId'],applicationDomainPayload($records,'initiative:'.$check['sceneId']),$context['activity']);
        if(applicationRequestsSpecialSuccess($arguments))onlineConsumeSpecialSuccess($connection,$records,$pending,$target,$check['sceneId']);
        $cast=onlineAbilityCastingRoll($plan,$rules,$identity,$arguments,$check['layerId'],$character,
            $isGm&&onlineGmTokenVisibleToPlayers($connection,$records,$table,$target,$check['sceneId']),$target['id']);
        $roll=$cast['roll'];$roll['label']=$check['abilityName'].' · Opposition · '.$cast['statLabel'];
        $roll['mapEvent']['label']='Opposition';
        onlineAppendAbilityEffectRoll($records,$pending,$roll);
        if(($roll['outcome']['raw']??null)!==null)onlineRecordCharacterLuckD100($connection,$records,$pending,$identity,$owner['characterId']?:($target['characterId']??''),[
            'formula'=>'1d100','rawD100'=>$roll['outcome']['raw'],'total'=>$roll['total']]);
    }
    try{$next=applicationAdvanceStatCheck($check,$arguments,$roll,$context['now']);}
    catch(RuntimeException $error){rejectOnlineCommand($connection,$error->getCode()?:409,$error->getMessage(),'stat_check_conflict');}
    if($action==='validate'){$roll=$next['defenseRoll'];onlineAppendAbilityEffectRoll($records,$pending,$roll);}
    if($roll!==null)onlineAppendAbilityRollActions($connection,$records,$pending,$identity,$check['sceneId'],[$roll]);
    $activity=$pending['activity']['payload']??applicationDomainPayload($records,'activity');
    $checks=$activity['statChecks']??[];$index=findEntryIndex($checks,$check['id']);$checks[$index]=$next;
    $activity['statChecks']=applicationTrimStatChecks($checks,$context['now']);queueOnlineDomainUpsert($pending,$records,'activity',$activity);
    $label=$next['status']==='completed'?(($next['sourceWon']??false)?'Duel réussi · suite à arbitrer par le MJ':'La cible résiste')
        :($next['status']==='cancelled'?'Duel annulé':($next['status']==='awaiting-validation'?'Opposition remarquable à valider':'Opposition attendue'));
    $logged=onlineAppendPlayerAction($connection,$records,$pending,$identity,$check['sceneId'],[
        'kind'=>'ability','characterName'=>$check['sourceName'],'targetName'=>$check['targetName'],'summary'=>$check['abilityName'].' · '.$label]);
    $result=['check'=>publicApplicationStatCheck($next,$isGm,$scope['sourceOwned'],$scope['targetOwned'],false),
        'rolls'=>$roll!==null?[publicOnlineAttackRoll($roll,false,'opposition')]:[],'deduplicated'=>false];
    onlineStorePersistentCommandReceipt($records,$pending,$context,'ability-check',$requestId,$accountId,(string)($logged['id']??('duel-'.$check['id'])),$check['id'],$signature,$result,$isGm?'gm':'player');
    return $result;
}
