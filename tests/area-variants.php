<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);
require __DIR__.'/tactical-special-effects.php';
const ROOTS_ID='ability-832949db-79d2-4e1a-a916-fea5c439edc1';
function rootsFixture(int $mana=100):MemoryConnection{
 $db=specialFixture();$c=$db->payload('character:character-player');
 $c['abilities']=[['id'=>ROOTS_ID,'name'=>'Racines de recette','effect'=>'damage','formula'=>'0','damageType'=>'physical','castingStatId'=>'','manaCost'=>15,'fatigueCost'=>5,'cooldownRounds'=>5,'damageTargeting'=>['mode'=>'area','origin'=>'point','radiusCells'=>2.5,'singleTargetHalfMana'=>true],
 'conditionRider'=>['version'=>1,'label'=>'Entravé','turns'=>3,'blocksMovement'=>true,'blocksActions'=>false,'escape'=>['statId'=>'character-stat-force','penalties'=>[-20,-10,0]]]]];
 $c['resources']['mana']=$mana;$c['passiveAbilities']=[];$db->put('character:character-player',$c);
 $source=synchronizeOnlineCharacterToken($db->payload('token:scene-one:token-player'),$c);$source['x']=49;$source['y']=49;$db->put('token:scene-one:token-player',$source);
 $enemy=$db->payload('token:scene-one:token-monster');$enemy['x']=49;$enemy['y']=51;$enemy['hidden']=false;$db->put('token:scene-one:token-monster',$enemy);
 $outside=$db->payload('token:scene-one:token-monster-two');$outside['x']=5;$outside['y']=5;$db->put('token:scene-one:token-monster-two',$outside);
 $ally=[...$enemy,'id'=>'token-ally','name'=>'Allié','x'=>51,'y'=>49,'controllerPlayerId'=>'account-player','characterId'=>null,'followCharacter'=>false];$db->put('token:scene-one:token-ally',$ally);$index=$db->payload('token-index:scene-one');$index['order'][]='token-ally';$db->put('token-index:scene-one',$index);return $db;
}
function rootsArgs(string $variant):array{return ['requestId'=>'roots-'.randomToken(18),'sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-player','attackKind'=>'ability','attackId'=>ROOTS_ID,'targetPoint'=>['x'=>50,'y'=>50],'targetingMode'=>'area','areaVariantId'=>$variant,'opposed'=>false];}
if(defined('ROOTS_FIXTURE_ONLY')&&ROOTS_FIXTURE_ONLY)return;
$a=normalizeOnlineAbilities(rootsFixture()->payload('character:character-player')['abilities'])[0];
specialCheck(count($a['areaVariants'])===3&&$a['damageTargeting']['shape']==='square'&&!isset($a['damageTargeting']['singleTargetHalfMana']),'Canonical upgrade and normalization preserve square choices');
specialCheck($a['fatigueCost']===5&&$a['cooldownRounds']===5&&$a['castingStatId']==='','Other configured mechanics are preserved');
foreach([['roots-2',10],['roots-4',20],['roots-8',30]]as[$variant,$mana]){
 $db=rootsFixture();$args=rootsArgs($variant);$p=runCommand($db,'token.attack',$args);specialCheck($p->status===200,'Prepare '.$variant.': '.json_encode($p->body));specialCheck(($p->body['pendingAreaGate']??false)===true,'Choose defenses before applying the square');
 specialCheck($db->payload('character:character-player')['resources']['mana']===100,'No payment before approval');
 $r=runCommand($db,'token.attack.area.resolve',['requestId'=>'roots-approve-'.randomToken(18),'attackId'=>$p->body['attack']['id'],'decision'=>'approve','confirmed'=>true,'defenseTokenIds'=>[]],true);
 specialCheck($r->status===200,'Approve '.$variant.': '.json_encode($r->body));$c=$db->payload('character:character-player');specialCheck($c['resources']['mana']==100-$mana&&$c['fatigue']['current']===5,'Exactly selected mana and shared fatigue');
 foreach(['token-ally','token-monster']as$id)specialCheck(in_array('Entravé',$db->payload('token:scene-one:'.$id)['conditions']??[],true),'Ally and enemy are entangled');
 specialCheck(!in_array('Entravé',$db->payload('token:scene-one:token-player')['conditions']??[],true),'Caster is immune while inside the square');
 $conditions=$db->payload('devices')['timedConditions'];specialCheck(count($conditions)===2,'Exactly two recipients');foreach($conditions as$condition)specialCheck($condition['remainingTurns']===3&&$condition['blocksMovement']===true&&$condition['escape']['penalties']===[-20,-10,0],'Same duration and escape penalties');
 specialCheck((runCommand($db,'token.attack',$args)->body['deduplicated']??false)===true,'Replay deduplicated');specialCheck($db->payload('character:character-player')['resources']['mana']==100-$mana,'Replay cannot pay twice');
 specialCheck(runCommand($db,'token.attack',[...$args,'areaVariantId'=>$variant==='roots-2'?'roots-8':'roots-2'])->status>=400,'Same receipt cannot change size');
 $events=$db->payload('activity')['abilityCueEvents']??[];$roots=array_values(array_filter($events,static fn($e)=>($e['completionCue']['spell']['presetId']??'')==='root-entanglement'));specialCheck(count($roots)===0&&count($db->payload('devices')['spellFields'])===1,'The persistent field owns its lifetime without a separate fading burst');
}
$db=rootsFixture();$source=$db->payload('token:scene-one:token-player');$alias=[...$source,'id'=>'keyrie-linked-alias','x'=>51,'y'=>51];$clone=[...$alias,'id'=>'keyrie-independent-clone','followCharacter'=>false,'independentClone'=>true];$db->put('token:scene-one:keyrie-linked-alias',$alias);$db->put('token:scene-one:keyrie-independent-clone',$clone);$index=$db->payload('token-index:scene-one');$index['order']=[...$index['order'],$alias['id'],$clone['id']];$db->put('token-index:scene-one',$index);
$p=runCommand($db,'token.attack',rootsArgs('roots-2'));$r=runCommand($db,'token.attack.area.resolve',['requestId'=>'alias-approve-'.randomToken(18),'attackId'=>$p->body['attack']['id'],'decision'=>'approve','confirmed'=>true,'defenseTokenIds'=>[]],true);specialCheck($r->status===200,'Alias area approval: '.json_encode($r->body));
foreach(['token-player','keyrie-linked-alias']as$id)specialCheck(!in_array('Entravé',$db->payload('token:scene-one:'.$id)['conditions']??[],true),'Linked occurrences are caster immune');specialCheck(!in_array('Entravé',$db->payload('character:character-player')['conditions']??[],true),'No indirect caster condition through a linked token');specialCheck(in_array('Entravé',$db->payload('token:scene-one:keyrie-independent-clone')['conditions']??[],true),'Independent clone is a separate affectable unit');
$db=rootsFixture(15);$base=rootsArgs('roots-2');foreach([['areaVariantId'=>null],['areaVariantId'=>'forged'],['targetingMode'=>'single','targetTokenId'=>'token-ally']]as$extra)specialCheck(runCommand($db,'token.attack',[...$base,...$extra,'requestId'=>'invalid-'.randomToken(18)])->status>=400,'Malformed or retired choice rejected');
$p=runCommand($db,'token.attack',rootsArgs('roots-8'));if($p->status===200)$p=runCommand($db,'token.attack.area.resolve',['requestId'=>'poor-'.randomToken(18),'attackId'=>$p->body['attack']['id'],'decision'=>'approve','confirmed'=>true,'defenseTokenIds'=>[]],true);
specialCheck($p->status>=400&&$db->payload('character:character-player')['resources']['mana']===15,'Insufficient mana is never debited');
echo json_encode(['ok'=>true,'checks'=>$specialChecks]),"\n";
