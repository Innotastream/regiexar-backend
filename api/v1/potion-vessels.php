<?php
declare(strict_types=1);
require_once __DIR__.'/item-artwork.php';
function reservedPotionVesselTemplateId(mixed $id):bool{static $presets=null;$presets??=json_decode((string)file_get_contents(__DIR__.'/potion-vessel-presets.json'),true,512,JSON_THROW_ON_ERROR);return is_string($id)&&isset($presets[$id]);}
function potionVesselTemplateFields(array $source):array {
    if(array_key_exists('potionVessel',$source)&&!is_bool($source['potionVessel']))itemFailure('Choisissez si ce modèle est une fiole réutilisable.');
    static $presets=null;$presets??=json_decode((string)file_get_contents(__DIR__.'/potion-vessel-presets.json'),true,512,JSON_THROW_ON_ERROR);
    $preset=$presets[$source['id']??'']??null;$fields=[];
    if(array_key_exists('potionVessel',$source))$fields['potionVessel']=$source['potionVessel'];
    elseif($preset!==null&&($source['name']??'')===$preset['name']&&($source['kind']??'')===$preset['kind'])$fields['potionVessel']=true;
    if(array_key_exists('potionColor',$source)&&(!is_string($source['potionColor'])||preg_match('/^#[0-9a-f]{6}$/Di',$source['potionColor'])!==1))itemFailure('La couleur de la potion doit être une couleur hexadécimale.');
    $color=isset($source['potionColor'])?strtolower($source['potionColor']):bundledPotionColor($source);if($color!=='')$fields['potionColor']=$color;
    return $fields;
}
function potionVesselChoices(array $store,bool $includeOptions=true):array{$choices=array_values(array_filter($store['templates']??[],static fn($t)=>$t['kind']==='potion'&&empty($t['potionVessel'])));if($includeOptions)foreach($store['vesselPotionOptions']??[] as $option)if(!in_array($option['id'],array_column($choices,'id'),true))$choices[]=$option;return $choices;}
function potionVesselOptions(array $templates):array{$result=[];foreach($templates as $t)if($t['kind']==='potion'&&empty($t['potionVessel'])&&($t['permissions']['read']??true)!==false){$color=$t['potionColor']??bundledPotionColor($t);$result[]=['id'=>$t['id'],'name'=>$t['name'],'kind'=>'potion','image'=>itemImage($t['image']??'')?:bundledItemArtwork($t),...($color!==''?['potionColor'=>$color]:[])];}return $result;}
function normalizePotionVesselOptions(mixed $value):array{if(!is_array($value)||!array_is_list($value)||count($value)>200)itemFailure('Le choix des potions disponibles est invalide.');$ids=[];foreach($value as $t){if(!itemId($t['id']??null)||isset($ids[$t['id']])||!is_string($t['name']??null)||trim($t['name'])===''||strlen($t['name'])>120||($t['kind']??'')!=='potion'||isset($t['potionColor'])&&preg_match('/^#[0-9a-f]{6}$/Di',$t['potionColor'])!==1)itemFailure('Le choix des potions disponibles est invalide.');$ids[$t['id']]=true;}return potionVesselOptions($value);}
function potionVesselInstanceFields(array $source,array $template,array $templates):array {
    if(!array_key_exists('vesselContentTemplateId',$source)&&!array_key_exists('vesselRequest',$source))return [];
    if(($template['potionVessel']??false)!==true)itemFailure('Cet objet n’est pas une fiole réutilisable.');
    if(array_key_exists('vesselContentTemplateId',$source)&&array_key_exists('vesselRequest',$source))itemFailure('Une fiole remplie ne peut pas attendre un autre contenu.');
    $valid=static fn($targetId)=>itemId($targetId)&&count(array_filter($templates,static fn($t)=>$t['id']===$targetId&&$t['kind']==='potion'&&empty($t['potionVessel'])))>0;
    if(array_key_exists('vesselContentTemplateId',$source)){if(!$valid($source['vesselContentTemplateId']))itemFailure('Le contenu de cette fiole n’existe plus.');return ['vesselContentTemplateId'=>$source['vesselContentTemplateId']];}
    $r=$source['vesselRequest']??null;
    if(!is_array($r)||!itemId($r['id']??null)||!itemId($r['accountId']??null)||!$valid($r['potionTemplateId']??null)||!is_int($r['requestedAt']??null)||$r['requestedAt']<0||($source['location']['kind']??'')!=='inventory')itemFailure('La demande de transformation de cette fiole est invalide.');
    return ['vesselRequest'=>array_intersect_key($r,array_flip(['id','potionTemplateId','accountId','requestedAt']))];
}
function effectivePotionVesselTemplate(array $template,array $item,array $store):array {
    if(($template['potionVessel']??false)!==true||empty($item['vesselContentTemplateId']))return $template;
    $content=null;foreach(potionVesselChoices($store) as $t)if($t['id']===$item['vesselContentTemplateId']){$content=$t;break;}if($content===null)return $template;
    $result=[...$template,'kind'=>'potion','name'=>$template['name'].' · '.$content['name'],'image'=>$template['image']??bundledItemArtwork($template),'description'=>implode("\n\n",array_filter([$template['description']??'','Contenu : '.$content['name'],$content['description']??''])),'potion'=>$content['potion']??['hp'=>0,'mana'=>0,'fatigue'=>0],'vesselContentColor'=>$content['potionColor']??'#b94870','vesselContentName'=>$content['name']];
    unset($result['active']);if(isset($content['active']))$result['active']=$content['active'];return $result;
}
function emptyPotionVessel(array &$item,array $template):bool {
    if(($template['potionVessel']??false)!==true||empty($item['vesselContentTemplateId']))return false;unset($item['vesselContentTemplateId']);return true;
}
function applyPotionVesselCommand(array $state,array &$items,array $actor,string $command,array $args,array $context):array {
    $index=null;foreach($items['instances'] as $i=>$item)if($item['id']===($args['itemId']??'')){$index=$i;break;}if($index===null)itemFailure('Objet introuvable.',404);
    $item=$items['instances'][$index];if($item['revision']!==($args['expectedItemRevision']??null))itemFailure('Cette fiole a changé. Rouvrez son inventaire.',409);
    $template=null;foreach($items['templates'] as $t)if($t['id']===$item['templateId'])$template=$t;
    if(($template['potionVessel']??false)!==true)itemFailure('Cet objet n’est pas une fiole réutilisable.');
    if($item['location']['kind']!=='inventory')itemFailure('La fiole doit être dans un inventaire.',409);
    foreach($items['pendingUses']??[] as $u)if($u['itemId']===$item['id'])itemFailure('Cette fiole attend la résolution de son effet.',409);
    if($command==='item.vessel.request'){
        $unit=($context['owned'])($args['characterId']??'');if($unit['id']!==$item['location']['characterId'])itemFailure('Cette fiole n’est plus dans cet inventaire.',409);
        if(!empty($state['tacticalSync']['paused'])&&$actor['role']!=='gm')itemFailure('La table est temporairement verrouillée par le MJ.',423);
        if(isset($item['vesselContentTemplateId'])||isset($item['vesselRequest']))itemFailure('Cette fiole est déjà remplie ou attend une validation MJ.',409);
        if(!array_filter(potionVesselChoices($items,false),static fn($t)=>$t['id']===($args['potionTemplateId']??'')))itemFailure('Choisissez une potion existante de la bibliothèque.');
        if($actor['role']!=='gm')foreach($items['templates'] as $target)if($target['id']===$args['potionTemplateId']&&($target['permissions']['read']??true)===false)itemFailure('Cette potion est réservée au MJ.',403);
        $item['vesselRequest']=['id'=>($context['id'])('vessel-request'),'potionTemplateId'=>$args['potionTemplateId'],'accountId'=>$actor['id'],'requestedAt'=>$context['now']??(int)floor(microtime(true)*1000)];$item['revision']++;$items['instances'][$index]=$item;return ['item'=>$item,'vesselRequested'=>true];
    }
    if($command==='item.vessel.resolve'){
        if($actor['role']!=='gm')itemFailure('Seul le MJ peut valider ou refuser la transformation.',403);
        if(!is_bool($args['approve']??null)||!isset($item['vesselRequest'])||$item['vesselRequest']['id']!==($args['vesselRequestId']??''))itemFailure('Cette demande n’est plus en attente.',409);
        if($args['approve']){if(!array_filter(potionVesselChoices($items,false),static fn($t)=>$t['id']===$item['vesselRequest']['potionTemplateId']))itemFailure('La potion choisie n’existe plus.',409);$item['vesselContentTemplateId']=$item['vesselRequest']['potionTemplateId'];}
        unset($item['vesselRequest']);$item['revision']++;$items['instances'][$index]=$item;return ['item'=>$item,'vesselApproved'=>$args['approve']];
    }
    itemFailure('Action de fiole inconnue.');
}
