<?php
declare(strict_types=1);
require_once __DIR__.'/ability-overdrive.php';
require_once __DIR__.'/armor-reduction.php';
require_once __DIR__.'/item-use-enhancements.php';
require_once __DIR__.'/attack-receipt-retention.php';
require_once __DIR__ . '/character-statistics.php';
require_once __DIR__ . '/unit-authority.php';

require_once __DIR__.'/active-items.php';
require_once __DIR__.'/ability-rule-presets.php';
function applicationAttackEnhancementDefinition(mixed $value): ?array {
    if(is_array($value)&&($value['mode']??'')==='overdrive')return applicationOverdrive($value);
    if(is_array($value)&&($value['mode']??'')==='arbitration-zone'){try{$d=applicationSpecialItemEffect([...$value,'kind'=>'arbitration-zone']);}catch(RuntimeException){return null;}$area=applicationFixedEffectArea($value['fixedArea']??null);return $d&&$area?['mode'=>'arbitration-zone','label'=>$d['label'],'turns'=>$d['turns'],'color'=>$d['color'],'fixedArea'=>$area]:null;}
    if(is_array($value)&&($value['mode']??'')==='next-item-use')return applicationItemUseEnhancementDefinition($value);
    if(is_array($value)&&($value['mode']??'')==='on-hit-effects'){$p=normalizeItemPayload($value['payload']??null);return $p&&in_array($p['kind'],['dot','condition'],true)&&activeItemInt($value['turns']??null,1,999)&&in_array($value['trigger']??'',['all-attacks','melee-weapon','main-weapon'],true)?['mode'=>'on-hit-effects','turns'=>$value['turns'],'trigger'=>$value['trigger'],'requiresHit'=>true,...((applicationEffectFamily($p)?:applicationEffectFamily($p['rider']??[])) ? ['family'=>applicationEffectFamily($p)?:applicationEffectFamily($p['rider']??[])]:[]),'payload'=>$p]:null;}
    if(is_array($value)&&($value['mode']??'')==='immunity')return activeItemTag($value['family']??null)&&activeItemInt($value['turns']??null,1,999)?['mode'=>'immunity','family'=>$value['family'],'turns'=>$value['turns']]:null;
    if(is_array($value)&&($value['mode']??'')==='on-attack-dot'){
        $d=$value['dot']??[];if(!activeItemInt($value['turns']??null,1,999)||!validApplicationAbilityFormula($d['formula']??'')||!activeItemInt($d['turns']??null,1,20))return null;
        if(isset($value['trigger'])&&!in_array($value['trigger'],['all-attacks','melee-weapon'],true))return null;
        if(isset($value['weaponIds'])&&(!is_array($value['weaponIds'])||!array_is_list($value['weaponIds'])||count($value['weaponIds'])>30||count(array_unique($value['weaponIds'],SORT_REGULAR))!==count($value['weaponIds'])))return null;
        foreach($value['weaponIds']??[] as $weaponId)if(!is_string($weaponId)||preg_match('/^[A-Za-z0-9_-]{1,120}$/D',$weaponId)!==1)return null;
        return ['mode'=>'on-attack-dot','turns'=>$value['turns'],...(isset($value['trigger'])?['trigger'=>$value['trigger']]:[]),...(isset($value['weaponIds'])?['weaponIds'=>$value['weaponIds']]:[]),'family'=>'poison','dot'=>['label'=>substr($d['label']??'Poison',0,120),'formula'=>$d['formula'],'turns'=>$d['turns'],'family'=>'poison','damageType'=>'poison'],'perTargetPerTurn'=>1,'requiresHit'=>true];
    }
    if(is_array($value)&&($value['mode']??'')==='stats'){
        if(!is_int($value['turns']??null)||$value['turns']<1||$value['turns']>999||!is_array($value['bonuses']??null))return null;
        $bonuses=[];foreach($value['bonuses'] as $key=>$amount){
            if(!in_array($key,[...array_keys(applicationStatLabels()),'mentalResistance','initiativeBonus','physicalResistance','magicalResistance','attackResultBonus'],true)||!is_int($amount)||$amount < -100||$amount>100)return null;
            if($amount!==0)$bonuses[$key]=$amount;
        }
        return $bonuses!==[]?['mode'=>'stats','turns'=>$value['turns'],'bonuses'=>$bonuses]:null;
    }
    if (!is_array($value) || !in_array($value['mode'] ?? '', ['bonus', 'replace', 'echo'], true)
        || ($type = (($value['damageType'] ?? '') === 'inherit' ? 'inherit' : applicationDamageType($value['damageType'] ?? null))) === null
        || !validApplicationOptionalDamageElement($value)
        || !is_int($value['turns'] ?? null) || $value['turns'] < 1 || $value['turns'] > 999) return null;
    $formula = strtolower(preg_replace('/\s+/', '', (string) ($value['formula'] ?? '')));
    if (!validApplicationAbilityFormula($formula) || ($value['mode'] === 'bonus'
        && (preg_match('/^\d+$/D', $formula) !== 1 || (float) $formula > 1000000000))) return null;
    $allyFormula = strtolower(preg_replace('/\s+/', '', (string) ($value['allyFormula'] ?? $formula)));
    if ($value['mode'] === 'echo' && !validApplicationAbilityFormula($allyFormula)) return null;
    if ($value['mode'] === 'echo' && isset($value['trigger']) && !in_array($value['trigger'], ['all-attacks', 'melee-weapon', 'main-weapon', 'enchanted-weapon'], true)) return null;
    if($value['mode']==='bonus'&&isset($value['trigger'])&&$value['trigger']!=='all-attacks')return null;
    return [...($value['mode']==='bonus'&&($value['trigger']??'')==='all-attacks'?['trigger'=>'all-attacks']:[]),'mode' => $value['mode'], 'formula' => $formula, 'damageType' => $type, ...applicationOptionalDamageElement($value), 'turns' => $value['turns'],
        ...($value['mode'] === 'echo' ? ['allyFormula' => $allyFormula, ...(in_array($value['trigger']??'',['melee-weapon','main-weapon','enchanted-weapon'],true)?['trigger'=>$value['trigger']]:[]),...(($value['requiresHit']??false)===true?['requiresHit'=>true]:[]),...(($value['expiry']??'')==='after-next-turn'?['expiry'=>'after-next-turn']:[]),...(!empty($value['immunityLabel'])&&is_string($value['immunityLabel'])?['immunityLabel'=>substr($value['immunityLabel'],0,240)]:[])] : [])];
}

function applicationAttackTriggersEnhancement(array $effect, array $attack): bool {
    $effect=applicationConfiguredAttackEnhancement((string)($effect['abilityId']??''),$effect);
    if(!empty($effect['weaponIds'])&&!in_array($attack['echoAttackContext']['weaponId']??'', $effect['weaponIds'],true))return false;
    if(!empty($effect['requiresHit'])&&(empty($attack['hit']['outcome']['success'])||in_array($attack['status']??'',['missed','defended','cancelled','rejected'],true)))return false;
    if(($effect['trigger']??'')==='enchanted-weapon')return ($attack['echoAttackContext']['weaponBased']??false)===true&&($attack['echoAttackContext']['naturalWeapon']??false)!==true&&($attack['echoAttackContext']['projectile']??false)!==true&&in_array($effect['enchantedItemId']??null,$attack['echoAttackContext']['equipmentItemIds']??[],true);
    if(($effect['trigger']??'')==='main-weapon')return ($attack['echoAttackContext']['weaponBased']??false)===true&&($attack['echoAttackContext']['projectile']??false)!==true&&($attack['echoAttackContext']['weaponId']??'')===($effect['weaponId']??null);
    if(($effect['trigger']??'')==='melee-weapon'&&($effect['mode']??'')==='on-attack-dot'){
        $context=$attack['echoAttackContext']??[];$parts=$attack['damageComponents']??[];if($parts===[])$parts=[['type'=>$attack['damageType']??'physical']];
        return ($context['weaponBased']??false)===true&&($context['projectile']??false)!==true&&($context['reach']??'')!=='ranged'&&array_filter($parts,static fn($part)=>in_array(applicationDamageArmorType($part['type']??null),['physical','ignore'],true))!==[];
    }
    return ($effect['trigger'] ?? '') !== 'melee-weapon'
        || (($attack['echoAttackContext']['weaponBased'] ?? false) === true && ($attack['echoAttackContext']['projectile'] ?? false) !== true);
}

function applicationEnhancementHolder(array $source): string {
    return isset($source['resources']) && empty($source['characterId']) && ($source['unitKind']??'')!=='token' ? 'character:' . ($source['id'] ?? '')
        : (unitUsesCharacterSheet($source)
            ? 'character:' . $source['characterId'] : 'token:' . ($source['id'] ?? ''));
}

// Presentation only: stable identifiers, no name-based gameplay rule.
function applicationBloodEnhancementSkin(array $effect): array {
    if(($effect['abilityId']??'')!=='ability-2e0bbf2b-ba81-4af6-b377-017615292509')return [];
    $start=$effect['visualStartedAt']??null;
    return ['skin'=>'champion-blood',...(is_int($start)&&$start>0?['startedAt'=>$start]:[])];
}

function applicationEnhancementVisualSkin(array $effect): array {
    $blood=applicationBloodEnhancementSkin($effect);if($blood!==[])return $blood;
    $skins=['ability-inho-metabolic-acceleration'=>'metabolic-overdrive','ability-6a6d080e-96b7-4d12-b25b-d179d5e17766'=>'stigma-awakening','ability-5f30d8c5-4efc-47d5-bf03-ea5cb192222b'=>'sacred-circle'];
    $skin=$skins[$effect['abilityId']??'']??null;if($skin===null)return [];
    $start=$effect['visualStartedAt']??null;
    return ['skin'=>$skin,...(is_int($start)&&$start>0&&$start<=9007199254740991?['startedAt'=>$start]:[])];
}

function applicationTokenBuffVisuals(array $token, array $state, string $sceneId): array {
    if (($token['dimSilhouette'] ?? false) === true) return [];
    $holder = applicationEnhancementHolder($token); $result = [];
    $tokens = ($state['activeSceneId'] ?? $state['activeScene']['id'] ?? '') === $sceneId ? ($state['map']['tokens'] ?? []) : [];
    if ($tokens === []) foreach ($state['scenes'] ?? [] as $scene) if (($scene['id'] ?? '') === $sceneId) $tokens = $scene['combat']['map']['tokens'] ?? [];
    $received=applicationActiveAttackEnhancements($token,$state,$sceneId);
    foreach($state['attackEnhancements']??[] as $effect)if(isset($effect['aura'])&&($effect['casterTokenId']??'')===$token['id']&&($effect['sceneId']??'')===$sceneId&&($effect['remainingTurns']??0)>0&&applicationAttackEnhancement($effect)!==null&&!in_array($effect['id'],array_column($received,'id'),true))$received[]=$effect;
    $map=($state['activeSceneId']??$state['activeScene']['id']??'')===$sceneId?($state['map']??[]):[];
    if($map===[])foreach($state['scenes']??[] as $scene)if(($scene['id']??'')===$sceneId)$map=$scene['combat']['map']??[];
    foreach($received as $effect){
        $fixed=isset($effect['fixedArea']);$image = $fixed?null:($effect['abilityImage'] ?? null);
        if (!$fixed && !$image) foreach ($tokens as $caster) if (($caster['id'] ?? '') === ($effect['casterTokenId'] ?? '')) {
            $abilities = $caster['abilities'] ?? [];
            if (unitUsesCharacterSheet($caster)) foreach ($state['characters'] ?? [] as $sheet) if ($sheet['id'] === $caster['characterId']) $abilities = $sheet['abilities'] ?? [];
            foreach ($abilities as $ability) if (($ability['id'] ?? '') === ($effect['abilityId'] ?? '')) $image = $ability['image'] ?? null;
        }
        $result[] = ['id' => (string) $effect['id'], 'label' => $fixed?'Zone d’affaiblissement d’armure':(string) ($effect['label'] ?? 'Effet actif'),
            'remainingTurns' => max(1, min(999, (int) $effect['remainingTurns'])),...applicationEnhancementVisualSkin($effect),...(($effect['mode']??'')==='next-item-use'?['remainingUses'=>1,'durationLabel'=>($effect['expiry']??'')==='combat-or-rest'?'Prochaine potion · fin du combat ou repos court/long':'Prochaine potion · jusqu’au repos long','symbol'=>'⚗']:[]),...(($effect['mode']??'')==='on-attack-dot'&&(applicationAttackEnhancement($effect)['trigger']??'')==='melee-weapon'&&($effect['targetTokenId']??'')===($token['id']??'')?['skin'=>'venom-source']:[]),...(isset($effect['aura'])&&($effect['casterTokenId']??'')===$token['id']&&($map['gridSize']??0)>0?['auraScale'=>min(10000,1+2*$effect['aura']['radiusCells']*$map['gridSize']/max(1,$token['size']??40))]:[]),...(($effect['expiry']??'')==='rests'?['durationLabel'=>'Prochain repos long ou '.(int)$effect['remainingShortRests'].' repos court(s)','remainingShortRests'=>(int)$effect['remainingShortRests']]:[]), 'image' => normalizePersistedImageReference($image)];
    }
    $poisonTurns=0;foreach($state['damageOverTime']??[] as $dot)if(($dot['family']??'')==='poison'&&($dot['sceneId']??'')===$sceneId&&($dot['targetTokenId']??'')===($token['id']??'')&&($dot['remainingTurns']??0)>0)$poisonTurns=max($poisonTurns,(int)$dot['remainingTurns']);
    if($poisonTurns>0)$result[]=['id'=>'status-poison-'.$token['id'],'label'=>'Empoisonné','remainingTurns'=>min(999,$poisonTurns),'skin'=>'venom-target','symbol'=>'☠','image'=>null];
    $bleedingTurns=0;foreach($state['damageOverTime']??[] as $dot)if(($dot['family']??'')==='bleeding'&&($dot['sceneId']??'')===$sceneId&&($dot['targetTokenId']??'')===($token['id']??'')&&($dot['remainingTurns']??0)>0&&!applicationElementalPeriodicDamageSuppressed($token,$dot,array_column($state['characters']??[],null,'id')))$bleedingTurns=max($bleedingTurns,(int)$dot['remainingTurns']);
    if($bleedingTurns>0)$result[]=['id'=>'status-bleeding-'.$token['id'],'label'=>'Saignement','remainingTurns'=>min(999,$bleedingTurns),'skin'=>'bleeding','symbol'=>'🩸','image'=>null];
    $protection=0;foreach($state['nextAttackGuards']??[] as $guard)if(($guard['effectId']??'')==='protection'&&($guard['sceneId']??'')===$sceneId&&($guard['targetTokenId']??'')===($token['id']??''))$protection+=(int)$guard['percent'];
    if($protection>0){$attacks=(int)ceil($protection/100);$result[]=['id'=>'status-protection-'.$token['id'],'label'=>'Protection · réserve '.$protection.' %','remainingTurns'=>$attacks,'remainingAttacks'=>$attacks,'symbol'=>'🛡','image'=>null];}
    return array_slice($result, 0, 100);
}

function applicationTransferAttackEnhancements(array $effects, array $source, string $fromSceneId, string $toSceneId, array $initiative): array {
    $holder = applicationEnhancementHolder($source);
    return array_map(static fn(array $effect): array => empty($effect['fixedArea']) && $effect['sceneId'] === $fromSceneId && $effect['holder'] === $holder
        ? [...$effect, 'sceneId' => $toSceneId, 'targetTokenId' => $source['id'],
            'combatId' => ($initiative['active'] ?? false) === true ? ($initiative['combatId'] ?? '') : '', 'lastTurnSerial' => (int) ($initiative['turnSerial'] ?? 0)]
        : $effect, $effects);
}

function applicationAttackEnhancement(mixed $value): ?array {
    $definition=applicationAttackEnhancementDefinition(is_array($value)?applicationConfiguredAttackEnhancement((string)($value['abilityId']??''),$value):$value);if($definition===null)return null;
    if(isset($value['bonuses'])&&($definition['mode']??'')!=='stats'){
        $stats=applicationAttackEnhancementDefinition(['mode'=>'stats','turns'=>$definition['turns'],'bonuses'=>$value['bonuses']]);
        if($stats===null)return null;$definition['bonuses']=$stats['bonuses'];
    }
    if(isset($value['aura'])){
        if(in_array($definition['mode']??'',['immunity','next-item-use'],true))return null;
        $a=$value['aura'];if(!is_array($a)||!(is_int($a['radiusCells']??null)||is_float($a['radiusCells']??null))||!is_finite((float)$a['radiusCells'])||floor($a['radiusCells']*2)!=$a['radiusCells']*2||$a['radiusCells']<0.5||$a['radiusCells']>20)return null;
        foreach(['affectCaster','affectAllies','affectEnemies'] as $key)if(!is_bool($a[$key]??null))return null;
        if(!$a['affectCaster']&&!$a['affectAllies']&&!$a['affectEnemies'])return null;
        $definition['aura']=array_intersect_key($a,array_flip(['radiusCells','affectCaster','affectAllies','affectEnemies']));
    }
    if(isset($value['fixedArea'])){
        $fixed=applicationFixedEffectArea($value['fixedArea']);if($fixed===null||isset($value['aura'])||($definition['mode']??'')!=='stats')return null;
        $definition['fixedArea']=$fixed;
    }
    return [...$definition,...(($value['target']??'')==='self'?['target'=>'self']:[]),...(($value['criticalFailureAsFailure']??false)===true?['criticalFailureAsFailure'=>true]:[])];
}

function applicationActiveAttackEnhancements(array $source,array $state,string $sceneId):array {
    if(empty($source['id']))return [];
    return array_values(array_filter($state['attackEnhancements']??[],static fn($effect)=>
        (($effect['sceneId']??'')===$sceneId||in_array($effect['expiry']??'',['rests','long-rest','combat-or-rest'],true))&&($effect['remainingTurns']??0)>0&&applicationAttackEnhancement($effect)!==null
        &&(($effect['trigger']??'')==='enchanted-weapon'?enchantmentOnUnit($effect,$source,$state):isset($effect['aura'])||isset($effect['fixedArea'])||($effect['holder']??'')===applicationEnhancementHolder($source))
        &&(!isset($effect['aura'])||applicationEnhancementAuraAffects($effect,$source,$state,$sceneId))
        &&(!isset($effect['fixedArea'])||applicationFixedAreaAffects($effect,$source,$state,$sceneId))));
}
function applicationEnhancementAuraAffects(array $effect,array $source,array $state,string $sceneId):bool {
    $map=($state['activeSceneId']??$state['activeScene']['id']??'')===$sceneId?($state['map']??[]):[];
    if($map===[])foreach($state['scenes']??[] as $scene)if(($scene['id']??'')===$sceneId)$map=$scene['combat']['map']??[];
    $tokens=$map['tokens']??[];$caster=null;foreach($tokens as $token)if($token['id']===($effect['casterTokenId']??''))$caster=$token;
    if(!$caster)return false;
    $controller=static fn($token)=>applicationUnitControllerId($token,$state);
    $recipients=isset($source['resources'])?array_values(array_filter($tokens,static fn($token)=>applicationEnhancementHolder($token)===applicationEnhancementHolder($source))):[$source];
    $a=[...$effect['aura'],'affectAllies'=>!empty($caster['friendlyFire'])||$effect['aura']['affectAllies']];$near=onlineDamageAreaTargets($recipients,$caster,$map,(float)$a['radiusCells'],true);
    foreach($recipients as $recipient)foreach($near as $token)if(($token['id']??'')===($recipient['id']??null)&&applicationDamageAreaAffects($a,$caster['id'],$controller($caster),$token['id'],$controller($token),unitIdForToken($caster),unitIdForToken($token)))return true;
    return false;
}
function onlineAttackEnhancementState(PDO $connection,array &$records,array $pending,string $sceneId,?array $activity=null):array {
    $activity??=$pending['activity']['payload']??applicationDomainPayload($records,'activity');
    if(!array_filter($activity['attackEnhancements']??[],static fn($effect)=>(isset($effect['aura'])||isset($effect['fixedArea']))&&($effect['sceneId']??'')===$sceneId&&($effect['remainingTurns']??0)>0))return $activity;
    if(!isset($records['map:'.$sceneId]))$records=array_replace($records,applicationDomainRecords($connection,['map:'.$sceneId]));
    $map=$pending['map:'.$sceneId]['payload']??applicationDomainPayload($records,'map:'.$sceneId);
    $map['tokens']=onlineSceneTokensForLights($connection,$records,$pending,$sceneId);
    foreach($map['tokens'] as &$token)$token['controllerPlayerId']=onlineTokenControllerIdFromRecords($connection,$records,$token);unset($token);
    return [...$activity,'activeSceneId'=>$sceneId,'map'=>$map];
}
function applicationAttackResultBonus(array $source,array $state,string $sceneId):int {
    return array_sum(array_map(static fn($effect)=>(int)($effect['bonuses']['attackResultBonus']??0),applicationActiveAttackEnhancements($source,$state,$sceneId)));
}
function applicationEnhancedResistanceTarget(array $source,array $state,string $sceneId):array {
    $bonus=['physical'=>0,'magical'=>0];foreach(applicationActiveAttackEnhancements($source,$state,$sceneId) as $effect){$bonus['physical']+=(int)($effect['bonuses']['physicalResistance']??0);$bonus['magical']+=(int)($effect['bonuses']['magicalResistance']??0);}
    return [...$source,'resistanceBonuses'=>$bonus,'elementalResistances'=>applicationEffectiveElementalResistances($source,$state,$sceneId)];
}

function applicationEffectiveElementalResistances(array $source, array $state, string $sceneId): array {
    $resistances = normalizeApplicationElementalResistances($source['elementalResistances'] ?? null);
    if (applicationHasEffectImmunity($source,$state,$sceneId,'poison')) $resistances['poison'] = 100;
    return $resistances;
}

function applicationRollCriticalFailureProtected(array $source,array $activity,string $sceneId): bool {
    foreach(applicationActiveAttackEnhancements($source,$activity,$sceneId) as $effect)if(($effect['criticalFailureAsFailure']??false)===true)return true;
    return false;
}

function applicationActivateAttackEnhancement(array &$activity, array $ability, array $source, string $sceneId, array $initiative, string $id, ?array $recipient = null, array $enchantment = []): void {
    $definition = applicationAttackEnhancement($ability['attackEnhancement'] ?? null);
    if ($definition === null) return;
    $recipient ??= $source;if(isset($definition['aura'])||($definition['target']??'')==='self')$recipient=$source;
    if ((applicationEffectFamily($definition)==='poison' || applicationEffectFamily($ability)==='poison')
        && !applicationBeneficialPoisonEnhancement($definition) && applicationHasEffectImmunity($recipient,$activity,$sceneId,'poison')) return;
    $holder = applicationEnhancementHolder($recipient);
    if ($definition['mode'] === 'echo' && $holder !== applicationEnhancementHolder($source)) $definition['formula'] = $definition['allyFormula'];
    $previousOverdrive=null;if($definition['mode']==='overdrive')foreach($activity['attackEnhancements']??[] as $entry)if(($entry['mode']??'')==='overdrive'&&($entry['holder']??'')===$holder){$previousOverdrive=$entry;break;}
    $effects = array_values(array_filter($activity['attackEnhancements'] ?? [], static fn(array $effect): bool =>
        !($definition['mode']==='overdrive'&&($effect['mode']??'')==='overdrive'&&($effect['holder']??'')===$holder) &&
        (($definition['trigger']??'')==='enchanted-weapon' ? ($effect['abilityId']!==$ability['id']||($effect['holder']??'')!==$holder&&($effect['enchantedItemId']??'')!==($enchantment['enchantedItemId']??'')) : ($effect['holder'] !== $holder || !($effect['abilityId'] === $ability['id'] || $definition['mode']==='next-item-use'&&($effect['mode']??'')==='next-item-use'&&($effect['itemKind']??'')===$definition['itemKind'])))));
    if (count($effects) >= 100) throw new RuntimeException('Trop d’effets sur les attaques sont déjà actifs.', 409);
    $effects[] = [...$definition, 'id' => $id, 'holder' => $holder, 'abilityId' => $ability['id'], 'label' => $ability['name'].(!empty($enchantment['enchantedItemName'])?' — '.$enchantment['enchantedItemName']:''), 'sceneId' => $sceneId, ...$enchantment,
        ...(in_array($ability['id']??'',['ability-inho-metabolic-acceleration','ability-2e0bbf2b-ba81-4af6-b377-017615292509','ability-6a6d080e-96b7-4d12-b25b-d179d5e17766','ability-5f30d8c5-4efc-47d5-bf03-ea5cb192222b'],true)?['visualStartedAt'=>(int)floor(microtime(true)*1000)]:[]),
        ...(!empty($ability['image']) ? ['abilityImage' => $ability['image']] : []),
        ...(($definition['trigger']??'')==='main-weapon'?['weaponId'=>$recipient['weaponAttacks'][0]['id']??'']:[]),...(($definition['expiry']??'')==='after-next-turn'?['hasStarted'=>false]:[]),
        'targetTokenId' => $recipient['id'], 'casterTokenId' => $source['id'], 'remainingTurns' => $definition['turns'],
        'combatId' => ($initiative['active'] ?? false) === true ? ($initiative['combatId'] ?? '') : '', 'lastTurnSerial' => (int) ($initiative['turnSerial'] ?? 0),
        ...($previousOverdrive?array_intersect_key($previousOverdrive,array_flip(['id','remainingTurns','combatId','lastTurnSerial','deferredFatigue'])):[])];
    $activity['attackEnhancements'] = $effects;
    if($definition['mode']==='overdrive')applicationRechargeOverdriveTimers($activity,$source,$definition);
}

function applicationPausePoisonEnhancement(array $effect): ?array {
    return ($effect['mode'] ?? '') === 'on-attack-dot' && ($effect['remainingTurns'] ?? 0) > 0
        ? [...$effect, 'combatId'=>'', 'lastTurnSerial'=>0] : null;
}

function applicationAdvanceAttackEnhancements(array $effects, string $sceneId, array $previous, array $initiative, bool $manual = false, array $tokens = []): array {
    if($manual){$remaining=[];foreach($effects as $effect){
        if(($effect['sceneId']??'')!==$sceneId||in_array($effect['expiry']??'',['rests','long-rest','combat-or-rest'],true)){$remaining[]=$effect;continue;}
        if(($effect['expiry']??'')==='after-next-turn'){if(empty($effect['hasStarted'])){$effect['hasStarted']=true;$remaining[]=$effect;}continue;}
        $effect['remainingTurns']--;if($effect['remainingTurns']>0)$remaining[]=$effect;
    }return $remaining;}
    if (($previous['active'] ?? false) === true && ($initiative['active'] ?? false) !== true) {
        $remaining=[];
        foreach($effects as $effect) {
            if(in_array($effect['expiry']??'',['rests','long-rest'],true)||$effect['sceneId']!==$sceneId&&!(($effect['expiry']??'')==='combat-or-rest'&&array_filter($tokens,static fn($token)=>applicationEnhancementHolder($token)===($effect['holder']??''))))$remaining[]=$effect;
            elseif (($poison = applicationPausePoisonEnhancement($effect)) !== null) $remaining[] = $poison;
            elseif (($effect['mode'] ?? '') === 'immunity' && ($effect['family'] ?? '') === 'poison' && $effect['remainingTurns'] > 0)
                $remaining[] = [...$effect, 'combatId'=>'', 'lastTurnSerial'=>0];
            elseif(isset($effect['fixedArea']))$remaining[]=[...$effect,'combatId'=>'','lastRound'=>0];
        }
        return $remaining;
    }
    if (($initiative['active'] ?? false) === true && (($previous['active'] ?? false) !== true || ($previous['combatId'] ?? '') !== ($initiative['combatId'] ?? ''))) {
        $effects = array_map(static function (array $effect) use ($sceneId, $initiative): array {
            if (($effect['sceneId'] ?? '') !== $sceneId) return $effect;
            if (applicationPausePoisonEnhancement($effect) !== null)
                return [...$effect, 'combatId'=>$initiative['combatId']??'', 'lastTurnSerial'=>(int)($initiative['turnSerial']??0)];
            if (isset($effect['fixedArea'])) return [...$effect, 'combatId'=>$initiative['combatId']??'', 'lastRound'=>max(1,(int)($initiative['round']??1))];
            return $effect;
        }, $effects);
    }
    if (($initiative['active'] ?? false) !== true || (int) ($initiative['turnSerial'] ?? 0) <= (int) ($previous['turnSerial'] ?? 0)) return $effects;
    $tokenId = $initiative['order'][$initiative['currentIndex'] ?? 0] ?? '';
    $remaining = [];
    foreach ($effects as $effect) {
        if ($effect['sceneId'] !== $sceneId || in_array($effect['expiry']??'',['rests','long-rest','combat-or-rest'],true)) { $remaining[] = $effect; continue; }
        if(isset($effect['fixedArea'])){
            $round=max(1,(int)($initiative['round']??1));
            if(($previous['active']??false)!==true||($effect['combatId']??'')!==($initiative['combatId']??'')) {
                $remaining[]=[...$effect,'combatId'=>$initiative['combatId']??'','lastRound'=>$round];continue;
            }
            $last=max((int)($effect['lastRound']??$previous['round']??1),(int)($previous['round']??1));
            $effect['remainingTurns']-=max(0,$round-$last);$effect['lastRound']=max($last,$round);
            if($effect['remainingTurns']>0)$remaining[]=$effect;continue;
        }
        if (($effect['combatId'] ?? '') !== '' && $effect['combatId'] !== ($initiative['combatId'] ?? '')) continue;
        if(($effect['expiry']??'')==='after-next-turn'){if(!empty($effect['hasStarted'])&&($previous['order'][$previous['currentIndex']??0]??'')===$effect['targetTokenId'])continue;if($tokenId===$effect['targetTokenId'])$effect['hasStarted']=true;$remaining[]=$effect;continue;}
        if ($effect['targetTokenId'] === $tokenId && (int) ($effect['lastTurnSerial'] ?? 0) < $initiative['turnSerial']) {
            $effect['remainingTurns']--; $effect['lastTurnSerial'] = $initiative['turnSerial'];
        }
        if ($effect['remainingTurns'] > 0) $remaining[] = $effect;
    }
    return $remaining;
}

function applicationEnhancementDamageType(array $effect,array $attack): string {
    if (($effect['damageType']??'')==='inherit') return normalizeOnlineDamageType($attack['damageType']??null);
    $type = normalizeOnlineDamageType($effect['damageType']??null);
    return $type === 'ignore' && applicationEffectFamily($effect) === 'poison' ? 'poison' : $type;
}

function applicationEnhancementDamageElement(array $effect, array $attack): ?string
{
    return applicationDamageElement($effect['damageElement'] ?? null)
        ?? (($effect['damageType'] ?? '') === 'inherit' ? applicationDamageElement($attack['damageElement'] ?? null) : null);
}

function applicationEnhancedBasicAttack(array $weapon, array $source, array $activity, string $sceneId,bool $bonuses=true): array {
    return applicationEnhancedAttackDamage($weapon,$source,$activity,$sceneId,true,$bonuses);
}
function applicationEnhancedAttackDamage(array $weapon,array $source,array $activity,string $sceneId,bool $weaponBased=false,bool $bonuses=true,bool $replacement=true):array {
    if(!$weaponBased&&applicationMaximumFormula($weapon['formula'])<=0)return $weapon;
    $effects = array_values(array_filter(applicationActiveAttackEnhancements($source,$activity,$sceneId),static fn($effect)=>in_array($effect['mode']??'',['bonus','replace'],true)&&($bonuses||$effect['mode']!=='bonus')&&($replacement||$effect['mode']!=='replace')&&($weaponBased||($effect['mode']??'')==='bonus'&&($effect['trigger']??'')==='all-attacks')));
    if ($effects === []) return $weapon;
    $replacement = null;
    foreach ($effects as $effect) if ($effect['mode'] === 'replace') $replacement = $effect;
    $parts = $replacement !== null
        ? [applicationSimpleDamageComponent(applicationEnhancementDamageType($replacement,$weapon),$replacement['formula'],applicationEnhancementDamageElement($replacement,$weapon))]
        : ($weapon['damageComponents'] ?? [applicationSimpleDamageComponent($weapon['damageType']??'physical',$weapon['formula'],applicationDamageElement($weapon['damageElement']??null))]);
    foreach ($effects as $effect) {
        if ($effect['mode'] !== 'bonus') continue;
        $initial = ['damageType'=>$parts[0]['type'], 'damageElement'=>$parts[0]['element']??null];
        $part = applicationSimpleDamageComponent(applicationEnhancementDamageType($effect,$initial),$effect['formula'],applicationEnhancementDamageElement($effect,$initial));
        if ($effect['damageType'] === 'inherit' && ($parts[0]['ignoreArmor'] ?? false) === true) $part['ignoreArmor'] = true;
        $index = null;
        foreach ($parts as $i => $candidate) if (applicationDamageComponentKey($candidate) === applicationDamageComponentKey($part)) $index = $i;
        if ($index !== null) $parts[$index]['formula'] .= '+' . $effect['formula'];
        else $parts[] = $part;
    }
    $formula = sumEffectFormulas(array_column($parts, 'formula'));
    if (!validApplicationAbilityFormula($formula)) throw new RuntimeException('Les dégâts cumulés des effets dépassent la limite autorisée.', 409);
    return [...$weapon, 'formula' => $formula, 'damageType' => $parts[0]['type'], 'damageComponents' => $parts,
        'enhancementLabel' => implode(' · ', array_column($effects, 'label'))];
}

// Persist the triggering attack and its independent receipts in the same transaction.
// A remarkable roll freezes the effects until MJ approval; echoes never recurse.
function onlineApplyAttackEchoes(PDO $connection, array &$records, array &$pending, array $attack, bool $approved = false): array {
    onlineBreakAttackTorch($connection,$records,$pending,$attack,$approved);
    if (!empty($attack['echoParentId']) || !empty($attack['deviceOrigin']) || !empty($attack['echoProcessed']) || !empty($attack['areaAnchorOnly'])) return $attack;
    $sceneId = $attack['sceneId'];
    $sourceKey = onlineTokenDomainKey($sceneId, $attack['sourceTokenId']);
    $targetKey = onlineTokenDomainKey($sceneId, $attack['targetTokenId']);
    $source = $pending[$sourceKey]['payload'] ?? applicationDomainPayload($records, $sourceKey);
    $target = $pending[$targetKey]['payload'] ?? applicationDomainPayload($records, $targetKey);
    $activity = $pending['activity']['payload'] ?? applicationDomainPayload($records, 'activity');
    $holder = applicationEnhancementHolder($source);
    $equipmentState=onlineEquipmentState($connection,$records,$pending,$sceneId);
    $enhancementState=array_replace($equipmentState,onlineAttackEnhancementState($connection,$records,$pending,$sceneId,$activity));
    $weaponEchoCues = [];
    $attack['echoDefinitions'] ??= array_values(array_filter(applicationActiveAttackEnhancements($source,$enhancementState,$sceneId), static fn(array $effect): bool =>
        ($effect['mode'] ?? '') === 'echo' && applicationAttackTriggersEnhancement($effect, $attack)));
    if ($attack['echoDefinitions'] !== [] && $attack['status'] !== 'awaiting-opposition' && ($attack['status'] !== 'pending' || $approved)) {
        $attack['echoProcessed'] = true;
        $attack['echoAttacks'] = [];
        $identity = ['id' => $attack['accountId'], 'display_name' => $attack['playerName'],
            'effective_mode' => $attack['attackerRole'], 'permanent_role' => $attack['attackerRole']];
        $strikeEchoes=[];foreach($attack['echoDefinitions'] as $effect)for($strike=0;$strike<max(1,min(10,(int)($attack['damageRepetitions']??1)));$strike++)$strikeEchoes[]=$effect;
        foreach ($strikeEchoes as $effect) {
            if(!applicationAttackTriggersEnhancement($effect,$attack))continue;
            $target = $pending[$targetKey]['payload'] ?? applicationDomainPayload($records, $targetKey);
            $characterKey='character:'.($target['characterId']??'');$character=$pending[$characterKey]['payload']??applicationDomainPayload($records,$characterKey);
            if(applicationEffectFamily($effect)==='poison'&&applicationHasEffectImmunity($target,$activity,$sceneId,'poison'))continue;
            if(!empty($effect['immunityLabel'])&&in_array($effect['immunityLabel'],normalizeOnlineConditions($character['conditionImmunities']??$target['conditionImmunities']??[]),true))continue;
            if ($source === [] || $target === [] || onlineTokenIsDead($target, onlineTokenControllerIdFromRecords($connection, $records, $target) !== '')
                || in_array($attack['status'], ['cancelled', 'rejected'], true)) break;
            $execution = ['id' => $effect['id'], 'revision' => 1, 'abilityId' => $effect['abilityId'], 'abilityName' => $effect['label'],
                'sourceTokenId' => $source['id'], 'sourceName' => $source['name'], 'sceneId' => $sceneId, 'layerId' => $attack['layerId'],
                'echoParentId' => $attack['id'], 'echoAreaRadiusCells' => $attack['areaRadiusCells'] ?? 0, 'echoApproved' => $approved, 'currentStepIndex' => 0, 'workflow' => ['steps' => [['spendOptions' => [['id' => 'echo', 'label' => 'Frappe rémanente']]]]]];
            $echo = onlineCounterDamageAttack($connection, $records, $pending, $identity, $execution,
                ['targetTokenId' => $target['id'], 'optionId' => 'echo', 'opposed' => false, 'effect' => ['formula' => $effect['formula'], 'damageType' => applicationEnhancementDamageType($effect,$attack), 'damageElement'=>applicationEnhancementDamageElement($effect,$attack)]],
                'echo-' . randomToken(18), $attack['attackerRole'] === 'gm');
            $attack['echoAttacks'][] = $echo;
            if (($effect['trigger'] ?? '') === 'enchanted-weapon') {
                $visualMap ??= [...$equipmentState['map'], 'tokens' => onlineSceneTokensForLights($connection,$records,$pending,$sceneId)];
                $spell = applicationTrustedWeaponEchoSpell($effect,$sceneId,$visualMap,$source,$target,$equipmentState['items']);
                if ($spell !== null) $weaponEchoCues[] = ['id' => 'weapon-echo-'.$echo['id'], 'spell' => $spell];
            }
        }
    }
    $activity = $pending['activity']['payload'] ?? $activity;
    foreach ($weaponEchoCues as $cue) {
        $spell = $cue['spell'];
        appendApplicationAbilityCueEvent($activity,normalizeApplicationAbilityCompletionCue(['spell'=>$spell]),
            $sceneId,$spell['layerId'],$source['id'],$attack['visibility']??'gm',$cue['id']);
    }
    foreach ($activity['pendingAttacks'] ?? [] as $i => $entry) if ($entry['id'] === $attack['id']) $activity['pendingAttacks'][$i] = $attack;
    foreach ($activity['attackReceipts'] ?? [] as $i => $entry) if (($entry['attack']['id'] ?? '') === $attack['id']) $activity['attackReceipts'][$i]['attack'] = $attack;
    queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
    return $attack;
}

function onlineFailedChainEchoes(PDO $connection, array &$records, array &$pending, array $current, array $next, string $action, array $identity, bool $isGm): array {
    $step = $current['workflow']['steps'][$current['currentStepIndex']] ?? [];
    $state = $next['stepStates'][$step['id'] ?? ''] ?? [];
    if (($step['type'] ?? '') !== 'attack-chain' || !in_array($action, ['prepare-chain', 'gate-roll'], true) || empty($state['plan']['targetTokenId'])) return [];
    $source = applicationDomainPayload($records, onlineTokenDomainKey($next['sceneId'], $next['sourceTokenId']));
    $holder = applicationEnhancementHolder($source); $activity = $pending['activity']['payload'] ?? applicationDomainPayload($records, 'activity');
    $equipmentState=onlineEquipmentState($connection,$records,$pending,$next['sceneId']);$weapon=array_values(array_filter(equipmentAttackRows($source['weaponAttacks']??[],$source,$equipmentState),static fn($w)=>($w['id']??'')===($state['plan']['attackId']??'')))[0]??[];
    if (empty($weapon['torchLightId'])&&!array_filter(applicationActiveAttackEnhancements($source,array_replace($equipmentState,onlineAttackEnhancementState($connection,$records,$pending,$next['sceneId'],$activity)),$next['sceneId']), static fn($e) => ($e['mode'] ?? '') === 'echo')) return [];
    $rolls = [];
    foreach (array_slice($state['rolls'], count($current['stepStates'][$step['id']]['rolls'] ?? [])) as $gate) {
        if (($gate['success'] ?? true) !== false) continue;
        $targetKey = onlineTokenDomainKey($next['sceneId'], $state['plan']['targetTokenId']);
        $target = $pending[$targetKey]['payload'] ?? applicationDomainPayload($records, $targetKey);
        if ($target === [] || onlineTokenIsDead($target, onlineTokenControllerIdFromRecords($connection, $records, $target) !== '')) continue;
        $activity = $pending['activity']['payload'] ?? $activity;
        $now = (int) floor(microtime(true)*1000);
        $activity['attackReceipts'] = onlineRetainedAttackReceipts($activity, $now);
        if (count($activity['attackReceipts'] ?? []) >= XAR_ATTACK_RECEIPT_MAXIMUM) rejectOnlineCommand($connection, 429, 'Le journal des attaques est plein.', 'attack_receipt_capacity');
        $defer = ($gate['outcomeDetails']['requiresGmValidation'] ?? false) === true;
        $attack = ['id'=>'attack-'.randomToken(18),'requestId'=>'chain-miss-'.randomToken(18),'sceneId'=>$next['sceneId'],'layerId'=>$next['layerId'],
            'sourceTokenId'=>$source['id'],'sourceName'=>$source['name'],'targetTokenId'=>$target['id'],'targetName'=>$target['name'],
            'accountId'=>$identity['id'],'attackerRole'=>$isGm?'gm':'player','playerName'=>$identity['display_name']??'Joueur',
            'attackKind'=>'ability','attackId'=>$next['abilityId'],'attackName'=>$next['abilityName'].' · Frappe ratée',
            'echoAttackContext'=>['weaponId'=>$state['plan']['attackId']??'','reach'=>applicationWeaponReach($weapon),'weaponBased'=>($state['plan']['attackKind']??'')==='weapon','projectile'=>($weapon['projectile']??false)===true,'naturalWeapon'=>($weapon['naturalWeapon']??false)===true,'equipmentItemIds'=>$weapon['equipmentItemIds']??[],'torchLightId'=>$weapon['torchLightId']??''],
            'visibility'=>$isGm && !onlineGmTokenVisibleToPlayers($connection,$records,applicationDomainPayload($records,'table'),$source,$next['sceneId'])?'gm':'public',
            'defenderAccountId'=>onlineTokenControllerIdFromRecords($connection,$records,$target),
            'hit'=>[...$gate,'raw'=>$gate['rawD100']??$gate['total'],'outcome'=>$gate['outcomeDetails'],'statId'=>$step['statId']],
            'status'=>$defer?'pending':'missed',...($defer?['validationKind'=>'outcome','provisionalStatus'=>'missed']:[]),
            'damage'=>[],'finalDamage'=>0,'damageFormula'=>'0','damageType'=>'physical','opposed'=>false,'createdAt'=>$now];
        if ($defer) {
            if (count($activity['pendingAttacks'] ?? []) >= XAR_PENDING_ATTACK_MAXIMUM) rejectOnlineCommand($connection,409,'La file des attaques est pleine.','pending_attack_capacity_reached');
            $activity['pendingAttacks'][]=$attack;
        }
        $activity['attackReceipts'][]=['requestId'=>$attack['requestId'],'accountId'=>$identity['id'],'expiresAt'=>$now+XAR_ATTACK_RECEIPT_TTL_MILLISECONDS,
            'requestSignature'=>onlineCommandRequestSignature('chain-miss',[$next['id'],$next['revision']]),'attack'=>$attack];
        queueOnlineDomainUpsert($pending,$records,'activity',$activity);
        $attack=onlineApplyAttackEchoes($connection,$records,$pending,$attack);
        foreach($attack['echoAttacks']??[] as $echo) $rolls=[...$rolls,...onlineAttackResponseRollFields($echo,$isGm,true,false)['rolls']];
    }
    return $rolls;
}

function applicationEnhancedStatRows(array $source,array $activity,string $sceneId,?array $character=null): array {
    if($character===null&&!empty($source['statsIncludeActiveEffects']))return $source['stats']??[];
    $bonuses=[];$holder=applicationEnhancementHolder($source);
    foreach(applicationActiveAttackEnhancements($source,$activity,$sceneId) as $effect){
        if(empty($effect['bonuses']))continue;
        foreach($effect['bonuses'] as $key=>$amount)$bonuses[$key]=($bonuses[$key]??0)+$amount;
    }
    if($character!==null){
        $rows=applicationEffectiveCharacterStats($character,$bonuses);$ids=array_column($rows,'id');
        // An attack may add an already resolved weapon-skill row. Keep these
        // contextual tests alongside the shared character scores.
        foreach($source['stats']??[] as $row)if(is_array($row)&&isset($row['id'])&&!in_array($row['id'],$ids,true))$rows[]=$row;
        return $rows;
    }
    $rows=applicationUnitStatRows($source['stats']??[]);
    $penalty=unitUsesCharacterSheet($source)?0:max(0,(int)floor(min(150,(float)($source['fatigue']['current']??0))-50));
    foreach($rows as &$row){
        $key=preg_replace('/^character-stat-/','',(string)($row['id']??''));
        if(empty($bonuses[$key])&&$penalty===0)continue;
        $base=$row['value']??0;
        if(!is_numeric($base))continue;
        $row['value']=(string)max(1,min(100,(float)$base+($bonuses[$key]??0)-$penalty));if(!empty($bonuses[$key]))$row['timedBonus']=$bonuses[$key];
    }
    unset($row);return $rows;
}
function onlineEnhancedStatRows(PDO $connection,array &$records,array $pending,array $source,string $sceneId,?array $activity=null): array {
    $keys=['activity'];$holder=applicationEnhancementHolder($source);if(str_starts_with($holder,'character:'))$keys[]=$holder;
    $records=array_replace($records,applicationDomainRecords($connection,$keys));
    $activity??=$pending['activity']['payload']??applicationDomainPayload($records,'activity');
    $character=str_starts_with($holder,'character:')?($pending[$holder]['payload']??applicationDomainPayload($records,$holder)):null;
    return applicationEnhancedStatRows($source,onlineAttackEnhancementState($connection,$records,$pending,$sceneId,$activity),$sceneId,$character);
}

function applicationEffectiveInitiativeBonus(array $source,array $state,string $sceneId):int { $bonus=(int)($source['initiativeBonus']??0);foreach(applicationActiveAttackEnhancements($source,$state,$sceneId) as $effect)$bonus+=(int)($effect['bonuses']['initiativeBonus']??0);return $bonus; }
