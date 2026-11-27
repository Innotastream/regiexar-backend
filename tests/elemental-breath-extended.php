<?php
declare(strict_types=1);
define('XAR_TACTICAL_FIXTURE_ONLY',true);require __DIR__.'/tactical-lifecycle.php';
$checks=0;function extendedBreathCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);$GLOBALS['checks']++;}
function extendedBreathFixture(int $stock=3):MemoryConnection{$db=fixture();$character=$db->payload('character:character-player');$character['resources']=['hp'=>100,'maxHp'=>100,'mana'=>100,'maxMana'=>100];$character['fatigue']=['current'=>60.5,'max'=>150];$character['stats']=['force'=>50,'intelligence'=>0];$db->put('character:character-player',$character);$instances=[];for($i=0;$i<$stock;$i++)$instances[]=['id'=>'reagent-'.$i,'templateId'=>'generic-reagent','revision'=>1,'location'=>['kind'=>'inventory','characterId'=>'character-player']];$db->put('items',['version'=>1,'templates'=>[['id'=>'generic-reagent','name'=>'Composant indépendant','kind'=>'object','description'=>'Composant dépensé à chaque tentative','image'=>'']],'instances'=>$instances]);return $db;}
function extendedBreathAbility():array{return ['id'=>'generic-ingredient-skill','name'=>'Compétence hors souffle','effect'=>'healing','formula'=>'10','healingFormula'=>'10','castingStatId'=>'character-stat-intelligence','reducedFailureCooldown'=>true,'manaCost'=>7,'hpCost'=>3,'fatigueCost'=>5,'cooldownRounds'=>9,'itemRequirement'=>['templateId'=>'generic-reagent','quantity'=>2,'consumeOn'=>'attempt']];}
foreach([false,true] as $combat){
 $db=extendedBreathFixture();$initiative=['active'=>$combat,'round'=>1,'combatId'=>'combat-recipe','turnSerial'=>2,'order'=>['token-player'],'currentIndex'=>0];$db->put('initiative:scene-one',$initiative);$records=applicationDomainRecords($db);$pending=[];$source=synchronizeOnlineCharacterToken($db->payload('token:scene-one:token-player'),$db->payload('character:character-player'));$rule=extendedBreathAbility();
 $plan=onlinePrepareAbilityCasting($db,$rule,$source,'scene-one',$initiative,$db->payload('activity'));
 $cast=onlineCommitAbilityCasting($db,$records,$pending,$plan,['success'=>false,'statId'=>'character-stat-intelligence','outcome'=>['code'=>'failure','success'=>false],'roll'=>null],$source,['id'=>'account-player']);
 extendedBreathCheck((float)($cast['fatigueRefunded']??0)===5.0,'Seule la fatigue de la tentative revient : '.json_encode($cast));extendedBreathCheck($pending['character:character-player']['payload']['fatigue']['current']===60.5,'La fatigue antérieure est conservée');extendedBreathCheck($pending['character:character-player']['payload']['resources']['mana']==93,'Le mana reste dépensé');extendedBreathCheck($pending['character:character-player']['payload']['resources']['hp']==97,'Les PV restent dépensés');
 $items=$pending['items']['payload']['instances'];extendedBreathCheck(count(array_filter($items,static fn($item)=>$item['location']['kind']==='consumed'))===2,'Deux vrais composants restent consommés');extendedBreathCheck($items[0]['revision']===2&&$items[1]['revision']===2&&$items[2]['revision']===1,'Seuls les composants consommés changent de révision');extendedBreathCheck(count(array_filter($items,static fn($item)=>$item['location']['kind']==='inventory'))===1,'Le troisième exemplaire reste intact');
 $signature=applicationAbilityRequestSignature('ability.use','scene-one',['sourceTokenId'=>'token-player','abilityId'=>$rule['id'],'targetTokenId'=>'token-player'],true);$request='generic-component-attempt-'.($combat?'combat':'free');$stored=onlineStoreAbilityReceipt($records,$pending,$request,'account-player',$signature,['cast'=>$cast],'action-component-attempt');$before=$pending;$replay=onlineAbilityReceipt($db,$pending['activity']['payload'],$request,'account-player',$signature);
 extendedBreathCheck(($replay['deduplicated']??false)===true,'Le reçu retrouve la tentative sans la rejouer');extendedBreathCheck($pending===$before,'Le reçu ne rembourse ni ne consomme un composant');
}
foreach([0,1] as $stock){$db=extendedBreathFixture($stock);$before=$db->domains;$records=applicationDomainRecords($db);$pending=[];$source=synchronizeOnlineCharacterToken($db->payload('token:scene-one:token-player'),$db->payload('character:character-player'));$plan=onlinePrepareAbilityCasting($db,extendedBreathAbility(),$source,'scene-one',['active'=>true,'round'=>1],$db->payload('activity'));$failed=false;try{onlineCommitAbilityCasting($db,$records,$pending,$plan,['success'=>false,'statId'=>'character-stat-intelligence','outcome'=>['code'=>'failure','success'=>false],'roll'=>null],$source,['id'=>'account-player']);}catch(RuntimeException $error){$failed=true;}extendedBreathCheck($failed,'Quantité insuffisante refusée');extendedBreathCheck($pending===[]&&$db->domains===$before,'Le refus ne dépense rien et ne produit aucun domaine partiel');}
function extendedAreaRule():array{return ['id'=>'extended-elemental-breath','name'=>'Souffle indépendant de recette','effect'=>'damage','formula'=>'10d6','damageType'=>'magical','castingStatId'=>'','fatigueCost'=>10,'manaCost'=>20,'cooldownRounds'=>0,'restRecharge'=>'short','usesPerRest'=>2,'reach'=>'reach','rangeCells'=>4,'projectile'=>false,'damageTargeting'=>['mode'=>'area','shape'=>'cone','lengthCells'=>4,'radiusCells'=>4,'origin'=>'point','affectCaster'=>false,'affectAllies'=>true,'affectEnemies'=>true,'defenseSelection'=>'gm-agility'],'castGate'=>['version'=>1,'kind'=>'chance','blockOn'=>'critical-failure','defenseSelection'=>'gm-agility'],'itemRequirement'=>['templateId'=>'extended-infusion','quantity'=>1,'consumeOn'=>'attempt'],'damageVariants'=>[['id'=>'fire','label'=>'Feu','damageType'=>'magical']]];}
function extendedAreaFixture():MemoryConnection{
 $db=fixture();$character=$db->payload('character:character-player');$character['abilities']=[extendedAreaRule()];$character['resources']=['hp'=>1000,'maxHp'=>1000,'mana'=>100,'maxMana'=>100];$character['fatigue']=['current'=>0,'max'=>150];$db->put('character:character-player',$character);
 $source=$db->payload('token:scene-one:token-player');$source=array_replace($source,$character['resources'],['x'=>10,'y'=>50,'size'=>40,'layerId'=>'ground']);$db->put('token:scene-one:token-player',$source);
 $clone=array_replace($source,['id'=>'extended-ally-clone','name'=>'Allié indépendant du même compte','controllerPlayerId'=>'account-player','followCharacter'=>false,'usesCharacterSheet'=>false,'independentClone'=>true,'cloneSourceTokenId'=>'token-player','x'=>20,'fatigue'=>['current'=>0,'max'=>150],'abilities'=>[extendedAreaRule()],'stats'=>[['id'=>'character-stat-agility','label'=>'Agilité','value'=>'100']]]);$db->put('token:scene-one:extended-ally-clone',$clone);$db->put('token-index:scene-one',['order'=>['token-player','extended-ally-clone']]);$db->put('initiative:scene-one',['active'=>false,'round'=>1]);
 $instances=[];foreach(['character-player'=>'sheet','unit_extended-ally-clone'=>'clone'] as $unit=>$prefix)for($i=1;$i<=2;$i++)$instances[]=['id'=>$prefix.'-infusion-'.$i,'templateId'=>'extended-infusion','revision'=>1,'location'=>['kind'=>'inventory','characterId'=>$unit]];
 $db->put('items',['version'=>1,'templates'=>[['id'=>'extended-infusion','kind'=>'object','name'=>'Infusion étendue','description'=>'Composant','image'=>'']],'instances'=>$instances]);return $db;
}
function extendedAreaLaunch(MemoryConnection $db,string $source='token-player',string $request='extended-area-attempt-0001'):TestResponse{return runCommand($db,'token.attack',['sceneId'=>'scene-one','sourceTokenId'=>$source,'targetTokenId'=>$source,'attackKind'=>'ability','attackId'=>'extended-elemental-breath','targetPoint'=>['x'=>90,'y'=>50],'variantId'=>'fire','requestId'=>$request]);}
function extendedAreaFixtureChance(MemoryConnection $db,string $id,int $raw):void{
 $activity=$db->payload('activity');$outcome=classifyOnlineD100Outcome($raw,50);
 foreach($activity['pendingAttacks'] as &$attack)if($attack['id']===$id)$attack['areaGate']['chanceOutcome']=$outcome;unset($attack);
 foreach($activity['attackReceipts'] as &$receipt)if($receipt['attack']['id']===$id)$receipt['attack']['areaGate']['chanceOutcome']=$outcome;unset($receipt);
 $db->put('activity',$activity);
}
// Exercise this recipe's deterministic setup from both initial categories. The
// real critical-failure behavior and retained costs stay covered in elemental-breath.php.
foreach([100,70] as $initialChance){
 $db=extendedAreaFixture();$created=extendedAreaLaunch($db);extendedBreathCheck($created->status===200,'La préparation contrôlée doit ouvrir le tableau');$id=$db->payload('activity')['pendingAttacks'][0]['id'];
 extendedAreaFixtureChance($db,$id,$initialChance);$initial=$db->payload('activity');$expected=$initialChance===100?'critical-failure':'failure';
 extendedBreathCheck($initial['pendingAttacks'][0]['areaGate']['chanceOutcome']['code']===$expected&&$initial['attackReceipts'][0]['attack']['areaGate']['chanceOutcome']['code']===$expected,'La catégorie initiale contrôlée existe dans les deux copies');
 extendedAreaFixtureChance($db,$id,70);
 $resolved=runCommand($db,'token.attack.area.resolve',['attackId'=>$id,'decision'=>'approve','confirmed'=>true,'defenseTokenIds'=>['extended-ally-clone']],true,'account-gm');$child=null;foreach($resolved->body['areaAttacks']??[] as $attack)if($attack['targetTokenId']==='extended-ally-clone')$child=$attack;
 extendedBreathCheck($resolved->status===200&&($child['status']??'')==='awaiting-opposition','La recette neutralise aussi le reçu autoritaire après Chance '.$initialChance);
}
$db=extendedAreaFixture();$created=extendedAreaLaunch($db);extendedBreathCheck($created->status===200,'Le lanceur doit ouvrir le tableau : '.json_encode($created->body));$activity=$db->payload('activity');$gate=$activity['pendingAttacks'][0];
// This recipe targets defense/projection, independently of the random Chance category.
// Resolution reads the receipt, not the pending/display copy alone.
extendedAreaFixtureChance($db,$gate['id'],70);
$resolved=runCommand($db,'token.attack.area.resolve',['attackId'=>$gate['id'],'decision'=>'approve','confirmed'=>true,'defenseTokenIds'=>['extended-ally-clone']],true,'account-gm');extendedBreathCheck($resolved->status===200,'La table doit accepter une esquive alliée : '.json_encode($resolved->body));$child=null;foreach($resolved->body['areaAttacks']??[] as $attack)if($attack['targetTokenId']==='extended-ally-clone')$child=$attack;extendedBreathCheck(($child['status']??'')==='awaiting-opposition','L’allié du même propriétaire attend son opposition');
$state=domainsToApplicationState(applicationDomainRecords($db),$db->revision);$view=publicPlayerState($state,['id'=>'account-player','display_name'=>'Joueur'],[]);$prompt=null;foreach($view['pendingOppositions']??[] as $attack)if($attack['id']===$child['id'])$prompt=$attack;
extendedBreathCheck(is_array($prompt),'Le même compte voit l’esquive de son clone allié');extendedBreathCheck(($prompt['defenseStatId']??'')==='character-stat-agility'&&($prompt['defenseRollMode']??'')==='normal','La projection n’offre que l’Agilité normale');
$before=$db->domains;$wrongAbility=runCommand($db,'token.attack.oppose',['attackId'=>$child['id'],'requestId'=>'extended-defense-ability-forged','abilityId'=>'invented-defense','statId'=>'character-stat-force']);extendedBreathCheck($wrongAbility->status===409&&$db->domains===$before,'Une compétence de défense substituée est refusée sans effet');
$foreign=runCommand($db,'token.attack.oppose',['attackId'=>$child['id'],'requestId'=>'extended-defense-foreign','statId'=>'character-stat-agility'],false,'account-other');extendedBreathCheck($foreign->status===403&&$db->domains===$before,'Un autre compte ne peut pas esquiver pour ce clone');
$defense=runCommand($db,'token.attack.oppose',['attackId'=>$child['id'],'requestId'=>'extended-defense-forged-stat','statId'=>'character-stat-force','rollMode'=>'advantage']);extendedBreathCheck($defense->status===200,'Le propriétaire peut esquiver : '.json_encode($defense->body));extendedBreathCheck(($defense->body['attack']['opposition']['statId']??'')==='character-stat-agility'&&($defense->body['attack']['opposition']['rollMode']??'')==='normal','Statistique et mode falsifiés sont remplacés par l’Agilité normale autoritaire');

function extendedChanceDecision(MemoryConnection $db, string $id, string $decision = 'approve', array $defenders = []): TestResponse {
    return runCommand($db, 'token.attack.area.resolve',
        ['attackId'=>$id, 'decision'=>$decision, 'confirmed'=>true, 'defenseTokenIds'=>$defenders], true, 'account-gm');
}

function extendedChanceResources(MemoryConnection $db): array {
    $sheet = $db->payload('character:character-player');
    $items = array_column($db->payload('items')['instances'], null, 'id');
    return [(float) $sheet['resources']['mana'], (float) $sheet['fatigue']['current'],
        $items['sheet-infusion-1']['location']['kind'], $items['sheet-infusion-2']['location']['kind']];
}

foreach ([
    'position dans le cône'=>['x'=>21, 'y'=>51, 'size'=>45],
    'sortie du cône'=>['x'=>90],
    'pion masqué'=>['hidden'=>true],
    'nouveau contrôleur'=>['controllerPlayerId'=>'account-other'],
    'défenseur KO'=>['hp'=>0],
] as $scenario => $change) {
    $db = extendedAreaFixture();
    $launch = extendedAreaLaunch($db);
    $gate = $db->payload('activity')['pendingAttacks'][0] ?? [];
    extendedBreathCheck($launch->status === 200 && ($gate['areaGate']['targetTokenIds'] ?? []) === ['extended-ally-clone'],
        'Le lanceur prépare sa seule cible : ' . $scenario);
    extendedAreaFixtureChance($db, $gate['id'], 70);
    $key = 'token:scene-one:extended-ally-clone';
    $db->put($key, array_replace($db->payload($key), $change));
    $paid = [80.0, 10.0, 'consumed', 'inventory'];
    extendedBreathCheck(extendedChanceResources($db) === $paid, 'Le lancement coûte une seule potion, 20 mana et 10 fatigue');
    $before = $db->domains;
    $resolved = extendedChanceDecision($db, $gate['id'], 'approve', ['extended-ally-clone']);
    if ($scenario === 'position dans le cône') {
        $children = $resolved->body['areaAttacks'] ?? [];
        extendedBreathCheck($resolved->status === 200 && count($children) === 1
            && $children[0]['targetTokenId'] === 'extended-ally-clone' && $children[0]['status'] === 'awaiting-opposition',
            'L’allié peut se déplacer et changer de taille dans le cône avant son esquive : ' . json_encode($resolved->body));
        extendedBreathCheck($db->payload($key)['hp'] === 1000 && extendedChanceResources($db) === $paid,
            'Aucun dégât avant l’esquive et aucun deuxième débit');
        $committed = $db->domains;
        $replay = extendedChanceDecision($db, $gate['id'], 'approve', ['extended-ally-clone']);
        extendedBreathCheck($replay->status === 200 && ($replay->body['deduplicated'] ?? false)
            && $db->domains === $committed, 'Le rejeu de confirmation ne crée ni frappe ni débit');
        continue;
    }
    extendedBreathCheck($resolved->status === 409 && $db->domains === $before,
        'La confirmation échoue sans effet en cas de ' . $scenario . ' : ' . json_encode($resolved->body));
    $rejected = extendedChanceDecision($db, $gate['id'], 'reject');
    extendedBreathCheck($rejected->status === 200 && ($rejected->body['attack']['status'] ?? '') === 'rejected'
        && ($db->payload('activity')['pendingAttacks'] ?? []) === [] && extendedChanceResources($db) === $paid,
        'Le MJ ferme la tentative sans dégâts ni remboursement hors échec critique : ' . $scenario);
}

foreach ([false, true] as $revealBeforeApproval) {
    $db = extendedAreaFixture();
    $db->put('token-index:scene-one', ['order'=>['token-player', 'extended-ally-clone', 'token-monster']]);
    $enemyKey = 'token:scene-one:token-monster';
    $db->put($enemyKey, array_replace($db->payload($enemyKey), ['x'=>17, 'y'=>50]));
    $map = $db->payload('map:scene-one');
    $bytes = str_repeat("\0", 512);
    for ($row = 29; $row <= 34; $row++) {
        for ($col = 9; $col <= 12; $col++) applicationSetMaskBit($bytes, $row * 64 + $col, true);
    }
    $fog = ['version'=>1, 'enabled'=>true, 'width'=>64, 'height'=>64,
        'mask'=>rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=')];
    $db->put('map:scene-one', [...$map, 'fog'=>$fog]);
    $launch = extendedAreaLaunch($db);
    $gate = $db->payload('activity')['pendingAttacks'][0] ?? [];
    extendedBreathCheck($launch->status === 200 && ($gate['areaGate']['targetTokenIds'] ?? []) === ['extended-ally-clone'],
        'Le monstre sous le brouillard peint reste absent de l’aperçu du lanceur');
    extendedAreaFixtureChance($db, $gate['id'], 70);
    if ($revealBeforeApproval) $db->put('map:scene-one', $map);
    $before = $db->domains;
    $resolved = extendedChanceDecision($db, $gate['id'], 'approve', ['extended-ally-clone']);
    if ($revealBeforeApproval) {
        extendedBreathCheck($resolved->status === 409 && $db->domains === $before,
            'Un monstre devenu visible impose de préparer de nouveau le cône');
    } else {
        extendedBreathCheck($resolved->status === 200
            && array_column($resolved->body['areaAttacks'] ?? [], 'targetTokenId') === ['extended-ally-clone']
            && $db->payload($enemyKey)['hp'] === 40,
            'Le MJ confirme avec le point de vue du lanceur, sans ajouter un monstre sous le brouillard');
    }
}

$db = extendedAreaFixture();
$source = $db->payload('token:scene-one:token-player');
$db->put('token:scene-one:token-player', [...$source, 'x'=>70]);
$launch = extendedAreaLaunch($db);
$gate = $db->payload('activity')['pendingAttacks'][0] ?? [];
extendedBreathCheck($launch->status === 200 && ($gate['areaGate']['targetTokenIds'] ?? null) === [],
    'Le lanceur n’est pas désigné lorsque le cône est vide');
extendedAreaFixtureChance($db, $gate['id'], 70);
$resolved = extendedChanceDecision($db, $gate['id']);
extendedBreathCheck($resolved->status === 200 && ($resolved->body['areaAttacks'] ?? null) === []
    && $db->payload('character:character-player')['resources']['hp'] === 1000, 'Un cône vide se termine sans infliger de dégâts');

$db=extendedAreaFixture();$source=$db->payload('token:scene-one:token-player');$source['x']=70;$db->put('token:scene-one:token-player',$source);$clone=$db->payload('token:scene-one:extended-ally-clone');$clone['x']=10;$db->put('token:scene-one:extended-ally-clone',$clone);$sheetBefore=$db->payload('character:character-player');$created=extendedAreaLaunch($db,'extended-ally-clone','extended-clone-attempt-0001');extendedBreathCheck($created->status===200,'Le clone peut lancer avec son propre stock : '.json_encode($created->body));
extendedBreathCheck($db->payload('character:character-player')===$sheetBefore,'La fiche modèle et ses ressources sont intactes');$clone=$db->payload('token:scene-one:extended-ally-clone');extendedBreathCheck($clone['mana']==80&&$clone['fatigue']['current']==10,'Seuls les coûts du clone sont payés');$instances=array_column($db->payload('items')['instances'],null,'id');extendedBreathCheck($instances['clone-infusion-1']['location']['kind']==='consumed'&&$instances['clone-infusion-2']['location']['kind']==='inventory','Un seul composant propre au clone est consommé');extendedBreathCheck($instances['sheet-infusion-1']['location']['kind']==='inventory'&&$instances['sheet-infusion-1']['revision']===1&&$instances['sheet-infusion-2']['revision']===1,'Les deux composants du personnage restent intacts');
$before=$db->domains;$replay=extendedAreaLaunch($db,'extended-ally-clone','extended-clone-attempt-0001');extendedBreathCheck(($replay->body['deduplicated']??false)&&$db->domains===$before,'Le reçu du clone ne repaie aucun composant ni ressource');$timers=$db->payload('activity')['actionTimers'];extendedBreathCheck(count(array_filter($timers,static fn($timer)=>($timer['tokenId']??'')==='extended-ally-clone'&&($timer['restUseCount']??0)===1))===1&&count(array_filter($timers,static fn($timer)=>($timer['characterId']??'')==='character-player'))===0,'Le quota de repos appartient au clone uniquement');
echo json_encode(['ok'=>true,'checks'=>$checks,'productionAccess'=>false],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n";
