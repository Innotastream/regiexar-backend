<?php
declare(strict_types=1);

function applicationDamageComponents(mixed $value): array {
    if (!is_array($value) || !array_is_list($value) || count($value) > 3) return [];
    $parts = []; $seen = [];
    foreach ($value as $entry) {
        if (!is_array($entry) || !in_array($entry['type'] ?? '', ['physical', 'magical', 'ignore'], true)
            || isset($seen[$entry['type']]) || !validApplicationAbilityFormula($entry['formula'] ?? null)) return [];
        $seen[$entry['type']] = true;
        $parts[] = ['type' => $entry['type'], 'formula' => strtolower(preg_replace('/\s+/', '', $entry['formula']))];
    }
    return $parts;
}
function applicationCombinedDamageFormula(array $parts): string {
    return str_replace(['+-', '++'], ['-', '+'], implode('+', array_column($parts, 'formula'))) ?: '0';
}
function validApplicationAbilityEffects(array $entry): bool {
    if (!validApplicationAbilityCastingFields($entry)) return false;
    $effect = $entry['effect'] ?? 'damage';
    if (!in_array($effect, ['damage', 'healing', 'metamorphosis'], true)) return false;
    if ($effect === 'healing') return validApplicationAbilityFormula($entry['healingFormula'] ?? null);
    if ($effect === 'metamorphosis') return validApplicationDomainIdentifier($entry['formCharacterId'] ?? null, 180);
    if (!array_key_exists('damageComponents', $entry)) return true;
    $parts = applicationDamageComponents($entry['damageComponents']);
    return $parts !== [] && validApplicationAbilityFormula(applicationCombinedDamageFormula($parts));
}
function applicationAbilityEffectFields(array $entry): array {
    $casting = applicationAbilityCastingFields($entry);
    $effect = $entry['effect'] ?? 'damage';
    if ($effect === 'healing') return [...$casting, 'effect' => 'healing', 'healingFormula' => (string) ($entry['healingFormula'] ?? '1d6')];
    if ($effect === 'metamorphosis') return [...$casting, 'effect' => 'metamorphosis', 'formCharacterId' => (string) ($entry['formCharacterId'] ?? '')];
    $parts = applicationDamageComponents($entry['damageComponents'] ?? []);
    return [...$casting, ...(array_key_exists('effect', $entry) ? ['effect' => 'damage'] : []), ...($parts !== [] ? ['damageComponents' => $parts] : [])];
}
function applicationCustomAttack(mixed $value): array {
    if (!is_array($value)) throw new InvalidArgumentException('Attaque personnalisée invalide.');
    $name = trim((string) ($value['name'] ?? ''));
    $parts = applicationDamageComponents($value['damageComponents'] ?? []);
    if ($name === '' || strlen($name) > 120 || $parts === [] || !validApplicationAbilityFormula(applicationCombinedDamageFormula($parts))) throw new InvalidArgumentException('Renseignez un nom et des formules de dégâts valides.');
    $customStat = ($value['customStat'] ?? false) === true;
    $threshold = $value['threshold'] ?? null; $label = trim((string) ($value['statLabel'] ?? 'Statistique personnalisée'));
    if ($customStat && (!is_numeric($threshold) || (float) $threshold != (int) $threshold || $threshold < 0 || $threshold > 100 || $label === '' || strlen($label) > 120)) throw new InvalidArgumentException('Statistique personnalisée invalide.');
    return ['name' => $name, 'damageComponents' => $parts, 'customStat' => $customStat, 'threshold' => (int) $threshold, 'statLabel' => $label];
}
function onlineRollAttackDamage(array $attack, array $target, ?callable $rollFormula = null): array {
    $parts = applicationDamageComponents($attack['damageComponents'] ?? []);
    if ($parts === []) {
        $rolled = onlineRollFormulaWithMode($attack['damageFormula'], $attack['damageRollMode'] ?? 'normal');
        return ['rolled' => $rolled, 'damage' => onlineAttackDamageSummary(max(0, (int) $rolled['total']), onlineAttackArmorPercent($target, $attack['damageType'] ?? 'physical'))];
    }
    $rollFormula ??= static fn (string $formula): array => onlineRollFormula($formula);
    $rollMode = normalizeOnlineRollMode($attack['damageRollMode'] ?? 'normal');
    $attempts = [];
    $attemptCount = $rollMode === 'normal' ? 1 : 2;
    for ($attemptIndex = 0; $attemptIndex < $attemptCount; $attemptIndex += 1) {
        $attemptTotal = 0; $attemptBreakdown = []; $attemptComponents = [];
        foreach ($parts as $part) {
            $componentRoll = $rollFormula($part['formula']);
            $componentTotal = (int) ($componentRoll['total'] ?? 0);
            $componentBreakdown = (string) ($componentRoll['breakdown'] ?? $componentTotal);
            $attemptTotal += $componentTotal;
            $attemptBreakdown[] = $part['type'] . ': ' . $componentBreakdown;
            $attemptComponents[] = [
                ...$part,
                'total' => $componentTotal,
                'breakdown' => $componentBreakdown,
            ];
        }
        $attempts[] = [
            'total' => $attemptTotal,
            'breakdown' => implode(' ; ', $attemptBreakdown),
            'rawD100' => null,
            'components' => $attemptComponents,
        ];
    }
    $selectedIndex = selectOnlineRollAttemptIndex($attempts, $rollMode);
    $selected = $attempts[$selectedIndex];
    $results = []; $raw = 0; $final = 0;
    foreach ($selected['components'] as $component) {
        $summary = onlineAttackDamageSummary(max(0, (int) $component['total']), onlineAttackArmorPercent($target, $component['type']));
        $results[] = [
            'type' => $component['type'],
            'formula' => $component['formula'],
            'breakdown' => $component['breakdown'],
            ...$summary,
        ];
        $raw += $summary['rawDamage'];
        $final += $summary['finalDamage'];
    }
    return ['rolled' => [
            'formula' => applicationCombinedDamageFormula($parts),
            'total' => $selected['total'],
            'breakdown' => $selected['breakdown'],
            'rawD100' => null,
            'rollMode' => $rollMode,
            'selectedIndex' => $selectedIndex,
            'attempts' => $attempts,
        ],
        'damage' => ['rawDamage' => $raw, 'finalDamage' => $final, 'preventedDamage' => $raw - $final, 'armorPercent' => count($results) === 1 ? $results[0]['armorPercent'] : ($raw ? (int) round(100 * ($raw - $final) / $raw) : 0), 'components' => $results]];
}

// Clients 3.2.2 may still send the former five-field ability. Preserve the new
// effect on existing rows; removal of a row remains an explicit deletion.
function preserveApplicationAbilityRows(array $incoming, array $previous): array {
    $byId = array_column($previous, null, 'id');
    return array_map(static function ($entry) use ($byId) {
        if (!is_array($entry)) return $entry;
        $old = $byId[$entry['id'] ?? ''] ?? [];
        foreach (['manaCost', 'cooldownRounds', 'castingStatId', 'image'] as $field) if (!array_key_exists($field, $entry) && array_key_exists($field, $old)) $entry[$field] = $old[$field];
        if (array_key_exists('effect', $entry)) return $entry;
        if (!array_key_exists('effect', $old) && !array_key_exists('damageComponents', $old)) return $entry;
        foreach (['effect', 'damageComponents', 'healingFormula', 'formCharacterId', 'formula', 'damageType'] as $field) if (array_key_exists($field, $old)) $entry[$field] = $old[$field];
        return $entry;
    }, $incoming);
}
function preserveApplicationAbilityExtensions(string $key, array $payload, array $previous): array {
    if (str_starts_with($key, 'character:') || str_starts_with($key, 'token:')) {
        if (is_array($payload['abilities'] ?? null)) $payload['abilities'] = preserveApplicationAbilityRows($payload['abilities'], $previous['abilities'] ?? []);
    }
    if (str_starts_with($key, 'token:') && !array_key_exists('transformation', $payload)
        && is_array($previous['transformation'] ?? null) && ($payload['characterId'] ?? '') === ($previous['characterId'] ?? '')
        && ($payload['followCharacter'] ?? true) !== false && empty($payload['linkedTokenId'])) $payload['transformation'] = $previous['transformation'];
    if ($key === 'activity') {
        // Older client normalizers cannot represent casting receipts. Preserve
        // immutable, unexpired authority receipts across their activity writes.
        $now = (int) floor(microtime(true) * 1000);
        $receipts = [];
        foreach ($payload['resourceReceipts'] ?? [] as $receipt) if (is_array($receipt) && ($receipt['expiresAt'] ?? 0) > $now) $receipts[$receipt['requestId'] ?? ''] = $receipt;
        foreach ($previous['resourceReceipts'] ?? [] as $receipt) if (is_array($receipt) && in_array($receipt['kind'] ?? '', ['ability-cast', 'token-roll', 'shortcut-roll', 'character-create', 'timer-create', 'token-clone', 'studio-conversation-create'], true) && ($receipt['expiresAt'] ?? 0) > $now) $receipts[$receipt['requestId'] ?? ''] = $receipt;
        if (count($receipts) > XAR_RESOURCE_RECEIPT_MAXIMUM) sendError(409, 'Le journal de sécurité des compétences est plein.', 'ability_receipt_capacity');
        if (array_key_exists('resourceReceipts', $payload) || $receipts !== []) $payload['resourceReceipts'] = array_values($receipts);
        $timers = array_column($previous['actionTimers'] ?? [], null, 'id');
        foreach ($payload['actionTimers'] ?? [] as $index => $timer) {
            $old = $timers[$timer['id'] ?? ''] ?? [];
            foreach (['abilityId', 'characterId', 'tokenId'] as $field) if (!array_key_exists($field, $timer) && array_key_exists($field, $old)) $payload['actionTimers'][$index][$field] = $old[$field];
        }
        $attacks = array_column($previous['pendingAttacks'] ?? [], null, 'id');
        foreach ($previous['attackReceipts'] ?? [] as $r) if (is_array($r['attack'] ?? null)) $attacks[$r['attack']['id'] ?? ''] = $r['attack'];
        $preserve = static function ($attack) use ($attacks) {
            if (!is_array($attack)) return $attack;
            $old = $attacks[$attack['id'] ?? ''] ?? [];
            foreach (['damageComponents', 'damageModifier', 'validationKind', 'provisionalStatus'] as $field) {
                if (!array_key_exists($field, $attack) && array_key_exists($field, $old)) $attack[$field] = $old[$field];
            }
            if (($old['attackKind'] ?? '') === 'custom') $attack['attackKind'] = 'custom';
            // A 3.2.16 activity snapshot knows the attack objects but not the
            // complete canonical roll presentation added in 3.2.17. Keep
            // those authoritative fields when that client writes the same
            // pending attack or receipt back without them.
            foreach (['hit', 'opposition'] as $rollKey) {
                if (!is_array($attack[$rollKey] ?? null) || !is_array($old[$rollKey] ?? null)) continue;
                foreach (['label', 'characterName', 'formula', 'total', 'rollMode', 'selectedIndex', 'attempts'] as $field) {
                    if (!array_key_exists($field, $attack[$rollKey]) && array_key_exists($field, $old[$rollKey])) {
                        $attack[$rollKey][$field] = $old[$rollKey][$field];
                    }
                }
            }
            foreach (['hitRoll', 'oppositionRoll', 'damageRoll'] as $rollKey) {
                if (!array_key_exists($rollKey, $attack) && is_array($old[$rollKey] ?? null)) $attack[$rollKey] = $old[$rollKey];
            }
            if (is_array($attack['damage'] ?? null) && is_array($old['damage'] ?? null)) {
                foreach (['rollId', 'total', 'rollMode', 'selectedIndex', 'attempts'] as $field) {
                    if (!array_key_exists($field, $attack['damage']) && array_key_exists($field, $old['damage'])) {
                        $attack['damage'][$field] = $old['damage'][$field];
                    }
                }
            }
            return $attack;
        };
        if (isset($payload['pendingAttacks'])) $payload['pendingAttacks'] = array_map($preserve, $payload['pendingAttacks']);
        foreach ($payload['attackReceipts'] ?? [] as $index => $receipt) if (isset($receipt['attack'])) $payload['attackReceipts'][$index]['attack'] = $preserve($receipt['attack']);
    }
    return $payload;
}
