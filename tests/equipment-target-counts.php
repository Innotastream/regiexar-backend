<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);require __DIR__.'/tactical-special-effects.php';

function targetCountFixture(array $options=[]):array {
    $source=['id'=>'source','characterId'=>'unrelated-owner','followCharacter'=>true,'layerId'=>'ground'];
    $gear=['version'=>1,'kind'=>'weapon','hands'=>1,'formula'=>'d30+6','damageType'=>'physical','reach'=>'reach','projectile'=>false,'finesse'=>false];
    $templateId=$options['templateId']??'v370-template-d29417860cce105b93bb7f17';
    $instance=['id'=>'held','templateId'=>$templateId,'revision'=>1,'location'=>['kind'=>'inventory','characterId'=>$source['characterId']]];
    if(isset($options['instanceEquipment']))$instance['equipment']=[...$gear,...$options['instanceEquipment']];
    $items=normalizeItemStore(['version'=>1,'templates'=>[['id'=>$templateId,'kind'=>'object','name'=>'Arme de recette','equipment'=>[...$gear,...($options['equipment']??[])]]],'instances'=>[$instance],'equipmentLoadouts'=>[$source['characterId']=>['sets'=>[],'equippedItemIds'=>['held'],'activeSetId'=>'']]]);
    return ['source'=>$source,'state'=>['activeSceneId'=>'scene','characters'=>[],'items'=>$items,'map'=>['activeLayerId'=>'ground','tokens'=>[$source],'lights'=>[]]],'definition'=>['id'=>'bound','name'=>'Attaque personnalisée','equipmentBinding'=>['kind'=>'item','id'=>'held'],...($options['attack']??[])]];
}
function targetCountCloneOutcome():array {
    $f=targetCountFixture();$target=[...$f['source'],'id'=>'clone','followCharacter'=>false,'weaponAttacks'=>[$f['definition']]];$next=0;
    $copied=copyUnitInventory($f['state'],$f['source'],$target,static function(string $kind)use(&$next):string{return 'copy-'.$kind.'-'.++$next;});
    $state=[...$f['state'],'items'=>$copied['items']];
    return ['rows'=>equipmentAttackRows($copied['weaponAttacks'],$target,$state),'inventory'=>equipmentInventory($copied['items'],'unit_clone')];
}
if(($argv[1]??'')==='--cases') {
    $cases=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$results=[];
    foreach($cases as $f){$state=$f['state'];$state['items']=normalizeItemStore($state['items']);$results[]=['attack'=>resolveEquipmentAttack($f['definition'],$f['source'],$state),'inventory'=>equipmentInventory($state['items'],$f['source']['characterId'])];}
    echo json_encode(['cases'=>$results,'clone'=>targetCountCloneOutcome()],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);return;
}

$specialChecks=0;$f=targetCountFixture();$before=$f['state'];$attack=resolveEquipmentAttack($f['definition'],$f['source'],$f['state']);
specialCheck($attack['targetCount']===2&&$attack['formula']==='d30+6'&&$attack['reach']==='reach'&&!$attack['projectile'],'Known model provides two targets without changing its attack');
specialCheck($f['state']===$before,'Resolving keeps stocks, instances and loadouts untouched');
specialCheck(!array_key_exists('targetCount',$f['state']['items']['templates'][0]['equipment']),'Legacy equipment remains byte-equivalent in shape');
specialCheck(applicationWeaponTargetIds($attack,'one',['one','two'])===['one','two'],'Two distinct recipients accepted');
foreach([['one','two','three'],['one','one']] as $ids){$rejected=false;try{applicationWeaponTargetIds($attack,'one',$ids);}catch(RuntimeException $e){$rejected=$e->getCode()===400;}specialCheck($rejected,'Third or duplicated target rejected');}
foreach([1,3] as $count){$f=targetCountFixture(['attack'=>['targetCount'=>$count],'equipment'=>['targetCount'=>4],'instanceEquipment'=>['targetCount'=>5]]);specialCheck(resolveEquipmentAttack($f['definition'],$f['source'],$f['state'])['targetCount']===$count,'Explicit attack limit has priority');}
$f=targetCountFixture(['equipment'=>['targetCount'=>4],'instanceEquipment'=>['targetCount'=>1]]);specialCheck(resolveEquipmentAttack($f['definition'],$f['source'],$f['state'])['targetCount']===1,'Explicit instance limit has priority over template');
$f=targetCountFixture(['equipment'=>['targetCount'=>3],'instanceEquipment'=>['formula'=>'9']]);specialCheck(resolveEquipmentAttack($f['definition'],$f['source'],$f['state'])['targetCount']===3,'Template limit survives a different instance damage formula');
$f=targetCountFixture(['templateId'=>'future-weapon','equipment'=>['targetCount'=>4]]);specialCheck(resolveEquipmentAttack($f['definition'],$f['source'],$f['state'])['targetCount']===4,'Future arbitrary model can configure its own count');
foreach([['templateId'=>'ordinary-weapon'],['equipment'=>['projectile'=>true,'reach'=>'ranged']],['attack'=>['naturalWeapon'=>true]]] as $options){$f=targetCountFixture($options);specialCheck(applicationWeaponTargetCount(resolveEquipmentAttack($f['definition'],$f['source'],$f['state']))===1,'No broadening of unrelated, projectile or natural attacks');}
$clone=targetCountCloneOutcome();specialCheck($clone['rows'][0]['targetCount']===2&&$clone['rows'][0]['equipmentBinding']['id']!=='held','Independent clone resolves its own copied whip');
specialCheck(normalizeEquipmentTargetCount(2.0)===2,'Integral JSON numbers normalize identically');
foreach([null,true,false,'2',0,21,1.5] as $count){$rejected=false;try{normalizeEquipmentTargetCount($count);}catch(RuntimeException $e){$rejected=$e->getCode()===400;}specialCheck($rejected,'Invalid count rejected without coercing booleans or strings');}
fwrite(STDOUT,json_encode(['ok'=>true,'checks'=>$specialChecks],JSON_THROW_ON_ERROR)."\n");
