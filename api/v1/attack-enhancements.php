<?php
declare(strict_types=1);
require_once __DIR__ . '/character-statistics.php';
require_once __DIR__ . '/unit-authority.php';

require_once __DIR__.'/active-items.php';
function applicationAttackEnhancement(mixed $value): ?array {
    if(is_array($value)&&($value['mode']??'')==='on-hit-effects'){$p=normalizeItemPayload($value['payload']??null);return $p&&in_array($p['kind'],['dot','condition'],true)&&activeItemInt($value['turns']??null,1,999)&&in_array($value['trigger']??'',['all-attacks','melee-weapon','main-weapon'],true)?['mode'=>'on-hit-effects','turns'=>$value['turns'],'trigger'=>$value['trigger'],'requiresHit'=>true,...((applicationEffectFamily($p)?:applicationEffectFamily($p['rider']??[])) ? ['family'=>applicationEffectFamily($p)?:applicationEffectFamily($p['rider']??[])]:[]),'payload'=>$p]:null;}
    if(is_array($value)&&($value['mode']??'')==='immunity')return activeItemTag($value['family']??null)&&activeItemInt($value['turns']??null,1,999)?['mode'=>'immunity','family'=>$value['family'],'turns'=>$value['turns']]:null;
    if(is_array($value)&&($value['mode']??'')==='on-attack-dot'){
        $d=$value['dot']??[];if(!activeItemInt($value['turns']??null,1,999)||!validApplicationAbilityFormula($d['formula']??'')||!activeItemInt($d['turns']??null,1,20))return null;
        return ['mode'=>'on-attack-dot','turns'=>$value['turns'],'family'=>'poison','dot'=>['label'=>substr($d['label']??'Poison',0,120),'formula'=>$d['formula'],'turns'=>$d['turns'],'family'=>'poison','damageType'=>'ignore'],'perTargetPerTurn'=>1,'requiresHit'=>true];
    }
    if(is_array($value)&&($value['mode']??'')==='stats'){
        if(!is_int($value['turns']??null)||$value['turns']<1||$value['turns']>999||!is_array($value['bonuses']??null))return null;
        $bonuses=[];foreach($value['bonuses'] as $key=>$amount){
            if(!in_array($key,[...array_keys(applicationStatLabels()),'mentalResistance','initiativeBonus'],true)||!is_int($amount)||$amount < -100||$amount>100)return null;
            if($amount!==0)$bonuses[$key]=$amount;
        }
        return $bonuses!==[]?['mode'=>'stats','turns'=>$value['turns'],'bonuses'=>$bonuses]:null;
    }
    if (!is_array($value) || !in_array($value['mode'] ?? '', ['bonus', 'replace', 'echo'], true)
        || !in_array($value['damageType'] ?? '', ['physical', 'magical', 'ignore'], true)
        || !is_int($value['turns'] ?? null) || $value['turns'] < 1 || $value['turns'] > 999) return null;
    $formula = strtolower(preg_replace('/\s+/', '', (string) ($value['formula'] ?? '')));
    if (!validApplicationAbilityFormula($formula) || ($value['mode'] === 'bonus'
        && (preg_match('/^\d+$/D', $formula) !== 1 || (float) $formula > 1000000000))) return null;
    $allyFormula = strtolower(preg_replace('/\s+/', '', (string) ($value['allyFormula'] ?? $formula)));
    if ($value['mode'] === 'echo' && !validApplicationAbilityFormula($allyFormula)) return null;
    if ($value['mode'] === 'echo' && isset($value['trigger']) && !in_array($value['trigger'], ['all-attacks', 'melee-weapon', 'main-weapon', 'enchanted-weapon'], true)) return null;
    return ['mode' => $value['mode'], 'formula' => $formula, 'damageType' => $value['damageType'], 'turns' => $value['turns'],
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
    foreach ($state['attackEnhancements'] ?? [] as $effect) {
        if ((($effect['trigger']??'')==='enchanted-weapon'?!enchantmentOnUnit($effect,$token,$state):($effect['holder']??'')!==$holder) || (($effect['sceneId'] ?? '') !== $sceneId&&($effect['expiry']??'')!=='rests') || ($effect['remainingTurns'] ?? 0) <= 0 || applicationAttackEnhancement($effect) === null) continue;
        $image = $effect['abilityImage'] ?? null;
        if (!$image) foreach ($tokens as $caster) if (($caster['id'] ?? '') === ($effect['casterTokenId'] ?? '')) {
            $abilities = $caster['abilities'] ?? [];
            if (unitUsesCharacterSheet($caster)) foreach ($state['characters'] ?? [] as $sheet) if ($sheet['id'] === $caster['characterId']) $abilities = $sheet['abilities'] ?? [];
            foreach ($abilities as $ability) if (($ability['id'] ?? '') === ($effect['abilityId'] ?? '')) $image = $ability['image'] ?? null;
        }
        $result[] = ['id' => (string) $effect['id'], 'label' => (string) ($effect['label'] ?? 'Effet actif'),
            'remainingTurns' => max(1, min(999, (int) $effect['remainingTurns'])),...(($effect['expiry']??'')==='rests'?['durationLabel'=>'Prochain repos long ou '.(int)$effect['remainingShortRests'].' repos court(s)','remainingShortRests'=>(int)$effect['remainingShortRests']]:[]), 'image' => normalizePersistedImageReference($image)];
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

function applicationActivateAttackEnhancement(array &$activity, array $ability, array $source, string $sceneId, array $initiative, string $id, ?array $recipient = null, array $enchantment = []): void {
    $definition = applicationAttackEnhancement($ability['attackEnhancement'] ?? null);
    if ($definition === null) return;
    $recipient ??= $source;
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

function applicationEnhancedBasicAttack(array $weapon, array $source, array $activity, string $sceneId): array {
    $holder = applicationEnhancementHolder($source);
    $effects = array_values(array_filter($activity['attackEnhancements'] ?? [], static fn(array $effect): bool =>
        $effect['holder'] === $holder && $effect['sceneId'] === $sceneId && $effect['remainingTurns'] > 0 && in_array($effect['mode'] ?? '', ['bonus','replace'], true) && applicationAttackEnhancement($effect) !== null));
    if ($effects === []) return $weapon;
    $replacement = null;
    foreach ($effects as $effect) if ($effect['mode'] === 'replace') $replacement = $effect;
    $parts = $replacement!==null?[['type'=>$replacement['damageType'],'formula'=>$replacement['formula']]]:($weapon['damageComponents']??[['type'=>$weapon['damageType']??'physical','formula'=>$weapon['formula']]]);
    foreach ($effects as $effect) {
        if ($effect['mode'] !== 'bonus') continue;
        $index = null;
        foreach ($parts as $i => $part) if ($part['type'] === $effect['damageType']) $index = $i;
        if ($index !== null) $parts[$index]['formula'] .= '+' . $effect['formula'];
        else $parts[] = ['type' => $effect['damageType'], 'formula' => $effect['formula']];
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
    $attack['echoDefinitions'] ??= array_values(array_filter($activity['attackEnhancements'] ?? [], static fn(array $effect): bool =>
        ((($effect['trigger']??'')==='enchanted-weapon')?enchantmentOnUnit($effect,$source,$equipmentState):($effect['holder']??'')===$holder) && ($effect['sceneId'] ?? '') === $sceneId && ($effect['mode'] ?? '') === 'echo'
        && ($effect['remainingTurns'] ?? 0) > 0 && applicationAttackEnhancement($effect) !== null && applicationAttackTriggersEnhancement($effect, $attack)));
    if ($attack['echoDefinitions'] !== [] && $attack['status'] !== 'awaiting-opposition' && ($attack['status'] !== 'pending' || $approved)) {
        $attack['echoProcessed'] = true;
        $attack['echoAttacks'] = [];
        $identity = ['id' => $attack['accountId'], 'display_name' => $attack['playerName'],
            'effective_mode' => $attack['attackerRole'], 'permanent_role' => $attack['attackerRole']];
        foreach ($attack['echoDefinitions'] as $effect) {
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
                ['targetTokenId' => $target['id'], 'optionId' => 'echo', 'opposed' => false, 'effect' => ['formula' => $effect['formula'], 'damageType' => $effect['damageType']]],
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
    if (empty($weapon['torchLightId'])&&!array_filter($activity['attackEnhancements'] ?? [], static fn($e) => ($e['holder'] ?? '') === $holder && ($e['sceneId'] ?? '') === $next['sceneId'] && ($e['mode'] ?? '') === 'echo' && ($e['remainingTurns'] ?? 0) > 0)) return [];
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
    foreach($activity['attackEnhancements']??[] as $effect){
        if(($effect['holder']??'')!==$holder||(($effect['sceneId']??'')!==$sceneId&&($effect['expiry']??'')!=='rests')||($effect['remainingTurns']??0)<=0||($effect['mode']??'')!=='stats'||applicationAttackEnhancement($effect)===null)continue;
        foreach($effect['bonuses'] as $key=>$amount)$bonuses[$key]=($bonuses[$key]??0)+$amount;
    }
    $rows=applicationUnitStatRows($source['stats']??[]);
    $penalty=unitUsesCharacterSheet($source)?0:max(0,(int)floor(min(150,(float)($source['fatigue']['current']??0))-50));
    foreach($rows as &$row){
        $key=preg_replace('/^character-stat-/','',(string)($row['id']??''));
        if(empty($bonuses[$key])&&$penalty===0)continue;
        $base=$row['value']??0;
        if($character!==null&&array_key_exists($key,$character['stats']??[])){
            $temporary=$character['temporaryStats'][$key]??null;
            $base=($temporary!==null&&$temporary!==''&&is_numeric($temporary)?(float)$temporary:(float)$character['stats'][$key])-max(0,(int)floor(($character['fatigue']['current']??0)-50));
        }
        if($character!==null&&$key==='mentalResistance')$base=(float)($character['resources']['mentalResistance']??0)-max(0,(int)floor(($character['fatigue']['current']??0)-50));
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
    return applicationEnhancedStatRows($source,$activity,$sceneId,$character);
}

function applicationEffectiveInitiativeBonus(array $source,array $state,string $sceneId):int { $bonus=(int)($source['initiativeBonus']??0);$holder=applicationEnhancementHolder($source);foreach($state['attackEnhancements']??[] as $e)if(($e['mode']??'')==='stats'&&($e['holder']??'')===$holder&&(($e['sceneId']??'')===$sceneId||($e['expiry']??'')==='rests')&&($e['remainingTurns']??0)>0&&applicationAttackEnhancement($e))$bonus+=(int)($e['bonuses']['initiativeBonus']??0);return $bonus; }
