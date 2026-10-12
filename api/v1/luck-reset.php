<?php
declare(strict_types=1);

function onlineResetCharacterLuck(PDO $connection, array &$records, array &$pending, array $identity, array $arguments, string $sceneId): array
{
    if (!onlineIdentityIsGm($identity)) rejectOnlineCommand($connection, 403, 'La RAZ de la Chance exige le mode MJ.', 'gm_mode_required');
    $requestId = $arguments['requestId'] ?? '';
    if (!is_string($requestId) || preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $requestId) !== 1
        || array_diff(array_keys($arguments), ['requestId']) !== []) {
        rejectOnlineCommand($connection, 400, 'Référence de RAZ invalide.', 'invalid_luck_reset_request');
    }
    $signature = onlineCommandRequestSignature('luck-reset', ['scope' => 'all', 'value' => 50]);
    $accountId = (string) $identity['id'];
    $context = onlinePersistentCommandReceipt($connection, $records, 'luck-reset', $requestId, $accountId, $signature, 'luck_reset');
    if (is_array($context['receipt'])) return [...$context['receipt']['result'], 'deduplicated' => true];

    $characters = applicationDomainRecordsByPrefix($connection, 'character:');
    if (count($characters) > XAR_LUCK_CHARACTER_MAXIMUM) rejectOnlineCommand($connection, 409, 'La limite du calculateur de Chance est dépassée.', 'luck_character_limit');
    $records = array_replace($records, $characters, applicationDomainRecords($connection, ['luck']));
    $now = gmdate('c');
    $statistics = [];
    foreach ($characters as $entry) {
        $id = (string) ($entry['payload']['id'] ?? '');
        if (!validApplicationDomainIdentifier($id, 180)) rejectOnlineCommand($connection, 409, 'Une fiche ne permet pas la RAZ.', 'invalid_luck_character');
        $statistics[$id] = ['rollCount' => 0, 'rawTotal' => 0, 'startedAt' => $now, 'updatedAt' => $now, 'resetValue' => 50];
    }
    queueOnlineDomainUpsert($pending, $records, 'luck', ['characters' => $statistics]);
    $action = onlineAppendPlayerAction($connection, $records, $pending, $identity, $sceneId, [
        'kind' => 'action', 'summary' => 'Réinitialise la Chance de toutes les fiches',
        'detail' => count($statistics) . ' fiche(s) · historique de calcul vidé · valeur 50',
    ]);
    $result = ['reset' => true, 'characterCount' => count($statistics), 'resetAt' => $now, 'value' => 50];
    onlineStorePersistentCommandReceipt($records, $pending, $context, 'luck-reset', $requestId, $accountId,
        $action['id'], 'luck', $signature, $result);
    return $result;
}
