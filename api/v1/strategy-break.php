<?php
declare(strict_types=1);
const XAR_STRATEGY_BREAK_DURATION_MS = 300000;
function normalizeApplicationStrategyBreak(mixed $value): ?array {
    if (!is_array($value) || preg_match('/^[A-Za-z0-9_-]{16,80}$/D',(string)($value['id']??''))!==1
        || !is_int($value['startedAt']??null) || $value['startedAt']<0 || ($value['endsAt']??null)!==$value['startedAt']+XAR_STRATEGY_BREAK_DURATION_MS) return null;
    $result=['id'=>$value['id'],'startedAt'=>$value['startedAt'],'endsAt'=>$value['endsAt']];
    if(is_int($value['stoppedAt']??null)&&$value['stoppedAt']>=$value['startedAt'])$result['stoppedAt']=$value['stoppedAt'];
    return $result;
}
function applicationStrategyBreakActive(mixed $value, ?int $now=null): bool {
    $pause=normalizeApplicationStrategyBreak($value);
    return $pause!==null&&!array_key_exists('stoppedAt',$pause)&&$pause['endsAt']>($now??(int)floor(microtime(true)*1000));
}
function applicationStrategyBreakProjection(mixed $value): ?array {
    $pause=normalizeApplicationStrategyBreak($value);
    return $pause===null?null:[...$pause,'serverNow'=>(int)floor(microtime(true)*1000)];
}
function onlineStrategyBreakCommand(PDO $connection,array &$records,array &$pending,array $table,array $identity,array $arguments): array {
    if(($identity['effective_mode']??'')!=='gm'||($identity['permanent_role']??'')!=='gm')rejectOnlineCommand($connection,403,'Seul le MJ gère le Point Strat.','gm_required');
    $action=$arguments['action']??'';$requestId=(string)($arguments['requestId']??'');
    if(!in_array($action,['start','stop'],true)||preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$requestId)!==1)rejectOnlineCommand($connection,400,'Action de Point Strat invalide.','strategy_break_invalid');
    $signature=json_encode([$action,(string)($arguments['id']??'')],JSON_THROW_ON_ERROR);$now=(int)floor(microtime(true)*1000);
    $records=array_replace($records,applicationDomainRecords($connection,['activity']));$activity=$pending['activity']['payload']??applicationDomainPayload($records,'activity');
    $receipts=array_values(array_filter($activity['resourceReceipts']??[],static fn($entry)=>is_array($entry)&&($entry['expiresAt']??0)>$now));
    foreach($receipts as $receipt)if(($receipt['requestId']??'')===$requestId){
        if(($receipt['accountId']??'')!==$identity['id'])rejectOnlineCommand($connection,403,'Ce reçu appartient à un autre compte.','receipt_forbidden');
        if(($receipt['kind']??'')!=='strategy-break'||($receipt['requestSignature']??'')!==$signature)rejectOnlineCommand($connection,409,'Cette référence correspond à une autre action.','request_mismatch');
        return [...$receipt['result'],'deduplicated'=>true,'serverNow'=>$now];
    }
    if(count($receipts)>=XAR_RESOURCE_RECEIPT_MAXIMUM)rejectOnlineCommand($connection,429,'Le journal de sécurité est plein.','receipt_capacity');
    $pause=normalizeApplicationStrategyBreak($table['strategyBreak']??null);
    if($action==='start') { if(!applicationStrategyBreakActive($pause,$now))$pause=['id'=>$requestId,'startedAt'=>$now,'endsAt'=>$now+XAR_STRATEGY_BREAK_DURATION_MS]; }
    else { if($pause===null||($arguments['id']??'')!==$pause['id'])rejectOnlineCommand($connection,409,'Ce Point Strat est terminé ou a été remplacé.','strategy_break_stale');
        if(!isset($pause['stoppedAt']))$pause['stoppedAt']=max($pause['startedAt'],$now); }
    $table['strategyBreak']=$pause;queueOnlineDomainUpsert($pending,$records,'table',$table);
    $result=['strategyBreak'=>$pause,'deduplicated'=>false];
    $entry=onlineAppendPlayerAction($connection,$records,$pending,$identity,onlineActiveSceneId($table),['kind'=>'action','summary'=>$action==='start'?'Commence un Point Strat de 5 minutes':'Arrête le Point Strat']);
    $activity=$pending['activity']['payload']??$activity;
    $receipts[]=['kind'=>'strategy-break','requestId'=>$requestId,'accountId'=>$identity['id'],'actionId'=>$entry['id'],'sourceKey'=>'table',
        'requestSignature'=>$signature,'expiresAt'=>$now+XAR_RESOURCE_RECEIPT_TTL_MILLISECONDS,'result'=>$result];
    $activity['resourceReceipts']=$receipts;queueOnlineDomainUpsert($pending,$records,'activity',$activity);
    return [...$result,'serverNow'=>$now];
}
