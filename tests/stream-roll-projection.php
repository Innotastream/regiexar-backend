<?php
declare(strict_types=1);
define('XAR_TACTICAL_FIXTURE_ONLY', true);
require __DIR__.'/tactical-lifecycle.php';

$db = fixture();
$character = $db->payload('character:character-player');
$character['specialSuccessStock'] = 2;
$db->put('character:character-player', $character);
$result = runCommand($db, 'token.roll', ['requestId'=>'stream-joker-regression-01', 'sceneId'=>'scene-one',
    'layerId'=>'ground', 'tokenId'=>'token-player', 'kind'=>'joker']);
requireTactical($result->status === 200, 'The authoritative Joker command succeeds.');
$state = domainsToApplicationState(applicationDomainRecords($db), $db->revision);
$jokerId = $result->body['roll']['id'];
$views = [];
foreach ([false, true] as $streamPov) {
    $view = publicPlayerState($state, ['id'=>'account-player','display_name'=>'Joueur fictif'], [], $streamPov);
    $joker = array_values(array_filter($view['rolls'], static fn($roll)=>$roll['id'] === $jokerId))[0] ?? null;
    requireTactical($joker !== null && ($joker['outcome']['joker']??false) && ($joker['outcome']['effect']??false),
        'The persisted Joker survives the complete Player/Stream projection, including its animation.');
    requireTactical(!array_key_exists('sourceTokenId',$joker)
        && !array_key_exists('tokenId',$joker['mapEvent']??[])
        && !array_key_exists('sceneId',$joker['mapEvent']??[]),
        'A globally revealed Joker keeps no private token or scene identifiers.');
    $views[$streamPov?'stream':'player'] = $view;
}
$natural = ['id'=>'natural-special-55', 'formula'=>'1d100', 'total'=>55, 'visibility'=>'public',
    'createdAt'=>date(DATE_ATOM), 'outcome'=>['raw'=>55,'result'=>55,'code'=>'special-success','label'=>'RÉUSSITE SPÉCIALE','success'=>true,'effect'=>true],
    'mapEvent'=>['kind'=>'roll','sceneId'=>'scene-one','layerId'=>'ground','tokenId'=>'token-player']];
foreach ([false,true] as $streamPov) {
    $sample = $state; $sample['rolls'] = [$natural];
    $project = static fn($value)=>publicPlayerState($value,['id'=>'account-player','display_name'=>'Joueur fictif'],[],$streamPov);
    requireTactical(count($project($sample)['rolls'])===1, 'A visible natural 55 keeps its animation.');
    $sample['rolls'][0]['visibility']='gm';
    requireTactical($project($sample)['rolls']===[], 'A private natural 55 stays private.');
    $sample['rolls'][0]=$natural; $sample['rolls'][0]['mapEvent']['sceneId']='scene-two';
    requireTactical($project($sample)['rolls']===[], 'An ordinary event on another scene is absent.');
    $sample['rolls'][0]=$natural; $sample['rolls'][0]['mapEvent']['layerId']='upper';
    requireTactical($project($sample)['rolls']===[], 'An ordinary event on another floor is absent.');
    $sample['rolls'][0]=$natural; $sample['rolls'][0]['mapEvent']['tokenId']='private-unrendered-token';
    requireTactical($project($sample)['rolls']===[], 'An unseen ordinary actor is not revealed.');
    $sample['rolls'][0]=$natural; $sample['rollRevelations']=[['rollId'=>$natural['id'],'revealedAt'=>date(DATE_ATOM)]];
    requireTactical(count($project($sample)['rolls'])===1, 'An explicitly revealed natural 55 survives anonymization.');
    $sample['rolls'][0]['mapEvent']['sceneId']='scene-two';
    requireTactical($project($sample)['rolls']===[], 'Explicit revelation does not leak another scene.');
}
echo json_encode(['ok'=>true,'checks'=>$GLOBALS['checks'],'views'=>$views], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), "\n";
