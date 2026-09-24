<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/v1/domains.php';
require_once __DIR__ . '/../api/v1/online.php';
require_once __DIR__ . '/../api/v1/ability-assistant.php';

function randomToken(int $bytes): string {
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function assertAssistantDraft(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function conversationWithAbilityContext(array $context): array {
    return ['context_json' => json_encode($context, JSON_THROW_ON_ERROR)];
}

function rejectedAssistantDraft(array $conversation, array $draft): bool {
    try {
        normalizeAbilityAssistantDraft($conversation, $draft);
    } catch (InvalidArgumentException) {
        return true;
    }
    return false;
}

$context = [
    'character' => ['id' => 'character-hero'],
    'existingAbility' => null,
    'linkedTokens' => [['id' => 'linked-dragon', 'name' => 'Dragon']],
    'availableForms' => [['id' => 'character-wolf', 'name' => 'Loup']],
];
$conversation = conversationWithAbilityContext($context);
$damage = normalizeAbilityAssistantDraft($conversation, [
    'name' => 'Frappe de braise', 'effect' => 'damage', 'description' => 'Une flamme.',
    'damageComponents' => [['type' => 'magical', 'formula' => '2d6+3']],
    'castingStatId' => 'character-stat-intelligence', 'restRecharge' => 'none', 'usesPerRest' => 3,
]);
assertAssistantDraft(($damage['effect'] ?? '') === 'damage' && ($damage['formula'] ?? '') === '2d6+3'
    && validApplicationAbilities([$damage]), 'Une compétence classique typée doit être applicable.');
assertAssistantDraft(array_key_exists('sound', $damage['completionCue'])
    && $damage['completionCue']['sound'] === null, 'Le brouillon IA ne crée pas de son.');
assertAssistantDraft(rejectedAssistantDraft($conversation, [
    'name' => 'Frappe sans dégâts', 'effect' => 'damage', 'damageComponents' => [],
]), 'Une proposition de dégâts sans formule doit être refusée.');
assertAssistantDraft(rejectedAssistantDraft($conversation, [
    'name' => 'Invocation inconnue', 'effect' => 'summoning', 'summonLinkedTokenId' => 'linked-foreign',
]), 'L’assistant ne peut pas inventer un pion lié.');
assertAssistantDraft(rejectedAssistantDraft($conversation, [
    'name' => 'Forme étrangère', 'effect' => 'metamorphosis', 'formCharacterId' => 'character-foreign',
]), 'L’assistant ne peut pas désigner une fiche étrangère.');
assertAssistantDraft(normalizeAbilityAssistantDraft($conversation, [
    'name' => 'Dragon allié', 'effect' => 'summoning', 'summonLinkedTokenId' => 'linked-dragon',
])['effect'] === 'summoning', 'Une invocation appartenant à la fiche est admise.');
assertAssistantDraft(normalizeAbilityAssistantDraft($conversation, [
    'name' => 'Forme de loup', 'effect' => 'metamorphosis', 'formCharacterId' => 'character-wolf',
])['effect'] === 'metamorphosis', 'Une forme du même propriétaire est admise.');

$sound = [
    'version' => 1, 'trigger' => 'completed',
    'sound' => ['url' => '/media/abcdefghijklmnopqrstuvwx', 'name' => 'Souffle.wav',
        'contentType' => 'audio/wav', 'byteSize' => 40044, 'durationMs' => 5000],
    'visual' => ['version' => 1, 'kind' => 'none'],
];
$existing = [...$damage, 'completionCue' => $sound];
$repair = normalizeAbilityAssistantDraft(conversationWithAbilityContext([
    ...$context, 'existingAbility' => $existing,
]), [
    'name' => 'Souffle réparé', 'effect' => 'healing', 'healingFormula' => '1d6+2',
    'completionCue' => ['sound' => null],
]);
assertAssistantDraft(($repair['id'] ?? '') === $existing['id']
    && ($repair['healingFormula'] ?? '') === '1d6+2'
    && ($repair['completionCue']['sound']['url'] ?? '') === $sound['sound']['url']
    && validApplicationAbilities([$repair]), 'La réparation classique préserve le son et son identifiant.');

echo "Contrat de l’assistant classique valide.\n";
