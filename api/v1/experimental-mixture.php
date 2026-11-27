<?php
declare(strict_types=1);

const XAR_EXPERIMENTAL_MIXTURE_ID = 'ability-mania-experimental-mixture';
const XAR_EXPERIMENTAL_MIXTURE_CAPSULE = 'item-template-mania-reagent-capsule';
const XAR_EXPERIMENTAL_MIXTURE_MANA_COST = 30;
const XAR_MIXTURE_INVINCIBILITY_ID = 'ability-mania-experimental-mixture-invincibility';
const XAR_MIXTURE_PETRIFICATION_ID = 'ability-mania-experimental-mixture-petrification';
const XAR_MIXTURE_LIGHTNING_ID = 'ability-mania-experimental-mixture-lightning';
const XAR_MIXTURE_ACID_ID = 'ability-mania-experimental-mixture-acid';
const XAR_MIXTURE_IRRADIATION_ID = 'ability-mania-experimental-mixture-irradiation';

function applicationExperimentalMixtureOutcomes(): array
{
    return [
        'restoration-field' => ['polarity'=>'positive','label'=>'Brume réparatrice','radius'=>4,'field'=>'restoration','formula'=>'1d20'],
        'vital-concentrate' => ['polarity'=>'positive','label'=>'Concentré vital','radius'=>2,'cleanse'=>true],
        'regenerative-serum' => ['polarity'=>'positive','label'=>'Potion d’invincibilité','radius'=>0,'formula'=>'1d50','invincibility'=>true],
        'alchemical-blast' => ['polarity'=>'negative','label'=>'Feu grégeois','radius'=>4,'field'=>'alchemical-fire','formula'=>'1d25+5','damageType'=>'fire'],
        'caustic-reaction' => ['polarity'=>'negative','label'=>'Foudre aveuglante','radius'=>2,'lightning'=>true],
        'corrosive-catalyst' => ['polarity'=>'negative','label'=>'Acide moléculaire','radius'=>0,'formula'=>'10d10+25','damageType'=>'physical','acid'=>true],
        'mixture-explosion' => ['polarity'=>'positive','label'=>'Explosion de la mixture','radius'=>0,'immediate'=>true],
        'mixture-irradiation' => ['polarity'=>'negative','label'=>'Irradiation de la mixture','radius'=>4,'immediate'=>true,
            'formula'=>'1d50','pulseFormula'=>'1d10','damageType'=>'ignore','exposureMinimumPercent'=>10],
    ];
}

function applicationExperimentalMixturePool(string $polarity): array
{
    return array_keys(array_filter(applicationExperimentalMixtureOutcomes(), static fn(array $entry): bool =>
        $entry['polarity'] === $polarity));
}

function validApplicationExperimentalMixture(mixed $mix): bool
{
    return is_array($mix) && count($mix) === 4 && applicationVisionZoneId($mix['id'] ?? null)
        && applicationVisionZoneId($mix['accountId'] ?? null) && is_string($mix['outcomeId'] ?? null)
        && isset(applicationExperimentalMixtureOutcomes()[$mix['outcomeId']])
        && is_string($mix['potionLabel'] ?? null) && $mix['potionLabel'] !== ''
        && strlen($mix['potionLabel']) <= 512;
}

function applicationPublicExperimentalMixture(array $mix): array
{
    return array_intersect_key($mix, array_flip(['id', 'outcomeId', 'potionLabel']));
}

function validApplicationExperimentalMixtureCharges(mixed $charges): bool
{
    if (!is_array($charges) || !array_is_list($charges)) return false;
    $outcomes = applicationExperimentalMixtureOutcomes();
    $seen = [];
    foreach ($charges as $charge) {
        if (!validApplicationExperimentalMixture($charge) || !empty($outcomes[$charge['outcomeId']]['immediate'])
            || isset($seen[$charge['id']])) return false;
        $seen[$charge['id']] = true;
    }
    return true;
}

function applicationExperimentalMixtureCharges(array $token): array
{
    if (!isset($token['experimentalMixtureCharges']) && !isset($token['experimentalMixture'])) return [];
    $charges = $token['experimentalMixtureCharges'] ?? [];
    if (!validApplicationExperimentalMixtureCharges($charges)) $charges = [];
    $legacy = $token['experimentalMixture'] ?? null;
    if (validApplicationExperimentalMixture($legacy) && empty(applicationExperimentalMixtureOutcomes()[$legacy['outcomeId']]['immediate'])
        && !in_array($legacy['id'], array_column($charges, 'id'), true)) $charges[] = $legacy;
    return $charges;
}

function applicationExperimentalMixturePotion(array $items, array $source, string $itemId): array
{
    $index = findEntryIndex($items['instances'] ?? [], $itemId);
    $item = $items['instances'][$index] ?? [];
    if (($item['location']['kind'] ?? '') !== 'inventory' || ($item['location']['characterId'] ?? '') !== unitIdForToken($source)
        || !empty($item['vesselRequest'])) return [];
    foreach ($items['pendingUses'] ?? [] as $use) {
        if (in_array($itemId, $use['itemIds'] ?? [$use['itemId'] ?? ''], true)) return [];
    }
    $templates = array_column($items['templates'] ?? [], null, 'id');
    $template = $templates[$item['templateId']] ?? [];
    if ($template === [] || $template['id'] === XAR_EXPERIMENTAL_MIXTURE_CAPSULE) return [];
    $vessel = !empty($template['potionVessel']);
    if ($vessel && empty($item['vesselContentTemplateId'])) return [];
    $effective = effectivePotionVesselTemplate($template, $item, $items);
    if ((($effective['kind'] ?? '') !== 'potion' && ($effective['active']['ingredientTag'] ?? '') !== 'elemental-potion')
        || $vessel && $effective === $template) return [];
    return ['index'=>$index,'item'=>$item,'template'=>$template,'label'=>$effective['name']];
}

function onlineExperimentalMixtureHeal(
    PDO $db, array &$records, array &$pending, string $sceneId, array $target, int $amount, string $accountId
): int|float {
    $owner = applicationAbilityCastingOwner($target);
    $characterKey = 'character:' . $owner['characterId'];
    $character = $owner['characterId'] !== '' ? ($pending[$characterKey]['payload'] ?? applicationDomainPayload($records, $characterKey)) : [];
    $rules = $character !== [] ? synchronizeOnlineCharacterToken($target, $character) : $target;
    if (applicationTraditionalHealingOnly($character ?: $target) || $amount <= 0
        || onlineTokenIsDead($rules, onlineTokenControllerIdFromRecords($db, $records, $target) !== '')) return 0;
    $result = applyOnlineTokenResourceAdjustment($db, $records, $pending, $sceneId, $target['id'], 'hp', $amount, $accountId, true, null, true);
    return $result['appliedDelta'];
}

function onlineExperimentalMixtureTarget(
    PDO $db, array &$records, array $map, array $source, array $target, ?string $polarity, Closure $visible
): bool {
    if ($target === [] || applicationIsNonCombatant($target) || !onlineTokenOnActiveLayer($target,$map) || !$visible($target)) return false;
    $targetController = onlineTokenControllerIdFromRecords($db, $records, $target);
    $owner = applicationAbilityCastingOwner($target);
    $sheet = $owner['characterId'] !== '' ? applicationDomainPayload($records, 'character:' . $owner['characterId']) : [];
    $health = $sheet !== [] ? synchronizeOnlineCharacterToken($target, $sheet) : $target;
    if (($health['maxHp'] ?? 0) <= 0 || onlineTokenIsDead($health, $targetController !== '')) return false;
    if ($polarity === null) return true;
    $sourceController = onlineTokenControllerIdFromRecords($db, $records, $source);
    $self = unitIdForToken($source) === unitIdForToken($target);
    $allied = $self || $targetController !== '' || !empty($target['allied']);
    if ($sourceController === '' && empty($source['allied']) && !$self) $allied = $targetController === '' && empty($target['allied']);
    return $polarity === 'positive' ? $allied : !$self && (!$allied || !empty($source['friendlyFire']));
}

function onlineExperimentalMixtureDamage(
    PDO $db, array &$records, array &$pending, array $table, array $identity, array $source,
    array $targets, array $outcome, string $sceneId, bool $environmental = false
): array {
    if ($targets === []) return ['damage'=>0,'healing'=>0];
    $rolled = onlineRollFormulaWithMode($outcome['formula'], 'normal');
    $visible = onlineGmTokenVisibility($db, $records, $table, $sceneId);
    $publicSource = $visible($source);
    $minimumPercent = $outcome['exposureMinimumPercent'] ?? null;
    $seen = [];
    $effectRolls = [];
    $totals = ['damage'=>0,'healing'=>0];
    $resistanceState = onlineAttackEnhancementState($db, $records, $pending, $sceneId);
    $amount = applicationMolecularAttackDamage((int)$rolled['total'], $outcome['damageType'], applicationHasMolecularAcid($source, $resistanceState, $sceneId));
    foreach ($targets as $target) {
        $unitId = unitIdForToken($target);
        if (isset($seen[$unitId])) continue;
        $seen[$unitId] = true;
        $hp = onlineApplyMixtureDamage($db, $records, $pending, $sceneId, $target, $outcome['damageType'], $amount, $resistanceState,
            $environmental, $minimumPercent);
        $totals['damage'] += $hp['appliedDamage'] ?? 0;
        $totals['healing'] += $hp['appliedHealing'] ?? 0;
        if ($hp === []) continue;
        $public = $publicSource && $visible($target);
        $effectRoll = onlineRollEntry($identity, $rolled, $outcome['label'], (string) ($target['name'] ?? 'Cible'));
        $effectRoll = onlineAbilityRollVisibility($effectRoll, $source, $identity, $public, $sceneId, $source['id']);
        if (!$public) {
            $effectRoll['visibility'] = 'gm';
            $effectRoll['revealed'] = false;
        }
        $damage = $hp['appliedDamage'] ?? 0;
        $healing = $hp['appliedHealing'] ?? 0;
        $effectRoll['damageMitigation'] = $hp['damageMitigation'];
        if ($damage > 0 || $healing > 0) {
            $effectRoll['mapEvent'] = ['kind'=>$damage>0?'damage':'roll','applied'=>true,'value'=>$damage>0?$damage:$healing,
                'label'=>$outcome['label'],'sceneId'=>$sceneId,'layerId'=>onlineTokenLayerId($target),
                'sourceTokenId'=>$source['id'],'targetTokenId'=>$target['id'],'anchorTokenId'=>$target['id'],'targetName'=>$target['name'] ?? 'Cible'];
        }
        if ($minimumPercent !== null) $effectRoll['breakdown'] .= ' · '.max($minimumPercent,$amount).' % des PV max · '.$damage.' dégâts appliqués';
        $effectRolls[] = $effectRoll;
    }
    if ($effectRolls !== []) {
        $activity = $pending['activity']['payload'] ?? applicationDomainPayload($records, 'activity');
        $activity['rolls'] = array_slice(applicationUniqueRolls([...$effectRolls,...($activity['rolls'] ?? [])]),0,100);
        queueOnlineDomainUpsert($pending,$records,'activity',$activity);
    }
    return $totals;
}

function onlineApplyMixtureDamage(
    PDO $db, array &$records, array &$pending, string $sceneId, array $target, string $type, int $amount, array $state,
    bool $environmental = false, ?int $minimumHpPercent = null
): array {
    $targetKey = onlineTokenDomainKey($sceneId, $target['id']);
    $target = $pending[$targetKey]['payload'] ?? $target;
    $owner = applicationAbilityCastingOwner($target);
    $characterKey = 'character:'.$owner['characterId'];
    $sheet = $owner['characterId'] !== '' ? ($pending[$characterKey]['payload'] ?? applicationDomainPayload($records, $characterKey)) : [];
    $rules = $sheet !== [] ? synchronizeOnlineCharacterToken($target, $sheet) : $target;
    if (($rules['hp'] ?? 0) <= 0 || onlineTokenIsDead($rules, onlineTokenControllerIdFromRecords($db, $records, $target) !== '')) return [];
    if ($minimumHpPercent !== null) {
        $amount = (int) round(($rules['maxHp'] ?? 0) * max($minimumHpPercent, $amount) / 100);
    }
    $damage = onlineTypedDamageSummary($amount, applicationEnhancedResistanceTarget($rules, $state, $sceneId), $type);
    $hp = $damage['finalDamage'] > 0
        ? applyOnlineAttackDamage($db, $records, $pending, $targetKey, $target, $damage['finalDamage'], !$environmental, [...$damage,'damageType'=>$type], !$environmental)
        : [];
    $applied = $hp['appliedDamage'] ?? 0;
    $guard = $hp['guardPreventedDamage'] ?? 0;
    $passive = !empty($hp['invulnerable']) ? $damage['finalDamage'] : ($hp['passivePreventedDamage'] ?? 0);
    $hp['damageMitigation'] = ['rawDamage'=>$damage['rawDamage'],'armorPercent'=>$damage['armorPercent'],
        'preventedDamage'=>$damage['rawDamage']-$applied,'finalDamage'=>$applied,
        ...array_intersect_key($damage,array_fill_keys(['elementalPreventedDamage','elementalResistancePercent','damageElement'],true)),
        ...($guard > 0 ? ['guardPreventedDamage'=>$guard] : []),
        ...($passive > 0 ? ['passivePreventedDamage'=>$passive] : []),
        ...(($hp['poisonConvertedDamage'] ?? 0) > 0 ? ['poisonConvertedDamage'=>$hp['poisonConvertedDamage'],'appliedHealing'=>$hp['appliedHealing']] : [])];
    return $hp;
}

function onlineMixtureSceneRecords(PDO $db, array &$records, string $sceneId, array $keys = []): array
{
    $keys = array_diff(['scene:'.$sceneId,'map:'.$sceneId,'initiative:'.$sceneId,'token-index:'.$sceneId,...$keys],array_keys($records));
    if ($keys !== []) $records += applicationDomainRecords($db,array_values($keys));
    return onlineSceneTokensForLights($db,$records,[],$sceneId);
}

function onlineMixtureCharacterRecords(PDO $db, array &$records, array $tokens): void
{
    $keys = [];
    foreach ($tokens as $token) {
        $characterId = $token['characterId'] ?? '';
        if ($characterId !== '' && !isset($records['character:'.$characterId])) $keys[] = 'character:'.$characterId;
    }
    if ($keys !== []) $records += applicationDomainRecords($db,$keys);
}

function onlineMixtureDeviceState(PDO $db, array &$records, array $pending, string $sceneId, string $layer, array $purgeTargets = []): array
{
    $scope = [$sceneId=>true];
    $owners = [];
    foreach ($purgeTargets as $target) {
        if (!unitUsesCharacterSheet($target) || isset($owners[$target['characterId']])) continue;
        $owners[$target['characterId']] = true;
        $related = applicationCharacterTokenDomainRecords($db,$target['characterId']);
        $records += $related;
        foreach ($related as $key=>$record) {
            if (unitUsesCharacterSheet($record['payload'])) $scope[explode(':',$key,3)[1]] = true;
        }
    }
    $tokens = [];
    foreach (array_keys($scope) as $id) {
        array_push($tokens,...onlineMixtureSceneRecords($db,$records,$id,['table','roster','devices','items','activity','luck']));
    }
    onlineMixtureCharacterRecords($db,$records,$tokens);
    // Fully read each affected token index so the native flush cannot drop unrelated pions from a linked scene.
    $view = onlineDeviceOverlayRecords($records,$pending);
    $view['scene-index']['payload'] = ['order'=>array_keys($scope)];
    return onlineDeviceSelectScene(domainsToApplicationState($view,0),$sceneId,$layer);
}

function onlineExperimentalVitalConcentrate(
    PDO $db, array &$records, array &$pending, string $sceneId, string $layer, array $source, array $targets, string $label
): void {
    if ($targets === []) return;
    $state = onlineMixtureDeviceState($db,$records,$pending,$sceneId,$layer,$targets);
    $clock = $state['initiative'];
    $group = XAR_EXPERIMENTAL_MIXTURE_ID;
    $bonuses = ['fireResistance'=>50,'iceResistance'=>50,'poisonResistance'=>50,'bleedingResistance'=>50,
        'physicalResistance'=>10,'magicalResistance'=>10];
    $seen = [];
    foreach ($targets as $target) {
        $unitId = unitIdForToken($target);
        if (isset($seen[$unitId])) continue;
        $unit = unitById($state, $unitId, false);
        if ($unit === null) continue;
        $seen[$unitId] = true;
        applicationCleanseUnitEffects($state, $unit, ['poison','bleeding']);
        if (unitUsesCharacterSheet($target)) {
            $index = findEntryIndex($state['characters'], $unitId);
            if ($index >= 0) $state['characters'][$index] = $unit;
        }
        $holder = applicationEnhancementHolder($target);
        $current = $state['attackEnhancements'] ?? [];
        $index = -1;
        foreach ($current as $i => $effect) {
            if (($effect['holder'] ?? '') === $holder && ($effect['stackGroup'] ?? '') === $group
                && ($effect['sceneId'] ?? '') === $sceneId) { $index = $i; break; }
        }
        $effect = ['id'=>$current[$index]['id'] ?? 'mixture-resistance-'.randomToken(16),'abilityId'=>$group,
            'holder'=>$holder,'label'=>$label,'sceneId'=>$sceneId,'targetTokenId'=>$target['id'],
            'casterTokenId'=>$source['id'],'abilityImage'=>'','combatId'=>($clock['active']??false)?($clock['combatId']??''):'',
            'lastTurnSerial'=>$clock['turnSerial']??0,'mode'=>'stats','turns'=>5,'remainingTurns'=>5,'stackGroup'=>$group,
            'effectIndex'=>0,'bonuses'=>$bonuses,...applicationDotPlacementRound($clock, $target['id'])];
        if ($index >= 0) $current[$index] = $effect;
        else $current[] = $effect;
        $state['attackEnhancements'] = $current;
    }
    onlineDeviceFlush($state, $records, $pending);
}

function onlineExperimentalBlindingLightning(
    PDO $db, array &$records, array &$pending, string $sceneId, string $layer, array $source, array $targets, string $label
): void {
    if ($targets === []) return;
    $state = onlineMixtureDeviceState($db,$records,$pending,$sceneId,$layer);
    $blindness = dsRider(['version'=>1,'label'=>'Aveuglé','turns'=>2,'resistance'=>false]);
    $enhancement = ['id'=>XAR_MIXTURE_LIGHTNING_ID,'name'=>$label,
        'attackEnhancement'=>['mode'=>'stats','turns'=>5,'bonuses'=>[
            'fireResistance'=>-50,'iceResistance'=>-50,'poisonResistance'=>-50,'bleedingResistance'=>-50,
        ]]];
    $seen = [];
    foreach ($targets as $target) {
        $holder = unitIdForToken($target);
        if (isset($seen[$holder]) || applicationHasAllHarmImmunity($target, $state, $sceneId)) continue;
        $seen[$holder] = true;
        onlineDeviceTimedCondition($state, ['sceneId'=>$sceneId,'targetTokenId'=>$target['id'],'sourceTokenId'=>$source['id'],
            'sourceAbilityId'=>XAR_MIXTURE_LIGHTNING_ID,'rider'=>$blindness,'resisted'=>false]);
        applicationActivateAttackEnhancement($state, $enhancement, $source, $sceneId, $state['initiative'],
            'mixture-lightning-'.randomToken(16), $target);
    }
    onlineDeviceFlush($state, $records, $pending);
}

function onlineExperimentalMolecularAcid(
    PDO $db, array &$records, array &$pending, string $sceneId, string $layer, array $source, array $target, string $label
): void {
    $state = onlineMixtureDeviceState($db,$records,$pending,$sceneId,$layer);
    $unit = unitById($state, unitIdForToken($target), false);
    if (($unit['resources']['hp'] ?? 0) <= 0 || ($unit['healthOverride'] ?? '') === 'dead'
        || applicationHasAllHarmImmunity($target, $state, $sceneId)) return;
    $definition = ['id'=>XAR_MIXTURE_ACID_ID,'name'=>$label,'attackEnhancement'=>['mode'=>'molecular-acid','turns'=>1]];
    applicationActivateAttackEnhancement($state, $definition, $source, $sceneId, $state['initiative'],
        'mixture-acid-'.randomToken(16), $target);
    onlineDeviceFlush($state, $records, $pending);
}

function applicationPurgeHarmfulMixtureEffects(array &$state, array $target): void
{
    $ids = [];
    $unitId = unitIdForToken($target);
    $keep = static fn(mixed $label): bool => !applicationIsHarmfulCondition($label);
    $purgeMap = static function (array &$map) use ($unitId, $keep, &$ids): void {
        foreach ($map['tokens'] ?? [] as $index => $token) {
            if (unitIdForToken($token) !== $unitId) continue;
            $ids[$token['id']] = true;
            foreach (['conditions','conditionsBase'] as $field) {
                if (isset($token[$field])) $token[$field] = array_values(array_filter($token[$field], $keep));
            }
            $token['condition'] = implode(', ', $token['conditions'] ?? []);
            unset($token['controlEffect']);
            $map['tokens'][$index] = $token;
        }
    };
    $purgeMap($state['map']);
    foreach ($state['scenes'] ?? [] as $i => $scene) {
        if (!isset($scene['combat']['map'])) continue;
        $purgeMap($scene['combat']['map']);
        $state['scenes'][$i] = $scene;
    }
    if (unitUsesCharacterSheet($target)) {
        $index = findEntryIndex($state['characters'], $unitId);
        if ($index >= 0) {
            foreach (['conditions','conditionsBase'] as $field) {
                if (isset($state['characters'][$index][$field])) {
                    $state['characters'][$index][$field] = array_values(array_filter($state['characters'][$index][$field], $keep));
                }
            }
        }
    }
    foreach (['damageOverTime', 'pendingDotResolutions'] as $key) {
        $state[$key] = array_values(array_filter($state[$key] ?? [], static fn(array $effect): bool =>
            !isset($ids[$effect['targetTokenId'] ?? '']) || ($effect['manualHp'] ?? false) && ($effect['direction'] ?? 0) > 0));
    }
    $holder = applicationEnhancementHolder($target);
    $state['attackEnhancements'] = array_values(array_filter($state['attackEnhancements'] ?? [], static fn(array $effect): bool =>
        ($effect['holder'] ?? '') !== $holder || !applicationHarmfulEnhancement($effect)));
    $removed = [];
    foreach ($state['devices']['timedConditions'] ?? [] as $effect) {
        if (isset($ids[$effect['targetTokenId'] ?? '']) && applicationIsHarmfulCondition($effect['label'] ?? null)) $removed[$effect['id']] = true;
    }
    $state['devices']['timedConditions'] = array_values(array_filter($state['devices']['timedConditions'] ?? [], static fn(array $effect): bool => !isset($removed[$effect['id']])));
    $state['devices']['pendingRolls'] = array_values(array_filter($state['devices']['pendingRolls'] ?? [], static function (array $pending) use ($ids, $removed): bool {
        $effect = $pending['effect'] ?? [];
        return !isset($removed[$effect['conditionId'] ?? '']) && !(($pending['type'] ?? '') === 'condition'
            && isset($ids[$effect['targetTokenId'] ?? '']) && applicationIsHarmfulCondition($effect['rider']['label'] ?? null));
    }));
}

function onlineExperimentalInvincibility(
    PDO $db, array &$records, array &$pending, array $table, array $identity, array $source, array $target, string $sceneId, string $layer, array $outcome
): int|float {
    $state = onlineMixtureDeviceState($db,$records,$pending,$sceneId,$layer,[$target]);
    applicationPurgeHarmfulMixtureEffects($state, $target);
    $definition = ['id'=>XAR_MIXTURE_INVINCIBILITY_ID,'name'=>$outcome['label'],
        'attackEnhancement'=>['mode'=>'immunity','family'=>'all-harm','turns'=>5]];
    applicationActivateAttackEnhancement($state, $definition, $source, $sceneId, $state['initiative'], 'mixture-invincibility-'.randomToken(16), $target);
    onlineDeviceFlush($state, $records, $pending);
    $unit = unitById($state, unitIdForToken($target), false);
    if ($unit === null || applicationTraditionalHealingOnly($unit, $state)) return 0;
    $rolled = onlineRollFormulaWithMode($outcome['formula'], 'normal');
    $maximum = (int) ($unit['resources']['maxHp'] ?? 0);
    $amount = (int) round($maximum * (int) $rolled['total'] / 100) + 50;
    $roll = onlineRollEntry($identity, $rolled, $outcome['label'], (string) ($source['name'] ?? 'Mania'));
    $visible = onlineGmTokenVisibility($db, $records, $table, $sceneId);
    onlineAppendAbilityEffectRoll($records, $pending, onlineAbilityRollVisibility($roll, $source, $identity, $visible($source) && $visible($target), $sceneId, $source['id']));
    return onlineExperimentalMixtureHeal($db, $records, $pending, $sceneId, $target, $amount, $identity['id']);
}

function onlineExperimentalMixtureExplosion(
    PDO $db, array &$records, array &$pending, string $sceneId, string $layer, array $source
): void {
    $state = onlineMixtureDeviceState($db,$records,$pending,$sceneId,$layer);
    if (applicationHasAllHarmImmunity($source, $state, $sceneId)) return;
    $rider = dsRider(['version'=>1,'label'=>'Pétrifié','turns'=>3,'blocksActions'=>true,'blocksMovement'=>true,'resistance'=>false]);
    onlineDeviceTimedCondition($state,['sceneId'=>$sceneId,'targetTokenId'=>$source['id'],'sourceTokenId'=>$source['id'],
        'sourceAbilityId'=>XAR_MIXTURE_PETRIFICATION_ID,'rider'=>$rider,'resisted'=>false]);
    onlineDeviceFlush($state, $records, $pending);
}

function onlineExperimentalMixtureIrradiation(
    PDO $db, array &$records, array &$pending, array $table, array $identity, string $sceneId, string $layer, array $source, array $outcome
): array {
    $result = onlineExperimentalMixtureDamage($db, $records, $pending, $table, $identity, $source, [$source], $outcome, $sceneId, true);
    $state = onlineMixtureDeviceState($db,$records,$pending,$sceneId,$layer);
    $holder = applicationEnhancementHolder($source);
    $unit = unitById($state, unitIdForToken($source), false);
    $result = ['damage'=>$result['damage'],'irradiated'=>false];
    $health = [...$source,...($unit['resources'] ?? []),'healthOverride'=>$unit['healthOverride'] ?? $source['healthOverride'] ?? null];
    if ($unit === null || onlineTokenIsDead($health, onlineTokenControllerIdFromRecords($db, $records, $source) !== '')
        || applicationHasAllHarmImmunity($source, $state, $sceneId)) return $result;
    $clock = ['lastIrradiationCombatId'=>'','lastIrradiationRound'=>0];
    foreach ($state['attackEnhancements'] ?? [] as $effect) {
        if (($effect['abilityId'] ?? '') === XAR_MIXTURE_IRRADIATION_ID && ($effect['holder'] ?? '') === $holder) {
            $clock = array_replace($clock, array_intersect_key($effect, array_flip(['lastIrradiationCombatId','lastIrradiationRound'])));
            break;
        }
    }
    $clock['lastIrradiationManualTurn'] = (int) ($state['devices']['manualTurns'][$sceneId] ?? 0);
    $initiative = $state['initiative'];
    $ownIndex = array_search($source['id'], $initiative['order'] ?? [], true);
    if (!empty($initiative['active']) && ($initiative['turnsStarted'] ?? true) === true
        && $ownIndex !== false && $ownIndex < ($initiative['currentIndex'] ?? 0)
        && !in_array($source['id'], $initiative['delayedTurnIds'] ?? [], true)) {
        $clock['lastIrradiationCombatId'] = $initiative['combatId'] ?? '';
        $clock['lastIrradiationRound'] = max(1, (int) ($initiative['round'] ?? 1));
    }
    $definition = ['id'=>XAR_MIXTURE_IRRADIATION_ID,'name'=>$outcome['label'],
        'attackEnhancement'=>['mode'=>'mixture-irradiation','turns'=>5]];
    applicationActivateAttackEnhancement($state, $definition, $source, $sceneId, $initiative,
        'mixture-irradiation-'.randomToken(16), $source, $clock);
    onlineDeviceFlush($state, $records, $pending);
    $result['irradiated'] = true;
    return $result;
}

function applicationAdvanceMixtureIrradiation(array &$state, array $previous, array $current, array $context, array &$events): void
{
    $sceneId = $state['activeSceneId'];
    $manual = ($context['manual'] ?? false) === true;
    if ($manual) {
        if (!empty($current['active'])) return;
        $turn = (int) ($context['manualTurn'] ?? (int) ($state['devices']['manualTurns'][$sceneId] ?? 0) + 1);
    } else {
        $combat = $current['combatId'] ?? '';
        $serial = (int) ($current['turnSerial'] ?? 0);
        $round = max(1, (int) ($previous['round'] ?? 1));
        if ($combat === '' || $combat !== ($previous['combatId'] ?? '') || empty($previous['active']) || empty($current['active'])
            || ($previous['turnsStarted'] ?? true) !== true || $serial <= ($previous['turnSerial'] ?? 0)
            || ($current['rewoundTurnSerial'] ?? null) === $serial || ($current['round'] ?? 1) < $round) return;
        $leaving = $previous['order'][$previous['currentIndex'] ?? 0] ?? '';
        $entering = $current['order'][$current['currentIndex'] ?? 0] ?? '';
        if ($leaving === '' || $leaving === $entering && ($current['round'] ?? 1) === $round) return;
    }
    $effects = $state['attackEnhancements'] ?? [];
    $tokens = $state['map']['tokens'] ?? [];
    $changed = false;
    foreach ($effects as $index => $effect) {
        if (($effect['abilityId'] ?? '') !== XAR_MIXTURE_IRRADIATION_ID || ($effect['mode'] ?? '') !== 'mixture-irradiation'
            || ($effect['sceneId'] ?? '') !== $sceneId || ($effect['remainingTurns'] ?? 0) <= 0) continue;
        $byId ??= array_column($tokens, null, 'id');
        $holder = $effect['holder'];
        $caster = $byId[$effect['targetTokenId'] ?? ''] ?? [];
        $unitId = unitIdForToken($caster);
        $unit = unitById($state, $unitId, false);
        $health = [...$caster,...($unit['resources'] ?? []),'healthOverride'=>$unit['healthOverride'] ?? $caster['healthOverride'] ?? null];
        if ($caster === [] || applicationEnhancementHolder($caster) !== $holder || applicationIsNonCombatant($caster)
            || $unit === null || onlineTokenIsDead($health, applicationUnitControllerId($caster, $state) !== '')) {
            unset($effects[$index]);
            $changed = true;
            continue;
        }
        if ($manual) {
            if (($effect['lastIrradiationManualTurn'] ?? 0) >= $turn) continue;
        } else {
            if ($leaving !== $caster['id'] || ($effect['lastIrradiationCombatId'] ?? '') === $combat
                && ($effect['lastIrradiationRound'] ?? 0) >= $round) continue;
        }
        $layer = $caster['layerId'] ?? 'ground';
        $center = applicationTokenSpatialFootprint($caster, $state['map']);
        $nearby = applicationMixtureFieldTargets($state, [...$center,'layerId'=>$layer,'radiusCells'=>4], $tokens);
        unset($nearby[$unitId]);
        if ($nearby !== []) {
            $events[] = ['type'=>'mixture-irradiation.damage','sceneId'=>$sceneId,
                'targetTokenIds'=>array_column(array_column($nearby, 'token'), 'id')];
        }
        $changed = true;
        $effect['remainingTurns']--;
        if ($effect['remainingTurns'] <= 0) unset($effects[$index]);
        else $effects[$index] = [...$effect,...($manual ? ['lastIrradiationManualTurn'=>$turn] : [
            'lastIrradiationCombatId'=>$combat,'lastIrradiationRound'=>$round,
        ])];
    }
    if ($changed) $state['attackEnhancements'] = array_values($effects);
}

function onlineExperimentalMixture(
    PDO $db, array &$records, array &$pending, array $table, array $identity, array $args, bool $isGm
): array {
    $action = $args['action'] ?? null;
    $request = $args['requestId'] ?? null;
    $sceneId = $args['sceneId'] ?? null;
    $sourceId = $args['sourceTokenId'] ?? null;
    $layer = $args['layerId'] ?? null;
    $accountId = $identity['id'];
    if (($args['abilityId'] ?? null) !== XAR_EXPERIMENTAL_MIXTURE_ID || !in_array($action, ['prepare', 'resolve'], true)
        || !applicationResourceReceiptKey($request, 80) || strlen($request) < 16 || !applicationVisionZoneId($sceneId)
        || !applicationVisionZoneId($sourceId) || !is_string($layer)) {
        rejectOnlineCommand($db, 400, 'La référence de la mixture est invalide. Rouvrez la compétence.', 'experimental_mixture_invalid');
    }
    $signature = onlineCommandRequestSignature('ability.mixture', array_intersect_key($args, array_flip(
        ['action','sceneId','layerId','sourceTokenId','abilityId','polarity','potionItemId','mixtureId','targetPoint','targetTokenId']
    )));
    $context = onlinePersistentCommandReceipt($db, $records, 'experimental-mixture', $request, $accountId, $signature, 'experimental_mixture');
    if ($context['receipt'] !== null) return [...$context['receipt']['result'], 'deduplicated'=>true];
    if (!$isGm && $sceneId !== onlineActiveSceneId($table)) rejectOnlineCommand($db, 409, 'La scène a changé. Revenez au pion de Mania.', 'stale_scene');
    if (!$isGm && !empty($table['tacticalSync']['paused'])) rejectOnlineCommand($db, 423, 'La table est verrouillée.', 'table_locked');
    $sourceKey = onlineTokenDomainKey($sceneId, $sourceId);
    $tokens = onlineMixtureSceneRecords($db,$records,$sceneId,[$sourceKey,'devices','items']);
    onlineMixtureCharacterRecords($db,$records,$tokens);
    $map = [...applicationDomainPayload($records,'map:'.$sceneId),'tokens'=>$tokens];
    $source = applicationDomainPayload($records, $sourceKey);
    if ($source === [] || !onlineTokenOnActiveLayer($source, $map) || onlineTokenLayerId($source, $map) !== $layer
        || !$isGm && (!empty($source['hidden']) || onlineTokenControllerIdFromRecords($db, $records, $source) !== $accountId)) {
        rejectOnlineCommand($db, 403, 'Le pion de lancement a changé de niveau ou de contrôleur.', 'ability_source_forbidden');
    }
    $owner = applicationAbilityCastingOwner($source);
    $sheet = $owner['characterId'] !== '' ? applicationDomainPayload($records, 'character:' . $owner['characterId']) : [];
    $rules = $sheet !== [] ? synchronizeOnlineCharacterToken($source, $sheet) : $source;
    $rules['controllerPlayerId'] = onlineTokenControllerIdFromRecords($db, $records, $source);
    $abilities = normalizeOnlineAbilities($rules['abilities'] ?? []);
    $ability = $abilities[findEntryIndex($abilities, XAR_EXPERIMENTAL_MIXTURE_ID)] ?? [];
    if ($ability === []) rejectOnlineCommand($db, 404, 'Ce pion ne possède pas la Mixture expérimentale.', 'experimental_mixture_missing');
    $charges = applicationExperimentalMixtureCharges($source);
    if (applicationAbilitySourceDefeated($rules) || applicationIsNonCombatant($source)) {
        rejectOnlineCommand($db, 409, 'Un pion KO ou mort ne peut pas utiliser cette compétence.', 'ability_source_defeated');
    }
    $clock = applicationDomainPayload($records, 'initiative:' . $sceneId);
    $devices = dsStore(applicationDomainPayload($records, 'devices', dsEmpty()));
    $state = ['devices'=>$devices,'map'=>$map,'initiative'=>$clock];
    dsTurn($state, ['role'=>$isGm ? 'gm' : 'player'], $source);
    dsAssertActions($state, $source, $sceneId);
    $outcomes = applicationExperimentalMixtureOutcomes();
    if ($action === 'prepare') {
        if (!in_array($args['polarity'] ?? null, ['positive','negative'], true) || !applicationVisionZoneId($args['potionItemId'] ?? null)) {
            rejectOnlineCommand($db, 400, 'Choisissez l’effet recherché et une potion de votre inventaire.', 'experimental_mixture_recipe_required');
        }
        $items = applicationDomainPayload($records, 'items');
        $potion = applicationExperimentalMixturePotion($items, $source, $args['potionItemId']);
        if ($potion === []) rejectOnlineCommand($db, 409, 'La potion sélectionnée n’est plus disponible dans cet inventaire.', 'experimental_mixture_potion_unavailable');
        $ability = ['id'=>XAR_EXPERIMENTAL_MIXTURE_ID,'name'=>$ability['name'],'effect'=>'movement','manaCost'=>XAR_EXPERIMENTAL_MIXTURE_MANA_COST,
            'itemRequirement'=>['templateId'=>XAR_EXPERIMENTAL_MIXTURE_CAPSULE,'quantity'=>1,'consumeOn'=>'attempt']];
        $plan = onlinePrepareAbilityCasting($db, $ability, $rules, $sceneId, $clock, $context['activity'], null, $state);
        $plan['deferCue'] = true;
        $cast = onlineCommitAbilityCasting($db, $records, $pending, $plan, ['success'=>true], $rules, $identity, false);
        $items = $pending['items']['payload'] ?? $items;
        $item = $items['instances'][$potion['index']];
        if (!empty($potion['template']['potionVessel'])) emptyPotionVessel($item, $potion['template']);
        else $item['location'] = ['kind'=>'consumed','characterId'=>unitIdForToken($source)];
        $item['revision']++;
        $items['instances'][$potion['index']] = $item;
        queueOnlineDomainUpsert($pending, $records, 'items', $items);
        $possible = applicationExperimentalMixturePool($args['polarity']);
        $mix = ['id'=>'mixture-'.randomToken(16),'accountId'=>$accountId,'outcomeId'=>$possible[random_int(0, count($possible)-1)],'potionLabel'=>$potion['label']];
        $detail = 'Une capsule de réactif et une potion consommées · 30 mana' . ($cast['fatigueGained'] > 0 ? ' · +'.$cast['fatigueGained'].' fatigue' : '');
        $outcome = $outcomes[$mix['outcomeId']];
        if (!empty($outcome['immediate'])) {
            $result = ['prepared'=>false,'resolved'=>true,'mixture'=>applicationPublicExperimentalMixture($mix)];
            if ($mix['outcomeId'] === 'mixture-irradiation') {
                $result += onlineExperimentalMixtureIrradiation($db, $records, $pending, $table, $identity, $sceneId, $layer, $source, $outcome);
            } else onlineExperimentalMixtureExplosion($db, $records, $pending, $sceneId, $layer, $source);
            $summary = 'Mixture expérimentale · '.$outcome['label'];
        } else {
            $charges[] = $mix;
            $result = ['prepared'=>true,'mixture'=>applicationPublicExperimentalMixture($mix)];
            $summary = 'Mixture expérimentale · préparation';
        }
        $footprint = applicationTokenSpatialFootprint($source, $map);
        $cue = normalizeApplicationAbilityCompletionCue(['spell'=>[
            'version'=>1,'presetId'=>'mania-mixture-cinematic-'.$mix['outcomeId'],'sceneId'=>$sceneId,'layerId'=>$layer,
            'placement'=>'source','source'=>['x'=>$footprint['x'],'y'=>$footprint['y'],'size'=>$footprint['size'] ?? 40],'radiusCells'=>1,
            'followSource'=>true,'sourceTokenId'=>$sourceId,
        ]]);
        $activity = $pending['activity']['payload'] ?? applicationDomainPayload($records, 'activity');
        $publicSource = onlineGmTokenVisibleToPlayers($db, $records, $table, $source, $sceneId);
        if (appendApplicationAbilityCueEvent($activity, $cue, $sceneId, $layer, $sourceId, $publicSource ? 'public' : 'gm', 'mixture-cinematic-'.$mix['id']) !== '') {
            queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
        }
    } else {
        $chargeIndex = array_search($args['mixtureId'] ?? null, array_column($charges, 'id'), true);
        if ($chargeIndex === false) {
            rejectOnlineCommand($db, 409, 'Cette charge de mixture n’est plus disponible.', 'experimental_mixture_not_pending');
        }
        $mix = $charges[$chargeIndex];
        if (!$isGm && $mix['accountId'] !== $accountId) {
            rejectOnlineCommand($db, 409, 'Le MJ doit gérer cette charge préparée par l’ancien contrôleur.', 'experimental_mixture_controller_changed');
        }
        $outcome = $outcomes[$mix['outcomeId']];
        $radius = $outcome['radius'];
        $vision = onlineAbilitySourceVisibility($map,$source,$sheet ?: null,$sceneId);
        $targetKey = $radius > 0 ? '' : onlineTokenDomainKey($sceneId,$args['targetTokenId'] ?? '');
        if ($targetKey !== '' && !isset($records[$targetKey])) $records += applicationDomainRecords($db,[$targetKey]);
        $target = applicationDomainPayload($records,$targetKey);
        $point = $radius > 0 ? applicationAreaTargetPoint($args['targetPoint'] ?? null, $layer) : ($target ?: null);
        if ($point === null || !applicationAbilityTargetInRange(['rangeCells'=>8], $source, $point, $map)
            || $radius > 0 && !$vision['point']($point['x'],$point['y'])) {
            rejectOnlineCommand($db, 409, 'Visez une cible ou un point visible à huit cases maximum.', 'experimental_mixture_aim_invalid');
        }
        $targetPolarity = !empty($outcome['cleanse']) || !empty($outcome['lightning']) ? null : $outcome['polarity'];
        $result = ['resolved'=>true,'mixture'=>applicationPublicExperimentalMixture($mix)];
        if (!empty($outcome['field'])) {
            $field = applicationCreateMixtureField($devices, $sceneId, $layer, $source, $point, $clock, $mix['id'], $outcome['label'], $outcome['field']);
            queueOnlineDomainUpsert($pending, $records, 'devices', $devices);
            $result['fieldId'] = $field['id'];
            if ($field['kind'] === 'alchemical-fire') {
                $state = onlineMixtureDeviceState($db,$records,$pending,$sceneId,$layer);
                $targets = array_column(applicationMixtureFieldTargets($state, $field, $state['map']['tokens']), 'token');
                onlineExperimentalMixtureDamage($db, $records, $pending, $table, $identity, $source, $targets, $outcome, $sceneId);
            }
        } else {
            $targetVisible = $vision['token'];
            $eligible = static function (array $token) use ($db,&$records,$map,$rules,$targetPolarity,$targetVisible): bool {
                return onlineExperimentalMixtureTarget($db,$records,$map,$rules,$token,$targetPolarity,$targetVisible);
            };
            if ($radius === 0 && !$eligible($target)) {
                rejectOnlineCommand($db,409,'Choisissez une unité du bon camp visible par le lanceur.','experimental_mixture_target_invalid');
            }
            $targets = $radius > 0
                ? array_values(array_filter(onlineDamageAreaTargets($map['tokens'],$point,$map,$radius),$eligible))
                : [$target];
            if (!empty($outcome['cleanse'])) {
                onlineExperimentalVitalConcentrate($db, $records, $pending, $sceneId, $layer, $source, $targets, $outcome['label']);
            } elseif (!empty($outcome['lightning'])) {
                onlineExperimentalBlindingLightning($db, $records, $pending, $sceneId, $layer, $source, $targets, $outcome['label']);
            } elseif (!empty($outcome['invincibility'])) {
                $result['healing'] = onlineExperimentalInvincibility($db, $records, $pending, $table, $identity, $source, $target, $sceneId, $layer, $outcome);
            } else {
                $result += onlineExperimentalMixtureDamage($db, $records, $pending, $table, $identity, $source, $targets, $outcome, $sceneId);
                if (!empty($outcome['acid'])) onlineExperimentalMolecularAcid($db, $records, $pending, $sceneId, $layer, $source, $target, $outcome['label']);
            }
        }
        array_splice($charges, $chargeIndex, 1);
        $summary = 'Mixture expérimentale · '.$outcome['label'];
        $detail = $radius > 0 ? 'Rayon de '.$radius.' cases' : 'Cible unique';
    }
    $token = $pending[$sourceKey]['payload'] ?? $source;
    if (isset($token['experimentalMixture']) || ($token['experimentalMixtureCharges'] ?? []) !== $charges) {
        unset($token['experimentalMixture']);
        if ($charges === []) unset($token['experimentalMixtureCharges']);
        else $token['experimentalMixtureCharges'] = $charges;
        queueOnlineDomainUpsert($pending, $records, $sourceKey, $token);
    }
    $entry = onlineAppendPlayerAction($db, $records, $pending, $identity, $sceneId, ['kind'=>'ability',
        'characterName'=>$source['name'] ?? 'Mania','summary'=>$summary,'detail'=>$detail]);
    onlineStorePersistentCommandReceipt($records, $pending, $context, 'experimental-mixture', $request, $accountId, $entry['id'], $sourceKey, $signature, $result);
    return $result;
}
