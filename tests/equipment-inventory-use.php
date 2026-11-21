<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);require __DIR__.'/tactical-special-effects.php';
$specialChecks=0;
function inventoryGearFixture():MemoryConnection {
    $db=specialFixture();$items=['version'=>1,'templates'=>[],'instances'=>[],'equipmentLoadouts'=>['character-player'=>['sets'=>[],'equippedItemIds'=>['blade'],'activeSetId'=>'']]];
    foreach(['blade'=>[1,'7','melee'],'chain'=>[2,'17','reach']] as $id=>[$hands,$formula,$reach]){
        $equipment=['version'=>1,'kind'=>'weapon','hands'=>$hands,'formula'=>$formula,'damageType'=>'magical','reach'=>$reach,'projectile'=>false,'finesse'=>false];
        $items['templates'][]=['id'=>'template-'.$id,'name'=>$id,'kind'=>'object','equipment'=>$equipment];
        $items['instances'][]=['id'=>$id,'templateId'=>'template-'.$id,'revision'=>1,'location'=>['kind'=>'inventory','characterId'=>'character-player']];
    }
    $db->put('items',normalizeItemStore($items));$c=$db->payload('character:character-player');
    $c['weaponText']="Lame / 1d2\nChaîne / 1d2";
    $c['weaponAttacks']=[['id'=>'blade-strike','formula'=>'1d2','damageType'=>'magical','equipmentBinding'=>['kind'=>'item','id'=>'blade']],['id'=>'chain-strike','formula'=>'1d2','damageType'=>'magical','equipmentBinding'=>['kind'=>'item','id'=>'chain']]];
    $c['abilities'][]=['id'=>'inventory-sweep','name'=>'Balayage de recette','effect'=>'damage','formula'=>'0','castingStatId'=>'','useWeaponDamage'=>true,'requiredWeaponAttackId'=>'chain-strike','manaCost'=>3,'reusableInTurn'=>true];
    $db->put('character:character-player',$c);return $db;
}
function inventoryGearAttack(MemoryConnection $db,string $kind,string $id,string $requestId=''):TestResponse {
    return runCommand($db,'token.attack',['requestId'=>$requestId?:'inventory-'.randomToken(20),'sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','attackKind'=>$kind,'attackId'=>$id,'opposed'=>false]);
}
$db=inventoryGearFixture();$before=$db->payload('items');$loadout=$before['equipmentLoadouts']['character-player'];
$reordered=['activeSetId'=>$loadout['activeSetId'],'equippedItemIds'=>$loadout['equippedItemIds'],'sets'=>$loadout['sets']];
specialCheck($reordered!==$loadout,'Regression fixture really changes associative-key order');
$r=runCommand($db,'item.equipment.equip',['requestId'=>'equip-'.randomToken(20),'characterId'=>'character-player','itemIds'=>['chain'],'expectedLoadout'=>$reordered]);
specialCheck($r->status===200,'Player can confirm hands after JSON key reordering: '.json_encode($r->body));
specialCheck($db->payload('items')['equipmentLoadouts']['character-player']['equippedItemIds']===['chain'],'Requested weapon equipped');
$r=runCommand($db,'item.equipment.equip',['requestId'=>'equip-'.randomToken(20),'characterId'=>'character-player','itemIds'=>['blade'],'expectedLoadout'=>$reordered]);
specialCheck($r->status===409,'A genuinely stale loadout cannot overwrite concurrent hands');
specialCheck($db->payload('items')['equipmentLoadouts']['character-player']['equippedItemIds']===['chain'],'Stale refusal preserves current hands');
$nested=['sets'=>[['id'=>'pair','name'=>'Paire','itemIds'=>['blade','second']]],'equippedItemIds'=>['blade'],'activeSetId'=>''];
specialCheck(equipmentLoadoutMatches(['activeSetId'=>'','sets'=>[['itemIds'=>['blade','second'],'name'=>'Paire','id'=>'pair']],'equippedItemIds'=>['blade']],$nested),'Nested set keys compare by content');
specialCheck(!equipmentLoadoutMatches([...$nested,'equippedItemIds'=>['second','blade']],$nested),'Hand order stays significant');
$emptyDb=inventoryGearFixture();$emptyItems=$emptyDb->payload('items');$emptyItems['equipmentLoadouts']['character-player']['equippedItemIds']=[];$emptyDb->put('items',$emptyItems);
specialCheck(inventoryGearAttack($emptyDb,'weapon','weapon-1')->status===404,'Empty hands cannot fabricate an unbound fallback attack');
specialCheck(inventoryGearAttack($emptyDb,'ability','inventory-sweep')->status===200,'Empty hands still allow a skill to draw its owned weapon');

$db=inventoryGearFixture();$before=$db->payload('items');$c=$db->payload('character:character-player');$mana=$c['resources']['mana'];
$projectionState=domainsToApplicationState($db->domains,$db->revision);
$ownerView=publicPlayerState($projectionState,['id'=>'account-player','display_name'=>'Joueur'],[]);
$ownerToken=array_values(array_filter($ownerView['map']['tokens'],static fn($token)=>$token['id']==='token-player'))[0];
specialCheck(in_array('chain-strike',array_column($ownerToken['abilityWeaponAttacks']??[],'id'),true),'Owner receives stored weapon choices for skills');
$streamView=publicPlayerState($projectionState,['id'=>'account-player','display_name'=>'Joueur'],[],true);
specialCheck(array_filter($streamView['map']['tokens'],static fn($token)=>isset($token['abilityWeaponAttacks']))===[],'Stream receives no inventory weapon choices');
specialCheck(inventoryGearAttack($db,'weapon','chain-strike')->status===404,'Stored weapon is not silently available as a basic attack');
$request='inventory-'.randomToken(20);$r=inventoryGearAttack($db,'ability','inventory-sweep',$request);
specialCheck($r->status===200,'Skill draws the required inventory weapon: '.json_encode($r->body));
specialCheck(($r->body['attack']['appliedDamage']??-1)===17,'Skill reads current weapon damage');
$stored=array_values(array_filter($db->payload('activity')['attackReceipts'],static fn($receipt)=>($receipt['attack']['id']??'')===$r->body['attack']['id']))[0]['attack'];
specialCheck(($stored['echoAttackContext']['equipmentItemIds']??[])===['chain'],'Skill retains the actual physical weapon identity');
specialCheck($db->payload('items')===$before,'Skill never changes hands, sets or inventory');
specialCheck($db->payload('character:character-player')['resources']['mana']===$mana-3,'Skill pays exactly its normal cost');
$after=$db->payload('character:character-player');$replay=inventoryGearAttack($db,'ability','inventory-sweep',$request);
specialCheck($replay->status===200&&$replay->body['attack']['id']===$r->body['attack']['id'],'Skill replay returns the existing attack');
specialCheck($db->payload('character:character-player')===$after,'Replay pays no second cost');
$items=$db->payload('items');$items['instances'][1]['location']=['kind'=>'inventory','characterId'=>'someone-else'];$db->put('items',$items);
$r=inventoryGearAttack($db,'ability','inventory-sweep');specialCheck($r->status===400,'Weapon belonging to someone else is unavailable');
specialCheck($db->payload('character:character-player')===$after,'Missing weapon refuses before paying');
$source=$db->payload('token:scene-one:token-player');$source['followCharacter']=false;
specialCheck(abilityEquipmentAttackRows($c['weaponAttacks'],$source,['items'=>$before])===[],'Independent clone cannot borrow source-sheet inventory');

$db = inventoryGearFixture();
$itemStore = $db->payload('items');
$chainTemplate = 'v370-template-bc70eea5b124221f0cade694';
$itemStore['templates'][1]['id'] = $chainTemplate;
$itemStore['instances'][1]['templateId'] = $chainTemplate;
$db->put('items', $itemStore);
$character = $db->payload('character:character-player');
$danceId = 'ability-d9f65d3a-1a9d-4580-a0f6-b1441e078a79';
$character['abilities'][] = ['id'=>$danceId,'name'=>'Danse de la griffe','effect'=>'damage','formula'=>'13',
    'damageType'=>'physical','castingStatId'=>'','fatigueCost'=>5,'manaCost'=>7,
    'damageTargeting'=>['mode'=>'area','origin'=>'target','radiusCells'=>4]];
$db->put('character:character-player', $character);
$danceRequest = ['sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-player',
    'attackKind'=>'ability','attackId'=>$danceId,'requestId'=>'claw-dance-'.randomToken(20),'opposed'=>false];
$prepared = runCommand($db, 'token.attack', $danceRequest);
specialCheck($prepared->status === 200 && !empty($prepared->body['pendingAreaGate']) && $db->payload('items') === $itemStore,
    'Danse prepares a real inventory chain while the original blade stays equipped: ' . json_encode($prepared->body));
$resolved = fixtureApproveDefenseGate($db, $prepared);
specialCheck($resolved->status === 200 && $db->payload('token:scene-one:token-monster')['hp'] === 487,
    'Danse uses its configured 13 physical damage instead of the chain’s 17 magical damage: ' . json_encode($resolved->body));
$childReceipt = array_values(array_filter($db->payload('activity')['attackReceipts'], static fn($row) =>
    ($row['attack']['attackId'] ?? '') === $danceId && ($row['attack']['targetTokenId'] ?? '') === 'token-monster'
))[0]['attack'];
specialCheck($childReceipt['echoAttackContext']['equipmentItemIds'] === ['chain'] && $childReceipt['echoAttackContext']['weaponBased'] === true,
    'The hit records the actual chain instance');
specialCheck($db->payload('items') === $itemStore, 'The original blade stays equipped after the swing');
$cue = array_values(array_filter($db->payload('activity')['abilityCueEvents'], static fn($event) =>
    ($event['completionCue']['spell']['presetId'] ?? '') === 'barbed-chain-area-sweep'));
specialCheck(count($cue) === 1, 'The successful swing emits its bound chain animation once');
$itemStore['instances'][1]['location'] = ['kind'=>'inventory','characterId'=>'another-owner'];
$db->put('items', $itemStore);
$after = $db->payload('character:character-player');
$missing = runCommand($db, 'token.attack', [...$danceRequest,'requestId'=>'claw-dance-missing-'.randomToken(20)]);
specialCheck($missing->status === 409 && ($missing->body['code'] ?? '') === 'ability_weapon_missing'
    && $db->payload('character:character-player') === $after && $db->payload('items') === $itemStore,
    'Danse cannot borrow an unavailable chain and pays nothing on refusal');
fwrite(STDOUT,json_encode(['ok'=>true,'checks'=>$specialChecks],JSON_UNESCAPED_UNICODE)."\n");
