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
fwrite(STDOUT,json_encode(['ok'=>true,'checks'=>$checks,'productionAccess'=>false])."\n");
