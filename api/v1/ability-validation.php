<?php
declare(strict_types=1);

// Trusted continuations live only in the authoritative activity domain. Client
// commands supply a validation identifier and decision, never a saved cast.
function onlineAssertAbilityReceiptVisibility(PDO $connection, array $result, bool $isGm): void {
    if ($isGm) return;
    $rolls = [...($result['rolls'] ?? []), $result['cast']['roll'] ?? null, $result['roll'] ?? null, $result['castRoll'] ?? null, $result['effectRoll'] ?? null];
    foreach ($rolls as $roll) if (is_array($roll) && ($roll['visibility'] ?? '') === 'gm') {
        rejectOnlineCommand($connection, 403, 'Ce reçu privé nécessite le mode MJ.', 'ability_receipt_forbidden');
    }
}

function publicOnlineAbilityValidation(array $entry, string $accountId = ''): array {
    $public = array_intersect_key($entry, array_flip(['id', 'requestId', 'status', 'route', 'sceneId', 'layerId', 'sourceTokenId', 'characterId', 'targetTokenId', 'abilityId', 'abilityName', 'sourceName', 'effect', 'cast', 'createdAt', 'resolvedAt']));
    $public['ownedByYou'] = $accountId !== '' && in_array($accountId, [$entry['_private']['identity']['id'] ?? '', $entry['_private']['controllerAccountId'] ?? ''], true);
    return $public;
}

function onlineAssertAbilityValidationCapacity(PDO $connection, array $activity): void {
    if (count($activity['pendingAbilityCasts'] ?? []) >= 30) rejectOnlineCommand($connection, 429, 'Le MJ doit traiter une validation de capacité avant une nouvelle tentative.', 'ability_validation_capacity');
    $liveReceipts = array_filter($activity['resourceReceipts'] ?? [], static fn(array $receipt): bool => ($receipt['expiresAt'] ?? 0) > (int) floor(microtime(true) * 1000));
    if (count($liveReceipts) + count($activity['pendingAbilityCasts'] ?? []) + 2 > XAR_RESOURCE_RECEIPT_MAXIMUM) rejectOnlineCommand($connection, 429, 'Le journal doit garder une place pour la décision du MJ.', 'ability_validation_receipt_capacity');
}

function onlineAssertAbilityValidationAvailable(PDO $connection, array $activity, array $ability, array $source, bool $forAttack = false): void {
    $owner = applicationAbilityCastingOwner($source);
    foreach($activity['pendingAttacks']??[]as$entry)if(!empty($entry['areaGate']['deferred'])&&empty($entry['areaGate']['resolving'])&&($entry['attackId']??'')===($ability['id']??'')&&($entry['areaGate']['sourceParty']['unitId']??'')===unitIdForToken($source))rejectOnlineCommand($connection,409,'Cette action attend déjà la décision du MJ.','ability_defense_selection_pending');
    foreach ($activity['pendingAbilityCasts'] ?? [] as $entry) {
        if (($entry['abilityId'] ?? '') !== ($ability['id'] ?? '')) continue;
        if ($owner['characterId'] !== '' ? ($entry['characterId'] ?? '') === $owner['characterId']
            : (($entry['characterId'] ?? '') === '' && ($entry['sourceTokenId'] ?? '') === $owner['tokenId'])) {
            rejectOnlineCommand($connection, 409, 'Cette capacité attend déjà la validation du MJ.', 'ability_validation_pending');
        }
    }
    foreach ($activity['pendingAttacks'] ?? [] as $entry) {
        $deferred = $entry['deferredAbilityCast'] ?? null;
        if (!is_array($deferred) || ($entry['attackId'] ?? '') !== ($ability['id'] ?? '')) continue;
        if ($owner['characterId'] !== '' ? ($deferred['characterId'] ?? '') === $owner['characterId']
            : (($deferred['characterId'] ?? '') === '' && ($entry['sourceTokenId'] ?? '') === $owner['tokenId'])) {
            rejectOnlineCommand($connection, 409, 'Cette capacité attend déjà la validation du MJ.', 'ability_validation_pending');
        }
    }
    // Reserve both the attempt receipt and its terminal decision before any
    // random outcome can distinguish ordinary and remarkable attempts.
    if (!$forAttack && (trim((string) ($ability['castingStatId'] ?? '')) !== '' || !empty($ability['manualD100']) || !empty($ability['visualD75']))) {
        onlineAssertAbilityValidationCapacity($connection, $activity);
    }

}

function onlineResumedAbilityPlan(array $saved, array $initiative, array $activity): array {
    $saved['usedRound'] = max(1, (int) ($initiative['round'] ?? 1));
    if(($initiative['active']??false)!==true){$saved['cooldownRounds']=0;$saved['reducedFailureEnabled']=false;}
    $saved['turnKey'] = applicationAbilityTurnKey($initiative);
    $saved['enhancementInitiative'] = ['active' => ($initiative['active'] ?? false) === true,
        'combatId' => ($initiative['active'] ?? false) === true ? ($initiative['combatId'] ?? '') : '', 'turnSerial' => (int) ($initiative['turnSerial'] ?? 0)];
    $saved['useCount'] = 1;
    foreach ($activity['actionTimers'] ?? [] as $timer) {
        if (($timer['sceneId'] ?? '') === $saved['sceneId'] && ($timer['abilityId'] ?? '') === $saved['abilityId']
            && ($timer['characterId'] ?? '') === $saved['characterId'] && ($timer['tokenId'] ?? '') === $saved['tokenId']
            && $saved['turnKey'] !== '' && ($timer['turnKey'] ?? '') === $saved['turnKey']) {
            $saved['useCount'] = (int) ($timer['useCount'] ?? 0) + 1;
        }
    }
    return $saved;
}

function onlineDeferAbilityCasting(PDO $connection, array &$records, array &$pending, string $route, array $identity, array $body, bool $isGm, array $ability, array $source, array $plan, array $cast, string $signature, array $target = [], array $receiptContext = []): array {
    $activity = $pending['activity']['payload'] ?? applicationDomainPayload($records, 'activity');
    onlineAssertAbilityValidationCapacity($connection, $activity);
    $visualD75 = ($cast['visualD75'] ?? false) === true;
    $cast = onlineCommitAbilityCasting($connection, $records, $pending, $plan, $cast, $source, $identity, $visualD75, true, false);
    $activity = $pending['activity']['payload'] ?? $activity;
    $layerId = $cast['roll']['mapEvent']['layerId'] ?? ($body['layerId'] ?? 'ground');
    if ($visualD75) {
        $layerId = onlineTokenLayerId($source, applicationDomainPayload($records, 'map:' . $plan['sceneId']));
        $cue = applicationPrayerRollCue($cast['roll']['total']);
        $visibility = ($source['hidden'] ?? false) || ($cast['roll']['visibility'] ?? '') === 'gm' ? 'gm' : 'public';
        $cast['completionCue'] = $cue;
        $cast['completionCueEventId'] = appendApplicationAbilityCueEvent($activity, $cue, $plan['sceneId'], $layerId, $source['id'], $visibility);
    }
    $actor = array_intersect_key($identity, array_flip(['id', 'display_name', 'permanent_role', 'effective_mode']));
    $entry = [
        'id' => 'validation-' . randomToken(16), 'requestId' => $body['requestId'], 'status' => 'pending', 'route' => $route,
        'sceneId' => $plan['sceneId'], 'layerId' => $layerId,
        'sourceTokenId' => $source['id'] ?? '', 'characterId' => $plan['characterId'], 'targetTokenId' => $route === 'ability.use' ? ($target['id'] ?? '') : '',
        'abilityId' => $ability['id'], 'abilityName' => $ability['name'], 'sourceName' => $source['name'] ?? 'Personnage',
        'effect' => $ability['effect'] ?? 'damage', 'cast' => $cast, 'createdAt' => gmdate('c'),
        '_private' => ['identity' => $actor, 'isGm' => $isGm, 'body' => $body, 'ability' => $ability, 'plan' => $plan,
            'sourceCharacterId' => $source['characterId'] ?? '', 'targetCharacterId' => $target['characterId'] ?? '',
            'controllerAccountId' => !empty($source['id']) ? onlineTokenControllerIdFromRecords($connection, $records, $source)
                : (applicationDomainPayload($records, 'character:' . $plan['characterId'])['ownerPlayerId'] ?? '')],
    ];
    $activity['pendingAbilityCasts'][] = $entry;
    queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
    if ($visualD75) onlineAppendAbilityRollActions($connection, $records, $pending, $identity, $plan['sceneId'], [$cast['roll']]);
    $action = onlineAppendPlayerAction($connection, $records, $pending, $identity, $plan['sceneId'], [
        'kind' => 'ability', 'characterName' => $entry['sourceName'], 'summary' => $ability['name'] . ($visualD75 ? ' · effet visuel à choisir par le MJ' : ' · validation MJ requise'),
        'detail' => $cast['manaSpent'] . ' mana · ' . $cast['hpSpent'] . ' PV · +' . $cast['fatigueGained'] . ' fatigue',
    ]);
    $result = ['pendingValidation' => true, 'validation' => publicOnlineAbilityValidation($entry, (string) $identity['id']),
        'effect' => $entry['effect'], 'cast' => $cast, 'castSucceeded' => $cast['success'], ...onlineAbilityRollBundle($cast)];
    if ($route === 'ability.complex') $result['execution'] = null;
    if ($route === 'ability.complex') onlineStorePersistentCommandReceipt($records, $pending, $receiptContext, 'ability-workflow', $body['requestId'], $identity['id'], $action['id'], $source['id'] ?? '', $signature, $result);
    else onlineStoreAbilityReceipt($records, $pending, $body['requestId'], $identity['id'], $signature, $result, $action['id']);
    $activity = $pending['activity']['payload'];
    foreach ($activity['resourceReceipts'] as &$receipt) if (($receipt['requestId'] ?? '') === $body['requestId']) $receipt['expiresAt'] = 9007199254740991;
    unset($receipt);
    queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
    return [...$result, 'deduplicated' => false];
}

function onlineValidateAbilityContinuation(PDO $connection, array $records, array $table, array $entry): array {
    $private = $entry['_private']; $sceneId = $entry['sceneId'];
    $map = applicationDomainPayload($records, 'map:' . $sceneId);
    if (($sceneId !== '' && applicationDomainPayload($records, 'scene:' . $sceneId) === [])
        || (!($private['isGm'] ?? false) && $sceneId !== onlineActiveSceneId($table))
        || (!empty($entry['sourceTokenId']) && onlineTokenLayerId([], $map) !== $entry['layerId'])) {
        rejectOnlineCommand($connection, 409, 'La scène ou le niveau du lancement a changé.', 'ability_validation_context_changed');
    }
    if (!empty($entry['sourceTokenId'])) {
        $source = applicationDomainPayload($records, onlineTokenDomainKey($sceneId, $entry['sourceTokenId']));
        $owner = applicationAbilityCastingOwner($source);
        $controller = onlineTokenControllerIdFromRecords($connection, $records, $source);
        if ($source === [] || !onlineTokenOnActiveLayer($source, $map) || ($source['characterId'] ?? '') !== ($private['sourceCharacterId'] ?? '') || $owner['characterId'] !== $entry['characterId']) {
            rejectOnlineCommand($connection, 409, 'Le porteur ou sa fiche a changé.', 'ability_validation_context_changed');
        }
        $character = $owner['characterId'] !== '' ? applicationDomainPayload($records, 'character:' . $owner['characterId']) : [];
        $rules = $character !== [] ? synchronizeOnlineCharacterToken($source, $character) : $source;
    } else {
        $character = applicationDomainPayload($records, 'character:' . $entry['characterId']);
        $controller = $character['ownerPlayerId'] ?? '';
        $rules = synchronizeOnlineCharacterToken(['characterId' => $entry['characterId']], $character);
        if ($character === []) rejectOnlineCommand($connection, 409, 'La fiche de lancement n’existe plus.', 'ability_validation_context_changed');
    }
    $abilities = normalizeOnlineAbilities($rules['abilities'] ?? []);
    $index = findEntryIndex($abilities, $entry['abilityId']);
    if ($controller !== ($private['controllerAccountId'] ?? '') || $index < 0 || $abilities[$index] != $private['ability']) {
        rejectOnlineCommand($connection, 409, 'Le propriétaire ou la capacité a changé.', 'ability_validation_context_changed');
    }
    if (!empty($entry['targetTokenId'])) {
        $target = applicationDomainPayload($records, onlineTokenDomainKey($sceneId, $entry['targetTokenId']));
        if ($target === [] || !onlineTokenOnActiveLayer($target, $map) || ($target['characterId'] ?? '') !== ($private['targetCharacterId'] ?? '')) {
            rejectOnlineCommand($connection, 409, 'La cible du lancement a changé.', 'ability_validation_context_changed');
        }
    }
    return $rules;
}

function onlineRefundAbilityValidation(PDO $connection, array &$records, array &$pending, array $entry, array $identity, bool $retireMissingWallet = false): array {
    $cast = $entry['cast']; $plan = $entry['_private']['plan'];
    $refunded = ['mana' => 0, 'hp' => 0, 'fatigue' => 0];
    $characterId = (string) ($entry['characterId'] ?? '');
    $key = $characterId !== '' ? 'character:' . $characterId : onlineTokenDomainKey($entry['sceneId'], $entry['sourceTokenId']);
    $owner = $pending[$key]['payload'] ?? applicationDomainPayload($records, $key);
    $mana = max(0, onlineResourceNumber($cast['manaSpent'] ?? 0)); $hp = max(0, (int) ($cast['hpSpent'] ?? 0));
    $fatigue = max(0, onlineResourceNumber($cast['fatigueGained'] ?? 0));
    $deviceId = (string) ($plan['manaProviderDeviceId'] ?? '');
    $originalCharacter = $entry['_private']['sourceCharacterId'] ?? null;
    $ownerMissing = $owner === [] || ($characterId === '' && (unitUsesCharacterSheet($owner)
        || $originalCharacter !== null && ($owner['characterId'] ?? '') !== $originalCharacter));
    if (!$retireMissingWallet && ($hp || $fatigue || ($mana && $deviceId === '')) && $ownerMissing) {
        rejectOnlineCommand($connection, 409, 'Le porteur qui a payé cette tentative n’existe plus ou a changé de fiche.', 'ability_refund_source_changed');
    }
    $unavailable = [];
    foreach (['mana' => $mana, 'hp' => $hp] as $resource => $amount) {
        if ($amount === 0) continue;
        if ($resource === 'mana' && $deviceId !== '') {
            $store = $pending['devices']['payload'] ?? applicationDomainPayload($records,'devices');
            if ($retireMissingWallet && dsIndex($store['devices'] ?? [],$deviceId) < 0) {
                $unavailable[$resource] = $amount;
                continue;
            }
            onlineDeviceRefund($connection, $records, $pending, ['kind' => 'battery', 'deviceId' => $deviceId, 'value' => $amount]);
            $refunded['mana'] = $amount;
        } elseif ($ownerMissing) {
            $unavailable[$resource] = $amount;
        } else {
            $adjusted = applyOnlineTokenResourceAdjustment($connection, $records, $pending, $entry['sceneId'], $entry['sourceTokenId'],
                $resource, $amount, (string) $identity['id'], true, $characterId, true);
            $refunded[$resource] = $adjusted['appliedDelta'];
        }
    }
    if ($ownerMissing) {
        if ($fatigue > 0) $unavailable['fatigue'] = $fatigue;
    } else {
        $refunded['fatigue'] = onlineRefundAbilityFatigue($connection, $records, $pending,
            (string) $entry['sceneId'], (string) $entry['sourceTokenId'], $characterId, $fatigue,(string)($cast['deferredFatigueEffectId']??''));
    }
    return [...$refunded,...($unavailable === [] ? [] : ['unavailable'=>$unavailable])];
}

function onlineResolveAbilityCasting(PDO $connection, array &$records, array &$pending, array $table, array $identity, array $arguments, bool $isGm): array {
    if (!$isGm) rejectOnlineCommand($connection, 403, 'La validation des capacités est réservée au MJ.', 'gm_required');
    $requestId = (string) ($arguments['requestId'] ?? ''); $validationId = (string) ($arguments['validationId'] ?? '');
    $decision = $arguments['decision'] ?? '';
    if (preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $requestId) !== 1 || !in_array($decision, ['approve', 'reject'], true)) rejectOnlineCommand($connection, 400, 'Référence ou décision de validation invalide.', 'invalid_ability_validation_request');
    if ($decision === 'approve' && ($arguments['confirmed'] ?? false) !== true) rejectOnlineCommand($connection, 400, 'Confirmez explicitement la décision.', 'ability_validation_confirmation_required');
    $visualChoice = $arguments['visualChoice'] ?? null;
    $signature = json_encode([$validationId, $decision, ($arguments['confirmed'] ?? false) === true,
        ...(isset($arguments['outcomeCode']) ? [$arguments['outcomeCode']] : []),
        ...($visualChoice !== null ? [['visualChoice' => $visualChoice]] : [])], JSON_THROW_ON_ERROR);
    $context = onlinePersistentCommandReceipt($connection, $records, 'ability-validation', $requestId, $identity['id'], $signature, 'ability_validation');
    if (is_array($context['receipt'] ?? null)) return [...$context['receipt']['result'], 'deduplicated' => true];
    $records = array_replace($records, applicationDomainRecords($connection));
    $activity = applicationDomainPayload($records, 'activity');
    $entries = array_values($activity['pendingAbilityCasts'] ?? []); $index = findEntryIndex($entries, $validationId);
    if ($index < 0) rejectOnlineCommand($connection, 409, 'Ce lancement n’est plus en attente.', 'ability_validation_missing');
    $entry = $entries[$index]; $private = $entry['_private'];
    $visualD75 = ($entry['cast']['visualD75'] ?? false) === true;
    $prayerRollCueEventId = $visualD75 ? ($entry['cast']['completionCueEventId'] ?? null) : null;
    if ($visualD75) unset($entry['cast']['completionCue'], $entry['cast']['completionCueEventId']);
    if ($decision === 'approve') {
        if ($visualD75 && onlineIsPreviousStoreRelease()) {
            rejectOnlineCommand($connection, 409, 'Mettez à jour le client MJ vers la Régie 4.4.6 pour choisir le visuel de la Prière.', 'visual_d75_client_update');
        }
        if (!$visualD75 && (applicationConfiguredPrayerAbility($private['ability'])['visualD75'] ?? false) === true) {
            rejectOnlineCommand($connection, 409, 'La règle de la Prière a changé. Annulez cette validation puis relancez la Prière.', 'prayer_legacy_validation');
        }
        if ($visualD75 && (!is_string($visualChoice) || !isset(applicationVisualD75Choices()[$visualChoice]))) {
            rejectOnlineCommand($connection, 400, 'Choisissez l’effet visuel Fantastique, Bon ou Mauvais.', 'visual_choice_required');
        }
        onlineValidateAbilityContinuation($connection, $records, $table, $entry);
        if ($visualD75) {
            $entry['cast']['visualChoice'] = $visualChoice;
            $entry['cast']['prayerRollCueEventId'] = $prayerRollCueEventId;
        }
        elseif(!empty($entry['cast']['manualOutcome'])){if(!isset(applicationMessageOutcomes()[$arguments['outcomeCode']??'']))rejectOnlineCommand($connection,400,'Choisissez le résultat arbitré par le MJ.','manual_outcome_required');$entry['cast']['outcome']=applicationManualD100Outcome((int)$entry['cast']['outcome']['raw'],$arguments['outcomeCode'],!empty($entry['cast']['outcome']['criticalFailureAsFailure']));$entry['cast']['success']=$entry['cast']['outcome']['success'];if(isset($entry['cast']['roll']))$entry['cast']['roll']['outcome']=$entry['cast']['outcome'];}
        $continuation = ['cast' => $entry['cast'], 'plan' => onlineResumedAbilityPlan($private['plan'], applicationDomainPayload($records, 'initiative:' . $entry['sceneId']), $activity)];
        $result = match ($entry['route']) {
            'ability.use' => onlineUseAbility($connection, $records, $pending, $table, $private['identity'], $private['body'], $private['isGm'], $continuation),
            'token.roll' => onlineSimpleAbilityRoll($connection, $records, $pending, $table, $private['identity'], $private['body'], $private['isGm'], $continuation),
            'ability.complex' => onlineUseComplexAbility($connection, $records, $pending, $table, $private['identity'], $private['body'], $private['isGm'], $continuation),
        };
        if ($visualD75) $entry['cast'] = $result['cast'];
    } else {
        if ($visualD75) unset($entry['cast']['pendingValidation']);
        $refundedCosts = onlineRefundAbilityValidation($connection, $records, $pending, $entry, $identity, true);
        $refundUnavailable = $refundedCosts['unavailable'] ?? [];
        unset($refundedCosts['unavailable']);
        $result = ['effect' => $entry['effect'], 'cast' => $entry['cast'], 'castSucceeded' => $visualD75 ? null : false, 'validationRejected' => true,
            'refundedCosts' => $refundedCosts, ...($refundUnavailable === [] ? [] : ['refundUnavailable'=>$refundUnavailable]),
            'roll' => null, 'castRoll' => null, 'effectRoll' => null, 'rolls' => [], 'execution' => null, 'appliedDelta' => 0];
    }
    $entry['status'] = $decision === 'approve' ? 'approved' : 'rejected'; $entry['resolvedAt'] = gmdate('c');
    $result = [...$result, 'pendingValidation' => false, 'validation' => publicOnlineAbilityValidation($entry, (string) $identity['id']), 'deduplicated' => false];
    $activity = $pending['activity']['payload'] ?? $activity;
    $parentAttackId = $private['plan']['defenseApproval']['parentAttackId'] ?? null;
    $executionId = $result['execution']['id'] ?? null;
    if ($decision === 'approve' && $entry['route'] === 'ability.complex' && is_string($parentAttackId)
        && $entry['requestId'] === 'release-' . $parentAttackId
        && $executionId === 'execution-' . $entry['requestId']) {
        foreach ($activity['attackReceipts'] ?? [] as $index => $receipt) {
            $parent = $receipt['attack'] ?? [];
            if (($parent['id'] ?? null) !== $parentAttackId || ($parent['status'] ?? null) !== 'resolved'
                || ($parent['areaGate']['kind'] ?? null) !== 'complex-start'
                || !onlineWantsTotalOpposition($parent['areaGate'])) continue;
            $activity['attackReceipts'][$index]['attack']['areaGate']['executionId'] = $executionId;
            break;
        }
    }
    $activity['pendingAbilityCasts'] = array_values(array_filter($activity['pendingAbilityCasts'] ?? [], static fn(array $candidate): bool => $candidate['id'] !== $validationId));
    foreach ($activity['resourceReceipts'] ?? [] as $i => $receipt) {
        if (($receipt['requestId'] ?? '') === $entry['requestId'] && ($receipt['accountId'] ?? '') === $private['identity']['id']) {
            $activity['resourceReceipts'][$i]['result'] = [...$result, 'validation' => publicOnlineAbilityValidation($entry, (string) $private['identity']['id'])];
            $activity['resourceReceipts'][$i]['expiresAt'] = (int) floor(microtime(true) * 1000) + XAR_RESOURCE_RECEIPT_TTL_MILLISECONDS;
        }
    }
    queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
    $summary = $visualD75
        ? ($decision === 'approve' ? ' · effet visuel choisi par le MJ' : ' · lancement annulé par le MJ')
        : ($decision === 'approve' ? ' · résultat validé par le MJ' : ' · résultat refusé par le MJ');
    $action = onlineAppendPlayerAction($connection, $records, $pending, $identity, $entry['sceneId'], ['kind' => 'ability', 'characterName' => $entry['sourceName'], 'summary' => $entry['abilityName'] . $summary,
        ...(!empty($refundUnavailable) ? ['detail'=>'Le porteur ou la réserve d’origine a disparu. Le remboursement a été limité aux ressources encore présentes.'] : [])]);
    $context['receipts'] = $pending['activity']['payload']['resourceReceipts'];
    onlineStorePersistentCommandReceipt($records, $pending, $context, 'ability-validation', $requestId, $identity['id'], $action['id'], $entry['sourceTokenId'], $signature, $result);
    return $result;
}
