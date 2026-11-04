<?php
declare(strict_types=1);
function applicationCosmeticForms():array{return ['keyrie-buzzard'=>['id'=>'keyrie-buzzard','abilityId'=>'ability-a8b354c7-87d2-41c9-83a8-25dd6de77e40','label'=>'Transformation en oiseau','asset'=>'/assets/token-forms/keyrie-buzzard-v1.png','returnCondition'=>'Nausée']];}
function applicationCosmeticFormAsset(mixed $value):bool{foreach(applicationCosmeticForms() as $form)if($form['asset']===$value)return true;return false;}
function applicationMetamorphosisReturnConditions():array{return ['ability-a8b354c7-87d2-41c9-83a8-25dd6de77e40'=>'Nausée'];}
function applicationMetamorphosisReturnCondition(array $transformation,array $characters):string{
 $conditions=applicationMetamorphosisReturnConditions();if(in_array($transformation['returnCondition']??null,array_values($conditions),true))return $transformation['returnCondition'];
 foreach($characters as $base)if($base['id']===($transformation['baseCharacterId']??''))foreach($base['abilities']??[] as $ability)if(($ability['formCharacterId']??'')===($transformation['formCharacterId']??'')&&isset($conditions[$ability['id']]))return $conditions[$ability['id']];return '';
}
function applicationAddArbitratedCondition(array &$entity,string $label):array{
 $entries=function_exists('normalizeOnlineConditions')?normalizeOnlineConditions($entity['conditions']??null,$entity['condition']??''):($entity['conditions']??$entity['condition']??[]);
 if(!is_array($entries))$entries=preg_split('/[,;\n]/u',(string)$entries);$entries[]=$label;
 $entity['conditions']=function_exists('normalizeOnlineConditions')?normalizeOnlineConditions($entries):array_values(array_unique($entries));return $entity['conditions'];
}
function applicationCosmeticAppearance(array $token):?array{
 $a=$token['appearanceState']??[];
 return !empty($a['active'])&&isset(applicationCosmeticForms()[$a['presetId']??''])&&($a['boundCharacterId']??'')===($token['characterId']??'')?$a:null;
}
function applicationCosmeticFormImage(array $token,mixed $base):mixed{
 $a=$token['appearanceState']??[];return applicationCosmeticAppearance($token)!==null?applicationCosmeticForms()[$a['presetId']]['asset']:$base;
}
function applicationReleaseCosmeticForm(array &$token,array &$owner,bool $condition=true):bool{
 $a=$token['appearanceState']??[];$form=applicationCosmeticForms()[$a['presetId']??'']??null;if(empty($a['active'])||$form===null)return false;
 $token['appearanceState']['active']=false;
 $token['image']=($owner['unitKind']??'')==='token'||($owner['id']??'')===($token['id']??'')?($a['baseImage']??null):($owner['portrait']??$owner['image']??null);
 if($condition&&!empty($form['returnCondition'])){
  applicationAddArbitratedCondition($owner,$form['returnCondition']);
  $token['conditions']=$owner['conditions'];$token['condition']=implode(', ',$owner['conditions']);
 }
 return true;
}
function applicationChangeCosmeticForm(array &$token,array &$owner,?array $ability,array $args,string $id):array{
 $form=applicationCosmeticForms()[$args['formId']??'']??null;
 if($form===null||!is_bool($args['active']??null))throw new RuntimeException('Cette apparence n’est pas disponible.',409);
 $active=applicationCosmeticAppearance($token);
 if(($args['expectedAppearanceId']??'')!==($active['id']??''))throw new RuntimeException('L’apparence du pion a changé. Rouvrez ses actions.',409);
 if($args['active']){
  if($form['abilityId']!==($ability['id']??''))throw new RuntimeException('Cette capacité ne propose pas cette apparence.',409);
  if(!empty($token['transformation']['active']))throw new RuntimeException('Reprenez votre forme normale avant de choisir cette apparence.',409);
  if(!empty($token['appearanceState']['active'])&&$active===null)throw new RuntimeException('Révoquez d’abord l’autre apparence active.',409);
  if($active===null)$token['appearanceState']=['version'=>1,'id'=>$id,'abilityId'=>$ability['id'],'label'=>$form['label'],'active'=>true,'presetId'=>$form['id'],'boundCharacterId'=>$token['characterId']??'','baseImage'=>$token['image']??null,'image'=>$form['asset']];
  $token['image']=$form['asset'];
 }else{
  if($active===null||$active['presetId']!==$form['id'])throw new RuntimeException('Cette apparence a déjà été retirée.',409);
  applicationReleaseCosmeticForm($token,$owner);
 }
 return ['appearanceChanged'=>true,'active'=>$args['active'],'formId'=>$form['id'],...(!$args['active']?['condition'=>$form['returnCondition']]:[]),'free'=>true];
}
function applicationCosmeticOwnerIndex(array $state,array $owner):int{foreach($state['characters']??[] as $index=>$entry)if($entry['id']===$owner['id'])return $index;return -1;}
