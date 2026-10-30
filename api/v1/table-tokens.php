<?php
declare(strict_types=1);
require_once __DIR__.'/player-stocks.php';
function applicationTableTokenName(mixed $value):string {preg_match('/^.{0,80}/us',(string)$value,$match);return $match[0]??'Joueur';}
function normalizeApplicationTableTokens(mixed $value): array {
    $value=is_array($value)?$value:[];
    $integer=static fn($n,$fallback,$min=0,$max=100)=>is_int($n)?max($min,min($max,$n)):$fallback;
    $state=['revision'=>$integer($value['revision']??null,0,0,PHP_INT_MAX),'pools'=>[],'requests'=>[],'events'=>[]];
    foreach(['gm','players'] as $side){$pool=$value['pools'][$side]??[];$max=$integer($pool['max']??null,6);$state['pools'][$side]=['max'=>$max,'current'=>$integer($pool['current']??null,$max,0,$max)];}
    foreach(array_slice(is_array($value['requests']??null)?$value['requests']:[],-100) as $r)if(is_array($r)&&preg_match('/^[A-Za-z0-9_-]{16,80}$/D',(string)($r['id']??''))===1&&is_string($r['accountId']??null)&&is_int($r['createdAt']??null))$state['requests'][]=['id'=>$r['id'],'accountId'=>$r['accountId'],'name'=>applicationTableTokenName($r['name']??'Joueur'),'createdAt'=>$r['createdAt']];
    foreach(array_slice(is_array($value['events']??null)?$value['events']:[],-32) as $e)if(is_array($e)&&preg_match('/^[A-Za-z0-9_-]{16,80}$/D',(string)($e['id']??''))===1&&in_array($e['side']??'', ['gm','players'],true)&&is_int($e['at']??null))$state['events'][]=['id'=>$e['id'],'side'=>$e['side'],'at'=>$e['at']];
    return $state;
}
function applicationTableTokensProjection(mixed $value,?array $identity=null,?int $now=null): array {
    $now??=(int)floor(microtime(true)*1000);$state=normalizeApplicationTableTokens($value);
    $gm=($identity['effective_mode']??'')==='gm'&&($identity['permanent_role']??'')==='gm';
    $state['requests']=array_values(array_filter($state['requests'],static fn($r)=>$now-$r['createdAt']<600000&&($gm||$r['accountId']===($identity['id']??null))));
    return [...$state,'serverNow'=>$now];
}
function applicationChangeTableTokens(mixed $value,array $args,array $identity,int $now): array {
    $fail=static function($message,$code=409): never {throw new RuntimeException($message,$code);};
    if(empty($identity['id']))$fail('Connexion requise.',401);
    if(preg_match('/^[A-Za-z0-9_-]{16,80}$/D',(string)($args['requestId']??''))!==1)$fail('Référence de demande invalide.',400);
    $state=normalizeApplicationTableTokens($value);$gm=($identity['effective_mode']??'')==='gm'&&($identity['permanent_role']??'')==='gm';
    $state['requests']=array_values(array_filter($state['requests'],static fn($r)=>$now-$r['createdAt']<600000));
    $consume=static function($side)use(&$state,$args,$now,$fail):void {
        if(!in_array($side,['gm','players'],true))$fail('Réserve inconnue.',400);
        if($state['pools'][$side]['current']<=0)$fail('Aucun jeton disponible dans cette réserve.');
        $state['pools'][$side]['current']--;$state['events'][]=['id'=>$args['requestId'],'side'=>$side,'at'=>$now];$state['events']=array_slice($state['events'],-32);
    };
    $action=$args['action']??'';
    if($action==='request'){
        if($gm)$fail('Le MJ peut utiliser directement un jeton.',400);
        if($state['pools']['players']['current']<=0)$fail('Aucun jeton joueur disponible.');
        foreach($state['requests'] as $r)if($r['accountId']===$identity['id'])$fail('Votre demande de jeton attend déjà le MJ.');
        if(count($state['requests'])>=100)$fail('Trop de demandes en attente.',429);
        $state['requests'][]=['id'=>$args['requestId'],'accountId'=>$identity['id'],'name'=>applicationTableTokenName($identity['display_name']??'Joueur'),'createdAt'=>$now];
    }elseif(in_array($action,['approve','reject','cancel'],true)){
        $request=null;foreach($state['requests'] as $r)if($r['id']===($args['id']??''))$request=$r;
        if($request===null)$fail('Cette demande a expiré ou a déjà été traitée.');
        if(!$gm&&!($action==='cancel'&&$request['accountId']===$identity['id']))$fail('Seul le MJ peut répondre à cette demande.',403);
        if($action==='approve')$consume('players');
        $state['requests']=array_values(array_filter($state['requests'],static fn($r)=>$r['id']!==$request['id']));
    }elseif($action==='use'){
        if(!$gm)$fail('Demandez l’accord du MJ pour utiliser un jeton joueur.',403);$consume($args['side']??'');
    }elseif($action==='configure'){
        if(!$gm)$fail('Seul le MJ règle les réserves.',403);
        if(($args['expectedRevision']??null)!==$state['revision'])$fail('Les réserves ont changé. Rouvrez les réglages.');
        foreach(['gm','players'] as $side){$pool=$args['pools'][$side]??[];foreach(['max','current'] as $key)if(!is_int($pool[$key]??null)||$pool[$key]<0||$pool[$key]>100)$fail('Chaque réserve doit respecter 0 ≤ actuel ≤ maximum ≤ 100.',400);
            if($pool['current']>$pool['max'])$fail('Chaque réserve doit respecter 0 ≤ actuel ≤ maximum ≤ 100.',400);
            $state['pools'][$side]=['max'=>$pool['max'],'current'=>$pool['current']];}
    }else $fail('Action de jeton inconnue.',400);
    $state['revision']++;return $state;
}
function onlineTableTokensCommand(PDO $connection,array &$records,array &$pending,array $table,array $identity,array $args): array {
    $now=(int)floor(microtime(true)*1000);$requestId=(string)($args['requestId']??'');
    $signature=json_encode(($args['action']??'')==='personal-use'?['personal-use',$args['characterId']??'',$args['expectedStock']??null]:[$args['action']??'', $args['side']??'', $args['id']??'', $args['expectedRevision']??null, $args['pools']??null],JSON_THROW_ON_ERROR);
    $records=array_replace($records,applicationDomainRecords($connection,['activity']));$activity=$pending['activity']['payload']??applicationDomainPayload($records,'activity');
    $receipts=array_values(array_filter($activity['resourceReceipts']??[],static fn($r)=>is_array($r)&&($r['expiresAt']??0)>$now));
    foreach($receipts as $receipt)if(($receipt['requestId']??'')===$requestId){
        if(($receipt['accountId']??'')!==$identity['id'])rejectOnlineCommand($connection,403,'Ce reçu appartient à un autre compte.','receipt_forbidden');
        if(($receipt['kind']??'')!=='table-tokens'||($receipt['requestSignature']??'')!==$signature)rejectOnlineCommand($connection,409,'Cette référence correspond à une autre action.','request_mismatch');
        return ['tableTokens'=>applicationTableTokensProjection($table['tableTokens']??null,$identity,$now),...(($args['action']??'')==='personal-use'?['personalAdvantage'=>onlinePersonalAdvantage($connection,$records,$pending,$identity,$args)]:[]),'deduplicated'=>true];
    }
    if(count($receipts)>=XAR_RESOURCE_RECEIPT_MAXIMUM)rejectOnlineCommand($connection,429,'Le journal de sécurité est plein.','receipt_capacity');
    $personalAdvantage=null;
    try{if(($args['action']??'')==='personal-use')$personalAdvantage=onlinePersonalAdvantage($connection,$records,$pending,$identity,$args,true);else {$table['tableTokens']=applicationChangeTableTokens($table['tableTokens']??null,$args,$identity,$now);queueOnlineDomainUpsert($pending,$records,'table',$table);}}catch(RuntimeException $error){rejectOnlineCommand($connection,$error->getCode(),$error->getMessage(),'table_tokens_rejected');}
    $entry=onlineAppendPlayerAction($connection,$records,$pending,$identity,onlineActiveSceneId($table),['kind'=>'action','summary'=>match($args['action']){'request'=>'Demande un jeton joueur','approve'=>'Accorde un jeton joueur','reject'=>'Refuse la demande de jeton','cancel'=>'Annule la demande de jeton','configure'=>'Règle les réserves communes','personal-use'=>'Consomme un avantage personnel',default=>'Utilise un jeton '.(($args['side']??'')==='gm'?'MJ':'joueur')}]);
    $activity=$pending['activity']['payload']??$activity;
    $receipts[]=['kind'=>'table-tokens','requestId'=>$requestId,'accountId'=>$identity['id'],'actionId'=>$entry['id'],'sourceKey'=>'table','requestSignature'=>$signature,'expiresAt'=>$now+XAR_RESOURCE_RECEIPT_TTL_MILLISECONDS,'result'=>['accepted'=>true]];
    $activity['resourceReceipts']=$receipts;queueOnlineDomainUpsert($pending,$records,'activity',$activity);
    return ['tableTokens'=>applicationTableTokensProjection($table['tableTokens']??null,$identity,$now),...($personalAdvantage!==null?['personalAdvantage'=>$personalAdvantage]:[]),'deduplicated'=>false];
}
