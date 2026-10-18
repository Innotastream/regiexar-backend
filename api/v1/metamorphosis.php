<?php
declare(strict_types=1);

function applicationTrackTransformationHp(array $token, float|int $hp): array {
    $form = $token['transformation'] ?? [];
    if (empty($form['active']) || ($form['bonusHp'] ?? 0) <= 0 || !is_numeric($form['lastHp'] ?? null)) return $token;
    $token['transformation'] = [...$form,
        'remainingBonusHp' => max(0, ($form['remainingBonusHp'] ?? $form['bonusHp']) - max(0, $form['lastHp'] - $hp)), 'lastHp' => $hp];
    return $token;
}
function applicationMorphLinked(array $token): bool {
    return ($token['followCharacter'] ?? true) !== false && ($token['usesCharacterSheet'] ?? true) !== false && empty($token['independentClone']) && empty($token['linkedTokenId']);
}
function applicationMorphTokens(array $state): array {
    $tokens = $state['map']['tokens'] ?? [];
    foreach ($state['scenes'] ?? [] as $scene) if (($scene['id'] ?? '') !== ($state['activeSceneId'] ?? '')) $tokens = [...$tokens, ...($scene['combat']['map']['tokens'] ?? [])];
    return $tokens;
}
function applicationSetMorphTokens(array &$state, array $ids, array $transformation, string $characterId): void {
    $byId = array_fill_keys($ids, true); $characters = array_column($state['characters'] ?? [], null, 'id'); $character = $characters[$characterId] ?? [];
    $update = static function(array $tokens) use ($byId, $transformation, $characterId, $character): array {
        foreach ($tokens as &$token) if (isset($byId[$token['id']]) && applicationMorphLinked($token)) {
            $token['characterId'] = $characterId; $token['transformation'] = $transformation;
            foreach (['hp', 'maxHp', 'mana', 'maxMana'] as $field) $token[$field] = $character['resources'][$field] ?? 0;
        } unset($token); return $tokens;
    };
    $state['map']['tokens'] = $update($state['map']['tokens'] ?? []);
    foreach ($state['scenes'] ?? [] as $index => $scene) if (($scene['id'] ?? '') !== ($state['activeSceneId'] ?? '')) $state['scenes'][$index]['combat']['map']['tokens'] = $update($scene['combat']['map']['tokens'] ?? []);
}
function applicationSettleFormHealth(array &$state, array $transformation): void {
    $indices = array_flip(array_column($state['characters'] ?? [], 'id'));
    $baseIndex = $indices[$transformation['baseCharacterId'] ?? ''] ?? null; $formIndex = $indices[$transformation['formCharacterId'] ?? ''] ?? null;
    if ($baseIndex === null || $formIndex === null || ($transformation['bonusHp'] ?? 0) <= 0 || !is_numeric($transformation['initialBaseHp'] ?? null)) return;
    $hp = $state['characters'][$formIndex]['resources']['hp'] ?? 0;
    $holder = applicationTrackTransformationHp(['transformation' => $transformation], $hp);
    $delta = $hp - $holder['transformation']['remainingBonusHp'] - $transformation['initialBaseHp'];
    $base = &$state['characters'][$baseIndex];
    $base['resources']['hp'] = max(-1000000000, min($base['resources']['maxHp'] ?? 0, ($base['resources']['hp'] ?? 0) + $delta));
}
function applicationApplyMetamorphosisPlan(array &$state, array $plan): void {
    if (!empty($plan['deduplicated']) || empty($plan['changes'])) return;
    $first = array_values($plan['changes'])[0]; $next = $first['transformation'];
    if (!empty($next['active']) && ($next['bonusHp'] ?? 0) > 0) {
        $indices = array_flip(array_column($state['characters'], 'id')); $base = $state['characters'][$indices[$next['baseCharacterId']]];
        $hp = ($base['resources']['hp'] ?? 0) + $next['bonusHp']; $maxHp = ($base['resources']['maxHp'] ?? 0) + $next['bonusHp'];
        if (max($hp, $maxHp) > 1000000000) throw new RuntimeException('Les PV de la forme dépassent la limite autorisée.', 409);
        $form = &$state['characters'][$indices[$next['formCharacterId']]]; $form['resources'] = [...($form['resources'] ?? []), 'hp' => $hp, 'maxHp' => $maxHp];
        $next = [...$next, 'initialBaseHp' => $base['resources']['hp'] ?? 0, 'remainingBonusHp' => $next['bonusHp'], 'lastHp' => $hp];
    } elseif (empty($next['active'])) applicationSettleFormHealth($state, $first['token']['transformation'] ?? []);
    applicationSetMorphTokens($state, array_map(static fn($change) => $change['token']['id'], array_values($plan['changes'])), $next, $plan['activeCharacterId']);
}
function applicationAdvanceMetamorphoses(array &$state, array $previous, bool $manual = false): array {
    $current = $state['initiative'] ?? [];
    $ended = ($previous['active'] ?? false) === true && ($current['active'] ?? false) !== true;
    $advanced = $manual || ($previous['active'] ?? false) === true && ($current['active'] ?? false) === true && ($previous['combatId'] ?? '') === ($current['combatId'] ?? '') && ($current['turnSerial'] ?? 0) > ($previous['turnSerial'] ?? 0);
    if (!$ended && !$advanced) return [];
    $tokens = applicationMorphTokens($state); $effects = []; $handled = [];
    foreach ($state['map']['tokens'] ?? [] as $token) {
        $form = $token['transformation'] ?? [];
        if (!applicationMorphLinked($token) || empty($form['active']) || ($form['durationTurns'] ?? 0) <= 0 || isset($handled[$form['requestId']])) continue;
        $handled[$form['requestId']] = true;
        $group = array_values(array_filter($tokens, static fn($entry) => applicationMorphLinked($entry) && !empty($entry['transformation']['active']) && ($entry['transformation']['requestId'] ?? '') === $form['requestId'] && ($entry['characterId'] ?? '') === $form['formCharacterId']));
        $ids = array_column($group, 'id'); $clockTokenId = in_array($form['clockTokenId'] ?? '', $previous['order'] ?? [], true) ? $form['clockTokenId'] : null;
        if ($clockTokenId === null) foreach ($previous['order'] ?? [] as $id) if (in_array($id, $ids, true)) { $clockTokenId = $id; break; }
        if (!$ended && !$manual && (($previous['order'][$previous['currentIndex'] ?? 0] ?? '') !== $clockTokenId || ($form['clockCombatId'] ?? '') === ($current['combatId'] ?? '') && ($form['lastTurnSerial'] ?? 0) >= ($current['turnSerial'] ?? 0))) continue;
        $next = [...$form, 'remainingTurns' => $ended ? 0 : max(0, $form['remainingTurns'] - 1), 'lastTurnSerial' => $current['turnSerial'] ?? 0, 'clockCombatId' => $current['combatId'] ?? ''];
        if ($next['remainingTurns'] > 0) { applicationSetMorphTokens($state, $ids, $next, $form['formCharacterId']); continue; }
        applicationSettleFormHealth($state, $form);
        applicationSetMorphTokens($state, $ids, [...$next, 'active' => false], $form['baseCharacterId']);
        $effects[] = ['type' => 'metamorphosis.return', 'characterId' => $form['baseCharacterId'], 'requestId' => $form['requestId'], 'sceneId' => $state['activeSceneId']];
    }
    return $effects;
}
