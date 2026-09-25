<?php
declare(strict_types=1);

require_once __DIR__ . '/ability-validation.php';

function validApplicationAbilityCastingFields(array $ability): bool {
    foreach (['manaCost' => 1000000000, 'hpCost' => 1000000000, 'fatigueCost' => 1000000000, 'difficultyIncrement' => 100, 'cooldownRounds' => 999] as $key => $limit) {
        if (array_key_exists($key, $ability) && (!is_int($ability[$key]) || $ability[$key] < 0 || $ability[$key] > $limit)) return false;
    }
    if (array_key_exists('restRecharge', $ability) && !in_array($ability['restRecharge'], ['none', 'short', 'long'], true)) return false;
    if (array_key_exists('usesPerRest', $ability) && (!is_int($ability['usesPerRest']) || $ability['usesPerRest'] < 1 || $ability['usesPerRest'] > 100)) return false;
    foreach (['reusableInTurn', 'reducedFailureCooldown'] as $key) if (array_key_exists($key, $ability) && !is_bool($ability[$key])) return false;
    if (array_key_exists('castingStatId', $ability) && $ability['castingStatId'] !== '' && !validApplicationDomainIdentifier($ability['castingStatId'], 120)) return false;
    return !array_key_exists('image', $ability) || $ability['image'] === null || validApplicationDomainText($ability['image'], 4096);
}

function applicationAbilityCastingFields(array $ability): array {
    $result = [];
    if (array_key_exists('restRecharge', $ability)) $result['restRecharge'] = in_array($ability['restRecharge'], ['short', 'long'], true) ? $ability['restRecharge'] : 'none';
    if (array_key_exists('usesPerRest', $ability)) $result['usesPerRest'] = max(1, min(100, (int) $ability['usesPerRest']));
    if (array_key_exists('reusableInTurn', $ability)) $result['reusableInTurn'] = $ability['reusableInTurn'] === true;
    foreach (['manaCost' => 1000000000, 'hpCost' => 1000000000, 'fatigueCost' => 1000000000, 'difficultyIncrement' => 100, 'cooldownRounds' => 999] as $key => $limit) {
        if (array_key_exists($key, $ability)) $result[$key] = max(0, min($limit, is_numeric($ability[$key]) ? (int) $ability[$key] : 0));
    }
    if (array_key_exists('castingStatId', $ability) && ($ability['castingStatId'] === '' || validApplicationDomainIdentifier($ability['castingStatId'], 120))) $result['castingStatId'] = $ability['castingStatId'];
    if (array_key_exists('reducedFailureCooldown', $ability)) {
        $result['reducedFailureCooldown'] = $ability['reducedFailureCooldown'] === true
            && is_string($ability['castingStatId'] ?? null)
            && $ability['castingStatId'] !== ''
            && validApplicationDomainIdentifier($ability['castingStatId'], 120);
    }
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

function applicationAbilityRechargeActivityDetail(array $cast): string {
    $remaining = max(0, (int) ($cast['remainingRounds'] ?? 0));
    $details = $remaining > 0 ? ' · recharge ' . applicationRoundCountLabel($remaining) : '';
    if (($cast['success'] ?? false) === true && in_array($cast['restRecharge'] ?? '', ['short', 'long'], true)) {
        $left = max(0, (int) ($cast['restUseLimit'] ?? 1) - (int) ($cast['restUseCount'] ?? 1));
        return $details . ($left > 0 ? ' · ' . $left . ' utilisation(s) restantes avant repos'
            : ' · repos ' . (($cast['restRecharge'] ?? '') === 'short' ? 'court ou long' : 'long') . ' requis' . (($cast['reusableInTurn'] ?? false) ? ' après ce tour' : ''));
    }
    return $details !== '' ? $details : ' · aucune recharge';
}

function applicationAbilityRequestSignature(string $route, string $sceneId, array $arguments, bool $hasCastingCheck): string {
    $attack = $route === 'token.attack';
    $simpleRoll = $route === 'token.roll';
    $sourceId = trim((string) ($arguments['sourceTokenId'] ?? ''));
    if ($sourceId === '') $sourceId = trim((string) ($arguments['tokenId'] ?? ''));
    $abilityId = trim((string) ($arguments['abilityId'] ?? ''));
    if ($abilityId === '') $abilityId = trim((string) ($arguments['attackId'] ?? ''));
    $modifierValue = 0;
    $modifierMode = 'threshold';
    if ($hasCastingCheck) {
        $modifierValue = normalizeOnlineD100Modifier($attack
            ? ($arguments['hitModifier'] ?? 0)
            : ($arguments['hitModifier'] ?? $arguments['castingModifier'] ?? $arguments['modifier'] ?? 0));
        $modeValue = $attack
            ? ($arguments['hitModifierMode'] ?? '')
            : ($arguments['hitModifierMode'] ?? $arguments['castingModifierMode'] ?? $arguments['modifierMode'] ?? '');
        $modifierMode = $modeValue === 'result' ? 'result' : 'threshold';
    }
    return json_encode([
        $route,
        $sceneId,
        $sourceId,
        (string) ($arguments['characterId'] ?? ''),
        $abilityId,
        $attack ? (string) ($arguments['statId'] ?? '') : '',
        $attack && ($arguments['statId'] ?? '') === 'weapon-skill' ? ($arguments['weaponSkill'] ?? null) : null,
        (string) ($arguments['targetTokenId'] ?? ''),
        ($arguments['returnForm'] ?? false) === true,
        $hasCastingCheck ? normalizeOnlineRollMode($arguments['rollMode'] ?? 'normal') : 'normal',
        $modifierValue,
        $modifierMode,
        $simpleRoll && !$hasCastingCheck ? normalizeOnlineD100Modifier($arguments['modifier'] ?? 0) : 0,
        $attack ? normalizeOnlineD100Modifier($arguments['damageModifier'] ?? 0) : 0,
        $attack && ($arguments['opposed'] ?? false) === true,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function applicationAbilityReceiptHasCastingCheck(array $receipt): bool {
    return trim((string) ($receipt['result']['cast']['statId'] ?? '')) !== '';
}

// A round is a complete initiative cycle. Stopping combat freezes the existing
// counter; beginning a new combat explicitly clears its timers in the MJ flow.
function applicationAbilityTurnKey(array $initiative): string {
    return ($initiative['active'] ?? false) === true ? ($initiative['turnSerial'] ?? 0) . ':' . ($initiative['round'] ?? 1) . ':' . ($initiative['order'][$initiative['currentIndex'] ?? 0] ?? '') : '';
}

function applicationAbilityCastingPlan(array $ability, array $source, string $sceneId, array $initiative, array $timers, ?string $legacyStatId = null): array {
    if (!validApplicationAbilityCastingFields($ability)) throw new RuntimeException('ability_cast_invalid');
    $owner = applicationAbilityCastingOwner($source);
    $round = max(1, (int) ($initiative['round'] ?? 1));
    $remaining = 0; $useCount = 0; $restUseCount = 0; $turnKey = applicationAbilityTurnKey($initiative);
    $restUseLimit = (int) ($ability['usesPerRest'] ?? 1);
    foreach ($timers as $timer) {
        if (!is_array($timer) || ($timer['sceneId'] ?? '') !== $sceneId || ($timer['abilityId'] ?? '') !== ($ability['id'] ?? '')) continue;
        if ($owner['characterId'] !== '' ? ($timer['characterId'] ?? '') !== $owner['characterId'] : (($timer['characterId'] ?? '') !== '' || ($timer['tokenId'] ?? '') !== $owner['tokenId'])) continue;
        $sameTurn = $turnKey !== '' && ($timer['turnKey'] ?? '') === $turnKey;
        if ($sameTurn) $useCount = (int) ($timer['useCount'] ?? 0);
        if (($timer['reusableInTurn'] ?? false) && $sameTurn) continue;
        if (($timer['cooldownActive'] ?? true) === false) continue;
        if (in_array($timer['restRecharge'] ?? '', ['short', 'long'], true)) {
            $restUseCount = max($restUseCount, (int) ($timer['restUseCount'] ?? 1));
        }
        $remaining = max($remaining, $restUseCount >= $restUseLimit ? 9999 : max(0, (int) ($timer['readyRound'] ?? 1) - $round));
    }
    if ($remaining > 0) throw new RuntimeException('ability_on_cooldown');
    $manaCost = (int) ($ability['manaCost'] ?? 0);
    $availableMana = max(0, min((float) ($source['maxMana'] ?? 0), (float) ($source['mana'] ?? 0)));
    if ($availableMana < $manaCost) throw new RuntimeException('ability_mana_insufficient');
    $hpCost = (int) ($ability['hpCost'] ?? 0); $fatigueCost = (int) ($ability['fatigueCost'] ?? 0);
    if ((float) ($source['hp'] ?? 0) < $hpCost) throw new RuntimeException('ability_hp_insufficient');
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
        'trackUses' => ($ability['reusableInTurn'] ?? false) === true || ($ability['difficultyIncrement'] ?? 0) > 0, 'turnKey' => $turnKey, 'useCount' => $useCount + 1, 'difficultyPenalty' => min(100, $useCount * (int) ($ability['difficultyIncrement'] ?? 0)),
        'hpCost' => $hpCost, 'fatigueCost' => $fatigueCost, 'restRecharge' => $ability['restRecharge'] ?? 'none', 'restUseCount' => $restUseCount, 'restUseLimit' => $restUseLimit, 'reusableInTurn' => ($ability['reusableInTurn'] ?? false) === true,
        'completionCue' => ($ability['effect'] ?? 'damage') !== 'complex' ? normalizeApplicationAbilityCompletionCue($ability['completionCue'] ?? null) : null,
        'usedRound' => $round, 'cooldownRounds' => (int) ($ability['cooldownRounds'] ?? 0), 'manaCost' => $manaCost,
        'reducedFailureEnabled' => $statId !== '' && ($ability['reducedFailureCooldown'] ?? false) === true,
        'statId' => $statId, 'statLabel' => (string) ($stat['label'] ?? ''), 'threshold' => $stat === null ? null : max(0, min(100, (int) $stat['value']))];
}

function applicationAbilityFailedCooldownRounds(array $plan, array $cast): int {
    return ($cast['success'] ?? false) !== true
        && ($plan['reducedFailureEnabled'] ?? false) === true
        && trim((string) ($plan['statId'] ?? '')) !== '' ? 1 : 0;
}

function onlinePrepareAbilityCasting(PDO $connection, array $ability, array $source, string $sceneId, array $initiative, array $activity, ?string $legacyStatId = null): array {
    try { return applicationAbilityCastingPlan($ability, $source, $sceneId, $initiative, $activity['actionTimers'] ?? [], $legacyStatId); }
    catch (RuntimeException $error) {
        $messages = ['ability_on_cooldown' => 'Cette compétence est encore en recharge.', 'ability_mana_insufficient' => 'Mana insuffisant pour tenter cette compétence.',
            'ability_hp_insufficient' => 'PV insuffisants pour cette compétence.',
            'ability_cast_stat_missing' => 'La caractéristique de lancement n’existe plus sur cette fiche.', 'ability_cast_invalid' => 'Les règles de lancement sont invalides.'];
        rejectOnlineCommand($connection, 409, $messages[$error->getMessage()] ?? 'Cette compétence ne peut pas être lancée.', $error->getMessage());
    }
}

function onlineAbilityCastingRoll(array $plan, array $source, array $identity, array $arguments = [], ?string $layerId = null, ?array $character = null): array {
    if ($plan['statId'] === '') return ['success' => true, 'statId' => '', 'statLabel' => '', 'outcome' => null, 'roll' => null];
    $modifierValue = normalizeOnlineD100Modifier($arguments['hitModifier'] ?? $arguments['castingModifier'] ?? $arguments['modifier'] ?? 0);
    $modifierMode = ($arguments['hitModifierMode'] ?? $arguments['castingModifierMode'] ?? $arguments['modifierMode'] ?? '') === 'result' ? 'result' : 'threshold';
    $thresholdModifier = ($modifierMode === 'threshold' ? $modifierValue : 0) - ($plan['difficultyPenalty'] ?? 0);
    $resultModifier = $modifierMode === 'result' ? $modifierValue : 0;
    $formula = '1d100' . ($resultModifier !== 0 ? ($resultModifier > 0 ? '+' : '') . $resultModifier : '');
    $rolled = onlineRollFormulaWithMode($formula, normalizeOnlineRollMode($arguments['rollMode'] ?? 'normal'), $plan['threshold'], $thresholdModifier, true);
    $outcome = classifyOnlineD100Outcome($rolled['rawD100'] ?? null, $plan['threshold'], $thresholdModifier, $resultModifier);
    if ($outcome !== null) {
        $fatigue = onlineStatFatigueDetails($source, (string) $plan['statId'], $character);
        if ($fatigue !== null) $outcome['fatigue'] = $fatigue;
    }
    if ($outcome !== null) $outcome['resultCustomized'] = $modifierMode === 'result';
    $roll = onlineRollEntry($identity, $rolled, $plan['label'] . ' · Lancement · ' . $plan['statLabel'], (string) ($source['name'] ?? 'Personnage'), $outcome);
    $roll = onlineAbilityRollVisibility($roll, $source, $identity);
    if (!empty($source['id'])) $roll['mapEvent'] = [
        'kind' => 'roll', 'sceneId' => $plan['sceneId'],
        'layerId' => onlineTokenLayerId($source, ['activeLayerId' => $layerId]),
        'anchorTokenId' => $source['id'], 'tokenId' => $source['id'],
        'value' => $outcome['result'] ?? $rolled['rawD100'] ?? $rolled['total'],
        'label' => 'Lancement', 'tone' => $outcome['code'] ?? 'normal'
    ];
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

// Apply attempt costs and/or recharge in the same locked transaction. Read
// pending payloads first so healing oneself never restores resources just spent.
function appendApplicationAbilityCueEvent(array &$activity, mixed $cue, string $sceneId, string $layerId, string $sourceTokenId, string $visibility = 'public'): string {
    if (!is_array($cue) || !validApplicationAbilityCompletionCue($cue) || !is_array($cue['sound'] ?? null)) return '';
    $now = (int) floor(microtime(true) * 1000);
    $id = 'cue-' . bin2hex(random_bytes(12));
    $events = array_values(array_filter(is_array($activity['abilityCueEvents'] ?? null) ? $activity['abilityCueEvents'] : [],
        static fn (mixed $event): bool => is_array($event) && (int) ($event['expiresAt'] ?? 0) > $now));
    $events[] = ['id' => $id, 'sceneId' => $sceneId, 'layerId' => $layerId, 'sourceTokenId' => $sourceTokenId,
        'visibility' => $visibility === 'gm' ? 'gm' : 'public',
        'createdAt' => $now, 'expiresAt' => $now + 30000, 'completionCue' => $cue];
    $activity['abilityCueEvents'] = array_slice($events, -40);
    return $id;
}

function onlineCommitAbilityCasting(PDO $connection, array &$records, array &$pending, array $plan, array $cast, array $source, array $identity, bool $recordRoll = true, bool $payCosts = true, bool $applyRecharge = true): array {
    $now = (int) floor(microtime(true) * 1000);
    if ($payCosts) {
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
    foreach (['hpCost' => 'hp', 'fatigueCost' => 'fatigue'] as $costKey => $resource) {
        $amount = (int) ($plan[$costKey] ?? 0);
        if ($amount === 0) continue;
        $characterKey = $plan['characterId'] !== '' ? 'character:' . $plan['characterId'] : '';
        $sourceKey = !empty($source['id']) ? onlineTokenDomainKey($plan['sceneId'], $source['id']) : '';
        $key = $characterKey ?: $sourceKey;
        $value = $pending[$key]['payload'] ?? applicationDomainPayload($records, $key);
        if ($resource === 'fatigue') {
            $fatigue = $value['fatigue'] ?? ['current' => 0, 'max' => 100];
            $fatigue['current'] = (float) ($fatigue['current'] ?? 0) + $amount;
            $value['fatigue'] = $fatigue;
        } else {
            $hp = (float) ($characterKey !== '' ? ($value['resources']['hp'] ?? 0) : ($value['hp'] ?? 0));
            if ($hp < $amount) rejectOnlineCommand($connection, 409, 'PV insuffisants.', 'ability_hp_insufficient');
            if ($characterKey !== '') $value['resources']['hp'] = $hp - $amount; else $value['hp'] = $hp - $amount;
        }
        $value['_updatedAt'] = $now;
        queueOnlineDomainUpsert($pending, $records, $key, $value);
        if ($characterKey !== '') {
            $related = applicationCharacterTokenDomainRecords($connection, $plan['characterId']);
            $records = array_replace($records, $related);
            foreach ($related as $tokenKey => $record) {
                $token = $pending[$tokenKey]['payload'] ?? applicationDomainPayload($records, $tokenKey);
                if (($token['characterId'] ?? '') !== $plan['characterId'] || ($token['followCharacter'] ?? true) === false || !empty($token['linkedTokenId'])) continue;
                queueOnlineDomainUpsert($pending, $records, $tokenKey, synchronizeOnlineCharacterToken($token, $value));
            }
        }
    }
    }
    $cost = (int) $plan['manaCost'];
    $activity = $pending['activity']['payload'] ?? applicationDomainPayload($records, 'activity');
    if ($recordRoll && is_array($cast['roll'] ?? null)) $activity['rolls'] = array_slice([$cast['roll'], ...($activity['rolls'] ?? [])], 0, 100);
    if (!$applyRecharge) {
        queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
        return [...$cast, 'manaSpent' => $cost, 'hpSpent' => $plan['hpCost'] ?? 0, 'fatigueGained' => $plan['fatigueCost'] ?? 0,
            'difficultyPenalty' => $plan['difficultyPenalty'] ?? 0, 'cooldownRounds' => (int) $plan['cooldownRounds'], 'remainingRounds' => 0, 'pendingValidation' => true];
    }
    unset($cast['pendingValidation']);
    $failedCooldown = applicationAbilityFailedCooldownRounds($plan, $cast);
    $remaining = $cast['success'] ? (int) $plan['cooldownRounds'] : $failedCooldown;
    $retainCooldown = false; $old = [];
    if ($remaining > 0 || (($plan['trackUses'] ?? false) && ($plan['turnKey'] ?? '') !== '') || ($cast['success'] && in_array($plan['restRecharge'] ?? '', ['short', 'long'], true))) {
        $isGm = ($identity['effective_mode'] ?? '') === 'gm' && ($identity['permanent_role'] ?? '') === 'gm';
        $timerOwner = $isGm ? (onlineTokenControllerIdFromRecords($connection, $records, $source) ?: (string) $identity['id']) : (string) $identity['id'];
        $timers = array_values($activity['actionTimers'] ?? []);
        $index = null;
        foreach ($timers as $i => $timer) if (($timer['sceneId'] ?? '') === $plan['sceneId'] && ($timer['abilityId'] ?? '') === $plan['abilityId'] && ($timer['characterId'] ?? '') === $plan['characterId'] && ($timer['tokenId'] ?? '') === $plan['tokenId']) { $index = $i; break; }
        if ($index === null && count($timers) >= 300) rejectOnlineCommand($connection, 409, 'La table a atteint sa limite de recharges.', 'timer_limit');
        $old = $index !== null ? $timers[$index] : [];
        $retainCooldown = !$cast['success'] && ($old['cooldownActive'] ?? false) && (($old['readyRound'] ?? 0) > $plan['usedRound'] || in_array($old['restRecharge'] ?? '', ['short', 'long'], true));
        if ($retainCooldown) $remaining = max($failedCooldown, ($old['readyRound'] ?? $plan['usedRound']) - $plan['usedRound']);
        $timer = ['id' => $index !== null ? $timers[$index]['id'] : 'timer-' . randomToken(9), 'sceneId' => $plan['sceneId'], 'abilityId' => $plan['abilityId'],
            'characterId' => $plan['characterId'], 'tokenId' => $plan['tokenId'], 'label' => $plan['label'], 'cooldown' => $retainCooldown ? max($old['cooldown'], $failedCooldown) : $remaining,
            'turnKey' => $plan['turnKey'], 'useCount' => $plan['useCount'], 'reusableInTurn' => $failedCooldown > 0 ? false : $plan['reusableInTurn'],
            'restRecharge' => $retainCooldown ? $old['restRecharge'] : ($cast['success'] ? $plan['restRecharge'] : 'none'),
            'restUseCount' => $cast['success'] && in_array($plan['restRecharge'], ['short', 'long'], true) ? (int) ($old['restUseCount'] ?? (in_array($old['restRecharge'] ?? '', ['short', 'long'], true) ? 1 : 0)) + 1 : (int) ($old['restUseCount'] ?? (in_array($old['restRecharge'] ?? '', ['short', 'long'], true) ? 1 : 0)),
            'restUseLimit' => $plan['restUseLimit'], 'cooldownActive' => $cast['success'] || $retainCooldown || $failedCooldown > 0,
            'usedRound' => $retainCooldown ? $old['usedRound'] : $plan['usedRound'], 'readyRound' => $retainCooldown ? max($old['readyRound'], $plan['usedRound'] + $failedCooldown) : $plan['usedRound'] + $remaining, 'ownerPlayerId' => $timerOwner,
            'ownerLabel' => $source['name'] ?? 'Personnage', 'visibility' => 'private', 'createdAt' => $index !== null ? ($timers[$index]['createdAt'] ?? gmdate('c')) : gmdate('c'), 'updatedAt' => gmdate('c')];
        if ($index !== null) $timers[$index] = $timer; else array_unshift($timers, $timer);
        $activity['actionTimers'] = $timers;
    }
    $cueEventId = ($cast['success'] ?? false) && is_array($plan['completionCue']['sound'] ?? null)
        ? appendApplicationAbilityCueEvent($activity, $plan['completionCue'], (string) $plan['sceneId'],
            (string) ($cast['roll']['mapEvent']['layerId'] ?? $source['layerId'] ?? 'ground'), (string) ($source['id'] ?? ''),
            ($source['hidden'] ?? false) || ($cast['roll']['visibility'] ?? '') === 'gm' ? 'gm' : 'public') : '';
    queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
    return [...$cast, 'manaSpent' => $cost, 'hpSpent' => $plan['hpCost'] ?? 0, 'fatigueGained' => $plan['fatigueCost'] ?? 0, 'difficultyPenalty' => $plan['difficultyPenalty'] ?? 0,
        'restRecharge' => $failedCooldown > 0 && !$retainCooldown ? 'none' : ($retainCooldown ? ($old['restRecharge'] ?? 'none') : ($plan['restRecharge'] ?? 'none')),
        'restUseCount' => $timer['restUseCount'] ?? 0, 'restUseLimit' => $plan['restUseLimit'], 'reusableInTurn' => $timer['reusableInTurn'] ?? false,
        'cooldownRounds' => (int) $plan['cooldownRounds'], 'remainingRounds' => $remaining,
        ...($cueEventId !== '' ? ['completionCue' => $plan['completionCue'], 'completionCueEventId' => $cueEventId] : []),
        'reducedFailureEnabled' => ($plan['reducedFailureEnabled'] ?? false) === true, 'reducedFailureApplied' => $failedCooldown > 0];
}

function onlineAbilityReceipt(PDO $connection, array $activity, string $requestId, string $accountId, mixed $signature, bool $isGm = false): ?array {
    if (preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $requestId) !== 1) rejectOnlineCommand($connection, 400, 'Actualisez le client pour sécuriser le lancement de cette compétence.', 'invalid_ability_request');
    $now = (int) floor(microtime(true) * 1000); $count = 0;
    foreach ($activity['resourceReceipts'] ?? [] as $receipt) {
        if (!is_array($receipt) || ($receipt['expiresAt'] ?? 0) <= $now) continue;
        $count += 1;
        if (($receipt['requestId'] ?? '') !== $requestId) continue;
        if (($receipt['accountId'] ?? '') !== $accountId) rejectOnlineCommand($connection, 403, 'Ce reçu appartient à un autre compte.', 'ability_receipt_forbidden');
        $expectedSignature = is_callable($signature) ? $signature($receipt) : (string) $signature;
        if (($receipt['requestSignature'] ?? '') !== $expectedSignature || ($receipt['kind'] ?? '') !== 'ability-cast' || !is_array($receipt['result'] ?? null)) rejectOnlineCommand($connection, 409, 'Cette référence désigne un autre lancement.', 'ability_request_mismatch');
        onlineAssertAbilityReceiptVisibility($connection, $receipt['result'], $isGm);
        return [...$receipt['result'], 'deduplicated' => true];
    }
    if ($count + count($activity['pendingAbilityCasts'] ?? []) >= XAR_RESOURCE_RECEIPT_MAXIMUM) rejectOnlineCommand($connection, 429, 'Le journal de sécurité des compétences est plein.', 'ability_receipt_capacity');
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

function onlineSimpleAbilityRoll(PDO $connection, array &$records, array &$pending, array $table, array $identity, array $arguments, bool $isGm, ?array $continuation = null): array {
    $sceneId = (string) ($arguments['sceneId'] ?? onlineActiveSceneId($table)); $accountId = (string) $identity['id'];
    $records = applicationDomainRecords($connection);
    $activity = applicationDomainPayload($records, 'activity');
    $receiptSignature = static fn(array $receipt): string => applicationAbilityRequestSignature(
        'token.roll', $sceneId, $arguments, applicationAbilityReceiptHasCastingCheck($receipt)
    );
    $requestId = (string) ($arguments['requestId'] ?? '');
    if ($continuation === null && $requestId !== '') { $receipt = onlineAbilityReceipt($connection, $activity, $requestId, $accountId, $receiptSignature, $isGm); if ($receipt !== null) return $receipt; }
    if (($table['tacticalSync']['paused'] ?? false) === true) rejectOnlineCommand($connection, 423, 'La table est verrouillée.', 'table_locked');
    if (!$isGm && $sceneId !== onlineActiveSceneId($table)) rejectOnlineCommand($connection, 409, 'La scène a changé.', 'stale_scene');
    $tokenId = (string) ($arguments['tokenId'] ?? '');
    $sourceLayerId = null;
    if ($tokenId !== '') {
        $source = applicationDomainPayload($records, onlineTokenDomainKey($sceneId, $tokenId));
        $map = applicationDomainPayload($records, 'map:' . $sceneId);
        if ($source === [] || !onlineTokenOnActiveLayer($source, $map) || (!empty($arguments['layerId']) && $arguments['layerId'] !== onlineTokenLayerId($source, $map))) rejectOnlineCommand($connection, 409, 'Ce pion a changé de niveau.', 'stale_token_layer');
        $sourceLayerId = onlineTokenLayerId($source, $map);
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
    $extended = ($ability['hpCost'] ?? 0) > 0 || ($ability['fatigueCost'] ?? 0) > 0 || ($ability['difficultyIncrement'] ?? 0) > 0 || in_array($ability['restRecharge'] ?? '', ['short', 'long'], true) || array_key_exists('castingStatId', $ability) || ($ability['manaCost'] ?? 0) > 0 || ($ability['cooldownRounds'] ?? 0) > 0;
    $hasCastingCheck = trim((string) ($ability['castingStatId'] ?? '')) !== '';
    $signature = applicationAbilityRequestSignature('token.roll', $sceneId, $arguments, $hasCastingCheck);
    if ($requestId === '') {
        if ($extended) onlineAbilityReceipt($connection, $activity, '', $accountId, $signature, $isGm);
        $requestId = 'legacy-ability-' . randomToken(12);
        onlineAbilityReceipt($connection, $activity, $requestId, $accountId, $signature, $isGm);
    }
    if ($continuation === null && applicationAbilitySourceDefeated($source)) rejectOnlineCommand($connection, 409, 'Un pion KO ou mort ne peut lancer une compétence.', 'ability_source_defeated');
    if ($continuation === null) onlineAssertAbilityValidationAvailable($connection, $activity, $ability, $source);
    $plan = $continuation['plan'] ?? onlinePrepareAbilityCasting($connection, $ability, $source, $sceneId, applicationDomainPayload($records, 'initiative:' . $sceneId), $activity);
    $cast = $continuation['cast'] ?? onlineAbilityCastingRoll($plan, $source, $identity, $arguments, $sourceLayerId, $character);
    if ($isGm && $sceneId !== '' && $sceneId !== onlineActiveSceneId($table) && is_array($cast['roll'] ?? null)) {
        $cast['roll']['visibility'] = 'gm'; $cast['roll']['revealed'] = false;
    }
    if ($continuation === null && ($cast['outcome']['requiresGmValidation'] ?? false) === true) {
        return onlineDeferAbilityCasting($connection, $records, $pending, 'token.roll', $identity, $arguments, $isGm, $ability, $source, $plan, $cast, $signature);
    }
    $effectRoll = null;
    if ($cast['success']) {
        $parts = applicationDamageComponents($ability['damageComponents'] ?? []);
        $formula = ($ability['effect'] ?? '') === 'healing' ? $ability['healingFormula'] : ($parts !== [] ? applicationCombinedDamageFormula($parts) : $ability['formula']);
        $modifier = $plan['statId'] !== '' ? 0 : normalizeOnlineD100Modifier($arguments['modifier'] ?? 0);
        $formula .= $modifier !== 0 ? ($modifier > 0 ? '+' : '') . $modifier : '';
        if (!validOnlineRollFormula($formula) || strlen($formula) > 100) rejectOnlineCommand($connection, 400, 'Formule de compétence invalide.', 'invalid_roll');
        $rolled = onlineRollFormulaWithMode($formula, 'normal');
        $effectRoll = onlineAbilityRollVisibility(onlineRollEntry($identity, $rolled, $ability['name'], $source['name'] ?? 'Personnage'), $source, $identity);
        if (($isGm && $sceneId !== '' && $sceneId !== onlineActiveSceneId($table)) || ($continuation !== null && ($cast['roll']['visibility'] ?? '') === 'gm')) {
            $effectRoll['visibility'] = 'gm'; $effectRoll['revealed'] = false;
        }
        onlineAppendAbilityEffectRoll($records, $pending, $effectRoll);
    }
    $cast = onlineCommitAbilityCasting($connection, $records, $pending, $plan, $cast, $source, $identity, true, $continuation === null);
    onlineAppendPlayerAction($connection, $records, $pending, $identity, $sceneId, ['kind' => 'ability', 'characterName' => $source['name'] ?? 'Personnage', 'summary' => $ability['name'] . ($cast['success'] ? ' · lancement réussi' : ' · lancement échoué'), 'detail' => $cast['manaSpent'] . ' mana consommé' . applicationAbilityRechargeActivityDetail($cast)]);
    $bundle = onlineAbilityRollBundle($cast, $effectRoll);
    $result = [...$bundle, 'cast' => $cast, 'castSucceeded' => $cast['success'], 'initiativeUpdated' => false];
    if ($continuation === null) $result = onlineStoreAbilityReceipt($records, $pending, $requestId, $accountId, $signature, $result);
    onlineAppendAbilityRollActions($connection, $records, $pending, $identity, $sceneId, $bundle['rolls']);
    return $result;
}
