<?php
declare(strict_types=1);
require_once __DIR__ . '/character-statistics.php';
require_once __DIR__ . '/unit-authority.php';

require_once __DIR__.'/active-items.php';
function applicationAttackEnhancementDefinition(mixed $value): ?array {
    if(is_array($value)&&($value['mode']??'')==='on-hit-effects'){$p=normalizeItemPayload($value['payload']??null);return $p&&in_array($p['kind'],['dot','condition'],true)&&activeItemInt($value['turns']??null,1,999)&&in_array($value['trigger']??'',['all-attacks','melee-weapon','main-weapon'],true)?['mode'=>'on-hit-effects','turns'=>$value['turns'],'trigger'=>$value['trigger'],'requiresHit'=>true,...((applicationEffectFamily($p)?:applicationEffectFamily($p['rider']??[])) ? ['family'=>applicationEffectFamily($p)?:applicationEffectFamily($p['rider']??[])]:[]),'payload'=>$p]:null;}
    if(is_array($value)&&($value['mode']??'')==='immunity')return activeItemTag($value['family']??null)&&activeItemInt($value['turns']??null,1,999)?['mode'=>'immunity','family'=>$value['family'],'turns'=>$value['turns']]:null;
    if(is_array($value)&&($value['mode']??'')==='on-attack-dot'){
        $d=$value['dot']??[];if(!activeItemInt($value['turns']??null,1,999)||!validApplicationAbilityFormula($d['formula']??'')||!activeItemInt($d['turns']??null,1,20))return null;
        return ['mode'=>'on-attack-dot','turns'=>$value['turns'],'family'=>'poison','dot'=>['label'=>substr($d['label']??'Poison',0,120),'formula'=>$d['formula'],'turns'=>$d['turns'],'family'=>'poison','damageType'=>'ignore'],'perTargetPerTurn'=>1,'requiresHit'=>true];
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
        || !in_array($value['damageType'] ?? '', ['physical', 'magical', 'ignore', 'inherit'], true)
        || !is_int($value['turns'] ?? null) || $value['turns'] < 1 || $value['turns'] > 999) return null;
    $formula = strtolower(preg_replace('/\s+/', '', (string) ($value['formula'] ?? '')));
    if (!validApplicationAbilityFormula($formula) || ($value['mode'] === 'bonus'
        && (preg_match('/^\d+$/D', $formula) !== 1 || (float) $formula > 1000000000))) return null;
    $allyFormula = strtolower(preg_replace('/\s+/', '', (string) ($value['allyFormula'] ?? $formula)));
    if ($value['mode'] === 'echo' && !validApplicationAbilityFormula($allyFormula)) return null;
    if ($value['mode'] === 'echo' && isset($value['trigger']) && !in_array($value['trigger'], ['all-attacks', 'melee-weapon', 'main-weapon', 'enchanted-weapon'], true)) return null;
    if($value['mode']==='bonus'&&isset($value['trigger'])&&$value['trigger']!=='all-attacks')return null;
    return [...($value['mode']==='bonus'&&($value['trigger']??'')==='all-attacks'?['trigger'=>'all-attacks']:[]),'mode' => $value['mode'], 'formula' => $formula, 'damageType' => $value['damageType'], 'turns' => $value['turns'],
        ...($value['mode'] === 'echo' ? ['allyFormula' => $allyFormula, ...(in_array($value['trigger']??'',['melee-weapon','main-weapon','enchanted-weapon'],true)?['trigger'=>$value['trigger']]:[]),...(($value['requiresHit']??false)===true?['requiresHit'=>true]:[]),...(($value['expiry']??'')==='after-next-turn'?['expiry'=>'after-next-turn']:[]),...(!empty($value['immunityLabel'])&&is_string($value['immunityLabel'])?['immunityLabel'=>substr($value['immunityLabel'],0,240)]:[])] : [])];
}

function applicationAttackTriggersEnhancement(array $effect, array $attack): bool {
    if(!empty($effect['requiresHit'])&&(empty($attack['hit']['outcome']['success'])||in_array($attack['status']??'',['missed','defended','cancelled','rejected'],true)))return false;
    if(($effect['trigger']??'')==='enchanted-weapon')return ($attack['echoAttackContext']['weaponBased']??false)===true&&($attack['echoAttackContext']['naturalWeapon']??false)!==true&&($attack['echoAttackContext']['projectile']??false)!==true&&in_array($effect['enchantedItemId']??null,$attack['echoAttackContext']['equipmentItemIds']??[],true);
    if(($effect['trigger']??'')==='main-weapon')return ($attack['echoAttackContext']['weaponBased']??false)===true&&($attack['echoAttackContext']['projectile']??false)!==true&&($attack['echoAttackContext']['weaponId']??'')===($effect['weaponId']??null);
    return ($effect['trigger'] ?? '') !== 'melee-weapon'
        || (($attack['echoAttackContext']['weaponBased'] ?? false) === true && ($attack['echoAttackContext']['projectile'] ?? false) !== true);
}

function applicationEnhancementHolder(array $source): string {
    return isset($source['resources']) && empty($source['characterId']) && ($source['unitKind']??'')!=='token' ? 'character:' . ($source['id'] ?? '')
        : (unitUsesCharacterSheet($source)
            ? 'character:' . $source['characterId'] : 'token:' . ($source['id'] ?? ''));
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
        $image = $effect['abilityImage'] ?? null;
        if (!$image) foreach ($tokens as $caster) if (($caster['id'] ?? '') === ($effect['casterTokenId'] ?? '')) {
            $abilities = $caster['abilities'] ?? [];
            if (unitUsesCharacterSheet($caster)) foreach ($state['characters'] ?? [] as $sheet) if ($sheet['id'] === $caster['characterId']) $abilities = $sheet['abilities'] ?? [];
            foreach ($abilities as $ability) if (($ability['id'] ?? '') === ($effect['abilityId'] ?? '')) $image = $ability['image'] ?? null;
        }
        $result[] = ['id' => (string) $effect['id'], 'label' => (string) ($effect['label'] ?? 'Effet actif'),
            'remainingTurns' => max(1, min(999, (int) $effect['remainingTurns'])),...(isset($effect['aura'])&&($effect['casterTokenId']??'')===$token['id']&&($map['gridSize']??0)>0?['auraScale'=>min(10000,1+2*$effect['aura']['radiusCells']*$map['gridSize']/max(1,$token['size']??40))]:[]),...(($effect['expiry']??'')==='rests'?['durationLabel'=>'Prochain repos long ou '.(int)$effect['remainingShortRests'].' repos court(s)','remainingShortRests'=>(int)$effect['remainingShortRests']]:[]), 'image' => normalizePersistedImageReference($image)];
    }
    return array_slice($result, 0, 100);
}

function applicationTransferAttackEnhancements(array $effects, array $source, string $fromSceneId, string $toSceneId, array $initiative): array {
    $holder = applicationEnhancementHolder($source);
    return array_map(static fn(array $effect): array => $effect['sceneId'] === $fromSceneId && $effect['holder'] === $holder
        ? [...$effect, 'sceneId' => $toSceneId, 'targetTokenId' => $source['id'],
            'combatId' => ($initiative['active'] ?? false) === true ? ($initiative['combatId'] ?? '') : '', 'lastTurnSerial' => (int) ($initiative['turnSerial'] ?? 0)]
        : $effect, $effects);
}

function applicationAttackEnhancement(mixed $value): ?array {
    $definition=applicationAttackEnhancementDefinition($value);if($definition===null)return null;
    if(isset($value['bonuses'])&&($definition['mode']??'')!=='stats'){
        $stats=applicationAttackEnhancementDefinition(['mode'=>'stats','turns'=>$definition['turns'],'bonuses'=>$value['bonuses']]);
        if($stats===null)return null;$definition['bonuses']=$stats['bonuses'];
    }
    if(isset($value['aura'])){
        if(($definition['mode']??'')==='immunity')return null;
        $a=$value['aura'];if(!is_array($a)||!(is_int($a['radiusCells']??null)||is_float($a['radiusCells']??null))||!is_finite((float)$a['radiusCells'])||floor($a['radiusCells']*2)!=$a['radiusCells']*2||$a['radiusCells']<0.5||$a['radiusCells']>20)return null;
        foreach(['affectCaster','affectAllies','affectEnemies'] as $key)if(!is_bool($a[$key]??null))return null;
        if(!$a['affectCaster']&&!$a['affectAllies']&&!$a['affectEnemies'])return null;
        $definition['aura']=array_intersect_key($a,array_flip(['radiusCells','affectCaster','affectAllies','affectEnemies']));
    }
    return [...$definition,...(($value['target']??'')==='self'?['target'=>'self']:[]),...(($value['criticalFailureAsFailure']??false)===true?['criticalFailureAsFailure'=>true]:[])];
}

function applicationActiveAttackEnhancements(array $source,array $state,string $sceneId):array {
    if(empty($source['id']))return [];
    return array_values(array_filter($state['attackEnhancements']??[],static fn($effect)=>
        (($effect['sceneId']??'')===$sceneId||($effect['expiry']??'')==='rests')&&($effect['remainingTurns']??0)>0&&applicationAttackEnhancement($effect)!==null
        &&(($effect['trigger']??'')==='enchanted-weapon'?enchantmentOnUnit($effect,$source,$state):isset($effect['aura'])||($effect['holder']??'')===applicationEnhancementHolder($source))
        &&(!isset($effect['aura'])||applicationEnhancementAuraAffects($effect,$source,$state,$sceneId))));
}
function applicationEnhancementAuraAffects(array $effect,array $source,array $state,string $sceneId):bool {
    $map=($state['activeSceneId']??$state['activeScene']['id']??'')===$sceneId?($state['map']??[]):[];
    if($map===[])foreach($state['scenes']??[] as $scene)if(($scene['id']??'')===$sceneId)$map=$scene['combat']['map']??[];
    $tokens=$map['tokens']??[];$caster=null;foreach($tokens as $token)if($token['id']===($effect['casterTokenId']??''))$caster=$token;
    if(!$caster)return false;
    $controller=static fn($token)=>(string)($token['controllerPlayerId']??$token['ownerPlayerId']??$token['controlEffect']['controllerId']??'');
    $recipients=isset($source['resources'])?array_values(array_filter($tokens,static fn($token)=>applicationEnhancementHolder($token)===applicationEnhancementHolder($source))):[$source];
    $a=$effect['aura'];$near=onlineDamageAreaTargets($recipients,$caster,$map,(float)$a['radiusCells'],true);
    foreach($recipients as $recipient)foreach($near as $token)if(($token['id']??'')===($recipient['id']??null)&&applicationDamageAreaAffects($a,$caster['id'],$controller($caster),$token['id'],$controller($token)))return true;
    return false;
}
function onlineAttackEnhancementState(PDO $connection,array &$records,array $pending,string $sceneId,?array $activity=null):array {
    $activity??=$pending['activity']['payload']??applicationDomainPayload($records,'activity');
    if(!array_filter($activity['attackEnhancements']??[],static fn($effect)=>isset($effect['aura'])&&($effect['sceneId']??'')===$sceneId&&($effect['remainingTurns']??0)>0))return $activity;
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
    return [...$source,'resistanceBonuses'=>$bonus];
}

function applicationRollCriticalFailureProtected(array $source,array $activity,string $sceneId): bool {
    foreach(applicationActiveAttackEnhancements($source,$activity,$sceneId) as $effect)if(($effect['criticalFailureAsFailure']??false)===true)return true;
    return false;
}

function applicationActivateAttackEnhancement(array &$activity, array $ability, array $source, string $sceneId, array $initiative, string $id, ?array $recipient = null, array $enchantment = []): void {
    $definition = applicationAttackEnhancement($ability['attackEnhancement'] ?? null);
    if ($definition === null) return;
    $recipient ??= $source;if(isset($definition['aura'])||($definition['target']??'')==='self')$recipient=$source;
    if((applicationEffectFamily($definition)==='poison'||applicationEffectFamily($ability)==='poison')&&applicationHasEffectImmunity($recipient,$activity,$sceneId,'poison'))return;
    $holder = applicationEnhancementHolder($recipient);
    if ($definition['mode'] === 'echo' && $holder !== applicationEnhancementHolder($source)) $definition['formula'] = $definition['allyFormula'];
    $effects = array_values(array_filter($activity['attackEnhancements'] ?? [], static fn(array $effect): bool =>
        ($definition['trigger']??'')==='enchanted-weapon' ? ($effect['abilityId']!==$ability['id']||($effect['enchantedItemId']??'')!==($enchantment['enchantedItemId']??'')) : ($effect['holder'] !== $holder || $effect['abilityId'] !== $ability['id'])));
    if (count($effects) >= 100) throw new RuntimeException('Trop d’effets sur les attaques sont déjà actifs.', 409);
    $effects[] = [...$definition, 'id' => $id, 'holder' => $holder, 'abilityId' => $ability['id'], 'label' => $ability['name'].(!empty($enchantment['enchantedItemName'])?' — '.$enchantment['enchantedItemName']:''), 'sceneId' => $sceneId, ...$enchantment,
        ...(!empty($ability['image']) ? ['abilityImage' => $ability['image']] : []),
        ...(($definition['trigger']??'')==='main-weapon'?['weaponId'=>$recipient['weaponAttacks'][0]['id']??'']:[]),...(($definition['expiry']??'')==='after-next-turn'?['hasStarted'=>false]:[]),
        'targetTokenId' => $recipient['id'], 'casterTokenId' => $source['id'], 'remainingTurns' => $definition['turns'],
        'combatId' => ($initiative['active'] ?? false) === true ? ($initiative['combatId'] ?? '') : '', 'lastTurnSerial' => (int) ($initiative['turnSerial'] ?? 0)];
    $activity['attackEnhancements'] = $effects;
}

function applicationAdvanceAttackEnhancements(array $effects, string $sceneId, array $previous, array $initiative, bool $manual = false): array {
    if($manual){$remaining=[];foreach($effects as $effect){
        if(($effect['sceneId']??'')!==$sceneId||($effect['expiry']??'')==='rests'){$remaining[]=$effect;continue;}
        if(($effect['expiry']??'')==='after-next-turn'){if(empty($effect['hasStarted'])){$effect['hasStarted']=true;$remaining[]=$effect;}continue;}
        $effect['remainingTurns']--;if($effect['remainingTurns']>0)$remaining[]=$effect;
    }return $remaining;}
    if (($previous['active'] ?? false) === true && ($initiative['active'] ?? false) !== true)
        return array_values(array_filter($effects, static fn(array $effect): bool => $effect['sceneId'] !== $sceneId || ($effect['expiry']??'')==='rests'));
    if (($initiative['active'] ?? false) !== true || (int) ($initiative['turnSerial'] ?? 0) <= (int) ($previous['turnSerial'] ?? 0)) return $effects;
    $tokenId = $initiative['order'][$initiative['currentIndex'] ?? 0] ?? '';
    $remaining = [];
    foreach ($effects as $effect) {
        if ($effect['sceneId'] !== $sceneId || ($effect['expiry']??'')==='rests') { $remaining[] = $effect; continue; }
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
    return ($effect['damageType']??'')==='inherit'?(in_array($attack['damageType']??'',['physical','magical','ignore'],true)?$attack['damageType']:'physical'):$effect['damageType'];
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
    $parts = $replacement!==null?[['type'=>applicationEnhancementDamageType($replacement,$weapon),'formula'=>$replacement['formula']]]:($weapon['damageComponents']??[['type'=>$weapon['damageType']??'physical','formula'=>$weapon['formula']]]);
    foreach ($effects as $effect) {
        if ($effect['mode'] !== 'bonus') continue;
        $damageType=applicationEnhancementDamageType($effect,['damageType'=>$parts[0]['type']]);
        $index = null;
        foreach ($parts as $i => $part) if ($part['type'] === $damageType) $index = $i;
        if ($index !== null) $parts[$index]['formula'] .= '+' . $effect['formula'];
        else $parts[] = ['type' => $damageType, 'formula' => $effect['formula']];
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
            $attack['echoAttacks'][] = onlineCounterDamageAttack($connection, $records, $pending, $identity, $execution,
                ['targetTokenId' => $target['id'], 'optionId' => 'echo', 'opposed' => false, 'effect' => ['formula' => $effect['formula'], 'damageType' => applicationEnhancementDamageType($effect,$attack)]],
                'echo-' . randomToken(18), $attack['attackerRole'] === 'gm');
        }
    }
    $activity = $pending['activity']['payload'] ?? $activity;
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
        if (count($activity['attackReceipts'] ?? []) >= XAR_ATTACK_RECEIPT_MAXIMUM) rejectOnlineCommand($connection, 429, 'Le journal des attaques est plein.', 'attack_receipt_capacity');
        $now = (int) floor(microtime(true)*1000); $defer = ($gate['outcomeDetails']['requiresGmValidation'] ?? false) === true;
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
