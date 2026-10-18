<?php
declare(strict_types=1);
require __DIR__.'/equipment-spirit-creatures.php';
$checks=0;
function libraryCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);$GLOBALS['checks']++;}
$db=gearFixture();$items=$db->payload('items');$items['instances'][1]['templateId']='template-first';$items['instances'][1]['health']=['current'=>5,'max'=>20];$db->put('items',$items);
$template=$items['templates'][0];$changed=[...$template,'name'=>'Arme commune','equipment'=>[...$template['equipment'],'formula'=>'1d40','hands'=>2]];
$snapshot=array_column($items['instances'],'revision','id');$character=$db->payload('character:character-player');
libraryCheck(gearCommand($db,'item.template.update',['template'=>$changed,'expectedTemplate'=>$template,'applyEquipmentToInstances'=>true,'expectedInstanceRevisions'=>$snapshot])->status===403,'Player cannot update shared properties');
$r=gearCommand($db,'item.template.update',['template'=>$changed,'expectedTemplate'=>$template,'applyEquipmentToInstances'=>true,'expectedInstanceRevisions'=>['first'=>999,'second'=>1]],true);
libraryCheck($r->status===409&&$db->payload('items')===$items,'Conflicting instance rejects the entire update');
$r=gearCommand($db,'item.template.update',['template'=>$changed,'expectedTemplate'=>$template,'applyEquipmentToInstances'=>true,'expectedInstanceRevisions'=>$snapshot],true);
libraryCheck($r->status===200,'Native shared weapon update: '.json_encode($r->body));
$after=$db->payload('items');foreach($after['instances'] as $i=>$item){libraryCheck($item['equipment']['formula']==='1d40'&&$item['equipment']['hands']===2,'All living instances use new weapon properties');libraryCheck($item['location']===$items['instances'][$i]['location'],'Locations preserved');}
libraryCheck($after['instances'][1]['health']===['current'=>5,'max'=>20]&&$db->payload('character:character-player')===$character,'Individual HP and resources preserved');
libraryCheck(gearCommand($db,'item.template.update',['template'=>$changed,'expectedTemplate'=>$template],true)->status===409,'Old library dialog cannot overwrite the new model');
$args=['name'=>'Corde','quantity'=>1,'templateId'=>'quick-cord','itemIds'=>['quick-one'],'equipment'=>$changed['equipment'],'active'=>['version'=>1,'effects'=>[]],'category'=>'equipment'];
libraryCheck(gearCommand($db,'item.quick.create',$args)->status===200,'Player quick addition remains available');$q=$db->payload('items')['templates'];$q=$q[array_search('quick-cord',array_column($q,'id'),true)];
libraryCheck($q['category']==='quick'&&!isset($q['active'],$q['equipment']),'Forged quick item never creates a dynamic effect or equipment');
libraryCheck(gearCommand($db,'item.equipment.create',['name'=>'Arme','itemId'=>'forged','templateId'=>'forged-model','equipment'=>$changed['equipment']])->status===403,'Player cannot create dynamic equipment');
libraryCheck(gearCommand($db,'item.equipment.update',['itemId'=>'first','expectedItemRevision'=>2,'name'=>'Triche','equipment'=>$changed['equipment']])->status===403,'Player cannot edit dynamic equipment');
$db=gearFixture();$db->mediaObjects=['abcdefghijklmnopqrstuvwx'=>['pending_delete_at'=>null]];
// Build the arbitrary machine ability in the synthetic fixture only.
$c=$db->payload('character:character-player');$c['abilities'][]=['id'=>'library-machine','name'=>'Machine fictive','effect'=>'device','formula'=>'0','castingStatId'=>'','device'=>dsDefinition(['kind'=>'battery'])];$db->put('character:character-player',$c);
$r=specialMechanics($db,'device.deploy',['characterId'=>'character-player','sourceTokenId'=>'token-player','abilityId'=>'library-machine'],true);libraryCheck($r->status===200,'Native arbitrary machine fixture');
$store=$db->payload('devices');$device=$store['devices'][dsDevice($store,$r->body['deviceId'],false)];$beforeItems=$db->payload('items');$item=$beforeItems['instances'][dsIndex($beforeItems['instances'],$device['itemId'])];$template=$beforeItems['templates'][dsIndex($beforeItems['templates'],$item['templateId'])];$beforeCharacter=$db->payload('character:character-player');
$r=gearCommand($db,'item.template.update',['appearanceOnly'=>true,'template'=>[...$template,'name'=>'Machine illustrée','image'=>'/media/abcdefghijklmnopqrstuvwx','equipment'=>$changed['equipment']],'expectedTemplate'=>$template],true);
libraryCheck($r->status===200,'Native linked appearance edit: '.json_encode($r->body));
$actual=$db->payload('devices')['devices'][dsDevice($db->payload('devices'),$device['id'],false)];
libraryCheck($actual['image']==='/media/abcdefghijklmnopqrstuvwx'&&$actual['name']==='Machine illustrée','Appearance updated on same native device');
libraryCheck($actual['definition']===$device['definition']&&$actual['mana']===$device['mana']&&$actual['itemId']===$device['itemId'],'Device mechanics, reserve and identity preserved');
libraryCheck($db->payload('items')['instances']===$beforeItems['instances']&&$db->payload('character:character-player')===$beforeCharacter,'No stock or character rewrite');
$model=$db->payload('items')['templates'][dsIndex($db->payload('items')['templates'],$template['id'])];libraryCheck(!isset($model['equipment'])&&$model['sourceAbilityId']===$template['sourceAbilityId'],'An appearance-only update cannot replace linked mechanics');
$db=gearFixture();$r=gearStrike($db);libraryCheck($r->status===200,'Native attack for journal');
$actions=$db->payload('activity')['playerActions'];$attackId=$r->body['attack']['id'];libraryCheck(count(array_filter($actions,static fn($a)=>($a['attackId']??'')===$attackId))>=1,'Native journal has explicit attack relation');
$groups=array_unique(array_column($actions,'groupId'));libraryCheck(count($groups)===1&&$groups[0]!=='','Native attack transaction groups rolls and damage');
echo json_encode(['ok'=>true,'checks'=>$checks,'productionAccess'=>false]).PHP_EOL;
