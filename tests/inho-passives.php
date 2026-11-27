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

$concealmentArgs = ['statId'=>'character-stat-agility', 'rollOptionId'=>'concealment'];
$tokenKey = 'token:scene-one:token-player';
foreach ([false, true] as $gm) {
    $db = specialFixture();
    inhoSource($db, $talents);
    $regular = inhoRoll($db, [...$concealmentArgs, 'rollMode'=>'disadvantage'], $gm);
    $roll = $regular->body['roll'] ?? [];
    specialCheck($regular->status === 200 && ($roll['label'] ?? '') === 'Dissimulation'
        && ($roll['rollMode'] ?? '') === 'advantage' && count($roll['attempts'] ?? []) === 2,
        'The explicit player and MJ Concealment paths apply Inho’s advantage to the actual dice: '.json_encode($regular->body));
    specialCheck(($roll['visibility'] ?? '') === 'gm' && ($roll['revealed'] ?? true) === false,
        'Other players cannot receive the exact value through the roll feed.');
    specialCheck(inhoRoll($db, [...$concealmentArgs, 'statId'=>'character-stat-spiritSocial'], $gm)->status === 400,
        'A Forged Social Concealment roll is refused.');
    specialCheck(inhoRoll($db, [...$concealmentArgs, 'tokenId'=>'', 'characterId'=>'character-player'], $gm)->status === 409,
        'The new option requires an actual pawn.');
    $characterKey = 'character:character-player';
    $character = $db->payload($characterKey);
    $db->put($characterKey, [...$character, 'specialSuccessStock'=>1]);
    $db->put($tokenKey, [...$db->payload($tokenKey), 'concealment'=>['result'=>35]]);
    $args = [...$concealmentArgs, 'useJoker'=>true, 'requestId'=>'concealment-replay-'.($gm?'gm':'player')];
    $accepted = inhoRoll($db, $args, $gm);
    $roll = $accepted->body['roll'] ?? [];
    specialCheck($accepted->status === 200 && ($roll['outcome']['joker'] ?? false) === true
        && $db->payload($tokenKey)['concealment'] === ['result'=>1],
        'A deterministic better success records the improved effective value on this token: '.json_encode($accepted->body));
    $before = $db->domains;
    specialCheck((inhoRoll($db, $args, $gm)->body['deduplicated'] ?? false) === true && $db->domains === $before,
        'A retried roll cannot re-spend the Joker or rewrite the pawn.');
    $full = domainsToApplicationState($db->domains, 0);
    $projection = static fn (array $view) => array_column($view['map']['tokens'], null, 'id')['token-player'] ?? [];
    $owned = publicPlayerState($full, ['id'=>'account-player'], []);
    $other = publicPlayerState($full, ['id'=>'unrelated-player'], []);
    $stream = publicPlayerState($full, ['id'=>'account-gm'], [], true);
    specialCheck(($projection($owned)['concealment'] ?? null) === ['result'=>1]
        && ($projection($other)['concealed'] ?? false) && !isset($projection($other)['concealment'])
        && ($projection($stream)['concealed'] ?? false) && !isset($projection($stream)['concealment']),
        'The owner gets the result while visible peers and Stream receive only opacity state.');
    specialCheck(!in_array($roll['id'], array_column($other['rolls'], 'id'), true)
        && !in_array($roll['id'], array_column($stream['rolls'], 'id'), true),
        'A Concealment Joker must not be republished by global Joker handling.');
    $activity = $db->payload('activity');
    $forgedRolls = array_map(static fn (array $entry): array => $entry['id'] === $roll['id']
        ? [...$entry, 'visibility'=>'public', 'revealed'=>true, 'rollOptionId'=>''] : $entry, $activity['rolls']);
    $merged = preserveApplicationAbilityExtensions('activity', [...$activity, 'rolls'=>$forgedRolls], $activity);
    $protected = array_column($merged['rolls'], null, 'id')[$roll['id']];
    specialCheck($protected['visibility'] === 'gm' && $protected['revealed'] === false && $protected['rollOptionId'] === 'concealment',
        'Replacing a stale activity snapshot cannot accidentally publish a private roll.');
    $reveal = runCommand($db, 'roll.reveal', ['rollId'=>$roll['id']], true, 'account-gm');
    specialCheck($reveal->status === 200 && ($reveal->body['roll']['revealed'] ?? false) === true,
        'The MJ can intentionally publish the roll through its normal declaration.');
    $forged = prepareApplicationDomainUpsert($tokenKey, [...$db->payload($tokenKey), 'concealment'=>['result'=>-99],'concealed'=>true],
        $db->domains[$tokenKey], true);
    specialCheck($forged === null, 'A generic snapshot cannot change an existing score.');
    $clone = runCommand($db, 'token.clone', ['sceneId'=>'scene-one','tokenId'=>'token-player'], true, 'account-gm');
    specialCheck($clone->status === 200 && !isset($clone->body['token']['concealment']),
        'A fresh clone starts with no Concealment from its source.');
}
$db = specialFixture();
inhoSource($db, []);
$ordinary = inhoRoll($db, $concealmentArgs);
specialCheck($ordinary->status === 200 && ($ordinary->body['roll']['rollMode'] ?? '') === 'normal',
    'An ordinary character can hide with a single die.');
$db->put($tokenKey, [...$db->payload($tokenKey), 'concealment'=>['result'=>14]]);
$records = $db->domains;
$pending = [];
$makeOutcome = static fn (int $value, bool $success): array => ['outcome'=>['result'=>$value,'success'=>$success]];
foreach ([$makeOutcome(15, true), $makeOutcome(14, true), $makeOutcome(-4, false)] as $row) {
    onlineConcealmentRoll($records, $pending, $tokenKey, $concealmentArgs, $row);
    specialCheck($pending === [], 'A worse, identical or failed score does not replace the record.');
}
onlineConcealmentRoll($records, $pending, $tokenKey, $concealmentArgs, $makeOutcome(-4, true));
specialCheck($pending[$tokenKey]['payload']['concealment'] === ['result'=>-4],
    'A valid result-modified roll uses its effective lower value.');

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
