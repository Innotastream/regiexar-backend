<?php
declare(strict_types=1);
function applicationMeleeVisualCatalogue(): array {
    return ['barbed-chain-lash'=>1040,'barbed-chain-sweep'=>1260,'ethereal-alternating'=>510,'ethereal-weave'=>585,
        'ethereal-instant'=>370,'ethereal-cross'=>440,'ethereal-dash-cross'=>1210,'requiem-judgement'=>1115,'requiem-grand-cleave'=>1065,'requiem-overhead'=>1270];
}
function applicationMeleeVisual(mixed $value): ?array {
    if(!is_array($value)||($value['version']??null)!==1||($value['placement']??'')!=='melee'
        ||!is_string($value['presetId']??null)||!array_key_exists($value['presetId'],applicationMeleeVisualCatalogue())
        ||!in_array($value['layerId']??null,['ground','upper','basement'],true))return null;
    foreach(['sceneId','sourceTokenId','targetTokenId'] as $key)if(!is_string($value[$key]??null)||preg_match('/^[A-Za-z0-9_-]{1,180}$/D',$value[$key])!==1)return null;
    if($value['sourceTokenId']===$value['targetTokenId']||!is_int($value['strikeIndex']??null)||$value['strikeIndex']<0||$value['strikeIndex']>1000)return null;
    foreach(['source','targetPoint'] as $key){
        $p=$value[$key]??null;if(!is_array($p))return null;
        foreach(['x','y','size'] as $axis)if((!is_int($p[$axis]??null)&&!is_float($p[$axis]??null))||!is_finite((float)$p[$axis])||$p[$axis]<($axis==='size'?10:0)||$p[$axis]>($axis==='size'?10000:100))return null;
    }
    return ['version'=>1,'placement'=>'melee','presetId'=>$value['presetId'],'sceneId'=>$value['sceneId'],'layerId'=>$value['layerId'],
        'sourceTokenId'=>$value['sourceTokenId'],'targetTokenId'=>$value['targetTokenId'],'source'=>array_intersect_key($value['source'],array_flip(['x','y','size'])),
        'targetPoint'=>array_intersect_key($value['targetPoint'],array_flip(['x','y','size'])),'strikeIndex'=>$value['strikeIndex']];
}
function applicationTrustedMeleeVisual(array $attack,array $context): ?array {
    if(($attack['hit']['outcome']['success']??false)!==true||empty($context['approved'])&&!empty($attack['hit']['outcome']['requiresGmValidation'])
        ||!empty($attack['areaGate']['pending'])||!empty($attack['projectileGate']['pending']))return null;
    $weapon=$attack['echoAttackContext']??[];
    static $configuration=null;
    if($configuration===null)$configuration=json_decode((string)file_get_contents(__DIR__.'/melee-visual-bindings.json'),true)?:[];
    $bindings=$context['bindings']??$configuration;$profiles=[];
    $binding=$bindings['abilities'][$context['abilityId']??(($attack['attackKind']??'')==='ability'?($attack['attackId']??''):'')]??null;
    $requiresEquipment=($binding['requiresEquipment']??true)!==false;
    if($requiresEquipment&&(empty($weapon['weaponBased'])||!empty($weapon['projectile'])||($weapon['reach']??'')==='ranged'||empty($weapon['equipmentItemIds'])))return null;
    foreach($weapon['equipmentItemIds']??[] as $id){
        $item=null;foreach($context['items']['instances']??[] as $entry)if(($entry['id']??'')===$id){$item=$entry;break;}
        $profile=$bindings['equipmentTemplates'][$item['templateId']??'']??null;
        if(!$profile&&$requiresEquipment)return null;$profiles[]=$profile;
    }
    if($requiresEquipment&&count(array_unique($profiles))!==1)return null;
    $profile=$profiles[0]??'';
    if($requiresEquipment&&$binding!==null&&$binding['profile']!==$profile)return null;
    $source=null;$target=null;$map=$context['map']??[];
    foreach($map['tokens']??[] as $token){if(($token['id']??'')===($attack['sourceTokenId']??''))$source=$token;if(($token['id']??'')===($attack['targetTokenId']??''))$target=$token;}
    if($source===null||$target===null)return null;
    $a=function_exists('applicationTokenSpatialFootprint')?applicationTokenSpatialFootprint($source,$map):$source;
    $b=function_exists('applicationTokenSpatialFootprint')?applicationTokenSpatialFootprint($target,$map):$target;
    $layer=$a['layerId']??'ground';if(($b['layerId']??'ground')!==$layer)return null;
    $point=static fn($p)=>['x'=>$p['x']??null,'y'=>$p['y']??null,'size'=>$p['size']??40];
    return applicationMeleeVisual(['version'=>1,'placement'=>'melee','presetId'=>$binding['presetId']??$bindings['profiles'][$profile]??'',
        'sceneId'=>$attack['sceneId'],'layerId'=>$layer,'sourceTokenId'=>$source['id'],'targetTokenId'=>$target['id'],
        'source'=>$point($a),'targetPoint'=>$point($b),'strikeIndex'=>$context['strikeIndex']??0]);
}
