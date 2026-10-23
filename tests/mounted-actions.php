<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);
require __DIR__.'/tactical-special-effects.php';

foreach([false,true] as $independent){
    $db=specialFixture();$c=$db->payload('character:character-player');
    $weapon=['id'=>'main','name'=>'Arme personnelle','formula'=>'1d2+6','damageType'=>'ignore','reach'=>'melee'];
    $c['weaponAttacks']=[$weapon];$c['weaponText']='Arme personnelle / 1d2+6';$c['specialSuccessStock']=1;$db->put('character:character-player',$c);
    $source=synchronizeOnlineCharacterToken($db->payload('token:scene-one:token-player'),$c);
    $source=[...$source,'x'=>20,'y'=>50,'size'=>20,'mountedOnTokenId'=>'riding-mount','mountedAt'=>1,'controllerPlayerId'=>'account-player'];
    if($independent)$source=[...$source,'followCharacter'=>false,'independentClone'=>true,'specialSuccessStock'=>1];
    $db->put('token:scene-one:token-player',$source);
    $enemy=$db->payload('token:scene-one:token-monster');$enemy=[...$enemy,'x'=>43,'y'=>50,'size'=>40];$db->put('token:scene-one:token-monster',$enemy);
    $mount=[...$enemy,'id'=>'riding-mount','name'=>'Monture indépendante','x'=>20,'size'=>220,'mana'=>81,'hp'=>90,'maxHp'=>100,'mountable'=>true,'mountControllable'=>true,'maxRiders'=>2,'weaponAttacks'=>[]];
    $db->put('token:scene-one:riding-mount',$mount);$beforeMount=$db->payload('token:scene-one:riding-mount');
    $index=$db->payload('token-index:scene-one');$index['order'][]='riding-mount';$db->put('token-index:scene-one',$index);
    $args=['requestId'=>'mounted-'.randomToken(20),'sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-monster','attackKind'=>'weapon','attackId'=>'main','statId'=>'character-stat-force','opposed'=>false,'useJoker'=>true];
    $r=runCommand($db,'token.attack',$args);
    specialCheck($r->status===200&&($r->body['attack']['status']??'')==='applied','Native mounted attack uses the full mount footprint: '.json_encode($r->body));
    $after=$db->domains;$again=runCommand($db,'token.attack',$args);
    specialCheck($again->status===200&&!empty($again->body['deduplicated'])&&$db->domains===$after,'Native retry does not consume or damage twice');
    specialCheck($db->payload('token:scene-one:riding-mount')===$beforeMount,'Rider attack preserves mount resources and identity');
    $source=$db->payload('token:scene-one:token-player');$source['mountedOnTokenId']=null;$source['specialSuccessStock']=1;$db->put('token:scene-one:token-player',$source);
    $c=$db->payload('character:character-player');$c['specialSuccessStock']=1;$db->put('character:character-player',$c);$before=$db->domains;
    $denied=runCommand($db,'token.attack',[...$args,'requestId'=>'dismounted-'.randomToken(20)]);
    specialCheck($denied->status===409&&($denied->body['code']??'')==='weapon_target_out_of_range','Dismounted source immediately loses mount reach: '.json_encode($denied->body));
    specialCheck($db->domains===$before,'Out of range rejection has no partial payment or damage');
}
echo json_encode(['ok'=>true,'mountedNativeChecks'=>$specialChecks]),"\n";
