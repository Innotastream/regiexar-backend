<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);require __DIR__.'/tactical-special-effects.php';
$talents=applicationConfiguredPassiveAbilities(['id'=>'character-inho']);
function inhoSource(MemoryConnection $db,array $talents):void{
 $c=$db->payload('character:character-player');$c['passiveAbilities']=$talents;$c['stats']['spiritSocial']=$c['stats']['agility']=70;$c['fatigue']['current']=0;$c['weaponText']='Rapières / 7';$c['weaponAttacks']=[['id'=>'rapiers','reach'=>'melee','formula'=>'7','damageType'=>'ignore']];$db->put('character:character-player',$c);
 $db->put('token:scene-one:token-player',synchronizeOnlineCharacterToken($db->payload('token:scene-one:token-player'),$c));
}
function inhoRoll(MemoryConnection $db,array $args=[],bool $gm=false):TestResponse{
 return runCommand($db,'token.roll',['requestId'=>'inho-'.randomToken(18),'sceneId'=>'scene-one','layerId'=>'ground','tokenId'=>'token-player','kind'=>'stat','statId'=>'character-stat-spiritSocial',...$args],$gm,$gm?'account-gm':'account-player');
}
specialCheck(count($talents)===6,'Six configured Inho passives');
specialCheck(count(array_filter($talents,static fn($p)=>$p['kind']==='manual'))===3,'Three arbitration-only traits');
$db=specialFixture();inhoSource($db,$talents);
foreach([false,true] as $gm){
 $requestId='inho-roll-'.randomToken(20);$args=['requestId'=>$requestId,'rollOptionId'=>'inho-hypnotic-words'];$r=inhoRoll($db,$args,$gm);specialCheck($r->status===200,'Real hypnotic roll: '.json_encode($r->body));
 $roll=$r->body['roll'];specialCheck($roll['label']==='Paroles hypnotiques'&&$roll['formula']===(!empty($roll['outcome']['resultModifierApplied'])?'1d100-25':'1d100'),'Authoritative label and formula obey the remarkable/raw failure rules');
 specialCheck($roll['outcome']['threshold']===70&&$roll['outcome']['resultModifier']===-25,'Threshold unchanged and result improvement configured');
 specialCheck(!empty(inhoRoll($db,$args,$gm)->body['deduplicated']),'Hypnotic receipt replay');
 specialCheck(inhoRoll($db,[...$args,'rollOptionId'=>'inho-stealth-advantage'],$gm)->status===409,'Changed option cannot reuse receipt');
 $r=inhoRoll($db,['statId'=>'character-stat-agility','rollOptionId'=>'inho-stealth-advantage','rollMode'=>'advantage'],$gm);specialCheck($r->status===200,'Real stealth roll: '.json_encode($r->body));
 specialCheck($r->body['roll']['label']==='Avantage furtivité'&&$r->body['roll']['rollMode']==='advantage'&&count($r->body['roll']['attempts'])===2,'Stealth has exactly two dice');
 foreach([['statId'=>'character-stat-agility','rollOptionId'=>'inho-hypnotic-words'],['rollOptionId'=>'unknown-talent'],['kind'=>'custom-stat','threshold'=>70,'rollOptionId'=>'inho-hypnotic-words'],['rollOptionId'=>'inho-hypnotic-words','modifier'=>5],['rollOptionId'=>'inho-hypnotic-words','useJoker'=>true]] as $bad){$r=inhoRoll($db,$bad,$gm);specialCheck(in_array($r->status,[400,403],true),'Invalid trait request rejected: '.json_encode($r->body));}
}
specialCheck($db->payload('character:character-player')['stats']['spiritSocial']===70,'Base Social unmodified');

$db=specialFixture();inhoSource($db,$talents);$source=$db->payload('token:scene-one:token-player');$source['x']=20;$source['y']=50;$db->put('token:scene-one:token-player',$source);$target=$db->payload('token:scene-one:token-monster');$target['x']=25;$db->put('token:scene-one:token-monster',$target);
$before=$db->payload('activity')['rolls']??[];
$r=runCommand($db,'token.move',['sceneId'=>'scene-one','layerId'=>'ground','tokenId'=>'token-monster','x'=>42,'y'=>50],true,'account-gm');specialCheck($r->status===200,'GM moves enemy: '.json_encode($r->body));
$p=$db->payload('devices')['pendingRolls'][0]??[];specialCheck(($p['type']??'')==='opportunity'&&($p['weaponAttackId']??'')==='rapiers','Opportunity is recorded after move with melee weapon');
specialCheck($db->payload('token:scene-one:token-monster')['hp']===500&&($db->payload('activity')['rolls']??[])===$before,'No auto damage or dice');
$state=domainsToApplicationState($db->domains,0);$ctx=['sceneId'=>'scene-one','layerId'=>'ground','map'=>$state['map'],'tokens'=>$state['map']['tokens'],'initiative'=>$state['initiative'],'authorityState'=>$state,'visibleTokenIds'=>['token-player','token-monster']];
$visible=static fn()=>true;$alerts=applicationPublicOpportunityAlerts($state['devices'],['id'=>'account-player'],$state['characters'],$ctx,$visible);specialCheck(count($alerts)===1,'Involved player sees a safe alert');
specialCheck(!isset($alerts[0]['sourcePosition'])&&!isset($alerts[0]['passiveId'])&&!isset($alerts[0]['statId']),'No private fields projected');
specialCheck(applicationPublicOpportunityAlerts($state['devices'],['id'=>'other-player'],$state['characters'],$ctx,$visible)===[],'Other player has no alert');
specialCheck(applicationPublicOpportunityAlerts($state['devices'],['id'=>'account-player'],$state['characters'],[...$ctx,'tokens'=>[$source],'visibleTokenIds'=>['token-player']],$visible)===[],'Absent public target neither leaks nor crashes projection');
specialCheck(applicationPublicOpportunityAlerts($state['devices'],null,$state['characters'],$ctx,$visible)===[],'Stream has no alert');
specialCheck(applicationPublicOpportunityAlerts($state['devices'],['id'=>'account-player'],$state['characters'],[...$ctx,'visibleTokenIds'=>['token-player']],$visible)===[],'Hidden target does not leak');
specialCheck(applicationPublicOpportunityAlerts($state['devices'],['id'=>'account-player'],$state['characters'],$ctx,static fn()=>false)===[],'Strict fog suppresses alert');
$r=specialMechanics($db,'opportunity.resolve',['validationId'=>$p['id'],'approve'=>true],false);specialCheck($r->status===403,'Player cannot resolve opportunity');
$r=specialMechanics($db,'opportunity.resolve',['validationId'=>$p['id'],'approve'=>false]);specialCheck($r->status===200&&empty($db->payload('devices')['pendingRolls']),'MJ refusal removes opportunity');

# The existing guard's passive is not exclusive to Inho, and allies do not become controllable.
$db=specialFixture();$guard=$db->payload('token:scene-one:token-monster');$guard['allied']=true;$guard['passiveAbilities']=array_values(array_filter($talents,static fn($p)=>$p['kind']==='opportunity-attack'));$db->put('token:scene-one:token-monster',$guard);
$r=specialMove($db,68);specialCheck($r->status===200&&empty($db->payload('devices')['pendingRolls']),'Allied guard gives no player warning');specialCheck(empty($db->payload('token:scene-one:token-monster')['controllerPlayerId']),'Allied flag grants no controller');
$guard['allied']=false;$db->put('token:scene-one:token-monster',$guard);$s=$db->payload('token:scene-one:token-player');$s['x']=55;$s['y']=50;$db->put('token:scene-one:token-player',$s);$r=specialMove($db,68);specialCheck($r->status===200&&!empty($db->payload('devices')['pendingRolls']),'Hostile guard warns after player movement');
$state=domainsToApplicationState($db->domains,0);$p=$state['devices']['pendingRolls'][0];specialCheck(applicationOpportunityIsLive($p,$state),'Native pending reaction is live');
$state['initiative']['active']=false;specialCheck(!applicationOpportunityIsLive($p,$state),'No reaction outside combat');
$i=$db->payload('initiative:scene-one');$i['active']=false;$db->put('initiative:scene-one',$i);$d=$db->payload('devices');$d['pendingRolls']=[];$db->put('devices',$d);$s['x']=55;$db->put('token:scene-one:token-player',$s);$r=specialMove($db,68);specialCheck($r->status===200&&empty($db->payload('devices')['pendingRolls']),'No opportunity generated outside combat');

echo json_encode(['ok'=>true,'checks'=>$specialChecks,'productionAccess'=>false],JSON_THROW_ON_ERROR)."\n";
