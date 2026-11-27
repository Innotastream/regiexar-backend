<?php
declare(strict_types=1);
define('XAR_CONTACT_FIXTURE_ONLY',true);require __DIR__.'/melee-contact-variants.php';
set_error_handler(static function($severity,$message){throw new ErrorException($message,0,$severity);});
$specialChecks=0;const BODY_CHARGE='ability-566f321d-ca05-4b02-92ad-8149076076e6';
function bodyChargeFixture(bool $miss=false,bool $immune=false):MemoryConnection {
 $db=contactFixture();$c=$db->payload('character:'.CONTACT_CHARACTER);$c['abilities']=[['id'=>BODY_CHARGE,'name'=>'Charge fictive','effect'=>'complex','formula'=>'0','castingStatId'=>'character-stat-force','workflow'=>json_decode(file_get_contents(__DIR__.'/fixtures/charge-ability.json'),true,512,JSON_THROW_ON_ERROR)['workflow']]];$c['stats']['force']=$miss?1:100;$c['weaponAttacks'][0]['reach']='reach';$c['weaponAttacks'][0]['formula']='1d40+6';$c['weaponText']='Hallebarde / 1d40+6';$db->put('character:'.CONTACT_CHARACTER,$c);
 $s=synchronizeOnlineCharacterToken($db->payload('token:scene-one:token-player'),$c);$s['x']=10;$s['y']=50;$db->put('token:scene-one:token-player',$s);$target=$db->payload('token:scene-one:token-monster');$target['x']=60;$target['hp']=$target['maxHp']=1000;$target['conditionImmunities']=$immune?['Étourdi']:[];$db->put('token:scene-one:token-monster',$target);
 $cross=[...$target,'id'=>'body-crossed','x'=>35,'conditionImmunities'=>[]];$flank=[...$target,'id'=>'body-flank','x'=>40,'y'=>73,'conditionImmunities'=>[]];$ally=[...$target,'id'=>'body-ally','x'=>25,'y'=>50,'controllerPlayerId'=>'account-player','conditionImmunities'=>[]];foreach([$cross,$flank,$ally]as$t)$db->put('token:scene-one:'.$t['id'],$t);$other=$db->payload('token:scene-one:token-monster-two');$other['x']=95;$other['y']=95;$db->put('token:scene-one:token-monster-two',$other);$index=$db->payload('token-index:scene-one');$index['order']=[...$index['order'],'body-crossed','body-flank','body-ally'];$db->put('token-index:scene-one',$index);return $db;
}
function bodyCommand(MemoryConnection $db,string $action,array $args=[]):TestResponse {$e=$db->payload('activity')['abilityExecutions'][0];return runCommand($db,'ability.complex',['action'=>'command','requestId'=>'body-'.randomToken(20),'executionId'=>$e['id'],'expectedRevision'=>$e['revision'],'command'=>['action'=>$action,...$args]]);}
function bodyPlan(MemoryConnection $db):array {
 $selection=['allocations'=>[['tokenId'=>'token-monster','count'=>1]]];$r=runCommand($db,'ability.complex',['action'=>'start','requestId'=>'body-start-'.randomToken(20),'sceneId'=>'scene-one','sourceTokenId'=>'token-player','abilityId'=>BODY_CHARGE,'initialSelection'=>$selection,'chargeSelection'=>$selection]);specialCheck($r->status===200,'Native charge starts: '.json_encode($r->body));specialCheck(($r->body['rolls']??[])===[],'No preliminary roll');specialCheck($db->payload('character:'.CONTACT_CHARACTER)['fatigue']['current']===0,'MJ decision precedes fatigue');
 $r=fixtureApproveDefenseGate($db,$r);specialCheck($r->status===200,'Native final target and route approved by MJ: '.json_encode($r->body));specialCheck($db->payload('character:'.CONTACT_CHARACTER)['fatigue']['current']===10,'Ten fatigue paid once');$p=$r->body['execution']['stepStates']['charge-movement'];specialCheck($p['status']==='completed','Native route confirmation recorded');specialCheck($db->payload('token:scene-one:token-player')['x']==10,'Planning does not move the token');specialCheck(array_column($p['attackPlan'],'tokenId')===['body-crossed','body-flank','token-monster'],'Reach enemies only, final once');specialCheck(!array_filter($p['chargeDefenses']),'Native individual defenses committed by MJ');return $p['attackPlan'];
}
if(defined('XAR_BODY_CHARGE_FIXTURE_ONLY')&&XAR_BODY_CHARGE_FIXTURE_ONLY)return;
$reach=applicationChargeWeaponRange(['weaponAttacks'=>[['reach'=>'reach']]]);
$track=['sourceSize'=>40,'points'=>[['x'=>10,'y'=>50],['x'=>55,'y'=>50]]];
$field=['naturalWidth'=>1000,'naturalHeight'=>1000,'gridSize'=>50];
$flank=['id'=>'measured-flank','x'=>40,'y'=>74,'size'=>40,'layerId'=>'ground'];
specialCheck($reach===4&&is_finite(applicationChargeFirstContact($flank,$track,$field,$reach)), 'Bélier includes a flank four cells from its edge');
specialCheck(!is_finite(applicationChargeFirstContact([...$flank,'y'=>74.001],$track,$field,$reach)), 'Bélier excludes a flank just beyond four cells');
foreach([[false,false],[true,false],[true,true]]as[$miss,$immune]){
 $db=bodyChargeFixture($miss,$immune);$items=$db->payload('items');$plan=bodyPlan($db);$cueCount=0;
 foreach($plan as$chargeIndex=>$strike){$before=$db->payload('token:scene-one:token-player');$hasMotion=!applicationChargeSame($before,$strike['movePoint'])||!empty($strike['finalContact']);$r=bodyCommand($db,'prepare-target',['targetTokenId'=>$strike['tokenId']]);specialCheck($r->status===200,'Native charge segment: '.json_encode($r->body));$own=array_values(array_filter($db->payload('activity')['abilityCueEvents']??[],static fn($cue)=>($cue['completionCue']['spell']['placement']??'')==='dash'&&($cue['completionCue']['spell']['sourceTokenId']??'')==='token-player'));specialCheck(count($own)===$cueCount+(int)$hasMotion,'One caster cue for each actual segment');if($hasMotion){$visual=$own[$cueCount]['completionCue']['spell'];specialCheck($visual['presetId']===($cueCount===0?'token-body-charge-ultimate':'token-body-charge-slow'),'Only the first real segment carries the cinematic');specialCheck(applicationSpellVisualDuration($visual)===($cueCount===0?6080:3880),'Native delivery lifetime matches the slowed renderer');$cueCount++;}specialCheck(abs($db->payload('token:scene-one:token-player')['x']-$strike['movePoint']['x'])<1e-5,'Actual position follows strike waypoint');$e=$r->body['execution'];$args=['requestId'=>'body-hit-'.randomToken(20),'sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>$strike['tokenId'],'attackKind'=>'weapon','attackId'=>$strike['attackId'],'statId'=>$strike['statId'],'opposed'=>$strike['opposed'],'complexExecutionId'=>$e['id']];$r=runCommand($db,'token.attack',$args);specialCheck($r->status===200,'Native Force strike: '.json_encode($r->body));
  if (!$miss) {
      $outcome = $r->body['attack']['hit']['outcome'];
      specialCheck((int)$outcome['modifier'] === [-10, -20, 0][$chargeIndex], 'Only attacks on the route carry its Force penalty');
      specialCheck((int)$outcome['threshold'] === [90, 80, 100][$chargeIndex], 'Final attack rolls at the full Force threshold');
  }
  if(($r->body['attack']['status']??'')==='pending'){$r=runCommand($db,'token.attack.resolve',['attackId'=>$r->body['attack']['id'],'decision'=>'approve','confirmed'=>true],true);specialCheck($r->status===200,'Remarkable waits for GM');}
  $r=bodyCommand($db,'confirm-attack',['attackRequestId'=>$args['requestId']]);specialCheck($r->status===200,'Native strike receipt confirmed: '.json_encode($r->body));
 }
 specialCheck($r->body['execution']['status']==='completed','Native charge completed');specialCheck($db->payload('token:scene-one:token-player')['x']==55,'Native body reaches final contact');specialCheck($db->payload('token:scene-one:body-ally')['y']==50&&$db->payload('token:scene-one:body-crossed')['y']==50,'Transit leaves other tokens in place');specialCheck($db->payload('token:scene-one:body-ally')['hp']===1000,'Ally never attacked');specialCheck($db->payload('items')===$items,'Inventories and hands unchanged');specialCheck($db->payload('character:'.CONTACT_CHARACTER)['fatigue']['current']===10,'No cost on individual strikes');
 $conditions=$db->payload('devices')['timedConditions'];specialCheck(count($conditions)===1,'One final condition');specialCheck($conditions[0]['label']===($immune?'Déstabilisé':'Étourdi'),'Immunity receives visible fallback');specialCheck(($conditions[0]['expiry']??'')==='end-next-turn','Both conditions last a full target turn');if($immune)specialCheck(!$conditions[0]['blocksActions']&&!$conditions[0]['blocksMovement']&&!isset($conditions[0]['attackPenalty']),'Fallback has no extra penalty');
}
// Approval keeps the player's visible, selected route after the scene changes.
$db=bodyChargeFixture();
$hero=$db->payload('character:'.CONTACT_CHARACTER);$hero['friendlyFire']=true;$db->put('character:'.CONTACT_CHARACTER,$hero);
$bytes=str_repeat("\0",128*128/8);
for($y=89;$y<=97;$y++)for($x=46;$x<=55;$x++){
    $pixel=$y*128+$x;$index=$pixel>>3;$bytes[$index]=chr(ord($bytes[$index])|(1<<($pixel&7)));
}
$map=$db->payload('map:scene-one');$map['layers']['ground']['fog']=['enabled'=>true,'version'=>1,'width'=>128,'height'=>128,'mask'=>rtrim(strtr(base64_encode($bytes),'+/','-_'),'=')];$db->put('map:scene-one',$map);
$selection=['allocations'=>[['tokenId'=>'token-monster','count'=>1]],'pathTargetTokenIds'=>['body-ally','body-crossed','body-flank']];
$stage=runCommand($db,'ability.complex',['action'=>'start','requestId'=>'body-private-'.randomToken(20),'sceneId'=>'scene-one',
    'sourceTokenId'=>'token-player','abilityId'=>BODY_CHARGE,'initialSelection'=>$selection]);
specialCheck($stage->status===200,'Friendly fire preview: '.json_encode($stage->body));
$card=$db->payload('activity')['pendingAttacks'][0]['areaGate'];
specialCheck($card['targetTokenIds']===['body-ally','body-crossed','token-monster'],'Preview only contains visible selected targets');
$crossed=$db->payload('token:scene-one:body-crossed');$crossed['hidden']=true;$db->put('token:scene-one:body-crossed',$crossed);
$approve=fixtureApproveDefenseGate($db,$stage);
specialCheck($approve->status===200,'Approval after concealment: '.json_encode($approve->body));
specialCheck(array_column($approve->body['execution']['stepStates']['charge-movement']['attackPlan'],'tokenId')===['body-ally','token-monster'],'Hidden targets are dropped at approval');
$ally=$db->payload('token:scene-one:body-ally');
$r=bodyCommand($db,'prepare-target',['targetTokenId'=>'body-ally']);
specialCheck($r->status===200,'Selected ally move was prepared');
$ally['hidden']=true;$db->put('token:scene-one:body-ally',$ally);
$r=runCommand($db,'token.attack',['requestId'=>'body-private-hit-'.randomToken(20),'sceneId'=>'scene-one','sourceTokenId'=>'token-player',
    'targetTokenId'=>'body-ally','attackKind'=>'weapon','attackId'=>'weapon-1','statId'=>'character-stat-force','complexExecutionId'=>$r->body['execution']['id']]);
specialCheck($r->status===409&&($r->body['code']??'')==='complex_ability_charge_target_unavailable','An ally hidden before the hit cannot be damaged');
$r=bodyCommand($db,'skip-charge-target',['targetTokenId'=>'body-ally']);
specialCheck($r->status===200,'Unavailable pending ally can be skipped: '.json_encode($r->body));
$r=bodyCommand($db,'prepare-target',['targetTokenId'=>'token-monster']);
specialCheck($r->status===200&&abs($db->payload('token:scene-one:token-player')['x']-55)<1e-5,'Continues to final contact after skipped ally: '.json_encode($r->body));
specialCheck($db->payload('token:scene-one:body-ally')['hp']===1000,'Skipped ally HP remains intact');
foreach ([false,true] as $gm) foreach ([false,true] as $open) {
    $doorTest=bodyChargeFixture();
    $door=['id'=>'charge-door','name'=>'Porte','x'=>55,'y'=>50,'hp'=>50,'maxHp'=>50,'size'=>100,
        'layerId'=>'ground','fixedStructure'=>true,
        'terrainBarrier'=>['version'=>1,'shape'=>'door','widthCells'=>4,'rotation'=>90,'layout'=>'double','open'=>$open]];
    $doorTest->put('token:scene-one:charge-door',$door);
    $index=$doorTest->payload('token-index:scene-one');$index['order'][]='charge-door';$doorTest->put('token-index:scene-one',$index);
    $selection=['allocations'=>[['tokenId'=>'token-monster','count'=>1]]];
    $r=runCommand($doorTest,'ability.complex',['action'=>'start','requestId'=>'body-door-'.randomToken(20),
        'sceneId'=>'scene-one','sourceTokenId'=>'token-player','abilityId'=>BODY_CHARGE,'initialSelection'=>$selection,'chargeSelection'=>$selection],
        $gm,$gm?'account-gm':'account-player');
    specialCheck($open ? $r->status===200&&!empty($r->body['pendingAreaGate']) : $r->status===409,
        'Only the real leaves/jambs can block the ordinary body charge, including from the MJ view: '.json_encode($r->body));
    if (!$open) continue;
    $r=fixtureApproveDefenseGate($doorTest,$r);
    specialCheck($r->status===200&&!empty($r->body['execution']['stepStates']['charge-movement']['attackPlan']),
        'MJ approval keeps the physically clear approach accepted: '.json_encode($r->body));
}
echo json_encode(['ok'=>true,'checks'=>$specialChecks,'productionAccess'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
