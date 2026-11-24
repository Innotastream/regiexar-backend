<?php

declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY', true);
require __DIR__.'/tactical-special-effects.php';

$goblin = [
    'id'=>'token-monster', 'name'=>'Gobelin 2', 'frameVariant'=>'creature',
    'x'=>50, 'y'=>50, 'size'=>40, 'layerId'=>'ground', 'hp'=>0, 'maxHp'=>40, 'mana'=>2, 'maxMana'=>10,
    'weaponAttacks'=>[['id'=>'goblin-blade', 'name'=>'Dague', 'formula'=>'1d30', 'damageType'=>'physical']],
    'stats'=>[['id'=>'character-stat-force', 'label'=>'Force', 'value'=>'60']],
    'characterId'=>null, 'controllerPlayerId'=>null, 'linkedTokenId'=>null, 'followCharacter'=>false, 'initiative'=>18,
    'armorCategory'=>'light', 'armor'=>10, 'healthOverride'=>'dead', 'conditions'=>['Empoisonné'], 'condition'=>'Empoisonné',
    'targetTokenId'=>'token-player', 'hidden'=>true, 'libraryTemplateId'=>'old-goblin', 'fatigue'=>['current'=>40,'max'=>150],
    'independentClone'=>true, 'cloneSourceTokenId'=>'original', 'specialSuccessStock'=>3,
];
$lower = static fn(int $min, int $max): int => $min;
$upper = static fn(int $min, int $max): int => $max;
$weapons = applicationMonsterReinforcementWeapons($goblin);
$low = applicationMonsterReinforcementProfile($goblin, [], $weapons, $lower);
$high = applicationMonsterReinforcementProfile($goblin, [], $weapons, $upper);
specialCheck([$low['maxHp'],$low['damageDice'],$high['maxHp'],$high['damageDice']] === [35,'1d20+5',50,'1d30+5'], 'Goblin endpoints are inclusive.');
specialCheck($low['stats'][0]['value'] === '57' && $high['stats'][0]['value'] === '63', 'A single authored combat stat may vary slightly.');
$other = [...$goblin, 'name'=>'Gobélourd', 'maxHp'=>150, 'weaponAttacks'=>[
    ...$goblin['weaponAttacks'], ['id'=>'goblin-spear', 'name'=>'Lance', 'formula'=>'1d50+5', 'damageType'=>'physical', 'reach'=>'reach'],
]];
$varied = applicationMonsterReinforcementProfile($other, [], applicationMonsterReinforcementWeapons($other), $lower);
$ordinary = applicationMonsterReinforcementProfile($other, [], applicationMonsterReinforcementWeapons($other), $upper);
specialCheck($varied['maxHp'] === 75 && $ordinary['maxHp'] === 225, '150 base HP varies by up to 50 percent.');
foreach ([[1,1,1],[49,35,63],[50,35,65],[51,26,76],[150,75,225],[151,144,158],[1000000000,950000000,1000000000]] as [$base,$min,$max]) {
    $profile = [...$other, 'maxHp'=>$base];
    specialCheck(applicationMonsterReinforcementProfile($profile, [], $weapons, $lower)['maxHp'] === $min
        && applicationMonsterReinforcementProfile($profile, [], $weapons, $upper)['maxHp'] === $max,
        'Reinforcement HP stays within the inclusive integer bounds for '.$base.' base HP.');
}
specialCheck($varied['damageDice'] === '1d50+5' && array_column($varied['weaponAttacks'],'id') === ['goblin-spear','goblin-blade']
    && $ordinary['damageDice'] === '1d30', 'An existing alternate becomes primary on the one-in-four branch.');
specialCheck(applicationMonsterReinforcementIsGoblin('Gobelin coupe-bourse · Renfort 1', ''), 'The standard cutpurse shares the authored Gobelin range.');
specialCheck(!applicationMonsterReinforcementIsGoblin('Gobelin en cocon', 'Gobelin'), 'The cocoon variant remains separate even if it uses the Gobelin template.');

$db = specialFixture();
$sourceKey = 'token:scene-one:token-monster';
$db->put($sourceKey, $goblin);
$beforeInitiative = $db->payload('initiative:scene-one');
$beforeItems = $db->payload('items');
$beforeCount = count($db->payload('token-index:scene-one')['order']);
$request = ['sceneId'=>'scene-one','layerId'=>'ground','tokenId'=>'token-monster','requestId'=>'reinforcement-fixture-0001'];
$denied = runCommand($db, 'token.reinforce', $request);
specialCheck($denied->status === 403, 'A player cannot invoke the GM command.');
$response = runCommand($db, 'token.reinforce', $request, true, 'account-gm');
specialCheck($response->status === 200, 'The GM creates a reinforcement: '.json_encode($response->body));
$token = $response->body['token'];
$key = 'token:scene-one:'.$token['id'];
specialCheck($token['name'] === 'Gobelin 2 · Renfort 1' && $token['id'] !== $goblin['id']
    && $token['layerId'] === 'ground' && hypot($token['x']-50,$token['y']-50) >= 4, 'The arriving creature is independent and clear of the source.');
specialCheck($token['hp'] === $token['maxHp'] && $token['maxHp'] >= 35 && $token['maxHp'] <= 50
    && preg_match('/^1d(?:2[0-9]|30)\+5$/', $token['damageDice']) === 1 && $token['weaponAttacks'][0]['formula'] === $token['damageDice'],
    'Spawned Gobelin has full HP and authoritative attack dice within the requested ranges.');
specialCheck(($token['fatigue']['current'] ?? -1) === 0 && ($token['conditions'] ?? null) === [] && $token['healthOverride'] === null
    && $token['mana'] === $token['maxMana'] && $token['hidden'] === true && $token['armor'] === 10, 'Live conditions reset and the encounter configuration is retained.');
foreach (['cloneSourceTokenId','cloneSourceCharacterId','independentClone','targetTokenId','libraryTemplateId','linkedTokenId'] as $field) {
    specialCheck(!isset($token[$field]), 'The reinforcement has no copied '.$field);
}
specialCheck($token['specialSuccessStock'] === 0 && $token['fatigue'] === ['current'=>0,'max'=>150], 'The reinforcement starts without stock or fatigue.');
specialCheck($db->payload($sourceKey) === $goblin && $db->payload('initiative:scene-one') === $beforeInitiative && $db->payload('items') === $beforeItems,
    'The source, initiative and inventory are unchanged.');
specialCheck($response->body['tokenDomain'] === ['key'=>$key,'revision'=>1]
    && count($db->payload('token-index:scene-one')['order']) === $beforeCount+1, 'Token audience revision and scene index are committed.');
$snapshot = domainsToApplicationState($db->domains, 0);
foreach ([false, true] as $stream) {
    $projection = publicPlayerState($snapshot, ['id'=>'account-player','display_name'=>'Joueur'], [], $stream);
    specialCheck(!in_array($token['id'],array_column($projection['map']['tokens'],'id'),true)
        && !isset($projection['resourceReceipts']), 'A hidden reinforcement and its replay receipt remain private.');
}
$again = runCommand($db, 'token.reinforce', $request, true, 'account-gm');
specialCheck($again->status === 200 && $again->body['deduplicated'] === true && $again->body['token']['id'] === $token['id']
    && count($db->payload('token-index:scene-one')['order']) === $beforeCount+1, 'A repeated request returns the original roll and token.');
$wrongReplay = runCommand($db, 'token.reinforce', [...$request,'tokenId'=>'token-player'], true, 'account-gm');
specialCheck($wrongReplay->status === 409, 'A request reference cannot be reused for a different source.');
$second = runCommand($db, 'token.reinforce', [...$request,'tokenId'=>$token['id'],'requestId'=>'reinforcement-fixture-0002'], true, 'account-gm');
specialCheck($second->status === 200 && $second->body['token']['name'] === 'Gobelin 2 · Renfort 2', 'Another reinforcement keeps the base name without nesting suffixes.');
$preserved = preserveApplicationAbilityExtensions('activity', ['resourceReceipts'=>[]], $db->payload('activity'));
specialCheck(count($preserved['resourceReceipts']) === 2, 'A stale activity update preserves the server receipts.');
$renamed = [...$goblin, 'name'=>'Glab'];
$db->put($sourceKey, $renamed);
$db->put('library', ['tokenLibrary'=>[[...$goblin, 'id'=>'old-goblin', 'name'=>'Gobelin']]]);
$third = runCommand($db, 'token.reinforce', [...$request,'requestId'=>'reinforcement-fixture-0003'], true, 'account-gm');
specialCheck($third->status === 200 && $third->body['token']['name'] === 'Gobelin · Renfort 1'
    && $db->payload($sourceKey) === $renamed, 'A renamed Gobelin keeps the original untouched and gives its reinforcement a stable species name.');

$db = specialFixture();
$db->put($sourceKey, $goblin);
$forbiddenLayer = runCommand($db, 'token.reinforce', [...$request,'layerId'=>'upper'], true, 'account-gm');
specialCheck($forbiddenLayer->status === 409, 'A stale floor cannot spawn.');
$db->put($sourceKey, [...$goblin,'immovable'=>true]);
specialCheck(runCommand($db, 'token.reinforce', $request, true, 'account-gm')->status === 409, 'A fixed obstacle cannot be reinforced.');
$db->put($sourceKey, $goblin);
$walls = emptyApplicationWallState(1000,1000);
$walls['mask'] = rtrim(strtr(base64_encode(str_repeat("\xff", (int) ceil($walls['width']*$walls['height']/8))), '+/', '-_'), '=');
$db->put('map:scene-one', [...$db->payload('map:scene-one'), 'walls'=>$walls]);
$blocked = runCommand($db, 'token.reinforce', $request, true, 'account-gm');
specialCheck($blocked->status === 409 && count($db->payload('token-index:scene-one')['order']) === $beforeCount
    && ($db->payload('activity')['resourceReceipts'] ?? []) === [], 'A blocked floor never consumes the request or adds a token.');

echo 'Monster reinforcement PHP: '.$specialChecks." checks passed\n";
