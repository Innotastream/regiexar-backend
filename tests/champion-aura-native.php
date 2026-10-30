<?php
declare(strict_types=1);
require_once __DIR__.'/tactical-lifecycle.php';
require_once __DIR__.'/champion-aura-combat.php';
set_error_handler(static function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
$nativeChecks=0;
function championNative(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);$GLOBALS['nativeChecks']++;}
function championStoredAttack(MemoryConnection $db,string $id):array{foreach($db->payload('activity')['pendingAttacks']??[] as $attack)if($attack['id']===$id)return $attack;foreach($db->payload('activity')['attackReceipts']??[] as $receipt)if(($receipt['attack']['id']??'')===$id)return $receipt['attack'];throw new RuntimeException('Missing authoritative attack');}
function championCommand(MemoryConnection $db,string $command,array $args,bool $gm=false):TestResponse{return runCommand($db,$command,$args,$gm);}
$db=fixture();$c=$db->payload('character:character-player');
$c['resources']=['hp'=>10,'maxHp'=>100,'mana'=>100,'maxMana'=>100];$c['stats']=['force'=>100,'agility'=>100];$c['fatigue']=['current'=>0,'max'=>150];$c['specialSuccessStock']=20;
$circle=$aura;$dance=['id'=>'dance','name'=>'Zone armée de recette','effect'=>'damage','formula'=>'0','castingStatId'=>'character-stat-force','fatigueCost'=>5,'cooldownRounds'=>5,'useWeaponDamage'=>true,'requiredWeaponAttackId'=>'chain','projectile'=>false,'damageTargeting'=>['mode'=>'area','origin'=>'target','radiusCells'=>4,'affectCaster'=>false,'affectAllies'=>false,'affectEnemies'=>true],'singleEnemy'=>['damageRepetitions'=>2,'conditionRider'=>['version'=>1,'label'=>'Restreint','turns'=>1,'resistance'=>false]]];
$c['abilities']=normalizeOnlineAbilities([$champion,$stigma,$circle,$dance]);$c['weaponText']='Principale 1d2+3; Chaîne 1d2+6';$c['weaponAttacks']=[['id'=>'main','name'=>'Principale','formula'=>'1d2+3','damageType'=>'physical','reach'=>'melee'],['id'=>'chain','name'=>'Chaîne','formula'=>'1d2+6','damageType'=>'physical','reach'=>'reach']];$db->put('character:character-player',$c);
$db->put('map:scene-one',['gridSize'=>50,'naturalWidth'=>1000,'naturalHeight'=>1000,'activeLayerId'=>'ground']);
$db->put('initiative:scene-one',['active'=>true,'order'=>['token-player','token-monster'],'currentIndex'=>0,'round'=>1,'turnSerial'=>1,'combatId'=>'champion-combat']);
$enemy=$db->payload('token:scene-one:token-monster');$enemy=[...$enemy,'x'=>35,'hp'=>10000,'maxHp'=>10000,'armor'=>0,'magicArmor'=>0,'layerId'=>'ground'];$db->put('token:scene-one:token-monster',$enemy);
$otherEnemy=$db->payload('token:scene-one:token-monster-two');$otherEnemy['x']=95;$db->put('token:scene-one:token-monster-two',$otherEnemy);
$use=static function($id,$extra=[])use($db){return championCommand($db,'ability.use',['sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-player','layerId'=>'ground','abilityId'=>$id,'requestId'=>'champion-use-'.randomToken(16),...$extra]);};
$denied=$use($champion['id'],['targetTokenId'=>'token-monster']);championNative($denied->status===400,'Self-only buff rejects forged recipient before cost: '.json_encode($denied->body));
foreach([$champion['id'],$stigma['id']] as $id){$r=$use($id);championNative($r->status===200,'Buff activates via PHP authority: '.json_encode($r->body));}
championNative($db->payload('character:character-player')['fatigue']['current']===15,'Combined buffs paid once');
$args=['requestId'=>'champion-attack-'.randomToken(16),'sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','attackKind'=>'ability','attackId'=>'dance','weaponAttackId'=>'chain','statId'=>'character-stat-force','useJoker'=>true,'opposed'=>true];
$r=championCommand($db,'token.attack',$args);championNative($r->status===200,'Required weapon AoE starts: '.json_encode($r->body));
$r=fixtureApproveDefenseGate($db,$r,true);
championNative($r->body['attack']['status']==='awaiting-opposition','One opposition awaited');
$attack=championStoredAttack($db,$r->body['attack']['id']);championNative(($attack['damageRepetitions']??0)===2,'Two damage rolls persisted before opposition');
championNative(str_contains($attack['damageFormula'],'+30')&&str_contains($attack['damageFormula'],'+15'),'Current weapon and offensive buffs combined');
$defend=['requestId'=>'champion-opposition-'.randomToken(16),'attackId'=>$attack['id'],'decision'=>'skip'];
$result=championCommand($db,'token.attack.oppose',$defend,true);championNative($result->status===200,'PHP opposition continuation: '.json_encode($result->body));
championNative(count(championStoredAttack($db,$attack['id'])['damage']['strikes']??[])===2,'Both separate damage rolls after one skipped defense');
$hp=$db->payload('token:scene-one:token-monster')['hp'];
$replay=championCommand($db,'token.attack.oppose',$defend,true);championNative($replay->status===200&&$db->payload('token:scene-one:token-monster')['hp']===$hp,'Opposition receipt cannot repeat damage');
championNative(count(array_filter($db->payload('devices')['timedConditions']??[],static fn($e)=>($e['label']??'')==='Restreint'))===1,'One timed restriction on hit');
$r=$use($circle['id']);championNative($r->status===200,'Moving aura activates via authority: '.json_encode($r->body));
$ally=[...$db->payload('character:character-player'),'id'=>'character-ally','name'=>'Allié fictif','ownerPlayerId'=>'account-player','abilities'=>[],'fatigue'=>['current'=>0,'max'=>150],'weaponText'=>'Arme 1d2+3','weaponAttacks'=>[['id'=>'ally-weapon','formula'=>'1d2+3','damageType'=>'physical','reach'=>'ranged']]];
$db->put('character:character-ally',$ally);$db->put('token:scene-one:token-ally',['id'=>'token-ally','characterId'=>'character-ally','name'=>'Allié fictif','controllerPlayerId'=>'account-player','followCharacter'=>true,'hp'=>100,'maxHp'=>100,'x'=>40,'y'=>50,'size'=>40,'layerId'=>'ground']);
$index=$db->payload('token-index:scene-one');$index['order'][]='token-ally';$db->put('token-index:scene-one',$index);
$roster=$db->payload('roster');$roster['characterOrder'][]='character-ally';$db->put('roster',$roster);
$allyArgs=['requestId'=>'champion-ally-'.randomToken(16),'sceneId'=>'scene-one','sourceTokenId'=>'token-ally','targetTokenId'=>'token-monster','attackKind'=>'weapon','attackId'=>'ally-weapon','statId'=>'character-stat-force','useJoker'=>true];
$r=championCommand($db,'token.attack',$allyArgs);championNative($r->status===200,'Ally attack with aura resolves: '.json_encode($r->body));championNative(championStoredAttack($db,$r->body['attack']['id'])['damageFormula']==='1d2+3+10','Ally receives only area offensive bonus');
$t=$db->payload('token:scene-one:token-ally');$t['x']=95;$db->put('token:scene-one:token-ally',$t);
$allyArgs['requestId']='champion-outside-'.randomToken(16);$r=championCommand($db,'token.attack',$allyArgs);championNative($r->status===200&&championStoredAttack($db,$r->body['attack']['id'])['damageFormula']==='1d2+3','Leaving removes bonus in authoritative PHP path');
$echo=$circle;$echo['id']='echo-circle';$echo['restRecharge']='none';$echo['usesPerRest']=1;$echo['attackEnhancement']=['mode'=>'echo','formula'=>'2','allyFormula'=>'2','damageType'=>'magical','turns'=>5,'aura'=>$circle['attackEnhancement']['aura']];
$c=$db->payload('character:character-player');$c['abilities'][]=normalizeOnlineAbilities([$echo])[0];$db->put('character:character-player',$c);$r=$use($echo['id']);championNative($r->status===200,'Echo aura uses existing activation command');
$t=$db->payload('token:scene-one:token-ally');$t['x']=40;$db->put('token:scene-one:token-ally',$t);$allyArgs['requestId']='echo-inside-'.randomToken(16);$r=championCommand($db,'token.attack',$allyArgs);
$id=$r->body['attack']['id']??'';championNative($r->status===200&&count(array_filter($db->payload('activity')['attackReceipts']??[],static fn($entry)=>($entry['attack']['echoParentId']??'')===$id))===1,'Aura echo applies on ally attack inside');
$t['x']=95;$db->put('token:scene-one:token-ally',$t);$allyArgs['requestId']='echo-outside-'.randomToken(16);$r=championCommand($db,'token.attack',$allyArgs);$id=$r->body['attack']['id']??'';
championNative($r->status===200&&count(array_filter($db->payload('activity')['attackReceipts']??[],static fn($entry)=>($entry['attack']['echoParentId']??'')===$id))===0,'Aura echo immediately disappears outside');
restore_error_handler();
echo json_encode(['ok'=>true,'nativeChecks'=>$nativeChecks,'productionAccess'=>false,'scope'=>'PHP commands and persistent receipts on isolated memory authority'],JSON_UNESCAPED_UNICODE).PHP_EOL;

