<?php
declare(strict_types=1);
require __DIR__.'/equipment-spirit-creatures.php';
$count=0;
function objectCheck(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);$GLOBALS['count']++;}
function objectFixture(array $active,bool $ground=false):array{
 $c=['id'=>'char','name'=>'Fictive','ownerPlayerId'=>'p','resources'=>['hp'=>60,'maxHp'=>100,'mana'=>40,'maxMana'=>40],'fatigue'=>['current'=>0,'max'=>150],'stats'=>['force'=>60,'agility'=>60],'conditions'=>[],'initiativeBonus'=>3];
 return ['activeSceneId'=>'s','scenes'=>[['id'=>'s']],'characters'=>[$c],'initiative'=>['active'=>true,'combatId'=>'c','turnSerial'=>2,'currentIndex'=>0,'order'=>['source','target']],'map'=>['activeLayerId'=>'ground','naturalWidth'=>1000,'naturalHeight'=>1000,'gridSize'=>50,'grid'=>false,'tokens'=>[
 ['id'=>'source','name'=>'Fictive','characterId'=>'char','followCharacter'=>true,'controllerPlayerId'=>'p','layerId'=>'ground','x'=>10,'y'=>50,'size'=>40,'hp'=>60,'maxHp'=>100,'initiativeBonus'=>3],
 ['id'=>'target','name'=>'Cible','followCharacter'=>false,'controllerPlayerId'=>'q','layerId'=>'ground','x'=>50,'y'=>50,'size'=>40,'hp'=>100,'maxHp'=>100,'armor'=>50,'armorCategory'=>'percent','magicArmor'=>0,'magicArmorCategory'=>'none','stats'=>[['id'=>'character-stat-agility','label'=>'Agilité','value'=>'60']],'conditions'=>[]]
 ]],'items'=>['version'=>1,'templates'=>[['id'=>'model','name'=>'Objet fictif','kind'=>'object','active'=>$active]],'instances'=>[['id'=>'copy','templateId'=>'model','revision'=>1,'location'=>$ground?['kind'=>'ground','sceneId'=>'s','layerId'=>'ground','x'=>50,'y'=>50]:['kind'=>'inventory','characterId'=>'char']]]],'devices'=>['version'=>1,'devices'=>[],'craftRequests'=>[],'pendingRolls'=>[],'timedConditions'=>[],'receipts'=>[]],'attackEnhancements'=>[],'damageOverTime'=>[]];
}
function objectAction(array &$state,string $command,array $args=[],?array $actor=null,int $die=70):array{
 static $n=0;$id=static function($p)use(&$n){return $p.'-'.(++$n);};
 $r=applyDynamicItemCommand($state,$actor??['id'=>'gm','role'=>'gm'],$command,['sceneId'=>'s','itemId'=>'copy','expectedItemRevision'=>1,'characterId'=>'char','tokenId'=>'source','targetTokenId'=>'target','opposed'=>false,...$args],['id'=>$id,'rollFormula'=>static fn($f,$l,$name)=>['id'=>$id('roll'),...evaluateEffectFormula($f,static fn()=> $die),'formula'=>$f,'label'=>$l,'characterName'=>$name]]);
 $state=$r['activeState']??$state;$state['items']=$r['items'];return $r['result'];
}
$damage=['kind'=>'damage','components'=>[['type'=>'physical','formula'=>'20'],['type'=>'magical','formula'=>'10'],['type'=>'ignore','formula'=>'5']]];
$single=['mode'=>'single','rangeCells'=>15];
$s=objectFixture(['version'=>1,'effects'=>[$damage,['kind'=>'dot','label'=>'Poison lent','formula'=>'1d8','family'=>'poison','damageType'=>'ignore','turns'=>3],['kind'=>'condition','rider'=>['label'=>'Immobilisé','turns'=>2,'blocksMovement'=>true]]],'targeting'=>$single]);
$r=objectAction($s,'item.activate');objectCheck($r['damage']===25&&$s['map']['tokens'][1]['hp']===75,'Mixed armor damage on actual independent unit');objectCheck($s['characters'][0]['resources']['hp']===60,'Owner untouched');objectCheck($s['damageOverTime'][0]['remainingTurns']===3&&$s['damageOverTime'][0]['lastTurnSerial']===2,'DOT starts later');objectCheck($s['devices']['timedConditions'][0]['remainingTurns']===2,'Timed condition uses existing lifecycle');objectCheck($s['items']['instances'][0]['location']['kind']==='consumed','One actual item consumed');
foreach([true,false] as $destroy){
 $s=objectFixture(['version'=>1,'effects'=>[$damage],'targeting'=>$single,'trap'=>['onPassage'=>true,'destroyAfterTrigger'=>$destroy,'radiusCells'=>0]],true);
 $s['initiative']['active']=false;objectCheck(queueItemTrapPassages($s,$s['map']['tokens'][0],['x'=>10,'y'=>50],['x'=>90,'y'=>50],null,static fn($p)=>$p.'-unique')===[],'No automatic trigger in free mode, despite initiative roster');
 $s['initiative']['active']=true;objectCheck(count(queueItemTrapPassages($s,$s['map']['tokens'][0],['x'=>10,'y'=>50],['x'=>90,'y'=>50],null,static fn($p)=>$p.'-unique'))===1,'Crossing queues a GM proposal');objectCheck($s['items']['instances'][0]['location']['kind']==='ground','Not destroyed before GM decision');
 $use=$s['items']['pendingUses'][0]['id'];objectAction($s,'item.trap.resolve',['useId'=>$use,'opposed'=>true]);objectCheck($s['map']['tokens'][0]['hp']===60,'No damage before opposition');
 objectAction($s,'item.opposition',['useId'=>$use,'targetTokenId'=>'source','statId'=>'character-stat-agility'],['id'=>'p','role'=>'player'],50);
 objectCheck($s['items']['instances'][0]['location']['kind']===($destroy?'destroyed':'ground'),'Destroy checkbox honored after resolution');objectCheck(count($s['items']['pendingUses'])===0,'Pending activation retired');
}
$s=objectFixture(['version'=>1,'effects'=>[$damage],'targeting'=>$single]);$r=objectAction($s,'item.activate',['opposed'=>true]);$use=$r['pendingUse'];
objectAction($s,'item.opposition',['useId'=>$use,'statId'=>'character-stat-agility'],null,77);objectCheck($s['items']['pendingUses'][0]['targets'][0]['status']==='validation','Critical defense awaits GM');
objectAction($s,'item.opposition.validate',['useId'=>$use,'approve'=>false]);objectCheck($s['items']['pendingUses'][0]['targets'][0]['status']==='pending','Rejected defense can be rolled again');
objectAction($s,'item.opposition',['useId'=>$use,'statId'=>'character-stat-agility'],null,70);objectCheck($s['map']['tokens'][1]['hp']===75,'Failed defense applies damage exactly once');
$s=objectFixture(['version'=>1,'effects'=>[['kind'=>'stats','bonuses'=>['initiativeBonus'=>20],'duration'=>['kind'=>'rounds','turns'=>1]]]]);
objectAction($s,'item.activate');objectCheck(applicationEffectiveInitiativeBonus($s['map']['tokens'][0],$s,'s')===23,'Initiative bonus applies');objectCheck($s['characters'][0]['initiativeBonus']===3,'Base initiative unchanged');
$s['attackEnhancements']=applicationAdvanceAttackEnhancements($s['attackEnhancements'],'s',[],[],true);objectCheck(applicationEffectiveInitiativeBonus($s['map']['tokens'][0],$s,'s')===3,'Initiative bonus expires');
$s=objectFixture(['version'=>1,'effects'=>[['kind'=>'summon','templateId'=>'model-smoke','nonCombatant'=>true,'controllable'=>false]]]);$s['tokenLibrary']=[['id'=>'model-smoke','name'=>'Modèle arbitraire','image'=>'/media/abcdefghijklmnopqrstuvwx','hp'=>0,'maxHp'=>0,'size'=>150]];
$r=objectAction($s,'item.activate',['summonPoint'=>['x'=>40,'y'=>40]]);$summon=end($s['map']['tokens']);objectCheck($summon['image']===$s['tokenLibrary'][0]['image']&&$summon['artworkOnly']===true,'PNG remains intact and unframed');objectCheck($summon['nonCombatant']&&$summon['immovable']&&$summon['initiative']===null,'Non-conscious fixed template never enters combat');
$db=gearFixture();$active=['version'=>1,'effects'=>[['kind'=>'damage','components'=>[['type'=>'ignore','formula'=>'5']]]],'targeting'=>['mode'=>'single','rangeCells'=>15]];
objectCheck(gearCommand($db,'item.template.create',['template'=>['id'=>'native-offensive','name'=>'Objet natif','kind'=>'object','active'=>$active]],true)->status===200,'Native model creation');
objectCheck(gearCommand($db,'item.spawn',['templateId'=>'native-offensive','itemId'=>'offensive-one','location'=>['kind'=>'inventory','characterId'=>'character-player']],true)->status===200,'Native spawn');
$before=$db->payload('token:scene-one:token-monster')['hp'];$args=['itemId'=>'offensive-one','expectedItemRevision'=>1,'tokenId'=>'token-player','targetTokenId'=>'token-monster','opposed'=>false,'requestId'=>'item-offensive-native-unique-01'];
$r=gearCommand($db,'item.activate',$args);objectCheck($r->status===200,'Native activation: '.json_encode($r->body));objectCheck($db->payload('token:scene-one:token-monster')['hp']===$before-5,'Native flush changes target HP');objectCheck(!empty(gearCommand($db,'item.activate',$args)->body['deduplicated']),'Persistent receipt avoids duplicate damage');objectCheck($db->payload('token:scene-one:token-monster')['hp']===$before-5,'Replay never damages twice');

$s=objectFixture(['version'=>1,'effects'=>[$damage],'targeting'=>$single]);$s['map']['tokens'][1]['hp']=5;objectAction($s,'item.activate');objectCheck($s['map']['tokens'][1]['hp']===-20,'Object damage preserves negative HP');
$s=objectFixture(['version'=>1,'effects'=>[['kind'=>'stats','bonuses'=>['initiativeBonus'=>20],'duration'=>['kind'=>'rounds','turns'=>2]],['kind'=>'stats','bonuses'=>['agility'=>10],'duration'=>['kind'=>'rounds','turns'=>3]]],'reusable'=>true]);objectAction($s,'item.activate');objectAction($s,'item.activate',['expectedItemRevision'=>2]);objectCheck(count($s['attackEnhancements'])===2&&applicationEffectiveInitiativeBonus($s['map']['tokens'][0],$s,'s')===23,'Two bonus rows coexist without accumulating on reuse');
foreach([['mode'=>'single','rangeCells'=>'15'],['mode'=>'area','rangeCells'=>15,'radiusCells'=>'2']] as $invalid){try{normalizeItemTargeting($invalid);objectCheck(false,'Numeric string accepted');}catch(RuntimeException){objectCheck(true,'Same numeric targeting contract as JS');}}
$formula=onlineRemainingDotFormula('1d2+2x4',3);$calls=0;objectCheck(evaluateEffectFormula($formula,static function()use(&$calls){return ++$calls%2?1:2;})['total']===40,'DOT remainder keeps independent rolls and left to right grouping');
$db=gearFixture();$db->mediaObjects['abcdefghijklmnopqrstuvwx']=['pending_delete_at'=>null];$library=$db->payload('library');$library['tokenLibrary']=[['id'=>'item-smoke-model','name'=>'Pion translucide fictif','image'=>'/media/abcdefghijklmnopqrstuvwx','hp'=>0,'maxHp'=>0,'size'=>150,'stats'=>[],'abilities'=>[]]];$db->put('library',$library);
$config=['version'=>1,'effects'=>[['kind'=>'summon','templateId'=>'item-smoke-model','controllable'=>false,'nonCombatant'=>true]]];
objectCheck(gearCommand($db,'item.template.create',['template'=>['id'=>'native-summon','name'=>'Smoke fictive','kind'=>'object','active'=>$config]],true)->status===200,'Native summon model');
objectCheck(gearCommand($db,'item.spawn',['templateId'=>'native-summon','itemId'=>'smoke-once','location'=>['kind'=>'inventory','characterId'=>'character-player']],true)->status===200,'Native summon item');
$args=['itemId'=>'smoke-once','expectedItemRevision'=>1,'tokenId'=>'token-player','summonPoint'=>['x'=>1,'y'=>1]];
$domainsBefore=$db->domains;$r=gearCommand($db,'item.activate',$args);objectCheck($r->status===409&&$db->domains===$domainsBefore,'Invalid full footprint refuses without consuming');
$r=gearCommand($db,'item.activate',[...$args,'summonPoint'=>['x'=>40,'y'=>50]]);objectCheck($r->status===200,'Native summon activation '.json_encode($r->body));
$created=$db->payload('token:scene-one:'.$r->body['summonedTokenIds'][0]);objectCheck($created['artworkOnly']&&$created['nonCombatant']&&$created['initiative']===null,'Native flush retains artwork and noncombatant settings');
$public=publicPlayerState(domainsToApplicationState($db->domains,$db->revision),['id'=>'account-player'],[]);$found=array_values(array_filter($public['map']['tokens'],static fn($t)=>$t['id']===$created['id']));objectCheck(($found[0]['artworkOnly']??false)===true,'Native public projection retains alpha rendering mode');


$db=gearFixture();$config=['version'=>1,'effects'=>[['kind'=>'dot','label'=>'Poison fourni par un objet','formula'=>'1d8','damageType'=>'magical','family'=>'poison','turns'=>2,'timing'=>'on-hit','buffTurns'=>5,'trigger'=>'melee-weapon'],['kind'=>'condition','rider'=>['label'=>'Effrayé','turns'=>2],'timing'=>'on-hit','buffTurns'=>5,'trigger'=>'melee-weapon']]];
objectCheck(gearCommand($db,'item.template.create',['template'=>['id'=>'native-onhit','name'=>'Enduit fictif','kind'=>'object','active'=>$config]],true)->status===200,'Native on-hit model');
objectCheck(gearCommand($db,'item.spawn',['templateId'=>'native-onhit','itemId'=>'onhit-copy','location'=>['kind'=>'inventory','characterId'=>'character-player']],true)->status===200,'Native on-hit item');
$r=gearCommand($db,'item.activate',['itemId'=>'onhit-copy','expectedItemRevision'=>1,'tokenId'=>'token-player']);objectCheck($r->status===200&&count($db->payload('activity')['attackEnhancements'])===2,'Native on-hit activation '.json_encode($r->body));
$r=gearStrike($db);$dots=$db->payload('activity')['damageOverTime']??[];objectCheck($r->status===200&&count($dots)===1&&$dots[0]['damageType']==='magical'&&$dots[0]['formula']==='1d8','Native base weapon attack applies the configured poison');
objectCheck(in_array('Effrayé',$db->payload('token:scene-one:token-monster')['conditions']??[],true),'Native weapon attack also adds the configured condition');
$s=objectFixture(['version'=>1,'effects'=>[$damage],'targeting'=>$single,'trap'=>['onPassage'=>true,'destroyAfterTrigger'=>false,'radiusCells'=>0]],true);
$s['items']['instances'][]=[...$s['items']['instances'][0],'id'=>'second-trap','location'=>[...$s['items']['instances'][0]['location'],'x'=>60]];
$s['items']['pendingUses']=array_map(static fn($i)=>['id'=>'busy-'.$i,'itemId'=>'busy-item-'.$i],range(1,99));$before=$s;
try{queueItemTrapPassages($s,$s['map']['tokens'][0],['x'=>10,'y'=>50],['x'=>90,'y'=>50],null,static fn($p)=>$p.'-new');objectCheck(false,'Saturated queue accepted');}catch(RuntimeException $e){objectCheck($e->getCode()===429&&$s===$before,'Two simultaneous traps refuse atomically if the queue is full');}

fwrite(STDOUT,json_encode(['ok'=>true,'checks'=>$count,'productionAccess'=>false])."\n");
