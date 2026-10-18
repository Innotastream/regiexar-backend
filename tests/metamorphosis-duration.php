<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY', true);
require __DIR__.'/tactical-special-effects.php';
$morphChecks = 0;
function morphCheck(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); $GLOBALS['morphChecks']++; }
function requireGmIdentity(PDO $connection): array { return $GLOBALS['testIdentity']; }
function morphFixture(): MemoryConnection {
    $db = specialFixture(); $base = $db->payload('character:character-player');
    $base['resources']['hp'] = 70;
    $base['abilities'] = [['id'=>'form','name'=>'Forme fictive','effect'=>'metamorphosis','formCharacterId'=>'character-form','formDurationTurns'=>5,'formBonusHp'=>150,
        'formula'=>'0','castingStatId'=>'','manaCost'=>30,'fatigueCost'=>10,'cooldownRounds'=>3,'restRecharge'=>'long','usesPerRest'=>1]];
    $db->put('character:character-player', $base);
    $form = [...$base, 'id'=>'character-form', 'name'=>'Forme temporaire', 'abilities'=>[]];
    $form['resources'] = ['hp'=>17,'maxHp'=>50,'mana'=>19,'maxMana'=>40]; $db->put('character:character-form', $form);
    $roster = $db->payload('roster'); $roster['characterOrder'][] = 'character-form'; $db->put('roster', $roster);
    $token = synchronizeOnlineCharacterToken($db->payload('token:scene-one:token-player'), $base); $db->put('token:scene-one:token-player', $token);
    $db->put('initiative:scene-one', ['active'=>true,'combatId'=>'morph-combat','round'=>7,'turnSerial'=>10,'order'=>['token-monster','token-player'],'currentIndex'=>1]);
    return $db;
}
function morphClock(MemoryConnection $db, array $fields): TestResponse {
    $key='initiative:scene-one'; $GLOBALS['testIdentity']=['id'=>'account-gm','display_name'=>'MJ fictif','effective_mode'=>'gm','permanent_role'=>'gm'];
    $GLOBALS['testBody']=['stateSchemaVersion'=>XAR_SESSION_SCHEMA_VERSION,'domainSchemaVersion'=>XAR_DOMAIN_SCHEMA_VERSION,'changes'=>[['key'=>$key,'operation'=>'upsert','expectedRevision'=>$db->domains[$key]['revision'],'payload'=>[...$db->payload($key),...$fields]]]];
    try { patchApplicationDomains($db); } catch (TestResponse $response) { return $response; } throw new RuntimeException('No response');
}
function morphResource(MemoryConnection $db, int $delta): TestResponse { return runCommand($db,'token.resource.adjust',['requestId'=>'resource-'.randomToken(20),'sceneId'=>'scene-one','tokenId'=>'token-player','resource'=>'hp','delta'=>$delta],true); }
function morphReturn(MemoryConnection $db): TestResponse { return runCommand($db,'ability.use',['requestId'=>'return-'.randomToken(20),'sceneId'=>'scene-one','sourceTokenId'=>'token-player','abilityId'=>'','returnForm'=>true,'expectedFormRequestId'=>$db->payload('token:scene-one:token-player')['transformation']['requestId']]); }

foreach ([['damage'=>100,'heal'=>0,'hp'=>70],['damage'=>200,'heal'=>0,'hp'=>20],['damage'=>200,'heal'=>15,'hp'=>35],['damage'=>0,'heal'=>20,'hp'=>90]] as $case) {
    $db=morphFixture(); $response=specialUse($db,'form','token-player'); morphCheck($response->status===200, 'Cast: '.json_encode($response->body));
    morphCheck($db->payload('character:character-form')['resources']['hp']==220,'150 PV bonus ajoutés aux PV courants');
    if ($case['damage']) morphCheck(morphResource($db,-$case['damage'])->status===200,'Dégâts réels');
    if ($case['heal']) morphCheck(morphResource($db,$case['heal'])->status===200,'Soin réel');
    morphCheck(morphReturn($db)->status===200,'Retour manuel');
    morphCheck($db->payload('character:character-player')['resources']['hp']==$case['hp'],'PV normaux exacts : '.json_encode($case));
    morphCheck($db->payload('token:scene-one:token-player')['hp']==$case['hp'],'Pion synchronisé');
}
$db=morphFixture(); specialUse($db,'form','token-player');
for ($turn=1;$turn<=5;$turn++) {
    $clock=$db->payload('initiative:scene-one'); $r=morphClock($db,['turnSerial'=>$clock['turnSerial']+1,'currentIndex'=>0,'round'=>$clock['round']+1]);
    morphCheck($r->status===200,'Fin du tour: '.json_encode($r->body));
    $token=$db->payload('token:scene-one:token-player'); morphCheck($token['transformation']['remainingTurns']===5-$turn,'Décompte après le tour du bénéficiaire');
    morphCheck($token['characterId']===($turn===5?'character-player':'character-form'),'Retour à la fin du cinquième tour');
    if ($turn<5) { $clock=$db->payload('initiative:scene-one'); morphClock($db,['turnSerial'=>$clock['turnSerial']+1,'currentIndex'=>1]); morphCheck($db->payload('token:scene-one:token-player')['transformation']['remainingTurns']===5-$turn,'Le tour adverse ne décompte pas'); }
}
morphCheck($db->payload('character:character-player')['resources']['hp']==70,'Aucune régénération gratuite');
morphCheck($db->payload('token:scene-one:token-player')['name']===$db->payload('character:character-player')['name'],'Nom initial restauré');
$timers=$db->payload('activity')['actionTimers']; morphCheck($timers[0]['restUseCount']===1,'Usage par repos conservé');
$db=morphFixture(); specialUse($db,'form','token-player'); morphResource($db,-180); $r=morphClock($db,['active'=>false]); morphCheck($r->status===200,'Fin du combat');
morphCheck($db->payload('token:scene-one:token-player')['characterId']==='character-player','Fin du combat expire la forme temporisée');
morphCheck($db->payload('character:character-player')['resources']['hp']==40,'Fin du combat transfère les dégâts une fois');
$entry=['id'=>'test','name'=>'test','effect'=>'metamorphosis','formCharacterId'=>'character-form','formula'=>'0','castingStatId'=>''];
foreach ([-1,1.5,1001,'5'] as $duration) morphCheck(!validApplicationAbilityEffects([...$entry,'formDurationTurns'=>$duration]),'Durée invalide rejetée');
morphCheck(validApplicationAbilityEffects([...$entry,'formDurationTurns'=>5,'formBonusHp'=>150]),'Champs simples acceptés');
echo json_encode(['ok'=>true,'checks'=>$morphChecks],JSON_THROW_ON_ERROR).PHP_EOL;
