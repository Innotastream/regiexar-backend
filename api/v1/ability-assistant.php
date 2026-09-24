<?php

declare(strict_types=1);

const XAR_ABILITY_ASSISTANT_MAXIMUM_TURNS = 12;
const XAR_ABILITY_ASSISTANT_MAXIMUM_PROMPT_BYTES = 6000;
const XAR_ABILITY_ASSISTANT_MAXIMUM_RESPONSE_BYTES = 12000;

function validAbilityAssistantConversationId(string $id): bool
{
    return preg_match('/^[A-Za-z0-9_-]{22}$/D', $id) === 1;
}

function validAbilityAssistantMessageId(string $id): bool
{
    return preg_match('/^[A-Za-z0-9_-]{24}$/D', $id) === 1;
}

function cleanAbilityAssistantText(mixed $value, int $maximumBytes, string $label, bool $required = true): string
{
    $text = trim(str_replace(["\r\n", "\r"], "\n", is_scalar($value) ? (string) $value : ''));
    if (($required && $text === '') || strlen($text) > $maximumBytes || str_contains($text, "\0") || preg_match('//u', $text) !== 1) {
        sendError(400, $label . ' invalide.', 'invalid_ability_assistant_text');
    }
    $redacted = preg_replace([
        '~(?:Bearer|Basic)\s+\S+~i',
        '~\b(?:password|passwd|authorization|cookie|session[-_]?token|access[-_]?token|refresh[-_]?token|api[-_]?key|client[-_]?secret|webhook|token|secret|signature)\b["\x27]?\s*[:=]\s*(?:"[^"\r\n]*"|\x27[^\x27\r\n]*\x27|[^\s,;}]+)~i',
        '~https?://[^\s)"\x27<>]+~i',
        '~(?:[A-Z]:[\\\\/]|file:///|/(?:home|Users|root|tmp|workspace)/)[^\s)"\x27<>]+~i',
        '~\b[\w.+-]{1,128}@[\w.-]{1,128}\.[A-Za-z]{2,20}\b~',
        '~\b[A-Za-z0-9_=-]{32,}\b~',
    ], '[masqué]', $text);
    return is_string($redacted) ? $redacted : '';
}

function requireAbilityAssistantIdentity(PDO $connection): array
{
    $identity = resolveSession($connection, requestSessionToken());
    if (!is_array($identity) || !in_array((string) ($identity['effective_mode'] ?? ''), ['gm', 'player'], true)) {
        sendError(401, 'Connexion à la Régie requise.', 'authentication_required');
    }
    return $identity;
}

function abilityAssistantCharacter(PDO $connection, array $identity, string $characterId): array
{
    if (preg_match('/^[A-Za-z0-9_-]{1,180}$/D', $characterId) !== 1) {
        sendError(400, 'Référence de personnage invalide.', 'invalid_character');
    }
    $key = 'character:' . $characterId;
    $records = applicationDomainRecords($connection, [$key]);
    $character = applicationDomainPayload($records, $key);
    if ($character === [] || (string) ($character['id'] ?? '') !== $characterId) {
        sendError(404, 'Fiche de personnage introuvable.', 'character_missing');
    }
    $isGm = (string) ($identity['effective_mode'] ?? '') === 'gm'
        && (string) ($identity['permanent_role'] ?? '') === 'gm';
    if (!$isGm && (string) ($character['ownerPlayerId'] ?? '') !== (string) $identity['id']) {
        sendError(403, 'Cette fiche ne vous appartient pas.', 'character_forbidden');
    }
    return $character;
}

function abilityAssistantSafeCharacterContext(array $character, string $existingAbilityId = ''): array
{
    $abilities = normalizeOnlineAbilities($character['abilities'] ?? []);
    $existing = null;
    if ($existingAbilityId !== '') {
        foreach ($abilities as $ability) {
            if ((string) ($ability['id'] ?? '') === $existingAbilityId) {
                $existing = $ability;
                break;
            }
        }
        if (!is_array($existing)) {
            sendError(404, 'La compétence à examiner n’existe plus.', 'ability_missing');
        }
    }
    $resources = is_array($character['resources'] ?? null) ? $character['resources'] : [];
    $fatigue = is_array($character['fatigue'] ?? null) ? $character['fatigue'] : [];
    return [
        'character' => [
            'id' => (string) ($character['id'] ?? ''),
            'name' => substr((string) ($character['name'] ?? 'Personnage'), 0, 120),
            'race' => substr((string) ($character['race'] ?? ''), 0, 120),
            'className' => substr((string) ($character['className'] ?? ''), 0, 120),
            'advancedClass' => substr((string) ($character['advancedClass'] ?? ''), 0, 120),
            'maximums' => [
                'hp' => is_numeric($resources['maxHp'] ?? null) ? 0 + $resources['maxHp'] : 0,
                'mana' => is_numeric($resources['maxMana'] ?? null) ? 0 + $resources['maxMana'] : 0,
                'fatigue' => is_numeric($fatigue['max'] ?? null) ? 0 + $fatigue['max'] : 100,
            ],
            'stats' => is_array($character['stats'] ?? null) ? $character['stats'] : [],
            'hitThreshold' => $character['hitThreshold'] ?? null,
            'armorCategory' => (string) ($character['armorCategory'] ?? ''),
            'magicArmorCategory' => (string) ($character['magicArmorCategory'] ?? ''),
            'knownConditions' => array_slice(normalizeOnlineConditions($character['conditions'] ?? []), 0, 30),
        ],
        'mode' => is_array($existing) ? 'repair' : 'create',
        'existingAbility' => $existing,
        'linkedTokens' => array_values(array_slice(array_map(
            static fn(array $token): array => ['id' => (string) ($token['id'] ?? ''), 'name' => substr((string) ($token['name'] ?? ''), 0, 120)],
            array_filter(is_array($character['linkedTokens'] ?? null) ? $character['linkedTokens'] : [], 'is_array')
        ), 0, 50)),
        'otherAbilityNames' => array_values(array_slice(array_map(
            static fn(array $ability): string => substr((string) ($ability['name'] ?? ''), 0, 120),
            array_filter($abilities, static fn(array $ability): bool => (string) ($ability['id'] ?? '') !== $existingAbilityId)
        ), 0, 80)),
    ];
}

function abilityAssistantConversationRecord(PDO $connection, string $id): ?array
{
    if (!validAbilityAssistantConversationId($id)) return null;
    $statement = $connection->prepare(
        'SELECT c.*, a.username, a.display_name FROM ability_assistant_conversations c '
        . 'JOIN accounts a ON a.id = c.owner_account_id WHERE c.id = :id LIMIT 1'
    );
    $statement->execute([':id' => $id]);
    $row = $statement->fetch();
    return is_array($row) ? $row : null;
}

function requireOwnedAbilityAssistantConversation(PDO $connection, array $identity, string $id): array
{
    $conversation = abilityAssistantConversationRecord($connection, $id);
    if (!is_array($conversation)) sendError(404, 'Conversation d’assistance introuvable.', 'conversation_missing');
    if ((string) $conversation['owner_account_id'] !== (string) $identity['id']) {
        sendError(403, 'Cette conversation ne vous appartient pas.', 'conversation_forbidden');
    }
    if ((string) $conversation['character_id'] !== '') {
        abilityAssistantCharacter($connection, $identity, (string) $conversation['character_id']);
    }
    return $conversation;
}

function abilityAssistantConversationPayload(array $row): array
{
    $context = json_decode((string) ($row['context_json'] ?? '{}'), true);
    return [
        'id' => (string) $row['id'],
        'characterId' => (string) $row['character_id'],
        'title' => (string) $row['title'],
        'mode' => in_array($context['mode'] ?? '', ['repair', 'help'], true) ? (string) $context['mode'] : 'create',
        'existingAbilityId' => (string) ($context['existingAbility']['id'] ?? ''),
        'createdAt' => (string) $row['created_at'],
        'updatedAt' => (string) $row['updated_at'],
    ];
}

function createAbilityAssistantConversation(PDO $connection): never
{
    $identity = requireAbilityAssistantIdentity($connection);
    $payload = readJsonBody(32768);
    $characterId = trim((string) ($payload['characterId'] ?? ''));
    $existingAbilityId = trim((string) ($payload['existingAbilityId'] ?? ''));
    $help = ($payload['mode'] ?? '') === 'help';
    if ($existingAbilityId !== '' && preg_match('/^[A-Za-z0-9_-]{1,120}$/D', $existingAbilityId) !== 1) {
        sendError(400, 'Référence de compétence invalide.', 'invalid_ability');
    }
    if ($help && $existingAbilityId !== '') sendError(400, 'Une aide générale ne répare pas une compétence.', 'invalid_ability');
    $character = $help && $characterId === '' ? null : abilityAssistantCharacter($connection, $identity, $characterId);
    $context = is_array($character) ? abilityAssistantSafeCharacterContext($character, $existingAbilityId) : [
        'mode' => 'help', 'role' => (string) $identity['effective_mode'], 'character' => null,
        'existingAbility' => null, 'otherAbilityNames' => [],
    ];
    if (is_array($character)) {
        $forms = [];
        foreach (applicationDomainRecordsByPrefix($connection, 'character:') as $key => $record) {
            $candidate = applicationDomainPayload([$key => $record], $key);
            if ((string) ($candidate['ownerPlayerId'] ?? '') !== (string) ($character['ownerPlayerId'] ?? '')
                || (string) ($candidate['id'] ?? '') === $characterId) continue;
            $forms[] = ['id' => (string) $candidate['id'], 'name' => substr((string) ($candidate['name'] ?? 'Forme'), 0, 120)];
            if (count($forms) >= 50) break;
        }
        $context['availableForms'] = $forms;
    }
    if ($help) $context['mode'] = 'help';
    $context['role'] = (string) $identity['effective_mode'];
    $id = randomToken(16);
    $title = $help ? 'Aide sur la Régie' : ($context['mode'] === 'repair'
        ? 'Réparer ' . (string) ($context['existingAbility']['name'] ?? 'une compétence')
        : 'Créer une compétence pour ' . (string) ($context['character']['name'] ?? 'un personnage'));
    $statement = $connection->prepare(
        'INSERT INTO ability_assistant_conversations (id, owner_account_id, character_id, title, context_json) '
        . 'VALUES (:id, :owner_account_id, :character_id, :title, :context_json)'
    );
    $statement->execute([
        ':id' => $id,
        ':owner_account_id' => (string) $identity['id'],
        ':character_id' => $characterId,
        ':title' => substr($title, 0, 180),
        ':context_json' => json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ]);
    $conversation = abilityAssistantConversationRecord($connection, $id);
    sendJson(201, ['ok' => true, 'conversation' => abilityAssistantConversationPayload($conversation)]);
}

function abilityAssistantMessageRecord(PDO $connection, string $id): ?array
{
    if (!validAbilityAssistantMessageId($id)) return null;
    $statement = $connection->prepare('SELECT * FROM ability_assistant_messages WHERE id = :id LIMIT 1');
    $statement->execute([':id' => $id]);
    $row = $statement->fetch();
    return is_array($row) ? $row : null;
}

function abilityAssistantMessagePayload(array $row): array
{
    $draft = $row['draft_json'] === null ? null : json_decode((string) $row['draft_json'], true);
    $payload = [
        'id' => (string) $row['id'],
        'conversationId' => (string) $row['conversation_id'],
        'prompt' => (string) $row['prompt'],
        'response' => $row['response_text'] === null ? null : (string) $row['response_text'],
        'assistantStatus' => $row['assistant_status'] === null ? null : (string) $row['assistant_status'],
        'draft' => is_array($draft) ? $draft : null,
        'status' => (string) $row['status'],
        'errorCode' => $row['error_code'] === null ? null : (string) $row['error_code'],
        'error' => $row['error_detail'] === null ? null : (string) $row['error_detail'],
        'createdAt' => (string) $row['created_at'],
        'completedAt' => $row['completed_at'] === null ? null : (string) $row['completed_at'],
    ];
    return $payload;
}

function listAbilityAssistantMessages(PDO $connection, string $conversationId, bool $headOnly): never
{
    $identity = requireAbilityAssistantIdentity($connection);
    requireOwnedAbilityAssistantConversation($connection, $identity, $conversationId);
    $statement = $connection->prepare(
        'SELECT * FROM ability_assistant_messages WHERE conversation_id = :conversation_id '
        . 'ORDER BY created_at, id LIMIT 24'
    );
    $statement->execute([':conversation_id' => $conversationId]);
    sendJson(200, [
        'ok' => true,
        'messages' => array_map(static fn(array $row): array => abilityAssistantMessagePayload($row), $statement->fetchAll()),
    ], $headOnly);
}

function createAbilityAssistantMessage(PDO $connection, string $conversationId): never
{
    $identity = requireAbilityAssistantIdentity($connection);
    requireOwnedAbilityAssistantConversation($connection, $identity, $conversationId);
    $payload = readJsonBody(16384);
    $prompt = cleanAbilityAssistantText($payload['prompt'] ?? '', XAR_ABILITY_ASSISTANT_MAXIMUM_PROMPT_BYTES, 'Description');
    $clientRequestId = trim((string) ($payload['clientRequestId'] ?? ''));
    if (preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $clientRequestId) !== 1) {
        sendError(400, 'Référence de demande invalide.', 'invalid_client_request');
    }
    $accountId = (string) $identity['id'];
    // Le même verrou par compte est utilisé par Image Studio : une demande
    // texte et une génération d’image ne peuvent donc pas franchir ensemble
    // le contrôle de quota partagé.
    $lockName = 'xar-image-generation-' . substr(hash('sha256', $accountId), 0, 38);
    $lock = $connection->prepare('SELECT GET_LOCK(:lock_name, 12)');
    $lock->execute([':lock_name' => $lockName]);
    if ((int) $lock->fetchColumn() !== 1) sendError(503, 'La file d’assistance est occupée.', 'assistant_lock_unavailable');
    $duplicateMessage = null;
    $requestMismatch = false;
    $regieAccessLockHeld = false;
    $id = '';
    try {
        // Le verrou par compte sérialise aussi deux reprises concurrentes du
        // même clientRequestId. Une référence réutilisée pour une autre
        // conversation ou un autre texte est un conflit, jamais un nouvel envoi.
        $duplicate = $connection->prepare(
            'SELECT * FROM ability_assistant_messages WHERE author_account_id = :account_id '
            . 'AND client_request_id = :client_request_id LIMIT 1'
        );
        $duplicate->execute([':account_id' => $accountId, ':client_request_id' => $clientRequestId]);
        $existing = $duplicate->fetch();
        if (is_array($existing)) {
            $requestMismatch = (string) $existing['conversation_id'] !== $conversationId
                || !hash_equals((string) $existing['prompt'], $prompt);
            if (!$requestMismatch) $duplicateMessage = $existing;
        } else {
            $accessLock = $connection->query("SELECT GET_LOCK('xar-regie-codex-access', 12)");
            if ($accessLock === false || (int) $accessLock->fetchColumn() !== 1) {
                sendError(503, 'Le contrôle du Compte de la Régie est occupé.', 'regie_codex_lock_unavailable');
            }
            $regieAccessLockHeld = true;
            $service = imageStudioRegieServiceRecord($connection);
            if ((bool) $service['paused']) sendError(423, 'Le Compte de la Régie est actuellement en pause.', 'regie_codex_paused');
            $turns = $connection->prepare('SELECT COUNT(*) FROM ability_assistant_messages WHERE conversation_id = :conversation_id');
            $turns->execute([':conversation_id' => $conversationId]);
            if ((int) $turns->fetchColumn() >= XAR_ABILITY_ASSISTANT_MAXIMUM_TURNS) {
                sendError(429, 'Cette conversation a atteint douze demandes. Appliquez la proposition ou ouvrez une nouvelle assistance.', 'assistant_turn_limit');
            }
            $active = $connection->prepare(
                "SELECT (SELECT COUNT(*) FROM ability_assistant_messages WHERE author_account_id = :assistant_account_id "
                . "AND status IN ('queued', 'generating')) + (SELECT COUNT(*) FROM image_studio_messages "
                . "WHERE author_account_id = :image_account_id AND execution_mode = 'regie' AND status IN ('queued', 'generating'))"
            );
            $active->execute([':assistant_account_id' => $accountId, ':image_account_id' => $accountId]);
            if ((int) $active->fetchColumn() > 0) {
                sendError(409, 'Une demande utilise déjà le Compte de la Régie pour ce compte.', 'regie_codex_already_active');
            }
            $id = randomToken(18);
            $insert = $connection->prepare(
                'INSERT INTO ability_assistant_messages '
                . '(id, conversation_id, author_account_id, prompt, client_request_id) '
                . 'VALUES (:id, :conversation_id, :author_account_id, :prompt, :client_request_id)'
            );
            $insert->execute([
                ':id' => $id, ':conversation_id' => $conversationId, ':author_account_id' => $accountId,
                ':prompt' => $prompt, ':client_request_id' => $clientRequestId,
            ]);
            $touch = $connection->prepare('UPDATE ability_assistant_conversations SET updated_at = UTC_TIMESTAMP(3) WHERE id = :id');
            $touch->execute([':id' => $conversationId]);
        }
    } finally {
        if ($regieAccessLockHeld) {
            try {
                $connection->query("SELECT RELEASE_LOCK('xar-regie-codex-access')");
            } catch (Throwable) {
            }
        }
        try {
            $release = $connection->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute([':lock_name' => $lockName]);
        } catch (Throwable) {
        }
    }
    if ($requestMismatch) {
        sendError(409, 'Cette référence désigne une autre demande d’assistance.', 'assistant_request_mismatch');
    }
    if (is_array($duplicateMessage)) {
        sendJson(200, ['ok' => true, 'deduplicated' => true, 'message' => abilityAssistantMessagePayload($duplicateMessage)]);
    }
    sendJson(202, ['ok' => true, 'deduplicated' => false, 'message' => abilityAssistantMessagePayload(abilityAssistantMessageRecord($connection, $id))]);
}

function normalizeAbilityAssistantDraft(array $conversation, mixed $value): ?array
{
    if ($value === null) return null;
    if (!is_array($value)) throw new InvalidArgumentException('Proposition absente ou illisible.');
    $context = json_decode((string) $conversation['context_json'], true);
    $existing = is_array($context['existingAbility'] ?? null) ? $context['existingAbility'] : null;
    if (is_array($existing)) {
        $value = preserveApplicationAbilityRows([$value], [$existing])[0] ?? $value;
        $value['id'] = (string) $existing['id'];
        if (array_key_exists('image', $existing)) $value['image'] = $existing['image'];
        else unset($value['image']);
        // L'assistant textuel ne crée ni ne remplace un média. Le son validé
        // reste attaché à la compétence réparée, quel que soit son brouillon.
        $value['completionCue'] = normalizeApplicationAbilityCompletionCue($existing['completionCue'] ?? null);
    } else {
        $value['id'] = 'ability-' . randomToken(12);
        $value['completionCue'] = normalizeApplicationAbilityCompletionCue(null);
        unset($value['image']);
    }
    $effect = (string) ($value['effect'] ?? '');
    if (!in_array($effect, ['damage', 'healing', 'movement', 'summoning', 'metamorphosis', 'complex'], true)) {
        throw new InvalidArgumentException('Choisissez un effet de capacité pris en charge.');
    }
    $stats = ['', 'character-stat-force', 'character-stat-dexterity', 'character-stat-agility',
        'character-stat-spiritSocial', 'character-stat-intelligence', 'character-stat-instinct'];
    if (!in_array((string) ($value['castingStatId'] ?? ''), $stats, true)) {
        throw new InvalidArgumentException('La statistique de lancement n’est pas disponible.');
    }
    if (trim((string) ($value['name'] ?? '')) === '' || !validApplicationAbilityEffects($value)) {
        throw new InvalidArgumentException('La compétence ne respecte pas les coûts, formules ou champs de son effet.');
    }
    if ($effect === 'damage') {
        $parts = applicationDamageComponents($value['damageComponents'] ?? []);
        if ($parts === []) throw new InvalidArgumentException('Ajoutez au moins une formule de dégâts typés.');
        $value['formula'] = applicationCombinedDamageFormula($parts);
        $value['damageType'] = $parts[0]['type'];
    } else {
        $value['formula'] = '0';
    }
    if ($effect === 'summoning' && !in_array((string) ($value['summonLinkedTokenId'] ?? ''), array_column($context['linkedTokens'] ?? [], 'id'), true)) {
        throw new InvalidArgumentException('Créez et choisissez un pion mémorisé de cette fiche pour l’invocation.');
    }
    if ($effect === 'metamorphosis' && !in_array((string) ($value['formCharacterId'] ?? ''), array_column($context['availableForms'] ?? [], 'id'), true)) {
        throw new InvalidArgumentException('Créez et choisissez une seconde fiche du même propriétaire pour cette forme.');
    }
    $normalized = normalizeOnlineAbilities([$value])[0] ?? null;
    $workflowError = $effect === 'complex' && is_array($normalized)
        ? applicationComplexAbilityWorkflowError($normalized['workflow'] ?? null)
        : 'Compétence absente après normalisation.';
    if ($effect !== 'complex') $workflowError = '';
    if (!is_array($normalized) || ($normalized['effect'] ?? '') !== $effect || $workflowError !== '') {
        throw new InvalidArgumentException($workflowError !== ''
            ? $workflowError
            : 'La proposition ne respecte pas le contrat de compétence.');
    }
    return $normalized;
}

function recordAbilityAssistantGap(PDO $connection, array $values): array
{
    $code = strtolower(trim((string) ($values['code'] ?? 'missing_capability')));
    if (preg_match('/^[a-z0-9_]{3,64}$/D', $code) !== 1) $code = 'missing_capability';
    $summary = cleanAbilityAssistantText($values['summary'] ?? 'Fonction manquante signalée.', 2000, 'Résumé');
    $capability = cleanAbilityAssistantText($values['missingCapability'] ?? 'Fonction non prise en charge', 500, 'Fonction manquante');
    $conversationId = trim((string) ($values['conversationId'] ?? ''));
    $messageId = trim((string) ($values['messageId'] ?? ''));
    $accountId = (string) ($values['accountId'] ?? '');
    $characterId = (string) ($values['characterId'] ?? '');
    $abilityId = (string) ($values['abilityId'] ?? '');
    $origin = in_array($values['origin'] ?? '', ['assistant', 'user', 'validation'], true) ? $values['origin'] : 'assistant';
    $fingerprint = hash('sha256', implode('|', [$code, applicationComplexAbilityComparable($capability), $abilityId, $characterId]));
    $id = randomToken(18);
    $context = [
        'origin' => $origin,
        'assistantVersion' => XAR_BACKEND_VERSION,
        'workflowVersion' => XAR_COMPLEX_ABILITY_WORKFLOW_VERSION,
        'characterId' => $characterId,
        'abilityId' => $abilityId,
    ];
    $statement = $connection->prepare(
        'INSERT INTO ability_assistant_reports '
        . '(id, fingerprint, reporter_account_id, conversation_id, message_id, character_id, ability_id, origin, code, summary, missing_capability, context_json) '
        . 'VALUES (:id, :fingerprint, :reporter_account_id, :conversation_id, :message_id, :character_id, :ability_id, :origin, :code, :summary, :missing_capability, :context_json) '
        . 'ON DUPLICATE KEY UPDATE occurrences = occurrences + 1, last_seen_at = UTC_TIMESTAMP(3), '
        . "status = 'open', resolved_by_account_id = NULL, resolved_at = NULL, "
        . 'summary = VALUES(summary), missing_capability = VALUES(missing_capability)'
    );
    $statement->execute([
        ':id' => $id, ':fingerprint' => $fingerprint, ':reporter_account_id' => $accountId !== '' ? $accountId : null,
        ':conversation_id' => validAbilityAssistantConversationId($conversationId) ? $conversationId : null,
        ':message_id' => validAbilityAssistantMessageId($messageId) ? $messageId : null,
        ':character_id' => substr($characterId, 0, 180) ?: null, ':ability_id' => substr($abilityId, 0, 120) ?: null,
        ':origin' => $origin, ':code' => $code, ':summary' => $summary, ':missing_capability' => $capability,
        ':context_json' => json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ]);
    $select = $connection->prepare('SELECT id, status, occurrences FROM ability_assistant_reports WHERE fingerprint = :fingerprint LIMIT 1');
    $select->execute([':fingerprint' => $fingerprint]);
    $row = $select->fetch();
    return is_array($row) ? $row : ['id' => $id, 'status' => 'open', 'occurrences' => 1];
}

function createAbilityAssistantReport(PDO $connection): never
{
    $identity = requireAbilityAssistantIdentity($connection);
    $payload = readJsonBody(16384);
    $characterId = trim((string) ($payload['characterId'] ?? ''));
    $character = abilityAssistantCharacter($connection, $identity, $characterId);
    $abilityId = trim((string) ($payload['abilityId'] ?? ''));
    $ability = null;
    foreach (normalizeOnlineAbilities($character['abilities'] ?? []) as $candidate) {
        if ((string) ($candidate['id'] ?? '') === $abilityId) { $ability = $candidate; break; }
    }
    if ($abilityId !== '' && !is_array($ability)) sendError(404, 'Compétence introuvable.', 'ability_missing');
    $report = recordAbilityAssistantGap($connection, [
        'origin' => 'user', 'accountId' => (string) $identity['id'], 'characterId' => $characterId,
        'abilityId' => $abilityId, 'code' => 'ability_malfunction',
        'summary' => $payload['summary'] ?? '',
        'missingCapability' => $payload['missingCapability'] ?? 'Comportement de compétence à examiner',
    ]);
    sendJson(201, ['ok' => true, 'report' => ['id' => (string) $report['id'], 'status' => (string) $report['status'], 'occurrences' => (int) $report['occurrences']]]);
}

function listAbilityAssistantReports(PDO $connection, bool $headOnly): never
{
    $identity = requireRegieCodexOwner($connection);
    $status = in_array($_GET['status'] ?? '', ['open', 'resolved', 'ignored'], true) ? (string) $_GET['status'] : 'open';
    $statement = $connection->prepare(
        'SELECT r.id, r.origin, r.code, r.summary, r.missing_capability, r.character_id, r.ability_id, '
        . 'r.status, r.occurrences, r.created_at, r.last_seen_at, a.display_name '
        . 'FROM ability_assistant_reports r LEFT JOIN accounts a ON a.id = r.reporter_account_id '
        . 'WHERE r.status = :status ORDER BY r.last_seen_at DESC, r.id DESC LIMIT 200'
    );
    $statement->execute([':status' => $status]);
    $reports = array_map(static fn(array $row): array => [
        'id' => (string) $row['id'], 'origin' => (string) $row['origin'], 'code' => (string) $row['code'],
        'summary' => (string) $row['summary'], 'missingCapability' => (string) $row['missing_capability'],
        'characterId' => $row['character_id'] === null ? null : (string) $row['character_id'],
        'abilityId' => $row['ability_id'] === null ? null : (string) $row['ability_id'],
        'status' => (string) $row['status'], 'occurrences' => (int) $row['occurrences'],
        'reporter' => $row['display_name'] === null ? null : (string) $row['display_name'],
        'createdAt' => (string) $row['created_at'], 'lastSeenAt' => (string) $row['last_seen_at'],
    ], $statement->fetchAll());
    sendJson(200, ['ok' => true, 'reports' => $reports], $headOnly);
}

function updateAbilityAssistantReport(PDO $connection, string $id): never
{
    $identity = requireRegieCodexOwner($connection);
    $payload = readJsonBody(8192);
    $status = (string) ($payload['status'] ?? '');
    if (!in_array($status, ['open', 'resolved', 'ignored'], true)) sendError(400, 'Statut de rapport invalide.', 'invalid_report_status');
    $statement = $connection->prepare(
        'UPDATE ability_assistant_reports SET status = :status, resolved_by_account_id = :account_id, '
        . "resolved_at = IF(:resolved_status = 'open', NULL, UTC_TIMESTAMP(3)) WHERE id = :id"
    );
    $statement->execute([
        ':status' => $status, ':resolved_status' => $status,
        ':account_id' => $status === 'open' ? null : (string) $identity['id'], ':id' => $id,
    ]);
    if ($statement->rowCount() !== 1) {
        $exists = $connection->prepare('SELECT 1 FROM ability_assistant_reports WHERE id = :id LIMIT 1');
        $exists->execute([':id' => $id]);
        if ($exists->fetchColumn() === false) sendError(404, 'Rapport introuvable.', 'report_missing');
    }
    sendJson(200, ['ok' => true, 'id' => $id, 'status' => $status]);
}

function recoverExpiredAbilityAssistantJobs(PDO $connection): void
{
    $fail = $connection->prepare(
        "UPDATE ability_assistant_messages SET status = 'failed', error_code = 'worker_interrupted', "
        . "error_detail = 'Le worker de la Régie a été interrompu à plusieurs reprises.', worker_account_id = NULL, "
        . 'worker_lease_id = NULL, worker_lease_expires_at = NULL, completed_at = UTC_TIMESTAMP(3) '
        . "WHERE status = 'generating' AND worker_lease_expires_at < UTC_TIMESTAMP(3) AND worker_attempts >= :maximum_attempts"
    );
    $fail->execute([':maximum_attempts' => XAR_IMAGE_STUDIO_WORKER_MAX_ATTEMPTS]);
    $retry = $connection->prepare(
        "UPDATE ability_assistant_messages SET status = 'queued', worker_account_id = NULL, worker_lease_id = NULL, "
        . 'worker_lease_expires_at = NULL, started_at = NULL, error_code = NULL, error_detail = NULL '
        . "WHERE status = 'generating' AND worker_lease_expires_at < UTC_TIMESTAMP(3) AND worker_attempts < :maximum_attempts"
    );
    $retry->execute([':maximum_attempts' => XAR_IMAGE_STUDIO_WORKER_MAX_ATTEMPTS]);
}

function recoverReplacedAbilityAssistantJobs(PDO $connection, string $workerLeaseId): void
{
    $fail = $connection->prepare(
        "UPDATE ability_assistant_messages SET status = 'failed', error_code = 'worker_interrupted', "
        . "error_detail = 'Le worker de la Régie a été interrompu à plusieurs reprises.', worker_account_id = NULL, "
        . 'worker_lease_id = NULL, worker_lease_expires_at = NULL, completed_at = UTC_TIMESTAMP(3) '
        . "WHERE status = 'generating' AND (worker_lease_id IS NULL OR worker_lease_id <> :worker_lease_id) "
        . 'AND worker_attempts >= :maximum_attempts'
    );
    $fail->execute([':worker_lease_id' => $workerLeaseId, ':maximum_attempts' => XAR_IMAGE_STUDIO_WORKER_MAX_ATTEMPTS]);
    $retry = $connection->prepare(
        "UPDATE ability_assistant_messages SET status = 'queued', worker_account_id = NULL, worker_lease_id = NULL, "
        . 'worker_lease_expires_at = NULL, started_at = NULL, error_code = NULL, error_detail = NULL '
        . "WHERE status = 'generating' AND (worker_lease_id IS NULL OR worker_lease_id <> :worker_lease_id) "
        . 'AND worker_attempts < :maximum_attempts'
    );
    $retry->execute([':worker_lease_id' => $workerLeaseId, ':maximum_attempts' => XAR_IMAGE_STUDIO_WORKER_MAX_ATTEMPTS]);
}

function abilityAssistantWorkerJobPayload(PDO $connection, array $message): array
{
    $conversation = abilityAssistantConversationRecord($connection, (string) $message['conversation_id']);
    if (!is_array($conversation)) throw new RuntimeException('ability_assistant_conversation_missing');
    $historyStatement = $connection->prepare(
        "SELECT prompt, response_text, assistant_status, draft_json FROM ability_assistant_messages "
        . "WHERE conversation_id = :conversation_id AND status = 'succeeded' AND id <> :id "
        . 'ORDER BY created_at, id LIMIT 24'
    );
    $historyStatement->execute([':conversation_id' => (string) $message['conversation_id'], ':id' => (string) $message['id']]);
    $history = [];
    foreach ($historyStatement->fetchAll() as $entry) {
        $history[] = ['role' => 'user', 'text' => (string) $entry['prompt']];
        $draft = $entry['draft_json'] === null ? null : json_decode((string) $entry['draft_json'], true);
        $history[] = [
            'role' => 'assistant', 'text' => (string) $entry['response_text'],
            'status' => (string) $entry['assistant_status'],
            ...(is_array($draft) ? ['draft' => $draft] : []),
        ];
    }
    $context = json_decode((string) $conversation['context_json'], true);
    return [
        'jobType' => 'ability-assistant', 'id' => (string) $message['id'],
        'conversationId' => (string) $message['conversation_id'], 'prompt' => (string) $message['prompt'],
        'context' => is_array($context) ? $context : [], 'history' => $history,
    ];
}

function completeAbilityAssistantRegieJob(PDO $connection, string $id): never
{
    $identity = requireRegieCodexOwner($connection);
    $payload = readJsonBody(131072);
    $workerLeaseId = requiredImageStudioWorkerLeaseId($payload);
    $assistantStatus = in_array($payload['status'] ?? '', ['answer', 'question', 'proposal', 'blocked', 'refused'], true)
        ? (string) $payload['status'] : '';
    if ($assistantStatus === '') sendError(400, 'Statut de réponse IA invalide.', 'invalid_assistant_result');
    $response = cleanAbilityAssistantText($payload['message'] ?? '', XAR_ABILITY_ASSISTANT_MAXIMUM_RESPONSE_BYTES, 'Réponse IA');
    $connection->beginTransaction();
    try {
        $service = imageStudioRegieServiceRecord($connection, true);
        if (!hash_equals($workerLeaseId, (string) ($service['worker_lease_id'] ?? ''))) {
            $connection->rollBack();
            sendError(409, 'Ce worker a été remplacé par un autre poste.', 'worker_lease_replaced');
        }
        $select = $connection->prepare('SELECT * FROM ability_assistant_messages WHERE id = :id LIMIT 1 FOR UPDATE');
        $select->execute([':id' => $id]);
        $message = $select->fetch();
        if (!is_array($message)) sendError(404, 'Travail d’assistance introuvable.', 'message_missing');
        if ((string) $message['status'] === 'succeeded') {
            $connection->commit();
            sendJson(200, ['ok' => true, 'message' => abilityAssistantMessagePayload($message)]);
        }
        if ((string) $message['status'] !== 'generating'
            || (string) ($message['worker_account_id'] ?? '') !== (string) $identity['id']
            || !hash_equals($workerLeaseId, (string) ($message['worker_lease_id'] ?? ''))) {
            sendError(409, 'Ce travail n’est pas attribué à ce worker.', 'worker_job_not_claimed');
        }
        $conversation = abilityAssistantConversationRecord($connection, (string) $message['conversation_id']);
        if (!is_array($conversation)) sendError(404, 'Conversation d’assistance introuvable.', 'conversation_missing');
        $draft = null;
        $validationGap = null;
        if ($assistantStatus === 'proposal') {
            $proposalContext = json_decode((string) $conversation['context_json'], true);
            if ((string) $conversation['character_id'] === '' || !is_array($proposalContext['character'] ?? null)) {
                $assistantStatus = 'answer';
                $response = 'Pour créer une compétence applicable, ouvrez « Fiches de personnages » (joueur) ou « Personnages » (MJ), sélectionnez une fiche, puis utilisez l’assistant dans « Capacités lançables ». Je peux vous aider à préparer les réglages ici.';
            }
        }
        if ($assistantStatus === 'proposal') {
            try {
                $draft = normalizeAbilityAssistantDraft($conversation, $payload['ability'] ?? null);
                if (!is_array($draft)) throw new InvalidArgumentException('La proposition de compétence est absente.');
            } catch (InvalidArgumentException $validationError) {
                $assistantStatus = 'blocked';
                $response = 'J’ai compris la compétence, mais la proposition obtenue ne peut pas encore être appliquée sans risque. '
                    . 'Un rapport technique limité aux informations utiles a été transmis automatiquement à la Régie.';
                $validationGap = [
                    'code' => 'assistant_draft_validation',
                    'summary' => 'Une proposition comprise par l’assistant a été refusée par le contrat de compétence.',
                    'missingCapability' => substr($validationError->getMessage(), 0, 500),
                ];
            }
        }
        $complete = $connection->prepare(
            "UPDATE ability_assistant_messages SET status = 'succeeded', response_text = :response_text, "
            . 'assistant_status = :assistant_status, draft_json = :draft_json, error_code = NULL, error_detail = NULL, '
            . 'worker_account_id = NULL, worker_lease_id = NULL, worker_lease_expires_at = NULL, completed_at = UTC_TIMESTAMP(3) '
            . "WHERE id = :id AND status = 'generating' AND worker_lease_id = :worker_lease_id"
        );
        $complete->execute([
            ':response_text' => $response, ':assistant_status' => $assistantStatus,
            ':draft_json' => is_array($draft) ? json_encode($draft, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
            ':id' => $id, ':worker_lease_id' => $workerLeaseId,
        ]);
        $reportedGap = is_array($validationGap)
            ? $validationGap
            : ($assistantStatus === 'blocked' && is_array($payload['gap'] ?? null) ? $payload['gap'] : null);
        if (is_array($reportedGap)) {
            $context = json_decode((string) $conversation['context_json'], true);
            recordAbilityAssistantGap($connection, [
                ...$reportedGap, 'origin' => is_array($validationGap) ? 'validation' : 'assistant',
                'accountId' => (string) $message['author_account_id'],
                'conversationId' => (string) $message['conversation_id'], 'messageId' => $id,
                'characterId' => (string) $conversation['character_id'],
                'abilityId' => (string) ($context['existingAbility']['id'] ?? ''),
            ]);
        }
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $error;
    }
    sendJson(200, ['ok' => true, 'message' => abilityAssistantMessagePayload(abilityAssistantMessageRecord($connection, $id))]);
}

function failAbilityAssistantRegieJob(PDO $connection, string $id): never
{
    $identity = requireRegieCodexOwner($connection);
    $payload = readJsonBody(8192);
    $workerLeaseId = requiredImageStudioWorkerLeaseId($payload);
    $code = strtolower(trim((string) ($payload['code'] ?? 'assistant_failed')));
    if (preg_match('/^[a-z0-9_]{3,64}$/D', $code) !== 1) $code = 'assistant_failed';
    $detail = cleanAbilityAssistantText($payload['message'] ?? 'L’assistant n’a pas abouti.', 500, 'Erreur');
    $status = $code === 'request_rejected' ? 'rejected' : 'failed';
    $connection->beginTransaction();
    try {
        $service = imageStudioRegieServiceRecord($connection, true);
        if (!hash_equals($workerLeaseId, (string) ($service['worker_lease_id'] ?? ''))) {
            $connection->rollBack();
            sendError(409, 'Ce worker a été remplacé par un autre poste.', 'worker_lease_replaced');
        }
        $select = $connection->prepare('SELECT * FROM ability_assistant_messages WHERE id = :id LIMIT 1 FOR UPDATE');
        $select->execute([':id' => $id]);
        $message = $select->fetch();
        if (!is_array($message)) sendError(404, 'Travail d’assistance introuvable.', 'message_missing');
        if (in_array((string) $message['status'], ['failed', 'rejected'], true)) {
            $connection->commit();
            sendJson(200, ['ok' => true, 'message' => abilityAssistantMessagePayload($message)]);
        }
        if ((string) $message['status'] !== 'generating'
            || (string) ($message['worker_account_id'] ?? '') !== (string) $identity['id']
            || !hash_equals($workerLeaseId, (string) ($message['worker_lease_id'] ?? ''))) {
            sendError(409, 'Ce travail n’est pas attribué à ce worker.', 'worker_job_not_claimed');
        }
        $statement = $connection->prepare(
            'UPDATE ability_assistant_messages SET status = :status, error_code = :error_code, error_detail = :error_detail, '
            . 'worker_account_id = NULL, worker_lease_id = NULL, worker_lease_expires_at = NULL, completed_at = UTC_TIMESTAMP(3) '
            . "WHERE id = :id AND status = 'generating' AND worker_account_id = :account_id AND worker_lease_id = :worker_lease_id"
        );
        $statement->execute([
            ':status' => $status, ':error_code' => $code, ':error_detail' => $detail, ':id' => $id,
            ':account_id' => (string) $identity['id'], ':worker_lease_id' => $workerLeaseId,
        ]);
        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $error;
    }
    sendJson(200, ['ok' => true, 'message' => abilityAssistantMessagePayload(abilityAssistantMessageRecord($connection, $id))]);
}

function handleAbilityAssistantRoute(PDO $connection, string $route, string $method, bool $headOnly): bool
{
    if (!str_starts_with($route, '/api/v1/ability-assistant')) return false;
    if ($route === '/api/v1/ability-assistant/conversations') {
        requireMethod($method, ['POST']);
        createAbilityAssistantConversation($connection);
    }
    if ($route === '/api/v1/ability-assistant/reports') {
        requireMethod($method, ['GET', 'HEAD', 'POST']);
        if ($method === 'POST') createAbilityAssistantReport($connection);
        listAbilityAssistantReports($connection, $headOnly);
    }
    if (preg_match('#^/api/v1/ability-assistant/reports/([A-Za-z0-9_-]{24})$#D', $route, $match) === 1) {
        requireMethod($method, ['PATCH']);
        updateAbilityAssistantReport($connection, $match[1]);
    }
    if (preg_match('#^/api/v1/ability-assistant/conversations/([A-Za-z0-9_-]{22})/messages$#D', $route, $match) === 1) {
        requireMethod($method, ['GET', 'HEAD', 'POST']);
        if ($method === 'POST') createAbilityAssistantMessage($connection, $match[1]);
        listAbilityAssistantMessages($connection, $match[1], $headOnly);
    }
    if (preg_match('#^/api/v1/ability-assistant/regie/jobs/([A-Za-z0-9_-]{24})/complete$#D', $route, $match) === 1) {
        requireMethod($method, ['POST']);
        completeAbilityAssistantRegieJob($connection, $match[1]);
    }
    if (preg_match('#^/api/v1/ability-assistant/regie/jobs/([A-Za-z0-9_-]{24})/fail$#D', $route, $match) === 1) {
        requireMethod($method, ['POST']);
        failAbilityAssistantRegieJob($connection, $match[1]);
    }
    sendError(404, 'Route de l’assistant de compétence inconnue.', 'route_missing');
}
