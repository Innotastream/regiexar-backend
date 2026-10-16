<?php
declare(strict_types=1);
require __DIR__.'/tactical-lifecycle.php';
$db=fixture();$character=$db->payload('character:character-player');$character['morale']='extreme-high';$character['moraleExtremes']=['low'=>'Effroi','high'=>'Vaillance'];
$token=$db->payload('token:scene-one:token-player');$token['followCharacter']=true;$token['morale']='Stressé';
$clone=[...$token,'id'=>'clone-moral','followCharacter'=>false,'independentClone'=>true,'morale'=>'Angoissé'];
$monster=['id'=>'private-moral','x'=>50,'y'=>50,'morale'=>'extreme-low','moraleExtremes'=>['low'=>'Secret du monstre'],'hp'=>10,'maxHp'=>10];
$state=['characters'=>[$character],'activeSceneId'=>'scene-one','map'=>['tokens'=>[$token,$clone,$monster],'vision'=>['enabled'=>false]],'initiative'=>[]];
$before=json_encode($state);
$view=publicPlayerState($state,['id'=>'account-player','display_name'=>'Recette'],[]);
$tokens=array_column($view['map']['tokens'],null,'id');
requireTactical($tokens[$token['id']]['morale']==='extreme-high'&&$tokens[$token['id']]['moraleExtremes']['high']==='Vaillance','Morale comes from the effective sheet');
requireTactical($tokens['clone-moral']['morale']==='Angoissé','Independent clone keeps its own morale');
requireTactical(!isset($tokens['private-moral']['morale'])&&!str_contains(json_encode($view),'Secret du monstre'),'Private morale does not leak');
requireTactical(json_encode($state)===$before,'Moral display does not mutate sheets');
$state['characters'][0]['morale']='Stressé';
$next=publicPlayerState($state,['id'=>'account-player','display_name'=>'Recette'],[]);
requireTactical($next['map']['tokens'][0]['morale']==='Stressé','Later sheet change is projected immediately');
echo "Moral summary contract passed.\n";
