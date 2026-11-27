<?php
declare(strict_types=1);
require_once __DIR__.'/effect-formula.php';

const XAR_PENDING_DOT_RESOLUTION_MAXIMUM = 500;

function onlineDotTargetIdentity(array $token): string {
    return !empty($token['characterId']) && ($token['followCharacter'] ?? true) !== false && empty($token['linkedTokenId'])
        ? 'character:' . $token['characterId'] : 'token:' . ($token['id'] ?? '');
}

function onlineRemainingDotFormula(string $formula, int $turns): ?string {
    $formula=normalizeEffectFormula($formula);
    if(preg_match('/[()*]/',$formula)){if($turns<1||$turns>20||!validEffectFormula($formula))return null;$sum=sumEffectFormulas(array_fill(0,$turns,$formula));return validEffectFormula($sum)?$sum:null;}
    if ($turns < 1 || $turns > 20 || preg_match('/^(?:\d*d\d+|\d+)(?:[+-](?:\d*d\d+|\d+))*$/D', $formula) !== 1) return null;
    preg_match_all('/[+-]?(?:\d*d\d+|\d+)/', $formula, $terms);
    return implode('', array_map(static function (string $term) use ($turns): string {
        $sign = in_array($term[0], ['+', '-'], true) ? $term[0] : '';
        $value = ltrim($term, '+-');
        if (preg_match('/^(\d*)d(\d+)$/D', $value, $dice) === 1)
            return $sign . ((int) ($dice[1] === '' ? '1' : $dice[1]) * $turns) . 'd' . $dice[2];
        return $sign . ((int) $value * $turns);
    }, $terms[0]));
}

function onlineOngoingEffectTarget(PDO $connection, array &$records, array $pending, string $sceneId, string $tokenId): array {
    $key = onlineTokenDomainKey($sceneId, $tokenId);
    $records = array_replace($records, applicationDomainRecords($connection, [$key]));
    $target = $pending[$key]['payload'] ?? applicationDomainPayload($records, $key);
    $owner = applicationAbilityCastingOwner($target);
    if (($owner['characterId'] ?? '') !== '') {
        $characterKey = 'character:' . $owner['characterId'];
        $records = array_replace($records, applicationDomainRecords($connection, [$characterKey]));
        $character = $pending[$characterKey]['payload'] ?? applicationDomainPayload($records, $characterKey);
        if ($character !== []) $target = synchronizeOnlineCharacterToken($target, $character);
    }
    return $target;
}

function onlineCreateManualHpEffect(PDO $connection, array &$records, array &$pending, array $identity, array $arguments): array {
    if (($identity['effective_mode'] ?? '') !== 'gm' || ($identity['permanent_role'] ?? '') !== 'gm')
        rejectOnlineCommand($connection, 403, 'Cette action est réservée au MJ.', 'gm_required');
    $label = is_string($arguments['label'] ?? null) ? trim($arguments['label']) : '';
    $formula = normalizeEffectFormula($arguments['formula'] ?? '');
    $turns = $arguments['turns'] ?? null;
    $direction = $arguments['direction'] ?? null;
    $applyNow = ($arguments['startNextTurn'] ?? true) === false;
    $requestId = (string) ($arguments['requestId'] ?? '');
    if (preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $requestId) !== 1 || preg_match('/^.{1,120}$/usD', $label) !== 1
        || !is_int($turns) || $turns < 1 || $turns > 20 || !in_array($direction, [-1, 1], true) || !validEffectFormula($formula)
        || (array_key_exists('startNextTurn', $arguments) && !is_bool($arguments['startNextTurn'])))
        rejectOnlineCommand($connection, 400, 'Renseignez un nom, une durée de 1 à 20 tours et une formule de dés valide.', 'periodic_hp_invalid');
    $sceneId = (string) ($arguments['sceneId'] ?? '');
    $tokenId = (string) ($arguments['tokenId'] ?? '');
    $layerId = (string) ($arguments['layerId'] ?? '');
    $kind = 'manual-hp-effect';
    $signature = hash('sha256', json_encode([$sceneId, $tokenId, $layerId, $label, $formula, $turns, $direction, ...($applyNow ? [false] : [])], JSON_THROW_ON_ERROR));
    $context = onlinePersistentCommandReceipt($connection, $records, $kind, $requestId, $identity['id'], $signature, 'periodic_hp');
    if ($context['receipt'] !== null) return [...$context['receipt']['result'], 'deduplicated' => true];
    $target = onlineOngoingEffectTarget($connection, $records, $pending, $sceneId, $tokenId);
    if ($target === [] || onlineTokenLayerId($target) !== $layerId)
        rejectOnlineCommand($connection, 404, 'Ce pion a quitté la scène ou le niveau.', 'periodic_hp_target_missing');
    if (($target['maxHp'] ?? 0) <= 0 || onlineTokenIsDead($target, onlineTokenControllerIdFromRecords($connection, $records, $target) !== ''))
        rejectOnlineCommand($connection, 409, 'Ce pion doit être vivant et disposer de PV.', 'periodic_hp_target_unavailable');
    $activity = $context['activity'];
    if ($direction < 0 && applicationHasAllHarmImmunity($target, $activity, $sceneId)) {
        rejectOnlineCommand($connection, 409, 'Cette cible est protégée par la Potion d’invincibilité.', 'target_invulnerable');
    }
    $clockKey = 'initiative:' . $sceneId;
    $records = array_replace($records, applicationDomainRecords($connection, [$clockKey, 'devices']));
    $clock = $pending[$clockKey]['payload'] ?? applicationDomainPayload($records, $clockKey);
    $devices = $pending['devices']['payload'] ?? applicationDomainPayload($records, 'devices');
    $targetIndex = array_search($tokenId, $clock['order'] ?? [], true);
    $effect = ['id' => 'manual-hp-' . $requestId, 'sceneId' => $sceneId, 'targetTokenId' => $tokenId,
        'targetIdentity' => onlineDotTargetIdentity($target), 'manualHp' => true, 'family' => 'manual-hp',
        'direction' => $direction, 'label' => $label, 'formula' => $formula, 'remainingTurns' => $turns,
        'combatId' => (string) ($clock['combatId'] ?? ''), 'lastTurnSerial' => (int) ($clock['turnSerial'] ?? 0),
        'lastManualTurn' => (int) ($devices['manualTurns'][$sceneId] ?? 0),
        ...(!empty($clock['active']) && $targetIndex !== false && $targetIndex <= ($clock['currentIndex'] ?? 0)
            ? ['lastTargetRound' => (int) ($clock['round'] ?? 1)] : [])];
    $firstTick = $applyNow ? onlineTickManualHpEffect($connection, $records, $pending, $identity, $effect, $target) : null;
    if ($applyNow && $firstTick === null)
        rejectOnlineCommand($connection, 409, 'Ce pion ne peut plus recevoir cet effet.', 'periodic_hp_target_unavailable');
    if ($firstTick !== null) {
        $activity = $pending['activity']['payload'] ?? $activity;
        $activity['rolls'] = array_slice([$firstTick['roll'], ...($activity['rolls'] ?? [])], 0, 100);
        $effect['remainingTurns']--;
    }
    if ($effect['remainingTurns'] > 0) $activity['damageOverTime'][] = $effect;
    queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
    $action = onlineAppendPlayerAction($connection, $records, $pending, $identity, $sceneId, [
        'kind' => 'effect', 'characterName' => $target['name'] ?? 'Pion',
        'summary' => 'Applique ' . $label . ' · ' . ($direction > 0 ? 'soins' : 'perte de PV'),
        'detail' => $formula . ' par tour · ' . $effect['remainingTurns'] . ' tour(s) restant(s)',
    ]);
    $result = ['effect' => $effect, ...($firstTick ?? [])];
    onlineStorePersistentCommandReceipt($records, $pending, $context, $kind, $requestId, $identity['id'], $action['id'], $tokenId, $signature, $result);
    return [...$result, 'deduplicated' => false];
}

function onlineTickManualHpEffect(PDO $connection, array &$records, array &$pending, array $identity, array $dot, array $target): ?array {
    if (($target['maxHp'] ?? 0) <= 0 || onlineDotTargetIdentity($target) !== ($dot['targetIdentity'] ?? '')
        || !in_array($dot['direction'] ?? null, [-1, 1], true)) return null;
    $rolled = onlineRollFormulaWithMode($dot['formula'], 'normal');
    $amount = max(0, min(1000000000, (int) $rolled['total']));
    $sceneId = $dot['sceneId'];
    $delta = $amount > 0 ? applyOnlineTokenResourceAdjustment(
        $connection, $records, $pending, $sceneId, $target['id'], 'hp', $amount * $dot['direction'],
        (string) ($identity['id'] ?? ''), true, null, true, $dot['direction'] < 0
    )['appliedDelta'] : 0;
    $label = $dot['label'] . ' · ' . abs($delta) . ' PV ' . ($dot['direction'] > 0 ? 'rendus' : 'retirés');
    $roll = onlineRollEntry($identity, $rolled, $label, (string) $target['name']);
    $roll['rollerRole'] = 'gm';
    if (!empty($target['hidden'])) { $roll['visibility'] = 'gm'; $roll['revealed'] = false; }
    $roll['mapEvent'] = ['kind' => 'roll', 'label' => $dot['label'], 'value' => $rolled['total'], 'tokenId' => $target['id'],
        'targetTokenId' => $target['id'], 'targetName' => $target['name'], 'sceneId' => $sceneId, 'layerId' => onlineTokenLayerId($target)];
    return ['roll' => $roll, 'appliedDelta' => $delta];
}

function onlineCaptureEndedDots(PDO $connection, array &$records, array &$pending, array &$activity, array $endedScenes): void {
    $queue = $activity['pendingDotResolutions'] ?? [];
    $known = array_column($queue, null, 'id');
    foreach ($activity['damageOverTime'] ?? [] as $dot) {
        if (($dot['manualHp'] ?? false) === true || !isset($endedScenes[(string) ($dot['sceneId'] ?? '')]) || (int) ($dot['remainingTurns'] ?? 0) <= 0 || isset($known[$dot['id'] ?? ''])) continue;
        $target = onlineOngoingEffectTarget($connection, $records, $pending, (string) $dot['sceneId'], (string) $dot['targetTokenId']);
        if ($target === [] || onlineTokenIsDead($target, onlineTokenControllerIdFromRecords($connection, $records, $target) !== '')) continue;
        if (count($queue) >= XAR_PENDING_DOT_RESOLUTION_MAXIMUM) rejectOnlineCommand($connection, 409, 'Résolvez les dégâts de fin de combat déjà en attente avant de terminer un autre combat.', 'dot_resolution_capacity');
        $queue[] = [...$dot, 'targetName' => (string) ($target['name'] ?? 'Cible'),
            'targetIdentity' => onlineDotTargetIdentity($target), 'endedAt' => gmdate('c')];
        $known[$dot['id']] = true;
    }
    $activity['pendingDotResolutions'] = $queue;
}

function onlinePublicRevealedRoll(array $roll): array {
    $public = publicOnlineAttackRoll($roll, false, ($roll['mapEvent']['kind'] ?? '') === 'damage' ? 'damage' : 'roll');
    unset($public['sourceTokenId'], $public['sourceSceneId'], $public['statId'], $public['statLabel']);
    if (isset($public['mapEvent'])) foreach (['tokenId', 'anchorTokenId', 'sourceTokenId', 'targetTokenId', 'attackId'] as $key) unset($public['mapEvent'][$key]);
    if(($roll['outcome']['joker']??false)===true && isset($public['mapEvent']))unset($public['mapEvent']['sceneId'],$public['mapEvent']['layerId']);
    return [...$public, 'visibility' => 'public', 'revealed' => true, 'gmRevealed' => true, 'revealedAt' => $roll['revealedAt'] ?? ''];
}

function onlineGmRevealRoll(PDO $connection, array &$records, array &$pending, array $table, array $identity, array $arguments): array {
    if (($identity['effective_mode'] ?? '') !== 'gm' || ($identity['permanent_role'] ?? '') !== 'gm') rejectOnlineCommand($connection, 403, 'Seul le MJ peut révéler un jet.', 'gm_required');
    $records = array_replace($records, applicationDomainRecords($connection, ['activity']));
    $activity = $pending['activity']['payload'] ?? applicationDomainPayload($records, 'activity');
    $rollId = (string) ($arguments['rollId'] ?? '');
    $index = findEntryIndex($activity['rolls'] ?? [], $rollId);
    foreach ($activity['rollRevelations'] ?? [] as $entry) if (($entry['rollId'] ?? '') === $rollId) return ['deduplicated' => true];
    if ($index < 0) rejectOnlineCommand($connection, 409, 'Ce jet n’est plus disponible.', 'roll_missing');
    $roll = $activity['rolls'][$index];
    if (($roll['revealed'] ?? false) === true || ($roll['visibility'] ?? '') === 'public') return ['deduplicated' => true];
    $sceneId = (string) ($roll['mapEvent']['sceneId'] ?? $roll['sourceSceneId'] ?? '');
    if ($sceneId !== '' && $sceneId !== onlineActiveSceneId($table)) rejectOnlineCommand($connection, 409, 'La scène de ce jet n’est pas diffusée.', 'roll_scene_private');
    if ((($roll['mapEvent']['kind'] ?? '') === 'damage' && ($roll['mapEvent']['applied'] ?? false) !== true)
        || in_array($roll['rollKind'] ?? '', ['damage', 'custom-damage'], true)
        || (!isset($roll['mapEvent']) && !isset($roll['outcome']) && preg_match('/^Dégâts(?:\s|$)/u', (string) ($roll['label'] ?? '')) === 1))
        rejectOnlineCommand($connection, 409, 'Seuls les dégâts déjà appliqués peuvent être révélés.', 'unapplied_damage_private');
    $roll['revealed'] = true; $roll['gmRevealed'] = true; $roll['revealedAt'] = gmdate('c');
    $activity['rolls'][$index] = $roll;
    $activity['rollRevelations'] = array_slice([['rollId' => $rollId, 'sceneId' => $sceneId, 'revealedAt' => $roll['revealedAt']], ...($activity['rollRevelations'] ?? [])], 0, 500);
    queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
    onlineAppendPlayerAction($connection, $records, $pending, $identity, $sceneId,
        ['kind' => 'roll-reveal', 'characterName' => $roll['characterName'] ?? 'MJ', 'summary' => 'Révèle un jet aux joueurs']);
    return ['roll' => onlinePublicRevealedRoll($roll), 'deduplicated' => false];
}

function onlineStackableEffectCommand(PDO $connection,array &$records,array &$pending,array $identity,array $arguments): array {
    if(($identity['effective_mode']??'')!=='gm'||($identity['permanent_role']??'')!=='gm')rejectOnlineCommand($connection,403,'Cette action est réservée au MJ.','gm_required');
    $sceneId=(string)($arguments['sceneId']??'');$tokenId=(string)($arguments['tokenId']??'');$effectId=(string)($arguments['effectId']??'');$delta=$arguments['delta']??null;$requestId=(string)($arguments['requestId']??'');
    if(preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$requestId)!==1||!is_int($delta)||!in_array($delta,[-1,1],true)||!isset(applicationStackableEffects()[$effectId]))rejectOnlineCommand($connection,400,'Ajustement d’effet invalide.','effect_stack_invalid');
    $signature=hash('sha256',json_encode([$sceneId,$tokenId,$effectId,$delta],JSON_THROW_ON_ERROR));$now=(int)floor(microtime(true)*1000);
    $records=array_replace($records,applicationDomainRecords($connection,['activity']));$activity=$pending['activity']['payload']??applicationDomainPayload($records,'activity');
    $receipts=array_values(array_filter($activity['resourceReceipts']??[],static fn($r)=>($r['expiresAt']??0)>$now));
    $receipt=applicationFindResourceReceipt($connection,$receipts,$requestId,$now);
    if($receipt!==null){
        if(($receipt['accountId']??'')!==$identity['id'])rejectOnlineCommand($connection,403,'Cette action appartient à un autre MJ.','effect_receipt_forbidden');
        if(($receipt['kind']??'')!=='effect-stack'||($receipt['requestSignature']??'')!==$signature)rejectOnlineCommand($connection,409,'Cette référence a déjà un autre contenu.','effect_request_mismatch');
        return [...$receipt['result'],'deduplicated'=>true];
    }
    if(count($receipts)>=XAR_RESOURCE_RECEIPT_MAXIMUM)rejectOnlineCommand($connection,429,'Le journal de sécurité est plein.','effect_receipt_capacity');
    $target=onlineOngoingEffectTarget($connection,$records,$pending,$sceneId,$tokenId);if($target===[])rejectOnlineCommand($connection,404,'Ce pion n’existe plus dans cette scène.','effect_target_missing');
    try{$guards=applicationAdjustStackableEffects($activity['nextAttackGuards']??[],$effectId,$sceneId,$tokenId,$delta,'guard-'.randomToken(12));}catch(RuntimeException $error){rejectOnlineCommand($connection,409,$error->getMessage(),'effect_stack_capacity');}
    $percent=0;foreach($guards as $g)if(($g['effectId']??'')===$effectId&&$g['sceneId']===$sceneId&&$g['targetTokenId']===$tokenId)$percent+=$g['percent'];
    $definition=applicationStackableEffects()[$effectId];$result=['sceneId'=>$sceneId,'tokenId'=>$tokenId,'effectId'=>$effectId,'percent'=>$percent,'stacks'=>$percent/$definition['perStack']];
    $activity['nextAttackGuards']=$guards;queueOnlineDomainUpsert($pending,$records,'activity',$activity);
    $action=onlineAppendPlayerAction($connection,$records,$pending,$identity,$sceneId,['kind'=>'effect-stack','characterName'=>$target['name']??'Cible','summary'=>$definition['label'].' : '.$result['stacks'].' stack(s)','detail'=>($delta>0?'+1':'−1').' · réserve '.$percent.' %']);
    $activity=$pending['activity']['payload']??$activity;$receipts[]=['kind'=>'effect-stack','requestId'=>$requestId,'accountId'=>$identity['id'],'requestSignature'=>$signature,'sourceKey'=>$tokenId,'actionId'=>$action['id'],'expiresAt'=>$now+XAR_RESOURCE_RECEIPT_TTL_MILLISECONDS,'result'=>$result];
    $activity['resourceReceipts']=$receipts;queueOnlineDomainUpsert($pending,$records,'activity',$activity);return [...$result,'deduplicated'=>false];
}
function onlineCombatEffectCommand(PDO $connection, array &$records, array &$pending, array $table, array $identity, array $arguments, bool $resolve): array {
    if (($identity['effective_mode'] ?? '') !== 'gm' || ($identity['permanent_role'] ?? '') !== 'gm') rejectOnlineCommand($connection, 403, 'Cette action est réservée au MJ.', 'gm_required');
    $records = array_replace($records, applicationDomainRecords($connection, ['activity']));
    $activity = $pending['activity']['payload'] ?? applicationDomainPayload($records, 'activity');
    $requestId = (string) ($arguments['requestId'] ?? '');
    $kind = $resolve ? 'residual' : (string) ($arguments['kind'] ?? '');
    $decision = $resolve ? (string) ($arguments['decision'] ?? '') : 'remove';
    $id = (string) ($arguments['effectId'] ?? '');
    $signature = hash('sha256', json_encode([$kind, $id, $decision, $decision === 'custom' ? ($arguments['formula'] ?? '') : ''], JSON_UNESCAPED_UNICODE));
    $now = (int) floor(microtime(true) * 1000);
    if (preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $requestId) !== 1) rejectOnlineCommand($connection, 400, 'Référence de résolution invalide.', 'effect_request_invalid');
    $receipts = array_values(array_filter($activity['resourceReceipts'] ?? [], static fn ($entry): bool => ($entry['expiresAt'] ?? 0) > $now));
    $receipt = applicationFindResourceReceipt($connection, $receipts, $requestId, $now);
    if ($receipt !== null) {
        if (($receipt['accountId'] ?? '') !== $identity['id']) rejectOnlineCommand($connection, 403, 'Cette résolution appartient à un autre MJ.', 'effect_receipt_forbidden');
        if (($receipt['kind'] ?? '') !== 'combat-effect' || ($receipt['requestSignature'] ?? '') !== $signature) rejectOnlineCommand($connection, 409, 'Cette référence a déjà un autre contenu.', 'effect_request_mismatch');
        return [...$receipt['result'], 'deduplicated' => true];
    }
    if (count($receipts) + count($activity['pendingAbilityCasts'] ?? []) >= XAR_RESOURCE_RECEIPT_MAXIMUM) rejectOnlineCommand($connection, 429, 'Le journal de sécurité est plein.', 'effect_receipt_capacity');
    $field = $resolve ? 'pendingDotResolutions' : ($kind === 'enhancement' ? 'attackEnhancements' : ($kind === 'dot' ? 'damageOverTime' : ($kind === 'guard' ? 'nextAttackGuards' : '')));
    if ($field === '' || ($resolve && !in_array($decision, ['roll', 'custom', 'skip'], true))) rejectOnlineCommand($connection, 400, 'Choix de résolution invalide.', 'effect_decision_invalid');
    $entries = $activity[$field] ?? [];
    $index = findEntryIndex($entries, $id);
    if ($index < 0) rejectOnlineCommand($connection, 409, 'Cet effet a déjà été retiré ou résolu.', 'effect_missing');
    $entry = $entries[$index];
    $sceneId = (string) ($entry['sceneId'] ?? '');
    $target = onlineOngoingEffectTarget($connection, $records, $pending, $sceneId, (string) ($entry['targetTokenId'] ?? ''));
    $result = ['effectId' => $id, 'kind' => $kind, 'decision' => $decision, 'appliedDamage' => 0];
    $operation = null;
    if ($resolve && $decision !== 'skip' && $target !== [] && onlineDotTargetIdentity($target) === ($entry['targetIdentity'] ?? '')
        && !onlineTokenIsDead($target, onlineTokenControllerIdFromRecords($connection, $records, $target) !== '')) {
        $formula = $decision === 'custom' ? (string) ($arguments['formula'] ?? '') : onlineRemainingDotFormula((string) $entry['formula'], (int) $entry['remainingTurns']);
        if (!validApplicationAbilityFormula($formula)) rejectOnlineCommand($connection, 400, 'La formule de dégâts restants est hors limites ; choisissez un jet personnalisé.', 'dot_formula_invalid');
        $rolled = onlineRollFormulaWithMode($formula, 'normal');
        $element = applicationPeriodicDamageElement($entry);
        $summary = onlineTypedDamageSummary(max(0, (int) $rolled['total']),
            applicationEnhancedResistanceTarget($target, onlineAttackEnhancementState($connection, $records, $pending, $sceneId), $sceneId),
            (string) ($entry['damageType'] ?? 'physical'), $element);
        $health = $summary['finalDamage'] > 0
            ? applyOnlineAttackDamage($connection, $records, $pending, onlineTokenDomainKey($sceneId, $target['id']), $target,
                $summary['finalDamage'], false, [...$summary, 'damageType' => $entry['damageType'] ?? 'physical',
                    'family'=>applicationEffectFamily($entry),'sourceItemKind'=>$entry['sourceItemKind']??''], false)
            : null;
        $applied = (int) ($health['appliedDamage'] ?? 0); $result['appliedDamage'] = $applied;
        $healed = (int) ($health['appliedHealing'] ?? 0);
        $absorbed = (int) ($health['poisonConvertedDamage'] ?? 0);
        if($absorbed>0)$result['appliedHealing']=$healed;
        $roll = onlineRollEntry($identity, $rolled, 'Fin de combat · ' . ($entry['label'] ?? 'Effet'), (string) $target['name']);
        $roll['diceAppearance'] = onlineDiceAppearance($target, onlineTokenControllerIdFromRecords($connection, $records, $target) !== '');
        $roll['mapEvent'] = ['kind' => $absorbed>0?'roll':'damage', 'applied' => $applied > 0, 'value' => $absorbed>0?$healed:$applied, 'label' => $absorbed>0?'Corps toxique':($entry['label'] ?? 'Effet'),
            'targetTokenId' => $target['id'], 'anchorTokenId' => $target['id'], 'targetName' => $target['name'], 'sceneId' => $sceneId, 'layerId' => onlineTokenLayerId($target)];
        if (!onlineGmTokenVisibleToPlayers($connection, $records, $table, $target, $sceneId)) { $roll['visibility'] = 'gm'; $roll['revealed'] = false; }
        if ($element !== null) $roll['damageMitigation'] = [...$summary, 'finalDamage' => $applied, ...($absorbed>0?['appliedHealing'=>$healed]:[])];
        $activity['rolls'] = array_slice([$roll, ...($activity['rolls'] ?? [])], 0, 100);
        $result['roll'] = $roll;
        if ($applied > 0 || $healed>0) $operation = ['kind' => 'resource-adjust', 'requestId' => $requestId, 'sceneId' => $sceneId, 'tokenId' => $target['id'],
            'characterId' => $health['characterId'] ?? '', 'resource' => 'hp', 'appliedDelta' => $healed-$applied,
            'previous' => $health['previousHp'] ?? 0, 'current' => $health['currentHp'] ?? 0, 'maximum' => $health['maximumHp'] ?? 0];
    } elseif ($resolve && $decision !== 'skip') $result['targetUnavailable'] = true;
    array_splice($entries, $index, 1); $activity[$field] = $entries;
    queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
    $action = onlineAppendPlayerAction($connection, $records, $pending, $identity, $sceneId, [
        'kind' => $resolve ? 'resource' : 'effect-remove', 'characterName' => $target['name'] ?? $entry['targetName'] ?? 'Cible',
        'summary' => $resolve ? (!empty($result['appliedHealing'])?'Corps toxique · '.$result['appliedHealing'].' PV rendus':'Dégâts de fin de combat : ' . $result['appliedDamage'] . ' PV perdus') : 'Retire ' . ($entry['label'] ?? 'un effet'),
        ...($operation !== null ? ['operation' => $operation] : [])]);
    $activity = $pending['activity']['payload'] ?? $activity;
    $receipts[] = ['kind' => 'combat-effect', 'requestId' => $requestId, 'accountId' => $identity['id'], 'actionId' => $action['id'],
        'sourceKey' => (string) ($entry['targetIdentity'] ?? $target['id'] ?? ''), 'requestSignature' => $signature, 'expiresAt' => $now + XAR_RESOURCE_RECEIPT_TTL_MILLISECONDS, 'result' => $result];
    $activity['resourceReceipts'] = $receipts; queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
    return [...$result, 'deduplicated' => false];
}
