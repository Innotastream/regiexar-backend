<?php
declare(strict_types=1);
require_once __DIR__.'/melee-visuals.php';
function applicationSpellVisualCatalogue(): array { return ['elemental-fire'=>2200,'elemental-ice'=>2400,'elemental-lightning'=>1900,'elemental-acid'=>2400,'elemental-darkness'=>2600,'elemental-roots'=>2800]; }
function applicationSpellVisualDuration(mixed $value): int { $spell=applicationSpellVisual($value);return $spell===null?0:($spell['placement']==='melee'?applicationMeleeVisualCatalogue()[$spell['presetId']]+1600:(applicationSpellVisualCatalogue()[$spell['presetId']]??0)); }

function applicationSpellVisual(mixed $value): ?array {
    if(is_array($value)&&($value['placement']??'')==='melee')return applicationMeleeVisual($value);
    if(!is_array($value)||($value['version']??null)!==1||!is_string($value['presetId']??null)||!array_key_exists($value['presetId'],applicationSpellVisualCatalogue())
        ||!is_string($value['sceneId']??null)||preg_match('/^[A-Za-z0-9_-]{1,180}$/D',$value['sceneId'])!==1
        ||!in_array($value['layerId']??null,['ground','upper','basement'],true)||!in_array($value['placement']??null,['source','target','area','cone'],true))return null;
    $point=static fn($p)=>is_array($p)&&array_reduce(['x','y'],static fn($ok,$key)=>$ok&&(is_int($p[$key]??null)||is_float($p[$key]??null))&&is_finite((float)$p[$key])&&$p[$key]>=0&&$p[$key]<=100,true);
    $source=$value['source']??null;if(!$point($source)||(!is_int($source['size']??null)&&!is_float($source['size']??null))||!is_finite((float)$source['size'])||$source['size']<10||$source['size']>10000)return null;
    $cone=$value['placement']==='cone';$size=$value[$cone?'rangeCells':'radiusCells']??null;
    if((!is_int($size)&&!is_float($size))||!is_finite((float)$size)||$size<($cone?1:.5)||$size>20||($cone?floor($size)!=$size:floor($size*2)!=$size*2))return null;
    if($value['placement']!=='source'&&!$point($value['targetPoint']??null))return null;
    $result=['version'=>1,'presetId'=>$value['presetId'],'sceneId'=>$value['sceneId'],'layerId'=>$value['layerId'],'placement'=>$value['placement'],
        'source'=>['x'=>$source['x'],'y'=>$source['y'],'size'=>$source['size']],$cone?'rangeCells':'radiusCells'=>$size];
    if($value['placement']!=='source')$result['targetPoint']=array_intersect_key($value['targetPoint'],array_flip(['x','y']));
    if($value['placement']==='source'&&($value['followAnchor']??false)===true&&is_string($value['anchorTokenId']??null)&&preg_match('/^[A-Za-z0-9_-]{1,180}$/D',$value['anchorTokenId'])){$result['followAnchor']=true;$result['anchorTokenId']=$value['anchorTokenId'];}
    return $result;
}

function applicationTrustedSpellVisual(array $ability,array $context): ?array {
    static $configuration=null;
    if($configuration===null){$data=json_decode((string)file_get_contents(__DIR__.'/spell-visual-bindings.json'),true);$configuration=is_array($data)?$data:[];}
    $binding=$configuration['bindings'][$ability['id']??'']??null;$source=$context['source']??null;$map=$context['map']??null;
    if(!is_array($binding)||!is_array($source)||!is_array($map))return null;
    $footprint=function_exists('applicationTokenSpatialFootprint')?applicationTokenSpatialFootprint($source,$map):$source;
    return applicationSpellVisual(['version'=>1,'presetId'=>isset($binding['variants'])?($binding['variants'][$context['variantId']??'']??''):($binding['presetId']??''),
        'sceneId'=>$context['sceneId']??'','layerId'=>$footprint['layerId']??$map['activeLayerId']??'ground','placement'=>$binding['placement']??'',
        'source'=>['x'=>$footprint['x']??null,'y'=>$footprint['y']??null,'size'=>$footprint['size']??40],'targetPoint'=>$context['targetPoint']??null,
        'rangeCells'=>$ability['damageTargeting']['lengthCells']??$ability['rangeCells']??$binding['rangeCells']??null,'radiusCells'=>$ability['damageTargeting']['radiusCells']??$binding['radiusCells']??2,
        'followAnchor'=>($binding['followAnchor']??false)===true,'anchorTokenId'=>$source['id']??'']);
}

function applicationPublicSpellVisual(mixed $value,array $context): ?array {
    $spell=applicationSpellVisual($value);$ids=$context['visibleTokenIds']??[];$visible=$context['visible']??static fn()=>false;
    if($spell===null||!empty($context['paused'])||$spell['sceneId']!==($context['sceneId']??'')||$spell['layerId']!==($context['layerId']??'')
        ||empty($context['sourceTokenId'])||!in_array($context['sourceTokenId'],$ids,true)||!$visible($spell['source']['x'],$spell['source']['y']))return null;
    if(!empty($spell['followAnchor'])&&!in_array($spell['anchorTokenId'],$ids,true))return null;
    if($spell['placement']==='melee'&&($spell['sourceTokenId']!==$context['sourceTokenId']||!in_array($spell['targetTokenId'],$ids,true)||!$visible($spell['targetPoint']['x'],$spell['targetPoint']['y'])))return null;
    if(in_array($spell['placement'],['target','area'],true)&&!$visible($spell['targetPoint']['x'],$spell['targetPoint']['y']))return null;
    return $spell;
}
