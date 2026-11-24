<?php
declare(strict_types=1);
require_once __DIR__.'/elemental-resistances.php';
require_once __DIR__.'/effect-formula.php';
function normalizeItemTargeting(mixed $v):?array {
 if($v===null)return null;
 if(!is_array($v)||!in_array($v['mode']??'',['self','single','area'],true)||!(is_int($v['rangeCells']??null)||is_float($v['rangeCells']??null))||$v['rangeCells']<0||$v['rangeCells']>1000)itemFailure('Choisissez la cible et la portée de l’objet.');
 if($v['mode']==='area'&&(!(is_int($v['radiusCells']??null)||is_float($v['radiusCells']??null))||floor($v['radiusCells']*2)!=$v['radiusCells']*2||$v['radiusCells']<.5||$v['radiusCells']>20))itemFailure('Rayon de zone invalide.');
 return ['mode'=>$v['mode'],'rangeCells'=>$v['rangeCells'],...($v['mode']==='area'?['radiusCells'=>$v['radiusCells'],'origin'=>($v['origin']??'')==='source'?'source':'point']:[])];
}
function normalizeItemTrap(mixed $v):?array {
 if($v===null)return null;
 if(!is_array($v)||!is_bool($v['onPassage']??null)||!is_bool($v['destroyAfterTrigger']??null)||!(is_int($v['radiusCells']??null)||is_float($v['radiusCells']??null))||$v['radiusCells']<0||$v['radiusCells']>20)itemFailure('Réglez le déclenchement et la disparition du piège.');
 return ['onPassage'=>$v['onPassage'],'destroyAfterTrigger'=>$v['destroyAfterTrigger'],'radiusCells'=>$v['radiusCells']];
}
function normalizeItemPayload(mixed $e):?array {
 if(!is_array($e)||!in_array($e['kind']??'',['damage','dot','condition'],true))return null;$kind=$e['kind'];
 if($kind==='damage'){
  if(!is_array($e['components']??null)||count($e['components'])<1||count($e['components'])>7)itemFailure('Renseignez les dégâts de l’objet.');$parts=[];$seen=[];
  foreach ($e['components'] as $p) {
   if (!is_array($p)||($type=applicationDamageType($p['type']??null))===null||!validApplicationOptionalDamageElement($p,'element')||!validEffectFormula($p['formula']??null))itemFailure('Formule ou type de dégâts invalide.');
   $part=applicationSimpleDamageComponent($type,normalizeEffectFormula($p['formula']),applicationDamageElement($p['element']??null));
   if(isset($seen[$type]))itemFailure('Formule ou type de dégâts invalide.');
   $seen[$type]=true;$parts[]=$part;
  }
  $payload=['kind'=>$kind,'components'=>$parts];
 }elseif($kind==='dot'){
  if(!validEffectFormula($e['formula']??null)||($type=applicationDamageType($e['damageType']??null))===null||!validApplicationOptionalDamageElement($e)||!is_int($e['turns']??null)||$e['turns']<1||$e['turns']>20||!in_array($e['family']??'',['','poison','bleeding'],true))itemFailure('Réglez les dégâts périodiques.');
  $payload=['kind'=>$kind,'label'=>itemText($e['label']??'Dégâts périodiques',120),'formula'=>normalizeEffectFormula($e['formula']),'damageType'=>$type,...applicationOptionalDamageElement($e),'turns'=>$e['turns'],...(!empty($e['family'])?['family'=>$e['family']]:[])];
 }else{
  require_once __DIR__.'/device-state.php';$r=$e['rider']??[];$labels=normalizeOnlineConditions([$r['label']??'']);
  if(count($labels)!==1||onlineReservedHealthCondition($labels[0])||!is_int($r['turns']??null)||$r['turns']<1||$r['turns']>100)itemFailure('Choisissez un état et sa durée.');
  $payload=['kind'=>$kind,'rider'=>dsRider([...$r,'label'=>$labels[0],'resistance'=>false])];
 }
 if(($e['timing']??'')==='on-hit'){
  if(!is_int($e['buffTurns']??null)||$e['buffTurns']<1||$e['buffTurns']>999||!in_array($e['trigger']??'',['all-attacks','melee-weapon','main-weapon'],true))itemFailure('Réglez la durée du bonus et les attaques concernées.');
  return [...$payload,'timing'=>'on-hit','buffTurns'=>$e['buffTurns'],'trigger'=>$e['trigger']];
 }
 if(isset($e['timing'])&&$e['timing']!=='direct')itemFailure('Moment d’application invalide.');return $payload;
}
