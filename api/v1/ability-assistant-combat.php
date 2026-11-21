<?php

declare(strict_types=1);

function abilityAssistantCombatPhrase(string $text): string
{
    return strtr(strtolower(trim($text)), [
        'é'=>'e', 'è'=>'e', 'ê'=>'e', 'ë'=>'e', 'à'=>'a', 'â'=>'a', 'î'=>'i', 'ï'=>'i', 'ô'=>'o', 'û'=>'u', 'ù'=>'u', 'ç'=>'c',
        'É'=>'e', 'È'=>'e', 'Ê'=>'e', 'Ë'=>'e', 'À'=>'a', 'Â'=>'a', 'Î'=>'i', 'Ï'=>'i', 'Ô'=>'o', 'Û'=>'u', 'Ù'=>'u', 'Ç'=>'c', '’'=>"'",
    ]);
}

function abilityAssistantCombatCommand(string $prompt): ?array
{
    $text = abilityAssistantCombatPhrase($prompt);
    if (str_contains($text, "\n")) return null;
    if (preg_match("~^(?:peux-tu\s+)?(diagnostiqu(?:e|er)|inspect(?:e|er)|verifi(?:e|er)|nettoi(?:e|yer)|debloqu(?:e|er)|repar(?:e|er)|retir(?:e|er)|vir(?:e|er))\s+((?:les\s+)?(?:validations?(?:\s+d'attaques?)?(?:\s+bloquees?)?|attaques?\s+bloquees?))(?:\s+(?:de|dans|sur)\s+(cette scene|la scene(?:\s+.+?)?))?\s*[.!?]?$~uD", $text, $match) !== 1) {
        return null;
    }
    $where = trim($match[3] ?? '');
    $name = preg_replace('~^[\s"«»“”]+|[\s"«»“”]+$~u', '', substr($where, strlen('la scene'))) ?? '';
    $namedScene = !in_array($where, ['', 'cette scene', 'la scene', 'la scene active', 'la scene courante'], true);
    $remove = in_array($match[1], ['retire', 'retirer', 'vire', 'virer'], true);
    if ($remove && (!$namedScene || $name === '' || preg_match("~^les\s+validations\s+d'attaques?\s+bloquees$~D", $match[2]) !== 1)) return null;
    $repair = $remove || in_array($match[1], ['nettoie', 'nettoyer', 'debloque', 'debloquer', 'repare', 'reparer'], true);
    return ['repair'=>$repair, 'sceneName'=>$namedScene ? $name : ''];
}

function abilityAssistantCanOperateCombat(array $identity, array $conversation): bool
{
    $context = json_decode((string) ($conversation['context_json'] ?? '{}'), true);
    return ($identity['effective_mode'] ?? '') === 'gm' && ($identity['permanent_role'] ?? '') === 'gm'
        && ($context['mode'] ?? '') === 'help' && (string) ($conversation['character_id'] ?? '') === '';
}

function abilityAssistantCombatScene(PDO $connection, array $identity, string $sceneName): ?array
{
    if ($sceneName === '') {
        $records = applicationDomainRecords($connection, ['table']);
        $table = applicationDomainPayload($records, 'table');
        $sceneId = $table['gmContexts'][$identity['id']]['workingSceneId'] ?? null;
        if (!is_string($sceneId) || !validApplicationDomainKey('scene:' . $sceneId)) return null;
        $key = 'scene:' . $sceneId;
        $candidates = applicationDomainRecords($connection, [$key]);
    } else {
        $candidates = applicationDomainRecordsByPrefix($connection, 'scene:');
    }
    $matches = [];
    foreach ($candidates as $key => $record) {
        $scene = applicationDomainPayload([$key=>$record], $key);
        $metadata = $scene['metadata'] ?? [];
        if (($metadata['id'] ?? null) !== substr($key, 6) || !is_string($metadata['name'] ?? null)) continue;
        if ($sceneName === '' || abilityAssistantCombatPhrase($metadata['name']) === $sceneName) $matches[] = $scene;
    }
    return count($matches) === 1 ? $matches[0] : null;
}

function abilityAssistantInspectCombat(array $activity, string $sceneId, array $present, int $now): array
{
    $scope = array_values(array_filter($activity['pendingAttacks'] ?? [], static fn($attack): bool =>
        is_array($attack) && ($attack['sceneId'] ?? null) === $sceneId));
    // The shared cleanup functions also serve ordinary writes. Give the
    // assistant only this scene's queue while preserving the full receipt set.
    $finalized = applicationWithoutFinalizedPendingAttacks([...$activity, 'pendingAttacks'=>$scope]);
    $missing = [];
    foreach ($finalized['pendingAttacks'] as $attack) {
        if (!in_array($attack['status'] ?? null, ['pending', 'awaiting-opposition'], true)) continue;
        $targets = is_array($attack['areaGate']['targetTokenIds'] ?? null) ? $attack['areaGate']['targetTokenIds'] : [];
        foreach ([$attack['sourceTokenId'] ?? null, $attack['targetTokenId'] ?? null, ...$targets] as $id) {
            // Device area validations use the device ID as a synthetic token.
            if ($id !== ($attack['deviceOrigin'] ?? null) && validApplicationDomainIdentifier($id, 120) && !isset($present[$id])) {
                $missing[$sceneId][$id] = true;
            }
        }
    }
    $clean = applicationCancelPendingAttacksForRemovedTokens($finalized, $missing, $now);
    $keepers = array_fill_keys(array_column($clean['pendingAttacks'], 'id'), true);
    $activity['pendingAttacks'] = array_values(array_filter($activity['pendingAttacks'] ?? [],
        static fn($attack): bool => ($attack['sceneId'] ?? null) !== $sceneId || isset($keepers[$attack['id'] ?? ''])));
    $activity['attackReceipts'] = $clean['attackReceipts'] ?? $activity['attackReceipts'] ?? [];
    return [
        'activity'=>$activity,
        'finished'=>count($scope)-count($finalized['pendingAttacks']),
        'orphaned'=>count($finalized['pendingAttacks'])-count($clean['pendingAttacks']),
        'remaining'=>count($clean['pendingAttacks']),
    ];
}

function completeAbilityAssistantCombatCommand(PDO $connection, array $identity, string $conversationId, string $prompt, string $requestId, array $command): string
{
    $connection->beginTransaction();
    try {
        $clock = domainClockRecord($connection, $command['repair']);
        $scene = $command['repair'] && $command['sceneName'] === '' ? null : abilityAssistantCombatScene($connection, $identity, $command['sceneName']);
        $response = 'Précisez le nom exact d’une scène unique. Par exemple, « Diagnostique les validations d’attaque de la scène Démo technique » ou « Débloque les validations d’attaque de la scène Démo technique ».';
        if ($scene !== null) {
            $sceneId = $scene['metadata']['id'];
            $tokens = applicationDomainRecordsByPrefix($connection, 'token:' . $sceneId . ':');
            $present = [];
            foreach ($tokens as $key => $token) $present[substr($key, strlen('token:' . $sceneId . ':'))] = true;
            foreach ($scene['combat']['map']['tokens'] ?? [] as $token) {
                if (is_string($token['id'] ?? null)) $present[$token['id']] = true;
            }
            $records = applicationDomainRecords($connection, ['activity']);
            $activity = $records['activity']['payload'] ?? [];
            $result = abilityAssistantInspectCombat($activity, $sceneId, $present, (int) floor(microtime(true) * 1000));
            $removed = $result['finished'] + $result['orphaned'];
            $name = $scene['metadata']['name'];
            $counts = sprintf('%d validation(s) déjà terminée(s), %d orpheline(s) et %d encore en attente.', $result['finished'], $result['orphaned'], $result['remaining']);
            $response = sprintf('Scène « %s » : %s Aucune modification.', $name, $counts);
            if ($command['repair'] && $removed > 0) {
                $pending = [];
                queueOnlineDomainUpsert($pending, $records, 'activity', $result['activity']);
                if (!isset($pending['activity'])) throw new RuntimeException('La correction des validations n’a pas pu être préparée.', 409);
                persistDomainChangesInTransaction($connection, $identity, $clock, array_values($pending));
                $response = sprintf('Scène « %s » : %d fiche(s) retirée(s), dont %d déjà terminée(s) et %d orpheline(s). %d fiche(s) encore en attente. Aucun dégât appliqué, aucun pion ni jet du journal modifié.', $name, $removed, $result['finished'], $result['orphaned'], $result['remaining']);
            }
        }
        $id = randomToken(18);
        $insert = $connection->prepare(
            "INSERT INTO ability_assistant_messages (id, conversation_id, author_account_id, prompt, client_request_id, status, response_text, assistant_status, completed_at) "
            . "VALUES (:id, :conversation_id, :account_id, :prompt, :request_id, 'succeeded', :response, 'answer', UTC_TIMESTAMP(3))"
        );
        $insert->execute([':id'=>$id, ':conversation_id'=>$conversationId, ':account_id'=>$identity['id'], ':prompt'=>$prompt, ':request_id'=>$requestId, ':response'=>$response]);
        touchAbilityAssistantConversation($connection, $conversationId);
        $connection->commit();
        return $id;
    } catch (Throwable $error) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $error;
    }
}

function abilityAssistantHelpGuidance(string $role): array
{
    $guidance = [
        'language'=>'Réponse courte en français. Donne d’abord l’étape praticable ou la prochaine vérification.',
        'incident'=>'Identifier l’action, la scène et le niveau concernés, le tour, les unités visibles et le message exact. Comparer avant/après. Pour un résultat incomplet, dire les points encore à vérifier.',
        'gameplay'=>'Distinguer jet de lancement, défense et dégâts réellement appliqués. Vérifier selon le cas les coûts et recharges, les conditions, les règles de portée et terrain, l’initiative ou la validation du MJ. Une règle de jeu vérifiée et une décision du MJ sont deux choses différentes.',
        'privacy'=>'Utiliser uniquement les données déjà autorisées dans le contexte. L’aide générale ne voit ni la carte en direct, ni les ressources ou conditions actuelles, ni les jets privés, ni les journaux techniques. Une donnée absente doit être demandée ou indiquée comme inconnue.',
    ];
    if ($role === 'gm') {
        $guidance['combatMaintenance'] = [
            'diagnosis'=>'Diagnostique les validations d’attaque de la scène Démo technique',
            'repair'=>'Débloque les validations d’attaque de la scène Démo technique',
            'scope'=>'Ces messages exacts sont pris en charge dans l’Assistant Régie du MJ, avec le vrai nom de la scène. Le diagnostic distingue les validations terminées, orphelines et encore en attente. La correction retire uniquement les deux premières catégories. Ne déclarer une intervention réussie que d’après son reçu. Le modèle ne dispose d’aucune autre commande d’intervention, ni d’un accès à la base, aux logs ou aux secrets.',
        ];
    } else {
        $guidance['player']='Indiquer les boutons réellement accessibles au Joueur. Pour un jet en attente ou un arbitrage, signaler clairement ce que le joueur peut faire et à quel moment le MJ intervient. Ne recommander aucune manipulation administrative ou opération réservée au MJ.';
    }
    return $guidance;
}
