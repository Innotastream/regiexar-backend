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
echo "Native Chance reset contract passed.\n";
