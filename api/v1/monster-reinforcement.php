<?php

declare(strict_types=1);

function applicationMonsterReinforcementBaseName(string $name): string
{
    $name = trim($name);
    $base = trim(preg_replace('/(?:\s*·\s*Renfort\s+\d+)+$/iu', '', $name) ?? $name);
    return $base === '' ? 'Créature' : $base;
}

function applicationMonsterReinforcementIsGoblin(string $name, string $templateName): bool
{
    $name = applicationMonsterReinforcementBaseName($name);
    if (preg_match('/\b(?:cocon|gobelourd|hobgob(?:el|l)in)s?\b/iu', $name) === 1) return false;
    foreach ([$name, applicationMonsterReinforcementBaseName($templateName)] as $variant) {
        if (preg_match('/^gob(?:el|l)ins?(?:\s+coupe-bourse)?(?:\s+(?:#\s*)?\d+)*$/iu', $variant) === 1) return true;
    }
    return false;
}

function applicationMonsterReinforcementWeapons(array $source): array
{
    $configured = is_array($source['weaponAttacks'] ?? null) ? $source['weaponAttacks'] : [];
    $seed = []; $formulas = []; $hasBinding = false;
    foreach ($configured as $weapon) {
        if (!is_array($weapon)) continue;
        if (isset($weapon['equipmentBinding'])) { $hasBinding = true; continue; }
        $formula = strtolower(preg_replace('/\s+/', '', (string) ($weapon['formula'] ?? '')) ?? '');
        if (!validApplicationAbilityFormula($formula)) continue;
        $seed[] = $weapon;
        $formulas[] = $formula;
    }
    if ($seed === [] && !$hasBinding) {
        $formula = strtolower(preg_replace('/\s+/', '', (string) ($source['damageDice'] ?? '')) ?? '');
        if (validApplicationAbilityFormula($formula)) {
            $seed[] = ['id'=>'weapon-1', 'formula'=>$formula, 'damageType'=>$source['damageType'] ?? 'physical',
                ...applicationOptionalDamageElement($source)];
            $formulas[] = $formula;
        }
    }
    return normalizeOnlineWeaponAttacks($seed, $formulas);
}

function applicationMonsterReinforcementStats(array $stats, callable $randomInt): array
{
    $combatStats = [];
    foreach ($stats as $index => $stat) {
        if (!is_array($stat) || !in_array(applicationStatKey($stat), ['force','dexterity','agility','instinct'], true)) continue;
        $value = $stat['value'] ?? null;
        if (!is_numeric($value) || $value < 1 || $value > 100 || (float) $value !== floor((float) $value)) continue;
        $combatStats[] = ['index'=>$index, 'value'=>(int) $value];
    }
    if ($combatStats === []) return $stats;
    $chosen = $combatStats[$randomInt(0, count($combatStats) - 1)];
    $options = array_values(array_filter([-3,-2,-1,1,2,3], static fn(int $delta): bool => $chosen['value']+$delta >= 1 && $chosen['value']+$delta <= 100));
    $index = $chosen['index'];
    $updated = $chosen['value'] + $options[$randomInt(0, count($options) - 1)];
    $stats[$index]['value'] = is_string($stats[$index]['value']) ? (string) $updated : $updated;
    return $stats;
}

function applicationMonsterReinforcementProfile(array $source, array $template, array $weapons, callable $randomInt): array
{
    $goblin = applicationMonsterReinforcementIsGoblin((string) ($source['name'] ?? ''), (string) ($template['name'] ?? ''));
    $maxHp = max(1, min(1000000000, (int) round((float) $source['maxHp'])));
    $variationPercent = match (true) {
        $maxHp <= 50 => 30,
        $maxHp <= 150 => 50,
        default => 5,
    };
    $spread = intdiv($maxHp * $variationPercent, 100);
    $maxHp = $goblin ? $randomInt(35, 50) : $randomInt(max(1, $maxHp - $spread), min(1000000000, $maxHp + $spread));
    $formula = $goblin ? ('1d'.$randomInt(20, 30).'+5') : null;
    if (count($weapons) > 1) {
        $signature = static fn(array $weapon): array => [
            onlineConditionKey((string) ($weapon['name'] ?? '')), $weapon['formula'], $weapon['damageType'],
            $weapon['damageElement'] ?? null, $weapon['reach'] ?? null, ($weapon['projectile'] ?? false) === true,
        ];
        $initial = $signature($weapons[0]);
        $alternatives = [];
        foreach (array_slice($weapons, 1, null, true) as $index => $weapon) {
            if ($signature($weapon) !== $initial) $alternatives[] = $index;
        }
        if ($alternatives !== [] && $randomInt(1, 4) === 1) {
            $selected = $alternatives[$randomInt(0, count($alternatives) - 1)];
            $first = $weapons[$selected];
            array_splice($weapons, $selected, 1);
            array_unshift($weapons, $first);
        }
    }
    if ($goblin) {
        if ($weapons === []) $weapons = [['id'=>'weapon-1','damageType'=>'physical','onHitConditions'=>[]]];
        $weapons[0]['formula'] = $formula;
    }
    $stats = applicationMonsterReinforcementStats(is_array($source['stats'] ?? null) ? $source['stats'] : [], $randomInt);
    return [
        'hp'=>$maxHp, 'maxHp'=>$maxHp, 'stats'=>$stats, 'weaponAttacks'=>$weapons, 'damageDice'=>$weapons[0]['formula'] ?? '',
        'damageType'=>$weapons[0]['damageType'] ?? normalizeOnlineDamageType($source['damageType'] ?? null),
        'damageElement'=>$weapons[0]['damageElement'] ?? null,
    ];
}

function applyOnlineMonsterReinforcementCommand(PDO $connection, array &$records, array &$pending, array $arguments, array $identity, bool $isGm): array
{
    if (!$isGm) rejectOnlineCommand($connection, 403, 'Les renforts sont réservés au MJ.', 'gm_mode_required');
    $argument = static fn(string $name): string => is_string($arguments[$name] ?? null) ? trim($arguments[$name]) : '';
    $sceneId = $argument('sceneId');
    $layerId = $argument('layerId');
    $sourceId = $argument('tokenId');
    $requestId = $argument('requestId');
    $mapKey = 'map:'.$sceneId;
    $indexKey = 'token-index:'.$sceneId;
    $sourceKey = onlineTokenDomainKey($sceneId, $sourceId);
    if (!validApplicationDomainKey($mapKey) || $sourceKey === '' || !in_array($layerId, ['basement','ground','upper'], true)
        || preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $requestId) !== 1) {
        rejectOnlineCommand($connection, 400, 'Le pion, le niveau ou la demande de renfort est invalide.', 'invalid_token_reinforce_request');
    }
    $signature = onlineCommandRequestSignature('token.reinforce', ['sceneId'=>$sceneId, 'tokenId'=>$sourceId, 'layerId'=>$layerId]);
    $accountId = (string) $identity['id'];
    $context = onlinePersistentCommandReceipt($connection, $records, 'token-reinforcement', $requestId, $accountId, $signature, 'token_reinforce');
    $receipt = $context['receipt'];
    if (is_array($receipt)) {
        $key = onlineTokenDomainKey($sceneId, $receipt['result']['tokenId'] ?? '');
        $records = array_replace($records, applicationDomainRecords($connection, [$key]));
        $token = applicationDomainPayload($records, $key);
        if ($token === []) rejectOnlineCommand($connection, 409, 'Le renfort créé par cette demande n’existe plus.', 'token_reinforce_receipt_stale');
        return ['sceneId'=>$sceneId, 'token'=>$token, 'tokenDomain'=>['key'=>$key, 'revision'=>(int) ($records[$key]['revision'] ?? 0)], 'deduplicated'=>true];
    }

    $records = array_replace($records, applicationDomainRecords($connection, ['scene:'.$sceneId, $mapKey, $indexKey]));
    $map = applicationDomainPayload($records, $mapKey);
    $index = applicationDomainPayload($records, $indexKey, ['order'=>[]]);
    $order = is_array($index['order'] ?? null) ? $index['order'] : [];
    if (applicationDomainPayload($records, 'scene:'.$sceneId) === [] || $map === []
        || $layerId !== onlineTokenLayerId([], $map) || !in_array($sourceId, $order, true)) {
        rejectOnlineCommand($connection, 409, 'Le pion n’est plus sur le niveau actif de cette scène.', 'stale_token_layer');
    }
    $records = onlineSceneTokenRecords($connection, $sceneId, $records);
    $source = applicationDomainPayload($records, $sourceKey);
    if ($source === [] || $layerId !== onlineTokenLayerId($source, $map)) {
        rejectOnlineCommand($connection, 409, 'Le pion n’est plus sur le niveau actif de cette scène.', 'stale_token_layer');
    }
    $frame = normalizeOnlineTokenFrameVariant($source['frameVariant'] ?? null);
    if (!in_array($frame, ['creature','elite','boss'], true)
        || applicationIsNonCombatant($source) || !empty($source['immovable']) || !empty($source['artworkOnly'])
        || !empty($source['characterId']) || !empty($source['controllerPlayerId']) || !empty($source['linkedTokenId'])
        || !is_numeric($source['maxHp'] ?? null) || !is_finite((float) $source['maxHp']) || $source['maxHp'] <= 0
        || !validApplicationDomainNumber($source['x'] ?? null, 0, 100)
        || !validApplicationDomainNumber($source['y'] ?? null, 0, 100)) {
        rejectOnlineCommand($connection, 409, 'Ce pion ne peut pas appeler de renfort.', 'token_reinforce_unavailable');
    }
    $weapons = applicationMonsterReinforcementWeapons($source);
    $template = [];
    $sourceName = (string) ($source['name'] ?? '');
    if (!empty($source['libraryTemplateId']) && ($weapons === [] || !applicationMonsterReinforcementIsGoblin($sourceName, ''))) {
        $records = array_replace($records, applicationDomainRecords($connection, ['library']));
        foreach (applicationDomainPayload($records, 'library')['tokenLibrary'] ?? [] as $entry) {
            if (($entry['id'] ?? null) === $source['libraryTemplateId']) { $template = $entry; break; }
        }
    }
    if ($weapons === [] && $template !== []) $weapons = applicationMonsterReinforcementWeapons($template);
    $hasAbility = array_filter(is_array($source['abilities'] ?? null) ? $source['abilities'] : [], static fn($ability): bool =>
        is_array($ability) && is_string($ability['id'] ?? null) && $ability['id'] !== '' && ($ability['enabled'] ?? true) !== false);
    if ($weapons === [] && $hasAbility === []) {
        rejectOnlineCommand($connection, 409, 'Cette créature doit avoir au moins une attaque ou une compétence.', 'token_reinforce_unavailable');
    }
    if (count($order) >= 2000) rejectOnlineCommand($connection, 409, 'La scène a atteint sa limite de pions.', 'token_limit');

    $sameLayer = [];
    foreach ($order as $id) {
        $other = applicationDomainPayload($records, onlineTokenDomainKey($sceneId, $id));
        if ($other !== [] && onlineTokenLayerId($other, $map) === $layerId) $sameLayer[] = [...$other, 'layerId'=>$layerId];
    }
    $templateName = (string) ($template['name'] ?? '');
    $renamedGoblin = !applicationMonsterReinforcementIsGoblin($sourceName, '') && applicationMonsterReinforcementIsGoblin($sourceName, $templateName);
    $base = applicationMonsterReinforcementBaseName($renamedGoblin ? $templateName : $sourceName);
    $name = rtrim(onlineUtf8ByteSlice($base, 120));
    $now = (int) floor(microtime(true) * 1000);
    $maxMana = max(0, min(1000000000, (float) ($source['maxMana'] ?? 0)));
    $token = [
        ...array_intersect_key($source, array_flip(['image', 'frameVariant', 'color', 'size', 'sizeCategory', 'armorCategory', 'armor',
            'magicArmorCategory', 'magicArmor', 'elementalResistances', 'temporalPerception', 'visionDistance', 'darkVision',
            'abilities', 'passiveAbilities', 'conditionImmunities', 'friendlyFire', 'initiativeBonus', 'hidden', 'revealDetailsToPlayers',
            'allied', 'bestiaryRole', 'bestiaryRank', 'bestiaryProfile', 'hitThreshold', 'mountable', 'mountControllable', 'maxRiders', 'notes', 'gmNotes'])),
        ...applicationMonsterReinforcementProfile($source, $template, $weapons, 'random_int'),
        'id'=>'token-'.randomToken(16), 'name'=>$name, 'frameVariant'=>$frame,
        'layerId'=>$layerId, 'followCharacter'=>false, 'characterId'=>null, 'controllerPlayerId'=>null, 'linkedTokenId'=>null,
        'initiative'=>null, 'healthOverride'=>null, 'conditions'=>[], 'condition'=>'', 'fatigue'=>['current'=>0,'max'=>150],
        'specialSuccessStock'=>0, 'mana'=>$maxMana, 'maxMana'=>$maxMana,
        '_updatedAt'=>$now, '_movedAt'=>$now,
    ];
    if (applicationUnitScalesWithMap($token)) $token['sizeCategory'] = applicationUnitSizeCategory($source);
    $token = applicationScaleUnitForMap($token, $map, $layerId);
    $level = applicationMapForUnitLayer($map, $layerId);
    $width = (float) ($level['naturalWidth'] ?? 1600) ?: 1600.0;
    $height = (float) ($level['naturalHeight'] ?? 900) ?: 900.0;
    $walls = $level['walls'] ?? null;
    $level['walls'] = applicationTerrainBarrierWalls(validApplicationWallState($walls) ? $walls : emptyApplicationWallState($width, $height),
        [...$level, 'layers'=>[], 'tokens'=>$sameLayer]);
    $occupied = array_values(array_filter($sameLayer, static fn(array $other): bool =>
        is_numeric($other['x'] ?? null) && is_numeric($other['y'] ?? null) && empty($other['artworkOnly'])
            && empty($other['mountedOnTokenId']) && !applicationIsRectangularObstacle($other)));
    $start = ['x'=>(float) $source['x'], 'y'=>(float) $source['y']];
    $spacing = (onlineGroupTokenSize($source) + onlineGroupTokenSize($token)) / 2 + 2;
    $desired = ['x'=>$start['x'] + $spacing / $width * 100, 'y'=>$start['y']];
    $position = nearestOnlineGroupPosition($desired, $token, $level, $occupied, $start, 0, null, false, max(2, $spacing / 12));
    if ($position === null) rejectOnlineCommand($connection, 409, 'Aucune place libre près de cette créature.', 'reinforcement_position_unavailable');

    $token = [...$token, ...$position];
    $key = onlineTokenDomainKey($sceneId, $token['id']);
    $index['order'][] = $token['id'];
    queueOnlineDomainUpsert($pending, $records, $key, $token);
    queueOnlineDomainUpsert($pending, $records, $indexKey, $index);
    onlineStorePersistentCommandReceipt($records, $pending, $context, 'token-reinforcement', $requestId, $accountId, '', $key, $signature,
        ['sceneId'=>$sceneId, 'tokenId'=>$token['id']]);
    return ['sceneId'=>$sceneId, 'token'=>$token, 'tokenDomain'=>['key'=>$key, 'revision'=>1], 'deduplicated'=>false];
}
