<?php
declare(strict_types=1);

function applicationAttackEnhancement(mixed $value): ?array {
    if (!is_array($value) || !in_array($value['mode'] ?? '', ['bonus', 'replace'], true)
        || !in_array($value['damageType'] ?? '', ['physical', 'magical', 'ignore'], true)
        || !is_int($value['turns'] ?? null) || $value['turns'] < 1 || $value['turns'] > 999) return null;
    $formula = strtolower(preg_replace('/\s+/', '', (string) ($value['formula'] ?? '')));
    if (!validApplicationAbilityFormula($formula) || ($value['mode'] === 'bonus'
        && (preg_match('/^\d+$/D', $formula) !== 1 || (float) $formula > 1000000000))) return null;
    return ['mode' => $value['mode'], 'formula' => $formula, 'damageType' => $value['damageType'], 'turns' => $value['turns']];
}

function applicationEnhancementHolder(array $source): string {
    return isset($source['resources']) ? 'character:' . ($source['id'] ?? '')
        : (!empty($source['characterId']) && ($source['followCharacter'] ?? true) !== false && empty($source['linkedTokenId'])
            && ($source['usesCharacterSheet'] ?? true) !== false && ($source['independent'] ?? false) !== true
            ? 'character:' . $source['characterId'] : 'token:' . ($source['id'] ?? ''));
}

function applicationTransferAttackEnhancements(array $effects, array $source, string $fromSceneId, string $toSceneId, array $initiative): array {
    $holder = applicationEnhancementHolder($source);
    return array_map(static fn(array $effect): array => $effect['sceneId'] === $fromSceneId && $effect['holder'] === $holder
        ? [...$effect, 'sceneId' => $toSceneId, 'targetTokenId' => $source['id'],
            'combatId' => ($initiative['active'] ?? false) === true ? ($initiative['combatId'] ?? '') : '', 'lastTurnSerial' => (int) ($initiative['turnSerial'] ?? 0)]
        : $effect, $effects);
}

function applicationActivateAttackEnhancement(array &$activity, array $ability, array $source, string $sceneId, array $initiative, string $id): void {
    $definition = applicationAttackEnhancement($ability['attackEnhancement'] ?? null);
    if ($definition === null) return;
    $holder = applicationEnhancementHolder($source);
    $effects = array_values(array_filter($activity['attackEnhancements'] ?? [], static fn(array $effect): bool =>
        $effect['holder'] !== $holder || $effect['abilityId'] !== $ability['id']));
    if (count($effects) >= 100) throw new RuntimeException('Trop d’effets sur les attaques sont déjà actifs.', 409);
    $effects[] = [...$definition, 'id' => $id, 'holder' => $holder, 'abilityId' => $ability['id'], 'label' => $ability['name'], 'sceneId' => $sceneId,
        'targetTokenId' => $source['id'], 'remainingTurns' => $definition['turns'],
        'combatId' => ($initiative['active'] ?? false) === true ? ($initiative['combatId'] ?? '') : '', 'lastTurnSerial' => (int) ($initiative['turnSerial'] ?? 0)];
    $activity['attackEnhancements'] = $effects;
}

function applicationAdvanceAttackEnhancements(array $effects, string $sceneId, array $previous, array $initiative): array {
    if (($previous['active'] ?? false) === true && ($initiative['active'] ?? false) !== true)
        return array_values(array_filter($effects, static fn(array $effect): bool => $effect['sceneId'] !== $sceneId));
    if (($initiative['active'] ?? false) !== true || (int) ($initiative['turnSerial'] ?? 0) <= (int) ($previous['turnSerial'] ?? 0)) return $effects;
    $tokenId = $initiative['order'][$initiative['currentIndex'] ?? 0] ?? '';
    $remaining = [];
    foreach ($effects as $effect) {
        if ($effect['sceneId'] !== $sceneId) { $remaining[] = $effect; continue; }
        if (($effect['combatId'] ?? '') !== '' && $effect['combatId'] !== ($initiative['combatId'] ?? '')) continue;
        if ($effect['targetTokenId'] === $tokenId && (int) ($effect['lastTurnSerial'] ?? 0) < $initiative['turnSerial']) {
            $effect['remainingTurns']--; $effect['lastTurnSerial'] = $initiative['turnSerial'];
        }
        if ($effect['remainingTurns'] > 0) $remaining[] = $effect;
    }
    return $remaining;
}

function applicationEnhancedBasicAttack(array $weapon, array $source, array $activity, string $sceneId): array {
    $holder = applicationEnhancementHolder($source);
    $effects = array_values(array_filter($activity['attackEnhancements'] ?? [], static fn(array $effect): bool =>
        $effect['holder'] === $holder && $effect['sceneId'] === $sceneId && $effect['remainingTurns'] > 0 && applicationAttackEnhancement($effect) !== null));
    if ($effects === []) return $weapon;
    $replacement = null;
    foreach ($effects as $effect) if ($effect['mode'] === 'replace') $replacement = $effect;
    $parts = [['type' => $replacement['damageType'] ?? $weapon['damageType'] ?? 'physical', 'formula' => $replacement['formula'] ?? $weapon['formula']]];
    foreach ($effects as $effect) {
        if ($effect['mode'] !== 'bonus') continue;
        $index = null;
        foreach ($parts as $i => $part) if ($part['type'] === $effect['damageType']) $index = $i;
        if ($index !== null) $parts[$index]['formula'] .= '+' . $effect['formula'];
        else $parts[] = ['type' => $effect['damageType'], 'formula' => $effect['formula']];
    }
    $formula = str_replace('+-', '-', implode('+', array_column($parts, 'formula')));
    if (!validApplicationAbilityFormula($formula)) throw new RuntimeException('Les dégâts cumulés des effets dépassent la limite autorisée.', 409);
    return [...$weapon, 'formula' => $formula, 'damageType' => $parts[0]['type'], 'damageComponents' => $parts,
        'enhancementLabel' => implode(' · ', array_column($effects, 'label'))];
}
