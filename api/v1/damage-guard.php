<?php
declare(strict_types=1);
require_once __DIR__.'/stackable-effects.php';
require_once __DIR__.'/elemental-resistances.php';
function applicationGuardDamageTypes(mixed $value): array
{
    $types = is_array($value) ? array_values(array_unique(array_filter(array_map('applicationDamageType', $value)))) : [];
    return $types !== [] ? $types : ['physical','magical','ignore'];
}
function applicationGuardDamageParts(int $damage, ?array $basis): array
{
    $parts = is_array($basis['components'] ?? null) && $basis['components'] !== [] ? $basis['components'] : [[...($basis ?? []), 'finalDamage'=>$damage, 'type'=>$basis['damageType'] ?? 'physical']];
    return array_map(static fn(array $part): array => ['type'=>applicationDamageType($part['type'] ?? null) ?? 'physical',
        'rawDamage'=>max(0,(int)($part['rawDamage'] ?? $part['finalDamage'] ?? 0)), 'finalDamage'=>max(0,(int)($part['finalDamage'] ?? 0)),
        'armorPercent'=>min(100,(int)($part['armorPercent'] ?? 0)),
        ...applicationOptionalDamageElement($part), ...(isset($part['elementalResistancePercent']) ? ['elementalResistancePercent'=>(int)$part['elementalResistancePercent']] : [])], $parts);
}
function applicationReflectedDamageBasis(array $summary, string $type, int $damage, array $target = []): array
{
    $parts=applicationGuardDamageParts((int)$summary['finalDamage'], [...$summary,'damageType'=>$type]);
    $total=array_sum(array_column($parts,'finalDamage'));
    foreach($parts as &$part){ $part['rawDamage']=(int)floor($damage*$part['finalDamage']/max(1,$total)); $part['armorPercent']=0; } unset($part);
    $remaining=$damage-array_sum(array_column($parts,'rawDamage'));
    $final = 0;
    foreach ($parts as &$part) {
        if ($part['finalDamage'] > 0 && $remaining > 0) { $part['rawDamage']++; $remaining--; }
        $part = [...$part, ...onlineTypedDamageSummary($part['rawDamage'], $target, $part['type'], $part['damageElement'] ?? null, true)];
        $final += $part['finalDamage'];
    }
    unset($part);
    return ['rawDamage'=>$damage,'finalDamage'=>$final,'armorPercent'=>0,'damageType'=>$type,'components'=>$parts];
}
function applicationGuardMatchesPart(array $guard, array $part): bool
{
    $types = applicationGuardDamageTypes($guard['damageTypes'] ?? null);
    return in_array($part['type'], $types, true) || in_array(applicationDamageArmorType($part['type']), $types, true)
        || in_array($part['damageElement'] ?? null, $types, true);
}
function applicationGuardCoversDamage(array $guard, ?array $basis): bool
{
    if($basis===null)return true;
    foreach(applicationGuardDamageParts((int)($basis['finalDamage'] ?? 0),$basis) as $part)
        if($part['finalDamage']>0 && applicationGuardMatchesPart($guard, $part))return true;
    return false;
}
function applicationMitigateGuardedDamage(int $damage, array $guards, ?array $basis): array
{
    $final=0;
    foreach(applicationGuardDamageParts($damage,$basis) as $part){
        $add=0;$after=0;
        foreach($guards as $guard){
            if(!applicationGuardMatchesPart($guard, $part))continue;
            $percent=max(1,min(100,(int)($guard['percent'] ?? 0)));
            if(($guard['armorStacking'] ?? '')==='add')$add=min(100,$add+$percent);else $after=min(100,$after+$percent);
        }
        $armored=$add>0 ? (int)round($part['rawDamage']*(100-min(100,$part['armorPercent']+$add))/100) : $part['finalDamage'];
        if ($add > 0 && isset($part['elementalResistancePercent'])) $armored = applicationReduceElementalDamage($armored, $part['elementalResistancePercent']);
        $final+=max(0,$armored-(int)round($armored*$after/100));
    }
    $remaining=min($damage,$final);
    return ['damage'=>$remaining,'prevented'=>$damage-$remaining];
}
function applicationConsumeGuardIndices(array $guards,array $indices): array {
    $budget=!empty($guards[$indices[0]??-1]['budget'])?100:PHP_INT_MAX;$next=[];
    foreach($guards as $index=>$guard){if(!in_array($index,$indices,true)||$budget<=0){$next[]=$guard;continue;}
        $percent=max(1,min(100,(int)($guard['percent']??0)));$used=min($budget,$percent);$budget-=$used;
        if($percent>$used)$next[]=[...$guard,'percent'=>$percent-$used];
    }return $next;
}
