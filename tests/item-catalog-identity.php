<?php
declare(strict_types=1);
require __DIR__.'/equipment-spirit-creatures.php';
$identityChecks=0;
function identityCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);$GLOBALS['identityChecks']++;}
$db=gearFixture();
identityCheck(itemNameKey("E\u{0301}PE\u{0301}E\u{00a0}de\u{202f}recette")===itemNameKey('ÉPÉE de recette'),'Decomposed accents and Unicode spaces');
identityCheck(itemNameKey('Œil de BŒUF')===itemNameKey('oeil de boeuf'),'French ligatures');
identityCheck(availableItemName(['templates'=>[['id'=>'a','name'=>'Atelier'],['id'=>'b','name'=>'Atelier · 2']]],'Atelier')==='Atelier · 3','Generated names remain unique');
identityCheck(count(preg_split('//u',availableItemName(['templates'=>[['id'=>'long','name'=>str_repeat('É',120)]]],str_repeat('É',120)),-1,PREG_SPLIT_NO_EMPTY))===120,'Generated names respect Unicode length');
$template=['id'=>'unique-model','kind'=>'object','name'=>'Épée de recette'];
identityCheck(gearCommand($db,'item.template.create',['template'=>$template],true)->status===200,'Initial unique model');
$before=$db->payload('items');
identityCheck(gearCommand($db,'item.template.create',['template'=>['id'=>'duplicate-name','kind'=>'object','name'=>' EPEE   DE RECETTE ']],true)->status===409,'Normalized duplicate refused');
identityCheck($db->payload('items')===$before,'Duplicate rejection is atomic');
$equipment=['version'=>1,'kind'=>'weapon','hands'=>1,'formula'=>'1d30+6','damageType'=>'physical','reach'=>'ranged','projectile'=>true,'finesse'=>false];
identityCheck(gearCommand($db,'item.equipment.create',['characterId'=>'character-player','templateId'=>'other-model','itemId'=>'other-copy','name'=>'epee de recette','equipment'=>$equipment],true)->status===409,'Equipment creation shares unique names');
$quick=['characterId'=>'character-player','name'=>'Corde de recette','description'=>'Première note','quantity'=>2,'templateId'=>'quick-identity-a','itemIds'=>['quick-identity-copy-a','quick-identity-copy-b']];
identityCheck(gearCommand($db,'item.quick.create',$quick)->status===200,'Player creates ordinary quick object');
identityCheck(gearCommand($db,'item.quick.create',[...$quick,'name'=>' CORDE  DE RECETTE ','description'=>'Autre note','templateId'=>'unused-model-id','itemIds'=>['quick-identity-copy-c','quick-identity-copy-d']])->status===200,'Quick name reuses same model');
$items=$db->payload('items');$matches=array_values(array_filter($items['templates'],static fn($t)=>itemNameKey($t['name'])==='corde de recette'));
identityCheck(count($matches)===1,'One model only');
identityCheck($matches[0]['description']==='','Private notes never enter the shared model');
$copies=array_values(array_filter($items['instances'],static fn($i)=>$i['templateId']===$matches[0]['id']));
identityCheck(count($copies)===4&&($copies[2]['description']??'')==='Autre note','Individual notes and counts preserved');
identityCheck(($copies[0]['description']??'')==='Première note','The first note also belongs to its instance');
identityCheck(!in_array('unused-model-id',array_column($items['templates'],'id'),true),'Unused identifier never creates another model');
identityCheck(gearCommand($db,'item.quick.create',[...$quick,'name'=>'Épée de recette','templateId'=>'fake-quick','itemIds'=>['fake-quick-a','fake-quick-b']])->status===409,'No quick-object duplication of configured model');
echo 'Identité catalogue : '.$identityChecks." contrôles PHP.\n";
