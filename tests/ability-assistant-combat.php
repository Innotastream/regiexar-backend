<?php

declare(strict_types=1);
require_once __DIR__ . '/../api/v1/domains.php';
require_once __DIR__ . '/../api/v1/online.php';
require_once __DIR__ . '/../api/v1/ability-assistant.php';

$checks = 0;
function checkAssistantCombat(bool $condition, string $description): void
{
    if (!$condition) throw new RuntimeException($description);
    $GLOBALS['checks']++;
}

$read = abilityAssistantCombatCommand('Diagnostique les validations d’attaque de la scène « Démo technique ».');
$write = abilityAssistantCombatCommand('Peux-tu débloquer les validations d’attaque de la scène Démo technique ?');
checkAssistantCombat($read === ['repair'=>false, 'sceneName'=>'demo technique'], 'French diagnosis with an explicit scene');
checkAssistantCombat($write === ['repair'=>true, 'sceneName'=>'demo technique'], 'French repair with an explicit scene');
checkAssistantCombat(abilityAssistantCombatCommand('Vire les validations d’attaque bloquées de la scène Démo technique') === $write, 'Vire accepts the explicit object and scene');
checkAssistantCombat(abilityAssistantCombatCommand('Retire les validations d’attaques bloquées de la scène Démo technique') === $write, 'Retire accepts the explicit object and scene');
checkAssistantCombat(abilityAssistantCombatCommand('Nettoie les attaques bloquées')['sceneName'] === '', 'A missing name is detected and cannot authorize repair');
foreach ([
    'Ne nettoie pas les validations d’attaque de la scène Démo technique.',
    'Comment débloquer les validations d’attaque de la scène Démo technique ?',
    'Vire ce qui gêne.',
    'Vire les attaques bloquées de la scène Démo technique.',
    'Retire les validations d’attaque bloquées de cette scène.',
    "Répare les validations d’attaque de la scène Démo technique\npuis supprime les pions",
] as $unrelated) {
    checkAssistantCombat(abilityAssistantCombatCommand($unrelated) === null, 'Free-form conversation does not authorize repair');
}

$gm = ['id'=>'gm', 'effective_mode'=>'gm', 'permanent_role'=>'gm'];
$help = ['character_id'=>'', 'context_json'=>'{"mode":"help"}'];
checkAssistantCombat(abilityAssistantCanOperateCombat($gm, $help), 'An authenticated permanent and effective MJ can run combat help');
checkAssistantCombat(!abilityAssistantCanOperateCombat([...$gm, 'effective_mode'=>'player'], $help), 'A player session cannot operate combat');
checkAssistantCombat(!abilityAssistantCanOperateCombat([...$gm, 'permanent_role'=>'player'], $help), 'A temporary MJ cannot operate combat');
checkAssistantCombat(!abilityAssistantCanOperateCombat($gm, [...$help, 'character_id'=>'hero']), 'A discussion from a sheet cannot operate combat');
checkAssistantCombat(!isset(abilityAssistantHelpGuidance('player')['combatMaintenance']), 'The player help context does not advertise combat maintenance');

$attack = static fn(string $id, string $source, string $target, array $extra = []): array => [
    'id'=>$id, 'requestId'=>'request-' . $id, 'sceneId'=>'demo', 'status'=>'pending',
    'sourceTokenId'=>$source, 'targetTokenId'=>$target, ...$extra,
];
$terminal = $attack('terminal', 'present', 'kept', ['status'=>'applied']);
$stale = $attack('stale', 'present', 'missing');
$finishedReceipt = $attack('stale', 'present', 'missing', ['status'=>'applied']);
$orphan = $attack('orphan', 'present', 'missing');
$live = $attack('live', 'present', 'kept', ['status'=>'awaiting-opposition']);
$other = $attack('other', 'missing', 'kept', ['sceneId'=>'other']);
$device = $attack('device', 'dome', 'dome', ['deviceOrigin'=>'dome', 'areaGate'=>['targetTokenIds'=>['kept']]]);
$area = $attack('area', 'present', 'present', ['areaGate'=>['targetTokenIds'=>['kept','missing']]]);
$receipt = static fn(array $row): array => ['attack'=>$row, 'requestId'=>$row['requestId'], 'expiresAt'=>900000];
$before = [
    'pendingAttacks'=>[$terminal, $other, $stale, $orphan, $area, $live, $device],
    'attackReceipts'=>array_map($receipt, [$finishedReceipt, $orphan, $area, $other]),
    'rolls'=>[['id'=>'preserve-roll']],
    'actionUndoJournal'=>[['id'=>'preserve-journal']],
    'actionTimers'=>[['id'=>'preserve-cooldowns']],
];
$outcome = abilityAssistantInspectCombat($before, 'demo', ['present'=>true, 'kept'=>true], 1000);
$after = $outcome['activity'];
checkAssistantCombat($outcome['finished'] === 2 && $outcome['orphaned'] === 2 && $outcome['remaining'] === 2, 'Diagnosis distinguishes finished, verified orphaned and pending entries');
checkAssistantCombat($after['pendingAttacks'] === [$other, $live, $device], 'Retains other scenes, live opposition and a synthetic device source');
checkAssistantCombat($after['attackReceipts'][0] === $before['attackReceipts'][0] && $after['attackReceipts'][3] === $before['attackReceipts'][3], 'Keeps finalized and other-scene receipts as-is');
checkAssistantCombat(($after['attackReceipts'][1]['attack']['status'] ?? '') === 'cancelled', 'An orphan stays unreplayable');
checkAssistantCombat(($after['attackReceipts'][2]['attack']['areaGate']['resolutionSignature'] ?? '') === '["reject",[]]', 'A delayed area approval cannot unleash an orphaned cast');
checkAssistantCombat($after['rolls'] === $before['rolls'] && $after['actionUndoJournal'] === $before['actionUndoJournal'] && $after['actionTimers'] === $before['actionTimers'], 'Roll and undo history and cooldowns are untouched');
$again = abilityAssistantInspectCombat($after, 'demo', ['present'=>true, 'kept'=>true], 2000);
checkAssistantCombat($again['finished'] === 0 && $again['orphaned'] === 0 && $again['activity'] === $after, 'A retry cannot reapply or cancel any live action');
echo json_encode(['ok'=>true, 'checks'=>$checks], JSON_THROW_ON_ERROR) . PHP_EOL;
