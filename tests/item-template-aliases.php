<?php
declare(strict_types=1);
require_once __DIR__.'/../api/v1/dynamic-items.php';
$checks=0;
function poisonCheck(bool $value,string $message):void{global $checks;if(!$value)throw new RuntimeException($message);$checks++;}
function poisonFixture():array{
 $active=['version'=>1,'authority'=>'gm','ingredientTag'=>'poison-vial','useLabel'=>'Utiliser · arbitrage MJ','effects'=>[['kind'=>'message','text'=>'Fiole de poison','theme'=>'poison','durationMs'=>1500]]];
 return ['version'=>1,'templates'=>[
  ['id'=>APPLICATION_POISON_ALIAS_ID,'name'=>'Poison rudimentaire','kind'=>'object','description'=>'','image'=>'','active'=>$active],
  ['id'=>APPLICATION_POISON_TEMPLATE_ID,'name'=>'Fiole de poison mortel','kind'=>'object','description'=>'','image'=>'/assets/item-art/poison-mortel.png','potionColor'=>'#83e31d','active'=>$active]
 ],'instances'=>array_map(static fn($kind,$i)=>['id'=>'copy-'.$kind,'templateId'=>APPLICATION_POISON_ALIAS_ID,'revision'=>$i+3,'location'=>match($kind){'inventory'=>['kind'=>$kind,'characterId'=>'owner'],'ground'=>['kind'=>$kind,'sceneId'=>'scene','layerId'=>'upper','x'=>12,'y'=>25],'consumed'=>['kind'=>$kind,'characterId'=>'owner'],default=>['kind'=>$kind]}],['inventory','ground','consumed','destroyed'],[0,1,2,3]),
 'inventoryCatalog'=>['owner'=>[APPLICATION_POISON_ALIAS_ID,APPLICATION_POISON_TEMPLATE_ID]],
 'favorites'=>[['accountId'=>'player','unitId'=>'owner','templateId'=>APPLICATION_POISON_ALIAS_ID],['accountId'=>'player','unitId'=>'owner','templateId'=>APPLICATION_POISON_TEMPLATE_ID]],'pendingUses'=>[]];
}
$source=poisonFixture();$before=$source;$merged=mergeKnownItemTemplateAliases($source);
poisonCheck($source===$before,'Source untouched');poisonCheck($merged['templates']===[$source['templates'][1]],'Canonical model untouched');
poisonCheck(count($merged['instances'])===count($source['instances']),'No copy lost or created');
foreach($source['instances']as$i=>$copy){$copy['templateId']=APPLICATION_POISON_TEMPLATE_ID;poisonCheck($merged['instances'][$i]===$copy,'Identity, revision and location preserved');}
poisonCheck($merged['inventoryCatalog']['owner']===[APPLICATION_POISON_TEMPLATE_ID],'Catalog aliases deduplicated');
poisonCheck(count($merged['favorites'])===1,'Favorites merged only for the same owner/unit');
poisonCheck(mergeKnownItemTemplateAliases($merged)===$merged,'Idempotent merge');
$normalized=normalizeItemStore($source);poisonCheck(count($normalized['templates'])===1&&count($normalized['instances'])===4,'Production normalizer applies merge');
poisonCheck(normalizeItemStore($normalized)===$normalized,'Production normalization idempotent');
$custom=poisonFixture();$custom['templates'][1]['image']='/media/owner-art';poisonCheck(mergeKnownItemTemplateAliases($custom)['templates'][0]['image']==='/media/owner-art','Canonical custom artwork preserved');
foreach(['name','image','description','size','active','permission','stock','canonical','pending','missing','duplicate','schema']as$guard){
 $value=poisonFixture();
 switch($guard){
  case 'name':$value['templates'][0]['name']='Poison personnel';break;
  case 'image':$value['templates'][0]['image']='/media/custom';break;
  case 'description':$value['templates'][0]['description']='Effet personnel';break;
  case 'size':$value['templates'][0]['size']=41;break;
  case 'active':$value['templates'][0]['active']['effects'][0]['durationMs']=2200;break;
  case 'permission':$value['templates'][0]['permissions']=['destroy'=>true];break;
  case 'stock':$value['templates'][0]['stockBindings']=[['characterId'=>'owner','abilityId'=>'ability']];break;
  case 'canonical':$value['templates'][1]['active']['authority']='owner';break;
  case 'pending':$value['pendingUses']=[['itemId'=>'copy-inventory']];break;
  case 'missing':array_pop($value['templates']);break;
  case 'duplicate':$value['templates'][]=$value['templates'][0];break;
  case 'schema':$value['version']=2;break;
 }
 poisonCheck(mergeKnownItemTemplateAliases($value)===$value,'Guard preserved: '.$guard);
}
echo "Poison alias preservation: $checks checks passed.\n";
