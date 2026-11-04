<?php
declare(strict_types=1);
function applicationAreaVariants(mixed $value):array{
 if($value===null)return [];
 if(!is_array($value)||!array_is_list($value)||count($value)<1||count($value)>12)throw new RuntimeException('Variantes de zone invalides.',400);
 $seen=[];$result=[];foreach($value as $row){
  if(!is_array($row)||!is_string($row['id']??null)||preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$row['id'])!==1||isset($seen[$row['id']])||!is_string($row['label']??null)||trim($row['label'])===''||preg_match('/^.{1,80}$/us',$row['label'])!==1
   ||!is_int($row['sizeCells']??null)||$row['sizeCells']<1||$row['sizeCells']>40||!is_int($row['manaCost']??null)||$row['manaCost']<0||$row['manaCost']>1000000000)throw new RuntimeException('Une variante de zone est invalide.',400);
  $seen[$row['id']]=true;$result[]=['id'=>$row['id'],'label'=>trim($row['label']),'sizeCells'=>$row['sizeCells'],'manaCost'=>$row['manaCost']];
 }return $result;
}
function applicationWithAreaVariant(array $ability,mixed $id):array{
 $variants=applicationAreaVariants($ability['areaVariants']??null);
 if($variants===[]){if($id!==null&&$id!=='')throw new RuntimeException('Cette capacité ne possède pas de variante de zone.',400);return $ability;}
 $choice=null;foreach($variants as $row)if($row['id']===$id)$choice=$row;
 if($choice===null)throw new RuntimeException('Choisissez une taille de zone prévue pour cette capacité.',400);
 if(($ability['damageTargeting']['mode']??'')!=='area'||($ability['damageTargeting']['shape']??'')==='cone')throw new RuntimeException('Ces variantes nécessitent une zone au sol.',400);
 $ability['manaCost']=$choice['manaCost'];$ability['damageTargeting']['radiusCells']=$choice['sizeCells']/2;unset($ability['damageTargeting']['singleTargetHalfMana']);return $ability;
}
