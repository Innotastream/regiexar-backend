<?php
declare(strict_types=1);
require_once __DIR__.'/explosion-fire-undo.php';
require_once __DIR__.'/unit-authority.php';
require_once __DIR__.'/trass-beam.php';
require_once __DIR__.'/impact-bleeding.php';

function combatUndoSame(mixed $a,mixed $b):bool{return json_encode($a)===json_encode($b);}
function combatUndoLinked(array $t):bool{return unitUsesCharacterSheet($t);}
function combatUndoIdentified(mixed $v):bool{if(!is_array($v)||!array_is_list($v))return false;$ids=[];foreach($v as$x){if(!is_array($x)||!is_string($x['id']??null)||isset($ids[$x['id']]))return false;$ids[$x['id']]=true;}return true;}
function combatUndoSelector(mixed $v):bool{return is_array($v)&&is_string($v['id']??null);}
function combatUndoImpactBleedingPath(string $domain,array $path):bool {
    return $domain === 'activity' && count($path) === 2 && $path[0] === 'damageOverTime'
        && combatUndoSelector($path[1]) && applicationIsImpactBleeding($path[1]);
}
function combatUndoView(array $domains):array{
 $collections=['actionTimers','abilityExecutions','abilityMovementLeases','damageOverTime','attackEnhancements','nextAttackGuards','statChecks','abilityCueEvents'];$derived=['name','portrait','color','resources','hp','maxHp','mana','maxMana','fatigue','stats','temporaryStats','conditions','conditionsBase','healthOverride','abilities','weaponText','weaponAttacks','armor','magicArmor','armorCategory','magicArmorCategory','elementalResistances','initiativeBonus','hitThreshold'];$result=[];
 foreach($domains as$key=>$value){if($key==='activity'){$result[$key]=[];foreach($collections as$k)$result[$key][$k]=$value[$k]??[];continue;}if(!in_array($key,['items','devices','luck'],true)&&!preg_match('/^(character|token|map|initiative):/',$key))continue;$result[$key]=$value;if($key==='devices')unset($result[$key]['receipts']);if(str_starts_with($key,'token:')&&combatUndoLinked($value))foreach($derived as$field)unset($result[$key][$field]);}return $result;
}
function combatUndoDifference(mixed $before,mixed $after,string $domain,array $path,array &$out,bool $hasBefore=true,bool $hasAfter=true):void{
 if($hasBefore===$hasAfter&&combatUndoSame($before,$after))return;
 if($hasBefore&&$hasAfter&&!combatUndoImpactBleedingPath($domain,$path)&&is_array($before)&&is_array($after)&&(!array_is_list($before)||$before===[])&&(!array_is_list($after)||$after===[])){foreach(array_unique([...array_keys($before),...array_keys($after)])as$key)if(!preg_match('/^_|^(revision|createdAt|updatedAt|resolvedAt|requestedAt|__proto__|prototype|constructor)$/',(string)$key))combatUndoDifference($before[$key]??null,$after[$key]??null,$domain,[...$path,$key],$out,array_key_exists($key,$before),array_key_exists($key,$after));return;}
 if($hasBefore&&$hasAfter&&combatUndoIdentified($before)&&combatUndoIdentified($after)){$b=array_column($before,null,'id');$a=array_column($after,null,'id');foreach(array_unique([...array_keys($b),...array_keys($a)])as$id)combatUndoDifference($b[$id]??null,$a[$id]??null,$domain,[...$path,['id'=>$id]],$out,isset($b[$id]),isset($a[$id]));return;}
 $out[]=['domain'=>$domain,'path'=>$path,'hasBefore'=>$hasBefore,'hasAfter'=>$hasAfter,...($hasBefore?['before'=>$before]:[]),...($hasAfter?['after'=>$after]:[])];
}
function combatUndoAliases(string $command,array $args,array $result):array{
 $ids=[];$add=static function($kind,$id)use(&$ids){if(is_string($id)&&$id!=='')$ids[]=$kind.':'.$id;};$add('request',$args['requestId']??$args['arguments']['requestId']??'');$add('execution',$args['executionId']??$args['complexExecutionId']??'');if($command!=='token.attack')$add('attack',$args['attackId']??'');$add('validation',$args['validationId']??'');$add('execution',$result['execution']['id']??'');$add('validation',$result['validation']['id']??'');$add('validation',$result['validationId']??'');$add('check',$args['checkId']??$args['statCheckId']??'');$add('effect',$args['dotId']??$args['effectId']??'');$add('effect',$result['effectId']??'');$add('item-use',$args['useId']??$result['pendingUse']??$result['useId']??'');
 foreach(array_filter([$result['attack']??null,...($result['attacks']??[])])as$a){$add('attack',$a['id']??'');$add('execution',$a['complexExecutionId']??'');$add('execution',$a['originExecutionId']??'');$add('attack',$a['echoParentId']??'');}return array_values(array_unique($ids));
}
function combatUndoAttacks(array $activity):array{$r=[];foreach($activity['attackReceipts']??[]as$v)if(!empty($v['attack']['id']))$r[$v['attack']['id']]=$v['attack'];foreach($activity['pendingAttacks']??[]as$a)if(!empty($a['id']))$r[$a['id']]=$a;return $r;}
function combatUndoDamageAmount(?array $a):float{return ($a['status']??'')==='applied'?max(0,(float)($a['appliedDamage']??0)):0;}
function combatUndoEntityKey(array $domains, string $scene, string $id): string {
    $key = 'token:'.$scene.':'.$id;
    $token = $domains[$key] ?? null;
    return $token !== null && combatUndoLinked($token) ? 'character:'.$token['characterId'] : $key;
}

function combatUndoDamageIndex(array $action, mixed $tokenId): int {
    $candidates = [];
    foreach ($action['damage'] as $index => $damage) {
        if ($damage['tokenId'] !== $tokenId || $damage['amount'] <= $damage['refunded']) continue;
        $scene = $damage['sceneId'] ?? '';
        if ($scene !== '' && $scene !== ($action['sceneId'] ?? '')) continue;
        $candidates[] = $index;
    }
    if (count($candidates) > 1) throw new RuntimeException('La cible de cette action est ambiguë dans sa scène d’origine.', 409);
    return $candidates[0] ?? -1;
}

function combatUndoDamageEntityKey(array $domains, array $damage): string {
    $scene = (string) ($damage['sceneId'] ?? '');
    $recorded = (string) ($damage['entityKey'] ?? '');
    if ($scene === '' || str_starts_with($recorded, 'character:')) return $recorded;
    $tokenKey = 'token:'.$scene.':'.$damage['tokenId'];
    if (isset($domains[$tokenKey]) && combatUndoLinked($domains[$tokenKey])) {
        throw new RuntimeException('La cible est liée à une autre fiche depuis cette action.', 409);
    }
    return $tokenKey;
}

function combatUndoCompactRecord(array $action): array {
    $action['complete'] = false;
    $action['undoUnavailable'] = true;
    $action['patches'] = $action['damage'] = $action['credits'] = [];
    unset($action['deviceBeamDeltas']);
    return $action;
}

function combatUndoCompactHistory(array $history): array {
    return array_map('combatUndoCompactRecord', $history);
}

function combatUndoHistoryBytes(array $value): int {
    return strlen(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR));
}

function combatUndoContinuationAliases(array $domains, array $activity): array {
    $live = [];
    foreach (['abilityExecutions' => 'execution', 'pendingAttacks' => 'attack', 'pendingAbilityCasts' => 'validation',
        'statChecks' => 'check', 'damageOverTime' => 'effect'] as $field => $kind) {
        foreach ($activity[$field] ?? [] as $entry) {
            if ($field === 'damageOverTime' && applicationIsImpactBleeding($entry)) continue;
            $live[$kind . ':' . $entry['id']] = true;
            if (!empty($entry['requestId'])) $live['request:' . $entry['requestId']] = true;
        }
    }
    foreach ($domains['items']['pendingUses'] ?? [] as $entry) $live['item-use:' . $entry['id']] = true;
    foreach ($domains['devices']['contactZones'] ?? [] as $zone) {
        if (!empty($zone['originExecutionId'])) $live['execution:' . $zone['originExecutionId']] = true;
    }
    return $live;
}

function combatUndoFinishRecord(array &$afterDomains, array $activity, array $history, int $index, array $action): array {
    unset($history[$index]);
    $history = [$action, ...array_values($history)];
    $compact = !empty($action['undoUnavailable']) || count($action['patches']) > 12000 || combatUndoHistoryBytes($action) > 1500000;
    if (!$compact && (count($history) > 120 || combatUndoHistoryBytes($history) > 2000000)) {
        $candidate = $history;
        $active = [];
        foreach ($activity['abilityExecutions'] ?? [] as $execution) {
            if (($execution['status'] ?? '') === 'active') $active['execution:' . $execution['id']] = true;
        }
        foreach (['pendingAttacks' => 'attack', 'pendingAbilityCasts' => 'validation'] as $field => $alias) {
            foreach ($activity[$field] ?? [] as $pending) $active[$alias . ':' . $pending['id']] = true;
        }
        $isActive = static function (array $trace) use ($active): bool {
            foreach ([$trace['aliases'], $trace['undoUnavailableAliases'] ?? []] as $aliases) {
                foreach ($aliases as $alias) if (isset($active[$alias])) return true;
            }
            return false;
        };
        while (count($candidate) > 120 || combatUndoHistoryBytes($candidate) > 2000000) {
            $oldest = count($candidate) - 1;
            while ($oldest > 0 && $isActive($candidate[$oldest])) $oldest--;
            // An older active trace can depend on the missing effects of an evicted later action.
            if ($oldest === 0 || $oldest !== count($candidate) - 1 || !empty($candidate[$oldest]['undoReceipts'])) {
                $compact = true;
                break;
            }
            array_pop($candidate);
        }
        if (!$compact) $history = $candidate;
    }
    if ($compact) {
        $history = combatUndoCompactHistory($history);
        $action = $history[0];
        if (count($history) > 120) {
            $oldest = count($history) - 1;
            while ($oldest > 0 && (!empty($history[$oldest]['undoReceipts']) || !empty($history[$oldest]['cancelled']))) $oldest--;
            $dropped = $history[$oldest];
            array_splice($history, $oldest, 1);
            // Keep the missing history detectable for continuing commands, with no reversible patches.
            $live = combatUndoContinuationAliases($afterDomains, $activity);
            $aliases = array_values(array_unique([
                ...($history[0]['undoUnavailableAliases'] ?? []), ...$dropped['aliases'], ...($dropped['undoUnavailableAliases'] ?? []),
            ]));
            $history[0]['undoUnavailableAliases'] = array_values(array_filter($aliases, static fn (string $alias): bool => isset($live[$alias])));
        }
    }
    foreach ($activity['playerActions'] ?? [] as $i => $entry) {
        if (in_array($entry['id'], $action['actionIds'], true)) $activity['playerActions'][$i]['combatActionId'] = $action['id'];
    }
    $activity['combatActions'] = $history;
    $afterDomains['activity'] = $activity;
    return $action;
}

function combatUndoRecord(array $beforeDomains,array &$afterDomains,string $command,array $args,array $result,?string $id=null):?array{
 if($command==='token.move'){$execution=null;foreach($beforeDomains['activity']['abilityExecutions']??[]as$e)if(($e['status']??'')==='active'&&($e['sourceTokenId']??'')===($args['tokenId']??'')&&($e['workflow']['steps'][$e['currentStepIndex']]['type']??'')==='movement'){$execution=$e;break;}if($execution===null){$oldContacts=array_keys(combatUndoAttacks($beforeDomains['activity']??[]));$contact=false;foreach(combatUndoAttacks($afterDomains['activity']??[])as$a)if(!empty($a['originExecutionId'])&&!in_array($a['id'],$oldContacts,true)){$contact=true;break;}if(!$contact)return null;}else $args['complexExecutionId']=$execution['id'];}
 if(!empty($result['deduplicated'])||!preg_match('/^(ability\.|token\.attack|token\.move|token\.roll|item\.(consume|activate|defenses|trap\.resolve|use\.cancel|opposition)|mechanics\.command|combat\.dot)/',$command)||$command==='token.roll'&&($args['kind']??'')!=='ability'&&($result['roll']['specialSuccessAwarded']??false)!==true)return null;
 $beforeActivity=$beforeDomains['activity']??[];$activity=$afterDomains['activity']??[];$list=$activity['combatActions']??[];$aliases=combatUndoAliases($command,$args,$result);$old=combatUndoAttacks($beforeActivity);$new=combatUndoAttacks($activity);$changed=[];
 foreach($new as$key=>$attack)if(!combatUndoSame($attack,$old[$key]??null)){$changed[]=$attack;$aliases[]='attack:'.$key;if(!empty($attack['complexExecutionId']))$aliases[]='execution:'.$attack['complexExecutionId'];if(!empty($attack['originExecutionId']))$aliases[]='execution:'.$attack['originExecutionId'];}
 foreach ([['statChecks','check'],['damageOverTime','effect']] as [$field,$kind]) {
     foreach ($activity[$field] ?? [] as $value) {
         if ($field === 'damageOverTime' && applicationIsImpactBleeding($value)) continue;
         if (!in_array($value['id'], array_column($beforeActivity[$field] ?? [],'id'), true))
             $aliases[] = $kind.':'.$value['id'];
     }
 }
 foreach($afterDomains['items']['pendingUses']??[]as$value)if(!in_array($value['id'],array_column($beforeDomains['items']['pendingUses']??[],'id'),true))$aliases[]='item-use:'.$value['id'];if($aliases===[])return null;
 $matching=[];$incomplete=false;foreach($list as$i=>$a){if(array_intersect($a['aliases'],$aliases)){if(!empty($a['cancelled']))throw new RuntimeException('Cette action a été annulée par le MJ.',409);$matching[]=$i;}$incomplete=$incomplete||!empty($a['undoUnavailableAliases'])&&array_intersect($a['undoUnavailableAliases'],$aliases)!==[];}$previousIds=array_column($beforeActivity['playerActions']??[],'id');$added=array_values(array_filter($activity['playerActions']??[],static fn($a)=>!in_array($a['id'],$previousIds,true)));
 if($matching===[]){$primary=null;foreach(array_reverse($added)as$a)if(in_array($a['kind']??'',['ability','ability-workflow','attack'],true)){$primary=$a;break;}$action=['id'=>$id??'combat-'.($args['requestId']??$args['arguments']['requestId']??$result['attack']['id']??$result['execution']['id']??$args['executionId']??$args['complexExecutionId']??$args['attackId']??$args['validationId']??$args['effectId']??$args['useId']??randomToken(16)),'sceneId'=>$args['sceneId']??$result['attack']['sceneId']??$result['execution']['sceneId']??$beforeDomains['table']['activeSceneId']??'','sourceTokenId'=>$args['sourceTokenId']??$args['tokenId']??$result['attack']['sourceTokenId']??$result['execution']['sourceTokenId']??'','label'=>$result['execution']['abilityName']??$result['attack']['attackName']??$primary['summary']??'Action de combat','serial'=>max([0,...array_column($list,'serial')])+1,'aliases'=>[],'actionIds'=>[],'patches'=>[],'damage'=>[],'credits'=>[],'cancelled'=>false,'complete'=>!(in_array($command,['ability.resolve','ability.check.respond','token.attack.resolve','token.attack.oppose','token.attack.area.oppose','token.attack.area.resolve','token.move','item.trap.resolve','item.use.cancel','item.opposition','item.opposition.validate'],true)||$command==='ability.complex'&&($args['action']??'')!=='start')];array_unshift($list,$action);$index=0;}
 else{$index=$matching[0];$action=$list[$index];foreach(array_slice($matching,1)as$i){foreach(['aliases','actionIds','patches','damage','credits','undoReceipts','undoUnavailableAliases']as$f)if(!empty($list[$i][$f]))$action[$f]=[...($action[$f]??[]),...$list[$i][$f]];$incomplete=$incomplete||!empty($list[$i]['undoUnavailable']);unset($list[$i]);}}
 if ($incomplete || !empty($action['undoUnavailable'])) {
     $action['aliases'] = array_values(array_unique([...$action['aliases'], ...$aliases]));
     $action['actionIds'] = array_values(array_unique([...$action['actionIds'], ...array_column($added, 'id')]));
     return combatUndoFinishRecord($afterDomains, $activity, $list, $index, combatUndoCompactRecord($action));
 }
 foreach($changed as$attack)if(isset($attack['deviceBeamDelta'])){$action['deviceBeamDeltas']??=[];if(!in_array($attack['id'],array_column($action['deviceBeamDeltas'],'attackId'),true))$action['deviceBeamDeltas'][]=['attackId'=>$attack['id'],'delta'=>$attack['deviceBeamDelta']];}
 $action['aliases']=array_values(array_unique([...$action['aliases'],...$aliases]));$action['actionIds']=array_values(array_unique([...$action['actionIds'],...array_column($added,'id')]));$b=combatUndoView($beforeDomains);$a=combatUndoView($afterDomains);$newPatches=[];foreach(['activity','devices','items','luck']as$key)if(isset($a[$key])){$b[$key]??=[];foreach($a[$key]as$field=>$value)if(!array_key_exists($field,$b[$key])){if(is_array($value)&&array_is_list($value))$b[$key][$field]=[];elseif($field==='version')$b[$key][$field]=$value;}}foreach(array_unique([...array_keys($b),...array_keys($a)])as$key)combatUndoDifference($b[$key]??null,$a[$key]??null,$key,[],$newPatches,array_key_exists($key,$b),array_key_exists($key,$a));
 foreach($newPatches as$p){$ancestor=-1;foreach($action['patches']as$i=>$old)if(!$old['hasBefore']&&$old['hasAfter']&&$old['domain']===$p['domain']&&count($old['path'])<=count($p['path'])&&combatUndoSame($old['path'],array_slice($p['path'],0,count($old['path'])))){$ancestor=$i;break;}if($ancestor>=0){$field=end($p['path']);if(count($p['path'])===count($action['patches'][$ancestor]['path'])+1&&in_array($field,['restUseCount','combatUseCount','percent','quantity','stackCount'],true)&&isset($p['before'],$p['after'])&&is_numeric($p['before'])&&is_numeric($p['after']))$action['patches'][$ancestor]['after'][$field]=($action['patches'][$ancestor]['after'][$field]??0)+$p['after']-$p['before'];}else $action['patches'][]=$p;}
 $damageEvents=[];foreach($changed as$attack)$damageEvents[]=['tokenId'=>$attack['targetTokenId'],'sceneId'=>$attack['sceneId'],'name'=>$attack['targetName']??'Cible','amount'=>combatUndoDamageAmount($attack)-combatUndoDamageAmount($old[$attack['id']]??null)];
 if(preg_match('/^(item\.|combat\.dot|mechanics\.)/',$command))foreach($activity['rolls']??[]as$roll){$event=$roll['mapEvent']??[];if(in_array($roll['id'],array_column($beforeActivity['rolls']??[],'id'),true)||($event['kind']??'')!=='damage'||($event['applied']??false)!==true||in_array($event['attackId']??'',array_column($changed,'id'),true))continue;$damageEvents[]=['tokenId'=>$event['targetTokenId']??'','sceneId'=>$event['sceneId']??'','name'=>$event['targetName']??'Cible','amount'=>(float)($event['value']??0)];}
 foreach($damageEvents as$event){if($event['amount']<=0||$event['tokenId']==='')continue;$d=-1;foreach($action['damage']as$i=>$v)if($v['tokenId']===$event['tokenId']&&$v['sceneId']===$event['sceneId'])$d=$i;if($d<0){$d=count($action['damage']);$action['damage'][]=['tokenId'=>$event['tokenId'],'sceneId'=>$event['sceneId'],'entityKey'=>combatUndoEntityKey($afterDomains,$event['sceneId'],$event['tokenId']),'name'=>$event['name'],'amount'=>0,'refunded'=>0];}$action['damage'][$d]['amount']+=$event['amount'];}

 return combatUndoFinishRecord($afterDomains, $activity, $list, $index, $action);
}
// Undo patches address the original document, including its scene identity.
function combatUndoRead(array $domains,array $p):array{$key=$p['domain'];if(!array_key_exists($key,$domains))return [false,null];$current=$domains[$key];foreach($p['path']as$part){if(!is_array($current))return [false,null];if(combatUndoSelector($part)){$index=array_search($part['id'],array_column($current,'id'),true);if($index===false)return [false,null];$current=$current[$index];}else{if(!array_key_exists($part,$current))return [false,null];$current=$current[$part];}}return [true,$current];}
function combatUndoPut(array &$domains,array $p,mixed $value,bool $exists):void{
 $key=$p['domain'];$path=$p['path'];if($path===[]){if($exists)$domains[$key]=$value;else unset($domains[$key]);return;}if(!array_key_exists($key,$domains))return;$current=&$domains[$key];$last=array_pop($path);foreach($path as$part){if(!is_array($current))return;if(combatUndoSelector($part)){$i=array_search($part['id'],array_column($current,'id'),true);if($i===false)return;$current=&$current[$i];}else{if(!array_key_exists($part,$current))return;$current=&$current[$part];}}
 if(combatUndoSelector($last)){$i=array_search($last['id'],array_column($current,'id'),true);if($i===false){if($exists)$current[]=$value;}elseif($exists)$current[$i]=$value;else array_splice($current,$i,1);}elseif($exists)$current[$last]=$value;else unset($current[$last]);
}
function combatUndoNumeric(array $p):bool{return $p['hasBefore']&&$p['hasAfter']&&(is_int($p['before'])||is_float($p['before']))&&(is_int($p['after'])||is_float($p['after']))&&!preg_match('/At$|Until$|Serial$|^(x|y|size|readyRound|currentStepIndex|round)$/',is_string(end($p['path']))?end($p['path']):'');}
function combatUndoBounded(?array $entity,array $path,float $value):float|int{
 $field=end($path);
 if($field==='specialSuccessStock')return (int)max(0,min(9999,$value));
 if($field==='personalAdvantageStock')return (int)max(0,min(9007199254740991,$value));
 if(count($path)===3&&$path[0]==='characters'&&in_array($field,['rollCount','rawTotal'],true))return (int)max(0,min(1e9,$value));
 if(in_array($field,['hp','mana'],true)){$holder=count($path)===2?($entity[$path[0]]??[]):$entity;$max=$holder[$field==='hp'?'maxHp':'maxMana']??null;if(is_numeric($max))$value=min($value,(float)$max);if($field==='mana')$value=max(0,$value);}
 if($field==='current'&&($path[count($path)-2]??'')==='fatigue')$value=max(0,$value);
 return max(-1e9,min(1e9,$value));
}
function combatUndoRestoreImpactBleeding(array &$domains,array $patch,?array $current):void {
    $after = $patch['after'] ?? [];
    if (empty($after['impactPulseId']) || ($current['impactPulseId'] ?? '') !== $after['impactPulseId']) return;
    $before = $patch['before'] ?? null;
    if ($before !== null) {
        foreach ($domains['activity']['playerActions'] ?? [] as $entry) {
            if (!empty($entry['undo']) && ($entry['operation']['kind'] ?? '') === 'resource-adjust'
                && ($entry['operation']['impactBleeding']['after']['impactPulseId'] ?? null) === ($before['impactPulseId'] ?? '')) {
                $before = null;
                break;
            }
        }
    }
    if ($before !== null) {
        foreach ($domains['activity']['combatActions'] ?? [] as $action) {
            $refunded = array_filter($action['damage'] ?? [], static fn(array $damage):bool =>
                $damage['tokenId'] === $before['targetTokenId'] && ($damage['sceneId'] ?? '') === ($before['sceneId'] ?? '')
                && ($damage['refunded'] ?? 0) > 0);
            if (empty($action['cancelled']) && $refunded === []) continue;
            foreach ($action['patches'] ?? [] as $other) {
                if (combatUndoImpactBleedingPath($other['domain'], $other['path'])
                    && ($other['after']['impactPulseId'] ?? '') === ($before['impactPulseId'] ?? null)) {
                    $before = null;
                    break 2;
                }
            }
        }
    }
    if ($before !== null) {
        $ticks = max(0, (int) $after['remainingTurns'] - (int) $current['remainingTurns']);
        $before['remainingTurns'] = max(0, (int) $before['remainingTurns'] - $ticks);
        if ($ticks > 0) {
            foreach (['combatId','lastManualTurn','lastTurnSerial','lastTargetRound'] as $clock) {
                if (array_key_exists($clock, $current)) $before[$clock] = $current[$clock];
                else unset($before[$clock]);
            }
        }
    }
    combatUndoPut($domains, $patch, $before, ($before['remainingTurns'] ?? 0) > 0);
}
function combatUndoRestoreManualImpactBleeding(array &$activity, array $operation): void {
    $change = applicationImpactBleedingOperationChange($operation);
    if ($change === null) return;
    $patch = [
        'domain'=>'activity',
        'path'=>['damageOverTime', ['id'=>$change['after']['id']]],
        'hasBefore'=>$change['before'] !== null,
        'hasAfter'=>true,
        ...$change,
    ];
    $domains = ['activity'=>$activity];
    combatUndoRestore($domains, $patch);
    $activity = $domains['activity'];
}
function combatUndoRestore(array &$domains,array $p):void{
 [$exists,$current]=combatUndoRead($domains,$p);$after=$p['after']??null;$before=$p['before']??null;$last=$p['path']===[]?null:end($p['path']);
 if (combatUndoImpactBleedingPath($p['domain'], $p['path'])) {
     combatUndoRestoreImpactBleeding($domains, $p, is_array($current) ? $current : null);
     return;
 }
 if(!$p['hasBefore']&&$p['hasAfter']&&is_array($after)&&combatUndoSelector($last)&&is_array($current)&&!combatUndoSame($current,$after)){$counters=[];foreach(['restUseCount','combatUseCount','percent','quantity','stackCount']as$k)if(is_numeric($after[$k]??null)&&is_numeric($current[$k]??null))$counters[]=$k;if($counters){$next=$current;$keep=false;foreach($counters as$k){$next[$k]=max(0,$next[$k]-$after[$k]);$keep=$keep||$next[$k]>0;}combatUndoPut($domains,$p,$next,$keep);return;}}
 if(!$p['hasBefore']&&$p['hasAfter']&&is_array($after)&&array_is_list($after)&&count(array_filter($after,'is_string'))===count($after)&&is_array($current)&&array_is_list($current)){$remaining=array_values(array_filter($current,static fn($v)=>!in_array($v,$after,true)));combatUndoPut($domains,$p,$remaining,$remaining!==[]);return;}
 if(!$p['hasBefore']){if(combatUndoSelector($last)||combatUndoSame($current,$after))combatUndoPut($domains,$p,null,false);return;}if(!$p['hasAfter']){if(!$exists)combatUndoPut($domains,$p,$before,true);return;}
 if(is_array($before)&&is_array($after)&&is_array($current)&&array_is_list($before)&&array_is_list($after)&&count(array_filter($before,'is_string'))===count($before)&&count(array_filter($after,'is_string'))===count($after)){combatUndoPut($domains,$p,array_values(array_unique([...array_filter($current,static fn($v)=>in_array($v,$before,true)||!in_array($v,$after,true)),...array_filter($before,static fn($v)=>!in_array($v,$after,true))])),true);return;}
 if(combatUndoSame($current,$after))combatUndoPut($domains,$p,$before,true);
}
function combatUndoMark(array &$activity,array $action,string $tokenId='',string $sceneId=''):void{
 $selected=static fn($a)=>is_array($a)&&(in_array('attack:'.($a['id']??''),$action['aliases'],true)||in_array('execution:'.($a['complexExecutionId']??''),$action['aliases'],true)||in_array('execution:'.($a['originExecutionId']??''),$action['aliases'],true))&&($tokenId===''||($a['targetTokenId']??'')===$tokenId&&($sceneId===''||($a['sceneId']??'')===$sceneId));
 if($tokenId===''){$activity['pendingAttacks']=array_values(array_filter($activity['pendingAttacks']??[],static fn($a)=>!$selected($a)));$activity['pendingAbilityCasts']=array_values(array_filter($activity['pendingAbilityCasts']??[],static fn($v)=>!in_array('validation:'.$v['id'],$action['aliases'],true)&&!in_array('request:'.($v['requestId']??''),$action['aliases'],true)));}
 foreach($activity['attackReceipts']??[]as$i=>$receipt)if($selected($receipt['attack']??null))$activity['attackReceipts'][$i]['attack']=[...$receipt['attack'],...($tokenId===''?['status'=>'cancelled','actionUndone'=>true]:['damageUndone'=>true]),'appliedDamage'=>0];
 if($tokenId==='')foreach($activity['resourceReceipts']??[]as$i=>$r)if(in_array('request:'.($r['requestId']??''),$action['aliases'],true)||in_array($r['actionId']??'',$action['actionIds'],true))if(isset($r['result'])){$r['result']=[...$r['result'],'actionUndone'=>true,'castSucceeded'=>false,'execution'=>null,'pendingUse'=>null,'pendingOpposition'=>false,'pendingValidation'=>false];if(isset($r['result']['attack']))$r['result']['attack']=[...$r['result']['attack'],'status'=>'cancelled','appliedDamage'=>0,'actionUndone'=>true];if(isset($r['result']['attacks']))foreach($r['result']['attacks']as$j=>$a)$r['result']['attacks'][$j]=[...$a,'status'=>'cancelled','appliedDamage'=>0,'actionUndone'=>true];unset($r['result']['roll']['mapEvent']);foreach($r['result']['rolls']??[]as$j=>$v)unset($r['result']['rolls'][$j]['mapEvent']);$activity['resourceReceipts'][$i]=$r;}
 foreach($activity['rolls']??[]as$i=>$roll)if($selected(['id'=>$roll['mapEvent']['attackId']??'','complexExecutionId'=>$roll['mapEvent']['complexExecutionId']??'','targetTokenId'=>$roll['mapEvent']['targetTokenId']??'','sceneId'=>$roll['mapEvent']['sceneId']??'']))unset($activity['rolls'][$i]['mapEvent']);
 foreach($activity['playerActions']??[]as$i=>$entry)if(in_array($entry['id'],$action['actionIds'],true)){$activity['playerActions'][$i]['combatActionId']=$action['id'];if($tokenId==='')$activity['playerActions'][$i]['combatUndone']=true;}
}
function combatUndoDeviceReceipts(array &$domains,array $action):void{foreach($domains['devices']['receipts']??[]as$i=>$receipt)if(in_array('request:'.($receipt['requestId']??''),$action['aliases'],true)&&isset($receipt['result'])){$r=[...$receipt['result'],'actionUndone'=>true,'cancelled'=>true,'castSucceeded'=>false,'pendingValidation'=>false,'pendingOpposition'=>false,'pendingUse'=>null,'execution'=>null,'completionCue'=>null];if(isset($r['attack']))$r['attack']=[...$r['attack'],'status'=>'cancelled','appliedDamage'=>0,'actionUndone'=>true];foreach($r['attacks']??[]as$j=>$a)$r['attacks'][$j]=[...$a,'status'=>'cancelled','appliedDamage'=>0,'actionUndone'=>true];unset($r['roll']['mapEvent']);foreach($r['rolls']??[]as$j=>$v)unset($r['rolls'][$j]['mapEvent']);$domains['devices']['receipts'][$i]['result']=$r;}}
function combatUndoTimerId(array $patch): ?string {
    $path = $patch['path'];
    return $patch['domain'] === 'activity' && ($path[0] ?? null) === 'actionTimers'
        && combatUndoSelector($path[1] ?? null) && count($path) <= 3 ? $path[1]['id'] : null;
}

function combatUndoTimerValues(array $patch, string $side): array {
    if (!($patch[$side === 'before' ? 'hasBefore' : 'hasAfter'] ?? false)) return [];
    return count($patch['path']) === 2 ? $patch[$side] : [$patch['path'][2] => $patch[$side]];
}

function combatUndoRestoreTimers(array &$domains, array $action): void {
    $groups = [];
    foreach ($action['patches'] as $patch) {
        $id = combatUndoTimerId($patch);
        if ($id !== null) $groups[$id][] = $patch;
    }
    if ($groups === []) return;
    $activity = &$domains['activity'];
    $timeline = [];
    foreach ($activity['combatActions'] ?? [] as $other) {
        if ($other['id'] === $action['id']) continue;
        foreach ($other['patches'] as $patch) {
            $id = combatUndoTimerId($patch);
            if ($id !== null && isset($groups[$id])) {
                $timeline[$id][] = [$patch, (int) ($other['serial'] ?? 0), !empty($other['cancelled'])];
            }
        }
    }

    $tombstones = array_column($activity['actionTimerTombstones'] ?? [], null, 'id');
    $clockFields = ['readyRound', 'readyAt', 'usedRound', 'turnKey', 'combatId', 'cooldown', 'cooldownActive', 'restRecharge'];
    $usageFields = ['restUseCount', 'combatUseCount', 'useCount'];
    foreach ($groups as $id => $patches) {
        $entity = ['domain' => 'activity', 'path' => ['actionTimers', ['id' => $id]]];
        [$exists, $current] = combatUndoRead($domains, $entity);
        if (!$exists) {
            foreach (array_reverse($patches) as $patch) {
                if (count($patch['path']) === 2 && $patch['hasBefore'] && !$patch['hasAfter'] && !isset($tombstones[$id])) {
                    combatUndoPut($domains, $entity, $patch['before'], true);
                    break;
                }
            }
            continue;
        }

        $expected = [];
        $deltas = [];
        $requestsAdded = $requestsRemoved = [];
        $created = false;
        foreach ($patches as $patch) {
            $before = combatUndoTimerValues($patch, 'before');
            $after = combatUndoTimerValues($patch, 'after');
            $expected = array_replace($expected, $after);
            $created = $created || (count($patch['path']) === 2 && !$patch['hasBefore'] && $patch['hasAfter']);
            foreach ($usageFields as $field) {
                $deltas[$field] = ($deltas[$field] ?? 0) + (int) ($after[$field] ?? 0) - (int) ($before[$field] ?? 0);
            }
            $requestsAdded = [...$requestsAdded, ...array_diff($after['requestIds'] ?? [], $before['requestIds'] ?? [])];
            $requestsRemoved = [...$requestsRemoved, ...array_diff($before['requestIds'] ?? [], $after['requestIds'] ?? [])];
        }

        $newer = $olderActive = $oldCreatorCancelled = false;
        $reset = [];
        foreach ($timeline[$id] ?? [] as [$patch, $serial, $cancelled]) {
            if ($serial <= ($action['serial'] ?? 0)) {
                $olderActive = $olderActive || !$cancelled;
                $oldCreatorCancelled = $oldCreatorCancelled || ($cancelled && count($patch['path']) === 2 && !$patch['hasBefore'] && $patch['hasAfter']);
                continue;
            }
            if ($cancelled) continue;
            $newer = true;
            $before = combatUndoTimerValues($patch, 'before');
            $after = combatUndoTimerValues($patch, 'after');
            foreach ($usageFields as $field) {
                if (($before[$field] ?? 0) > 0 && ($after[$field] ?? null) === 0) $reset[$field] = true;
            }
            foreach (['combatId' => ['combatUseCount', 'useCount'], 'turnKey' => ['useCount']] as $axis => $fields) {
                if (($before[$axis] ?? null) === ($after[$axis] ?? null)) continue;
                foreach ($fields as $field) $reset[$field] = true;
            }
        }
        foreach (['combatId' => ['combatUseCount', 'useCount'], 'turnKey' => ['useCount']] as $axis => $fields) {
            if (!array_key_exists($axis, $expected) || ($current[$axis] ?? null) === $expected[$axis]) continue;
            foreach ($fields as $field) $reset[$field] = true;
        }
        foreach ($clockFields as $field) {
            if (array_key_exists($field, $expected) && !combatUndoSame($current[$field] ?? null, $expected[$field])) {
                $newer = true;
            }
        }
        if (!$newer && ($created || ($oldCreatorCancelled && !$olderActive))) {
            combatUndoPut($domains, $entity, null, false);
            $tombstones[$id] ??= ['id' => $id, 'deletedAt' => gmdate('c')];
            $activity['actionTimerTombstones'] = array_slice(array_values($tombstones), -1000);
            continue;
        }

        // Reused timers retain their newer clock. Only refund this action's surviving usage.
        if (!$newer) {
            foreach (array_reverse($patches) as $patch) {
                $field = $patch['path'][2] ?? null;
                if (!in_array($field, [...$usageFields, 'requestIds'], true)) combatUndoRestore($domains, $patch);
            }
        }
        [, $timer] = combatUndoRead($domains, $entity);
        if (!is_array($timer)) continue;
        foreach ($deltas as $field => $delta) {
            if ($delta !== 0 && !isset($reset[$field]) && (isset($timer[$field]) || $delta < 0)) {
                $timer[$field] = max(0, (int) ($timer[$field] ?? 0) - $delta);
            }
        }
        if (!isset($reset['useCount']) && isset($timer['requestIds']) && ($requestsAdded !== [] || $requestsRemoved !== [])) {
            $timer['requestIds'] = array_values(array_unique([...array_diff($timer['requestIds'], $requestsAdded), ...$requestsRemoved]));
        }
        combatUndoPut($domains, $entity, $timer, true);
    }
}

function combatUndoDependencies(array $activity,array $action):void{
 $created=array_filter($action['patches'],static fn($p)=>combatUndoTimerId($p)===null
    &&!combatUndoImpactBleedingPath($p['domain'],$p['path'])&&!$p['hasBefore']&&$p['hasAfter']&&($p['path']===[]||combatUndoSelector(end($p['path']))));
 foreach($activity['combatActions']??[]as$other){if($other['id']===$action['id']||!empty($other['cancelled'])||($other['serial']??0)<=($action['serial']??0))continue;foreach($other['patches']as$p)foreach($created as$c)if($c['domain']===$p['domain']&&count($c['path'])<=count($p['path'])&&combatUndoSame($c['path'],array_slice($p['path'],0,count($c['path']))))throw new RuntimeException('Une action plus récente utilise un effet ou un compteur créé par celle-ci. Annulez d’abord : '.$other['label'].'.',409);}
}
function combatUndoPreserveTimed(array &$domains,array $action,array $previous):void{
 foreach($action['patches']as$p){if($p['hasBefore']||!$p['hasAfter']||$p['domain']!=='devices'||($p['path'][0]??'')!=='timedConditions'||empty($p['after']['label']))continue;$removed=$p['after'];foreach($previous as$c)if($c['id']===$removed['id']){$removed=$c;break;}$key=!empty($removed['targetCharacterId'])?'character:'.$removed['targetCharacterId']:combatUndoEntityKey($domains,$removed['sceneId'],$removed['targetTokenId']);$matches=[];foreach($domains['devices']['timedConditions']??[]as$i=>$c)if($c['label']===$removed['label']&&(!empty($c['targetCharacterId'])?'character:'.$c['targetCharacterId']:combatUndoEntityKey($domains,$c['sceneId'],$c['targetTokenId']))===$key)$matches[]=$i;
 if($matches){$introduced=false;foreach($matches as$i)$introduced=$introduced||!empty($domains['devices']['timedConditions'][$i]['introduced']);if(!empty($removed['introduced'])&&!$introduced)$domains['devices']['timedConditions'][$matches[0]]['introduced']=true;if(isset($domains[$key]))$domains[$key]['conditions']=array_values(array_unique([...($domains[$key]['conditions']??[]),$removed['label']]));}elseif(!empty($removed['introduced'])&&isset($domains[$key]))$domains[$key]['conditions']=array_values(array_filter($domains[$key]['conditions']??[],static fn($label)=>$label!==$removed['label']));if(isset($domains[$key]['conditions']))$domains[$key]['condition']=implode(', ',$domains[$key]['conditions']);}
}
function combatUndoItemDependencies(array $domains,array $action):void{
 foreach($action['patches']as$patch){$path=$patch['path'];if($patch['domain']!=='items'||count($path)!==3||$path[0]!=='instances'||!combatUndoSelector($path[1])||$path[2]!=='vesselContentTemplateId'||!$patch['hasBefore']||$patch['hasAfter'])continue;foreach($domains['items']['instances']??[]as$item)if($item['id']===$path[1]['id']&&!empty($item['vesselRequest']))throw new RuntimeException('Cette fiole attend une transformation plus récente. Validez ou refusez cette demande avant d’annuler son utilisation précédente.',409);}
}
function combatUndoBeamWallPatch(array $patch,array $action):bool {
 foreach($action['deviceBeamDeltas']??[]as$entry){$delta=$entry['delta'];if($patch['domain']!=='map:'.$delta['sceneId'])continue;$path=$patch['path'];if(($path[0]??'')==='walls'||($path[0]??'')==='layers'&&($path[1]??'')===$delta['layerId']&&($path[2]??'')==='walls')return true;}return false;
}
function combatUndoRestoreBeamDeltas(array &$domains,array $action,bool $validateOnly=false):void {
 foreach(array_reverse($action['deviceBeamDeltas']??[])as$entry){$delta=$entry['delta'];$key='map:'.$delta['sceneId'];if(!isset($domains[$key]))throw new RuntimeException('La scène de ce rayon n’existe plus.',409);$map=&$domains[$key];$layer=$map['layers'][$delta['layerId']]??null;$working=[...$map,...($layer??[])];$walls=applicationRestoreBeamWallDelta($working['walls']??[],$delta['wall'],$working);if($validateOnly)continue;
  $remove=static fn($effects)=>array_values(array_filter($effects??[],static fn($effect)=>!in_array($effect['id']??'',$delta['terrainEffectIds'],true)));
  if($layer!==null){$map['layers'][$delta['layerId']]['walls']=$walls;$map['layers'][$delta['layerId']]['terrainEffects']=$remove($layer['terrainEffects']??[]);}
  if(($map['activeLayerId']??'ground')===$delta['layerId']){$map['walls']=$walls;$map['terrainEffects']=$remove($map['terrainEffects']??[]);}unset($map);
 }
}
function combatUndoApply(array &$domains,array $args):array{
 $scope=$args['scope']??'action';$tokenId=$args['tokenId']??'';$requestId=$args['requestId']??'';$actorId=$args['actorId']??'';if(!in_array($scope,['action','target-damage'],true)||!is_string($requestId)||!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$requestId))throw new RuntimeException('Référence d’annulation invalide.',400);
 $activity=&$domains['activity'];$entry=null;foreach($activity['playerActions']??[]as$e)if($e['id']===($args['actionId']??''))$entry=$e;$index=-1;foreach($activity['combatActions']??[]as$i=>$a)if($a['id']===($entry['combatActionId']??$args['actionId']??''))$index=$i;if($index<0)throw new RuntimeException('Le suivi complet de cette action n’est plus disponible.',404);$action=$activity['combatActions'][$index];$signature=$scope.':'.$tokenId;
 foreach($action['undoReceipts']??[]as$r)if($r['requestId']===$requestId){if($r['actorId']!==$actorId||$r['signature']!==$signature)throw new RuntimeException('Cette référence appartient à une autre annulation.',409);return [...$r,'deduplicated'=>true];}if(!empty($action['cancelled']))throw new RuntimeException('Cette action a déjà été annulée.',409);if(!empty($action['undoUnavailable']))throw new RuntimeException('L’historique de cette action ne permet plus de l’annuler en sécurité.',409);if($scope==='action'&&($action['complete']??true)===false)throw new RuntimeException('Le début de cette action précède le suivi complet. Ses coûts et effets anciens ne peuvent pas être reconstruits avec certitude.',409);if($scope==='action'){combatUndoDependencies($activity,$action);combatUndoItemDependencies($domains,$action);}$restoredDamage=0;
 if($scope==='target-damage'){
    $di = combatUndoDamageIndex($action, $tokenId);
    if($di<0)throw new RuntimeException('Cette cible n’a plus de dégâts à retirer pour cette action.',409);
    $target = $action['damage'][$di];
    $targetScene = (string) ($target['sceneId'] ?? '');
    $entityKey = combatUndoDamageEntityKey($domains, $target);
    $entity = $domains[$entityKey] ?? null;
    if($entity===null)throw new RuntimeException('Cette unité n’existe plus.',409);
    $path=isset($entity['resources'])?['resources','hp']:['hp'];$p=['domain'=>$entityKey,'path'=>$path];[, $previous]=combatUndoRead($domains,$p);
    $next=combatUndoBounded($entity,$path,(float)$previous+$target['amount']-$target['refunded']);
    combatUndoPut($domains,$p,$next,true);$restoredDamage=$next-$previous;
    $action['damage'][$di]['refunded']=$target['amount'];$action['credits'][]=['domain'=>$entityKey,'path'=>$path,'amount'=>$restoredDamage];
    foreach (array_reverse($action['patches']) as $patch) {
        if (combatUndoImpactBleedingPath($patch['domain'], $patch['path']) && ($patch['after']['targetTokenId'] ?? '') === $tokenId
            && ($targetScene === '' || ($patch['after']['sceneId'] ?? '') === $targetScene))
            combatUndoRestore($domains, $patch);
    }
    combatUndoMark($activity,$action,$tokenId,$targetScene);
 }
 else{combatUndoRestoreBeamDeltas($domains,$action,true);$previousConditions=$domains['devices']['timedConditions']??[];$executions=array_values(array_filter($activity['abilityExecutions']??[],static fn($e)=>in_array('execution:'.$e['id'],$action['aliases'],true)));$amounts=[];foreach($action['patches']as$p)if(combatUndoTimerId($p)===null&&combatUndoNumeric($p)){$key=json_encode([$p['domain'],$p['path']]);$amounts[$key]??=['patch'=>$p,'amount'=>0];$amounts[$key]['amount']+=$p['before']-$p['after'];}foreach($action['credits']as$c){$key=json_encode([$c['domain'],$c['path']]);$amounts[$key]??=['patch'=>['domain'=>$c['domain'],'path'=>$c['path']],'amount'=>0];$amounts[$key]['amount']-=$c['amount'];}foreach(array_reverse($action['patches'])as$p)if(combatUndoTimerId($p)===null&&!combatUndoNumeric($p)&&!combatUndoBeamWallPatch($p,$action))combatUndoRestore($domains,$p);combatUndoRestoreTimers($domains,$action);combatUndoRestoreBeamDeltas($domains,$action);applicationRemoveActionExplosionFire($domains,$action);foreach($amounts as$row){$p=$row['patch'];[$exists,$value]=combatUndoRead($domains,$p);if($exists&&(is_int($value)||is_float($value)))combatUndoPut($domains,$p,combatUndoBounded($domains[$p['domain']]??null,$p['path'],$value+$row['amount']),true);}foreach($executions as$e){$cancelled=[...$e,'status'=>'cancelled','revision'=>$e['revision']+1,'updatedAt'=>(int)floor(microtime(true)*1000),'completedAt'=>(int)floor(microtime(true)*1000)];$i=array_search($e['id'],array_column($activity['abilityExecutions']??[],'id'),true);if($i===false)$activity['abilityExecutions'][]=$cancelled;else $activity['abilityExecutions'][$i]=$cancelled;}$action['cancelled']=true;combatUndoMark($activity,$action);combatUndoDeviceReceipts($domains,$action);combatUndoPreserveTimed($domains,$action,$previousConditions);}
 $receipt=['requestId'=>$requestId,'actorId'=>$actorId,'actorName'=>$args['actorName']??'MJ','signature'=>$signature,'scope'=>$scope,'tokenId'=>$tokenId,'restoredDamage'=>$restoredDamage,'actionId'=>$action['id'],'createdAt'=>gmdate('c')];$action['undoReceipts'][]=$receipt;$activity['combatActions'][$index]=$action;return [...$receipt,'deduplicated'=>false];
}

function combatUndoStamp(array $before,array &$after):void{
 $now=(int)floor(microtime(true)*1000);foreach($after as$key=>$entity)if(preg_match('/^(character|token):/',$key)&&!combatUndoSame($before[$key]??null,$entity)){$after[$key]['_updatedAt']=max($now,(int)($before[$key]['_updatedAt']??0)+1);if(str_starts_with($key,'token:'))foreach(['x','y','layerId']as$field)if(($before[$key][$field]??null)!==($entity[$field]??null))$after[$key]['_movedAt']=$after[$key]['_updatedAt'];}
 foreach([['items',['instances','templates']],['devices',['devices']]]as[$domain,$fields])foreach($fields as$field)foreach($after[$domain][$field]??[]as$i=>$entity){$old=null;foreach($before[$domain][$field]??[]as$v)if($v['id']===$entity['id']){$old=$v;break;}if(!combatUndoSame($old,$entity)&&(isset($old['revision'])||isset($entity['revision'])))$after[$domain][$field][$i]['revision']=max(0,(int)($old['revision']??0),(int)($entity['revision']??0))+1;}
}
function onlineUndoCombatAction(PDO $db,array &$records,array &$pending,array $identity,array $args):array{
 $records=array_replace($records,applicationDomainRecords($db,['activity']));$activity=$pending['activity']['payload']??applicationDomainPayload($records,'activity');$entry=null;foreach($activity['playerActions']??[]as$a)if($a['id']===($args['actionId']??''))$entry=$a;$action=null;foreach($activity['combatActions']??[]as$a)if($a['id']===($entry['combatActionId']??''))$action=$a;if($action===null)throw new RuntimeException('Le suivi complet de cette action n’est plus disponible.',404);
 $keys = ['activity', ...array_column($action['patches'], 'domain'), ...array_column($action['damage'], 'entityKey')];
 if (($args['scope'] ?? '') === 'target-damage') {
     $damageIndex = combatUndoDamageIndex($action, $args['tokenId'] ?? null);
     $damage = $action['damage'][$damageIndex] ?? [];
     if (!empty($damage['sceneId']) && !str_starts_with($damage['entityKey'] ?? '', 'character:')) {
         $tokenKey = onlineTokenDomainKey($damage['sceneId'], $damage['tokenId']);
         if ($tokenKey !== '') $keys[] = $tokenKey;
     }
 }
 $records=array_replace($records,applicationDomainRecords($db,array_values(array_unique($keys))));$domains=[];foreach($records as$k=>$r)$domains[$k]=applicationDomainPayload($records,$k);foreach($pending as$k=>$r)if(($r['operation']??'upsert')==='delete')unset($domains[$k]);else $domains[$k]=$r['payload'];
 $before=$domains;
 $archived=($args['scope']??'action')==='action'?applicationReadUndoResourceReceipts($db,$action,applicationResourceReceiptClock()):[];
 $inline=array_column($domains['activity']['resourceReceipts']??[],null,'requestId');
 $archived=array_diff_key($archived,$inline);
 if($archived!==[])$domains['activity']['resourceReceipts']=[...array_values($inline),...array_values($archived)];
 $archivedAttacks=applicationReadUndoDirectAttackReceipts($db,$action,applicationResourceReceiptClock());
 $storedAttacks=$domains['activity']['attackReceipts']??[];
 $archivedAttacks=array_diff_key($archivedAttacks,array_column($storedAttacks,null,'requestId'));
 if($archivedAttacks!==[])$domains['activity']['attackReceipts']=[...$storedAttacks,...array_values($archivedAttacks)];
 $result=combatUndoApply($domains,[...$args,'actorId'=>$identity['id'],'actorName'=>$identity['display_name']??'MJ']);
 if($archivedAttacks!==[]){
     $updated=[];
     $retained=[];
     foreach($domains['activity']['attackReceipts'] as $receipt){
         $id=$receipt['requestId']??'';
         if(isset($archivedAttacks[$id])){
             if(!combatUndoSame($archivedAttacks[$id],$receipt))$updated[]=$receipt;
         }else $retained[]=$receipt;
     }
     if(!$result['deduplicated'])applicationWriteDirectAttackReceipts($db,$updated);
     $domains['activity']['attackReceipts']=$retained;
 }
 if($archived!==[]){
     $updated=[];
     $retained=[];
     foreach($domains['activity']['resourceReceipts'] as $receipt){
         $id=$receipt['requestId']??'';
         if(isset($archived[$id])){
             if(!combatUndoSame($archived[$id],$receipt))$updated[]=$receipt;
         }else $retained[]=$receipt;
     }
     if(!$result['deduplicated'])applicationWriteResourceReceiptLedger($db,$updated);
     $domains['activity']['resourceReceipts']=$retained;
 }
 if(!$result['deduplicated'])combatUndoStamp($before,$domains);
 foreach($domains as$k=>$character)if(str_starts_with($k,'character:')&&!combatUndoSame($before[$k]??null,$character)){$occurrences=applicationCharacterTokenDomainRecords($db,substr($k,10));$records=array_replace($records,$occurrences);foreach($occurrences as$tk=>$record){$token=$domains[$tk]??$pending[$tk]['payload']??applicationDomainPayload($records,$tk);$before[$tk]??=$token;if(unitUsesCharacterSheet($token))$domains[$tk]=synchronizeOnlineCharacterToken($token,$character);}}
 foreach(array_unique([...array_keys($before),...array_keys($domains)])as$k)if(!combatUndoSame($before[$k]??null,$domains[$k]??null)){if(!isset($domains[$k]))queueOnlineDomainDelete($pending,$records,$k);else queueOnlineDomainUpsert($pending,$records,$k,$domains[$k]);}

 if(!$result['deduplicated'])onlineAppendPlayerAction($db,$records,$pending,$identity,$action['sceneId'],['kind'=>'undo','summary'=>$result['scope']==='target-damage'?'Retire les dégâts d’une cible':'Annule toute l’action','detail'=>$action['label']]);return [...$result,'combatAction'=>true];
}
