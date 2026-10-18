<?php
declare(strict_types=1);
function applicationTargetIgnoresRange(array $target):bool {
    return in_array('cible',onlineConditionKeys(normalizeOnlineConditions($target['conditions']??null,$target['condition']??'')),true);
}
function applicationWeaponReach(array $weapon):string {
    return in_array($weapon['reach']??'', ['melee','reach','ranged'],true)?$weapon['reach']:(!empty($weapon['projectile'])?'ranged':'melee');
}
function applicationWeaponRangeCells(array $weapon):int {
    return ['melee'=>2,'reach'=>4,'ranged'=>15][applicationWeaponReach($weapon)];
}
function applicationWeaponInRange(array $weapon,array $source,array $target,array $map):bool {
    return ($source['layerId']??'ground')===($target['layerId']??'ground') && (applicationTargetIgnoresRange($target)||dsDistance($source,$target,$map,true,true)<=applicationWeaponRangeCells($weapon)+1e-9);
}
function applicationAbilityRangeCells(array $ability,array $weapons=[],string $requested=''):float {
    if(is_numeric($ability['rangeCells']??null)&&$ability['rangeCells']>0)return (float)$ability['rangeCells'];
    if(in_array($ability['reach']??'', ['melee','reach','ranged'],true))return applicationWeaponRangeCells($ability);
    $usesWeapon=!empty($ability['useWeaponDamage']);
    foreach($ability['workflow']['steps']??[] as $step)if(in_array($step['type']??'',['attack-chain','allocated-attacks'],true)&&($step['damageMode']??'')!=='configured')$usesWeapon=true;
    $weapon=$weapons[0]??[];foreach($weapons as $entry)if(($entry['id']??'')===$requested)$weapon=$entry;
    return applicationWeaponRangeCells($usesWeapon?$weapon:['reach'=>'ranged']);
}
function applicationAbilityTargetInRange(array $ability,array $source,array $target,array $map,array $weapons=[],string $requested=''):bool {
    return ($source['id']??'')!==''&&($source['id']??'')===($target['id']??null)||($source['layerId']??'ground')===($target['layerId']??'ground')&&(applicationTargetIgnoresRange($target)||dsDistance($source,$target,$map,true,true)<=applicationAbilityRangeCells($ability,$weapons,$requested)+1e-9);
}
function applicationWeaponTargetCount(array $weapon):int {
    return is_numeric($weapon['targetCount']??null)?max(1,min(20,(int)$weapon['targetCount'])):1;
}
function applicationWeaponTargetIds(array $weapon,string $primary,mixed $requested=null,bool $singleTarget=false):array {
    $ids=$requested??[$primary];
    if(!is_array($ids)||!array_is_list($ids)||count($ids)<1||count($ids)>($singleTarget?1:applicationWeaponTargetCount($weapon))||($ids[0]??null)!==$primary)
        throw new RuntimeException('Choisissez des cibles distinctes dans la limite prévue par cette arme.',400);
    $seen=[];foreach($ids as $id){if(!is_string($id)||preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$id)!==1||isset($seen[$id]))throw new RuntimeException('Choisissez des cibles distinctes dans la limite prévue par cette arme.',400);$seen[$id]=true;}
    return $ids;
}
function applicationSimpleWeaponDamage(array $ability,array $weapons,string $requested=''):array {
    $id=$requested!==''?$requested:(!empty($ability['mainWeaponOnly'])?($weapons[0]['id']??''):'');$weapon=null;
    foreach($weapons as $entry)if($entry['id']===$id){$weapon=$entry;break;}
    if(empty($weapon['formula']))throw new RuntimeException('Choisissez une arme de votre fiche pour cette compétence.',400);
    if(!empty($ability['mainWeaponOnly'])&&$weapon['id']!==($weapons[0]['id']??''))throw new RuntimeException('Cette compétence utilise uniquement l’arme principale.',400);
    $parts=[['type'=>$weapon['damageType'],'formula'=>!empty($ability['maximumWeaponDamage'])?(string)applicationMaximumFormula($weapon['formula']):$weapon['formula']]];
    if(($ability['weaponDamageMode']??'')==='add')$parts=[...$parts,...(applicationDamageComponents($ability['damageComponents']??[])?:[['type'=>$ability['damageType']??'physical','formula'=>$ability['formula']??'1d6']])];
    $merged=[];foreach($parts as $part){$type=$part['type'];$merged[$type]=isset($merged[$type])?[...$merged[$type],'formula'=>applicationCombinedDamageFormula([$merged[$type],$part])]:$part;}
    $parts=array_values($merged);return ['selectedWeapon'=>$weapon,'damageComponents'=>$parts,'damageFormula'=>applicationCombinedDamageFormula($parts),'damageType'=>$weapon['damageType']];
}
