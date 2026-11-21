<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);require __DIR__.'/tactical-special-effects.php';$specialChecks=0;
const VESSEL_TEST_ID='v370-template-e8b39e7fd0dccc6b6add1b5f';
function vesselCommand(MemoryConnection $db,string $command,array $args=[],bool $gm=true):TestResponse{return runCommand($db,$command,['requestId'=>'vessel-'.randomToken(16),'sceneId'=>'scene-one',...$args],$gm);}
function vesselInstance(MemoryConnection $db):array{return $db->payload('items')['instances'][0];}
function vesselArgs(MemoryConnection $db,array $extra=[]):array{$item=vesselInstance($db);return ['itemId'=>$item['id'],'expectedItemRevision'=>$item['revision'],'characterId'=>'character-player',...$extra];}
$db=specialFixture();$c=$db->payload('character:character-player');$c['resources']['hp']=40;$db->put('character:character-player',$c);
$templates=[['id'=>VESSEL_TEST_ID,'name'=>'Fiole indestructible','kind'=>'object','description'=>'Souvenir conservé'],['id'=>'flat-potion','name'=>'Potion fictive','kind'=>'potion','potion'=>['hp'=>7,'mana'=>0,'fatigue'=>0],'potionColor'=>'#17cbbb'],['id'=>'targeted-potion','name'=>'Potion ciblée fictive','kind'=>'potion','potion'=>['hp'=>0,'mana'=>0,'fatigue'=>0],'active'=>['version'=>1,'useLabel'=>'Lancer','authority'=>'owner','targeting'=>['mode'=>'single','rangeCells'=>15],'effects'=>[['kind'=>'damage','components'=>[['type'=>'ignore','formula'=>'5']]]]]]];
foreach($templates as $template)specialCheck(vesselCommand($db,'item.template.create',['template'=>$template])->status===200,'Native create template');
specialCheck(vesselCommand($db,'item.spawn',['templateId'=>VESSEL_TEST_ID,'itemId'=>'native-vial','location'=>['kind'=>'inventory','characterId'=>'character-player']])->status===200,'Native existing vial stock');
$before=$db->payload('character:character-player');$requestId='native-vial-request-'.randomToken(16);$request=vesselArgs($db,['potionTemplateId'=>'flat-potion','requestId'=>$requestId]);$r=vesselCommand($db,'item.vessel.request',$request,false);specialCheck($r->status===200&&!empty($r->body['vesselRequested']),'Owner request');
specialCheck(vesselInstance($db)['revision']===2&&is_int(vesselInstance($db)['vesselRequest']['requestedAt']),'One revision and valid timestamp');specialCheck(vesselCommand($db,'item.vessel.request',$request,false)->body['deduplicated']===true,'Request replay');
specialCheck(vesselCommand($db,'item.consume',vesselArgs($db,['tokenId'=>'token-player']),false)->status===409,'Cannot use while pending');
$resolve=vesselArgs($db,['vesselRequestId'=>vesselInstance($db)['vesselRequest']['id'],'approve'=>false]);specialCheck(vesselCommand($db,'item.vessel.resolve',$resolve,false)->status===403,'Player cannot approve');specialCheck(vesselCommand($db,'item.vessel.resolve',$resolve)->status===200,'GM refusal');specialCheck(!isset(vesselInstance($db)['vesselRequest']),'Refusal leaves empty vial');specialCheck($db->payload('character:character-player')===$before,'Request and refusal change no resources');
specialCheck(vesselCommand($db,'item.vessel.request',vesselArgs($db,['potionTemplateId'=>'flat-potion']),false)->status===200,'Second request');
$resolve=vesselArgs($db,['vesselRequestId'=>vesselInstance($db)['vesselRequest']['id'],'approve'=>true,'requestId'=>'native-vial-approval-'.randomToken(16)]);specialCheck(vesselCommand($db,'item.vessel.resolve',$resolve)->status===200,'GM approval');specialCheck(vesselCommand($db,'item.vessel.resolve',$resolve)->body['deduplicated']===true,'Approval replay');
$full=vesselInstance($db);specialCheck($full['templateId']===VESSEL_TEST_ID&&$full['vesselContentTemplateId']==='flat-potion','Container identity is unchanged');
$view=publicItemStore($db->payload('items'),[['id'=>'character-player','ownerPlayerId'=>'account-player']],['id'=>'account-player','role'=>'player'],[],fn()=>true);specialCheck(count($view['instances'])===1&&count($view['templates'])===2&&count($view['vesselPotionOptions'])===2,'Public content definition and option names without potion copies');specialCheck(normalizeItemStore($view)['instances'][0]['vesselContentTemplateId']==='flat-potion','Public view stays normalizable');
$use=vesselArgs($db,['tokenId'=>'token-player','requestId'=>'native-vial-consume-'.randomToken(16)]);specialCheck(vesselCommand($db,'item.consume',$use,false)->status===200,'Native potion use');specialCheck(vesselCommand($db,'item.consume',$use,false)->body['deduplicated']===true,'Use replay');specialCheck($db->payload('character:character-player')['resources']['hp']===47,'Potion applies once');$empty=vesselInstance($db);specialCheck($empty['id']===$full['id']&&$empty['templateId']===VESSEL_TEST_ID&&$empty['location']===$full['location']&&!isset($empty['vesselContentTemplateId']),'Empty original vial returned');specialCheck(count($db->payload('items')['instances'])===1,'No generic stock created');
specialCheck(vesselCommand($db,'item.vessel.request',vesselArgs($db,['potionTemplateId'=>'targeted-potion']),false)->status===200,'Targeted potion request');specialCheck(vesselCommand($db,'item.vessel.resolve',vesselArgs($db,['vesselRequestId'=>vesselInstance($db)['vesselRequest']['id'],'approve'=>true]))->status===200,'Targeted approval');
$targetBefore=$db->payload('token:scene-one:token-monster')['hp'];$activate=vesselArgs($db,['tokenId'=>'token-player','targetTokenId'=>'token-monster','opposed'=>true]);$pending=vesselCommand($db,'item.activate',$activate,false);specialCheck($pending->status===200&&!empty($pending->body['pendingOpposition']),'Targeted use waits for defenses');specialCheck(vesselCommand($db,'item.use.cancel',['useId'=>$pending->body['pendingUse']])->status===200,'Cancel targeted use');specialCheck(vesselInstance($db)['vesselContentTemplateId']==='targeted-potion'&&$db->payload('token:scene-one:token-monster')['hp']===$targetBefore,'Cancellation preserves content and victim');
$hit=vesselArgs($db,['tokenId'=>'token-player','targetTokenId'=>'token-monster','opposed'=>false,'requestId'=>'native-vial-hit-'.randomToken(16)]);$r=vesselCommand($db,'item.activate',$hit,false);specialCheck($r->status===200,'Native targeted resolution: '.json_encode($r->body));specialCheck(vesselCommand($db,'item.activate',$hit,false)->body['deduplicated']===true,'Targeted replay');specialCheck($db->payload('token:scene-one:token-monster')['hp']===$targetBefore-5,'Targeted effect applies once');specialCheck(!isset(vesselInstance($db)['vesselContentTemplateId'])&&vesselInstance($db)['location']['kind']==='inventory','Targeted use also returns empty container');
$db=specialFixture();specialCheck(vesselCommand($db,'item.quick.create',['templateId'=>VESSEL_TEST_ID,'itemIds'=>['forged'],'name'=>'Fiole indestructible','characterId'=>'character-player','quantity'=>1],false)->status===403,'Reserved identity cannot be forged with quick object');
$db=specialFixture();
$sheet=$db->payload('character:character-player');$sheet['resources']['hp']=10;$db->put('character:character-player',$sheet);
$potion=['id'=>'leihr-healing-fixture','name'=>'Potion de soin de recette','kind'=>'potion','active'=>['version'=>1,'authority'=>'owner','useLabel'=>'Boire','effects'=>[['kind'=>'heal-max-die','percent'=>30,'bonusFormula'=>'1']]]];
specialCheck(vesselCommand($db,'item.template.create',['template'=>$potion])->status===200,'Prepare actual healing dice and flat bonus');
specialCheck(vesselCommand($db,'item.spawn',['templateId'=>$potion['id'],'itemId'=>'healing-one','location'=>['kind'=>'inventory','characterId'=>'character-player']])->status===200,'Prepare one potion');
$activity=$db->payload('activity');$activity['rolls']=array_map(static fn($i)=>[...onlineRollEntry(['display_name'=>'Recette'],['formula'=>'1','total'=>1,'breakdown'=>'1'],'Jet précédent','MJ'),'id'=>'old-roll-'.$i],range(1,100));$db->put('activity',$activity);
$args=vesselArgs($db,['tokenId'=>'token-player','requestId'=>'native-full-journal-'.randomToken(16)]);
$used=vesselCommand($db,'item.consume',$args,false);
specialCheck($used->status===200,'Healing at full journal: '.json_encode($used->body,JSON_UNESCAPED_UNICODE));
$recorded=$db->payload('activity')['rolls'];
specialCheck(count($recorded)===100 && !in_array('old-roll-100',array_column($recorded,'id'),true),'Newest rolls displace old ones at the limit');
specialCheck(count(array_filter($recorded,static fn($roll)=>str_starts_with($roll['label'],'Potion de soin de recette')))===2,'Both potion dice are in the journal');
$after=$db->payload('character:character-player');
specialCheck($after['resources']['hp']>10 && vesselInstance($db)['location']['kind']==='consumed','Potion applies healing and consumes one instance');
specialCheck(vesselCommand($db,'item.consume',$args,false)->body['deduplicated']===true&&$db->payload('character:character-player')===$after,'A retry consumes and heals once');

$db=specialFixture();
$targetKey='token:scene-one:token-monster';
$carrier=$db->payload('token:scene-one:token-player');
$target=$db->payload($targetKey);
$target['x']=94;$target['y']=94;$target['hp']=10;$db->put($targetKey,$target);
specialCheck(vesselCommand($db,'item.template.create',['template'=>$potion])->status===200,'Register healing potion for administration');
specialCheck(vesselCommand($db,'item.spawn',['templateId'=>$potion['id'],'itemId'=>'healing-one','location'=>['kind'=>'inventory','characterId'=>'character-player']])->status===200,'Create one potion for another recipient');
$args=vesselArgs($db,['tokenId'=>'token-player','targetTokenId'=>'token-monster']);
$holderBefore=$db->payload('character:character-player')['resources'];
specialCheck(vesselCommand($db,'item.consume',$args,false)->status===409 && vesselInstance($db)['location']['kind']==='inventory','Distant administration refuses and keeps the potion');
$target['x']=$carrier['x']+0.5;$target['y']=$carrier['y'];$db->put($targetKey,$target);
$given=vesselCommand($db,'item.consume',$args,false);
specialCheck($given->status===200,'Administer potion to a nearby recipient: '.json_encode($given->body,JSON_UNESCAPED_UNICODE));
specialCheck($db->payload('character:character-player')['resources']===$holderBefore && (int)$db->payload($targetKey)['hp']>10,'The recipient gets the healing and the holder keeps their HP');
fwrite(STDOUT,json_encode(['ok'=>true,'checks'=>$specialChecks],JSON_UNESCAPED_UNICODE)."\n");
