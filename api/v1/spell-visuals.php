<?php
declare(strict_types=1);
require_once __DIR__.'/melee-visuals.php';
require_once __DIR__.'/token-dash.php';
function applicationSpellVisualCatalogue(): array { return ['trass-plasma-beam'=>2200,'moonlight-density'=>2400,'mist-capsule'=>1600,'potion-satchel'=>3400,'venom-awakening'=>2800,'venom-ramifications'=>1300,'venom-affliction'=>1700,'compact-force-wave'=>2150,'force-wall-shatter'=>1250,'intimidation-red'=>2300,'blood-burst'=>1290,'blood-crystal'=>1640,'elemental-fire'=>2200,'elemental-ice'=>2400,'elemental-lightning'=>1900,'elemental-acid'=>2400,'elemental-darkness'=>2600,'elemental-roots'=>2800]; }
function applicationSpellVisualDuration(mixed $value): int { $spell=applicationSpellVisual($value);return $spell===null?0:($spell['placement']==='dash'?($spell['presetId']==='token-body-charge'?2400:($spell['presetId']==='token-fulgurant-dash'?2810:2500)):($spell['placement']==='melee'?applicationMeleeVisualCatalogue()[$spell['presetId']]+1600+applicationMeleeVisualDeliverySlack($spell['presetId']):(applicationSpellVisualCatalogue()[$spell['presetId']]??0)+(in_array($spell['presetId'],['trass-plasma-beam','blood-burst','blood-crystal','force-wall-shatter','compact-force-wave','venom-awakening','venom-ramifications','venom-affliction','potion-satchel','mist-capsule'],true)?1600:0))); }

function applicationSpellVisual(mixed $value): ?array {
    if(is_array($value)&&($value['placement']??'')==='dash')return applicationTokenDash($value);
    if(is_array($value)&&($value['placement']??'')==='melee')return applicationMeleeVisual($value);
    if(!is_array($value)||($value['version']??null)!==1||!is_string($value['presetId']??null)||!array_key_exists($value['presetId'],applicationSpellVisualCatalogue())
        ||!is_string($value['sceneId']??null)||preg_match('/^[A-Za-z0-9_-]{1,180}$/D',$value['sceneId'])!==1
        ||!in_array($value['layerId']??null,['ground','upper','basement'],true)||!in_array($value['placement']??null,['source','target','area','cone','beam'],true))return null;
    $point=static fn($p)=>is_array($p)&&array_reduce(['x','y'],static fn($ok,$key)=>$ok&&(is_int($p[$key]??null)||is_float($p[$key]??null))&&is_finite((float)$p[$key])&&$p[$key]>=0&&$p[$key]<=100,true);
    $source=$value['source']??null;if(!$point($source)||(!is_int($source['size']??null)&&!is_float($source['size']??null))||!is_finite((float)$source['size'])||$source['size']<10||$source['size']>10000)return null;
    if(($value['placement']??'')==='beam'){$size=$value['widthCells']??null;return $value['presetId']==='trass-plasma-beam'&&$point($value['targetPoint']??null)&&(is_int($size)||is_float($size))&&is_finite((float)$size)&&$size>=.5&&$size<=100&&floor($size*2)==$size*2&&hypot($value['targetPoint']['x']-$source['x'],$value['targetPoint']['y']-$source['y'])>1e-6?['version'=>1,'presetId'=>$value['presetId'],'sceneId'=>$value['sceneId'],'layerId'=>$value['layerId'],'placement'=>'beam','source'=>['x'=>$source['x'],'y'=>$source['y'],'size'=>$source['size']],'targetPoint'=>array_intersect_key($value['targetPoint'],array_flip(['x','y'])),'widthCells'=>$size]:null;}
    $cone=$value['placement']==='cone';$size=$value[$cone?'rangeCells':'radiusCells']??null;
    if((!is_int($size)&&!is_float($size))||!is_finite((float)$size)||$size<($cone?1:.5)||$size>20||($cone?floor($size)!=$size:floor($size*2)!=$size*2))return null;
    if($value['placement']!=='source'&&!$point($value['targetPoint']??null))return null;
    if(isset($value['wallImpact'])&&($value['presetId']!=='compact-force-wave'||!$point($value['wallImpact'])))return null;
    $result=['version'=>1,'presetId'=>$value['presetId'],'sceneId'=>$value['sceneId'],'layerId'=>$value['layerId'],'placement'=>$value['placement'],
        'source'=>['x'=>$source['x'],'y'=>$source['y'],'size'=>$source['size']],$cone?'rangeCells':'radiusCells'=>$size];
    if($value['placement']!=='source')$result['targetPoint']=array_intersect_key($value['targetPoint'],array_flip(['x','y']));
    if(isset($value['wallImpact']))$result['wallImpact']=array_intersect_key($value['wallImpact'],array_flip(['x','y']));
    if(in_array($value['placement'],['source','target'],true)&&($value['followAnchor']??false)===true&&is_string($value['anchorTokenId']??null)&&preg_match('/^[A-Za-z0-9_-]{1,180}$/D',$value['anchorTokenId'])){$result['followAnchor']=true;$result['anchorTokenId']=$value['anchorTokenId'];}
    return $result;
}

function applicationSpellVisualBindings(): array {
    static $configuration=null;
    if($configuration===null){$data=json_decode((string)file_get_contents(__DIR__.'/spell-visual-bindings.json'),true);$configuration=is_array($data)?$data:[];}
    return $configuration['bindings']??[];
}
function applicationTrustedSpellVisual(array $ability,array $context): ?array {
    $binding=($context['bindings']??applicationSpellVisualBindings())[$ability['id']??'']??null;$source=$context['source']??null;$map=$context['map']??null;
    if(!is_array($binding)||!empty($binding['movementStepId'])||!is_array($source)||!is_array($map))return null;
    $footprint=function_exists('applicationTokenSpatialFootprint')?applicationTokenSpatialFootprint($source,$map):$source;
    $target=!empty($binding['followTarget'])&&!empty($context['targetPoint']['id'])?(function_exists('applicationTokenSpatialFootprint')?applicationTokenSpatialFootprint($context['targetPoint'],$map):$context['targetPoint']):null;
    if($target!==null&&($target['layerId']??'ground')!==($footprint['layerId']??'ground'))return null;
    $fitRadius=!empty($binding['fitTarget'])&&$target!==null?min(20,max(.5,ceil((($target['size']??40)/2/max(.001,$map['gridSize']??0)+.4)*2)/2))
        :(!empty($binding['fitSource'])?min(20,max(.5,ceil((($footprint['size']??40)/2/max(.001,$map['gridSize']??0)+max(0,(float)($binding['sourcePaddingCells']??0)))*2)/2)):null);
    return applicationSpellVisual(['version'=>1,'presetId'=>isset($binding['variants'])?($binding['variants'][$context['variantId']??'']??''):($binding['presetId']??''),
        'sceneId'=>$context['sceneId']??'','layerId'=>$footprint['layerId']??$map['activeLayerId']??'ground','placement'=>$binding['placement']??'',
        'source'=>['x'=>$footprint['x']??null,'y'=>$footprint['y']??null,'size'=>$footprint['size']??40],'targetPoint'=>$target!==null?['x'=>$target['x'],'y'=>$target['y']]:($context['targetPoint']??null),
        ...(!empty($binding['handlesKnockbackImpact'])&&!empty($context['wallImpact'])?['wallImpact'=>$context['wallImpact']]:[]),
        'rangeCells'=>$ability['damageTargeting']['lengthCells']??$ability['rangeCells']??$binding['rangeCells']??null,'radiusCells'=>$fitRadius??$ability['damageTargeting']['radiusCells']??$binding['radiusCells']??2,
        'followAnchor'=>($binding['followAnchor']??false)===true||$target!==null,'anchorTokenId'=>$target!==null?$context['targetPoint']['id']:($source['id']??'')]);
}

function applicationTrustedEnhancementSpellVisual(array $effect,array $context): ?array {
    $abilityId=$effect['sourceAbilityId']??$effect['abilityId']??'';$phase=$context['phase']??'';
    $binding=applicationSpellVisualBindings()[$abilityId]['enhancementEvents'][$phase]??null;$target=$context['targetPoint']??null;
    if(!is_array($binding)||!is_array($target)||!in_array($phase,['hit','periodic'],true))return null;
    return applicationTrustedSpellVisual(['id'=>$abilityId],[...$context,'source'=>$phase==='periodic'?$target:($context['source']??null),'bindings'=>[$abilityId=>$binding]]);
}
function applicationTrustedPoisonImpactVisuals(array $attack,array $context): array {
    if(($attack['hit']['outcome']['success']??false)!==true||!in_array($attack['status']??'',['applied','blocked'],true)||!empty($attack['hit']['outcome']['requiresGmValidation'])&&empty($context['approved']))return [];
    $source=null;$target=null;foreach($context['map']['tokens']??[] as $token){if($token['id']===($attack['sourceTokenId']??''))$source=$token;if($token['id']===($attack['targetTokenId']??''))$target=$token;}
    if($source===null||$target===null)return [];$result=[];$seen=[];
    foreach(array_slice($attack['poisonApplications']??[],0,100)as$effect){
        if(empty($effect['dotId'])||($effect['sourceTokenId']??'')!==$source['id']||($effect['targetTokenId']??'')!==$target['id'])continue;
        $spell=applicationTrustedEnhancementSpellVisual($effect,['phase'=>'hit','map'=>$context['map'],'sceneId'=>$attack['sceneId'],'source'=>$source,'targetPoint'=>$target]);
        if($spell!==null&&!isset($seen[$spell['presetId']])){$seen[$spell['presetId']]=true;$result[]=['key'=>($attack['id']??$effect['dotId']).':'.$target['id'].':'.$spell['presetId'],'spell'=>$spell];}
    }
    return $result;
}

function applicationTrustedDamageSpellVisual(array $attack,array $context): ?array {
    if(empty(applicationSpellVisualBindings()[$attack['attackId']??'']['onResolvedDamage'])||($attack['hit']['outcome']['success']??false)!==true||!in_array($attack['status']??'',['applied','blocked'],true)||!empty($attack['hit']['outcome']['requiresGmValidation'])&&empty($context['approved']))return null;
    $source=null;$target=null;foreach($context['map']['tokens']??[] as $token){if($token['id']===$attack['sourceTokenId'])$source=$token;if($token['id']===$attack['targetTokenId'])$target=$token;}
    if($source===null||$target===null)return null;
    return applicationTrustedSpellVisual(['id'=>$attack['attackId']],['map'=>$context['map'],'sceneId'=>$attack['sceneId'],'source'=>$source,'targetPoint'=>$target,'variantId'=>'damage','wallImpact'=>$attack['knockbackApplied']['impact']??null]);
}

function applicationPublicSpellVisual(mixed $value,array $context): ?array {
    $spell=applicationSpellVisual($value);$ids=$context['visibleTokenIds']??[];$visible=$context['visible']??static fn()=>false;
    if($spell===null||!empty($context['paused'])||$spell['sceneId']!==($context['sceneId']??'')||$spell['layerId']!==($context['layerId']??'')
        ||empty($context['sourceTokenId'])||!in_array($context['sourceTokenId'],$ids,true)||!$visible($spell['source']['x'],$spell['source']['y']))return null;
    if(!empty($spell['followAnchor'])&&!in_array($spell['anchorTokenId'],$ids,true))return null;
    if(isset($spell['wallImpact'])&&!$visible($spell['wallImpact']['x'],$spell['wallImpact']['y']))unset($spell['wallImpact']);
    if($spell['placement']==='dash'){if($spell['sourceTokenId']!==$context['sourceTokenId']||isset($spell['impact'])&&(!in_array($spell['impact']['targetTokenId'],$ids,true)||!$visible($spell['impact']['point']['x'],$spell['impact']['point']['y'])))return null;foreach($spell['path']as$p)if(!$visible($p['x'],$p['y']))return null;}
    if($spell['placement']==='melee'&&($spell['sourceTokenId']!==$context['sourceTokenId']||!in_array($spell['targetTokenId'],$ids,true)||!$visible($spell['targetPoint']['x'],$spell['targetPoint']['y'])))return null;
    if($spell['placement']==='melee'&&isset($spell['movementDash']))foreach($spell['movementDash']['path']as$p)if(!$visible($p['x'],$p['y']))return null;
    if(in_array($spell['placement'],['target','area'],true)&&!$visible($spell['targetPoint']['x'],$spell['targetPoint']['y']))return null;
    return $spell;
}
