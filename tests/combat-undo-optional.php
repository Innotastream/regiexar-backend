<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/v1/combat-undo.php';

$checks = 0;
function checkOptionalUndo(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}

function assertOptionalUndoRejected(callable $operation): void {
    try {
        $operation();
    } catch (RuntimeException $error) {
        checkOptionalUndo($error->getCode() === 409 && str_contains($error->getMessage(), 'en sécurité'), 'Un suivi incomplet refuse les deux portées.');
        return;
    }
    throw new RuntimeException('L’annulation d’un suivi incomplet devait être refusée.');
}

$source = 'token:scene-one:source';
$target = 'token:scene-one:target';
$before = [
    'table' => ['activeSceneId' => 'scene-one'], 'activity' => ['playerActions' => []],
    $source => ['id' => 'source', 'hp' => 100, 'maxHp' => 100, 'mana' => 90],
    $target => ['id' => 'target', 'hp' => 80, 'maxHp' => 100],
];
$after = $before;
$after[$target]['hp'] = 70;
$attack = ['id' => 'hit-one', 'targetTokenId' => 'target', 'sceneId' => 'scene-one', 'status' => 'applied', 'appliedDamage' => 10];
$after['activity']['attackReceipts'] = [['requestId' => 'request-attack-one', 'attack' => $attack]];
$after['activity']['playerActions'][] = ['id' => 'display-first', 'kind' => 'attack', 'summary' => 'Coup précédent'];
combatUndoRecord($before, $after, 'token.attack', ['requestId' => 'request-attack-one'], ['attack' => $attack], 'combat-first');
$oldUndo = ['actionId' => 'display-first', 'tokenId' => 'target', 'actorId' => 'gm',
    'scope' => 'target-damage', 'requestId' => 'undo-first-receipt-1'];
$receipt = combatUndoApply($after, $oldUndo);
checkOptionalUndo($receipt['restoredDamage'] === 10.0, 'La restauration historique précède la saturation.');

$before = $after;
$after[$source]['mana'] = 80;
$after['activity']['playerActions'][] = ['id' => 'display-serpent', 'kind' => 'ability-workflow', 'summary' => 'Bottes'];
$execution = ['id' => 'exec-serpent', 'status' => 'active', 'sceneId' => 'scene-one', 'abilityName' => 'Bottes'];
$after['activity']['abilityExecutions'] = [$execution];
combatUndoRecord($before, $after, 'ability.complex', ['action' => 'start', 'requestId' => 'request-serpent-one'], ['execution' => $execution], 'combat-serpent');
$before = $after;
$after[$target]['hp'] = 64;
$after['activity']['resourceReceipts'] = [['requestId' => 'request-serpent-two', 'result' => ['ok' => true]]];
for ($i = 0; $i < 9; $i++) $after[$source]['fixtureData' . $i] = str_repeat('x', 200000);
$record = combatUndoRecord($before, $after, 'ability.complex',
    ['action' => 'command', 'executionId' => 'exec-serpent', 'requestId' => 'request-serpent-two'], ['execution' => $execution]);
checkOptionalUndo($record['id'] === 'combat-serpent' && $record['undoUnavailable'] && !$record['complete'], 'La continuation volumineuse compresse le suivi déjà commencé.');
checkOptionalUndo($record['patches'] === [] && $record['damage'] === [] && $record['credits'] === [], 'Aucun fragment de remboursement ambigu ne reste disponible.');
checkOptionalUndo($after[$source]['mana'] === 80 && $after[$target]['hp'] === 64, 'Le jeu conserve la dépense et les dégâts réels.');
checkOptionalUndo($after['activity']['resourceReceipts'][0]['requestId'] === 'request-serpent-two'
    && $after['activity']['attackReceipts'][0]['requestId'] === 'request-attack-one', 'Les reçus métier sont conservés.');
checkOptionalUndo($after['activity']['playerActions'][1]['combatActionId'] === 'combat-serpent', 'Le résumé continue de référencer la trace compacte.');
$recorded = combatUndoApply($after, $oldUndo);
checkOptionalUndo($recorded['deduplicated'] && $recorded['restoredDamage'] === $receipt['restoredDamage']
    && $after[$target]['hp'] === 64, 'Un accusé d’annulation antérieur est reconnu sans restituer de PV une seconde fois.');

foreach (['action', 'target-damage'] as $scope) {
    $snapshot = $after;
    assertOptionalUndoRejected(static function () use (&$after, $scope): void {
        combatUndoApply($after, ['actionId' => 'display-serpent', 'targetTokenId' => 'target',
            'tokenId' => 'target', 'actorId' => 'gm', 'scope' => $scope, 'requestId' => 'undo-serpent-' . $scope . '-001']);
    });
    checkOptionalUndo($after === $snapshot, 'Un refus de suivi ne modifie aucune donnée.');
}

$before = $after;
$after[$target]['hp'] = 60;
$continued = combatUndoRecord($before, $after, 'ability.complex',
    ['action' => 'command', 'executionId' => 'exec-serpent', 'requestId' => 'request-serpent-three'], ['execution' => $execution]);
checkOptionalUndo($continued['id'] === 'combat-serpent' && $continued['undoUnavailable'] && $continued['patches'] === []
    && $after[$target]['hp'] === 60, 'Une continuation déjà sans suivi ne recrée pas une annulation partielle.');

$before = $after;
$after['activity']['combatActions'][0]['cancelled'] = true;
try {
    combatUndoRecord($before, $after, 'ability.complex',
        ['action' => 'command', 'executionId' => 'exec-serpent', 'requestId' => 'request-serpent-cancelled'], ['execution' => $execution]);
    throw new LogicException('Une commande suivant une annulation explicite devait rester refusée.');
} catch (RuntimeException $error) {
    checkOptionalUndo($error->getCode() === 409 && str_contains($error->getMessage(), 'annulée par le MJ'), 'Le refus d’une action déjà annulée reste autoritaire.');
}

define('XAR_SPECIAL_FIXTURE_ONLY', true);
require __DIR__ . '/tactical-special-effects.php';
$domain = specialFixture()->payload('activity');
$domain['resourceReceipts'] = $after['activity']['resourceReceipts'];
$domain['attackReceipts'] = $after['activity']['attackReceipts'];
$domain['playerActions'] = $after['activity']['playerActions'];
$domain['combatActions'] = $after['activity']['combatActions'];
$deep = 'détail historique';
for ($i = 0; $i < 25; $i++) $deep = ['level' => $deep];
$domain['combatActions'][0]['patches'] = [['domain' => $source, 'path' => ['nested'], 'before' => $deep]];
checkOptionalUndo(!validApplicationDomainShape($domain, null), 'Un détail optionnel franchit la vraie limite de profondeur.');
$validated = validatedDomainPayload('activity', $domain);
checkOptionalUndo($validated['combatActions'][0]['patches'] === [] && $validated['combatActions'][0]['undoUnavailable'], 'La validation compresse le détail optionnel sans bloquer l’activité.');
checkOptionalUndo($validated['attackReceipts'] === $domain['attackReceipts'] && $validated['resourceReceipts'] === $domain['resourceReceipts']
    && $validated['playerActions'] === $domain['playerActions'], 'Le repli de validation garde les reçus et le résumé visibles.');

echo json_encode(['ok' => true, 'checks' => $checks], JSON_THROW_ON_ERROR), "\n";
