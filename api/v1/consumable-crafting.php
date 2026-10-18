<?php
declare(strict_types=1);
function applicationCoatingVials(array $items,array $source,array $ability):array {
    $templates=[];foreach($items['templates']??[] as $t)if((($t['kind']==='object'&&($t['sourceAbilityId']??'')===$ability['id'])||($t['active']['ingredientTag']??'')==='poison-vial'))$templates[$t['id']]=true;
    return array_values(array_filter($items['instances']??[],static fn($i)=>isset($templates[$i['templateId']])&&$i['location']['kind']==='inventory'&&$i['location']['characterId']===unitIdForToken($source)));
}
function applicationConsumeCoatingVial(array &$state,array $source,array $ability,string $id):void {
    $matches=array_column(applicationCoatingVials($state['items']??[],$source,$ability),null,'id');if(!isset($matches[$id]))dsError('Choisissez une fiole de ce poison encore présente dans l’inventaire du lanceur.');
    $i=dsIndex($state['items']['instances'],$id);$state['items']['instances'][$i]['location']=['kind'=>'consumed','characterId'=>unitIdForToken($source)];$state['items']['instances'][$i]['revision']++;
}
function applicationRequestConsumableCraft(array &$state,array &$store,array $owner,array $token,array $ability,array $context):array {
    if((applicationTacticalEffect($ability['tacticalEffect']??null)['kind']??'')!=='coating')dsError('Cette compétence ne fabrique pas de fiole.',400);
    foreach($store['craftRequests'] as $r)if(($r['kind']??'')==='consumable'&&$r['characterId']===$owner['id']&&$r['ability']['id']===$ability['id']&&$r['status']==='pending')dsError('Une tentative de fabrication attend déjà le MJ.');
    if(count($store['craftRequests'])>=1000)dsError('La liste des fabrications est pleine.');
    $cast=($context['roll'])($state,$owner,$token,'character-stat-intelligence',0,$ability['name'].' · Création d’une fiole');
    $request=['id'=>($context['id'])('craft'),'kind'=>'consumable','status'=>'pending','characterId'=>$owner['id'],'controllerAccountId'=>$owner['ownerPlayerId']??'',
        'ability'=>$ability,'sourceTokenId'=>$token['id'],'sceneId'=>$state['activeSceneId'],'quantity'=>1,'cost'=>0,'cast'=>$cast,'requestedAt'=>$context['now']];
    $store['craftRequests'][]=$request;return ['craftRequestId'=>$request['id'],'pendingGmApproval'=>true,'cast'=>$cast,'quantity'=>1,'manaCost'=>0,'fatigueCost'=>0];
}
function applicationResolveConsumableCraft(array &$state,array &$store,int $index,bool $approve,array $context):array {
    $r=&$store['craftRequests'][$index];
    if(!$approve){$r['status']='rejected';return ['rejected'=>true];}
    if(!($r['cast']['success']??false)){$r['status']='failed';return ['castSucceeded'=>false,'itemIds'=>[]];}
    if($r['sceneId']!==$state['activeSceneId'])dsError('La scène de cette fabrication a changé.');
    if(count($state['items']['instances']??[])>=2000)dsError('L’inventaire est plein.');
    $state['items']??=['version'=>1,'templates'=>[],'instances'=>[]];$template=null;$a=$r['ability'];
    foreach($state['items']['templates'] as $t)if($t['kind']==='object'&&($t['sourceAbilityId']??'')===$a['id']&&($t['creatorCharacterId']??'')===$r['characterId']){$template=$t;break;}
    if(!$template){if(count($state['items']['templates'])>=200)dsError('La bibliothèque d’objets est pleine.');$template=['id'=>($context['id'])('template'),'kind'=>'object','name'=>'Fiole · '.$a['name'],'description'=>'Poison d’arme. Fabrication validée par le MJ.','image'=>'','sourceAbilityId'=>$a['id'],'creatorCharacterId'=>$r['characterId']];$state['items']['templates'][]=$template;}
    $item=['id'=>($context['id'])('item'),'templateId'=>$template['id'],'revision'=>1,'location'=>['kind'=>'inventory','characterId'=>$r['characterId']]];
    $state['items']['instances'][]=$item;$r['status']='approved';$r['approvedAt']=$context['now'];return ['itemIds'=>[$item['id']],'manaSpent'=>0,'fatigueSpent'=>0];
}
