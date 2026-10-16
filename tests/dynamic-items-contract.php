<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/v1/dynamic-items.php';

$checks = 0;
function itemCheck(bool $value, string $message): void { global $checks; if (!$value) throw new RuntimeException($message); $checks++; }
function itemRefused(callable $action, int $status): void {
    try { $action(); } catch (RuntimeException $error) { itemCheck($error->getCode() === $status, $error->getMessage()); return; }
    throw new RuntimeException('An invalid item command was accepted');
}
$state = ['activeSceneId' => 'scene', 'scenes' => [['id' => 'scene']], 'tacticalSync' => ['paused' => false],
    'characters' => [['id' => 'hero-a', 'ownerPlayerId' => 'owner-a', 'resources' => ['hp' => -60, 'maxHp' => 100, 'mana' => 5, 'maxMana' => 10], 'fatigue' => ['current' => 5, 'max' => 150]], ['id' => 'hero-b', 'ownerPlayerId' => 'owner-b']],
    'map' => ['activeLayerId' => 'ground', 'naturalWidth' => 1600, 'naturalHeight' => 900, 'tokens' => [['id' => 'token-a', 'characterId' => 'hero-a', 'x' => 50, 'y' => 50, 'size' => 40]]],
    'items' => ['version' => 1, 'templates' => [['id' => 'letter', 'kind' => 'letter', 'name' => 'Lettre', 'document' => ['blocks' => [['type' => 'paragraph', 'text' => 'Secret privé']]]], ['id' => 'potion', 'kind' => 'potion', 'name' => 'Potion', 'potion' => ['hp' => 10, 'mana' => -100, 'fatigue' => 200]]],
    'instances' => [['id' => 'letter-one', 'templateId' => 'letter', 'revision' => 1, 'location' => ['kind' => 'inventory', 'characterId' => 'hero-a']], ['id' => 'potion-one', 'templateId' => 'potion', 'revision' => 1, 'location' => ['kind' => 'inventory', 'characterId' => 'hero-a']]]]];
$state['map']['tokens'][]=['id'=>'token-b','characterId'=>'hero-b','x'=>50,'y'=>50,'layerId'=>'ground'];
$owner = ['id' => 'owner-a', 'role' => 'player'];
itemCheck(itemText("été\nsuite", 2) === 'ét', 'Unicode text stays valid with or without mbstring');
$args = ['itemId' => 'letter-one', 'characterId' => 'hero-a', 'expectedItemRevision' => 1, 'targetCharacterId' => 'hero-b'];
$rangeState=$state;$rangeState['map']['gridSize']=50;$rangeState['map']['naturalWidth']=1000;$rangeState['map']['naturalHeight']=500;
foreach([0,4.999,5] as $cells){
    $near=$rangeState;$near['map']['tokens'][1]['x']=50+$cells*5;
    itemCheck(applyDynamicItemCommand($near,$owner,'item.transfer',$args)['result']['item']['location']['characterId']==='hero-b','Player gift accepts up to five cells');
}
foreach(['far','floor','absent','hidden','invalid','paused','scene','wrong-token'] as $reason){
    $bad=$rangeState;$badArgs=$args;
    if($reason==='far'){$bad['map']['tokens'][1]['x']=75.001;$bad['map']['tokens'][1]['size']=1000;}
    if($reason==='floor')$bad['map']['tokens'][1]['layerId']='upper';
    if($reason==='absent')array_pop($bad['map']['tokens']);
    if($reason==='hidden')$bad['map']['tokens'][1]['hidden']=true;
    if($reason==='invalid')$bad['map']['gridSize']=0;
    if($reason==='paused')$bad['tacticalSync']['paused']=true;
    if($reason==='scene')$badArgs['sceneId']='elsewhere';
    if($reason==='wrong-token')$badArgs['targetTokenId']='token-a';
    itemRefused(fn()=>applyDynamicItemCommand($bad,$owner,'item.transfer',$badArgs),$reason==='paused'?423:409);
    itemCheck(applyDynamicItemCommand($bad,['id'=>'gm','role'=>'gm'],'item.transfer',$badArgs)['result']['item']['location']['characterId']==='hero-b','GM may give regardless of proximity or token presence');
}
$diagonal=$rangeState;$diagonal['map']['tokens'][1]['x']=65;$diagonal['map']['tokens'][1]['y']=90;
itemCheck(itemTransferPair($diagonal,'hero-a','hero-b')!==null,'Three by four cells is exactly five');
$diagonal['map']['tokens'][1]['y']=90.001;itemCheck(itemTransferPair($diagonal,'hero-a','hero-b')===null,'Outside five cells is refused');
itemRefused(fn() => applyDynamicItemCommand($state, ['id' => 'owner-b', 'role' => 'player'], 'item.transfer', $args), 403);
$given = applyDynamicItemCommand($state, $owner, 'item.transfer', $args);
itemCheck(count($given['items']['instances']) === 2 && $given['items']['instances'][0]['location']['characterId'] === 'hero-b', 'Transfer moves one instance');
itemCheck($state['items']['instances'][0]['location']['characterId'] === 'hero-a', 'Previous snapshot is preserved');
$after = [...$state, 'items' => $given['items']];
itemRefused(fn() => applyDynamicItemCommand($after, $owner, 'item.transfer', $args), 409);
$projection = publicItemStore($given['items'], $state['characters'], $owner, [], fn() => true);
itemCheck(!in_array('letter', array_column($projection['templates'], 'id'), true), 'Previous owner cannot read transferred document');
$receiver = publicItemStore($given['items'], $state['characters'], ['id' => 'owner-b'], [], fn() => true);
itemCheck($receiver['templates'][0]['document']['blocks'][0]['runs'][0]['text'] === 'Secret privé', 'Receiver can read the original document');
$dropArgs = ['itemId' => 'letter-one', 'characterId' => 'hero-a', 'expectedItemRevision' => 1, 'tokenId' => 'token-a', 'sceneId' => 'scene', 'x' => 99, 'y' => 99];
$bound=$state;$bound['items']['templates'][0]['permissions']=['boundToCarrier'=>true,'read'=>false];
itemCheck(itemPermissions($state['items']['templates'][0])['boundToCarrier']===false,'Binding defaults to false on existing objects');
itemRefused(fn()=>applyDynamicItemCommand($bound,$owner,'item.drop',$dropArgs),403);
itemRefused(fn()=>applyDynamicItemCommand($bound,$owner,'item.transfer',$args),403);
$boundView=publicItemStore($bound['items'],$bound['characters'],$owner,[],fn()=>true);
itemCheck($boundView['templates'][0]['permissions']['boundToCarrier']&&!isset($boundView['templates'][0]['document']),'Binding is projected without revealing a private document');
itemCheck(applyDynamicItemCommand($bound,['id'=>'gm','role'=>'gm'],'item.transfer',$args)['result']['item']['location']['characterId']==='hero-b','The GM can reassign a bound item');
$boundGround=[...$bound,'items'=>applyDynamicItemCommand($bound,['id'=>'gm','role'=>'gm'],'item.drop',$dropArgs)['items']];
$boundPickup=applyDynamicItemCommand($boundGround,$owner,'item.pickup',[...$dropArgs,'expectedItemRevision'=>2]);
itemCheck($boundPickup['result']['item']['location']['characterId']==='hero-a','Initial pickup follows the independent pickup permission');
itemRefused(fn()=>applyDynamicItemCommand([...$boundGround,'items'=>$boundPickup['items']],$owner,'item.drop',[...$dropArgs,'expectedItemRevision'=>3]),403);
$dropped = applyDynamicItemCommand($state, $owner, 'item.drop', $dropArgs);
itemCheck($dropped['result']['item']['location']['x'] === 50 && $dropped['result']['item']['location']['y'] === 50, 'Drop uses authoritative token coordinates');
$ground = [...$state, 'items' => $dropped['items']];
$visible = publicItemStore($dropped['items'], $state['characters'], ['id' => 'owner-b'], ['sceneId' => 'scene', 'layerId' => 'ground'], fn() => true);
itemCheck(count($visible['instances']) === 1 && !isset($visible['templates'][0]['document']), 'Ground projection hides document content');
itemCheck(publicItemStore($dropped['items'], $state['characters'], ['id' => 'owner-b'], ['sceneId' => 'scene', 'layerId' => 'ground'], fn() => false)['instances'] === [], 'Unseen ground objects stay private');
$pickupArgs = [...$dropArgs, 'expectedItemRevision' => 2];
$picked = applyDynamicItemCommand($ground, $owner, 'item.pickup', $pickupArgs);
itemRefused(fn() => applyDynamicItemCommand([...$ground, 'items' => $picked['items']], $owner, 'item.pickup', $pickupArgs), 409);
itemRefused(fn() => applyDynamicItemCommand([...$ground, 'tacticalSync' => ['paused' => true]], $owner, 'item.pickup', $pickupArgs), 423);
$far = $ground; $far['items']['instances'][0]['location']['x'] = 80;
itemRefused(fn() => applyDynamicItemCommand($far, $owner, 'item.pickup', $pickupArgs), 403);
$potionArgs = ['itemId' => 'potion-one', 'characterId' => 'hero-a', 'expectedItemRevision' => 1];
$consumed = applyDynamicItemCommand($state, $owner, 'item.consume', $potionArgs);
itemCheck($consumed['characterPatch']['resources']['hp'] === -50 && $consumed['characterPatch']['resources']['mana'] === 0 && $consumed['characterPatch']['fatigue']['current'] === 150, 'Potion preserves negative HP and bounds other resources');
itemRefused(fn() => applyDynamicItemCommand([...$state, 'items' => $consumed['items']], $owner, 'item.consume', $potionArgs), 409);
itemRefused(fn() => applyDynamicItemCommand($state, $owner, 'item.template.create', ['template' => ['id' => 'key', 'kind' => 'object', 'name' => 'Clef']]), 403);
itemRefused(fn() => normalizeItemStore([...$state['items'], 'version' => 2]), 409);
itemRefused(fn() => normalizeItemStore([...$state['items'], 'instances' => [...$state['items']['instances'], $state['items']['instances'][0]]]), 400);
itemRefused(fn() => normalizeItemTemplate(['id' => 'bad', 'kind' => 'book', 'name' => 'Livre', 'image' => 'https://other.example/track.png']), 400);
$tracked = $state;
$tracked['characters'][0]['abilities'] = [['id'=>'launcher','device'=>['kind'=>'launcher','ammoTemplateId'=>'bolt']]];
$tracked['items'] = ['version'=>1,'templates'=>[['id'=>'bolt','kind'=>'object','name'=>'Carreau lourd','description'=>'Munition privée','image'=>'']],
    'instances'=>[['id'=>'last-bolt','templateId'=>'bolt','revision'=>1,'location'=>['kind'=>'inventory','characterId'=>'hero-a']]]];
$moved=applyDynamicItemCommand($tracked,$owner,'item.transfer',['itemId'=>'last-bolt','characterId'=>'hero-a','targetCharacterId'=>'hero-b','expectedItemRevision'=>1]);
$zero=publicItemStore($moved['items'],$tracked['characters'],$owner,[],fn()=>false);
itemCheck(count($zero['templates'])===1 && $zero['instances']===[] && $zero['templates'][0]['name']==='Carreau lourd', 'The last transfer preserves a real ability stock at zero without a usable instance');
itemCheck(publicItemStore(['version'=>1,'templates'=>$tracked['items']['templates'],'instances'=>[]],$tracked['characters'],$owner,[],fn()=>false)['templates']===[], 'An invented ammunition reference cannot reveal an unrelated private model');
itemCheck(publicItemStore($moved['items'],$tracked['characters'],null,[],fn()=>false)['templates']===[], 'No identity sees linked private stocks');
$bomb=['id'=>'bomb','kind'=>'object','name'=>'Bombe à flux','sourceAbilityId'=>'bombs','creatorCharacterId'=>'hero-a'];
itemCheck(normalizeItemTemplate($bomb)['sourceAbilityId']==='bombs', 'Craft model linkage survives native normalization');
itemRefused(fn()=>normalizeItemTemplate([...$bomb,'creatorCharacterId'=>'bad id']),400);
itemRefused(fn()=>normalizeItemTemplate([...$bomb,'stockBindings'=>[['characterId'=>'hero-a','abilityId'=>'bad id']]]),400);
$restricted=$state;$restricted['items']['templates'][0]['permissions']=['read'=>false,'pickup'=>false,'transfer'=>false,'destroy'=>false];
$projection=publicItemStore($restricted['items'],$state['characters'],$owner,[],fn()=>true);
itemCheck(!isset($projection['templates'][0]['document']), 'A forbidden read excludes the private document from its owner projection');
itemRefused(fn()=>applyDynamicItemCommand($restricted,$owner,'item.transfer',$args),403);
itemRefused(fn()=>applyDynamicItemCommand($restricted,$owner,'item.destroy',$args),403);
$restricted['items']['instances'][0]['location']=['kind'=>'ground','sceneId'=>'scene','layerId'=>'ground','x'=>50,'y'=>50];
itemRefused(fn()=>applyDynamicItemCommand($restricted,$owner,'item.pickup',[...$dropArgs,'expectedItemRevision'=>1]),403);
$restricted['items']['templates'][0]['permissions']['read']=true;
itemCheck(isset(publicItemStore($restricted['items'],$state['characters'],$owner,['sceneId'=>'scene','layerId'=>'ground'],fn()=>true)['templates'][0]['document']), 'An explicitly readable ground object reveals its document only in sight');
$previous=normalizeItemStore($state['items'])['templates'][0];$edited=[...$previous,'name'=>'Lettre annotée','permissions'=>['read'=>false,'pickup'=>true,'transfer'=>false,'destroy'=>true]];
$update=['template'=>$edited,'expectedTemplate'=>$previous];$mj=['id'=>'gm','role'=>'gm'];
itemRefused(fn()=>applyDynamicItemCommand($state,$owner,'item.template.update',$update),403);
$updated=applyDynamicItemCommand($state,$mj,'item.template.update',$update);
itemCheck($updated['items']['instances']===$state['items']['instances'],'Editing the model preserves unique instances and locations');
itemRefused(fn()=>applyDynamicItemCommand([...$state,'items'=>$updated['items']],$mj,'item.template.update',$update),409);
$destroyed=applyDynamicItemCommand([...$state,'items'=>$updated['items']],$owner,'item.destroy',['itemId'=>'letter-one','characterId'=>'hero-a','expectedItemRevision'=>1]);
itemCheck($destroyed['result']['item']['location']===['kind'=>'destroyed']&&$destroyed['result']['item']['revision']===2,'Destroying tombstones the same unique instance without changing a character');
itemRefused(fn()=>applyDynamicItemCommand([...$state,'items'=>$destroyed['items']],$owner,'item.destroy',['itemId'=>'letter-one','characterId'=>'hero-a','expectedItemRevision'=>2]),409);
itemRefused(fn()=>normalizeItemTemplate([...$previous,'permissions'=>['read'=>'oui']]),400);
itemCheck(applyDynamicItemCommand($restricted,$mj,'item.destroy',['itemId'=>'letter-one','expectedItemRevision'=>1,'sceneId'=>'scene'])['result']['item']['location']===['kind'=>'destroyed'],'The MJ can remove a ground copy without owning or placing a character token');
fwrite(STDOUT, "Dynamic items PHP contract: $checks checks passed.\n");
