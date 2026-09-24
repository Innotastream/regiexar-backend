<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/v1/domains.php';
require_once __DIR__ . '/../api/v1/online.php';

final class PendingDomainError extends RuntimeException {
    public function __construct(public int $status, public string $errorCode) { parent::__construct($errorCode); }
}
function sendError(int $status, string $message, string $code = ''): never { throw new PendingDomainError($status, $code); }
function pendingDomainCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    $GLOBALS['pendingDomainChecks'] = ($GLOBALS['pendingDomainChecks'] ?? 0) + 1;
}
function pendingDomainRefuses(callable $action, string $code): void {
    try { $action(); } catch (PendingDomainError $error) {
        pendingDomainCheck($error->errorCode === $code, 'Unexpected refusal: ' . $error->errorCode); return;
    }
    throw new RuntimeException('Expected refusal: ' . $code);
}
function preparedActivity(array $incoming, ?array $previous, bool $client = true): array {
    $current = $previous === null ? null : ['payload' => $previous, 'revision' => 3];
    return prepareApplicationDomainUpsert('activity', $incoming, $current, $client)['payload'] ?? $previous ?? [];
}
$entry = ['id' => 'validation-synthetic', 'requestId' => 'request-synthetic', 'status' => 'pending', 'route' => 'ability.use',
    'sceneId' => 'prepared-scene', 'cast' => ['success' => true, 'total' => 100],
    '_private' => ['identity' => ['id' => 'synthetic-account'], 'isGm' => false, 'body' => ['requestId' => 'request-synthetic'],
        'ability' => ['id' => 'synthetic-ability'], 'plan' => ['abilityId' => 'synthetic-ability', 'manaCost' => 2],
        'controllerAccountId' => 'synthetic-account', 'sourceCharacterId' => 'synthetic-character', 'targetCharacterId' => '']];
$base = legacyStateToDomains([])['activity'];
$activity = [...$base, 'pendingAbilityCasts' => [$entry]];
pendingDomainCheck(validatedDomainPayload('activity', $activity) === $activity, 'The trusted snapshot survives validation exactly.');
$entries = [];
for ($i = 0; $i < XAR_PENDING_ABILITY_CAST_MAXIMUM; $i++) $entries[] = [...$entry, 'id' => 'validation-' . $i];
pendingDomainCheck(validApplicationPendingAbilityCasts($entries), 'Thirty active validations are accepted without trimming.');
pendingDomainCheck(!validApplicationPendingAbilityCasts([...$entries, [...$entry, 'id' => 'overflow']]), 'The thirty-first active validation is refused.');
pendingDomainCheck(!validApplicationPendingAbilityCasts([$entry, $entry]), 'Duplicate identifiers are refused.');
pendingDomainCheck(!validApplicationPendingAbilityCasts([[...$entry, 'status' => 'approved']]), 'Completed entries cannot remain in the active list.');
pendingDomainCheck(!validApplicationPendingAbilityCasts([[...$entry, 'route' => 'forged']]), 'Unknown continuation routes are refused.');
pendingDomainCheck(!validApplicationPendingAbilityCasts([[...$entry, '_private' => null]]), 'A missing trusted snapshot is refused.');
$domains = legacyStateToDomains(['pendingAbilityCasts' => [$entry]]);
$records = array_map(static fn (array $payload): array => ['revision' => 1, 'payload' => $payload], $domains);
pendingDomainCheck(domainsToApplicationState($records, 1)['pendingAbilityCasts'] === [$entry], 'Legacy/domain roundtrip preserves pending validation exactly.');
foreach ([[], [[...$entry, 'status' => 'approved']], 'invalid'] as $forged) {
    $incoming = [...$base, 'pendingAbilityCasts' => $forged];
    pendingDomainCheck(preparedActivity($incoming, $activity)['pendingAbilityCasts'] === [$entry], 'Client mutation or removal cannot alter active server validations.');
}
$oldClient = $base; unset($oldClient['pendingAbilityCasts']);
pendingDomainCheck(preparedActivity($oldClient, $activity)['pendingAbilityCasts'] === [$entry], 'An older client omitting the list preserves pending validation.');
pendingDomainCheck(preparedActivity($activity, $base)['pendingAbilityCasts'] === [], 'An authoritative empty list removes client injection.');
pendingDomainCheck(!array_key_exists('pendingAbilityCasts', preparedActivity($activity, $oldClient)), 'An authoritative absent field removes client injection.');
pendingDomainCheck(!array_key_exists('pendingAbilityCasts', preparedActivity($activity, null)), 'A client cannot create the first trusted validation.');
pendingDomainCheck(preparedActivity($activity, $base, false)['pendingAbilityCasts'] === [$entry], 'The authoritative command can create a validation.');
pendingDomainCheck(preparedActivity($base, $activity, false)['pendingAbilityCasts'] === [], 'The authoritative command can resolve a validation.');
$deferred = ['ability' => ['id' => 'ability-deferred'], 'characterId' => 'synthetic-character', 'plan' => ['manaCost' => 2],
    'identity' => ['id' => 'synthetic-account'], 'isGm' => false, 'controllerAccountId' => 'synthetic-account'];
$attack = ['id' => 'attack-synthetic', 'status' => 'awaiting-gm', 'sceneId' => 'prepared-scene', 'sourceTokenId' => 'token-synthetic',
    'cast' => ['success' => true, 'cooldownRounds' => 4], 'deferredAbilityCast' => $deferred];
$receipt = ['requestId' => 'attack-request', 'signature' => 'original', 'attack' => $attack];
$previous = [...$base, 'pendingAttacks' => [$attack], 'attackReceipts' => [$receipt]];
$forgedAttack = [...$attack, 'status' => 'resolved', 'sceneId' => 'wrong-scene', 'sourceTokenId' => 'wrong-token',
    'cast' => ['success' => false, 'cooldownRounds' => 0], 'deferredAbilityCast' => ['plan' => ['manaCost' => 0]]];
$incoming = [...$base, 'pendingAttacks' => [$forgedAttack], 'attackReceipts' => [[...$receipt, 'signature' => 'forged', 'attack' => $forgedAttack]]];
$result = preparedActivity($incoming, $previous);
pendingDomainCheck($result['pendingAttacks'] === [$attack], 'A deferred attack keeps its entire authoritative status, cast and context.');
pendingDomainCheck($result['attackReceipts'] === [$receipt], 'A deferred attack receipt remains exact.');
$result = preparedActivity($base, $previous);
pendingDomainCheck($result['pendingAttacks'] === [$attack] && $result['attackReceipts'] === [$receipt], 'Erasing entire arrays cannot discard a deferred attack or its receipt.');
$ordinary = $attack; unset($ordinary['deferredAbilityCast']);
$result = preparedActivity([...$base, 'pendingAttacks' => [$attack]], [...$base, 'pendingAttacks' => [$ordinary]]);
pendingDomainCheck(!array_key_exists('deferredAbilityCast', $result['pendingAttacks'][0]), 'A client cannot inject deferred authority into an ordinary attack.');
pendingDomainRefuses(fn () => prepareApplicationDomainDelete('activity', ['payload' => $activity]), 'readonly_activity_domain');
pendingDomainRefuses(fn () => prepareApplicationDomainDelete('activity', ['payload' => $previous]), 'readonly_activity_domain');
pendingDomainRefuses(fn () => prepareApplicationDomainDelete('activity', ['payload' => $base]), 'readonly_activity_domain');
$full = [...$activity, 'resourceReceipts' => array_fill(0, XAR_RESOURCE_RECEIPT_MAXIMUM, ['requestId' => 'synthetic'])];
pendingDomainRefuses(fn () => validatedDomainPayload('activity', $full), 'invalid_activity_domain');
$originalReceipt = ['requestId' => 'request-synthetic', 'kind' => 'ability-cast', 'expiresAt' => 9007199254740991, 'result' => ['pendingValidation' => true]];
$terminalReceipt = ['requestId' => 'resolve-synthetic', 'kind' => 'ability-validation', 'expiresAt' => 9007199254740991, 'result' => ['validationRejected' => true]];
$previous = [...$activity, 'resourceReceipts' => [$originalReceipt, $terminalReceipt]];
$forgedReceipt = [...$terminalReceipt, 'requestId' => 'forged-resolution'];
$incoming = [...$base, 'resourceReceipts' => [[...$originalReceipt, 'result' => ['pendingValidation' => false]], $forgedReceipt]];
$result = preparedActivity($incoming, $previous);
pendingDomainCheck($result['resourceReceipts'] === [$originalReceipt, $terminalReceipt], 'Generic activity patch preserves exact live authority receipts and discards forged new ones.');
$restored = preserveApplicationPendingAbilityValidation($base, $previous);
$restored = preserveApplicationAbilityExtensions('activity', $restored, $previous);
$restored = validatedDomainPayload('activity', $restored);
pendingDomainCheck($restored['pendingAbilityCasts'] === [$entry] && $restored['resourceReceipts'] === [$originalReceipt, $terminalReceipt], 'History restoration preserves both continuation and immutable initial/terminal receipts.');
echo 'Pending ability domain contract: ' . $GLOBALS['pendingDomainChecks'] . " checks passed.\n";
