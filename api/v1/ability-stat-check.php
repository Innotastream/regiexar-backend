<?php
declare(strict_types=1);
require_once __DIR__ . '/character-statistics.php';

const XAR_STAT_CHECK_MAXIMUM = 100;
function applicationStatCheckDefinition(mixed $value): ?array {
    if (!is_array($value) || ($value['version'] ?? 1) !== 1
        || !in_array($value['defenseStatId'] ?? '', applicationStatIds(), true)
        || !is_int($value['resultModifier'] ?? 0) || abs($value['resultModifier'] ?? 0) > 100) return null;
    return ['version'=>1, 'defenseStatId'=>$value['defenseStatId'],
        'oppositionMode'=>in_array($value['oppositionMode'] ?? '', ['required','none'],true) ? $value['oppositionMode'] : 'optional',
        'resultModifier'=>$value['resultModifier'] ?? 0];
}
function applicationStatCheckPending(array $value): bool {
    return in_array($value['status'] ?? '', ['choosing-opposition','awaiting-opposition','awaiting-validation'], true);
}
function applicationTrimStatChecks(array $values, int $now): array {
    $pending=[]; $closed=[];
    foreach($values as $value) {
        if (!is_array($value) || empty($value['id'])) continue;
        if (applicationStatCheckPending($value)) $pending[]=$value;
        elseif (($value['resolvedAt'] ?? 0) > $now-300000) $closed[]=$value;
    }
    $room=max(0,XAR_STAT_CHECK_MAXIMUM-count($pending));
    return array_slice([...$pending,...($room>0?array_slice($closed,-$room):[])],0,XAR_STAT_CHECK_MAXIMUM);
}
function applicationCreateStatCheck(string $id, string $sceneId, string $layerId, array $source, array $target, string $sourceUnitKey, string $targetUnitKey, array $ability, array $cast, int $now): array {
    $definition=applicationStatCheckDefinition($ability['statCheck'] ?? null);
    if ($definition===null || !in_array($ability['castingStatId'] ?? '', applicationStatIds(),true)
        || ($cast['success'] ?? false)!==true || !is_array($cast['roll'] ?? null)) throw new RuntimeException('Duel de statistiques invalide.',400);
    $complete=($cast['outcome']['breaksOpposition'] ?? false)===true || $definition['oppositionMode']==='none';
    return ['version'=>1,'id'=>$id,'sceneId'=>$sceneId,'layerId'=>$layerId,
        'sourceTokenId'=>$source['id'],'sourceName'=>$source['name'] ?? 'Lanceur',
        'targetTokenId'=>$target['id'],'targetName'=>$target['name'] ?? 'Cible',
        'sourceUnitKey'=>$sourceUnitKey,'targetUnitKey'=>$targetUnitKey,
        'abilityId'=>$ability['id'],'abilityName'=>$ability['name'],'definition'=>$definition,'sourceRoll'=>$cast['roll'],
        'status'=>$complete?'completed':($definition['oppositionMode']==='required'?'awaiting-opposition':'choosing-opposition'),
        'revision'=>1,'createdAt'=>$now,'updatedAt'=>$now,
        ...($complete?['sourceWon'=>true,'resolvedAt'=>$now]:[])];
}
function applicationStatCheckRequestSignature(array $body): string {
    return json_encode([(string)($body['checkId']??''),(string)($body['action']??''),$body['expectedRevision']??null,
        ($body['opposed']??false)===true,($body['confirmed']??false)===true,$body['rollMode']??'normal',
        (float)($body['modifier']??0),($body['modifierMode']??'')==='result'?'result':'threshold',($body['useJoker']??false)===true],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}
function applicationAdvanceStatCheck(array $value, array $command, ?array $roll, int $now): array {
    if (!applicationStatCheckPending($value)) throw new RuntimeException('Ce duel est déjà terminé.',409);
    if (!is_int($command['expectedRevision']??null) || $command['expectedRevision']!==$value['revision']) throw new RuntimeException('Le duel a changé. Actualisez son résultat.',409);
    $complete=static function(array &$next) use($now): void {
        $next['status']='completed';$next['resolvedAt']=$now;
        $next['sourceWon']=!onlineDefenderWinsOpposition($next['sourceRoll']['outcome'],$next['defenseRoll']['outcome']);
    };
    $next=$value; $action=$command['action']??'';
    if($action==='cancel'){$next['status']='cancelled';$next['resolvedAt']=$now;}
    elseif($action==='choose-opposition'&&$value['status']==='choosing-opposition'){
        if(!is_bool($command['opposed']??null))throw new RuntimeException('Choisissez avec ou sans opposition.',400);
        $next['opposed']=$command['opposed'];
        if($command['opposed'])$next['status']='awaiting-opposition';
        else{$next['status']='completed';$next['sourceWon']=true;$next['resolvedAt']=$now;}
    }elseif($action==='defend'&&$value['status']==='awaiting-opposition'){
        if(!is_array($roll['outcome']??null))throw new RuntimeException('Le jet d’opposition manque.',400);
        $next['defenseRoll']=$roll;
        if(!empty($roll['outcome']['requiresGmValidation']))$next['status']='awaiting-validation';else $complete($next);
    }elseif($action==='validate'&&$value['status']==='awaiting-validation'){
        if(($command['confirmed']??false)!==true)throw new RuntimeException('Confirmez la décision du MJ.',400);
        $next['defenseRoll']['outcome']['requiresGmValidation']=false;$complete($next);
    }else throw new RuntimeException('Cette action ne correspond plus à l’étape du duel.',409);
    $next['updatedAt']=$now;$next['revision']++;return $next;
}
function publicApplicationStatCheck(array $value, bool $isGm=false, bool $sourceOwned=false, bool $targetOwned=false, bool $includeDetails=true): array {
    $fields=['id','sceneId','layerId','sourceTokenId','sourceName','targetTokenId','targetName','abilityId','abilityName','status','revision','createdAt','updatedAt','resolvedAt','sourceWon'];
    return [...array_intersect_key($value,array_fill_keys($fields,true)),
        'sourceRoll'=>publicOnlineAttackRoll($value['sourceRoll'],$includeDetails&&($isGm||$sourceOwned),'roll'),
        ...(isset($value['defenseRoll'])?['defenseRoll'=>publicOnlineAttackRoll($value['defenseRoll'],$includeDetails&&($isGm||$targetOwned),'opposition')]:[]),
        ...($isGm||$targetOwned?['defenseStatId'=>$value['definition']['defenseStatId']]:[]),
        'canChooseOpposition'=>$value['status']==='choosing-opposition'&&($isGm||$sourceOwned),
        'canDefend'=>$value['status']==='awaiting-opposition'&&($isGm||$targetOwned),
        'canValidate'=>$value['status']==='awaiting-validation'&&$isGm,
        'canCancel'=>applicationStatCheckPending($value)&&$isGm];
}
function validApplicationStatChecks(mixed $values): bool {
    if(!is_array($values)||!array_is_list($values)||count($values)>XAR_STAT_CHECK_MAXIMUM)return false;
    $seen=[];
    foreach($values as $v){
        if(!is_array($v)||($v['version']??0)!==1||isset($seen[$v['id']??''])
            ||applicationStatCheckDefinition($v['definition']??null)===null
            ||!in_array($v['status']??'', ['choosing-opposition','awaiting-opposition','awaiting-validation','completed','cancelled'],true)
            ||!is_int($v['revision']??null)||$v['revision']<1)return false;
        foreach(['id','sceneId','layerId','sourceTokenId','targetTokenId','sourceUnitKey','targetUnitKey','abilityId'] as $f)
            if(!validApplicationDomainIdentifier($v[$f]??null,220))return false;
        foreach(['sourceName','targetName','abilityName'] as $f)if(!validApplicationDomainText($v[$f]??null,120))return false;
        foreach(['createdAt','updatedAt'] as $f)if(!is_int($v[$f]??null)||$v[$f]<0)return false;
        if(!validApplicationRollDomain($v['sourceRoll']??[])||!is_array($v['sourceRoll']['outcome']??null))return false;
        if(isset($v['defenseRoll'])&&(!validApplicationRollDomain($v['defenseRoll'])||!is_array($v['defenseRoll']['outcome']??null)))return false;
        if($v['status']==='awaiting-validation'&&!isset($v['defenseRoll']))return false;
        if(!applicationStatCheckPending($v)&&(!is_int($v['resolvedAt']??null)||($v['status']==='completed'&&!is_bool($v['sourceWon']??null))))return false;
        $seen[$v['id']]=true;
    }return true;
}
