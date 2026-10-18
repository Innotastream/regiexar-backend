<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);require __DIR__.'/tactical-special-effects.php';
$cases=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$results=[];
foreach($cases as $case){try{$state=$case['state'];$source=$case['source'];$items=normalizeItemStore($state['items']);$state['items']=$items;$unit=equipmentUnitId($source);$attacks=[];foreach($case['attacks'] as $attack){$resolved=resolveEquipmentAttack($attack,$source,$state,false);if(empty($resolved['disabled']))$attacks[]=['id'=>$resolved['id'],'formula'=>$resolved['formula'],'damageType'=>$resolved['damageType']??'physical','equipmentItemIds'=>$resolved['equipmentItemIds']??[],'naturalWeapon'=>$resolved['naturalWeapon']??false];}$results[]=['unitId'=>$unit,'activeIds'=>array_column(effectiveEquipment($items,$unit,carriedTorch($state,$source)!==null),'id'),'attacks'=>$attacks];}catch(Throwable $error){$results[]=['error'=>$error->getMessage()];}}
echo json_encode($results,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
