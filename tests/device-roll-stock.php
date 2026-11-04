<?php
declare(strict_types=1);
define('XAR_TACTICAL_FIXTURE_ONLY',true);
require __DIR__.'/tactical-lifecycle.php';

$stockChecks=0;
function deviceStockCheck(bool $ok,string $label):void {
    if(!$ok)throw new RuntimeException($label);
    $GLOBALS['stockChecks']++;
}

foreach([false,true] as $independent){
    $db=fixture();
    $index=$db->payload('token-index:scene-one');$index['order'][]='token-independent';$db->put('token-index:scene-one',$index);
    $db->put('token-index:scene-two',['order'=>['token-copy']]);
    $roster=$db->payload('roster');$roster['players']=[['id'=>'account-player'],['id'=>'account-other']];$db->put('roster',$roster);
    $character=$db->payload('character:character-player');$character['specialSuccessStock']=2;$db->put('character:character-player',$character);
    $sourceKey='token:scene-one:'.($independent?'token-independent':'token-player');
    $source=$db->payload($sourceKey);$source['specialSuccessStock']=2;$source['controllerPlayerId']='account-player';$db->put($sourceKey,$source);
    $records=[];$pending=[];$state=onlineDeviceState($db,$records,$pending);$owner=unitById($state,unitIdForToken($source));
    $special=['raw'=>55,'code'=>'special-success','result'=>55,'success'=>true];
    $rolled=applicationMarkNaturalRoll(['rawD100'=>55,'formula'=>'1d100','total'=>55,'breakdown'=>'55']);
    $roll=onlineRollEntry(['display_name'=>'Recette'],$rolled,'Dextérité','Personnage',$special);
    deviceStockCheck(onlineAwardNaturalJoker($db,$records,$pending,$source,$roll,$special,'scene-one'),'Natural55 awards one Joker');
    $stockKey=$independent?$sourceKey:'character:character-player';
    deviceStockCheck($pending[$stockKey]['payload']['specialSuccessStock']===3,'The authoritative award reaches three');
    onlineDeviceReadChanges($state,$owner,$records,$pending);
    onlineDeviceFlush($state,$records,$pending);
    deviceStockCheck($pending[$stockKey]['payload']['specialSuccessStock']===3,'Reading back and flushing a device roll preserves the natural55 award');
    onlineDeviceFlush($state,$records,$pending);
    deviceStockCheck($pending[$stockKey]['payload']['specialSuccessStock']===3,'A second flush cannot recreate or erase the award');
    if($independent){
        deviceStockCheck(($pending['character:character-player']['payload']['specialSuccessStock']??$character['specialSuccessStock'])===2,'Autonomous source never credits its model');
    }else{
        deviceStockCheck($pending['token:scene-one:token-player']['payload']['specialSuccessStock']===3,'Linked source retains the same stock');
        deviceStockCheck($pending['token:scene-two:token-copy']['payload']['specialSuccessStock']===3,'Linked occurrence in another scene retains the same stock');
    }
}

// NPC resistance calls the runtime without a character-sheet owner. The roll
// outcome is irrelevant; every d100 must preserve this supported null owner.
$db=fixture();$records=[];$pending=[];$state=onlineDeviceState($db,$records,$pending);
$source=$state['map']['tokens'][dsIndex($state['map']['tokens'],'token-monster')];
$source['stats']=[['id'=>'character-stat-force','label'=>'Force','value'=>50]];
$owner=null;$context=onlineDeviceContext($db,$records,$pending,['id'=>'mechanics-authority','display_name'=>'Recette','effective_mode'=>'gm','permanent_role'=>'gm']);
$result=($context['roll'])($state,$owner,$source,'character-stat-force',0,'Résistance autonome');
deviceStockCheck(isset($result['roll']['outcome']['code']),'Autonomous resistance produces its d100 without a nullable-owner crash');
echo json_encode(['ok'=>true,'checks'=>$stockChecks,'productionAccess'=>false]).PHP_EOL;
