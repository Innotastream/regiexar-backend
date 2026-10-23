<?php
declare(strict_types=1);
require __DIR__.'/equipment-spirit-creatures.php';
$checks=0;function catalogCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);$GLOBALS['checks']++;}
foreach(['1d2+2x4','1d2+2X4','1d2+2×4','1d2+2*4'] as $f){
    catalogCheck(evaluateEffectFormula($f,static fn($s)=>1)['total']===12,'Left to right minimum');
    catalogCheck(evaluateEffectFormula($f,static fn($s)=>2)['total']===16,'Left to right maximum');
    catalogCheck(applicationMaximumFormula($f)===16,'Maximum shares the grammar');
}
foreach(['','1d0','1d1','0d6','101d6','1d1001','1d2*','1d2**4','()','2/3','1000000000*1000000000','1+-2','(((((((1)))))))'] as $f)catalogCheck(!validEffectFormula($f),'Invalid formula '.$f);
catalogCheck(evaluateEffectFormula('2*(3+4)')['total']===14,'Explicit parentheses');
catalogCheck(evaluateEffectFormula('10-3*2+1')['total']===15,'No multiplication priority');
catalogCheck(applicationMaximumFormula('1d6*(-2)')===-2,'Negative factor maximum');
$db=gearFixture();
catalogCheck(gearCommand($db,'item.category.create',['categoryId'=>'category-quest','name'=>'Quête'])->status===403,'Effective player cannot create category');
catalogCheck(gearCommand($db,'item.category.create',['categoryId'=>'category-quest','name'=>'Quête'],true)->status===200,'GM creates category');
catalogCheck(gearCommand($db,'item.category.create',['categoryId'=>'category-duplicate','name'=>'quete'],true)->status===400,'Duplicate accented category refused');
$template=['id'=>'catalog-native','kind'=>'potion','name'=>'Potion de recette','category'=>'category-quest','active'=>['version'=>1,'effects'=>[['kind'=>'heal-max-die','percent'=>30,'bonusFormula'=>'1d2+2x4']]]];
catalogCheck(gearCommand($db,'item.template.create',['template'=>$template],true)->status===200,'Custom category and formula persist');
catalogCheck(gearCommand($db,'item.spawn',['templateId'=>$template['id'],'itemIds'=>['batch-a','batch-b'],'location'=>['kind'=>'inventory','characterId'=>'character-player']],true)->status===200,'Atomic batch');
$before=$db->payload('items');
catalogCheck(gearCommand($db,'item.spawn',['templateId'=>$template['id'],'itemIds'=>['batch-new','batch-a'],'location'=>['kind'=>'inventory','characterId'=>'character-player']],true)->status===409&&$db->payload('items')===$before,'Duplicate rolls back entire batch');
$copies=array_values(array_filter($before['instances'],static fn($i)=>$i['templateId']==='catalog-native'));$snapshot=array_column($copies,'revision','id');$model=$before['templates'][array_search('catalog-native',array_column($before['templates'],'id'),true)];
catalogCheck(gearCommand($db,'item.template.delete',['templateId'=>$model['id'],'expectedTemplate'=>$model,'expectedInstanceRevisions'=>$snapshot])->status===403,'Player cannot delete library model');
$c=$db->payload('character:character-player');$c['resources']['hp']=1;$c['resources']['maxHp']=103;$db->put('character:character-player',$c);
$consume=['requestId'=>'heal-catalog-native-0000001','itemId'=>'batch-a','expectedItemRevision'=>1,'characterId'=>'character-player','sceneId'=>'scene-one','tokenId'=>'token-player'];
$r=gearCommand($db,'item.consume',$consume);
catalogCheck($r->status===200&&count($r->body['rolls'])===2,'Percentage and extra formula rolled authoritatively');
catalogCheck(in_array($r->body['rolls'][1]['total'],[12,16],true),'Extra formula uses left to right');
catalogCheck($db->payload('character:character-player')['resources']['hp']===1+$r->body['rolls'][0]['total']+$r->body['rolls'][1]['total'],'Both rolls heal the actual owner');
catalogCheck(gearCommand($db,'item.template.delete',['templateId'=>$model['id'],'expectedTemplate'=>$model,'expectedInstanceRevisions'=>$snapshot],true)->status===409,'Consumption invalidates confirmation');
$items=$db->payload('items');$copies=array_filter($items['instances'],static fn($i)=>$i['templateId']===$model['id']);$snapshot=array_column($copies,'revision','id');
$args=['requestId'=>'catalog-delete-native-000001','templateId'=>$model['id'],'expectedTemplate'=>$model,'expectedInstanceRevisions'=>$snapshot];
catalogCheck(gearCommand($db,'item.template.delete',$args,true)->status===200,'Complete deletion');
catalogCheck(!in_array($model['id'],array_column($db->payload('items')['templates'],'id'),true)&&!array_filter($db->payload('items')['instances'],static fn($i)=>$i['templateId']===$model['id']),'No model or remaining copies');
catalogCheck(!empty(gearCommand($db,'item.template.delete',$args,true)->body['deduplicated']),'Deletion receipt survives model removal');
catalogCheck(!empty(gearCommand($db,'item.consume',$consume)->body['deduplicated']),'Consumption receipt survives model removal');
$db=gearFixture();
$model=['id'=>'edit-physical','kind'=>'object','name'=>'Vase natif','size'=>40,'maxHp'=>60,'permissions'=>['destroy'=>true,'read'=>true]];
catalogCheck(gearCommand($db,'item.template.create',['template'=>$model],true)->status===200,'Full editor model');
$location=['kind'=>'ground','sceneId'=>'scene-one','layerId'=>'ground','x'=>40,'y'=>50];
catalogCheck(gearCommand($db,'item.spawn',['templateId'=>$model['id'],'itemId'=>'edit-ground','location'=>$location],true)->status===200,'Placed editor target');
catalogCheck(gearCommand($db,'item.spawn',['templateId'=>$model['id'],'itemId'=>'edit-stock','location'=>['kind'=>'inventory','characterId'=>'character-player']],true)->status===200,'Other copy');
$before=$db->payload('items');$model=$before['templates'][array_search($model['id'],array_column($before['templates'],'id'),true)];
$args=['requestId'=>'edit-physical-native-000001','expectedTemplate'=>$model,'template'=>array_replace($model,['description'=>'Contenu sauvegardé']),'instanceConfiguration'=>['itemId'=>'edit-ground','expectedItemRevision'=>1,'size'=>90,'health'=>['current'=>35,'max'=>80]]];
catalogCheck(gearCommand($db,'item.template.update',$args)->status===403,'Player cannot edit through composition');
catalogCheck(gearCommand($db,'item.template.update',$args,true)->status===200,'Atomic template and physical save');
$after=$db->payload('items');$copies=array_column($after['instances'],null,'id');$models=array_column($after['templates'],null,'id');
catalogCheck($copies['edit-ground']['size']===90&&$copies['edit-ground']['health']===['current'=>35,'max'=>80]&&$copies['edit-ground']['revision']===2&&$copies['edit-ground']['location']===$location,'Target size and HP saved without moving');
catalogCheck($copies['edit-stock']===array_column($before['instances'],null,'id')['edit-stock']&&$models[$model['id']]['size']===40&&$models[$model['id']]['maxHp']===60,'Other copy and model physical defaults preserved');
catalogCheck(!empty(gearCommand($db,'item.template.update',$args,true)->body['deduplicated'])&&$db->payload('items')===$after,'One durable receipt for both changes');
$current=$models[$model['id']];
foreach([['itemId'=>'edit-ground','expectedItemRevision'=>1,'size'=>100],['itemId'=>'edit-ground','expectedItemRevision'=>2,'size'=>9],['itemId'=>'edit-ground','expectedItemRevision'=>2,'health'=>['current'=>81,'max'=>80]],null] as $configuration){
    $r=gearCommand($db,'item.template.update',['expectedTemplate'=>$current,'template'=>array_replace($current,['description'=>'Refusé']),'instanceConfiguration'=>$configuration],true);
    catalogCheck(in_array($r->status,[400,409],true)&&$db->payload('items')===$after,'Invalid or stale instance rolls back common changes');
}
$global=['expectedTemplate'=>$current,'template'=>array_replace($current,['equipment'=>['kind'=>'weapon','hands'=>1,'formula'=>'1d20+5','reach'=>'reach','damageType'=>'magical']]),'applyEquipmentToInstances'=>true,'expectedInstanceRevisions'=>['edit-ground'=>2,'edit-stock'=>1],'instanceConfiguration'=>['itemId'=>'edit-ground','expectedItemRevision'=>2,'size'=>110]];
catalogCheck(gearCommand($db,'item.template.update',$global,true)->status===200,'Global equipment plus local physical configuration');
$copies=array_column($db->payload('items')['instances'],null,'id');
catalogCheck($copies['edit-ground']['size']===110&&$copies['edit-stock']['size']===40&&$copies['edit-ground']['equipment']['formula']==='1d20+5'&&$copies['edit-stock']['equipment']['formula']==='1d20+5','Both scopes stay independent');
fwrite(STDOUT,json_encode(['ok'=>true,'checks'=>$checks,'productionAccess'=>false])."\n");
