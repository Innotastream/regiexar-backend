<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY', true);
require __DIR__.'/tactical-special-effects.php';
$checks=0;$failures=[];
function itemReadCheck(bool $ok,string $message):void {
    $GLOBALS['checks']++;
    if(!$ok)$GLOBALS['failures'][]=$message;
}
function itemReadFixture(bool $read):MemoryConnection {
    $db=specialFixture();
    $db->put('items',normalizeItemStore(['version'=>1,'templates'=>[
        ['id'=>'sealed-letter','name'=>'Lettre scellée','kind'=>'letter','description'=>'Contenu du modèle',
         'document'=>['blocks'=>[['type'=>'paragraph','text'=>'Document confidentiel fictif']]],
         'permissions'=>['read'=>$read,'pickup'=>true,'transfer'=>true]]],
        'instances'=>[['id'=>'letter-copy','templateId'=>'sealed-letter','revision'=>1,
            'description'=>'Note confidentielle fictive de cet exemplaire',
            'location'=>['kind'=>'ground','sceneId'=>'scene-one','layerId'=>'ground','x'=>55,'y'=>50]]]]));
    return $db;
}
$args=['requestId'=>'item-read-authority-pickup','sceneId'=>'scene-one','itemId'=>'letter-copy',
    'expectedItemRevision'=>1,'characterId'=>'character-player','tokenId'=>'token-player'];
foreach([false,true] as $read){
    $db=itemReadFixture($read);$original=$db->payload('items')['instances'][0]['description'];
    $picked=runCommand($db,'item.pickup',$args);
    itemReadCheck($picked->status===200,'A sealed item can still be picked up');
    itemReadCheck(isset($picked->body['item']['description'])===$read,'Pickup response enforces the read permission');
    $view=publicItemStore($db->payload('items'),[$db->payload('character:character-player')],
        ['id'=>'account-player'],[],static fn()=>true);
    itemReadCheck(isset($view['instances'][0]['description'])===$read,'Owned copy enforces the read permission');
    itemReadCheck(isset($view['templates'][0]['document'])===$read,'Model document follows the same read permission');
    itemReadCheck($db->payload('items')['instances'][0]['description']===$original,'Filtering preserves authoritative content for the GM');
    $before=$db->domains;$replay=runCommand($db,'item.pickup',$args);
    itemReadCheck(!empty($replay->body['deduplicated'])&&isset($replay->body['item']['description'])===$read,
        'A replay does not reveal unreadable content');
    itemReadCheck($db->domains===$before,'Replaying the response leaves the item and domains unchanged');
    if($read){
        $items=$db->payload('items');$items['templates'][0]['permissions']['read']=false;$db->put('items',$items);
        $before=$db->domains;$revokedReplay=runCommand($db,'item.pickup',$args);
        itemReadCheck(!isset($revokedReplay->body['item']['description']),'Revoking reading also filters an older successful receipt');
        itemReadCheck($db->domains===$before,'Receipt filtering after revocation does not write game data');
    }
}
$db=itemReadFixture(false);
$gm=runCommand($db,'item.pickup',[...$args,'requestId'=>'item-read-authority-gm'],true);
itemReadCheck($gm->status===200&&isset($gm->body['item']['description']),'The effective GM keeps access to the private description');

$db=itemReadFixture(true);
$gmArgs=[...$args,'requestId'=>'item-read-gm-then-player'];
$first=runCommand($db,'item.pickup',$gmArgs,true,'account-gm');
itemReadCheck($first->status===200&&isset($first->body['item']['description']),'The GM receipt records its authorized description');
$before=$db->domains;$replay=runCommand($db,'item.pickup',$gmArgs,false,'account-gm');
itemReadCheck($replay->status===200&&!empty($replay->body['deduplicated']),'A mode switch keeps an idempotent acknowledgement');
itemReadCheck(!array_key_exists('item',$replay->body),
    'Replaying a GM receipt as Player cannot expose another unit inventory');
itemReadCheck($db->domains===$before,'A mode-switch replay performs no write');
$gmReplay=runCommand($db,'item.pickup',$gmArgs,true,'account-gm');
itemReadCheck(isset($gmReplay->body['item']['description']),'Returning to effective GM keeps the original authorized receipt');
$items=$db->payload('items');$items['instances'][0]['revision']=3;
$items['instances'][0]['description']='Description publique révisée';
$items['instances'][0]['location']=['kind'=>'ground','sceneId'=>'scene-one','layerId'=>'ground','x'=>55,'y'=>50];
$db->put('items',$items);$before=$db->domains;
$publicReplay=runCommand($db,'item.pickup',$gmArgs,false,'account-gm');
itemReadCheck($publicReplay->status===200&&!empty($publicReplay->body['deduplicated']),
    'The receipt stays an acknowledgement when the item becomes publicly visible');
itemReadCheck(($publicReplay->body['item']['description']??'')==='Description publique révisée',
    'A now-visible copy exposes the current public description instead of the historical GM description');
itemReadCheck(($publicReplay->body['item']['location']??[])===$items['instances'][0]['location']
    &&($publicReplay->body['item']['revision']??0)===3&&$db->domains===$before,
    'A visible replay returns current public location and revision without moving or consuming the item');

$db=itemReadFixture(true);$first=runCommand($db,'item.pickup',$args);
$recipient=$db->payload('character:character-player');$recipient['id']='character-recipient';$recipient['ownerPlayerId']='account-other';
$db->put('character:character-recipient',$recipient);
$roster=$db->payload('roster');$roster['characterOrder'][]='character-recipient';$db->put('roster',$roster);
$recipientToken=$db->payload('token:scene-one:token-player');$recipientToken['id']='token-recipient';$recipientToken['characterId']='character-recipient';$recipientToken['x']=60;
$db->put('token:scene-one:token-recipient',$recipientToken);
$tokenIndex=$db->payload('token-index:scene-one');$tokenIndex['order'][]='token-recipient';$db->put('token-index:scene-one',$tokenIndex);
$transfer=runCommand($db,'item.transfer',['requestId'=>'item-read-transfer-after-pickup','itemId'=>'letter-copy',
    'expectedItemRevision'=>2,'characterId'=>'character-player','targetCharacterId'=>'character-recipient']);
itemReadCheck($transfer->status===200,'The readable item can be transferred to another player: '.json_encode($transfer->body));
$before=$db->domains;$replay=runCommand($db,'item.pickup',$args);
itemReadCheck($replay->status===200&&!empty($replay->body['deduplicated'])&&!isset($replay->body['item']['description']),
    'A previous owner receives no private description after transfer');
itemReadCheck(!array_key_exists('item',$replay->body)&&$db->domains===$before,
    'A transfer replay omits the inaccessible item without disclosing location or replaying the pickup');

$items=$db->payload('items');$firstCopy=$items['instances'][0];
$firstCopy['location']=['kind'=>'inventory','characterId'=>'character-player'];$firstCopy['revision']=4;
$secondCopy=[...$firstCopy,'id'=>'letter-second'];$items['instances']=[$firstCopy,$secondCopy];$db->put('items',$items);
$batchArgs=['requestId'=>'item-read-batch-gm-then-player','itemId'=>'letter-copy','itemIds'=>['letter-copy','letter-second'],
    'expectedItemRevisions'=>['letter-copy'=>4,'letter-second'=>4],'characterId'=>'character-player','targetCharacterId'=>'character-recipient'];
$batch=runCommand($db,'item.transfer',$batchArgs,true,'account-player');
itemReadCheck($batch->status===200&&count($batch->body['items']??[])===2,'The GM can transfer a batch into another inventory');
$items=$db->payload('items');$items['instances'][1]['location']=['kind'=>'inventory','characterId'=>'character-player'];
$items['instances'][1]['revision']=6;$db->put('items',$items);$before=$db->domains;
$batchReplay=runCommand($db,'item.transfer',$batchArgs,false,'account-player');
itemReadCheck($batchReplay->status===200&&!empty($batchReplay->body['deduplicated'])&&!array_key_exists('item',$batchReplay->body),
    'A batch replay omits its inaccessible primary item');
itemReadCheck(array_column($batchReplay->body['items']??[],'id')===['letter-second']
    &&array_is_list($batchReplay->body['items']??[]),'A batch replay removes inaccessible members and preserves a JSON list');
itemReadCheck(($batchReplay->body['items'][0]['revision']??0)===6
    &&($batchReplay->body['items'][0]['location']??[])===['kind'=>'inventory','characterId'=>'character-player']&&$db->domains===$before,
    'A batch replay preserves current accessible values without repeating the transfer');

if($failures)throw new RuntimeException(implode("\n",$failures));
echo json_encode(['ok'=>true,'checks'=>$checks,'productionAccess'=>false])."\n";
