<?php
declare(strict_types=1);
// Reuse the native authority fixture, then expose only this adversarial trace.
ob_start();require dirname(__DIR__).'/item-activations.php';ob_end_clean();
set_error_handler(static function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$state=$input['state'];$trace=[];
foreach($input['commands'] as $command){
    try {
        $result=applyDynamicItemCommand($state,$command['identity'],$command['kind'],$command['args'],[]);
        $state=$result['activeState']??$state;$state['items']=$result['items'];$status=200;
    } catch(Throwable $error) {$status=(int)($error->status??$error->getCode());if($status<400)$status=400;}
    $items=array_map(static fn($item)=>['id'=>$item['id'],'revision'=>$item['revision'],'location'=>$item['location']],$state['items']['instances']);
    $trace[]=['status'=>$status,'items'=>$items,'equipment'=>array_column(effectiveEquipment($state['items'],'hero'),'id'),'templateCount'=>count($state['items']['templates'])];
}
echo json_encode($trace,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
