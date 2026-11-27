<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);require __DIR__.'/tactical-special-effects.php';
$specialChecks=0;$id='ability-ae84d88e-2469-45f5-b22e-b56971892753';
$ability=['id'=>$id,'name'=>'Bottes du Serpent Céleste','effect'=>'complex','formula'=>'0','castingStatId'=>'','usesPerCombat'=>1,'manaCost'=>0,'fatigueCost'=>0,'cooldownRounds'=>0,'workflow'=>['version'=>8,'steps'=>[
 ['id'=>'move','type'=>'movement','maximumMeters'=>20],['id'=>'targets','type'=>'targets','minTargets'=>0,'maxTargets'=>20,'maximumPerTarget'=>1,'allocationTotal'=>0,'rangeCells'=>0,'sourceMovementStepId'=>'move','targetRelation'=>'enemies'],
 ['id'=>'strikes','type'=>'allocated-attacks','sourceStepId'=>'targets','awarenessMode'=>'none','damageMode'=>'configured','hitMode'=>'automatic','statId'=>'character-stat-agility','damageComponents'=>[['type'=>'magical','formula'=>'1d30+6','ignoreArmor'=>true]],'defenseStatId'=>'character-stat-agility','defenseRollMode'=>'disadvantage']
]]];
$db=specialFixture();$c=$db->payload('character:character-player');$c['abilities']=[$ability];$db->put('character:character-player',$c);
$source=synchronizeOnlineCharacterToken($db->payload('token:scene-one:token-player'),$c);$source['x']=20;$db->put('token:scene-one:token-player',$source);
$map=$db->payload('map:scene-one');$map['naturalWidth']=1000;$map['naturalHeight']=600;$db->put('map:scene-one',$map);
foreach([['token-monster',30],['token-monster-two',40]]as[$tokenId,$x]){$target=$db->payload('token:scene-one:'.$tokenId);$target=[...$target,'x'=>$x,'y'=>50,'size'=>40,'hp'=>500,'maxHp'=>500,'magicArmor'=>100,'magicArmorCategory'=>'special','stats'=>[['id'=>'character-stat-agility','label'=>'Agilité','value'=>'100']]];$db->put('token:scene-one:'.$tokenId,$target);}
$db->put('token:scene-one:token-monster-two',[...$db->payload('token:scene-one:token-monster-two'),'elementalResistances'=>['fire'=>50]]);
$args=['action'=>'start','requestId'=>'path-strike-start-'.randomToken(16),'sceneId'=>'scene-one','layerId'=>'ground','sourceTokenId'=>'token-player','abilityId'=>$id,'initialSelection'=>['allocations'=>[['tokenId'=>'token-monster-two','count'=>1],['tokenId'=>'token-monster','count'=>1]]],'movementSelection'=>['destination'=>['x'=>50,'y'=>50],'path'=>[['x'=>20,'y'=>50],['x'=>50,'y'=>50]]]];
$before=$db->payload('character:character-player');$start=runCommand($db,'ability.complex',$args);
specialCheck($start->status===200&&!empty($start->body['pendingAreaGate'])&&$start->body['execution']===null,'Route is prepared for the MJ before any execution: '.json_encode($start->body));
specialCheck($db->payload('token:scene-one:token-player')['x']===20&&$db->payload('character:character-player')===$before,'Preparation neither moves nor charges the source');
$reject=runCommand($db,'token.attack.area.resolve',['attackId'=>$start->body['attack']['id'],'decision'=>'reject','confirmed'=>true,'defenseTokenIds'=>[],'requestId'=>'path-strike-refuse-'.randomToken(16)],true);
specialCheck($reject->status===200&&$db->payload('token:scene-one:token-player')['x']===20,'Refusing the MJ table leaves the source untouched');
specialCheck(empty($db->payload('devices')['contactZones'])&&empty($db->payload('activity')['abilityCueEvents']),'Refusal creates neither ground effect nor dash');
$args['requestId']='path-strike-accept-'.randomToken(16);$start=runCommand($db,'ability.complex',$args);
$approve=runCommand($db,'token.attack.area.resolve',['attackId'=>$start->body['attack']['id'],'decision'=>'approve','confirmed'=>true,'defenseTokenIds'=>['token-monster'],'requestId'=>'path-strike-approve-'.randomToken(16)],true);
specialCheck($approve->status===200&&is_array($approve->body['execution']??null),'Real MJ decision starts the prepared route: '.json_encode($approve->body));$execution=$approve->body['execution'];
specialCheck(($db->payload('activity')['abilityExecutions'][0]['sourceSight']??false)===true,
    'The new caster sight persists across workflow actions');
specialCheck($db->payload('token:scene-one:token-player')['x']===20,'MJ approval itself does not teleport the source');
specialCheck(count($db->payload('devices')['contactZones']??[])===1&&count($db->payload('devices')['contactZones'][0]['points'])===1,'The filament lifetime starts once at the approved cast, before its first real hop');
specialCheck(array_column($execution['stepStates']['targets']['attackPlan'],'tokenId')===['token-monster','token-monster-two'],'Actual workflow orders enemies by descending distance to final point');
$command=static function(array $body)use($db,&$execution):TestResponse {$r=runCommand($db,'ability.complex',['action'=>'command','requestId'=>'path-cmd-'.randomToken(20),'executionId'=>$execution['id'],'expectedRevision'=>$execution['revision'],'command'=>$body]);if(isset($r->body['execution']))$execution=$r->body['execution'];return $r;};
foreach(['token-monster','token-monster-two']as$index=>$targetId){
 $prepared=$command(['action'=>'prepare-target','targetTokenId'=>$targetId]);specialCheck($prepared->status===200,'Next physical hop is accepted: '.json_encode($prepared->body));
 if($index===0){try{applicationPathStrikeValidatedTravel($execution,$execution['workflow']['steps'][0],$execution['stepStates']['move'],[],[],['x'=>0,'y'=>0],['x'=>1,'y'=>1],[]);specialCheck(false,'Premature next hop accepted');}catch(ApplicationComplexAbilityException){specialCheck(true,'A forged next hop is rejected before the real 360 ms arrival');}}
 $actual=$db->payload('token:scene-one:token-player');$stop=$execution['stepStates']['move']['targets'][$index]['contactPoint'];specialCheck(abs($actual['x']-$stop['x'])<1e-7&&abs($actual['y']-$stop['y'])<1e-7,'Token is really at the selected enemy contact');
 usleep(730000); // Half-speed presentation: the dash arrives after 720 ms.
 $request='path-hit-'.randomToken(20);$r=runCommand($db,'token.attack',['requestId'=>$request,'sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>$targetId,'attackKind'=>'ability','attackId'=>$id,'statId'=>'character-stat-agility','opposed'=>false,'complexExecutionId'=>$execution['id']]);
 specialCheck($r->status===200,'Existing configured strike accepted: '.json_encode($r->body));$attack=$r->body['attack'];
 if($index===0){specialCheck($attack['status']==='awaiting-opposition','Player cannot remove the defense allowed by the MJ');$stored=array_values(array_filter($db->payload('activity')['attackReceipts'],static fn($receipt)=>$receipt['requestId']===$request))[0]['attack'];specialCheck($stored['defenseStatId']==='character-stat-agility'&&$stored['defenseRollMode']==='disadvantage','Original Agility defense with disadvantage is preserved');$r=runCommand($db,'token.attack.oppose',['attackId'=>$attack['id'],'requestId'=>'path-defense-'.randomToken(16),'decision'=>'skip'],true);specialCheck($r->status===200,'MJ can skip the offered defense: '.json_encode($r->body));}
 $stored=array_values(array_filter($db->payload('activity')['attackReceipts'],static fn($receipt)=>$receipt['requestId']===$request))[0]['attack'];
 $part=$stored['damage']['components'][0];$resistance=$index===0?0:50;
 specialCheck($part['damageElement']==='fire'&&$part['rawDamage']>=7&&$part['rawDamage']<=36&&$part['armorPercent']===0
    &&($part['elementalResistancePercent']??0)===$resistance&&$stored['appliedDamage']===(int)round($part['rawDamage']*(100-$resistance)/100),
    'The 1d30+6 strike bypasses armor and respects fire resistance');
 specialCheck(($db->payload('token:scene-one:'.$targetId)['x'])===($index===0?30:40),'Dash does not push its victim');
 $confirmed=$command(['action'=>'confirm-attack','attackRequestId'=>$request]);specialCheck($confirmed->status===200,'Attack confirmation advances the actual route: '.json_encode($confirmed->body));
}
$final=$db->payload('token:scene-one:token-player');specialCheck($execution['status']==='completed'&&abs($final['x']-50)<1e-7&&abs($final['y']-50)<1e-7,'Last real hop reaches the chosen final point');
$store=$db->payload('devices');specialCheck(count($store['contactZones'])===1&&$store['contactZones'][0]['definition']['damage']===['formula'=>'1d20','damageType'=>'magical'],'One persistent normal-magical contact filament follows the accepted hops');
specialCheck($store['contactZones'][0]['contacts']===[],'Enemy contacts are not charged during Hira’s own turn');
$dashes=array_values(array_filter($db->payload('activity')['abilityCueEvents'],static fn($event)=>($event['completionCue']['spell']['presetId']??'')==='token-celestial-dash'));
specialCheck(count($dashes)===3&&array_filter($dashes,static fn($event)=>$event['completionCue']['sound']!==null)===[],'Two hops and one final move have silent token afterimages, without a melee X');
specialCheck(($db->payload('character:character-player')['resources']['mana']??0)===100&&($db->payload('character:character-player')['fatigue']['current']??0)===0,'Original zero mana/fatigue costs remain unchanged');

$foreignMove=runCommand($db,'token.move',['sceneId'=>'scene-one','layerId'=>'ground','tokenId'=>'token-monster-two','x'=>40,'y'=>70],true);specialCheck($foreignMove->status===200,'A later unrelated victim move is accepted');$foreignPosition=$db->payload('token:scene-one:token-monster-two');
$action=null;foreach($db->payload('activity')['combatActions']as$a)if(in_array('execution:'.$execution['id'],$a['aliases'],true))$action=$a;$entry=null;foreach($db->payload('activity')['playerActions']as$a)if(($a['combatActionId']??'')===($action['id']??'')){$entry=$a;break;}
specialCheck($entry!==null,'The full route has a common undo action');$undo=runCommand($db,'action.undo',['actionId'=>$entry['id'],'requestId'=>'path-whole-undo-'.randomToken(18)],true);
specialCheck($undo->status===200,'Whole real route undo succeeds: '.json_encode($undo->body));specialCheck(abs($db->payload('token:scene-one:token-player')['x']-20)<1e-7,'Undo restores the source before its dash');
specialCheck($db->payload('token:scene-one:token-monster')['hp']===500&&$db->payload('token:scene-one:token-monster-two')['hp']===500,'Undo restores all original attack damage');
$foreignAfter=$db->payload('token:scene-one:token-monster-two');specialCheck($foreignAfter['x']===$foreignPosition['x']&&$foreignAfter['y']===$foreignPosition['y'],'Undo keeps the unrelated later victim position');
specialCheck(empty($db->payload('devices')['contactZones'])&&empty($db->payload('activity')['abilityMovementLeases']),'Undo removes filament and movement locks');

$fresh=static function()use($ability,$map):MemoryConnection{$d=specialFixture();$c=$d->payload('character:character-player');$c['abilities']=[$ability];$d->put('character:character-player',$c);$s=synchronizeOnlineCharacterToken($d->payload('token:scene-one:token-player'),$c);$s['x']=20;$d->put('token:scene-one:token-player',$s);$d->put('map:scene-one',$map);foreach([['token-monster',30],['token-monster-two',40]]as[$id,$x]){$t=$d->payload('token:scene-one:'.$id);$d->put('token:scene-one:'.$id,[...$t,'x'=>$x,'y'=>50,'size'=>40,'hp'=>500,'maxHp'=>500]);}return $d;};

$frozen=$fresh();
$sheet=$frozen->payload('character:character-player');
$sheet['elementalResistances']=['ice'=>90];
$frozen->put('character:character-player',$sheet);
$tokenKey='token:scene-one:token-player';
$pawn=synchronizeOnlineCharacterToken($frozen->payload($tokenKey),$sheet);
$frozen->put($tokenKey,$pawn);
$store=dsEmpty();
$store['timedConditions']=[['id'=>'frozen-path','label'=>'Glacé','sceneId'=>'scene-one','targetTokenId'=>'token-player',
    'targetCharacterId'=>'character-player','remainingTurns'=>3,'blocksMovement'=>true]];
$frozen->put('devices',$store);
$frozenRequest=[...$args,'requestId'=>'path-frozen-'.randomToken(16)];
$blocked=runCommand($frozen,'ability.complex',$frozenRequest);
specialCheck($blocked->status===409&&str_contains($blocked->body['message']??$blocked->body['error']??'','immobilisé'),
    '90 ice resistance does not unlock the frozen path');
$caster=[...$frozen->payload('token:scene-one:token-monster'),'id'=>'emblem-caster','x'=>20,'y'=>30,'controllerPlayerId'=>'account-ally'];
$frozen->put('token:scene-one:emblem-caster',$caster);
$activity=$frozen->payload('activity');
$activity['attackEnhancements']=[['id'=>'path-emblem','abilityId'=>'ability-5f30d8c5-4efc-47d5-bf03-ea5cb192222b',
    'label'=>'Emblème','mode'=>'bonus','formula'=>'10','damageType'=>'inherit','trigger'=>'all-attacks',
    'aura'=>['radiusCells'=>4,'affectCaster'=>true,'affectAllies'=>true,'affectEnemies'=>false],'sceneId'=>'scene-one',
    'holder'=>'token:emblem-caster','casterTokenId'=>'emblem-caster','targetTokenId'=>'emblem-caster','remainingTurns'=>5,'turns'=>5]];
$frozen->put('activity',$activity);
$unblocked=runCommand($frozen,'ability.complex',[...$frozenRequest,'requestId'=>'path-with-emblem-'.randomToken(16)]);
specialCheck($unblocked->status===200&&!empty($unblocked->body['pendingAreaGate']),
    'A friendly active Emblem raises ice resistance from 90 to 100 and unlocks the real path: '.json_encode($unblocked->body));
$unblockedDecision=runCommand($frozen,'token.attack.area.resolve',['attackId'=>$unblocked->body['attack']['id'],
    'decision'=>'approve','confirmed'=>true,'defenseTokenIds'=>[],'requestId'=>'path-with-emblem-approve-'.randomToken(16)],true);
specialCheck($unblockedDecision->status===200&&is_array($unblockedDecision->body['execution']??null),
    'The immunity holds on real GM approval of the planned route');
$unblockedExecution=$unblockedDecision->body['execution'];
$firstHop=runCommand($frozen,'ability.complex',['action'=>'command','requestId'=>'path-with-emblem-hop-'.randomToken(16),
    'executionId'=>$unblockedExecution['id'],'expectedRevision'=>$unblockedExecution['revision'],
    'command'=>['action'=>'prepare-target','targetTokenId'=>'token-monster']]);
specialCheck($firstHop->status===200&&$frozen->payload($tokenKey)['x']!==20,
    'The player takes the real hop without the suppressed freeze: '.json_encode($firstHop->body));
specialCheck($frozen->payload('character:character-player')['elementalResistances']['ice']===90
    &&!isset($frozen->payload($tokenKey)['elementalResistanceOverrides'])
    &&($frozen->payload('devices')['timedConditions'][0]['id']??null)==='frozen-path',
    'The command preserves the base resistance and existing freeze, without persisting a computed override');

$zero=$fresh();foreach(['token-monster','token-monster-two']as$i=>$id){$t=$zero->payload('token:scene-one:'.$id);$zero->put('token:scene-one:'.$id,[...$t,'x'=>80+$i*10,'y'=>80]);}
$emptyArgs=[...$args,'requestId'=>'path-zero-'.randomToken(20),'initialSelection'=>['allocations'=>[]]];$empty=runCommand($zero,'ability.complex',$emptyArgs);
specialCheck($empty->status===200&&empty($empty->body['pendingAreaGate'])&&($empty->body['initialSelectionApplied']??false)&&$empty->body['execution']['status']==='completed','Zero chosen victims does not create an empty MJ table: '.json_encode($empty->body));
specialCheck(abs($zero->payload('token:scene-one:token-player')['x']-50)<1e-7&&count($zero->payload('devices')['contactZones'])===1,'Zero-target route still moves to its endpoint and leaves its accepted filament');

$changeClock=static function(MemoryConnection $fixture,array $updates):void {
    $records=applicationDomainRecords($fixture);
    $pending=[];
    $identity=['id'=>'account-gm','display_name'=>'MJ','effective_mode'=>'gm','permanent_role'=>'gm'];
    queueOnlineDomainUpsert($pending,$records,'initiative:scene-one',[...$fixture->payload('initiative:scene-one'),...$updates]);
    foreach(onlineAdvanceDevicesOnChanges($fixture,$records,array_values($pending),$identity) as $entry){
        $fixture->put($entry['key'],$entry['payload']);
    }
};
$changeClock($zero,['currentIndex'=>1,'turnSerial'=>11]);
specialCheck(count($zero->payload('devices')['contactZones'])===1&&!isset($zero->payload('devices')['contactZones'][0]['visualOnlySince']),'A different actor starting their turn leaves the filament armed');
$changeClock($zero,['currentIndex'=>0,'turnSerial'=>12,'round'=>2]);
$decorative=$zero->payload('devices')['contactZones'];
specialCheck(($decorative[0]['visualOnlySince']??null)===['turnSerial'=>12,'round'=>2,'manualTurn'=>0],'The next caster turn keeps the filament as a drawing without contact damage');
$changeClock($zero,['currentIndex'=>0,'turnSerial'=>12,'round'=>2]);
specialCheck($zero->payload('devices')['contactZones']===$decorative,'A replayed initiative revision keeps the decorative filament');
$changeClock($zero,['currentIndex'=>1,'turnSerial'=>13]);
specialCheck($zero->payload('devices')['contactZones']===$decorative,'Other actors do not consume the second display turn');
$changeClock($zero,['currentIndex'=>0,'turnSerial'=>14,'round'=>3]);
specialCheck($zero->payload('devices')['contactZones']===[],'The second caster turn expires the drawing');

$free=$fresh();
$clock=$free->payload('initiative:scene-one');
$clock['active']=false;
$free->put('initiative:scene-one',$clock);
$cast=runCommand($free,'ability.complex',[...$emptyArgs,'requestId'=>'path-free-'.randomToken(20)]);
specialCheck($cast->status===200&&$cast->body['execution']['status']==='completed','Path can be used in free play: '.json_encode($cast->body));
$freeStore=$free->payload('devices');
$zone=$freeStore['contactZones'][0];
specialCheck($zone['definition']['style']==='silver-filament','The path makes its actual silver filament visible');
$freeStore['contactZones'][]=[...$zone,'id'=>'filament-other-scene','sceneId'=>'scene-two'];
$free->put('devices',$freeStore);
$beforeHp=$free->payload('token:scene-one:token-monster')['hp'];
$advanceArgs=['requestId'=>'path-free-advance-'.randomToken(20)];
$advanced=specialMechanics($free,'scene.advance',$advanceArgs);
specialCheck($advanced->status===200,'The GM can advance the next turn in free play');
$after=$free->payload('devices');
specialCheck(array_column($after['contactZones'],'id')===[$zone['id'],'filament-other-scene']
    &&($after['contactZones'][0]['visualOnlySince']['manualTurn']??null)===1
    &&!isset($after['contactZones'][1]['visualOnlySince']),'The first free turn makes only this scene’s filament decorative');
specialCheck($beforeHp===$free->payload('token:scene-one:token-monster')['hp'],'A decorative free play filament deals no damage');
specialMechanics($free,'scene.advance',$advanceArgs);
specialCheck($free->payload('devices')['manualTurns']['scene-one']===1&&$free->payload('devices')['contactZones']===$after['contactZones'],'Replaying a turn preserves the clock and filament');
$advanced=specialMechanics($free,'scene.advance',['requestId'=>'path-free-second-'.randomToken(20)]);
$after=$free->payload('devices');
specialCheck($advanced->status===200&&$after['manualTurns']['scene-one']===2&&array_column($after['contactZones'],'id')===['filament-other-scene'],'The second free turn clears the filament only from the active scene');

$crowded=$fresh();
$crowdedTokens=[];
foreach(['token-monster','token-monster-two'] as $i=>$tokenId){
    $key='token:scene-one:'.$tokenId;
    $crowdedTokens[$key]=[...$crowded->payload($key),'x'=>24+13*$i,'y'=>50,'size'=>140];
    $crowded->put($key,$crowdedTokens[$key]);
}
$occupied=['x'=>37,'y'=>50];
$tooFar=['x'=>80,'y'=>50];
$longStart=runCommand($crowded,'ability.complex',[...$emptyArgs,'requestId'=>'path-long-'.randomToken(16),
    'movementSelection'=>['destination'=>$tooFar,'path'=>[['x'=>20,'y'=>50],$tooFar]]]);
specialCheck($longStart->status===409&&str_contains($longStart->body['error']??'','24 m et dépasse la limite de 20 mètres'),
    'The player gets the measured distance and the limit when the route is too long.');
$occupiedStart=runCommand($crowded,'ability.complex',[...$emptyArgs,'requestId'=>'path-occupied-'.randomToken(16),
    'movementSelection'=>['destination'=>$occupied,'path'=>[['x'=>20,'y'=>50],$occupied]]]);
specialCheck($occupiedStart->status===409&&str_contains($occupiedStart->body['error']??'','occupée'),'An ordinary pawn can occupy a landing, but not the route.');
specialCheck($crowded->payload('token:scene-one:token-player')['x']===20,'An invalid landing leaves Hira at her original position.');
$destination=['x'=>50,'y'=>57];
$waypoints=[['x'=>20,'y'=>50],['x'=>29,'y'=>53],$destination];
$pass=runCommand($crowded,'ability.complex',[...$emptyArgs,'requestId'=>'path-crowded-'.randomToken(16),
    'movementSelection'=>['destination'=>$destination,'path'=>$waypoints]]);
specialCheck($pass->status===200&&$pass->body['execution']['status']==='completed','Hira may dash directly through adjacent ordinary units. '.json_encode($pass->body));
$walk=$pass->body['execution']['stepStates']['move'];
specialCheck($walk['points']===$waypoints&&abs($walk['distanceMeters']-applicationPathStrikeMeters($waypoints,$map))<1e-7,
    'The server follows and measures the actual clicked waypoints.');
$arrived=$crowded->payload('token:scene-one:token-player');
specialCheck($arrived['x']===$destination['x']&&$arrived['y']===$destination['y'],'Hira reaches the unobstructed landing.');
foreach($crowdedTokens as $key=>$before){
    $after=$crowded->payload($key);
    specialCheck($after['x']===$before['x']&&$after['y']===$before['y']&&$after['hp']===$before['hp'],
        'Crossed pawns remain in place and unhurt when no strikes were selected.');
}

$mounted=$fresh();$s=$mounted->payload('token:scene-one:token-player');$s=[...$s,'x'=>1,'y'=>1,'mountedOnTokenId'=>'path-mount','mountedAt'=>1];$mounted->put('token:scene-one:token-player',$s);$mount=['id'=>'path-mount','name'=>'Monture','x'=>20,'y'=>50,'size'=>120,'hp'=>100,'maxHp'=>100,'layerId'=>'ground','controllerPlayerId'=>'another-controller'];$mounted->put('token:scene-one:path-mount',$mount);$passenger=[...$mount,'id'=>'path-passenger','mountedOnTokenId'=>'path-mount','mountedAt'=>2,'size'=>40];$mounted->put('token:scene-one:path-passenger',$passenger);$index=$mounted->payload('token-index:scene-one');$index['order']=[...$index['order'],'path-mount','path-passenger'];$mounted->put('token-index:scene-one',$index);
$mountArgs=[...$args,'requestId'=>'path-mounted-'.randomToken(16),'mountedMovement'=>true];$waiting=runCommand($mounted,'ability.complex',$mountArgs);specialCheck($waiting->status===200&&!empty($waiting->body['pendingAreaGate']),'Mounted source prepares from its real mount position');
$accepted=runCommand($mounted,'token.attack.area.resolve',['attackId'=>$waiting->body['attack']['id'],'decision'=>'approve','confirmed'=>true,'defenseTokenIds'=>[],'requestId'=>'path-mounted-approved-'.randomToken(16)],true);specialCheck($accepted->status===200,'Mounted default path passes the same MJ approval: '.json_encode($accepted->body));$e=$accepted->body['execution'];
specialCheck($mounted->payload('token:scene-one:token-player')['mountedOnTokenId']==='path-mount','Preparation and approval never dismount early');
$hop=runCommand($mounted,'ability.complex',['action'=>'command','requestId'=>'path-mounted-hop-'.randomToken(16),'executionId'=>$e['id'],'expectedRevision'=>$e['revision'],'command'=>['action'=>'prepare-target','targetTokenId'=>'token-monster']]);specialCheck($hop->status===200,'Mounted rider begins its real solo dash: '.json_encode($hop->body));
$rider=$mounted->payload('token:scene-one:token-player');specialCheck(!isset($rider['mountedOnTokenId'])&&$rider['size']===40,'Forged top-level mountedMovement cannot override the ability default');specialCheck($mounted->payload('token:scene-one:path-mount')===$mount&&$mounted->payload('token:scene-one:path-passenger')===$passenger,'Mount and other passenger remain entirely unchanged');
// A deliberately contradictory legacy clone flag must still use its own unit.
$clone=$fresh();$sheet=$clone->payload('character:character-player');$sheet['resources']['mana']=40;$sheet['abilities']=[];$clone->put('character:character-player',$sheet);$own=$clone->payload('token:scene-one:token-player');$own=[...$own,'independent'=>true,'followCharacter'=>true,'abilities'=>[[...$ability,'manaCost'=>3]],'mana'=>30,'maxMana'=>100];$clone->put('token:scene-one:token-player',$own);
$r=runCommand($clone,'ability.complex',[...$args,'requestId'=>'path-clone-start-'.randomToken(16)]);specialCheck($r->status===200&&!empty($r->body['pendingAreaGate']),'Independent clone uses its own workflow despite followCharacter legacy flag: '.json_encode($r->body));$r=fixtureApproveDefenseGate($clone,$r);specialCheck($r->status===200,'Independent workflow is approved normally: '.json_encode($r->body));specialCheck($clone->payload('token:scene-one:token-player')['mana']===27&&$clone->payload('character:character-player')['resources']['mana']===40,'Independent clone pays 30 to 27 and leaves model mana 40 untouched');
$cloneAction=null;foreach($clone->payload('activity')['combatActions']as$action)if(in_array('execution:'.$r->body['execution']['id'],$action['aliases'],true))$cloneAction=$action;specialCheck($cloneAction!==null,'Independent cast has its own complete undo action');$undo=runCommand($clone,'action.undo',['actionId'=>$cloneAction['actionIds'][0],'requestId'=>'path-clone-undo-'.randomToken(16)],true);specialCheck($undo->status===200&&$clone->payload('token:scene-one:token-player')['mana']===30&&$clone->payload('character:character-player')['resources']['mana']===40,'Undo restores clone mana 27 to 30 and keeps the model untouched');
foreach ([false,true] as $gm) {
    $limited=$fresh();
    $sheet=$limited->payload('character:character-player');
    $limited->put('character:character-player',[...$sheet,'visionDistance'=>2]);
    $map=$limited->payload('map:scene-one');
    $limited->put('map:scene-one',[...$map,'vision'=>['enabled'=>true,'shared'=>true,'distance'=>12]]);
    $ally=[...$limited->payload('token:scene-one:token-monster'),'id'=>'sight-ally','x'=>55,'y'=>50,
        'characterId'=>'','controllerPlayerId'=>'account-player','visionDistance'=>12];
    $limited->put('token:scene-one:sight-ally',$ally);
    $index=$limited->payload('token-index:scene-one');$index['order'][]='sight-ally';$limited->put('token-index:scene-one',$index);
    $before=$limited->domains;
    $r=runCommand($limited,'ability.complex',[...$args,'requestId'=>'path-caster-sight-'.randomToken(16)],$gm,$gm?'account-gm':'account-player');
    specialCheck($r->status===409&&($r->body['code']??'')==='complex_ability_path_strike_invalid'
        &&$limited->domains===$before,'Shared vision cannot extend the caster route even for the player or during MJ preparation: '.json_encode($r->body));
}
echo json_encode(['ok'=>true,'checks'=>$specialChecks,'productionAccess'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
