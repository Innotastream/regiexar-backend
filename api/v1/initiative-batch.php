<?php
declare(strict_types=1);

function onlineSceneCharacterIndex(PDO $connection, array &$records, array $tokens): array
{
    $keys = [];
    foreach ($tokens as $token) {
        $key = 'character:' . ($token['characterId'] ?? '');
        if (validApplicationDomainKey($key)) $keys[$key] = true;
    }
    $missing = array_keys(array_diff_key($keys, $records));
    if ($missing !== []) $records = array_replace($records, applicationDomainRecords($connection, $missing));
    $characters = [];
    foreach ($keys as $key => $_) {
        $character = applicationDomainPayload($records, $key);
        if ($character !== []) $characters[substr($key, 10)] = $character;
    }
    return $characters;
}

function onlineGmTokenVisibility(
    PDO $connection,
    array &$records,
    array $table,
    string $sceneId,
    ?array $tokens = null,
    ?array $characters = null
): Closure {
    if ($sceneId !== onlineActiveSceneId($table) || ($table['tacticalSync']['paused'] ?? false) === true) {
        return static fn(array $source): bool => false;
    }
    $mapKey = 'map:' . $sceneId;
    if (!isset($records[$mapKey])) $records = array_replace($records, applicationDomainRecords($connection, [$mapKey]));
    $map = applicationDomainPayload($records, $mapKey);
    $fog = applicationActiveMapFogState($map);
    $vision = applicationMapVisionSettings($map);
    $mask = null;
    return static function (array $source) use ($connection, &$records, $sceneId, $map, $fog, $vision, $tokens, $characters, &$mask): bool {
        if ($map === [] || ($source['hidden'] ?? false) === true || empty($source['id']) || !onlineTokenOnActiveLayer($source, $map)
            || applicationFogCoversPoint($fog, $source['x'] ?? 0, $source['y'] ?? 0)) return false;
        if (!$vision['enabled']) return true;
        if ($mask === null) {
            if ($tokens === null) {
                $records = onlineSceneTokenRecords($connection, $sceneId, $records);
                $tokens = [];
                foreach ($records as $key => $record) {
                    if (!str_starts_with($key, 'token:' . $sceneId . ':')) continue;
                    $token = applicationDomainPayload($records, $key);
                    if ($token !== []) $tokens[] = $token;
                }
            }
            $characters ??= onlineSceneCharacterIndex($connection, $records, $tokens);
            $owners = onlineCharacterOwnerIndex($characters);
            $origins = [];
            foreach ($tokens as $token) {
                if (($token['hidden'] ?? false) === true || !onlineTokenOnActiveLayer($token, $map)
                    || onlineEffectiveTokenControllerId($token, $owners) === '') continue;
                $character = $characters[$token['characterId'] ?? ''] ?? null;
                $origins[] = [
                    'x' => $token['x'] ?? 50, 'y' => $token['y'] ?? 50,
                    'visionDistance' => onlineTokenVisionDistance($token, $character),
                    'darkVision' => onlineTokenDarkVision($token, $character),
                    'source' => 'token',
                ];
            }
            $mask = $origins === [] ? false : applicationComputeVisionMask(
                applicationActiveMapOcclusionState($map, array_values($tokens)), $origins, $map['gridSize'] ?? 50
            );
        }
        return $mask !== false && !applicationVisionCoversPoint($mask, $source['x'] ?? 0, $source['y'] ?? 0);
    };
}

function onlineGmInitiativeBatch(
    PDO $connection,
    array &$records,
    array &$pending,
    array $table,
    array $identity,
    array $args
): array {
    if (!onlineIdentityIsGm($identity)) {
        rejectOnlineCommand($connection, 403, 'Le lancement groupé des initiatives est réservé au MJ.', 'gm_required');
    }
    $requestId = $args['requestId'] ?? null;
    $sceneId = $args['sceneId'] ?? null;
    $layerId = $args['layerId'] ?? null;
    $ids = $args['tokenIds'] ?? null;
    if (!is_string($requestId) || preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $requestId) !== 1
        || !validApplicationDomainIdentifier($sceneId, 80) || !in_array($layerId, ['basement', 'ground', 'upper'], true)
        || !validApplicationDomainIdentifierList($ids, 2000) || $ids === []) {
        rejectOnlineCommand($connection, 400, 'La scène, le niveau ou la sélection d’initiative est invalide.', 'invalid_initiative_batch');
    }
    $now = applicationResourceReceiptClock();
    $signature = onlineCommandRequestSignature('token.initiative.batch', [$sceneId, $layerId, $ids]);
    $receipt = applicationReadResourceReceiptLedger($connection, $requestId, $now);
    if ($receipt !== null) {
        if (($receipt['accountId'] ?? null) !== ($identity['id'] ?? null)) {
            rejectOnlineCommand($connection, 403, 'Ce reçu appartient à un autre compte.', 'initiative_batch_receipt_forbidden');
        }
        if (($receipt['kind'] ?? '') !== 'initiative-batch' || ($receipt['requestSignature'] ?? '') !== $signature
            || ($receipt['result']['rolled'] ?? null) !== count($ids)) {
            rejectOnlineCommand($connection, 409, 'Cette référence a déjà servi à une autre commande.', 'initiative_batch_request_mismatch');
        }
        return [...$receipt['result'], 'deduplicated' => true];
    }
    $mapKey = 'map:' . $sceneId;
    $initiativeKey = 'initiative:' . $sceneId;
    $sceneKey = 'scene:' . $sceneId;
    $tokenIndexKey = 'token-index:' . $sceneId;
    $records = array_replace($records, applicationDomainRecords($connection, [$sceneKey, $mapKey, $initiativeKey, $tokenIndexKey, 'activity']));
    $map = applicationDomainPayload($records, $mapKey);
    if ($map === [] || applicationDomainPayload($records, $sceneKey) === []) {
        rejectOnlineCommand($connection, 409, 'Cette scène n’existe plus. Relancez depuis la carte actuelle.', 'stale_scene');
    }
    if (onlineTokenLayerId([], $map) !== $layerId) {
        rejectOnlineCommand($connection, 409, 'Le niveau a changé. Relancez depuis la carte actuelle.', 'stale_token_layer');
    }
    $records = onlineSceneTokenRecords($connection, $sceneId, $records);
    $tokens = [];
    foreach (applicationDomainPayload($records, $tokenIndexKey)['order'] ?? [] as $id) {
        $token = applicationDomainPayload($records, onlineTokenDomainKey($sceneId, $id));
        if ($token !== []) $tokens[$id] = $token;
    }
    foreach ($ids as $id) {
        $token = $tokens[$id] ?? null;
        if ($token === null || !onlineTokenOnActiveLayer($token, $map) || applicationIsNonCombatant($token)) {
            rejectOnlineCommand($connection, 409, 'Un pion sélectionné a été retiré, déplacé ou ne participe plus au combat. Refaites la sélection.', 'stale_initiative_selection');
        }
    }
    $characters = onlineSceneCharacterIndex($connection, $records, $tokens);
    $owners = onlineCharacterOwnerIndex($characters);
    $activity = applicationDomainPayload($records, 'activity');
    foreach ($activity['resourceReceipts'] ?? [] as $pendingReceipt) {
        if (($pendingReceipt['requestId'] ?? '') === $requestId && ($pendingReceipt['expiresAt'] ?? 0) > $now) {
            rejectOnlineCommand($connection, 409, 'Cette référence a déjà servi à une autre commande.', 'initiative_batch_request_mismatch');
        }
    }
    $enhancements = onlineAttackEnhancementState($connection, $records, $pending, $sceneId, $activity, $owners);
    $isVisible = onlineGmTokenVisibility($connection, $records, $table, $sceneId, $tokens, $characters);
    $initiative = applicationDomainPayload($records, $initiativeKey);
    $order = $initiative['order'] ?? [];
    $activeId = ($initiative['active'] ?? false) === true ? ($order[$initiative['currentIndex'] ?? 0] ?? null) : null;
    $forSort = $records;
    $rolls = [];
    $journal = [];
    $initiativeBatchId = count($ids) > 1 ? bin2hex(random_bytes(12)) : null;
    foreach ($ids as $id) {
        $token = $tokens[$id];
        $characterId = applicationAbilityCastingOwner($token)['characterId'];
        $character = $characters[$characterId] ?? null;
        $source = $character !== null ? synchronizeOnlineCharacterToken($token, $character) : $token;
        $controller = onlineEffectiveTokenControllerId($source, $owners);
        $source['controllerPlayerId'] = $controller;
        $bonus = applicationEffectiveInitiativeBonus($source, $enhancements, $sceneId);
        $spec = applicationTacticalRollSpecification([...$source, 'initiativeBonus' => $bonus], ['kind' => 'initiative']);
        $rolled = onlineRollFormulaWithMode($spec['formula'], $spec['rollMode']);
        $roll = onlineRollEntry($identity, $rolled, $spec['label'], onlineUtf8ByteSlice((string) ($source['name'] ?? 'Pion'), 120));
        $roll['rollKind'] = 'initiative';
        if ($initiativeBatchId !== null) $roll['initiativeBatchId'] = $initiativeBatchId;
        $roll['sourceSceneId'] = $sceneId;
        $roll['sourceTokenId'] = $id;
        $roll['diceAppearance'] = onlineDiceAppearance($source, $controller !== '', $character);
        // The complete roll remains in history, without a flood of map animations.
        $rolls[] = applicationTacticalRollVisibility($roll, $source, 'initiative', 'public', $isVisible($source));
        $journal[] = $roll['characterName'] . ' · ' . $rolled['formula'] . ' = ' . $rolled['total'];
        $tokenKey = onlineTokenDomainKey($sceneId, $id);
        $token['initiative'] = $rolled['total'];
        $token['_updatedAt'] = $now;
        $forSort[$tokenKey]['payload'] = $token;
        queueOnlineDomainUpsert($pending, $records, $tokenKey, $token);
    }
    $order = array_values(array_unique([...$order, ...$ids]));
    $initiative['order'] = $order;
    $initiative = applicationSetPreparedInitiativeOrder($initiative, sortedOnlineInitiativeOrder($order, $forSort, $sceneId), $activeId);
    $initiative['_updatedAt'] = $now;
    queueOnlineDomainUpsert($pending, $records, $initiativeKey, $initiative);
    $activity['rolls'] = array_slice([...array_reverse($rolls), ...($activity['rolls'] ?? [])], 0, 100);
    $count = count($ids);
    $action = onlineAppendPlayerAction($connection, $records, $pending, $identity, $sceneId, [
        'kind' => 'roll', 'summary' => $count === 1 ? '1 initiative lancée' : $count . ' initiatives lancées',
        'detail' => implode("\n", $journal),
    ], $activity);
    $result = ['rolled' => $count, 'deduplicated' => false];
    applicationWriteResourceReceiptLedger($connection, [[
        'kind' => 'initiative-batch', 'requestId' => $requestId, 'accountId' => $identity['id'],
        'actionId' => $action['id'], 'sourceKey' => $initiativeKey, 'requestSignature' => $signature,
        'expiresAt' => $now + XAR_RESOURCE_RECEIPT_TTL_MILLISECONDS, 'result' => $result,
    ]]);
    return $result;
}
