<?php
declare(strict_types=1);
require_once __DIR__ . '/dynamic-items.php';

function assistantObjectPlanFailure(string $message, int $status = 400): never { throw new RuntimeException($message, $status); }
function assistantObjectTemplate(array $fields): array {
    try { return normalizeItemTemplate($fields); }
    catch (TypeError|ValueError $error) { assistantObjectPlanFailure('Un champ du modèle d’objet est invalide.'); }
}
function assistantObjectTemplateSignature(array $template): string {
    return hash('sha256', json_encode(canonicalApplicationDomainValue(normalizeItemTemplate($template)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function abilityAssistantObjectContext(array $records, array $character, array $identity): array {
    $isGm = ($identity['effective_mode'] ?? '') === 'gm' && ($identity['permanent_role'] ?? '') === 'gm';
    $store = normalizeItemStore(applicationDomainPayload($records, 'items', ['version'=>1,'templates'=>[],'instances'=>[]]));
    $characterId = (string) $character['id']; $counts = []; $known = []; $managed = [];
    foreach ($store['instances'] as $item) {
        if (($item['location']['characterId'] ?? '') !== $characterId) continue;
        if (in_array($item['location']['kind'], ['inventory','consumed'], true)) $known[$item['templateId']] = true;
        if ($item['location']['kind'] === 'inventory') $counts[$item['templateId']] = ($counts[$item['templateId']] ?? 0) + 1;
    }
    foreach (applicationDomainPayload($records, 'devices')['devices'] ?? [] as $device) {
        foreach ($store['instances'] as $item) if ($item['id'] === ($device['itemId'] ?? '')) $managed[$item['templateId']] = true;
    }
    $abilityIds = array_column($character['abilities'] ?? [], 'id');
    $templates = []; $snapshots = [];
    foreach ($store['templates'] as $template) {
        if ($template['kind'] !== 'object') continue;
        $bound = ($template['creatorCharacterId'] ?? '') === $characterId && in_array($template['sourceAbilityId'] ?? '', $abilityIds, true);
        foreach ($template['stockBindings'] ?? [] as $binding) if (($binding['characterId'] ?? '') === $characterId && in_array($binding['abilityId'] ?? '', $abilityIds, true)) $bound = true;
        if (!$isGm && !isset($known[$template['id']]) && !$bound) continue;
        $templates[] = array_intersect_key($template, array_flip(['id','kind','name','active','sourceAbilityId','creatorCharacterId']))
            + ['quantity'=>$counts[$template['id']] ?? 0,'deviceManaged'=>isset($managed[$template['id']])];
        $snapshots[$template['id']] = assistantObjectTemplateSignature($template);
    }
    return ['objectTemplates'=>$templates, '_objectSnapshots'=>$snapshots,
        'canApplyObjectPlan'=>$isGm, 'characterRevision'=>(int) ($records['character:'.$characterId]['revision'] ?? 0)];
}

function normalizeAssistantObjectPlan(mixed $value, array $ability, array $context): ?array {
    if ($value === null) return null;
    if (!is_array($value) || ($value['version'] ?? null) !== 1 || array_diff(array_keys($value), ['version','objects','links'])
        || !is_array($value['objects'] ?? null) || !array_is_list($value['objects']) || count($value['objects']) < 1 || count($value['objects']) > 4
        || !is_array($value['links'] ?? null) || !array_is_list($value['links']) || count($value['links']) < 1 || count($value['links']) > 2) assistantObjectPlanFailure('Le plan d’objets est invalide.');
    $allowed = array_column($context['objectTemplates'] ?? [], null, 'id'); $objects = []; $seen = [];
    foreach ($value['objects'] as $object) {
        if (!is_array($object) || !is_string($object['ref'] ?? null) || preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', $object['ref']) !== 1
            || isset($seen[$object['ref']]) || array_diff(array_keys($object), ['ref','templateId','template'])) assistantObjectPlanFailure('Une référence d’objet est invalide ou répétée.');
        $seen[$object['ref']] = true;
        if (itemId($object['templateId'] ?? null) && !isset($object['template'])) {
            $template = $allowed[$object['templateId']] ?? null;
            if (!is_array($template) || ($template['kind'] ?? '') !== 'object' || !empty($template['deviceManaged'])) assistantObjectPlanFailure('Choisissez un modèle réellement autorisé, hors appareil.');
            $objects[] = ['ref'=>$object['ref'],'templateId'=>$object['templateId']]; continue;
        }
        $fields = $object['template'] ?? null;
        if (isset($object['templateId']) || !is_array($fields) || ($fields['kind'] ?? '') !== 'object'
            || array_diff(array_keys($fields), ['kind','name','description','active'])) assistantObjectPlanFailure('Le nouveau modèle doit être un objet sans exemplaire, média ou droit inventé.');
        $template = assistantObjectTemplate([...$fields, 'id'=>'assistant-'.$object['ref'],'category'=>'ability','image'=>'']);
        unset($template['id'], $template['category'], $template['image']);
        $objects[] = ['ref'=>$object['ref'],'template'=>$template];
    }
    $kinds = []; $used = []; $links = [];
    foreach ($value['links'] as $link) {
        if (!is_array($link) || array_diff(array_keys($link), ['kind','objectRef']) || !is_string($link['objectRef'] ?? null) || preg_match('/^[a-z][a-z0-9_-]{0,39}$/D',$link['objectRef']) !== 1 || !in_array($link['kind'] ?? '', ['launcher-ammunition','coating-vial'], true)
            || isset($kinds[$link['kind']]) || !isset($seen[$link['objectRef'] ?? ''])) assistantObjectPlanFailure('Le port de liaison est absent, répété ou non pris en charge.');
        $kind = $link['kind']; $kinds[$kind] = true; $used[$link['objectRef']] = true;
        if ($kind === 'launcher-ammunition' && (($ability['effect'] ?? '') !== 'device' || ($ability['device']['kind'] ?? '') !== 'launcher')) assistantObjectPlanFailure('Ce lien exige un lanceur.');
        if ($kind === 'coating-vial' && (($ability['effect'] ?? '') !== 'enhancement' || ($ability['tacticalEffect']['kind'] ?? '') !== 'coating')) assistantObjectPlanFailure('Ce lien exige une compétence d’enduction de type Buff.');
        $object = array_values(array_filter($objects, static fn($o)=>$o['ref']===$link['objectRef']))[0];
        $template = isset($object['templateId']) ? $allowed[$object['templateId']] : $object['template'];
        if ($kind === 'coating-vial' && isset($object['templateId']) && ($template['active']['ingredientTag'] ?? '') !== 'poison-vial'
            && !(($template['sourceAbilityId'] ?? '') === $ability['id'] && ($template['creatorCharacterId'] ?? '') === ($context['character']['id'] ?? ''))) assistantObjectPlanFailure('Ce modèle n’est pas une fiole compatible avec cette enduction.');
        if ($kind === 'launcher-ammunition' && isset($template['sourceAbilityId'])) assistantObjectPlanFailure('Un objet fabriqué par une compétence ne peut pas devenir une munition de lanceur.');
        $links[] = ['kind'=>$kind,'objectRef'=>$link['objectRef']];
    }
    foreach ($objects as $object) {
        if (!isset($used[$object['ref']])) assistantObjectPlanFailure('Chaque modèle proposé doit être relié à la compétence.');
        if (count(array_filter($links, static fn($l)=>$l['objectRef']===$object['ref'])) > 1) assistantObjectPlanFailure('Une fiole fabriquée ne peut pas aussi servir de munition.');
    }
    return ['version'=>1,'objects'=>$objects,'links'=>$links];
}

// Pure preparation: no writes and no instance grant. The endpoint persists both
// domains and its durable message receipt in one transaction under the clock.
function prepareAbilityAssistantObjectApplication(array $records, array $conversation, array $draft, array $identity, ?callable $newId = null): array {
    if (($identity['effective_mode'] ?? '') !== 'gm' || ($identity['permanent_role'] ?? '') !== 'gm') assistantObjectPlanFailure('Création et liaison d’objets réservées au MJ.', 403);
    $context = json_decode((string) $conversation['context_json'], true, 512, JSON_THROW_ON_ERROR);
    $characterId = (string) ($context['character']['id'] ?? ''); $key = 'character:'.$characterId;
    $character = applicationDomainPayload($records, $key);
    if ($character === [] || ($character['id'] ?? '') !== $characterId) assistantObjectPlanFailure('La fiche a disparu.', 409);
    if (($records[$key]['revision'] ?? 0) !== ($context['characterRevision'] ?? -1)) assistantObjectPlanFailure('La fiche a changé pendant l’assistance. Rouvrez l’assistant pour garder sa version actuelle.', 409);
    $plan = normalizeAssistantObjectPlan($draft['objectPlan'] ?? null, $draft, $context);
    if ($plan === null) assistantObjectPlanFailure('Cette proposition ne contient aucun plan d’objets.');
    $newId ??= static fn()=> 'template-'.randomToken(16);
    $items = normalizeItemStore(applicationDomainPayload($records, 'items', ['version'=>1,'templates'=>[],'instances'=>[]]));
    $templates = array_column($items['templates'], null, 'id'); $refs = []; $ability = $draft; unset($ability['objectPlan'], $ability['applicationReceipt']);
    $currentChoices = array_column(abilityAssistantObjectContext($records,$character,$identity)['objectTemplates'],null,'id');
    foreach ($plan['objects'] as $object) {
        if (isset($object['templateId'])) {
            $templateId = $object['templateId']; $template = $templates[$templateId] ?? null;
            if (!empty($currentChoices[$templateId]['deviceManaged'])) assistantObjectPlanFailure('Cet objet est désormais géré par un appareil.',409);
            if (!$template || !isset($context['_objectSnapshots'][$templateId]) || !hash_equals($context['_objectSnapshots'][$templateId], assistantObjectTemplateSignature($template))) assistantObjectPlanFailure('Un modèle d’objet a changé ou disparu. Rouvrez l’assistant.', 409);
        } else {
            if (count($templates) >= 200) assistantObjectPlanFailure('La bibliothèque d’objets est pleine.', 409);
            $templateId = $newId();
            if (!itemId($templateId) || isset($templates[$templateId])) assistantObjectPlanFailure('La nouvelle référence de modèle est indisponible.', 409);
            $template = normalizeItemTemplate([...$object['template'], 'id'=>$templateId,'category'=>'ability','image'=>'']);
        }
        $refs[$object['ref']] = $templateId; $templates[$templateId] = $template;
    }
    foreach ($plan['links'] as $link) {
        $templateId = $refs[$link['objectRef']];
        if ($link['kind'] === 'launcher-ammunition') {
            $ability['device']['ammoTemplateId'] = $templateId;
            $bindings = $templates[$templateId]['stockBindings'] ?? [];
            if (!array_filter($bindings, static fn($l)=>$l['characterId']===$characterId && $l['abilityId']===$ability['id'])) {
                if (count($bindings) >= 1000) assistantObjectPlanFailure('Le stock atteint sa limite de liens.',409);
                $bindings[] = ['characterId'=>$characterId,'abilityId'=>$ability['id']];
            }
            $templates[$templateId]['stockBindings'] = $bindings;
        } else {
            $object = array_values(array_filter($plan['objects'], static fn($o)=>$o['ref']===$link['objectRef']))[0];
            // An existing generic vial remains a shared ingredient. Never seize
            // its model on behalf of this ability or alter the other users.
            if (isset($object['template'])) {
                foreach ($templates as $existingTemplate) if ($existingTemplate['id'] !== $templateId
                    && ($existingTemplate['sourceAbilityId'] ?? '') === $ability['id'] && ($existingTemplate['creatorCharacterId'] ?? '') === $characterId) assistantObjectPlanFailure('Cette enduction possède déjà son modèle fabriqué. Réutilisez-le au lieu d’en créer un doublon.',409);
                $templates[$templateId]['sourceAbilityId'] = $ability['id'];
                $templates[$templateId]['creatorCharacterId'] = $characterId;
            }
        }
    }
    $validationContext = $context;
    $validationContext['ammunition'] = array_values(array_map(static fn($template)=>['id'=>$template['id']], $templates));
    $validationContext['existingAbility'] = $context['existingAbility'] ?? $ability;
    $ability = normalizeAbilityAssistantDraft(['context_json'=>json_encode($validationContext, JSON_THROW_ON_ERROR)], $ability);
    if (!is_array($ability)) assistantObjectPlanFailure('La compétence est invalide.');
    $abilities = $character['abilities'] ?? []; $existingId = $context['existingAbility']['id'] ?? null;
    if ($existingId !== null) {
        $index = array_search($existingId, array_column($abilities, 'id'), true);
        if ($index === false || canonicalApplicationDomainValue($abilities[$index]) !== canonicalApplicationDomainValue($context['existingAbility'])) assistantObjectPlanFailure('La compétence a changé pendant l’assistance.',409);
        $abilities[$index] = $ability;
    } else {
        if (count($abilities) >= 200 || in_array($ability['id'], array_column($abilities,'id'),true)) assistantObjectPlanFailure('La compétence ne peut plus être ajoutée.',409);
        $abilities[] = $ability;
    }
    $character['abilities'] = $abilities; $items['templates'] = array_values($templates);
    if (!validApplicationAbilities($abilities)) assistantObjectPlanFailure('La liste de compétences est invalide.');
    return ['character'=>$character,'items'=>normalizeItemStore($items), 'result'=>['characterId'=>$characterId,'abilityId'=>$ability['id'],'abilities'=>$abilities,'templateIds'=>array_values($refs)]];
}
