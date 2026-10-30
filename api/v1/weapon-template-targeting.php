<?php
declare(strict_types=1);

function normalizeEquipmentTargetCount(mixed $value):int {
    if((!is_int($value)&&!is_float($value))||!is_finite((float)$value)||floor((float)$value)!=(float)$value||$value<1||$value>20)throw new RuntimeException('Choisissez entre une et vingt cibles pour cette arme.',400);
    return (int)$value;
}
function equipmentWeaponTargetCount(array $equipment,?array $templateEquipment,string $templateId):?int {
    if(($equipment['kind']??'')!=='weapon')return null;
    if(array_key_exists('targetCount',$equipment))return normalizeEquipmentTargetCount($equipment['targetCount']);
    if(($templateEquipment['kind']??'')==='weapon'&&array_key_exists('targetCount',$templateEquipment))return normalizeEquipmentTargetCount($templateEquipment['targetCount']);
    if(($equipment['projectile']??false)===true)return null;
    // Rules data belongs to the weapon model, independently of its owner or artwork.
    $presets=['v370-template-d29417860cce105b93bb7f17'=>2];
    return $presets[$templateId]??null;
}
