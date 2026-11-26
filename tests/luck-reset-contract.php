<?php
declare(strict_types=1);
require_once __DIR__ . '/tactical-lifecycle.php';
$db = fixture();
$db->put('character:character-second', ['id'=>'character-second','ownerPlayerId'=>'other-player','name'=>'Seconde fiche fictive','lore'=>'Récit conservé']);
$beforeCharacters = array_filter($db->domains, static fn(array $row): bool => str_starts_with($row['domain_key'], 'character:'));
$db->put('luck', ['characters'=>['character-player'=>['rollCount'=>2,'rawTotal'=>160,'startedAt'=>'2026-10-01T00:00:00Z','updatedAt'=>'2026-10-01T00:00:00Z']]]);
$request = ['requestId'=>'luck-native-reset-0001'];
foreach ([[false,'account-player',false], [false,'account-gm',true]] as [$gm,$account,$admin]) {
    $before = [$db->domains,$db->revision];
    $result = runCommand($db,'luck.reset',$request,$gm,$account,$admin);
    requireTactical($result->status===403 && [$db->domains,$db->revision]===$before, 'Player and administrator in Player mode cannot reset Chance.');
}
$result = runCommand($db,'luck.reset',$request,true,'account-gm');
requireTactical($result->status===200 && $result->body['characterCount']===2, 'Native dispatcher accepts a global reset in effective MJ mode.');
requireTactical(array_filter($db->domains, static fn(array $row): bool => str_starts_with($row['domain_key'], 'character:'))===$beforeCharacters, 'The reset preserves all character payloads and revisions.');
$records=[];$pending=[];
requireTactical(onlineCharacterLuckThreshold($db,$records,$pending,'character-player')===50 && onlineCharacterLuckThreshold($db,$records,$pending,'character-second')===50, 'Every existing sheet has threshold 50 with zero rolls.');
requireTactical(validApplicationLuckDomain($db->payload('luck')), 'The reset aggregate satisfies the strict session domain.');
onlineRecordCharacterLuckD100($db,$records,$pending,['id'=>'account-player','permanent_role'=>'player','effective_mode'=>'player'],'character-player',80);
$db->put('luck',$pending['luck']['payload']);
$before = [$db->domains,$db->revision];
$result = runCommand($db,'luck.reset',$request,true,'account-gm');
requireTactical($result->status===200 && $result->body['deduplicated']===true && [$db->domains,$db->revision]===$before, 'Replayed reset preserves later rolls and creates no second action.');
$records=[];
requireTactical(onlineCharacterLuckThreshold($db,$records,[],'character-player')===80, 'The first actual future roll starts the new average without a synthetic 50.');
requireTactical(!validApplicationLuckDomain(['characters'=>['character-player'=>['rollCount'=>0,'rawTotal'=>0,'startedAt'=>'2026-10-01T00:00:00Z','updatedAt'=>'2026-10-01T00:00:00Z']]]), 'A zero counter without the explicit reset marker stays invalid.');
$result = runCommand($db,'luck.reset',[...$request,'value'=>75],true,'account-gm');
requireTactical($result->status===400 && [$db->domains,$db->revision]===$before, 'The client cannot choose an arbitrary reset value.');
$cloneReply = runCommand($db, 'token.clone', ['sceneId'=>'scene-one','tokenId'=>'token-player','requestId'=>'luck-clone-create-0001'], true, 'account-gm');
$cloneId = $cloneReply->body['token']['id'] ?? '';
requireTactical($cloneReply->status===200 && $cloneId!=='' && unitIdForToken($cloneReply->body['token'])==='unit_'.$cloneId,
    'An actual clone is created with its own identity.');
$currentLuck = $db->payload('luck');
$rollArgs = ['sceneId'=>'scene-one','layerId'=>'ground','tokenId'=>$cloneId,'kind'=>'luck','characterId'=>'character-player','threshold'=>100];
$actors = [[false,'account-player','advantage'],[true,'account-gm','disadvantage']];
foreach ($actors as [$isGm,$account,$rollMode]) {
    $args = [...$rollArgs, 'requestId'=>'luck-clone-actual-'.$account, 'rollMode'=>$rollMode];
    $reply = runCommand($db, 'token.roll', $args, $isGm, $account);
    $roll = $reply->body['roll'] ?? [];
    $raw = $roll['outcome']['raw'] ?? 0;
    $expected = classifyOnlineD100Outcome($raw, 50);
    $attempts = array_column($roll['attempts'] ?? [], 'rawD100');
    requireTactical($reply->status===200 && $raw>=1 && $raw<=100 && $roll['formula']==='1d100' && $roll['total']===$raw
        && ($roll['outcome']['code']??null)===$expected['code'] && $roll['outcome']['threshold']===50
        && $roll['mapEvent']['tokenId']===$cloneId, 'The player and GM roll real independent Chance against 50.');
    requireTactical($roll['rollMode']===$rollMode && count($attempts)===2
        && $raw===($rollMode==='advantage' ? min($attempts) : max($attempts)), 'The existing Chance roll mode still selects the same attempt.');
    $beforeRetry = [$db->domains,$db->revision];
    $retry = runCommand($db, 'token.roll', $args, $isGm, $account);
    requireTactical($retry->status===200 && ($retry->body['deduplicated']??false) && $retry->body['roll']===$roll
        && [$db->domains,$db->revision]===$beforeRetry, 'A retried request returns the same Chance result without writes.');
}
requireTactical($db->payload('luck')===$currentLuck, 'The clone does not borrow or update the source sheet Chance history.');

$cloneLuck = ['rollCount'=>1,'rawTotal'=>37,'startedAt'=>'2026-10-01T00:00:00Z','updatedAt'=>'2026-10-01T00:00:00Z'];
$db->put('luck',['characters'=>[...$currentLuck['characters'],'unit_'.$cloneId=>$cloneLuck]]);
foreach ($actors as [$isGm,$account]) {
    $storedRoll = runCommand($db, 'token.roll', [...$rollArgs,'requestId'=>'luck-clone-stored-'.$account], $isGm, $account);
    requireTactical($storedRoll->status===200 && ($storedRoll->body['roll']['outcome']['threshold']??null)===37
        && $db->payload('luck')['characters']['unit_'.$cloneId]===$cloneLuck, 'An existing independent threshold is read without writing to the history.');
}
requireTactical($db->payload('luck')['characters']['character-player']===$currentLuck['characters']['character-player'], 'Reading a clone threshold never changes the original sheet.');

$autonomousKey = 'token:scene-one:token-monster';
$db->put($autonomousKey,[...$db->payload($autonomousKey), 'controllerPlayerId'=>'account-player',
    'followCharacter'=>false, 'cloneSourceCharacterId'=>'character-player']);
foreach ($actors as [$isGm,$account]) {
    $reply = runCommand($db, 'token.roll', ['sceneId'=>'scene-one','layerId'=>'ground','tokenId'=>'token-monster',
        'kind'=>'luck','requestId'=>'luck-autonomous-'.$account], $isGm, $account);
    $outcome = $reply->body['roll']['outcome'] ?? null;
    requireTactical($reply->status===200 && ($outcome['threshold']??null)===null,
        'An ordinary autonomous token with sheet provenance is not a clone and gets no fallback threshold.');
}

$sheetRoll = runCommand($db, 'token.roll', [...$rollArgs,'requestId'=>'luck-original-sheet-0001','tokenId'=>'token-player']);
requireTactical($sheetRoll->status===200 && ($sheetRoll->body['roll']['outcome']['threshold']??null)===80
    && $db->payload('luck')['characters']['character-player']['rollCount']===2, 'A real sheet maintains its established dynamic Chance threshold.');
$newSheet = fixture();
$ungraded = runCommand($newSheet, 'token.roll', [...$rollArgs,'requestId'=>'luck-original-ungraded-0001','tokenId'=>'token-player']);
$newRoll = $ungraded->body['roll'] ?? [];
$expected = classifyOnlineD100Outcome($newRoll['total'] ?? 0);
requireTactical($ungraded->status===200 && $newRoll['formula']==='1d100' && ($newRoll['outcome']['threshold']??null)===null
    && ($newRoll['outcome']['code']??null)===($expected['code']??null)
    && $newSheet->payload('luck')['characters']['character-player']['rollCount']===1, 'An uninitialized sheet starts its history after an ungraded first Chance roll.');

// Generate real receipts through the 0.19.29 paths, before the clone marker
// makes the same token eligible for the new threshold, then replay both IDs.
$cloneKey = 'token:scene-one:'.$cloneId;
$cloneRecord = $db->domains[$cloneKey];
$legacySource = $cloneRecord['payload'];
unset($legacySource['cloneSourceTokenId'], $legacySource['independentClone']);
$db->put($cloneKey,$legacySource,$cloneRecord['revision']);
$legacy = [];
foreach ($actors as [$isGm,$account]) {
    $args = ['sceneId'=>'scene-one','layerId'=>'ground','tokenId'=>$cloneId,'kind'=>'luck','requestId'=>'luck-legacy-receipt-'.$account];
    $reply = runCommand($db, 'token.roll', $args, $isGm, $account);
    requireTactical($reply->status===200 && ($reply->body['roll']['outcome']['threshold']??null)===null,
        'The former ungraded path persists the native receipt.');
    $legacy[] = [$isGm,$account,$args,$reply->body['roll']];
}
$db->domains[$cloneKey] = $cloneRecord;
$beforeReplay = [$db->domains,$db->revision];
foreach ($legacy as [$isGm,$account,$args,$roll]) {
    $reply = runCommand($db, 'token.roll', $args, $isGm, $account);
    requireTactical($reply->status===200 && ($reply->body['deduplicated']??false) && $reply->body['roll']===$roll
        && [$db->domains,$db->revision]===$beforeReplay, 'A receipt with the former result is replayed before new Chance rules apply.');
}
echo "Native Chance reset, clone identity and receipts passed.\n";
