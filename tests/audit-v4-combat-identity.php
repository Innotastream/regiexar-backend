<?php
declare(strict_types=1);
define('XAR_TACTICAL_FIXTURE_ONLY',true);
require __DIR__.'/tactical-lifecycle.php';
$auditChecks=0;
function auditCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);$GLOBALS['auditChecks']++;}
function auditFixture():array{
 $db=fixture();
 $rule=['id'=>'audit-breath','name'=>'Souffle audit','effect'=>'damage','formula'=>'10','damageType'=>'magical','castingStatId'=>'','manaCost'=>7,'fatigueCost'=>5,'cooldownRounds'=>0,'restRecharge'=>'short','usesPerRest'=>2,
  'itemRequirement'=>['templateId'=>'audit-reagent','quantity'=>1,'consumeOn'=>'attempt'],
  'damageTargeting'=>['mode'=>'area','shape'=>'cone','lengthCells'=>4,'radiusCells'=>4,'origin'=>'point','affectCaster'=>false,'affectAllies'=>true,'affectEnemies'=>true],
  'castGate'=>['version'=>1,'kind'=>'chance','blockOn'=>'critical-failure','defenseSelection'=>'gm-agility']];
 $source=$db->payload('character:character-player');$source['abilities']=[$rule];$source['resources']=['hp'=>1000,'maxHp'=>1000,'mana'=>100,'maxMana'=>100];$source['fatigue']=['current'=>0,'max'=>150];$db->put('character:character-player',$source);
 $target=[...$source,'id'=>'character-target','name'=>'Cible','ownerPlayerId'=>'account-other','abilities'=>[],'armor'=>0,'armorCategory'=>'none','magicArmor'=>0,'stats'=>['agility'=>100]];
 $db->put('character:character-target',$target);$db->put('character:character-replacement',[...$target,'id'=>'character-replacement','name'=>'Autre unité']);
 $token=$db->payload('token:scene-one:token-monster');$token=array_replace($token,['characterId'=>'character-target','followCharacter'=>true,'hp'=>1000,'maxHp'=>1000]);$db->put('token:scene-one:token-monster',$token);
 $db->put('token-index:scene-one',['order'=>['token-player','token-monster']]);unset($db->domains['token:scene-one:token-monster-two'],$db->domains['token:scene-one:token-independent']);
 $db->put('roster',['characterOrder'=>['character-player','character-target','character-replacement'],'players'=>[]]);
 $db->put('items',['version'=>1,'templates'=>[['id'=>'audit-reagent','kind'=>'object','name'=>'Composant audit','description'=>'Consommation sur tentative','image'=>'']],'instances'=>[['id'=>'audit-reagent-1','templateId'=>'audit-reagent','revision'=>1,'location'=>['kind'=>'inventory','characterId'=>'character-player']],['id'=>'audit-reagent-2','templateId'=>'audit-reagent','revision'=>1,'location'=>['kind'=>'inventory','characterId'=>'character-player']]]]);
 $result=runCommand($db,'token.attack',['sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-player','attackKind'=>'ability','attackId'=>'audit-breath','targetPoint'=>['x'=>90,'y'=>50],'requestId'=>'audit-breath-launch-00001']);
 auditCheck($result->status===200,'Lancement de recette : '.json_encode($result->body));
 // Isolate deferred-context assertions from the random category of Chance.
 $activity=$db->payload('activity');$id=$activity['pendingAttacks'][0]['id'];$outcome=classifyOnlineD100Outcome(70,50);
 foreach($activity['pendingAttacks'] as &$attack)if($attack['id']===$id)$attack['areaGate']['chanceOutcome']=$outcome;unset($attack);
 foreach($activity['attackReceipts'] as &$receipt)if($receipt['attack']['id']===$id)$receipt['attack']['areaGate']['chanceOutcome']=$outcome;unset($receipt);
 $db->put('activity',$activity);
 return [$db,$id];
}
function auditResources(MemoryConnection $db):array{return ['resources'=>$db->payload('character:character-player')['resources'],'fatigue'=>$db->payload('character:character-player')['fatigue'],'items'=>$db->payload('items'),'timers'=>$db->payload('activity')['actionTimers']];}
function auditResolve(MemoryConnection $db,string $id,string $decision='approve',array $defenses=[]):TestResponse{return runCommand($db,'token.attack.area.resolve',['attackId'=>$id,'decision'=>$decision,'confirmed'=>true,'defenseTokenIds'=>$defenses],true,'account-gm');}
$changes=[
 'rebind'=>static function(MemoryConnection $db):void{$token=$db->payload('token:scene-one:token-monster');$token['characterId']='character-replacement';$db->put('token:scene-one:token-monster',$token);},
 'clone'=>static function(MemoryConnection $db):void{$token=$db->payload('token:scene-one:token-monster');$token['followCharacter']=false;$token['usesCharacterSheet']=false;$db->put('token:scene-one:token-monster',$token);}
];
foreach($changes as $label=>$change){
 [$db,$id]=auditFixture();$change($db);$before=$db->domains;$paid=auditResources($db);
 $result=auditResolve($db,$id);auditCheck($result->status===409,'Identité de cible modifiée refusée avant le tableau : '.$label);auditCheck($db->domains===$before,'Aucun dommage ni domaine partiel après refus : '.$label);
 $cancel=auditResolve($db,$id,'reject');auditCheck($cancel->status===200&&$cancel->body['attack']['status']==='rejected','Le MJ peut refuser le tableau : '.$label);auditCheck(auditResources($db)===$paid,'Annuler conserve les coûts déjà payés : '.$label);
}
$changes['level']=static function(MemoryConnection $db):void{$token=$db->payload('token:scene-one:token-monster');$token['layerId']='upper';$db->put('token:scene-one:token-monster',$token);};
$changes['disappear']=static function(MemoryConnection $db):void{unset($db->domains['token:scene-one:token-monster']);};
$changes['death']=static function(MemoryConnection $db):void{$c=$db->payload('character:character-target');$c['resources']['hp']=-1000;$db->put('character:character-target',$c);};
$changes['scene']=static function(MemoryConnection $db):void{$table=$db->payload('table');$table['activeSceneId']='scene-two';$db->put('table',$table);};
foreach($changes as $label=>$change){
 [$db,$id]=auditFixture();$result=auditResolve($db,$id,'approve',['token-monster']);auditCheck($result->status===200,'Tableau initial : '.$label.' '.json_encode($result->body));
 $child=$result->body['areaAttacks'][0];auditCheck($child['status']==='awaiting-opposition','Esquive en attente : '.$label);
 $change($db);$before=$db->domains;$paid=auditResources($db);
 $result=runCommand($db,'token.attack.oppose',['attackId'=>$child['id'],'requestId'=>'audit-opposition-skip-'.$label,'decision'=>'skip'],true,'account-gm');
 auditCheck(in_array($result->status,[404,409],true),'Contexte changé refusé pendant l’esquive : '.$label.' '.json_encode($result->body));auditCheck($db->domains===$before,'Aucun changement après refus d’esquive : '.$label);
 $args=['attackId'=>$child['id'],'requestId'=>'audit-opposition-cancel-'.$label,'decision'=>'cancel'];
 $cancel=runCommand($db,'token.attack.oppose',$args,true,'account-gm');auditCheck($cancel->status===200&&$cancel->body['attack']['status']==='cancelled','Annulation possible même après changement : '.$label);auditCheck(auditResources($db)===$paid,'Annulation ne repaie et ne rembourse rien : '.$label);
 $before=$db->domains;$replay=runCommand($db,'token.attack.oppose',$args,true,'account-gm');auditCheck($replay->status===200&&!empty($replay->body['deduplicated'])&&$db->domains===$before,'Rejeu de l’annulation neutre : '.$label);
}
[$db,$id]=auditFixture();$target=$db->payload('character:character-target');$target['name']='Même unité renommée';$db->put('character:character-target',$target);
$result=auditResolve($db,$id);auditCheck($result->status===200&&$result->body['areaAttacks'][0]['status']==='applied','Renommer la même unité reste permis');auditCheck($db->payload('character:character-target')['resources']['hp']===990,'La cible initiale perd exactement les dégâts convenus');
echo json_encode(['ok'=>true,'checks'=>$auditChecks,'productionAccess'=>false],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n";
