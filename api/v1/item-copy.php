<?php
declare(strict_types=1);
require_once __DIR__.'/potion-vessels.php';
function groundItemCopyReason(array $state,array $item):string{
 $template=null;foreach($state['items']['templates']??[] as $model)if($model['id']===($item['templateId']??'')){$template=$model;break;}
 if($template===null||($item['location']['kind']??'')!=='ground')return 'Cet objet n’est plus posé sur la carte.';
 if(reservedPotionVesselTemplateId($template['id'])||!empty($template['potionVessel'])||!empty($item['vesselContentTemplateId'])||!empty($item['vesselRequest']))return 'Cette fiole spéciale conserve une identité propre et ne peut pas être copiée ici.';
 if(!empty($template['sourceAbilityId'])||!empty($template['stockBindings']))return 'Cet objet est lié à une capacité ; utilisez son outil habituel pour en créer un autre.';
 foreach($state['devices']['devices']??[] as $device)if(($device['itemId']??'')===$item['id'])return 'Ce dispositif possède son propre fonctionnement et ne peut pas être copié ici.';
 foreach($state['items']['pendingUses']??[] as $use)if(($use['itemId']??'')===$item['id'])return 'Terminez ou annulez l’utilisation de cet objet avant de le copier.';
 return '';
}
function itemCopyFields(array $item):array{return array_intersect_key($item,array_flip(['name','description','equipment','size','health']));}
