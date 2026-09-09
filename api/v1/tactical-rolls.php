<?php
declare(strict_types=1);

function applicationTacticalRollSignature(string $sceneId, array $arguments): string {
    return json_encode(['token.roll', $sceneId, $arguments['tokenId'] ?? '', $arguments['characterId'] ?? '', $arguments['kind'] ?? '', $arguments['statId'] ?? '', $arguments['weaponId'] ?? ''], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function applicationTacticalRollSpecification(array $source, array $arguments): array {
    $kind = $arguments['kind'] ?? '';
    if (!is_string($kind) || !in_array($kind, ['stat', 'hit', 'luck', 'damage', 'custom', 'custom-stat', 'custom-damage'], true)) throw new RuntimeException('invalid_tactical_roll_kind');
    if (array_key_exists('label', $arguments) && !validApplicationDomainText($arguments['label'], 120)) throw new RuntimeException('invalid_tactical_roll_label');
    if (array_key_exists('visibility', $arguments) && !in_array($arguments['visibility'], ['public', 'gm', 'queued'], true)) throw new RuntimeException('invalid_tactical_roll_visibility');
    $label = trim((string) ($arguments['label'] ?? ''));
    $formula = '1d100'; $threshold = null; $modifier = 0; $resultModifier = 0;
    $mode = $kind === 'luck' ? 'normal' : normalizeOnlineRollMode($arguments['rollMode'] ?? 'normal');
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
    } elseif ($kind === 'luck') {
        $label = 'Chance';
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
        $label = 'Dégâts présumés';
    } else {
        if (!validApplicationDomainText($arguments['formula'] ?? null, 100, false)) throw new RuntimeException('invalid_tactical_roll_formula');
        $formula = $arguments['formula'];
        if ($label === '') $label = $kind === 'custom-damage' ? 'Dégâts personnalisés présumés' : 'Jet personnalisé';
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
        'resultModifier' => $resultModifier, 'modifierMode' => ($arguments['modifierMode'] ?? '') === 'result' ? 'result' : 'threshold', 'rollMode' => $mode, 'visibility' => $arguments['visibility'] ?? 'public'];
}

function applicationTacticalRollVisibility(array $roll, array $source, string $kind, string $requestedVisibility = 'public'): array {
    $private = ($source['hidden'] ?? false) === true || in_array($kind, ['damage', 'custom-damage'], true)
        || (empty($source['controllerPlayerId']) && ($source['revealDetailsToPlayers'] ?? false) !== true);
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
    if ($sceneId !== onlineActiveSceneId($table)) rejectOnlineCommand($connection, 409, 'La scène du jet a changé ; rouvrez la fiche.', 'stale_scene');
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
    $rolled = onlineRollFormulaWithMode($spec['formula'], $spec['rollMode'], $spec['threshold'], $spec['modifier']);
    $outcome = $spec['threshold'] !== null
        ? classifyOnlineD100Outcome($rolled['rawD100'] ?? null, $spec['threshold'], $spec['modifier'], $spec['resultModifier'])
        : ($spec['kind'] === 'luck' ? classifyOnlineD100Outcome($rolled['rawD100'] ?? null) : null);
    if ($outcome !== null && $spec['threshold'] !== null) $outcome['resultCustomized'] = $spec['modifierMode'] === 'result';
    $roll = onlineRollEntry($identity, $rolled, $spec['label'], (string) ($source['name'] ?? 'Personnage'), $outcome);
    if ($tokenId !== '' && !in_array($spec['kind'], ['damage', 'custom-damage'], true)) $roll['mapEvent'] = ['kind' => 'roll', 'sceneId' => $sceneId, 'layerId' => onlineTokenLayerId($source, $map), 'anchorTokenId' => $tokenId, 'tokenId' => $tokenId, 'value' => $outcome['result'] ?? $rolled['rawD100'] ?? $rolled['total'], 'label' => $spec['label'], 'tone' => $outcome['code'] ?? 'normal'];
    $roll = applicationTacticalRollVisibility($roll, $source, $spec['kind'], $spec['visibility']);
    $activity['rolls'] = array_slice([$roll, ...($activity['rolls'] ?? [])], 0, 100);
    queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
    $action = onlineAppendPlayerAction($connection, $records, $pending, $identity, $sceneId, ['kind' => 'roll', 'characterName' => $source['name'] ?? 'Personnage', 'summary' => $spec['label'], 'detail' => $roll['breakdown']]);
    $activity = $pending['activity']['payload'] ?? $activity;
    $receipts = array_values(array_filter($activity['resourceReceipts'] ?? [], static fn($entry): bool => is_array($entry) && ($entry['expiresAt'] ?? 0) > $now));
    $result = ['roll' => $roll, 'initiativeUpdated' => false];
    $receipts[] = ['kind' => 'token-roll', 'requestId' => $requestId, 'accountId' => $accountId, 'actionId' => $action['id'], 'sourceKey' => $tokenId !== '' ? $tokenId : $characterId, 'requestSignature' => $signature, 'expiresAt' => $now + XAR_RESOURCE_RECEIPT_TTL_MILLISECONDS, 'result' => $result];
    $activity['resourceReceipts'] = $receipts; queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
    return [...$result, 'deduplicated' => false];
}
