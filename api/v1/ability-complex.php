<?php
declare(strict_types=1);

function onlineComplexAbilityRequestSignature(array $arguments): string
{
    $action = ($arguments['action'] ?? '') === 'start' ? 'start' : 'command';
    return onlineCommandRequestSignature('ability-complex', $action === 'start' ? [
        'action' => 'start',
        'sceneId' => (string) ($arguments['sceneId'] ?? ''),
        'layerId' => (string) ($arguments['layerId'] ?? 'ground'),
        'sourceTokenId' => (string) ($arguments['sourceTokenId'] ?? ''),
        'abilityId' => (string) ($arguments['abilityId'] ?? ''),
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
                return [...$execution, 'controllerAccountId' => '', 'controllerName' => '', 'ownedByYou' => false, 'participantOnly' => true,
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
        $initiative = applicationDomainPayload($records, 'initiative:' . $sceneId);
        $persistent = count(array_filter($ability['workflow']['steps'] ?? [], static fn(mixed $step): bool => is_array($step)
            && ($step['type'] ?? '') === 'counter' && ($step['combatPersistent'] ?? false) === true)) > 0;
        $combatId = trim((string) ($initiative['combatId'] ?? ''));
        if ($persistent && (($initiative['active'] ?? false) !== true || $combatId === '')) {
            rejectOnlineCommand($connection, 409, 'Ce sort persistant exige un combat actif.', 'complex_ability_combat_required');
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
        $plan = $continuation['plan'] ?? onlinePrepareAbilityCasting(
            $connection, $ability, $rules, $sceneId,
            applicationDomainPayload($records, 'initiative:' . $sceneId), $activity
        );
        $sourceVisibleToPlayers = $isGm && onlineGmTokenVisibleToPlayers($connection, $records, $table, $source, $sceneId);
        $cast = $continuation['cast'] ?? onlineAbilityCastingRoll($plan, $rules, $identity, $arguments, onlineTokenLayerId($source, $map), $character, $sourceVisibleToPlayers, (string) ($source['id'] ?? ''));
        if ($continuation === null && ($cast['outcome']['requiresGmValidation'] ?? false) === true) {
            return onlineDeferAbilityCasting($connection, $records, $pending, 'ability.complex', $identity, $arguments, $isGm, $ability, $rules, $plan, $cast, $signature, [], $receiptContext);
        }
        $cast = onlineCommitAbilityCasting($connection, $records, $pending, $plan, $cast, $rules, $identity, true, $continuation === null);
        $execution = null;
        if (($cast['success'] ?? false) === true) {
            try {
                $execution = createApplicationComplexAbilityExecution([
                    'id' => 'execution-' . randomToken(12), 'requestId' => $requestId,
                    'sceneId' => $sceneId, 'layerId' => onlineTokenLayerId($source, $map),
                    'sourceTokenId' => $source['id'] ?? '', 'sourceName' => $source['name'] ?? 'Personnage',
                    'characterId' => $owner['characterId'] ?? '',
                    'controllerAccountId' => $controllerId !== '' ? $controllerId : $accountId,
                    'controllerName' => $identity['display_name'] ?? '', 'ability' => $ability, 'now' => $now,
                    'combatId' => $persistent ? $combatId : '',
                ]);
            } catch (ApplicationComplexAbilityException $error) {
                rejectOnlineCommand($connection, $error->httpStatus, $error->getMessage(), $error->errorCode);
            }
            $currentActivity = is_array($pending['activity']['payload'] ?? null) ? $pending['activity']['payload'] : $activity;
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
                'tokens' => $tokens, 'now' => $now, 'attack' => $confirmedAttack,
                'combatActive' => ($initiative['active'] ?? false) === true
                    && (($current['combatId'] ?? '') === '' || ($current['combatId'] ?? '') === ($initiative['combatId'] ?? '')),
                'turnKey' => $sceneId . ':' . (string) ($initiative['combatId'] ?? '') . ':' . (string) ($initiative['turnSerial'] ?? 0),
                'roll' => static function (string $formula) use (&$generatedRolls): array {
                    $rolled = onlineRollFormulaWithMode($formula, 'normal');
                    $generatedRolls[] = $rolled;
                    return $rolled;
                },
            ]
        );
    } catch (ApplicationComplexAbilityException $error) {
        rejectOnlineCommand($connection, $error->httpStatus, $error->getMessage(), $error->errorCode);
    }
    if (($current['status'] ?? '') === 'active' && ($next['status'] ?? '') === 'completed' && ($next['endedByFailure'] ?? false) !== true) {
        applyOnlineAttackConditions($connection, $records, $pending, [
            'status' => 'applied', 'sceneId' => $sceneId, 'targetTokenId' => $next['sourceTokenId'], 'onHitConditions' => $next['onHitConditions'] ?? [],
        ]);
        appendApplicationAbilityCueEvent($activity, $next['completionCue'] ?? null, $sceneId,
            (string) ($next['layerId'] ?? 'ground'), (string) ($next['sourceTokenId'] ?? ''),
            (applicationDomainPayload($records, onlineTokenDomainKey($sceneId, $next['sourceTokenId'] ?? ''))['hidden'] ?? false) ? 'gm' : 'public');
    }
    $activity['abilityExecutions'][$executionIndex] = $next;
    $activity['abilityExecutions'] = trimApplicationComplexAbilityExecutions($activity['abilityExecutions']);
    queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
    $source = applicationDomainPayload($records, onlineTokenDomainKey($sceneId, $next['sourceTokenId'] ?? ''));
    $responseRolls = [];
    $stepTitle = (string) ($current['workflow']['steps'][$current['currentStepIndex'] ?? -1]['title'] ?? 'Étape');
    $rollSubject = $workflowAction === 'awareness-roll' && $participantTokenId !== ''
        ? applicationComplexAbilityTokenById($tokens, $participantTokenId) : $source;
    $rollSubjectName = $workflowAction === 'awareness-roll' && is_array($rollSubject)
        ? (string) ($rollSubject['name'] ?? 'Cible') : $next['sourceName'];
    foreach ($generatedRolls as $rolled) {
        $roll = onlineRollEntry($identity, $rolled, $next['abilityName'] . ' · ' . $stepTitle, $rollSubjectName);
        $responseRolls[] = !is_array($rollSubject) || $rollSubject === [] ? $roll : onlineAbilityRollVisibility($roll, $rollSubject, $identity,
            $isGm && onlineGmTokenVisibleToPlayers($connection, $records, $table, $rollSubject, $sceneId), $sceneId);
        onlineAppendAbilityEffectRoll($records, $pending, $responseRolls[count($responseRolls) - 1]);
    }
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
    $result = ['effect' => 'complex', 'execution' => $projected, 'rolls' => $responseRolls];
    onlineStorePersistentCommandReceipt($records, $pending, $receiptContext, 'ability-workflow', $requestId,
        $accountId, $actionEntry['id'], $executionId, $signature, $result);
    return [...$result, 'deduplicated' => false];
}
