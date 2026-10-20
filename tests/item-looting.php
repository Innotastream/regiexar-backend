<?php
declare(strict_types=1);
require_once __DIR__.'/../api/v1/dynamic-items.php';
$checks=0;
function lootCheck(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function lootDenied(callable $f,int $status=403):void {try{$f();}catch(RuntimeException $e){lootCheck($e->getCode()===$status,$e->getMessage());return;}throw new RuntimeException('Forbidden loot accepted');}
function lootFixture():array {return ['activeSceneId'=>'scene','scenes'=>[['id'=>'scene']],'characters'=>[['id'=>'hero','ownerPlayerId'=>'owner','resources'=>['hp'=>100,'maxHp'=>100]],['id'=>'receiver','ownerPlayerId'=>'other']],
 'map'=>['activeLayerId'=>'ground','naturalWidth'=>1000,'naturalHeight'=>1000,'gridSize'=>50,'tokens'=>[['id'=>'hero-token','characterId'=>'hero','ownedByYou'=>true,'x'=>50,'y'=>50,'size'=>40],['id'=>'corpse','x'=>59,'y'=>50,'size'=>40,'hp'=>-1,'maxHp'=>100]]],
 'items'=>['version'=>1,'templates'=>[['id'=>'model','kind'=>'object','name'=>'Butin','description'=>'Description publique']],'instances'=>[['id'=>'copy','templateId'=>'model','revision'=>1,'description'=>'Note privée de l’exemplaire','health'=>['current'=>12,'max'=>30],'location'=>['kind'=>'inventory','characterId'=>'unit_corpse']]]]];}
$owner=['id'=>'owner','role'=>'player'];$gm=['id'=>'gm','role'=>'gm'];$args=['itemId'=>'copy','expectedItemRevision'=>1,'characterId'=>'hero','tokenId'=>'hero-token','targetTokenId'=>'corpse','sceneId'=>'scene'];
$act=static fn($s,$a=null,$context=[])=>applyDynamicItemCommand($s,$owner,'item.loot',$a??$args,$context);
$view=static fn($s,$identity=null,$visible=null)=>publicItemStore($s['items'],$s['characters'],$identity??$owner,['sceneId'=>'scene','layerId'=>$s['map']['activeLayerId']??'ground','map'=>$s['map'],'tokens'=>$s['map']['tokens'],'paused'=>$s['tacticalSync']['paused']??false],$visible??static fn($p)=>true);
$state=lootFixture();$before=$state;$taken=$act($state);
lootCheck(count($taken['items']['instances'])===1&&$taken['result']['item']['id']==='copy'&&$taken['result']['item']['revision']===2&&$taken['result']['item']['location']['characterId']==='hero','Loot moves one original instance');
lootCheck($taken['result']['item']['health']===['current'=>12,'max'=>30]&&$taken['result']['item']['description']===$state['items']['instances'][0]['description'],'Properties preserved');
lootCheck($state===$before,'Previous state preserved');lootDenied(fn()=>$act([...$state,'items'=>$taken['items']]),409);
$diagonal=$state;$diagonal['map']['tokens'][1]['y']=59;lootCheck(itemLootAccess($diagonal,'corpse','hero-token')==='','Diagonal counts one cell from edges');
$diagonal['map']['tokens'][1]['x']=59.001;lootDenied(fn()=>$act($diagonal));
$large=$state;$large['map']['tokens'][1]=[...$large['map']['tokens'][1],'size'=>240,'x'=>69,'y'=>69];$large['map']['zoom']=4;$large['map']['gridVisible']=false;lootCheck($act($large)['result']['item']['location']['characterId']==='hero','Large footprints, zoom and hidden grid keep reach');
foreach(['living','ko','hidden','silhouette','floor','sourceHidden','sourceKO','far','invalidGrid','paused'] as $why){
 $s=$state;
 if($why==='living')$s['map']['tokens'][1]['hp']=1;
 if($why==='ko')$s['map']['tokens'][1]['hp']=0;
 if($why==='hidden')$s['map']['tokens'][1]['hidden']=true;
 if($why==='silhouette')$s['map']['tokens'][1]['dimSilhouette']=true;
 if($why==='floor')$s['map']['tokens'][1]['layerId']='upper';
 if($why==='sourceHidden')$s['map']['tokens'][0]['hidden']=true;
 if($why==='sourceKO')$s['characters'][0]['resources']['hp']=0;
 if($why==='far')$s['map']['tokens'][1]['x']=80;
 if($why==='invalidGrid')$s['map']['gridSize']=0;
 if($why==='paused')$s['tacticalSync']['paused']=true;
 lootDenied(fn()=>$act($s),$why==='sourceHidden'?409:($why==='paused'?423:403));lootCheck($view($s)['instances']===[],'Private inventory removed: '.$why);
}
foreach([0,-25,-25.001] as $hp){$s=$state;$s['map']['tokens'][1]['controllerPlayerId']='other';$s['map']['tokens'][1]['hp']=$hp;lootCheck((itemTokenHealth($s,$s['map']['tokens'][1])==='dead')===($hp< -25),'Player threshold differs from KO');}
$s=$state;$s['map']['tokens'][1]['hp']=100;$s['map']['tokens'][1]['healthOverride']='dead';lootCheck(itemTokenHealth($s,$s['map']['tokens'][1])==='dead','Manual death');
$s['map']['tokens'][1]['healthOverride']=null;$s['map']['tokens'][1]['conditions']=['Mort'];lootCheck(itemTokenHealth($s,$s['map']['tokens'][1])==='normal','Explicit revival wins over legacy label');unset($s['map']['tokens'][1]['healthOverride']);lootCheck(itemTokenHealth($s,$s['map']['tokens'][1])==='dead','Legacy death uses shared rule');
$linked=$state;$linked['map']['tokens'][1]['characterId']='hero';lootCheck(itemTokenHealth($linked,$linked['map']['tokens'][1])==='normal','Linked token uses actual sheet HP');$linked['map']['tokens'][1]['followCharacter']=false;lootCheck(itemTokenHealth($linked,$linked['map']['tokens'][1])==='dead','Independent clone keeps its own HP');
foreach(['bound','pickup','device'] as $why){$s=$state;if($why==='bound')$s['items']['templates'][0]['permissions']=['boundToCarrier'=>true];if($why==='pickup')$s['items']['templates'][0]['permissions']=['pickup'=>false];if($why==='device')$s['devices']=['devices'=>[['itemId'=>'copy']]];lootDenied(fn()=>$act($s));}
lootDenied(fn()=>$act($state,[...$args,'sceneId'=>'elsewhere']),409);lootDenied(fn()=>applyDynamicItemCommand($state,['id'=>'intruder','role'=>'player'],'item.loot',$args));lootDenied(fn()=>$act($state,null,['visible'=>static fn($t)=>$t['id']!=='corpse']));
$viewed=$view($state);lootCheck(count($viewed['instances'])===1&&!isset($viewed['instances'][0]['health'])&&$viewed['templates'][0]['description']==='Description publique','Nearby loot readable without private HP');
$secret=$state;$secret['items']['templates'][0]['permissions']=['read'=>false];$secret['items']['instances'][0]['equipment']=['version'=>1,'kind'=>'weapon','formula'=>'1d50+1','hands'=>1,'damageType'=>'physical','reach'=>'melee','projectile'=>false];$secret['items']['equipmentLoadouts']=['unit_corpse'=>['sets'=>[],'equippedItemIds'=>['copy'],'activeSetId'=>'']];$hidden=$view($secret);
lootCheck(!isset($hidden['templates'][0]['description'])&&!isset($hidden['instances'][0]['description'])&&!isset($hidden['instances'][0]['equipment'])&&!isset($hidden['equipmentLoadouts']['unit_corpse']),'Unreadable notes, equipment overrides and loadout remain private');
$stranger=$state;$stranger['map']['tokens'][0]['ownedByYou']=false;lootCheck($view($stranger,['id'=>'stranger'])['instances']===[],'No owned source means no inventory');lootCheck($view($state,$owner,static fn($p)=>$p['id']!=='corpse')['instances']===[],'Fog hides loot');
$clone=$state;$clone['characters']=[];$clone['map']['tokens'][0]=[...$clone['map']['tokens'][0],'characterId'=>null,'followCharacter'=>false,'controllerPlayerId'=>'owner','hp'=>100,'maxHp'=>100];
lootCheck($act($clone,[...$args,'characterId'=>'unit_hero-token'])['result']['item']['location']['characterId']==='unit_hero-token','Controlled clone owns its loot');unset($clone['map']['tokens'][0]['controllerPlayerId']);lootCheck(count($view($clone)['instances'])===1,'Public ownedByYou supports independent token projection without exposing controller id');
foreach([-1,100] as $hp){$s=$state;$s['map']['tokens'][1]=[...$s['map']['tokens'][1],'hp'=>$hp,'x'=>99,'layerId'=>'upper'];$r=applyDynamicItemCommand($s,$gm,'item.transfer',['itemId'=>'copy','expectedItemRevision'=>1,'characterId'=>'unit_corpse','targetCharacterId'=>'receiver']);lootCheck($r['result']['item']['location']['characterId']==='receiver','GM gives directly from living or dead token, no presence or distance required');}
echo "Item looting PHP: $checks checks passed.\n";
