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

function applicationHonorificInteger(mixed $value): bool { return (is_int($value)||is_float($value))&&is_finite((float)$value)&&floor((float)$value)===(float)$value; }
function validApplicationHonorificTitles(mixed $value): bool {
    if(!is_array($value)||!applicationHonorificInteger($value['version']??null)||(int)$value['version']!==1||!is_array($value['entries']??null)||!array_is_list($value['entries'])||count($value['entries'])>50||!is_string($value['displayedId']??null))return false;
    if(array_diff(array_keys($value),['version','entries','displayedId']))return false;
    $ids=[];
    foreach($value['entries'] as $entry){
        if(!is_array($entry)||array_diff(array_keys($entry),['id','name','bonuses'])||!is_string($entry['id']??null)||!preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$entry['id'])||isset($ids[$entry['id']]))return false;
        $name=$entry['name']??null;if(!is_string($name)||trim($name)===''||applicationCharacterTextLength($name)>120||preg_match('/[\x00-\x1f]/',$name))return false;
        if(!is_array($entry['bonuses']??null)&&!(($entry['bonuses']??null) instanceof stdClass))return false;
        foreach((array)$entry['bonuses'] as $key=>$amount)if(!array_key_exists($key,applicationStatLabels())||!applicationHonorificInteger($amount)||abs($amount)>100)return false;
        $ids[$entry['id']]=true;
    }
    return $value['displayedId']===''||isset($ids[$value['displayedId']]);
}
function applicationCharacterTextLength(string $value): int { return function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):preg_match_all('/./us',$value); }
function normalizeApplicationHonorificTitles(mixed $value): array {
    if(is_array($value)&&(int)($value['version']??0)>1)throw new RuntimeException('future_honorific_titles');
    $value=is_array($value)?$value:[];$entries=[];$ids=[];
    foreach(array_slice(is_array($value['entries']??null)?$value['entries']:[],0,50) as $entry){
        if(!is_array($entry)||!is_string($entry['id']??null)||!preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$entry['id'])||isset($ids[$entry['id']])||!is_string($entry['name']??null))continue;
        $name=trim(preg_replace('/[\x00-\x1f]/','',$entry['name'])??'');if($name==='')continue;
        preg_match('/^.{0,120}/us',$name,$match);$name=$match[0]??'';$bonuses=[];
        foreach(applicationStatLabels() as $key=>$_label){$amount=((array)($entry['bonuses']??[]))[$key]??null;if(applicationHonorificInteger($amount)&&abs($amount)<=100&&(int)$amount!==0)$bonuses[$key]=(int)$amount;}
        $entries[]=['id'=>$entry['id'],'name'=>$name,'bonuses'=>$bonuses===[]?(object)[]:$bonuses];$ids[$entry['id']]=true;
    }
    return ['version'=>1,'entries'=>$entries,'displayedId'=>isset($ids[$value['displayedId']??''])?$value['displayedId']:''];
}
function applicationHonorificTitleBonuses(array $character): array {
    $bonuses=[];foreach(normalizeApplicationHonorificTitles($character['honorificTitles']??null)['entries'] as $entry)foreach((array)$entry['bonuses'] as $key=>$amount)$bonuses[$key]=($bonuses[$key]??0)+$amount;return $bonuses;
}
function applicationDisplayedHonorificTitle(array $entity): string {
    if(is_string($entity['honorificTitle']??null)){preg_match('/^.{0,120}/us',preg_replace('/[\x00-\x1f]/','',$entity['honorificTitle'])??'',$match);return $match[0]??'';}
    $titles=normalizeApplicationHonorificTitles($entity['honorificTitles']??null);foreach($titles['entries'] as $entry)if($entry['id']===$titles['displayedId'])return $entry['name'];return '';
}
function normalizeApplicationFortune(mixed $value): int { return applicationHonorificInteger($value)&&$value>=0&&$value<=5?(int)$value:2; }
function applicationCharacterStatTotal(array $character,string $key): float {
    $temporary=$character['temporaryStats'][$key]??null;$base=$character['stats'][$key]??($key==='spirit'?30:0);
    if($temporary!==null&&$temporary!==''&&is_numeric($temporary))$base=max(0,min(100,(float)$temporary));
    return (is_numeric($base)?(float)$base:0)+(applicationHonorificTitleBonuses($character)[$key]??0);
}
function applicationEffectiveCharacterStats(array $character,array $bonuses=[]): array {
    $fatigue=max(0,(int)floor(min(150,(float)($character['fatigue']['current']??0))-50));$titles=applicationHonorificTitleBonuses($character);$rows=[];
    foreach(applicationCharacterStats($character['stats']??[]) as $key=>$base){
        $row=['id'=>'character-stat-'.$key,'label'=>applicationStatLabels()[$key]??$key,'value'=>(string)max(1,min(100,applicationCharacterStatTotal($character,(string)$key)+($bonuses[$key]??0)-$fatigue))];
        if(!empty($titles[$key]))$row['titleBonus']=$titles[$key];if(!empty($bonuses[$key]))$row['timedBonus']=$bonuses[$key];$rows[]=$row;
    }
    $row=['id'=>'character-stat-mentalResistance','label'=>'Résistance mentale','value'=>(string)max(1,min(100,(float)($character['resources']['mentalResistance']??$character['secret']['mentalResistance']??0)+($bonuses['mentalResistance']??0)-$fatigue))];if(!empty($bonuses['mentalResistance']))$row['timedBonus']=$bonuses['mentalResistance'];$rows[]=$row;return $rows;
}
function applicationIndependentCharacterStats(array $character): array {
    $rows=applicationEffectiveCharacterStats($character);foreach($rows as &$row){$key=preg_replace('/^character-stat-/','',$row['id']);$row['value']=(string)($key==='mentalResistance'?($character['resources']['mentalResistance']??$character['secret']['mentalResistance']??0):applicationCharacterStatTotal($character,$key));unset($row['titleBonus']);}unset($row);return $rows;
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
