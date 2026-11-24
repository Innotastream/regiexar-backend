<?php
declare(strict_types=1);
require_once __DIR__.'/../api/v1/domains.php';
require_once __DIR__.'/../api/v1/online.php';
if(($argv[1]??'')==='--json'){$cases=json_decode((string)stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);echo json_encode(array_map(static fn($value)=>applicationSpellVisual($value),$cases),JSON_THROW_ON_ERROR);exit;}
if(($argv[1]??'')==='--durations'){$cases=json_decode((string)stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);echo json_encode(array_map(static fn($value)=>applicationSpellVisualDuration($value),$cases),JSON_THROW_ON_ERROR);exit;}
$checks=0;$check=static function(bool $value,string $message)use(&$checks){if(!$value)throw new RuntimeException($message);$checks++;};
$map=['activeLayerId'=>'ground','naturalWidth'=>1000,'naturalHeight'=>600,'gridSize'=>50,'tokens'=>[]];
$ability=['id'=>'ability-mania-elemental-breath','name'=>'Nom arbitraire','rangeCells'=>4];$context=['sceneId'=>'scene','source'=>['id'=>'source','x'=>20,'y'=>50,'size'=>40,'layerId'=>'ground'],'targetPoint'=>['x'=>80,'y'=>50],'variantId'=>'fire','map'=>$map];
$spell=applicationTrustedSpellVisual($ability,$context);$check($spell!==null&&$spell['presetId']==='elemental-fire','Liaison de données lue');
$check(applicationTrustedSpellVisual([...$ability,'name'=>'Autre nom'],$context)===$spell,'Aucune règle par nom');
$check(applicationTrustedSpellVisual([...$ability,'id'=>'unknown','completionCue'=>['spell'=>$spell]],$context)===null,'Un champ de fiche ne remplace pas le registre');
foreach(['fire'=>'elemental-fire','ice'=>'elemental-ice','lightning'=>'elemental-lightning','acid'=>'elemental-acid','darkness'=>'elemental-darkness','roots'=>'elemental-roots']as$variant=>$preset)$check(applicationTrustedSpellVisual($ability,[...$context,'variantId'=>$variant])['presetId']===$preset,'Variante du catalogue');
foreach([['presetId'=>'unknown'],['placement'=>'iframe'],['sceneId'=>'../private'],['layerId'=>'roof'],['source'=>['x'=>20,'y'=>50,'size'=>'40']],['targetPoint'=>['x'=>101,'y'=>50]],['rangeCells'=>4.5],['rangeCells'=>21],['version'=>2]]as$patch)$check(applicationSpellVisual([...$spell,...$patch])===null,'Géométrie invalide refusée');
$cue=normalizeApplicationAbilityCompletionCue(['spell'=>$spell]);$check(validApplicationAbilityCompletionCue($cue),'Événement spatial valide sans son');
$check(!isset(applicationAbilityCompletionCueFor([...$ability,'completionCue'=>['spell'=>$spell]])['spell']),'Animation arbitraire fournie par une fiche ignorée');
$activity=[];$id=appendApplicationAbilityCueEvent($activity,$cue,'scene','ground','source');$event=$activity['abilityCueEvents'][0];
$check($id!==''&&$event['expiresAt']-$event['createdAt']===4400,'Événement one-shot à durée bornée et demi-vitesse');$check(validApplicationAbilityCueEvents($activity['abilityCueEvents']),'Événement valide dans le journal autoritatif');
$public=['sceneId'=>'scene','layerId'=>'ground','sourceTokenId'=>'source','visibleTokenIds'=>['source'],'visible'=>static fn()=>true];
$check(applicationPublicSpellVisual($spell,$public)===$spell,'DTO public minimal');
foreach([['visibleTokenIds'=>[]],['sceneId'=>'elsewhere'],['layerId'=>'upper'],['paused'=>true],['visible'=>static fn()=>false]]as$patch)$check(applicationPublicSpellVisual($spell,[...$public,...$patch])===null,'Source ou scène privée cachée');
$mounted=applicationTrustedSpellVisual($ability,[...$context,'source'=>[...$context['source'],'mountedOnTokenId'=>'mount'],'map'=>[...$map,'tokens'=>[['id'=>'mount','x'=>30,'y'=>40,'size'=>100,'layerId'=>'ground']]]]);$check($mounted['source']===['x'=>30,'y'=>40,'size'=>100],'Empreinte de monture capturée');
foreach(['elemental-darkness'=>2600,'elemental-roots'=>2800]as$preset=>$duration){
 $next=[...$spell,'presetId'=>$preset];$duration*=2;$check(applicationSpellVisualDuration($next)===$duration,'Durée exacte de la nouvelle matière à demi-vitesse');
 $nextCue=normalizeApplicationAbilityCompletionCue(['spell'=>$next]);$nextActivity=[];appendApplicationAbilityCueEvent($nextActivity,$nextCue,'scene','ground','source');$nextEvent=$nextActivity['abilityCueEvents'][0];
 $check($nextEvent['expiresAt']-$nextEvent['createdAt']===$duration,'Le journal conserve toute la croissance ou dissipation');
 foreach(['source','target','area','cone']as$placement){$placed=applicationSpellVisual([...$next,'placement'=>$placement,'radiusCells'=>2]);$check($placed!==null&&applicationPublicSpellVisual($placed,$public)===$placed,'Placement public borné');$check(applicationPublicSpellVisual($placed,[...$public,'visible'=>static fn()=>false])===null,'Nouvelle matière cachée si origine privée');}
}

define('XAR_SPECIAL_FIXTURE_ONLY', true);
require __DIR__.'/tactical-special-effects.php';
$seedEnemyAttack = static function (bool $combat, bool $opposed, string $pendingStatus, array $sourcePatch = []): array {
    for ($attempt = 0; $attempt < 25; $attempt++) {
        $db = specialFixture();
        $sourceKey = 'token:scene-one:token-monster';
        $db->put($sourceKey, [...$db->payload($sourceKey),
            'bestiaryRole'=>'enemy', 'weaponText'=>'Morsure de recette / 1d2+4',
            'weaponAttacks'=>[['id'=>'enemy-bite-fixture','name'=>'Morsure de recette','formula'=>'1d2+4','damageType'=>'physical','reach'=>'melee']],
            ...$sourcePatch,
        ]);
        $initiative = $db->payload('initiative:scene-one');
        $db->put('initiative:scene-one', [...$initiative,'active'=>$combat,'currentIndex'=>1]);
        $request = ['sceneId'=>'scene-one','sourceTokenId'=>'token-monster','targetTokenId'=>'token-player',
            'attackKind'=>'weapon','attackId'=>'enemy-bite-fixture','statId'=>'character-stat-force','rollMode'=>'advantage',
            'opposed'=>$opposed,'requestId'=>'enemy-visual-hit-'.randomToken(20)];
        $result = runCommand($db, 'token.attack', $request, true, 'account-gm');
        if ($result->status === 200 && ($result->body['attack']['status'] ?? '') === $pendingStatus
            && empty($result->body['attack']['hit']['outcome']['requiresGmValidation'])) return [$db,$result->body['attack']];
    }
    throw new RuntimeException('La route ne produit pas le résultat ordinaire attendu. ' . $result->getMessage());
};
$enemyEvents = static function (MemoryConnection $db): array {
    return array_values(array_filter($db->payload('activity')['abilityCueEvents'] ?? [], static fn(array $event): bool =>
        str_starts_with($event['completionCue']['spell']['presetId'] ?? '', 'enemy-')));
};

[$db,$initialAttack] = $seedEnemyAttack(true,true,'awaiting-opposition');
$check($enemyEvents($db) === [], 'La morsure ne joue aucun visuel avant la décision de défense.');
$skip = ['attackId'=>$initialAttack['id'],'decision'=>'skip','requestId'=>'enemy-visual-skip-0000001'];
$concluded = runCommand($db, 'token.attack.oppose', $skip, true, 'account-gm');
$biteCue = $enemyEvents($db)[0] ?? [];
$check($concluded->status === 200 && $concluded->body['attack']['status'] === 'applied'
    && ($biteCue['completionCue']['spell']['presetId'] ?? '') === 'enemy-bite'
    && ($biteCue['id'] ?? '') === 'attack-visual-' . $initialAttack['id']
    && ($biteCue['completionCue']['spell']['anchorTokenId'] ?? '') === 'token-player',
    'La vraie route d’opposition émet une morsure ancrée une fois les dégâts appliqués.');
$replayed = runCommand($db, 'token.attack.oppose', $skip, true, 'account-gm');
$check($replayed->status === 200 && $enemyEvents($db) === [$biteCue], 'Rejouer la décision ne duplique pas le visuel.');
$contextPublic = ['sceneId'=>'scene-one','layerId'=>'ground','sourceTokenId'=>'token-monster',
    'visibleTokenIds'=>['token-monster','token-player'],'visible'=>static fn()=>true];
$check(applicationPublicSpellVisual($biteCue['completionCue']['spell'], $contextPublic) !== null
    && applicationPublicSpellVisual($biteCue['completionCue']['spell'], [...$contextPublic,'visibleTokenIds'=>['token-player']]) === null,
    'Le flux ne projette la morsure que si sa source et sa cible sont visibles.');

foreach ([false,true] as $projectile) {
    [$db,$initialAttack] = $seedEnemyAttack(false,false,'pending');
    if ($projectile) {
        $activity = $db->payload('activity');
        $activity['pendingAttacks'][0]['projectileGate'] = ['percent'=>100,'pending'=>true,'checks'=>[]];
        $db->put('activity', $activity);
    }
    $check($enemyEvents($db) === [], 'Une validation MJ nécessaire retarde le visuel ennemi.');
    $approved = runCommand($db, 'token.attack.resolve', ['attackId'=>$initialAttack['id'],'decision'=>'approve','confirmed'=>true], true, 'account-gm');
    $events = $enemyEvents($db);
    $check($approved->status === 200 && $approved->body['attack']['status'] === 'applied' && count($events) === 1
        && $events[0]['id'] === 'attack-visual-' . $initialAttack['id']
        && (!$projectile || $approved->body['attack']['projectileGate']['pending'] === false),
        'Valider les dégâts joue la morsure et libère un éventuel verrou de projectile.');
    runCommand($db, 'token.attack.resolve', ['attackId'=>$initialAttack['id'],'decision'=>'approve','confirmed'=>true], true, 'account-gm');
    $check($enemyEvents($db) === $events, 'Une seconde validation ne peut pas republier la morsure.');
}
[$db,$initialAttack] = $seedEnemyAttack(true,true,'awaiting-opposition');
$activity = $db->payload('activity');
$activity['pendingAttacks'][0]['status'] = 'pending';
$activity['pendingAttacks'][0]['projectileGate'] = ['percent'=>100,'pending'=>true,'checks'=>[]];
$db->put('activity', $activity);
$approved = runCommand($db, 'token.attack.resolve', ['attackId'=>$initialAttack['id'],'decision'=>'approve','confirmed'=>true], true, 'account-gm');
$check($approved->status === 200 && $approved->body['attack']['status'] === 'awaiting-opposition'
    && $enemyEvents($db) === [], 'Lever le verrou de projectile ne joue rien si une opposition reste nécessaire.');
$resolved = runCommand($db, 'token.attack.oppose', [...$skip,'attackId'=>$initialAttack['id']], true, 'account-gm');
$check($resolved->status === 200 && count($enemyEvents($db)) === 1
    && $enemyEvents($db)[0]['id'] === 'attack-visual-' . $initialAttack['id'],
    'L’opposition suivante produit le seul visuel ennemi de cette résolution.');

[$db,$initialAttack] = $seedEnemyAttack(false,false,'pending');
$declined = runCommand($db, 'token.attack.resolve', ['attackId'=>$initialAttack['id'],'decision'=>'reject'], true, 'account-gm');
$check($declined->status === 200 && $declined->body['attack']['status'] === 'rejected'
    && $enemyEvents($db) === [], 'Une frappe refusée n’a aucun effet visuel.');
foreach (['peaceful', 'neutral'] as $role) {
    [$db,$initialAttack] = $seedEnemyAttack(true,true,'awaiting-opposition',['bestiaryRole'=>$role, 'bestiaryRank'=>'strong']);
    $reaction = runCommand($db, 'token.attack.oppose', [...$skip,'attackId'=>$initialAttack['id']], true, 'account-gm');
    $check($reaction->status === 200 && $enemyEvents($db) === [], 'Un PNJ neutre classé ne reçoit pas l’effet générique du bestiaire.');
    $model = $db->payload('token:scene-one:token-monster');
    $library = validatedDomainPayload('library', ['tokenLibrary'=>[$model]]);
    $check($library['tokenLibrary'][0]['bestiaryRole'] === $role && validApplicationTokenDomain($model),
        'Le rôle neutre est préservé en bibliothèque et sur la carte.');
}

[$db,$initialAttack] = $seedEnemyAttack(true,false,'applied');
$check(count($enemyEvents($db)) === 1, 'Un coup immédiat garde son visuel de bestiaire exactement une fois.');
$equipped = [...$initialAttack, 'echoAttackContext'=>['equipmentItemIds'=>['known-chain'],'weaponBased'=>true,'reach'=>'melee']];
$tokens = [$db->payload('token:scene-one:token-monster'), $db->payload('token:scene-one:token-player')];
$equippedMap = [...$db->payload('map:scene-one'), 'tokens'=>$tokens];
$equippedItems = ['instances'=>[['id'=>'known-chain','templateId'=>'v370-template-6f8e72ba624ecbbe481091a0']]];
$lateVisualActivity = [];
appendApplicationAttackVisualEvent($lateVisualActivity, $equipped, $equippedMap, $equippedItems, [], false, false, [], true);
$check($lateVisualActivity === [] && applicationTrustedMeleeVisual($equipped, ['map'=>$equippedMap,'items'=>$equippedItems]) !== null,
    'Le fallback tardif ne remplace pas une arme avec son propre effet.');

echo json_encode(['ok'=>true,'checks'=>$checks,'productionAccess'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
