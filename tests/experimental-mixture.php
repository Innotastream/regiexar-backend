<?php
declare(strict_types=1);
define('XAR_TACTICAL_FIXTURE_ONLY', true);
require __DIR__.'/tactical-lifecycle.php';
$checks = 0;

const MIXTURE_TEST_MANIA = 'character-amxWfdDZFvFZjLzT';
function requireGmIdentity(PDO $connection): array
{
    $identity = $GLOBALS['testIdentity'];
    if (($identity['effective_mode'] ?? '') !== 'gm') sendError(403, 'MJ requis');
    return $identity;
}

function mixtureNativePatch(MemoryConnection $db, array $changes): TestResponse
{
    $GLOBALS['testIdentity'] = ['id'=>'account-gm','display_name'=>'MJ','effective_mode'=>'gm','permanent_role'=>'gm'];
    $prepared = [];
    foreach ($changes as $key=>$patch) {
        $prepared[] = ['key'=>$key,'operation'=>'upsert','expectedRevision'=>$db->domains[$key]['revision'],
            'payload'=>[...$db->payload($key),...$patch]];
    }
    $GLOBALS['testBody'] = ['stateSchemaVersion'=>XAR_SESSION_SCHEMA_VERSION,'domainSchemaVersion'=>XAR_DOMAIN_SCHEMA_VERSION,'changes'=>$prepared];
    try { patchApplicationDomains($db); } catch (TestResponse $reply) { return $reply; }
    throw new RuntimeException('No domain response');
}

function mixtureFixture(int $mana = 60): MemoryConnection
{
    $db = fixture();
    $sheet = $db->payload('character:character-player');
    $sheet['id'] = MIXTURE_TEST_MANIA;
    $sheet['name'] = 'Mania';
    $sheet['fatigue'] = ['current'=>0,'max'=>150];
    $sheet['resources']['mana'] = $mana;
    $sheet['resources']['maxMana'] = 60;
    $sheet['abilities'] = [['id'=>XAR_EXPERIMENTAL_MIXTURE_ID,'name'=>'Mixture expérimentale','effect'=>'movement','formula'=>'0'],
        ['id'=>'mixture-test-healing','name'=>'Soin magique','effect'=>'healing','formula'=>'12','healingFormula'=>'12','manaCost'=>3,'rangeCells'=>8]];
    $db->put('character:'.MIXTURE_TEST_MANIA, $sheet);
    $db->put('roster', ['characterOrder'=>[MIXTURE_TEST_MANIA,'character-gohachu'],'players'=>[]]);
    $source = [...$db->payload('token:scene-one:token-player'),'characterId'=>MIXTURE_TEST_MANIA];
    $db->put('token:scene-one:token-player', synchronizeOnlineCharacterToken($source, $sheet));
    $gohachu = [...$sheet,'id'=>'character-gohachu','name'=>'Gohachu','ownerPlayerId'=>'account-ally','abilities'=>[]];
    $db->put('character:character-gohachu', $gohachu);
    $ally = synchronizeOnlineCharacterToken(['id'=>'token-gohachu','characterId'=>'character-gohachu','x'=>24,'y'=>48,'layerId'=>'ground'], $gohachu);
    $db->put('token:scene-one:token-gohachu', $ally);
    $tokens = $db->payload('token-index:scene-one');
    $tokens['order'][] = $ally['id'];
    $db->put('token-index:scene-one', $tokens);
    $db->put('initiative:scene-one', ['active'=>false,'round'=>1,'turnSerial'=>0,'order'=>[],'currentIndex'=>0]);
    $capsule = ['id'=>XAR_EXPERIMENTAL_MIXTURE_CAPSULE,'kind'=>'object','name'=>'Capsule de réactif'];
    $potion = ['id'=>'mixture-test-potion','kind'=>'potion','name'=>'Potion de test','potion'=>['hp'=>12,'mana'=>0,'fatigue'=>0]];
    $items = normalizeItemStore(['version'=>1,'templates'=>[$capsule,$potion],'instances'=>[
        ['id'=>'capsule-one','templateId'=>$capsule['id'],'revision'=>1,'location'=>['kind'=>'inventory','characterId'=>MIXTURE_TEST_MANIA]],
        ['id'=>'potion-one','templateId'=>$potion['id'],'revision'=>1,'location'=>['kind'=>'inventory','characterId'=>MIXTURE_TEST_MANIA]],
        ['id'=>'potion-spare','templateId'=>$potion['id'],'revision'=>1,'location'=>['kind'=>'inventory','characterId'=>MIXTURE_TEST_MANIA]],
    ]]);
    $db->put('items', $items);
    return $db;
}

function mixtureCommand(MemoryConnection $db, array $extra, bool $gm = false, string $account = 'account-player'): TestResponse
{
    return runCommand($db,'ability.mixture',['requestId'=>'mixture-test-'.randomToken(20),'sceneId'=>'scene-one','layerId'=>'ground',
        'sourceTokenId'=>'token-player','abilityId'=>XAR_EXPERIMENTAL_MIXTURE_ID,...$extra],$gm,$account);
}

$legacyClient = mixtureFixture();
$legacyBefore = [$legacyClient->domains, $legacyClient->revision];
$_SERVER['HTTP_X_XAR_CLIENT_VERSION'] = '4.4.5';
$legacyAttempt = mixtureCommand($legacyClient, ['action'=>'prepare','polarity'=>'positive','potionItemId'=>'potion-one']);
requireTactical($legacyAttempt->status === 409 && $legacyAttempt->body['code'] === 'experimental_mixture_client_update'
    && [$legacyClient->domains, $legacyClient->revision] === $legacyBefore, 'The old client cannot spend mana or components on Mixture.');
$legacyGeneric = runCommand($legacyClient,'ability.use',['requestId'=>'mixture-old-client-generic','sceneId'=>'scene-one',
    'sourceTokenId'=>'token-player','abilityId'=>XAR_EXPERIMENTAL_MIXTURE_ID]);
requireTactical($legacyGeneric->status === 409 && $legacyGeneric->body['code'] === 'experimental_mixture_client_update'
    && [$legacyClient->domains, $legacyClient->revision] === $legacyBefore, 'The legacy ability button returns the Store instruction, without writes.');
unset($_SERVER['HTTP_X_XAR_CLIENT_VERSION']);

function mixtureSeedPending(MemoryConnection $db, string $outcome, string $accountId = 'account-player', bool $legacy = false): string
{
    // The application has already drawn a uniform outcome. Exercise every
    // resolution deterministically without retrying or making randomness flaky.
    $token = $db->payload('token:scene-one:token-player');
    $charge = ['id'=>'mixture-'.randomToken(16),'accountId'=>$accountId,'outcomeId'=>$outcome,'potionLabel'=>'Potion de test'];
    if ($legacy) $token['experimentalMixture'] = $charge;
    else $token['experimentalMixtureCharges'][] = $charge;
    $db->put('token:scene-one:token-player', $token);
    return $charge['id'];
}

$mixtureDefaults = json_decode(file_get_contents(__DIR__.'/../api/v1/active-ability-defaults.json'),true,512,JSON_THROW_ON_ERROR)[MIXTURE_TEST_MANIA];
$mixtureDefinition = $mixtureDefaults['abilities'][0];
$formerMixtureDescription = $mixtureDefaults['previousDescriptions'][array_key_last($mixtureDefaults['previousDescriptions'])];
$previousSheet = ['id'=>MIXTURE_TEST_MANIA,'activeAbilityDefaults'=>[$mixtureDefaults['revision']],
    'abilities'=>[[...$mixtureDefinition,'description'=>$formerMixtureDescription]]];
requireTactical(str_contains($formerMixtureDescription,'Aveugle les ennemis')
    && str_contains($mixtureDefinition['description'],'Aveugle tous les camps')
    && str_contains($mixtureDefinition['description'],'Purge poisons et saignements dans la zone, tous camps')
    && applicationActiveAbilityDefaults($previousSheet)['abilities'][0]['description'] === $mixtureDefinition['description'],
    'Existing official ability text migrates to the all-camp area rules');
$previousSheet['abilities'][0]['description'] = 'Note de campagne personnalisée';
requireTactical(applicationActiveAbilityDefaults($previousSheet)['abilities'][0]['description'] === 'Note de campagne personnalisée',
    'The area update preserves campaign descriptions');

$db = mixtureFixture();
$recipe = ['action'=>'prepare','polarity'=>'positive','potionItemId'=>'potion-one','requestId'=>'mixture-prepare-receipt'];
$hidden = mixtureFixture();
$secret = $hidden->payload('token:scene-one:token-monster');
$hidden->put('token:scene-one:token-monster', [...$secret, 'hidden'=>true]);
$secretCharge = mixtureSeedPending($hidden, 'corrosive-catalyst');
$secretAim = ['action'=>'resolve','mixtureId'=>$secretCharge,'targetTokenId'=>$secret['id']];
$playerAim = mixtureCommand($hidden, $secretAim);
requireTactical($playerAim->status === 409 && count($hidden->payload('token:scene-one:token-player')['experimentalMixtureCharges']) === 1,
    'Players cannot select a hidden target and retain the charge');
$gmAim = mixtureCommand($hidden, $secretAim, true, 'account-gm');
$secretRolls = $hidden->payload('activity')['rolls'];
requireTactical($gmAim->status === 200 && $gmAim->body['damage'] > 0
    && ($secretRolls[array_key_last($secretRolls)]['visibility'] ?? '') === 'gm',
    'The GM can target a hidden enemy without revealing the roll: '.json_encode($gmAim->body, JSON_UNESCAPED_UNICODE));

$hiddenArea = mixtureFixture();
$hiddenArea->put('token:scene-one:token-monster', [...$secret, 'hidden'=>true]);
$areaCharge = mixtureSeedPending($hiddenArea, 'caustic-reaction');
$areaAim = mixtureCommand($hiddenArea, ['action'=>'resolve','mixtureId'=>$areaCharge,'targetPoint'=>['x'=>$secret['x'],'y'=>$secret['y']]], true, 'account-gm');
requireTactical($areaAim->status === 200 && in_array('Aveuglé',$hiddenArea->payload('token:scene-one:token-monster')['conditions'] ?? [],true),
    'GM area targeting includes hidden enemies on the chosen level');

$paused = mixtureFixture();
$paused->put('table', [...$paused->payload('table'),'tacticalSync'=>['paused'=>true]]);
$lockedCharge = mixtureSeedPending($paused, 'corrosive-catalyst');
$lockedAim = ['action'=>'resolve','mixtureId'=>$lockedCharge,'targetTokenId'=>$secret['id']];
requireTactical(mixtureCommand($paused, $recipe)->status === 423 && mixtureCommand($paused, $lockedAim)->status === 423,
    'Players cannot prepare or resolve a Mixture while the table is locked');
$gmPaused = mixtureCommand($paused, $lockedAim, true, 'account-gm');
requireTactical($gmPaused->status === 200 && $gmPaused->body['damage'] > 0,
    'The GM can resolve a Mixture during secret preparation');
requireTactical(mixtureCommand($paused, $recipe, true, 'account-gm')->status === 200
    && $paused->payload('character:'.MIXTURE_TEST_MANIA)['resources']['mana'] === 30,
    'The GM can prepare a Mixture during secret preparation');
requireTactical($paused->payload('activity')['abilityCueEvents'][0]['visibility'] === 'gm',
    'The cinematic stays in the GM view when prepared during a pause');

$oddMax = mixtureFixture();
$maniaKey = 'character:'.MIXTURE_TEST_MANIA;
$oddSheet = $oddMax->payload($maniaKey);
$oddSheet['resources']['maxHp'] = 201;
$oddMax->put($maniaKey, $oddSheet);
$oddTarget = $oddMax->payload('token:scene-one:token-player');
$oddRecords = applicationDomainRecords($oddMax);
$oddPending = [];
$oddHeal = onlineExperimentalInvincibility($oddMax, $oddRecords, $oddPending, $oddMax->payload('table'),
    ['id'=>'account-player','display_name'=>'Mania'], $oddTarget, $oddTarget, 'scene-one', 'ground',
    [...applicationExperimentalMixtureOutcomes()['regenerative-serum'],'formula'=>'50']);
requireTactical($oddHeal === 151 && $oddPending[$maniaKey]['payload']['resources']['hp'] === 161,
    'Invincibility rounds the 50% heal once before adding 50 points at an odd max HP');

foreach (['vital-concentrate','regenerative-serum'] as $purgingOutcome) {
    $linkedCampaign = mixtureFixture();
    $connectedPawn = [...$linkedCampaign->payload('token:scene-one:token-player'),'id'=>'token-linked-mania',
        'conditions'=>['Empoisonné','Aveuglé'],'conditionsBase'=>['Empoisonné','Aveuglé']];
    $independentPawn = [...$connectedPawn,'id'=>'token-independent-mania','followCharacter'=>false];
    $linkedCampaign->put('token:scene-two:token-linked-mania',$connectedPawn);
    $linkedCampaign->put('token:scene-two:token-independent-mania',$independentPawn);
    $linkedIndex = ['order'=>['token-linked-mania','token-independent-mania']];
    $linkedCampaign->put('token-index:scene-two',$linkedIndex);
    $linkedActivity = $linkedCampaign->payload('activity');
    $poison = ['id'=>'mixture-linked-poison','sceneId'=>'scene-two','targetTokenId'=>'token-linked-mania','label'=>'Poison',
        'formula'=>'1d4','damageType'=>'poison','remainingTurns'=>3];
    $linkedActivity['damageOverTime'] = [$poison,[...$poison,'id'=>'mixture-independent-poison','targetTokenId'=>'token-independent-mania']];
    $linkedCampaign->put('activity',$linkedActivity);
    $purgingCharge = mixtureSeedPending($linkedCampaign,$purgingOutcome);
    $aim = $purgingOutcome === 'vital-concentrate' ? ['targetPoint'=>['x'=>26,'y'=>50]] : ['targetTokenId'=>'token-player'];
    $purge = mixtureCommand($linkedCampaign,['action'=>'resolve','mixtureId'=>$purgingCharge,...$aim]);
    $kept = $purgingOutcome === 'vital-concentrate' ? ['Aveuglé'] : [];
    requireTactical($purge->status === 200 && $linkedCampaign->payload('token:scene-two:token-linked-mania')['conditions'] === $kept
        && $linkedCampaign->payload('token:scene-two:token-independent-mania')['conditions'] === $independentPawn['conditions']
        && $linkedCampaign->payload('token-index:scene-two') === $linkedIndex
        && array_column($linkedCampaign->payload('activity')['damageOverTime'],'id') === ['mixture-independent-poison'],
        'A scoped purge follows the linked sheet into the other scene, keeping its independent pawn, poison and full token index: '.$purgingOutcome);
}

$insufficient = mixtureFixture(29);
$unpaid = mixtureCommand($insufficient,$recipe);
requireTactical($unpaid->status===409 && $unpaid->body['code']==='ability_mana_insufficient'
    && $insufficient->payload('character:'.MIXTURE_TEST_MANIA)['resources']['mana']===29
    && $insufficient->payload('items')['instances'][0]['location']['kind']==='inventory', 'An unpaid mixture cannot consume resources or draw a charge');
$legacyChargeId = mixtureSeedPending($db,'vital-concentrate',legacy:true);
$stock = $db->payload('items');
requireTactical(mixtureCommand($db,$recipe,false,'another-player')->status===403, 'A player cannot prepare somebody else’s potion');
requireTactical(mixtureCommand($db,[...$recipe,'potionItemId'=>'capsule-one'])->status===409&&$db->payload('items')===$stock, 'Capsules cannot satisfy both ingredients');
$offTurn = ['active'=>true,'turnsStarted'=>true,'order'=>['token-monster','token-player'],'currentIndex'=>0,'round'=>1,'combatId'=>'combat-mixture','turnSerial'=>1];
$db->put('initiative:scene-one',$offTurn);
requireTactical(mixtureCommand($db,$recipe)->status===403&&$db->payload('items')===$stock, 'Out-of-turn preparation does not debit components');
$db->put('initiative:scene-one',['active'=>false,'round'=>1,'turnSerial'=>0,'order'=>[],'currentIndex'=>0]);
$prepared = mixtureCommand($db,$recipe);
requireTactical($prepared->status===200, 'An owned Mania can prepare the mixture: '.json_encode($prepared->body));
$mix = $prepared->body['mixture'];
requireTactical(in_array($mix['outcomeId'], applicationExperimentalMixturePool('positive'), true)&&!isset($mix['accountId']), 'Preparation reveals one candidate outcome with a private receipt');
$event = $db->payload('activity')['abilityCueEvents'][0];
$preset = 'mania-mixture-cinematic-'.$mix['outcomeId'];
requireTactical($event['id'] === 'mixture-cinematic-'.$mix['id'] && $event['sceneId'] === 'scene-one'
    && $event['sourceTokenId'] === 'token-player' && $event['visibility'] === 'public'
    && $event['expiresAt'] - $event['createdAt'] === 13800
    && ($event['completionCue']['spell']['presetId'] ?? '') === $preset
    && ($event['completionCue']['spell']['followSource'] ?? false) === true,
    'The successful transaction emits its one authoritative cinematic cue on the actual pawn');
requireTactical(count(applicationMixtureCinematicDurations()) === 8 && array_diff(array_keys(applicationMixtureCinematicDurations()), array_map(
    static fn(string $id): string => 'mania-mixture-cinematic-'.$id,
    array_keys(applicationExperimentalMixtureOutcomes())
)) === [], 'All eight outcomes are accepted by the audiovisual catalogue');
$spell = $event['completionCue']['spell'];
requireTactical(applicationSpellVisual([...$spell,'followSource'=>false]) === null
    && applicationSpellVisual([...$spell,'sourceTokenId'=>'bogus id']) === null
    && applicationSpellVisual([...$spell,'placement'=>'target','targetPoint'=>['x'=>25,'y'=>50]]) === null,
    'The cinematic schema rejects a floating or targeted cue');
requireTactical(applicationExperimentalMixturePool('positive')===['restoration-field','vital-concentrate','regenerative-serum','mixture-explosion']
    && applicationExperimentalMixturePool('negative')===['alchemical-blast','caustic-reaction','corrosive-catalyst','mixture-irradiation'],
    'Each uniform pool has a distinct failure as its fourth result');
$items = array_column($db->payload('items')['instances'], null, 'id');
requireTactical($items['capsule-one']['location']['kind']==='consumed'&&$items['potion-one']['location']['kind']==='consumed'
    && $items['potion-spare']['location']['kind']==='inventory', 'Exactly the capsule and selected potion are consumed');
requireTactical($db->payload('character:'.MIXTURE_TEST_MANIA)['fatigue']['current']===1
    && $db->payload('character:'.MIXTURE_TEST_MANIA)['resources']['mana']===30, 'Preparing takes exactly 30 mana and the only fatigue comes from Hyperactive');
$replay = mixtureCommand($db,$recipe);
requireTactical($replay->status===200&&$replay->body['deduplicated']&&$replay->body['mixture']===$mix
    && $db->payload('character:'.MIXTURE_TEST_MANIA)['resources']['mana']===30, 'Retry preserves the draw and does not pay again');
requireTactical($db->payload('activity')['abilityCueEvents'] === [$event], 'A request replay never starts the video again');
requireTactical(mixtureCommand($db,[...$recipe,'polarity'=>'negative'])->status===409, 'Reusing a receipt for different arguments fails');
$bankToken = $db->payload('token:scene-one:token-player');
$charges = $bankToken['experimentalMixtureCharges'];
requireTactical(!isset($bankToken['experimentalMixture']) && array_column($charges,'id')===($mix['outcomeId']==='mixture-explosion'
        ? [$legacyChargeId] : [$legacyChargeId,$mix['id']]), 'Preparing migrates and preserves the old charge, and only a storable draw adds a new charge');
if ($mix['outcomeId']==='mixture-explosion') requireTactical(!$prepared->body['prepared'] && $prepared->body['resolved'],
    'An explosion resolves the paid preparation immediately');
foreach (['ability.use','ability.complex','token.roll','token.attack'] as $route) {
    $attempt = runCommand($db,$route,['sceneId'=>'scene-one','layerId'=>'ground','sourceTokenId'=>'token-player',
        'abilityId'=>XAR_EXPERIMENTAL_MIXTURE_ID,'attackId'=>XAR_EXPERIMENTAL_MIXTURE_ID,'attackKind'=>'ability','kind'=>'ability','action'=>'start'],true);
    requireTactical($attempt->status===400&&$attempt->body['code']==='experimental_mixture_required', 'Alternative launch route is rejected: '.$route);
}
$token = $db->payload('token:scene-one:token-player');
$forged = preserveApplicationTokenPrivateState([...$token,'experimentalMixture'=>['id'=>'fake'],'experimentalMixtureCharges'=>[]],$token);
requireTactical(!isset($forged['experimentalMixture']) && $forged['experimentalMixtureCharges']===$charges, 'Generic writes cannot forge or erase server-owned charges');
mixtureSeedPending($db,'regenerative-serum','account-previous-owner');
$snapshot = domainsToApplicationState(applicationDomainRecords($db), $db->revision);
$own = publicPlayerState($snapshot, ['id'=>'account-player'], []);
$other = publicPlayerState($snapshot, ['id'=>'account-ally'], []);
$stream = publicPlayerState($snapshot, ['id'=>'account-player'], [], true);
requireTactical((array_column($own['map']['tokens'],null,'id')['token-player']['experimentalMixtureCharges']??null)===array_map('applicationPublicExperimentalMixture',$charges)
    && !isset(array_column($other['map']['tokens'],null,'id')['token-player']['experimentalMixtureCharges'])
    && !isset(array_column($stream['map']['tokens'],null,'id')['token-player']['experimentalMixtureCharges']), 'Only the owner sees their own charge DTOs, with no account identifiers');

$db = mixtureFixture(0);
$legacy = mixtureSeedPending($db,'vital-concentrate',legacy:true);
$first = mixtureSeedPending($db,'vital-concentrate');
$second = mixtureSeedPending($db,'restoration-field');
$foreign = mixtureSeedPending($db,'vital-concentrate','account-previous-owner');
$aim = ['action'=>'resolve','mixtureId'=>$first,'targetPoint'=>['x'=>26,'y'=>50]];
requireTactical(mixtureCommand($db,[...$aim,'mixtureId'=>$foreign])->status===409, 'A new controller cannot spend a previous controller charge');
$result = mixtureCommand($db,$aim);
requireTactical($result->status===200 && array_column($db->payload('token:scene-one:token-player')['experimentalMixtureCharges'],'id')===[$second,$foreign,$legacy]
    && $db->payload('character:'.MIXTURE_TEST_MANIA)['resources']['mana']===0
    && $db->payload('character:'.MIXTURE_TEST_MANIA)['fatigue']['current']===0
    && $db->payload('items')['instances'][0]['location']['kind']==='inventory',
    'Spending one of two equal results preserves the other charges and costs no mana, fatigue or stock');
$gmResult = mixtureCommand($db,[...$aim,'mixtureId'=>$foreign],true,'account-gm');
requireTactical($gmResult->status===200 && array_column($db->payload('token:scene-one:token-player')['experimentalMixtureCharges'],'id')===[$second,$legacy],
    'A GM may manage a charge from a prior controller without using the others');

foreach (array_diff(array_keys(applicationExperimentalMixtureOutcomes()), ['mixture-explosion','mixture-irradiation']) as $outcomeId) {
    $db = mixtureFixture();
    $mixtureId = mixtureSeedPending($db, $outcomeId);
    $args = ['action'=>'resolve','mixtureId'=>$mixtureId,'requestId'=>'mixture-resolve-receipt'];
    $badAim = mixtureCommand($db,[...$args,'targetPoint'=>['x'=>99,'y'=>99]]);
    requireTactical($badAim->status===409&&isset($db->payload('token:scene-one:token-player')['experimentalMixtureCharges']), "$outcomeId refuses bad range without losing the charge");
    $outcome = applicationExperimentalMixtureOutcomes()[$outcomeId];
    if ($outcome['radius']>0) $args['targetPoint'] = ['x'=>26,'y'=>50];
    else $args['targetTokenId'] = $outcome['polarity']==='positive' ? 'token-player' : 'token-monster';
    if (in_array($outcomeId, ['vital-concentrate','caustic-reaction'], true)) {
        $neutral = $db->payload('token:scene-one:token-monster-two');
        $neutral['bestiaryRole'] = 'neutral';
        $db->put('token:scene-one:token-monster-two', $neutral);
        $list = $db->payload('token-index:scene-one');
        foreach ([
            'token-mixture-dead'=>['hp'=>0,'healthOverride'=>'dead'],
            'token-mixture-hidden'=>['hidden'=>true],
            'token-mixture-far'=>['x'=>80],
        ] as $id=>$change) {
            $db->put('token:scene-one:'.$id,[...$neutral,'id'=>$id,...$change]);
            $list['order'][] = $id;
        }
        $db->put('token-index:scene-one', $list);
    }
    if ($outcomeId==='alchemical-blast') {
        $enemy = $db->payload('token:scene-one:token-monster');
        $enemy['elementalResistances'] = ['fire'=>100];
        $db->put('token:scene-one:token-monster', $enemy);
        foreach ([MIXTURE_TEST_MANIA,'character-gohachu'] as $holder) {
            $sheet = $db->payload('character:'.$holder);
            $sheet['resources']['hp'] = 100;
            $db->put('character:'.$holder,$sheet);
        }
        $hidden = $db->payload('token:scene-one:token-monster-two');
        $hidden['hidden'] = true;
        $hidden['hp'] = $hidden['maxHp'] = 150;
        $db->put('token:scene-one:token-monster-two',$hidden);
    }
    if ($outcomeId==='vital-concentrate') {
        $source = $db->payload('token:scene-one:token-player');
        $source['conditions'] = $source['conditionsBase'] = ['Empoisonné','Saignement','Aveuglé'];
        $db->put('token:scene-one:token-player', $source);
        $sheet = $db->payload('character:'.MIXTURE_TEST_MANIA);
        $sheet['conditions'] = $source['conditions'];
        $db->put('character:'.MIXTURE_TEST_MANIA, $sheet);
        $activity = $db->payload('activity');
        $dot = ['id'=>'mixture-poison-dot','sceneId'=>'scene-one','targetTokenId'=>'token-player','label'=>'Poison',
            'formula'=>'1d4','damageType'=>'poison','remainingTurns'=>3];
        $activity['damageOverTime'] = [$dot,
            [...$dot,'id'=>'mixture-bleeding-dot','label'=>'Saignement','damageType'=>'bleeding'],
            [...$dot,'id'=>'mixture-enemy-dot','targetTokenId'=>'token-monster'],
            [...$dot,'id'=>'mixture-outside-dot','targetTokenId'=>'token-mixture-far']];
        $activity['attackEnhancements'] = [[
            'id'=>'mixture-beneficial-coating','abilityId'=>'ability-weapon-coating','holder'=>'character:'.MIXTURE_TEST_MANIA,
            'targetTokenId'=>'token-player','sceneId'=>'scene-one','mode'=>'on-attack-dot','label'=>'Enduit de poison',
            'turns'=>5,'remainingTurns'=>5,'dot'=>['label'=>'Poison','formula'=>'1d4','damageType'=>'poison','turns'=>2],
        ]];
        $db->put('activity', $activity);
    }
    if ($outcomeId==='caustic-reaction') {
        $neutral = $db->payload('token:scene-one:token-monster-two');
        $immune = [...$neutral,'id'=>'token-lightning-immune','bestiaryRole'=>'enemy'];
        $db->put('token:scene-one:token-lightning-immune', $immune);
        $list = $db->payload('token-index:scene-one');
        $list['order'][] = $immune['id'];
        $db->put('token-index:scene-one', $list);
        $activity = $db->payload('activity');
        applicationActivateAttackEnhancement($activity,
            ['id'=>XAR_MIXTURE_INVINCIBILITY_ID,'name'=>'Protection','attackEnhancement'=>['mode'=>'immunity','family'=>'all-harm','turns'=>5]],
            $immune,'scene-one',[],'lightning-fixture-protection',$immune);
        $db->put('activity', $activity);
    }
    if ($outcomeId==='corrosive-catalyst') {
        $armored = $db->payload('token:scene-one:token-monster');
        $armored['hp'] = $armored['maxHp'] = 200;
        $armored['armorCategory'] = 'medium';
        $armored['armor'] = $armored['magicArmor'] = 20;
        $db->put('token:scene-one:token-monster', $armored);
        $activity = $db->payload('activity');
        $activity['nextAttackGuards'] = [['id'=>'mixture-test-guard','sceneId'=>'scene-one','targetTokenId'=>'token-monster',
            'percent'=>50,'lifecycle'=>'status','trigger'=>'damage','armorStacking'=>'after','damageTypes'=>['physical']]];
        $db->put('activity',$activity);
    }
    $resolved = mixtureCommand($db,$args);
    requireTactical($resolved->status===200&&$resolved->body['resolved']&&!isset($db->payload('token:scene-one:token-player')['experimentalMixtureCharges']), "$outcomeId resolves once: ".json_encode($resolved->body));
    requireTactical(array_filter($db->payload('activity')['abilityCueEvents'] ?? [], static fn(array $cue): bool =>
        str_starts_with($cue['completionCue']['spell']['presetId'] ?? '', 'mania-mixture-cinematic-')) === [], 'Spending a saved charge does not replay the preparation cinematic');
    requireTactical(mixtureCommand($db,$args)->body['deduplicated']??false, "$outcomeId resolution is safe to retry");
    requireTactical(mixtureCommand($db,[...$args,'requestId'=>'mixture-new-resolve-replay'])->status===409, "$outcomeId cannot replay by changing the request ID");
    if ($outcomeId==='corrosive-catalyst') {
        $roll = $db->payload('activity')['rolls'][0];
        $raw = $roll['total'];
        $guard = (int)round(round($raw*.8)/2);
        $applied = $resolved->body['damage'];
        requireTactical(($roll['damageMitigation']['guardPreventedDamage'] ?? null)===$guard
            && ($roll['damageMitigation']['finalDamage'] ?? null)===$applied && ($roll['mapEvent']['value'] ?? null)===$applied,
            'The acid journal details the spent guard and the actual HP loss');
    }
    if ($outcomeId!=='alchemical-blast') requireTactical($db->payload('character:character-gohachu')['resources']['hp']===10, "$outcomeId never heals Gohachu");
    if ($outcomeId==='restoration-field') {
        $field = $db->payload('devices')['spellFields'][0];
        requireTactical($field['kind']==='restoration'&&$field['remainingTurns']===5&&$field['radiusCells']===4, 'Restoration is a five-turn physical field');
        $t = $db->payload('token:scene-one:token-monster');$t['hp'] = 15;
        $db->put('token:scene-one:token-monster',$t);
        $db->put('token:scene-one:token-ko',[...$t,'id'=>'token-ko','hp'=>0]);
        $db->put('token:scene-one:token-dead',[...$t,'id'=>'token-dead','hp'=>0,'healthOverride'=>'dead']);
        $tokenIndex = $db->payload('token-index:scene-one');
        $tokenIndex['order'] = [...$tokenIndex['order'],'token-ko','token-dead'];
        $db->put('token-index:scene-one', $tokenIndex);
        $before = $db->payload('character:'.MIXTURE_TEST_MANIA)['resources']['hp'];
        for ($turn=1;$turn<=5;$turn++) {
            $advance = runCommand($db,'mechanics.command',['sceneId'=>'scene-one','command'=>'scene.advance','arguments'=>['requestId'=>'mixture-manual-turn-0'.$turn]],true);
            requireTactical($advance->status===200&&$db->payload('devices')['spellFields'][0]['remainingTurns']===5-$turn, 'Manual field advancement consumes one turn: '.json_encode($advance->body));
        }
        requireTactical($db->payload('token:scene-one:token-ko')['hp']>0 && $db->payload('token:scene-one:token-dead')['hp']===0,
            'The restoration field helps a living KO unit and leaves a corpse at 0 HP');
        requireTactical($db->payload('character:'.MIXTURE_TEST_MANIA)['resources']['hp']>$before
            && $db->payload('token:scene-one:token-monster')['hp']>15
            && $db->payload('character:character-gohachu')['resources']['hp']===10, 'All living teams within the field heal on manual turns, except Gohachu');
    } elseif ($outcomeId==='alchemical-blast') {
        $fire = $db->payload('devices')['spellFields'][0];
        requireTactical($fire['kind']==='alchemical-fire'&&$fire['remainingTurns']===5&&$fire['radiusCells']===4
            && !isset($resolved->body['damage'],$resolved->body['effects']), 'Fire is a five-turn field and its private targets are not echoed');
        requireTactical($db->payload('token:scene-one:token-monster')['hp']===40, 'Fire immunity prevents the initial heat');
        $hiddenHp = $db->payload('token:scene-one:token-monster-two')['hp'];
        $casterHp = $db->payload('character:'.MIXTURE_TEST_MANIA)['resources']['hp'];
        requireTactical($hiddenHp>=120&&$hiddenHp<=144&&$casterHp>=70&&$casterHp<=94
            && $db->payload('character:character-gohachu')['resources']['hp']===$casterHp, 'The initial ring damages hidden enemies, allies and the caster within range');
        $advance = runCommand($db,'mechanics.command',['sceneId'=>'scene-one','command'=>'scene.advance','arguments'=>['requestId'=>'mixture-fire-manual-turn-01']],true);
        requireTactical($advance->status===200&&$db->payload('devices')['spellFields'][0]['remainingTurns']===4
            && $db->payload('token:scene-one:token-monster-two')['hp']<$hiddenHp, 'A manual turn damages the field occupants again');
    } elseif ($outcomeId==='vital-concentrate') {
        $activity = $db->payload('activity');
        $buffs = array_values(array_filter($activity['attackEnhancements'],static fn(array $effect):bool=>$effect['abilityId']===XAR_EXPERIMENTAL_MIXTURE_ID));
        $affected = ['token-player','token-gohachu','token-monster','token-monster-two'];
        requireTactical(count($buffs)===4 && array_diff($affected,array_column($buffs,'targetTokenId'))===[]
            && $buffs[0]['remainingTurns']===5
            && $buffs[0]['bonuses']===['fireResistance'=>50,'iceResistance'=>50,'poisonResistance'=>50,'bleedingResistance'=>50,
                'physicalResistance'=>10,'magicalResistance'=>10] && $db->payload('character:'.MIXTURE_TEST_MANIA)['resources']['hp']===10,
            'The vital concentrate grants timed protection to the caster, ally, enemy and neutral inside the radius without healing');
        requireTactical(array_column($activity['damageOverTime'],'id')===['mixture-outside-dot']
            && in_array('mixture-beneficial-coating',array_column($activity['attackEnhancements'],'id'),true), 'The cleanse removes hostile and friendly poisons inside the radius while keeping a beneficial coating and out-of-range effects');
        $token = $db->payload('token:scene-one:token-player');
        requireTactical($token['conditions']===['Aveuglé']&&$token['conditionsBase']===['Aveuglé']
            && $db->payload('character:'.MIXTURE_TEST_MANIA)['conditions']===['Aveuglé'], 'The status clears from physical token, base conditions, and linked sheet');
        $pausedBuffs = applicationAdvanceAttackEnhancements($buffs,'scene-one',[...$offTurn,'round'=>2],['active'=>false]);
        requireTactical(count($pausedBuffs)===4&&$pausedBuffs[0]['remainingTurns']===5
            && applicationAdvanceAttackEnhancements($pausedBuffs,'scene-one',['active'=>false],['active'=>false],true)[0]['remainingTurns']===4,
            'Stopping combat retains remaining vital protection and free turns advance its clock');
    } elseif ($outcomeId==='caustic-reaction') {
        $effects = $db->payload('activity');
        $blindness = array_values(array_filter($db->payload('devices')['timedConditions'],static fn(array $entry): bool =>
            ($entry['sourceAbilityId'] ?? '') === XAR_MIXTURE_LIGHTNING_ID));
        $susceptibility = array_values(array_filter($effects['attackEnhancements'],static fn(array $entry): bool =>
            ($entry['abilityId'] ?? '') === XAR_MIXTURE_LIGHTNING_ID));
        $affected = ['token-player','token-gohachu','token-monster','token-monster-two'];
        requireTactical(count($blindness)===4 && array_diff($affected,array_column($blindness,'targetTokenId'))===[]
            && str_starts_with($blindness[0]['id'],'mixture-blindness-') && $blindness[0]['label']==='Aveuglé'
            && $blindness[0]['remainingTurns']===2, 'Foudre blinds the caster, ally, enemy and neutral inside the radius even with friendly fire disabled, while invincibility protects');
        requireTactical(count($susceptibility)===4 && array_diff($affected,array_column($susceptibility,'targetTokenId'))===[]
            && $susceptibility[0]['remainingTurns']===5 && $susceptibility[0]['bonuses']===[
                'fireResistance'=>-50,'iceResistance'=>-50,'poisonResistance'=>-50,'bleedingResistance'=>-50,
            ], 'Elemental vulnerability reaches the same four units and lasts five turns');
        $target = $db->payload('token:scene-one:token-monster');
        requireTactical(!isset($resolved->body['damage']) && $target['hp']===40
            && in_array('Aveuglé',$target['conditions'],true) && empty($db->payload('devices')['spellFields']), 'Foudre applies instant penalties, without direct damage or a persistent area');
        $armored = [...$target,'armorCategory'=>'medium','armor'=>20,'magicArmor'=>20];
        $enhancedTarget = applicationEnhancedResistanceTarget($armored,$effects,'scene-one');
        requireTactical(onlineTypedDamageSummary(100,$enhancedTarget,'physical')['finalDamage']===88
            && onlineTypedDamageSummary(100,$enhancedTarget,'magical')['finalDamage']===88
            && onlineTypedDamageSummary(100,$enhancedTarget,'ignore')['finalDamage']===100, 'Lightning amplifies remaining physical and magical damage only');
        requireTactical(onlineTypedDamageSummary(100,$enhancedTarget,'fire')['finalDamage']===120,
            'Fire receives the elemental penalty without a second +10% amplifier');
        $arvin = ['percent'=>50,'armorStacking'=>'add','damageTypes'=>['physical']];
        $strikes = onlineRollAttackDamage(['damageFormula'=>'100','damageType'=>'physical','damageRepetitions'=>2,'sceneId'=>'scene-one'],$armored,null,$effects);
        requireTactical(applicationMitigateGuardedDamage(176,[$arvin],[...$strikes['damage'],'damageType'=>'physical'])['damage']===66,
            'Arvin absorbs each strike, then lightning amplifies its remaining damage');
        $pausedBuffs = applicationAdvanceAttackEnhancements($susceptibility,'scene-one',$offTurn,['active'=>false]);
        requireTactical(count($pausedBuffs)===4 && $pausedBuffs[0]['remainingTurns']===5, 'Lightning keeps its remaining duration when combat ends');
        for ($turn=1;$turn<=2;$turn++) {
            $advance = runCommand($db,'mechanics.command',['sceneId'=>'scene-one','command'=>'scene.advance',
                'arguments'=>['requestId'=>'lightning-manual-turn-'.$turn.'-test']],true);
            requireTactical($advance->status===200, 'A free turn advances both Lightning axes: '.json_encode($advance->body));
        }
        $target = $db->payload('token:scene-one:token-monster');
        requireTactical(!in_array('Aveuglé',$target['conditions'],true)
            && applicationHasMixtureLightning($target,$db->payload('activity'),'scene-one'), 'Blindness expires before the remaining vulnerability');
    } elseif ($outcomeId==='corrosive-catalyst') {
        $target = $db->payload('token:scene-one:token-monster');
        $state = $db->payload('activity');
        $roll = $state['rolls'][0];
        $afterArmor = (int)round($roll['total']*.8);
        $firstDamage = $afterArmor - (int)round($afterArmor/2);
        requireTactical($roll['formula']==='10d10+25'&&$resolved->body['damage']===$firstDamage&&$target['hp']===200-$firstDamage,
            'Molecular acid hits with the original physical armor and guard before acid removes the armor');
        $acid = array_values(array_filter($state['attackEnhancements'],static fn(array $effect):bool=>($effect['mode']??'')==='molecular-acid'));
        requireTactical(count($acid)===1&&applicationHasMolecularAcid($target,$state,'scene-one')
            && applicationAdvanceAttackEnhancements($acid,'scene-one',[],[],true)===$acid
            && applicationAdvanceAttackEnhancements($acid,'scene-one',$offTurn,['active'=>false])===[],
            'The molecular debuff persists through free turns and ends with combat');
        $previous = [...$offTurn,'order'=>['token-monster','token-player']];
        $advancing = $acid;
        for ($round=2;$round<=7;$round++) {
            $current = [...$previous,'round'=>$round,'turnSerial'=>$round*2,'currentIndex'=>0];
            $advancing = applicationAdvanceAttackEnhancements($advancing,'scene-one',$previous,$current);
            $previous = $current;
        }
        requireTactical($advancing===$acid, 'Six real target turns do not expire molecular acid in active combat');
        $armorless = applicationEnhancedResistanceTarget($target,$state,'scene-one');
        requireTactical(onlineTypedDamageSummary(100,$armorless,'physical')['finalDamage']===100
            && onlineTypedDamageSummary(100,$armorless,'magical')['finalDamage']===80
            && onlineTypedDamageSummary(100,[...$armorless,'resistanceBonuses'=>['physical'=>-40]],'physical')['finalDamage']===120,
            'The strip caps positive physical resistance at zero and keeps other or negative resistance');
        $mixed = onlineRollAttackDamage(['sceneId'=>'scene-one','damageComponents'=>[
            ['type'=>'physical','formula'=>'100'],['type'=>'magical','formula'=>'100'],['type'=>'fire','formula'=>'100'],
        ]],[],null,$state,$target);
        requireTactical(array_column($mixed['damage']['components'],'rawDamage')===[80,100,100],
            'The weakened effective attacker loses twenty percent on its physical component only');
    } elseif ($outcome['polarity']==='positive') {
        requireTactical(($resolved->body['healing']??0)>0&&$db->payload('token:scene-one:token-monster')['hp']===40, 'Instant healing applies only to allies');
    } else {
        requireTactical(($resolved->body['damage']??0)>0&&$db->payload('character:'.MIXTURE_TEST_MANIA)['resources']['hp']===10, 'Destructive results damage enemies without damaging allies or self');
    }
}

$db = mixtureFixture(5);
$heal = runCommand($db,'ability.use',['requestId'=>'mixture-immune-spell-test','sceneId'=>'scene-one','layerId'=>'ground',
    'sourceTokenId'=>'token-player','targetTokenId'=>'token-gohachu','abilityId'=>'mixture-test-healing']);
requireTactical($heal->status===409&&$heal->body['code']==='traditional_healing_only'
    && $db->payload('character:'.MIXTURE_TEST_MANIA)['resources']['mana']===5, 'Ordinary magical healing rejects the immune target without paying');
$legacyPotion = runCommand($db,'item.consume',['sceneId'=>'scene-one','tokenId'=>'token-player','targetTokenId'=>'token-gohachu',
    'characterId'=>MIXTURE_TEST_MANIA,'itemId'=>'potion-spare','requestId'=>'mixture-immune-potion-test','expectedItemRevision'=>1],true);
requireTactical($legacyPotion->status===200&&$db->payload('character:character-gohachu')['resources']['hp']===10, 'Legacy healing potion cannot restore Gohachu’s HP: '.json_encode($legacyPotion->body));
$manual = runCommand($db,'token.resource.adjust',['requestId'=>'mixture-immune-manual-test','sceneId'=>'scene-one','tokenId'=>'token-gohachu',
    'resource'=>'hp','delta'=>8],true);
requireTactical($manual->status===200&&$db->payload('character:character-gohachu')['resources']['hp']===18, 'Traditional/manual healing remains available to Gohachu: '.json_encode($manual->body));

$identity = ['role'=>'gm'];
$activeEffect = ['version'=>1,'effects'=>[['kind'=>'heal-max-die','percent'=>20]]];
$state = ['activeSceneId'=>'scene-one','characters'=>[]];
$recipient = $db->payload('character:character-gohachu');
$result = applicationApplyActiveItem($state,['id'=>'potion','kind'=>'potion','name'=>'Potion forte','active'=>$activeEffect],$recipient,null,$identity,[
    'rollDie'=>static function (): never { throw new RuntimeException('Potion healing is blocked before rolling'); },
]);
requireTactical($result['healing']===0&&$recipient['resources']['hp']===18, 'Configured healing potion respects Gohachu’s restriction');
$result = applicationApplyActiveItem($state,['id'=>'bandage','kind'=>'object','name'=>'Bandage','active'=>$activeEffect],$recipient,null,$identity,[
    'rollDie'=>static fn(): array=>['total'=>6],
]);
requireTactical($result['healing']===6&&$recipient['resources']['hp']===24, 'Medical items remain eligible to heal Gohachu');

$db = mixtureFixture();
$mixtureId = mixtureSeedPending($db,'regenerative-serum');
$source = $db->payload('token:scene-one:token-gohachu');
$source['conditions'] = $source['conditionsBase'] = ['Empoisonné','Motivé','Invisible','Pétrifié'];
$db->put('token:scene-one:token-gohachu',$source);
$gohachu = $db->payload('character:character-gohachu');
$gohachu['conditions'] = $source['conditions'];
$db->put('character:character-gohachu',$gohachu);
$resolve = mixtureCommand($db,['action'=>'resolve','mixtureId'=>$mixtureId,'targetTokenId'=>'token-gohachu']);
$state = $db->payload('activity');
$target = $db->payload('token:scene-one:token-gohachu');
requireTactical($resolve->status===200&&$resolve->body['healing']===0
    && $target['conditions']===['Motivé','Invisible']&&applicationHasAllHarmImmunity($target,$state,'scene-one'),
    'Gohachu receives purge and protection while keeping positive conditions and current HP');
foreach (['physical','magical','fire','poison','ignore'] as $damageType) {
    $damage = onlineRollAttackDamage(['damageFormula'=>'80','damageType'=>$damageType,'sceneId'=>'scene-one'],$target,null,$state);
    requireTactical($damage['damage']['finalDamage']===0, 'Invincibility blocks '.$damageType.' damage');
}
$impact = runCommand($db,'token.resource.adjust',['requestId'=>'mixture-immune-impact-test','sceneId'=>'scene-one','tokenId'=>'token-gohachu',
    'resource'=>'hp','delta'=>-8,'impactDamage'=>true],true);
requireTactical($impact->status===200&&$db->payload('character:character-gohachu')['resources']['hp']===10, 'Damage-type adjustments respect invincibility');
$correction = runCommand($db,'token.resource.adjust',['requestId'=>'mixture-manual-correction','sceneId'=>'scene-one','tokenId'=>'token-gohachu',
    'resource'=>'hp','delta'=>-1],true);
requireTactical($correction->status===200&&$db->payload('character:character-gohachu')['resources']['hp']===9, 'Explicit GM correction is available during invincibility');

$db = mixtureFixture();
$records = applicationDomainRecords($db);
$pending = [];
$source = $db->payload('token:scene-one:token-player');
onlineExperimentalMixtureExplosion($db,$records,$pending,'scene-one','ground',$source);
$timer = $pending['devices']['payload']['timedConditions'][0];
$source = $pending['token:scene-one:token-player']['payload'];
requireTactical($timer['label']==='Pétrifié'&&$timer['remainingTurns']===3&&$timer['blocksActions']&&$timer['blocksMovement']
    && str_starts_with($timer['id'],'mixture-petrification-'), 'An unstable mixture petrifies its caster for three turns and prevents actions and movement');
$state = onlineAttackEnhancementState($db,$records,$pending,'scene-one');
$armored = [...$source,'armorCategory'=>'medium','armor'=>20];
$effective = applicationEnhancedResistanceTarget($armored,$state,'scene-one');
$physical = onlineRollAttackDamage(['damageFormula'=>'100','damageType'=>'physical','sceneId'=>'scene-one'],$armored,null,$state);
$magic = onlineRollAttackDamage(['damageFormula'=>'100','damageType'=>'magical','sceneId'=>'scene-one'],$source,null,$state);
requireTactical($physical['damage']['armorPercent']===75&&$physical['damage']['finalDamage']===25&&$magic['damage']['finalDamage']===100,
    'Petrification sets physical armor to 75 percent without changing magical damage');
requireTactical(onlineTypedDamageSummary(100,[...$effective,'mixtureAcid'=>true],'physical')['finalDamage']===25
    && onlineTypedDamageSummary(100,$effective,'physical',null,true)['finalDamage']===100
    && onlineTypedDamageSummary(100,$effective,'ignore')['finalDamage']===100,
    'Petrification overrides molecular acid armor but retains explicit armor bypass');
$arvin = ['percent'=>10,'armorStacking'=>'add','damageTypes'=>['physical']];
requireTactical(applicationMitigateGuardedDamage(25,[$arvin],[...$physical['damage'],'damageType'=>'physical'])['damage']===15,
    'Arvin additive mitigation stacks on the fixed 75 percent physical armor');
$lightningTarget = [...$effective,'mixtureLightning'=>true];
$lightning = onlineTypedDamageSummary(100,$lightningTarget,'physical');
requireTactical($lightning['finalDamage']===28
    && applicationMitigateGuardedDamage(28,[$arvin],[...$lightning,'damageType'=>'physical'])['damage']===17,
    'Blinding lightning still amplifies actual physical damage after fixed armor and Arvin guard');
$reflected = applicationReflectedDamageBasis($physical['damage'],'physical',100,$lightningTarget);
requireTactical($reflected['finalDamage']===110,
    'Reflected damage bypasses the fixed physical armor and recalculates lightning vulnerability');
$strikes = onlineRollAttackDamage(['damageFormula'=>'3','damageType'=>'physical','damageRepetitions'=>2,'sceneId'=>'scene-one'],$armored,null,$state);
requireTactical($strikes['damage']['finalDamage']===2&&count($strikes['damage']['components'])===2
    &&applicationMitigateGuardedDamage(2,[[...$arvin,'percent'=>20]],[...$strikes['damage'],'damageType'=>'physical'])['damage']===0,
    'Repeated physical hits keep their fixed armor for per-strike guard and rounding');

$db = mixtureFixture();
$fieldState = domainsToApplicationState(applicationDomainRecords($db), $db->revision);
$source = $db->payload('token:scene-one:token-player');
$clock = ['active'=>true,'turnsStarted'=>true,'combatId'=>'combat-mixture-field','round'=>1,'turnSerial'=>1,
    'currentIndex'=>0,'order'=>['token-player','token-monster']];
$fieldDevices = dsEmpty();
$field = applicationCreateMixtureField($fieldDevices,'scene-one','ground',$source,['x'=>26,'y'=>50],$clock,'mixture-clock-proof','Feu grégeois','alchemical-fire');
$enemyIndex = findEntryIndex($fieldState['map']['tokens'],'token-monster');
$fieldState['map']['tokens'][$enemyIndex]['x'] = 95;
$fieldEffects = [];
$second = [...$clock,'currentIndex'=>1,'turnSerial'=>2];
$field = applicationAdvanceMixtureField($field,$fieldState,$clock,$second,[],$fieldEffects);
$fieldState['map']['tokens'][$enemyIndex]['x'] = 26;
$again = [...$second,'turnSerial'=>4];
$field = applicationAdvanceMixtureField($field,$fieldState,$second,$again,[],$fieldEffects);
requireTactical($fieldEffects===[]&&in_array('unit_token-monster',$field['healedUnitIds'],true), 'Starting outside the field and replaying that turn inside does not give a late tick');
$nextRound = [...$again,'round'=>2,'turnSerial'=>6];
applicationAdvanceMixtureField($field,$fieldState,$again,$nextRound,[],$fieldEffects);
requireTactical(count($fieldEffects)===1&&$fieldEffects[0]['targetTokenId']==='token-monster', 'Starting the next round inside the field gives a single tick');

$db = mixtureFixture();
$stock = $db->payload('items');
$unrelatedCharge = mixtureSeedPending($db,'vital-concentrate');
$prepare = mixtureCommand($db,['action'=>'prepare','polarity'=>'negative','potionItemId'=>'potion-one']);
requireTactical($prepare->status===200,'The negative mixture can be prepared: '.json_encode($prepare->body));
$mix = $prepare->body['mixture'];
$laterCharge = mixtureSeedPending($db,'caustic-reaction');
if ($mix['outcomeId']!=='mixture-irradiation') {
    $resolve = mixtureCommand($db,['action'=>'resolve','mixtureId'=>$mix['id'],...($mix['outcomeId']==='corrosive-catalyst'
        ? ['targetTokenId'=>'token-monster'] : ['targetPoint'=>['x'=>26,'y'=>50]])]);
    requireTactical($resolve->status===200,'The actual negative outcome resolves: '.json_encode($resolve->body));
} else {
    requireTactical($prepare->body['resolved']===true&&$prepare->body['prepared']===false
        && array_column($db->payload('token:scene-one:token-player')['experimentalMixtureCharges'],'id')===[$unrelatedCharge,$laterCharge], 'Offensive failure resolves without a target prompt and preserves other charges');
}
$trace = $db->payload('activity')['combatActions'];
requireTactical(count($trace)===1&&in_array('mixture:'.$mix['id'],$trace[0]['aliases'],true), 'Preparation and completion share exactly one undo history entry');
$undone = runCommand($db,'action.undo',['requestId'=>'mixture-complete-undo-test','actionId'=>$trace[0]['actionIds'][0]],true,'account-gm');
requireTactical($undone->status===200&&$db->payload('character:'.MIXTURE_TEST_MANIA)['fatigue']['current']===0
    && $db->payload('character:'.MIXTURE_TEST_MANIA)['resources']['mana']===60
    && $db->payload('token:scene-one:token-monster')['hp']===40
    && array_column($db->payload('token:scene-one:token-player')['experimentalMixtureCharges'],'id')===[$unrelatedCharge,$laterCharge]
    && array_column(array_column($db->payload('items')['instances'],'location'),'kind')===array_column(array_column($stock['instances'],'location'),'kind'),
    'Undo restores both ingredients and the complete effect without rearming the mixture: '.json_encode($undone->body));

$db = mixtureFixture();
$source = $db->payload('token:scene-one:token-player');
$sheet = $db->payload('character:'.MIXTURE_TEST_MANIA);
$sheet['abilities'][] = ['id'=>'mixture-acid-physical-ability','name'=>'Coup de recette','effect'=>'damage','formula'=>'100',
    'damageType'=>'physical','castingStatId'=>'','rangeCells'=>8];
$db->put('character:'.MIXTURE_TEST_MANIA,$sheet);
$target = $db->payload('token:scene-one:token-monster');
$target['hp'] = $target['maxHp'] = 250;
$db->put('token:scene-one:token-monster',$target);
$clock = [...$offTurn,'order'=>['token-player','token-monster']];
$db->put('initiative:scene-one',$clock);
$activity = $db->payload('activity');
applicationActivateAttackEnhancement($activity, ['id'=>XAR_MIXTURE_ACID_ID,'name'=>'Acide moléculaire',
    'attackEnhancement'=>['mode'=>'molecular-acid','turns'=>1]],$target,'scene-one',$clock,'mixture-acid-attacker-proof',$source);
$db->put('activity',$activity);
$attacked = runCommand($db,'token.attack',['requestId'=>'mixture-acid-ordinary-attack-test','sceneId'=>'scene-one',
    'sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','attackKind'=>'ability','attackId'=>'mixture-acid-physical-ability','opposed'=>false]);
requireTactical($attacked->status===200&&$attacked->body['attack']['appliedDamage']===80
    && $db->payload('token:scene-one:token-monster')['hp']===170, 'A real native physical ability is reduced by the acid effect on its caster: '.json_encode($attacked->body));

$irradiation = applicationExperimentalMixtureOutcomes()['mixture-irradiation'];
$identity = ['id'=>'account-player','display_name'=>'Mania','effective_mode'=>'player','permanent_role'=>'player'];
foreach ([[40,7,4],[300,7,30],[300,42,126],[101,50,51]] as [$maximum,$face,$damage]) {
    $example = mixtureFixture();
    $sheetKey = 'character:'.MIXTURE_TEST_MANIA;
    $sheet = $example->payload($sheetKey);
    $sheet['resources']['maxHp'] = $maximum;
    $sheet['resources']['hp'] = $maximum-10;
    $example->put($sheetKey,$sheet);
    $records = applicationDomainRecords($example);
    $pending = [];
    $source = $example->payload('token:scene-one:token-player');
    $actual = onlineExperimentalMixtureIrradiation($example,$records,$pending,$example->payload('table'),$identity,'scene-one',
        'ground',$source,[...$irradiation,'formula'=>(string)$face]);
    $roll = $pending['activity']['payload']['rolls'][0];
    $resources = $pending[$sheetKey]['payload']['resources'];
    requireTactical($roll['total']===$face
        && str_contains($roll['breakdown'],max(10,$face).' % des PV max · '.$damage.' dégâts appliqués')
        && $actual['damage']===$damage && $resources['hp']===$maximum-10-$damage && $resources['maxHp']===$maximum,
        'Irradiation applies the journaled percentage to linked max HP with a 10 percent floor');
}
requireTactical($irradiation['pulseFormula']==='1d10', 'Pulses roll an independent d10 percentage of each target maximum');
foreach ([[40,1,0],[300,1,3],[305,10,31]] as [$maximum,$face,$damage]) {
    $example = mixtureFixture();
    $sheetKey = 'character:character-gohachu';
    $sheet = $example->payload($sheetKey);
    $sheet['resources']['maxHp'] = $sheet['resources']['hp'] = $maximum;
    $example->put($sheetKey,$sheet);
    $records = applicationDomainRecords($example);
    $pending = [];
    $target = $example->payload('token:scene-one:token-gohachu');
    $applied = onlineApplyMixtureDamage($example,$records,$pending,'scene-one',$target,'ignore',$face,[],true,minimumHpPercent:0);
    $resources = $pending[$sheetKey]['payload']['resources'] ?? $sheet['resources'];
    requireTactical(($applied['appliedDamage'] ?? 0)===$damage && $resources['hp']===$maximum-$damage,
        'A pulse uses its own target maximum with normal rounding and no self-exposure floor');
}

$db = mixtureFixture();
$enemyMaximum = 1000;
$allyMaximum = 400;
foreach ([MIXTURE_TEST_MANIA,'character-gohachu'] as $holder) {
    $sheet = $db->payload('character:'.$holder);
    $sheet['resources']['hp'] = $sheet['resources']['maxHp'] = $holder === MIXTURE_TEST_MANIA ? 100 : $allyMaximum;
    $db->put('character:'.$holder,$sheet);
}
foreach (['token-monster','token-monster-two'] as $id) {
    $target = $db->payload('token:scene-one:'.$id);
    $target['hp'] = $target['maxHp'] = $id === 'token-monster' ? $enemyMaximum : 500;
    $target['armorCategory'] = $target['magicArmorCategory'] = 'special';
    $target['armor'] = $target['magicArmor'] = 100;
    $db->put('token:scene-one:'.$id,$target);
}
$source = $db->payload('token:scene-one:token-player');
$db->put('token:scene-one:token-mania-copy',[...$source,'id'=>'token-mania-copy']);
$ally = $db->payload('token:scene-one:token-gohachu');
$db->put('token:scene-one:token-gohachu-copy',[...$ally,'id'=>'token-gohachu-copy']);
$list = $db->payload('token-index:scene-one');
$list['order'] = [...$list['order'],'token-mania-copy','token-gohachu-copy'];
$db->put('token-index:scene-one',$list);
$records = applicationDomainRecords($db);
$pending = [];
$exposure = onlineExperimentalMixtureIrradiation($db,$records,$pending,$db->payload('table'),$identity,'scene-one','ground',$source,$irradiation);
foreach ($pending as $key=>$write) $db->put($key,$write['payload']);
$exposureRoll = $db->payload('activity')['rolls'][0];
requireTactical($exposureRoll['total']>=1 && $exposureRoll['total']<=50 && $exposure['damage']===max(10,$exposureRoll['total'])
    && $db->payload('character:'.MIXTURE_TEST_MANIA)['resources']['hp']===100-$exposure['damage']
    && $exposureRoll['formula']==='1d50'
    && empty($db->payload('activity')['damageOverTime'])
    && $db->payload('token:scene-one:token-monster')['hp']===$enemyMaximum, 'Irradiation applies one raw exposure only to Mania on activation');
$stored = $db->payload('activity')['attackEnhancements'][0];
requireTactical($stored['remainingTurns']===5 && $stored['mode']==='mixture-irradiation', 'A living exposed Mania gets five turns of carried irradiation');
$otherPawn = $db->payload('token:scene-one:token-mania-copy');
requireTactical(applicationTransferAttackEnhancements([$stored], $otherPawn, 'scene-one', 'scene-empty', []) === [$stored],
    'Another linked pawn cannot take the irradiation along a passage');
$movedIrradiation = applicationTransferAttackEnhancements([$stored], $source, 'scene-one', 'scene-empty', [])[0];
requireTactical($movedIrradiation['sceneId'] === 'scene-empty' && $movedIrradiation['targetTokenId'] === $source['id']
    && $movedIrradiation['remainingTurns'] === 5,
    'The actual exposed pawn carries the remaining pulses through a passage');
$state = domainsToApplicationState(applicationDomainRecords($db), $db->revision);
$events = [];
$own = ['active'=>true,'turnsStarted'=>true,'order'=>['token-player','token-monster'],'currentIndex'=>0,'round'=>1,'combatId'=>'combat-mixture','turnSerial'=>1];
$other = [...$own,'currentIndex'=>1,'turnSerial'=>2];
applicationAdvanceMixtureIrradiation($state,$own,$other,[],$events);
$hitIds = $events[0]['targetTokenIds'];
requireTactical(count($events)===1 && $state['attackEnhancements'][0]['remainingTurns']===4
    && in_array('token-monster',$hitIds,true) && in_array('token-gohachu',$hitIds,true)
    && !in_array('token-gohachu-copy',$hitIds,true) && !in_array('token-mania-copy',$hitIds,true), 'First genuine turn end hits allies and enemies once per sheet and spares all Mania occurrences');
$rewoundExit = [...$other,'turnSerial'=>4,'rewoundTurnSerial'=>4];
applicationAdvanceMixtureIrradiation($state,$own,$rewoundExit,[],$events);
applicationAdvanceMixtureIrradiation($state,$own,[...$other,'turnSerial'=>5],[],$events);
requireTactical(count($events)===1 && $state['attackEnhancements'][0]['remainingTurns']===4, 'Rewinding and replaying the same turn does not pulse twice');
$finished = applicationAdvanceAttackEnhancements($state['attackEnhancements'],'scene-one',$other,['active'=>false]);
requireTactical($finished===$state['attackEnhancements'], 'Ending combat preserves the remaining aura turns');
$state = domainsToApplicationState(applicationDomainRecords($db), $db->revision);
$delayed = [...$own,'order'=>['token-monster','token-player'],'delayedTurnIds'=>['token-player'],'turnSerial'=>2];
[$from,$to] = applicationInitiativeEffectClocks($own,$delayed);
$events = [];
applicationAdvanceMixtureIrradiation($state,$from,$to,[],$events);
requireTactical($events===[] && $state['attackEnhancements'][0]['remainingTurns']===5, 'Deferring Mania does not end her turn');
$resumed = [...$delayed,'currentIndex'=>1,'turnSerial'=>3];
$nextRound = [...$own,'round'=>2,'turnSerial'=>4];
[$from,$to] = applicationInitiativeEffectClocks($resumed,$nextRound);
applicationAdvanceMixtureIrradiation($state,$from,$to,[],$events);
requireTactical(count($events)===1 && $state['attackEnhancements'][0]['remainingTurns']===4, 'The actual end of a resumed delayed turn ticks');
$missingAnchor = $state;
$missingAnchor['map']['tokens'] = array_values(array_filter($missingAnchor['map']['tokens'], static fn(array $token): bool => $token['id'] !== 'token-player'));
$noPulse = [];
applicationAdvanceMixtureIrradiation($missingAnchor,[],[],['manual'=>true],$noPulse);
requireTactical($noPulse===[] && $missingAnchor['attackEnhancements']===[],
    'Removing the exact aura anchor retires it without transferring it to another depiction');
$combatDb = new MemoryConnection(array_column($db->domains,'payload','domain_key'));
$combatDb->put('initiative:scene-one',$own);
$reply = mixtureNativePatch($combatDb,['initiative:scene-one'=>$other]);
$enemyAfterFirstEnd = $combatDb->payload('token:scene-one:token-monster')['hp'];
$enemyLoss = $enemyMaximum-$enemyAfterFirstEnd;
$allyLoss = $allyMaximum-$combatDb->payload('character:character-gohachu')['resources']['hp'];
requireTactical($reply->status===200 && $enemyLoss>=10 && $enemyLoss<=100 && $enemyLoss%10===0
    && $allyLoss>=4 && $allyLoss<=40 && $allyLoss%4===0
    && $combatDb->payload('activity')['attackEnhancements'][0]['remainingTurns']===4,
    'The normal initiative endpoint commits one irradiation pulse on Mania’s turn end: '.json_encode($reply->body));
$reply = mixtureNativePatch($combatDb, [
    'token:scene-one:token-player'=>['x'=>72,'y'=>50], 'token:scene-one:token-monster-two'=>['x'=>74,'y'=>50],
]);
requireTactical($reply->status===200,'The native map move commits while the aura is active: '.json_encode($reply->body));
$reply = mixtureNativePatch($combatDb,['initiative:scene-one'=>[...$own,'round'=>2,'turnSerial'=>3]]);
requireTactical($reply->status===200 && $combatDb->payload('activity')['attackEnhancements'][0]['remainingTurns']===4,
    'Starting Mania’s next turn does not advance her aura');
$reply = mixtureNativePatch($combatDb,['initiative:scene-one'=>[...$other,'round'=>2,'turnSerial'=>4]]);
requireTactical($reply->status===200 && $combatDb->payload('activity')['attackEnhancements'][0]['remainingTurns']===3
    && $combatDb->payload('token:scene-one:token-monster')['hp']===$enemyAfterFirstEnd
    && $combatDb->payload('token:scene-one:token-monster-two')['hp']<500, 'The next end pulse follows Mania’s new position: '.json_encode($reply->body));
$orderedDb = new MemoryConnection(array_column($db->domains,'payload','domain_key'));
$orderedDb->put('initiative:scene-one',$own);
$reply = runCommand($orderedDb,'combat.effect.periodic-hp',['requestId'=>'irradiation-order-heal-proof','sceneId'=>'scene-one','layerId'=>'ground',
    'tokenId'=>'token-monster','direction'=>1,'formula'=>'100','turns'=>1,'label'=>'Régénération de recette'],true);
requireTactical($reply->status===200,'The next unit has a start-of-turn heal: '.json_encode($reply->body));
$reply = mixtureNativePatch($orderedDb,['initiative:scene-one'=>$other]);
requireTactical($reply->status===200 && $orderedDb->payload('token:scene-one:token-monster')['hp']===$enemyMaximum
    && $orderedDb->payload('activity')['attackEnhancements'][0]['remainingTurns']===4, 'The closing irradiation pulse precedes the next turn’s heal');
for ($turn=1;$turn<=5;$turn++) {
    $reply = runCommand($db,'mechanics.command',['sceneId'=>'scene-one','command'=>'scene.advance',
        'arguments'=>['requestId'=>'irradiation-manual-proof-'.$turn]],true);
    requireTactical($reply->status===200, 'Free-play irradiation advances through the native command: '.json_encode($reply->body));
}
$remaining = $db->payload('activity')['attackEnhancements'];
$allyHp = $db->payload('character:character-gohachu')['resources']['hp'];
$enemyHp = $db->payload('token:scene-one:token-monster')['hp'];
requireTactical($remaining===[] && $allyHp>=200&&$allyHp<=380 && $enemyHp>=500&&$enemyHp<=950
    && $db->payload('character:'.MIXTURE_TEST_MANIA)['resources']['hp']===100-$exposure['damage'],
    'Five manual ticks consume the aura, bypass armor and never hit Mania again');

echo json_encode(['ok'=>true,'checks'=>$checks,'productionAccess'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
