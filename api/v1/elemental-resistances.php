<?php

declare(strict_types=1);

require_once __DIR__ . '/health-state.php';
require_once __DIR__ . '/unit-authority.php';

const XAR_ELEMENTAL_RESISTANCE_TYPES = ['fire', 'ice', 'poison', 'bleeding'];
const XAR_ELEMENTAL_RESISTANCE_MINIMUM = -1000000000;
const XAR_VENOM_PARALYSIS_LABEL = 'Paralysé · venin';

function applicationDamageElement(mixed $value): ?string
{
    if (!is_string($value)) return null;
    return match (strtolower(strtr(trim($value), ['É'=>'e', 'é'=>'e', 'È'=>'e', 'è'=>'e', 'Ê'=>'e', 'ê'=>'e']))) {
        'fire', 'feu', 'burn', 'burning', 'flame', 'flammes' => 'fire',
        'ice', 'glace', 'gel', 'frost', 'cold', 'froid', 'givre', 'glacial' => 'ice',
        'poison', 'venom', 'venin', 'empoisonnement', 'toxin', 'toxine' => 'poison',
        'bleeding', 'bleed', 'saignement', 'hemorrhage', 'haemorrhage', 'hemorragie' => 'bleeding',
        default => null,
    };
}

function applicationDamageType(mixed $value): ?string
{
    if (!is_string($value)) return null;
    $type = strtolower(trim($value));
    return in_array($type, ['physical', 'magical', 'ignore'], true) ? $type : applicationDamageElement($type);
}

function applicationDamageArmorType(mixed $value): string
{
    return match (applicationDamageType($value)) {
        'fire', 'ice', 'magical' => 'magical',
        'poison', 'bleeding', 'ignore' => 'ignore',
        default => 'physical',
    };
}

function applicationOptionalDamageElement(array $value, string $key = 'damageElement'): array
{
    $element = applicationDamageElement($value[$key] ?? null);
    return $element === null ? [] : [$key => $element];
}

function applicationDamageComponentKey(array $part): string
{
    $type = $part['type'];
    return $type . ':' . (applicationDamageElement($part['element'] ?? null) ?? applicationDamageElement($type))
        . ':' . (($part['ignoreArmor'] ?? false) === true ? 'bypass' : 'armor');
}

function applicationSimpleDamageComponent(string $type, string $formula, ?string $element = null): array
{
    return ['type'=>$type, 'formula'=>$formula, ...($element === null ? [] : ['element'=>$element])];
}

function validApplicationOptionalDamageElement(array $value, string $key = 'damageElement'): bool
{
    return !array_key_exists($key, $value) || $value[$key] === null || applicationDamageElement($value[$key]) !== null;
}

function normalizeApplicationElementalResistances(mixed $value): array
{
    $result = array_fill_keys(XAR_ELEMENTAL_RESISTANCE_TYPES, 0);
    if (!is_array($value)) return $result;
    foreach ($result as $element => $_) {
        $percent = $value[$element] ?? null;
        if (is_numeric($percent) && is_finite((float) $percent)) $result[$element] = (int) max(XAR_ELEMENTAL_RESISTANCE_MINIMUM, min(100, (float) $percent));
    }
    return $result;
}

function validApplicationElementalResistances(mixed $value): bool
{
    if (!is_array($value)) return false;
    foreach ($value as $element => $percent) {
        if (!in_array($element, XAR_ELEMENTAL_RESISTANCE_TYPES, true)
            || !is_int($percent) || $percent < XAR_ELEMENTAL_RESISTANCE_MINIMUM || $percent > 100) return false;
    }
    return true;
}

function applicationReduceElementalDamage(int $damage, int $percent): int
{
    return $percent === 0 ? $damage : (int) min(1000000000, round((float) $damage * (100 - $percent) / 100));
}

function applicationElementalStatusType(mixed $label): ?string
{
    if (!is_string($label)) return null;
    return match (onlineConditionKey($label)) {
        'brulure', 'brulures', 'brule', 'brulee', 'enflamme', 'enflammee', 'enflame', 'enflamee', 'en feu', 'burning', 'burn' => 'fire',
        'glace', 'glacee', 'gele', 'gelee', 'congele', 'congelee', 'gel', 'frozen', 'freezing', 'chilled' => 'ice',
        'saignement', 'saigne', 'bleeding', 'hemorragie', 'hemorrhage', 'haemorrhage' => 'bleeding',
        onlineConditionKey(XAR_VENOM_PARALYSIS_LABEL), 'poison paralysant', 'venin paralysant', 'empoisonnement paralysant',
        'paralysie due au poison', 'paralysie due au venin', 'paralyse venin', 'paralyzing poison', 'paralyzing venom' => 'poison',
        default => null,
    };
}

function applicationElementalSavingConditionLabel(mixed $label): ?string
{
    return match (applicationElementalStatusType($label)) {
        'fire' => 'Enflammé',
        'ice' => 'Glacé',
        'poison' => XAR_VENOM_PARALYSIS_LABEL,
        default => null,
    };
}

function applicationElementalConditionSave(array $entity, mixed $label, ?callable $rollDie = null): ?array
{
    if (applicationElementalSavingConditionLabel($label) === null) return null;
    $element = applicationElementalStatusType($label);
    $percent=$entity['elementalResistanceOverrides'][$element]??$entity['elementalResistances'][$element]??0;
    $threshold=is_numeric($percent)&&is_finite((float)$percent)?(int)max(0,min(100,$percent)):0;
    if ($threshold === 0) return null;
    $rolled = $threshold === 100 ? null : ($rollDie ? $rollDie() : random_int(1,100));
    return ['element'=>$element,'threshold'=>$threshold,'rolled'=>$rolled,'success'=>$rolled === null || $rolled <= $threshold];
}

function applicationRecordElementalConditionSave(array &$state,array $unit,string $sceneId,string $label,array $save,?array $token=null): void
{
    $rolled=$save['rolled'];
    if ($rolled===null) return;
    $label=applicationElementalSavingConditionLabel($label) ?? $label;
    $name=(string)($unit['name']??$token['name']??'Unité');
    $outcome=classifyOnlineD100Outcome($rolled,$save['threshold'],0,0,false);
    $entry=onlineRollEntry(['display_name'=>$name],['formula'=>'1d100','total'=>$rolled,'breakdown'=>"[$rolled]"],'Résistance · '.$label,$name,$outcome);
    if ($token!==null) {
        $entry['sourceTokenId']=$token['id'];
        $entry['sourceSceneId']=$sceneId;
    }
    if (!empty($token['hidden'])||empty($unit['ownerPlayerId'])&&empty($token['controllerPlayerId'])) {
        $entry['visibility']='gm';
        $entry['revealed']=false;
        $entry['rollerRole']='gm';
    }
    $state['rolls']=array_slice([$entry,...($state['rolls']??[])],0,100);
}

function applicationElementalStatusImmune(array $entity, ?string $element, array $charactersById = []): bool
{
    if (!in_array($element, XAR_ELEMENTAL_RESISTANCE_TYPES, true)) return false;
    $override=$entity['elementalResistanceOverrides'][$element]??null;
    if ($charactersById !== [] && unitUsesCharacterSheet($entity)) $entity = $charactersById[$entity['characterId']] ?? $entity;
    $percent = $override ?? $entity['elementalResistances'][$element] ?? 0;
    return is_numeric($percent) && is_finite((float) $percent) && $percent >= 100;
}

function applicationElementalConditionSuppressed(array $entity, mixed $label, array $charactersById = []): bool
{
    return applicationElementalStatusImmune($entity, applicationElementalStatusType($label), $charactersById);
}

function applicationUnsuppressedConditions(array $entity, array $conditions, array $charactersById = []): array
{
    return array_values(array_filter($conditions, static fn ($label): bool => !applicationElementalConditionSuppressed($entity, $label, $charactersById)));
}
