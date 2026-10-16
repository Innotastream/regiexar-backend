<?php
declare(strict_types=1);
require_once __DIR__.'/unit-authority.php';

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
    if ($name === '') itemFailure('Donnez un nom à l’objet.');
    $image = itemImage($value['image'] ?? '');
    if (($value['image'] ?? '') !== '' && $image === '') itemFailure('L’image de l’objet est invalide.');
    $result = ['id' => $value['id'], 'kind' => $value['kind'], 'name' => $name, 'description' => itemText($value['description'] ?? '', 4000), 'image' => $image];
    if(array_key_exists('permissions',$value))$result['permissions']=normalizeItemPermissions($value['permissions']);
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
    $templates = []; $ids = [];
    foreach ($value['templates'] as $template) {
        if (!is_array($template)) itemFailure('Le modèle d’objet est invalide.');
        $template = normalizeItemTemplate($template);
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
        $instances[] = ['id' => $item['id'], 'templateId' => $item['templateId'], 'revision' => $item['revision'], 'location' => $clean];
    }
    return ['version' => 1, 'templates' => $templates, 'instances' => $instances];
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

function applyDynamicItemCommand(array $state, array $identity, string $command, array $args): array {
    $items = normalizeItemStore($state['items'] ?? null); $isGm = ($identity['role'] ?? '') === 'gm';
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
    if ($command === 'item.template.create') {
        if (!$isGm) itemFailure('La création de modèles est réservée au MJ.', 403);
        if (count($items['templates']) >= 200) itemFailure('La collection de modèles est pleine.', 429);
        $template = normalizeItemTemplate(is_array($args['template'] ?? null) ? $args['template'] : []);
        foreach ($items['templates'] as $entry) if ($entry['id'] === $template['id']) itemFailure('Ce modèle existe déjà.', 409);
        $items['templates'][] = $template;
        return ['items' => $items, 'result' => ['template' => $template]];
    }
    if($command==='item.template.update'){
        if(!$isGm)itemFailure('La modification des objets est réservée au MJ.',403);
        $index=array_search($args['template']['id']??'',array_column($items['templates'],'id'),true);
        if($index===false)itemFailure('Modèle d’objet introuvable.',404);
        $previous=$items['templates'][$index];
        if(!empty($previous['sourceAbilityId']))itemFailure('Ce modèle est géré par sa compétence.',409);
        if(normalizeItemTemplate(is_array($args['expectedTemplate']??null)?$args['expectedTemplate']:[])!==$previous)itemFailure('Ce modèle a été modifié par un autre MJ. Rouvrez-le avant de continuer.',409);
        $editable=array_intersect_key($args['template'],array_fill_keys(['name','kind','description','image','document','potion','permissions'],true));
        if(!empty($previous['stockBindings'])&&($editable['kind']??'')!==$previous['kind'])itemFailure('Ce modèle est lié à un stock de compétence : conservez son type.',409);
        $editable['id']=$previous['id'];if(isset($previous['stockBindings']))$editable['stockBindings']=$previous['stockBindings'];
        $template=normalizeItemTemplate($editable);$items['templates'][$index]=$template;
        return ['items'=>$items,'result'=>['template'=>$template]];
    }
    if ($command === 'item.spawn') {
        if (!$isGm) itemFailure('La création d’exemplaires est réservée au MJ.', 403);
        if (count($items['instances']) >= 2000) itemFailure('La collection d’exemplaires est pleine.', 429);
        if (!itemId($args['itemId'] ?? null) || !in_array($args['templateId'] ?? '', array_column($items['templates'], 'id'), true) || in_array($args['itemId'], array_column($items['instances'], 'id'), true)) itemFailure('L’exemplaire d’objet est invalide.', 409);
        $location = $args['location'] ?? [];
        if (($location['kind'] ?? '') === 'inventory') $character($location['characterId'] ?? null);
        elseif (($location['kind'] ?? '') !== 'ground' || !in_array($location['sceneId'] ?? '', array_column($state['scenes'] ?? [], 'id'), true)) itemFailure('Choisissez un inventaire ou une scène existante.');
        $item = ['id' => $args['itemId'], 'templateId' => $args['templateId'], 'revision' => 1, 'location' => $location];
        $items['instances'][] = $item;
        if ($location['kind'] === 'inventory') rememberLinkedItemStock($items, $character($location['characterId']), $item['templateId']);
        return ['items' => normalizeItemStore($items), 'result' => ['item' => $item]];
    }
    $index = array_search($args['itemId'] ?? '', array_column($items['instances'], 'id'), true);
    if ($index === false) itemFailure('Objet introuvable.', 404);
    $item = $items['instances'][$index];
    if ($item['revision'] !== ($args['expectedItemRevision'] ?? null)) itemFailure('Cet objet a changé de place. L’inventaire a été actualisé.', 409);
    $template=$items['templates'][array_search($item['templateId'],array_column($items['templates'],'id'),true)];
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
        $item['location']=['kind'=>'destroyed'];$item['revision']++;$items['instances'][$index]=$item;
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
    foreach($context['tokens']??[] as $token)if(!empty($identity['id'])&&($token['controllerPlayerId']??'')===$identity['id']&&!unitUsesCharacterSheet($token))
        $characters[]=[...$token,'id'=>unitIdForToken($token),'ownerPlayerId'=>$identity['id']];
    $owned = [];
    foreach ($characters as $character) if (($character['ownerPlayerId'] ?? null) === ($identity['id'] ?? false)) $owned[$character['id']] = true;
    $instances = []; $ids = []; $ownIds = [];
    foreach ($store['instances'] ?? [] as $item) {
        $location = $item['location']; $inventory = $location['kind'] === 'inventory' && isset($owned[$location['characterId']]);
        $ground = $location['kind'] === 'ground' && !($context['paused'] ?? false) && $location['sceneId'] === ($context['sceneId'] ?? '') && $location['layerId'] === ($context['layerId'] ?? '') && $visible($location);
        if (!$inventory && !$ground) continue;
        $instances[] = $item; $ids[$item['templateId']] = true;
        if ($inventory) $ownIds[$item['templateId']] = true;
    }
    foreach ($characters as $character) if (isset($owned[$character['id']])) {
        foreach (linkedItemTemplateIds($character, $store['templates'] ?? []) as $templateId) {
            if (readableLinkedStock($store, $character, $templateId)) { $ids[$templateId] = true; $ownIds[$templateId] = true; }
        }
    }
    $templates = [];
    foreach ($store['templates'] ?? [] as $template) if (isset($ids[$template['id']])) {
        $mayRead=itemPermissions($template)['read']&&(isset($ownIds[$template['id']])||($template['permissions']['read']??false)===true);
        $templates[]=$mayRead?$template:array_intersect_key($template,array_fill_keys(['id','name','kind','image','permissions'],true));
    }
    return ['version' => 1, 'templates' => $templates, 'instances' => $instances];
}

function onlineItemCommand(PDO $connection, array &$records, array &$pending, array $table, array $identity, string $command, array $args, bool $isGm): array {
    require_once __DIR__.'/device-items.php';
    $requestId = $args['requestId'] ?? '';
    if (!is_string($requestId) || preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $requestId) !== 1) rejectOnlineCommand($connection, 400, 'Référence d’objet invalide.', 'invalid_item_request');
    $signature = hash('sha256', json_encode(canonicalApplicationDomainValue(['command' => $command, 'arguments' => $args]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $context = onlinePersistentCommandReceipt($connection, $records, 'item-command', $requestId, $identity['id'], $signature, 'item');
    if (is_array($context['receipt'] ?? null)) return [...$context['receipt']['result'], 'deduplicated' => true];
    $records = array_replace($records, applicationDomainRecords($connection, ['items', 'devices', 'scene-index']), applicationDomainRecordsByPrefix($connection, 'character:'));
    $sceneId = $isGm && itemId($args['sceneId'] ?? null) ? $args['sceneId'] : onlineActiveSceneId($table);
    $records = array_replace($records, applicationDomainRecords($connection, ['map:' . $sceneId,'initiative:'.$sceneId]));
    $records = onlineSceneTokenRecords($connection, $sceneId, $records);
    $map = applicationDomainPayload($records, 'map:' . $sceneId); $map['tokens'] = onlineSceneTokensForLights($connection, $records, $pending, $sceneId);
    $characters = [];
    foreach ($records as $key => $record) if (str_starts_with($key, 'character:') && is_array($record['payload'] ?? null)) $characters[] = $record['payload'];
    $state = ['items' => applicationDomainPayload($records, 'items'), 'devices'=>$pending['devices']['payload']??applicationDomainPayload($records,'devices'), 'characters' => $characters, 'activeSceneId' => $sceneId, 'map' => $map,
        'initiative'=>$pending['initiative:'.$sceneId]['payload']??applicationDomainPayload($records,'initiative:'.$sceneId),
        'scenes' => array_map(static fn($id) => ['id' => $id], applicationDomainPayload($records, 'scene-index')['order'] ?? []), 'tacticalSync' => $table['tacticalSync'] ?? []];
    if (in_array($command,['item.pickup','item.destroy'],true) && !$isGm) {
        $visible = onlineMapMovementVisibility($connection, $records, $map, $identity['id'], $sceneId);
        foreach ($state['items']['instances'] ?? [] as $item) if ($item['id'] === ($args['itemId'] ?? '') && $item['location']['kind'] === 'ground' && !$visible($item['location']['x'], $item['location']['y'])) rejectOnlineCommand($connection, 403, 'Cet objet n’est pas visible sur la carte.', 'item_not_visible');
    }
    try {
        ensureDeviceItems($state,static fn($prefix)=>$prefix.'-'.randomToken(16));
        $applied = applyDynamicItemCommand($state, ['id' => $identity['id'], 'role' => $isGm ? 'gm' : 'player'], $command, $args);
        if(function_exists('assertPhysicalDeviceItemAction'))assertPhysicalDeviceItemAction($state,['id'=>$identity['id'],'role'=>$isGm?'gm':'player'],$command,$args,['direct'=>'onlineDeviceDirect']);
        $state['items']=$applied['items'];synchronizeDeviceItemLocations($state,(string)($args['itemId']??''),['unitId'=>$args['characterId']??'','tokenId'=>$args['tokenId']??'']);
    }
    catch (RuntimeException $error) { rejectOnlineCommand($connection, in_array($error->getCode(), [400, 403, 404, 409, 423, 429], true) ? $error->getCode() : 400, $error->getMessage(), 'item_command_refused'); }
    queueOnlineDomainUpsert($pending, $records, 'items', $applied['items']);
    if(!empty($state['devices']))queueOnlineDomainUpsert($pending,$records,'devices',$state['devices']);
    if (is_array($applied['characterPatch'] ?? null)) {
        $patch = $applied['characterPatch']; $unit=unitById($state,$patch['id']);
        if(($unit['unitKind']??'')==='token'){
            $unit=array_replace($unit,$patch);persistUnitResources($state,$unit);
            foreach($state['map']['tokens'] as $token)if($token['id']===$unit['unitTokenId'])queueOnlineDomainUpsert($pending,$records,onlineTokenDomainKey($sceneId,$token['id']),$token);
        }else{
        $key = 'character:' . $patch['id'];
        $updated = applicationDomainPayload($records, $key); $updated['resources'] = $patch['resources']; $updated['fatigue'] = $patch['fatigue']; $updated['_updatedAt'] = (int) floor(microtime(true) * 1000);
        queueOnlineDomainUpsert($pending, $records, $key, $updated);
        foreach (applicationCharacterTokenDomainRecords($connection, $patch['id']) as $tokenKey => $record) {
            $records[$tokenKey] = $record;
            if (($record['payload']['followCharacter'] ?? true) !== false && empty($record['payload']['linkedTokenId'])) queueOnlineDomainUpsert($pending, $records, $tokenKey, synchronizeOnlineCharacterToken($record['payload'], $updated));
        }
        }
    }
    $result = isset($applied['result']['template']) ? ['templateId' => $applied['result']['template']['id']] : $applied['result'];
    onlineStorePersistentCommandReceipt($records, $pending, $context, 'item-command', $requestId, $identity['id'], 'item-' . $requestId, 'items', $signature, $result);
    return $result;
}
