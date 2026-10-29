<?php
declare(strict_types=1);
define('XAR_CONTACT_FIXTURE_ONLY',true);require __DIR__.'/melee-contact-variants.php';
$specialChecks=0;
const BRUTE='ability-db5723cf-d4e4-4518-8b1f-52a0ca8967ee';
function bruteFixture(int $intelligence=100):MemoryConnection {
 $db=contactFixture();$c=$db->payload('character:'.CONTACT_CHARACTER);$c['abilities']=[['id'=>BRUTE,'name'=>'Nom de recette','effect'=>'damage','formula'=>'1','fatigueCost'=>5]];$c['stats']['intelligence']=$intelligence;$db->put('character:'.CONTACT_CHARACTER,$c);
 $db->put('token:scene-one:token-player',synchronizeOnlineCharacterToken($db->payload('token:scene-one:token-player'),$c));$target=$db->payload('token:scene-one:token-monster');$target['hp']=$target['maxHp']=1000;$db->put('token:scene-one:token-monster',$target);return $db;
}
function bruteCase(string $status,int $intelligence=100,bool $wall=false):array {
 for($i=0;$i<100;$i++){$db=bruteFixture($intelligence);if($wall){$map=$db->payload('map:scene-one');$map['walls']=bruteWall();$db->put('map:scene-one',$map);}
  $args=['requestId'=>'brute-'.randomToken(20),'statId'=>'character-stat-intelligence'];$r=contactAttack($db,'ability',BRUTE,$args);
  if($r->status!==200)throw new RuntimeException(json_encode($r->body));if(($r->body['attack']['status']??'')===$status)return [$db,$r,$args];
 }throw new RuntimeException('No ordinary brute case '.$status);
}
function bruteWall():array {
 $wall=emptyApplicationWallState(1000,1000);$bits=str_repeat("\0",(int)ceil($wall['width']*$wall['height']/8));
 for($y=0;$y<$wall['height'];$y++)for($x=(int)floor($wall['width']*.55);$x<(int)ceil($wall['width']*.57);$x++){$index=$y*$wall['width']+$x;$byte=intdiv($index,8);$bits[$byte]=chr(ord($bits[$byte])|(1<<($index%8)));}
 $wall['mask']=rtrim(strtr(base64_encode($bits),'+/','-_'),'=');return $wall;
}
$config=normalizeOnlineAbilities([['id'=>BRUTE,'name'=>'Nouveau nom','effect'=>'damage','formula'=>'1','fatigueCost'=>5]])[0];
specialCheck($config['manaCost']===10&&$config['fatigueCost']===5&&$config['cooldownRounds']===3&&$config['usesPerRest']===2&&$config['restRecharge']==='short','Costs, cooldown and rest quota');
specialCheck($config['castingStatId']==='character-stat-intelligence'&&$config['castingChoices']===['character-stat-intelligence']&&!empty($config['reducedFailureCooldown']),'Intel and reduced failure');
foreach([1,10,11,20,21,30,31,40]as$raw){
 $calls=0;$result=onlineRollAttackDamage([...$config,'damageFormula'=>$config['formula']],['magicArmor'=>100],static function($formula)use($raw,&$calls){$calls++;return evaluateEffectFormula($formula,static fn($sides)=>$raw);});
 specialCheck($calls===1&&$result['damage']['rawDamage']===$raw+10&&$result['damage']['knockbackRoll']['requestedCells']===(int)ceil($raw/10)*2,'One actual d40 and correct band '.$raw);
}
[$db,$r,$args]=bruteCase('applied');$activity=$db->payload('activity');$stored=$activity['attackReceipts'][0]['attack'];$roll=$stored['damage']['knockbackRoll'];$target=$db->payload('token:scene-one:token-monster');
specialCheck($stored['cast']['statId']==='character-stat-intelligence','Intel cast in real command');
specialCheck(abs($target['x']-(44+$stored['knockbackApplied']['movedCells']*5))<1e-6&&$stored['knockbackApplied']['requestedCells']===(int)ceil($roll['raw']/10)*2,'Authoritative displacement');
specialCheck($db->payload('character:'.CONTACT_CHARACTER)['resources']['mana']===90,'One mana payment');
$again=contactAttack($db,'ability',BRUTE,$args);specialCheck(!empty($again->body['deduplicated'])&&$db->payload('token:scene-one:token-monster')['x']===$target['x'],'No repeated movement on replay');
$action=$db->payload('activity')['combatActions'][0];$undo=runCommand($db,'action.undo',['requestId'=>'brute-undo-00000001','actionId'=>$action['actionIds'][0]],true,'account-gm');
specialCheck($undo->status===200&&$db->payload('token:scene-one:token-monster')['x']==44&&$db->payload('token:scene-one:token-monster')['hp']===1000,'Complete undo restores position and HP');
specialCheck($db->payload('character:'.CONTACT_CHARACTER)['resources']['mana']===100&&$db->payload('character:'.CONTACT_CHARACTER)['fatigue']['current']===0,'Complete undo restores costs');
[$db,$r]=bruteCase('missed',1);specialCheck($db->payload('character:'.CONTACT_CHARACTER)['resources']['mana']===90&&$db->payload('character:'.CONTACT_CHARACTER)['fatigue']['current']===0,'Reduced failure retains mana but refunds fatigue');
specialCheck($db->payload('token:scene-one:token-monster')['x']==44&&$db->payload('token:scene-one:token-monster')['hp']===1000,'Failed Intel has no displacement or damage');
$db=bruteFixture();$target=$db->payload('token:scene-one:token-monster');$target['x']=34;$db->put('token:scene-one:token-monster',$target);
$r=contactAttack($db,'ability',BRUTE,['statId'=>'character-stat-intelligence']);specialCheck($r->status===409&&$db->payload('character:'.CONTACT_CHARACTER)['resources']['mana']===100,'Melee rejected before payment');
[$db,$r]=bruteCase('applied',100,true);$stored=$db->payload('activity')['attackReceipts'][0]['attack'];specialCheck(isset($stored['knockbackApplied']['impact']),'Wall stops displacement and records impact');
specialCheck((bool)array_filter($db->payload('activity')['abilityCueEvents']??[],static fn($e)=>($e['completionCue']['spell']['presetId']??'')==='compact-force-wave'),'Wall impact event is emitted');
$map=['background'=>'fixture','naturalWidth'=>1000,'naturalHeight'=>1000,'gridSize'=>50,'activeLayerId'=>'ground','tokens'=>[['id'=>'source','x'=>10,'y'=>50,'size'=>40,'layerId'=>'ground'],['id'=>'target','x'=>40,'y'=>50,'size'=>40,'layerId'=>'ground'],['id'=>'occupant','x'=>56,'y'=>50,'size'=>40,'layerId'=>'ground','hidden'=>true]]];
$a=['id'=>'pure-brute','sceneId'=>'scene','sourceTokenId'=>'source','targetTokenId'=>'target','status'=>'applied','knockback'=>$config['knockback'],'damage'=>['knockbackRoll'=>['raw'=>40,'dieSides'=>40]]];$p=applicationPlanAttackKnockback($a,[...$map,'grid'=>false]);$on=applicationPlanAttackKnockback($a,[...$map,'grid'=>true]);
specialCheck($p===$on&&$p['movedCells']==8,'Grid display does not affect displacement or side step');$side=array_values(array_filter($p['moves'],static fn($m)=>$m['tokenId']==='occupant'))[0];specialCheck($side['to']['x']==56&&abs($side['to']['y']-50)>=5,'The occupant already in place moves sideways from its own position');
$map['fog']=['version'=>1,'enabled'=>true,'width'=>64,'height'=>64,'mask'=>rtrim(strtr(base64_encode(str_repeat(chr(255),512)),'+/','-_'),'=')];specialCheck(applicationPlanAttackKnockback($a,$map)===$p,'Fog does not physically stop the push');
$map['tokens'][2]['immovable']=true;$stopped=applicationPlanAttackKnockback($a,$map);specialCheck($stopped['movedCells']<8&&!array_filter($stopped['moves'],static fn($m)=>$m['tokenId']==='occupant'),'An immovable occupant stops the push without overlap');
echo json_encode(['ok'=>true,'checks'=>$specialChecks,'productionAccess'=>false,'sqlExecution'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
