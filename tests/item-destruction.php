<?php
declare(strict_types=1);
require __DIR__.'/item-activations.php';
$discardChecks=0;
function discardCheck(bool $condition,string $label):void{if(!$condition)throw new RuntimeException($label);$GLOBALS['discardChecks']++;}
function discardFixture():array{$s=objectFixture(['version'=>1,'effects'=>[['kind'=>'message','text'=>'Exemple','durationMs'=>1000,'theme'=>'neutral']]]);unset($s['items']['templates'][0]['active']);$s['items']['instances']=[];for($i=0;$i<5;$i++)$s['items']['instances'][]=['id'=>'copy-'.$i,'templateId'=>'model','revision'=>1,'location'=>['kind'=>'inventory','characterId'=>'char']];return $s;}
function discardArgs(array $ids):array{return ['itemId'=>$ids[0],'itemIds'=>$ids,'expectedItemRevisions'=>array_fill_keys($ids,1)];}
$s=discardFixture();$before=$s;$result=objectAction($s,'item.destroy',discardArgs(['copy-0','copy-1']),['id'=>'p','role'=>'player']);
discardCheck($result['quantity']===2,'Exact selected quantity');discardCheck(count(array_filter($s['items']['instances'],static fn($i)=>$i['location']['kind']==='inventory'))===3,'Remaining three untouched');
discardCheck($s['items']['instances'][2]===$before['items']['instances'][2],'Remaining instance revision preserved');discardCheck(count($s['items']['templates'])===1,'Library model preserved');discardCheck($s['characters']===$before['characters'],'No resource change');
foreach(['stale','duplicate','bound','device','other'] as $case){$s=discardFixture();$args=discardArgs(['copy-0','copy-1']);$status=409;
 if($case==='stale')$args['expectedItemRevisions']['copy-1']=2;
 if($case==='duplicate'){$args['itemIds']=['copy-0','copy-0'];$status=400;}
 if($case==='bound'){$s['items']['templates'][0]['permissions']=['boundToCarrier'=>true];$status=403;}
 if($case==='device')$s['devices']['devices']=[['itemId'=>'copy-1']];
 if($case==='other')$s['items']['instances'][1]['location']['characterId']='not-owner';
 $before=$s;try{objectAction($s,'item.destroy',$args,['id'=>'p','role'=>'player']);throw new RuntimeException('Should fail');}catch(RuntimeException $e){discardCheck($e->getCode()===$status,$case.' refused');}
 discardCheck($s===$before,$case.' atomic');
}
echo json_encode(['ok'=>true,'discardChecks'=>$discardChecks,'productionAccess'=>false])."\n";
