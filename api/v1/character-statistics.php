<?php
declare(strict_types=1);

// Keep the legacy Social key: its score and existing references never become Esprit.
function applicationStatLabels(): array {
    return ['force'=>'Force','dexterity'=>'Dextérité','agility'=>'Agilité','spiritSocial'=>'Social','intelligence'=>'Intelligence','spirit'=>'Esprit','instinct'=>'Instinct'];
}
function applicationStatIds(): array { return array_map(static fn($key) => 'character-stat-'.$key, array_keys(applicationStatLabels())); }
function applicationComparableStat(mixed $label): string {
    return preg_replace('/[^a-z0-9]/', '', strtolower(strtr((string)$label, ['é'=>'e','É'=>'e','è'=>'e','È'=>'e','ê'=>'e','Ê'=>'e','î'=>'i','Î'=>'i','ï'=>'i','Ï'=>'i','à'=>'a','À'=>'a','â'=>'a','Â'=>'a']))) ?? '';
}
function applicationStatKey(array $row): string {
    $key = preg_replace('/^character-stat-/', '', (string)($row['id']??''));
    if (array_key_exists($key, applicationStatLabels())) return $key;
    $aliases = ['force'=>['force'],'dexterity'=>['dexterite','dexterity'],'agility'=>['agilite','agility'],'spiritSocial'=>['social','espritsocial'],'intelligence'=>['intelligence'],'spirit'=>['esprit','spirit'],'instinct'=>['instinct','instinctperception']];
    $label = applicationComparableStat($row['label']??'');
    foreach ($aliases as $candidate=>$labels) if (in_array($label,$labels,true)) return $candidate;
    return '';
}
function applicationCharacterStats(mixed $value): array {
    $stats = is_array($value) ? $value : [];
    $stats['spirit'] ??= 30;
    $ordered=[];
    foreach (applicationStatLabels() as $key=>$_label) if (array_key_exists($key,$stats)) $ordered[$key]=$stats[$key];
    return $ordered + $stats;
}
function applicationUnitStatRows(mixed $value): array {
    $rows=is_array($value)?$value:[];
    if (!array_is_list($rows)) {
        $rows=array_map(static fn($id,$score)=>['id'=>$id,'label'=>applicationStatLabels()[$id]??$id,'value'=>(string)$score],array_keys($rows),array_values($rows));
    }
    if ($rows===[]) return [];
    $found=false;$prefixed=false;
    foreach ($rows as $row) { if(!is_array($row))continue; $found=$found||applicationStatKey($row)==='spirit';$prefixed=$prefixed||str_starts_with((string)($row['id']??''),'character-stat-'); }
    if(!$found)$rows[]=['id'=>$prefixed?'character-stat-spirit':'spirit','label'=>'Esprit','value'=>'30'];
    $known=[];$custom=[];
    foreach($rows as $row){if(!is_array($row))continue;$key=applicationStatKey($row);if($key!==''){$row['label']=applicationStatLabels()[$key];$known[]=$row;}else$custom[]=$row;}
    $keys=array_keys(applicationStatLabels());
    usort($known,static fn($a,$b)=>array_search(applicationStatKey($a),$keys,true)<=>array_search(applicationStatKey($b),$keys,true));
    return [...$known,...$custom];
}
function applicationResolveCharacterStat(array $rows,string $statId):?array {
    foreach($rows as $row)if(is_array($row)&&($row['id']??'')===$statId)return $row;
    $key=preg_replace('/^character-stat-/','',$statId);
    if(!array_key_exists($key,applicationStatLabels()))return null;
    foreach($rows as $row)if(is_array($row)&&applicationStatKey($row)===$key)return $row;
    return null;
}

// Scope migration to actual entities, never historical rolls, receipts or snapshots of an action.
function applicationSpiritDomainPayload(string $key,array $payload):array {
    if(str_starts_with($key,'character:')) {
        if(($payload['characterSchemaVersion']??0)>10)throw new RuntimeException('future_character_schema');
        $payload['stats']=applicationCharacterStats($payload['stats']??[]);
        $payload['characterSchemaVersion']=10;
    } elseif(str_starts_with($key,'token:')) {
        if(array_key_exists('stats',$payload))$payload['stats']=applicationUnitStatRows($payload['stats']);
    } elseif($key==='library') {
        foreach($payload['tokenLibrary']??[] as $i=>$token)if(is_array($token))$payload['tokenLibrary'][$i]=applicationSpiritDomainPayload('token:template',$token);
    } elseif($key==='detached-combat'||str_starts_with($key,'presentation:')) {
        foreach($payload['map']['tokens']??[] as $i=>$token)if(is_array($token))$payload['map']['tokens'][$i]=applicationSpiritDomainPayload('token:snapshot',$token);
    }
    return $payload;
}

function migrateApplicationSpiritStatistics(PDO $connection): int {
    $connection->beginTransaction();
    try {
        $clock=domainClockRecord($connection,true);
        $version=(int)$connection->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn();
        if($version>24)throw new RuntimeException('future_database_schema');
        if($version===24){$connection->commit();return 0;}
        $records=array_replace(applicationDomainRecordsByPrefix($connection,'character:'),applicationDomainRecordsByPrefix($connection,'token:'),applicationDomainRecordsByPrefix($connection,'presentation:'),applicationDomainRecords($connection,['library','detached-combat']));
        $pending=[];
        foreach($records as $key=>$record){
            $before=$record['payload'];$after=applicationSpiritDomainPayload($key,$before);
            if($after!==$before)$pending[]=['key'=>$key,'operation'=>'upsert','payload'=>$after,'current'=>$record];
        }
        // The ordinary domain writer backs up every original payload in the same transaction.
        if($pending!==[])persistDomainChangesInTransaction($connection,[],$clock,$pending);
        $connection->prepare('INSERT INTO schema_migrations (version,name,checksum) VALUES (24,:name,:checksum)')->execute([':name'=>'independent_spirit_stat_social_preserved',':checksum'=>hash('sha256','character-schema-10|spirit-default-30|social-legacy-key|3.7.0')]);
        $connection->commit();
        return count($pending);
    }catch(Throwable $error){if($connection->inTransaction())$connection->rollBack();throw $error;}
}
