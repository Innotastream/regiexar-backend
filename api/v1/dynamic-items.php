<?php
declare(strict_types=1);
require_once __DIR__.'/item-catalog.php';
require_once __DIR__.'/item-activations.php';
require_once __DIR__.'/unit-authority.php';
require_once __DIR__.'/item-physical.php';
require_once __DIR__.'/active-items.php';
require_once __DIR__.'/equipment.php';
require_once __DIR__.'/health-state.php';
require_once __DIR__.'/complex-movement.php';

function itemFailure(string $message, int $status = 400): never { throw new RuntimeException($message, $status); }
function itemId(mixed $value): bool { return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,180}$/D', $value) === 1; }
function itemText(mixed $value, int $maximum): string { if (!is_string($value)) return ''; $value = str_replace("\0", '', $value); if (function_exists('mb_substr')) return mb_substr($value, 0, $maximum); preg_match('/^.{0,' . $maximum . '}/us', $value, $match); return $match[0] ?? ''; }
function itemPermissions(array $template):array {return array_replace(['read'=>true,'pickup'=>true,'transfer'=>true,'destroy'=>false,'boundToCarrier'=>false],$template['permissions']??[]);}
function normalizeItemPermissions(mixed $value):array {
    if(!is_array($value)||array_diff(array_keys($value),['read','pickup','transfer','destroy','boundToCarrier']))itemFailure('Les droits de l’objet sont invalides.');
    foreach($value as $flag)if(!is_bool($flag))itemFailure('Les droits de l’objet sont invalides.');
    return itemPermissions(['permissions'=>$value]);
}
function itemImage(mixed $value): string {
    if (!is_string($value) || $value === '') return '';
    if (preg_match('#^/media/[A-Za-z0-9_.-]+$#D', $value) === 1) return $value;
    if (preg_match('#^/assets/[A-Za-z0-9_./-]+\.(?:png|jpe?g|webp)$#Di', $value) === 1 && !str_contains($value, '..')) return $value;
    $url = parse_url($value);
    return is_array($url) && ($url['scheme'] ?? '') === 'https' && ($url['host'] ?? '') === 'regie-xar-tsaroth.fr'
        && !isset($url['user'], $url['pass']) && !isset($url['user']) && !isset($url['pass']) && !isset($url['port']) ? $value : '';
}

function normalizeItemTemplate(array $value): array {
    if (!itemId($value['id'] ?? null) || !in_array($value['kind'] ?? '', ['letter', 'book', 'potion', 'object'], true)) itemFailure('Le modèle d’objet est invalide.');
    $name = trim(itemText($value['name'] ?? '', 120));
    if (array_key_exists('maxHp', $value) && !itemPermissions($value)['destroy']) itemFailure('Activez le droit Détruire avant d’ajouter des PV à l’objet.');
    if ($name === '') itemFailure('Donnez un nom à l’objet.');
    $image = itemImage($value['image'] ?? '');
    if (($value['image'] ?? '') !== '' && $image === '') itemFailure('L’image de l’objet est invalide.');
    $result = ['id' => $value['id'], 'kind' => $value['kind'], 'name' => $name, 'description' => itemText($value['description'] ?? '', 4000), 'image' => $image];
    if(array_key_exists('category',$value)){if(!in_array($value['category'],['ability','usable','reading','equipment','utility','quick'],true)&&!customItemCategoryId($value['category']))itemFailure('La catégorie d’objet est invalide.');$result['category']=$value['category'];}
    if(isset($value['active']))$result['active']=normalizeActiveItem($value['active']);
    if(isset($value['equipment']))$result['equipment']=normalizeEquipment($value['equipment']);
    if(array_key_exists('permissions',$value))$result['permissions']=normalizeItemPermissions($value['permissions']);
    if (array_key_exists('size', $value)) $result['size'] = normalizeItemSize($value['size']);
    if (array_key_exists('maxHp', $value)) $result['maxHp'] = normalizeItemMaxHp($value['maxHp']);
    if (array_key_exists('sourceAbilityId', $value) || array_key_exists('creatorCharacterId', $value)) {
        if (!itemId($value['sourceAbilityId'] ?? null) || !itemId($value['creatorCharacterId'] ?? null)) itemFailure('Le lien de l’objet à sa compétence est invalide.');
        $result['sourceAbilityId'] = $value['sourceAbilityId'];
        $result['creatorCharacterId'] = $value['creatorCharacterId'];
    }
    if (array_key_exists('stockBindings', $value)) {
        if (!is_array($value['stockBindings']) || count($value['stockBindings']) > 1000) itemFailure('Les liens de stock sont invalides.');
        $result['stockBindings'] = [];
        foreach ($value['stockBindings'] as $link) {
            if (!is_array($link) || !itemId($link['characterId'] ?? null) || !itemId($link['abilityId'] ?? null)) itemFailure('Les liens de stock sont invalides.');
            $result['stockBindings'][] = ['characterId' => $link['characterId'], 'abilityId' => $link['abilityId']];
        }
    }
    if (isset($value['document'])) {
        $document = $value['document'];
        if (!is_array($document) || !is_array($document['blocks'] ?? null) || count($document['blocks']) > 1000) itemFailure('Le document est trop long.');
        $blocks = [];
        foreach ($document['blocks'] as $block) {
            if (!is_array($block)) itemFailure('Ce bloc de document n’est pas pris en charge.');
            $type = $block['type'] ?? '';
            $pageBreak = ($block['pageBreak'] ?? false) === true;
            if ($type === 'image') {
                $src = itemImage($block['src'] ?? '');
                if ($src === '') itemFailure('Une illustration du document est invalide.');
                $blocks[] = ['type' => 'image', 'src' => $src, 'alt' => itemText($block['alt'] ?? '', 200), 'pageBreak' => $pageBreak];
            } elseif ($type === 'table') {
                if (!is_array($block['rows'] ?? null) || count($block['rows']) > 200) itemFailure('Le tableau est trop grand.');
                $rows = [];
                foreach ($block['rows'] as $row) {
                    if (!is_array($row) || count($row) > 30) itemFailure('Le tableau est trop grand.');
                    $rows[] = array_map(static fn($cell) => itemText($cell, 10000), $row);
                }
                $blocks[] = ['type' => 'table', 'rows' => $rows, 'pageBreak' => $pageBreak];
            } elseif (in_array($type, ['heading', 'paragraph'], true)) {
                $runs = $block['runs'] ?? [['text' => $block['text'] ?? '']];
                if (!is_array($runs) || count($runs) > 200) itemFailure('Un paragraphe est trop complexe.');
                $clean = [];
                foreach ($runs as $run) {
                    if (!is_array($run)) itemFailure('Un paragraphe est invalide.');
                    $clean[] = ['text' => itemText($run['text'] ?? '', 20000), 'bold' => ($run['bold'] ?? false) === true, 'italic' => ($run['italic'] ?? false) === true];
                }
                $blocks[] = ['type' => $type, 'runs' => $clean, 'align' => in_array($block['align'] ?? '', ['left', 'right', 'center', 'justify'], true) ? $block['align'] : 'left', 'pageBreak' => $pageBreak];
            } else itemFailure('Ce bloc de document n’est pas pris en charge.');
        }
        if (strlen(json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) > 200000) itemFailure('Le document dépasse 200 000 octets.');
        $result['document'] = ['title' => itemText($document['title'] ?? $name, 120), 'filename' => itemText($document['filename'] ?? '', 180), 'blocks' => $blocks];
    }
    if ($value['kind'] === 'potion') {
        $result['potion'] = [];
        foreach (['hp', 'mana', 'fatigue'] as $field) {
            $amount = $value['potion'][$field] ?? 0;
            if (!is_int($amount) || abs($amount) > 1000000) itemFailure('L’effet de la potion doit être un nombre entier borné.');
            $result['potion'][$field] = $amount;
        }
    }
    return $result;
}

function normalizeItemStore(?array $value): array {
    if ($value === null || $value === []) return ['version' => 1, 'templates' => [], 'instances' => []];
    if (($value['version'] ?? 1) !== 1) itemFailure('Ces objets proviennent d’une version plus récente. Ils ont été conservés.', 409);
    if (!is_array($value['templates'] ?? null) || !is_array($value['instances'] ?? null) || count($value['templates']) > 200 || count($value['instances']) > 2000) itemFailure('La collection d’objets dépasse les limites.');
    $categories=normalizeItemCategories($value['categories']??[]);
    $templates = []; $ids = [];
    foreach ($value['templates'] as $template) {
        if (!is_array($template)) itemFailure('Le modèle d’objet est invalide.');
        $template = normalizeItemTemplate($template);
        if(customItemCategoryId($template['category']??null)&&!in_array($template['category'],array_column($categories,'id'),true))itemFailure('Cette catégorie n’existe plus.');
        if (isset($ids[$template['id']])) itemFailure('Deux modèles portent le même identifiant.');
        $ids[$template['id']] = true; $templates[] = $template;
    }
    $instances = []; $seen = [];
    foreach ($value['instances'] as $item) {
        if (!is_array($item) || !itemId($item['id'] ?? null) || isset($seen[$item['id']]) || !isset($ids[$item['templateId'] ?? '']) || !is_int($item['revision'] ?? null) || $item['revision'] < 1 || !is_array($item['location'] ?? null)) itemFailure('Un exemplaire d’objet est invalide.');
        $seen[$item['id']] = true; $location = $item['location']; $kind = $location['kind'] ?? '';
        if($kind==='destroyed')$clean=['kind'=>'destroyed'];
        elseif (in_array($kind, ['inventory', 'consumed'], true) && itemId($location['characterId'] ?? null)) $clean = ['kind' => $kind, 'characterId' => $location['characterId']];
        elseif ($kind === 'ground' && itemId($location['sceneId'] ?? null) && in_array($location['layerId'] ?? '', ['basement', 'ground', 'upper'], true)
            && isset($location['x'], $location['y']) && (is_int($location['x']) || is_float($location['x'])) && (is_int($location['y']) || is_float($location['y']))
            && is_finite((float)$location['x']) && is_finite((float)$location['y']) && $location['x'] >= 0 && $location['x'] <= 100 && $location['y'] >= 0 && $location['y'] <= 100) $clean = ['kind' => 'ground', 'sceneId' => $location['sceneId'], 'layerId' => $location['layerId'], 'x' => $location['x'], 'y' => $location['y']];
        else itemFailure('L’emplacement d’un objet est invalide.');
        $normalized = ['id' => $item['id'], 'templateId' => $item['templateId'], 'revision' => $item['revision'], 'location' => $clean];
        if(array_key_exists('name',$item))$normalized['name']=trim(itemText($item['name'],120));
        if(array_key_exists('description',$item))$normalized['description']=itemText($item['description'],4000);
        if(isset($item['equipment']))$normalized['equipment']=normalizeEquipment($item['equipment']);
        if (array_key_exists('size', $item)) $normalized['size'] = normalizeItemSize($item['size']);
        if (array_key_exists('health', $item)) {
            $normalized['health'] = normalizeItemHealth($item['health']);
            if ($normalized['health']['current'] === 0 && !in_array($clean['kind'], ['destroyed','consumed'], true)) itemFailure('Un objet à 0 PV doit être détruit.');
        }
        $instances[] = $normalized;
    }
    $catalog=null;if(isset($value['inventoryCatalog'])){if(!is_array($value['inventoryCatalog'])||count($value['inventoryCatalog'])>1000)itemFailure('Catalogue des stocks invalide.');$catalog=[];foreach($value['inventoryCatalog'] as $unitId=>$entryIds){if(!itemId((string)$unitId)||!is_array($entryIds)||!array_is_list($entryIds)||count($entryIds)>200||count(array_unique($entryIds))!==count($entryIds))itemFailure('Catalogue des stocks invalide.');foreach($entryIds as $entryId)if(!is_string($entryId)||!isset($ids[$entryId]))itemFailure('Modèle du catalogue introuvable.');$catalog[$unitId]=$entryIds;}}
    return ['version' => 1, 'templates' => $templates, 'instances' => $instances, ...(array_key_exists('pendingUses',$value)?['pendingUses'=>normalizeItemUses($value['pendingUses'])]:[]),...(array_key_exists('categories',$value)?['categories'=>$categories]:[]),...($catalog!==null?['inventoryCatalog'=>$catalog]:[]),...(isset($value['equipmentLoadouts'])?['equipmentLoadouts'=>normalizeEquipmentLoadouts($value['equipmentLoadouts'])]:[])];
}

function itemWithinReach(array $location, array $token, array $map): bool {
    $dx = ($location['x'] - ($token['x'] ?? NAN)) * ($map['naturalWidth'] ?? 1600) / 100;
    $dy = ($location['y'] - ($token['y'] ?? NAN)) * ($map['naturalHeight'] ?? 900) / 100;
    return is_finite($dx) && is_finite($dy) && hypot($dx, $dy) <= ($token['size'] ?? 40) / 2 + 21;
}

function itemTransferPair(array $state, string $sourceId, string $targetId, array $args = []): ?array {
    $map=$state['map']??[];$layerId=$map['activeLayerId']??'ground';$level=$map['layers'][$layerId]??$map;
    $grid=(float)($map['gridSize']??50);$width=(float)($level['naturalWidth']??$map['naturalWidth']??1600);$height=(float)($level['naturalHeight']??$map['naturalHeight']??900);
    if(!($grid>0&&$width>0&&$height>0)||!is_finite($grid)||!is_finite($width)||!is_finite($height))return null;
    $sources=[];$targets=[];
    foreach($map['tokens']??[] as $token){
        if(!empty($token['hidden'])||($token['layerId']??$layerId)!==$layerId
            ||!isset($token['x'],$token['y'])||!(is_int($token['x'])||is_float($token['x']))||!(is_int($token['y'])||is_float($token['y']))||!is_finite((float)$token['x'])||!is_finite((float)$token['y']))continue;
        $unitId=unitIdForToken($token);
        if($unitId===$sourceId&&(empty($args['tokenId'])||$token['id']===$args['tokenId']))$sources[]=$token;
        if($unitId===$targetId&&(empty($args['targetTokenId'])||$token['id']===$args['targetTokenId']))$targets[]=$token;
    }
    foreach($sources as $source)foreach($targets as $target){
        $distance=hypot(($source['x']-$target['x'])*$width/(100*$grid),($source['y']-$target['y'])*$height/(100*$grid));
        if($distance<=5+1e-9)return ['source'=>$source,'target'=>$target];
    }
    return null;
}

function itemTokenHealth(array $state,array $token):string {
    $unit=unitUsesCharacterSheet($token)?unitById($state,unitIdForToken($token),false):null;
    $entity=$unit??$token;$hp=$unit['resources']['hp']??$token['hp']??null;$max=$unit['resources']['maxHp']??$token['maxHp']??null;
    if(($hp===null||$max===null)&&in_array($token['publicHealth']??'',['normal','critical','down','dead'],true))return onlineManualDeath($entity)?'dead':$token['publicHealth'];
    return onlineHealthState($hp,$max,($token['playerControlled']??false)===true||!empty($entity['ownerPlayerId'])||!empty($token['controllerPlayerId'])||!empty($token['controlEffect']['controllerId']),onlineManualDeath($entity))['code'];
}
function itemLootAccess(array $state,string $targetTokenId,string $sourceTokenId,array $context=[]):string {
    $map=$state['map']??[];$layer=$map['activeLayerId']??'ground';$target=null;$source=null;
    if(!empty($state['tacticalSync']['paused']))return 'La table est temporairement verrouillée par le MJ.';
    foreach($map['tokens']??[] as $t){if($t['id']===$targetTokenId)$target=$t;if($t['id']===$sourceTokenId)$source=$t;}
    $visible=$context['visible']??static fn($t)=>true;
    if(!$target||!empty($target['hidden'])||!empty($target['dimSilhouette'])||($target['layerId']??$layer)!==$layer||!$visible($target))return 'Ce pion n’est plus visible sur ce niveau.';
    if(itemTokenHealth($state,$target)!=='dead')return 'Seuls les pions morts peuvent être fouillés par les joueurs.';
    if(!$source||!empty($source['hidden'])||!empty($source['dimSilhouette'])||($source['layerId']??$layer)!==$layer||!$visible($source))return 'Placez votre pion sur ce niveau.';
    if(in_array(itemTokenHealth($state,$source),['dead','down'],true))return 'Un pion KO ou mort ne peut pas fouiller.';
    if(unitIdForToken($source)===unitIdForToken($target))return 'Ouvrez votre propre inventaire.';
    foreach([$source['x']??null,$source['y']??null,$target['x']??null,$target['y']??null] as $n)if(!(is_int($n)||is_float($n))||!is_finite((float)$n))return 'Approchez votre pion à 1 case maximum du bord du pion mort.';
    if(applicationMapDistanceInCells($source,$target,$map,true,true)>1+1e-9)return 'Approchez votre pion à 1 case maximum du bord du pion mort.';
    return '';
}

function applyDynamicItemCommand(array $state, array $identity, string $command, array $args,array $context=[]): array {
    $items = normalizeItemStore($state['items'] ?? null); $isGm = ($identity['role'] ?? '') === 'gm';
    if(in_array($command,['item.activate','item.trap.resolve','item.use.cancel','item.opposition','item.opposition.validate'],true)){$result=applyItemUseCommand($state,$items,$identity,$command,$args,$context);return ['items'=>normalizeItemStore($items),'result'=>$result,'activeState'=>$state];}
    $character = static function($id) use ($state): array {
        return unitById($state,(string)$id);
    };
    $owned = static function($id) use ($character, $identity, $isGm): array {
        $entry = $character($id);
        if (!$isGm && ($entry['ownerPlayerId'] ?? '') !== ($identity['id'] ?? null)) itemFailure('Cet inventaire ne vous appartient pas.', 403);
        return $entry;
    };
    $tokenFor = static function($id) use ($state, $args, $isGm): array {
        $token = null;
        foreach ($state['map']['tokens'] ?? [] as $entry) if (($entry['id'] ?? '') === ($args['tokenId'] ?? null) && unitIdForToken($entry) === $id) $token = $entry;
        if ($token === null || !$isGm && ($token['hidden'] ?? false) === true || ($token['layerId'] ?? $state['map']['activeLayerId'] ?? 'ground') !== ($state['map']['activeLayerId'] ?? 'ground')) itemFailure('Choisissez le pion de ce personnage sur le niveau courant.', 409);
        if (($args['sceneId'] ?? '') !== ($state['activeSceneId'] ?? '')) itemFailure('La scène a changé.', 409);
        if (!$isGm && ($state['tacticalSync']['paused'] ?? false) === true) itemFailure('La table est temporairement verrouillée par le MJ.', 423);
        return $token;
    };
    if($command==='item.effects.rest'){
        if(!$isGm)itemFailure('Seul le MJ peut appliquer un repos.',403);
        if(!in_array($args['kind']??'',['short','long'],true)||!is_array($args['unitIds']??null)||count($args['unitIds'])<1||count($args['unitIds'])>100)itemFailure('Choisissez les unités du repos.');foreach($args['unitIds'] as $unitId)unitById($state,$unitId);
        return ['items'=>$items,'result'=>applicationItemEffectRest($state,$args['unitIds'],$args['kind']),'activeState'=>$state];
    }
    if(str_starts_with($command,'item.equipment.'))return applyEquipmentCommand($state,$items,$identity,$command,$args,$owned);
    if($command==='item.quick.create'){
        $unit=$owned($args['characterId']??'');$name=trim(itemText($args['name']??'',120));$quantity=$args['quantity']??1;$ids=$args['itemIds']??null;
        if($name===''||!is_int($quantity)||$quantity<1||$quantity>50||!itemId($args['templateId']??null)||!is_array($ids)||!array_is_list($ids)||count($ids)!==$quantity||count(array_unique($ids))!==$quantity)itemFailure('Indiquez un nom et une quantité de 1 à 50.');
        foreach($ids as $id)if(!itemId($id))itemFailure('Indiquez un nom et une quantité de 1 à 50.');
        $existing=namedItemTemplate($items,$name);
        if($existing&&($existing['kind']!=='object'||($existing['category']??'')!=='quick'||($existing['description']??'')!==''||isset($existing['active'])||isset($existing['equipment'])||isset($existing['document'])||isset($existing['sourceAbilityId'])||!empty($existing['stockBindings'])||isset($existing['maxHp'])||itemPermissions($existing)!==itemPermissions([])))itemFailure('Ce nom appartient déjà à un modèle de la bibliothèque. Choisissez un autre nom pour cet objet rapide.',409);
        if((!$existing&&count($items['templates'])>=200)||count($items['instances'])+$quantity>2000)itemFailure('La collection d’objets est pleine.',429);
        if(in_array($args['templateId'],array_column($items['templates'],'id'),true)||array_intersect($ids,array_column($items['instances'],'id'))!==[])itemFailure('Cet ajout existe déjà.',409);
        $description=itemText($args['description']??'',4000);$template=$existing??['id'=>$args['templateId'],'kind'=>'object','name'=>$name,'description'=>'','image'=>'','category'=>'quick'];if(!$existing)$items['templates'][]=$template;$created=[];
        foreach($ids as $id)$created[]=['id'=>$id,'templateId'=>$template['id'],'revision'=>1,'description'=>$description,'location'=>['kind'=>'inventory','characterId'=>$unit['id']]];
        $items['instances']=[...$items['instances'],...$created];return ['items'=>$items,'result'=>['items'=>$created,'template'=>$template]];
    }
    if($command==='item.category.create'){
        if(!$isGm)itemFailure('Seul le MJ peut créer une catégorie.',403);
        $items['categories']=normalizeItemCategories([...($items['categories']??[]),['id'=>$args['categoryId']??null,'name'=>$args['name']??null]]);
        return ['items'=>$items,'result'=>['category'=>end($items['categories'])]];
    }
    if($command==='item.template.delete'){
        if(!$isGm)itemFailure('Seul le MJ peut supprimer un objet de la bibliothèque.',403);
        $result=deleteItemTemplate($items,$state,$args);return ['items'=>normalizeItemStore($items),'result'=>$result,'activeState'=>$state];
    }
    if ($command === 'item.template.create') {
        if (!$isGm) itemFailure('La création de modèles est réservée au MJ.', 403);
        if (count($items['templates']) >= 200) itemFailure('La collection de modèles est pleine.', 429);
        $template = normalizeItemTemplate(is_array($args['template'] ?? null) ? $args['template'] : []);
        foreach ($items['templates'] as $entry) if ($entry['id'] === $template['id']) itemFailure('Ce modèle existe déjà.', 409);
        if(namedItemTemplate($items,$template['name']))itemFailure('Un modèle porte déjà ce nom. Ajoutez un exemplaire depuis la bibliothèque ou précisez un nom différent.',409);
        if(customItemCategoryId($template['category']??null)&&!in_array($template['category'],array_column($items['categories']??[],'id'),true))itemFailure('Cette catégorie n’existe plus.',409);
        $items['templates'][] = $template;
        return ['items' => $items, 'result' => ['template' => $template]];
    }
    if($command==='item.template.update'){
        if(!$isGm)itemFailure('La modification des objets est réservée au MJ.',403);
        $index=array_search($args['template']['id']??'',array_column($items['templates'],'id'),true);
        if($index===false)itemFailure('Modèle d’objet introuvable.',404);
        $previous=$items['templates'][$index];
        if(normalizeItemTemplate(is_array($args['expectedTemplate']??null)?$args['expectedTemplate']:[])!==$previous)itemFailure('Ce modèle a été modifié par un autre MJ. Rouvrez-le avant de continuer.',409);
        if(!empty($previous['sourceAbilityId'])){
            if(($args['appearanceOnly']??false)!==true)itemFailure('Ce modèle est géré par sa compétence. Seule son apparence peut être modifiée ici.',409);
            $template=normalizeItemTemplate(array_replace($previous,array_intersect_key($args['template'],array_fill_keys(['name','description','image','category'],true))));
            if(itemNameKey($template['name'])!==itemNameKey($previous['name'])&&namedItemTemplate($items,$template['name'],$previous['id']))itemFailure('Un modèle porte déjà ce nom. Précisez un nom différent.',409);
            if(customItemCategoryId($template['category']??null)&&!in_array($template['category'],array_column($items['categories']??[],'id'),true))itemFailure('Cette catégorie n’existe plus.',409);
            $items['templates'][$index]=$template;return ['items'=>$items,'result'=>['template'=>$template]];
        }
        $editable=array_intersect_key($args['template'],array_fill_keys(['name','kind','description','image','document','potion','active','equipment','permissions','size','maxHp','category'],true));
        if(!empty($previous['stockBindings'])&&($editable['kind']??'')!==$previous['kind'])itemFailure('Ce modèle est lié à un stock de compétence : conservez son type.',409);
        $editable['id']=$previous['id'];if(isset($previous['stockBindings']))$editable['stockBindings']=$previous['stockBindings'];
        $template=normalizeItemTemplate($editable);
        if(itemNameKey($template['name'])!==itemNameKey($previous['name'])&&namedItemTemplate($items,$template['name'],$previous['id']))itemFailure('Un modèle porte déjà ce nom. Précisez un nom différent.',409);
        if(($args['applyEquipmentToInstances']??false)===true){
            $instances=array_filter($items['instances'],static fn($item)=>$item['templateId']===$previous['id']&&in_array($item['location']['kind'],['inventory','ground'],true));
            $expected=$args['expectedInstanceRevisions']??null;
            if(!is_array($expected)||count($expected)!==count($instances))itemFailure('Un exemplaire de ce modèle a changé. Rouvrez la bibliothèque avant d’appliquer les réglages à tous.',409);
            foreach($instances as $item)if(($expected[$item['id']]??null)!==$item['revision'])itemFailure('Un exemplaire de ce modèle a changé. Rouvrez la bibliothèque avant d’appliquer les réglages à tous.',409);
            foreach(array_keys($instances) as $ii){if(isset($template['equipment']))$items['instances'][$ii]['equipment']=$template['equipment'];else unset($items['instances'][$ii]['equipment']);$items['instances'][$ii]['revision']++;}
        }
        if(customItemCategoryId($template['category']??null)&&!in_array($template['category'],array_column($items['categories']??[],'id'),true))itemFailure('Cette catégorie n’existe plus.',409);
        $items['templates'][$index]=$template;
        return ['items'=>$items,'result'=>['template'=>$template]];
    }
    if ($command === 'item.spawn') {
        if (!$isGm) itemFailure('La création d’exemplaires est réservée au MJ.', 403);
        $ids=$args['itemIds']??[$args['itemId']??null];
        if(!is_array($ids)||!array_is_list($ids)||count($ids)<1||count($ids)>50||count(array_unique($ids))!==count($ids))itemFailure('Choisissez de 1 à 50 nouveaux exemplaires distincts.',409);
        foreach($ids as $id)if(!itemId($id)||in_array($id,array_column($items['instances'],'id'),true))itemFailure('Choisissez de 1 à 50 nouveaux exemplaires distincts.',409);
        if(count($items['instances'])+count($ids)>2000)itemFailure('La collection d’exemplaires est pleine.',429);
        $index=array_search($args['templateId']??'',array_column($items['templates'],'id'),true);if($index===false)itemFailure('Ce modèle n’existe plus.',409);$model=$items['templates'][$index];
        $location=$args['location']??[];
        if(($location['kind']??'')==='inventory')$character($location['characterId']??null);
        elseif(count($ids)!==1||($location['kind']??'')!=='ground'||!in_array($location['sceneId']??'',array_column($state['scenes']??[],'id'),true))itemFailure('Choisissez un inventaire ou une scène existante.');
        $created=[];foreach($ids as $id){$item=['id'=>$id,'templateId'=>$model['id'],'revision'=>1,'location'=>$location];
            if(isset($model['equipment']))$item['equipment']=$model['equipment'];if(isset($model['size']))$item['size']=$model['size'];if(isset($model['maxHp']))$item['health']=['current'=>$model['maxHp'],'max'=>$model['maxHp']];$created[]=$item;}
        $items['instances']=[...$items['instances'],...$created];
        if($location['kind']==='inventory')rememberLinkedItemStock($items,$character($location['characterId']),$model['id']);
        return ['items'=>normalizeItemStore($items),'result'=>['item'=>$created[0],...(count($ids)>1?['items'=>$created]:[])]];
    }
    $index = array_search($args['itemId'] ?? '', array_column($items['instances'], 'id'), true);
    if ($index === false) itemFailure('Objet introuvable.', 404);
    $item = $items['instances'][$index];
    if ($item['revision'] !== ($args['expectedItemRevision'] ?? null)) itemFailure('Cet objet a changé de place. L’inventaire a été actualisé.', 409);
    if(array_filter($items['pendingUses']??[],static fn($u)=>$u['itemId']===$item['id'])){if($command==='item.destroy'&&$isGm)$items['pendingUses']=array_values(array_filter($items['pendingUses'],static fn($u)=>$u['itemId']!==$item['id']));else itemFailure('Cet objet attend la résolution de son effet. Le MJ peut annuler son activation.',409);}
    if ($command === 'item.move') {
        if (!$isGm) itemFailure('Le déplacement des objets posés est réservé au MJ.', 403);
        foreach ($state['devices']['devices'] ?? [] as $device) if (($device['itemId'] ?? '') === $item['id']) itemFailure('Utilisez les actions du dispositif pour le déplacer.', 409);
        if ($item['location']['kind'] !== 'ground' || $item['location']['sceneId'] !== ($args['sceneId'] ?? '') || $item['location']['layerId'] !== ($args['layerId'] ?? '')
            || !in_array($args['sceneId'] ?? '', array_column($state['scenes'] ?? [], 'id'), true)) itemFailure('Cet objet n’est plus sur ce niveau.', 409);
        $location = $args['location'] ?? [];
        if (!is_array($location) || ($location['kind'] ?? '') !== 'ground' || ($location['sceneId'] ?? '') !== $args['sceneId'] || ($location['layerId'] ?? '') !== $args['layerId']) itemFailure('Choisissez une position sur le même niveau.');
        $item['location'] = $location; $item['revision']++; $items['instances'][$index] = $item;
        return ['items' => normalizeItemStore($items), 'result' => ['item' => $item]];
    }
    $template=$items['templates'][array_search($item['templateId'],array_column($items['templates'],'id'),true)];
    if ($command === 'item.configure') {
        if (!$isGm) itemFailure('La taille et les PV des objets sont réservés au MJ.', 403);
        foreach ($state['devices']['devices'] ?? [] as $device) if (($device['itemId'] ?? '') === $item['id']) itemFailure('Utilisez les réglages du dispositif.', 409);
        if (!in_array($item['location']['kind'], ['ground','inventory'], true)) itemFailure('Cet exemplaire a déjà été utilisé ou détruit.', 409);
        if (array_key_exists('size', $args)) $item['size'] = normalizeItemSize($args['size']);
        if (array_key_exists('health', $args)) {
            if ($args['health'] === null) unset($item['health']);
            else {
                if (!itemPermissions($template)['destroy']) itemFailure('Activez le droit Détruire avant d’ajouter des PV à l’objet.', 409);
                $item['health'] = normalizeItemHealth($args['health']);
                if ($item['health']['current'] === 0) {
                    if ($item['location']['kind'] === 'inventory') rememberLinkedItemStock($items, $character($item['location']['characterId']), $item['templateId']);
                    $item['location'] = ['kind' => 'destroyed'];
                }
            }
        }
        $item['revision']++; $items['instances'][$index] = $item;
        return ['items' => normalizeItemStore($items), 'result' => ['item' => $item]];
    }
    if(!$isGm&&itemPermissions($template)['boundToCarrier']&&in_array($command,['item.drop','item.transfer'],true))itemFailure('Cet objet est lié à son porteur. Seul le MJ peut le déplacer.',403);
    if(!$isGm&&in_array($command,['item.pickup','item.transfer','item.destroy'],true)&&!itemPermissions($template)[substr($command,5)])itemFailure('Le MJ n’autorise pas cette action sur cet objet.',403);
    if($command==='item.destroy'){
        foreach($state['devices']['devices']??[] as $device)if(($device['itemId']??'')===$item['id'])itemFailure('Utilisez les actions du dispositif pour le retirer.',409);
        if($item['location']['kind']==='inventory'){
            $source=$owned($args['characterId']??null);if($item['location']['characterId']!==$source['id'])itemFailure('Cet objet n’est plus dans cet inventaire.',409);
            rememberLinkedItemStock($items,$source,$item['templateId']);
        }elseif($item['location']['kind']==='ground'){
            if($item['location']['sceneId']!==($args['sceneId']??''))itemFailure('Cet objet n’est plus sur cette scène.',409);
            if(!$isGm){
                $owned($args['characterId']??null);$token=$tokenFor($args['characterId']??null);
                if($item['location']['layerId']!==($token['layerId']??$state['map']['activeLayerId']??'ground'))itemFailure('Cet objet n’est plus sur ce niveau.',409);
                if(!itemWithinReach($item['location'],$token,$state['map']))itemFailure('Approchez votre pion de l’objet pour le détruire.',403);
            }
        }else itemFailure('Cet exemplaire a déjà été utilisé ou détruit.',409);
        $item['location']=['kind'=>'destroyed'];if(isset($item['health']))$item['health']['current']=0;$item['revision']++;$items['instances'][$index]=$item;
        return ['items'=>normalizeItemStore($items),'result'=>['item'=>$item]];
    }
    if($command==='item.loot'){
        $receiver=$owned($args['characterId']??'');$source=$tokenFor($receiver['id']);
        $reason=itemLootAccess($state,$args['targetTokenId']??'',$source['id'],$context);
        if($reason!=='')itemFailure($reason,!empty($state['tacticalSync']['paused'])?423:403);
        $target=null;foreach($state['map']['tokens'] as $t)if($t['id']===$args['targetTokenId'])$target=$t;$targetId=unitIdForToken($target);
        if($item['location']['kind']!=='inventory'||$item['location']['characterId']!==$targetId)itemFailure('Cet objet n’est plus dans cet inventaire.',409);
        $permissions=itemPermissions($template);$device=false;foreach($state['devices']['devices']??[] as $d)if(($d['itemId']??'')===$item['id'])$device=true;
        if(!$permissions['pickup']||$permissions['boundToCarrier']||$device)itemFailure('Cet objet est lié à son porteur ou réservé par le MJ et ne peut pas être pris.',403);
        rememberLinkedItemStock($items,$character($targetId),$item['templateId']);rememberLinkedItemStock($items,$receiver,$item['templateId']);
        $item['location']=['kind'=>'inventory','characterId'=>$receiver['id']];$item['revision']++;$items['instances'][$index]=$item;
        return ['items'=>normalizeItemStore($items),'result'=>['item'=>$item]];
    }
    $patch = null;
    if ($command === 'item.pickup') {
        $owned($args['characterId'] ?? null); $token = $tokenFor($args['characterId'] ?? null);
        if ($item['location']['kind'] !== 'ground' || $item['location']['sceneId'] !== ($args['sceneId'] ?? '') || $item['location']['layerId'] !== ($token['layerId'] ?? $state['map']['activeLayerId'] ?? 'ground')) itemFailure('Cet objet n’est plus sur ce niveau.', 409);
        if (!$isGm && !itemWithinReach($item['location'], $token, $state['map'])) itemFailure('Approchez votre pion de l’objet pour le ramasser.', 403);
        $item['location'] = ['kind' => 'inventory', 'characterId' => $args['characterId']];
    } else {
        if ($item['location']['kind'] !== 'inventory' || $item['location']['characterId'] !== ($args['characterId'] ?? '')) itemFailure('Cet objet n’est plus dans cet inventaire.', 409);
        $source = $owned($args['characterId'] ?? null);
        rememberLinkedItemStock($items, $source, $item['templateId']);
        if ($command === 'item.transfer') {
            $target = $character($args['targetCharacterId'] ?? null);
            if (!$isGm && ($target['ownerPlayerId'] ?? '') === '') itemFailure('Ce personnage n’appartient à aucun joueur.', 403);
            if ($source['id'] === $target['id']) itemFailure('L’objet se trouve déjà dans cet inventaire.');
            if(!$isGm){
                if(isset($args['sceneId'])&&$args['sceneId']!==($state['activeSceneId']??''))itemFailure('La scène a changé.',409);
                if(!empty($state['tacticalSync']['paused']))itemFailure('La table est temporairement verrouillée par le MJ.',423);
                if(itemTransferPair($state,$source['id'],$target['id'],$args)===null)itemFailure('Pour donner cet objet, les deux pions doivent être à 5 cases maximum sur la même scène et le même étage.',409);
            }
            $item['location'] = ['kind' => 'inventory', 'characterId' => $target['id']];
        } elseif ($command === 'item.drop') {
            $token = $tokenFor($source['id']);
            $item['location'] = ['kind' => 'ground', 'sceneId' => $state['activeSceneId'], 'layerId' => $token['layerId'] ?? $state['map']['activeLayerId'] ?? 'ground', 'x' => $token['x'], 'y' => $token['y']];
        } elseif ($command === 'item.consume') {
            $template = $items['templates'][array_search($item['templateId'], array_column($items['templates'], 'id'), true)];
            if(isset($template['active'])&&(isset($template['active']['targeting'])||isset($template['active']['trap'])||array_filter($template['active']['effects'],static fn($e)=>in_array($e['kind'],['damage','dot','condition','summon'],true)))){$result=applyItemUseCommand($state,$items,$identity,'item.activate',$args,$context);return ['items'=>normalizeItemStore($items),'result'=>$result,'activeState'=>$state];}
            if(isset($template['active'])){
                if(!empty($state['tacticalSync']['paused'])&&!$isGm)itemFailure('La table est temporairement verrouillée par le MJ.',423);
                if(($args['sceneId']??'')!==$state['activeSceneId'])itemFailure('La scène a changé.',409);$token=null;foreach($state['map']['tokens']??[] as $t)if(unitIdForToken($t)===$source['id']&&(!isset($args['tokenId'])||$args['tokenId']===$t['id'])&&($t['layerId']??'ground')===($state['map']['activeLayerId']??'ground')){$token=$t;break;}
                $result=applicationApplyActiveItem($state,$template,$source,$token,$identity,$context);$item['location']=['kind'=>'consumed','characterId'=>$source['id']];$item['revision']++;$items['instances'][$index]=$item;
                return ['items'=>normalizeItemStore($items),'result'=>['item'=>$item,...$result],'characterPatch'=>['id'=>$source['id'],'resources'=>$source['resources'],'fatigue'=>$source['fatigue']??['current'=>0,'max'=>150],'conditions'=>$source['conditions']??[]],'activeState'=>$state];
            }
            if ($template['kind'] !== 'potion') itemFailure('Cet objet n’est pas une potion.');
            $resources = $source['resources'] ?? [];
            foreach (['hp', 'mana'] as $resource) $resources[$resource] = max($resource === 'hp' ? -1000000000 : 0, min($resources[$resource === 'hp' ? 'maxHp' : 'maxMana'] ?? 0, ($resources[$resource] ?? 0) + $template['potion'][$resource]));
            $fatigue = $source['fatigue'] ?? []; $fatigue['current'] = max(0, min($fatigue['max'] ?? 150, ($fatigue['current'] ?? 0) + $template['potion']['fatigue']));
            $patch = ['id' => $source['id'], 'resources' => $resources, 'fatigue' => $fatigue];
            $item['location'] = ['kind' => 'consumed', 'characterId' => $source['id']];
        } else itemFailure('Action d’objet inconnue.');
    }
    if ($item['location']['kind'] === 'inventory') rememberLinkedItemStock($items, $character($item['location']['characterId']), $item['templateId']);
    $item['revision']++; $items['instances'][$index] = $item;
    return ['items' => normalizeItemStore($items), 'result' => ['item' => $item], 'characterPatch' => $patch];
}

function linkedItemTemplateIds(array $character, array $templates = []): array {
    $ids = []; $abilityIds = [];
    foreach ($character['abilities'] ?? [] as $ability) {
        $abilityIds[(string) ($ability['id'] ?? '')] = true;
        if (($ability['device']['kind'] ?? '') === 'launcher' && itemId($ability['device']['ammoTemplateId'] ?? null)) $ids[$ability['device']['ammoTemplateId']] = true;
    }
    foreach ($templates as $template) if (($template['creatorCharacterId'] ?? null) === ($character['id'] ?? false)
        && isset($abilityIds[$template['sourceAbilityId'] ?? ''])) $ids[$template['id']] = true;
    foreach($templates as $template)foreach($template['stockBindings']??[] as $link)if($link['characterId']===($character['id']??'')&&isset($abilityIds[$link['abilityId']]))$ids[$template['id']]=true;
    return array_values(array_map(static fn(array $template): string => $template['id'], array_filter($templates,
        static fn(array $template): bool => $template['kind'] === 'object' && isset($ids[$template['id']]))));
}

function rememberLinkedItemStock(array &$store, ?array $character, string $templateId): void {
    $sourceAbilityId='';foreach($store['templates'] as $entry)if($entry['id']===$templateId)$sourceAbilityId=$entry['sourceAbilityId']??'';
    $ability = null;
    foreach ($character['abilities'] ?? [] as $entry) if (itemId($entry['id'] ?? null)&&(($entry['device']['kind'] ?? '') === 'launcher'
        && ($entry['device']['ammoTemplateId'] ?? '') === $templateId || $sourceAbilityId===($entry['id']??''))) { $ability = $entry; break; }
    if ($ability === null) return;
    foreach ($store['templates'] as &$template) {
        if ($template['id'] !== $templateId || $template['kind'] !== 'object') continue;
        $bindings = $template['stockBindings'] ?? [];
        foreach ($bindings as $link) if ($link['characterId'] === $character['id'] && $link['abilityId'] === $ability['id']) return;
        if (count($bindings) >= 1000) itemFailure('Le stock atteint sa limite de liens.', 409);
        $template['stockBindings'] = [...$bindings, ['characterId' => $character['id'], 'abilityId' => $ability['id']]];
        return;
    }
}

function readableLinkedStock(array $store, array $character, string $templateId): bool {
    $abilities = array_fill_keys(array_column($character['abilities'] ?? [], 'id'), true);
    foreach ($store['templates'] ?? [] as $template) if ($template['id'] === $templateId) {
        if (($template['creatorCharacterId'] ?? '') === $character['id'] && isset($abilities[$template['sourceAbilityId'] ?? ''])) return true;
        foreach ($template['stockBindings'] ?? [] as $link) if ($link['characterId'] === $character['id'] && isset($abilities[$link['abilityId']])) return true;
    }
    foreach ($store['instances'] ?? [] as $item) if ($item['templateId'] === $templateId
        && in_array($item['location']['kind'], ['inventory', 'consumed'], true) && $item['location']['characterId'] === $character['id']) return true;
    return false;
}

function publicItemStore(array $store, array $characters, ?array $identity, array $context, callable $visible): array {
    foreach($context['tokens']??[] as $token)if(!empty($identity['id'])&&(($token['ownedByYou']??false)===true||($token['controllerPlayerId']??'')===$identity['id'])&&!unitUsesCharacterSheet($token))
        $characters[]=[...$token,'id'=>unitIdForToken($token),'ownerPlayerId'=>$identity['id']];
    $owned = [];
    foreach ($characters as $character) if (($character['ownerPlayerId'] ?? null) === ($identity['id'] ?? false)) $owned[$character['id']] = true;
    foreach($context['tokens']??[] as $token)if(!empty($identity['id'])&&($token['ownedByYou']??false)===true)$owned[unitIdForToken($token)]=true;
    $lootable=[];$lootState=['characters'=>$characters,'activeSceneId'=>$context['sceneId']??'','map'=>[...($context['map']??[]),'activeLayerId'=>$context['layerId']??'ground','tokens'=>$context['tokens']??[]],'tacticalSync'=>['paused'=>$context['paused']??false]];
    $inventoryOwners=[];foreach($store['instances']??[] as $i)if($i['location']['kind']==='inventory')$inventoryOwners[$i['location']['characterId']]=true;
    if(!empty($identity['id']))foreach($context['tokens']??[] as $target)if(isset($inventoryOwners[unitIdForToken($target)])&&!isset($owned[unitIdForToken($target)])&&itemTokenHealth($lootState,$target)==='dead')foreach($context['tokens']??[] as $source)if(isset($owned[unitIdForToken($source)])&&itemLootAccess($lootState,$target['id'],$source['id'],['visible'=>$visible])===''){$lootable[unitIdForToken($target)]=true;break;}
    $instances = []; $ids = []; $ownIds = []; $lootIds=[];
    foreach ($store['instances'] ?? [] as $item) {
        $location = $item['location']; $inventory = $location['kind'] === 'inventory' && isset($owned[$location['characterId']]);
        $ground = $location['kind'] === 'ground' && !($context['paused'] ?? false) && $location['sceneId'] === ($context['sceneId'] ?? '') && $location['layerId'] === ($context['layerId'] ?? '') && $visible($location);
        $loot=$location['kind']==='inventory'&&isset($lootable[$location['characterId']]);
        if (!$inventory && !$ground && !$loot) continue;
        $instances[] = $item; $ids[$item['templateId']] = true;
        if ($inventory) $ownIds[$item['templateId']] = true;
        if($loot)$lootIds[$item['templateId']]=true;
    }
    foreach ($characters as $character) if (isset($owned[$character['id']])) {
        foreach (linkedItemTemplateIds($character, $store['templates'] ?? []) as $templateId) {
            if (readableLinkedStock($store, $character, $templateId)) { $ids[$templateId] = true; $ownIds[$templateId] = true; }
        }
    }
    foreach($store['inventoryCatalog']??[] as $unitId=>$catalogIds)if(isset($owned[$unitId]))foreach($catalogIds as $templateId){$ownIds[$templateId]=true;$ids[$templateId]=true;}
    $templates = []; $readableIds=[];
    foreach ($store['templates'] ?? [] as $template) if (isset($ids[$template['id']])) {
        $mayRead=itemPermissions($template)['read']&&(isset($ownIds[$template['id']])||isset($lootIds[$template['id']])||($template['permissions']['read']??false)===true);
        if($mayRead)$readableIds[$template['id']]=true;
        $templates[]=$mayRead?publicItemPhysical($template):array_intersect_key($template,array_fill_keys(['id','name','kind','image','permissions','size'],true));
    }
    foreach($instances as &$instance)if(!isset($owned[$instance['location']['characterId']??''])&&!isset($readableIds[$instance['templateId']]))unset($instance['description'],$instance['equipment']);unset($instance);
    $uses=[];if(empty($context['paused']))foreach($store['pendingUses']??[] as $u){if($u['phase']!=='opposition'||$u['sceneId']!==($context['sceneId']??'')||$u['layerId']!==($context['layerId']??''))continue;$targets=[];foreach($u['targets'] as $t)foreach($context['tokens']??[] as $token)if($token['id']===$t['tokenId']&&isset($owned[unitIdForToken($token)]))$targets[]=['tokenId'=>$t['tokenId'],'status'=>$t['status']];if($targets)$uses[]=[...array_intersect_key($u,array_flip(['id','itemId','itemRevision','sceneId','layerId','name','phase'])),'targets'=>$targets];}
    return ['version' => 1, 'templates' => $templates, 'instances' => array_map('publicItemPhysical', $instances), ...(isset($store['pendingUses'])?['pendingUses'=>$uses]:[]),...(isset($store['categories'])?['categories'=>array_values(array_filter($store['categories'],static fn($c)=>in_array($c['id'],array_column($templates,'category'),true)))]:[]),...(isset($store['inventoryCatalog'])?['inventoryCatalog'=>array_intersect_key($store['inventoryCatalog'],$owned)]:[]),...(isset($store['equipmentLoadouts'])?['equipmentLoadouts'=>array_intersect_key($store['equipmentLoadouts'],$owned)]:[])];
}

function onlineItemCommand(PDO $connection, array &$records, array &$pending, array $table, array $identity, string $command, array $args, bool $isGm): array {
    require_once __DIR__.'/device-items.php';
    $requestId = $args['requestId'] ?? '';
    if (!is_string($requestId) || preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $requestId) !== 1) rejectOnlineCommand($connection, 400, 'Référence d’objet invalide.', 'invalid_item_request');
    $signature = hash('sha256', json_encode(canonicalApplicationDomainValue(['command' => $command, 'arguments' => $args]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $context = onlinePersistentCommandReceipt($connection, $records, 'item-command', $requestId, $identity['id'], $signature, 'item');
    if (is_array($context['receipt'] ?? null)) return [...($isGm ? $context['receipt']['result'] : publicItemCommandResult($context['receipt']['result'])), 'deduplicated' => true];
    $records = array_replace($records, applicationDomainRecords($connection, ['items', 'devices', 'scene-index']), applicationDomainRecordsByPrefix($connection, 'character:'));
    $sceneId = $isGm && itemId($args['sceneId'] ?? null) ? $args['sceneId'] : onlineActiveSceneId($table);
    $records = array_replace($records, applicationDomainRecords($connection, ['map:' . $sceneId,'initiative:'.$sceneId]));
    $records = onlineSceneTokenRecords($connection, $sceneId, $records);
    $map = applicationDomainPayload($records, 'map:' . $sceneId); $map['tokens'] = onlineSceneTokensForLights($connection, $records, $pending, $sceneId);
    $characters = [];
    foreach ($records as $key => $record) if (str_starts_with($key, 'character:') && is_array($record['payload'] ?? null)) $characters[] = $record['payload'];
    $state=onlineDeviceSelectScene(onlineDeviceState($connection,$records,$pending),$sceneId);
    $state['tacticalSync']=$table['tacticalSync']??[];
    if (in_array($command,['item.pickup','item.destroy'],true) && !$isGm) {
        $visible = onlineMapMovementVisibility($connection, $records, $map, $identity['id'], $sceneId);
        foreach ($state['items']['instances'] ?? [] as $item) if ($item['id'] === ($args['itemId'] ?? '') && $item['location']['kind'] === 'ground' && !$visible($item['location']['x'], $item['location']['y'])) rejectOnlineCommand($connection, 403, 'Cet objet n’est pas visible sur la carte.', 'item_not_visible');
    }
    try {
        ensureDeviceItems($state,static fn($prefix)=>$prefix.'-'.randomToken(16));
        $itemVisible=$isGm?static fn()=>true:onlineMapMovementVisibility($connection,$records,$state['map'],$identity['id'],$sceneId);
        $applied = applyDynamicItemCommand($state, ['id' => $identity['id'], 'role' => $isGm ? 'gm' : 'player'], $command, $args, ['id'=>static fn($prefix)=>$prefix.'-'.randomToken(16),'visible'=>static fn($point)=>$isGm||$itemVisible($point['x'],$point['y']),'rollRecord'=>static fn($rolled,$label,$name)=>onlineRollEntry($identity,$rolled,$label,$name),'rollFormula'=>static fn($formula,$label,$name)=>onlineRollEntry($identity,onlineRollFormula($formula),$label,$name),'rollDie'=>static function($sides,$label,$name)use($identity){$n=random_int(1,$sides);return onlineRollEntry($identity,['formula'=>'1d'.$sides,'total'=>$n,'breakdown'=>(string)$n],$label,$name);}]);
        if(function_exists('assertPhysicalDeviceItemAction'))assertPhysicalDeviceItemAction($state,['id'=>$identity['id'],'role'=>$isGm?'gm':'player'],$command,$args,['direct'=>'onlineDeviceDirect']);
        $state=$applied['activeState']??$state;
        $state['items']=$applied['items'];reconcileEquipmentEnchantments($state);synchronizeDeviceItemLocations($state,(string)($args['itemId']??''),['unitId'=>$args['characterId']??'','tokenId'=>$args['tokenId']??'']);
        if($command==='item.template.update')synchronizeDeviceTemplateAppearance($state,$applied['result']['template']);
    }
    catch (RuntimeException $error) { rejectOnlineCommand($connection, in_array($error->getCode(), [400, 403, 404, 409, 423, 429], true) ? $error->getCode() : 400, $error->getMessage(), 'item_command_refused'); }
    queueOnlineDomainUpsert($pending, $records, 'items', $applied['items']);
    $activity=$pending['activity']['payload']??applicationDomainPayload($records,'activity');if(($activity['attackEnhancements']??[])!==($state['attackEnhancements']??[])){$activity['attackEnhancements']=$state['attackEnhancements']??[];queueOnlineDomainUpsert($pending,$records,'activity',$activity);}
    if(!empty($state['devices']))queueOnlineDomainUpsert($pending,$records,'devices',$state['devices']);
    if (is_array($applied['characterPatch'] ?? null)) {
        $patch = $applied['characterPatch']; $unit=unitById($state,$patch['id']);
        if(($unit['unitKind']??'')==='token'){
            $unit=array_replace($unit,$patch);persistUnitResources($state,$unit);
            if(isset($patch['conditions']))foreach($state['map']['tokens'] as &$t)if($t['id']===$unit['unitTokenId']){$t['conditions']=$patch['conditions'];$t['condition']=implode(', ',$patch['conditions']);}unset($t);
            foreach($state['map']['tokens'] as $token)if($token['id']===$unit['unitTokenId'])queueOnlineDomainUpsert($pending,$records,onlineTokenDomainKey($sceneId,$token['id']),$token);
        }else{
        $key = 'character:' . $patch['id'];
        $updated = applicationDomainPayload($records, $key); $updated['resources'] = $patch['resources']; $updated['fatigue'] = $patch['fatigue']; if(isset($patch['conditions']))$updated['conditions']=$patch['conditions']; $updated['_updatedAt'] = (int) floor(microtime(true) * 1000);
        queueOnlineDomainUpsert($pending, $records, $key, $updated);
        foreach (applicationCharacterTokenDomainRecords($connection, $patch['id']) as $tokenKey => $record) {
            $records[$tokenKey] = $record;
            if (($record['payload']['followCharacter'] ?? true) !== false && empty($record['payload']['linkedTokenId'])) queueOnlineDomainUpsert($pending, $records, $tokenKey, synchronizeOnlineCharacterToken($record['payload'], $updated));
        }
        }
    }
    if(isset($applied['activeState'])){
        foreach($applied['result']['rolls']??[] as $roll)$state['rolls']=array_slice([$roll,...($state['rolls']??[])],0,200);
        $token=null;foreach($state['map']['tokens']??[] as $t)if(unitIdForToken($t)===($args['characterId']??'')){$token=$t;break;}
        foreach($applied['result']['messages']??[] as $message){$cue=['version'=>1,'trigger'=>'completed','sound'=>null,'visual'=>[...$message,'version'=>1,'kind'=>'message']];appendApplicationAbilityCueEvent($state,$cue,$sceneId,$token['layerId']??'ground',$token['id']??'');}
        // Keep resources/conditions already queued above while flushing authoritative effects.
        foreach($state['characters'] as $i=>$c)if(isset($pending['character:'.$c['id']]['payload']))$state['characters'][$i]=$pending['character:'.$c['id']]['payload'];
        foreach($state['map']['tokens'] as $i=>$t)if(isset($pending[onlineTokenDomainKey($sceneId,$t['id'])]['payload']))$state['map']['tokens'][$i]=$pending[onlineTokenDomainKey($sceneId,$t['id'])]['payload'];
        onlineDeviceFlush($state,$records,$pending);
        foreach(legacyStateToDomains($state) as $k=>$p)if(str_starts_with($k,'map:')&&dsCanonical($pending[$k]['payload']??applicationDomainPayload($records,$k))!==dsCanonical($p))queueOnlineDomainUpsert($pending,$records,$k,$p);
    }
    $result = isset($applied['result']['template']) ? ['templateId' => $applied['result']['template']['id']] : $applied['result'];
    if (!$isGm) $result = publicItemCommandResult($result);
    onlineStorePersistentCommandReceipt($records, $pending, $context, 'item-command', $requestId, $identity['id'], 'item-' . $requestId, 'items', $signature, $result);
    return $result;
}
