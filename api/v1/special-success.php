<?php
declare(strict_types=1);

function normalizeSpecialSuccessStock(mixed $value): int {
    return is_numeric($value) && is_finite((float)$value) ? max(0,min(9999,(int)$value)) : 0;
}
function applicationRequestsSpecialSuccess(array $arguments): bool {
    if(array_key_exists('useJoker',$arguments) && !is_bool($arguments['useJoker'])) throw new RuntimeException('invalid_joker_choice',400);
    return ($arguments['useJoker']??false)===true || ($arguments['kind']??'')==='joker';
}
function applicationSpecialSuccessRoll(): array {
    return ['formula'=>'1','total'=>1,'rawD100'=>null,'joker'=>true,'breakdown'=>'Joker · réussite simple','rollMode'=>'normal','selectedIndex'=>0,
        'attempts'=>[['total'=>1,'rawD100'=>null,'breakdown'=>'Joker']]];
}
function applicationSpecialSuccessOutcome(mixed $threshold=null): array {
    return ['raw'=>null,'result'=>1,'baseThreshold'=>$threshold,'threshold'=>$threshold,'modifier'=>0,'resultModifier'=>0,'resultModifierApplied'=>false,
        'resultCustomized'=>false,'code'=>'success','label'=>'RÉUSSITE','success'=>true,'joker'=>true,'effect'=>true,'breaksOpposition'=>false,'requiresGmValidation'=>false];
}
function applicationComplexStepAllowsSpecialSuccess(array $step, string $action): bool {
    return ($step['type'] ?? '') === 'attack-chain' && ($action === 'gate-roll' || $action === 'prepare-chain' && ($step['resolutionMode'] ?? '') === 'batch')
        || ($step['type'] ?? '') === 'allocated-attacks' && $action === 'awareness-roll'
        || ($step['type'] ?? '') === 'defense-series' && $action === 'defense-roll'
        || ($step['type'] ?? '') === 'rolls' && $action === 'roll' && ($step['threshold'] ?? null) !== null && preg_match('/^1?d100$/i',preg_replace('/\s+/','',(string)($step['formula']??''))) === 1;
}

// Call after the command has checked its current source, scene and actor.
// The usual transaction and durable receipt own the debit and any rollback.
function onlineConsumeSpecialSuccess(PDO $connection,array &$records,array &$pending,array $source,string $sceneId): array {
    $owner=applicationAbilityCastingOwner($source);
    $characterId=(string)($owner['characterId']??'');
    $key=$characterId!==''?'character:'.$characterId:onlineTokenDomainKey($sceneId,(string)($source['id']??''));
    if(!validApplicationDomainKey($key))rejectOnlineCommand($connection,409,'L’unité du Joker n’existe plus.','joker_source_missing');
    if(!isset($records[$key]))$records=array_replace($records,applicationDomainRecords($connection,[$key]));
    $entity=$pending[$key]['payload']??applicationDomainPayload($records,$key);
    if($entity===[] || normalizeSpecialSuccessStock($entity['specialSuccessStock']??0)<1)
        rejectOnlineCommand($connection,409,'Aucune réussite spéciale disponible pour cette unité.','joker_stock_empty');
    $entity['specialSuccessStock']=normalizeSpecialSuccessStock($entity['specialSuccessStock'])-1;
    $entity['_updatedAt']=(int)floor(microtime(true)*1000);
    queueOnlineDomainUpsert($pending,$records,$key,$entity);
    if($characterId!==''){
        $related=applicationCharacterTokenDomainRecords($connection,$characterId);$records=array_replace($records,$related);
        foreach($related as $tokenKey=>$record){
            $token=$pending[$tokenKey]['payload']??applicationDomainPayload($records,$tokenKey);
            if(!unitUsesCharacterSheet($token)||($token['characterId']??'')!==$characterId)continue;
            $token['specialSuccessStock']=$entity['specialSuccessStock'];$token['_updatedAt']=$entity['_updatedAt'];
            queueOnlineDomainUpsert($pending,$records,$tokenKey,$token);
        }
    }
    return applicationSpecialSuccessRoll();
}

function applicationForcePublicJoker(array $roll): array {
    if (($roll['outcome']['joker']??false)===true||($roll['joker']??false)===true) { $roll['visibility']='public';$roll['revealed']=true;$roll['gmRevealed']=true; }
    return $roll;
}
