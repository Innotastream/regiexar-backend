<?php
declare(strict_types=1);

function applicationChainCritical(array $step, array $state): ?array
{
    if (($step['type'] ?? '') !== 'attack-chain' || ($step['resolutionMode'] ?? '') !== 'batch'
        || ($step['criticalSuccessCompletesChain'] ?? false) !== true) return null;
    foreach ($state['rolls'] ?? [] as $index => $gate) {
        if (($gate['success'] ?? false) !== true || ($gate['outcomeDetails']['code'] ?? '') !== 'critical-success') continue;
        $attack = $state['attacks'][$index] ?? null;
        return ['index' => $index, 'gate' => $gate, 'rejected' => in_array($attack['status'] ?? '', ['rejected', 'cancelled'], true),
            'waiting' => ($gate['outcomeDetails']['requiresGmValidation'] ?? false) === true && ($attack === null || ($attack['status'] ?? '') === 'pending')];
    }
    return null;
}

function applicationChainAttackGate(array $step, array $state): ?array
{
    $index = count($state['attacks'] ?? []);
    if (($step['oppositionMode'] ?? '') === 'once' && !empty($state['plan']['opposed']) && $index > 0
        && !in_array($state['attacks'][0]['status'] ?? '', ['applied','blocked','missed'], true)) return null;
    $actual = array_values(array_filter($state['rolls'] ?? [], static fn(array $gate): bool => ($gate['success'] ?? false) === true))[$index] ?? null;
    if ($actual !== null) return $actual;
    $critical = applicationChainCritical($step, $state);
    if ($critical === null || $critical['waiting'] || $critical['rejected'] || $index >= $step['count']) return null;
    return ['success' => true, 'statId' => $step['statId'], 'total' => null, 'threshold' => null, 'formula' => '', 'breakdown' => 'Frappe autorisée par le critique', 'outcome' => 'success', 'outcomeDetails' => ['code' => 'success', 'label' => 'RÉUSSITE · ENCHAÎNEMENT CRITIQUE',
        'success' => true, 'automatic' => true, 'effect' => false, 'requiresGmValidation' => false]];
}

function applicationSettleRejectedChainCritical(array &$execution, int $now, bool $firstStrikeRefused = false): void
{
    $step = $execution['workflow']['steps'][$execution['currentStepIndex']] ?? null;
    if (($execution['status'] ?? '') !== 'active' || $step === null) return;
    $state = &$execution['stepStates'][$step['id']];
    if (!is_array($state)) return;
    $first = ($step['oppositionMode'] ?? '') === 'once' && !empty($state['plan']['opposed']) ? ($state['attacks'][0] ?? []) : [];
    $stopped = ($first['status'] ?? '') === 'defended';
    $interrupted = in_array($first['status'] ?? '', ['cancelled','rejected'], true);
    $criticalRejected = applicationChainCritical($step, $state)['rejected'] ?? false;
    if (!$firstStrikeRefused && !$criticalRejected && !$stopped && !$interrupted) return;
    if ($criticalRejected) $state['criticalRejected'] = true;
    if ($stopped) { $state['oppositionStopped'] = true; $execution['endedByOpposition'] = true; }
    $state['skippedAttackCount'] = max(0, ($state['queuedAttackCount'] ?? 0) - count($state['attacks'] ?? []));
    $state['awaitingAttack'] = false; $state['attackRequestId'] = '';
    $state['status'] = 'completed'; $state['completedAt'] = $now;
    $execution['currentStepIndex'] = count($execution['workflow']['steps']);
    $execution['status'] = 'completed'; $execution['completedAt'] = $now; $execution['endedByFailure'] = true;
}

function applicationInhoFirstStrikeRefusalIndex(array $activity, array $attack): ?int
{
    if (empty($attack['complexExecutionId']) || empty($attack['requestId'])) return null;
    foreach ($activity['abilityExecutions'] ?? [] as $index => $execution) {
        if (($execution['id'] ?? '') !== $attack['complexExecutionId']
            || ($execution['sceneId'] ?? '') !== ($attack['sceneId'] ?? '')
            || ($execution['sourceTokenId'] ?? '') !== ($attack['sourceTokenId'] ?? '')) continue;
        $atFirstStep = ($execution['status'] ?? '') === 'active' && ($execution['currentStepIndex'] ?? null) === 0;
        $singleStepCompleted = ($execution['status'] ?? '') === 'completed' && ($execution['currentStepIndex'] ?? null) === 1
            && count($execution['workflow']['steps'] ?? []) === 1;
        if ((!$atFirstStep && !$singleStepCompleted) || (($execution['rulePreset']['id'] ?? '') !== 'inho-final-chain'
                && ($execution['abilityId'] ?? '') !== 'ability-c2d11a48-40e5-46a6-b43a-05405d1a5362')) return null;
        $step = $execution['workflow']['steps'][0] ?? [];
        if (($step['type'] ?? '') !== 'attack-chain' || ($step['firstGateAtCast'] ?? false) !== true) return null;
        $state = $execution['stepStates'][$step['id']] ?? [];
        $strikes = $state['attacks'] ?? [];
        if ($strikes === []) return $atFirstStep && ($state['attackRequestId'] ?? '') === $attack['requestId'] ? $index : null;
        return count($strikes) === 1 && empty($state['attackRequestId']) && ($strikes[0]['id'] ?? '') === $attack['id']
            && ($strikes[0]['requestId'] ?? '') === $attack['requestId']
            && (!$singleStepCompleted || ($strikes[0]['status'] ?? '') === 'pending') ? $index : null;
    }
    return null;
}

function applicationInhoChainCastReceipt(array $receipts, string $executionId): ?array
{
    foreach ($receipts as $receipt) {
        if (!in_array($receipt['kind'] ?? '', ['ability-workflow', 'ability-validation'], true)
            || ($receipt['result']['execution']['id'] ?? '') !== $executionId
            || ($receipt['result']['cast']['success'] ?? false) !== true) continue;
        return $receipt['result']['cast'];
    }
    return null;
}

function onlineSettleInhoFirstStrikeRefusal(PDO $connection, array &$records, array &$pending, array $attack): int|float|null
{
    $activity = $pending['activity']['payload'] ?? applicationDomainPayload($records, 'activity');
    $index = applicationInhoFirstStrikeRefusalIndex($activity, $attack);
    if ($index === null) return null;
    $execution = $activity['abilityExecutions'][$index];
    $cast = $execution['castGate']['openingStrikeCost'] ?? applicationInhoChainCastReceipt($activity['resourceReceipts'] ?? [], $execution['id']);
    if ($cast === null) {
        $alias = 'execution:' . $execution['id'];
        foreach ($activity['combatActions'] ?? [] as $action) {
            if (!in_array($alias, $action['aliases'] ?? [], true)) continue;
            $receipts = applicationReadUndoResourceReceipts($connection, $action, applicationResourceReceiptClock());
            $cast = applicationInhoChainCastReceipt($receipts, $execution['id']);
            break;
        }
    }
    $refunded = 0;
    $fatigue = max(0, onlineResourceNumber($cast['fatigueGained'] ?? 0));
    if ($fatigue > 0) {
        $characterId = (string) ($execution['characterId'] ?? '');
        $key = $characterId !== '' ? 'character:' . $characterId : onlineTokenDomainKey($execution['sceneId'], $execution['sourceTokenId']);
        $records = array_replace($records, applicationDomainRecords($connection, [$key]));
        $owner = $pending[$key]['payload'] ?? applicationDomainPayload($records, $key);
        if ($owner !== [] && ($characterId !== '' || !unitUsesCharacterSheet($owner))) {
            $refunded = onlineRefundAbilityFatigue($connection, $records, $pending, $execution['sceneId'],
                $execution['sourceTokenId'], $characterId, $fatigue, (string) ($cast['deferredFatigueEffectId'] ?? ''));
        }
    }
    $now = applicationResourceReceiptClock();
    $stepId = $execution['workflow']['steps'][0]['id'];
    $execution['stepStates'][$stepId]['attacks'] = [applicationComplexAbilityAttackResult($attack)];
    applicationSettleRejectedChainCritical($execution, $now, true);
    $execution['endedByFailure'] = true;
    $execution['revision']++;
    $execution['updatedAt'] = $now;
    $activity = $pending['activity']['payload'] ?? $activity;
    $activity['abilityExecutions'][$index] = $execution;
    queueOnlineDomainUpsert($pending, $records, 'activity', $activity);
    return $refunded;
}

function applicationChainAttackOpposed(array $step,array $state):bool {
    return !empty($state['plan']['opposed']) && (($step['oppositionMode']??'')!=='once'||count($state['attacks']??[])===0);
}

function applicationCaptureChainFailureTimer(array &$execution,array $ability,array $before,array $after,array $initiative):void {
    if (empty($ability['reducedFailureCooldown']) || !array_filter($execution['workflow']['steps'],static fn($s)=>($s['oppositionMode']??'')==='once')) return;
    $find=static function(array $timers)use($execution):?array {
        foreach($timers as $timer) if(($timer['sceneId']??'')===$execution['sceneId']&&($timer['abilityId']??'')===$execution['abilityId']
            && ($execution['characterId']!==''?($timer['characterId']??'')===$execution['characterId']:empty($timer['characterId'])&&($timer['tokenId']??'')===$execution['sourceTokenId']))return $timer;
        return null;
    };
    $execution['oppositionFailureTimer']=['before'=>$find($before),'after'=>$find($after),'combatId'=>$initiative['combatId']??'','restRecharge'=>$ability['restRecharge']??'none',
        'usedRound'=>max(1,(int)($initiative['round']??1)),'settled'=>false];
}

function applicationSettleChainFailureTimers(array &$state,string $sceneId,array $initiative,?int $now=null):void {
    $now??=(int)floor(microtime(true)*1000);$date=gmdate('Y-m-d\TH:i:s',intdiv($now,1000)).sprintf('.%03dZ',$now%1000);
    foreach($state['abilityExecutions']??[] as $ei=>$execution){
        $context=$execution['oppositionFailureTimer']??null;
        if(($execution['sceneId']??'')!==$sceneId||empty($execution['endedByOpposition'])||!is_array($context)||!empty($context['settled']))continue;
        $state['abilityExecutions'][$ei]['oppositionFailureTimer']['settled']=true;
        if(($initiative['active']??false)!==true||($initiative['combatId']??'')!==$context['combatId'])continue;
        $index=null;foreach($state['actionTimers']??[] as $i=>$timer)if(($timer['sceneId']??'')===$sceneId&&($timer['abilityId']??'')===$execution['abilityId']
            && ($execution['characterId']!==''?($timer['characterId']??'')===$execution['characterId']:empty($timer['characterId'])&&($timer['tokenId']??'')===$execution['sourceTokenId'])){$index=$i;break;}
        $current=$index!==null?$state['actionTimers'][$index]:null;
        if($context['after']!==null?($current===null||$current!=$context['after']):$current!==null)continue;
        $before=$context['before']??[];$round=$context['usedRound'];$retained=($before['cooldownActive']??true)!==false&&($before['readyRound']??0)>$round;
        $timer=[...($current??['id'=>'timer-'.$execution['id'],'sceneId'=>$sceneId,'abilityId'=>$execution['abilityId'],'characterId'=>$execution['characterId'],
            'tokenId'=>$execution['characterId']!==''?'':$execution['sourceTokenId'],'label'=>$execution['abilityName'],'ownerPlayerId'=>$execution['controllerAccountId'],
            'ownerLabel'=>$execution['sourceName'],'visibility'=>'private','createdAt'=>$date]),
            'cooldown'=>$retained?max(1,(int)($before['cooldown']??0)):1,'cooldownActive'=>true,'reusableInTurn'=>false,
            'usedRound'=>$retained?$before['usedRound']:$round,'readyRound'=>max($round+1,$retained?(int)$before['readyRound']:0),
            'restRecharge'=>($context['restRecharge']??'none')==='none'?'none':($before['restRecharge']??'none'),'restUseCount'=>(int)($before['restUseCount']??0),
            'combatUseCount'=>($before['combatId']??'')===$context['combatId']?(int)($before['combatUseCount']??0):0,'updatedAt'=>$date];
        if($index===null)$state['actionTimers'][]=$timer;else $state['actionTimers'][$index]=$timer;
    }
}
