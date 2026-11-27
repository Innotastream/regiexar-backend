<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);require __DIR__.'/tactical-special-effects.php';$specialChecks=0;
const APPROACH_ABILITY_TEST='ability-541dcf8a-3492-4196-afc1-2a7815eb02f4';
function approachFixture():MemoryConnection{$db=specialFixture();$c=$db->payload('character:character-player');$c['abilities']=[['id'=>APPROACH_ABILITY_TEST,'name'=>'Foudroiement','effect'=>'damage','formula'=>'7','damageType'=>'ignore','manaCost'=>0,'fatigueCost'=>10,'cooldownRounds'=>5,'castingStatId'=>'','restRecharge'=>'none']];$db->put('character:character-player',$c);$s=$db->payload('token:scene-one:token-player');$s['x']=20;$s['y']=50;$db->put('token:scene-one:token-player',$s);$t=$db->payload('token:scene-one:token-monster');$t['x']=80;$t['y']=50;$db->put('token:scene-one:token-monster',$t);$other=$db->payload('token:scene-one:token-monster-two');$other['x']=10;$other['y']=10;$db->put('token:scene-one:token-monster-two',$other);return $db;}
function approachAttack(array $extra=[]):array{return ['sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','attackKind'=>'ability','attackId'=>APPROACH_ABILITY_TEST,'approachPoint'=>['x'=>74,'y'=>50],'opposed'=>false,...$extra];}
$db=approachFixture();$bad=runCommand($db,'token.attack',approachAttack(['requestId'=>'approach-missing-'.randomToken(16),'approachPoint'=>null]));specialCheck($bad->status===409,'Destination required before costs');specialCheck($db->payload('character:character-player')['fatigue']['current']===0,'No cost for missing destination');specialCheck($db->payload('token:scene-one:token-player')['x']===20,'No move for invalid cast');
$request=approachAttack(['requestId'=>'approach-success-'.randomToken(16)]);$r=runCommand($db,'token.attack',$request);specialCheck($r->status===200,'Native approach: '.json_encode($r->body));specialCheck($db->payload('token:scene-one:token-player')['x']===74,'Chosen destination applied');specialCheck($db->payload('token:scene-one:token-monster')['hp']===493,'Existing damage applied');specialCheck($db->payload('character:character-player')['fatigue']['current']===10,'Existing fatigue paid once');specialCheck($db->payload('activity')['actionTimers'][0]['readyRound']===6,'Existing cooldown unchanged');
$again=runCommand($db,'token.attack',$request);specialCheck($again->status===200&&!empty($again->body['deduplicated']),'Attack replay deduplicated');specialCheck($db->payload('character:character-player')['fatigue']['current']===10,'Replay no second cost');
$activity=$db->payload('activity');specialCheck(count($activity['abilityMovementLeases'])===1,'One authority movement lease');$cue=array_values(array_filter($activity['abilityCueEvents'],static fn($e)=>($e['completionCue']['spell']['placement']??'')==='melee'))[0]['completionCue']['spell'];specialCheck($cue['source']['x']===74&&$cue['movementDash']['source']['x']===20,'One cue has arrival X and original dash');specialCheck(is_int($cue['movementDash']['startedAt']),'Cue retains common start time');
$locked=runCommand($db,'token.move',['sceneId'=>'scene-one','layerId'=>'ground','tokenId'=>'token-player','x'=>75,'y'=>50]);specialCheck($locked->status===409,'Movement blocked throughout lease');
$action=$activity['combatActions'][0];$undo=['requestId'=>'approach-undo-'.randomToken(16),'actionId'=>$action['actionIds'][0]];$r=runCommand($db,'action.undo',$undo,true);specialCheck($r->status===200,'Undo approach: '.json_encode($r->body));specialCheck($db->payload('token:scene-one:token-player')['x']===20,'Undo restores departure');specialCheck($db->payload('token:scene-one:token-monster')['hp']===500,'Undo restores damage');specialCheck($db->payload('character:character-player')['fatigue']['current']===0,'Undo restores costs');specialCheck(empty($db->payload('activity')['abilityMovementLeases']),'Undo removes lease');specialCheck(empty($db->payload('activity')['abilityCueEvents']),'Undo removes visual event');
$db=approachFixture();$initiative=$db->payload('initiative:scene-one');$initiative['active']=false;$db->put('initiative:scene-one',$initiative);$pending=runCommand($db,'token.attack',approachAttack(['requestId'=>'approach-pending-'.randomToken(16)]));specialCheck($pending->status===200&&$pending->body['attack']['status']==='pending','Outside combat awaits GM');specialCheck($db->payload('token:scene-one:token-player')['x']===20,'No premature move while awaiting GM');
$target=$db->payload('token:scene-one:token-monster');$target['x']=95;$db->put('token:scene-one:token-monster',$target);$approve=runCommand($db,'token.attack.resolve',['attackId'=>$pending->body['attack']['id'],'decision'=>'approve','confirmed'=>true],true);specialCheck($approve->status===409,'Moved target revalidated before resolution');specialCheck($db->payload('token:scene-one:token-monster')['hp']===500&&$db->payload('token:scene-one:token-player')['x']===20,'Failed late validation applies neither movement nor damage');specialCheck(count($db->payload('activity')['pendingAttacks'])===1,'Failed resolution preserves pending action for MJ');
// Mounted compatibility belongs to the ability definition, never the command.
function mountedApproachFixture(bool $compatible=false):MemoryConnection{
    $db=approachFixture();$source=$db->payload('token:scene-one:token-player');$source['mountedOnTokenId']='approach-mount';$source['mountedAt']=17;$source['x']=20;$source['y']=50;$db->put('token:scene-one:token-player',$source);
    $mount=['id'=>'approach-mount','name'=>'Monture','controllerPlayerId'=>'account-gm','x'=>20,'y'=>50,'size'=>160,'layerId'=>'ground','hp'=>100,'maxHp'=>100,'immovable'=>!$compatible];
    $sibling=['id'=>'approach-other-rider','name'=>'Autre cavalier','controllerPlayerId'=>'account-gm','mountedOnTokenId'=>'approach-mount','mountedAt'=>18,'x'=>20,'y'=>50,'size'=>30,'layerId'=>'ground','hp'=>100,'maxHp'=>100,'immovable'=>!$compatible];
    foreach([$mount,$sibling]as$token)$db->put('token:scene-one:'.$token['id'],$token);$index=$db->payload('token-index:scene-one');$index['order']=[...$index['order'],'approach-mount','approach-other-rider'];$db->put('token-index:scene-one',$index);
    if($compatible){$character=$db->payload('character:character-player');$character['abilities']=normalizeOnlineAbilities($character['abilities']);$character['abilities'][0]['mountedMovement']=true;$db->put('character:character-player',$character);$mount['controllerPlayerId']='account-player';$db->put('token:scene-one:approach-mount',$mount);}
    return $db;
}
$db=mountedApproachFixture();$beforeSource=$db->payload('token:scene-one:token-player');$beforeMount=$db->payload('token:scene-one:approach-mount');$beforeSibling=$db->payload('token:scene-one:approach-other-rider');
$invalid=runCommand($db,'token.attack',approachAttack(['requestId'=>'rider-invalid-'.randomToken(16),'approachPoint'=>null]));specialCheck($invalid->status===409&&$db->payload('token:scene-one:token-player')===$beforeSource,'Failed selection does not dismount');
$request=approachAttack(['requestId'=>'rider-solo-'.randomToken(16),'mountedMovement'=>true]);$r=runCommand($db,'token.attack',$request);specialCheck($r->status===200,'Native solo rider attack: '.json_encode($r->body));$source=$db->payload('token:scene-one:token-player');
specialCheck(!isset($source['mountedOnTokenId'],$source['mountedAt'])&&$source['x']===74,'Default skill dismounts only its caster');specialCheck($db->payload('token:scene-one:approach-mount')===$beforeMount&&$db->payload('token:scene-one:approach-other-rider')===$beforeSibling,'Mount and other rider remain untouched even when immovable');
$activity=$db->payload('activity');$lease=$activity['abilityMovementLeases'][0];specialCheck($lease['tokenIds']===['token-player'],'Solo rider movement lease');$receipt=array_values(array_filter($activity['attackReceipts'],static fn($receipt)=>$receipt['requestId']===$request['requestId']))[0]['attack'];specialCheck(empty($receipt['mountedMovement']),'Forged compatibility is ignored');
$cue=array_values(array_filter($activity['abilityCueEvents'],static fn($event)=>($event['completionCue']['spell']['placement']??'')==='melee'))[0]['completionCue']['spell'];specialCheck($cue['movementDash']['source']==['x'=>20,'y'=>50,'size'=>40]&&empty($cue['movementDash']['mountedMovement']),'Portrait starts at mount centre with rider size');
$again=runCommand($db,'token.attack',$request);specialCheck($again->status===200&&!empty($again->body['deduplicated'])&&count($db->payload('activity')['abilityMovementLeases'])===1,'Mounted command replay has no second move or lease');
$action=$activity['combatActions'][0];$undo=runCommand($db,'action.undo',['requestId'=>'rider-undo-'.randomToken(16),'actionId'=>$action['actionIds'][0]],true);specialCheck($undo->status===200,'Undo solo rider: '.json_encode($undo->body));$restored=$db->payload('token:scene-one:token-player');foreach(['x','y','mountedOnTokenId','mountedAt']as$field)specialCheck(($restored[$field]??null)===($beforeSource[$field]??null),'Undo restores rider '.$field);specialCheck(empty($db->payload('activity')['abilityMovementLeases']),'Undo removes rider lease');
$db=mountedApproachFixture(true);$r=runCommand($db,'token.attack',approachAttack(['requestId'=>'rider-compatible-'.randomToken(16),'approachPoint'=>['x'=>65,'y'=>50]]));specialCheck($r->status===200,'Explicit mounted compatibility: '.json_encode($r->body));foreach(['token-player','approach-mount','approach-other-rider']as$id)specialCheck($db->payload('token:scene-one:'.$id)['x']===65,'Compatible group moves '.$id);specialCheck($db->payload('token:scene-one:token-player')['mountedOnTokenId']==='approach-mount','Compatible skill preserves mount link');
$activity=$db->payload('activity');$cue=array_values(array_filter($activity['abilityCueEvents'],static fn($event)=>($event['completionCue']['spell']['placement']??'')==='melee'))[0]['completionCue']['spell'];specialCheck(($cue['movementDash']['mountedMovement']??false)===true&&$cue['movementDash']['source']['size']===160,'Compatible cue carries authoritative group flag');
$db=mountedApproachFixture();$map=$db->payload('map:scene-one');$map['naturalWidth']=2000;$db->put('map:scene-one',$map);foreach(['token-player','approach-mount','approach-other-rider']as$id){$token=$db->payload('token:scene-one:'.$id);$token['x']=10;$db->put('token:scene-one:'.$id,$token);}$target=$db->payload('token:scene-one:token-monster');$target['x']=52;$db->put('token:scene-one:token-monster',$target);$r=runCommand($db,'token.attack',approachAttack(['requestId'=>'rider-own-range-'.randomToken(16),'approachPoint'=>['x'=>47.5,'y'=>50],'mountedMovement'=>true]));specialCheck($r->status===409,'Dismounted range does not borrow the mount footprint beyond fifteen cells');specialCheck($db->payload('character:character-player')['fatigue']['current']===0&&$db->payload('token:scene-one:token-player')['mountedOnTokenId']==='approach-mount'&&empty($db->payload('activity')['abilityMovementLeases']),'Out-of-range rider spends nothing and stays mounted');
$db=approachFixture();$target=$db->payload('token:scene-one:token-monster');
$after=['x'=>$target['x']+6,'y'=>$target['y']];
$r=runCommand($db,'token.attack',approachAttack(['requestId'=>'opposite-lightning-'.randomToken(16),'approachPoint'=>$after]));
specialCheck($r->status===200,'Foudroiement alone can cross its selected target: '.json_encode($r->body));
specialCheck($db->payload('token:scene-one:token-player')['x']===$after['x'],'Native final position is on the far side');
foreach ([false,true] as $gm) {
    $account=$gm?'account-gm':'account-player';
    $db=approachFixture();
    $caster=$db->payload('character:character-player');
    $caster['visionDistance']=2;
    $db->put('character:character-player',$caster);
    $map=$db->payload('map:scene-one');
    $map['vision']=['enabled'=>true,'shared'=>true,'distance'=>8];
    $db->put('map:scene-one',$map);
    $target=$db->payload('token:scene-one:token-monster');
    $db->put('token:scene-one:token-monster',[...$target,'x'=>28]);
    $before=$db->domains;
    $r=runCommand($db,'token.attack',approachAttack(['requestId'=>'unseen-dash-'.randomToken(16),'approachPoint'=>['x'=>34,'y'=>50]]),$gm,$account);
    specialCheck($r->status===409&&str_contains($r->body['error']??'','non visible')&&$db->domains===$before,
        'A target in sight does not allow Foudroiement to land outside the caster sight, for either caller: '.json_encode($r->body));

    $db=approachFixture();
    $caster=$db->payload('character:character-player');
    $direct=['id'=>'source-vision-direct','name'=>'Sort ciblé fictif','effect'=>'damage','formula'=>'8','castingStatId'=>'','damageType'=>'physical','rangeCells'=>6];
    $zone=[...$direct,'id'=>'source-vision-zone','noDefensePossible'=>true,
        'damageTargeting'=>['mode'=>'area','shape'=>'circle','origin'=>'point','radiusCells'=>3,'affectCaster'=>false,'affectAllies'=>false,'affectEnemies'=>true]];
    $caster['abilities']=[$direct,$zone];
    $db->put('character:character-player',$caster);
    $wall=str_repeat("\0",32768);
    for($row=0;$row<512;$row++){
        $bit=$row*512+128;
        $wall[$bit>>3]=chr(ord($wall[$bit>>3])|(1<<($bit&7)));
    }
    $map['walls']=['version'=>1,'width'=>512,'height'=>512,'mask'=>rtrim(strtr(base64_encode($wall),'+/','-_'),'=')];
    $db->put('map:scene-one',$map);
    $db->put('token:scene-one:token-monster',[...$target,'x'=>22]);
    $sheltered=[...$target,'x'=>30,'id'=>'token-monster-two'];
    $db->put('token:scene-one:token-monster-two',$sheltered);
    $base=['requestId'=>'sight-test-'.randomToken(16),'sceneId'=>'scene-one','sourceTokenId'=>'token-player','attackKind'=>'ability','opposed'=>false];
    $before=$db->domains;
    $r=runCommand($db,'token.attack',[...$base,'targetTokenId'=>'token-monster-two','attackId'=>$direct['id']],$gm,$account);
    specialCheck($r->status===403&&($r->body['code']??null)==='attack_target_hidden'&&$db->domains===$before,
        'The direct spell cannot damage a target through a wall, even clicked by the MJ: '.json_encode($r->body));
    $r=runCommand($db,'token.attack',[...$base,'requestId'=>'sight-hidden-aim-'.randomToken(16),'targetTokenId'=>'token-player',
        'attackId'=>$zone['id'],'targetPoint'=>['x'=>30,'y'=>50]],$gm,$account);
    specialCheck($r->status===403&&($r->body['code']??null)==='attack_area_point_hidden'&&$db->domains===$before,
        'The direct area cannot be centered through a wall: '.json_encode($r->body));
    $r=runCommand($db,'token.attack',[...$base,'requestId'=>'sight-visible-aim-'.randomToken(16),'targetTokenId'=>'token-player',
        'attackId'=>$zone['id'],'targetPoint'=>['x'=>22,'y'=>50]],$gm,$account);
    specialCheck($r->status===200&&$db->payload('token:scene-one:token-monster')['hp']===492
        &&$db->payload('token:scene-one:token-monster-two')['hp']===500,
        'A visible area deals its damage once and excludes the other side of the wall: '.json_encode($r->body));
}
foreach ([false,true] as $gm) {
    $db=approachFixture();
    $caster=$db->payload('character:character-player');
    $ability=['id'=>'waiting-source-sight','name'=>'Attaque de recette','effect'=>'damage','formula'=>'8','castingStatId'=>'',
        'damageType'=>'physical','rangeCells'=>6];
    $caster['abilities']=[$ability];
    $db->put('character:character-player',$caster);
    $target=$db->payload('token:scene-one:token-monster');
    $db->put('token:scene-one:token-monster',[...$target,'x'=>30,'y'=>50]);
    $door=applicationCreateTerrainBarrierToken(['id'=>'waiting-sight-structure','name'=>'Porte','terrainBarrier'=>['version'=>1,'shape'=>'door','hp'=>50,'size'=>100]],
        ['x'=>25,'y'=>50,'rotation'=>90],'waiting-sight-door');
    $door['terrainBarrier']=[...$door['terrainBarrier'],'layout'=>'double','widthCells'=>4,'open'=>true];
    $db->put('token:scene-one:waiting-sight-door',$door);
    $index=$db->payload('token-index:scene-one');$index['order'][]=$door['id'];$db->put('token-index:scene-one',$index);
    $map=$db->payload('map:scene-one');
    $db->put('map:scene-one',[...$map,'vision'=>['enabled'=>true,'shared'=>true,'distance'=>8]]);
    $launched=runCommand($db,'token.attack',['sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>$target['id'],
        'requestId'=>'wait-open-door-'.randomToken(16),'attackKind'=>'ability','attackId'=>$ability['id'],'opposed'=>true],$gm,$gm?'account-gm':'account-player');
    $attack=$db->payload('activity')['pendingAttacks'][0]??[];
    specialCheck($launched->status===200&&($attack['status']??'')==='awaiting-opposition'&&($attack['sourceSight']??false)===true,
        'An ordinary spell waits for opposition with the door open and stores the authoritative sight: '.json_encode($launched->body));
    $doorRequest=['sceneId'=>'scene-one','layerId'=>'ground','tokenId'=>$door['id'],'sourceTokenId'=>'token-player'];
    $closed=runCommand($db,'door.interact',[...$doorRequest,'action'=>'close'],true,'account-gm');
    specialCheck($closed->status===200&&!$closed->body['open'],'The door really closes while opposition waits: '.json_encode($closed->body));
    $stored=$db->domains;
    $decision=['attackId'=>$attack['id'],'decision'=>'skip','requestId'=>'wait-close-door-'.randomToken(16)];
    $blocked=runCommand($db,'token.attack.oppose',$decision,true,'account-gm');
    specialCheck($blocked->status===409&&($blocked->body['code']??'')==='attack_target_hidden'&&$db->domains===$stored
        &&$db->payload('token:scene-one:token-monster')['hp']===500,
        'Neither original caller can force damage after the door closes: '.json_encode($blocked->body));
    $opened=runCommand($db,'door.interact',[...$doorRequest,'action'=>'open'],true,'account-gm');
    $resolved=runCommand($db,'token.attack.oppose',$decision,true,'account-gm');
    specialCheck($opened->status===200&&$opened->body['open']&&$resolved->status===200
        &&$db->payload('token:scene-one:token-monster')['hp']===492,
        'Once reopened, the same opposition can finish exactly once: '.json_encode($resolved->body));
    $replayed=runCommand($db,'token.attack.oppose',$decision,true,'account-gm');
    specialCheck($replayed->status===200&&!empty($replayed->body['deduplicated'])
        &&$db->payload('token:scene-one:token-monster')['hp']===492,
        'Replaying the resolved opposition does not damage the target again');

    if ($gm) {
        $custom=['sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>$target['id'],'requestId'=>'custom-door-'.randomToken(16),
            'attackKind'=>'custom','opposed'=>false,'customAttack'=>['name'=>'Arbitrage de recette','customStat'=>true,'threshold'=>100,
                'statLabel'=>'Force','damageComponents'=>[['type'=>'physical','formula'=>'8']]]];
        $before=$db->domains;
        $playerCustom=runCommand($db,'token.attack',$custom);
        specialCheck($playerCustom->status===403&&($playerCustom->body['code']??'')==='gm_required'&&$db->domains===$before,
            'A player cannot use custom arbitration, even with the door open: '.json_encode($playerCustom->body));
        $closed=runCommand($db,'door.interact',[...$doorRequest,'action'=>'close'],true,'account-gm');
        specialCheck($closed->status===200&&!$closed->body['open'],'Door closed before the custom-arbitration comparison');
        $normal=runCommand($db,'token.attack',[...$custom,'requestId'=>'normal-closed-door-'.randomToken(16),
            'attackKind'=>'ability','attackId'=>$ability['id']],true,'account-gm');
        specialCheck($normal->status===403&&($normal->body['code']??'')==='attack_target_hidden',
            'An ordinary spell cast by the GM still obeys a closed door: '.json_encode($normal->body));
        $approvedCustom=runCommand($db,'token.attack',$custom,true,'account-gm');
        specialCheck($approvedCustom->status===200&&($approvedCustom->body['attack']['attackKind']??'')==='custom',
            'The same closed door permits explicit GM arbitration: '.json_encode($approvedCustom->body));
    }
}
fwrite(STDOUT,json_encode(['ok'=>true,'checks'=>$specialChecks],JSON_UNESCAPED_UNICODE)."\n");
