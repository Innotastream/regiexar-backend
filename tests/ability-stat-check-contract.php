<?php
declare(strict_types=1);
require_once __DIR__.'/../api/v1/domains.php';
require_once __DIR__.'/../api/v1/online.php';
require_once __DIR__.'/../api/v1/ability-assistant.php';
function checkDuel(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);$GLOBALS['checks']++;}
function rejectDuel(callable $fn,int $code):void{try{$fn();}catch(RuntimeException $e){checkDuel($e->getCode()===$code,$e->getMessage());return;}throw new RuntimeException('Rejet manquant');}
$checks=0;$social='character-stat-spiritSocial';
$ability=['id'=>'social-duel','name'=>'Duel social','effect'=>'check','formula'=>'0','fatigueCost'=>5,'manaCost'=>0,'hpCost'=>0,'cooldownRounds'=>3,'reducedFailureCooldown'=>true,
    'castingStatId'=>$social,'image'=>'/media/abcdefghijklmnopqrstuvwx',
    'statCheck'=>['version'=>1,'defenseStatId'=>$social,'oppositionMode'=>'optional','resultModifier'=>-25]];
checkDuel(validApplicationAbilityEffects($ability),'Primitive check acceptée');
foreach([
    ['statCheck'=>[...$ability['statCheck'],'version'=>2]],['statCheck'=>[...$ability['statCheck'],'resultModifier'=>-101]],
    ['castingStatId'=>''],['castingStatId'=>'Endurance'],['statCheck'=>[...$ability['statCheck'],'defenseStatId'=>'Endurance']],
    ['tacticalEffect'=>['kind'=>'control']],['manualD100'=>true],['useWeaponDamage'=>true],['damageOverTime'=>['formula'=>'5']],
    ['completionCue'=>['version'=>1,'trigger'=>'completed','sound'=>null,'visual'=>['version'=>1,'kind'=>'none'],'icon'=>['url'=>'file:///private.png']]]
] as $patch)checkDuel(!validApplicationAbilityEffects([...$ability,...$patch]),'Configuration invalide rejetée');
$source=['id'=>'source','name'=>'Lanceur','characterId'=>'source-sheet','hp'=>100,'maxHp'=>100,'mana'=>50,'maxMana'=>50,'fatigue'=>['current'=>0],
    'stats'=>[['id'=>$social,'label'=>'Social','value'=>80]]];
$target=['id'=>'target','name'=>'Cible','hp'=>100,'maxHp'=>100,'followCharacter'=>false];
$plan=applicationAbilityCastingPlan($ability,$source,'scene',['active'=>true,'round'=>1],[]);
checkDuel($plan['fixedResultModifier']===-25&&$plan['threshold']===80,'Le bonus vise le résultat, pas le seuil');
checkDuel($plan['completionCue']['icon']['url']===$ability['image'],'Icône automatique sans son');
$roll=['id'=>'source-roll','total'=>35,'outcome'=>classifyOnlineD100Outcome(60,80,0,-25,true,true,false)];
$cast=['success'=>true,'roll'=>$roll,'outcome'=>$roll['outcome']];
$check=applicationCreateStatCheck('check-one','scene','ground',$source,$target,onlineStatCheckUnitKey($source),onlineStatCheckUnitKey($target),$ability,$cast,1000);
checkDuel($check['status']==='choosing-opposition'&&$check['sourceRoll']['outcome']['result']===35,'Choix après le jet');
checkDuel(validApplicationStatChecks([$check]),'Duel persistant valide');
$public=publicApplicationStatCheck($check);
checkDuel(!isset($public['definition'],$public['sourceUnitKey'],$public['sourceRoll']['outcome']['threshold']),'Aucun seuil ni règle privée publique');
$owned=publicApplicationStatCheck($check,false,true,false);
checkDuel($owned['canChooseOpposition']&&!$owned['canDefend']&&$owned['sourceRoll']['outcome']['threshold']===80,'Droits du lanceur courant');
$chosen=applicationAdvanceStatCheck($check,['action'=>'choose-opposition','expectedRevision'=>1,'opposed'=>true],null,2000);
checkDuel($chosen['status']==='awaiting-opposition'&&$chosen['revision']===2,'Opposition attendue');
rejectDuel(fn()=>applicationAdvanceStatCheck($chosen,['action'=>'defend','expectedRevision'=>1],null,3000),409);
foreach([10=>false,35=>true,50=>true,90=>true] as $raw=>$sourceWon){
    $defense=['id'=>'defense-'.$raw,'total'=>$raw,'outcome'=>classifyOnlineD100Outcome($raw,80,0,0,true,true,false)];
    $next=applicationAdvanceStatCheck($chosen,['action'=>'defend','expectedRevision'=>2],$defense,3000);
    checkDuel($next['status']==='completed'&&$next['sourceWon']===$sourceWon,'Comparaison degré/résultat, égalité au lanceur');
    checkDuel(validApplicationStatChecks([$next]),'Duel terminé valide');
    rejectDuel(fn()=>applicationAdvanceStatCheck($next,['action'=>'defend','expectedRevision'=>3],$defense,4000),409);
}
foreach([1,11,22,33,44,55,66,77,88,99,100] as $raw){
    $defense=['id'=>'remarkable-'.$raw,'total'=>$raw,'outcome'=>classifyOnlineD100Outcome($raw,80,0,0,true,true,false)];
    $next=applicationAdvanceStatCheck($chosen,['action'=>'defend','expectedRevision'=>2],$defense,3000);
    checkDuel($next['status']==='awaiting-validation','Remarquable attend le MJ');
    rejectDuel(fn()=>applicationAdvanceStatCheck($next,['action'=>'validate','expectedRevision'=>3,'confirmed'=>false],null,4000),400);
    $next=applicationAdvanceStatCheck($next,['action'=>'validate','expectedRevision'=>3,'confirmed'=>true],null,4000);
    checkDuel($next['status']==='completed','Résolution après validation explicite');
}
$none=applicationAdvanceStatCheck($check,['action'=>'choose-opposition','expectedRevision'=>1,'opposed'=>false],null,2000);
checkDuel($none['status']==='completed'&&$none['sourceWon'],'Sans opposition');
$cancel=applicationAdvanceStatCheck($chosen,['action'=>'cancel','expectedRevision'=>2],null,2000);
checkDuel($cancel['status']==='cancelled','Annulation ne crée aucun effet');
$failed=classifyOnlineD100Outcome(90,80,0,-25,true,true,false);
checkDuel(!$failed['success']&&$failed['result']===90&&!$failed['resultModifierApplied'],'Un échec brut reste un échec');
$normal=normalizeApplicationAbilityCompletionCue(null);
checkDuel(applicationAbilityCompletionCueFor(['name'=>'Sans image'])===$normal,'Aucun artwork générique fabriqué');
$previous=['statChecks'=>[$chosen]];$incoming=preserveApplicationPendingAbilityValidation(['statChecks'=>[]],$previous);
checkDuel($incoming['statChecks']===[$chosen],'Ancien instantané sans autorité de suppression');
echo json_encode(['checks'=>$checks,'status'=>'ok','scope'=>'PHP stat-check primitives and icon contracts; no real data'])."\n";
