<?php
declare(strict_types=1);
require_once __DIR__.'/character-statistics.php';
function applicationPassivePresets():array {static $presets=null;return $presets??=json_decode((string)file_get_contents(__DIR__.'/passive-ability-presets.json'),true,512,JSON_THROW_ON_ERROR);}
function applicationPassiveKinds():array {return [
 'stat-roll-option'=>['name'=>'Option de jet de caractéristique','description'=>'Propose un jet particulier sur la caractéristique configurée, avec amélioration de résultat ou avantage.'],
 'manual'=>['name'=>'Passif à arbitrer','description'=>'Connaissance ou particularité à arbitrer par le MJ, sans bonus chiffré automatique.'],
 'effect-resistance'=>['name'=>'Résistance aux effets reçus','description'=>'Réduit les dégâts et la durée des familles d’effets ou des consommables configurés.'],
 'ranged-target-evasion'=>['name'=>'Malus des attaques à distance reçues','description'=>'Réduit le seuil des attaques à distance dirigées contre cette unité.'],
 'infusion-efficiency'=>['name'=>'Efficacité des infusions','description'=>'Améliore le ratio des infusions déjà autorisées par les attaques et compétences.'],
 'opportunity-attack'=>['name'=>'Attaque d’opportunité','description'=>'En combat, avertit après qu’un ennemi quitte la portée d’une arme de mêlée. Le MJ choisit de résoudre ou de refuser la réaction ; aucune attaque automatique.'],
 'mental-control-resistance'=>['name'=>'Boss : résistance au contrôle mental','description'=>'Un contrôle mental réussi devient une simple influence de même durée. Le contrôleur du pion ne change pas.'],
 'ability-fatigue-surcharge'=>['name'=>'Fatigue supplémentaire des compétences','description'=>'L’activation d’une compétence coûte une fatigue supplémentaire. Les coûts indiqués comme déjà majorés ne sont pas augmentés une seconde fois.']];}
function applicationPassiveNumber(mixed $value,int $min,int $max,int $fallback):int{return is_int($value)&&$value>=$min&&$value<=$max?$value:$fallback;}
function applicationPassiveAbilities(mixed $v):array {
 if($v===null)return [];if(!is_array($v)||!array_is_list($v)||count($v)>100)throw new RuntimeException('La liste des compétences passives est invalide.',400);
 $kinds=applicationPassiveKinds();$seen=[];$result=[];
 foreach($v as $e){
  if(!is_array($e)||($e['version']??0)!==1||!isset($kinds[$e['kind']??'']))throw new RuntimeException('Ce passif utilise une version ou une mécanique non prise en charge.',400);
  foreach(applicationPassivePresets() as $entries)foreach(array_is_list($entries)?$entries:[$entries] as $configured)if(($configured['id']??'')===($e['id']??'')&&$configured['kind']===$e['kind']){$enabled=$e['enabled']??true;$e=[...$e,...$configured,'enabled'=>$enabled];break 2;}
  $id=(string)($e['id']??$e['kind']);if(!preg_match('/^[A-Za-z0-9_-]{1,120}$/D',$id)||isset($seen[$id]))throw new RuntimeException('Identité de passif invalide.',400);$seen[$id]=true;
  $p=['version'=>1,'id'=>$id,'kind'=>$e['kind'],'name'=>substr((string)($e['name']??$kinds[$e['kind']]['name']),0,120),'description'=>substr((string)($e['description']??$kinds[$e['kind']]['description']),0,4000),'enabled'=>($e['enabled']??true)!==false];
  if(in_array($e['polarity']??'',['positive','negative'],true))$p['polarity']=$e['polarity'];
  if($e['kind']==='opportunity-attack'){$p['reachMode']=($e['reachMode']??'')==='fixed'?'fixed':'weapon';$p['reachCells']=applicationPassiveNumber($e['reachCells']??null,1,20,1);$p['statId']=in_array($e['statId']??'',applicationStatIds(),true)?$e['statId']:'character-stat-force';}
  if($e['kind']==='ability-fatigue-surcharge')$p['amount']=applicationPassiveNumber($e['amount']??null,0,100,1);
  if($e['kind']==='ranged-target-evasion')$p['thresholdPenalty']=applicationPassiveNumber($e['thresholdPenalty']??null,0,100,10);
  if($e['kind']==='infusion-efficiency')$p['ratio']=applicationPassiveNumber($e['ratio']??null,1,100,2);
  if($e['kind']==='stat-roll-option'){
   if(!in_array($e['statId']??'',applicationStatIds(),true))throw new RuntimeException('Choisissez la caractéristique de ce passif.',400);
   $p=[...$p,'statId'=>$e['statId'],'resultBonus'=>applicationPassiveNumber($e['resultBonus']??null,-100,100,0),'rollMode'=>in_array($e['rollMode']??'',['advantage','disadvantage'],true)?$e['rollMode']:'normal','rollLabel'=>substr((string)($e['rollLabel']??$e['name']??$kinds[$e['kind']]['name']),0,120),'choiceLabel'=>substr((string)($e['choiceLabel']??$e['name']??$kinds[$e['kind']]['name']),0,120)];
  }
  if($e['kind']==='effect-resistance'){
   $families=$e['families']??['poison'];$itemKinds=$e['itemKinds']??['potion'];
   if(!is_array($families)||!array_is_list($families)||count($families)>20||!is_array($itemKinds)||!array_is_list($itemKinds)||$families===[]&&$itemKinds===[])throw new RuntimeException('Choisissez les familles ou consommables concernés par ce passif.',400);
   foreach($families as $family)if(!is_string($family)||!preg_match('/^[a-z][a-z0-9-]{0,59}$/D',$family))throw new RuntimeException('Famille de passif invalide.',400);
   foreach($itemKinds as $kind)if(!in_array($kind,['potion','object'],true))throw new RuntimeException('Consommable de passif invalide.',400);
   $p=[...$p,'percent'=>applicationPassiveNumber($e['percent']??null,0,100,25),'durationReduction'=>applicationPassiveNumber($e['durationReduction']??null,0,20,1),'families'=>array_values(array_unique($families)),'itemKinds'=>array_values(array_unique($itemKinds)),'affectBeneficial'=>($e['affectBeneficial']??false)===true];
  }
  $result[]=$p;
 }
 return $result;
}
function applicationConfiguredPassiveAbilities(array $source):array {
 $values=applicationPassiveAbilities($source['passiveAbilities']??null);$origin=($source['characterId']??'')?:((($source['cloneSourceCharacterId']??'')?:($source['id']??'')));$entries=applicationPassivePresets()[$origin]??[];
 $independent=($source['followCharacter']??true)===false||!empty($source['linkedTokenId'])||($source['independent']??false)===true||($source['independentClone']??false)===true||!empty($source['cloneSourceCharacterId'])&&($source['id']??'')!==$origin;
 foreach(array_is_list($entries)?$entries:[$entries] as $preset){if($independent&&($preset['existingCloneDefault']??false)!==true)continue;$found=false;foreach($values as $value)if($value['id']===$preset['id']||!in_array($preset['kind'],['manual','stat-roll-option'],true)&&$value['kind']===$preset['kind']){$found=true;break;}if(!$found)$values[]=$preset;}
 return applicationPassiveAbilities($values);
}
function applicationHasPassive(array $source,string $kind):bool {foreach(applicationConfiguredPassiveAbilities($source) as $p)if($p['enabled']&&$p['kind']===$kind)return true;return false;}
function applicationMovementAvoidsOpportunity(array $executions,string $scene,string $token):bool {foreach($executions as $e){$step=$e['workflow']['steps'][$e['currentStepIndex']??-1]??[];if(($e['status']??'')==='active'&&($e['sceneId']??'')===$scene&&($e['sourceTokenId']??'')===$token&&($step['type']??'')==='movement'&&($step['avoidsOpportunity']??false)===true)return true;}return false;}
require_once __DIR__.'/opportunity-attacks.php';
