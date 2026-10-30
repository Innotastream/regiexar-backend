<?php
declare(strict_types=1);
define('XAR_CONTACT_FIXTURE_ONLY',true);
require __DIR__.'/melee-contact-variants.php';
$specialChecks=0;

function staffWhipFixture(string $templateId,bool $twoTargets=false):MemoryConnection {
    $db=contactFixture();
    $c=$db->payload('character:'.CONTACT_CHARACTER);
    $c['name']='Nom sans rapport avec le propriétaire du modèle';
    $c['specialSuccessStock']=1;
    $c['weaponAttacks']=[['id'=>'configured-strike','name'=>'Ligne sans rapport avec le visuel','formula'=>'1d2','damageType'=>'physical',...($twoTargets?[]:['targetCount'=>1]),'equipmentBinding'=>['kind'=>'item','id'=>'actual-weapon']]];
    $c['weaponText']='Ligne sans rapport avec le visuel / 1d2';
    $db->put('character:'.CONTACT_CHARACTER,$c);
    $source=synchronizeOnlineCharacterToken($db->payload('token:scene-one:token-player'),$c);
    $db->put('token:scene-one:token-player',$source);
    $target=$db->payload('token:scene-one:token-monster');$target['x']=$twoTargets?40:30;$db->put('token:scene-one:token-monster',$target);
    $second=[...$target,'id'=>'token-monster-two','name'=>'Même nom sans importance','x'=>38,'y'=>56];$db->put('token:scene-one:token-monster-two',$second);
    $db->put('items',normalizeItemStore(['version'=>1,'templates'=>[['id'=>$templateId,'name'=>'Nom arbitraire','kind'=>'object','equipment'=>['version'=>1,'kind'=>'weapon','hands'=>$twoTargets?1:2,'formula'=>'13','damageType'=>'physical','reach'=>$twoTargets?'reach':'melee','projectile'=>false,'finesse'=>false]]],'instances'=>[['id'=>'actual-weapon','templateId'=>$templateId,'revision'=>1,'location'=>['kind'=>'inventory','characterId'=>CONTACT_CHARACTER]]],'equipmentLoadouts'=>[CONTACT_CHARACTER=>['sets'=>[],'equippedItemIds'=>['actual-weapon'],'activeSetId'=>'']]]));
    return $db;
}

foreach([
    ['v370-template-5ec5f08a803a58d2fd9404d9','druidic-staff-strike',950,false],
    ['v370-template-d29417860cce105b93bb7f17','bladed-whip-lash',1440,true],
] as [$template,$preset,$duration,$twoTargets]) {
    $db=staffWhipFixture($template,$twoTargets);$before=$db->payload('items');$request='staff-whip-'.randomToken(20);
    $arguments=['requestId'=>$request,'useJoker'=>true,...($twoTargets?['targetTokenIds'=>['token-monster','token-monster-two']]:[])];
    $result=contactAttack($db,'weapon','configured-strike',$arguments);
    specialCheck($result->status===200,'Actual equipped attack accepted: '.json_encode($result->body));
    if($twoTargets){
        specialCheck(!empty($result->body['pendingAreaGate']),'Whip with two targets waits for the MJ');
        specialCheck(($db->payload('activity')['abilityCueEvents']??[])===[],'No visual before MJ approval');
        specialCheck($db->payload('token:scene-one:token-monster')['hp']===500,'No premature damage');
        specialCheck($db->payload('character:'.CONTACT_CHARACTER)['specialSuccessStock']===1,'No premature Joker payment');
        $result=runCommand($db,'token.attack.area.resolve',['attackId'=>$result->body['attack']['id'],'decision'=>'approve','confirmed'=>true,'defenseTokenIds'=>[]],true);
        specialCheck($result->status===200,'MJ approval resolves the actual whip targets: '.json_encode($result->body));
    }
    $attacks=$twoTargets?$result->body['areaAttacks']:[$result->body['attack']];
    $attacks=array_values(array_filter($attacks,static fn($a)=>empty($a['areaAnchorOnly'])));
    specialCheck(count($attacks)===($twoTargets?2:1),'One attack receipt per actual target');
    foreach($attacks as $attack)specialCheck($attack['appliedDamage']===13,'Existing weapon damage is preserved');
    $events=$db->payload('activity')['abilityCueEvents']??[];
    specialCheck(count($events)===count($attacks),'One visual per real target');
    foreach($events as $event){
        $cue=$event['completionCue']['spell'];
        specialCheck($cue['presetId']===$preset,'Exact item template chooses its visual');
        specialCheck(in_array($cue['targetTokenId'],array_column($attacks,'targetTokenId'),true),'Visual aims at an actual victim');
        $actual=$db->payload('token:scene-one:'.$cue['targetTokenId']);
        specialCheck($cue['targetPoint']==['x'=>$actual['x'],'y'=>$actual['y'],'size'=>$actual['size']],'Visual keeps the actual target footprint');
        specialCheck($event['completionCue']['sound']===null,'No Stream audio');
        specialCheck(applicationMeleeVisualCatalogue()[$preset]===$duration,'PHP catalogue uses the exact animation timing');
    }
    specialCheck($db->payload('character:'.CONTACT_CHARACTER)['specialSuccessStock']===0,'Only one accepted attack consumes the guaranteed hit');
    specialCheck($db->payload('items')===$before,'Attacks preserve physical inventory and hands');
    $replay=contactAttack($db,'weapon','configured-strike',$arguments);
    specialCheck($replay->status===200&&!empty($replay->body['deduplicated']),'Attack replay returns its existing receipt');
    specialCheck(($db->payload('activity')['abilityCueEvents']??[])===$events,'Replay never repeats a visual');
    if($twoTargets){
        $activity=$db->payload('activity');$stored=array_column($activity['attackReceipts'],'attack');
        $parent=array_values(array_filter($stored,static fn($a)=>$a['requestId']===$request))[0];
        $children=array_values(array_filter($stored,static fn($a)=>($a['weaponAttackParentId']??null)===$parent['id']));
        $fullMap=['naturalWidth'=>1000,'naturalHeight'=>1000,'tokens'=>[$db->payload('token:scene-one:token-player'),$db->payload('token:scene-one:token-monster'),$db->payload('token:scene-one:token-monster-two')]];
        appendApplicationAttackVisualEvent($activity,$parent,$fullMap,$db->payload('items'),[...$children,...$children]);
        specialCheck($activity['abilityCueEvents']===$events,'Duplicate child candidates and repeated delivery keep the same stable events');
    }

    $map=['naturalWidth'=>1000,'naturalHeight'=>1000,'tokens'=>[$db->payload('token:scene-one:token-player'),$db->payload('token:scene-one:token-monster'),$db->payload('token:scene-one:token-monster-two')]];
    $attack=array_values(array_filter($db->payload('activity')['attackReceipts'],static fn($receipt)=>$receipt['attack']['id']===$attacks[0]['id']))[0]['attack'];
    $context=['map'=>$map,'items'=>$db->payload('items'),'approved'=>true];
    specialCheck(applicationTrustedMeleeVisual($attack,$context)['presetId']===$preset,'The canonical receipt still resolves its real item');
    specialCheck(applicationTrustedMeleeVisual([...$attack,'echoAttackContext'=>[...$attack['echoAttackContext'],'projectile'=>true]],$context)===null,'Projectile cannot inherit its holder’s melee animation');
    specialCheck(applicationTrustedMeleeVisual([...$attack,'echoAttackContext'=>[...$attack['echoAttackContext'],'reach'=>'ranged']],$context)===null,'Ranged attack remains outside melee visuals');
    specialCheck(applicationTrustedMeleeVisual($attack,[...$context,'items'=>['instances'=>[]]])===null,'Missing physical object cannot be replaced by a name or profile');
    $visible=['sceneId'=>'scene-one','layerId'=>'ground','sourceTokenId'=>'token-player','visibleTokenIds'=>['token-player'],'visible'=>static fn()=>true];
    specialCheck(applicationPublicSpellVisual($events[0]['completionCue']['spell'],$visible)===null,'Hidden victim never receives a public visual');
}
echo json_encode(['ok'=>true,'checks'=>$specialChecks,'productionAccess'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
