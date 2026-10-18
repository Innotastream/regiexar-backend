<?php
declare(strict_types=1);
require_once __DIR__.'/device-state.php';

function onlineDeviceDirect(array $a,array $b,array $map): bool {
    $occlusion=applicationActiveMapOcclusionState($map);$walls=$occlusion['walls'];$bytes=applicationWallMaskBytes($walls);
    if($bytes===null || ($walls['mask']??'')==='') return true;
    $steps=max(1,(int)ceil(hypot(($a['x']-$b['x'])*$occlusion['naturalWidth']/100,($a['y']-$b['y'])*$occlusion['naturalHeight']/100)));
    for($i=0;$i<=$steps;$i++) { $x=(int)round(($a['x']+($b['x']-$a['x'])*$i/$steps)/100*($walls['width']-1));$y=(int)round(($a['y']+($b['y']-$a['y'])*$i/$steps)/100*($walls['height']-1));if(applicationMaskBit($bytes,$y*$walls['width']+$x)) return false; }
    return true;
}
function onlineDeviceOverlayRecords(array $records,array $pending): array {
    foreach($pending as $key=>$entry) { if(($entry['operation']??'upsert')==='delete')unset($records[$key]);else $records[$key]=[...($records[$key]??[]),'payload'=>$entry['payload']]; }return $records;
}
function onlineDeviceState(PDO $db,array &$records,array $pending): array {
    $records=array_replace(applicationDomainRecords($db),$records);return domainsToApplicationState(onlineDeviceOverlayRecords($records,$pending),0);
}
function onlineDeviceFlush(array $state,array &$records,array &$pending): void {
    foreach(legacyStateToDomains($state) as $key=>$payload) {
        if(!in_array($key,['devices','items','activity','luck'],true)&&!str_starts_with($key,'character:')&&!str_starts_with($key,'token:')&&!str_starts_with($key,'token-index:'))continue;
        $before=$pending[$key]['payload']??applicationDomainPayload($records,$key);
        if(dsCanonical($before)!==dsCanonical($payload)) queueOnlineDomainUpsert($pending,$records,$key,$payload);
    }
}
function onlineDeviceReadChanges(array &$state,array &$owner,array $records,array $pending): void {
    $new=domainsToApplicationState(onlineDeviceOverlayRecords($records,$pending),0);
    foreach($state['devices']['timedConditions'] as &$condition){$i=dsIndex($new['devices']['timedConditions'],$condition['id']);if($i>=0&&!empty($new['devices']['timedConditions'][$i]['escapeWindowClosed']))$condition['escapeWindowClosed']=true;}unset($condition);
    foreach(['actionTimers','resourceReceipts','rolls','abilityCueEvents','luckStatistics'] as $key) if(isset($new[$key]))$state[$key]=$new[$key];
    foreach($state['devices']['devices'] as &$device) {
        $index=dsIndex($new['devices']['devices'],$device['id']);
        if($index>=0&&isset($new['devices']['devices'][$index]['mana'])){$device['mana']=$new['devices']['devices'][$index]['mana'];$device['revision']=max($device['revision'],$new['devices']['devices'][$index]['revision']);}
    }unset($device);
    $current=unitById($new,$owner['id'],false);
    if($current){$owner['resources']=$current['resources'];$owner['fatigue']=$current['fatigue']??['current'=>0,'max'=>150];persistUnitResources($state,$owner);}
    foreach($state['map']['tokens'] as &$token) if(unitUsesCharacterSheet($token)&&$token['characterId']===$owner['id'])$token=synchronizeOnlineCharacterToken($token,$owner);
    unset($token);
}
function onlineDeviceContext(PDO $db,array &$records,array &$pending,array $identity): array {
    return ['now'=>gmdate('c'),'id'=>static fn($prefix)=>$prefix.'-'.randomToken(16),'direct'=>'onlineDeviceDirect',
        'cast'=>static function(array &$state,array &$store,array &$owner,array $token,array $ability,array $args)use($db,&$records,&$pending,$identity):array {
            onlineDeviceFlush($state,$records,$pending);$sheet=($owner['unitKind']??'')==='token'?null:$owner;$source=$sheet?synchronizeOnlineCharacterToken($token,$sheet):$token;$sceneId=$state['activeSceneId'];
            $plan=onlinePrepareAbilityCasting($db,$ability,$source,$sceneId,$state['initiative'],$pending['activity']['payload']??applicationDomainPayload($records,'activity'),null,$state);
            $cast=onlineAbilityCastingRoll($plan,$source,$identity,$args,$token['layerId']??'ground',$sheet,true);
            $remarkable=($cast['outcome']['requiresGmValidation']??false)===true;
            $cast=onlineCommitAbilityCasting($db,$records,$pending,$plan,$cast,$source,$identity,true,true,!$remarkable);
            onlineDeviceReadChanges($state,$owner,$records,$pending);$cast['pendingGmApproval']=$remarkable;
            if($remarkable){$cast['_devicePlan']=$plan;$cast['_deviceIdentity']=['id'=>$identity['id'],'display_name'=>$identity['display_name']??$owner['name'],'effective_mode'=>$identity['effective_mode'],'permanent_role'=>$identity['permanent_role']];}
            return $cast;
        },
        'finishCast'=>static function(array &$state,array &$owner,array $token,array $ability,array $cast,string $sceneId)use($db,&$records,&$pending):void {
            onlineDeviceFlush($state,$records,$pending);$plan=$cast['_devicePlan'];$identity=$cast['_deviceIdentity'];
            onlineCommitAbilityCasting($db,$records,$pending,$plan,$cast,($owner['unitKind']??'')==='token'?$token:synchronizeOnlineCharacterToken($token,$owner),$identity,false,false,true);
            onlineDeviceReadChanges($state,$owner,$records,$pending);
        },
        'roll'=>static function(array &$state,?array $owner,array $token,string $statId,int $penalty,string $label)use($db,&$records,&$pending,$identity):array {
            if(($identity['mechanicsAutomatic']??false)&&!empty($owner['ownerPlayerId']))$identity=['id'=>$owner['ownerPlayerId'],'display_name'=>$owner['name'],'effective_mode'=>'player','permanent_role'=>'player'];
            onlineDeviceFlush($state,$records,$pending);$source=$owner&&($owner['unitKind']??'')!=='token'?synchronizeOnlineCharacterToken($token,$owner):$token;
            $stat=applicationAbilityCastingStat(onlineEnhancedStatRows($db,$records,$pending,$source,(string)($state['activeSceneId']??'')),$statId);
            $threshold=$statId==='luck'?onlineCharacterLuckThreshold($db,$records,$pending,$owner['id']??''):($stat['value']??null);
            if($statId!=='luck'&&$threshold===null)dsError('La caractéristique de ce test est absente.');
            $rolled=onlineRollFormulaWithMode('1d100','normal',$threshold===null?null:(int)$threshold,$penalty,true);
            $outcome=classifyOnlineD100Outcome($rolled['rawD100']??null,$threshold===null?null:(int)$threshold,$penalty,0,true,$statId!=='luck');applyOnlineD100ResultToRoll($rolled,$outcome);
            $roll=onlineRollEntry($identity,$rolled,$label,$owner['name']??$token['name'],$outcome);
            $roll['sourceTokenId']=$token['id'];$roll['sourceSceneId']=$state['activeSceneId'];
            if(onlineIdentityIsGm($identity)||!$owner){$roll['visibility']='gm';$roll['revealed']=false;}
            $activity=$pending['activity']['payload']??applicationDomainPayload($records,'activity');$activity['rolls']=array_slice([$roll,...($activity['rolls']??[])],0,100);queueOnlineDomainUpsert($pending,$records,'activity',$activity);
            onlineRecordCharacterLuckD100($db,$records,$pending,$identity,$owner['id']??'',$rolled);$state['rolls']=$activity['rolls'];
            $state['luckStatistics']=($pending['luck']['payload']??applicationDomainPayload($records,'luck'))['characters']??[];
            return ['success'=>($outcome['success']??false)===true,'outcome'=>$outcome,'roll'=>$roll,'pendingGmApproval'=>($outcome['requiresGmValidation']??false)===true];
        }];
}
function onlineLauncherAbility(array $device,int $mana,string $statId): array {
    return ['id'=>$device['id'],'name'=>$device['name'],'effect'=>'damage','formula'=>$device['definition']['damageFormula'],'damageType'=>$device['definition']['damageType'],
        'damageComponents'=>[['type'=>$device['definition']['damageType'],'formula'=>$device['definition']['damageFormula']],['type'=>'ignore','formula'=>(string)($mana*$device['definition']['infusion']['ratio'])]],
        'castingStatId'=>$statId,'manaCost'=>0,'hpCost'=>0,'fatigueCost'=>0,'cooldownRounds'=>0,'restRecharge'=>'none','projectile'=>$device['definition']['projectile']];
}

function onlineDeviceSelectScene(array $state,string $sceneId,?string $layerId=null):array {
    if($layerId!==null&&!in_array($layerId,['basement','ground','upper'],true))dsError('Ce niveau n’existe plus.',400);
    if($sceneId!==$state['activeSceneId']){
        $scene=null;foreach($state['scenes']??[] as $entry)if(($entry['id']??'')===$sceneId){$scene=$entry;break;}
        if(!isset($scene['combat']['map']))dsError('Cette scène n’existe plus.',404);
        $state['activeSceneId']=$sceneId;$state['map']=$scene['combat']['map'];$state['initiative']=$scene['combat']['initiative'];
    }
    if($layerId!==null)$state['map']['activeLayerId']=$layerId;
    return $state;
}

function onlineDeviceCommands(PDO $db,array &$records,array &$pending,array $identity,array $arguments): array {
    $state=onlineDeviceState($db,$records,$pending);$actor=['id'=>$identity['id'],'role'=>onlineIdentityIsGm($identity)?'gm':'player'];
    if($actor['role']==='gm')$state=onlineDeviceSelectScene($state,(string)($arguments['arguments']['sceneId']??$state['activeSceneId']),$arguments['arguments']['layerId']??null);
    $context=onlineDeviceContext($db,$records,$pending,[...$identity,'mechanicsAutomatic'=>($arguments['command']??'')==='scene.advance']);
    if($actor['role']!=='gm'&&in_array($arguments['command']??'',['device.deploy','device.pack','device.place','dome.mode','battery.charge','launcher.fire'],true)) {
        $map=publicPlayerState($state,$identity,[])['map'];
        $context['visible']=static fn(array $point):bool=>!applicationFogCoversPoint($map['fog']??null,$point['x'],$point['y'])&&!applicationVisionCoversPoint($map['visionMask']??null,$point['x'],$point['y']);
    }
    $applied=applicationApplyDeviceCommand($state,$actor,(string)($arguments['command']??''),$arguments['arguments']??[],$context);
    onlineDeviceFlush($applied['state'],$records,$pending);return $applied;
}
function onlineDeviceRememberResult(array &$records,array &$pending,string $requestId,array $result): void {
    $store=$pending['devices']['payload']??applicationDomainPayload($records,'devices',dsEmpty());
    foreach($store['receipts'] as &$receipt)if($receipt['requestId']===$requestId){$receipt['result']=$result;break;}unset($receipt);
    queueOnlineDomainUpsert($pending,$records,'devices',$store);
}
function onlineAdvanceDevicesOnChanges(PDO $db,array $records,array $changes,array $identity): array {
    $stored=applicationDomainRecords($db,['devices']);
    if(!array_filter($changes,static fn($c)=>str_starts_with($c['key'],'initiative:')))return $changes;
    $pending=[];foreach($changes as $change)$pending[$change['key']]=$change;
    $records=array_replace(applicationDomainRecords($db),$records);
    foreach($changes as $change){
        if(!str_starts_with($change['key'],'initiative:')||($change['operation']??'upsert')==='delete')continue;
        $sceneId=substr($change['key'],11);$previous=applicationDomainPayload($records,$change['key']);
        $state=onlineDeviceSelectScene(onlineDeviceState($db,$records,$pending),$sceneId);
        $advanced=applicationAdvanceDevices($state,$previous,onlineDeviceContext($db,$records,$pending,[...$identity,'mechanicsAutomatic'=>true]));onlineDeviceFlush($advanced['state'],$records,$pending);
        onlineDeviceEffects($db,$records,$pending,$identity,$advanced['effects']);
    }
    return array_values($pending);
}
function onlineDeviceTargets(array $device,array $map,bool $beam): array {
    $targets=[];$definition=$device['definition'];$grid=(float)($map['gridSize']??0);if($grid<=0)return [];
    $level=$map['layers'][$device['layerId']]??$map;$sx=($level['naturalWidth']??$map['naturalWidth'])/(100*$grid);$sy=($level['naturalHeight']??$map['naturalHeight'])/(100*$grid);
    $dx=$beam?($device['mark']['x']-$device['x'])*$sx:0;$dy=$beam?($device['mark']['y']-$device['y'])*$sy:0;$length=hypot($dx,$dy);
    foreach($map['tokens'] as $token) {
        if(($token['hidden']??false)||($token['layerId']??'ground')!==$device['layerId'])continue;
        if(!$beam){if(dsDistance(['x'=>$device['x'],'y'=>$device['y'],'layerId'=>$device['layerId']],$token,$map,false,true)<=$definition['radiusCells'])$targets[]=['tokenId'=>$token['id'],'damagePercent'=>100];continue;}
        if($length<=0||($token['maxHp']??0)<=0)continue;
        $x=($token['x']-$device['x'])*$sx;$y=($token['y']-$device['y'])*$sy;$forward=($x*$dx+$y*$dy)/$length;$lateral=abs($x*$dy-$y*$dx)/$length;
        if($forward>0&&$forward<=$definition['lengthCells']&&$lateral<=$definition['widthCells']/2)$targets[]=['tokenId'=>$token['id'],'forward'=>$forward];
    }
    if($beam){usort($targets,static fn($a,$b)=>($a['forward']<=>$b['forward'])?:strcmp($a['tokenId'],$b['tokenId']));foreach($targets as $i=>&$entry){unset($entry['forward']);$entry['damagePercent']=max(0,100-$i*$definition['falloffPercent']);}unset($entry);}
    return $targets;
}
function onlineDeviceAutomaticAttack(PDO $db,array &$records,array &$pending,array $identity,array $device,string $targetId,int $damage): array {
    $sceneId=$device['sceneId'];$key=onlineTokenDomainKey($sceneId,$targetId);$target=$pending[$key]['payload']??applicationDomainPayload($records,$key);
    if(($target['maxHp']??0)<=0||onlineTokenIsDead($target,true))return [];
    $attack=['id'=>'attack-'.randomToken(16),'requestId'=>'device-damage-'.randomToken(16),'sceneId'=>$sceneId,'layerId'=>$device['layerId'],
        'sourceTokenId'=>$device['id'],'deviceOrigin'=>$device['id'],'sourceName'=>$device['name'],'targetTokenId'=>$targetId,'targetName'=>$target['name'],
        'accountId'=>$identity['id'],'attackerRole'=>'gm','playerName'=>$device['name'],'visibility'=>'public','attackName'=>$device['name'],'attackKind'=>'ability','attackId'=>$device['abilityId'],
        'areaRadiusCells'=>$device['definition']['radiusCells']??1,'opposed'=>false,'defenderAccountId'=>onlineTokenControllerIdFromRecords($db,$records,$target),
        'damageFormula'=>(string)$damage,'damageType'=>$device['definition']['damageType'],'damagePercent'=>100,'damageRollMode'=>'normal','onHitConditions'=>[],
        'hit'=>['raw'=>null,'total'=>null,'formula'=>'','statId'=>'','statLabel'=>'Sans jet','breakdown'=>'Lancement sans jet','outcome'=>['automatic'=>true,'success'=>true,'code'=>'success','label'=>'SANS JET']],
        'createdAt'=>(int)floor(microtime(true)*1000)];
    $rolled=onlineRollAttackDamage($attack,$target);$attack['damage']=$rolled['damage'];$attack['finalDamage']=$rolled['damage']['finalDamage'];
    $initiative=$pending['initiative:'.$sceneId]['payload']??applicationDomainPayload($records,'initiative:'.$sceneId);
    if($attack['finalDamage']<=0)$attack['status']='blocked';
    elseif($initiative['active']??false){$health=applyOnlineAttackDamage($db,$records,$pending,$key,$target,$attack['finalDamage'],true,[...$attack['damage'],'damageType'=>$attack['damageType']]);$attack=onlineRecordAttackGuard($attack,$health);$attack['status']='applied';}
    else $attack['status']='pending';
    $activity=$pending['activity']['payload']??applicationDomainPayload($records,'activity');
    if(count($activity['attackReceipts']??[])>=XAR_ATTACK_RECEIPT_MAXIMUM)dsError('Le journal de sécurité des attaques est plein.',429);
    if($attack['status']==='pending'){if(count($activity['pendingAttacks']??[])>=100)dsError('La file des attaques en attente est pleine.',429);$activity['pendingAttacks'][]=$attack;}
    $activity['attackReceipts'][]=['requestId'=>$attack['requestId'],'accountId'=>$identity['id'],'requestSignature'=>'device-damage','expiresAt'=>$attack['createdAt']+XAR_ATTACK_RECEIPT_TTL_MILLISECONDS,'attack'=>$attack];
    queueOnlineDomainUpsert($pending,$records,'activity',$activity);return $attack;
}
function onlineDeviceEffects(PDO $db,array &$records,array &$pending,array $identity,array $effects): array {
    $attacks=[];
    foreach($effects as $effect) {
        if ($effect['type'] === 'metamorphosis.return') {
            $character = $pending['character:' . $effect['characterId']]['payload'] ?? applicationDomainPayload($records, 'character:' . $effect['characterId']);
            foreach (onlineDeviceOverlayRecords($records, $pending) as $key => $record) if (str_starts_with($key, 'token:')) {
                $token = $record['payload'] ?? [];
                if (($token['characterId'] ?? '') === $effect['characterId'] && applicationMorphLinked($token)) queueOnlineDomainUpsert($pending, $records, $key, synchronizeOnlineCharacterToken($token, $character));
            }
        } elseif($effect['type']==='structure.created'){
            $state=onlineDeviceSelectScene(onlineDeviceState($db,$records,$pending),$effect['sceneId']);$i=dsIndex($state['map']['tokens'],$effect['tokenId']);if($i<0)continue;$web=$state['map']['tokens'][$i];$d=$web['fixedStructure']['definition'];
            foreach($state['map']['tokens'] as $target)if(!applicationIsNonCombatant($target)&&($target['hp']??0)>0&&($target['layerId']??'ground')===($web['layerId']??'ground')&&(($d['affectCreator']??false)||$target['id']!==$web['fixedStructure']['creatorTokenId'])&&dsDistance(['x'=>$web['x'],'y'=>$web['y'],'layerId'=>$web['layerId']],$target,$state['map'],false,true)<=$d['contactRadiusCells'])onlineStructureContactTarget($db,$records,$pending,$state,$web,$target);
            onlineDeviceFlush($state,$records,$pending);
        }elseif(in_array($effect['type'],['explosion','beam'],true)) {
            $device=$effect['device'];$state=onlineDeviceSelectScene(onlineDeviceState($db,$records,$pending),$device['sceneId'],$device['layerId']);$beam=$effect['type']==='beam';$rolled=$beam?['total'=>$device['mana']]:onlineRollFormula($device['definition']['damageFormula']);
            if(!$beam){$damageRoll=onlineRollEntry(['id'=>'mechanics-authority','display_name'=>$device['name'],'effective_mode'=>'gm','permanent_role'=>'gm'],$rolled,$device['name'].' · Explosion',$device['name']);$damageRoll['visibility']='gm';$damageRoll['revealed']=false;onlineAppendAbilityEffectRoll($records,$pending,$damageRoll);}
            foreach(onlineDeviceTargets($device,$state['map'],$beam) as $target) if($target['damagePercent']>0)$attacks[]=onlineDeviceAutomaticAttack($db,$records,$pending,$identity,$device,$target['tokenId'],(int)round($rolled['total']*$target['damagePercent']/100));
        } elseif($effect['type']==='beam.finish') {
            $state=onlineDeviceState($db,$records,$pending);$i=dsDevice($state['devices'],$effect['deviceId'],false);$device=$state['devices']['devices'][$i];$state=onlineDeviceSelectScene($state,$device['sceneId'],$device['layerId']);$creator=$device['creatorCharacterId'];$owner=unitById($state,$creator,false);
            $roller=['id'=>$owner['ownerPlayerId']??'mechanics-authority','display_name'=>$owner['name']??'Dispositif','effective_mode'=>!empty($owner['ownerPlayerId'])?'player':'gm','permanent_role'=>!empty($owner['ownerPlayerId'])?'player':'gm'];
            applicationFinishBeam($state,$effect['deviceId'],onlineDeviceContext($db,$records,$pending,$roller));onlineDeviceFlush($state,$records,$pending);
        } elseif($effect['type']==='condition') {
            $state=onlineDeviceSelectScene(onlineDeviceState($db,$records,$pending),$effect['sceneId']);onlineDeviceTimedCondition($state,$effect);onlineDeviceFlush($state,$records,$pending);
        } elseif($effect['type']==='condition.expire') {
            $condition=$effect['condition'];$state=onlineDeviceSelectScene(onlineDeviceState($db,$records,$pending),$condition['sceneId']);
            $other=null;foreach($state['devices']['timedConditions'] as $index=>$entry)if($entry['label']===$condition['label']&&($entry['targetTokenId']===$condition['targetTokenId']||!empty($condition['targetCharacterId'])&&($entry['targetCharacterId']??'')===$condition['targetCharacterId'])){$other=$index;break;}
            if($condition['introduced']??false){if($other!==null)$state['devices']['timedConditions'][$other]['introduced']=true;else onlineDeviceCondition($state,$condition['targetTokenId'],$condition['label'],false);}onlineDeviceFlush($state,$records,$pending);
        }
    }
    return $attacks;
}
function onlineStructureContactTarget(PDO $db,array &$records,array &$pending,array &$state,array $web,array $target):void {
    $definition=$web['fixedStructure']['definition']??null;$rider=$definition['conditionRider']??null;if(!$rider||applicationIsNonCombatant($target)||($target['hp']??0)<=0)return;
    $ci=dsIndex($state['characters'],applicationAbilityCastingOwner($target)['characterId']);$owner=$ci>=0?$state['characters'][$ci]:null;
    if(in_array(normalizeOnlineCondition($rider['label']),normalizeOnlineConditions($owner['conditionImmunities']??$target['conditionImmunities']??[]),true))return;
    $identity=['id'=>($owner['ownerPlayerId']??'')?:'mechanics-authority','display_name'=>$target['name'],'effective_mode'=>!empty($owner['ownerPlayerId'])?'player':'gm','permanent_role'=>!empty($owner['ownerPlayerId'])?'player':'gm'];
    $context=onlineDeviceContext($db,$records,$pending,$identity);try{$cast=($context['roll'])($state,$owner,$target,$definition['resistanceStatId'],0,$web['name'].' · Éviter l’immobilisation');}catch(RuntimeException $e){if($e->getCode()!==409||$e->getMessage()!=='La caractéristique de ce test est absente.')throw $e;$cast=['manualResistance'=>true,'success'=>false,'pendingGmApproval'=>true,'outcome'=>['label'=>'Caractéristique absente · résistance à arbitrer par le MJ']];}
    $effect=['sceneId'=>$state['activeSceneId'],'targetTokenId'=>$target['id'],'sourceTokenId'=>$web['id'],'rider'=>$rider];
    if($cast['pendingGmApproval'])$state['devices']['pendingRolls'][]=['id'=>'validation-'.randomToken(16),'type'=>'condition','ownerId'=>$owner['id']??'','tokenId'=>$target['id'],'sceneId'=>$state['activeSceneId'],'cast'=>$cast,'effect'=>$effect];
    elseif(!$cast['success'])onlineDeviceTimedCondition($state,[...$effect,'resisted'=>false]);
}
function onlineStructureContact(PDO $db,array &$records,array &$pending,array $attack):array {
    if(!empty($attack['structureContactProcessed'])||!empty($attack['echoParentId'])||!in_array($attack['status']??'',['applied','blocked','missed','defended'],true)||empty($attack['echoAttackContext']['weaponBased'])||!empty($attack['echoAttackContext']['projectile'])||applicationWeaponReach($attack['echoAttackContext'])!=='melee')return $attack;
    $key=onlineTokenDomainKey($attack['sceneId'],$attack['targetTokenId']);
    if(!isset($records[$key])&&!isset($pending[$key]))$records=array_replace(applicationDomainRecords($db,[$key]),$records);
    $target=$pending[$key]['payload']??applicationDomainPayload($records,$key);
    if(empty($target['fixedStructure'])){$attack['structureContactProcessed']=true;return $attack;}
    $state=onlineDeviceSelectScene(onlineDeviceState($db,$records,$pending),$attack['sceneId']);$wi=dsIndex($state['map']['tokens'],$attack['targetTokenId']);$ti=dsIndex($state['map']['tokens'],$attack['sourceTokenId']);
    if($wi>=0&&$ti>=0&&!empty($state['map']['tokens'][$wi]['fixedStructure'])){onlineStructureContactTarget($db,$records,$pending,$state,$state['map']['tokens'][$wi],$state['map']['tokens'][$ti]);onlineDeviceFlush($state,$records,$pending);}
    $attack['structureContactProcessed']=true;return $attack;
}
function onlineDeviceCondition(array &$state,string $tokenId,string $label,bool $add): bool {
    $i=dsIndex($state['map']['tokens'],$tokenId);if($i<0)return false;$token=&$state['map']['tokens'][$i];$owner=applicationAbilityCastingOwner($token);$ci=dsIndex($state['characters'],$owner['characterId']);
    $value=$ci>=0?$state['characters'][$ci]:$token;$before=normalizeOnlineConditions($value['conditions']??[],$value['condition']??'');$exists=in_array($label,$before,true);
    $value['conditions']=normalizeOnlineConditions($add?[...$before,$label]:array_values(array_filter($before,static fn($entry)=>$entry!==$label)));
    if($ci>=0){$state['characters'][$ci]=$value;foreach($state['map']['tokens'] as &$related)if(($related['characterId']??'')===$value['id']&&($related['followCharacter']??true)!==false&&empty($related['linkedTokenId']))$related=synchronizeOnlineCharacterToken($related,$value);unset($related);}
    else{$token['conditions']=$value['conditions'];$token['condition']=implode(', ',$value['conditions']);}return !$exists;
}
function onlineDeviceTimedCondition(array &$state,array $effect): void {
    $rider=$effect['rider'];$resisted=$effect['resisted'];$label=normalizeOnlineCondition($resisted?$rider['fallbackLabel']:$rider['label']);if($label==='')return;
    $target=$state['map']['tokens'][dsIndex($state['map']['tokens'],$effect['targetTokenId'])]??[];
    if(applicationEffectFamily($label)==='poison'&&applicationHasEffectImmunity($target,$state,$effect['sceneId'],'poison'))return;
    $introduced=onlineDeviceCondition($state,$effect['targetTokenId'],$label,true);
    if(count($state['devices']['timedConditions'])>=1000)dsError('Trop d’états temporisés actifs.',429);
    $state['devices']['timedConditions'][]=['id'=>'condition-'.randomToken(16),'sceneId'=>$effect['sceneId'],'targetTokenId'=>$effect['targetTokenId'],'label'=>$label,
        'targetCharacterId'=>applicationAbilityCastingOwner($state['map']['tokens'][dsIndex($state['map']['tokens'],$effect['targetTokenId'])])['characterId'],
        ...(!$resisted&&($rider['expiry']??'')==='end-next-turn'?['expiry'=>'end-next-turn','createdTurnSerial'=>(int)($state['initiative']['turnSerial']??0),'hasStarted'=>false,'escapeWindowClosed'=>false]:[]),
        'remainingTurns'=>$resisted?$rider['fallbackTurns']:$rider['turns'],...(!$resisted&&!empty($rider['attackPenalty'])?['attackPenalty'=>$rider['attackPenalty'],'sourceTokenId'=>$effect['sourceTokenId']??'']:[]),...(!empty($rider['escape'])&&!$resisted?['escape'=>$rider['escape'],'initialTurns'=>$rider['turns']]:[]),'blocksMovement'=>$resisted?$rider['fallbackBlocksMovement']:$rider['blocksMovement'],'blocksActions'=>$resisted?($rider['fallbackBlocksActions']??false):($rider['blocksActions']??false),'clockCombatId'=>$state['initiative']['combatId']??'','lastTurnSerial'=>(int)($state['initiative']['turnSerial']??0),'introduced'=>$introduced];
}
function onlineDeviceInfusion(PDO $db,array &$records,array &$pending,array $identity,array $definition,array $args,array $source,int $fixedCost): ?array {
    $infusion=dsInfusion($definition['infusion']??null);$value=$args['infusionMana']??0;
    if(!$infusion){if($value!==0)dsError('Cette attaque ne permet pas d’infusion.',400);return null;}
    dsAmount($value,$infusion['minimumMana']);if($value>1000000)dsError('L’infusion dépasse sa limite.',400);
    $state=onlineDeviceState($db,$records,$pending);$state=onlineDeviceSelectScene($state,$args['sceneId']??$state['activeSceneId'],$source['layerId']??'ground');$actor=['id'=>$identity['id'],'role'=>onlineIdentityIsGm($identity)?'gm':'player'];
    $ownerId=applicationAbilityCastingOwner($source)['characterId'];$context=onlineDeviceContext($db,$records,$pending,$identity);
    $oi=$ownerId!==''?dsOwner($state,$actor,$ownerId):dsIndex($state['map']['tokens'],$source['id']);if($oi<0)dsError('Le porteur de cette réserve de mana n’existe plus.');
    $trial=$state;if($ownerId!=='')$trialOwner=&$trial['characters'][$oi];else $trialOwner=&$trial['map']['tokens'][$oi];dsPayMana($trial,$trial['devices'],$trialOwner,$source,$value+$fixedCost,$context);
    if($ownerId!=='')$owner=&$state['characters'][$oi];else $owner=&$state['map']['tokens'][$oi];$payment=$value?dsPayMana($state,$state['devices'],$owner,$source,$value,$context):null;
    onlineDeviceFlush($state,$records,$pending);return [...$infusion,'mana'=>$value,'damage'=>$value*$infusion['ratio'],'payment'=>$payment];
}
function onlineDeviceRefund(PDO $db,array &$records,array &$pending,array $payment): void {
    $state=onlineDeviceState($db,$records,$pending);
    if($payment['kind']==='battery'){$index=dsDevice($state['devices'],$payment['deviceId'],false);$state['devices']['devices'][$index]['mana']+=$payment['value'];$state['devices']['devices'][$index]['revision']++;}
    elseif($payment['kind']==='token'){$i=dsIndex($state['map']['tokens'],$payment['tokenId']);if($i<0)dsError('Le porteur de cette réserve de mana n’existe plus.');$owner=&$state['map']['tokens'][$i];dsResource($state,$owner,'mana',$payment['value']);}
    else{$i=dsOwner($state,['role'=>'gm','id'=>'authority'],$payment['characterId']);$owner=&$state['characters'][$i];dsResource($state,$owner,'mana',$payment['value']);}
    onlineDeviceFlush($state,$records,$pending);
}
function onlineDeviceProjectile(PDO $db,array &$records,array &$pending,array $identity,array $source,array $target,bool $projectile,int $pendingFatigueCost=0,?string $sceneId=null): array {
    $checks=[];$percent=100;if(!$projectile)return ['percent'=>100,'checks'=>[],'pending'=>false];
    $state=onlineDeviceState($db,$records,$pending);$state=onlineDeviceSelectScene($state,$sceneId??$state['activeSceneId'],$source['layerId']??'ground');$map=$state['map'];$owner=unitById($state,unitIdForToken($source),false);
    if($owner&&$pendingFatigueCost>0)$owner['fatigue']=normalizeApplicationFatigue(['current'=>($owner['fatigue']['current']??0)+$pendingFatigueCost]);
    $context=onlineDeviceContext($db,$records,$pending,$identity);
    foreach($state['devices']['devices'] as $device) {
        if($device['status']!=='active'||$device['definition']['kind']!=='dome'||$device['sceneId']!==$state['activeSceneId']||dsDistance($source,$target,$map)<=$device['definition']['pointBlankCells'])continue;
        if(($device['mode']??'carried')==='placed')$center=$device;
        else{$center=dsDeviceSource($state,$device);if(!$center)continue;}
        $layer=$center['layerId']??'ground';
        if(($center['hidden']??false)||$layer!==($source['layerId']??'ground')||$layer!==($target['layerId']??'ground'))continue;
        if(dsDistance($source,$center,$map)<=$device['definition']['radiusCells']&&dsDistance($target,$center,$map)<=$device['definition']['radiusCells'])continue;
        $level=$map['layers'][$layer]??$map;$sx=($level['naturalWidth']??$map['naturalWidth'])/(100*$map['gridSize']);$sy=($level['naturalHeight']??$map['naturalHeight'])/(100*$map['gridSize']);$dx=($target['x']-$source['x'])*$sx;$dy=($target['y']-$source['y'])*$sy;
        $cx=($center['x']-$source['x'])*$sx;$cy=($center['y']-$source['y'])*$sy;$p=max(0,min(1,($cx*$dx+$cy*$dy)/($dx*$dx+$dy*$dy?:1)));
        if(hypot($p*$dx-$cx,$p*$dy-$cy)>$device['definition']['radiusCells'])continue;
        $cast=($context['roll'])($state,$owner,$source,$device['definition']['projectileStatId'],0,$device['name'].' · Passage du projectile');$checks[]=['deviceId'=>$device['id'],'cast'=>$cast];
        $percent=!$cast['success']?0:min($percent,$device['definition']['damagePercent']);
    }
    onlineDeviceFlush($state,$records,$pending);return ['percent'=>$percent,'checks'=>$checks,'pending'=>count(array_filter($checks,static fn($entry)=>$entry['cast']['pendingGmApproval']))>0];
}
function onlineDeviceRider(PDO $db,array &$records,array &$pending,array $attack): array {
    if(empty($attack['conditionRider'])||!empty($attack['areaAnchorOnly'])||($attack['riderApplied']??false)||!in_array($attack['status']??'', ($attack['conditionRider']['trigger']??'')==='attempt'?['applied','blocked','missed','defended']:['applied','blocked'],true))return $attack;
    $state=onlineDeviceState($db,$records,$pending);$i=dsIndex($state['map']['tokens'],$attack['targetTokenId']);if($i<0)return $attack;
    $target=$state['map']['tokens'][$i];$ownerId=applicationAbilityCastingOwner($target)['characterId'];$ci=dsIndex($state['characters'],$ownerId);$owner=$ci>=0?$state['characters'][$ci]:null;$rider=dsRider($attack['conditionRider']);
    $effect=['sceneId'=>$attack['sceneId'],'targetTokenId'=>$target['id'],'sourceTokenId'=>$attack['sourceTokenId']??'','rider'=>$rider];$immune=in_array(normalizeOnlineCondition($rider['immunityLabel']?:$rider['label']),normalizeOnlineConditions($owner['conditionImmunities']??$target['conditionImmunities']??[]),true);
    if($immune)onlineDeviceTimedCondition($state,[...$effect,'resisted'=>true]);
    elseif(!empty($rider['escape'])||($rider['resistance']??true)===false)onlineDeviceTimedCondition($state,[...$effect,'resisted'=>false]);
    else{
        $identity=['id'=>($owner['ownerPlayerId']??'')?:'mechanics-authority','display_name'=>$target['name'],'effective_mode'=>!empty($owner['ownerPlayerId'])?'player':'gm','permanent_role'=>!empty($owner['ownerPlayerId'])?'player':'gm'];
        $context=onlineDeviceContext($db,$records,$pending,$identity);$cast=($context['roll'])($state,$owner,$target,$rider['resistanceStatId'],0,$attack['attackName'].' · Résistance');
        if($cast['pendingGmApproval'])$state['devices']['pendingRolls'][]=['id'=>'validation-'.randomToken(16),'type'=>'condition','ownerId'=>$owner['id']??'','tokenId'=>$target['id'],'sceneId'=>$attack['sceneId'],'cast'=>$cast,'effect'=>$effect];
        else onlineDeviceTimedCondition($state,[...$effect,'resisted'=>$cast['success']]);
    }
    if(!$immune&&!empty($rider['criticalLabel'])&&($attack['hit']['outcome']['code']??'')==='critical-success')onlineDeviceTimedCondition($state,[...$effect,'resisted'=>false,'rider'=>[...$rider,'label'=>$rider['criticalLabel'],'attackPenalty'=>0]]);
    onlineDeviceFlush($state,$records,$pending);$attack['riderApplied']=true;return $attack;
}
function publicDeviceStore(array $store,?array $identity,array $characters,array $context,callable $visible): array {
    $own=[];foreach($characters as $character)if(($character['ownerPlayerId']??'')===($identity['id']??null))$own[$character['id']]=true;
    foreach($context['tokens']??[] as $token)if(!empty($identity['id'])&&(($token['controllerPlayerId']??'')===$identity['id']||($token['ownedByYou']??false)))$own[unitIdForToken($token)]=true;
    $items=[];foreach($context['items']['instances']??[] as $item)if($item['location']['kind']==='inventory'&&isset($own[$item['location']['characterId']]))$items[$item['id']]=true;
    $devices=[];foreach($store['devices']??[] as $device) {
        if($device['definition']['kind']==='dome'&&($device['mode']??'carried')!=='placed'){$center=dsDeviceSource(['map'=>['tokens'=>$context['tokens']??[]]],$device);if(!$center||!in_array($center['id'],$context['visibleTokenIds']??[],true))continue;$device=array_replace($device,['x'=>$center['x'],'y'=>$center['y'],'layerId'=>$center['layerId']??'ground','sourceTokenId'=>$center['id']]);}
        if($device['status']==='packed'){if(!isset($own[$device['creatorCharacterId']]))continue;}
        elseif(in_array($device['status'],['inventory','awaiting-launch'],true)){if(!isset($items[$device['itemId']]))continue;}
        elseif(($context['paused']??false)||$device['sceneId']!==$context['sceneId']||$device['layerId']!==$context['layerId']||!$visible($device))continue;
        $device['image']=dsImage(array_key_exists('image',$device)?$device['image']:($device['ability']['image']??''));
        unset($device['ability'],$device['controllerAccountId']);
        if(isset($device['participants'])){$device['participants']=array_map(static fn($entry)=>['characterId'=>$entry['characterId'],'status'=>$entry['status']],$device['participants']);if(!isset($own[$device['creatorCharacterId']]))unset($device['mana']);}
        $devices[]=$device;
    }
    if(!($context['paused']??false)&&!empty($identity['id']))foreach($store['devices']??[] as $device){
        if($device['definition']['kind']!=='beam'||$device['status']!=='active'||in_array($device['id'],array_column($devices,'id'),true))continue;
        $creator=isset($own[$device['creatorCharacterId']]);$participants=array_values(array_filter($device['participants']??[],static fn($entry)=>isset($own[$entry['characterId']])));
        if(!$creator&&!array_filter($participants,static fn($entry)=>$entry['status']==='pending'))continue;
        $safe=array_intersect_key($device,array_fill_keys(['id','name','revision','status','sceneId','creatorCharacterId','definition','waitingForConsent'],true));
        $safe['controlOnly']=true;$safe['elapsedTurns']=$device['elapsedTurns']??0;
        $safe['participants']=array_map(static fn($entry)=>['characterId'=>$entry['characterId'],'status'=>$entry['status']],$creator?($device['participants']??[]):$participants);
        if($creator)$safe['mana']=$device['mana'];$devices[]=$safe;
    }
    $requests=[];foreach($store['craftRequests']??[] as $request)if(isset($own[$request['characterId']])){$request['abilityName']=$request['ability']['name'];unset($request['ability'],$request['controllerAccountId']);$requests[]=$request;}
    $rolls=[];foreach($store['pendingRolls']??[] as $roll)if(isset($own[$roll['ownerId']])){unset($roll['ability'],$roll['args'],$roll['cast']['_devicePlan'],$roll['cast']['_deviceIdentity']);$rolls[]=$roll;}
    $conditions=($context['paused']??false)?[]:array_values(array_filter($store['timedConditions']??[],static fn($entry)=>$entry['sceneId']===$context['sceneId']&&in_array($entry['targetTokenId'],$context['visibleTokenIds']??[],true)));
    return ['version'=>1,'devices'=>$devices,'craftRequests'=>$requests,'pendingRolls'=>$rolls,'timedConditions'=>$conditions,'receipts'=>[]];
}
