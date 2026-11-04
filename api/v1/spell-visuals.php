<?php
declare(strict_types=1);
require_once __DIR__.'/melee-visuals.php';
require_once __DIR__.'/token-dash.php';
require_once __DIR__.'/equipment.php';
require_once __DIR__.'/combat-animation-timing.php';
function applicationSpellVisualCatalogue(): array { return ['ethereal-weapon-echo'=>1220,'root-entanglement'=>3400,'thorn-wall-growth'=>3650,'feral-metamorphosis'=>4600,'mirror-copy'=>3000,'whisper-puppet'=>3300,'supreme-eye'=>3900,'weapon-poison-brew'=>2300,'weapon-poison-coat'=>2700,'timed-flux-explosion'=>1900,'stationary-blue-bolt'=>1350,'trass-plasma-beam'=>2200,'moonlight-density'=>2400,'mist-capsule'=>1600,'potion-satchel'=>3400,'super-potion-charge'=>2800,'predator-analysis'=>3300,'venom-awakening'=>2800,'venom-ramifications'=>1300,'venom-affliction'=>1700,'barbed-chain-area-sweep'=>1260,'compact-force-wave'=>2150,'force-wall-shatter'=>1250,'intimidation-red'=>2300,'blood-awakening'=>2600,'blood-burst'=>1290,'blood-crystal'=>1640,'elemental-fire'=>2200,'elemental-ice'=>2400,'elemental-lightning'=>1900,'elemental-acid'=>2400,'elemental-darkness'=>2600,'elemental-roots'=>2800]; }
function applicationSpellVisualDuration(mixed $value): int {
    $spell=applicationSpellVisual($value);if($spell===null)return 0;
    if($spell['placement']==='dash')return applicationCombatAnimationDuration(match($spell['presetId']){'token-body-charge'=>800,'token-fulgurant-dash'=>1210,'token-celestial-dash'=>910,default=>900})+1600;
    if($spell['placement']==='melee')return applicationCombatAnimationDuration(applicationMeleeVisualCatalogue()[$spell['presetId']])+1600+applicationMeleeVisualDeliverySlack($spell['presetId']);
    if($spell['presetId']==='ethereal-weapon-echo')return applicationCombatAnimationDuration(1220)+3000;
    return applicationCombatAnimationDuration(applicationSpellVisualCatalogue()[$spell['presetId']])+(in_array($spell['presetId'],['root-entanglement','thorn-wall-growth','feral-metamorphosis','mirror-copy','whisper-puppet','supreme-eye','weapon-poison-brew','weapon-poison-coat','timed-flux-explosion','stationary-blue-bolt','trass-plasma-beam','blood-awakening','blood-burst','blood-crystal','force-wall-shatter','compact-force-wave','venom-awakening','venom-ramifications','venom-affliction','barbed-chain-area-sweep','potion-satchel','mist-capsule','super-potion-charge','predator-analysis'],true)?1600:0);
}

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
    $thorn=$value['presetId']==='thorn-wall-growth';
    $rotation=$value['targetPoint']['rotation']??null;
    if($thorn&&($value['placement']!=='target'||($value['followAnchor']??false)!==true
        ||!equipmentId($value['anchorTokenId']??null)||!is_int($rotation)||$rotation<0||$rotation>=180))return null;
    if(array_key_exists('areaShape',$value)&&($value['placement']!=='area'||$value['areaShape']!=='square'))return null;
    if(array_key_exists('excludeSource',$value)&&($value['placement']!=='area'||!is_bool($value['excludeSource'])))return null;
    if(($value['followSource']??false)===true&&(!in_array($value['placement'],['source','target'],true)||!is_string($value['sourceTokenId']??null)||preg_match('/^[A-Za-z0-9_-]{1,180}$/D',$value['sourceTokenId'])!==1))return null;
    $weaponEcho = $value['presetId'] === 'ethereal-weapon-echo';
    if ($weaponEcho && ($value['placement'] !== 'target' || !equipmentId($value['effectId'] ?? null)
        || ($value['followSource'] ?? false) !== true || ($value['followAnchor'] ?? false) !== true
        || !equipmentId($value['anchorTokenId'] ?? null) || $value['anchorTokenId'] === $value['sourceTokenId'])) return null;
    if (array_key_exists('weaponProfile',$value)) {
        $profile = $value['weaponProfile'];
        if ($weaponEcho ? applicationEnchantedWeaponProfile($profile) === null
            : $value['presetId'] !== 'weapon-poison-coat' || !is_string($profile) || !array_key_exists($profile,applicationMeleeVisualBindings()['profiles'] ?? [])) return null;
    }
    if(isset($value['wallImpact'])&&($value['presetId']!=='compact-force-wave'||!$point($value['wallImpact'])))return null;
    $result=['version'=>1,'presetId'=>$value['presetId'],'sceneId'=>$value['sceneId'],'layerId'=>$value['layerId'],'placement'=>$value['placement'],
        'source'=>['x'=>$source['x'],'y'=>$source['y'],'size'=>$source['size']],$cone?'rangeCells':'radiusCells'=>$size];
    if(($value['areaShape']??null)==='square')$result['areaShape']='square';
    if(($value['excludeSource']??false)===true)$result['excludeSource']=true;
    if(isset($value['weaponProfile']))$result['weaponProfile']=$value['weaponProfile'];
    if($weaponEcho)$result['effectId']=$value['effectId'];
    if($value['placement']!=='source')$result['targetPoint']=array_intersect_key($value['targetPoint'],array_flip($thorn?['x','y','rotation']:['x','y']));
    if(isset($value['wallImpact']))$result['wallImpact']=array_intersect_key($value['wallImpact'],array_flip(['x','y']));
    if(in_array($value['placement'],['source','target'],true)&&($value['followAnchor']??false)===true&&is_string($value['anchorTokenId']??null)&&preg_match('/^[A-Za-z0-9_-]{1,180}$/D',$value['anchorTokenId'])){$result['followAnchor']=true;$result['anchorTokenId']=$value['anchorTokenId'];}
    if(($value['followSource']??false)===true){$result['followSource']=true;$result['sourceTokenId']=$value['sourceTokenId'];}
    return $result;
}

function applicationEnchantedWeaponProfile(mixed $profile): ?string {
    return is_string($profile) && $profile !== 'paired-chitin-legs'
        && isset(applicationMeleeVisualBindings()['profiles'][$profile]) ? $profile : null;
}

function applicationEnchantedItemVisualProfile(array $items, string $itemId): ?string {
    foreach ($items['instances'] ?? [] as $item) {
        if (($item['id'] ?? '') !== $itemId) continue;
        return applicationEnchantedWeaponProfile(applicationMeleeVisualBindings()['equipmentTemplates'][$item['templateId'] ?? ''] ?? null);
    }
    return null;
}

function applicationTrustedWeaponEchoSpell(array $effect, string $sceneId, array $map, array $source, array $target, array $items): ?array {
    if (($effect['mode'] ?? '') !== 'echo' || ($effect['trigger'] ?? '') !== 'enchanted-weapon') return null;
    $a = applicationTokenSpatialFootprint($source,$map);
    $b = applicationTokenSpatialFootprint($target,$map);
    $layerId = $a['layerId'] ?? 'ground';
    if (($b['layerId'] ?? 'ground') !== $layerId) return null;
    $profile = applicationEnchantedItemVisualProfile($items,$effect['enchantedItemId'] ?? '')
        ?? applicationEnchantedWeaponProfile($effect['weaponVisualProfile'] ?? null);
    return applicationSpellVisual([
        'version'=>1,'presetId'=>'ethereal-weapon-echo','placement'=>'target','sceneId'=>$sceneId,'layerId'=>$layerId,
        'source'=>['x'=>$a['x'],'y'=>$a['y'],'size'=>$a['size'] ?? 40],
        'targetPoint'=>['x'=>$b['x'],'y'=>$b['y']],'radiusCells'=>1,
        'followSource'=>true,'sourceTokenId'=>$source['id'],'followAnchor'=>true,'anchorTokenId'=>$target['id'],
        'effectId'=>$effect['id'],...($profile !== null ? ['weaponProfile'=>$profile] : [])
    ]);
}

function applicationPublicEnchantedWeaponVisuals(array $state, array $map, string $sceneId, string $layerId, bool $paused, array $visibleIds, callable $visible, ?int $now=null): array {
    if ($paused || $sceneId === '' || empty($state['attackEnhancements'])) return [];
    $map['tokens'] = $state['map']['tokens'] ?? [];
    $tokens = array_column($map['tokens'],null,'id');
    $items = array_column($state['items']['instances'] ?? [],null,'id');
    $shown = static function (?array $token) use ($map,$layerId,$visibleIds,$visible): bool {
        if (!$token || !isset($visibleIds[$token['id']]) || !empty($token['hidden']) || !empty($token['dimSilhouette'])) return false;
        $point = applicationTokenSpatialFootprint($token,$map);
        return ($point['layerId'] ?? 'ground') === $layerId && $visible($point['x'],$point['y']);
    };
    $rows = [];
    $now ??= (int) floor(microtime(true) * 1000);
    $formationLifetime = applicationCombatAnimationDuration(1100);
    foreach (array_slice($state['attackEnhancements'],0,100) as $effect) {
        if (($effect['mode'] ?? '') !== 'echo' || ($effect['trigger'] ?? '') !== 'enchanted-weapon'
            || ($effect['sceneId'] ?? '') !== $sceneId || ($effect['remainingTurns'] ?? 0) <= 0 || !equipmentId($effect['id'] ?? null)) continue;
        $token = $tokens[$effect['targetTokenId'] ?? ''] ?? null;
        $caster = $tokens[$effect['casterTokenId'] ?? ''] ?? null;
        $item = $items[$effect['enchantedItemId'] ?? ''] ?? null;
        $startedAt = $effect['weaponVisualStartedAt'] ?? 0;
        $showFormation = is_int($startedAt) && $startedAt > 0
            && ($effect['weaponVisualPublicStart'] ?? false) === true && $shown($caster);
        if ($shown($token) && ($item['location']['kind'] ?? '') === 'inventory' && $item['location']['characterId'] === equipmentUnitId($token)) {
            $profile = applicationEnchantedWeaponProfile(applicationMeleeVisualBindings()['equipmentTemplates'][$item['templateId'] ?? ''] ?? null)
                ?? applicationEnchantedWeaponProfile($effect['weaponVisualProfile'] ?? null);
            $rows[] = ['id'=>$effect['id'],'tokenId'=>$token['id'],'profile'=>$profile,...($showFormation?['startedAt'=>$startedAt]:[])];
        }
        if ($showFormation && $startedAt <= $now && $now-$startedAt < $formationLifetime) {
            $rows[] = ['id'=>$effect['id'],'tokenId'=>$caster['id'],'role'=>'caster','startedAt'=>$startedAt];
        }
    }
    return $rows;
}

function applicationSpellVisualBindings(string $section='bindings'): array {
    static $configuration=null;
    if($configuration===null){$data=json_decode((string)file_get_contents(__DIR__.'/spell-visual-bindings.json'),true);$configuration=is_array($data)?$data:[];}
    return is_array($configuration[$section]??null)?$configuration[$section]:[];
}
function applicationCoatingWeaponProfile(array $target,array $context): ?string {
    if(empty($context['items']))return null;
    $attacks=$context['weaponAttacks']??$target['weaponAttacks']??[];
    $attack=equipmentAttackRows($attacks,$target,['items'=>$context['items'],'map'=>$context['map']??[]])[0]??[];
    $ids=$attack['equipmentItemIds']??[];
    if(!empty($attack['naturalWeapon'])||!empty($attack['projectile'])||count($ids)!==1)return null;
    foreach($context['items']['instances']??[]as$item)if($item['id']===$ids[0])return applicationMeleeVisualBindings()['equipmentTemplates'][$item['templateId']]??null;
    return null;
}
function applicationTrustedCraftSpellVisual(array $effect,array $context): ?array {
    $binding=($context['bindings']??applicationSpellVisualBindings())[$effect['abilityId']??'']['craftEvent']??null;$source=null;
    foreach($context['map']['tokens']??[]as$token)if($token['id']===($effect['sourceTokenId']??''))$source=$token;
    if(($effect['type']??'')!=='consumable.crafted'||($effect['sceneId']??'')!==($context['sceneId']??'')||!is_array($binding)||$source===null)return null;
    return applicationTrustedSpellVisual(['id'=>$effect['abilityId']],[...$context,'source'=>$source,'bindings'=>[$effect['abilityId']=>$binding]]);
}
function applicationTrustedActivationSpellVisual(array $ability,array $context): ?array {
    $binding=applicationSpellVisualBindings()[$ability['id']??'']['activation']??null;
    return is_array($binding)?applicationTrustedSpellVisual($ability,[...$context,'bindings'=>[$ability['id']=>$binding]]):null;
}
function applicationTrustedSpellVisual(array $ability,array $context): ?array {
    $effect=$ability['tacticalEffect']??[];$kind=($effect['version']??null)===1&&($ability['effect']??'')==='movement'?($effect['kind']??null):null;
    $tactical=$context['tacticalBindings']??applicationSpellVisualBindings('tacticalEffects');
    $binding=($context['bindings']??applicationSpellVisualBindings())[$ability['id']??'']??(is_string($kind)?($tactical[$kind]??null):null);$source=$context['source']??null;$map=$context['map']??null;
    if(!is_array($binding)||!empty($binding['movementStepId'])||!is_array($source)||!is_array($map))return null;
    $footprint=function_exists('applicationTokenSpatialFootprint')?applicationTokenSpatialFootprint($source,$map):$source;
    $target=!empty($binding['followTarget'])&&!empty($context['targetPoint']['id'])?(function_exists('applicationTokenSpatialFootprint')?applicationTokenSpatialFootprint($context['targetPoint'],$map):$context['targetPoint']):null;
    if($target!==null&&($target['layerId']??'ground')!==($footprint['layerId']??'ground'))return null;
    $profile=!empty($binding['weaponFromTarget'])&&is_array($context['targetPoint']??null)?applicationCoatingWeaponProfile($context['targetPoint'],$context):null;
    $fitRadius=!empty($binding['fitTarget'])&&$target!==null?min(20,max(.5,ceil((($target['size']??40)/2/max(.001,$map['gridSize']??0)+.4)*2)/2))
        :(!empty($binding['fitSource'])?min(20,max(.5,ceil((($footprint['size']??40)/2/max(.001,$map['gridSize']??0)+max(0,(float)($binding['sourcePaddingCells']??0))+max(0,(float)($binding['sourcePaddingPixels']??0))/max(.001,(float)($map['gridSize']??0)))*2)/2)):null);
    return applicationSpellVisual(['version'=>1,'presetId'=>isset($binding['variants'])?($binding['variants'][$context['variantId']??'']??''):($binding['presetId']??''),
        'sceneId'=>$context['sceneId']??'','layerId'=>$footprint['layerId']??$map['activeLayerId']??'ground','placement'=>$binding['placement']??'',
        'source'=>['x'=>$footprint['x']??null,'y'=>$footprint['y']??null,'size'=>$footprint['size']??40],'targetPoint'=>$target!==null?['x'=>$target['x'],'y'=>$target['y']]:($context['targetPoint']??null),
        ...(($binding['placement']??'')==='area'&&($ability['damageTargeting']['shape']??'')==='square'?['areaShape'=>'square']:[]),
        ...(($binding['placement']??'')==='area'&&($ability['damageTargeting']['affectCaster']??null)===false?['excludeSource'=>true]:[]),
        ...($profile!==null?['weaponProfile'=>$profile]:[]),
        ...(!empty($binding['handlesKnockbackImpact'])&&!empty($context['wallImpact'])?['wallImpact'=>$context['wallImpact']]:[]),
        'rangeCells'=>$ability['damageTargeting']['lengthCells']??$ability['rangeCells']??$binding['rangeCells']??null,'radiusCells'=>$fitRadius??$ability['damageTargeting']['radiusCells']??$binding['radiusCells']??2,
        'followAnchor'=>($binding['followAnchor']??false)===true||$target!==null,'anchorTokenId'=>$target!==null?$context['targetPoint']['id']:($source['id']??''),
        ...(($binding['followSource']??false)===true?['followSource'=>true,'sourceTokenId'=>$source['id']??'']:[])]);
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
    foreach($context['devices']??[] as $device)if(($device['id']??'')===($attack['attackId']??null)&&($device['definition']['kind']??'')==='launcher'&&($device['status']??'')==='active'&&($device['sceneId']??'')===($attack['sceneId']??'')){
        if(!in_array($attack['status']??'',['applied','blocked','defended','missed'],true)||!empty($attack['hit']['outcome']['requiresGmValidation'])&&empty($context['approved'])||!empty($attack['projectileGate']['pending']))break;
        foreach($context['map']['tokens']??[] as $target)if($target['id']===$attack['targetTokenId']&&($target['layerId']??'ground')===($device['layerId']??'ground'))return applicationSpellVisual(['version'=>1,'presetId'=>'stationary-blue-bolt','placement'=>'target','sceneId'=>$attack['sceneId'],'layerId'=>$device['layerId']??'ground','source'=>['x'=>$device['x'],'y'=>$device['y'],'size'=>$device['size']??40],'targetPoint'=>['x'=>$target['x'],'y'=>$target['y']],'radiusCells'=>1]);
    }
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
    if(!empty($spell['followSource'])&&$spell['sourceTokenId']!==$context['sourceTokenId'])return null;
    if(isset($spell['wallImpact'])&&!$visible($spell['wallImpact']['x'],$spell['wallImpact']['y']))unset($spell['wallImpact']);
    if($spell['placement']==='dash'){if($spell['sourceTokenId']!==$context['sourceTokenId']||isset($spell['impact'])&&(!in_array($spell['impact']['targetTokenId'],$ids,true)||!$visible($spell['impact']['point']['x'],$spell['impact']['point']['y'])))return null;foreach($spell['path']as$p)if(!$visible($p['x'],$p['y']))return null;}
    if($spell['placement']==='melee'&&($spell['sourceTokenId']!==$context['sourceTokenId']||!in_array($spell['targetTokenId'],$ids,true)||!$visible($spell['targetPoint']['x'],$spell['targetPoint']['y'])))return null;
    if($spell['placement']==='melee'&&isset($spell['movementDash']))foreach($spell['movementDash']['path']as$p)if(!$visible($p['x'],$p['y']))return null;
    if($spell['presetId']!=='thorn-wall-growth'&&in_array($spell['placement'],['target','area'],true)&&!$visible($spell['targetPoint']['x'],$spell['targetPoint']['y']))return null;
    return $spell;
}
