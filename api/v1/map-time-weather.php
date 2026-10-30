<?php
declare(strict_types=1);

function applicationMapTimeWeatherVariantId(mixed $time,mixed $weather):?string {return in_array($time,['original','day','night','dusk'],true)&&in_array($weather,['original','overcast','rain'],true)&&!($time==='original'&&$weather==='original')?'time-'.$time.'-weather-'.$weather:null;}
function applicationMapTimeWeatherLabel(string $time,string $weather):string {$a=['original'=>'','day'=>'Jour','night'=>'Nuit','dusk'=>'Crépuscule'];$b=['original'=>'','overcast'=>'Couvert','rain'=>'Pluie'];return implode(' + ',array_values(array_filter([$a[$time]??'',$b[$weather]??''])))?:'Normal';}
function applicationMapTimeWeatherName(mixed $v):string {if(!is_string($v))return '';preg_match('/^.{0,240}/us',str_replace(["\0","\r","\n"],' ',$v),$m);return $m[0]??'';}
function applicationMapTimeWeatherId(mixed $v):bool {return is_string($v)&&preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$v)===1;}
function applicationMapTimeWeatherTimestamp(mixed $v):bool {return is_int($v)&&$v>=0&&$v<=9007199254740991;}
function applicationMapTimeWeather(mixed $raw):?array {
 if(!is_array($raw))return null;if(is_int($raw['version']??null)&&$raw['version']>1)throw new RuntimeException('Ces variantes de carte proviennent d’une version plus récente.',409);
 if(($raw['version']??null)!==1)return null;$intent=applicationMapTimeWeatherTimestamp($raw['intentRevision']??null)?$raw['intentRevision']:0;$error=applicationMapTimeWeatherError($raw['lastError']??null);if(array_key_exists('normal',$raw)&&$raw['normal']===null&&$intent>0)return ['version'=>1,'normal'=>null,'variants'=>[],'activeVariantId'=>null,'pending'=>null,'intentRevision'=>$intent,'lastError'=>$error];if(!is_array($raw['normal']??null))return null;$normal=is_string($raw['normal']['background']??null)?normalizePersistedImageReference($raw['normal']['background']):null;if(!$normal)return null;
 $variants=[];$old=[];foreach(is_array($raw['variants']??null)&&array_is_list($raw['variants'])?$raw['variants']:[]as$v){if(!is_array($v)||!applicationMapTimeWeatherTimestamp($v['createdAt']??null))continue;$id=applicationMapTimeWeatherVariantId($v['time']??null,$v['weather']??null);$bg=is_string($v['background']??null)?normalizePersistedImageReference($v['background']):null;if(!$id||!$bg)continue;if(applicationMapTimeWeatherId($v['id']??null))$old[$v['id']]=$id;$next=['id'=>$id,'label'=>applicationMapTimeWeatherLabel($v['time'],$v['weather']),'time'=>$v['time'],'weather'=>$v['weather'],'background'=>$bg,'backgroundName'=>applicationMapTimeWeatherName($v['backgroundName']??''),'createdAt'=>$v['createdAt']];if(!isset($variants[$id])||$variants[$id]['createdAt']<=$v['createdAt'])$variants[$id]=$next;}
 $activeKey=is_string($raw['activeVariantId']??null)?$raw['activeVariantId']:'';$active=$old[$activeKey]??$activeKey;$pending=null;$v=$raw['pending']??null;
 if(is_array($v)){
  $expected=is_string($v['expectedBackground']??null)&&strlen($v['expectedBackground'])<=2048&&!str_starts_with($v['expectedBackground'],'data:')?normalizePersistedImageReference($v['expectedBackground']):null;
  $source=is_string($v['sourceBackground']??null)&&preg_match('#^/media/[A-Za-z0-9_-]{24}$#D',$v['sourceBackground'])===1?$v['sourceBackground']:null;
  $hash=is_string($v['expectedBackgroundHash']??null)&&preg_match('/^[a-f0-9]{64}$/D',$v['expectedBackgroundHash'])===1?$v['expectedBackgroundHash']:null;
  if(applicationMapTimeWeatherId($v['requestId']??null)&&applicationMapTimeWeatherId($v['authorAccountId']??null)&&applicationMapTimeWeatherVariantId($v['time']??null,$v['weather']??null)&&applicationMapTimeWeatherTimestamp($v['startedAt']??null)&&($expected||$source&&$hash))$pending=['requestId'=>$v['requestId'],...((is_string($v['messageId']??null)&&preg_match('/^[A-Za-z0-9_-]{24}$/D',$v['messageId'])===1)?['messageId'=>$v['messageId']]:[]),'authorAccountId'=>$v['authorAccountId'],...($expected?['expectedBackground'=>$expected]:[]),...($source?['sourceBackground'=>$source]:[]),...($hash?['expectedBackgroundHash'=>$hash]:[]),'time'=>$v['time'],'weather'=>$v['weather'],'startedAt'=>$v['startedAt']];
 }
 return ['version'=>1,'normal'=>['background'=>$normal,'backgroundName'=>applicationMapTimeWeatherName($raw['normal']['backgroundName']??'')],'variants'=>array_values($variants),'activeVariantId'=>is_string($active)&&isset($variants[$active])?$active:null,'pending'=>$pending,'intentRevision'=>$intent,'lastError'=>$error];
}
function applicationMapBackgroundTransition(mixed $raw):?array {return is_array($raw)&&($raw['version']??null)===1&&applicationMapTimeWeatherId($raw['id']??null)&&applicationMapTimeWeatherTimestamp($raw['createdAt']??null)&&($raw['durationMs']??null)===1400?['version'=>1,'id'=>$raw['id'],'createdAt'=>$raw['createdAt'],'durationMs'=>1400]:null;}
function applicationMapTimeWeatherLayer(array $map,string $id):array {$layer=$map['layers'][$id]??[];if(($map['activeLayerId']??'ground')===$id)foreach(['background','backgroundName','naturalWidth','naturalHeight','mapTimeWeather','backgroundTransition']as$f)if(array_key_exists($f,$map))$layer[$f]=$map[$f];return $layer;}
function applicationSetMapTimeWeatherLayer(array $map,string $id,array $layer):array {foreach(['background','backgroundName','mapTimeWeather','backgroundTransition']as$f){if(array_key_exists($f,$layer)){$map['layers'][$id][$f]=$layer[$f];if(($map['activeLayerId']??'ground')===$id)$map[$f]=$layer[$f];}else{unset($map['layers'][$id][$f]);if(($map['activeLayerId']??'ground')===$id)unset($map[$f]);}}return $map;}
function applicationMapTimeWeatherContext(mixed $raw):?array {
 if(!is_array($raw)||($raw['version']??null)!==1||!applicationMapTimeWeatherId($raw['sceneId']??null)||!in_array($raw['layerId']??'', ['basement','ground','upper'],true)||!applicationMapTimeWeatherId($raw['requestId']??null)||!applicationMapTimeWeatherVariantId($raw['time']??null,$raw['weather']??null))return null;
 foreach(['sourceBackground','normalBackground']as$f)if(!is_string($raw[$f]??null)||preg_match('#^/media/[A-Za-z0-9_-]{24}$#D',$raw[$f])!==1)return null;
 if(!is_string($raw['expectedBackgroundHash']??null)||preg_match('/^[a-f0-9]{64}$/D',$raw['expectedBackgroundHash'])!==1)return null;
 if(!applicationMapTimeWeatherTimestamp($raw['intentRevision']??null))return null;
 foreach(['sourceWidth','sourceHeight']as$f)if(!is_numeric($raw[$f]??null)||!is_finite((float)$raw[$f])||$raw[$f]<=0)return null;
 return ['version'=>1,'sceneId'=>$raw['sceneId'],'layerId'=>$raw['layerId'],'requestId'=>$raw['requestId'],'sourceBackground'=>$raw['sourceBackground'],'sourceMediaId'=>substr($raw['sourceBackground'],7),'expectedBackgroundHash'=>$raw['expectedBackgroundHash'],'intentRevision'=>$raw['intentRevision'],'time'=>$raw['time'],'weather'=>$raw['weather'],'normalBackground'=>$raw['normalBackground'],'sourceWidth'=>$raw['sourceWidth'],'sourceHeight'=>$raw['sourceHeight']];
}
function applicationStudioMapTimeWeatherContext(array $message):?array {$refs=is_array($message['references_json']??null)?$message['references_json']:jsonColumn($message['references_json']??'[]');foreach($refs as$r)if(isset($r['mapTimeWeather']))return applicationMapTimeWeatherContext($r['mapTimeWeather']);return null;}
function applicationMapTimeWeatherChange(array $map,array $args,string $author,int $now):array {
 $id=$args['layerId'];$layer=applicationMapTimeWeatherLayer($map,$id);$bg=$layer['background']??null;if(!is_string($bg)||$bg==='')throw new RuntimeException('Ce niveau ne possède pas de fond de carte.',409);
 $registry=applicationMapTimeWeather($layer['mapTimeWeather']??null);$action=$args['action'];$intent=$registry['intentRevision']??0;
 if($action==='prepare'){
  if(!applicationMapTimeWeatherVariantId($args['time']??null,$args['weather']??null))throw new RuntimeException('Choisissez une heure ou une météo.',400);
  if(!applicationMapTimeWeatherTimestamp($args['expectedIntentRevision']??null)||$args['expectedIntentRevision']!==$intent)throw new RuntimeException('Une action plus récente a changé la demande de Temps.',409);
  $source=$args['sourceBackground']??$bg;
  if(!isset($registry['normal']))$registry=null;
  $registry??=['version'=>1,'normal'=>['background'=>$source,'backgroundName'=>applicationMapTimeWeatherName($layer['backgroundName']??'')],'variants'=>[],'activeVariantId'=>null,'pending'=>null];
  $registry['pending']=['requestId'=>$args['requestId'],'authorAccountId'=>$author,'sourceBackground'=>$source,'expectedBackgroundHash'=>hash('sha256',$bg),...(preg_match('#^/media/[A-Za-z0-9_-]{24}$#D',$bg)?['expectedBackground'=>$bg]:[]),'time'=>$args['time'],'weather'=>$args['weather'],'startedAt'=>$now];
 }else{
  if(!$registry)$registry=['version'=>1,'normal'=>preg_match('#^/media/[A-Za-z0-9_-]{24}$#D',$bg)?['background'=>$bg,'backgroundName'=>applicationMapTimeWeatherName($layer['backgroundName']??'')]:null,'variants'=>[],'activeVariantId'=>null,'pending'=>null];
  $registry['pending']=null;
  if($action==='normal'){$chosen=$registry['normal'];$registry['activeVariantId']=null;}
  elseif($action==='select'){$chosen=null;foreach($registry['variants']as$v)if($v['id']===($args['variantId']??'')){$chosen=$v;break;}if(!$chosen)throw new RuntimeException('Cette variante n’existe plus.',409);$registry['activeVariantId']=$chosen['id'];}
  elseif($action!=='cancel')throw new RuntimeException('Commande Temps inconnue.',400);
  if(isset($chosen)){$layer['background']=$chosen['background'];$layer['backgroundName']=$chosen['backgroundName'];$layer['backgroundTransition']=['version'=>1,'id'=>$args['requestId'],'createdAt'=>$now,'durationMs'=>1400];}
 }
 $registry['intentRevision']=$intent+1;$registry['lastError']=null;$layer['mapTimeWeather']=$registry;$next=applicationSetMapTimeWeatherLayer($map,$id,$layer);$context=$action==='prepare'?['version'=>1,'sceneId'=>$args['sceneId'],'layerId'=>$id,'requestId'=>$args['requestId'],'sourceBackground'=>$registry['pending']['sourceBackground'],'sourceMediaId'=>substr($registry['pending']['sourceBackground'],7),'expectedBackgroundHash'=>$registry['pending']['expectedBackgroundHash'],'intentRevision'=>$registry['intentRevision'],'time'=>$args['time'],'weather'=>$args['weather'],'normalBackground'=>$registry['normal']['background'],'sourceWidth'=>$layer['naturalWidth']??$map['naturalWidth']??1600,'sourceHeight'=>$layer['naturalHeight']??$map['naturalHeight']??900]:null;
 return ['map'=>$next,'mapTimeWeather'=>$registry,'context'=>$context,'sourceBackground'=>$context['sourceBackground']??$bg,'normalBackground'=>$registry['normal']['background']??null,'background'=>$layer['background'],'backgroundName'=>$layer['backgroundName']??''];
}
function applicationApplyMapTimeWeatherResult(array $map,array $context,string $messageId,string $background,int $now):array {
 $layer=applicationMapTimeWeatherLayer($map,$context['layerId']);$reg=applicationMapTimeWeather($layer['mapTimeWeather']??null);$p=$reg['pending']??null;
 if(!$p||($p['requestId']??'')!==$context['requestId']||($p['messageId']??'')!==$messageId)return ['applied'=>false,'map'=>$map];
 if(!isset($p['expectedBackgroundHash'],$p['sourceBackground'])||!hash_equals($p['expectedBackgroundHash'],hash('sha256',(string)($layer['background']??'')))||$context['intentRevision']!==($reg['intentRevision']??0)||$context['expectedBackgroundHash']!==$p['expectedBackgroundHash']||$context['sourceBackground']!==$p['sourceBackground']||$reg['normal']['background']!==$context['normalBackground']||$p['time']!==$context['time']||$p['weather']!==$context['weather']){
  $reg['pending']=null;$reg['lastError']=['requestId'=>$context['requestId'],'code'=>'map_source_changed','message'=>'Le fond a changé pendant la génération. Le résultat est conservé dans le Studio.','createdAt'=>$now];$layer['mapTimeWeather']=$reg;return ['applied'=>false,'changed'=>true,'map'=>applicationSetMapTimeWeatherLayer($map,$context['layerId'],$layer)];
 }
 $id=applicationMapTimeWeatherVariantId($p['time'],$p['weather']);$variant=['id'=>$id,'label'=>applicationMapTimeWeatherLabel($p['time'],$p['weather']),'time'=>$p['time'],'weather'=>$p['weather'],'background'=>$background,'backgroundName'=>$reg['normal']['backgroundName'].' · '.applicationMapTimeWeatherLabel($p['time'],$p['weather']),'createdAt'=>$now];
 $reg['variants']=array_values(array_filter($reg['variants'],static fn($v)=>$v['id']!==$id));$reg['variants'][]=$variant;$reg['activeVariantId']=$id;$reg['pending']=null;$reg['lastError']=null;$layer['mapTimeWeather']=$reg;$layer['background']=$background;$layer['backgroundName']=$variant['backgroundName'];$layer['backgroundTransition']=['version'=>1,'id'=>$p['requestId'],'createdAt'=>$now,'durationMs'=>1400];return ['applied'=>true,'map'=>applicationSetMapTimeWeatherLayer($map,$context['layerId'],$layer)];
}
function onlineMapTimeWeatherMedia(PDO $db,string $id):array {$record=mediaRecord($db,$id);$path=is_array($record)?privateMediaDirectory().DIRECTORY_SEPARATOR.basename((string)$record['stored_name']):'';if(!$record||$record['pending_delete_at']!==null||!str_starts_with((string)$record['content_type'],'image/')||!is_file($path)||@getimagesize($path)===false)throw new RuntimeException('Le fond doit être une image disponible et décodable.',409);return ['record'=>$record,'path'=>$path];}
function onlineMapTimeWeatherCanonicalSource(PDO $db,string $source,?string $mediaId,string $accountId):string {
 $originalId=preg_match('#^/media/([A-Za-z0-9_-]{24})$#D',$source,$m)===1?$m[1]:null;
 if($originalId===null){$parts=parse_url($source);if(is_array($parts)&&strtolower($parts['scheme']??'')==='https'&&strtolower($parts['host']??'')==='regie-xar-tsaroth.fr'&&array_intersect(['port','user','pass','query','fragment'],array_keys($parts))===[]&&preg_match('#^/(?:api/v1/)?media/([A-Za-z0-9_-]{24})$#D',$parts['path']??'',$m)===1)$originalId=$m[1];}
 if($mediaId===null||$mediaId===''){$mediaId=$originalId;if(!$mediaId)throw new RuntimeException('Téléversez d’abord les octets du fond historique.',400);}
 if(preg_match('/^[A-Za-z0-9_-]{24}$/D',$mediaId)!==1)throw new RuntimeException('Média source invalide.',400);$target=onlineMapTimeWeatherMedia($db,$mediaId);
 if($originalId===$mediaId)return '/media/'.$mediaId;
 if(($target['record']['uploaded_by_account_id']??'')!==$accountId)throw new RuntimeException('La copie source doit appartenir au MJ demandeur.',403);
 if($originalId){$original=onlineMapTimeWeatherMedia($db,$originalId);$expected=onlineMapTimeWeatherBytesHash($original['path']);}
 elseif(preg_match('#^data:image/(?:png|jpeg|webp);base64,([A-Za-z0-9+/=\r\n]+)$#D',$source,$m)===1){$bytes=base64_decode($m[1],true);if($bytes===false||@getimagesizefromstring($bytes)===false)throw new RuntimeException('Le fond historique est invalide.',400);$expected=hash('sha256',$bytes);}
 else throw new RuntimeException('Ce fond historique doit être importé dans les médias avant sa transformation.',409);
 if(!hash_equals($expected,onlineMapTimeWeatherBytesHash($target['path'])))throw new RuntimeException('Les octets de la référence ne correspondent pas au fond de cette carte.',409);return '/media/'.$mediaId;
}
function onlineMapTimeWeatherCommand(PDO $db,array &$records,array &$pending,array $table,array $identity,array $args):array {
 if(!onlineIdentityIsGm($identity))throw new RuntimeException('La transformation du fond est réservée au MJ.',403);
 $scene=(string)($args['sceneId']??'');$layer=(string)($args['layerId']??'');$request=(string)($args['requestId']??'');if(!validApplicationDomainKey('map:'.$scene)||!in_array($layer,['basement','ground','upper'],true)||preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$request)!==1)throw new RuntimeException('Référence de carte ou de demande invalide.',400);
 if(array_key_exists('onlyPendingRequestId',$args)&&(($args['action']??'')!=='cancel'||!applicationMapTimeWeatherId($args['onlyPendingRequestId'])))throw new RuntimeException('Le nettoyage doit cibler une préparation identifiée.',400);
 $signature=onlineCommandRequestSignature('map.time-weather',array_intersect_key($args,array_flip(['action','sceneId','layerId','time','weather','variantId','expectedBackground','expectedBackgroundHash','expectedIntentRevision','onlyPendingRequestId'])));$receipt=onlinePersistentCommandReceipt($db,$records,'map-time-weather',$request,$identity['id'],$signature,'map_time_weather');if(isset($receipt['receipt']))return [...$receipt['receipt']['result'],'deduplicated'=>true];
 $key='map:'.$scene;$records=array_replace($records,applicationDomainRecords($db,[$key,'scene:'.$scene]));$map=$pending[$key]['payload']??applicationDomainPayload($records,$key);if($map===[]||applicationDomainPayload($records,'scene:'.$scene)===[])throw new RuntimeException('Cette scène n’existe plus.',409);$current=applicationMapTimeWeatherLayer($map,$layer);$source=$current['background']??'';
 if(isset($args['onlyPendingRequestId'])){$registry=applicationMapTimeWeather($current['mapTimeWeather']??null);if(($registry['pending']['requestId']??null)!==$args['onlyPendingRequestId']){$result=['mapTimeWeather'=>$registry,'context'=>null,'backgroundUnchanged'=>true,'skipped'=>true,'deduplicated'=>false];onlineStorePersistentCommandReceipt($records,$pending,$receipt,'map-time-weather',$request,$identity['id'],'',$key,$signature,$result);return $result;}}
 if(($args['action']??'')==='prepare'&&!isset($args['expectedBackground'])&&!isset($args['expectedBackgroundHash']))throw new RuntimeException('La référence du fond attendu est nécessaire.',400);
 if(isset($args['expectedBackground'])&&$args['expectedBackground']!==$source||isset($args['expectedBackgroundHash'])&&(!is_string($args['expectedBackgroundHash'])||!hash_equals(hash('sha256',(string)$source),$args['expectedBackgroundHash'])))throw new RuntimeException('Le fond de carte a changé depuis cette demande.',409);
 if(($args['action']??'')==='prepare'){$existing=applicationMapTimeWeather($current['mapTimeWeather']??null);if(!empty($existing['pending'])){$job=isset($existing['pending']['messageId'])?imageStudioMessageRecord($db,$existing['pending']['messageId']):null;if(!is_array($job)||in_array($job['status']??'', ['queued','generating'],true))throw new RuntimeException('Une transformation de ce niveau est déjà en cours.',409);}$args['sourceBackground']=onlineMapTimeWeatherCanonicalSource($db,(string)$source,isset($args['sourceMediaId'])?(string)$args['sourceMediaId']:null,$identity['id']);}
 $change=applicationMapTimeWeatherChange($map,$args,$identity['id'],(int)floor(microtime(true)*1000));if(in_array($args['action']??'',['normal','select'],true)&&preg_match('#^/media/([A-Za-z0-9_-]{24})$#D',(string)($change['background']??''),$mediaMatch)===1)onlineMapTimeWeatherMedia($db,$mediaMatch[1]);$prepared=prepareApplicationDomainUpsert($key,$change['map'],$records[$key]??null);if($prepared)$pending[$key]=$prepared;
 unset($change['map']);foreach(['background','sourceBackground']as$field)if(is_string($change[$field]??null)&&str_starts_with($change[$field],'data:')){unset($change[$field]);$change['backgroundHash']=hash('sha256',(string)$source);$change['backgroundUnchanged']=true;}$result=[...$change,'deduplicated'=>false];onlineStorePersistentCommandReceipt($records,$pending,$receipt,'map-time-weather',$request,$identity['id'],'',$key,$signature,$result);return $result;
}
function onlineStampStudioMapTimeWeather(PDO $db,array $identity,mixed $input,array $references,string $messageId,array $clock):array {
 $context=applicationMapTimeWeatherContext($input);if(!$context)throw new RuntimeException('Contexte de carte invalide.',400);$records=applicationDomainRecords($db,['map:'.$context['sceneId']]);$key='map:'.$context['sceneId'];$map=applicationDomainPayload($records,$key);$layer=applicationMapTimeWeatherLayer($map,$context['layerId']);$reg=applicationMapTimeWeather($layer['mapTimeWeather']??null);$p=$reg['pending']??null;
 if(!$p||$p['requestId']!==$context['requestId']||$p['authorAccountId']!==$identity['id']||!hash_equals($p['expectedBackgroundHash'],hash('sha256',(string)($layer['background']??'')))||($reg['intentRevision']??0)!==$context['intentRevision']||$p['expectedBackgroundHash']!==$context['expectedBackgroundHash']||$p['sourceBackground']!==$context['sourceBackground']||$reg['normal']['background']!==$context['normalBackground']||$p['time']!==$context['time']||$p['weather']!==$context['weather']||isset($p['messageId'])&&$p['messageId']!==$messageId)throw new RuntimeException('Cette demande de carte a été remplacée ou annulée.',409);
 if(count($references)!==1||'/media/'.($references[0]['mediaId']??'')!==$p['sourceBackground'])throw new RuntimeException('Le travail doit utiliser uniquement les octets du fond capturé.',400);
 onlineMapTimeWeatherMedia($db,$references[0]['mediaId']);$context['sourceWidth']=$layer['naturalWidth']??$map['naturalWidth']??1600;$context['sourceHeight']=$layer['naturalHeight']??$map['naturalHeight']??900;$references[0]['mapTimeWeather']=$context;$reg['pending']['messageId']=$messageId;$layer['mapTimeWeather']=$reg;$map=applicationSetMapTimeWeatherLayer($map,$context['layerId'],$layer);$change=prepareApplicationDomainUpsert($key,$map,$records[$key]??null);if($change)persistDomainChangesInTransaction($db,$identity,$clock,[$change]);return $references;
}
function onlineCompleteStudioMapTimeWeather(PDO $db,array $message,string $mediaId,array $clock):array {
 $context=applicationStudioMapTimeWeatherContext($message);if(!$context)return ['applied'=>false];onlineMapTimeWeatherMedia($db,$mediaId);$key='map:'.$context['sceneId'];$records=applicationDomainRecords($db,[$key]);$map=applicationDomainPayload($records,$key);if($map===[])return ['applied'=>false];$change=applicationApplyMapTimeWeatherResult($map,$context,$message['id'],'/media/'.$mediaId,(int)floor(microtime(true)*1000));
 if($change['applied']||($change['changed']??false)){$prepared=prepareApplicationDomainUpsert($key,$change['map'],$records[$key]??null);if($prepared)persistDomainChangesInTransaction($db,['id'=>$message['author_account_id'],'effective_mode'=>'gm','permanent_role'=>'gm'],$clock,[$prepared]);}return ['applied'=>$change['applied']];
}
function preserveApplicationMapTimeWeather(array $incoming,array $previous):array {
 foreach(['basement','ground','upper']as$id){
  $old=applicationMapTimeWeatherLayer($previous,$id);$next=applicationMapTimeWeatherLayer($incoming,$id);$changed=($old['background']??null)!==($next['background']??null);
  if($changed){
   $reg=applicationMapTimeWeather($old['mapTimeWeather']??null);$known=false;$active=null;
   if($reg&&isset($reg['normal'])){if(($next['background']??null)===$reg['normal']['background'])$known=true;foreach($reg['variants']as$v)if($v['background']===($next['background']??null)){$known=true;$active=$v['id'];}}
   if($known){$reg['pending']=null;$reg['intentRevision']=($reg['intentRevision']??0)+1;$reg['activeVariantId']=$active;$reg['lastError']=null;$next['mapTimeWeather']=$reg;}else unset($next['mapTimeWeather']);
   unset($next['backgroundTransition']);
  }else foreach(['mapTimeWeather','backgroundTransition']as$f)if(array_key_exists($f,$old))$next[$f]=$old[$f];else unset($next[$f]);
  $incoming=applicationSetMapTimeWeatherLayer($incoming,$id,$next);
 }
 return $incoming;
}

function applicationNormalizeMapTimeWeatherFields(array $map):array {
 foreach(['mapTimeWeather'=>'applicationMapTimeWeather','backgroundTransition'=>'applicationMapBackgroundTransition']as$field=>$normalize){if(array_key_exists($field,$map)){$value=$normalize($map[$field]);if($value!==null)$map[$field]=$value;else unset($map[$field]);}foreach($map['layers']??[]as$id=>$layer)if(array_key_exists($field,$layer)){$value=$normalize($layer[$field]);if($value!==null)$map['layers'][$id][$field]=$value;else unset($map['layers'][$id][$field]);}}
 return $map;
}
function onlineFailStudioMapTimeWeather(PDO $db,array $message):void {
 $context=applicationStudioMapTimeWeatherContext($message);if(!$context)return;$db->beginTransaction();try{$clock=domainClockRecord($db,true);$key='map:'.$context['sceneId'];$records=applicationDomainRecords($db,[$key]);$map=applicationDomainPayload($records,$key);$layer=applicationMapTimeWeatherLayer($map,$context['layerId']);$reg=applicationMapTimeWeather($layer['mapTimeWeather']??null);if(($reg['pending']['requestId']??'')===$context['requestId']&&($reg['pending']['messageId']??'')===$message['id']){$reg['pending']=null;$reg['lastError']=applicationMapTimeWeatherFailure($message,$context);$layer['mapTimeWeather']=$reg;$map=applicationSetMapTimeWeatherLayer($map,$context['layerId'],$layer);$change=prepareApplicationDomainUpsert($key,$map,$records[$key]??null);if($change)persistDomainChangesInTransaction($db,['id'=>$message['author_account_id'],'effective_mode'=>'gm','permanent_role'=>'gm'],$clock,[$change]);}$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

function applicationMapTimeWeatherError(mixed $raw):?array {
 if(!is_array($raw)||!applicationMapTimeWeatherId($raw['requestId']??null)||!applicationMapTimeWeatherTimestamp($raw['createdAt']??null)||!is_string($raw['message']??null))return null;$message=preg_replace('/[\x{0000}-\x{001F}\x{007F}\x{0085}\x{2028}\x{2029}]/u',' ',$raw['message']);preg_match('/^.{0,500}/us',trim($message),$m);if(($m[0]??'')==='')return null;return ['requestId'=>$raw['requestId'],'code'=>is_string($raw['code']??null)&&preg_match('/^[a-z0-9_]{3,64}$/D',$raw['code'])===1?$raw['code']:'generation_failed','message'=>$m[0]??'','createdAt'=>$raw['createdAt']];
}
function applicationMapTimeWeatherFailure(array $message,array $context):array {$code=is_string($message['error_code']??null)&&preg_match('/^[a-z0-9_]{3,64}$/D',$message['error_code'])===1?$message['error_code']:'generation_failed';return ['requestId'=>$context['requestId'],'code'=>$code,'message'=>'La transformation de la carte n’a pas abouti. Vous pouvez réessayer.','createdAt'=>(int)floor(microtime(true)*1000)];}
function applicationMapUnchangedInlineForValidation(array $incoming,array $previous,array &$inlinePaths,array $path=[]):array {
 foreach($incoming as$key=>$value){$at=[...$path,$key];if(is_string($value)&&str_starts_with(strtolower($value),'data:image/')){if(($previous[$key]??null)!==$value)throw new RuntimeException('Une image historique intégrée ne peut être conservée que sans modification.',400);$inlinePaths[]=['path'=>$at,'value'=>$value];$incoming[$key]=null;}elseif(is_array($value))$incoming[$key]=applicationMapUnchangedInlineForValidation($value,is_array($previous[$key]??null)?$previous[$key]:[],$inlinePaths,$at);}return $incoming;
}
function applicationRestoreMapUnchangedInline(array $payload,array $inlinePaths):array {foreach($inlinePaths as$row){$target=&$payload;foreach($row['path']as$key)$target=&$target[$key];$target=$row['value'];unset($target);}return $payload;}

function onlineMapTimeWeatherBytesHash(string $path):string {$hash=@hash_file('sha256',$path);if(!is_string($hash))throw new RuntimeException('Les octets de ce fond ne sont plus disponibles.',409);return $hash;}

// Run after the Studio recovery transaction has committed. Only a pending map
// message can be reconciled; ordinary Studio history is never mutated here.
function onlineReconcileFailedStudioMaps(PDO $db,array $identity):int {
 if($db->inTransaction())throw new LogicException('Map reconciliation requires a committed Studio recovery.');
 $db->beginTransaction();
 try{
  $clock=domainClockRecord($db,true);
  $query="SELECT domain_key, JSON_EXTRACT(payload, '$.mapTimeWeather.pending') AS active_pending, JSON_EXTRACT(payload, '$.layers.basement.mapTimeWeather.pending') AS basement_pending, JSON_EXTRACT(payload, '$.layers.ground.mapTimeWeather.pending') AS ground_pending, JSON_EXTRACT(payload, '$.layers.upper.mapTimeWeather.pending') AS upper_pending FROM application_domains WHERE domain_key LIKE 'map:%' AND JSON_CONTAINS_PATH(payload, 'one', '$.mapTimeWeather.pending.messageId', '$.layers.basement.mapTimeWeather.pending.messageId', '$.layers.ground.mapTimeWeather.pending.messageId', '$.layers.upper.mapTimeWeather.pending.messageId') = 1 ORDER BY domain_key LIMIT 100";
  $rows=$db->query($query)->fetchAll();$ids=[];
  foreach($rows as$row)foreach(['active_pending','basement_pending','ground_pending','upper_pending']as$field){$p=is_array($row[$field]??null)?$row[$field]:jsonColumn($row[$field]??null);if(is_string($p['messageId']??null)&&preg_match('/^[A-Za-z0-9_-]{24}$/D',$p['messageId'])===1)$ids[$p['messageId']]=true;}
  $pending=[];$maps=[];$records=[];$count=0;
  foreach(array_slice(array_keys($ids),0,25)as$id){
   $message=imageStudioMessageRecord($db,$id);if(!is_array($message)||!in_array($message['status'],['failed','rejected','cancelled'],true))continue;
   $context=applicationStudioMapTimeWeatherContext($message);if(!$context)continue;$key='map:'.$context['sceneId'];
   if(!isset($maps[$key])){$records=array_replace($records,applicationDomainRecords($db,[$key]));$maps[$key]=applicationDomainPayload($records,$key);}
   $layer=applicationMapTimeWeatherLayer($maps[$key],$context['layerId']);$reg=applicationMapTimeWeather($layer['mapTimeWeather']??null);
   if(($reg['pending']['requestId']??'')!==$context['requestId']||($reg['pending']['messageId']??'')!==$id)continue;
   $reg['pending']=null;$reg['lastError']=applicationMapTimeWeatherFailure($message,$context);$layer['mapTimeWeather']=$reg;$maps[$key]=applicationSetMapTimeWeatherLayer($maps[$key],$context['layerId'],$layer);$count++;
  }
  foreach($maps as$key=>$map){$change=prepareApplicationDomainUpsert($key,$map,$records[$key]??null);if($change)$pending[]=$change;}
  if($pending!==[])persistDomainChangesInTransaction($db,$identity,$clock,$pending);$db->commit();return $count;
 }catch(Throwable$e){if($db->inTransaction())$db->rollBack();throw $e;}
}
