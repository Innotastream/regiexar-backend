<?php
declare(strict_types=1);
require_once __DIR__.'/ability-movement-lock.php';
require_once __DIR__.'/attack-enhancements.php';
require_once __DIR__.'/token-map-scale.php';

const PASSAGE_USE_RANGE_CELLS=2.5;

function passageFailure(string $message,int $status=409):never {throw new RuntimeException($message,$status);}
function passageId(mixed $id):bool {return is_string($id)&&preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$id)===1;}
function passageCoordinate(mixed $value):bool {return (is_int($value)||is_float($value))&&is_finite((float)$value)&&$value>=0&&$value<=100;}
function passageNameLength(string $value):int {
    if(preg_match_all('/./us',$value,$matches)===false)return PHP_INT_MAX;
    $length=0;foreach($matches[0] as $character)$length+=strlen($character)===4?2:1;return $length;
}
function passageArrivalValidator(array $map,array $start):Closure {
    $walls=applicationMovementWalls($map['walls']??[]);$fog=applicationActiveMapFogState($map);
    $masks=[];
    if(($walls['mask']??'')!==''&&($bytes=applicationWallMaskBytes($walls))!==null)$masks[]=[$walls,$bytes];
    if(($fog['enabled']??false)&&($fog['mask']??'')!==''&&($bytes=applicationFogMaskBytes($fog))!==null)$masks[]=[$fog,$bytes];
    $width=max($walls['width']??1,$fog['width']??1);$height=max($walls['height']??1,$fog['height']??1);
    return static function(array $point) use($start,$masks,$width,$height):bool {
        $steps=max(1,(int)ceil(max(abs($point['x']-$start['x'])*$width,abs($point['y']-$start['y'])*$height)/100*2));
        for($i=0;$i<=$steps;$i++){
            $x=$start['x']+($point['x']-$start['x'])*$i/$steps;$y=$start['y']+($point['y']-$start['y'])*$i/$steps;
            foreach($masks as [$mask,$bytes]){
                $column=(int)round(max(0,min(100,$x))/100*($mask['width']-1));$row=(int)round(max(0,min(100,$y))/100*($mask['height']-1));
                if(applicationMaskBit($bytes,$row*$mask['width']+$column))return false;
            }
        }
        return true;
    };
}
function normalizePassages(mixed $value):array {
    if($value===null)return [];
    if(!is_array($value)||!array_is_list($value)||count($value)>80)passageFailure('Le registre des passages est invalide.',400);
    $ids=[];$result=[];
    foreach($value as $entry){
        if(!is_array($entry)||!passageId($entry['id']??null)||isset($ids[$entry['id']])||!is_int($entry['revision']??null)||$entry['revision']<1
            ||!in_array($entry['kind']??'', ['door','portal','stairs','walk'],true)||!in_array($entry['layerId']??'', ['basement','ground','upper'],true)
            ||!passageCoordinate($entry['x']??null)||!passageCoordinate($entry['y']??null)
            ||!is_string($entry['name']??null)||trim($entry['name'])===''||passageNameLength($entry['name'])>120||array_key_exists('hidden',$entry)&&!is_bool($entry['hidden'])
            ||!empty($entry['destination'])&&(!is_array($entry['destination'])||!passageId($entry['destination']['sceneId']??null)||!passageId($entry['destination']['passageId']??null)))passageFailure('Un passage est invalide.',400);
        $ids[$entry['id']]=true;$result[]=['id'=>$entry['id'],'revision'=>$entry['revision'],'name'=>trim($entry['name']),'kind'=>$entry['kind'],'layerId'=>$entry['layerId'],'x'=>$entry['x'],'y'=>$entry['y'],'destination'=>empty($entry['destination'])?null:array_intersect_key($entry['destination'],array_flip(['sceneId','passageId'])),...(array_key_exists('hidden',$entry)?['hidden'=>$entry['hidden']]:[])];
    }return $result;
}
function &passageMap(array &$state,string $sceneId):array {
    if($sceneId===($state['activeSceneId']??''))return $state['map'];
    foreach(array_keys($state['scenes']??[]) as $index)if($state['scenes'][$index]['id']===$sceneId&&isset($state['scenes'][$index]['combat']['map']))return $state['scenes'][$index]['combat']['map'];
    passageFailure('Cette scène n’existe plus.',404);
}
function &passageEntry(array &$state,array $reference):array {
    $map=&passageMap($state,(string)($reference['sceneId']??''));
    foreach(array_keys($map['passages']??[]) as $index)if($map['passages'][$index]['id']===($reference['passageId']??''))return $map['passages'][$index];
    passageFailure('Ce passage n’existe plus.',404);
}
function freshPassage(array $entry,array $reference):void {if($entry['revision']!==($reference['expectedRevision']??null))passageFailure('Ce passage a changé. Actualisez son menu.');}
function detachPassage(array &$state,string $sceneId,array &$entry):void {
    $link=$entry['destination'];if(!$link)return;
    try{$other=&passageEntry($state,$link);if(($other['destination']['sceneId']??'')===$sceneId&&($other['destination']['passageId']??'')===$entry['id']){$other['destination']=null;$other['revision']++;}}catch(RuntimeException $error){if($error->getCode()!==404)throw $error;}
    $entry['destination']=null;$entry['revision']++;
}
function passageWithinReach(array $token,array $entry,array $map):bool {
    $level=array_replace($map,$map['layers'][$entry['layerId']]??[]);
    return ($token['layerId']??'ground')===$entry['layerId']&&dsDistance($token,$entry,$level)<=PASSAGE_USE_RANGE_CELLS;
}
function passageDestinationLabel(array $state,array $entry):string {
    if(!$entry['destination'])return 'Sans liaison';
    try{$target=passageEntry($state,$entry['destination']);}catch(RuntimeException $error){return 'Liaison indisponible';}
    $sceneName='Scène';foreach($state['scenes']??[] as $scene)if($scene['id']===$entry['destination']['sceneId'])$sceneName=$scene['name']??$scene['metadata']['name']??$sceneName;
    return $sceneName.' · '.(['basement'=>'Sous-sol','ground'=>'RDC','upper'=>'Étage'][$target['layerId']]).' · '.$target['name'];
}
function publicPassages(array $state,array $map,string $layerId,callable $visible):array {
    $result=[];foreach(normalizePassages($map['passages']??null) as $entry)if(empty($entry['hidden'])&&$entry['layerId']===$layerId&&$visible($entry)){
        $destinationLayer=null;if($entry['destination'])try{$destinationLayer=passageEntry($state,$entry['destination'])['layerId'];}catch(RuntimeException $error){}
        $result[]=[...$entry,'destinationLabel'=>passageDestinationLabel($state,$entry),'destinationLayerId'=>$destinationLayer];
    }return $result;
}

// Project only the linked endpoints into the public read. Modern scene
// records keep the arrival descriptors separate from their heavy map data.
function applicationPassageDestinationRecords(PDO $db, array $map, string $sceneId): array
{
    $layerId = $map['activeLayerId'] ?? 'ground';
    $destinations = [];
    foreach ($map['passages'] ?? [] as $entry) {
        $targetScene = $entry['destination']['sceneId'] ?? null;
        $targetPassage = $entry['destination']['passageId'] ?? null;
        if (($entry['layerId'] ?? '') !== $layerId || !empty($entry['hidden'])
            || !passageId($targetScene) || $targetScene === $sceneId || !passageId($targetPassage)) continue;
        $destinations[$targetScene][$targetPassage] = true;
    }
    if (!$destinations) return [];

    $sceneKeys = array_map(static fn($id) => 'scene:' . $id, array_keys($destinations));
    $scenes = applicationDomainRecords($db, ['scene-index', ...$sceneKeys]);
    $listed = array_fill_keys(applicationDomainPayload($scenes, 'scene-index')['order'] ?? [], true);
    $maps = [];
    $modernKeys = [];
    foreach ($destinations as $id => $_) {
        $scene = applicationDomainPayload($scenes, 'scene:' . $id);
        if (!isset($listed[$id]) || ($scene['metadata']['id'] ?? null) !== (string) $id) continue;
        if (is_array($scene['combat'] ?? null)) $maps[$id] = $scene['combat']['map']['passages'] ?? [];
        else $modernKeys[] = 'map:' . $id;
    }
    if ($modernKeys) {
        $slots = implode(', ', array_fill(0, count($modernKeys), '?'));
        $statement = $db->prepare("SELECT domain_key, JSON_EXTRACT(payload, '$.passages') AS passages FROM application_domains WHERE domain_key IN ($slots)");
        $statement->execute($modernKeys);
        foreach ($statement->fetchAll() as $row) $maps[substr($row['domain_key'], 4)] = jsonColumn($row['passages']);
    }

    $records = [];
    foreach ($maps as $id => $passages) {
        if (!is_array($passages)) continue;
        $endpoints = [];
        foreach ($passages as $entry) {
            if (is_array($entry) && isset($destinations[$id][$entry['id'] ?? '']) && is_string($entry['name'] ?? null)
                && in_array($entry['layerId'] ?? null, ['basement', 'ground', 'upper'], true)) {
                $endpoints[] = array_intersect_key($entry, array_flip(['id', 'name', 'layerId']));
            }
        }
        if (!$endpoints) continue;
        $metadata = applicationDomainPayload($scenes, 'scene:' . $id)['metadata'];
        $records['scene:' . $id] = ['payload' => ['metadata' => array_intersect_key($metadata, array_flip(['id', 'name']))]];
        $records['map:' . $id] = ['payload' => ['passages' => $endpoints]];
    }
    return $records;
}
function applicationApplyPassageCommand(array $state,array $identity,string $command,array $args,array $context=[]):array {
    $isGm=($identity['role']??'')==='gm';
    if($command!=='passage.use'&&!$isGm)passageFailure('La gestion des passages est réservée au MJ.',403);
    if($command==='passage.visibility'){
        $map=&passageMap($state,(string)($args['sceneId']??''));
        if(!in_array($args['layerId']??'', ['basement','ground','upper'],true)||!is_bool($args['hidden']??null)||!is_array($args['expectedPassages']??null))passageFailure('Le réglage des passages est invalide.',400);
        $entries=array_values(array_filter(normalizePassages($map['passages']??null),static fn($entry)=>$entry['layerId']===$args['layerId']));
        if(count($entries)!==count($args['expectedPassages']))passageFailure('Les passages ont changé. Rouvrez leur menu.');
        foreach($entries as $entry){$matched=false;foreach($args['expectedPassages'] as $expected)if(($expected['id']??'')===$entry['id']&&($expected['revision']??0)===$entry['revision'])$matched=true;if(!$matched)passageFailure('Les passages ont changé. Rouvrez leur menu.');}
        $map['passages']=array_map(static fn($entry)=>$entry['layerId']===$args['layerId']?[...$entry,'hidden'=>$args['hidden'],'revision'=>$entry['revision']+1]:$entry,normalizePassages($map['passages']??null));
        return ['state'=>$state,'result'=>['hidden'=>$args['hidden'],'passageCount'=>count($entries)]];
    }
    if($command==='passage.create'){
        if(!is_array($args['endpoints']??null)||count($args['endpoints'])!==2)passageFailure('Placez les deux extrémités du passage.',400);
        $references=[];
        foreach($args['endpoints'] as $entry){
            $map=&passageMap($state,$entry['sceneId']??'');$map['passages']=normalizePassages($map['passages']??null);
            if(count($map['passages'])>=80)passageFailure('Cette scène a atteint sa limite de passages.',429);
            $entry=normalizePassages([[...$entry,'revision'=>1,'destination'=>null]])[0];
            if(in_array($entry['id'],array_column($map['passages'],'id'),true))passageFailure('Cet identifiant de passage existe déjà.');
            $map['passages'][]=$entry;$references[]=['sceneId'=>$args['endpoints'][count($references)]['sceneId'],'passageId'=>$entry['id']];unset($map);
        }
        $first=&passageEntry($state,$references[0]);$second=&passageEntry($state,$references[1]);$first['destination']=$references[1];$second['destination']=$references[0];
        return ['state'=>$state,'result'=>['passageId'=>$first['id'],'destinationPassageId'=>$second['id']]];
    }
    $sceneId=(string)($args['sceneId']??'');$entry=&passageEntry($state,['sceneId'=>$sceneId,'passageId'=>$args['passageId']??'']);freshPassage($entry,$args);$map=&passageMap($state,$sceneId);
    if($command==='passage.edit'){
        $next=normalizePassages([[...$entry,...($args['patch']??[]),'id'=>$entry['id'],'destination'=>$entry['destination'],'revision'=>$entry['revision']+1]])[0];
        if(array_key_exists('arrival',$args)){
            $arrival=$args['arrival'];
            if(!is_array($arrival)||!passageId($arrival['sceneId']??null)||!passageId($arrival['passageId']??null))passageFailure('L’arrivée du passage est invalide.',400);
            if(($entry['destination']['sceneId']??'')!==$arrival['sceneId']||($entry['destination']['passageId']??'')!==$arrival['passageId'])passageFailure('La liaison a changé. Rouvrez le passage.');
            $target=&passageEntry($state,$arrival);freshPassage($target,$arrival);
            if(($arrival['sceneId']===$sceneId&&$target['id']===$entry['id'])||($target['destination']['sceneId']??'')!==$sceneId||($target['destination']['passageId']??'')!==$entry['id'])passageFailure('La liaison a changé. Rouvrez le passage.');
            $target=normalizePassages([[...$target,'x'=>$arrival['x']??null,'y'=>$arrival['y']??null,'revision'=>$target['revision']+1]])[0];
            unset($target);
        }
        $entry=$next;
    }
    elseif($command==='passage.link'){
        $target=&passageEntry($state,$args['target']??[]);freshPassage($target,$args['target']);
        if($sceneId===$args['target']['sceneId']&&$entry['id']===$target['id'])passageFailure('Choisissez une autre extrémité.');
        detachPassage($state,$sceneId,$entry);detachPassage($state,$args['target']['sceneId'],$target);
        $entry['destination']=['sceneId'=>$args['target']['sceneId'],'passageId'=>$target['id']];$target['destination']=['sceneId'=>$sceneId,'passageId'=>$entry['id']];$entry['revision']++;$target['revision']++;
    }elseif($command==='passage.remove'){$passageId=$entry['id'];detachPassage($state,$sceneId,$entry);unset($entry);$map['passages']=array_values(array_filter($map['passages'],static fn($other)=>$other['id']!==$passageId));return ['state'=>$state,'result'=>['passageId'=>$passageId]];}
    elseif($command==='passage.use'){
        if(!$isGm&&!empty($entry['hidden']))passageFailure('Ce passage n’est pas visible.',403);
        if(!$isGm&&($state['tacticalSync']['paused']??false))passageFailure('La table est temporairement verrouillée par le MJ.',423);
        if(!$isGm&&($sceneId!==$state['activeSceneId']||$entry['layerId']!==($map['activeLayerId']??'ground')))passageFailure('Ce passage n’est plus sur la carte diffusée.');
        $index=array_search($args['tokenId']??'',array_column($map['tokens']??[],'id'),true);if($index===false)passageFailure('Ce pion n’existe plus.',404);$token=$map['tokens'][$index];
        $movementLock=applicationAbilityMovementLock($state['abilityExecutions']??[],$sceneId,$token['id'],$map['tokens'],null,$state['abilityMovementLeases']??[]);if($movementLock!==null)passageFailure($movementLock['label']);
        $controllerOf=static function(array $unit)use($state,$context):string{
            if(isset($context['controller']))return (string)($context['controller'])($unit);
            $controller=$unit['controllerPlayerId']??'';if(unitUsesCharacterSheet($unit))foreach($state['characters']??[] as $sheet)if($sheet['id']===$unit['characterId'])$controller=$sheet['ownerPlayerId']??$controller;
            return (string)$controller;
        };
        $owner=$controllerOf($token);$pilot=$owner!==''?null:onlineMountPilot($map['tokens'],$token);$controller=$pilot!==null?$controllerOf($pilot):$owner;
        if(!$isGm&&($controller!==$identity['id']||!empty($token['hidden'])))passageFailure('Vous ne pouvez pas faire traverser ce pion.',403);
        if(!empty($token['mountedOnTokenId']))passageFailure('Empruntez le passage avec la monture : ses cavaliers la suivent sans descendre.');
        $travelers=[$token,...onlineMountedRiders($map['tokens'],$token)];$travelerIds=array_column($travelers,'id');
        foreach($travelers as $traveler)if(($traveler['layerId']??'ground')!==($token['layerId']??'ground'))passageFailure('Les cavaliers de cette monture ont changé. Actualisez son menu.');
        foreach($map['tokens'] as $other)if(!empty($other['mountedOnTokenId'])&&$other['mountedOnTokenId']!==$token['id']&&in_array($other['mountedOnTokenId'],$travelerIds,true))passageFailure('Les cavaliers de cette monture ont changé. Actualisez son menu.');
        foreach(array_slice($travelers,1) as $traveler)if(!empty($traveler['immovable']))passageFailure('Un cavalier est inamovible : la traversée de la monture est bloquée.');
        if(!$isGm)foreach($state['devices']['timedConditions']??[] as $condition)if($condition['sceneId']===$sceneId&&$condition['blocksMovement']&&($condition['remainingTurns']??1)>0&&($condition['targetTokenId']===$token['id']||unitUsesCharacterSheet($token)&&($condition['targetCharacterId']??'')===$token['characterId']))passageFailure('Ce pion est immobilisé jusqu’à la fin de son effet.',423);
        if(!empty($token['immovable'])||($token['healthOverride']??'')==='dead'||($token['maxHp']??0)>0&&($token['hp']??0)<=0)passageFailure('Ce pion ne peut pas emprunter le passage.');
        if((float)$token['x']!==(float)($args['expectedX']??-1)||(float)$token['y']!==(float)($args['expectedY']??-1))passageFailure('Ce pion s’est déplacé. Actualisez son menu.');
        if(!passageWithinReach($token,$entry,$map))passageFailure('Approchez le pion du passage.');
        $level=array_replace($map,$map['layers'][$entry['layerId']]??[]);
        if(!onlineDeviceDirect($token,$entry,$level))passageFailure('Un mur empêche d’atteindre ce passage.');
        if(!$isGm&&isset($context['visible'])&&!($context['visible'])($entry))passageFailure('Ce passage n’est pas visible.',403);
        $initiative=$sceneId===$state['activeSceneId']?($state['initiative']??[]):[];
        if(!$isGm&&($initiative['active']??false)&&($initiative['order'][$initiative['currentIndex']??0]??'')!==$token['id']&&($pilot===null||($initiative['order'][$initiative['currentIndex']??0]??'')!==$pilot['id'])
            &&($initiative['movementOverrides'][$token['id']]??false)!==true&&($pilot===null||($initiative['movementOverrides'][$pilot['id']]??false)!==true))passageFailure('Attendez le tour de ce pion ou de son pilote.',403);
        if(!$entry['destination'])passageFailure('Le MJ doit relier ce passage avant de l’utiliser.');
        $target=&passageEntry($state,$entry['destination']);$targetScene=$entry['destination']['sceneId'];$targetMap=&passageMap($state,$targetScene);
        if(($target['destination']['sceneId']??'')!==$sceneId||($target['destination']['passageId']??'')!==$entry['id'])passageFailure('Cette liaison a changé. Actualisez le passage.');
        if($targetScene!==$sceneId&&array_intersect($travelerIds,array_column($targetMap['tokens']??[],'id')))passageFailure('La scène de destination possède déjà un pion avec cette identité.');
        if($targetScene!==$sceneId&&count($targetMap['tokens']??[])+count($travelers)>2000)passageFailure('La scène de destination n’a pas assez de places pour la monture et ses cavaliers.',429);
        $destination=applicationMapForUnitLayer($targetMap,$target['layerId']);$desired=['x'=>$target['x']+($destination['gridSize']??50)/($destination['naturalWidth']??1600)*100,'y'=>$target['y']];
        if($targetScene!==$sceneId||$target['layerId']!==($token['layerId']??'ground'))$travelers=array_map(static fn(array $traveler):array=>applicationScaleUnitForMap($traveler,$targetMap,$target['layerId']),$travelers);
        $fog=applicationActiveMapFogState([...$targetMap,'activeLayerId'=>$target['layerId']]);
        $occupied=array_values(array_filter($targetMap['tokens']??[],static fn($other)=>$other['id']!==$token['id']&&empty($other['hidden'])&&empty($other['mountedOnTokenId'])&&!applicationFogCoversPoint($fog,$other['x'],$other['y'])&&($other['layerId']??'ground')===$target['layerId']));
        // Expand to neighbouring cells while keeping a direct, wall-free arrival.
        $arrivalToken=[...$travelers[0],'size'=>max(array_map(static fn(array $unit):float=>(float)($unit['size']??40),$travelers))];
        $position=nearestOnlineGroupPosition($desired,$arrivalToken,$destination,$occupied,$target,0,passageArrivalValidator($destination,$target),true,(float)($destination['gridSize']??50));
        if(!$position)passageFailure('Aucune place libre accessible sans traverser un mur, un bloqueur ou le brouillard autour de l’arrivée. Le pion reste au départ.');
        $leaderId=$token['id'];$movedAt=$context['now']??(int)round(microtime(true)*1000);
        foreach($travelers as $traveler){
        $index=array_search($traveler['id'],array_column($map['tokens'],'id'),true);
        $token=array_replace($traveler,$position,['layerId'=>$target['layerId'],'targetTokenId'=>null,'_updatedAt'=>$movedAt,'_movedAt'=>$movedAt]);
        foreach($map['tokens'] as &$other)if(($other['targetTokenId']??'')===$token['id']&&($other['layerId']??'ground')!==$token['layerId'])$other['targetTokenId']=null;unset($other);
        if($targetScene===$sceneId)$map['tokens'][$index]=$token;
        else {
            array_splice($map['tokens'],$index,1);$targetMap['tokens'][]=$token;
            foreach($map['tokens'] as &$other)if(($other['targetTokenId']??'')===$token['id'])$other['targetTokenId']=null;unset($other);
            if(!isset($map['layers'][$map['activeLayerId']??'ground'])){
                $carried=array_values(array_filter($map['lights']??[],static fn($light)=>($light['carrierTokenId']??null)===$token['id']));
                $map['lights']=array_values(array_filter($map['lights']??[],static fn($light)=>($light['carrierTokenId']??null)!==$token['id']));
                if($carried)$targetMap['layers'][$token['layerId']]['lights']=[...($targetMap['layers'][$token['layerId']]['lights']??[]),...array_map(static fn($light)=>[...$light,'x'=>$token['x'],'y'=>$token['y']],$carried)];
            }
            foreach(array_keys($map['layers']??[]) as $layerId){
                $lights=$map['layers'][$layerId]['lights']??[];$carried=array_values(array_filter($lights,static fn($light)=>($light['carrierTokenId']??'')===$token['id']));
                if($carried){$map['layers'][$layerId]['lights']=array_values(array_filter($lights,static fn($light)=>($light['carrierTokenId']??'')!==$token['id']));$targetMap['layers'][$token['layerId']]['lights']=[...($targetMap['layers'][$token['layerId']]['lights']??[]),...array_map(static fn($light)=>array_replace($light,['x'=>$token['x'],'y'=>$token['y']]),$carried)];}
                if($layerId===($map['activeLayerId']??'ground'))$map['lights']=$map['layers'][$layerId]['lights']??[];
            }
            if(($targetMap['activeLayerId']??'ground')===$token['layerId'])$targetMap['lights']=$targetMap['layers'][$token['layerId']]['lights']??($targetMap['lights']??[]);
            foreach(array_keys($state['actionTimers']??[]) as $i)if($state['actionTimers'][$i]['sceneId']===$sceneId&&($state['actionTimers'][$i]['tokenId']??'')===$token['id'])$state['actionTimers'][$i]['sceneId']=$targetScene;
            $destinationInitiative=$targetScene===$state['activeSceneId']?($state['initiative']??[]):[];
            if($targetScene!==$state['activeSceneId'])foreach($state['scenes']??[] as $scene)if($scene['id']===$targetScene)$destinationInitiative=$scene['combat']['initiative']??[];
            if(isset($state['attackEnhancements']))$state['attackEnhancements']=applicationTransferAttackEnhancements($state['attackEnhancements'],$token,$sceneId,$targetScene,$destinationInitiative);
        }
        foreach(array_keys($state['devices']['devices']??[]) as $i){$device=&$state['devices']['devices'][$i];if($device['sourceTokenId']===$token['id']&&$device['sceneId']===$sceneId&&$device['definition']['kind']==='dome'&&($device['mode']??'carried')!=='placed')$device=array_replace($device,['sceneId'=>$targetScene,'layerId'=>$token['layerId'],'x'=>$token['x'],'y'=>$token['y'],'revision'=>$device['revision']+1]);unset($device);}
        }
        return ['state'=>$state,'result'=>['tokenId'=>$leaderId,'riderCount'=>count($travelers)-1,'sourceSceneId'=>$sceneId,'sceneId'=>$targetScene,'layerId'=>$target['layerId'],'x'=>$position['x'],'y'=>$position['y'],'destinationLabel'=>passageDestinationLabel($state,$entry)]];
    }else passageFailure('Action de passage inconnue.',400);
    return ['state'=>$state,'result'=>['passageId'=>$entry['id']]];
}

// Only the two crossing maps need combat data while the command holds the
// write lock. Keep the broadcast scene's metadata if the MJ works elsewhere.
function onlinePassageTravelState(PDO $db, array &$records, array $pending, array $args): array
{
    $sourceId = is_string($args['sceneId'] ?? null) ? $args['sceneId'] : '';
    $publishedId = (string) (applicationDomainPayload($records, 'table')['activeSceneId'] ?? '');
    $sourceKey = 'scene:' . $sourceId;
    $records += applicationDomainRecords($db, ['scene-index', 'roster', 'activity', 'devices', $sourceKey, 'map:' . $sourceId]);
    $scope = onlineDeviceOverlayRecords($records, $pending);
    $sceneOrder = applicationDomainPayload($scope, 'scene-index')['order'] ?? [];
    $listed = array_fill_keys($sceneOrder, true);
    if (!isset($listed[$publishedId])) {
        $records += applicationDomainRecords($db, ['detached-combat']);
        $scope = onlineDeviceOverlayRecords($records, $pending);
    }
    $source = applicationDomainPayload($scope, $sourceKey);
    $sourceMap = $sourceId === ''
        ? (applicationDomainPayload($scope, 'detached-combat')['map'] ?? [])
        : (is_array($source['combat'] ?? null) ? ($source['combat']['map'] ?? []) : applicationDomainPayload($scope, 'map:' . $sourceId));
    $targetId = '';
    foreach ($sourceMap['passages'] ?? [] as $entry) {
        if ($entry['id'] === ($args['passageId'] ?? null)) {
            $targetId = (string) ($entry['destination']['sceneId'] ?? '');
            break;
        }
    }

    $travelScenes = array_values(array_intersect($sceneOrder, [$sourceId, $targetId]));
    $mapKeys = ['scene:' . $publishedId];
    foreach ($travelScenes as $id) {
        array_push($mapKeys, 'scene:' . $id, 'map:' . $id, 'token-index:' . $id, 'initiative:' . $id);
    }
    $records += applicationDomainRecords($db, array_values(array_diff($mapKeys, array_keys($records))));
    $scope = onlineDeviceOverlayRecords($records, $pending);
    $documentKeys = [];
    foreach ($travelScenes as $id) {
        foreach (applicationDomainPayload($scope, 'token-index:' . $id)['order'] ?? [] as $tokenId) {
            $documentKeys[] = 'token:' . $id . ':' . $tokenId;
        }
    }
    foreach (applicationDomainPayload($scope, 'roster')['characterOrder'] ?? [] as $id) {
        $documentKeys[] = 'character:' . $id;
    }
    $records += applicationDomainRecords($db, $documentKeys);
    $scope = onlineDeviceOverlayRecords($records, $pending);
    $scope['scene-index']['payload']['order'] = array_values(array_intersect($sceneOrder, [...$travelScenes, $publishedId]));
    return domainsToApplicationState($scope, 0);
}

function onlinePassageManagementState(PDO $db, array &$records, array $pending, string $command, array $args): ?array
{
    $source = ['sceneId'=>$args['sceneId'] ?? null, 'passageId'=>$args['passageId'] ?? null];
    $references = match ($command) {
        'passage.create' => is_array($args['endpoints'] ?? null) && count($args['endpoints']) === 2 ? $args['endpoints'] : [],
        'passage.link' => [$source, $args['target'] ?? []],
        'passage.edit' => [$source, ...(isset($args['arrival']) ? [$args['arrival']] : [])],
        default => [$source],
    };
    // A detached legacy map uses the old embedded combat document.
    foreach ($references as $reference) if (($reference['sceneId'] ?? null) === '') return null;
    $records += applicationDomainRecords($db, array_values(array_diff(['scene-index'], array_keys($records))));
    $scope = onlineDeviceOverlayRecords($records, $pending);
    $sceneOrder = applicationDomainPayload($scope, 'scene-index')['order'] ?? [];
    $sceneIds = array_column($references, 'sceneId');
    $loadMaps = static function (array $ids) use ($db, &$records, $pending, $sceneOrder): array {
        $keys = [];
        foreach (array_intersect($sceneOrder, array_filter($ids, 'passageId')) as $id) {
            array_push($keys, 'scene:' . $id, 'map:' . $id);
        }
        $records += applicationDomainRecords($db, array_values(array_diff($keys, array_keys($records))));
        return onlineDeviceOverlayRecords($records, $pending);
    };
    $scope = $loadMaps($sceneIds);
    if ($command === 'passage.link' || $command === 'passage.remove') {
        // Detaching an endpoint can also update the previous link on another map.
        foreach ($references as $reference) {
            $id = $reference['sceneId'] ?? '';
            if (!passageId($id)) continue;
            if (is_array(applicationDomainPayload($scope, 'scene:' . $id)['combat'] ?? null)) return null;
            foreach (applicationDomainPayload($scope, 'map:' . $id)['passages'] ?? [] as $entry) {
                if (($entry['id'] ?? null) === ($reference['passageId'] ?? null) && isset($entry['destination']['sceneId'])) {
                    $sceneIds[] = $entry['destination']['sceneId'];
                    break;
                }
            }
        }
        $scope = $loadMaps($sceneIds);
    }
    $scope['scene-index']['payload']['order'] = array_values(array_intersect($sceneOrder, array_filter($sceneIds, 'passageId')));
    foreach ($scope['scene-index']['payload']['order'] as $id) {
        if (is_array(applicationDomainPayload($scope, 'scene:' . $id)['combat'] ?? null)) return null;
    }
    return domainsToApplicationState($scope, 0);
}

function onlinePassageCommand(PDO $db,array &$records,array &$pending,array $identity,string $command,array $args):array {
    $requestId=$args['requestId']??'';if(!is_string($requestId)||preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$requestId)!==1)rejectOnlineCommand($db,400,'Référence de passage invalide.','invalid_passage_request');
    $signature=hash('sha256',json_encode(canonicalApplicationDomainValue(['command'=>$command,'arguments'=>$args]),JSON_THROW_ON_ERROR));
    $receipt=onlinePersistentCommandReceipt($db,$records,'passage-command',$requestId,$identity['id'],$signature,'passage');
    if(is_array($receipt['receipt']??null))return [...$receipt['receipt']['result'],'deduplicated'=>true];
    $isGm = onlineIdentityIsGm($identity);
    $management = false;
    if ($command === 'passage.use') {
        $before = onlinePassageTravelState($db, $records, $pending, $args);
    } else {
        if (!$isGm) rejectOnlineCommand($db,403,'La gestion des passages est réservée au MJ.','passage_command_refused');
        $before = onlinePassageManagementState($db, $records, $pending, $command, $args);
        $management = $before !== null;
        if (!$management) {
            $records = array_replace(applicationDomainRecords($db), $records);
            $before = domainsToApplicationState(onlineDeviceOverlayRecords($records, $pending), 0);
        }
    }
    try {
        $applied = applicationApplyPassageCommand($before, ['id' => $identity['id'], 'role' => $isGm ? 'gm' : 'player'], $command, $args, [
            // Called only for a player's valid departure. An MJ needs no player projection.
            'visible' => static fn($entry) => in_array($entry['id'], array_column(publicPlayerState($before, $identity, [])['map']['passages'] ?? [], 'id'), true),
            'controller' => static fn($token) => onlineTokenControllerIdFromRecords($db, $records, $token),
        ]);
    }
    catch(RuntimeException $error){rejectOnlineCommand($db,in_array($error->getCode(),[400,403,404,409,423,429],true)?$error->getCode():409,$error->getMessage(),'passage_command_refused');}
    if ($management) {
        foreach ($before['scenes'] as $scene) {
            $id = $scene['id'];
            $oldPassages = passageMap($before, $id)['passages'] ?? [];
            $newPassages = passageMap($applied['state'], $id)['passages'] ?? [];
            if ($oldPassages === $newPassages) continue;
            $key = 'map:' . $id;
            $payload = $pending[$key]['payload'] ?? applicationDomainPayload($records, $key);
            queueOnlineDomainUpsert($pending, $records, $key, [...$payload, 'passages'=>$newPassages]);
        }
    } else {
        $old=legacyStateToDomains($before);$new=legacyStateToDomains($applied['state']);
        foreach($new as $key=>$payload)if((str_starts_with($key,'map:')||str_starts_with($key,'token:')||str_starts_with($key,'token-index:')||in_array($key,['devices','activity'],true))&&dsCanonical($old[$key]??null)!==dsCanonical($payload))queueOnlineDomainUpsert($pending,$records,$key,$payload);
        foreach($old as $key=>$payload)if(str_starts_with($key,'token:')&&!isset($new[$key]))queueOnlineDomainDelete($pending,$records,$key);
    }
    onlineStorePersistentCommandReceipt($records,$pending,$receipt,'passage-command',$requestId,$identity['id'],'passage-'.$requestId,$args['sceneId']??'passages',$signature,$applied['result']);
    return $applied['result'];
}
