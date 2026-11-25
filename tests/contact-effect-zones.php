<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);require __DIR__.'/tactical-special-effects.php';
if(($argv[1]??'')==='--cases'){
    $v=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$out=[];
    foreach($v['definitions']??[]as$d)try{$out['definitions'][]=applicationContactZoneDefinition($d);}catch(Throwable){$out['definitions'][]=null;}
    foreach($v['states']??[]as$entry){$state=$entry['state'];$events=applicationCollectContactZoneMovement($state,$entry['mover'],$entry['start'],$entry['destination'],$entry['path']??[]);$out['states'][]=['events'=>$events,'state'=>$state];}
    echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
}
$specialChecks=0;
$definition=['version'=>1,'geometry'=>'path','radiusCells'=>.08,'damage'=>['formula'=>'1d20','damageType'=>'magical'],'affectCaster'=>false,'affectAllies'=>false,'affectEnemies'=>true,'trigger'=>'contact-during-target-turn','maximumPerTargetTurn'=>1,'expiry'=>'source-next-turn-start','style'=>'silver-filament'];
$db=specialFixture();$scene='scene-one';$source=$db->payload('token:scene-one:token-player');$source['x']=20;$db->put('token:scene-one:token-player',$source);
$enemy=$db->payload('token:scene-one:token-monster');$enemy['x']=40;$enemy['y']=40;$enemy['hp']=$enemy['maxHp']=100;$enemy['magicArmor']=50;$enemy['magicArmorCategory']='special';$db->put('token:scene-one:token-monster',$enemy);
$clock=['active'=>true,'combatId'=>'contact-fixture-combat','turnSerial'=>10,'round'=>2,'order'=>['token-player','token-monster'],'currentIndex'=>0];$db->put('initiative:scene-one',$clock);
$zone=applicationCreateContactZone($definition,['id'=>'contact-zone-fixture','sceneId'=>$scene,'layerId'=>'ground','sourceTokenId'=>'token-player','sourceUnitId'=>'character-player','sourceControllerId'=>'account-player','originExecutionId'=>'contact-origin-execution','initiative'=>$clock,'now'=>100,'origin'=>['x'=>20,'y'=>50]]);
$zone=applicationAppendContactZonePath($zone,[['x'=>20,'y'=>50],['x'=>60,'y'=>50]]);
$state=domainsToApplicationState($db->domains,0);$state['devices']['contactZones']=[$zone];
specialCheck(applicationCollectContactZoneMovement($state,$enemy,['x'=>40,'y'=>40],['x'=>40,'y'=>60])===[],'Contact outside victim turn neither hurts nor consumes its ledger');
$state['initiative']=[...$clock,'turnSerial'=>11,'currentIndex'=>1];
$events=applicationCollectContactZoneMovement($state,$enemy,['x'=>40,'y'=>40],['x'=>40,'y'=>60]);
specialCheck(count($events)===1&&$events[0]['damage']===['formula'=>'1d20','damageType'=>'magical'],'Crossing detects the whole accepted path and its normal magical damage');
specialCheck(count($state['devices']['contactZones'][0]['contacts'])===1,'One contact recorded');
specialCheck(applicationCollectContactZoneMovement($state,$enemy,['x'=>40,'y'=>60],['x'=>40,'y'=>40])===[],'Exit and reentry cannot hurt twice within the same turn');
specialCheck($events[0]['requestId']===applicationContactZoneRequestId($zone['id'],'unit_token-monster',$clock['combatId'],11),'Deterministic receipt uses canonical unit and turn');
$inside=[...$enemy,'y'=>50];$insideState=domainsToApplicationState($db->domains,0);$insideState['initiative']=[...$clock,'turnSerial'=>12,'currentIndex'=>1];$insideState['devices']['contactZones']=[$zone];
specialCheck(count(applicationCollectContactZoneMovement($insideState,$inside,['x'=>40,'y'=>50],['x'=>40,'y'=>50]))===1,'Already inside at turn start is a contact');
$rewoundContact=$insideState;
$rewoundContact['initiative']=[...$insideState['initiative'],'turnSerial'=>13,'rewoundTurnSerial'=>13];
specialCheck(applicationCollectContactZoneMovement($rewoundContact,$inside,['x'=>40,'y'=>50],['x'=>40,'y'=>50])===[]
    &&$rewoundContact['devices']['contactZones']===$insideState['devices']['contactZones'],'A rewound initiative cannot hit a victim already standing on the filament again');
$ally=[...$enemy,'id'=>'ally-token','controllerPlayerId'=>'account-player'];$allied=$insideState;$allied['map']['tokens'][]=$ally;$allied['initiative']['order'][1]='ally-token';$allied['devices']['contactZones']=[$zone];
specialCheck(applicationCollectContactZoneMovement($allied,$ally,['x'=>40,'y'=>40],['x'=>40,'y'=>60])===[],'Allies are excluded by default');
$allied['devices']['contactZones'][0]['friendlyFire']=true;specialCheck(count(applicationCollectContactZoneMovement($allied,$ally,['x'=>40,'y'=>40],['x'=>40,'y'=>60]))===1,'Authoritative Friendly Fire opens allied contact through the common relation rule');
$allied['initiative']=[...$clock,'turnSerial'=>13];specialCheck(applicationCollectContactZoneMovement($allied,$source,['x'=>20,'y'=>50],['x'=>30,'y'=>50])===[],'Friendly Fire does not invent affectCaster');
$alias=[...$source,'id'=>'linked-caster-alias'];$aliasState=$insideState;$aliasState['map']['tokens'][]=$alias;$aliasState['initiative']['order'][1]=$alias['id'];$aliasState['devices']['contactZones'][0]['friendlyFire']=true;specialCheck(applicationCollectContactZoneMovement($aliasState,$alias,['x'=>20,'y'=>50],['x'=>30,'y'=>50])===[],'Friendly Fire never bypasses affectCaster through another linked occurrence of the same source unit');
$next=[...$clock,'turnSerial'=>12,'currentIndex'=>0];
$context=['tokens'=>[$source,$enemy]];
$visual=applicationAdvanceContactZones([$zone],$scene,$clock,$next,$context);
specialCheck(($visual[0]['visualOnlySince']??null)===['turnSerial'=>12,'round'=>2,'manualTurn'=>0],'Damage expires exactly at the next source turn start');
$otherTurn=[...$next,'turnSerial'=>13,'currentIndex'=>1];
$decorativeState=$insideState;
$decorativeState['initiative']=$otherTurn;
$decorativeState['devices']['contactZones']=$visual;
specialCheck(applicationCollectContactZoneMovement($decorativeState,$enemy,['x'=>40,'y'=>40],['x'=>40,'y'=>60])===[]&&$decorativeState['devices']['contactZones']===$visual,'A real crossing of the decorative filament cannot hurt or consume a contact');
$second=[...$next,'turnSerial'=>14,'round'=>3];
$rewound=[...$otherTurn,'currentIndex'=>0,'turnSerial'=>14,'rewoundTurnSerial'=>14];
specialCheck(applicationAdvanceContactZones($visual,$scene,$otherTurn,$rewound,$context)===$visual,'Revisiting the source turn preserves the decorative filament');
specialCheck(applicationAdvanceContactZones($visual,$scene,$otherTurn,$second,$context)===[],'The drawing ends at the second source turn start');
$linked=[...$source,'id'=>'other-linked-source'];
$nextLinked=$next;$nextLinked['order'][0]=$linked['id'];
$secondLinked=$second;$secondLinked['order'][0]=$linked['id'];
$linkedContext=['tokens'=>[$linked,$enemy]];
$linkedVisual=applicationAdvanceContactZones([$zone],$scene,$clock,$nextLinked,$linkedContext);
specialCheck($linkedVisual===$visual&&applicationAdvanceContactZones($linkedVisual,$scene,$nextLinked,$secondLinked,$linkedContext)===[],'Linked source identity uses the canonical unit for both deadlines');
specialCheck(applicationAdvanceContactZones($visual,$scene,$next,['active'=>false])===[],'Combat end clears a decorative transient zone');
$missing=[...$clock,'turnSerial'=>12,'round'=>3,'order'=>['token-monster']];
$missingContext=['tokens'=>[$enemy]];
$missingVisual=applicationAdvanceContactZones([$zone],$scene,$clock,$missing,$missingContext);
$sameRound=[...$missing,'turnSerial'=>13];$followingRound=[...$sameRound,'turnSerial'=>14,'round'=>4];
specialCheck(($missingVisual[0]['visualOnlySince']['round']??null)===3
    &&applicationAdvanceContactZones($missingVisual,$scene,$missing,$sameRound,$missingContext)===$missingVisual
    &&applicationAdvanceContactZones($missingVisual,$scene,$sameRound,$followingRound,$missingContext)===[],'An absent source gets the remaining display round and cannot leave an eternal zone');
foreach([['radiusCells'=>'0.08'],['radiusCells'=>true],['version'=>2],['style'=>'fire'],['maximumPerTargetTurn'=>2]]as$invalid){try{applicationContactZoneDefinition([...$definition,...$invalid]);specialCheck(false,'Invalid definition accepted');}catch(RuntimeException){specialCheck(true,'Strict contact definition rejects forged or future fields');}}
$public=applicationPublicContactZones([$zone],['sceneId'=>$scene,'layerId'=>'ground','map'=>$state['map'],'visible'=>static fn($x,$y)=>$x<35]);
specialCheck(count($public)===1&&$public[0]['style']==='silver-filament','Only whitelisted public geometry is projected');
specialCheck(array_diff(array_keys($public[0]),['id','kind','sceneId','layerId','paths','radiusCells','style'])===[],'No controller, source, ledger, clock or execution leaks');
specialCheck(applicationPublicContactZones($visual,['sceneId'=>$scene,'layerId'=>'ground','map'=>$state['map'],'visible'=>static fn($x,$y)=>$x<35])===$public,'Decorative geometry retains the same filtered public drawing without leaking its clock');
specialCheck(array_filter(array_merge(...$public[0]['paths']),static fn($p)=>$p['x']>=35)===[],'No hidden path point is disclosed');
specialCheck(applicationPublicContactZones([$zone],['sceneId'=>$scene,'layerId'=>'ground','paused'=>true,'visible'=>static fn()=>true])===[],'Pause hides environmental cues');

// Real command authority: moving the victim records only contact consequences
// into the originating action. Undoing that spell leaves this later move alone.
$before=[];foreach($db->domains as$key=>$record)$before[$key]=$record['payload'];
$store=$db->payload('devices')?:dsEmpty();$store['contactZones']=[$zone];$db->put('devices',$store);$activity=$db->payload('activity');$activity['playerActions'][]=['id'=>'action-contact-source-fixture','kind'=>'ability','summary'=>'Source contact fixture'];$db->put('activity',$activity);
$after=[];foreach($db->domains as$key=>$record)$after[$key]=$record['payload'];
combatUndoRecord($before,$after,'ability.complex',['action'=>'start','sceneId'=>$scene,'sourceTokenId'=>'token-player','requestId'=>'contact-source-request'],['execution'=>['id'=>'contact-origin-execution','abilityName'=>'Contact fixture']]);$db->put('activity',$after['activity']);
$db->put('initiative:scene-one',[...$clock,'turnSerial'=>11,'currentIndex'=>1]);
$move=runCommand($db,'token.move',['sceneId'=>$scene,'layerId'=>'ground','tokenId'=>'token-monster','x'=>40,'y'=>60],true);
specialCheck($move->status===200,'Real own-turn enemy movement succeeds: '.json_encode($move->body));
$receipts=array_values(array_filter($db->payload('activity')['attackReceipts'],static fn($r)=>isset($r['attack']['contactZoneId'])));
specialCheck(count($receipts)===1,'One real environmental attack receipt');$attack=$receipts[0]['attack'];
specialCheck($attack['damageType']==='magical'&&($attack['damage']['components'][0]['armorPercent']??$attack['damage']['armorPercent']??null)===50,'Normal magical armor remains in the shared damage pipeline');
specialCheck($attack['originExecutionId']==='contact-origin-execution'&&!isset($attack['complexExecutionId']),'Contact has private causal identity but never becomes a workflow strike');
$position=$db->payload('token:scene-one:token-monster');$actions=$db->payload('activity')['combatActions'];specialCheck(count($actions)===1,'Unrelated physical movement is not merged into a second spell action');
$undo=runCommand($db,'action.undo',['actionId'=>'action-contact-source-fixture','requestId'=>'contact-origin-undo-001'],true);
specialCheck($undo->status===200,'Whole originating action can be undone: '.json_encode($undo->body));$restored=$db->payload('token:scene-one:token-monster');
specialCheck($restored['hp']===100&&$restored['x']===$position['x']&&$restored['y']===$position['y'],'Undo restores only causal damage and preserves the later victim position');
specialCheck(($db->payload('devices')['contactZones']??[])===[],'Undo also removes the actual filament and contact ledger');
$store=$db->payload('devices');
$store['contactZones']=$visual;
$db->put('devices',$store);
$db->put('initiative:scene-one',$otherTurn);
$move=runCommand($db,'token.move',['sceneId'=>$scene,'layerId'=>'ground','tokenId'=>'token-monster','x'=>40,'y'=>40],true);
$decorativeEnemy=$db->payload('token:scene-one:token-monster');
specialCheck($move->status===200&&$decorativeEnemy['y']===40&&$decorativeEnemy['hp']===100
    &&$db->payload('devices')['contactZones']===$visual,'The real movement command crosses the decorative trail without adding damage or a contact receipt');
echo json_encode(['ok'=>true,'checks'=>$specialChecks,'productionAccess'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
