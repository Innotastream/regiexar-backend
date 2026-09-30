<?php
declare(strict_types=1);
function applicationGuardDamageTypes(mixed $value): array
{
    $types = is_array($value) ? array_values(array_intersect(['physical','magical','ignore'], $value)) : [];
    return $types !== [] ? $types : ['physical','magical','ignore'];
}
function applicationGuardDamageParts(int $damage, ?array $basis): array
{
    $parts = is_array($basis['components'] ?? null) && $basis['components'] !== [] ? $basis['components'] : [[...($basis ?? []), 'finalDamage'=>$damage, 'type'=>$basis['damageType'] ?? 'physical']];
    return array_map(static fn(array $part): array => ['type'=>in_array($part['type'] ?? '', ['physical','magical','ignore'], true) ? $part['type'] : 'physical',
        'rawDamage'=>max(0,(int)($part['rawDamage'] ?? $part['finalDamage'] ?? 0)), 'finalDamage'=>max(0,(int)($part['finalDamage'] ?? 0)),
        'armorPercent'=>max(0,min(100,(int)($part['armorPercent'] ?? 0)))], $parts);
}
function applicationReflectedDamageBasis(array $summary, string $type, int $damage): array
{
    $parts=applicationGuardDamageParts((int)$summary['finalDamage'], [...$summary,'damageType'=>$type]);
    $total=array_sum(array_column($parts,'finalDamage'));
    foreach($parts as &$part){ $part['rawDamage']=(int)floor($damage*$part['finalDamage']/max(1,$total)); $part['armorPercent']=0; } unset($part);
    $remaining=$damage-array_sum(array_column($parts,'rawDamage'));
    foreach($parts as &$part){ if($part['finalDamage']>0 && $remaining>0){$part['rawDamage']++;$remaining--;} $part['finalDamage']=$part['rawDamage']; } unset($part);
    return ['rawDamage'=>$damage,'finalDamage'=>$damage,'armorPercent'=>0,'damageType'=>$type,'components'=>$parts];
}
function applicationGuardCoversDamage(array $guard, ?array $basis): bool
{
    if($basis===null)return true;
    foreach(applicationGuardDamageParts((int)($basis['finalDamage'] ?? 0),$basis) as $part)
        if($part['finalDamage']>0 && in_array($part['type'], applicationGuardDamageTypes($guard['damageTypes'] ?? null),true))return true;
    return false;
}
function applicationMitigateGuardedDamage(int $damage, array $guards, ?array $basis): array
{
    $final=0;
    foreach(applicationGuardDamageParts($damage,$basis) as $part){
        $add=0;$after=0;
        foreach($guards as $guard){
            if(!in_array($part['type'],applicationGuardDamageTypes($guard['damageTypes'] ?? null),true))continue;
            $percent=max(1,min(100,(int)($guard['percent'] ?? 0)));
            if(($guard['armorStacking'] ?? '')==='add')$add=min(100,$add+$percent);else $after=min(100,$after+$percent);
        }
        $armored=$add>0 ? (int)round($part['rawDamage']*(100-min(100,$part['armorPercent']+$add))/100) : $part['finalDamage'];
        $final+=max(0,$armored-(int)round($armored*$after/100));
    }
    $remaining=min($damage,$final);
    return ['damage'=>$remaining,'prevented'=>$damage-$remaining];
}
