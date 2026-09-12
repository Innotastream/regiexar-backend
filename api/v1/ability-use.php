<?php
declare(strict_types=1);

function planApplicationMetamorphosis(array $source, array $characters, array $tokens, ?array $ability, array $request, string $accountId, bool $isGm, array $pendingAttacks = []): array {
    if (($source['followCharacter'] ?? true) === false || !empty($source['linkedTokenId']) || empty($source['characterId'])) throw new RuntimeException('La métamorphose nécessite un pion lié à une fiche.');
    $previous = is_array($source['transformation'] ?? null) ? $source['transformation'] : [];
    $returning = ($request['returnForm'] ?? false) === true;
    $byId = array_column($characters, null, 'id');
    $baseId = ($previous['active'] ?? false) ? ($previous['baseCharacterId'] ?? '') : $source['characterId'];
    $base = $byId[$baseId] ?? []; $current = $byId[$source['characterId']] ?? [];
    $formId = $returning || ($previous['requestId'] ?? '') === $request['requestId'] ? ($previous['formCharacterId'] ?? '') : ($ability['formCharacterId'] ?? '');
    $form = $byId[$formId] ?? [];
    if ($base === [] || $form === [] || $current === [] || $baseId === $formId || ($base['ownerPlayerId'] ?? null) !== ($form['ownerPlayerId'] ?? null) || (!$isGm && ($base['ownerPlayerId'] ?? '') !== $accountId)) throw new RuntimeException('Les deux fiches doivent exister et appartenir au même joueur.');
    if (($previous['requestId'] ?? '') === $request['requestId'] && ($previous['accountId'] ?? '') === $accountId) return ['deduplicated' => true, 'activeCharacterId' => $source['characterId'], 'changes' => []];
    if ((string) ($request['expectedFormRequestId'] ?? '') !== (string) ($previous['requestId'] ?? '')) throw new RuntimeException('La forme du pion a changé. Actualisez la table avant de relancer.');
    if ($returning ? !($previous['active'] ?? false) : (($previous['active'] ?? false) || ($ability['effect'] ?? '') !== 'metamorphosis')) throw new RuntimeException('Cette forme n’est pas disponible pour cette action.');
    $affected = array_filter($tokens, static fn(array $t): bool => ($t['followCharacter'] ?? true) !== false && empty($t['linkedTokenId']) && ($returning
        ? ($t['transformation']['active'] ?? false) && ($t['transformation']['baseCharacterId'] ?? '') === $baseId && ($t['characterId'] ?? '') === $formId
        : ($t['characterId'] ?? '') === $baseId));
    $ids = array_fill_keys(array_column($affected, 'id'), true);
    foreach ($pendingAttacks as $attack) if (isset($ids[$attack['sourceTokenId'] ?? '']) || isset($ids[$attack['targetTokenId'] ?? ''])) throw new RuntimeException('Terminez les attaques en attente avant de changer de forme.');
    $activeCharacterId = $returning ? $baseId : $formId;
    $transformation = ['baseCharacterId' => $baseId, 'formCharacterId' => $formId, 'active' => !$returning, 'requestId' => $request['requestId'], 'accountId' => $accountId];
    return ['activeCharacterId' => $activeCharacterId, 'deduplicated' => false, 'changes' => array_map(static fn(array $token): array => ['token' => $token, 'characterId' => $activeCharacterId, 'transformation' => $transformation], $affected)];
}

function onlineUseAbility(PDO $connection, array &$records, array &$pending, array $table, array $identity, array $arguments, bool $isGm): array {
    $accountId = (string) $identity['id']; $sceneId = (string) ($arguments['sceneId'] ?? '');
    if ($sceneId === '' || (!$isGm && $sceneId !== onlineActiveSceneId($table))) rejectOnlineCommand($connection, 409, 'La scène a changé. Rouvrez la capacité.', 'stale_scene');
    if (($table['tacticalSync']['paused'] ?? false)) rejectOnlineCommand($connection, 423, 'La table est verrouillée.', 'table_locked');
    $requestId = (string) ($arguments['requestId'] ?? '');
    $records = applicationDomainRecords($connection);
    $activity = applicationDomainPayload($records, 'activity');
    $signature = json_encode(['ability.use', $sceneId, $arguments['sourceTokenId'] ?? '', $arguments['characterId'] ?? '', $arguments['abilityId'] ?? '', $arguments['targetTokenId'] ?? '', ($arguments['returnForm'] ?? false) === true], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    // Preserve a healing receipt created by the preceding 3.2.3 candidate.
    foreach ($activity['resourceReceipts'] ?? [] as $old) if (($old['requestId'] ?? '') === $requestId && ($old['expiresAt'] ?? 0) > (int) floor(microtime(true) * 1000) && ($old['operation']['kind'] ?? '') === 'resource-adjust') {
        if (($old['accountId'] ?? '') !== $accountId) rejectOnlineCommand($connection, 403, 'Ce reçu appartient à un autre compte.', 'ability_receipt_forbidden');
        if (($old['operation']['sceneId'] ?? '') !== $sceneId || ($old['operation']['tokenId'] ?? '') !== ($arguments['targetTokenId'] ?? '')) rejectOnlineCommand($connection, 409, 'Cette référence désigne un autre soin.', 'ability_request_mismatch');
        return ['effect' => 'healing', 'appliedDelta' => $old['operation']['appliedDelta'] ?? 0, 'deduplicated' => true];
    }
    $receipt = onlineAbilityReceipt($connection, $activity, $requestId, $accountId, $signature);
    if ($receipt !== null) return $receipt;
    $map = applicationDomainPayload($records, 'map:' . $sceneId);
    $source = applicationDomainPayload($records, onlineTokenDomainKey($sceneId, $arguments['sourceTokenId'] ?? ''));
    if ($source === [] || (!empty($arguments['layerId']) && $arguments['layerId'] !== onlineTokenLayerId($source, $map)) || !onlineTokenOnActiveLayer($source, $map) || (!$isGm && (($source['hidden'] ?? false) || onlineTokenControllerIdFromRecords($connection, $records, $source) !== $accountId))) rejectOnlineCommand($connection, 403, 'Ce pion ne vous appartient pas ou a changé de niveau.', 'ability_source_forbidden');
    $owner = applicationAbilityCastingOwner($source);
    $character = $owner['characterId'] !== '' ? applicationDomainPayload($records, 'character:' . $owner['characterId']) : [];
    $rules = $character !== [] ? synchronizeOnlineCharacterToken($source, $character) : $source;
    $rules['controllerPlayerId'] = onlineTokenControllerIdFromRecords($connection, $records, $source);
    $abilities = normalizeOnlineAbilities($rules['abilities'] ?? []);
    $abilityIndex = findEntryIndex($abilities, (string) ($arguments['abilityId'] ?? '')); $ability = $abilityIndex >= 0 ? $abilities[$abilityIndex] : null;
    $returning = ($arguments['returnForm'] ?? false) === true;
    $metamorphosis = $returning || ($ability['effect'] ?? '') === 'metamorphosis' || ($source['transformation']['requestId'] ?? '') === $requestId;
    $formPlan = null; $target = [];
    if ($metamorphosis) {
        $characters = []; $tokens = [];
        foreach ($records as $key => $record) {
            if (str_starts_with($key, 'character:')) $characters[] = applicationDomainPayload($records, $key);
            if (str_starts_with($key, 'token:')) $tokens[$key] = applicationDomainPayload($records, $key);
        }
        try { $formPlan = planApplicationMetamorphosis($source, $characters, $tokens, $ability, $arguments, $accountId, $isGm, $activity['pendingAttacks'] ?? []); }
        catch (RuntimeException $error) { rejectOnlineCommand($connection, 409, $error->getMessage(), 'form_conflict'); }
        if ($formPlan['deduplicated']) return ['effect' => 'metamorphosis', 'activeCharacterId' => $formPlan['activeCharacterId'], 'deduplicated' => true];
    } else {
        if (($ability['effect'] ?? '') !== 'healing') rejectOnlineCommand($connection, 404, 'Cette capacité de soin n’existe plus.', 'healing_ability_missing');
        $target = applicationDomainPayload($records, onlineTokenDomainKey($sceneId, $arguments['targetTokenId'] ?? ''));
        if ($target === [] || !onlineTokenOnActiveLayer($target, $map) || (!$isGm && (($target['hidden'] ?? false) || !onlineAttackTargetVisible($connection, $records, $map, $target, $accountId, $sceneId)))) rejectOnlineCommand($connection, 403, 'La cible du soin n’est pas visible.', 'healing_target_hidden');
    }
    if (!$returning && applicationAbilitySourceDefeated($rules)) rejectOnlineCommand($connection, 409, 'Un pion KO ou mort ne peut lancer une compétence.', 'ability_source_defeated');
    $plan = $returning ? null : onlinePrepareAbilityCasting($connection, $ability, $rules, $sceneId, applicationDomainPayload($records, 'initiative:' . $sceneId), $activity);
    $cast = $returning ? ['success' => true, 'manaSpent' => 0, 'cooldownRounds' => 0, 'remainingRounds' => 0, 'statId' => '', 'statLabel' => '', 'outcome' => null, 'roll' => null] : onlineAbilityCastingRoll($plan, $rules, $identity, $arguments);
    $effectRoll = null;
    $result = ['effect' => $metamorphosis ? 'metamorphosis' : 'healing', 'appliedDelta' => 0];
    if ($cast['success'] && $metamorphosis) {
        $form = applicationDomainPayload($records, 'character:' . $formPlan['activeCharacterId']);
        foreach ($formPlan['changes'] as $key => $change) {
            $token = $change['token']; $token['characterId'] = $change['characterId']; $token['transformation'] = $change['transformation'];
            queueOnlineDomainUpsert($pending, $records, $key, synchronizeOnlineCharacterToken($token, $form));
        }
        $result['activeCharacterId'] = $formPlan['activeCharacterId'];
        onlineAppendPlayerAction($connection, $records, $pending, $identity, $sceneId, ['kind' => 'character', 'characterName' => $source['name'] ?? 'Personnage', 'summary' => $returning ? 'Reprend sa forme initiale' : 'Change de forme']);
    } elseif ($cast['success']) {
        $rolled = onlineRollFormulaWithMode($ability['healingFormula'], 'normal');
        onlineRecordCharacterLuckD100($connection, $records, $pending, $identity, $owner['characterId'] !== '' ? $owner['characterId'] : ($source['characterId'] ?? ''), $rolled);
        if ($rolled['total'] <= 0) rejectOnlineCommand($connection, 400, 'Le soin doit rendre au moins un PV.', 'invalid_healing_total');
        $effectRoll = onlineAbilityRollVisibility(
            onlineRollEntry($identity, $rolled, (string) $ability['name'] . ' · Soin', (string) ($rules['name'] ?? 'Personnage')),
            $rules,
            $identity
        );
        onlineAppendAbilityEffectRoll($records, $pending, $effectRoll);
        $amount = max(0, min(1000000000, $rolled['total']));
        if ($amount > 0) {
            // The owner and visible target were checked above. No armor is used.
            $adjustment = applyOnlineTokenResourceAdjustment($connection, $records, $pending, $sceneId, $target['id'], 'hp', $amount, $accountId, true, null, true);
            $operation = ['kind' => 'resource-adjust', 'requestId' => $requestId, 'sceneId' => $sceneId, 'tokenId' => $target['id'], 'characterId' => $adjustment['characterId'] ?? '', 'resource' => 'hp', 'appliedDelta' => $adjustment['appliedDelta'], 'previous' => $adjustment['previous'], 'current' => $adjustment['current'], 'maximum' => $adjustment['maximum'], 'formula' => $rolled['formula'], 'rollTotal' => $rolled['total'], 'rollBreakdown' => $rolled['breakdown']];
            $result['appliedDelta'] = $adjustment['appliedDelta'];
            onlineAppendPlayerAction($connection, $records, $pending, $identity, $sceneId, ['kind' => 'resource', 'characterName' => $source['name'] ?? 'Personnage', 'targetName' => $target['name'] ?? 'Cible', 'summary' => $ability['name'] . ' : ' . $adjustment['appliedDelta'] . ' PV rendus', 'operation' => $operation]);
        }
    }
    if ($plan !== null) {
        $cast = onlineCommitAbilityCasting($connection, $records, $pending, $plan, $cast, $rules, $identity);
        onlineAppendPlayerAction($connection, $records, $pending, $identity, $sceneId, ['kind' => 'ability', 'characterName' => $source['name'] ?? 'Personnage', 'summary' => $ability['name'] . ($cast['success'] ? ' · lancement réussi' : ' · lancement échoué'), 'detail' => $cast['manaSpent'] . ' mana consommé' . ($cast['success'] ? ' · recharge ' . applicationRoundCountLabel((int) $cast['remainingRounds']) : ' · aucune recharge')]);
    }
    $bundle = onlineAbilityRollBundle($cast, $effectRoll);
    $stored = onlineStoreAbilityReceipt($records, $pending, $requestId, $accountId, $signature, [...$result, ...$bundle, 'cast' => $cast, 'castSucceeded' => $cast['success']]);
    onlineAppendAbilityRollActions($connection, $records, $pending, $identity, $sceneId, $bundle['rolls']);
    return $stored;
}
