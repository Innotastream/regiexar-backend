<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY', true);
require __DIR__ . '/tactical-special-effects.php';

function totalOppositionFixture(): MemoryConnection
{
    $db = specialFixture();
    $character = $db->payload('character:character-player');
    $ability = ['id'=>'total-area','name'=>'Zone test', 'effect'=>'damage','formula'=>'7','damageType'=>'magical',
        'castingStatId'=>'','manaCost'=>3,'fatigueCost'=>2,
        'damageTargeting'=>['mode'=>'area','origin'=>'caster','radiusCells'=>4,
            'affectCaster'=>false,'affectAllies'=>true,'affectEnemies'=>true]];
    $direct = ['id'=>'total-direct','name'=>'Frappe test','effect'=>'damage','formula'=>'7','damageType'=>'magical','castingStatId'=>''];
    $character['abilities'] = [$ability,$direct,[...$ability,'id'=>'total-unopposable','noDefensePossible'=>true]];
    $db->put('character:character-player',$character);
    $order = [];
    foreach ([
        'token-player'=>['x'=>20],
        'token-monster'=>['x'=>24,'stats'=>[['id'=>'character-stat-force','label'=>'Force','value'=>'40']]],
        'token-monster-two'=>['x'=>26,'stats'=>[['id'=>'character-stat-force','label'=>'Force','value'=>'40'],
            ['id'=>'character-stat-agility','label'=>'Agilité','value'=>'30']]],
        'token-player-defender'=>['x'=>29,'name'=>'Défense privée', 'controllerPlayerId'=>'account-defender',
            'stats'=>[['id'=>'defender-agility','label'=>'Agilité','value'=>'65']]],
        'token-no-stat'=>['x'=>31,'name'=>'Sans défense','stats'=>[]],
        'token-wall'=>['x'=>34,'name'=>'Obstacle','nonCombatant'=>true],
        'token-distant'=>['x'=>95,'name'=>'Hors portée'],
    ] as $id=>$data) {
        $key = 'token:scene-one:' . $id;
        $base = $db->payload($key) ?: ['id'=>$id,'name'=>$id,'stats'=>[],'conditions'=>[],'layerId'=>'ground','size'=>40];
        $db->put($key,[...$base,'y'=>50,'hp'=>100,'maxHp'=>100,'magicArmor'=>0,...$data]);
        $order[]=$id;
    }
    $db->put('token-index:scene-one',['order'=>$order]);
    return $db;
}

function totalLaunchArgs(string $ability, string $target='token-player'): array
{
    return ['requestId'=>'total-opposition-' . randomToken(16),'sceneId'=>'scene-one','sourceTokenId'=>'token-player',
        'targetTokenId'=>$target,'attackKind'=>'ability','attackId'=>$ability,'opposed'=>true,'oppositionMode'=>'total'];
}

function totalAssertMixedArea(MemoryConnection $db, TestResponse $result): void
{
    specialCheck($result->status===200 && empty($result->body['pendingAreaGate']) && !empty($result->body['areaAttacks']),
        'La zone se résout avec la réponse groupée : ' . json_encode($result->body,JSON_UNESCAPED_UNICODE));
    $children = array_column($result->body['areaAttacks'],null,'targetTokenId');
    foreach (['token-monster'=>'character-stat-force','token-monster-two'=>'character-stat-agility'] as $id=>$stat) {
        $entry = $children[$id]??[];
        specialCheck(!empty($entry['opposition']['rollId']) && ($entry['opposition']['automatic']??false)===true
            && ($entry['opposition']['statId']??'')===$stat && ($entry['opposition']['accountId']??'')==='regie-auto-defense'
            && ($entry['status']??'')!=='awaiting-opposition', 'Défense automatique canonique pour ' . $id);
    }
    $player = $children['token-player-defender']??[];
    specialCheck(($player['status']??'')==='awaiting-opposition' && empty($player['opposition'])
        && $db->payload('token:scene-one:token-player-defender')['hp']===100, 'Les PV du PJ attendent sa décision de défense.');
    $private = publicPlayerState(domainsToApplicationState($db->domains,$db->revision),['id'=>'account-defender'],[]);
    $outsider = publicPlayerState(domainsToApplicationState($db->domains,$db->revision),['id'=>'account-other'],[]);
    specialCheck(in_array($player['id'],array_column($private['pendingOppositions']??[],'id'),true)
        && !in_array($player['id'],array_column($outsider['pendingOppositions']??[],'id'),true), 'Seul le défenseur voit sa demande personnelle.');
    specialCheck(($children['token-no-stat']['opposition']['reason']??'')==='no-defense-stat'
        && $db->payload('token:scene-one:token-no-stat')['hp']===93, 'Une créature sans caractéristique ne bloque pas la zone.');
    specialCheck(empty($children['token-wall']['opposition']) && !isset($children['token-distant'])
        && $db->payload('token:scene-one:token-distant')['hp']===100, 'L’obstacle ne défend pas et le rayon reste inchangé.');
}

$db = totalOppositionFixture();
$args = totalLaunchArgs('total-area');
$launched = runCommand($db,'token.attack',$args,true,'account-gm');
totalAssertMixedArea($db,$launched);
specialCheck($db->payload('character:character-player')['resources']['mana']===97,'Le coût de zone est payé une seule fois.');
$committed = $db->domains;
$clock = $db->revision;
$retry = runCommand($db,'token.attack',$args,true,'account-gm');
specialCheck($retry->status===200 && !empty($retry->body['deduplicated']) && $db->revision===$clock && $db->domains===$committed,
    'Le rejeu du MJ ne relance aucune défense ni aucun dégât.');
$manual = $args;
unset($manual['oppositionMode']);
specialCheck(runCommand($db,'token.attack',$manual,true,'account-gm')->status===409,'La même référence ne change pas de mode.');
$children = array_column($launched->body['areaAttacks'],null,'targetTokenId');
$auto = $children['token-monster'];
$autoArguments = ['attackId'=>$auto['id'],'requestId'=>$auto['opposition']['requestId'],'statId'=>$auto['opposition']['statId'],'decision'=>'roll'];
specialCheck(runCommand($db,'token.attack.oppose',$autoArguments,true,'account-gm')->status===403,
    'Même une copie des références ne permet pas au MJ de reprendre l’identité du défenseur automatique.');
$playerDefense = ['attackId'=>$children['token-player-defender']['id'],'requestId'=>'total-proof-manual-player','statId'=>'defender-agility','decision'=>'roll'];
specialCheck(runCommand($db,'token.attack.oppose',$playerDefense,false,'account-other')->status===403,
    'Un autre joueur ne peut pas choisir cette défense.');
$playerResult = runCommand($db,'token.attack.oppose',$playerDefense,false,'account-defender');
specialCheck($playerResult->status===200 && !empty($playerResult->body['attack']['opposition']['rollId']),
    'Le joueur défenseur lance sa propre opposition.');
$action = array_values(array_filter($db->payload('activity')['combatActions'],
    static fn(array $row):bool=>in_array('request:'.$args['requestId'],$row['aliases'],true)))[0]??[];
$undoArgs = ['actionId'=>$action['actionIds'][0]??'','requestId'=>'complete-total-undo-proof'];
$undone = runCommand($db,'action.undo',$undoArgs,true,'account-gm');
specialCheck($undone->status===200 && $db->payload('character:character-player')['resources']['mana']===100
    && $db->payload('token:scene-one:token-no-stat')['hp']===100 && $db->payload('activity')['pendingAttacks']===[],
    'L’annulation complète restaure le coût, les dégâts et clôt les demandes restantes.');
$clock = $db->revision;
specialCheck((runCommand($db,'action.undo',$undoArgs,true,'account-gm')->body['deduplicated']??false)===true && $db->revision===$clock,
    'Le rejeu de l’annulation ne rembourse rien une deuxième fois.');

foreach ([true,false] as $totalAtLaunch) {
    $db = totalOppositionFixture();
    $args = totalLaunchArgs('total-area');
    if (!$totalAtLaunch) unset($args['oppositionMode']);
    $waiting = runCommand($db,'token.attack',$args);
    specialCheck($waiting->status===200 && !empty($waiting->body['pendingAreaGate'])
        && $db->payload('character:character-player')['resources']['mana']===100, 'Le joueur prépare une seule décision, sans coût immédiat.');
    $gate = $waiting->body['attack']['areaGate']??[];
    if ($totalAtLaunch) specialCheck($gate===['oppositionMode'=>'total'],'Le mode est annoncé sans révéler le plan de défense.');
    $approval = ['attackId'=>$waiting->body['attack']['id'],'confirmed'=>true,'decision'=>'approve'];
    if (!$totalAtLaunch) $approval['oppositionMode']='total';
    specialCheck(runCommand($db,'token.attack.area.resolve',$approval)->status===403,'Le joueur ne peut approuver sa propre zone.');
    specialCheck(runCommand($db,'token.attack.area.resolve',[...$approval,'defenseTokenIds'=>['token-player-defender']],true,'account-gm')->status===400,
        'Le mode total n’accepte pas de forcer la sélection des cibles.');
    $released = runCommand($db,'token.attack.area.resolve',$approval,true,'account-gm');
    totalAssertMixedArea($db,$released);
    $sameRevision = $db->revision;
    $replay = runCommand($db,'token.attack.area.resolve',$approval,true,'account-gm');
    specialCheck($replay->status===200 && !empty($replay->body['deduplicated'])
        && array_column($replay->body['areaAttacks'],'id')===array_column($released->body['areaAttacks'],'id')
        && $db->revision===$sameRevision, 'Le rejeu de la validation conserve toutes les oppositions.');
}

$friendlyKey = 'token:scene-one:token-player-defender';
foreach (['inside','outside','new-owner','moving-center'] as $change) {
    $db = totalOppositionFixture();
    $character = $db->payload('character:character-player');
    $character['abilities'][0]['damageTargeting']['affectAllies'] = false;
    if ($change === 'moving-center') $character['abilities'][0]['damageTargeting']['origin'] = 'target';
    $db->put('character:character-player', $character);
    $friendly = runCommand($db, 'unit.friendly-fire', ['sceneId'=>'scene-one','tokenId'=>'token-player','enabled'=>true]);
    specialCheck($friendly->status===200 && $friendly->body['friendlyFire']===true, 'Le joueur active Friendly fire avant son sort de zone.');
    $args = totalLaunchArgs('total-area', $change === 'moving-center' ? 'token-monster' : 'token-player');
    unset($args['oppositionMode']);
    $waiting = runCommand($db,'token.attack',$args);
    specialCheck($waiting->status===200 && !empty($waiting->body['pendingAreaGate']),
        'Le sort prépare la validation de zone sans coût : ' . $waiting->getMessage());
    $gate = $db->payload('activity')['pendingAttacks'][0]['areaGate'];
    specialCheck(in_array('token-player-defender',$gate['targetTokenIds'],true)
        && !in_array('token-player',$gate['targetTokenIds'],true),
        'Friendly fire inclut l’allié autorisé sans faire du lanceur une victime.');
    switch ($change) {
        case 'inside':
            $db->put($friendlyKey,[...$db->payload($friendlyKey),'x'=>30,'y'=>51,'size'=>45]);
            break;
        case 'outside':
            $db->put($friendlyKey,[...$db->payload($friendlyKey),'x'=>90]);
            break;
        case 'new-owner':
            $db->put($friendlyKey,[...$db->payload($friendlyKey),'controllerPlayerId'=>'account-changed']);
            break;
        case 'moving-center':
            $centerKey = 'token:scene-one:token-monster';
            $db->put($centerKey,[...$db->payload($centerKey),'x'=>25]);
            break;
    }
    $decision = ['attackId'=>$waiting->body['attack']['id'],'confirmed'=>true,'decision'=>'approve','defenseTokenIds'=>['token-player-defender']];
    $released = runCommand($db,'token.attack.area.resolve',$decision,true,'account-gm');
    if ($change === 'inside') {
        $strikes = array_column($released->body['areaAttacks']??[],null,'targetTokenId');
        specialCheck($released->status===200 && ($strikes['token-player-defender']['status']??'')==='awaiting-opposition'
            && ($strikes['token-player-defender']['hit']??[]) !== []
            && $db->payload($friendlyKey)['hp']===100 && $db->payload('token:scene-one:token-no-stat')['hp']===93
            && $db->payload('character:character-player')['resources']['mana']===97,
            'Un déplacement/redimensionnement dans la zone conserve le jet, la défense prévue et le paiement unique : '.$released->getMessage());
        continue;
    }
    $recalculatedAreaRefusal = $change === 'outside' && $released->status===400
        && str_contains($released->getMessage(),'defense_selection_changed');
    specialCheck(($released->status===409||$recalculatedAreaRefusal) && $db->payload('character:character-player')['resources']['mana']===100
        && $db->payload($friendlyKey)['hp']===100, 'L’approbation reste sans effet si la cible change de propriétaire, sort de la zone ou en déplace le centre : '.$released->getMessage());
    $refused = runCommand($db,'token.attack.area.resolve',[...$decision,'decision'=>'reject','defenseTokenIds'=>[]],true,'account-gm');
    specialCheck($refused->status===200 && ($refused->body['attack']['status']??'')==='rejected'
        && $db->payload('activity')['pendingAttacks']===[],
        'Le MJ peut toujours refuser et vider une préparation devenue caduque.');
}

$db = totalOppositionFixture();
$args = totalLaunchArgs('total-direct','token-monster');
$direct = runCommand($db,'token.attack',$args);
specialCheck($direct->status===200 && ($direct->body['attack']['status']??'')!=='awaiting-opposition'
    && !empty($db->payload('activity')['attackReceipts'][0]['attack']['opposition']['automatic']), 'La frappe directe du joueur lance aussi la défense du monstre.');
$invalid = runCommand($db,'token.attack',[...$args,'requestId'=>'total-invalid-proof-001','opposed'=>false]);
specialCheck($invalid->status===400,'Le mode total refuse une opposition explicitement désactivée.');

$db = totalOppositionFixture();
$args = totalLaunchArgs('total-direct','token-monster');
$ordinary = $args;
unset($ordinary['oppositionMode']);
$projectile = runCommand($db,'token.attack',$ordinary,true,'account-gm');
specialCheck($projectile->status===200 && ($projectile->body['attack']['status']??'')==='awaiting-opposition',
    'Le trajet de projectile part d’un vrai reçu opposable.');
$activity = $db->payload('activity');
// Inject a successful dome check awaiting its independent GM validation.
$held = [...$activity['pendingAttacks'][0],'oppositionMode'=>'total','status'=>'pending','validationKind'=>'outcome',
    'projectileGate'=>['percent'=>75,'pending'=>true,'checks'=>[]]];
$activity['pendingAttacks'][0] = $held;
$activity['attackReceipts'][0]['attack'] = $held;
$db->put('activity',$activity);
specialCheck(empty($held['opposition']) && $db->payload('token:scene-one:token-monster')['hp']===100,
    'Le contrôle de projectile ne déclenche aucune opposition avant son approbation.');
$cleared = runCommand($db,'token.attack.resolve',['attackId'=>$held['id'],'decision'=>'approve','confirmed'=>true],true,'account-gm');
specialCheck($cleared->status===200 && !empty($cleared->body['attack']['opposition']['automatic'])
    && ($cleared->body['attack']['status']??'')!=='awaiting-opposition'
    && ($cleared->body['attack']['projectileGate']['pending']??true)===false, 'L’approbation de la protection lance la défense totale tardive.');
specialCheck(!onlineTotalAreaLaunchCanResolve(['preparedHit'=>['outcomes'=>[['requiresGmValidation'=>true]]]]),
    'Le lancement exceptionnel exige toujours une confirmation dédiée.');

$db = totalOppositionFixture();
$noDefense = runCommand($db,'token.attack',totalLaunchArgs('total-unopposable'),true,'account-gm');
specialCheck($noDefense->status===200 && count($db->payload('activity')['pendingAttacks'])===0
    && count(array_filter($db->payload('activity')['attackReceipts'],static fn(array $entry):bool=>isset($entry['attack']['opposition'])))===0,
    'Une compétence sans défense possible garde sa mécanique.');

$db = totalOppositionFixture();
$sourceKey = 'character:character-player';
$character = $db->payload($sourceKey);
$allocatedAbility = ['id'=>'total-allocated','name'=>'Rafale test','effect'=>'complex','formula'=>'0','castingStatId'=>'',
    'workflow'=>['version'=>8,'steps'=>[
        ['id'=>'targets','type'=>'targets','minTargets'=>1,'maxTargets'=>2,'maximumPerTarget'=>1,'allocationTotal'=>0],
        ['id'=>'strikes','type'=>'allocated-attacks','sourceStepId'=>'targets','awarenessMode'=>'none','damageMode'=>'configured',
            'hitMode'=>'automatic','statId'=>'','damageComponents'=>[['type'=>'magical','formula'=>'7','ignoreArmor'=>true]],
            'defenseStatId'=>'character-stat-force','defenseRollMode'=>'disadvantage'],
    ]]];
$character['abilities'][] = $allocatedAbility;
$db->put($sourceKey,$character);
$start = runCommand($db,'ability.complex',['action'=>'start','requestId'=>'total-allocated-start','sceneId'=>'scene-one','sourceTokenId'=>'token-player',
    'abilityId'=>'total-allocated','initialSelection'=>['allocations'=>[['tokenId'=>'token-monster-two','count'=>1]],
        'attackPlan'=>[['tokenId'=>'token-monster-two','attackId'=>'total-allocated','statId'=>'','opposed'=>false]]]]);
specialCheck($start->status===200 && !empty($start->body['pendingAreaGate']),'Une vraie compétence allouée prépare ses cibles : ' . $start->getMessage());
$allocated = runCommand($db,'token.attack.area.resolve',['attackId'=>$start->body['attack']['id'],
    'confirmed'=>true,'decision'=>'approve','oppositionMode'=>'total'],true,'account-gm');
specialCheck($allocated->status===200 && !empty($allocated->body['execution']['id']),'La confirmation totale autorise la compétence allouée.');
$execution = $allocated->body['execution'];
$prepared = runCommand($db,'ability.complex',['action'=>'command','requestId'=>'total-allocated-prepare','executionId'=>$execution['id'],
    'expectedRevision'=>$execution['revision'],'command'=>['action'=>'prepare-target','targetTokenId'=>'token-monster-two']]);
specialCheck($prepared->status===200, 'La cible de la frappe allouée est préparée : ' . $prepared->getMessage());
$strikeArgs = ['sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster-two','attackKind'=>'ability',
    'attackId'=>'total-allocated','statId'=>'','opposed'=>true,'complexExecutionId'=>$execution['id'],'requestId'=>'total-allocated-strike'];
$strike = runCommand($db,'token.attack',$strikeArgs);
$actual = end($db->domains['activity']['payload']['attackReceipts'])['attack'];
specialCheck($strike->status===200 && $actual['id']===($strike->body['attack']['id']??'')
    && ($actual['opposition']['automatic']??false)===true && $actual['opposition']['statId']==='character-stat-force'
    && $actual['opposition']['rollMode']==='disadvantage',
    'La frappe allouée hérite de la décision totale et respecte statistique et mode imposés : ' . $strike->getMessage());
$targetKey = 'token:scene-one:token-monster-two';
$db->put($targetKey,[...$db->payload($targetKey),'x'=>80]);
$snapshot = $db->domains;
$replay = runCommand($db,'token.attack',$strikeArgs);
specialCheck($replay->status===200 && !empty($replay->body['deduplicated']) && $db->domains===$snapshot,
    'Le rejeu d’une frappe validée reste sans effet si la cible a bougé.');

$db = totalOppositionFixture();
$targetKey = 'token:scene-one:token-monster';
$db->put($targetKey,[...$db->payload($targetKey),'stats'=>[]]);
$db->put('initiative:scene-one',[...$db->payload('initiative:scene-one'),'active'=>false]);
$free = runCommand($db,'token.attack',totalLaunchArgs('total-direct','token-monster'),true,'account-gm');
specialCheck($free->status===200 && ($free->body['attack']['status']??'')==='pending'
    && ($free->body['attack']['opposition']['reason']??'')==='no-defense-stat' && $db->payload($targetKey)['hp']===100,
    'Hors combat, les dégâts restent soumis à la validation habituelle.');
$approvedFree = runCommand($db,'token.attack.resolve',['attackId'=>$free->body['attack']['id'],'confirmed'=>true,'decision'=>'approve'],true,'account-gm');
specialCheck($approvedFree->status===200 && $db->payload($targetKey)['hp']===93,'L’accord hors combat applique une seule fois les dégâts.');

$db = totalOppositionFixture();
$pending = runCommand($db,'token.attack',totalLaunchArgs('total-area'));
$refused = runCommand($db,'token.attack.area.resolve',['attackId'=>$pending->body['attack']['id'],'confirmed'=>true,'decision'=>'reject'],true,'account-gm');
specialCheck($refused->status===200 && ($refused->body['attack']['status']??'')==='rejected'
    && $db->payload($sourceKey)['resources']['mana']===100 && $db->payload('activity')['pendingAttacks']===[],
    'Un refus de la préparation totale n’applique ni coût ni opposition.');

$remarkable = null;
for ($attempt=0;$attempt<360;$attempt++) {
    $db = totalOppositionFixture();
    $character = $db->payload($sourceKey);
    $character['abilities'][0]['castingStatId']='character-stat-force';
    $db->put($sourceKey,$character);
    $launched = runCommand($db,'token.attack',[...totalLaunchArgs('total-area'),'statId'=>'character-stat-force'],true,'account-gm');
    if (!empty($launched->body['pendingAreaGate']) && !empty($launched->body['attack']['hit']['outcome']['breaksOpposition'])) {
        $remarkable=$launched;
        break;
    }
}
specialCheck($remarkable!==null && $db->payload($sourceKey)['resources']['mana']===100,
    'Un vrai jet remarquable qui rompt les oppositions suspend le lancement et son coût.');
$result = runCommand($db,'token.attack.area.resolve',['attackId'=>$remarkable->body['attack']['id'],'confirmed'=>true,'decision'=>'approve'],true,'account-gm');
specialCheck($result->status===200
    && count(array_filter($result->body['areaAttacks']??[],static fn(array $attack):bool=>!empty($attack['opposition']['rollId'])))===0,
    'Après confirmation, le jet remarquable conserve sa règle et ne lance aucune défense.');

$validation = null;
$firstTarget = 'token-monster-two';
$secondTarget = 'token-player-defender';
$groupDecision = ['confirmed'=>true, 'decision'=>'approve', 'oppositionMode'=>'total'];
foreach (range(1, 360) as $trial) {
    $db = totalOppositionFixture();
    $character = $db->payload($sourceKey);
    $character['stats']['force'] = 100;
    $character['abilities'][] = [...$allocatedAbility, 'castingStatId'=>'character-stat-force', 'manaCost'=>3];
    $db->put($sourceKey, $character);
    $defenderKey = 'token:scene-one:' . $secondTarget;
    $defender = $db->payload($defenderKey);
    $defender['stats'][] = ['id'=>'character-stat-force','label'=>'Force','value'=>'40'];
    $db->put($defenderKey,$defender);
    $selected = [$firstTarget,$secondTarget];
    $start = runCommand($db, 'ability.complex', ['action'=>'start','requestId'=>'total-critical-start-id',
        'sceneId'=>'scene-one','sourceTokenId'=>'token-player','abilityId'=>$allocatedAbility['id'],
        'initialSelection'=>[
            'allocations'=>array_map(static fn(string $tokenId): array => ['tokenId'=>$tokenId,'count'=>1], $selected),
            'attackPlan'=>array_map(static fn(string $tokenId): array => ['tokenId'=>$tokenId,'attackId'=>$allocatedAbility['id'],'statId'=>'','opposed'=>false], $selected)
        ]]);
    specialCheck($start->status===200 && !empty($start->body['pendingAreaGate']), 'A real allocated cast prepares both recipients.');
    $gateId = $start->body['attack']['id'];
    $resolvedGate = runCommand($db, 'token.attack.area.resolve', ['attackId'=>$gateId,...$groupDecision], true, 'account-gm');
    specialCheck(in_array($resolvedGate->status, [200,202], true), 'Total is an authorized group choice.');
    if (empty($resolvedGate->body['pendingValidation'])) continue;
    $candidate = $db->payload('activity')['pendingAbilityCasts'][0];
    if (($candidate['cast']['outcome']['code'] ?? '') !== 'critical-success') continue;
    $validation = $candidate;
    break;
}
specialCheck($validation!==null && $db->payload($sourceKey)['resources']['mana']===97,
    'A real initial critical success paid once and awaits its independent adjudication.');
specialCheck(!isset($resolvedGate->body['attack']['areaGate']['executionId']), 'No execution exists before the remarkable result is approved.');
$waitingActivity = $db->payload('activity');
$waitingReceipts = onlineRetainedAttackReceipts($waitingActivity, applicationResourceReceiptClock() + XAR_ATTACK_RECEIPT_TTL_MILLISECONDS + 1);
specialCheck(in_array($gateId, array_column(array_column($waitingReceipts,'attack'),'id'), true)
    && applicationClosedAttackReceiptGroups($waitingActivity)===[],
    'An outstanding remarkable decision protects its resolved group from expiry and from immediate SQL archival.');
$validationDecision = ['requestId'=>'total-critical-decision-id','validationId'=>$validation['id'],'confirmed'=>true,'decision'=>'approve'];
$approved = runCommand($db, 'ability.resolve', $validationDecision, true, 'account-gm');
$execution = $approved->body['execution'] ?? [];
specialCheck($approved->status===200 && isset($execution['id']) && $db->payload($sourceKey)['resources']['mana']===97,
    'Approval creates exactly one execution and never repays the cast.');
$beforeReplay = [$db->domains,$db->revision];
$approvalReplay = runCommand($db, 'ability.resolve', $validationDecision, true, 'account-gm');
$gateReplay = runCommand($db, 'token.attack.area.resolve', ['attackId'=>$gateId,...$groupDecision], true, 'account-gm');
specialCheck(($approvalReplay->body['deduplicated']??false)===true
    && ($approvalReplay->body['execution']['id']??null)===$execution['id']
    && ($gateReplay->body['deduplicated']??false)===true
    && ($gateReplay->body['attack']['areaGate']['executionId']??null)===$execution['id']
    && [$db->domains,$db->revision]===$beforeReplay, 'Both decisions recover the same execution and do no new work.');
foreach ([$firstTarget,$secondTarget] as $targetId) {
    $prepared = runCommand($db, 'ability.complex', ['action'=>'command','requestId'=>'total-critical-prepare-'.$targetId,
        'executionId'=>$execution['id'],'expectedRevision'=>$execution['revision'],
        'command'=>['action'=>'prepare-target','targetTokenId'=>$targetId]]);
    specialCheck($prepared->status===200, 'Prepare each actual strike after the resumed cast.');
    $execution = $prepared->body['execution'];
    $strikeBody = ['sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>$targetId,'attackKind'=>'ability',
        'attackId'=>$allocatedAbility['id'],'statId'=>'','opposed'=>true,'complexExecutionId'=>$execution['id'],
        'requestId'=>'total-critical-strike-'.$targetId];
    $strike = runCommand($db,'token.attack',$strikeBody);
    specialCheck($strike->status===200, 'The resumed execution can attack each of its actual recipients.');
    $id = $strike->body['attack']['id'];
    $current = array_column(array_column($db->payload('activity')['attackReceipts'],'attack'),null,'id')[$id];
    if ($targetId === $firstTarget) {
        specialCheck(($current['opposition']['automatic']??false)===true && !empty($current['opposition']['rollId']),
            'A creature still rolls automatically through the approved preparation after the casting validation.');
        if ($current['status']==='pending') {
            $confirmed = runCommand($db,'token.attack.resolve',['attackId'=>$id,'decision'=>'approve','confirmed'=>true],true,'account-gm');
            specialCheck($confirmed->status===200, 'A separately remarkable automatic defense can still be authorized.');
        }
        $liveExecution = $db->payload('activity')['abilityExecutions'][0];
        $confirmation = runCommand($db, 'ability.complex', ['action'=>'command','requestId'=>'total-critical-confirm-attack',
            'executionId'=>$execution['id'],'expectedRevision'=>$liveExecution['revision'],
            'command'=>['action'=>'confirm-attack','attackRequestId'=>$strikeBody['requestId']]]);
        specialCheck($confirmation->status===200, 'Continue from the first resolved attack to the next recipient.');
        $execution = $confirmation->body['execution'];
        continue;
    }
    specialCheck($current['status']==='awaiting-opposition' && !isset($current['opposition']) && $current['defenderAccountId']==='account-defender',
        'The same resumed total mode leaves the controlled character in charge of their defense.');
    $defense = runCommand($db,'token.attack.oppose',['attackId'=>$id,'requestId'=>'total-critical-player-defense','statId'=>'character-stat-force',
        'decision'=>'roll'],false,'account-defender');
    specialCheck($defense->status===200 && !empty($defense->body['attack']['opposition']['rollId']),
        'The controlling player can roll their preserved opposition.');
}

echo json_encode(['ok'=>true,'checks'=>$specialChecks],JSON_THROW_ON_ERROR).PHP_EOL;
