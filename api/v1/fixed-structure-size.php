<?php
declare(strict_types=1);
function applicationStructureSizeAdjustment(mixed $value,array $variants):?array {
 if($value===null)return null;$sizes=array_column($variants,'size');$last=$sizes[count($sizes)-1];$min=$value['minSize']??null;$max=$value['maxSize']??null;
 if(!is_array($value)||($value['version']??null)!==1||!is_int($min)||!is_int($max)||$min<10||$min>$sizes[0]||$max<$last||$max>220||$max>(int)floor($last*1.1))throw new RuntimeException('Réglage de taille de l’élément fixe invalide.',400);
 foreach($sizes as $i=>$size)if($i&&$size<=$sizes[$i-1])throw new RuntimeException('Réglage de taille de l’élément fixe invalide.',400);
 return ['version'=>1,'minSize'=>$min,'maxSize'=>$max];
}
function applicationQuoteStructureSize(array $definition,array $args=[]):array {
 $variants=$definition['variants'];$policy=$definition['sizeAdjustment']??null;$wanted=$args['variantId']??$variants[0]['id'];$variant=null;
 foreach($variants as $v)if($v['id']===$wanted)$variant=$v;
 if($variant===null)throw new RuntimeException('Choisissez une taille de l’élément fixe.',400);$size=$variant['size'];
 if(array_key_exists('structureSize',$args)){
  $size=$args['structureSize'];if(!$policy||!is_int($size)||$size<$policy['minSize']||$size>$policy['maxSize'])throw new RuntimeException('La taille de la toile dépasse les limites autorisées.',400);
  $variant=$variants[count($variants)-1];foreach($variants as $v)if($size<=$v['size']){$variant=$v;break;}
  if(!empty($args['variantId'])&&$args['variantId']!==$variant['id'])throw new RuntimeException('Le palier choisi ne correspond pas à cette taille de toile.',400);
 }
 return ['variantId'=>$variant['id'],'name'=>$variant['name'],'size'=>$size,'hp'=>$variant['hp'],'uses'=>$variant['uses'],'nominalSize'=>$variant['size'],'minSize'=>$policy['minSize']??$variants[0]['size'],'maxSize'=>$policy['maxSize']??$variants[count($variants)-1]['size']];
}
function applicationConfiguredStructureSizing(array $source):array {
 if(($source['id']??'')!=='ability-c5b5d747-8018-4ace-b05d-d9c59508935b'||($source['effect']??'')!=='device'||($source['device']['kind']??'')!=='structure'||(($source['rulePreset']['id']??'')==='silk-web-sizing'&&($source['rulePreset']['revision']??0)>=1))return $source;
 $defaults=[['id'=>'medium','name'=>'Normale','size'=>70,'hp'=>30,'uses'=>1],['id'=>'large','name'=>'Grosse','size'=>90,'hp'=>60,'uses'=>2],['id'=>'huge','name'=>'Très grosse','size'=>110,'hp'=>90,'uses'=>3]];$prior=$source['device']['variants']??$defaults;$byId=array_column($prior,null,'id');
 if(count($prior)!==3||array_diff(['medium','large','huge'],array_keys($byId)))return $source;
 $variants=[];foreach($defaults as $v)$variants[]=[...$byId[$v['id']],'name'=>$v['name'],'size'=>$v['size']];
 return [...$source,'device'=>[...$source['device'],'artworkPreset'=>'silk-web','variants'=>$variants,'sizeAdjustment'=>['version'=>1,'minSize'=>50,'maxSize'=>120]],'rulePreset'=>['id'=>'silk-web-sizing','revision'=>1],'description'=>str_replace('Moyenne : taille 50, 30 PV, 1 utilisation ; grosse : taille 70, 60 PV, 2 utilisations ; très grosse : taille 90, 90 PV, 3 utilisations.','Taille ajustable avant la pose : normale jusqu’à 70, grosse jusqu’à 90, très grosse jusqu’à 110 avec une marge limitée à 120. Les PV et utilisations suivent le palier choisi. Le MJ peut ajuster la taille après la pose.',$source['description']??'')];
}
