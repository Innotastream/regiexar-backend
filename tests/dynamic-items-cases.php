<?php
declare(strict_types=1);

$db = fixture();
$db->put('scene-index', ['order' => ['scene-one']]);
$db->put('character:character-receiver', ['id' => 'character-receiver', 'name' => 'Destinataire', 'ownerPlayerId' => 'account-receiver']);
$templateArgs = ['requestId' => 'item-template-native-0001', 'template' => ['id' => 'native-potion', 'kind' => 'potion', 'name' => 'Potion de soin', 'potion' => ['hp' => 10, 'mana' => 0, 'fatigue' => 0]]];
$response = runCommand($db, 'item.template.create', $templateArgs, false, 'account-player', true);
requireTactical($response->status === 403 && $db->payload('items') === [], 'Permanent administrator in player mode cannot create an item model');
$response = runCommand($db, 'item.template.create', $templateArgs, true, 'account-gm');
requireTactical($response->status === 200 && ($response->body['templateId'] ?? '') === 'native-potion', 'Native dispatcher creates a model in MJ mode');
$spawn = ['requestId' => 'item-spawn-native-0001', 'itemId' => 'native-item', 'templateId' => 'native-potion', 'location' => ['kind' => 'inventory', 'characterId' => 'character-player']];
$response = runCommand($db, 'item.spawn', $spawn, true, 'account-gm');
requireTactical($response->status === 200 && count($db->payload('items')['instances']) === 1, 'Native dispatcher creates exactly one instance');
$revision = $db->revision;
$retry = runCommand($db, 'item.spawn', $spawn, true, 'account-gm');
requireTactical($retry->status === 200 && ($retry->body['deduplicated'] ?? false) && $db->revision === $revision && count($db->payload('items')['instances']) === 1, 'Persistent replay does not duplicate an instance or bump the clock');
$drop = ['requestId' => 'item-drop-native-0001', 'itemId' => 'native-item', 'characterId' => 'character-player', 'expectedItemRevision' => 1, 'sceneId' => 'scene-one', 'tokenId' => 'token-player', 'x' => 99, 'y' => 99];
$response = runCommand($db, 'item.drop', $drop);
requireTactical($response->status === 200 && $db->payload('items')['instances'][0]['location']['x'] === 20, 'Native drop uses token coordinates, not client coordinates');
$pickup = [...$drop, 'requestId' => 'item-pickup-native-0001', 'expectedItemRevision' => 2];
$response = runCommand($db, 'item.pickup', $pickup);
requireTactical($response->status === 200 && $db->payload('items')['instances'][0]['revision'] === 3, 'Native pickup verifies current instance revision');
$second = runCommand($db, 'item.pickup', [...$pickup, 'requestId' => 'item-pickup-native-0002']);
requireTactical($second->status === 409 && count($db->payload('items')['instances']) === 1, 'Second pickup cannot acquire the same ground instance');
$consume = ['requestId' => 'item-consume-native-0001', 'itemId' => 'native-item', 'characterId' => 'character-player', 'expectedItemRevision' => 3];
$response = runCommand($db, 'item.consume', $consume);
requireTactical($response->status === 200 && $db->payload('character:character-player')['resources']['hp'] === 20
    && $db->payload('token:scene-one:token-player')['hp'] === 20 && $db->payload('token:scene-two:token-copy')['hp'] === 20
    && $db->payload('token:scene-one:token-independent')['hp'] === 40
    && $db->payload('items')['instances'][0]['location']['kind'] === 'consumed', 'Potion commits consumption, resources and direct following tokens together');
$revision = $db->revision;
$retry = runCommand($db, 'item.consume', $consume);
requireTactical($retry->status === 200 && ($retry->body['deduplicated'] ?? false) && $db->revision === $revision && $db->payload('character:character-player')['resources']['hp'] === 20, 'Persistent potion replay never applies the heal twice');
$conflict = runCommand($db, 'item.consume', [...$consume, 'characterId' => 'character-receiver']);
requireTactical($conflict->status === 409 && $db->revision === $revision, 'A reused request identity with changed arguments is refused');
$refusedLore = runCommand($db, 'character.patch', ['characterId' => 'character-player', 'patch' => ['lore' => 'Edit forbidden']]);
requireTactical($refusedLore->status === 403 && !isset($db->payload('character:character-player')['lore']), 'Native player patch keeps the lore read only');

$mediaId = 'abcdefghijklmnopqrstuvwx';
$mediaState = ['characters' => [['id' => 'hero-doc', 'ownerPlayerId' => 'owner-doc']], 'map' => [], 'scenes' => [],
    'items' => ['version' => 1, 'templates' => [['id' => 'doc', 'kind' => 'letter', 'name' => 'Lettre', 'image' => '', 'document' => ['blocks' => [['type' => 'image', 'src' => '/media/' . $mediaId]]]]],
    'instances' => [['id' => 'doc-one', 'templateId' => 'doc', 'revision' => 1, 'location' => ['kind' => 'inventory', 'characterId' => 'hero-doc']]]]];
requireTactical(onlineMediaVisibleInPlayerState($mediaState, ['id' => 'owner-doc'], $mediaId), 'Native media authority includes illustrations from the current owned inventory');
requireTactical(!onlineMediaVisibleInPlayerState($mediaState, ['id' => 'other-owner'], $mediaId), 'Native media authority refuses another player’s document illustrations');

$db=fixture();$db->put('scene-index',['order'=>['scene-one']]);$db->put('map:scene-one',['activeLayerId'=>'ground','gridSize'=>50,'naturalWidth'=>1600,'naturalHeight'=>900,'vision'=>['enabled'=>false],'background'=>'/assets/regie-chamber_1f146e88.webp']);
$db->put('scene:scene-one',['metadata'=>['id'=>'scene-one','name'=>'Scène de recette']]);$db->put('roster',['characterOrder'=>['character-player']]);
$template=['id'=>'sealed-letter','name'=>'Lettre scellée','kind'=>'letter','permissions'=>['read'=>false,'pickup'=>false,'transfer'=>false,'destroy'=>false],'document'=>['title'=>'Piste secrète','blocks'=>[['type'=>'paragraph','text'=>'Texte réservé'],['type'=>'image','src'=>'/assets/lore/ada-origines.png']]]];
requireTactical(runCommand($db,'item.template.create',['requestId'=>'private-letter-create-001','template'=>$template],true)->status===200,'MJ creates a document with explicit player rights');
requireTactical(runCommand($db,'item.spawn',['requestId'=>'private-letter-spawn-001','templateId'=>$template['id'],'itemId'=>'sealed-copy','location'=>['kind'=>'ground','sceneId'=>'scene-one','layerId'=>'ground','x'=>20,'y'=>50]],true)->status===200,'The native dispatcher places the unique document copy');
$args=['requestId'=>'private-letter-pickup-001','itemId'=>'sealed-copy','expectedItemRevision'=>1,'characterId'=>'character-player','sceneId'=>'scene-one','tokenId'=>'token-player'];
requireTactical(runCommand($db,'item.pickup',$args)->status===403,'A player cannot bypass a forbidden pickup');
requireTactical(runCommand($db,'item.destroy',[...$args,'requestId'=>'private-letter-destroy-001'])->status===403,'A player cannot bypass a forbidden destruction');
$previous=$db->payload('items')['templates'][0];$edited=[...$previous,'permissions'=>['read'=>true,'pickup'=>true,'transfer'=>false,'destroy'=>true]];
$update=['requestId'=>'private-letter-update-001','template'=>$edited,'expectedTemplate'=>$previous];
requireTactical(runCommand($db,'item.template.update',$update)->status===403,'A permanent or ordinary player cannot edit the model');
requireTactical(runCommand($db,'item.template.update',$update,true)->status===200,'The native dispatcher saves the edited model');
$revision=$db->revision;requireTactical((runCommand($db,'item.template.update',$update,true)->body['deduplicated']??false)&&$db->revision===$revision,'Model update replays its durable receipt');
requireTactical(runCommand($db,'item.template.update',[...$update,'requestId'=>'private-letter-update-002'],true)->status===409,'A concurrent model update refuses stale content');
$public=publicPlayerState(domainsToApplicationState(applicationDomainRecords($db),0),['id'=>'account-player'],[]);
requireTactical(str_contains(json_encode($public['items'],JSON_UNESCAPED_UNICODE), 'Texte réservé'),'An explicitly readable visible ground document reaches the player projection');
$pickup=runCommand($db,'item.pickup',[...$args,'requestId'=>'private-letter-pickup-002']);requireTactical($pickup->status===200,'The newly authorized pickup moves the existing copy');
$destroy=[...$args,'requestId'=>'private-letter-destroy-002','expectedItemRevision'=>2];$response=runCommand($db,'item.destroy',$destroy);
requireTactical($response->status===200&&$db->payload('items')['instances'][0]['location']===['kind'=>'destroyed'],'Authorized destruction tombstones the native unique instance');
$revision=$db->revision;requireTactical((runCommand($db,'item.destroy',$destroy)->body['deduplicated']??false)&&$db->revision===$revision,'Replayed destruction cannot change the item twice');
