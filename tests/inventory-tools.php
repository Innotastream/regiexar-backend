<?php
declare(strict_types=1);
require_once __DIR__.'/../api/v1/dynamic-items.php';
require_once __DIR__.'/../api/v1/device-state.php';
require_once __DIR__.'/../api/v1/online.php';
$checks=0;
function invCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function invDenied(callable $action,int $status=0):void{try{$action();}catch(RuntimeException $e){invCheck($status===0||$e->getCode()===$status,'Status: '.$e->getMessage());return;}throw new RuntimeException('Forbidden inventory action accepted');}
function invFixture():array{return ['activeSceneId'=>'scene','characters'=>[['id'=>'a','ownerPlayerId'=>'owner','abilities'=>[['id'=>'ability','device'=>['kind'=>'launcher','ammoTemplateId'=>'model']]]],['id'=>'b','ownerPlayerId'=>'other']],
 'map'=>['activeLayerId'=>'ground','gridSize'=>50,'naturalWidth'=>1000,'naturalHeight'=>500,'tokens'=>[['id'=>'ta','characterId'=>'a','ownedByYou'=>true,'x'=>50,'y'=>50],['id'=>'tb','characterId'=>'b','x'=>55,'y'=>50],['id'=>'clone','characterId'=>'a','followCharacter'=>false,'controllerPlayerId'=>'owner','abilities'=>[['id'=>'ability','device'=>['kind'=>'launcher','ammoTemplateId'=>'model']]],'x'=>52,'y'=>50]]],
 'items'=>['version'=>1,'templates'=>[['id'=>'model','name'=>'Carreau','kind'=>'object','sourceAbilityId'=>'ability','creatorCharacterId'=>'a']], 'instances'=>array_map(static fn($i)=>['id'=>'copy-'.$i,'templateId'=>'model','revision'=>1,'health'=>['current'=>12,'max'=>20],'description'=>'Copie '.$i,'location'=>['kind'=>'inventory','characterId'=>'a']],range(0,4))]];}
$owner=['id'=>'owner','role'=>'player'];$gm=['id'=>'gm','role'=>'gm'];$args=['itemId'=>'copy-0','itemIds'=>['copy-0','copy-1','copy-2'],'expectedItemRevisions'=>['copy-0'=>1,'copy-1'=>1,'copy-2'=>1],'characterId'=>'a','targetCharacterId'=>'b','sceneId'=>'scene','tokenId'=>'ta','targetTokenId'=>'tb'];
$s=invFixture();$before=$s;$r=applyDynamicItemCommand($s,$owner,'item.transfer',$args);
invCheck($s===$before,'No previous-state mutation');invCheck(count($r['result']['items'])===3,'Exact batch quantity');
foreach($r['items']['instances'] as $i=>$item){invCheck($item===[...normalizeItemStore($s['items'])['instances'][$i],'revision'=>$i<3?2:1,'location'=>['kind'=>'inventory','characterId'=>$i<3?'b':'a']],'Original identity and properties');}
invCheck(!isset(publicItemCommandResult($r['result'])['items'][0]['health']),'Batch result hides GM durability');
foreach(['revision','missing','otherOwner','consumed','bound','forbidden','pending','duplicates','extraRevision','missingRevision','first','empty','fraction','far','paused'] as $why){
 $s=invFixture();$a=$args;
 if($why==='revision')$s['items']['instances'][2]['revision']=2;if($why==='missing')array_splice($s['items']['instances'],2,1);if($why==='otherOwner')$s['items']['instances'][2]['location']['characterId']='b';if($why==='consumed')$s['items']['instances'][2]['location']['kind']='consumed';
 if(in_array($why,['bound','forbidden'],true)){$s['items']['templates'][]=['id'=>'reserved','name'=>'Réservé','kind'=>'object','permissions'=>$why==='bound'?['boundToCarrier'=>true]:['transfer'=>false]];$s['items']['instances'][2]['templateId']='reserved';}
 if($why==='pending')$s['items']['pendingUses']=[['id'=>'wait','itemId'=>'copy-2','itemRevision'=>1,'sceneId'=>'scene','layerId'=>'ground','name'=>'Piège','phase'=>'opposition','targets'=>[['tokenId'=>'ta','status'=>'pending']]]];
 if($why==='duplicates')$a['itemIds'][2]='copy-0';if($why==='extraRevision')$a['expectedItemRevisions']['other']=1;if($why==='missingRevision')unset($a['expectedItemRevisions']['copy-2']);if($why==='first')$a['itemId']='copy-4';if($why==='empty')$a['itemIds']=[];if($why==='fraction')$a['expectedItemRevisions']['copy-2']=1.5;if($why==='far')$s['map']['tokens'][1]['x']=99;if($why==='paused')$s['tacticalSync']=['paused'=>true];
 $before=$s;invDenied(fn()=>applyDynamicItemCommand($s,$owner,'item.transfer',$a));invCheck($s===$before,'Rejected whole batch: '.$why);
}
$s=invFixture();$s['devices']=['devices'=>[['itemId'=>'copy-2','definition'=>['kind'=>'launcher']]]];foreach([$owner,$gm] as $actor)invDenied(fn()=>assertPhysicalDeviceItemAction($s,$actor,'item.transfer',$args),403);
$s=invFixture();foreach($s['items']['instances'] as &$item)$item['location']['characterId']='unit_clone';unset($item);
$r=applyDynamicItemCommand($s,$owner,'item.transfer',[...$args,'characterId'=>'unit_clone','tokenId'=>'clone']);invCheck($r['result']['items'][2]['location']['characterId']==='b','Clone transfers its own stock');invCheck(in_array('unit_clone',array_column($r['items']['templates'][0]['stockBindings'],'characterId'),true),'Linked clone stock remembered');invCheck(count(array_filter($r['items']['instances'],static fn($i)=>$i['location']['characterId']==='a'))===0,'Model inventory untouched');
$s=invFixture();$instances=normalizeItemStore($s['items'])['instances'];
foreach([[$owner,'a'],[$gm,'a'],[$owner,'unit_clone']] as [$actor,$unit]){$s['items']['inventoryCatalog']['unit_clone']=['model'];$s['items']=applyDynamicItemCommand($s,$actor,'item.favorite.set',['characterId'=>$unit,'templateId'=>'model','enabled'=>true])['items'];}
invCheck($s['items']['instances']===$instances,'Favorite never changes stock');invCheck(count($s['items']['favorites'])===3,'Per-account, per-unit favorites');
$view=publicItemStore($s['items'],$s['characters'],$owner,['sceneId'=>'scene','layerId'=>'ground','map'=>$s['map'],'tokens'=>$s['map']['tokens']],static fn($p)=>true);invCheck(count($view['favorites'])===2,'Only own account and controlled inventories projected');
$s['map']['tokens'][2]['controllerPlayerId']='other';$view=publicItemStore($s['items'],$s['characters'],$owner,['sceneId'=>'scene','layerId'=>'ground','map'=>$s['map'],'tokens'=>$s['map']['tokens']],static fn($p)=>true);invCheck(count($view['favorites'])===1,'Lost clone control removes favorites from projection');
invDenied(fn()=>applyDynamicItemCommand($s,$owner,'item.favorite.set',['characterId'=>'unit_clone','templateId'=>'model','enabled'=>false]),403);
invDenied(fn()=>applyDynamicItemCommand($s,$owner,'item.favorite.set',['characterId'=>'b','templateId'=>'model','enabled'=>true]),403);
$s['items']['instances']=[];$s['items']=applyDynamicItemCommand($s,$owner,'item.favorite.set',['characterId'=>'a','templateId'=>'model','enabled'=>false])['items'];invCheck(count($s['items']['favorites'])===2,'Linked stock at zero still permits removing a favorite');
$fav=['accountId'=>'owner','unitId'=>'a','templateId'=>'model'];invDenied(fn()=>normalizeInventoryFavorites([$fav,$fav]),400);invCheck(normalizeInventoryFavorites([$fav],[])===[],'Deleting a model prunes its favorites');
$s=invFixture();$s['items']['favorites']=array_map(static fn($i)=>[...$fav,'unitId'=>'unit-'.$i],range(0,199));invDenied(fn()=>applyDynamicItemCommand($s,$owner,'item.favorite.set',['characterId'=>'a','templateId'=>'model','enabled'=>true]),429);
foreach([['followCharacter'=>false],['independentClone'=>true],['independent'=>true],['usesCharacterSheet'=>false],['rollUsesCharacterSheet'=>false]] as $flags){
 $token=[...invFixture()['map']['tokens'][0],'controllerPlayerId'=>'other',...$flags];$sheet=invFixture()['characters'][0];
 invCheck(onlineEffectiveTokenControllerId($token,['a'=>'owner'])==='other','Independent controller wins over retained model reference');
 invCheck(synchronizeOnlineCharacterToken($token,$sheet)===$token,'Sheet synchronization never restores the model controller on a clone');
 invCheck(!unitUsesCharacterSheet($token),'Independent inventory projection flag');
}
echo 'Inventory tools PHP: '.$checks." checks passed.\n";
