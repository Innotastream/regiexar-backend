<?php
declare(strict_types=1);

function validApplicationAbilityCastingFields(array $ability): bool {
    foreach (['manaCost' => 1000000000, 'cooldownRounds' => 999] as $key => $limit) {
        if (array_key_exists($key, $ability) && (!is_int($ability[$key]) || $ability[$key] < 0 || $ability[$key] > $limit)) return false;
    }
    if (array_key_exists('castingStatId', $ability) && $ability['castingStatId'] !== '' && !validApplicationDomainIdentifier($ability['castingStatId'], 120)) return false;
    return !array_key_exists('image', $ability) || $ability['image'] === null || validApplicationDomainText($ability['image'], 4096);
}

function applicationAbilityCastingFields(array $ability): array {
    $result = [];
    foreach (['manaCost' => 1000000000, 'cooldownRounds' => 999] as $key => $limit) {
        if (array_key_exists($key, $ability)) $result[$key] = max(0, min($limit, is_numeric($ability[$key]) ? (int) $ability[$key] : 0));
    }
    if (array_key_exists('castingStatId', $ability) && ($ability['castingStatId'] === '' || validApplicationDomainIdentifier($ability['castingStatId'], 120))) $result['castingStatId'] = $ability['castingStatId'];
    if (array_key_exists('image', $ability)) $result['image'] = validApplicationDomainText($ability['image'], 4096) ? $ability['image'] : null;
    return $result;
}

function applicationAbilityCastingOwner(array $source): array {
    $characterId = ($source['followCharacter'] ?? true) !== false && empty($source['linkedTokenId']) ? (string) ($source['characterId'] ?? '') : '';
    return ['characterId' => $characterId, 'tokenId' => $characterId === '' ? (string) ($source['id'] ?? '') : ''];
}

function applicationAbilityCastingStat(array $stats, string $statId): ?array {
    foreach ($stats as $stat) if (is_array($stat) && ($stat['id'] ?? '') === $statId) return $stat;
    $shortId = preg_replace('/^character-stat-/', '', $statId);
    $labels = ['force' => 'force', 'dexterity' => 'dexterite', 'agility' => 'agilite', 'spiritSocial' => 'espritsocial', 'intelligence' => 'intelligence', 'instinct' => 'instinctperception'];
    if (!array_key_exists($shortId, $labels)) return null;
    foreach ($stats as $stat) if (is_array($stat) && in_array($stat['id'] ?? '', [$shortId, 'character-stat-' . $shortId], true)) return $stat;
    foreach ($stats as $stat) {
        if (!is_array($stat)) continue;
        $label = strtr((string) ($stat['label'] ?? ''), ['é' => 'e', 'É' => 'e', 'è' => 'e', 'È' => 'e', 'ê' => 'e', 'Ê' => 'e', 'î' => 'i', 'Î' => 'i', 'ï' => 'i', 'Ï' => 'i', 'à' => 'a', 'À' => 'a', 'â' => 'a', 'Â' => 'a']);
        $label = preg_replace('/[^a-z0-9]/', '', strtolower($label));
        if ($label === $labels[$shortId]) return $stat;
    }
    return null;
}

function applicationAbilitySourceDefeated(array $source): bool {
    return !is_numeric($source['hp'] ?? null) || !is_finite((float) $source['hp']) || $source['hp'] <= 0
        || onlineTokenIsDead($source, !empty($source['controllerPlayerId']));
}

function applicationRoundCountLabel(int $count): string {
    $count = max(0, $count);
    return $count . ' round' . ($count === 1 ? '' : 's');
}

// A round is a complete initiative cycle. Stopping combat freezes the existing
// counter; beginning a new combat explicitly clears its timers in the MJ flow.
function applicationAbilityCastingPlan(array $ability, array $source, string $sceneId, array $initiative, array $timers, ?string $legacyStatId = null): array {
    if (!validApplicationAbilityCastingFields($ability)) throw new RuntimeException('ability_cast_invalid');
    $owner = applicationAbilityCastingOwner($source);
    $round = max(1, (int) ($initiative['round'] ?? 1));
    $remaining = 0;
    foreach ($timers as $timer) {
        if (!is_array($timer) || ($timer['sceneId'] ?? '') !== $sceneId || ($timer['abilityId'] ?? '') !== ($ability['id'] ?? '')) continue;
        if ($owner['characterId'] !== '' ? ($timer['characterId'] ?? '') !== $owner['characterId'] : (($timer['characterId'] ?? '') !== '' || ($timer['tokenId'] ?? '') !== $owner['tokenId'])) continue;
        $remaining = max($remaining, max(0, (int) ($timer['readyRound'] ?? 1) - $round));
    }
    if ($remaining > 0) throw new RuntimeException('ability_on_cooldown');
    $manaCost = (int) ($ability['manaCost'] ?? 0);
    $availableMana = max(0, min((float) ($source['maxMana'] ?? 0), (float) ($source['mana'] ?? 0)));
    if ($availableMana < $manaCost) throw new RuntimeException('ability_mana_insufficient');
    // Absence preserves the old attack-dialogue statistic; an explicit empty
    // string means no casting check. Legacy simple rolls had no check.
    $statId = array_key_exists('castingStatId', $ability) ? $ability['castingStatId'] : ($legacyStatId ?? '');
    $stat = null;
    if ($statId !== '') {
        $stat = applicationAbilityCastingStat($source['stats'] ?? [], $statId);
        if (!is_array($stat) || !is_numeric($stat['value'] ?? null)) throw new RuntimeException('ability_cast_stat_missing');
        $statId = (string) $stat['id'];
    }
    return [...$owner, 'sceneId' => $sceneId, 'abilityId' => (string) ($ability['id'] ?? ''), 'label' => (string) ($ability['name'] ?? 'Capacité'),
        'usedRound' => $round, 'cooldownRounds' => (int) ($ability['cooldownRounds'] ?? 0), 'manaCost' => $manaCost,
        'statId' => $statId, 'statLabel' => (string) ($stat['label'] ?? ''), 'threshold' => $stat === null ? null : max(0, min(100, (int) $stat['value']))];
}

function onlinePrepareAbilityCasting(PDO $connection, array $ability, array $source, string $sceneId, array $initiative, array $activity, ?string $legacyStatId = null): array {
    try { return applicationAbilityCastingPlan($ability, $source, $sceneId, $initiative, $activity['actionTimers'] ?? [], $legacyStatId); }
    catch (RuntimeException $error) {
        $messages = ['ability_on_cooldown' => 'Cette compétence est encore en recharge.', 'ability_mana_insufficient' => 'Mana insuffisant pour tenter cette compétence.',
            'ability_cast_stat_missing' => 'La caractéristique de lancement n’existe plus sur cette fiche.', 'ability_cast_invalid' => 'Les règles de lancement sont invalides.'];
        rejectOnlineCommand($connection, 409, $messages[$error->getMessage()] ?? 'Cette compétence ne peut pas être lancée.', $error->getMessage());
    }
}

function onlineAbilityCastingRoll(array $plan, array $source, array $identity, array $arguments = []): array {
    if ($plan['statId'] === '') return ['success' => true, 'statId' => '', 'statLabel' => '', 'outcome' => null, 'roll' => null];
    $modifierValue = normalizeOnlineD100Modifier($arguments['hitModifier'] ?? $arguments['castingModifier'] ?? $arguments['modifier'] ?? 0);
    $modifierMode = ($arguments['hitModifierMode'] ?? $arguments['castingModifierMode'] ?? $arguments['modifierMode'] ?? '') === 'result' ? 'result' : 'threshold';
    $thresholdModifier = $modifierMode === 'threshold' ? $modifierValue : 0;
    $resultModifier = $modifierMode === 'result' ? $modifierValue : 0;
    $formula = '1d100' . ($resultModifier !== 0 ? ($resultModifier > 0 ? '+' : '') . $resultModifier : '');
    $rolled = onlineRollFormulaWithMode($formula, normalizeOnlineRollMode($arguments['rollMode'] ?? 'normal'), $plan['threshold'], $thresholdModifier);
    $outcome = classifyOnlineD100Outcome($rolled['rawD100'] ?? null, $plan['threshold'], $thresholdModifier, $resultModifier);
    if ($outcome !== null) $outcome['resultCustomized'] = $modifierMode === 'result';
    $roll = onlineRollEntry($identity, $rolled, $plan['label'] . ' · Lancement · ' . $plan['statLabel'], (string) ($source['name'] ?? 'Personnage'), $outcome);
    $roll = onlineAbilityRollVisibility($roll, $source, $identity);
    if (!empty($source['id'])) $roll['mapEvent'] = ['kind' => 'roll', 'sceneId' => $plan['sceneId'], 'anchorTokenId' => $source['id'], 'tokenId' => $source['id'], 'value' => $outcome['result'] ?? $rolled['rawD100'] ?? $rolled['total'], 'label' => 'Lancement', 'tone' => $outcome['code'] ?? 'normal'];
    return ['success' => ($outcome['success'] ?? false) === true, 'statId' => $plan['statId'], 'statLabel' => $plan['statLabel'], 'outcome' => $outcome, 'roll' => $roll];
}

function onlineAbilityRollVisibility(array $roll, array $source, array $identity): array {
    $isGm = ($identity['effective_mode'] ?? '') === 'gm' && ($identity['permanent_role'] ?? '') === 'gm';
    if ($isGm) $roll['rollerRole'] = 'gm';
    if ($isGm && (($source['hidden'] ?? false) === true || (empty($source['controllerPlayerId']) && ($source['revealDetailsToPlayers'] ?? false) !== true))) {
        $roll['visibility'] = 'gm'; $roll['revealed'] = false;
    }
    return $roll;
}

function onlineAbilityRollBundle(array $cast, ?array $effectRoll = null): array {
    $castRoll = is_array($cast['roll'] ?? null) ? $cast['roll'] : null;
    $rolls = applicationUniqueRolls([$castRoll, $effectRoll]);
    return [
        // `roll` remains the historical primary result consumed by clients up
        // to 3.2.16: the effect when one was rolled, otherwise the cast.
        'roll' => $effectRoll ?? $castRoll,
        'castRoll' => $castRoll,
        'effectRoll' => $effectRoll,
        'rolls' => $rolls,
    ];
}

function onlineAppendAbilityEffectRoll(array &$records, array &$pending, array $roll): void {
    $activity = is_array($pending['activity']['payload'] ?? null)
        ? $pending['activity']['payload']
        : applicationDomainPayload($records, 'activity');
    $existing = is_array($activity['rolls'] ?? null) ? $activity['rolls'] : [];
    $activity['rolls'] = array_slice(applicationUniqueRolls([$roll, ...$existing]), 0, 100);
    queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
}

function onlineAppendAbilityRollActions(PDO $connection, array &$records, array &$pending, array $identity, string $sceneId, array $rolls): void {
    // Player actions are stored newest first. Append in reverse so a single
    // cast is presented before its effect, matching the response bundle.
    foreach (array_reverse(applicationUniqueRolls($rolls)) as $roll) {
        onlineAppendPlayerAction($connection, $records, $pending, $identity, $sceneId, [
            'kind' => 'roll',
            ...applicationRollActivityFields($roll),
        ]);
    }
}

// Queue costs after effect preparation, in the same locked transaction. Read
// pending payloads first so healing oneself never restores the mana just spent.
function onlineCommitAbilityCasting(PDO $connection, array &$records, array &$pending, array $plan, array $cast, array $source, array $identity, bool $recordRoll = true): array {
    $now = (int) floor(microtime(true) * 1000);
    onlineRecordCharacterLuckD100(
        $connection,
        $records,
        $pending,
        $identity,
        ($plan['characterId'] ?? '') !== '' ? $plan['characterId'] : ($source['characterId'] ?? ''),
        $cast
    );
    $cost = (int) $plan['manaCost'];
    if ($cost > 0) {
        $characterKey = $plan['characterId'] !== '' ? 'character:' . $plan['characterId'] : '';
        $sourceKey = !empty($source['id']) ? onlineTokenDomainKey($plan['sceneId'], $source['id']) : '';
        $pulse = ['id' => 'resource-' . randomToken(12), 'resource' => 'mana', 'delta' => -$cost, 'at' => $now];
        if ($characterKey !== '') {
            $character = $pending[$characterKey]['payload'] ?? applicationDomainPayload($records, $characterKey);
            if ($character === []) rejectOnlineCommand($connection, 409, 'La fiche de lancement a changé.', 'ability_source_changed');
            $mana = max(0, min((float) ($character['resources']['maxMana'] ?? 0), (float) ($character['resources']['mana'] ?? 0)));
            if ($mana < $cost) rejectOnlineCommand($connection, 409, 'Mana insuffisant pour cette compétence.', 'ability_mana_insufficient');
            $character['resources']['mana'] = $mana - $cost; $character['_updatedAt'] = $now;
            queueOnlineDomainUpsert($pending, $records, $characterKey, $character);
            $related = applicationCharacterTokenDomainRecords($connection, $plan['characterId']);
            $records = array_replace($records, $related);
            foreach ($related as $key => $record) {
                $token = $pending[$key]['payload'] ?? applicationDomainPayload($records, $key);
                if (($token['characterId'] ?? '') !== $plan['characterId'] || ($token['followCharacter'] ?? true) === false || !empty($token['linkedTokenId'])) continue;
                $token['mana'] = $mana - $cost; $token['_updatedAt'] = $now;
                if (($pending[$key]['payload']['resourcePulse']['resource'] ?? '') !== 'hp') $token['resourcePulse'] = $pulse;
                queueOnlineDomainUpsert($pending, $records, $key, $token);
            }
        } elseif ($sourceKey !== '') {
            $token = $pending[$sourceKey]['payload'] ?? applicationDomainPayload($records, $sourceKey);
            $mana = max(0, min((float) ($token['maxMana'] ?? 0), (float) ($token['mana'] ?? 0)));
            if ($mana < $cost) rejectOnlineCommand($connection, 409, 'Mana insuffisant pour cette compétence.', 'ability_mana_insufficient');
            $token['mana'] = $mana - $cost; $token['_updatedAt'] = $now;
            if (($pending[$sourceKey]['payload']['resourcePulse']['resource'] ?? '') !== 'hp') $token['resourcePulse'] = $pulse;
            queueOnlineDomainUpsert($pending, $records, $sourceKey, $token);
        }
    }
    $activity = $pending['activity']['payload'] ?? applicationDomainPayload($records, 'activity');
    if ($recordRoll && is_array($cast['roll'] ?? null)) $activity['rolls'] = array_slice([$cast['roll'], ...($activity['rolls'] ?? [])], 0, 100);
    $remaining = $cast['success'] ? (int) $plan['cooldownRounds'] : 0;
    if ($remaining > 0) {
        $isGm = ($identity['effective_mode'] ?? '') === 'gm' && ($identity['permanent_role'] ?? '') === 'gm';
        $timerOwner = $isGm ? (onlineTokenControllerIdFromRecords($connection, $records, $source) ?: (string) $identity['id']) : (string) $identity['id'];
        $timers = array_values($activity['actionTimers'] ?? []);
        $index = null;
        foreach ($timers as $i => $timer) if (($timer['sceneId'] ?? '') === $plan['sceneId'] && ($timer['abilityId'] ?? '') === $plan['abilityId'] && ($timer['characterId'] ?? '') === $plan['characterId'] && ($timer['tokenId'] ?? '') === $plan['tokenId']) { $index = $i; break; }
        if ($index === null && count($timers) >= 300) rejectOnlineCommand($connection, 409, 'La table a atteint sa limite de recharges.', 'timer_limit');
        $timer = ['id' => $index !== null ? $timers[$index]['id'] : 'timer-' . randomToken(9), 'sceneId' => $plan['sceneId'], 'abilityId' => $plan['abilityId'],
            'characterId' => $plan['characterId'], 'tokenId' => $plan['tokenId'], 'label' => $plan['label'], 'cooldown' => $remaining,
            'usedRound' => $plan['usedRound'], 'readyRound' => $plan['usedRound'] + $remaining, 'ownerPlayerId' => $timerOwner,
            'ownerLabel' => $source['name'] ?? 'Personnage', 'visibility' => 'private', 'createdAt' => $index !== null ? ($timers[$index]['createdAt'] ?? gmdate('c')) : gmdate('c'), 'updatedAt' => gmdate('c')];
        if ($index !== null) $timers[$index] = $timer; else array_unshift($timers, $timer);
        $activity['actionTimers'] = $timers;
    }
    queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
    return [...$cast, 'manaSpent' => $cost, 'cooldownRounds' => (int) $plan['cooldownRounds'], 'remainingRounds' => $remaining];
}

function onlineAbilityReceipt(PDO $connection, array $activity, string $requestId, string $accountId, string $signature): ?array {
    if (preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $requestId) !== 1) rejectOnlineCommand($connection, 400, 'Actualisez le client pour sécuriser le lancement de cette compétence.', 'invalid_ability_request');
    $now = (int) floor(microtime(true) * 1000); $count = 0;
    foreach ($activity['resourceReceipts'] ?? [] as $receipt) {
        if (!is_array($receipt) || ($receipt['expiresAt'] ?? 0) <= $now) continue;
        $count += 1;
        if (($receipt['requestId'] ?? '') !== $requestId) continue;
        if (($receipt['accountId'] ?? '') !== $accountId) rejectOnlineCommand($connection, 403, 'Ce reçu appartient à un autre compte.', 'ability_receipt_forbidden');
        if (($receipt['requestSignature'] ?? '') !== $signature || ($receipt['kind'] ?? '') !== 'ability-cast' || !is_array($receipt['result'] ?? null)) rejectOnlineCommand($connection, 409, 'Cette référence désigne un autre lancement.', 'ability_request_mismatch');
        return [...$receipt['result'], 'deduplicated' => true];
    }
    if ($count >= XAR_RESOURCE_RECEIPT_MAXIMUM) rejectOnlineCommand($connection, 429, 'Le journal de sécurité des compétences est plein.', 'ability_receipt_capacity');
    return null;
}

function onlineStoreAbilityReceipt(array &$records, array &$pending, string $requestId, string $accountId, string $signature, array $result, ?string $committedActionId = null): array {
    $activity = $pending['activity']['payload'] ?? applicationDomainPayload($records, 'activity');
    $now = (int) floor(microtime(true) * 1000);
    $receipts = array_values(array_filter($activity['resourceReceipts'] ?? [], static fn($entry): bool => is_array($entry) && ($entry['expiresAt'] ?? 0) > $now));
    $parts = json_decode($signature, true);
    $actionId = trim((string) ($committedActionId ?? ''));
    if ($actionId === '') $actionId = (string) ($activity['playerActions'][0]['id'] ?? '');
    if ($actionId === '') throw new RuntimeException('A casting receipt requires its committed action.');
    $receipts[] = ['kind' => 'ability-cast', 'requestId' => $requestId, 'accountId' => $accountId, 'actionId' => $actionId, 'sourceKey' => (string) (($parts[2] ?? '') ?: ($parts[3] ?? '')), 'abilityId' => (string) ($parts[4] ?? ''), 'expiresAt' => $now + XAR_RESOURCE_RECEIPT_TTL_MILLISECONDS, 'requestSignature' => $signature, 'result' => $result];
    $activity['resourceReceipts'] = $receipts; queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
    return [...$result, 'deduplicated' => false];
}

function onlineSimpleAbilityRoll(PDO $connection, array &$records, array &$pending, array $table, array $identity, array $arguments, bool $isGm): array {
    if (($table['tacticalSync']['paused'] ?? false) === true) rejectOnlineCommand($connection, 423, 'La table est verrouillée.', 'table_locked');
    $sceneId = (string) ($arguments['sceneId'] ?? onlineActiveSceneId($table)); $accountId = (string) $identity['id'];
    if (!$isGm && $sceneId !== onlineActiveSceneId($table)) rejectOnlineCommand($connection, 409, 'La scène a changé.', 'stale_scene');
    $records = applicationDomainRecords($connection);
    $activity = applicationDomainPayload($records, 'activity');
    $signature = json_encode(['token.roll', $sceneId, $arguments['tokenId'] ?? '', $arguments['characterId'] ?? '', $arguments['abilityId'] ?? '', $arguments['targetTokenId'] ?? '', ($arguments['returnForm'] ?? false) === true], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $requestId = (string) ($arguments['requestId'] ?? '');
    if ($requestId !== '') { $receipt = onlineAbilityReceipt($connection, $activity, $requestId, $accountId, $signature); if ($receipt !== null) return $receipt; }
    $tokenId = (string) ($arguments['tokenId'] ?? '');
    if ($tokenId !== '') {
        $source = applicationDomainPayload($records, onlineTokenDomainKey($sceneId, $tokenId));
        $map = applicationDomainPayload($records, 'map:' . $sceneId);
        if ($source === [] || !onlineTokenOnActiveLayer($source, $map) || (!empty($arguments['layerId']) && $arguments['layerId'] !== onlineTokenLayerId($source, $map))) rejectOnlineCommand($connection, 409, 'Ce pion a changé de niveau.', 'stale_token_layer');
        if (!$isGm && (($source['hidden'] ?? false) || onlineTokenControllerIdFromRecords($connection, $records, $source) !== $accountId)) rejectOnlineCommand($connection, 403, 'Ce pion ne vous appartient pas.', 'token_forbidden');
        $source['controllerPlayerId'] = onlineTokenControllerIdFromRecords($connection, $records, $source);
        $owner = applicationAbilityCastingOwner($source);
        $character = $owner['characterId'] !== '' ? applicationDomainPayload($records, 'character:' . $owner['characterId']) : [];
        if ($character !== []) $source = synchronizeOnlineCharacterToken($source, $character);
    } else {
        $characterId = (string) ($arguments['characterId'] ?? '');
        $character = applicationDomainPayload($records, 'character:' . $characterId);
        if ($character === [] || (!$isGm && ($character['ownerPlayerId'] ?? '') !== $accountId)) rejectOnlineCommand($connection, 403, 'Cette fiche ne vous appartient pas.', 'character_forbidden');
        $source = synchronizeOnlineCharacterToken(['characterId' => $characterId], $character);
    }
    $abilities = normalizeOnlineAbilities($source['abilities'] ?? []);
    $index = findEntryIndex($abilities, (string) ($arguments['abilityId'] ?? ''));
    if ($index < 0) rejectOnlineCommand($connection, 404, 'Cette compétence n’existe plus.', 'token_ability_missing');
    $ability = $abilities[$index];
    if (($ability['effect'] ?? 'damage') !== 'damage') rejectOnlineCommand($connection, 400, 'Utilisez cette capacité comme soin ou métamorphose depuis un pion placé.', 'ability_effect_required');
    $extended = array_key_exists('castingStatId', $ability) || ($ability['manaCost'] ?? 0) > 0 || ($ability['cooldownRounds'] ?? 0) > 0;
    if ($requestId === '') {
        if ($extended) onlineAbilityReceipt($connection, $activity, '', $accountId, $signature);
        $requestId = 'legacy-ability-' . randomToken(12);
        onlineAbilityReceipt($connection, $activity, $requestId, $accountId, $signature);
    }
    if (applicationAbilitySourceDefeated($source)) rejectOnlineCommand($connection, 409, 'Un pion KO ou mort ne peut lancer une compétence.', 'ability_source_defeated');
    $plan = onlinePrepareAbilityCasting($connection, $ability, $source, $sceneId, applicationDomainPayload($records, 'initiative:' . $sceneId), $activity);
    $cast = onlineAbilityCastingRoll($plan, $source, $identity, $arguments);
    $effectRoll = null;
    if ($cast['success']) {
        $parts = applicationDamageComponents($ability['damageComponents'] ?? []);
        $formula = ($ability['effect'] ?? '') === 'healing' ? $ability['healingFormula'] : ($parts !== [] ? applicationCombinedDamageFormula($parts) : $ability['formula']);
        $modifier = $plan['statId'] !== '' ? 0 : normalizeOnlineD100Modifier($arguments['modifier'] ?? 0);
        $formula .= $modifier !== 0 ? ($modifier > 0 ? '+' : '') . $modifier : '';
        if (!validOnlineRollFormula($formula) || strlen($formula) > 100) rejectOnlineCommand($connection, 400, 'Formule de compétence invalide.', 'invalid_roll');
        $rolled = onlineRollFormulaWithMode($formula, normalizeOnlineRollMode($arguments['rollMode'] ?? 'normal'));
        $effectRoll = onlineAbilityRollVisibility(onlineRollEntry($identity, $rolled, $ability['name'], $source['name'] ?? 'Personnage'), $source, $identity);
        onlineAppendAbilityEffectRoll($records, $pending, $effectRoll);
    }
    $cast = onlineCommitAbilityCasting($connection, $records, $pending, $plan, $cast, $source, $identity);
    onlineAppendPlayerAction($connection, $records, $pending, $identity, $sceneId, ['kind' => 'ability', 'characterName' => $source['name'] ?? 'Personnage', 'summary' => $ability['name'] . ($cast['success'] ? ' · lancement réussi' : ' · lancement échoué'), 'detail' => $cast['manaSpent'] . ' mana consommé' . ($cast['success'] ? ' · recharge ' . applicationRoundCountLabel((int) $cast['remainingRounds']) : ' · aucune recharge')]);
    $bundle = onlineAbilityRollBundle($cast, $effectRoll);
    $result = onlineStoreAbilityReceipt($records, $pending, $requestId, $accountId, $signature, [...$bundle, 'cast' => $cast, 'castSucceeded' => $cast['success'], 'initiativeUpdated' => false]);
    onlineAppendAbilityRollActions($connection, $records, $pending, $identity, $sceneId, $bundle['rolls']);
    return $result;
}
