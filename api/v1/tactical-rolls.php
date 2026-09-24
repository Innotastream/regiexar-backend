<?php
declare(strict_types=1);

function applicationRollModeLabel(mixed $mode): string {
    return normalizeOnlineRollMode($mode) === 'advantage' ? 'Avantage'
        : (normalizeOnlineRollMode($mode) === 'disadvantage' ? 'Désavantage' : 'Jet normal');
}

function applicationRollUppercase(string $value): string {
    return strtr(strtoupper($value), [
        'à' => 'À', 'â' => 'Â', 'ä' => 'Ä', 'ç' => 'Ç', 'é' => 'É', 'è' => 'È', 'ê' => 'Ê', 'ë' => 'Ë',
        'î' => 'Î', 'ï' => 'Ï', 'ô' => 'Ô', 'ö' => 'Ö', 'ù' => 'Ù', 'û' => 'Û', 'ü' => 'Ü', 'ÿ' => 'Ÿ', 'œ' => 'Œ',
    ]);
}

function onlineStatFatigueDetails(array $source, string $statId, ?array $character = null): ?array {
    if (!str_starts_with($statId, 'character-stat-') || trim((string) ($source['characterId'] ?? '')) === ''
        || ($source['followCharacter'] ?? true) === false || trim((string) ($source['linkedTokenId'] ?? '')) !== '') return null;
    $fatigue = is_array($source['fatigue'] ?? null) ? $source['fatigue'] : [];
    $current = is_numeric($fatigue['current'] ?? null) ? (float) $fatigue['current'] : 0;
    $maximum = is_numeric($fatigue['max'] ?? null) && (float) $fatigue['max'] > 0 ? (float) $fatigue['max'] : 100;
    if ($current <= $maximum / 2) return null;
    $index = findEntryIndex(is_array($source['stats'] ?? null) ? $source['stats'] : [], $statId);
    if ($index < 0 || !is_numeric($source['stats'][$index]['value'] ?? null)) return null;
    $effective = max(0, min(100, (int) $source['stats'][$index]['value']));
    $before = $effective > 0 ? min(100, $effective + 1) : null;
    $key = substr($statId, strlen('character-stat-'));
    if (is_array($character) && $character !== []) {
        $original = $key === 'mentalResistance'
            ? ($character['resources']['mentalResistance'] ?? $character['secret']['mentalResistance'] ?? null)
            : ($character['temporaryStats'][$key] ?? $character['stats'][$key] ?? null);
        if (is_numeric($original)) $before = max(0, min(100, (int) $original));
    }
    return ['current' => $current, 'max' => $maximum, 'penalty' => 1, 'before' => $before];
}

function applicationD100Comparison(array $outcome): string {
    if (!is_numeric($outcome['raw'] ?? null) || !is_numeric($outcome['threshold'] ?? null)) return '';
    $raw = (int) $outcome['raw'];
    $resultModifier = (int) ($outcome['resultModifier'] ?? 0);
    $modifier = (int) ($outcome['modifier'] ?? 0);
    $threshold = (int) $outcome['threshold'];
    $signed = static fn(int $value): string => ($value > 0 ? '+' : '−') . abs($value);
    $parts = ['dé brut ' . $raw . ($resultModifier !== 0
        ? ' ' . $signed($resultModifier) . ' = ' . (int) ($outcome['result'] ?? $raw + $resultModifier) : '')];
    $fatigue = is_array($outcome['fatigue'] ?? null) ? $outcome['fatigue'] : [];
    if (($fatigue['penalty'] ?? 0) === 1) {
        $before = is_numeric($fatigue['before'] ?? null) ? (int) $fatigue['before'] : null;
        $level = (0 + ($fatigue['current'] ?? 0)) . '/' . (0 + ($fatigue['max'] ?? 100));
        $parts[] = ($before === null ? 'fatigue ' . $level . ' : −1 · seuil après fatigue ' . (int) ($outcome['baseThreshold'] ?? $threshold)
            : 'seuil ' . $before . ' −1 (fatigue ' . $level . ')')
            . ($modifier !== 0 ? ' ' . $signed($modifier) : '') . ' = ' . $threshold;
    } else {
        $parts[] = $modifier !== 0
            ? 'seuil ' . (int) ($outcome['baseThreshold'] ?? 0) . ' ' . $signed($modifier) . ' = ' . $threshold
            : 'seuil ' . $threshold;
    }
    return implode(' · ', $parts);
}

function applicationRollPresentation(array $roll): array {
    $mode = normalizeOnlineRollMode($roll['rollMode'] ?? 'normal');
    $formula = trim((string) ($roll['formula'] ?? 'Jet')) ?: 'Jet';
    $character = trim((string) ($roll['characterName'] ?? ''));
    if ($character === '') $character = trim((string) ($roll['rollerName'] ?? ''));
    if ($character === '') $character = 'MJ';
    $storedAttempts = array_values(array_filter(
        is_array($roll['attempts'] ?? null) ? array_slice($roll['attempts'], 0, 2) : [],
        static fn (mixed $attempt): bool => is_array($attempt)
    ));
    $attempts = $mode !== 'normal' && count($storedAttempts) > 1
        ? $storedAttempts
        : [['total' => $roll['total'] ?? 0]];
    $selectedIndex = count($attempts) > 1 ? max(0, min(count($attempts) - 1, (int) ($roll['selectedIndex'] ?? 0))) : 0;
    $calculations = [];
    foreach ($attempts as $index => $attempt) {
        $value = $attempt['total'] ?? $roll['total'] ?? 0;
        $total = is_numeric($value) ? (string) (0 + $value) : '0';
        $calculations[] = [
            'formula' => $formula,
            'total' => $total,
            'ignored' => count($attempts) > 1 && $index !== $selectedIndex,
        ];
    }
    $label = trim((string) ($roll['label'] ?? 'Jet')) ?: 'Jet';
    $outcome = trim((string) ($roll['outcome']['label'] ?? ''));
    $comparison = applicationD100Comparison(is_array($roll['outcome'] ?? null) ? $roll['outcome'] : []);
    return [
        'character' => $character,
        'type' => $label . ($mode === 'normal' ? '' : ' (' . applicationRollModeLabel($mode) . ')'),
        'calculations' => $calculations,
        'comparison' => $comparison,
        'outcome' => $outcome === '' ? '' : applicationRollUppercase($outcome),
    ];
}

function applicationRollActivityFields(array $roll): array {
    $presentation = applicationRollPresentation($roll);
    $lines = [];
    foreach ($presentation['calculations'] as $calculation) {
        $lines[] = $calculation['formula'] . ' : ' . $calculation['total'] . ($calculation['ignored'] ? ' (jet ignoré)' : '');
    }
    if ($presentation['comparison'] !== '') $lines[] = $presentation['comparison'];
    if ($presentation['outcome'] !== '') $lines[] = $presentation['outcome'];
    return [
        'characterName' => $presentation['character'],
        'summary' => $presentation['type'],
        'detail' => implode("\n", $lines),
    ];
}

function applicationUniqueRolls(array $candidates): array {
    $rolls = [];
    $seen = [];
    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) continue;
        $id = trim((string) ($candidate['id'] ?? ''));
        $fingerprint = $id !== ''
            ? 'id:' . $id
            : 'value:' . hash('sha256', serialize($candidate));
        if (isset($seen[$fingerprint])) continue;
        $seen[$fingerprint] = true;
        $rolls[] = $candidate;
    }
    return $rolls;
}

function applicationResultRolls(array $result): array {
    if (is_array($result['rolls'] ?? null)) return applicationUniqueRolls($result['rolls']);
    return applicationUniqueRolls([$result['roll'] ?? null]);
}

function applicationPublicResultRolls(array $result): array {
    return array_values(array_filter(
        applicationResultRolls($result),
        static fn (array $roll): bool => ($roll['visibility'] ?? '') === 'public' || ($roll['revealed'] ?? false) === true
    ));
}

function applicationTacticalRollSignature(string $sceneId, array $arguments): string {
    $kind = is_string($arguments['kind'] ?? null) ? $arguments['kind'] : '';
    $formula = is_string($arguments['formula'] ?? null)
        ? strtolower(preg_replace('/\s+/', '', $arguments['formula']) ?? '')
        : '';
    return json_encode([
        'token.roll',
        trim($sceneId),
        trim((string) ($arguments['tokenId'] ?? '')),
        trim((string) ($arguments['characterId'] ?? '')),
        $kind,
        trim((string) ($arguments['statId'] ?? '')),
        trim((string) ($arguments['weaponId'] ?? '')),
        is_string($arguments['label'] ?? null) ? trim($arguments['label']) : '',
        $formula,
        $arguments['threshold'] ?? null,
        normalizeOnlineD100Modifier($arguments['modifier'] ?? 0),
        ($arguments['modifierMode'] ?? '') === 'result' ? 'result' : 'threshold',
        onlineRollModeForKind($arguments['rollMode'] ?? 'normal', $kind),
        is_string($arguments['visibility'] ?? null) ? $arguments['visibility'] : 'public',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function applicationTacticalRollSpecification(array $source, array $arguments): array {
    $kind = $arguments['kind'] ?? '';
    if (!is_string($kind) || !in_array($kind, ['coin', 'stat', 'hit', 'luck', 'initiative', 'damage', 'custom', 'custom-stat', 'custom-damage'], true)) throw new RuntimeException('invalid_tactical_roll_kind');
    if (array_key_exists('label', $arguments) && !validApplicationDomainText($arguments['label'], 120)) throw new RuntimeException('invalid_tactical_roll_label');
    if (array_key_exists('visibility', $arguments) && !in_array($arguments['visibility'], ['public', 'gm', 'queued'], true)) throw new RuntimeException('invalid_tactical_roll_visibility');
    $label = trim((string) ($arguments['label'] ?? ''));
    $formula = '1d100'; $threshold = null; $modifier = 0; $resultModifier = 0;
    $mode = onlineRollModeForKind($arguments['rollMode'] ?? 'normal', $kind);
    if ($kind === 'stat') {
        $stat = applicationAbilityCastingStat($source['stats'] ?? [], (string) ($arguments['statId'] ?? ''));
        if (!is_array($stat) || !is_numeric($stat['value'] ?? null)) throw new RuntimeException('token_stat_missing');
        $threshold = max(0, min(100, (int) $stat['value']));
        $label = (string) ($stat['label'] ?? 'Statistique');
    } elseif ($kind === 'hit') {
        $threshold = normalizeOnlineD100Difficulty($source['hitThreshold'] ?? null);
        if ($threshold === null) throw new RuntimeException('token_hit_missing');
        $label = 'Touché';
    } elseif ($kind === 'custom-stat') {
        if (!is_int($arguments['threshold'] ?? null) || !validApplicationDomainNumber($arguments['threshold'], 0, 100)) throw new RuntimeException('invalid_tactical_roll_threshold');
        $threshold = (int) $arguments['threshold'];
        if ($label === '') $label = 'Statistique personnalisée';
    } elseif ($kind === 'coin') {
        $label = 'Pile ou face'; $formula = '1d2';
    } elseif ($kind === 'luck') {
        $label = 'Chance';
    } elseif ($kind === 'initiative') {
        $modifier = -(int) ($source['initiativeBonus'] ?? 0);
        $formula = '1d100' . ($modifier === 0 ? '' : ($modifier > 0 ? '+' : '') . $modifier);
        $modifier = 0;
        $label = 'Initiative';
    } elseif ($kind === 'damage') {
        $weaponId = (string) ($arguments['weaponId'] ?? '');
        $weapons = normalizeOnlineWeaponAttacks($source['weaponAttacks'] ?? [], extractOnlineDamageFormulas($source['weaponText'] ?? ''));
        if ($weaponId !== '') {
            $index = findEntryIndex($weapons, $weaponId);
            if ($index < 0) throw new RuntimeException('attack_weapon_missing');
            $formula = (string) $weapons[$index]['formula'];
        } else {
            $formula = (string) ($weapons[0]['formula'] ?? $source['damageDice'] ?? '');
        }
        $label = 'Dégâts';
    } else {
        if (!validApplicationDomainText($arguments['formula'] ?? null, 100, false)) throw new RuntimeException('invalid_tactical_roll_formula');
        $formula = $arguments['formula'];
        if ($label === '') $label = $kind === 'custom-damage' ? 'Dégâts personnalisés' : 'Test personnalisé';
    }
    $formula = strtolower(preg_replace('/\s+/', '', $formula));
    if ($threshold !== null) {
        $value = normalizeOnlineD100Modifier($arguments['modifier'] ?? 0);
        $customResult = ($arguments['modifierMode'] ?? '') === 'result';
        $modifier = $customResult ? 0 : $value;
        $resultModifier = $customResult ? $value : 0;
        $formula .= $resultModifier !== 0 ? ($resultModifier > 0 ? '+' : '') . $resultModifier : '';
    } elseif ($kind === 'damage') {
        $value = normalizeOnlineD100Modifier($arguments['modifier'] ?? 0);
        $formula .= $value !== 0 ? ($value > 0 ? '+' : '') . $value : '';
    }
    if (strlen($formula) > 100 || !validOnlineRollFormula($formula)) throw new RuntimeException('invalid_tactical_roll_formula');
    return ['kind' => $kind, 'label' => onlineUtf8ByteSlice($label, 120), 'formula' => $formula, 'threshold' => $threshold, 'modifier' => $modifier,
        'resultModifier' => $resultModifier, 'modifierMode' => ($arguments['modifierMode'] ?? '') === 'result' ? 'result' : 'threshold',
        'rollMode' => $mode, 'd100RollUnder' => in_array($kind, ['stat', 'luck', 'custom-stat'], true), 'visibility' => $arguments['visibility'] ?? 'public'];
}

function applicationTacticalRollVisibility(array $roll, array $source, string $kind, string $requestedVisibility = 'public'): array {
    $private = ($source['hidden'] ?? false) === true || in_array($kind, ['damage', 'custom-damage'], true)
        || ($kind !== 'initiative' && empty($source['controllerPlayerId']) && ($source['revealDetailsToPlayers'] ?? false) !== true);
    $canonicalVisibility = in_array($requestedVisibility, ['public', 'gm', 'queued'], true) ? $requestedVisibility : 'gm';
    $roll['rollerRole'] = 'gm'; $roll['visibility'] = $private ? 'gm' : $canonicalVisibility; $roll['revealed'] = $roll['visibility'] === 'public';
    if (in_array($kind, ['damage', 'custom-damage'], true)) unset($roll['mapEvent']);
    return $roll;
}

function applicationTacticalRollReceipt(array $activity, string $requestId, string $accountId, string $signature, int $now): ?array {
    if (preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $requestId) !== 1) throw new RuntimeException('invalid_tactical_roll_request');
    $count = 0;
    foreach ($activity['resourceReceipts'] ?? [] as $receipt) {
        if (!is_array($receipt) || ($receipt['expiresAt'] ?? 0) <= $now) continue;
        $count += 1;
        if (($receipt['requestId'] ?? '') !== $requestId) continue;
        if (($receipt['accountId'] ?? '') !== $accountId) throw new RuntimeException('tactical_roll_receipt_forbidden');
        if (($receipt['kind'] ?? '') !== 'token-roll' || ($receipt['requestSignature'] ?? '') !== $signature || !is_array($receipt['result'] ?? null)) throw new RuntimeException('tactical_roll_request_mismatch');
        return [...$receipt['result'], 'deduplicated' => true];
    }
    if ($count >= XAR_RESOURCE_RECEIPT_MAXIMUM) throw new RuntimeException('tactical_roll_receipt_capacity');
    return null;
}

function onlineGmTacticalRoll(PDO $connection, array &$records, array &$pending, array $table, array $identity, array $arguments): array {
    if (($identity['effective_mode'] ?? '') !== 'gm' || ($identity['permanent_role'] ?? '') !== 'gm') rejectOnlineCommand($connection, 403, 'Ces jets tactiques sont réservés au MJ.', 'gm_required');
    $tokenId = (string) ($arguments['tokenId'] ?? ''); $characterId = (string) ($arguments['characterId'] ?? '');
    $sceneId = (string) ($arguments['sceneId'] ?? '');
    if ($tokenId !== '' && $sceneId === '') rejectOnlineCommand($connection, 409, 'La scène du jet est absente ; rouvrez la fiche.', 'tactical_roll_scene_required');
    if (($tokenId !== '' && !validApplicationDomainIdentifier($arguments['sceneId'] ?? null, 80)) || ($sceneId !== '' && !validApplicationDomainIdentifier($sceneId, 80))) rejectOnlineCommand($connection, 409, 'La scène du jet a changé ; rouvrez la fiche.', 'tactical_roll_scene_required');
    if ($tokenId !== '' && !in_array($arguments['layerId'] ?? null, ['basement', 'ground', 'upper'], true)) rejectOnlineCommand($connection, 409, 'Le niveau du jet est absent ou invalide ; rouvrez la fiche.', 'stale_token_layer');
    $sourceKey = $tokenId !== '' ? onlineTokenDomainKey($sceneId, $tokenId) : 'character:' . $characterId;
    if (!validApplicationDomainKey($sourceKey)) rejectOnlineCommand($connection, 400, 'La source du jet est invalide.', 'invalid_tactical_roll_source');
    $keys = [$sourceKey, 'activity'];
    if ($sceneId !== '') $keys = [...$keys, 'map:' . $sceneId, 'scene:' . $sceneId];
    $records = array_replace($records, applicationDomainRecords($connection, $keys));
    $activity = applicationDomainPayload($records, 'activity');
    $requestId = (string) ($arguments['requestId'] ?? ''); $accountId = (string) $identity['id'];
    $signature = applicationTacticalRollSignature($sceneId, $arguments);
    $now = (int) floor(microtime(true) * 1000);
    try { $receipt = applicationTacticalRollReceipt($activity, $requestId, $accountId, $signature, $now); }
    catch (RuntimeException $error) {
        $code = $error->getMessage(); $status = $code === 'tactical_roll_receipt_forbidden' ? 403 : ($code === 'tactical_roll_receipt_capacity' ? 429 : ($code === 'invalid_tactical_roll_request' ? 400 : 409));
        rejectOnlineCommand($connection, $status, 'Le jet ne peut pas être repris avec cette référence ; actualisez la fiche.', $code);
    }
    if ($receipt !== null) return $receipt;
    if ($sceneId !== '' && applicationDomainPayload($records, 'scene:' . $sceneId) === []) rejectOnlineCommand($connection, 409, 'Cette scène n’existe plus.', 'stale_scene');
    $source = applicationDomainPayload($records, $sourceKey);
    if ($source === []) rejectOnlineCommand($connection, 404, 'La source du jet n’existe plus.', 'tactical_roll_source_missing');
    if ($tokenId !== '') {
        $map = applicationDomainPayload($records, 'map:' . $sceneId);
        if (!onlineTokenOnActiveLayer($source, $map) || $arguments['layerId'] !== onlineTokenLayerId($source, $map)) rejectOnlineCommand($connection, 409, 'Ce pion a changé de niveau.', 'stale_token_layer');
        $owner = applicationAbilityCastingOwner($source);
        if ($owner['characterId'] !== '') {
            $records = array_replace($records, applicationDomainRecords($connection, ['character:' . $owner['characterId']]));
            $character = applicationDomainPayload($records, 'character:' . $owner['characterId']);
            if ($character !== []) $source = synchronizeOnlineCharacterToken($source, $character);
        }
        $source['controllerPlayerId'] = onlineTokenControllerIdFromRecords($connection, $records, $source);
    } else {
        $source = synchronizeOnlineCharacterToken(['characterId' => $characterId], $source);
    }
    try { $spec = applicationTacticalRollSpecification($source, $arguments); }
    catch (RuntimeException $error) { rejectOnlineCommand($connection, 400, 'Vérifiez la statistique, le nom et la formule du jet.', $error->getMessage()); }
    $rolled = onlineRollFormulaWithMode($spec['formula'], $spec['rollMode'], $spec['threshold'], $spec['modifier'], $spec['d100RollUnder']);
    $outcome = $spec['threshold'] !== null
        ? classifyOnlineD100Outcome($rolled['rawD100'] ?? null, $spec['threshold'], $spec['modifier'], $spec['resultModifier'], $spec['kind'] !== 'hit')
        : ($spec['kind'] === 'luck' ? classifyOnlineD100Outcome($rolled['rawD100'] ?? null) : null);
    if ($outcome !== null && $spec['threshold'] !== null) $outcome['resultCustomized'] = $spec['modifierMode'] === 'result';
    if ($outcome !== null && $spec['kind'] === 'stat') {
        $fatigue = onlineStatFatigueDetails($source, (string) ($arguments['statId'] ?? ''), $character ?? null);
        if ($fatigue !== null) $outcome['fatigue'] = $fatigue;
    }
    $roll = onlineRollEntry($identity, $rolled, $spec['label'], (string) ($source['name'] ?? 'Personnage'), $outcome);
    $roll['diceAppearance'] = onlineDiceAppearance($source, !empty($source['controllerPlayerId']), $character ?? null);
    if ($tokenId !== '' && !in_array($spec['kind'], ['damage', 'custom-damage'], true)) $roll['mapEvent'] = ['kind' => 'roll', 'sceneId' => $sceneId, 'layerId' => onlineTokenLayerId($source, $map), 'anchorTokenId' => $tokenId, 'tokenId' => $tokenId, 'value' => $outcome['result'] ?? $rolled['total'], 'label' => $spec['label'], 'tone' => $outcome['code'] ?? 'normal', 'diceAppearance' => $roll['diceAppearance']];
    $roll = applicationTacticalRollVisibility($roll, $source, $spec['kind'], $spec['visibility']);
    if ($sceneId !== '' && $sceneId !== onlineActiveSceneId($table)) {
        $roll['visibility'] = 'gm';
        $roll['revealed'] = false;
    }
    $activity['rolls'] = array_slice([$roll, ...($activity['rolls'] ?? [])], 0, 100);
    queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
    $action = onlineAppendPlayerAction($connection, $records, $pending, $identity, $sceneId, ['kind' => 'roll', ...applicationRollActivityFields($roll)]);
    $activity = $pending['activity']['payload'] ?? $activity;
    $receipts = array_values(array_filter($activity['resourceReceipts'] ?? [], static fn($entry): bool => is_array($entry) && ($entry['expiresAt'] ?? 0) > $now));
    $initiativeUpdated = $tokenId !== '' && $spec['kind'] === 'initiative';
    if ($initiativeUpdated) onlineRecordTokenInitiative($connection, $records, $pending, $sceneId, $source, $rolled['total']);
    $result = ['roll' => $roll, 'initiativeUpdated' => $initiativeUpdated];
    $receipts[] = ['kind' => 'token-roll', 'requestId' => $requestId, 'accountId' => $accountId, 'actionId' => $action['id'], 'sourceKey' => $tokenId !== '' ? $tokenId : $characterId, 'requestSignature' => $signature, 'expiresAt' => $now + XAR_RESOURCE_RECEIPT_TTL_MILLISECONDS, 'result' => $result];
    $activity['resourceReceipts'] = $receipts; queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
    return [...$result, 'deduplicated' => false];
}
