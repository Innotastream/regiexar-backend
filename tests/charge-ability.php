<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);require __DIR__.'/tactical-special-effects.php';
$db=specialFixture();$ability=json_decode(file_get_contents(__DIR__.'/fixtures/charge-ability.json'),true,512,JSON_THROW_ON_ERROR);
specialCheck(applicationComplexAbilityWorkflowError($ability['workflow'])==='','Reusable charge accepted by native schema');
$c=$db->payload('character:character-player');$c['abilities']=[$ability];$c['stats']['force']=60;$c['weaponText']='Lance 1d2+6';$c['weaponAttacks']=[['id'=>'weapon-1','name'=>'Lance','formula'=>'1d2+6','damageType'=>'physical']];$db->put('character:character-player',$c);
$s=synchronizeOnlineCharacterToken($db->payload('token:scene-one:token-player'),$c);$s['x']=10;$s['y']=50;$db->put('token:scene-one:token-player',$s);
$m=$db->payload('token:scene-one:token-monster');$m['x']=50;$m['hp']=$m['maxHp']=1000;$db->put('token:scene-one:token-monster',$m);
$db->put('token:scene-one:token-monster-two',[...$db->payload('token:scene-one:token-monster-two'),'x'=>65]);
foreach([20,30,40] as $i=>$x){$t=$m;$t['id']='charge-crossed-'.$i;$t['name']='Traversée '.$i;$t['x']=$x;$db->put('token:scene-one:'.$t['id'],$t);}
$index=$db->payload('token-index:scene-one');$index['order']=[...$index['order'],'charge-crossed-0','charge-crossed-1','charge-crossed-2'];$db->put('token-index:scene-one',$index);
function chargeCommand(MemoryConnection $db,string $action,array $extra=[]):TestResponse {
 $e=$db->payload('activity')['abilityExecutions'][0];return runCommand($db,'ability.complex',['action'=>'command','requestId'=>'charge-'.randomToken(20),'executionId'=>$e['id'],'expectedRevision'=>$e['revision'],'command'=>['action'=>$action,...$extra]]);
}
$start=['action'=>'start','requestId'=>'charge-'.randomToken(20),'sceneId'=>'scene-one','sourceTokenId'=>'token-player','abilityId'=>$ability['id'],'initialSelection'=>['allocations'=>[['tokenId'=>'token-monster','count'=>1]]]];$r=runCommand($db,'ability.complex',$start);specialCheck($r->status===200,'Native start: '.json_encode($r->body));
$r=fixtureApproveDefenseGate($db,$r);specialCheck($r->status===200,'MJ approves the initial charge target');$e=$r->body['execution'];specialCheck($db->payload('character:character-player')['fatigue']['current']===10,'Fatigue paid once');$r=runCommand($db,'ability.complex',$start);specialCheck(!empty($r->body['deduplicated']),'Idempotent start');
specialCheck($e['stepStates']['charge-target']['allocations'][0]['tokenId']==='token-monster','Select final target before the MJ decision');
$r=runCommand($db,'token.move',['sceneId'=>'scene-one','layerId'=>'ground','tokenId'=>'token-player','x'=>20,'y'=>70]);specialCheck($r->status===409,'Detour refused');specialCheck($db->payload('token:scene-one:token-player')['x']==10,'Rejected detour preserves position');
$r=specialMove($db,45);specialCheck($r->status===200,'Accepted native movement: '.json_encode($r->body));$r=chargeCommand($db,'confirm-movement');specialCheck($r->status===200,'Confirm native movement: '.json_encode($r->body));
$e=$r->body['execution'];specialCheck(($e['defenseGate']['status']??'')==='pending','Actual route asks the MJ about all crossed targets');$r=runCommand($db,'ability.complex',['action'=>'command','requestId'=>'charge-mj-defenses-'.randomToken(16),'executionId'=>$e['id'],'expectedRevision'=>$e['revision'],'command'=>['action'=>'resolve-defenses','decision'=>'approve','defenseTokenIds'=>[]]],true);specialCheck($r->status===200,'MJ decides defenses for the real route: '.json_encode($r->body));$e=$r->body['execution'];$plan=$e['stepStates']['charge-movement']['attackPlan'];specialCheck(array_column($plan,'tokenId')===['charge-crossed-0','charge-crossed-1','charge-crossed-2','token-monster','token-monster'],'All path targets ordered, final twice');specialCheck(array_column($plan,'thresholdPenalty')===[10,20,30,0,0],'Cumulative thresholds exclude final target');
foreach($plan as $strike){$r=chargeCommand($db,'prepare-target',['targetTokenId'=>$strike['tokenId']]);specialCheck($r->status===200,'Prepare strike');$e=$r->body['execution'];
 $args=['requestId'=>'charge-strike-'.randomToken(20),'sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>$strike['tokenId'],'attackKind'=>'weapon','attackId'=>$strike['attackId'],'statId'=>$strike['statId'],'opposed'=>false,'complexExecutionId'=>$e['id']];
 $bad=runCommand($db,'token.attack',[...$args,'hitModifier'=>100]);specialCheck($bad->status===409,'Cannot forge a positive threshold modifier');
 $r=runCommand($db,'token.attack',$args);specialCheck($r->status===200||$r->status===202,'Native strike: '.json_encode($r->body));$attack=$r->body['attack'];
 $replay=runCommand($db,'token.attack',$args);specialCheck(!empty($replay->body['deduplicated']),'Strike receipt reused without damage');
 if(($attack['status']??'')==='pending'){$r=runCommand($db,'token.attack.resolve',['attackId'=>$attack['id'],'decision'=>'approve','confirmed'=>true],true);specialCheck($r->status===200,'GM resolves critical attack: '.json_encode($r->body));}
 $r=chargeCommand($db,'confirm-attack',['attackRequestId'=>$args['requestId']]);specialCheck($r->status===200,'Confirm strike receipt: '.json_encode($r->body));
}
specialCheck($r->body['execution']['status']==='completed','No stop on failed path attacks');$receipts=array_values(array_filter($db->payload('activity')['attackReceipts'],static fn($receipt)=>empty($receipt['attack']['areaGate'])));
specialCheck(array_map(static fn($r)=>(int)($r['attack']['hit']['outcome']['modifier']??0),$receipts)===[-10,-20,-30,0,0],'Native attack rolls receive authoritative penalties');
specialCheck($db->payload('character:character-player')['fatigue']['current']===10,'No fatigue on individual strikes');specialCheck(count($db->payload('devices')['timedConditions'])===1,'Exactly one final stun');
specialCheck(in_array('Étourdi',$db->payload('token:scene-one:token-monster')['conditions'],true),'Final state visible on token');
// This tests the configurable attempt trigger independently of random dice.
$db2=specialFixture();$records=applicationDomainRecords($db2);$pending=[];$rider=$ability['workflow']['steps'][1]['charge']['finalCondition'];
$a=onlineDeviceRider($db2,$records,$pending,['id'=>'missed-charge','sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','attackName'=>'Finale','status'=>'missed','conditionRider'=>$rider]);
specialCheck(!empty($a['riderApplied'])&&count($pending['devices']['payload']['timedConditions'])===1,'Attempt rider applies on a miss');
$a=onlineDeviceRider($db2,$records,$pending,$a);specialCheck(count($pending['devices']['payload']['timedConditions'])===1,'Replayed attempt cannot duplicate stun');
$records=applicationDomainRecords($db2);$pending=[];$a['status']='rejected';unset($a['riderApplied']);onlineDeviceRider($db2,$records,$pending,$a);specialCheck($pending===[],'GM rejection never applies stun');
$plain=$rider;unset($plain['trigger']);$a['status']='missed';$a['conditionRider']=$plain;onlineDeviceRider($db2,$records,$pending,$a);specialCheck($pending===[],'An ordinary hit rider still requires a hit');
$invalid=$ability['workflow'];$invalid['steps'][1]['charge']['version']=2;try{normalizeApplicationComplexAbilityWorkflow($invalid);specialCheck(false,'Future charge refused');}catch(ApplicationComplexAbilityException){specialCheck(true,'Future charge refused');}
fwrite(STDOUT,json_encode(['ok'=>true,'checks'=>$specialChecks],JSON_UNESCAPED_UNICODE)."\n");
