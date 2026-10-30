<?php
declare(strict_types=1);
function onlineAssertAbilityMountPilot(PDO $connection,array &$records,array $pending,array $source,array $step,string $sceneId,string $accountId,bool $isGm):void{
    if($isGm||empty($source['mountedOnTokenId'])||($step['mountedMovement']??false)!==true)return;
    $tokens=onlineSceneTokensForLights($connection,$records,$pending,$sceneId);$mount=null;foreach($tokens as $token)if($token['id']===$source['mountedOnTokenId'])$mount=$token;
    if($mount===null)rejectOnlineCommand($connection,403,'Vous ne pilotez pas cette monture.','ability_mount_pilot_required');
    $controller=onlineTokenControllerIdFromRecords($connection,$records,$mount);$pilot=$controller===''?onlineMountPilot($tokens,$mount):null;if($pilot!==null)$controller=onlineTokenControllerIdFromRecords($connection,$records,$pilot);
    if($controller!==$accountId)rejectOnlineCommand($connection,403,'Vous ne pilotez pas cette monture.','ability_mount_pilot_required');
}

function onlineComplexAbilityRequestSignature(array $arguments): string
{
    $action = ($arguments['action'] ?? '') === 'start' ? 'start' : 'command';
    return onlineCommandRequestSignature('ability-complex', $action === 'start' ? [
        'action' => 'start',
        'sceneId' => (string) ($arguments['sceneId'] ?? ''),
        'layerId' => (string) ($arguments['layerId'] ?? 'ground'),
        'sourceTokenId' => (string) ($arguments['sourceTokenId'] ?? ''),
        'abilityId' => (string) ($arguments['abilityId'] ?? ''),
        'targetTokenId' => (string) ($arguments['targetTokenId'] ?? ''),
        'rollMode' => normalizeOnlineRollMode($arguments['rollMode'] ?? 'normal'),
        'modifier' => normalizeOnlineD100Modifier($arguments['modifier'] ?? 0),
        'modifierMode' => ($arguments['modifierMode'] ?? '') === 'result' ? 'result' : 'threshold',
    ] : [
        'action' => 'command',
        'executionId' => (string) ($arguments['executionId'] ?? ''),
        'expectedRevision' => is_numeric($arguments['expectedRevision'] ?? null) ? (int) $arguments['expectedRevision'] : null,
        'command' => is_array($arguments['command'] ?? null) ? $arguments['command'] : [],
    ]);
}

function onlineComplexAbilitySceneTokens(
    PDO $connection,
    array &$records,
    string $sceneId,
    array $map,
    string $accountId,
    bool $isGm
): array {
    $records = array_replace($records, applicationDomainRecords($connection));
    $tokens = [];
    foreach ($records as $key => $_record) {
        if (!str_starts_with($key, 'token:' . $sceneId . ':')) continue;
        $token = applicationDomainPayload($records, $key);
        if ($token === [] || !onlineTokenOnActiveLayer($token, $map)) continue;
        $characterId = (string) ($token['characterId'] ?? '');
        $character = $characterId !== '' ? applicationDomainPayload($records, 'character:' . $characterId) : [];
        if ($character !== []) $token = synchronizeOnlineCharacterToken($token, $character);
        if (!$isGm && ($token['hidden'] ?? false) === true
            && onlineTokenControllerIdFromRecords($connection, $records, $token) !== $accountId) continue;
        if (!$isGm && onlineTokenControllerIdFromRecords($connection, $records, $token) !== $accountId
            && !onlineAttackTargetVisible($connection, $records, $map, $token, $accountId, $sceneId)) continue;
        $token['controllerAccountId'] = onlineTokenControllerIdFromRecords($connection, $records, $token);
        $token['stats']=onlineEnhancedStatRows($connection,$records,[],$token,$sceneId);
        $token['weaponAttacks']=abilityEquipmentAttackRows($token['weaponAttacks']??[],$token,onlineEquipmentState($connection,$records,[],$sceneId,$map));
        $tokens[] = $token;
    }
    return $tokens;
}

function publicApplicationComplexAbilityExecution(
    array $execution,
    string $accountId,
    bool $isGm,
    array $tokens,
    string $participantTokenId = ''
): ?array
{
    if ($isGm || (string) ($execution['controllerAccountId'] ?? '') === $accountId) {
        return [...$execution, 'ownedByYou' => (string) ($execution['controllerAccountId'] ?? '') === $accountId, 'participantOnly' => false];
    }
    $step = $execution['workflow']['steps'][$execution['currentStepIndex'] ?? -1] ?? null;
    if (($step['type'] ?? '') === 'allocated-attacks' || $participantTokenId !== '') {
        $allocatedStep = ($step['type'] ?? '') === 'allocated-attacks' ? $step : null;
        if (!is_array($allocatedStep) && $participantTokenId !== '') foreach (array_reverse($execution['workflow']['steps'] ?? []) as $candidateStep) {
            if (($candidateStep['type'] ?? '') !== 'allocated-attacks') continue;
            $candidateState = $execution['stepStates'][$candidateStep['id']] ?? null;
            if (in_array($participantTokenId, array_column(is_array($candidateState['targets'] ?? null) ? $candidateState['targets'] : [], 'tokenId'), true)) { $allocatedStep = $candidateStep; break; }
        }
        if (is_array($allocatedStep)) {
            $allocatedState = $execution['stepStates'][$allocatedStep['id']] ?? [];
            $targetId = $participantTokenId !== '' ? $participantTokenId : (($allocatedState['awaitingAwareness'] ?? false) ? ($allocatedState['pendingTargetTokenId'] ?? '') : '');
            foreach (is_array($allocatedState['targets'] ?? null) ? $allocatedState['targets'] : [] as $candidate) {
                if (($candidate['tokenId'] ?? '') !== $targetId || $targetId === '') continue;
                $token = applicationComplexAbilityTokenById($tokens, $targetId);
                if (!is_array($token) || (string) ($token['controllerAccountId'] ?? $token['controllerPlayerId'] ?? '') !== $accountId) return null;
                return [...$execution, 'castGate' => null, 'controllerAccountId' => '', 'controllerName' => '', 'ownedByYou' => false, 'participantOnly' => true,
                    'events' => [], 'stepStates' => [$allocatedStep['id'] => [
                        'status' => $allocatedState['status'] ?? 'pending', 'targets' => [$candidate],
                        'pendingTargetTokenId' => $targetId, 'awaitingAwareness' => ($allocatedState['awaitingAwareness'] ?? false) === true,
                        'awaitingAttack' => ($allocatedState['awaitingAttack'] ?? false) === true,
                    ]]];
            }
        }
    }
    if ((!is_array($step) || ($step['type'] ?? '') !== 'defense-series') && $participantTokenId !== '') {
        foreach (array_reverse($execution['workflow']['steps'] ?? []) as $candidateStep) {
            if (($candidateStep['type'] ?? '') !== 'defense-series') continue;
            $candidateState = $execution['stepStates'][$candidateStep['id']] ?? null;
            foreach (is_array($candidateState['targets'] ?? null) ? $candidateState['targets'] : [] as $candidateTarget) {
                if (($candidateTarget['tokenId'] ?? '') === $participantTokenId) { $step = $candidateStep; break 2; }
            }
        }
    }
    if (!is_array($step) || ($step['type'] ?? '') !== 'defense-series') return null;
    $state = $execution['stepStates'][$step['id']] ?? null;
    if (!is_array($state)) return null;
    $target = null;
    foreach (is_array($state['targets'] ?? null) ? $state['targets'] : [] as $candidate) {
        if (!is_array($candidate)) continue;
        if ($participantTokenId !== '' ? ($candidate['tokenId'] ?? '') === $participantTokenId : ($candidate['status'] ?? '') === 'pending') {
            $target = $candidate; break;
        }
    }
    if (!is_array($target)) return null;
    $token = applicationComplexAbilityTokenById($tokens, $target['tokenId'] ?? '');
    if (!is_array($token) || (($token['ownedByYou'] ?? false) !== true
        && (string) ($token['controllerAccountId'] ?? $token['controllerPlayerId'] ?? '') !== $accountId)) return null;
    $sourceState = $execution['stepStates'][$step['sourceStepId']] ?? null;
    $allocation = null;
    foreach (is_array($sourceState['allocations'] ?? null) ? $sourceState['allocations'] : [] as $candidate) {
        if (is_array($candidate) && ($candidate['tokenId'] ?? '') === ($target['tokenId'] ?? '')) { $allocation = $candidate; break; }
    }
    $projected = $execution;
    $projected['castGate'] = null;
    $projected['controllerAccountId'] = '';
    $projected['controllerName'] = '';
    $projected['ownedByYou'] = false;
    $projected['participantOnly'] = true;
    $projected['events'] = [];
    $projected['stepStates'] = [
        ...($allocation === null ? [] : [$step['sourceStepId'] => ['status' => 'completed', 'allocations' => [$allocation]]]),
        $step['id'] => ['status' => $state['status'] ?? 'pending', 'targets' => [$target]],
    ];
    return $projected;
}

// Charge spending and attack creation share the enclosing command transaction.
// All subsequent defenses use the ordinary authoritative attack resolver.
function onlineCounterDamageAttack(PDO $connection, array &$records, array &$pending, array $identity,
    array $execution, array $use, string $requestId, bool $isGm): array
{
    $now = (int) floor(microtime(true) * 1000);
    $sceneId = $execution['sceneId'];
    $source = applicationDomainPayload($records, onlineTokenDomainKey($sceneId, $execution['sourceTokenId']));
    $targetKey = onlineTokenDomainKey($sceneId, $use['targetTokenId']);
    $target = applicationDomainPayload($records, $targetKey);
    $option = null;
    foreach ($execution['workflow']['steps'][$execution['currentStepIndex']]['spendOptions'] as $candidate) if ($candidate['id'] === $use['optionId']) $option = $candidate;
    $table = applicationDomainPayload($records, 'table');
    $attack = [
        'id' => 'attack-' . randomToken(12), 'requestId' => $requestId, 'sceneId' => $sceneId, 'layerId' => $execution['layerId'],
        'sourceTokenId' => $execution['sourceTokenId'], 'sourceName' => $source['name'] ?? $execution['sourceName'],
        'targetTokenId' => $use['targetTokenId'], 'targetName' => $target['name'] ?? 'Cible', 'accountId' => (string) $identity['id'],
        'attackerRole' => $isGm ? 'gm' : 'player', 'playerName' => $identity['display_name'] ?? 'Joueur',
        'visibility' => $isGm && !onlineGmTokenVisibleToPlayers($connection, $records, $table, $source, $sceneId) ? 'gm' : 'public',
        'attackName' => substr($execution['abilityName'] . ' · ' . ($option['label'] ?? 'Charge'), 0, 120),
        'attackKind' => 'ability', 'attackId' => $execution['abilityId'], 'counterExecutionId' => $execution['id'],
        ...(!empty($execution['echoParentId']) ? ['echoParentId' => $execution['echoParentId']] : []),
        ...(!empty($execution['echoParentId']) && !empty($execution['echoAreaRadiusCells']) ? ['areaRadiusCells' => $execution['echoAreaRadiusCells']] : []),
        'opposed' => ($use['opposed'] ?? false) === true,
        'defenderAccountId' => $target !== [] ? onlineTokenControllerIdFromRecords($connection, $records, $target) : '',
        'damageFormula' => $use['effect']['formula'], 'damageType' => $use['effect']['damageType'], 'damageRollMode' => 'normal', 'damagePercent' => 100,
        'hit' => ['raw' => null, 'total' => null, 'formula' => '', 'statId' => '', 'statLabel' => 'Sans jet', 'breakdown' => 'Lancement sans jet',
            'diceAppearance' => onlineDiceAppearance($source, ($source['controllerPlayerId'] ?? '') !== ''),
            'outcome' => ['automatic' => true, 'success' => true, 'code' => 'success', 'label' => 'SANS JET', 'raw' => null, 'threshold' => null]],
        'damage' => [], 'finalDamage' => 0, 'onHitConditions' => [], 'createdAt' => $now,
    ];
    if(empty($execution['echoParentId'])){$enhanced=applicationEnhancedAttackDamage(['formula'=>$attack['damageFormula'],'damageType'=>$attack['damageType']],$source,onlineAttackEnhancementState($connection,$records,$pending,$sceneId),$sceneId);$attack['damageFormula']=$enhanced['formula'];$attack['damageType']=$enhanced['damageType'];if(isset($enhanced['damageComponents']))$attack['damageComponents']=$enhanced['damageComponents'];}
    $parties = onlinePendingAttackParties($connection, $records, $attack);
    $target = $parties['target'];
    if (!is_numeric($target['maxHp'] ?? null) || (float) $target['maxHp'] <= 0 || onlineTokenIsDead($target, $parties['targetController'] !== '')) {
        rejectOnlineCommand($connection, 409, 'Cette cible est morte ou ne possède pas de points de vie.', 'complex_ability_effect_target_dead');
    }
    $activity = $pending['activity']['payload'] ?? applicationDomainPayload($records, 'activity');
    $pendingIds = array_column($activity['pendingAttacks'] ?? [], 'id');
    $receipts = array_values(array_filter($activity['attackReceipts'] ?? [], static fn(array $receipt): bool =>
        (int) ($receipt['expiresAt'] ?? 0) > $now || in_array($receipt['attack']['id'] ?? '', $pendingIds, true)));
    if (count($receipts) >= XAR_ATTACK_RECEIPT_MAXIMUM) rejectOnlineCommand($connection, 429, 'Le journal de sécurité des attaques est plein.', 'attack_receipt_capacity');
    $attack['opposed'] = $attack['opposed'] && (float) ($target['hp'] ?? 0) > 0;
    if ($attack['opposed']) $attack['status'] = 'awaiting-opposition';
    else {
        ['rolled' => $rolled, 'damage' => $damage] = onlineRollAttackDamage($attack, $target,null,onlineAttackEnhancementState($connection,$records,$pending,$sceneId));
        $attack['damage'] = [...$damage, 'formula' => $rolled['formula'], 'breakdown' => $rolled['breakdown']];
        $attack['finalDamage'] = $damage['finalDamage'];
        $attack['damageRoll'] = onlineRollEntry($identity, $rolled, $attack['attackName'] . ' · Dégâts', $attack['sourceName']);
        $initiative = applicationDomainPayload($records, 'initiative:' . $sceneId);
        if ($damage['finalDamage'] <= 0) $attack['status'] = 'blocked';
        elseif (($initiative['active'] ?? false) === true || !empty($execution['echoParentId']) && !empty($execution['echoApproved'])) {
            $health = applyOnlineAttackDamage($connection, $records, $pending, $targetKey, $target, $damage['finalDamage'], true, [...$damage, 'damageType' => $attack['damageType']]);
            $attack = onlineRecordAttackGuard($attack, $health);
            $attack['status'] = $health['appliedDamage'] > 0 ? 'applied' : 'blocked';
        } else $attack['status'] = 'pending';
    }
    $activity = $pending['activity']['payload'] ?? $activity;
    if (in_array($attack['status'], ['pending', 'awaiting-opposition'], true)) {
        if (count($activity['pendingAttacks'] ?? []) >= XAR_PENDING_ATTACK_MAXIMUM) rejectOnlineCommand($connection, 409, 'La file des attaques en attente est pleine.', 'pending_attack_capacity_reached');
        $activity['pendingAttacks'][] = $attack;
    }
    queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
    $attack = applyOnlineAttackConditions($connection, $records, $pending, $attack);
    $attack = onlineFinalizeAttackDamageRoll($attack);
    $activity = $pending['activity']['payload'] ?? $activity;
    $activity = onlineMergeAttackActivityRolls($activity, $attack, applicationDomainPayload($records, 'initiative:' . (string) ($attack['sceneId'] ?? '')));
    foreach ($activity['pendingAttacks'] ?? [] as $index => $entry) if (($entry['id'] ?? '') === $attack['id']) $activity['pendingAttacks'][$index] = $attack;
    $receipts[] = ['requestId' => $requestId, 'accountId' => (string) $identity['id'],
        'requestSignature' => onlineCommandRequestSignature('counter-spend', [$execution['id'], $execution['revision']]),
        'expiresAt' => $now + XAR_ATTACK_RECEIPT_TTL_MILLISECONDS, 'attack' => $attack];
    $activity['attackReceipts'] = $receipts;
    queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
    onlineAppendPlayerAction($connection, $records, $pending, $identity, $sceneId, ['kind' => 'attack', 'attackId'=>$attack['id'],
        'characterName' => $attack['sourceName'], 'targetName' => $attack['targetName'],
        'summary' => $attack['sourceName'] . ' attaque ' . $attack['targetName'] . ' avec ' . $attack['attackName'],
        'detail' => $attack['status'] === 'awaiting-opposition' ? 'Orbe consommé · jet de défense demandé' : onlineAttackHistoryDetail($attack)]);
    if ($attack['status'] === 'applied') onlineAppendAppliedDamageAction($connection, $records, $pending, $identity, $attack);
    $attack=onlineApplyAttackEchoes($connection, $records, $pending, $attack);
    if(applicationAttackVisualWaitsForDamage($activity,$attack)&&in_array($attack['status'],['applied','blocked'],true)){
        $visualState=onlineEquipmentState($connection,$records,$pending,$sceneId);$visualMap=[...$visualState['map'],'tokens'=>onlineSceneTokensForLights($connection,$records,$pending,$sceneId)];
        $activity=$pending['activity']['payload']??$activity;appendApplicationAttackVisualEvent($activity,$attack,$visualMap,$visualState['items']);queueOnlineDomainUpsert($pending,$records,'activity',$activity);
    }
    return $attack;
}

function onlineUseComplexAbility(
    PDO $connection,
    array &$records,
    array &$pending,
    array $table,
    array $identity,
    array $arguments,
    bool $isGm,
    ?array $continuation = null
): array {
    $accountId = (string) ($identity['id'] ?? '');
    $requestId = trim((string) ($arguments['requestId'] ?? ''));
    if (preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $requestId) !== 1) {
        rejectOnlineCommand($connection, 400, 'Actualisez le client pour sécuriser cette compétence complexe.', 'invalid_complex_ability_request');
    }
    $signature = onlineComplexAbilityRequestSignature($arguments);
    $receiptContext = $continuation === null ? onlinePersistentCommandReceipt(
        $connection, $records, 'ability-workflow', $requestId, $accountId, $signature, 'complex_ability'
    ) : [];
    if (is_array($receiptContext['receipt'] ?? null)) {
        onlineAssertAbilityReceiptVisibility($connection, $receiptContext['receipt']['result'], $isGm);
        return [...$receiptContext['receipt']['result'], 'deduplicated' => true];
    }
    $records = array_replace($records, applicationDomainRecords($connection));
    $activity = applicationDomainPayload($records, 'activity');
    $activity['abilityExecutions'] = trimApplicationComplexAbilityExecutions($activity['abilityExecutions'] ?? []);
    $action = ($arguments['action'] ?? '') === 'start' ? 'start' : 'command';
    $now = (int) floor(microtime(true) * 1000);

    if ($action === 'start') {
        $sceneId = trim((string) ($arguments['sceneId'] ?? ''));
        if ($sceneId === '' || $sceneId !== onlineActiveSceneId($table)) {
            rejectOnlineCommand($connection, 409, 'La scène a changé. Rouvrez la compétence.', 'stale_scene');
        }
        if (!$isGm && ($table['tacticalSync']['paused'] ?? false) === true) {
            rejectOnlineCommand($connection, 423, 'La table est verrouillée.', 'table_locked');
        }
        $map = applicationDomainPayload($records, 'map:' . $sceneId);
        $sourceKey = onlineTokenDomainKey($sceneId, $arguments['sourceTokenId'] ?? '');
        $source = applicationDomainPayload($records, $sourceKey);
        $controllerId = $source === [] ? '' : onlineTokenControllerIdFromRecords($connection, $records, $source);
        if ($source === [] || !onlineTokenOnActiveLayer($source, $map)
            || (!empty($arguments['layerId']) && $arguments['layerId'] !== onlineTokenLayerId($source, $map))
            || (!$isGm && (($source['hidden'] ?? false) === true || $controllerId !== $accountId))) {
            rejectOnlineCommand($connection, 403, 'Ce pion ne vous appartient pas ou a changé de niveau.', 'ability_source_forbidden');
        }
        $owner = applicationAbilityCastingOwner($source);
        $character = $owner['characterId'] !== '' ? applicationDomainPayload($records, 'character:' . $owner['characterId']) : [];
        $rules = $character !== [] ? synchronizeOnlineCharacterToken($source, $character) : $source;
        $rules['controllerPlayerId'] = $controllerId;
        $abilities = normalizeOnlineAbilities($rules['abilities'] ?? []);
        $abilityIndex = findEntryIndex($abilities, (string) ($arguments['abilityId'] ?? ''));
        $ability = $abilityIndex >= 0 ? $abilities[$abilityIndex] : null;
        if (!is_array($ability) || ($ability['effect'] ?? '') !== 'complex') {
            rejectOnlineCommand($connection, 404, 'Cette compétence complexe n’existe plus.', 'complex_ability_missing');
        }
        $first=$ability['workflow']['steps'][0]??[];$initialMovement=($first['type']??'')==='movement'?$first:null;
        if($initialMovement===null)foreach($ability['workflow']['steps'] as $step)if(($step['charge']['targetStepId']??null)===($first['id']??'')){$initialMovement=$step;break;}
        if($initialMovement!==null)onlineAssertAbilityMountPilot($connection,$records,$pending,$source,[...$initialMovement,'mountedMovement'=>$initialMovement['mountedMovement']??$ability['mountedMovement']??false],$sceneId,$accountId,$isGm);
        $initiative = applicationDomainPayload($records, 'initiative:' . $sceneId);
        $persistent = count(array_filter($ability['workflow']['steps'] ?? [], static fn(mixed $step): bool => is_array($step)
            && ($step['type'] ?? '') === 'counter' && ($step['combatPersistent'] ?? false) === true)) > 0;
        $requiresCombat = $persistent || count(array_filter($ability['workflow']['steps'] ?? [],
            static fn(mixed $step): bool => is_array($step) && ($step['type'] ?? '') === 'guard')) > 0;
        $combatId = ($initiative['active'] ?? false) === true ? trim((string) ($initiative['combatId'] ?? '')) : '';
        if ($requiresCombat && (($initiative['active'] ?? false) !== true || $combatId === '')) {
            rejectOnlineCommand($connection, 409, 'Cette compétence exige un combat actif.', 'complex_ability_combat_required');
        }
        if ($persistent) foreach ($activity['abilityExecutions'] as $execution) {
            if (($execution['sceneId'] ?? '') === $sceneId && ($execution['sourceTokenId'] ?? '') === ($source['id'] ?? '')
                && ($execution['abilityId'] ?? '') === ($ability['id'] ?? '') && ($execution['combatId'] ?? '') === $combatId) {
                rejectOnlineCommand($connection, 409, 'Ce sort a déjà été activé pendant ce combat.', 'complex_ability_combat_once');
            }
        }
        if ($continuation === null && applicationAbilitySourceDefeated($rules)) {
            rejectOnlineCommand($connection, 409, 'Un pion KO ou mort ne peut lancer une compétence.', 'ability_source_defeated');
        }
        foreach ($activity['abilityExecutions'] as $execution) {
            if (($execution['status'] ?? '') === 'active' && ($execution['sceneId'] ?? '') === $sceneId
                && ($execution['sourceTokenId'] ?? '') === ($source['id'] ?? '')
                && ($execution['abilityId'] ?? '') === ($ability['id'] ?? '')) {
                rejectOnlineCommand($connection, 409, 'Cette compétence est déjà en cours. Reprenez son exécution existante.', 'complex_ability_already_active');
            }
        }
        if (count(array_filter($activity['abilityExecutions'], static fn(array $entry): bool => ($entry['status'] ?? '') === 'active')) >= 30) {
            rejectOnlineCommand($connection, 429, 'La table contient déjà trop de compétences complexes actives.', 'complex_ability_active_capacity');
        }
        if ($continuation === null) onlineAssertAbilityValidationAvailable($connection, $activity, $ability, $rules);
        if(array_filter($ability['workflow']['steps']??[],static fn($s)=>($s['type']??'')==='movement')){$lock=applicationAbilityMovementLock($activity['abilityExecutions'],$sceneId,$source['id'],onlineSceneTokensForLights($connection,$records,$pending,$sceneId),null,$activity['abilityMovementLeases']??[]);if($lock!==null)rejectOnlineCommand($connection,409,$lock['label'],'ability_movement_locked');}
        $plan = $continuation['plan'] ?? onlinePrepareAbilityCasting(
            $connection, $ability, $rules, $sceneId,
            applicationDomainPayload($records, 'initiative:' . $sceneId), $activity
        );
        $sourceVisibleToPlayers = $isGm && onlineGmTokenVisibleToPlayers($connection, $records, $table, $source, $sceneId);
        $cast = $continuation['cast'] ?? onlineAbilityCastingRoll($plan, $rules, $identity, $arguments, onlineTokenLayerId($source, $map), $character, $sourceVisibleToPlayers, (string) ($source['id'] ?? ''));
        if ($continuation === null && ($cast['outcome']['requiresGmValidation'] ?? false) === true) {
            return onlineDeferAbilityCasting($connection, $records, $pending, 'ability.complex', $identity, $arguments, $isGm, $ability, $rules, $plan, $cast, $signature, [], $receiptContext);
        }
        $chainTimersBefore = $activity['actionTimers'] ?? [];
        $cast = onlineCommitAbilityCasting($connection, $records, $pending, $plan, $cast, $rules, $identity, true, $continuation === null);
        if ($continuation !== null && is_array($cast['outcome'] ?? null)) $cast['outcome']['requiresGmValidation'] = false;
        $execution = null;
        if (($cast['success'] ?? false) === true) {
            try {
                $execution = createApplicationComplexAbilityExecution([
                    'id' => 'execution-' . randomToken(12), 'requestId' => $requestId,
                    'sceneId' => $sceneId, 'layerId' => onlineTokenLayerId($source, $map),
                    'sourcePosition' => applicationAbilityMovementSource($source,[...$map,'tokens'=>onlineSceneTokensForLights($connection,$records,$pending,$sceneId)],($ability['workflow']['steps'][0]['mountedMovement']??$ability['mountedMovement']??false)===true), 'sourceTokenId' => $source['id'] ?? '', 'sourceName' => $source['name'] ?? 'Personnage',
                    'characterId' => $owner['characterId'] ?? '',
                    'controllerAccountId' => $controllerId !== '' ? $controllerId : $accountId,
                    'controllerName' => $identity['display_name'] ?? '', 'ability' => applicationConfiguredStackableAbility($ability), 'cast' => $cast, 'now' => $now,
                    'targetTokenId' => $arguments['targetTokenId'] ?? '',
                    'combatId' => $persistent ? $combatId : '',
                ]);
            } catch (ApplicationComplexAbilityException $error) {
                rejectOnlineCommand($connection, $error->httpStatus, $error->getMessage(), $error->errorCode);
            }
            $currentActivity = is_array($pending['activity']['payload'] ?? null) ? $pending['activity']['payload'] : $activity;
            applicationCaptureChainFailureTimer($execution,$ability,$chainTimersBefore,$currentActivity['actionTimers']??[],applicationDomainPayload($records,'initiative:'.$sceneId));
            $currentActivity['abilityExecutions'] = trimApplicationComplexAbilityExecutions([
                ...($currentActivity['abilityExecutions'] ?? []), $execution,
            ]);
            queueOnlineDomainUpsert($pending, $records, 'activity', $currentActivity);
        }
        $actionEntry = onlineAppendPlayerAction($connection, $records, $pending, $identity, $sceneId, [
            'kind' => 'ability-workflow', 'characterName' => $source['name'] ?? 'Personnage',
            'summary' => ($cast['success'] ?? false) ? 'Commence ' . $ability['name'] : 'Échoue à lancer ' . $ability['name'],
            'detail' => ($cast['manaSpent'] ?? 0) . ' mana · ' . ($cast['hpSpent'] ?? 0) . ' PV · +' . ($cast['fatigueGained'] ?? 0) . ' fatigue',
        ]);
        $bundle = onlineAbilityRollBundle($cast);
        $firstStep = $ability['workflow']['steps'][0];
        if (empty($cast['success']) && $firstStep['type'] === 'attack-chain' && !empty($firstStep['firstGateAtCast']) && !empty($arguments['targetTokenId'])) {
            $context = ['id'=>'cast-'.$requestId,'revision'=>0,'sceneId'=>$sceneId,'layerId'=>onlineTokenLayerId($source,$map),
                'sourceTokenId'=>$source['id'],'abilityId'=>$ability['id'],'abilityName'=>$ability['name'],'workflow'=>$ability['workflow'],'currentStepIndex'=>0,'stepStates'=>[]];
            $failed = [...$context,'revision'=>1,'stepStates'=>[$firstStep['id']=>['plan'=>['targetTokenId'=>$arguments['targetTokenId']],
                'rolls'=>[[...($cast['roll']??[]),'rawD100'=>$cast['outcome']['raw'],'success'=>false,'outcomeDetails'=>[...$cast['outcome'],'requiresGmValidation'=>false]]]]]];
            $bundle['rolls'] = [...$bundle['rolls'], ...onlineFailedChainEchoes($connection,$records,$pending,$context,$failed,'prepare-chain',$identity,$isGm)];
        }
        onlineAppendAbilityRollActions($connection, $records, $pending, $identity, $sceneId, $bundle['rolls']);
        $result = ['effect' => 'complex', 'castSucceeded' => ($cast['success'] ?? false) === true, 'cast' => $cast,
            ...$bundle, 'execution' => $execution];
        if ($continuation === null) onlineStorePersistentCommandReceipt($records, $pending, $receiptContext, 'ability-workflow', $requestId,
            $accountId, $actionEntry['id'], $sourceKey, $signature, $result);
        return [...$result, 'deduplicated' => false];
    }

    $executionId = trim((string) ($arguments['executionId'] ?? ''));
    $executionIndex = -1;
    foreach ($activity['abilityExecutions'] as $index => $candidate) {
        if (($candidate['id'] ?? '') === $executionId) { $executionIndex = $index; break; }
    }
    if ($executionIndex < 0) rejectOnlineCommand($connection, 404, 'Cette exécution n’existe plus.', 'complex_ability_execution_missing');
    $current = $activity['abilityExecutions'][$executionIndex];
    $workflowAction = (string) ($arguments['command']['action'] ?? '');
    $currentStep = $current['workflow']['steps'][$current['currentStepIndex'] ?? -1] ?? null;
    $currentStepState = is_array($currentStep) ? ($current['stepStates'][$currentStep['id']] ?? null) : null;
    $participantTokenId = '';
    if (($currentStep['type'] ?? '') === 'defense-series') {
        foreach (is_array($currentStepState['targets'] ?? null) ? $currentStepState['targets'] : [] as $candidateTarget) {
            if (($candidateTarget['status'] ?? '') === 'pending') { $participantTokenId = (string) ($candidateTarget['tokenId'] ?? ''); break; }
        }
    }
    if (($currentStep['type'] ?? '') === 'allocated-attacks' && ($currentStepState['awaitingAwareness'] ?? false) === true) {
        $participantTokenId = (string) ($currentStepState['pendingTargetTokenId'] ?? '');
    }
    $sceneId = (string) ($current['sceneId'] ?? '');
    $workflowCommand = is_array($arguments['command'] ?? null) ? $arguments['command'] : [];
    $useJoker = applicationRequestsSpecialSuccess($workflowCommand);
    if ($useJoker && !applicationComplexStepAllowsSpecialSuccess($currentStep ?? [], $workflowAction)) rejectOnlineCommand($connection, 409, 'Le Joker remplace uniquement un jet de réussite de cette étape.', 'joker_step_invalid');
    $jokerRollCount = $workflowCommand['jokerRollCount'] ?? null;
    if (array_key_exists('jokerRollCount', $workflowCommand) && (!$useJoker || ($currentStep['resolutionMode'] ?? '') !== 'batch' || $workflowAction !== 'prepare-chain' || !is_int($jokerRollCount) || $jokerRollCount < 1 || $jokerRollCount > $currentStep['count'])) rejectOnlineCommand($connection, 400, 'Le nombre de Jokers de cette série est invalide.', 'joker_count_invalid');
    $map = applicationDomainPayload($records, 'map:' . $sceneId);
    if ($workflowAction !== 'cancel') {
        if ($sceneId !== onlineActiveSceneId($table)
            || ($current['layerId'] ?? 'ground') !== onlineTokenLayerId([], $map)) {
            rejectOnlineCommand($connection, 409, 'La scène ou le niveau a changé. Revenez au contexte du sort ou arrêtez-le.', 'complex_ability_context_changed');
        }
        if (!$isGm && ($table['tacticalSync']['paused'] ?? false) === true) {
            rejectOnlineCommand($connection, 423, 'La table est verrouillée.', 'table_locked');
        }
    }
    $tokens = onlineComplexAbilitySceneTokens($connection, $records, $sceneId, $map, $accountId, $isGm);
    $staged=count(array_filter($current['workflow']['steps'],static fn($s)=>!empty($s['charge']['staged'])))>0;
    if($staged){
        $visibleIds=array_column($tokens,'id');foreach(onlineSceneTokensForLights($connection,$records,$pending,$sceneId)as$t)if(!in_array($t['id'],$visibleIds,true))$tokens[]=[...$t,'hidden'=>true];
        $source=applicationComplexAbilityTokenById($tokens,$current['sourceTokenId']);$occlusion=applicationActiveMapOcclusionState($map,$tokens);
        if($isGm){$character=applicationDomainPayload($records,'character:'.($source['characterId']??''));$origins=[['x'=>$source['x'],'y'=>$source['y'],'visionDistance'=>onlineTokenVisionDistance($source,$character?:null),'darkVision'=>onlineTokenDarkVision($source,$character?:null),'source'=>'token']];$map['_chargeVisible']=onlineVisiblePathPointTester(applicationActiveMapFogState($map),applicationComputeVisionMask($occlusion,$origins,$map['gridSize']??50));}
        else $map['_chargeVisible']=onlineMapMovementVisibility($connection,$records,$map,$accountId,$sceneId);
    }
    $map['_abilityTimedConditions']=$isGm?[]:onlineDeviceState($connection,$records,$pending)['devices']['timedConditions'];
    if($workflowAction==='prepare-target')foreach($current['workflow']['steps'] as $movement)if($movement['id']===($currentStep['sourceStepId']??'')&&!empty($movement['charge']['staged']))onlineAssertAbilityMountPilot($connection,$records,$pending,applicationDomainPayload($records,onlineTokenDomainKey($sceneId,$current['sourceTokenId'])),$movement,$sceneId,$accountId,$isGm);
    $chargeTravel=applicationStagedChargeTravel($current,$workflowCommand,$tokens,[...$map,'tokens'=>$tokens,'_abilityExecutions'=>$activity['abilityExecutions']??[],'_abilityMovementLeases'=>$activity['abilityMovementLeases']??[]]);$tokens=applicationChargeTravelTokens($tokens,$chargeTravel);
    $map['tokens']=$tokens;$map['_abilityExecutions']=$activity['abilityExecutions']??[];$map['_abilityMovementLeases']=$activity['abilityMovementLeases']??[];
    $generatedRolls = [];
    $confirmedAttack = null;
    if ($workflowAction === 'confirm-attack') {
        $attackRequestId = trim((string) ($arguments['command']['attackRequestId'] ?? ''));
        foreach (is_array($activity['attackReceipts'] ?? null) ? $activity['attackReceipts'] : [] as $receipt) {
            if (($receipt['requestId'] ?? '') === $attackRequestId && ($receipt['accountId'] ?? '') === $accountId) {
                $confirmedAttack = $receipt['attack'] ?? null;
                break;
            }
        }
    }
    $initiative = applicationDomainPayload($records, 'initiative:' . $sceneId);
    try {
        $next = applyApplicationComplexAbilityCommand(
            $current,
            [...(is_array($arguments['command'] ?? null) ? $arguments['command'] : []),
                'expectedRevision' => $arguments['expectedRevision'] ?? null],
            [
                'actor' => ['id' => $accountId, 'name' => $identity['display_name'] ?? '', 'role' => $isGm ? 'gm' : 'player'],
                'tokens' => $tokens, 'map' => $map, 'now' => $now, 'attack' => $confirmedAttack,
                'combatActive' => ($initiative['active'] ?? false) === true
                    && (($current['combatId'] ?? '') === '' || ($current['combatId'] ?? '') === ($initiative['combatId'] ?? '')),
                'currentTokenId' => (string) ($initiative['order'][$initiative['currentIndex'] ?? 0] ?? ''),
                'turnKey' => $sceneId . ':' . (string) ($initiative['combatId'] ?? '') . ':' . (string) ($initiative['turnSerial'] ?? 0),
                'roll' => static function (string $formula, string $mode = 'normal', bool $d100RollUnder = false) use (&$generatedRolls, $useJoker, $jokerRollCount, $participantTokenId, $current, $sceneId, $connection, &$records, &$pending): array {
                    $sourceKey = onlineTokenDomainKey($sceneId, $participantTokenId ?: $current['sourceTokenId']);
                    $jokerSource = $pending[$sourceKey]['payload'] ?? applicationDomainPayload($records, $sourceKey);
                    $joker = $useJoker && ($jokerRollCount === null || count(array_filter($generatedRolls, static fn($roll) => ($roll['joker'] ?? false) === true)) < $jokerRollCount);
                    $rolled = $joker ? onlineConsumeSpecialSuccess($connection, $records, $pending, $jokerSource, $sceneId) : onlineRollFormulaWithMode($formula, $mode, null, 0, $d100RollUnder);
                    $rolled['criticalFailureAsFailure']=applicationRollCriticalFailureProtected($jokerSource,$pending['activity']['payload']??applicationDomainPayload($records,'activity'),$sceneId);
                    $generatedRolls[] = $rolled;
                    return $rolled;
                },
            ]
        );
    } catch (ApplicationComplexAbilityException $error) {
        rejectOnlineCommand($connection, $error->httpStatus, $error->getMessage(), $error->errorCode);
    }
    if($chargeTravel!==null){
        foreach($next['workflow']['steps']as$movement)if(!empty($movement['charge']['staged']))$next['stepStates'][$movement['id']]['movementLockUntil']=$now+1800;
        $source=applicationDomainPayload($records,onlineTokenDomainKey($sceneId,$current['sourceTokenId']));
        $opportunities=applicationMovementAvoidsOpportunity($activity['abilityExecutions']??[],$sceneId,$source['id'])?[]:applicationOpportunityRequests([...$map,'tokens'=>onlineSceneTokensForLights($connection,$records,$pending,$sceneId)],$source,$chargeTravel['from'],$chargeTravel['to'],[$chargeTravel['from'],$chargeTravel['to']],$sceneId,static fn($t)=>onlineTokenControllerIdFromRecords($connection,$records,$t),static function($t)use($records){$owner=applicationAbilityCastingOwner($t)['characterId'];return $owner!==''?(applicationDomainPayload($records,'character:'.$owner)['passiveAbilities']??$t['passiveAbilities']??null):($t['passiveAbilities']??null);});
        if($opportunities!==[]){$store=$pending['devices']['payload']??applicationDomainPayload($records,'devices',dsEmpty());if(count($store['pendingRolls'])+count($opportunities)>1000)rejectOnlineCommand($connection,429,'Traitez les réactions en attente.','opportunity_queue_full');$store['pendingRolls']=[...$store['pendingRolls'],...$opportunities];queueOnlineDomainUpsert($pending,$records,'devices',$store);}
        $trap=onlineDeviceSelectScene(onlineDeviceState($connection,$records,$pending),$sceneId);if(queueItemTrapPassages($trap,$source,$chargeTravel['from'],$chargeTravel['to'],[$chargeTravel['from'],$chargeTravel['to']],static fn($p)=>$p.'-'.randomToken(16)))queueOnlineDomainUpsert($pending,$records,'items',$trap['items']);
        foreach($chargeTravel['moves']as$move){$key=onlineTokenDomainKey($sceneId,$move['tokenId']);$token=applicationDomainPayload($records,$key);if($token!==[]){if(in_array($token['id'],$chargeTravel['dismountTokenIds']??[],true))unset($token['mountedOnTokenId'],$token['mountedAt']);queueOnlineDomainUpsert($pending,$records,$key,[...$token,...$move['to'],'_movedAt'=>$now]);}}
        appendApplicationChargeTravelCue($activity,$current,$chargeTravel);
    }
    $effectResult = null;
    $counterUse = null;
    if ($workflowAction === 'apply-guard' && ($currentStep['type'] ?? '') === 'guard') {
        if (($initiative['active'] ?? false) !== true || ($initiative['combatId'] ?? '') === '') {
            rejectOnlineCommand($connection, 409, 'La protection de prochaine attaque exige un combat actif.', 'complex_ability_guard_outside_combat');
        }
        $targetIds = $next['stepStates'][$currentStep['id']]['protectedTokenIds'] ?? [];
        $source = applicationDomainPayload($records, onlineTokenDomainKey($sceneId, $next['sourceTokenId'] ?? ''));
        $targetStep = null;
        foreach ($next['workflow']['steps'] as $candidate) if (($candidate['id'] ?? '') === ($currentStep['sourceStepId'] ?? '') && ($candidate['type'] ?? '') === 'targets') $targetStep = $candidate;
        $guards = array_values(array_filter(is_array($activity['nextAttackGuards'] ?? null) ? $activity['nextAttackGuards'] : [],
            static fn(mixed $guard): bool => is_array($guard) && (($guard['sceneId'] ?? '') !== $sceneId
                || ($guard['combatId'] ?? '') === ($initiative['combatId'] ?? ''))));
        if (count($guards) + count($targetIds) > 60) rejectOnlineCommand($connection, 409, 'Trop de protections attendent une attaque.', 'complex_ability_guard_capacity');
        foreach ($targetIds as $tokenId) {
            $target = applicationDomainPayload($records, onlineTokenDomainKey($sceneId, $tokenId));
            if ($target === [] || !onlineTokenOnActiveLayer($target, $map)
                || (($currentStep['scope'] ?? '') === 'targets' && (!is_array($targetStep)
                    || !applicationComplexAbilityTargetInRange($source, $target, $map, (int) $targetStep['rangeCells'])))) {
                rejectOnlineCommand($connection, 409, 'Une cible protégée a quitté sa portée ou son niveau.', 'complex_ability_guard_target_changed');
            }
            $guards[] = ['id' => 'guard-' . randomToken(12), 'sceneId' => $sceneId,
                'targetTokenId' => (string) $target['id'], 'combatId' => (string) $initiative['combatId'],
                'percent' => $currentStep['percent'], 'sourceExecutionId' => $next['id'], 'lifecycle' => 'next-attack'];
        }
        $activity['nextAttackGuards'] = $guards;
        queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
        $effectResult = ['kind' => 'guard', 'targetCount' => count($targetIds), 'percent' => $currentStep['percent']];
    }
    if ($workflowAction === 'counter-spend' && ($currentStep['type'] ?? '') === 'counter') {
        $use = $next['stepStates'][$currentStep['id']]['lastUse'] ?? [];
        $effect = is_array($use['effect'] ?? null) ? $use['effect'] : [];
        if (in_array($effect['kind'] ?? '', ['damage', 'guard'], true)) {
            $targetKey = onlineTokenDomainKey($sceneId, $use['targetTokenId'] ?? '');
            $target = applicationDomainPayload($records, $targetKey);
            if ($target === [] || !onlineTokenOnActiveLayer($target, $map)) {
                rejectOnlineCommand($connection, 409, 'La cible de cet effet a changé de niveau ou n’existe plus.', 'complex_ability_effect_target_changed');
            }
            if ($effect['kind'] === 'guard') {
                if (($initiative['active'] ?? false) !== true || ($initiative['combatId'] ?? '') === '') {
                    rejectOnlineCommand($connection, 409, 'La protection de la prochaine attaque exige un combat actif.', 'complex_ability_guard_outside_combat');
                }
                $guards = array_values(array_filter(is_array($activity['nextAttackGuards'] ?? null) ? $activity['nextAttackGuards'] : [],
                    static fn(mixed $guard): bool => is_array($guard) && (($guard['sceneId'] ?? '') !== $sceneId
                        || ($guard['combatId'] ?? '') === ($initiative['combatId'] ?? '')||($guard['lifecycle']??'')==='status')));
                if (count($guards) >= 60) rejectOnlineCommand($connection, 409, 'Trop de protections attendent déjà une attaque.', 'complex_ability_guard_capacity');
                $stackable=applicationConfiguredStackableEffect((string)$next['abilityId'],'guard');
                $guards[] = $stackable!==null?applicationMakeStackableEffect($stackable,$sceneId,(string)$target['id'],'guard-'.randomToken(12)):['id' => 'guard-' . randomToken(12), 'sceneId' => $sceneId,
                    'targetTokenId' => (string) $target['id'], 'combatId' => (string) ($initiative['combatId'] ?? ''),
                    'percent' => $effect['percent'], 'sourceExecutionId' => $next['id'], 'lifecycle' => ($effect['persistAfterEnd'] ?? false) ? 'next-attack' : 'execution',
                    'stackGroup' => ($effect['stackable'] ?? false) ? (string) $next['abilityId'] : '', 'trigger' => $effect['trigger'] ?? 'attack', 'damageTypes' => $effect['damageTypes'], 'armorStacking' => $effect['armorStacking']];
                $activity['nextAttackGuards'] = $guards;
                queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
                $effectResult = ['kind' => 'guard', 'targetTokenId' => $target['id'], 'targetName' => $target['name'] ?? 'Cible',
                    'percent' => $stackable!==null?applicationStackableEffects()[$stackable]['perStack']:$effect['percent']];
                if(isset(applicationSpellVisualBindings()[$next['abilityId']]['variants']['guard'])){
                    $visualSource=applicationDomainPayload($records,onlineTokenDomainKey($sceneId,$next['sourceTokenId']));
                    $visualMap=[...$map,'tokens'=>onlineSceneTokensForLights($connection,$records,$pending,$sceneId)];
                    $spell=applicationTrustedSpellVisual(['id'=>$next['abilityId']],['sceneId'=>$sceneId,'map'=>$visualMap,'source'=>$visualSource,'targetPoint'=>$target,'variantId'=>'guard']);
                    if($spell!==null){appendApplicationAbilityCueEvent($activity,['version'=>1,'spell'=>$spell],$sceneId,$next['layerId'],$next['sourceTokenId'],!empty($visualSource['hidden'])?'gm':'public');queueOnlineDomainUpsert($pending,$records,'activity',$activity);}
                }
            } else {
                $counterUse = $use;
            }
        }
    }
    if (($current['status'] ?? '') === 'active' && ($next['status'] ?? '') === 'completed' && ($next['endedByFailure'] ?? false) !== true) {
        $hasNativeAttacks = ($current['workflow']['version'] ?? 1) >= 7
            && array_filter($current['workflow']['steps'], static fn(array $step): bool => in_array($step['type'], ['attack-chain', 'allocated-attacks'], true)) !== [];
        if (!$hasNativeAttacks) applyOnlineAttackConditions($connection, $records, $pending, [
            'status' => 'applied', 'sceneId' => $sceneId, 'targetTokenId' => $next['sourceTokenId'], 'onHitConditions' => $next['onHitConditions'] ?? [],
        ]);
        appendApplicationAbilityCueEvent($activity, $next['completionCue'] ?? null, $sceneId,
            (string) ($next['layerId'] ?? 'ground'), (string) ($next['sourceTokenId'] ?? ''),
            (applicationDomainPayload($records, onlineTokenDomainKey($sceneId, $next['sourceTokenId'] ?? ''))['hidden'] ?? false) ? 'gm' : 'public');
    }
    $activity = $pending['activity']['payload'] ?? $activity;
    if($workflowAction==='confirm-movement'&&($currentStep['type']??'')==='movement'){
        $movementSource=applicationDomainPayload($records,onlineTokenDomainKey($sceneId,$next['sourceTokenId']??''));
        $movementMap=[...$map,'tokens'=>onlineSceneTokensForLights($connection,$records,$pending,$sceneId)];
        $movementSpell=applicationTrustedMovementSpellVisual($next,['stepId'=>$currentStep['id'],'source'=>$movementSource,'map'=>$movementMap]);
        if($movementSpell!==null)appendApplicationAbilityCueEvent($activity,normalizeApplicationAbilityCompletionCue(['version'=>1,'spell'=>$movementSpell]),$sceneId,$next['layerId'],$next['sourceTokenId'],!empty($movementSource['hidden'])?'gm':'public');
    }
    if (($current['status'] ?? '') === 'active' && ($next['status'] ?? '') !== 'active') {
        $activity['nextAttackGuards'] = array_values(array_filter(
            is_array($activity['nextAttackGuards'] ?? null) ? $activity['nextAttackGuards'] : [],
            static fn(mixed $guard): bool => is_array($guard) && (($guard['sourceExecutionId'] ?? '') !== $next['id']
                || ($guard['lifecycle'] ?? '') === 'next-attack')
        ));
    }
    $activity['abilityExecutions'][$executionIndex] = $next;
    applicationSettleChainFailureTimers($activity,$sceneId,$initiative,$now);
    $next=$activity['abilityExecutions'][$executionIndex];
    $activity['abilityExecutions'] = trimApplicationComplexAbilityExecutions($activity['abilityExecutions']);
    queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
    if (is_array($counterUse)) {
        $counterAttack = onlineCounterDamageAttack($connection, $records, $pending, $identity, $next, $counterUse, $requestId, $isGm);
        $effectResult = ['kind' => 'damage', 'targetTokenId' => $counterAttack['targetTokenId'], 'targetName' => $counterAttack['targetName'],
            'attackId' => $counterAttack['id'], 'status' => $counterAttack['status'], 'appliedDamage' => (int) ($counterAttack['appliedDamage'] ?? 0)];
        foreach ($pending['activity']['payload']['abilityExecutions'] ?? [] as $revised) if (($revised['id'] ?? '') === $next['id']) { $next = $revised; break; }
    }
    $source = applicationDomainPayload($records, onlineTokenDomainKey($sceneId, $next['sourceTokenId'] ?? ''));
    $responseRolls = [];
    $stepTitle = (string) ($current['workflow']['steps'][$current['currentStepIndex'] ?? -1]['title'] ?? 'Étape');
    $rollSubject = in_array($workflowAction, ['awareness-roll', 'defense-roll'], true) && $participantTokenId !== ''
        ? applicationComplexAbilityTokenById($tokens, $participantTokenId) : $source;
    $rollSubjectName = in_array($workflowAction, ['awareness-roll', 'defense-roll'], true) && is_array($rollSubject)
        ? (string) ($rollSubject['name'] ?? 'Cible') : $next['sourceName'];
    $newStep = $next['workflow']['steps'][$next['currentStepIndex'] ?? -1] ?? null;
    $startAwareness = $workflowAction === 'select-targets' && ($newStep['type'] ?? '') === 'allocated-attacks'
        && ($newStep['awarenessMode'] ?? '') === 'once';
    $batch = ($currentStep['resolutionMode'] ?? '') === 'batch' && $workflowAction === 'prepare-chain';
    $gateOffset = count($currentStepState['rolls'] ?? []);
    foreach ($generatedRolls as $rollIndex => $rolled) {
        $subject = $startAwareness
            ? applicationComplexAbilityTokenById($tokens, $next['stepStates'][$newStep['id']]['targets'][$rollIndex]['tokenId'] ?? '')
            : $rollSubject;
        $subjectName = $startAwareness ? (string) ($subject['name'] ?? 'Cible') : $rollSubjectName;
        $gate = $batch ? $next['stepStates'][$currentStep['id']]['rolls'][$gateOffset + $rollIndex] : null;
        $roll = onlineRollEntry($identity, $rolled, $next['abilityName'] . ' · ' . ($startAwareness ? 'Vigilance' : $stepTitle), $subjectName, $gate['outcomeDetails'] ?? (($rolled['joker'] ?? false) ? applicationSpecialSuccessOutcome() : null));
        if (($rolled['joker'] ?? false) === true && !$batch) $roll['mapEvent'] = ['kind' => 'roll', 'label' => 'Réussite spéciale · Joker', 'value' => 1,
            'sceneId' => $sceneId, 'layerId' => $next['layerId'], 'tokenId' => $participantTokenId ?: $next['sourceTokenId'],
            'anchorTokenId' => $participantTokenId ?: $next['sourceTokenId'], 'diceAppearance' => onlineDiceAppearance($subject ?? $source, !$isGm), 'tone' => 'success'];
        if ($batch) {
            $next['stepStates'][$currentStep['id']]['rolls'][$gateOffset + $rollIndex]['rollId'] = $roll['id'];
            $roll['diceAppearance'] = onlineDiceAppearance($source, trim((string) ($source['controllerPlayerId'] ?? '')) !== '');
            $roll['mapEvent'] = ['kind' => 'roll', 'label' => 'Jet ATK', 'value' => $gate['total'],
                'sceneId' => $sceneId, 'layerId' => $next['layerId'], 'tokenId' => $next['sourceTokenId'],
                'sourceTokenId' => $next['sourceTokenId'], 'anchorTokenId' => $next['sourceTokenId'],
                'targetTokenId' => $next['stepStates'][$currentStep['id']]['plan']['targetTokenId'],
                'diceAppearance' => $roll['diceAppearance'], 'tone' => $gate['outcome']];
            onlineRecordCharacterLuckD100($connection, $records, $pending, $identity, $next['characterId'], $rolled);
        }
        $responseRolls[] = !is_array($subject) || $subject === [] ? $roll : onlineAbilityRollVisibility($roll, $subject, $identity,
            $isGm && onlineGmTokenVisibleToPlayers($connection, $records, $table, $subject, $sceneId), $sceneId);
        onlineAppendAbilityEffectRoll($records, $pending, $responseRolls[count($responseRolls) - 1]);
    }
    if ($batch) {
        $latestActivity = $pending['activity']['payload'] ?? $activity;
        foreach ($latestActivity['abilityExecutions'] as &$storedExecution) {
            if (($storedExecution['id'] ?? '') === $next['id']) $storedExecution = $next;
        }
        unset($storedExecution);
        queueOnlineDomainUpsert($pending, $records, 'activity', $latestActivity);
    }
    $responseRolls = [...$responseRolls, ...onlineFailedChainEchoes($connection, $records, $pending, $current, $next, $workflowAction, $identity, $isGm)];
    onlineAppendAbilityRollActions($connection, $records, $pending, $identity, $sceneId, $responseRolls);
    $actionEntry = onlineAppendPlayerAction($connection, $records, $pending, $identity, $sceneId, [
        'kind' => 'ability-workflow', 'characterName' => $next['sourceName'],
        'summary' => $next['status'] === 'cancelled' ? 'Arrête ' . $next['abilityName']
            : ($next['status'] === 'completed' ? 'Termine ' . $next['abilityName'] : 'Fait progresser ' . $next['abilityName']),
        'detail' => $next['status'] === 'active'
            ? 'Étape ' . ($next['currentStepIndex'] + 1) . '/' . count($next['workflow']['steps']) : $next['status'],
    ]);
    $projected = publicApplicationComplexAbilityExecution($next, $accountId, $isGm, $tokens, $participantTokenId);
    if (!is_array($projected)) rejectOnlineCommand($connection, 403, 'Cette compétence ne vous est plus accessible.', 'complex_ability_forbidden');
    $result = ['effect' => 'complex', 'execution' => $projected, 'rolls' => $responseRolls,
        'chargeEffect' => $effectResult === null ? null : array_diff_key($effectResult, ['health' => true])];
    onlineStorePersistentCommandReceipt($records, $pending, $receiptContext, 'ability-workflow', $requestId,
        $accountId, $actionEntry['id'], $executionId, $signature, $result);
    return [...$result, 'deduplicated' => false];
}
