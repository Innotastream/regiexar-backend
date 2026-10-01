<?php
declare(strict_types=1);

const XAR_PENDING_DOT_RESOLUTION_MAXIMUM = 500;

function onlineDotTargetIdentity(array $token): string {
    return !empty($token['characterId']) && ($token['followCharacter'] ?? true) !== false && empty($token['linkedTokenId'])
        ? 'character:' . $token['characterId'] : 'token:' . ($token['id'] ?? '');
}

function onlineRemainingDotFormula(string $formula, int $turns): ?string {
    $formula = strtolower(preg_replace('/\s+/', '', $formula));
    if ($turns < 1 || $turns > 20 || preg_match('/^(?:\d*d\d+|\d+)(?:[+-](?:\d*d\d+|\d+))*$/D', $formula) !== 1) return null;
    preg_match_all('/[+-]?(?:\d*d\d+|\d+)/', $formula, $terms);
    return implode('', array_map(static function (string $term) use ($turns): string {
        $sign = in_array($term[0], ['+', '-'], true) ? $term[0] : '';
        $value = ltrim($term, '+-');
        if (preg_match('/^(\d*)d(\d+)$/D', $value, $dice) === 1)
            return $sign . ((int) ($dice[1] === '' ? '1' : $dice[1]) * $turns) . 'd' . $dice[2];
        return $sign . ((int) $value * $turns);
    }, $terms[0]));
}

function onlineOngoingEffectTarget(PDO $connection, array &$records, array $pending, string $sceneId, string $tokenId): array {
    $key = onlineTokenDomainKey($sceneId, $tokenId);
    $records = array_replace($records, applicationDomainRecords($connection, [$key]));
    $target = $pending[$key]['payload'] ?? applicationDomainPayload($records, $key);
    $owner = applicationAbilityCastingOwner($target);
    if (($owner['characterId'] ?? '') !== '') {
        $characterKey = 'character:' . $owner['characterId'];
        $records = array_replace($records, applicationDomainRecords($connection, [$characterKey]));
        $character = $pending[$characterKey]['payload'] ?? applicationDomainPayload($records, $characterKey);
        if ($character !== []) $target = synchronizeOnlineCharacterToken($target, $character);
    }
    return $target;
}

function onlineCaptureEndedDots(PDO $connection, array &$records, array &$pending, array &$activity, array $endedScenes): void {
    $queue = $activity['pendingDotResolutions'] ?? [];
    $known = array_column($queue, null, 'id');
    foreach ($activity['damageOverTime'] ?? [] as $dot) {
        if (!isset($endedScenes[(string) ($dot['sceneId'] ?? '')]) || (int) ($dot['remainingTurns'] ?? 0) <= 0 || isset($known[$dot['id'] ?? ''])) continue;
        $target = onlineOngoingEffectTarget($connection, $records, $pending, (string) $dot['sceneId'], (string) $dot['targetTokenId']);
        if ($target === [] || onlineTokenIsDead($target, onlineTokenControllerIdFromRecords($connection, $records, $target) !== '')) continue;
        if (count($queue) >= XAR_PENDING_DOT_RESOLUTION_MAXIMUM) rejectOnlineCommand($connection, 409, 'Résolvez les dégâts de fin de combat déjà en attente avant de terminer un autre combat.', 'dot_resolution_capacity');
        $queue[] = [...$dot, 'targetName' => (string) ($target['name'] ?? 'Cible'),
            'targetIdentity' => onlineDotTargetIdentity($target), 'endedAt' => gmdate('c')];
        $known[$dot['id']] = true;
    }
    $activity['pendingDotResolutions'] = $queue;
}

function onlinePublicRevealedRoll(array $roll): array {
    $public = publicOnlineAttackRoll($roll, false, ($roll['mapEvent']['kind'] ?? '') === 'damage' ? 'damage' : 'roll');
    unset($public['sourceTokenId'], $public['sourceSceneId'], $public['statId'], $public['statLabel']);
    if (isset($public['mapEvent'])) foreach (['tokenId', 'anchorTokenId', 'sourceTokenId', 'targetTokenId', 'attackId'] as $key) unset($public['mapEvent'][$key]);
    return [...$public, 'visibility' => 'public', 'revealed' => true, 'gmRevealed' => true, 'revealedAt' => $roll['revealedAt'] ?? ''];
}

function onlineGmRevealRoll(PDO $connection, array &$records, array &$pending, array $table, array $identity, array $arguments): array {
    if (($identity['effective_mode'] ?? '') !== 'gm' || ($identity['permanent_role'] ?? '') !== 'gm') rejectOnlineCommand($connection, 403, 'Seul le MJ peut révéler un jet.', 'gm_required');
    $records = array_replace($records, applicationDomainRecords($connection, ['activity']));
    $activity = $pending['activity']['payload'] ?? applicationDomainPayload($records, 'activity');
    $rollId = (string) ($arguments['rollId'] ?? '');
    $index = findEntryIndex($activity['rolls'] ?? [], $rollId);
    foreach ($activity['rollRevelations'] ?? [] as $entry) if (($entry['rollId'] ?? '') === $rollId) return ['deduplicated' => true];
    if ($index < 0) rejectOnlineCommand($connection, 409, 'Ce jet n’est plus disponible.', 'roll_missing');
    $roll = $activity['rolls'][$index];
    if (($roll['revealed'] ?? false) === true || ($roll['visibility'] ?? '') === 'public') return ['deduplicated' => true];
    $sceneId = (string) ($roll['mapEvent']['sceneId'] ?? $roll['sourceSceneId'] ?? '');
    if ($sceneId !== '' && $sceneId !== onlineActiveSceneId($table)) rejectOnlineCommand($connection, 409, 'La scène de ce jet n’est pas diffusée.', 'roll_scene_private');
    if ((($roll['mapEvent']['kind'] ?? '') === 'damage' && ($roll['mapEvent']['applied'] ?? false) !== true)
        || in_array($roll['rollKind'] ?? '', ['damage', 'custom-damage'], true)
        || (!isset($roll['mapEvent']) && !isset($roll['outcome']) && preg_match('/^Dégâts(?:\s|$)/u', (string) ($roll['label'] ?? '')) === 1))
        rejectOnlineCommand($connection, 409, 'Seuls les dégâts déjà appliqués peuvent être révélés.', 'unapplied_damage_private');
    $roll['revealed'] = true; $roll['gmRevealed'] = true; $roll['revealedAt'] = gmdate('c');
    $activity['rolls'][$index] = $roll;
    $activity['rollRevelations'] = array_slice([['rollId' => $rollId, 'sceneId' => $sceneId, 'revealedAt' => $roll['revealedAt']], ...($activity['rollRevelations'] ?? [])], 0, 500);
    queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
    onlineAppendPlayerAction($connection, $records, $pending, $identity, $sceneId,
        ['kind' => 'roll-reveal', 'characterName' => $roll['characterName'] ?? 'MJ', 'summary' => 'Révèle un jet aux joueurs']);
    return ['roll' => onlinePublicRevealedRoll($roll), 'deduplicated' => false];
}

function onlineCombatEffectCommand(PDO $connection, array &$records, array &$pending, array $table, array $identity, array $arguments, bool $resolve): array {
    if (($identity['effective_mode'] ?? '') !== 'gm' || ($identity['permanent_role'] ?? '') !== 'gm') rejectOnlineCommand($connection, 403, 'Cette action est réservée au MJ.', 'gm_required');
    $records = array_replace($records, applicationDomainRecords($connection, ['activity']));
    $activity = $pending['activity']['payload'] ?? applicationDomainPayload($records, 'activity');
    $requestId = (string) ($arguments['requestId'] ?? '');
    $kind = $resolve ? 'residual' : (string) ($arguments['kind'] ?? '');
    $decision = $resolve ? (string) ($arguments['decision'] ?? '') : 'remove';
    $id = (string) ($arguments['effectId'] ?? '');
    $signature = hash('sha256', json_encode([$kind, $id, $decision, $decision === 'custom' ? ($arguments['formula'] ?? '') : ''], JSON_UNESCAPED_UNICODE));
    $now = (int) floor(microtime(true) * 1000);
    if (preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $requestId) !== 1) rejectOnlineCommand($connection, 400, 'Référence de résolution invalide.', 'effect_request_invalid');
    $receipts = array_values(array_filter($activity['resourceReceipts'] ?? [], static fn ($entry): bool => ($entry['expiresAt'] ?? 0) > $now));
    foreach ($receipts as $receipt) {
        if (($receipt['requestId'] ?? '') !== $requestId) continue;
        if (($receipt['accountId'] ?? '') !== $identity['id']) rejectOnlineCommand($connection, 403, 'Cette résolution appartient à un autre MJ.', 'effect_receipt_forbidden');
        if (($receipt['kind'] ?? '') !== 'combat-effect' || ($receipt['requestSignature'] ?? '') !== $signature) rejectOnlineCommand($connection, 409, 'Cette référence a déjà un autre contenu.', 'effect_request_mismatch');
        return [...$receipt['result'], 'deduplicated' => true];
    }
    if (count($receipts) + count($activity['pendingAbilityCasts'] ?? []) >= XAR_RESOURCE_RECEIPT_MAXIMUM) rejectOnlineCommand($connection, 429, 'Le journal de sécurité est plein.', 'effect_receipt_capacity');
    $field = $resolve ? 'pendingDotResolutions' : ($kind === 'dot' ? 'damageOverTime' : ($kind === 'guard' ? 'nextAttackGuards' : ''));
    if ($field === '' || ($resolve && !in_array($decision, ['roll', 'custom', 'skip'], true))) rejectOnlineCommand($connection, 400, 'Choix de résolution invalide.', 'effect_decision_invalid');
    $entries = $activity[$field] ?? [];
    $index = findEntryIndex($entries, $id);
    if ($index < 0) rejectOnlineCommand($connection, 409, 'Cet effet a déjà été retiré ou résolu.', 'effect_missing');
    $entry = $entries[$index];
    $sceneId = (string) ($entry['sceneId'] ?? '');
    $target = onlineOngoingEffectTarget($connection, $records, $pending, $sceneId, (string) ($entry['targetTokenId'] ?? ''));
    $result = ['effectId' => $id, 'kind' => $kind, 'decision' => $decision, 'appliedDamage' => 0];
    $operation = null;
    if ($resolve && $decision !== 'skip' && $target !== [] && onlineDotTargetIdentity($target) === ($entry['targetIdentity'] ?? '')
        && !onlineTokenIsDead($target, onlineTokenControllerIdFromRecords($connection, $records, $target) !== '')) {
        $formula = $decision === 'custom' ? (string) ($arguments['formula'] ?? '') : onlineRemainingDotFormula((string) $entry['formula'], (int) $entry['remainingTurns']);
        if (!validApplicationAbilityFormula($formula)) rejectOnlineCommand($connection, 400, 'La formule de dégâts restants est hors limites ; choisissez un jet personnalisé.', 'dot_formula_invalid');
        $rolled = onlineRollFormulaWithMode($formula, 'normal');
        $summary = onlineAttackDamageSummary(max(0, (int) $rolled['total']), onlineAttackArmorPercent($target, (string) ($entry['damageType'] ?? 'physical')));
        $health = applyOnlineAttackDamage($connection, $records, $pending, onlineTokenDomainKey($sceneId, $target['id']), $target, $summary['finalDamage'], false, [...$summary, 'damageType' => $entry['damageType'] ?? 'physical']);
        $applied = (int) ($health['appliedDamage'] ?? 0); $result['appliedDamage'] = $applied;
        $roll = onlineRollEntry($identity, $rolled, 'Fin de combat · ' . ($entry['label'] ?? 'Effet'), (string) $target['name']);
        $roll['diceAppearance'] = onlineDiceAppearance($target, onlineTokenControllerIdFromRecords($connection, $records, $target) !== '');
        $roll['mapEvent'] = ['kind' => 'damage', 'applied' => true, 'value' => $applied, 'label' => $entry['label'] ?? 'Effet',
            'targetTokenId' => $target['id'], 'anchorTokenId' => $target['id'], 'targetName' => $target['name'], 'sceneId' => $sceneId, 'layerId' => onlineTokenLayerId($target)];
        if (!onlineGmTokenVisibleToPlayers($connection, $records, $table, $target, $sceneId)) { $roll['visibility'] = 'gm'; $roll['revealed'] = false; }
        $activity['rolls'] = array_slice([$roll, ...($activity['rolls'] ?? [])], 0, 100);
        $result['roll'] = $roll;
        $operation = ['kind' => 'resource-adjust', 'requestId' => $requestId, 'sceneId' => $sceneId, 'tokenId' => $target['id'],
            'characterId' => $health['characterId'] ?? '', 'resource' => 'hp', 'appliedDelta' => -$applied,
            'previous' => $health['previousHp'] ?? 0, 'current' => $health['currentHp'] ?? 0, 'maximum' => $health['maximumHp'] ?? 0];
    } elseif ($resolve && $decision !== 'skip') $result['targetUnavailable'] = true;
    array_splice($entries, $index, 1); $activity[$field] = $entries;
    queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
    $action = onlineAppendPlayerAction($connection, $records, $pending, $identity, $sceneId, [
        'kind' => $resolve ? 'resource' : 'effect-remove', 'characterName' => $target['name'] ?? $entry['targetName'] ?? 'Cible',
        'summary' => $resolve ? 'Dégâts de fin de combat : ' . $result['appliedDamage'] . ' PV perdus' : 'Retire ' . ($entry['label'] ?? 'un effet'),
        ...($operation !== null ? ['operation' => $operation] : [])]);
    $activity = $pending['activity']['payload'] ?? $activity;
    $receipts[] = ['kind' => 'combat-effect', 'requestId' => $requestId, 'accountId' => $identity['id'], 'actionId' => $action['id'],
        'sourceKey' => (string) ($entry['targetIdentity'] ?? $target['id'] ?? ''), 'requestSignature' => $signature, 'expiresAt' => $now + XAR_RESOURCE_RECEIPT_TTL_MILLISECONDS, 'result' => $result];
    $activity['resourceReceipts'] = $receipts; queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
    return [...$result, 'deduplicated' => false];
}
