<?php
declare(strict_types=1);
function normalizeItemSize(mixed $value): int {
    if (!is_int($value) || $value < 10 || $value > 10000) throw new RuntimeException('La taille de l’objet doit être comprise entre 10 et 10 000.', 400);
    return $value;
}
function normalizeItemMaxHp(mixed $value): int {
    if (!is_int($value) || $value < 1 || $value > 1000000) throw new RuntimeException('Les PV maximum de l’objet doivent être compris entre 1 et 1 000 000.', 400);
    return $value;
}
function normalizeItemHealth(mixed $value): array {
    if (!is_array($value)) throw new RuntimeException('Les PV de l’objet sont invalides.', 400);
    $max = normalizeItemMaxHp($value['max'] ?? null);
    if (!is_int($value['current'] ?? null) || $value['current'] < 0 || $value['current'] > $max) throw new RuntimeException('Les PV actuels de l’objet doivent être compris entre 0 et son maximum.', 400);
    return ['current' => $value['current'], 'max' => $max];
}
function publicItemPhysical(array $value): array { unset($value['health'], $value['maxHp']); return $value; }
function publicItemCommandResult(array $result, array $templates = [], ?array $visibleInstances = null): array {
    $models=array_column($templates,null,'id');
    $visible=$visibleInstances===null?null:array_column($visibleInstances,null,'id');
    $project=static function(array $item)use($models,$visible):?array {
        // A receipt acknowledges an earlier action; it does not retain access to
        // a foreign inventory after a transfer or an effective-role change.
        if($visible!==null){
            $current=$visible[$item['id']??'']??null;
            if($current===null)return null;
            // Even a visible item may have changed since a privileged receipt:
            // only its currently public values may be returned to this player.
            $item=array_intersect_key($current,$item);
        }
        $model=$models[$item['templateId']??'']??null;
        if($model===null||!itemPermissions($model)['read'])unset($item['description']);
        return publicItemPhysical($item);
    };
    if (isset($result['item'])) {
        $item=$project($result['item']);
        if($item===null)unset($result['item']);else $result['item']=$item;
    }
    if (isset($result['items'])) $result['items'] = array_values(array_filter(array_map($project, $result['items']),static fn($item)=>$item!==null));
    if(isset($result['rolls']))$result['rolls']=array_map(static fn($roll)=>($roll['mapEvent']['kind']??'')==='damage'?publicOnlineAttackRoll($roll,false,'damage',false):$roll,$result['rolls']);
    return $result;
}
