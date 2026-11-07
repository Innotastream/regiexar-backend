<?php
declare(strict_types=1);
define('XAR_TACTICAL_FIXTURE_ONLY',true);
require __DIR__.'/tactical-lifecycle.php';
$specialChecks=0;
function specialCheck(bool $v,string $message):void {if(!$v)throw new RuntimeException($message);$GLOBALS['specialChecks']++;}
function specialFixture():MemoryConnection {
    $db=fixture();$db->put('scene-index',['order'=>['scene-one']]);$db->put('scene:scene-one',['metadata'=>['id'=>'scene-one','name'=>'Recette fictive']]);
    $db->put('roster',['players'=>[['id'=>'account-player','name'=>'Recette']],'characterOrder'=>['character-player']]);
    $db->put('map:scene-one',['activeLayerId'=>'ground','grid'=>false,'gridSize'=>50,'naturalWidth'=>1000,'naturalHeight'=>1000]);
    $db->put('initiative:scene-one',['active'=>true,'combatId'=>'special-test','turnSerial'=>10,'order'=>['token-player','token-monster'],'currentIndex'=>0,'round'=>1]);
    $c=$db->payload('character:character-player');$c['abilities']=json_decode(file_get_contents(__DIR__.'/fixtures/tactical-special-effects.json'),true,512,JSON_THROW_ON_ERROR);$c['resources']=['hp'=>100,'maxHp'=>100,'mana'=>100,'maxMana'=>100];$c['fatigue']=['current'=>0,'max'=>150];$c['stats']=['force'=>100,'agility'=>100,'intelligence'=>100,'spiritSocial'=>100];$c['weaponAttacks']=[['id'=>'main','formula'=>'1d6+4','damageType'=>'physical']];$db->put('character:character-player',$c);
    $c['weaponText']='Arme principale / 1d6+4';$db->put('character:character-player',$c);
    $source=$db->payload('token:scene-one:token-player');$source=synchronizeOnlineCharacterToken($source,$c);$source['x']=55;$source['layerId']='ground';$source['size']=40;$db->put('token:scene-one:token-player',$source);
    $m=$db->payload('token:scene-one:token-monster');$m['x']=50;$m['y']=50;$m['layerId']='ground';$m['size']=40;$m['hp']=$m['maxHp']=500;$m['armor']=0;$m['magicArmor']=0;$m['stats']=[['id'=>'character-stat-force','label'=>'Force','value'=>'100']];$m['weaponAttacks']=[['id'=>'monster-main','formula'=>'7','damageType'=>'ignore']];$db->put('token:scene-one:token-monster',$m);return $db;
}
function specialUse(MemoryConnection $db,string $id,string $target='token-monster',array $extra=[]):TestResponse {return runCommand($db,'ability.use',['requestId'=>'special-'.randomToken(20),'sceneId'=>'scene-one','layerId'=>'ground','sourceTokenId'=>'token-player','targetTokenId'=>$target,'abilityId'=>$id,...$extra]);}
function specialMechanics(MemoryConnection $db,string $command,array $args,bool $gm=true):TestResponse {return runCommand($db,'mechanics.command',['command'=>$command,'arguments'=>['sceneId'=>'scene-one','requestId'=>'special-'.randomToken(20),...$args]],$gm);}
function specialMove(MemoryConnection $db,float $x):TestResponse {return runCommand($db,'token.move',['sceneId'=>'scene-one','layerId'=>'ground','tokenId'=>'token-player','x'=>$x,'y'=>50]);}
if(defined('XAR_SPECIAL_FIXTURE_ONLY')&&XAR_SPECIAL_FIXTURE_ONLY)return;

$db=specialFixture();$m=$db->payload('token:scene-one:token-monster');$m['passiveAbilities']=[['version'=>1,'id'=>'boss','kind'=>'mental-control-resistance']];$db->put('token:scene-one:token-monster',$m);
$r=specialUse($db,'eye');specialCheck($r->status===200,'Boss: '.json_encode($r->body));$m=$db->payload('token:scene-one:token-monster');specialCheck(in_array('Influencé',$m['conditions'],true)&&empty($m['controlEffect']),'Boss converts control to influence');specialCheck(($db->payload('devices')['timedConditions'][0]['remainingTurns']??0)===1,'Influence keeps replaced duration');

$db=specialFixture();$r=specialUse($db,'eye');specialCheck($r->status===200,'Control cast');$m=$db->payload('token:scene-one:token-monster');specialCheck($m['controlEffect']['controllerId']==='account-player','Target controlled by caster');specialCheck(($m['hp']??0)===500,'Control preserves victim HP');
$before=['active'=>true,'combatId'=>'special-test','turnSerial'=>10,'order'=>['token-player','token-monster'],'currentIndex'=>0];$clock=[...$before,'turnSerial'=>11,'currentIndex'=>1];$tokens=applicationAdvanceTacticalTokens([$m],$before,$clock);specialCheck(isset($tokens[0]['controlEffect']),'Control persists during actual target turn');$tokens=applicationAdvanceTacticalTokens($tokens,$clock,[...$clock,'turnSerial'=>12,'currentIndex'=>0]);specialCheck(!isset($tokens[0]['controlEffect']),'Control expires after target plays');

$db=specialFixture();$m=$db->payload('token:scene-one:token-monster');$m['passiveAbilities']=[['version'=>1,'id'=>'opportunity','kind'=>'opportunity-attack']];$db->put('token:scene-one:token-monster',$m);
$r=specialMove($db,65);specialCheck($r->status===200,'Leave contact: '.json_encode($r->body));$p=$db->payload('devices')['pendingRolls'][0]??[];specialCheck(($p['type']??'')==='opportunity','Movement queues opportunity');specialCheck($db->payload('character:character-player')['resources']['hp']===100,'No damage before approval');specialCheck(!isset($p['ownerId']),'No player owner on private reaction');
$privateRolls=$db->payload('devices');$privateRolls['pendingRolls'][]=['id'=>'owned-check','ownerId'=>'character-player','ability'=>['private'=>true],'args'=>['private'=>true]];$privateRolls['pendingRolls'][]=['id'=>'foreign-check','ownerId'=>'another-character'];
set_error_handler(static function(int $severity,string $message):never {throw new ErrorException($message,0,$severity);});
try {
 foreach([null,['id'=>'other-player'],['id'=>'account-player']] as $viewer){
  $projected=publicDeviceStore($privateRolls,$viewer,[$db->payload('character:character-player')],['sceneId'=>'scene-one','layerId'=>'ground'],static fn()=>false);
  specialCheck(array_column($projected['pendingRolls'],'id')===(($viewer['id']??'')==='account-player'?['owned-check']:[]),'Ownerless GM reactions and foreign checks never leak to Player or Stream');
  foreach($projected['pendingRolls'] as $publicRoll)specialCheck(!isset($publicRoll['ability'])&&!isset($publicRoll['args']),'An owned check excludes private execution data');
 }
} finally {restore_error_handler();}
$r=specialMechanics($db,'opportunity.resolve',['validationId'=>$p['id'],'approve'=>true],false);specialCheck($r->status===403,'Player cannot approve opportunity');
$r=specialMechanics($db,'opportunity.resolve',['validationId'=>$p['id'],'approve'=>true,'opposed'=>false]);specialCheck($r->status===200,'GM approves opportunity: '.json_encode($r->body));
$reaction=$r->body['attack'];
if($reaction['status']==='pending'){
    specialCheck($db->payload('character:character-player')['resources']['hp']===100,'Remarkable reaction waits for its own GM decision');
    $r=runCommand($db,'token.attack.resolve',['attackId'=>$reaction['id'],'decision'=>'approve','confirmed'=>true],true,'account-gm');
    specialCheck($r->status===200,'GM confirms the remarkable reaction: '.json_encode($r->body));
}
$stored=array_values(array_filter($db->payload('activity')['attackReceipts'],static fn($entry)=>$entry['attack']['id']===$reaction['id']))[0]['attack'];
$expectedHp=$stored['hit']['outcome']['success']?93:100;
specialCheck($db->payload('character:character-player')['resources']['hp']===$expectedHp,'Reaction applies the actual main weapon hit, preserving critical failure');
specialCheck($db->payload('devices')['pendingRolls']===[],'Reaction removed once');

$db=specialFixture();$r=specialUse($db,'influence');specialCheck($r->status===200,'Influence cast');specialCheck(in_array('Influencé',$db->payload('token:scene-one:token-monster')['conditions'],true),'Influence applied');specialCheck($db->payload('devices')['timedConditions'][0]['remainingTurns']===3,'Influence three target turns');
$m=$db->payload('token:scene-one:token-monster');$m['conditions']=['Doute','Motivé'];$m['morale']='Normal';$db->put('token:scene-one:token-monster',$m);$r=specialMechanics($db,'condition.activate',['tokenId'=>'token-monster','condition'=>'Doute']);specialCheck($r->status===200,'Activate doubt: '.json_encode($r->body));$m=$db->payload('token:scene-one:token-monster');specialCheck($m['morale']==='Stressé'&&!in_array('Doute',$m['conditions'],true),'Doubt -1 then removes');$r=specialMechanics($db,'condition.activate',['tokenId'=>'token-monster','condition'=>'Motivé']);specialCheck($r->status===200&&$db->payload('token:scene-one:token-monster')['morale']==='Normal','Motivation restores one level');

$db=specialFixture();$a=$db->payload('character:character-player')['abilities'];$wall=$a[array_search('wall',array_column($a,'id'),true)];$r=runCommand($db,'token.attack',['requestId'=>'wall-'.randomToken(20),'sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-player','attackKind'=>'ability','attackId'=>'wall','targetPoint'=>['x'=>50,'y'=>50],'opposed'=>false]);specialCheck($r->status===200,'Ground wall: '.json_encode($r->body));$r=fixtureApproveDefenseGate($db,$r);specialCheck($r->status===200,'MJ confirms this area before its effects: '.json_encode($r->body));
$wallTokens=array_values(array_filter(array_map(static fn($r)=>$r['payload'],$db->domains),static fn($v)=>isset($v['terrainBarrier'])));specialCheck(count($wallTokens)===1,'Exactly one wall token');$token=$wallTokens[0];specialCheck($token['hp']===75&&$token['maxHp']===75&&$token['size']>=150&&$token['armor']===0&&$token['magicArmor']===0,'Wall stats fixed');specialCheck($token['immovable']===true&&$token['controllerPlayerId']===null,'Wall fixed and not controllable');specialCheck(count($db->payload('activity')['damageOverTime']??[])>=1,'Wall poison registered');
$db=specialFixture();
$c=$db->payload('character:character-player');
$wallAbility=$wall;
$wallAbility['id']='ability-28849018-287a-4ce4-b1fa-7ec786d00807';
$wallAbility['damageComponents']=[['type'=>'magical','formula'=>'10'],['type'=>'ignore','formula'=>'11']];
$c['abilities']=[$wallAbility];$db->put('character:character-player',$c);
$args=['requestId'=>'thorn-wall-rectangular','sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-player',
    'attackKind'=>'ability','attackId'=>$wallAbility['id'],'targetPoint'=>['x'=>50,'y'=>50,'rotation'=>90],'opposed'=>false];
$r=runCommand($db,'token.attack',$args);
specialCheck($r->status===200&&!empty($r->body['pendingAreaGate']),'Oriented wall can be prepared: '.json_encode($r->body));
$r=fixtureApproveDefenseGate($db,$r);specialCheck($r->status===200,'Rectangular wall resolves: '.json_encode($r->body));
$walls=array_values(array_filter(array_column($db->domains,'payload'),static fn($v)=>isset($v['terrainBarrier'])));
$token=$walls[0];$wallKey='token:scene-one:'.$token['id'];
specialCheck($token['terrainBarrier']['rotation']===90&&$token['immovable']===false&&$token['terrainBarrier']['playerSide']===true,'Rectangular obstacle keeps its rotation and caster side');
$cue=array_values(array_filter($db->payload('activity')['abilityCueEvents']??[],static fn($event)=>($event['completionCue']['spell']['presetId']??'')==='thorn-wall-growth'));
specialCheck(count($cue)===1&&$cue[0]['sourceTokenId']==='token-player','Wall growth cue is emitted once by the caster');
specialCheck($cue[0]['completionCue']['spell']['targetPoint']['rotation']===90&&$cue[0]['completionCue']['spell']['anchorTokenId']===$token['id'],'Cue preserves the actual wall position and rotation');
specialCheck($db->payload('character:character-player')['resources']['mana']===70,'Canonical wall costs thirty mana');
$monsterKey='token:scene-one:token-monster';
$m=$db->payload($monsterKey);
specialCheck($m['hp']===479,'Initial magic and poison are both immediate');
$dots=array_values(array_filter($db->payload('activity')['damageOverTime']??[],static fn($dot)=>$dot['targetTokenId']==='token-monster'));
specialCheck(count($dots)===1&&$dots[0]['remainingTurns']===2,'Poison has precisely two later ticks');
$map=$db->payload('map:scene-one');$mask=applicationActiveMapOcclusionState($map,[$token])['walls'];
$covers=static fn($mask,$x,$y)=>applicationMaskBit(applicationWallMaskBytes($mask),(int)round($y/100*($mask['height']-1))*$mask['width']+(int)round($x/100*($mask['width']-1)));
specialCheck($covers($mask,50,60)&&!$covers($mask,60,50),'Wall mask keeps the vertical rectangular footprint');
$move=['sceneId'=>'scene-one','layerId'=>'ground','tokenId'=>$token['id'],'x'=>60,'y'=>50];
specialCheck(runCommand($db,'token.move',$move)->status===403,'Player cannot drag the obstacle');
$r=runCommand($db,'token.move',$move,true);
specialCheck($r->status===200&&$db->payload($wallKey)['x']==60,'GM can move the obstacle: '.json_encode($r->body));
$token=$db->payload($wallKey);$mask=applicationActiveMapOcclusionState($map,[$token])['walls'];
specialCheck(!$covers($mask,50,60)&&$covers($mask,60,60),'Only the new footprint blocks movement');
$m['x']=60;$m['y']=66;$m['magicArmor']=0;$m['abilities']=[['id'=>'demolition','name'=>'Démolition','effect'=>'damage','formula'=>'75','damageType'=>'ignore','castingStatId'=>'']];
$db->put($monsterKey,$m);
$initiative=$db->payload('initiative:scene-one');$initiative['currentIndex']=1;$db->put('initiative:scene-one',$initiative);
$attack=['requestId'=>'thorn-wall-demolish-native','sceneId'=>'scene-one','sourceTokenId'=>'token-monster','targetTokenId'=>$token['id'],
    'attackKind'=>'ability','attackId'=>'demolition','opposed'=>false];
$r=runCommand($db,'token.attack',$attack,true);
$counter=$r->body['attack']['thornWallRetaliation']['appliedDamage']??0;
specialCheck($r->status===200&&$counter>=11&&$counter<=40,'Fatal enemy strike triggers magical retaliation: '.json_encode($r->body));
specialCheck($db->payload($monsterKey)['hp']===479-$counter&&$db->payload($wallKey)['hp']===0,'Both wall and enemy take their damage');
$r=runCommand($db,'token.attack',$attack,true);
specialCheck(!empty($r->body['deduplicated'])&&$db->payload($monsterKey)['hp']===479-$counter,'Retaliation cannot be replayed');

$door=applicationCreateTerrainBarrierToken(['id'=>'door','terrainBarrier'=>['version'=>1,'shape'=>'door','hp'=>75,'size'=>40]],['x'=>50,'y'=>50,'rotation'=>0],'fixture-door');
$doorMask=applicationActiveMapOcclusionState($map,[$door])['walls'];
specialCheck($covers($doorMask,50,50)&&!$covers($doorMask,50,51.2),'Closed door uses its centre line as the blocker');
specialCheck(applicationTerrainBarrierPointVisible($door,$map,static fn($x,$y)=>$y<49.5),'Door is fully projectable if a front edge is visible');
$next=$door;$next['terrainBarrier']['open']=true;$door=applicationProtectTerrainBarrier($door,$next);
$openedMask=applicationActiveMapOcclusionState($map,[$door])['walls'];
specialCheck(!$covers($openedMask,50,50)&&$covers($openedMask,47.5,47)&&applicationPublicTerrainBarrier($door)['open'],'Opening pivots the blocker around its hinge');
$double=applicationCreateTerrainBarrierToken(
    ['id'=>'door','terrainBarrier'=>['version'=>1,'shape'=>'door','hp'=>50,'size'=>40,'layout'=>'double']],
    ['x'=>50,'y'=>50,'rotation'=>90],'fixture-double-door');
specialCheck(applicationObstacleDoorWidth($double)===2.0&&applicationPublicTerrainBarrier($double)['layout']==='double','A new double door occupies two cells');
$double['terrainBarrier']['widthCells']=2.5;
$closedMask=applicationActiveMapOcclusionState($map,[$double])['walls'];
specialCheck($covers($closedMask,50,44)&&$covers($closedMask,50,56)&&!$covers($closedMask,50,57),'A resized and rotated door blocks precisely across its full width');
$double['terrainBarrier']['open']=true;
$doubleMask=applicationActiveMapOcclusionState($map,[$double])['walls'];
specialCheck($covers($doubleMask,53,45)&&$covers($doubleMask,53,55)&&!$covers($doubleMask,53,50)&&!$covers($doubleMask,50,50),'The two rotated leaves block separately and the middle passage opens');
$double['terrainBarrier']['layout']='handle-left';
$singleMask=applicationActiveMapOcclusionState($map,[$double])['walls'];
specialCheck(!$covers($singleMask,53,45)&&$covers($singleMask,53,55)&&applicationObstacleDoorLockOffset($double)===-.37,'The left handle pivots on the right and moves the keyhole');
$db=specialFixture();
$door=applicationCreateTerrainBarrierToken(['id'=>'door','name'=>'Porte des archives','terrainBarrier'=>['version'=>1,'shape'=>'door','hp'=>50,'size'=>40]],['x'=>53,'y'=>50,'rotation'=>270],'fixture-door');
$db->put('token:scene-one:fixture-door',$door);
$tokenIndex=$db->payload('token-index:scene-one');$tokenIndex['order'][]='fixture-door';$db->put('token-index:scene-one',$tokenIndex);
specialCheck(in_array('fixture-door',array_column(domainsToApplicationState($db->domains,0)['map']['tokens'],'id'),true),'Door is indexed in the authoritative scene used by GM validation');
$cmd=['sceneId'=>'scene-one','layerId'=>'ground','tokenId'=>'fixture-door','sourceTokenId'=>'token-player'];
specialCheck($door['armor']===20&&$door['maxHp']===50,'Ordinary door has medium armor and fifty HP');
specialCheck(runCommand($db,'door.interact',[...$cmd,'action'=>'lock'],true)->status===200,'GM locks the door');
specialCheck(runCommand($db,'door.interact',[...$cmd,'action'=>'open'])->status===403,'Lock rejects a player without its key');
$created=runCommand($db,'door.interact',[...$cmd,'action'=>'generate','recipientCharacterId'=>'character-player'],true);
specialCheck($created->status===200&&!empty($created->body['key']['id']),'Generation creates a real transferable item: '.json_encode($created->body));
$again=runCommand($db,'door.interact',[...$cmd,'action'=>'generate'],true);
specialCheck($again->body['key']['id']===$created->body['key']['id']&&count($db->payload('items')['instances'])===1,'Repeated generation keeps one key');
$opened=runCommand($db,'door.interact',[...$cmd,'action'=>'open']);
specialCheck($opened->status===200&&!empty($opened->body['open']),'The nearby holder can open manually');
$closed=runCommand($db,'door.interact',[...$cmd,'action'=>'close']);
specialCheck($closed->status===200&&!$closed->body['open']&&$closed->body['locked'],'Closing preserves the keyed lock');
specialCheck(runCommand($db,'door.interact',[...$cmd,'action'=>'resize','widthCells'=>2.8])->status===403,'Only the GM can resize');
$resized=runCommand($db,'door.interact',[...$cmd,'action'=>'resize','widthCells'=>2.8],true);
specialCheck($resized->status===200&&$db->payload('token:scene-one:fixture-door')['terrainBarrier']['widthCells']===2.8,'GM adjusts the physical width');
specialCheck(!isset(applicationPublicTerrainBarrier($db->payload('token:scene-one:fixture-door'))['keyItemId']),'Player projection keeps the key identifier private');
$doorKey='token:scene-one:fixture-door';
$before=$db->payload($doorKey);
$configure=[...$cmd,'action'=>'configure','widthCells'=>2.5,'rotation'=>90,'layout'=>'double',
    'expectedConfiguredAt'=>$before['terrainBarrier']['configuredAt']];
specialCheck(runCommand($db,'door.interact',[...$configure,'isGm'=>true])->status===403,'A player cannot change geometry with a forged GM flag');
specialCheck(runCommand($db,'door.interact',[...$configure,'layout'=>'triple'],true)->status===400&&$db->payload($doorKey)===$before,'Invalid geometry leaves the whole door unchanged');
$configured=runCommand($db,'door.interact',$configure,true);
$after=$db->payload($doorKey);$barrier=$after['terrainBarrier'];
specialCheck($configured->status===200&&$barrier['widthCells']===2.5&&$barrier['rotation']===90&&$barrier['layout']==='double'
    &&$barrier['configuredAt']>$before['terrainBarrier']['configuredAt'],'The authoritative command stores all three geometry fields');
specialCheck($after['x']===$before['x']&&$after['y']===$before['y']&&$after['hp']===$before['hp']
    &&$barrier['locked']&&!$barrier['open']&&$barrier['keyItemId']===$created->body['key']['id'],'Reconfiguring keeps position, health and the keyed lock');
specialCheck(runCommand($db,'door.interact',$configure,true)->status===200&&$db->payload($doorKey)===$after,'Retrying the same settings is idempotent');
specialCheck(runCommand($db,'door.interact',[...$configure,'rotation'=>0],true)->status===409&&$db->payload($doorKey)===$after,'A stale form cannot overwrite the later geometry');
$public=applicationPublicTerrainBarrier($after);
specialCheck($public['layout']==='double'&&$public['rotation']===90&&$public['widthCells']===2.5
    &&!isset($public['configuredAt'])&&!isset($public['keyItemId']),'The double door geometry is public and its internal configuration remains private');
$staleEdit=[...$before,'name'=>'Porte renommée','_updatedAt'=>$after['_updatedAt']+1];
$staleWrite=prepareApplicationDomainUpsert($doorKey,$staleEdit,$db->domains[$doorKey],true);
specialCheck(($staleWrite['payload']['terrainBarrier']??null)===$barrier,'An ordinary GM sync cannot undo authoritative geometry');
$character=$db->payload('character:character-player');$character['stats']['dexterity']=100;$db->put('character:character-player',$character);
for($i=0;$i<2;$i++){
    $pickItem=runCommand($db,'door.interact',[...$cmd,'action'=>'spawn-pick','recipientCharacterId'=>'character-player'],true);
    specialCheck($pickItem->status===200,'GM can give a real Passe partout: '.json_encode($pickItem->body));
}
specialCheck(runCommand($db,'door.interact',[...$cmd,'action'=>'difficulty','difficulty'=>100],true)->status===200,'GM can set pick difficulty');
$picks=static fn($db)=>array_values(array_filter($db->payload('items')['instances'],static fn($item)=>$item['templateId']===XAR_PASS_PARTOUT_TEMPLATE));
$picksById=static function($db)use($picks):array{$items=array_column($picks($db),null,'id');ksort($items,SORT_STRING);return $items;};
$settledPick=static function(bool $success)use($db,$cmd,$picks,$picksById):array {
    for($i=0;$i<128;$i++){
        $before=$picksById($db);
        $available=array_values(array_filter($picks($db),static fn($item)=>$item['location']['kind']==='inventory'&&$item['location']['characterId']==='character-player'));
        specialCheck(count($available)>0,'A real tool is available before the native lockpick');$toolId=$available[0]['id'];
        $args=[...$cmd,'action'=>'pick','requestId'=>'door-fixture-pick-'.randomToken(16)];
        $response=runCommand($db,'door.interact',$args);
        specialCheck($response->status===200,'Native lockpick request succeeds: '.json_encode($response->body));
        $attempt=$response->body['attempt'];
        if(!empty($attempt['pendingValidation'])){
            specialCheck($db->payload('token:scene-one:fixture-door')['terrainBarrier']['locked'],'Remarkable result waits for the GM without unlocking');
            $reserved=array_values(array_filter($db->payload('devices')['pendingRolls'],static fn($entry)=>$entry['id']===$attempt['validationId']));
            specialCheck(count($reserved)===1&&$reserved[0]['toolId']===$toolId&&$reserved[0]['toolRevision']===$before[$toolId]['revision'],'The remarkable result reserves the exact available tool and revision');
            specialCheck($picksById($db)===$before,'A pending remarkable check consumes no tool');
            $approve=$attempt['success']===$success;
            $decision=specialMechanics($db,'roll.resolve',['validationId'=>$attempt['validationId'],'approve'=>$approve]);
            specialCheck($decision->status===200,'GM can arbitrate a native remarkable lockpick: '.json_encode($decision->body));
            specialCheck(($decision->body['applied']??null)===$approve&&($decision->body['obsolete']??null)===false,'The indexed door produces the requested GM decision, never an obsolete cancellation: '.json_encode($decision->body));
            $response=runCommand($db,'door.interact',$args);
            specialCheck(!empty($response->body['deduplicated'])&&($response->body['attempt']['pendingValidation']??null)===false&&($response->body['attempt']['applied']??null)===$approve&&($response->body['attempt']['cancelled']??null)===!$approve,'The replay reads the final decision receipt: '.json_encode($response->body));
            if(!$approve){specialCheck($picksById($db)===$before,'Refusing a remarkable check preserves both tools and their revisions');continue;}
        }
        return [$response,$args,$toolId,$before];
    }
    throw new RuntimeException('No matching lockpick outcome after 128 real dice rolls');
};
[$failed,$pick,$failedToolId,$beforeFailure]=$settledPick(false);
specialCheck($failed->status===200&&$failed->body['attempt']['success']===false&&$failed->body['locked'],'A failed check leaves the door locked: '.json_encode($failed->body));
$afterFailure=$picksById($db);$locations=array_count_values(array_column(array_column($afterFailure,'location'),'kind'));
specialCheck(count($afterFailure)===2&&($locations['destroyed']??0)===1&&($locations['inventory']??0)===1,'Exactly one tool is destroyed and one stays in inventory: '.json_encode($afterFailure));
foreach($beforeFailure as $id=>$item){$expected=$item;if($id===$failedToolId){$expected['revision']++;$expected['location']=['kind'=>'destroyed'];}specialCheck(($afterFailure[$id]??null)===$expected,'Only the selected or reserved tool is destroyed once; its sibling is unchanged: '.$id);}
specialCheck(!empty(runCommand($db,'door.interact',$pick)->body['deduplicated']),'Replaying a failed request consumes nothing');
specialCheck($picksById($db)===$afterFailure,'Failed-request replay preserves tool identities, revisions and locations');
specialCheck(runCommand($db,'door.interact',[...$cmd,'action'=>'difficulty','difficulty'=>0],true)->status===200,'GM can lower difficulty');
[$success]=$settledPick(true);
specialCheck($success->status===200&&$success->body['attempt']['success']===true&&!$success->body['locked']&&!$success->body['open'],'Successful picking unlocks without opening the door');
specialCheck($picksById($db)===$afterFailure,'Successful picking preserves both tool identities, revisions and locations');

$db=specialFixture();
$map=$db->payload('map:scene-one');$map['vision']=['enabled'=>true,'distance'=>15,'shared'=>false];$map['lightingMode']='day';$db->put('map:scene-one',$map);
$door=applicationCreateTerrainBarrierToken(['id'=>'door','name'=>'Porte au judas','terrainBarrier'=>['version'=>1,'shape'=>'door','hp'=>50,'size'=>40]],['x'=>50,'y'=>50,'rotation'=>0],'keyhole-door');
$db->put('token:scene-one:keyhole-door',$door);
$tokenIndex=$db->payload('token-index:scene-one');$tokenIndex['order'][]='keyhole-door';$db->put('token-index:scene-one',$tokenIndex);
$source=$db->payload('token:scene-one:token-player');$source['x']=52;$source['y']=55;$db->put('token:scene-one:token-player',$source);
$foe=$db->payload('token:scene-one:token-monster');$foe['x']=52;$foe['y']=42;$db->put('token:scene-one:token-monster',$foe);
$command=['sceneId'=>'scene-one','layerId'=>'ground','tokenId'=>'keyhole-door','sourceTokenId'=>'token-player'];
$project=static fn($account,$include=true,$stream=false)=>publicPlayerState(domainsToApplicationState($db->domains,0),['id'=>$account,'display_name'=>'Recette','effective_mode'=>'player'],[],$stream,$include);
specialCheck(runCommand($db,'door.interact',[...$command,'action'=>'peek'])->status===403,'Keyhole requires GM permission');
$r=runCommand($db,'door.interact',[...$command,'action'=>'peek-permission','allowPeek'=>true],true);
specialCheck($r->status===200,'GM enables the keyhole: '.json_encode($r->body));
$r=runCommand($db,'door.interact',[...$command,'action'=>'peek']);
specialCheck($r->status===200&&!empty($r->body['peeking']),'Player starts peeking: '.json_encode($r->body));
$visible=$project('account-player');
specialCheck(($visible['doorPeek']['doorId']??'')==='keyhole-door'&&!empty($visible['doorPeek']['mask']),'Player receives the keyhole pane');
specialCheck(in_array($foe['name'],array_column($visible['doorPeek']['occupants'],'name'),true),'Player can see the unit behind the door');
specialCheck(!in_array('token-monster',array_column($visible['map']['tokens'],'id'),true),'The keyhole does not add attackable targets to the map');
specialCheck($project('another-player')['doorPeek']===null&&!isset($project('account-player',false)['doorPeek'])&&!isset($project('account-player',true,true)['doorPeek']),'Observation stays private and absent from Stream and authority');
$source=$db->payload('token:scene-one:token-player');$source['x']=54;$source['_movedAt']=7;$db->put('token:scene-one:token-player',$source);
specialCheck($project('account-player')['doorPeek']===null,'The pawn movement ends observation');
$source['x']=52;$source['_movedAt']=8;$db->put('token:scene-one:token-player',$source);
specialCheck($project('account-player')['doorPeek']===null,'Returning to the same point cannot restore observation');
$r=runCommand($db,'door.interact',[...$command,'action'=>'peek']);
specialCheck($r->status===200&&$project('account-player')['doorPeek']!==null,'A new observation can be started');
$r=runCommand($db,'door.interact',['sceneId'=>'scene-one','layerId'=>'ground','action'=>'peek-end']);
specialCheck($r->status===200&&$project('account-player')['doorPeek']===null,'Player can cancel the observation');

$door=$db->payload('token:scene-one:keyhole-door');
$door['terrainBarrier']['swungAt']=(int)floor(microtime(true)*1000)+10000;
$db->put('token:scene-one:keyhole-door',$door);
$r=runCommand($db,'door.interact',[...$command,'action'=>'peek']);
specialCheck($r->status===200&&$project('account-player')['doorPeek']!==null,'Observation starts after an earlier recorded door movement');
foreach(['open','close'] as $action){
    $previous=$db->payload('token:scene-one:keyhole-door')['terrainBarrier']['swungAt'];
    $r=runCommand($db,'door.interact',[...$command,'action'=>$action],true);
    specialCheck($r->status===200&&$db->payload('token:scene-one:keyhole-door')['terrainBarrier']['swungAt']>$previous,'Door checkpoints remain monotone after a clock adjustment');
}
specialCheck($project('account-player')['doorPeek']===null,'Opening and reclosing cannot restore a stale observation');
$before=$db->payload('token:scene-one:keyhole-door')['terrainBarrier']['swungAt'];
$r=runCommand($db,'door.interact',[...$command,'action'=>'close'],true);
specialCheck($r->status===200&&$db->payload('token:scene-one:keyhole-door')['terrainBarrier']['swungAt']===$before,'An unchanged closed door keeps its checkpoint');

$s=['x'=>20,'y'=>20];$movement=initialApplicationComplexMovementState($s,1);$movement=appendApplicationComplexMovementPath($movement,$s,['x'=>35,'y'=>35],null,['naturalWidth'=>1000,'naturalHeight'=>1000,'gridSize'=>50],20,2,5);specialCheck($movement['distanceCells']==3,'Retreat diagonal counts cells');try{appendApplicationComplexMovementPath($movement,['x'=>35,'y'=>35],['x'=>55,'y'=>35],null,['naturalWidth'=>1000,'naturalHeight'=>1000,'gridSize'=>50],20,3,5);specialCheck(false,'Too long retreat should fail');}catch(ApplicationComplexAbilityException){specialCheck(true,'Retreat rejects over five cells');}
$changed=applicationTacticalEffect(['version'=>1,'kind'=>'mark','attackBonus'=>7,'dodgeBonus'=>9]);specialCheck($changed['attackBonus']===7&&$changed['dodgeBonus']===9,'Bonuses configurable');try{applicationTacticalEffect(['version'=>2,'kind'=>'mark']);specialCheck(false,'Future version rejected');}catch(RuntimeException){specialCheck(true,'Future schema refused');}

$db=specialFixture();$c=$db->payload('character:character-player');foreach($c['abilities'] as &$a)if($a['id']==='entangle'){$a['manaCost']=15;$a['fatigueCost']=5;}unset($a);$db->put('character:character-player',$c);
$r=runCommand($db,'token.attack',['requestId'=>'entangle-single-'.randomToken(16),'sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','attackKind'=>'ability','attackId'=>'entangle','targetingMode'=>'single','opposed'=>false]);specialCheck($r->status===200,'Single entanglement: '.json_encode($r->body));$r=fixtureApproveDefenseGate($db,$r);specialCheck($r->status===200,'MJ confirms this area before its effects: '.json_encode($r->body));specialCheck($db->payload('character:character-player')['resources']['mana']==92.5,'Single target spends exactly 7.5 mana');specialCheck($db->payload('character:character-player')['fatigue']['current']==5,'Single target keeps full fatigue');
$conditions=$db->payload('devices')['timedConditions'];specialCheck(count($conditions)===1&&$conditions[0]['targetTokenId']==='token-monster','Single target does not affect neighbours');specialCheck($conditions[0]['remainingTurns']===3&&$conditions[0]['escape']['penalties']===[-20,-10,0],'Three target turns and progressive escape penalties');
$map=['naturalWidth'=>1000,'naturalHeight'=>1000];$opaque=['x'=>50,'y'=>50,'size'=>150,'hp'=>75,'terrainBarrier'=>['version'=>1]];specialCheck(applicationTerrainBarrierPointVisible($opaque,$map,static fn($x,$y)=>$x<44),'Opaque wall can be selected from visible edge');specialCheck(!applicationTerrainBarrierPointVisible($opaque,$map,static fn($x,$y)=>false),'Fog does not reveal an unseen wall');
set_error_handler(static function(int $severity,string $message):never {throw new ErrorException($message,0,$severity);});
try{foreach([[],['x'=>50],['y'=>50],['x'=>null,'y'=>50],['x'=>NAN,'y'=>50],['x'=>50,'y'=>INF],['x'=>'50','y'=>50],['x'=>-1,'y'=>50],['x'=>50,'y'=>101]] as $incomplete)foreach([false,true] as $barrier){$calls=0;$unit=$barrier?[...$incomplete,'terrainBarrier'=>['version'=>1],'hp'=>75,'size'=>150]:$incomplete;specialCheck(!applicationTerrainBarrierPointVisible($unit,$map,static function($x,$y)use(&$calls){$calls++;return true;}),'Invalid legacy position stays hidden');specialCheck($calls===0,'No visibility query with invented coordinates');}}finally{restore_error_handler();}

$db=specialFixture();$r=specialUse($db,'reading');specialCheck($r->status===200,'Mark: '.json_encode($r->body));$source=$db->payload('token:scene-one:token-player');specialCheck(applicationPredatoryResultBonus($source,'token-monster','attack')===20&&applicationPredatoryResultBonus($source,'token-monster-two','attack')===0,'Mark restricted to chosen target');specialCheck(applicationPredatoryResultBonus($source,'token-monster','defense','character-stat-agility')===20&&applicationPredatoryResultBonus($source,'token-monster','defense','character-stat-force')===0,'Only agility defense gains bonus');
$db=specialFixture();$c=$db->payload('character:character-player');$c['abilities'][]=['id'=>'precision','name'=>'Précision synthétique','effect'=>'damage','formula'=>'0','castingStatId'=>'','manaCost'=>0,'hpCost'=>0,'fatigueCost'=>0,'cooldownRounds'=>0,'restRecharge'=>'none','reusableInTurn'=>true,'useWeaponDamage'=>true,'mainWeaponOnly'=>true,'maximumWeaponDamage'=>true,'noDefensePossible'=>true];
$poison=['id'=>'poison','name'=>'Poison synthétique','effect'=>'enhancement','formula'=>'0','castingStatId'=>'','manaCost'=>0,'hpCost'=>0,'fatigueCost'=>5,'cooldownRounds'=>0,'restRecharge'=>'none','reusableInTurn'=>true,'tacticalEffect'=>['version'=>1,'kind'=>'coating'],'attackEnhancement'=>['mode'=>'echo','trigger'=>'main-weapon','requiresHit'=>true,'formula'=>'5d5','allyFormula'=>'5d5','damageType'=>'ignore','turns'=>1,'expiry'=>'after-next-turn']];$c['abilities'][]=$poison;$db->put('character:character-player',$c);
$state=domainsToApplicationState($db->domains,0);$applied=applicationApplyDeviceCommand($state,['id'=>'account-player','role'=>'player'],'consumable.request',['requestId'=>'consumable-fixture-request','sceneId'=>'scene-one','characterId'=>'character-player','sourceTokenId'=>'token-player','abilityId'=>'poison'],['now'=>'2026-10-17T20:00:00Z','id'=>static fn($p)=>$p.'-'.randomToken(16),'roll'=>static fn(...$args)=>['success'=>true,'pendingGmApproval'=>false,'roll'=>['total'=>50],'outcome'=>['raw'=>50,'success'=>true]]]);
$db->put('devices',$applied['state']['devices']);$id=$applied['result']['craftRequestId'];$r=specialMechanics($db,'craft.resolve',['craftRequestId'=>$id,'approve'=>true]);specialCheck($r->status===200&&count($r->body['itemIds'])===1,'Craft produces one vial on GM approval: '.json_encode($r->body));specialCheck($db->payload('character:character-player')['fatigue']['current']===0,'Craft costs no fatigue');
$r=specialUse($db,'poison','token-player',['consumableItemId'=>$r->body['itemIds'][0]]);specialCheck($r->status===200,'Apply poison: '.json_encode($r->body));specialCheck($db->payload('character:character-player')['fatigue']['current']===5,'Application costs five fatigue');specialCheck($db->payload('items')['instances'][0]['location']['kind']==='consumed','Application consumes the vial');
$r=runCommand($db,'token.attack',['requestId'=>'precision-'.randomToken(16),'sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','attackKind'=>'ability','attackId'=>'precision','weaponAttackId'=>'main','opposed'=>true]);specialCheck($r->status===200,'Guaranteed strike: '.json_encode($r->body));$hp=$db->payload('token:scene-one:token-monster')['hp'];specialCheck($hp>=465&&$hp<=485,'Maximum main weapon plus ordinary 5d5 poison');specialCheck(($r->body['attack']['status']??'')!=='awaiting-opposition','Guaranteed strike has no counter or opposition');
// A known binding exercises both real authority paths, including the crafted cue.
$db=specialFixture();$c=$db->payload('character:character-player');$poison['id']='ability-d4fe4e60-f96e-4c0d-9f5a-280aead0846a';$c['abilities']=[$poison];
$c['weaponAttacks']=[['id'=>'main','name'=>'Fouet de recette','formula'=>'2d20+10','damageType'=>'physical','equipmentBinding'=>['kind'=>'item','id'=>'fixture-whip']]];$c['weaponText']='Fouet de recette / 2d20+10';$db->put('character:character-player',$c);
$db->put('items',['version'=>1,'templates'=>[['id'=>'v370-template-d29417860cce105b93bb7f17','kind'=>'object','name'=>'Fouet de recette','image'=>'','equipment'=>['version'=>1,'kind'=>'weapon','hands'=>1,'formula'=>'2d20+10','damageType'=>'physical','reach'=>'reach','projectile'=>false]]],
 'instances'=>[['id'=>'fixture-whip','templateId'=>'v370-template-d29417860cce105b93bb7f17','revision'=>1,'location'=>['kind'=>'inventory','characterId'=>'character-player']]],
 'equipmentLoadouts'=>['character-player'=>['sets'=>[],'equippedItemIds'=>['fixture-whip'],'activeSetId'=>'']]]);
$state=domainsToApplicationState($db->domains,0);$applied=applicationApplyDeviceCommand($state,['id'=>'account-player','role'=>'player'],'consumable.request',['requestId'=>'poison-visual-request','sceneId'=>'scene-one','characterId'=>'character-player','sourceTokenId'=>'token-player','abilityId'=>$poison['id']],['now'=>'2026-11-01T20:00:00Z','id'=>static fn($p)=>$p.'-'.randomToken(16),'roll'=>static fn(...$args)=>['success'=>true,'roll'=>['total'=>50],'outcome'=>['raw'=>50,'success'=>true]]]);
specialCheck(empty($applied['effects']),'Request alone does not animate a successful craft');$db->put('devices',$applied['state']['devices']);$id=$applied['result']['craftRequestId'];
$args=['command'=>'craft.resolve','arguments'=>['sceneId'=>'scene-one','requestId'=>'poison-visual-resolve','craftRequestId'=>$id,'approve'=>true]];
$r=runCommand($db,'mechanics.command',$args,true);specialCheck($r->status===200,'Known poison craft: '.json_encode($r->body));$vial=$r->body['itemIds'][0];$repeated=runCommand($db,'mechanics.command',$args,true);specialCheck($repeated->status===200&&!empty($repeated->body['deduplicated']),'Craft receipt is idempotent');
$cues=array_values(array_filter($db->payload('activity')['abilityCueEvents']??[],static fn($e)=>($e['completionCue']['spell']['presetId']??'')==='weapon-poison-brew'));specialCheck(count($cues)===1&&$cues[0]['sourceTokenId']==='token-player','Exactly one crafted visual on the actual source');
$r=specialUse($db,$poison['id'],'token-player',['consumableItemId'=>$vial]);specialCheck($r->status===200,'Known coating: '.json_encode($r->body));
$cues=array_values(array_filter($db->payload('activity')['abilityCueEvents']??[],static fn($e)=>($e['completionCue']['spell']['presetId']??'')==='weapon-poison-coat'));specialCheck(count($cues)===1&&$cues[0]['completionCue']['spell']['weaponProfile']==='bladed-whip','Coating uses the actual equipped main weapon profile');
specialCheck($db->payload('character:character-player')['fatigue']['current']===5,'Visual stages do not change the application cost');

fwrite(STDOUT,json_encode(['ok'=>true,'checks'=>$specialChecks],JSON_UNESCAPED_UNICODE)."\n");
