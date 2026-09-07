<?php

declare(strict_types=1);

foreach ([[null,8],['',8],[false,8],[0,1],[-2,1],[7.6,8],[40.9,40],[12,12]] as [$raw,$expected]) {
    requireTactical(normalizeApplicationVisionDistance($raw) === $expected, 'Individual vision normalizes to a bounded finite stat.');
}
requireTactical(playerCharacterPatch([],[])['visionDistance'] === 8, 'New player sheets default to eight cells.');
requireTactical(!validApplicationCharacterDomain(['visionDistance'=>41]) && !validApplicationTokenDomain(['visionDistance'=>-1]), 'Invalid vision stats cannot enter MJ domains.');
requireTactical(onlineTokenVisionDistance(['visionDistance'=>3],['visionDistance'=>12]) === 12 && onlineTokenVisionDistance(['visionDistance'=>3,'followCharacter'=>false],['visionDistance'=>12]) === 3, 'Sheets are authoritative for ordinary tokens, while independent clones keep their vision.');
$db = fixture();
$response = runCommand($db,'character.patch',['characterId'=>'character-player','patch'=>['visionDistance'=>12]]);
requireTactical($response->status === 200 && $db->payload('character:character-player')['visionDistance'] === 12, 'A player can save the individual vision stat.');
requireTactical($db->payload('token:scene-one:token-player')['visionDistance'] === 12 && $db->payload('token:scene-two:token-copy')['visionDistance'] === 12 && !isset($db->payload('token:scene-one:token-independent')['visionDistance']), 'Vision converges across scenes without modifying independent tokens.');
requireTactical(runCommand($db,'character.patch',['characterId'=>'character-player','patch'=>['visionDistance'=>1]],false,'intruder')->status === 403, 'A different account cannot alter a sheet vision.');

$db = fixture();
$response = runCommand($db,'token.resource.adjust',['sceneId'=>'scene-one','tokenId'=>'token-player','resource'=>'hp','delta'=>-2,'requestId'=>'manual-hp-request-0001']);
$pulse = $db->payload('token:scene-one:token-player')['resourcePulse'] ?? null;
requireTactical($response->status === 200 && $pulse['delta'] === -2, 'Manual HP loss publishes an effective resource pulse.');
requireTactical(($db->payload('token:scene-two:token-copy')['resourcePulse'] ?? null) === $pulse && !isset($db->payload('token:scene-one:token-independent')['resourcePulse']), 'One pulse reaches all synchronized scene tokens, excluding clones.');
$response = runCommand($db,'token.resource.adjust',['sceneId'=>'scene-one','tokenId'=>'token-player','resource'=>'hp','delta'=>5,'requestId'=>'manual-hp-request-0002']);
requireTactical($response->status === 200 && $db->payload('token:scene-two:token-copy')['resourcePulse']['delta'] === 5 && $db->payload('token:scene-two:token-copy')['resourcePulse']['id'] !== $pulse['id'], 'Healing generates a new positive pulse across scenes.');
$db->beginTransaction(); $records=$db->domains; $pending=[];
$damage=applyOnlineAttackDamage($db,$records,$pending,'token:scene-one:token-player',$db->payload('token:scene-one:token-player'),2);
requireTactical($pending['token:scene-two:token-copy']['payload']['resourcePulse'] === $pending['token:scene-one:token-player']['payload']['resourcePulse'], 'Applied attack damage also animates the other synchronized scene tokens.');
$db->rollBack();

$db = fixture();
$db->put('map:scene-one',['activeLayerId'=>'ground','viewLocked'=>false,'naturalWidth'=>1600,'naturalHeight'=>900]);
$monster = $db->payload('token:scene-one:token-monster');$monster['layerId']='upper';$db->put('token:scene-one:token-monster',$monster);
$request=['sceneId'=>'scene-one','targetLayerId'=>'basement','mapRevision'=>1,'tokens'=>[['id'=>'token-monster','x'=>50,'y'=>50,'layerId'=>'upper']]];
$initial=$db->domains;
requireTactical(runCommand($db,'tokens.layers',$request)->status === 403 && $db->domains === $initial, 'Players cannot use the MJ quick layer command.');
$response=runCommand($db,'tokens.layers',$request,true);
requireTactical($response->status === 200 && $db->payload('token:scene-one:token-monster')['layerId'] === 'basement', 'An MJ brings a token from an inactive floor with the map unlocked.');
requireTactical($db->payload('map:scene-one')['activeLayerId'] === 'ground' && $response->body['tokenDomains'][0]['key'] === 'token:scene-one:token-monster' && isset($response->body['revision']), 'Layer transfer preserves the current view and returns authoritative domain revisions.');
$initial=$db->domains;$revision=$db->revision;
requireTactical(runCommand($db,'tokens.layers',$request,true,'other-gm')->status === 409 && $db->domains === $initial, 'Two MJ commands based on the same old floor cannot apply twice.');
$request['tokens'][0]['layerId']='basement';
requireTactical(runCommand($db,'tokens.layers',$request,true)->status === 200 && $db->revision === $revision && $db->domains === $initial, 'A token already on the destination floor remains an exact no-op.');
$request['targetLayerId']='upper';$request['tokens'][]=['id'=>'missing','x'=>20,'y'=>50,'layerId'=>'ground'];
requireTactical(runCommand($db,'tokens.layers',$request,true)->status === 409 && $db->domains === $initial, 'An invalid member refuses the whole transfer before any mutation.');
$request['tokens']=[$request['tokens'][0]];$request['mapRevision']=0;
requireTactical(runCommand($db,'tokens.layers',$request,true)->status === 409 && $db->domains === $initial, 'Changed walls or map revision refuse quick transfers.');

// Destination tokens are occupied, including selected tokens that stay in place.
$map=['activeLayerId'=>'ground','naturalWidth'=>1000,'naturalHeight'=>1000,'layers'=>['upper'=>['naturalWidth'=>1000,'naturalHeight'=>1000]],'tokens'=>[
    ['id'=>'moving','x'=>50,'y'=>50,'size'=>50,'layerId'=>'basement'],
    ['id'=>'staying','x'=>50,'y'=>50,'size'=>50,'layerId'=>'upper'],
]];
$placements=planApplicationTokenLayers($map,['targetLayerId'=>'upper','tokens'=>[['id'=>'moving','x'=>50,'y'=>50,'layerId'=>'basement'],['id'=>'staying','x'=>50,'y'=>50,'layerId'=>'upper']]]);
requireTactical($placements[0]['relocated'] && !$placements[1]['relocated'] && hypot(($placements[0]['x']-50)*10,($placements[0]['y']-50)*10) >= 50.1, 'Transfer relocates beside an occupied destination and preserves the token already there.');

$db = fixture();$db->put('map:scene-one',['activeLayerId'=>'upper']);
$initial=$db->domains;
requireTactical(runCommand($db,'ping',['sceneId'=>'scene-two','layerId'=>'upper','x'=>20,'y'=>20],true)->status === 409 && $db->domains === $initial, 'A delayed ping from another scene is fenced.');
requireTactical(runCommand($db,'ping',['sceneId'=>'scene-one','layerId'=>'ground','x'=>20,'y'=>20],true)->status === 409 && $db->domains === $initial, 'A delayed ping from another level is fenced.');
$response=runCommand($db,'ping',['sceneId'=>'scene-one','layerId'=>'upper','x'=>20,'y'=>20],true);
requireTactical($response->status === 200 && $response->body['ping']['layerId'] === 'upper', 'Pings are stamped with the authoritative level.');
$now=(int)floor(microtime(true)*1000);
$state=['activeSceneId'=>'scene-one','map'=>['activeLayerId'=>'upper','tokens'=>[]],'initiative'=>[], 'mapPings'=>[
    ['id'=>'visible','sceneId'=>'scene-one','layerId'=>'upper','x'=>20,'y'=>20,'createdAt'=>$now,'expiresAt'=>$now+4200],
    ['id'=>'other-floor','sceneId'=>'scene-one','layerId'=>'ground','x'=>20,'y'=>20,'createdAt'=>$now,'expiresAt'=>$now+4200],
    ['id'=>'legacy','sceneId'=>'scene-one','x'=>20,'y'=>20,'createdAt'=>$now,'expiresAt'=>$now+4200],
]];
$projected=publicPlayerState($state,['id'=>'account-player','display_name'=>'Player'],[]);
requireTactical(array_column($projected['mapPings'],'id') === ['visible'] && $projected['mapPings'][0]['createdAt'] === $now, 'Projection filters other and legacy floors and retains ping age.');

// Fixed-radius geometric fixtures use real JS-generated masks and byte hashes for PHP parity.
$cases=json_decode(file_get_contents(__DIR__.'/vision-render-geometry.json'),true,512,JSON_THROW_ON_ERROR);
foreach($cases as $case) {
    $actual=applicationComputeVisionRenderMask($case['occlusion'],$case['origins'],$case['gridSize']);
    requireTactical(hash('sha256',base64_decode(strtr($actual['mask'],'-_','+/'))) === $case['strictSha256'], $case['name'].': PHP strict visibility matches JavaScript.');
    requireTactical(hash('sha256',base64_decode(strtr($actual['opacity'],'-_','+/'))) === $case['opacitySha256'], $case['name'].': PHP progressive darkening matches JavaScript byte for byte.');
}
$occlusion=$cases[0]['occlusion'];$origins=$cases[0]['origins'];
$render=applicationComputeVisionRenderMask($occlusion,$origins,50);
$alpha=base64_decode(strtr($render['opacity'],'-_','+/'));
$partial=0;$strictHiddenPartial=0;
$strictBytes=base64_decode(strtr($render['mask'],'-_','+/'));
for($index=0;$index<strlen($alpha);++$index) if(ord($alpha[$index])>0 && ord($alpha[$index])<255) {++$partial;if(applicationMaskBit($strictBytes,$index)) ++$strictHiddenPartial;}
requireTactical($partial>0 && $partial===$strictHiddenPartial, 'The darkening halo never extends authoritative token visibility.');

$map=['activeLayerId'=>'ground','naturalWidth'=>3200,'naturalHeight'=>3200,'gridSize'=>50,'vision'=>['version'=>1,'enabled'=>true,'distance'=>40,'shared'=>false,'isolatedPlayerIds'=>[]], 'tokens'=>[
    ['id'=>'observer','characterId'=>'hero','controllerPlayerId'=>'account-player','x'=>25,'y'=>50,'visionDistance'=>40],
    ['id'=>'inside','x'=>35,'y'=>50],['id'=>'halo','x'=>40,'y'=>50],['id'=>'other-floor','x'=>27,'y'=>50,'layerId'=>'upper'],
]];
$state=['activeSceneId'=>'scene-one','map'=>$map,'initiative'=>[],'characters'=>[['id'=>'hero','ownerPlayerId'=>'account-player','visionDistance'=>8]],'rolls'=>[
    ['id'=>'roll-inside','visibility'=>'public','mapEvent'=>['kind'=>'roll','sceneId'=>'scene-one','tokenId'=>'inside']],
    ['id'=>'roll-halo','visibility'=>'public','mapEvent'=>['kind'=>'roll','sceneId'=>'scene-one','tokenId'=>'halo']],
    ['id'=>'roll-floor','visibility'=>'public','mapEvent'=>['kind'=>'roll','sceneId'=>'scene-one','tokenId'=>'other-floor']],
]];
$view=publicPlayerState($state,['id'=>'account-player','display_name'=>'Player'],[]);
requireTactical(array_column($view['map']['tokens'],'id') === ['observer','inside'] && $view['map']['tokens'][0]['visionDistance'] === 8, 'The sheet radius overrides an old global/token distance; neither halo tokens nor other levels are projected.');
requireTactical(array_column($view['rolls'],'id') === ['roll-inside'], 'Ordinary dice without attack ids cannot disclose names in the halo or another floor.');

$db=fixture();$map=['activeLayerId'=>'ground','viewLocked'=>false,'naturalWidth'=>1000,'naturalHeight'=>1000,'layers'=>['upper'=>['naturalWidth'=>1000,'naturalHeight'=>1000,'walls'=>['version'=>1,'width'=>32,'height'=>32,'mask'=>rtrim(strtr(base64_encode(str_repeat("\xff",128)),'+/','-_'),'=')]]]];
$db->put('map:scene-one',$map);$initial=$db->domains;
$request=['sceneId'=>'scene-one','targetLayerId'=>'upper','mapRevision'=>1,'tokens'=>[['id'=>'token-player','x'=>20,'y'=>50,'layerId'=>'ground'],['id'=>'token-monster','x'=>50,'y'=>50,'layerId'=>'ground']]];
requireTactical(runCommand($db,'tokens.layers',$request,true)->status === 409 && $db->domains === $initial, 'An entirely blocked destination fails within the search bound and leaves the whole group untouched.');

$state=['activeSceneId'=>'scene-one','map'=>['activeLayerId'=>'ground','tokens'=>[
    ['id'=>'target','controllerPlayerId'=>'account-player','x'=>50,'y'=>50],['id'=>'source','layerId'=>'upper','x'=>50,'y'=>50],
]],'initiative'=>[],'pendingAttacks'=>[['id'=>'pending-hidden-source','sceneId'=>'scene-one','sourceTokenId'=>'source','sourceName'=>'Secret','targetTokenId'=>'target','status'=>'awaiting-opposition','attackerRole'=>'gm','accountId'=>'gm','hit'=>['raw'=>1]]]];
$view=publicPlayerState($state,['id'=>'account-player','display_name'=>'Player'],[]);
requireTactical($view['pendingOppositions'] === [] && $view['pendingMapAttacks'] === [], 'A pending opposition cannot leak the name or dice of an attacker moved to another floor.');
