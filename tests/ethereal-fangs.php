<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);
require __DIR__.'/tactical-special-effects.php';
$specialChecks=0;
const FANG_ABILITY='ability-29a3ef13-ceea-4740-bfe2-4007fa89e10b';
function fangFixture(): MemoryConnection {
    $db=specialFixture();$c=$db->payload('character:character-player');
    $c['abilities']=[['id'=>FANG_ABILITY,'name'=>'Nom indépendant','effect'=>'damage','formula'=>'2d40+24','damageType'=>'ignore','damageComponents'=>[['type'=>'ignore','formula'=>'2d40+24']],'castingStatId'=>'','manaCost'=>0,'hpCost'=>0,'fatigueCost'=>0,'cooldownRounds'=>5,'usesPerRest'=>1,'restRecharge'=>'short','reducedFailureCooldown'=>false]];
    $db->put('character:character-player',$c);
    $source=$db->payload('token:scene-one:token-player');$source['x']=20;$source['y']=20;$source['size']=40;$db->put('token:scene-one:token-player',$source);
    $target=$db->payload('token:scene-one:token-monster');$target['x']=64;$target['y']=20;$target['size']=40;$target['hp']=1000;$target['maxHp']=1000;$db->put('token:scene-one:token-monster',$target);return $db;
}
$db=fangFixture();$arguments=['sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','attackKind'=>'ability','attackId'=>FANG_ABILITY,'requestId'=>'fangs-'.randomToken(16),'infusionMana'=>7,'opposed'=>false];
$r=runCommand($db,'token.attack',$arguments);specialCheck($r->status===200,'Native cast: '.json_encode($r->body));
$activity=$db->payload('activity');$attack=$activity['attackReceipts'][0]['attack'];
specialCheck($attack['status']==='applied','Native damage applied');specialCheck($attack['infusion']['mana']===7&&$attack['infusion']['damage']===7,'Native infusion is one for one');
specialCheck($db->payload('character:character-player')['resources']['mana']===93,'One mana debit');specialCheck($db->payload('character:character-player')['fatigue']['current']===0,'Existing fatigue cost kept');
$cues=array_values(array_filter($activity['abilityCueEvents']??[],static fn($event)=>($event['completionCue']['spell']['presetId']??'')==='ethereal-ground-fangs'));specialCheck(count($cues)===1,'One authoritative cue for both fangs');specialCheck($cues[0]['sourceTokenId']==='token-player'&&$cues[0]['completionCue']['spell']['targetTokenId']==='token-monster','Actual source and target');
$again=runCommand($db,'token.attack',$arguments);specialCheck($again->status===200&&!empty($again->body['deduplicated']),'Native replay deduplicated');specialCheck($db->payload('character:character-player')['resources']['mana']===93,'No second infusion payment');specialCheck(count($db->payload('activity')['abilityCueEvents'])===count($activity['abilityCueEvents']),'No replayed visual');
$db=fangFixture();$target=$db->payload('token:scene-one:token-monster');$target['x']=64.01;$db->put('token:scene-one:token-monster',$target);
$r=runCommand($db,'token.attack',[...$arguments,'requestId'=>'fangs-far-'.randomToken(16),'rangeCells'=>999]);specialCheck($r->status===409,'Beyond eight cells refused');specialCheck($db->payload('character:character-player')['resources']['mana']===100,'Refused range pays nothing');
$legacy=$db->payload('character:character-player')['abilities'][0];$legacy['castingStatId']='character-stat-agility';$rules=normalizeOnlineAbilities([$legacy])[0];specialCheck($rules['rangeCells']===8&&$rules['reducedFailureCooldown']===true&&$rules['infusion']['minimumMana']===0,'Shared configurable rules loaded');
echo json_encode(['ok'=>true,'checks'=>$specialChecks,'productionAccess'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
