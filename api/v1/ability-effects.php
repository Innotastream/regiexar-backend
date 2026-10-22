<?php
declare(strict_types=1);
require_once __DIR__ . '/device-state.php';
require_once __DIR__ . '/attack-enhancements.php';
require_once __DIR__ . '/terrain-barrier.php';
require_once __DIR__ . '/tactical-effects.php';

function applicationDamageComponents(mixed $value): array {
    if (!is_array($value) || !array_is_list($value) || count($value) > 3) return [];
    $parts = []; $seen = [];
    foreach ($value as $entry) {
        if (!is_array($entry) || !in_array($entry['type'] ?? '', ['physical', 'magical', 'ignore'], true)
            || isset($seen[$entry['type']]) || !validApplicationAbilityFormula($entry['formula'] ?? null)) return [];
        $seen[$entry['type']] = true;
        $parts[] = ['type' => $entry['type'], 'formula' => strtolower(preg_replace('/\s+/', '', $entry['formula'])), ...(($entry['ignoreArmor'] ?? false) === true ? ['ignoreArmor' => true] : [])];
    }
    return $parts;
}
function applicationCombinedDamageFormula(array $parts): string {
    return sumEffectFormulas(array_column($parts,'formula'));
}
function validApplicationOppositionAbility(mixed $value): bool {
    if (!is_array($value) || !validApplicationDomainIdentifier($value['statId'] ?? null, 120)
        || !is_array($value['options'] ?? null) || !array_is_list($value['options'])
        || count($value['options']) < 1 || count($value['options']) > 8) return false;
    $seen = [];
    foreach ($value['options'] as $option) {
        if (!is_array($option) || !validApplicationDomainIdentifier($option['id'] ?? null, 80)
            || isset($seen[$option['id']]) || !validApplicationDomainText($option['label'] ?? null, 120, false)
            || !is_int($option['manaCost'] ?? null) || $option['manaCost'] < 0 || $option['manaCost'] > 1000000000
            || !is_int($option['reflectPercent'] ?? null) || $option['reflectPercent'] < 0 || $option['reflectPercent'] > 100) return false;
        $seen[$option['id']] = true;
    }
    return true;
}
function applicationOppositionAbility(array $value): array {
    return ['statId' => (string) $value['statId'], 'options' => array_map(
        static fn (array $option): array => ['id' => $option['id'], 'label' => $option['label'],
            'manaCost' => $option['manaCost'], 'reflectPercent' => $option['reflectPercent']], $value['options'])];
}
function validApplicationDamageOverTime(mixed $value): bool {
    return is_array($value) && validApplicationDomainText($value['label'] ?? null, 120, false)
        && validApplicationAbilityFormula($value['formula'] ?? null)
        && in_array($value['damageType'] ?? null, ['physical', 'magical', 'ignore'], true)
        && is_int($value['turns'] ?? null) && $value['turns'] >= 1 && $value['turns'] <= 20;
}
function validApplicationDamageTargeting(mixed $value): bool {
    return is_array($value) && ($value['mode'] ?? '') === 'area'
        && (is_int($value['radiusCells'] ?? null) || is_float($value['radiusCells'] ?? null)) && is_finite((float)$value['radiusCells']) && floor($value['radiusCells']*2)===$value['radiusCells']*2.0 && $value['radiusCells'] >= 0.5 && $value['radiusCells'] <= 20
        && (!array_key_exists('origin', $value) || in_array($value['origin'], ['target', 'caster', 'point'], true))
        && (!array_key_exists('singleTargetHalfMana', $value) || is_bool($value['singleTargetHalfMana']))
        && (!array_key_exists('affectCaster', $value) || is_bool($value['affectCaster']))
        && (!array_key_exists('affectAllies', $value) || is_bool($value['affectAllies']))
        && (!array_key_exists('affectEnemies', $value) || is_bool($value['affectEnemies']))
        && (($value['affectCaster'] ?? true) || ($value['affectAllies'] ?? true) || ($value['affectEnemies'] ?? true));
}
function applicationDamageAreaAffects(array $targeting, string $sourceId, string $sourceController, string $targetId, string $targetController): bool {
    if ($sourceId === $targetId) return ($targeting['affectCaster'] ?? true) === true;
    $allied = ($sourceController !== '') === ($targetController !== '');
    return $allied ? ($targeting['affectAllies'] ?? true) === true : ($targeting['affectEnemies'] ?? true) === true;
}

function applicationAreaTargetPoint(mixed $value, string $layerId): ?array {
    if (!is_array($value) || !(is_int($value['x']??null)||is_float($value['x']??null)) || !(is_int($value['y']??null)||is_float($value['y']??null))
        || !is_finite((float)$value['x']) || !is_finite((float)$value['y']) || $value['x']<0 || $value['x']>100 || $value['y']<0 || $value['y']>100) return null;
    return ['x'=>$value['x'],'y'=>$value['y'],'layerId'=>$layerId];
}
function validApplicationAbilityEffects(array $entry): bool {
    if(isset($entry['requiredWeaponAttackId'])&&(empty($entry['useWeaponDamage'])||!validApplicationDomainIdentifier($entry['requiredWeaponAttackId'],180)))return false;
    if(isset($entry['singleEnemy'])){if(($entry['effect']??'damage')!=='damage'||!is_array($entry['singleEnemy'])||!is_int($entry['singleEnemy']['damageRepetitions']??null)||$entry['singleEnemy']['damageRepetitions']<1||$entry['singleEnemy']['damageRepetitions']>10)return false;try{if(isset($entry['singleEnemy']['conditionRider'])&&dsRider($entry['singleEnemy']['conditionRider'])===null)return false;}catch(RuntimeException){return false;}}
    if(isset($entry['weaponDamageMultiplier'])&&(!is_int($entry['weaponDamageMultiplier'])||$entry['weaponDamageMultiplier']<1||$entry['weaponDamageMultiplier']>10||empty($entry['useWeaponDamage'])))return false;
    if(isset($entry['manualD100'])&&(!is_bool($entry['manualD100'])||$entry['manualD100']&&!in_array($entry['effect']??'',['movement','healing','enhancement','summoning','metamorphosis'],true)))return false;
    try{applicationOutcomeMessages($entry['outcomeMessages']??null);}catch(RuntimeException){return false;}
    if (array_key_exists('attackEnhancement', $entry) && (!in_array($entry['effect'] ?? 'damage', ['damage', 'healing', 'movement', 'enhancement'], true)
        || applicationAttackEnhancement($entry['attackEnhancement']) === null)) return false;
    if (array_key_exists('noDefensePossible', $entry) && (!is_bool($entry['noDefensePossible'])
        || !in_array($entry['effect'] ?? 'damage', ['damage', 'complex'], true))) return false;
    if(array_key_exists('tacticalEffect',$entry)&&applicationTacticalEffect($entry['tacticalEffect'])===null)return false;
    foreach(['mainWeaponOnly','maximumWeaponDamage'] as $k)if(array_key_exists($k,$entry)&&!is_bool($entry[$k]))return false;
    if(isset($entry['weaponDamageMode'])&&!in_array($entry['weaponDamageMode'],['add','replace'],true))return false;
    if(array_key_exists('terrainBarrier',$entry)&&(($entry['effect']??'damage')!=='damage'||applicationTerrainBarrier($entry['terrainBarrier'])===null))return false;
    if (!validApplicationAbilityCastingFields($entry)) return false;
    if(array_key_exists('rangeCells',$entry)&&(!is_numeric($entry['rangeCells'])||$entry['rangeCells']<0||$entry['rangeCells']>1000))return false;
    if(array_key_exists('reach',$entry)&&!in_array($entry['reach'],['melee','reach','ranged'],true))return false;
    if (array_key_exists('onHitConditions', $entry) && !validApplicationConditions($entry['onHitConditions'])) return false;
    $effect = $entry['effect'] ?? 'damage';
    foreach (['formDurationTurns' => 1000, 'formBonusHp' => 1000000000] as $field => $maximum) if (array_key_exists($field, $entry)
        && ($effect !== 'metamorphosis' || !is_int($entry[$field]) || $entry[$field] < 0 || $entry[$field] > $maximum)) return false;
    if (!in_array($effect, ['damage', 'healing', 'metamorphosis', 'movement', 'summoning', 'complex', 'opposition', 'device', 'enhancement'], true)) return false;
    foreach (['projectile','useWeaponDamage'] as $key) if (isset($entry[$key]) && !is_bool($entry[$key])) return false;
    try {
        if (isset($entry['infusion'])) dsInfusion($entry['infusion']);
        if (isset($entry['conditionRider'])) dsRider($entry['conditionRider']);
        if (isset($entry['castingChoices']) && (!is_array($entry['castingChoices']) || count(dsCastingChoices($entry['castingChoices']))!==count($entry['castingChoices']))) return false;
        if ($effect==='device') dsDefinition($entry['device']??null);
    } catch (RuntimeException $error) { return false; }
    if (array_key_exists('opposition', $entry) && (!in_array($effect, ['complex', 'opposition'], true) || !validApplicationOppositionAbility($entry['opposition']))) return false;
    if ($effect === 'opposition' && !validApplicationOppositionAbility($entry['opposition'] ?? null)) return false;
    if (array_key_exists('damageOverTime', $entry) && ($effect !== 'damage' || !validApplicationDamageOverTime($entry['damageOverTime']))) return false;
    if (array_key_exists('damageTargeting', $entry) && ($effect !== 'damage' || !validApplicationDamageTargeting($entry['damageTargeting']))) return false;
    if (array_key_exists('completionCue', $entry) && !validApplicationAbilityCompletionCue($entry['completionCue'])) return false;
    if ($effect==='device') return true;
    if ($effect === 'complex') {
        if (!validApplicationComplexAbilityWorkflow($entry['workflow'] ?? null)) return false;
        $first = $entry['workflow']['steps'][0] ?? [];
        return !is_array($first) || ($first['firstGateAtCast'] ?? false) !== true
            || ($entry['castingStatId'] ?? '') === ($first['statId'] ?? '');
    }
    if ($effect === 'enhancement') return applicationAttackEnhancement($entry['attackEnhancement'] ?? null) !== null;
    if ($effect === 'movement') return true;
    if ($effect === 'summoning') return validApplicationDomainIdentifier($entry['summonLinkedTokenId'] ?? null, 180);
    if ($effect === 'healing') return validApplicationAbilityFormula($entry['healingFormula'] ?? null);
    if ($effect === 'metamorphosis') return validApplicationDomainIdentifier($entry['formCharacterId'] ?? null, 180);
    if (!array_key_exists('damageComponents', $entry)) return true;
    $parts = applicationDamageComponents($entry['damageComponents']);
    return $parts !== [] && validApplicationAbilityFormula(applicationCombinedDamageFormula($parts));
}
function applicationAbilityEffectFields(array $entry): array {
    $casting = applicationAbilityCastingFields($entry);
    $effect = $entry['effect'] ?? 'damage';
    $cue = ($effect === 'complex' || array_key_exists('completionCue', $entry))
        ? ['completionCue' => normalizeApplicationAbilityCompletionCue($entry['completionCue'] ?? null)] : [];
    $conditions = array_key_exists('onHitConditions', $entry) ? ['onHitConditions' => normalizeOnlineConditions($entry['onHitConditions'])] : [];
    if (in_array($effect, ['damage', 'healing', 'movement', 'enhancement'], true) && applicationAttackEnhancement($entry['attackEnhancement'] ?? null) !== null)
        $conditions['attackEnhancement'] = applicationAttackEnhancement($entry['attackEnhancement']);
    if(array_key_exists('projectile',$entry)) $conditions['projectile']=$entry['projectile']===true;
    if(is_numeric($entry['rangeCells']??null)&&$entry['rangeCells']>0)$conditions['rangeCells']=$entry['rangeCells'];
    if(in_array($entry['reach']??'', ['melee','reach','ranged'],true))$conditions['reach']=$entry['reach'];
    if(!empty($entry['manualD100']))$conditions['manualD100']=true;
    if($messages=applicationOutcomeMessages($entry['outcomeMessages']??null))$conditions['outcomeMessages']=$messages;
    if(($entry['useWeaponDamage']??false)===true){$conditions['useWeaponDamage']=true;if(($entry['weaponDamageMultiplier']??1)>1)$conditions['weaponDamageMultiplier']=$entry['weaponDamageMultiplier'];if(($entry['weaponDamageMode']??'')==='add')$conditions['weaponDamageMode']='add';}
    if(!empty($entry['requiredWeaponAttackId']))$conditions['requiredWeaponAttackId']=$entry['requiredWeaponAttackId'];
    if(isset($entry['singleEnemy']))$conditions['singleEnemy']=['damageRepetitions'=>$entry['singleEnemy']['damageRepetitions'],...(isset($entry['singleEnemy']['conditionRider'])?['conditionRider'=>dsRider($entry['singleEnemy']['conditionRider'])]:[])];
    if(dsCastingChoices($entry['castingChoices']??null)) $conditions['castingChoices']=dsCastingChoices($entry['castingChoices']);
    if(dsInfusion($entry['infusion']??null)) $conditions['infusion']=dsInfusion($entry['infusion']);
    if(applicationTacticalEffect($entry['tacticalEffect']??null))$conditions['tacticalEffect']=applicationTacticalEffect($entry['tacticalEffect']);
    foreach(['mainWeaponOnly','maximumWeaponDamage'] as $k)if(($entry[$k]??false)===true)$conditions[$k]=true;
    if(applicationTerrainBarrier($entry['terrainBarrier']??null))$conditions['terrainBarrier']=applicationTerrainBarrier($entry['terrainBarrier']);
    if(dsRider($entry['conditionRider']??null)) $conditions['conditionRider']=dsRider($entry['conditionRider']);
    if($effect==='device') return [...$casting,...$cue,...$conditions,'effect'=>'device','device'=>dsDefinition($entry['device'])];
    if (in_array($effect, ['damage', 'complex'], true) && ($entry['noDefensePossible'] ?? false) === true) $conditions['noDefensePossible'] = true;
    $opposition = validApplicationOppositionAbility($entry['opposition'] ?? null) ? ['opposition' => applicationOppositionAbility($entry['opposition'])] : [];
    if ($effect === 'complex') return [
        ...$casting,
        ...$conditions,
        ...$opposition,
        'effect' => 'complex',
        'workflow' => normalizeApplicationComplexAbilityWorkflow($entry['workflow'] ?? null),
        ...$cue,
    ];
    if ($effect === 'opposition') return [...$casting, ...$cue, ...$conditions, ...$opposition, 'effect' => 'opposition'];
    if ($effect === 'enhancement') return [...$casting, ...$cue, ...$conditions, 'effect' => 'enhancement'];
    if ($effect === 'movement') return [...$casting, ...$cue, ...$conditions, 'effect' => 'movement'];
    if ($effect === 'summoning') return [...$casting, ...$cue, ...$conditions, 'effect' => 'summoning', 'summonLinkedTokenId' => (string) ($entry['summonLinkedTokenId'] ?? '')];
    if ($effect === 'healing') return [...$casting, ...$cue, ...$conditions, 'effect' => 'healing', 'healingFormula' => (string) ($entry['healingFormula'] ?? '1d6')];
    if ($effect === 'metamorphosis') return [...$casting, ...$cue, ...$conditions, 'effect' => 'metamorphosis', 'formCharacterId' => (string) ($entry['formCharacterId'] ?? ''),
        ...(($entry['formDurationTurns'] ?? 0) > 0 ? ['formDurationTurns' => $entry['formDurationTurns']] : []),
        ...(($entry['formBonusHp'] ?? 0) > 0 ? ['formBonusHp' => $entry['formBonusHp']] : [])];
    $parts = applicationDamageComponents($entry['damageComponents'] ?? []);
    return [...$casting, ...$cue, ...$conditions, ...(array_key_exists('effect', $entry) ? ['effect' => 'damage'] : []), ...($parts !== [] ? ['damageComponents' => $parts] : []),
        ...(validApplicationDamageOverTime($entry['damageOverTime'] ?? null) ? ['damageOverTime' => $entry['damageOverTime']] : []),
        ...(validApplicationDamageTargeting($entry['damageTargeting'] ?? null) ? ['damageTargeting' => [
            'mode' => 'area', 'radiusCells' => $entry['damageTargeting']['radiusCells'],
            'origin' => $entry['damageTargeting']['origin'] ?? 'target',
            ...(($entry['damageTargeting']['singleTargetHalfMana']??false)===true?['singleTargetHalfMana'=>true]:[]),
            'affectCaster' => $entry['damageTargeting']['affectCaster'] ?? true,
            'affectAllies' => $entry['damageTargeting']['affectAllies'] ?? true,
            'affectEnemies' => $entry['damageTargeting']['affectEnemies'] ?? true]] : [])];
}
function applicationCustomAttack(mixed $value): array {
    if (!is_array($value)) throw new InvalidArgumentException('Attaque personnalisée invalide.');
    $name = trim((string) ($value['name'] ?? ''));
    $parts = applicationDamageComponents($value['damageComponents'] ?? []);
    if ($name === '' || strlen($name) > 120 || $parts === [] || !validApplicationAbilityFormula(applicationCombinedDamageFormula($parts))) throw new InvalidArgumentException('Renseignez un nom et des formules de dégâts valides.');
    $customStat = ($value['customStat'] ?? false) === true;
    $threshold = $value['threshold'] ?? null; $label = trim((string) ($value['statLabel'] ?? 'Statistique personnalisée'));
    if ($customStat && (!is_numeric($threshold) || (float) $threshold != (int) $threshold || $threshold < 0 || $threshold > 100 || $label === '' || strlen($label) > 120)) throw new InvalidArgumentException('Statistique personnalisée invalide.');
    return ['name' => $name, 'damageComponents' => $parts, 'customStat' => $customStat, 'threshold' => (int) $threshold, 'statLabel' => $label];
}
function onlineRollAttackDamage(array $attack, array $target, ?callable $rollFormula = null,array $state=[]): array {
    if($state!==[])$target=applicationEnhancedResistanceTarget($target,$state,(string)($attack['sceneId']??$state['activeSceneId']??''));
    $count=max(1,min(10,(int)($attack['damageRepetitions']??1)));if($count===1)return onlineRollSingleAttackDamage($attack,$target,$rollFormula);
    $strikes=[];for($i=0;$i<$count;$i++)$strikes[]=onlineRollSingleAttackDamage($attack,$target,$rollFormula);
    $raw=array_sum(array_column(array_column($strikes,'damage'),'rawDamage'));$final=array_sum(array_column(array_column($strikes,'damage'),'finalDamage'));
    return ['rolled'=>[...$strikes[0]['rolled'],'total'=>$raw,'strikes'=>array_column($strikes,'rolled'),'breakdown'=>implode(' ; ',array_map(static fn($strike,$index)=>'Frappe '.($index+1).' : '.$strike['rolled']['breakdown'],$strikes,array_keys($strikes)))],
        'damage'=>['rawDamage'=>$raw,'finalDamage'=>$final,'preventedDamage'=>$raw-$final,'armorPercent'=>$raw?(int)round(100*($raw-$final)/$raw):0,'strikes'=>array_column($strikes,'damage'),'components'=>array_merge(...array_map(static fn($strike)=>$strike['damage']['components']??[],$strikes))]];
}
function onlineRollSingleAttackDamage(array $attack, array $target, ?callable $rollFormula = null): array {
    $parts = applicationDamageComponents($attack['damageComponents'] ?? []);
    $percent = max(1, min(100, (int) ($attack['damagePercent'] ?? 100)));
    if ($parts === []) {
        $rolled = $rollFormula!==null?$rollFormula($attack['damageFormula']):onlineRollFormulaWithMode($attack['damageFormula'], 'normal');
        return ['rolled' => $rolled, 'damage' => onlineAttackDamageSummary((int) round(max(0, (int) $rolled['total']) * $percent / 100), onlineAttackArmorPercent($target, $attack['damageType'] ?? 'physical'))];
    }
    $rollFormula ??= static fn (string $formula): array => onlineRollFormula($formula);
    $rollMode = 'normal';
    $attempts = [];
    $attemptCount = $rollMode === 'normal' ? 1 : 2;
    for ($attemptIndex = 0; $attemptIndex < $attemptCount; $attemptIndex += 1) {
        $attemptTotal = 0; $attemptBreakdown = []; $attemptComponents = [];
        foreach ($parts as $part) {
            $componentRoll = $rollFormula($part['formula']);
            $componentTotal = (int) ($componentRoll['total'] ?? 0);
            $componentBreakdown = (string) ($componentRoll['breakdown'] ?? $componentTotal);
            $attemptTotal += $componentTotal;
            $attemptBreakdown[] = $part['type'] . ': ' . $componentBreakdown;
            $attemptComponents[] = [
                ...$part,
                'total' => $componentTotal,
                'breakdown' => $componentBreakdown,
            ];
        }
        $attempts[] = [
            'total' => $attemptTotal,
            'breakdown' => implode(' ; ', $attemptBreakdown),
            'rawD100' => null,
            'components' => $attemptComponents,
        ];
    }
    $selectedIndex = selectOnlineRollAttemptIndex($attempts, $rollMode);
    $selected = $attempts[$selectedIndex];
    $results = []; $raw = 0; $final = 0;
    foreach ($selected['components'] as $component) {
        $summary = onlineAttackDamageSummary((int) round(max(0, (int) $component['total']) * $percent / 100), ($component['ignoreArmor'] ?? false) === true ? 0 : onlineAttackArmorPercent($target, $component['type']));
        $results[] = [
            'type' => $component['type'],
            'formula' => $component['formula'],
            'breakdown' => $component['breakdown'],
            ...$summary,
        ];
        $raw += $summary['rawDamage'];
        $final += $summary['finalDamage'];
    }
    return ['rolled' => [
            'formula' => applicationCombinedDamageFormula($parts),
            'total' => $selected['total'],
            'breakdown' => $selected['breakdown'],
            'rawD100' => null,
            'rollMode' => $rollMode,
            'selectedIndex' => $selectedIndex,
            'attempts' => $attempts,
        ],
        'damage' => ['rawDamage' => $raw, 'finalDamage' => $final, 'preventedDamage' => $raw - $final, 'armorPercent' => count($results) === 1 ? $results[0]['armorPercent'] : ($raw ? (int) round(100 * ($raw - $final) / $raw) : 0), 'components' => $results]];
}

// Clients 3.2.2 may still send the former five-field ability. Preserve the new
// effect on existing rows; removal of a row remains an explicit deletion.
function preserveApplicationAbilityRows(array $incoming, array $previous): array {
    $byId = array_column($previous, null, 'id');
    return array_map(static function ($entry) use ($byId) {
        if (!is_array($entry)) return $entry;
        $old = $byId[$entry['id'] ?? ''] ?? [];
        if (($entry['effect'] ?? $old['effect'] ?? '') === 'metamorphosis') foreach (['formDurationTurns', 'formBonusHp'] as $field)
            if (!array_key_exists($field, $entry) && array_key_exists($field, $old)) $entry[$field] = $old[$field];
        foreach (['manaCost', 'hpCost', 'fatigueCost', 'restRecharge', 'usesPerRest', 'reusableInTurn', 'difficultyIncrement', 'cooldownRounds', 'castingStatId', 'reducedFailureCooldown', 'image'] as $field) if (!array_key_exists($field, $entry) && array_key_exists($field, $old)) $entry[$field] = $old[$field];
        if (!array_key_exists('completionCue', $entry) && array_key_exists('completionCue', $old)) $entry['completionCue'] = $old['completionCue'];
        if (($entry['effect'] ?? $old['effect'] ?? '') === 'complex' && is_array($old['workflow'] ?? null)
            && (int) ($old['workflow']['version'] ?? 0) > (int) ($entry['workflow']['version'] ?? 0)) $entry['workflow'] = $old['workflow'];
        if (array_key_exists('effect', $entry)) return $entry;
        if (!array_key_exists('effect', $old) && !array_key_exists('damageComponents', $old)) return $entry;
        foreach (['effect', 'attackEnhancement', 'damageComponents', 'damageOverTime', 'damageTargeting', 'opposition', 'healingFormula', 'formCharacterId', 'summonLinkedTokenId', 'onHitConditions', 'workflow', 'completionCue', 'formula', 'damageType'] as $field) if (array_key_exists($field, $old)) $entry[$field] = $old[$field];
        return $entry;
    }, $incoming);
}
function preserveApplicationAbilityExtensions(string $key, array $payload, array $previous): array {
    if (str_starts_with($key, 'token:') && ($previous['immovable'] ?? false) === true && ($payload['immovable'] ?? true) === true
        && (($payload['x'] ?? $previous['x']) != $previous['x'] || ($payload['y'] ?? $previous['y']) != $previous['y'] || ($payload['layerId'] ?? 'ground') !== ($previous['layerId'] ?? 'ground'))) sendError(409, 'Ce pion est inamovible. Désactivez cette option pour le déplacer.', 'token_immovable');
    if (str_starts_with($key, 'character:') || str_starts_with($key, 'token:')) {
        if (is_array($payload['abilities'] ?? null)) $payload['abilities'] = preserveApplicationAbilityRows($payload['abilities'], $previous['abilities'] ?? []);
    }
    if (str_starts_with($key, 'token:') && !array_key_exists('transformation', $payload)
        && is_array($previous['transformation'] ?? null) && ($payload['characterId'] ?? '') === ($previous['characterId'] ?? '')
        && ($payload['followCharacter'] ?? true) !== false && empty($payload['linkedTokenId'])) $payload['transformation'] = $previous['transformation'];
    if ($key === 'activity') {
        // Periodic damage is server owned; an old or modified MJ snapshot cannot reset its counters.
        $payload['damageOverTime'] = is_array($previous['damageOverTime'] ?? null) ? $previous['damageOverTime'] : [];
        $payload['attackEnhancements'] = is_array($previous['attackEnhancements'] ?? null) ? $previous['attackEnhancements'] : [];
        $payload['pendingDotResolutions'] = $previous['pendingDotResolutions'] ?? [];
        $payload['rollRevelations'] = $previous['rollRevelations'] ?? [];
        $removedGuards = [];
        foreach ($previous['resourceReceipts'] ?? [] as $receipt) if (($receipt['kind'] ?? '') === 'combat-effect' && ($receipt['result']['kind'] ?? '') === 'guard' && ($receipt['result']['decision'] ?? '') === 'remove') $removedGuards[$receipt['result']['effectId']] = true;
        if (is_array($payload['nextAttackGuards'] ?? null)) $payload['nextAttackGuards'] = array_values(array_filter($payload['nextAttackGuards'], static fn ($guard): bool => !isset($removedGuards[$guard['id'] ?? ''])));
        // Older client normalizers cannot represent casting receipts. Preserve
        // immutable, unexpired authority receipts across their activity writes.
        $now = (int) floor(microtime(true) * 1000);
        $serverReceiptKinds = ['ability-cast', 'ability-workflow', 'ability-validation', 'token-roll', 'combat-effect', 'shortcut-roll', 'character-create', 'timer-create', 'token-clone', 'studio-conversation-create', 'item-command', 'passage-command', 'luck-reset', 'strategy-break', 'table-tokens'];
        $receipts = [];
        foreach ($payload['resourceReceipts'] ?? [] as $receipt) if (is_array($receipt) && !in_array($receipt['kind'] ?? '', $serverReceiptKinds, true) && ($receipt['expiresAt'] ?? 0) > $now) $receipts[$receipt['requestId'] ?? ''] = $receipt;
        foreach ($previous['resourceReceipts'] ?? [] as $receipt) if (is_array($receipt) && in_array($receipt['kind'] ?? '', $serverReceiptKinds, true) && ($receipt['expiresAt'] ?? 0) > $now) $receipts[$receipt['requestId'] ?? ''] = $receipt;
        if (count($receipts) + count($payload['pendingAbilityCasts'] ?? $previous['pendingAbilityCasts'] ?? []) > XAR_RESOURCE_RECEIPT_MAXIMUM) sendError(409, 'Le journal de sécurité des compétences est plein.', 'ability_receipt_capacity');
        if (array_key_exists('resourceReceipts', $payload) || $receipts !== []) $payload['resourceReceipts'] = array_values($receipts);
        // A stale client must never erase or rewind an authoritative workflow.
        $executions = [];
        foreach (normalizeApplicationComplexAbilityExecutions($previous['abilityExecutions'] ?? []) as $execution) $executions[$execution['id']] = $execution;
        foreach (normalizeApplicationComplexAbilityExecutions($payload['abilityExecutions'] ?? []) as $execution) {
            $old = $executions[$execution['id']] ?? null;
            // Existing workflows are command-owned; table snapshots cannot
            // restore spent charges by claiming an equal or newer revision.
            if (!is_array($old)) $executions[$execution['id']] = $execution;
        }
        if (array_key_exists('abilityExecutions', $payload) || $executions !== []) {
            $payload['abilityExecutions'] = trimApplicationComplexAbilityExecutions(array_values($executions));
        }
        $timers = array_column($previous['actionTimers'] ?? [], null, 'id');
        foreach ($payload['actionTimers'] ?? [] as $index => $timer) {
            $old = $timers[$timer['id'] ?? ''] ?? [];
            foreach (['abilityId', 'characterId', 'tokenId', 'restRecharge', 'restUseCount', 'restUseLimit', 'reusableInTurn', 'turnKey', 'useCount', 'cooldownActive'] as $field) if (!array_key_exists($field, $timer) && array_key_exists($field, $old)) $payload['actionTimers'][$index][$field] = $old[$field];
        }
        $attacks = array_column($previous['pendingAttacks'] ?? [], null, 'id');
        foreach ($previous['attackReceipts'] ?? [] as $r) if (is_array($r['attack'] ?? null)) $attacks[$r['attack']['id'] ?? ''] = $r['attack'];
        $preserve = static function ($attack) use ($attacks) {
            if (!is_array($attack)) return $attack;
            $old = $attacks[$attack['id'] ?? ''] ?? [];
            if (($old['visibility'] ?? '') === 'gm') $attack['visibility'] = 'gm';
            if (is_array($attack['opposition'] ?? null) && isset($old['opposition']['accountId'])) {
                $attack['opposition']['accountId'] = $old['opposition']['accountId'];
            }
            foreach (['onHitConditions', 'effectsApplied', 'damageComponents', 'damageOverTime', 'dotApplied', 'areaRadiusCells', 'areaAnchorOnly', 'oppositionAbility', 'reflection', 'damageModifier', 'validationKind', 'provisionalStatus'] as $field) {
                if (!array_key_exists($field, $attack) && array_key_exists($field, $old)) $attack[$field] = $old[$field];
            }
            if (($old['attackKind'] ?? '') === 'custom') $attack['attackKind'] = 'custom';
            // A 3.2.16 activity snapshot knows the attack objects but not the
            // complete canonical roll presentation added in 3.2.17. Keep
            // those authoritative fields when that client writes the same
            // pending attack or receipt back without them.
            foreach (['hit', 'opposition'] as $rollKey) {
                if (!is_array($attack[$rollKey] ?? null) || !is_array($old[$rollKey] ?? null)) continue;
                foreach (['label', 'characterName', 'formula', 'total', 'rollMode', 'selectedIndex', 'attempts'] as $field) {
                    if (!array_key_exists($field, $attack[$rollKey]) && array_key_exists($field, $old[$rollKey])) {
                        $attack[$rollKey][$field] = $old[$rollKey][$field];
                    }
                }
            }
            foreach (['hitRoll', 'oppositionRoll', 'damageRoll'] as $rollKey) {
                if (!array_key_exists($rollKey, $attack) && is_array($old[$rollKey] ?? null)) $attack[$rollKey] = $old[$rollKey];
            }
            if (is_array($attack['damage'] ?? null) && is_array($old['damage'] ?? null)) {
                foreach (['rollId', 'total', 'rollMode', 'selectedIndex', 'attempts'] as $field) {
                    if (!array_key_exists($field, $attack['damage']) && array_key_exists($field, $old['damage'])) {
                        $attack['damage'][$field] = $old['damage'][$field];
                    }
                }
            }
            return $attack;
        };
        if (isset($payload['pendingAttacks'])) $payload['pendingAttacks'] = array_map($preserve, $payload['pendingAttacks']);
        foreach ($payload['attackReceipts'] ?? [] as $index => $receipt) if (isset($receipt['attack'])) $payload['attackReceipts'][$index]['attack'] = $preserve($receipt['attack']);
    }
    return $payload;
}
