<?php

declare(strict_types=1);

require_once __DIR__ . '/health-state.php';
require_once __DIR__ . '/elemental-resistances.php';
require_once __DIR__ . '/fixed-structures.php';

function applicationImpactBleedingTargetIdentity(array $token): string
{
    return unitUsesCharacterSheet($token) ? 'character:' . $token['characterId'] : 'token:' . ($token['id'] ?? '');
}

function applicationIsImpactBleeding(array $effect): bool
{
    return str_starts_with((string) ($effect['id'] ?? ''), 'impact-bleed-');
}

function applicationQualifiesForImpactBleeding(
    array $token, int|float $previous, int|float $current, int|float $maximum, bool $playerControlled
): bool {
    return !empty($token['id']) && !applicationIsNonCombatant($token)
        && $maximum > 0 && ($previous - $current) * 4 >= $maximum
        && onlineHealthState($current, $maximum, $playerControlled, onlineManualDeath($token))['code'] !== 'dead'
        && !applicationElementalStatusImmune($token, 'bleeding');
}

function applicationImpactBleedingOperationChange(array $operation): ?array
{
    $change = $operation['impactBleeding'] ?? null;
    if (($operation['kind'] ?? '') !== 'resource-adjust' || ($operation['resource'] ?? '') !== 'hp'
        || !is_numeric($operation['appliedDelta'] ?? null) || $operation['appliedDelta'] >= 0 || !is_array($change)
        || !array_key_exists('before', $change) || !is_array($change['after'] ?? null)) return null;
    $sceneId = (string) ($operation['sceneId'] ?? '');
    $tokenId = (string) ($operation['tokenId'] ?? '');
    $characterId = (string) ($operation['characterId'] ?? '');
    if ($sceneId === '' || $tokenId === '') return null;
    $identity = $characterId !== '' ? 'character:' . $characterId : 'token:' . $tokenId;
    $expectedId = 'impact-bleed-' . substr(hash('sha256', $sceneId . '|' . $identity), 0, 32);

    foreach (['before','after'] as $side) {
        $effect = $change[$side];
        if ($side === 'before' && $effect === null) continue;
        if (!is_array($effect) || ($effect['id'] ?? '') !== $expectedId || ($effect['sceneId'] ?? '') !== $sceneId
            || ($effect['targetIdentity'] ?? '') !== $identity
            || ($side === 'after' && ($effect['targetTokenId'] ?? '') !== $tokenId)
            || !is_string($effect['targetTokenId'] ?? null) || preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$effect['targetTokenId']) !== 1
            || !is_string($effect['impactPulseId'] ?? null) || preg_match('/^resource-[A-Za-z0-9_-]{1,110}$/D',$effect['impactPulseId']) !== 1
            || !is_string($effect['combatId'] ?? null) || preg_match('/^[A-Za-z0-9_-]{0,180}$/D',$effect['combatId']) !== 1
            || ($effect['damageType'] ?? '') !== 'bleeding' || ($effect['family'] ?? '') !== 'bleeding'
            || ($effect['formula'] ?? '') !== '5' || ($effect['label'] ?? '') !== 'Saignement d’impact'
            || !is_int($effect['remainingTurns'] ?? null) || $effect['remainingTurns'] < 1 || $effect['remainingTurns'] > 3
            || !is_int($effect['lastTurnSerial'] ?? null) || $effect['lastTurnSerial'] < 0
            || !is_int($effect['lastManualTurn'] ?? null) || $effect['lastManualTurn'] < 0
            || (array_key_exists('lastTargetRound',$effect) && (!is_int($effect['lastTargetRound']) || $effect['lastTargetRound'] < 1)))
            return null;
    }
    return ['before'=>$change['before'], 'after'=>$change['after']];
}

function applicationApplyImpactBleeding(
    array &$activity, array $token, string $sceneId, array $clock, int $manualTurn, string $impactPulseId
): ?array {
    if (applicationHasEffectImmunity($token, $activity, $sceneId, 'bleeding')) return null;

    $targetIdentity = applicationImpactBleedingTargetIdentity($token);
    $id = 'impact-bleed-' . substr(hash('sha256', $sceneId . '|' . $targetIdentity), 0, 32);
    $inCombat = !empty($clock['active']);
    $targetIndex = array_search($token['id'], $clock['order'] ?? [], true);
    $effect = [
        'id' => $id,
        'sceneId' => $sceneId,
        'combatId' => $inCombat ? ($clock['combatId'] ?? '') : '',
        'targetTokenId' => $token['id'],
        'targetIdentity' => $targetIdentity,
        'impactPulseId' => $impactPulseId,
        'label' => 'Saignement d’impact',
        'damageType' => 'bleeding',
        'family' => 'bleeding',
        'formula' => '5',
        'remainingTurns' => 3,
        'lastTurnSerial' => $inCombat ? (int) ($clock['turnSerial'] ?? 0) : 0,
        'lastManualTurn' => $manualTurn,
        ...($inCombat && $targetIndex !== false && $targetIndex <= ($clock['currentIndex'] ?? 0)
            ? ['lastTargetRound' => (int) ($clock['round'] ?? 1)] : []),
    ];
    foreach ($activity['damageOverTime'] ?? [] as $index => $active) {
        if (($active['id'] ?? null) === $id) {
            $activity['damageOverTime'][$index] = $effect;
            return ['before'=>$active, 'after'=>$effect];
        }
    }
    $activity['damageOverTime'][] = $effect;
    return ['before'=>null, 'after'=>$effect];
}

function onlineApplyImpactBleeding(
    PDO $connection, array &$records, array &$pending, string $sceneId, array $token, ?array $character,
    int|float $previous, int|float $current, int|float $maximum, bool $playerControlled, string $impactPulseId
): ?array {
    if ($sceneId === '' || $maximum <= 0 || ($previous - $current) * 4 < $maximum) return null;
    if ($character !== null && unitUsesCharacterSheet($token)) {
        $token['elementalResistances'] = $character['elementalResistances'] ?? [];
        $token['conditionImmunities'] = $character['conditionImmunities'] ?? [];
        $token['healthOverride'] = onlineManualDeath($character) ? 'dead' : null;
    }
    if (!applicationQualifiesForImpactBleeding($token, $previous, $current, $maximum, $playerControlled)) return null;

    $clockKey = 'initiative:' . $sceneId;
    $missing = array_diff(['activity', $clockKey, 'devices'], array_keys($records), array_keys($pending));
    if ($missing !== []) $records = array_replace($records, applicationDomainRecords($connection, array_values($missing)));
    $activity = $pending['activity']['payload'] ?? applicationDomainPayload($records, 'activity');
    $clock = $pending[$clockKey]['payload'] ?? applicationDomainPayload($records, $clockKey);
    $devices = $pending['devices']['payload'] ?? applicationDomainPayload($records, 'devices');
    $change = applicationApplyImpactBleeding($activity, $token, $sceneId, $clock, (int) ($devices['manualTurns'][$sceneId] ?? 0), $impactPulseId);
    if ($change === null) return null;
    queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
    return $change;
}
