<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);
require __DIR__.'/tactical-special-effects.php';
$specialChecks=0;
const VENOM_ABILITY='ability-93fa3849-3fbf-43fa-84cb-0c0c9d7f4189';
$db=specialFixture();
$sheet=$db->payload('character:character-player');$sheet['specialSuccessStock']=10;$sheet['stats']['force']=100;
$sheet['weaponText']="Hallebarde / 1d2\nPattes / 1d2\nTir / 1d2\nLame magique / 1d2";
$sheet['weaponAttacks']=[['id'=>'weapon-1','formula'=>'1d2','damageType'=>'physical','reach'=>'reach'],['id'=>'weapon-2','formula'=>'1d2','damageType'=>'physical','naturalWeapon'=>true,'reach'=>'melee'],['id'=>'shot','formula'=>'1d2','damageType'=>'physical','projectile'=>true,'reach'=>'ranged'],['id'=>'magic','formula'=>'1d2','damageType'=>'magical','reach'=>'melee']];
$legacy=['id'=>VENOM_ABILITY,'name'=>'Venin fictif','effect'=>'enhancement','formula'=>'0','castingStatId'=>'','fatigueCost'=>10,'manaCost'=>0,'cooldownRounds'=>0,'usesPerRest'=>1,'restRecharge'=>'long','attackEnhancement'=>['mode'=>'on-attack-dot','turns'=>5,'dot'=>['label'=>'Poison mortel','formula'=>'1d20','turns'=>5]]];
$sheet['abilities']=[$legacy,['id'=>'spell','name'=>'Sort fictif','effect'=>'damage','formula'=>'1d2','damageType'=>'magical','castingStatId'=>'character-stat-force'],['id'=>'combat','name'=>'Compétence de combat fictive','effect'=>'damage','formula'=>'1d2','damageType'=>'physical','useWeaponDamage'=>true,'weaponDamageMode'=>'replace','castingStatId'=>'character-stat-force']];
$db->put('character:character-player',$sheet);
$target=$db->payload('token:scene-one:token-monster');$target['hp']=$target['maxHp']=1000;$db->put('token:scene-one:token-monster',$target);
$normalized=normalizeOnlineAbilities([$legacy])[0];specialCheck($normalized['attackEnhancement']['trigger']==='melee-weapon','Known legacy sheet gets the melee trigger');specialCheck($normalized['attackEnhancement']['weaponIds']===['weapon-2'],'Only the configured legs attack is authorized');
$normalized=normalizeOnlineAbilities([$legacy])[0];specialCheck($normalized['attackEnhancement']['dot']['formula']==='1d20+2','The historical poison formula is upgraded');
$custom=$legacy;$custom['attackEnhancement']['trigger']='all-attacks';$custom['attackEnhancement']['turns']=7;$custom['attackEnhancement']['dot']['formula']='2d6+1';
$normalized=normalizeOnlineAbilities([$custom])[0];specialCheck($normalized['attackEnhancement']['trigger']==='all-attacks'&&$normalized['attackEnhancement']['turns']===7&&$normalized['attackEnhancement']['dot']['formula']==='2d6+1','Explicit later settings are preserved');
$result=specialUse($db,VENOM_ABILITY,'token-player');specialCheck($result->status===200,'Activation succeeds: '.json_encode($result->body));specialCheck($db->payload('character:character-player')['fatigue']['current']===10,'Activation spends ten fatigue exactly once');
$strike=static fn(array $options)=>runCommand($db,'token.attack',['requestId'=>'venom-'.randomToken(20),'sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','attackKind'=>'weapon','attackId'=>'weapon-2','statId'=>'character-stat-force','opposed'=>false,'useJoker'=>true,...$options]);
foreach([['attackKind'=>'ability','attackId'=>'spell','echoAttackContext'=>['weaponBased'=>true,'projectile'=>false]],['attackId'=>'shot'],['attackId'=>'magic'],['attackId'=>'weapon-1','echoAttackContext'=>['weaponId'=>'weapon-2','weaponBased'=>true,'naturalWeapon'=>true,'projectile'=>false]],['attackKind'=>'ability','attackId'=>'combat','weaponAttackId'=>'weapon-1']] as $options){$result=$strike($options);specialCheck($result->status===200,'Non-contact attack resolves: '.json_encode($result->body));specialCheck(count($db->payload('activity')['damageOverTime'])===0,'Spell, projectile or purely magical weapon cannot apply poison');}
$result=$strike(['attackKind'=>'ability','attackId'=>'combat','weaponAttackId'=>'weapon-2']);specialCheck($result->status===200,'Physical combat ability resolves: '.json_encode($result->body));
$activity=$db->payload('activity');specialCheck(count($activity['damageOverTime'])===1,'Physical combat ability applies one charge');specialCheck(($activity['damageOverTime'][0]['sourceAbilityId']??'')===VENOM_ABILITY,'Charge preserves its originating configured ability');
$result=$strike([]);specialCheck($result->status===200&&count($db->payload('activity')['damageOverTime'])===1,'Second contact on same turn does not add a charge');
$clock=$db->payload('initiative:scene-one');$clock['turnSerial']++;$db->put('initiative:scene-one',$clock);$result=$strike([]);specialCheck($result->status===200&&count($db->payload('activity')['damageOverTime'])===2,'Next turn adds a separate five-turn charge');
$state=domainsToApplicationState($db->domains,0);$source=$state['map']['tokens'][0];$target=array_values(array_filter($state['map']['tokens'],static fn($row)=>$row['id']==='token-monster'))[0];
$attack=['id'=>'duplicate','sceneId'=>'scene-one','status'=>'blocked','hit'=>['outcome'=>['success'=>true]],'damageType'=>'physical','echoAttackContext'=>['weaponId'=>'weapon-2','weaponBased'=>true,'projectile'=>false,'reach'=>'melee']];$state['initiative']['turnSerial']++;
applicationAttackPoisonStacks($state,$attack,$source,$target,static fn($prefix)=>$prefix.'-native-'.randomToken(12));specialCheck(count($attack['poisonApplications']??[])===1,'Only a real stack records its trusted injection');
$count=count($state['damageOverTime']);applicationAttackPoisonStacks($state,$attack,$source,$target,static fn($prefix)=>$prefix.'-native-'.randomToken(12));specialCheck(count($state['damageOverTime'])===$count,'An existing receipt never applies poison twice');

$db = specialFixture();
$sheet['specialSuccessStock'] = 30;
$sheet['weaponAttacks'][0]['maxAttacksPerTurn'] = 1;
$sheet['weaponAttacks'][1]['maxAttacksPerTurn'] = 1;
$db->put('character:character-player', $sheet);
$baseStrike = static function (MemoryConnection $db, string $weaponId='weapon-2', string $sourceId='token-player'): TestResponse {
    return runCommand($db, 'token.attack', ['requestId'=>'paw-quota-'.randomToken(16),
        'sceneId'=>'scene-one','sourceTokenId'=>$sourceId,'targetTokenId'=>'token-monster','attackKind'=>'weapon',
        'attackId'=>$weaponId,'statId'=>'character-stat-force','opposed'=>false,'useJoker'=>true], true, 'account-gm');
};
$storedQuota = static fn (MemoryConnection $db, string $weaponId, string $unitId='character-player'): array => array_values(array_filter(
    $db->payload('activity')['actionTimers'], static fn (array $timer): bool =>
        ($timer['kind'] ?? '') === 'weapon-turn-limit' && $timer['weaponId'] === $weaponId && $timer['unitId'] === $unitId
));
specialCheck($baseStrike($db)->status === 200 && $baseStrike($db)->status === 409, 'The ordinary paw limit allows one attempt per turn');
$oldQuota = $storedQuota($db, 'weapon-2');
specialCheck(specialUse($db, VENOM_ABILITY, 'token-player')->status === 200, 'Perfidious Poison can be activated after the normal paw quota is spent');
$activity = $db->payload('activity');
$legacyEffect = $activity['attackEnhancements'][0];
$legacyEffect['dot']['formula'] = '1d20';
unset($legacyEffect['weaponIds'], $legacyEffect['trigger']);
$activity['attackEnhancements'] = [$legacyEffect];
$db->put('activity', $activity);
specialCheck($baseStrike($db)->status === 200 && $baseStrike($db)->status === 200
    && $storedQuota($db, 'weapon-2') === $oldQuota, 'Buffed attacks ignore the existing quota without rewriting or adding to it');
$activity = $db->payload('activity');
specialCheck(count($activity['damageOverTime']) === 1 && $activity['damageOverTime'][0]['formula'] === '1d20+2'
    && $activity['damageOverTime'][0]['damageType'] === 'poison', 'An active buff from the old version deals the new formula, once per target and turn');
specialCheck($baseStrike($db, 'weapon-1')->status === 200 && $baseStrike($db, 'weapon-1')->status === 409
    && count($db->payload('activity')['damageOverTime']) === 1, 'Halberd keeps its own restriction and does not convey poison');

$cloneResult = runCommand($db, 'token.clone', ['requestId'=>'paw-clone-'.randomToken(16), 'sceneId'=>'scene-one',
    'tokenId'=>'token-player'], true, 'account-gm');
specialCheck($cloneResult->status === 200, 'The cloning command creates a separate unit: ' . json_encode($cloneResult->body));
$clone = $cloneResult->body['token'];
$cloneKey = 'token:scene-one:'.$clone['id'];
$db->put($cloneKey, [...$db->payload($cloneKey),'x'=>45,'y'=>50,'specialSuccessStock'=>10]);
specialCheck($baseStrike($db, 'weapon-2', $clone['id'])->status === 200 && $baseStrike($db, 'weapon-2', $clone['id'])->status === 409
    && $storedQuota($db, 'weapon-2') === $oldQuota, 'A clone has a separate quota and never borrows the original buff');
$cloneBuff = runCommand($db, 'ability.use', ['requestId'=>'paw-clone-buff-'.randomToken(16),
    'sceneId'=>'scene-one','sourceTokenId'=>$clone['id'],'targetTokenId'=>$clone['id'],'abilityId'=>VENOM_ABILITY], true, 'account-gm');
specialCheck($cloneBuff->status === 200 && $baseStrike($db, 'weapon-2', $clone['id'])->status === 200,
    'A clone with its own activation can suspend its own quota');
$effectId = $legacyEffect['id'];
$removed = runCommand($db, 'combat.effect.remove', ['kind'=>'enhancement','effectId'=>$effectId,
    'requestId'=>'paw-remove-'.randomToken(16)], true, 'account-gm');
specialCheck($removed->status === 200 && $baseStrike($db)->status === 409
    && $baseStrike($db, 'weapon-2', $clone['id'])->status === 200, 'Removing the original buff in the same turn restores its unchanged exhausted quota and leaves the clone buff active');
$initiative = $db->payload('initiative:scene-one');
$db->put('initiative:scene-one', [...$initiative, 'turnSerial'=>$initiative['turnSerial'] + 1]);
$cloneEffect = $db->payload('activity')['attackEnhancements'][0];
$fromPriorTurn = $storedQuota($db, 'weapon-2', 'unit_'.$clone['id']);
specialCheck($baseStrike($db, 'weapon-2', $clone['id'])->status === 200
    && $storedQuota($db, 'weapon-2', 'unit_'.$clone['id']) === $fromPriorTurn, 'The final buffed attempts create no debt for the next available ordinary attack');
$removed = runCommand($db, 'combat.effect.remove', ['kind'=>'enhancement','effectId'=>$cloneEffect['id'],
    'requestId'=>'paw-clone-remove-'.randomToken(16)], true, 'account-gm');
specialCheck($removed->status === 200 && $baseStrike($db, 'weapon-2', $clone['id'])->status === 200
    && $baseStrike($db, 'weapon-2', $clone['id'])->status === 409, 'Expired older quota rolls over naturally when the clone buff ends');
$db->put('initiative:scene-one', [...$initiative, 'active'=>false]);
specialCheck($baseStrike($db)->status === 200 && $baseStrike($db)->status === 200, 'Free play still permits paw attacks without the combat quota');
fwrite(STDOUT,json_encode(['ok'=>true,'checks'=>$specialChecks],JSON_UNESCAPED_UNICODE)."\n");
