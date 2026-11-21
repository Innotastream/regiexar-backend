<?php
declare(strict_types=1);
require_once __DIR__.'/item-catalog.php';
function applicationPotionCatalogueKey(string $name):string{return str_replace(['’',"'"],' ',itemNameKey($name));}
function applicationPotionCatalogueIndex(): array
{
    static $index = null;
    if ($index !== null) return $index;
    $rules = json_decode((string)file_get_contents(__DIR__.'/potion-catalog-presets.json'),true,512,JSON_THROW_ON_ERROR);
    $families = $names = [];
    foreach ($rules as $rule) {
        $families[$rule['family']][] = $rule;
        foreach ($rule['names'] as $name) $names[applicationPotionCatalogueKey($name)][$rule['id']] = true;
    }
    return $index = [$families, $names];
}

function applicationMarkConfiguredPotionTemplate(array $template): array
{
    if (!in_array($template['kind'] ?? '', ['potion','object'], true)) return $template;
    [, $names] = applicationPotionCatalogueIndex();
    if (isset($names[applicationPotionCatalogueKey($template['name'] ?? '')])) {
        $template['potionCatalogRevision'] ??= 1;
    }
    return $template;
}

function applicationPotionCatalogPresets(array $store): array
{
    if (!is_array($store['templates'] ?? null) || !is_array($store['instances'] ?? null)) return $store;
    [$families, $names] = applicationPotionCatalogueIndex();

    $pending = [];
    $pendingUses = $store['pendingUses'] ?? [];
    $pendingIds = [];
    foreach (is_array($pendingUses) ? $pendingUses : [] as $use) {
        if (is_string($use['itemId'] ?? null)) $pendingIds[$use['itemId']] = true;
    }
    if ($pendingIds !== []) {
        foreach ($store['instances'] as $item) {
            if (isset($pendingIds[$item['id'] ?? ''], $item['templateId'])) $pending[$item['templateId']] = true;
        }
    }
    $matches = $templateIds = [];
    foreach ($store['templates'] as $i => $template) {
        if (isset($template['id'])) $templateIds[$template['id']] = true;
        if (!in_array($template['kind'] ?? '', ['object','potion'], true)
            || !empty($template['equipment']) || !empty($template['sourceAbilityId']) || !empty($template['potionVessel'])) continue;
        $key = applicationPotionCatalogueKey($template['name'] ?? '');
        foreach ($names[$key] ?? [] as $ruleId => $_) $matches[$ruleId][$i] = true;
    }
    $canUpgrade = static fn(array $template): bool => empty($template['potionCatalogRevision']) && !isset($pending[$template['id']]);
    foreach ($families as $family) {
        $needed = false;
        foreach ($family as $rule) {
            foreach ($matches[$rule['id']] ?? [] as $i => $_) {
                if ($canUpgrade($store['templates'][$i])) {
                    $needed = true;
                    break 2;
                }
            }
        }
        if (!$needed) continue;
        foreach ($family as $rule) {
            $existing = $matches[$rule['id']] ?? [];
            $configured = ['kind'=>$rule['kind'], 'active'=>$rule['active'], 'potionCatalogRevision'=>1];
            if ($rule['kind'] === 'potion') $configured['potion'] = ['hp'=>0, 'mana'=>0, 'fatigue'=>0];
            foreach ($existing as $i => $_) {
                if ($canUpgrade($store['templates'][$i])) $store['templates'][$i] = [...$store['templates'][$i], ...$configured];
            }
            if ($existing !== [] || count($store['templates']) >= 200 || isset($templateIds[$rule['id']])) continue;
            $name = $rule['names'][0];
            $store['templates'][] = ['id'=>$rule['id'], 'name'=>$name, 'kind'=>$rule['kind'],
                'description'=>$rule['description'] ?? '', ...$configured];
            $templateIds[$rule['id']] = true;
            foreach ($names[applicationPotionCatalogueKey($name)] as $ruleId => $_) {
                $matches[$ruleId][array_key_last($store['templates'])] = true;
            }
        }
    }
    return $store;
}
