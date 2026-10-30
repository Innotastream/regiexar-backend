<?php
declare(strict_types=1);
require_once __DIR__.'/deferred-defense-authority.php';
// One authoritative engine is shared by direct attacks and MJ-approved groups.
function onlinePerformTargetAttack(PDO $connection,array &$records,array &$pending,array $table,array $identity,array $arguments,bool $isGm,array $context=[]):array {
    $accountId=(string)$identity['id'];$sceneId=(string)($context['sceneId']??onlineActiveSceneId($table));$now=(int)floor(microtime(true)*1000);
    $mechanicsApplied=$context['mechanicsApplied']??null;$mechanicsAbility=$context['mechanicsAbility']??null;$mechanicsStrikePosition=$context['mechanicsStrikePosition']??null;
    $result=[];$defenseApproval=$context['defenseApproval']??null;$authorizedOpposed=null;$prepareDefenseOnly=($context['prepareDefenseOnly']??false)===true;
if (($table['tacticalSync']['paused'] ?? false) === true) {
    rejectOnlineCommand($connection, 423, 'La table est temporairement verrouillée.', 'table_locked');
}
if ($isGm) {
    $requestedSceneId = trim((string) ($arguments['sceneId'] ?? ''));
    if ($requestedSceneId !== '' && !validApplicationDomainIdentifier($requestedSceneId, 80)) {
        rejectOnlineCommand($connection, 400, 'La scène de l’attaque est invalide.', 'invalid_attack_scene');
    }
    if ($requestedSceneId !== '') $sceneId = $requestedSceneId;
} elseif (trim((string) ($arguments['sceneId'] ?? '')) !== ''
    && trim((string) $arguments['sceneId']) !== $sceneId) {
    rejectOnlineCommand($connection, 409, 'La scène diffusée a changé. Actualisez la carte avant d’attaquer.', 'stale_scene');
}
if ($sceneId === '') rejectOnlineCommand($connection, 409, 'Aucune scène active.', 'combat_scene_required');
$requestId = trim((string) ($arguments['requestId'] ?? ''));
if (preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $requestId) !== 1) {
    rejectOnlineCommand($connection, 400, 'Référence d’attaque invalide.', 'invalid_attack_request');
}
$attackRequestSignature = onlineAttackRequestSignature($sceneId, $arguments);
$sourceKey = onlineTokenDomainKey($sceneId, $arguments['sourceTokenId'] ?? '');
$targetKey = onlineTokenDomainKey($sceneId, $arguments['targetTokenId'] ?? '');
if ($sourceKey === '' || $targetKey === '' || ($sourceKey === $targetKey && !in_array(($arguments['attackKind'] ?? ''),['ability','weapon'],true))) {
    rejectOnlineCommand($connection, 400, 'Attaquant ou cible invalide.', 'invalid_attack_target');
}
$initiativeKey = 'initiative:' . $sceneId;
$mapKey = 'map:' . $sceneId;
$records = array_replace($records, applicationDomainRecords($connection, [$sourceKey, $targetKey, $initiativeKey, $mapKey, 'scene:' . $sceneId, 'activity']));
if (applicationDomainPayload($records, 'scene:' . $sceneId) === []) {
    rejectOnlineCommand($connection, 409, 'Cette scène n’existe plus.', 'stale_scene');
}
$activity = $pending['activity']['payload'] ?? applicationDomainPayload($records, 'activity');
$abilityRequestSignature = null; $abilityReceipt = null;
if (($arguments['attackKind'] ?? '') === 'ability') {
    $abilityReceiptSignature = static fn(array $receipt): string => applicationAbilityRequestSignature(
        'token.attack', $sceneId, $arguments, applicationAbilityReceiptHasCastingCheck($receipt)
    );
    $abilityReceipt = onlineAbilityReceipt($connection, $activity, $requestId, $accountId, $abilityReceiptSignature, $isGm);
}
$now = (int) floor(microtime(true) * 1000);
$pendingReceiptAttackIds = [];
foreach (is_array($activity['pendingAttacks'] ?? null) ? $activity['pendingAttacks'] : [] as $pendingAttack) {
    if (is_array($pendingAttack) && trim((string) ($pendingAttack['id'] ?? '')) !== '') {
        $pendingReceiptAttackIds[(string) $pendingAttack['id']] = true;
    }
}
$receipts = array_values(array_filter(
    is_array($activity['attackReceipts'] ?? null) ? $activity['attackReceipts'] : [],
    static fn (mixed $entry): bool => is_array($entry) && (
        (int) ($entry['expiresAt'] ?? 0) > $now
        || isset($pendingReceiptAttackIds[(string) ($entry['attack']['id'] ?? '')])
    )
));
$deduplicatedAttack = null;
foreach ($receipts as $receipt) {
    if ((string) ($receipt['requestId'] ?? '') === $requestId
        && (string) ($receipt['accountId'] ?? '') !== $accountId) {
        rejectOnlineCommand($connection, 403, 'Ce reçu d’attaque appartient à un autre intervenant.', 'attack_receipt_forbidden');
    }
    if ((string) ($receipt['requestId'] ?? '') === $requestId
        && (string) ($receipt['accountId'] ?? '') === $accountId
        && is_array($receipt['attack'] ?? null)) {
        $storedSignature = (string) ($receipt['requestSignature'] ?? '');
        $receiptHasCastingCheck = isset($receipt['attack']['areaGate']['preparedHit']) ? $receipt['attack']['areaGate']['preparedHit']['hasStatCheck'] : (($arguments['attackKind'] ?? '') !== 'ability'
            || trim((string) ($receipt['attack']['cast']['statId'] ?? '')) !== '');
        $expectedAttackSignature = onlineAttackRequestSignature($sceneId, $arguments, $receiptHasCastingCheck);
        $sameRequest = $storedSignature !== ''
            ? hash_equals($storedSignature, $expectedAttackSignature)
            : onlineLegacyAttackReceiptMatchesRequest($receipt['attack'], $sceneId, $arguments);
        if (!$sameRequest) {
            rejectOnlineCommand($connection, 409, 'Cette référence désigne une autre attaque.', 'attack_request_mismatch');
        }
        $deduplicatedAttack = $receipt['attack'];
        break;
    }
}
if ($deduplicatedAttack === null) {
    foreach (is_array($activity['pendingAttacks'] ?? null) ? $activity['pendingAttacks'] : [] as $entry) {
        if (is_array($entry) && (string) ($entry['requestId'] ?? '') === $requestId
            && (string) ($entry['accountId'] ?? '') !== $accountId) {
            rejectOnlineCommand($connection, 403, 'Cette attaque appartient à un autre intervenant.', 'attack_receipt_forbidden');
        }
        if (is_array($entry) && (string) ($entry['requestId'] ?? '') === $requestId
            && (string) ($entry['accountId'] ?? '') === $accountId) {
            if (!onlineLegacyAttackReceiptMatchesRequest($entry, $sceneId, $arguments)) {
                rejectOnlineCommand($connection, 409, 'Cette référence désigne une autre attaque.', 'attack_request_mismatch');
            }
            $deduplicatedAttack = $entry;
            break;
        }
    }
}
if ($deduplicatedAttack === null && $abilityReceipt !== null) rejectOnlineCommand($connection, 409, 'Le reçu de cette attaque a expiré ; sa tentative ne sera pas répétée.', 'ability_attack_receipt_missing');
if (is_array($deduplicatedAttack)) {
    if (!$isGm && ($deduplicatedAttack['attackerRole'] ?? 'player') === 'gm') {
        rejectOnlineCommand($connection, 403, 'Cette attaque a été créée en mode MJ.', 'attack_receipt_forbidden');
    }
    if (!isset($deduplicatedAttack['cast']) && is_array($abilityReceipt['cast'] ?? null)) $deduplicatedAttack['cast'] = $abilityReceipt['cast'];
    $linkedTargets=[];foreach($receipts as $linkedReceipt)if((($linkedReceipt['attack']['weaponAttackParentId']??'')===$deduplicatedAttack['id']||($linkedReceipt['attack']['areaGateParentId']??'')===$deduplicatedAttack['id']))$linkedTargets[]=$isGm?$linkedReceipt['attack']:publicOnlineAttackResult($linkedReceipt['attack'],true,false);
    if($linkedTargets!==[])$result['areaAttacks']=$linkedTargets;
    $result['attack'] = $isGm ? $deduplicatedAttack : publicOnlineAttackResult($deduplicatedAttack, true, false);
    if (is_array($deduplicatedAttack['cast'] ?? null)) {
        $result['cast'] = $deduplicatedAttack['cast'];
        $result['castSucceeded'] = $deduplicatedAttack['cast']['success'];
    }
    $result = [...$result, ...onlineAttackResponseRollFields($deduplicatedAttack, $isGm, true, false)];
    $result['deduplicated'] = true;
} else {
    if (count($receipts) >= XAR_ATTACK_RECEIPT_MAXIMUM) {
        rejectOnlineCommand(
            $connection,
            429,
            'Le journal de sécurité des attaques est plein. Réessayez après expiration des anciens reçus.',
            'attack_receipt_capacity_reached'
        );
    }
    $source = applicationDomainPayload($records, $sourceKey);
    $target = applicationDomainPayload($records, $targetKey);
    $map = $isGm && $mechanicsApplied !== null ? $mechanicsApplied['state']['map'] : applicationDomainPayload($records, $mapKey);
    $map['tokens']=onlineSceneTokensForLights($connection,$records,$pending,$sceneId);
    if (($source !== [] && !onlineTokenOnActiveLayer($source, $map)) || ($target !== [] && !onlineTokenOnActiveLayer($target, $map))) {
        rejectOnlineCommand($connection, 409, 'L’attaquant et la cible doivent être sur le niveau actif.', 'stale_token_layer');
    }
    if(applicationIsNonCombatant($source))rejectOnlineCommand($connection,403,'Cet élément fixe ne peut pas agir.','fixed_structure_cannot_act');
    $sourceController = $source === [] ? '' : onlineTokenControllerIdFromRecords($connection, $records, $source);
    $targetController = $target === [] ? '' : onlineTokenControllerIdFromRecords($connection, $records, $target);
    $selfAreaRequest = ($target['id'] ?? null) === ($source['id'] ?? null) && in_array(($arguments['attackKind'] ?? ''),['ability','weapon'],true);
    if ($isGm) {
        if ($source === [] || ($source['hidden'] ?? false) === true) {
            rejectOnlineCommand($connection, 403, 'Le token attaquant doit être visible sur le niveau actif.', 'attack_source_not_visible');
        }
        if ($target === [] || ($target['hidden'] ?? false) === true || (($target['id'] ?? '') === ($source['id'] ?? '') && !$selfAreaRequest)) {
            rejectOnlineCommand($connection, 403, 'La cible doit être un autre token visible sur le niveau actif.', 'attack_target_not_visible');
        }
    } else {
        if ($source === [] || $sourceController !== $accountId || ($source['hidden'] ?? false) === true) {
            rejectOnlineCommand($connection, 403, 'Ce token attaquant ne vous appartient pas.', 'attack_source_forbidden');
        }
        if ($target === [] || ($target['hidden'] ?? false) === true || ($targetController === $accountId && ($arguments['attackKind']??'weapon')==='ability' && !$selfAreaRequest && ($arguments['targetingMode']??'')!=='single')) {
            rejectOnlineCommand($connection, 403, 'Cette cible ne peut pas être attaquée.', 'attack_target_forbidden');
        }
    }
    $targetCharacterId = trim((string) ($target['characterId'] ?? ''));
    if ($targetCharacterId !== '' && isset($records['character:' . $targetCharacterId])) {
        $targetCharacter = applicationDomainPayload($records, 'character:' . $targetCharacterId);
        if ($targetCharacter !== []) $target = synchronizeOnlineCharacterToken($target, $targetCharacter);
    }
    if (!is_numeric($target['maxHp'] ?? null) || onlineResourceNumber($target['maxHp']) <= 0) {
        rejectOnlineCommand($connection, 409, 'Cette cible ne possède pas de points de vie.', 'target_health_missing');
    }
    if (onlineTokenIsDead($target, $targetController !== '')) {
        rejectOnlineCommand($connection, 409, 'Cette cible est déjà morte.', 'target_already_defeated');
    }
    $map = [...applicationDomainPayload($records, $mapKey),'tokens'=>onlineSceneTokensForLights($connection,$records,$pending,$sceneId)];
    if (!$isGm && !onlineAttackTargetVisible($connection, $records, $map, $target, $accountId, $sceneId)) {
        rejectOnlineCommand($connection, 403, 'Cette cible n’est pas visible par votre joueur.', 'attack_target_hidden');
    }
    $characterId = trim((string) ($source['characterId'] ?? ''));
    if ($characterId !== '' && isset($records['character:' . $characterId])) {
        $character = applicationDomainPayload($records, 'character:' . $characterId);
        if ($character !== []) $source = synchronizeOnlineCharacterToken($source, $character);
    }
    if (!is_numeric($source['hp'] ?? null) || (float) $source['hp'] <= 0 || onlineTokenIsDead($source, $sourceController !== '')) {
        rejectOnlineCommand($connection, 409, 'Un attaquant KO ou mort ne peut pas attaquer.', 'attack_source_defeated');
    }
    $sourceCharacter = $characterId !== '' ? applicationDomainPayload($records, 'character:' . $characterId) : [];
    $equipmentState=onlineEquipmentState($connection,$records,$pending,$sceneId,$map);
    $hadWeaponDefinitions=!empty($source['weaponAttacks']);
    $source['abilityWeaponAttacks']=abilityEquipmentAttackRows($source['weaponAttacks']??[],$source,$equipmentState);
    $source['weaponAttacks']=equipmentAttackRows($source['weaponAttacks']??[],$source,$equipmentState);
    if($hadWeaponDefinitions)$source['damageDice']=$source['weaponAttacks'][0]['formula']??'';
    $complexExecutionId = trim((string) ($arguments['complexExecutionId'] ?? ''));
    $damagePercent = 100;
    $chainGate = null; $chainDefinition = null; $chargeStrike = null; $authorizedRangeCells=0; $strikeSourcePosition=null;
    if ($complexExecutionId !== '') {
        if (preg_match('/^execution-[A-Za-z0-9_-]{12,120}$/D', $complexExecutionId) !== 1
            || !in_array($arguments['attackKind'] ?? 'weapon', ['weapon', 'ability'], true)) {
            rejectOnlineCommand($connection, 400, 'La série accepte seulement une attaque de base.', 'complex_attack_kind_invalid');
        }
        $executionIndex = findEntryIndex($activity['abilityExecutions'] ?? [], $complexExecutionId);
        $execution = $executionIndex >= 0 ? ($activity['abilityExecutions'][$executionIndex] ?? null) : null;
        $step = is_array($execution) ? ($execution['workflow']['steps'][$execution['currentStepIndex'] ?? -1] ?? null) : null;
        $stepId = is_array($step) ? (string) ($step['id'] ?? '') : '';
        $stepState = is_array($execution) ? ($execution['stepStates'][$stepId] ?? null) : null;
        $authorizedRangeCells=is_array($execution)&&is_array($step)?applicationComplexAbilityTargetRangeCells($execution,$step,$source,(string)($arguments['attackId']??'')):0;
        if (!is_array($execution) || ($execution['status'] ?? '') !== 'active' || !in_array($step['type'] ?? '', ['attack-chain', 'allocated-attacks'], true)
            || ($execution['sceneId'] ?? '') !== $sceneId || ($execution['sourceTokenId'] ?? '') !== ($source['id'] ?? '')
            || ($execution['layerId'] ?? '') !== onlineTokenLayerId($source, $map)
            || (!$isGm && ($execution['controllerAccountId'] ?? '') !== $accountId)
            || !is_array($stepState) || ($stepState['awaitingAttack'] ?? false) !== true
            || (string) ($stepState['attackRequestId'] ?? '') !== '') {
            rejectOnlineCommand($connection, 409, 'Cette série n’attend plus d’attaque de base.', 'complex_attack_not_ready');
        }
        $source['weaponAttacks']=$source['abilityWeaponAttacks'];
        $authorizedRangeCells=applicationComplexAbilityTargetRangeCells($execution,$step,$source,(string)($arguments['attackId']??''));
        if ($step['type'] === 'allocated-attacks') {
            // Check the current resolved loadout before reserving a
            // strike, including executions saved by an older client.
            if (($step['damageMode'] ?? 'weapon') === 'weapon') {
                $currentWeaponIndex = findEntryIndex($source['weaponAttacks'], (string) ($arguments['attackId'] ?? ''));
                if ($currentWeaponIndex >= 0 && !applicationAllocatedAttackAllowsWeapon($step, $source['weaponAttacks'][$currentWeaponIndex])) {
                    rejectOnlineCommand($connection, 409, 'Cette série exige une seule arme par frappe : choisissez une arme individuelle.', 'complex_ability_single_weapon_required');
                }
            }
            if(!applicationComplexAbilityTargetEligible($execution,$step,$source,$target,$map,(string)($arguments['attackId']??'')))rejectOnlineCommand($connection,409,'La cible ne respecte plus le trajet ou la portée de cette compétence.','complex_ability_target_out_of_range');
            $strikeSourcePosition=applicationComplexAbilityStrikeOrigin($execution,$step,$target,$map);
            $targetId = (string) ($target['id'] ?? '');
            $assigned = null;
            foreach (is_array($stepState['targets'] ?? null) ? $stepState['targets'] : [] as $entry) {
                if (($entry['tokenId'] ?? '') === $targetId) { $assigned = $entry; break; }
            }
            $allowedDefense=applicationComplexAbilityDefenseAllowed($execution,$targetId,$step['id']);
            if($allowedDefense===null)rejectOnlineCommand($connection,409,'Le MJ doit choisir les défenses de cette série avant les frappes.','complex_ability_defenses_pending');
            $authorizedOpposed=$allowedDefense&&(($step['awarenessMode']??'none')==='none'||($assigned['aware']??false)===true);
            if (($stepState['awaitingAwareness'] ?? false) === true || ($stepState['pendingTargetTokenId'] ?? '') !== $targetId
                || !is_array($assigned) || count($assigned['attacks'] ?? []) >= ($assigned['attackCount'] ?? 0)
                || ($authorizedOpposed === true && ($assigned['aware'] ?? false) !== true)) {
                rejectOnlineCommand($connection, 409, 'Résolvez la vigilance et respectez les frappes attribuées avant d’attaquer.', 'complex_attack_not_ready');
            }
            $damagePercent = (int) $step['damagePercent'];
            $attackPlan = $execution['stepStates'][$step['sourceStepId']]['attackPlan'] ?? [];
            $done = array_sum(array_map(static fn(array $entry): int => count($entry['attacks'] ?? []), $stepState['targets'] ?? []));
            $planned = $attackPlan[$done] ?? null;
            $movement=null;foreach($execution['workflow']['steps'] as $s)if($s['id']===$step['sourceStepId']&&$s['type']==='movement'&&!empty($s['charge']))$movement=$s;
            if($movement){
                $movementState=$execution['stepStates'][$movement['id']];$chargeTokens=onlineComplexAbilitySceneTokens($connection,$records,$sceneId,$map,$accountId,$isGm);
                try{applicationValidateChargePath($movementState,applicationChargeFinalTarget($execution,$movement,$chargeTokens),$map,true,$source);}
                catch(ApplicationComplexAbilityException $error){rejectOnlineCommand($connection,$error->httpStatus,$error->getMessage(),$error->errorCode);}
                $end=!empty($movement['charge']['staged'])?($planned['movePoint']??[]):$movementState['points'][count($movementState['points'])-1];
                if(empty($planned['charge'])||abs($end['x']-$source['x'])>1e-5||abs($end['y']-$source['y'])>1e-5||($source['weaponAttacks'][0]['id']??'')!==($planned['attackId']??'')||normalizeOnlineD100Modifier($arguments['hitModifier']??0)||normalizeOnlineD100Modifier($arguments['damageModifier']??0)||isset($arguments['weaponSkill']))rejectOnlineCommand($connection,409,'Respectez la position, l’arme principale et les malus de la charge.','complex_attack_plan_mismatch');
                $chargeStrike=$planned;
            }
            if (is_array($planned) && (($planned['tokenId'] ?? '') !== ($target['id'] ?? '')
                || ($planned['attackId'] ?? '') !== ($arguments['attackId'] ?? '') || ($planned['statId'] ?? '') !== ($arguments['statId'] ?? '')
                || $authorizedOpposed !== (($planned['opposed'] ?? false) && ($step['awarenessMode'] === 'none' || ($assigned['aware'] ?? false)))))
                rejectOnlineCommand($connection, 409, 'Respectez la frappe prévue pour cette cible.', 'complex_attack_plan_mismatch');
            if ($step['damageMode'] === 'configured') {
                $chainDefinition = $step; $chainGate = $step['hitMode'] === 'automatic' ? ['success' => true, 'statId' => $step['statId'], 'total' => null, 'threshold' => null, 'formula' => '', 'breakdown' => 'Frappe automatique', 'outcomeDetails' => ['automatic' => true, 'success' => true, 'code' => 'success', 'label' => 'SANS JET']] : ($execution['castGate'] ?? null);
                if (!is_array($planned) || !is_array($chainGate) || ($chainGate['statId'] ?? '') !== $step['statId']
                    || ($arguments['attackKind'] ?? '') !== 'ability' || ($arguments['attackId'] ?? '') !== $execution['abilityId']
                    || normalizeOnlineD100Modifier($arguments['hitModifier'] ?? 0) !== 0 || normalizeOnlineD100Modifier($arguments['damageModifier'] ?? 0) !== 0
                    || isset($arguments['weaponSkill'])) rejectOnlineCommand($connection, 409, 'Respectez les dégâts et le lancement de cette série.', 'complex_attack_plan_mismatch');
            }
        }
        if ($step['type'] === 'attack-chain' && ($step['targetMode'] ?? 'each') === 'once') {
            $plan = is_array($stepState['plan'] ?? null) ? $stepState['plan'] : [];
            if (($target['id'] ?? '') !== ($plan['targetTokenId'] ?? '')
                || ($arguments['attackId'] ?? '') !== ($plan['attackId'] ?? '')
                || ($arguments['statId'] ?? '') !== ($plan['statId'] ?? '')
                || ($arguments['opposed'] ?? false) !== applicationChainAttackOpposed($step,$stepState))
                rejectOnlineCommand($connection, 409, 'Respectez la cible, l’arme et l’opposition prévues pour cette série.', 'complex_attack_plan_mismatch');
        }
        if ($step['type'] === 'attack-chain' && ($step['resolutionMode'] ?? '') === 'batch') {
            $chainDefinition = $step;
            $chainGate = applicationChainAttackGate($step, $stepState);
            if (($stepState['batchReady'] ?? false) !== true || !is_array($chainGate)
                || ($arguments['attackKind'] ?? 'weapon') !== ($stepState['plan']['attackKind'] ?? 'weapon')
                || normalizeOnlineD100Modifier($arguments['hitModifier'] ?? 0) !== 0
                || normalizeOnlineD100Modifier($arguments['damageModifier'] ?? 0) !== 0 || isset($arguments['weaponSkill']))
                rejectOnlineCommand($connection, 409, 'Respectez les jets et dégâts autoritatifs de la série.', 'complex_attack_plan_mismatch');
        } elseif ($chainDefinition === null && ($arguments['attackKind'] ?? 'weapon') !== 'weapon')
            rejectOnlineCommand($connection, 400, 'Cette série attend une attaque de base.', 'complex_attack_kind_invalid');
        $activity['abilityExecutions'][$executionIndex]['stepStates'][$stepId]['attackRequestId'] = $requestId;
        // Damage, guard and casting may each queue a newer activity snapshot.
        // Persist the reservation first so they cannot restore the old step.
        queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
    }
    $diceAppearance = onlineDiceAppearance($source, $sourceController !== '', $sourceCharacter);
    $deviceRecords=applicationDomainRecords($connection,['devices']);try{dsAssertActions(['devices'=>applicationDomainPayload($deviceRecords,'devices',dsEmpty())],$source,$sceneId);}catch(RuntimeException $error){rejectOnlineCommand($connection,$error->getCode()?:409,$error->getMessage(),'timed_action_block');}
    $attackLayerId = onlineTokenLayerId($source, $map);
    $newAttackId = 'attack-' . randomToken(12);
    $attackKind = in_array($arguments['attackKind'] ?? '', ['ability', 'custom'], true) ? $arguments['attackKind'] : 'weapon';
    $custom = null; $damageComponents = [];
    if ($attackKind === 'custom') {
        try { $custom = applicationCustomAttack($arguments['customAttack'] ?? null); }
        catch (InvalidArgumentException $error) { rejectOnlineCommand($connection, 400, $error->getMessage(), 'invalid_custom_attack'); }
    }
    $attackId = trim((string) ($arguments['attackId'] ?? ''));
    $attackName = 'Attaque de base';
    $damageFormula = '';
    $damageType = 'physical';
    $weaponConditions = [];
    if ($custom !== null) {
        $attackName = $custom['name']; $attackId = 'custom'; $damageComponents = $custom['damageComponents'];
        $damageFormula = applicationCombinedDamageFormula($damageComponents); $damageType = $damageComponents[0]['type'];
    } elseif (($chainDefinition['damageMode'] ?? '') === 'configured') {
        $attackName = (string) $execution['abilityName'];
        $damageComponents = applicationDamageComponents($chainDefinition['damageComponents']);
        $damageFormula = applicationCombinedDamageFormula($damageComponents); $damageType = $damageComponents[0]['type'];
    } elseif ($attackKind === 'ability') {
        $abilities = normalizeOnlineAbilities($mechanicsAbility !== null ? [$mechanicsAbility] : ($source['abilities'] ?? []));
        $abilityIndex = findEntryIndex($abilities, $attackId);
        if ($abilityIndex < 0) rejectOnlineCommand($connection, 404, 'Cette capacité n’existe plus sur le token.', 'attack_ability_missing');
        if (($abilities[$abilityIndex]['effect'] ?? 'damage') !== 'damage') rejectOnlineCommand($connection, 400, 'Utilisez cette capacité comme soin ou métamorphose.', 'ability_effect_required');
        $damageComponents = applicationDamageComponents($abilities[$abilityIndex]['damageComponents'] ?? []);
        $attackName = (string) $abilities[$abilityIndex]['name'];
        $damageFormula = (string) $abilities[$abilityIndex]['formula'];
        $damageType = normalizeOnlineDamageType($abilities[$abilityIndex]['damageType'] ?? null);
        try{$variant=applicationSelectedDamageVariant($abilities[$abilityIndex],$arguments['variantId']??null);}catch(RuntimeException $error){rejectOnlineCommand($connection,$error->getCode()?:400,$error->getMessage(),'ability_variant_invalid');}
        if($variant!==null){$damageType=$variant['damageType'];$damageComponents=[['type'=>$damageType,'formula'=>$damageComponents!==[]?applicationCombinedDamageFormula($damageComponents):$damageFormula]];}
    } else {
        $storedWeaponAttacks = is_array($source['weaponAttacks'] ?? null) ? $source['weaponAttacks'] : [];
        $weaponFormulas = array_values(array_filter(array_map(
            static fn (mixed $entry): string => is_array($entry) ? strtolower(str_replace(' ', '', (string) ($entry['formula'] ?? ''))) : '',
            $storedWeaponAttacks
        )));
        $manualFormula = strtolower(str_replace(' ', '', (string) ($source['damageDice'] ?? '')));
        if ($weaponFormulas === [] && validOnlineRollFormula($manualFormula)) $weaponFormulas = [$manualFormula];
        $seed = $storedWeaponAttacks;
        if ($seed === [] && $weaponFormulas !== []) {
            $seed = [['id' => 'weapon-1', 'formula' => $weaponFormulas[0], 'damageType' => normalizeOnlineDamageType($source['damageType'] ?? null)]];
        }
        $weapons = $complexExecutionId!==''?abilityEquipmentAttackRows(normalizeOnlineWeaponAttacks($seed,$weaponFormulas),$source,$equipmentState):equipmentAttackRows(normalizeOnlineWeaponAttacks($seed, $weaponFormulas),$source,$equipmentState);
        $weaponIndex = findEntryIndex($weapons, $attackId);
        if ($weaponIndex < 0) rejectOnlineCommand($connection, 404, 'Cette attaque de base n’existe plus sur le token.', 'attack_weapon_missing');
        if ($complexExecutionId !== '' && ($step['type'] ?? '') === 'allocated-attacks'
            && !applicationAllocatedAttackAllowsWeapon($step, $weapons[$weaponIndex])) {
            rejectOnlineCommand($connection, 409, 'Cette série exige une seule arme par frappe : choisissez une arme individuelle.', 'complex_ability_single_weapon_required');
        }
        $attackName = $weapons[$weaponIndex]['name'] ?? (count($weapons) > 1 ? 'Attaque de base ' . ($weaponIndex + 1) : 'Attaque de base');
        $damageFormula = (string) $weapons[$weaponIndex]['formula'];
        $damageType = normalizeOnlineDamageType($weapons[$weaponIndex]['damageType'] ?? null);
        $weaponConditions = $weapons[$weaponIndex]['onHitConditions'] ?? [];
        $enhanced = applicationEnhancedBasicAttack($weapons[$weaponIndex], $source, onlineAttackEnhancementState($connection,$records,$pending,$sceneId,$activity), $sceneId);
        $damageFormula = $enhanced['formula']; $damageType = $enhanced['damageType'];
        $damageComponents = $enhanced['damageComponents'] ?? [];
    }
    if ($complexExecutionId !== '') $weaponConditions = normalizeOnlineConditions([...$weaponConditions, ...($execution['onHitConditions'] ?? [])]);
    $mechanicsDefinition = $chainDefinition === null ? ($attackKind==='ability' ? $abilities[$abilityIndex] : ($attackKind==='weapon' ? $weapons[$weaponIndex] : [])) : [];
    $selectedWeapon = [];
    if (($mechanicsDefinition['useWeaponDamage']??false)===true) {
        $weaponList=$source['abilityWeaponAttacks'];
        $enhancementState=onlineAttackEnhancementState($connection,$records,$pending,$sceneId,$activity);$weaponList=array_map(static fn($weapon)=>applicationEnhancedBasicAttack($weapon,$source,$enhancementState,$sceneId,false),$weaponList);
        try{$weaponResult=applicationSimpleWeaponDamage($mechanicsDefinition,$weaponList,(string)($arguments['weaponAttackId']??''));}
        catch(RuntimeException $error){rejectOnlineCommand($connection,400,$error->getMessage(),'ability_weapon_required');}
        $selectedWeapon=$weaponResult['selectedWeapon'];$damageComponents=$weaponResult['damageComponents'];$damageFormula=$weaponResult['damageFormula'];$damageType=$weaponResult['damageType'];
    }
    if($attackKind!=='weapon'){$enhanced=applicationEnhancedAttackDamage(['formula'=>$damageFormula,'damageType'=>$damageType,...($damageComponents!==[]?['damageComponents'=>$damageComponents]:[])],$source,onlineAttackEnhancementState($connection,$records,$pending,$sceneId,$activity),$sceneId,!empty($mechanicsDefinition['useWeaponDamage']),true,false);$damageFormula=$enhanced['formula'];$damageType=$enhanced['damageType'];$damageComponents=$enhanced['damageComponents']??[];}
    if(array_key_exists('targetTokenIds',$arguments)&&$attackKind!=='weapon')rejectOnlineCommand($connection,400,'Les cibles multiples de cette arme ne s’appliquent pas à cette compétence.','weapon_targets_invalid');
    if(array_key_exists('targetTokenIds',$arguments)&&$arguments['targetTokenIds']===null)rejectOnlineCommand($connection,400,'Liste des cibles invalide.','weapon_targets_invalid');
    try{$requestedWeaponIds=$attackKind==='weapon'&&!validApplicationDamageTargeting($mechanicsDefinition['damageTargeting']??null)?applicationWeaponTargetIds($mechanicsDefinition,(string)$target['id'],array_key_exists('targetTokenIds',$arguments)?$arguments['targetTokenIds']:null,$complexExecutionId!==''):[];}
    catch(RuntimeException $error){rejectOnlineCommand($connection,400,$error->getMessage(),'weapon_targets_invalid');}
    $weaponTargets=[];
    foreach($requestedWeaponIds as $weaponTargetId){
        $key=onlineTokenDomainKey($sceneId,$weaponTargetId);$records=array_replace($records,applicationDomainRecords($connection,[$key]));$candidate=applicationDomainPayload($records,$key);
        $controller=onlineTokenControllerIdFromRecords($connection,$records,$candidate);
        if($candidate===[]||$weaponTargetId===$source['id']||!empty($candidate['hidden'])||!onlineTokenOnActiveLayer($candidate,$map)||(!$isGm&&(!onlineAttackTargetVisible($connection,$records,$map,$candidate,$accountId,$sceneId))))rejectOnlineCommand($connection,403,'Chaque cible doit être distincte, visible et sur le niveau actif.','weapon_target_forbidden');
        if(onlineResourceNumber($candidate['maxHp']??0)<=0||onlineTokenIsDead($candidate,$controller!==''))rejectOnlineCommand($connection,409,'Une des cibles ne peut plus être attaquée.','weapon_target_unavailable');
        $rangeTarget=$mechanicsStrikePosition?[...$candidate,...$mechanicsStrikePosition]:$candidate;
        $rangeSource=$strikeSourcePosition?[...$source,...$strikeSourcePosition]:$source;
        if(!($authorizedRangeCells?applicationAbilityTargetInRange(['rangeCells'=>$authorizedRangeCells],$rangeSource,$rangeTarget,$map):applicationWeaponInRange($mechanicsDefinition,$rangeSource,$rangeTarget,$map)))rejectOnlineCommand($connection,409,'Une des cibles est hors de portée de cette arme.','weapon_target_out_of_range');
        $weaponTargets[]=$candidate;
    }
    if(count($weaponTargets)>1&&(count($activity['pendingAttacks']??[])+count($weaponTargets)>XAR_PENDING_ATTACK_MAXIMUM||count($receipts)+count($weaponTargets)>XAR_ATTACK_RECEIPT_MAXIMUM))rejectOnlineCommand($connection,409,'La file des attaques est pleine.','pending_attack_capacity_reached');
    $areaTargetCandidates = [];
    $damageTargeting=$mechanicsDefinition['damageTargeting']??null;
    if(($damageTargeting['shape']??'')==='cone')$map['tokens']=onlineSceneTokensForLights($connection,$records,$pending,$sceneId);
    if(validApplicationDamageTargeting($damageTargeting)&&($complexExecutionId!==''||array_key_exists('targetTokenIds',$arguments)))rejectOnlineCommand($connection,400,'La zone détermine ses propres cibles et ne peut pas remplacer une frappe de série.','attack_area_targets_invalid');
    $singleAreaTarget = ($arguments['targetingMode']??'')==='single';
    if($singleAreaTarget&&(!validApplicationDamageTargeting($damageTargeting)||empty($damageTargeting['singleTargetHalfMana'])))rejectOnlineCommand($connection,400,'Cette compétence ne permet pas ce mode cible unique.','attack_single_mode_invalid');
    $aimedPoint=array_key_exists('targetPoint',$arguments)?applicationAreaTargetPoint($arguments['targetPoint'],$attackLayerId):null;
    if(($damageTargeting['origin']??'')==='point'&&!$singleAreaTarget&&!$aimedPoint)rejectOnlineCommand($connection,400,'Visez un point de la carte pour cette zone.','attack_area_point_required');
    if(array_key_exists('targetPoint',$arguments)&&(!$aimedPoint||!validApplicationDamageTargeting($damageTargeting)||($damageTargeting['origin']??'')!=='point'||$singleAreaTarget))rejectOnlineCommand($connection,400,'Cette zone ne peut pas être visée ici.','attack_area_point_invalid');
    if($aimedPoint&&!$isGm&&!onlineAttackTargetVisible($connection,$records,$map,$aimedPoint,$accountId,$sceneId))rejectOnlineCommand($connection,403,'Le centre de cette zone doit être visible.','attack_area_point_hidden');
    if (validApplicationDamageTargeting($damageTargeting)) {
        if (($damageTargeting['origin'] ?? 'target') === 'caster' && ($target['id'] ?? '') !== ($source['id'] ?? '')) {
            rejectOnlineCommand($connection, 403, 'Une zone autour du lanceur doit être lancée sur son propre pion.', 'attack_area_origin_invalid');
        }
        $areaTargetCandidates = ($damageTargeting['shape']??'')==='cone' ? applicationConeAreaTargets(onlineSceneTokensForLights($connection,$records,$pending,$sceneId),$source,$aimedPoint??[], $map,(int)$damageTargeting['lengthCells']) : onlineDamageAreaTargets(
            onlineSceneTokensForLights($connection, $records, $pending, $sceneId),
            $aimedPoint ?? (($damageTargeting['origin'] ?? 'target') === 'caster' ? $source : $target), $map,
            (float) $damageTargeting['radiusCells'], ($damageTargeting['origin'] ?? 'target') === 'caster');
        if($singleAreaTarget)$areaTargetCandidates=[];
        $areaTargetCandidates = array_values(array_filter($areaTargetCandidates,
            static function (array $candidate) use ($connection, &$records, $damageTargeting, $source, $sourceController, $map, $accountId, $sceneId, $isGm): bool {
                $controller = onlineTokenControllerIdFromRecords($connection, $records, $candidate);
                return ($isGm || onlineAttackTargetVisible($connection,$records,$map,$candidate,$accountId,$sceneId)) && applicationDamageAreaAffects([...$damageTargeting,'affectAllies'=>!empty($source['friendlyFire'])||($damageTargeting['affectAllies']??true)], (string) $source['id'], $sourceController,
                    (string) ($candidate['id'] ?? ''), $controller);
            }));
        if (count($areaTargetCandidates) > XAR_PENDING_ATTACK_MAXIMUM) {
            rejectOnlineCommand($connection, 409, 'La zone contient trop de pions pour une seule attaque.', 'attack_area_too_many_targets');
        }
    }
    if(count($weaponTargets)>1)$areaTargetCandidates=$weaponTargets;
    if ($selfAreaRequest && (!validApplicationDamageTargeting($damageTargeting) || ($damageTargeting['origin'] ?? 'target') !== 'caster' && !$aimedPoint && !$singleAreaTarget)) {
        rejectOnlineCommand($connection, 403, 'Cette attaque ne peut pas cibler le lanceur.', 'attack_self_forbidden');
    }
    if(($damageTargeting['shape']??'')==='cone'&&applicationConeAreaGeometry($source,$aimedPoint??[],$map,(int)$damageTargeting['lengthCells'])===null)rejectOnlineCommand($connection,400,'Choisissez une direction pour le cône.','ability_cone_direction_required');
    if(($damageTargeting['shape']??'')!=='cone'&&($attackKind==='ability'||validApplicationDamageTargeting($damageTargeting))&&$complexExecutionId===''&&!applicationAbilityTargetInRange($mechanicsDefinition,$source,$aimedPoint?:$target,$map,$selectedWeapon!==[]?[$selectedWeapon]:($source['weaponAttacks']??[]),(string)($arguments['weaponAttackId']??'')))rejectOnlineCommand($connection,409,'Cette cible ou ce point est hors de portée de la compétence.','ability_target_out_of_range');
    if($complexExecutionId!==''&&$authorizedRangeCells&&!applicationAbilityTargetInRange(['rangeCells'=>$authorizedRangeCells],[...$source,...($strikeSourcePosition??[])],$target,$map))rejectOnlineCommand($connection,409,'Cette cible est hors de portée de la compétence.','ability_target_out_of_range');
    $primaryAffects = !$aimedPoint && (!validApplicationDamageTargeting($damageTargeting)
        || applicationDamageAreaAffects([...$damageTargeting,'affectAllies'=>!empty($source['friendlyFire'])||($damageTargeting['affectAllies']??true)], (string) $source['id'], $sourceController, (string) $target['id'], $targetController));
    if ($attackKind === 'ability' && $chainDefinition === null) onlineAssertAbilityValidationAvailable($connection, $activity, $abilities[$abilityIndex], $source, true);
    $attackResultBonus=applicationAttackResultBonus($source,onlineAttackEnhancementState($connection,$records,$pending,$sceneId,$activity),$sceneId);
    $liveAreaTargets=array_values(array_filter($areaTargetCandidates?:[$target],static fn($token)=>(float)($token['maxHp']??0)>0&&!onlineTokenIsDead($token,onlineTokenControllerIdFromRecords($connection,$records,$token)!=='')));
    $isolatedFields=applicationSingleEnemyAttackFields($mechanicsDefinition,$liveAreaTargets,$source,$target,static fn($token)=>onlineTokenControllerIdFromRecords($connection,$records,$token));
    $selectedStatId = (string) (($arguments['statId'] ?? '') ?: ($attackKind === 'ability' ? ($abilities[$abilityIndex]['castingStatId'] ?? '') : ''));
    $abilityForAttack = $attackKind === 'ability' && $chainDefinition === null ? $abilities[$abilityIndex] : null;
    $noCastingRoll = $abilityForAttack !== null && array_key_exists('castingStatId', $abilityForAttack)
        && $abilityForAttack['castingStatId'] === '';
    if (!$noCastingRoll && $selectedStatId === 'weapon-skill') {
        $rawSkill = $arguments['weaponSkill'] ?? null;
        if (!is_numeric($rawSkill) || (float) $rawSkill != (int) $rawSkill
            || (int) $rawSkill < 0 || (int) $rawSkill > 100) {
            rejectOnlineCommand($connection, 400, 'La compétence d’arme doit être un entier de 0 à 100.', 'attack_weapon_skill_invalid');
        }
        $fatiguePenalty = $sourceCharacter !== []
            ? max(0, (int) floor(normalizeApplicationFatigue($source['fatigue'] ?? null)['current'] - 50)) : 0;
        $source['weaponSkillBefore'] = (int) $rawSkill;
        $source['stats'][] = ['id' => 'weapon-skill', 'label' => 'Compétence d’arme',
            'value' => max(1, (int) $rawSkill - $fatiguePenalty)];
    }
    if ($abilityForAttack !== null && !$noCastingRoll) {
        if(!empty($abilityForAttack['castingChoices'])&&!in_array($selectedStatId,$abilityForAttack['castingChoices'],true))rejectOnlineCommand($connection,400,'Choisissez une des caractéristiques prévues par cette compétence.','ability_stat_choice_required');
        if ($selectedStatId === '') rejectOnlineCommand($connection, 400, 'Choisissez une statistique d’attaque.', 'attack_stat_required');
        $abilityForAttack['castingStatId'] = $selectedStatId;
    }
    $approachContext=!empty($mechanicsDefinition['attackApproach'])?['sceneId'=>$sceneId,'sourceTokenId'=>$source['id'],'targetTokenId'=>$target['id'],'attackApproach'=>$mechanicsDefinition['attackApproach'],'mountedMovement'=>($mechanicsDefinition['mountedMovement']??false)===true,'approachPoint'=>$arguments['approachPoint']??null,'targetRangeCells'=>applicationAbilityRangeCells($mechanicsDefinition,$selectedWeapon!==[]?[$selectedWeapon]:($source['weaponAttacks']??[]),(string)($arguments['weaponAttackId']??'')),...(isset($mechanicsDefinition['minimumReach'])?['minimumReach'=>$mechanicsDefinition['minimumReach']]:[])]:null;
    if($approachContext!==null)onlineAttackApproachPlan($connection,$records,$pending,$approachContext,$accountId,$isGm);
    $castingPlan = $abilityForAttack !== null ? onlinePrepareAbilityCasting($connection, $abilityForAttack, $source, $sceneId, applicationDomainPayload($records, $initiativeKey), $activity, $selectedStatId, onlineDeviceState($connection,$records,$pending), $singleAreaTarget?0.5:1) : null;
    if($castingPlan!==null&&empty(applicationSpellVisualBindings()[$abilityForAttack['id']??'']['onResolvedDamage']))$castingPlan['spellVisual']=applicationTrustedSpellVisual($abilityForAttack,['sceneId'=>$sceneId,'source'=>$source,'targetPoint'=>$aimedPoint??['x'=>$target['x'],'y'=>$target['y']],'variantId'=>$arguments['variantId']??'','map'=>$map]);
    if($defenseApproval!==null&&(!onlineDefenseIdsEqual(array_column($liveAreaTargets,'id'),$defenseApproval['gate']['targetTokenIds'])||canonicalApplicationDomainValue($mechanicsDefinition)!==canonicalApplicationDomainValue($defenseApproval['gate']['definition'])))rejectOnlineCommand($connection,409,'La zone ou l’attaque a changé depuis la préparation.','defense_selection_changed');
    $quotaWeapon=$selectedWeapon!==[]?$selectedWeapon:($attackKind==='weapon'?$mechanicsDefinition:null);
    if($quotaWeapon!==null)try{assertWeaponAttackLimit(onlineWeaponTurnState($records,$pending,$sceneId,$map),$source,$quotaWeapon,['sceneId'=>$sceneId,'requestId'=>$requestId]);}catch(RuntimeException $error){rejectOnlineCommand($connection,$error->getCode()?:409,$error->getMessage(),'weapon_turn_limit');}
    if(!empty($abilityForAttack['castGate'])){
        try{$result=onlineBeginAreaCastGate($connection,$records,$pending,$identity,$arguments,['ability'=>$abilityForAttack,'source'=>$source,'sceneId'=>$sceneId,'layerId'=>$attackLayerId,'map'=>$map,'activity'=>$activity,'receipts'=>$receipts,'castingPlan'=>$castingPlan,'targetPoint'=>$aimedPoint,'targets'=>$liveAreaTargets,'damageComponents'=>$damageComponents,'damageFormula'=>$damageFormula,'damageType'=>$damageType,'isGm'=>$isGm,'visible'=>$sceneId===onlineActiveSceneId($table)&&onlineGmTokenVisibleToPlayers($connection,$records,$table,$source,$sceneId)]);}catch(RuntimeException $error){rejectOnlineCommand($connection,in_array($error->getCode(),[400,403,404,409,429],true)?$error->getCode():400,$error->getMessage(),'area_gate_refused');}
    }elseif(!$prepareDefenseOnly&&$defenseApproval===null&&$complexExecutionId===''&&($mechanicsDefinition['noDefensePossible']??false)!==true&&(($damageTargeting['mode']??'')==='area'||(int)($mechanicsDefinition['targetCount']??1)>1||count($weaponTargets)>1)){
        // The isolated records/pending copy prepares one hit; it never commits costs or effects.
        $previewRecords=$records;$previewPending=$pending;$preparedHit=onlinePerformTargetAttack($connection,$previewRecords,$previewPending,$table,$identity,$arguments,$isGm,[...$context,'prepareDefenseOnly'=>true]);
        try{$result=onlineQueueDeferredDefenseGate($connection,$records,$pending,$identity,$arguments,['definition'=>$mechanicsDefinition,'source'=>$source,'sceneId'=>$sceneId,'map'=>$map,'targets'=>$liveAreaTargets,'targetPoint'=>$aimedPoint,'isGm'=>$isGm,'requestSignature'=>onlineAttackRequestSignature($sceneId,$arguments,$preparedHit['hasStatCheck']),'preparedHit'=>$preparedHit]);}catch(RuntimeException $error){rejectOnlineCommand($connection,$error->getCode()?:400,$error->getMessage(),'defense_selection_refused');}
    }else{
    if(!$prepareDefenseOnly&&$defenseApproval!==null&&!empty($defenseApproval['gate']['preparedHit'])){$previewRecords=$records;$previewPending=$pending;$rechecked=onlinePerformTargetAttack($connection,$previewRecords,$previewPending,$table,$identity,$arguments,$isGm,[...$context,'prepareDefenseOnly'=>true]);if(canonicalApplicationDomainValue($rechecked['outcomes'])!==canonicalApplicationDomainValue($defenseApproval['gate']['preparedHit']['outcomes']))rejectOnlineCommand($connection,409,'Les conditions du jet ont changé depuis sa préparation.','defense_roll_changed');}

    try { $infusion=onlineDeviceInfusion($connection,$records,$pending,$identity,$mechanicsDefinition,$arguments,$source,(int)($mechanicsDefinition['manaCost']??0)); }
    catch(RuntimeException $error){rejectOnlineCommand($connection,$error->getCode()?:409,$error->getMessage(),'infusion_rejected');}
    if(($infusion['damage']??0)>0){
        if($damageComponents===[])$damageComponents=[['type'=>$damageType,'formula'=>$damageFormula]];
        $index=-1;foreach($damageComponents as $i=>$part)if($part['type']==='ignore')$index=$i;
        if($index>=0)$damageComponents[$index]['formula'].='+'.$infusion['damage'];else $damageComponents[]=['type'=>'ignore','formula'=>(string)$infusion['damage']];
        $damageFormula=applicationCombinedDamageFormula($damageComponents);
    }
    if($infusion!==null&&$sourceCharacter!==[]){$sourceCharacter=$pending['character:'.$characterId]['payload']??$sourceCharacter;$source=synchronizeOnlineCharacterToken($source,$sourceCharacter);}
    if($quotaWeapon!==null){$quotaState=onlineWeaponTurnState($records,$pending,$sceneId,$map);try{consumeWeaponAttackUse($quotaState,$source,$quotaWeapon,['sceneId'=>$sceneId,'requestId'=>$requestId,'ownerPlayerId'=>$sourceController]);}catch(RuntimeException $error){rejectOnlineCommand($connection,$error->getCode()?:409,$error->getMessage(),'weapon_turn_limit');}$activity=$pending['activity']['payload']??$activity;$activity['actionTimers']=$quotaState['actionTimers'];queueOnlineDomainUpsert($pending,$records,'activity',$activity);}
    $hasCastingCheck = ($chainGate['outcomeDetails']['automatic'] ?? false) !== true && ($castingPlan === null || $castingPlan['statId'] !== '');
    $attackRequestSignature = onlineAttackRequestSignature($sceneId, $arguments, $hasCastingCheck);
    if ($castingPlan !== null) {
        $abilityRequestSignature = applicationAbilityRequestSignature('token.attack', $sceneId, $arguments, $hasCastingCheck);
    }
    $stats = onlineEnhancedStatRows($connection,$records,$pending,$source,$sceneId);
    if (($custom['customStat'] ?? false) === true) $stats = [['id' => 'custom', 'label' => $custom['statLabel'], 'value' => $custom['threshold']]];
    $statIndex = findEntryIndex($stats, $castingPlan !== null ? $castingPlan['statId'] : (($custom['customStat'] ?? false) === true ? 'custom' : (string) ($arguments['statId'] ?? '')));
    if ($hasCastingCheck && ($statIndex < 0 || !is_numeric($stats[$statIndex]['value'] ?? null))) {
        rejectOnlineCommand($connection, 404, 'Cette statistique d’attaque n’existe plus sur le token.', 'attack_stat_missing');
    }
    $threshold = $hasCastingCheck ? max(0, min(100, (int) $stats[$statIndex]['value'])) : null;
    $hitModifierValue = normalizeOnlineD100Modifier($arguments['hitModifier'] ?? 0);
    $hitModifierMode = ($arguments['hitModifierMode'] ?? '') === 'result' ? 'result' : 'threshold';
    $conditionAttackPenalty=applicationTimedAttackPenalty(onlineDeviceState($connection,$records,$pending),$source,(string)$target['id'],$sceneId)+($chargeStrike['thresholdPenalty']??0);
    $thresholdModifier = ($hitModifierMode === 'threshold' ? $hitModifierValue : 0) - ($castingPlan['difficultyPenalty'] ?? 0) - $conditionAttackPenalty;
    $resultModifier = ($hitModifierMode === 'result' ? $hitModifierValue : 0)-(applicationPredatoryResultBonus($source,(string)$target['id'],'attack')+$attackResultBonus);
    $rollMode = normalizeOnlineRollMode($arguments['rollMode'] ?? 'normal');
    $hitFormula = '1d100' . ($resultModifier !== 0 ? ($resultModifier > 0 ? '+' : '') . $resultModifier : '');
    onlineCloseFixedEscapeWindows($connection,$records,$pending,(string)$source['id'],$sceneId);
    $joker=applicationRequestsSpecialSuccess($arguments);
    if($joker&&(!$hasCastingCheck||$chainGate!==null))rejectOnlineCommand($connection,409,'Ce jet est déjà résolu ou cette attaque ne demande aucun jet.','joker_roll_already_resolved');
    if($joker)$hitFormula='1';
    $hitRolled = $chainGate !== null ? applicationComplexAbilityGateRoll($chainGate, $activity['rolls'] ?? [])
        : ($joker?($castingPlan!==null?applicationSpecialSuccessRoll():onlineConsumeSpecialSuccess($connection,$records,$pending,$source,$sceneId)):($hasCastingCheck ? ($defenseApproval['gate']['preparedHit']['rolled']??onlineRollFormulaWithMode($hitFormula, $rollMode, $threshold, $thresholdModifier, true)) : ['formula' => '0', 'total' => 0, 'breakdown' => 'Sans jet de lancement']));
    if(!$prepareDefenseOnly&&$defenseApproval!==null&&!empty($defenseApproval['gate']['preparedHit']['rolled']))$hitRolled=applicationMarkNaturalRoll($hitRolled);
    $hitOutcome = $chainGate !== null ? ($chainGate['outcomeDetails'] ?? classifyOnlineD100Outcome($chainGate['total'], $chainGate['threshold'], 0, 0, true, true,applicationRollCriticalFailureProtected($source,$pending['activity']['payload']??applicationDomainPayload($records,'activity'),$sceneId)))
        : ($joker?applicationSpecialSuccessOutcome($threshold):($hasCastingCheck ? classifyOnlineD100Outcome($hitRolled['rawD100'] ?? null, $threshold, $thresholdModifier, $resultModifier, true, true,applicationRollCriticalFailureProtected($source,$pending['activity']['payload']??applicationDomainPayload($records,'activity'),$sceneId)) : ['code' => 'success', 'label' => 'SANS JET', 'success' => true, 'effect' => false, 'automatic' => true]));
    if($chainGate!==null&&!($hitOutcome['automatic']??false)&&($conditionAttackPenalty||(applicationPredatoryResultBonus($source,(string)$target['id'],'attack')+$attackResultBonus)))$hitOutcome=[...$hitOutcome,...classifyOnlineD100Outcome($hitOutcome['raw'],$hitOutcome['baseThreshold']??$hitOutcome['threshold'],($hitOutcome['modifier']??0)-$conditionAttackPenalty,($hitOutcome['resultModifier']??0)-(applicationPredatoryResultBonus($source,(string)$target['id'],'attack')+$attackResultBonus),true,true,applicationRollCriticalFailureProtected($source,$pending['activity']['payload']??applicationDomainPayload($records,'activity'),$sceneId))];
    applyOnlineD100ResultToRoll($hitRolled, $hitOutcome);
    if ($hasCastingCheck && $hitOutcome !== null) {
        $fatigue = onlineStatFatigueDetails($source, (string) ($stats[$statIndex]['id'] ?? ''), $sourceCharacter);
        if ($fatigue !== null) $hitOutcome['fatigue'] = $fatigue;
    }
    if ($castingPlan === null && $chainGate === null) onlineRecordCharacterLuckD100($connection, $records, $pending, $identity, $characterId, $hitRolled);
    if ($hitOutcome !== null) $hitOutcome['resultCustomized'] = $hitModifierMode === 'result';
    $weaponOutcomes=[];
    foreach($weaponTargets as $recipient){$id=(string)$recipient['id'];$weaponOutcomes[$id]=$joker||$id===$target['id']?$hitOutcome:[...$hitOutcome,...classifyOnlineD100Outcome($hitOutcome['raw'],$threshold,($hitOutcome['modifier']??0)+$conditionAttackPenalty-applicationTimedAttackPenalty(onlineDeviceState($connection,$records,$pending),$source,$id,$sceneId),($hitOutcome['resultModifier']??0)+(applicationPredatoryResultBonus($source,(string)$target['id'],'attack')+$attackResultBonus)-(applicationPredatoryResultBonus($source,$id,'attack')+$attackResultBonus),true,true,applicationRollCriticalFailureProtected($source,$pending['activity']['payload']??applicationDomainPayload($records,'activity'),$sceneId))];}
    if($prepareDefenseOnly){$outcomes=[];foreach($liveAreaTargets as$recipient)$outcomes[$recipient['id']]=$weaponOutcomes[$recipient['id']]??$hitOutcome;return ['rolled'=>$hitRolled,'hasStatCheck'=>$hasCastingCheck,'hit'=>['raw'=>$hitRolled['rawD100']??null,'total'=>$hitRolled['total'],'formula'=>$hitRolled['formula']??$hitFormula,'statId'=>$hasCastingCheck?($stats[$statIndex]['id']??''):'','statLabel'=>$hasCastingCheck?($stats[$statIndex]['label']??'Statistique'):'Sans jet','outcome'=>$hitOutcome],'outcomes'=>$outcomes];}
    $infusionMissed=$weaponOutcomes!==[]?!array_filter($weaponOutcomes,static fn($o)=>!empty($o['success'])):!($hitOutcome['success']??false);
    if(!empty($infusion['payment'])&&$infusion['refundOnMiss']&&$infusionMissed)onlineDeviceRefund($connection,$records,$pending,$infusion['payment']);
    $baseDamagePercent=$damagePercent;
    $projectileGate=($hitOutcome['success']??false)?onlineDeviceProjectile($connection,$records,$pending,$identity,$source,$target,($mechanicsDefinition['projectile']??false)===true,(int)($castingPlan['fatigueCost']??0),$sceneId):['percent'=>100,'checks'=>[],'pending'=>false];
    $damagePercent=(int)round($damagePercent*$projectileGate['percent']/100);$activity=$pending['activity']['payload']??$activity;
    $statLabel = $hasCastingCheck ? substr(trim((string) ($stats[$statIndex]['label'] ?? 'Statistique')), 0, 120) : 'Sans jet';
    $hitRoll = ($hitOutcome['automatic'] ?? false) === true ? null : onlineRollEntry($identity, $hitRolled, $attackName . ' · ' . $statLabel, (string) ($source['name'] ?? 'Token'), $hitOutcome);
    if ($hitRoll !== null && !empty($chainGate['rollId'])) $hitRoll['id'] = $chainGate['rollId'];
    $attackVisibleToPlayers = $sceneId === onlineActiveSceneId($table)
        && onlineGmTokenVisibleToPlayers($connection, $records, $table, $source, $sceneId);
    if ($hitRoll !== null && $isGm && !$attackVisibleToPlayers) {
        $hitRoll['visibility'] = 'gm';
        $hitRoll['revealed'] = false;
    }
    $defensePossible = ($abilityForAttack['noDefensePossible'] ?? false) !== true
        && ($execution['noDefensePossible'] ?? false) !== true;
    $opposed = $defensePossible && $primaryAffects && ($defenseApproval!==null?in_array($target['id'],$defenseApproval['defenseTokenIds'],true):($authorizedOpposed??(($arguments['opposed']??false)===true))) && (float) ($target['hp'] ?? 0) > 0;
    $oppositionRequired = $opposed && $projectileGate['percent'] > 0
        && ($hitOutcome['success'] ?? false) === true
        && ($hitOutcome['breaksOpposition'] ?? false) !== true;
    if ($hitRoll !== null) {
    if($castingPlan===null)onlineAwardNaturalJoker($connection,$records,$pending,$source,$hitRoll,$hitOutcome,$sceneId);
    $hitRoll['diceAppearance'] = $diceAppearance;
    $hitRoll['mapEvent'] = [
        'sceneId' => $sceneId, 'layerId' => $attackLayerId, 'attackId' => $newAttackId,
        'anchorTokenId' => (string) ($source['id'] ?? ''), 'diceAppearance' => $diceAppearance,
        'kind' => 'roll',
        'value' => $hitOutcome['result'] ?? $hitRolled['rawD100'] ?? $hitRolled['total'],
        'label' => 'Jet ATK',
        'tokenId' => (string) ($source['id'] ?? ''),
        'sourceTokenId' => (string) ($source['id'] ?? ''),
        'targetTokenId' => (string) ($target['id'] ?? ''),
        'tone' => (string) ($hitOutcome['code'] ?? 'normal'),
    ];
    }
    $damageModifier = normalizeOnlineD100Modifier($arguments['damageModifier'] ?? 0);
    $damageFormulaWithModifier = $damageFormula . ($damageModifier !== 0 ? ($damageModifier > 0 ? '+' : '') . $damageModifier : '');
    if (strlen($damageFormulaWithModifier) > 100 || !validOnlineRollFormula($damageFormulaWithModifier)) {
        rejectOnlineCommand($connection, 400, 'Le modificateur rend la formule de dégâts invalide.', 'invalid_attack_damage');
    }
    if ($damageComponents !== [] && $damageModifier !== 0) $damageComponents[0]['formula'] .= ($damageModifier > 0 ? '+' : '') . $damageModifier;
    $damageSpecification = [...(isset($isolatedFields['damageRepetitions'])?['damageRepetitions'=>$isolatedFields['damageRepetitions']]:[]),'onHitConditions' => $chainDefinition !== null ? [] : ($attackKind === 'ability' ? normalizeOnlineConditions($abilities[$abilityIndex]['onHitConditions'] ?? []) : $weaponConditions), 'damageFormula' => $damageFormulaWithModifier, 'damageType' => $damageType, 'damageRollMode' => 'normal', 'damagePercent' => $damagePercent, ...($damageComponents !== [] ? ['damageComponents' => $damageComponents] : []), ...($chainDefinition === null && $attackKind === 'ability' && validApplicationDamageOverTime($abilities[$abilityIndex]['damageOverTime'] ?? null) ? ['damageOverTime' => $abilities[$abilityIndex]['damageOverTime']] : [])];
    if(!empty($isolatedFields['conditionRider'])||!empty($chargeStrike['conditionRider'])||!empty($mechanicsDefinition['conditionRider']))$damageSpecification['conditionRider']=$isolatedFields['conditionRider']??$chargeStrike['conditionRider']??$mechanicsDefinition['conditionRider'];
    if(!empty($mechanicsDefinition['knockback']))$damageSpecification['knockback']=$mechanicsDefinition['knockback'];
    if($projectileGate['checks']!==[])$damageSpecification['projectileGate']=$projectileGate;
    if($infusion!==null)$damageSpecification['infusion']=['mana'=>$infusion['mana'],'damage'=>$infusion['damage'],'refunded'=>$infusionMissed&&$infusion['refundOnMiss']];
    $damageRoll = null;
    $damageSummary = ['rawDamage' => 0, 'armorPercent' => 0, 'preventedDamage' => 0, 'finalDamage' => 0];
    if (($hitOutcome['success'] ?? false) === true && $projectileGate['percent'] > 0 && !$oppositionRequired && $primaryAffects) {
        $damageResult = onlineRollAttackDamage([...$damageSpecification,'sceneId'=>$sceneId], $target,null,onlineAttackEnhancementState($connection,$records,$pending,$sceneId));
        $damageRolled = $damageResult['rolled']; $damageSummary = $damageResult['damage'];
        $damageRoll = onlineRollEntry($identity, $damageRolled, $attackName . ' · Dégâts', (string) ($source['name'] ?? 'Token'));
        $damageRoll['visibility'] = 'gm';
        $damageRoll['revealed'] = false;
    }
    $attack = [
        'id' => $newAttackId,
        'requestId' => $requestId,
        'sceneId' => $sceneId,
        'layerId' => $attackLayerId,
        'sourceTokenId' => (string) ($source['id'] ?? ''),
        'sourceName' => substr((string) ($source['name'] ?? 'Attaquant'), 0, 120),
        'targetTokenId' => (string) ($target['id'] ?? ''),
        'targetName' => substr((string) ($target['name'] ?? 'Cible'), 0, 120),
        'accountId' => $accountId,
        'attackerRole' => $isGm ? 'gm' : 'player',
        'visibility' => $isGm && !$attackVisibleToPlayers ? 'gm' : 'public',
        'playerName' => substr((string) ($identity['display_name'] ?? 'Joueur'), 0, 120),
        'attackName' => substr($attackName, 0, 120),
        'attackKind' => $attackKind,
        ...($approachContext!==null?['attackApproach'=>$approachContext['attackApproach'],...(!empty($approachContext['mountedMovement'])?['mountedMovement'=>true]:[]),'approachPoint'=>['x'=>$arguments['approachPoint']['x'],'y'=>$arguments['approachPoint']['y']]]:[]),
        'targetRangeCells'=>$authorizedRangeCells?:($attackKind==='ability'?applicationAbilityRangeCells($mechanicsDefinition,$selectedWeapon!==[]?[$selectedWeapon]:($source['weaponAttacks']??[]),(string)($arguments['weaponAttackId']??'')):applicationWeaponRangeCells($mechanicsDefinition)),
        ...(isset($mechanicsDefinition['minimumReach'])?['minimumReach'=>$mechanicsDefinition['minimumReach']]:[]),
        ...($mechanicsStrikePosition?['strikeTargetPosition'=>$mechanicsStrikePosition]:[]),
        ...($strikeSourcePosition?['strikeSourcePosition'=>$strikeSourcePosition]:[]),
        ...(validApplicationDamageTargeting($damageTargeting) ? ['areaRadiusCells' => $damageTargeting['radiusCells']] : []),
        ...(!$primaryAffects ? ['areaAnchorOnly' => true] : []),
        ...($aimedPoint?['targetPoint'=>$aimedPoint]:[]),
        ...(!empty($mechanicsDefinition['terrainBarrier'])&&$aimedPoint?['terrainBarrier'=>$mechanicsDefinition['terrainBarrier']]:[]),
        ...($complexExecutionId !== '' ? ['complexExecutionId' => $complexExecutionId] : []),
        'attackId' => $attackId,
        'echoAttackContext' => ['equipmentItemIds'=>($selectedWeapon?:$mechanicsDefinition)['equipmentItemIds']??[],'naturalWeapon'=>($selectedWeapon?:$mechanicsDefinition)['naturalWeapon']??false,'torchLightId'=>($selectedWeapon?:$mechanicsDefinition)['torchLightId']??'', 'weaponId'=>$attackKind==='weapon'?$attackId:($selectedWeapon['id']??''),'weaponBased' => $attackKind === 'weapon' || ($mechanicsDefinition['useWeaponDamage'] ?? false) === true,
            'projectile' => ($mechanicsDefinition['projectile'] ?? false) === true || ($selectedWeapon['projectile'] ?? false) === true, 'reach'=>applicationWeaponReach($selectedWeapon?:$mechanicsDefinition)],
        'opposed' => $opposed,
        ...(!empty($chainDefinition['defenseStatId']) ? ['defenseStatId' => $chainDefinition['defenseStatId'], 'defenseRollMode' => $chainDefinition['defenseRollMode']] : []),
        'damageModifier' => $damageModifier,
        'defenderAccountId' => $targetController,
        'damageType' => $damageType,
        'hit' => [
            'rollId' => $hitRoll['id'] ?? null, 'diceAppearance' => $diceAppearance,
            'label' => $hitRoll['label'] ?? ($attackName . ' · Sans jet'), 'characterName' => $hitRoll['characterName'] ?? (string) ($source['name'] ?? 'Token'),
            'statId' => (string) ($stats[$statIndex]['id'] ?? ''), 'statLabel' => $statLabel,
            'raw' => $hitRolled['rawD100'] ?? null, 'total' => $hitRolled['total'],
            'formula' => $hitRolled['formula'], 'breakdown' => $hitRolled['breakdown'],
            'rollMode' => $hitRolled['rollMode'] ?? 'normal',
            'selectedIndex' => $hitRolled['selectedIndex'] ?? 0,
            'attempts' => $hitRolled['attempts'] ?? [],
            'outcome' => $hitOutcome,
        ],
        ...($hitRoll !== null ? ['hitRoll' => $hitRoll] : []),
        ...($opposed && ($hitOutcome['breaksOpposition'] ?? false) === true
            ? ['opposition' => ['skipped' => true, 'reason' => 'immediate-outcome', 'requiresGmValidation' => true]]
            : []),
        'damage' => $damageRoll === null ? [] : [
            ...$damageSummary,
            'formula' => $damageRoll['formula'],
            'breakdown' => $damageRoll['breakdown'],
            'rollId' => $damageRoll['id'],
            'total' => $damageRoll['total'],
            'rollMode' => $damageRoll['rollMode'],
            'selectedIndex' => $damageRoll['selectedIndex'],
            // Le reçu d’attaque conserve la tentative interne unique
            // même si le jet public normal n’expose pas un faux mode.
            'attempts' => $damageRolled['attempts'] ?? [],
        ],
        ...($damageRoll !== null ? ['damageRoll' => $damageRoll] : []),
        'finalDamage' => (int) $damageSummary['finalDamage'],
        ...$damageSpecification,
        'damageRollMode' => 'normal',
        'createdAt' => $now,
    ];
    $initiative = applicationDomainPayload($records, $initiativeKey);
    $combatActive = ($initiative['active'] ?? false) === true;
    if (($hitOutcome['requiresGmValidation'] ?? false) === true || $projectileGate['pending']) {
        $pendingAttacks = is_array($activity['pendingAttacks'] ?? null) ? $activity['pendingAttacks'] : [];
        if (count($pendingAttacks) >= XAR_PENDING_ATTACK_MAXIMUM) {
            rejectOnlineCommand($connection, 409, 'La file des résultats remarquables à valider est pleine. Le MJ doit traiter une demande.', 'pending_attack_capacity_reached');
        }
        $attack['status'] = 'pending';
        $attack['validationKind'] = 'outcome';
        $attack['provisionalStatus'] = ($hitOutcome['success'] ?? false) === true && $projectileGate['percent'] > 0
            ? ((int) $damageSummary['finalDamage'] > 0 ? 'applied' : 'blocked')
            : 'missed';
        $pendingAttacks[] = $attack;
        $activity['pendingAttacks'] = $pendingAttacks;
    } elseif (($hitOutcome['success'] ?? false) !== true || $projectileGate['percent'] === 0) {
        $attack['status'] = 'missed';
        $result['discordContent'] = onlineAttackDiscordContent($attack);
    } elseif ($oppositionRequired) {
        $pendingAttacks = is_array($activity['pendingAttacks'] ?? null) ? $activity['pendingAttacks'] : [];
        if (count($pendingAttacks) >= XAR_PENDING_ATTACK_MAXIMUM) {
            rejectOnlineCommand($connection, 409, 'La file des attaques en attente est pleine. Le MJ doit traiter une demande.', 'pending_attack_capacity_reached');
        }
        $attack['status'] = 'awaiting-opposition';
        $pendingAttacks[] = $attack;
        $activity['pendingAttacks'] = $pendingAttacks;
    } elseif ((int) $damageSummary['finalDamage'] <= 0) {
        $attack['status'] = 'blocked';
        $result['discordContent'] = onlineAttackDiscordContent($attack);
    } elseif ($combatActive) {
        $health = applyOnlineAttackDamage($connection, $records, $pending, $targetKey, $target, (int) $damageSummary['finalDamage'], true, [...$damageSummary, 'damageType'=>$attack['damageType']]);
        $attack = onlineRecordAttackGuard($attack, $health);
        $attack['status'] = $health['appliedDamage'] > 0 ? 'applied' : 'blocked';
        $result['discordContent'] = onlineAttackDiscordContent($attack);
    } else {
        $attack['status'] = 'pending';
        $pendingAttacks = is_array($activity['pendingAttacks'] ?? null) ? $activity['pendingAttacks'] : [];
        if (count($pendingAttacks) >= XAR_PENDING_ATTACK_MAXIMUM) {
            rejectOnlineCommand(
                $connection,
                409,
                'La file des dégâts hors combat est pleine. Le MJ doit traiter une demande avant une nouvelle attaque.',
                'pending_attack_capacity_reached'
            );
        }
        $pendingAttacks[] = $attack;
        $activity['pendingAttacks'] = $pendingAttacks;
    }
    // A consumed one-hit guard is server-owned activity state; the
    // casting commit below must not restore a stale copy of it.
    $activity['nextAttackGuards'] = ($pending['activity']['payload']['nextAttackGuards'] ?? $activity['nextAttackGuards'] ?? []);
    if ($castingPlan !== null) {
        queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
        $cast = ['success' => ($hitOutcome['success'] ?? false) === true, 'statId' => $castingPlan['statId'], 'statLabel' => $castingPlan['statLabel'], 'outcome' => $hasCastingCheck ? $hitOutcome : null, 'roll' => $hasCastingCheck ? $hitRoll : null];
        $deferRecharge = ($hitOutcome['requiresGmValidation'] ?? false) === true || $projectileGate['pending'];
        $cast = onlineCommitAbilityCasting($connection, $records, $pending, $castingPlan, $cast, $source, $identity, false, true, !$deferRecharge);
        if ($deferRecharge) $attack['deferredAbilityCast'] = [
            'ability' => $abilities[$abilityIndex], 'characterId' => $castingPlan['characterId'], 'plan' => $castingPlan,
            'identity' => array_intersect_key($identity, array_flip(['id', 'display_name', 'effective_mode', 'permanent_role'])),
            'controllerAccountId' => $sourceController, 'sourceCharacterId' => $source['characterId'] ?? '',
            ...($mechanicsAbility !== null ? ['deviceId'=>$mechanicsAbility['id'], 'deviceMana'=>$mechanicsApplied['result']['manaSpent']] : []),
        ];
        $attack['cast'] = $cast; $activity = $pending['activity']['payload'] ?? $activity;
        // The status branch above may already have copied the
        // attack into the pending queue. Replace that copy after
        // committing the cast so later opposition/resolution can
        // never erase the canonical casting roll from receipts.
        foreach (is_array($activity['pendingAttacks'] ?? null) ? $activity['pendingAttacks'] : [] as $pendingIndex => $pendingAttack) {
            if (is_array($pendingAttack) && (string) ($pendingAttack['id'] ?? '') === $attack['id']) {
                $activity['pendingAttacks'][$pendingIndex] = $attack;
                break;
            }
        }
        $result['cast'] = $cast; $result['castSucceeded'] = $cast['success'];
    }
    // Keep the newly queued attack alongside the execution reservation
    // before condition handling reads the latest activity snapshot.
    queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
    $attack = applyOnlineAttackConditions($connection, $records, $pending, $attack);
    $attack = onlineFinalizeAttackDamageRoll($attack);
    $activity = $pending['activity']['payload'] ?? $activity;
    $activity = onlineMergeAttackActivityRolls($activity, $attack, applicationDomainPayload($records, 'initiative:' . (string) ($attack['sceneId'] ?? '')));
    foreach (is_array($activity['pendingAttacks'] ?? null) ? $activity['pendingAttacks'] : [] as $pendingIndex => $pendingAttack) {
        if (is_array($pendingAttack) && (string) ($pendingAttack['id'] ?? '') === $attack['id']) {
            $activity['pendingAttacks'][$pendingIndex] = $attack;
            break;
        }
    }
    $areaAttackResults = []; $areaVisualAttacks = [];
    if (count($weaponTargets)>1 || $areaTargetCandidates !== [] && ($hitOutcome['success'] ?? false) === true && $projectileGate['percent'] > 0) {
        queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
        foreach ($areaTargetCandidates as $areaCandidate) {
            $areaId = (string) ($areaCandidate['id'] ?? '');
            if ($areaId === '' || $areaId === (string) $target['id'] && !$aimedPoint) continue;
            $areaKey = onlineTokenDomainKey($sceneId, $areaId);
            if ($areaKey === '') continue;
            if (!isset($records[$areaKey])) $records = array_replace($records, applicationDomainRecords($connection, [$areaKey]));
            $areaTarget = $pending[$areaKey]['payload'] ?? applicationDomainPayload($records, $areaKey);
            if ($areaTarget === [] || ($areaTarget['hidden'] ?? false) === true || !onlineTokenOnActiveLayer($areaTarget, $map)) continue;
            $areaController = onlineTokenControllerIdFromRecords($connection, $records, $areaTarget);
            if (count($weaponTargets)<=1 && !applicationDamageAreaAffects([...$damageTargeting,'affectAllies'=>!empty($source['friendlyFire'])||($damageTargeting['affectAllies']??true)], (string) $source['id'], $sourceController, $areaId, $areaController)) continue;
            // Visibility was fixed before this cast could create its opaque wall.
            $areaCharacterId = trim((string) ($areaTarget['characterId'] ?? ''));
            if ($areaCharacterId !== '') {
                $areaCharacter = $pending['character:' . $areaCharacterId]['payload'] ?? applicationDomainPayload($records, 'character:' . $areaCharacterId);
                if ($areaCharacter !== [] && ($areaTarget['followCharacter']??true)!==false && empty($areaTarget['linkedTokenId'])) $areaTarget = synchronizeOnlineCharacterToken($areaTarget, $areaCharacter);
            }
            if (onlineResourceNumber($areaTarget['maxHp'] ?? 0) <= 0 || onlineTokenIsDead($areaTarget, $areaController !== '')) continue;
            $areaAttack = $attack;
            foreach (['damage', 'damageRoll', 'cast', 'deferredAbilityCast', 'resolvedAt', 'appliedDamage', 'previousHp', 'currentHp', 'maximumHp', 'validationKind', 'provisionalStatus', 'effectsApplied', 'dotApplied', 'reflection', 'guardPercent', 'guardPreventedDamage', 'resourcePulse', 'characterId', 'areaAnchorOnly', 'terrainBarrier', 'terrainCreated', 'structureContactProcessed', 'poisonStacksProcessed', 'poisonApplications','attackApproach','approachPoint','approachApplied','mountedMovement', 'riderApplied', 'opposition', 'projectileGate', 'echoDefinitions', 'echoProcessed', 'echoAttacks'] as $field) unset($areaAttack[$field]);
            $areaAttack['id'] = 'attack-' . randomToken(12);
            $areaAttack['requestId'] = 'area-' . randomToken(12);
            $areaAttack['targetTokenId'] = $areaId;
            $areaAttack['targetName'] = substr((string) ($areaTarget['name'] ?? 'Cible'), 0, 120);
            $areaAttack['defenderAccountId'] = $areaController;
            $areaAttack['damage'] = [];
            $areaAttack['finalDamage'] = 0;
            $targetOutcome=$weaponOutcomes[$areaId]??$hitOutcome;
            $targetGate=count($weaponTargets)>1?(($targetOutcome['success']??false)?onlineDeviceProjectile($connection,$records,$pending,$identity,$source,$areaTarget,($mechanicsDefinition['projectile']??false)===true,0,$sceneId):['percent'=>100,'checks'=>[],'pending'=>false]):$projectileGate;
            $areaAttack['hit']=[...$attack['hit'],'outcome'=>$targetOutcome,'total'=>$targetOutcome['result']??$attack['hit']['total']];
            $targetIsolated=applicationSingleEnemyAttackFields($mechanicsDefinition,$liveAreaTargets,$source,$areaTarget,static fn($token)=>onlineTokenControllerIdFromRecords($connection,$records,$token));
            $areaAttack['damageRepetitions']=$targetIsolated['damageRepetitions']??1;
            if(isset($mechanicsDefinition['singleEnemy'])){unset($areaAttack['conditionRider']);if(isset($mechanicsDefinition['conditionRider']))$areaAttack['conditionRider']=$mechanicsDefinition['conditionRider'];if(isset($targetIsolated['conditionRider']))$areaAttack['conditionRider']=$targetIsolated['conditionRider'];}
            $areaAttack['damagePercent']=(int)round($baseDamagePercent*$targetGate['percent']/100);
            if($targetGate['checks']!==[])$areaAttack['projectileGate']=$targetGate;
            if(count($weaponTargets)>1)$areaAttack['weaponAttackParentId']=$attack['id'];
            $areaAttack['opposed'] = $defensePossible && ($defenseApproval!==null?in_array($areaTarget['id'],$defenseApproval['defenseTokenIds'],true):($arguments['opposed']??false)===true) && (float) ($areaTarget['hp'] ?? 0) > 0;
            if(!($targetOutcome['success']??false)||$targetGate['percent']===0){
                $areaAttack['status']=!empty($targetOutcome['requiresGmValidation'])||$targetGate['pending']?'pending':'missed';
                if($areaAttack['status']==='pending'){$areaAttack['validationKind']='outcome';$areaAttack['provisionalStatus']='missed';}
            } elseif (empty($targetOutcome['requiresGmValidation'])&&!$targetGate['pending']&&$areaAttack['opposed'] && ($targetOutcome['breaksOpposition'] ?? false) !== true) {
                $areaAttack['status'] = 'awaiting-opposition';
            } else {
                $areaResult = onlineRollAttackDamage($areaAttack, $areaTarget,null,onlineAttackEnhancementState($connection,$records,$pending,$sceneId));
                $areaSummary = $areaResult['damage'];
                $areaRolled = $areaResult['rolled'];
                $areaRoll = onlineRollEntry($identity, $areaRolled, $attackName . ' · Dégâts', (string) ($source['name'] ?? 'Attaquant'));
                $areaRoll['visibility'] = 'gm'; $areaRoll['revealed'] = false;
                $areaAttack['damageRoll'] = $areaRoll;
                $areaAttack['damage'] = [...$areaSummary, 'formula' => $areaRoll['formula'], 'breakdown' => $areaRoll['breakdown'],
                    'rollId' => $areaRoll['id'], 'total' => $areaRoll['total'], 'rollMode' => $areaRoll['rollMode'],
                    'selectedIndex' => $areaRoll['selectedIndex'], 'attempts' => $areaRolled['attempts'] ?? []];
                $areaAttack['finalDamage'] = (int) $areaSummary['finalDamage'];
                if (!empty($targetOutcome['requiresGmValidation']) || $targetGate['pending']) {
                    $areaAttack['status'] = 'pending'; $areaAttack['validationKind'] = 'outcome';
                    $areaAttack['provisionalStatus'] = $areaAttack['finalDamage'] > 0 ? 'applied' : 'blocked';
                } elseif ($areaAttack['finalDamage'] <= 0) {
                    $areaAttack['status'] = 'blocked';
                } elseif ($combatActive) {
                    $health = applyOnlineAttackDamage($connection, $records, $pending, $areaKey, $areaTarget, $areaAttack['finalDamage'], true, [...$areaAttack['damage'], 'damageType'=>$areaAttack['damageType']]);
                    $areaAttack = onlineRecordAttackGuard($areaAttack, $health);
                    $areaAttack['status'] = $health['appliedDamage'] > 0 ? 'applied' : 'blocked';
                } else {
                    $areaAttack['status'] = 'pending';
                }
            }
            $areaAttack = applyOnlineAttackConditions($connection, $records, $pending, $areaAttack);
            $areaAttack = onlineFinalizeAttackDamageRoll($areaAttack);
            $areaActivity = $pending['activity']['payload'] ?? applicationDomainPayload($records, 'activity');
            if (in_array($areaAttack['status'], ['pending', 'awaiting-opposition'], true)) {
                $areaPending = is_array($areaActivity['pendingAttacks'] ?? null) ? $areaActivity['pendingAttacks'] : [];
                if (count($areaPending) >= XAR_PENDING_ATTACK_MAXIMUM) rejectOnlineCommand($connection, 409, 'La file des attaques de zone est pleine.', 'pending_attack_capacity_reached');
                $areaActivity['pendingAttacks'][] = $areaAttack;
            }
            $areaActivity = onlineMergeAttackActivityRolls($areaActivity, $areaAttack, applicationDomainPayload($records, 'initiative:' . (string) ($areaAttack['sceneId'] ?? '')));
            queueOnlineDomainUpsert($pending, $records, 'activity', $areaActivity);
            onlineAppendPlayerAction($connection, $records, $pending, $identity, $sceneId, [
                'kind' => 'attack', 'attackId'=>$areaAttack['id'], 'characterName' => $attack['sourceName'], 'targetName' => $areaAttack['targetName'],
                'summary' => $attack['sourceName'] . ' attaque ' . $areaAttack['targetName'] . ' avec ' . $attackName,
                'detail' => onlineAttackHistoryDetail($areaAttack, false) . (count($weaponTargets)>1?' · frappe sur plusieurs cibles':' · zone de ' . $damageTargeting['radiusCells'] . ' cases'),
            ]);
            if ($areaAttack['status'] === 'applied') onlineAppendAppliedDamageAction($connection, $records, $pending, $identity, $areaAttack);
            if (count($receipts) >= XAR_ATTACK_RECEIPT_MAXIMUM - 1) rejectOnlineCommand($connection, 409, 'Le journal de sécurité des attaques est plein.', 'attack_receipt_capacity');
            $receipts[] = ['requestId' => $areaAttack['requestId'], 'accountId' => $accountId,
                'requestSignature' => $attackRequestSignature,
                'expiresAt' => $now + XAR_ATTACK_RECEIPT_TTL_MILLISECONDS, 'attack' => $areaAttack];
            $areaAttackResults[] = $isGm ? $areaAttack : publicOnlineAttackResult($areaAttack, true, false);
            $areaVisualAttacks[] = $areaAttack;
        }
        $activity = $pending['activity']['payload'] ?? $activity;
    }
    $receipts[] = [
        'requestId' => $requestId,
        'accountId' => $accountId,
        'requestSignature' => $attackRequestSignature,
        'expiresAt' => $now + XAR_ATTACK_RECEIPT_TTL_MILLISECONDS,
        'attack' => $attack,
    ];
    appendApplicationAttackVisualEvent($activity,$attack,$map,$equipmentState['items']??[],$areaVisualAttacks);
    $activity['attackReceipts'] = $receipts;
    queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
    $attackActivityDetail = onlineAttackHistoryDetail($attack, false);
    if ($attack['status'] === 'awaiting-opposition') $attackActivityDetail .= ' · Jet d’opposition demandé';
    if (($attack['validationKind'] ?? '') === 'outcome') $attackActivityDetail .= ' · validation MJ en attente';
    $attackAction = onlineAppendPlayerAction($connection, $records, $pending, $identity, $sceneId, [
        'kind' => 'attack', 'attackId'=>$attack['id'],
        'characterName' => $attack['sourceName'],
        'targetName' => $attack['targetName'],
        'summary' => ($attack['areaAnchorOnly'] ?? false)
            ? $attack['sourceName'] . ' lance ' . $attack['attackName'] . ' autour de lui'
            : $attack['sourceName'] . ' attaque ' . $attack['targetName'] . ' avec ' . $attack['attackName'],
        'detail' => $attackActivityDetail,
    ]);
    if (($attack['status'] ?? '') === 'applied') {
        onlineAppendAppliedDamageAction($connection, $records, $pending, $identity, $attack);
    }
    foreach ($areaAttackResults as $i => $areaResult) {
        foreach (($pending['activity']['payload']['attackReceipts'] ?? []) as $receipt) {
            if (($receipt['attack']['id'] ?? '') !== $areaResult['id']) continue;
            $areaEchoResult = onlineApplyAttackEchoes($connection, $records, $pending, $receipt['attack']);
            $areaAttackResults[$i] = $isGm ? $areaEchoResult : publicOnlineAttackResult($areaEchoResult, true, false);
            break;
        }
    }
    $attack = onlineApplyAttackEchoes($connection, $records, $pending, $attack);
    $result['attack'] = $isGm ? $attack : publicOnlineAttackResult($attack, true, false);
    if ($areaAttackResults !== []) $result['areaAttacks'] = $areaAttackResults;
    $responseRolls = onlineAttackResponseRollFields($attack, $isGm, true, false);
    $result = [...$result, ...$responseRolls];
    if ($isGm && $sceneId !== onlineActiveSceneId($table)) unset($result['discordContent']);
    if ($abilityRequestSignature !== null) onlineStoreAbilityReceipt($records, $pending, $requestId, $accountId, $abilityRequestSignature, [
        'attackId' => $attack['id'],
        'cast' => $result['cast'],
        'castSucceeded' => $result['castSucceeded'],
        'roll' => $responseRolls['roll'],
        'castRoll' => $result['castRoll'],
        'effectRoll' => $result['effectRoll'],
        'damageRoll' => $result['damageRoll'],
        'rolls' => $result['rolls'],
    ], (string) ($attackAction['id'] ?? ''));
    }
}
    return $result;
}
