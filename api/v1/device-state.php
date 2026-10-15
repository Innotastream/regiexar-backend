<?php
declare(strict_types=1);
require_once __DIR__.'/unit-authority.php';
require_once __DIR__.'/device-items.php';

// Native PHP counterpart of public/device-state.js. Storage and dice are
// supplied by the authority inside its existing transaction/clock lock.
function dsError(string $message, int $status = 409): never { throw new RuntimeException($message, $status); }
function dsImage(mixed $value): string {
    if(!is_string($value)||strlen($value)>4096||preg_match('/[\x00\r\n]/',$value))return '';
    $image=trim($value);
    if(preg_match('#^/media/[A-Za-z0-9_-]{24}$#D',$image)===1)return $image;
    if(strlen($image)>2048)return '';
    $url=parse_url($image);
    return is_array($url)&&strtolower($url['scheme']??'')==='https'&&strtolower($url['host']??'')==='regie-xar-tsaroth.fr'&&!isset($url['user'],$url['pass'])&&!isset($url['user'])&&!isset($url['pass'])&&!isset($url['port'])?$image:'';
}
function dsAmount(mixed $value, int $minimum = 0): int {
    if (!is_int($value) || $value < $minimum || $value > 1000000000) dsError('Renseignez une quantité entière valide.', 400);
    return $value;
}
function dsNumber(mixed $value): bool { return (is_int($value) || is_float($value)) && is_finite((float)$value); }
function dsInt(mixed $value, int $minimum, int $maximum, int $fallback): int {
    return is_numeric($value) && (float)$value === (float)(int)$value && $value >= $minimum && $value <= $maximum ? (int)$value : $fallback;
}
function dsStats(): array { return array_map(static fn($stat) => 'character-stat-' . $stat, ['force','dexterity','agility','spiritSocial','intelligence','instinct']); }
function dsCastingChoices(mixed $value): array { return is_array($value) ? array_values(array_unique(array_intersect($value, dsStats()))) : []; }
function dsInfusion(mixed $value): ?array {
    if (!is_array($value)) return null;
    if (($value['version'] ?? 1) !== 1) dsError('Cette infusion provient d’une version plus récente.');
    if (($value['enabled'] ?? false) !== true) return null;
    return ['version'=>1,'enabled'=>true,'ratio'=>dsInt($value['ratio']??null,1,100,1),'minimumMana'=>dsInt($value['minimumMana']??null,0,1000000,0),'refundOnMiss'=>($value['refundOnMiss']??true)!==false];
}
function dsRider(mixed $value): ?array {
    if (!is_array($value) || trim((string)($value['label']??''))==='') return null;
    if (($value['version']??1)!==1) dsError('Cet effet provient d’une version plus récente.');
    return ['version'=>1,'label'=>mb_substr(trim($value['label']),0,240),'turns'=>dsInt($value['turns']??null,1,100,1),
        'resistanceStatId'=>in_array($value['resistanceStatId']??'',dsStats(),true)?$value['resistanceStatId']:'character-stat-force',
        'fallbackLabel'=>mb_substr(trim((string)($value['fallbackLabel']??'')),0,240),'fallbackTurns'=>dsInt($value['fallbackTurns']??null,1,100,1),
        'immunityLabel'=>mb_substr(trim((string)($value['immunityLabel']??'')),0,240),'blocksMovement'=>($value['blocksMovement']??false)===true,'fallbackBlocksMovement'=>($value['fallbackBlocksMovement']??false)===true,'blocksActions'=>($value['blocksActions']??false)===true,'fallbackBlocksActions'=>($value['fallbackBlocksActions']??false)===true];
}
function dsDefinition(mixed $v): array {
    if (!is_array($v) || !in_array($v['kind']??'', ['battery','launcher','explosive','dome','beam'], true)) dsError('Choisissez un type de dispositif valide.',400);
    if (($v['version']??1)!==1) dsError('Ce dispositif provient d’une version plus récente.');
    $int=static fn($key,$min,$max,$fallback)=>dsInt($v[$key]??null,$min,$max,$fallback);
    $formula=static fn($key,$fallback)=>validApplicationAbilityFormula($v[$key]??null)?strtolower(preg_replace('/\s+/','',$v[$key])):$fallback;
    $damage=static fn($fallback)=>in_array($v['damageType']??'', ['physical','magical','ignore'],true)?$v['damageType']:$fallback;
    $stat=static fn($key,$fallback)=>in_array($v[$key]??'',dsStats(),true)?$v[$key]:$fallback;
    $base=['version'=>1,'kind'=>$v['kind'],'interactionRangeCells'=>$int('interactionRangeCells',0,100,2)];
    return match($v['kind']) {
        'battery'=>[...$base,'canPack'=>($v['canPack']??true)!==false,'radiusCells'=>$int('radiusCells',1,100,2),'chargeRatio'=>$int('chargeRatio',1,100,2),'requireDirectRange'=>($v['requireDirectRange']??true)!==false],
        'launcher'=>[...$base,'canPack'=>($v['canPack']??true)!==false,'damageFormula'=>$formula('damageFormula','1d35'),'damageType'=>$damage('physical'),
            'infusion'=>dsInfusion([...($v['infusion']??[]),'enabled'=>true,'minimumMana'=>$v['infusion']['minimumMana']??5,'refundOnMiss'=>false]),
            'firingStats'=>dsCastingChoices($v['firingStats']??null)?:['character-stat-intelligence','character-stat-dexterity'],
            'ammoTemplateId'=>preg_match('/^[A-Za-z0-9_-]{1,180}$/D',(string)($v['ammoTemplateId']??''))===1?$v['ammoTemplateId']:'','projectile'=>($v['projectile']??true)!==false],
        'explosive'=>[...$base,'craftManaCost'=>$int('craftManaCost',0,1000000,10),'maximumInventory'=>$int('maximumInventory',1,100,5),
            'minimumDelay'=>$int('minimumDelay',1,100,1),'maximumDelay'=>max($int('minimumDelay',1,100,1),$int('maximumDelay',1,100,3)),
            'radiusCells'=>$int('radiusCells',1,100,4),'damageFormula'=>$formula('damageFormula','1d60+12'),'damageType'=>$damage('magical'),'gmApproval'=>true],
        'dome'=>[...$base,'canSetDown'=>($v['canSetDown']??true)!==false,'radiusCells'=>$int('radiusCells',1,100,5),'channelStartTurn'=>$int('channelStartTurn',1,100,3),
            'channelManaCost'=>$int('channelManaCost',0,1000000,10),'penaltyIncrement'=>$int('penaltyIncrement',0,100,10),'channelStatId'=>$stat('channelStatId','character-stat-intelligence'),
            'projectileStatId'=>$stat('projectileStatId','character-stat-dexterity'),'damagePercent'=>$int('damagePercent',0,100,50),'pointBlankCells'=>$int('pointBlankCells',0,100,1),
            'affectAllies'=>true,'affectEnemies'=>true],
        default=>[...$base,'delayTurns'=>$int('delayTurns',1,100,1),'lengthCells'=>$int('lengthCells',1,100,20),'widthCells'=>$int('widthCells',1,100,1),
            'falloffPercent'=>$int('falloffPercent',0,100,10),'damageType'=>$damage('magical'),'consentRequired'=>true,'repairRequiresGm'=>true]
    };
}
function dsEmpty(): array { return ['version'=>1,'devices'=>[],'craftRequests'=>[],'pendingRolls'=>[],'timedConditions'=>[],'receipts'=>[]]; }
function dsStore(mixed $value): array {
    if ($value===null) return dsEmpty();
    if (!is_array($value) || ($value['version']??0)!==1) dsError('Ces dispositifs proviennent d’une version plus récente. Ils ont été conservés.');
    foreach (['devices','craftRequests','pendingRolls','timedConditions','receipts'] as $key) if (!is_array($value[$key]??null) || !array_is_list($value[$key]) || count($value[$key])>($key==='receipts'?2048:1000)) dsError('Le registre des dispositifs est invalide.');
    $ids=[];
    foreach ($value['devices'] as $entry) {
        if (!is_string($entry['id']??null) || isset($ids[$entry['id']]) || !is_int($entry['revision']??null) || $entry['revision']<1
            || !in_array($entry['status']??'', ['active','packed','inventory','awaiting-launch','spent','stopped','failed','broken','repaired'],true)
            || empty($entry['creatorCharacterId']) || empty($entry['sceneId']) || !dsNumber($entry['x']??null) || !dsNumber($entry['y']??null)) dsError('Le registre des dispositifs est invalide.');
        dsDefinition($entry['definition']); $ids[$entry['id']]=true;
        if($entry['definition']['kind']==='dome'&&array_key_exists('mode',$entry)&&!in_array($entry['mode'],['carried','placed'],true))dsError('L’état du bouclier est invalide.');
        if(isset($entry['image'])&&$entry['image']!==''&&dsImage($entry['image'])==='')dsError('L’image du dispositif est invalide.',400);
        if (isset($entry['mana'])) {if($entry['definition']['kind']==='beam'){if(!dsNumber($entry['mana'])||$entry['mana']<0||$entry['mana']>1000000000)dsError('La réserve de ce rayon est invalide.');}else dsAmount($entry['mana']);}
    }
    return $value;
}
function dsIndex(array $list, string $id): int { foreach($list as $index=>$entry) if (($entry['id']??'')===$id) return $index; return -1; }
function dsOwner(array $state,array $actor,string $id): int {
    $index=dsIndex($state['characters']??[],$id); if($index<0) dsError('Cette fiche n’existe plus.',404);
    if (($actor['role']??'')!=='gm' && ($state['characters'][$index]['ownerPlayerId']??'')!==$actor['id']) dsError('Cette fiche ne vous appartient pas.',403);
    return $index;
}
function dsToken(array $state,array $owner,array $args): array {
    foreach($state['map']['tokens']??[] as $token) if (($token['id']??'')===($args['sourceTokenId']??'') && unitIdForToken($token)===$owner['id']
        && (($owner['effectiveRole']??'')==='gm'||!($token['hidden']??false))
        && ($token['layerId']??'ground')===($state['map']['activeLayerId']??'ground')) {
        if (($owner['resources']['hp']??$token['hp']??0)<=0||($owner['healthOverride']??'')==='dead'||($token['healthOverride']??'')==='dead') dsError('Un personnage KO ou mort ne peut pas utiliser ce dispositif.');
        dsAssertActions($state,$token,$state['activeSceneId']);
        return $token;
    }
    dsError('Placez votre personnage sur le niveau courant avant cette action.');
}
function dsAssertActions(array $state,array $token,string $sceneId):void {
    foreach($state['devices']['timedConditions']??[] as $condition)if($condition['sceneId']===$sceneId&&$condition['remainingTurns']>0&&($condition['blocksActions']??false)&&($condition['targetTokenId']===($token['id']??'')||!empty($condition['targetCharacterId'])&&$condition['targetCharacterId']===($token['characterId']??'')))dsError('Ce personnage est '.mb_strtolower($condition['label']).' et ne peut pas agir jusqu’à la fin de cet effet.',423);
}
function dsTurn(array $state,array $actor,array $token): void {
    $i=$state['initiative']??[];
    if ($actor['role']!=='gm' && ($i['active']??false) && ($i['order'][$i['currentIndex']??0]??'')!==$token['id']) dsError('Cette action attend le tour de votre personnage.',403);
}
function dsAbility(array $owner,string $id): array {
    foreach($owner['abilities']??[] as $entry) if ($entry['id']===$id && ($entry['effect']??'')==='device') return $entry;
    dsError('Ce dispositif n’existe plus dans vos compétences.',404);
}
function dsDistance(array $a,array $b,array $map): float {
    $level=$map['layers'][$a['layerId']??$map['activeLayerId']??'ground']??$map;
    $grid=(float)($map['gridSize']??0); $width=(float)($level['naturalWidth']??$map['naturalWidth']??0); $height=(float)($level['naturalHeight']??$map['naturalHeight']??0);
    return $grid>0 && $width>0 && $height>0 ? hypot(($a['x']-$b['x'])*$width/(100*$grid),($a['y']-$b['y'])*$height/(100*$grid)):INF;
}
function dsReach(array $state,array $token,array $device): void {
    if ($device['sceneId']!==$state['activeSceneId'] || ($token['layerId']??'ground')!==$device['layerId']
        || $device['definition']['interactionRangeCells']>0 && dsDistance($token,$device,$state['map'])>$device['definition']['interactionRangeCells']) dsError('Ce dispositif n’est pas à portée sur ce niveau.');
}
function dsDevice(array $store,string $id,bool $active=true): int {
    $index=dsIndex($store['devices'],$id); if($index<0 || $active && $store['devices'][$index]['status']!=='active') dsError('Ce dispositif n’est plus disponible.',404); return $index;
}
function dsResource(array &$state,array &$owner,string $resource,int|float $delta): void {
    $isCharacter=isset($owner['resources'])&&is_array($owner['resources']);
    if($isCharacter)$resources=&$owner['resources'];else $resources=&$owner;
    $maximum=max(0,(float)($resources['max'.ucfirst($resource)]??0));
    $current=max(0,min($maximum,(float)($resources[$resource]??0))); if($current+$delta<0) dsError('Mana insuffisant pour cette action.');
    $resources[$resource]=min($maximum,$current+$delta);$owner['_updatedAt']=(int)floor(microtime(true)*1000);
    if($isCharacter){persistUnitResources($state,$owner);return;}
    if(!$isCharacter){foreach($state['scenes']??[] as $s=>$scene)if($scene['id']===$state['activeSceneId'])foreach($scene['combat']['map']['tokens']??[] as $t=>$token)if($token['id']===$owner['id'])$state['scenes'][$s]['combat']['map']['tokens'][$t][$resource]=$resources[$resource];return;}
    foreach($state['map']['tokens']??[] as $index=>$token) if(($token['characterId']??'')===$owner['id'] && ($token['followCharacter']??true)!==false && empty($token['linkedTokenId'])) $state['map']['tokens'][$index][$resource]=$owner['resources'][$resource];
    foreach($state['scenes']??[] as $s=>$scene) foreach($scene['combat']['map']['tokens']??[] as $t=>$token) if(($token['characterId']??'')===$owner['id'] && ($token['followCharacter']??true)!==false && empty($token['linkedTokenId'])) $state['scenes'][$s]['combat']['map']['tokens'][$t][$resource]=$owner['resources'][$resource];
}
function dsProvider(array $store,array $source,array $map,string $sceneId,callable $direct): ?int {
    $found=[]; foreach($store['devices'] as $index=>$device) if($device['status']==='active' && $device['definition']['kind']==='battery' && $device['sceneId']===$sceneId
        && $device['layerId']===($source['layerId']??'ground') && dsDistance($source,$device,$map)<=$device['definition']['radiusCells']
        && (!$device['definition']['requireDirectRange'] || $direct($source,$device,$map))) $found[]=['index'=>$index,'distance'=>dsDistance($source,$device,$map),'id'=>$device['id']];
    usort($found,static fn($a,$b)=>($a['distance']<=>$b['distance'])?:strcmp($a['id'],$b['id'])); return $found[0]['index']??null;
}
function dsPayMana(array &$state,array &$store,array &$owner,?array $token,int $value,array $context): array {
    dsAmount($value); $index=$token?dsProvider($store,$token,$state['map'],$state['activeSceneId'],$context['direct']??static fn()=>true):null;
    if($index!==null) { $device=&$store['devices'][$index]; if($device['mana']<$value) dsError('La batterie à portée ne contient pas assez de mana.'); $device['mana']-=$value; $device['revision']++; return ['kind'=>'battery','deviceId'=>$device['id'],'value'=>$value]; }
    dsResource($state,$owner,'mana',-$value); return isset($owner['resources'])?['kind'=>'character','characterId'=>$owner['id'],'value'=>$value]:['kind'=>'token','tokenId'=>$owner['id'],'sceneId'=>$state['activeSceneId'],'value'=>$value];
}
function dsBombCount(array $state,array $store,string $id): int {
    $ids=[]; foreach($store['devices'] as $device) if($device['definition']['kind']==='explosive') $ids[$device['itemId']]=true;
    return count(array_filter($state['items']['instances']??[],static fn($item)=>isset($ids[$item['id']]) && $item['location']['kind']==='inventory' && $item['location']['characterId']===$id));
}
function dsAssertItemLimits(array $state):void {
    $bombs=[];foreach($state['devices']['devices']??[] as $device)if($device['definition']['kind']==='explosive')$bombs[$device['itemId']]=$device;
    $inventories=[];foreach($state['items']['instances']??[] as $item){$bomb=$bombs[$item['id']]??null;if(!$bomb||$item['location']['kind']!=='inventory')continue;$id=$item['location']['characterId'];$entry=$inventories[$id]??['count'=>0,'maximum'=>$bomb['definition']['maximumInventory']];$entry['count']++;$entry['maximum']=min($entry['maximum'],$bomb['definition']['maximumInventory']);$inventories[$id]=$entry;if($entry['count']>$entry['maximum'])dsError('Le nombre maximal de bombes dans cet inventaire serait dépassé.');}
}
function dsAppend(array &$store,array $device): array { if(count($store['devices'])>=1000 || dsIndex($store['devices'],$device['id'])>=0) dsError('Le registre des dispositifs est plein.'); $store['devices'][]=$device; return $device; }
function dsPlacement(array $state,array $actor,array $token,array $definition,array $args,array $context): array {
    if(!array_key_exists('x',$args)&&!array_key_exists('y',$args))return ['x'=>$token['x'],'y'=>$token['y'],'layerId'=>$token['layerId']??'ground'];
    if(!dsNumber($args['x']??null)||!dsNumber($args['y']??null)||$args['x']<0||$args['x']>100||$args['y']<0||$args['y']>100)dsError('Choisissez une position valide sur la carte.',400);
    $point=['x'=>$args['x'],'y'=>$args['y'],'layerId'=>$token['layerId']??'ground'];
    if($definition['interactionRangeCells']>0&&dsDistance($token,$point,$state['map'])>$definition['interactionRangeCells'])dsError('Posez le dispositif à portée de votre personnage.');
    if(isset($context['direct'])&&!($context['direct'])($token,$point,$state['map']))dsError('Un mur empêche de poser le dispositif ici.');
    if($actor['role']!=='gm'&&isset($context['visible'])&&!($context['visible'])($point))dsError('Choisissez une position visible sur le niveau courant.',403);
    return $point;
}
function dsInteraction(array $state,array $actor,array $token,array $device,array $context): void {
    dsReach($state,$token,$device);
    if(isset($context['direct'])&&!($context['direct'])($token,$device,$state['map']))dsError('Un mur empêche de manipuler le dispositif.');
    if($actor['role']!=='gm'&&isset($context['visible'])&&!($context['visible'])($device))dsError('Ce dispositif n’est pas visible.',403);
}
function dsDomeMode(array $args,array $definition): string {
    $mode=$args['mode']??'carried';
    if(!in_array($mode,['carried','placed'],true))dsError('Choisissez si le bouclier est porté ou posé.',400);
    if($mode==='placed'&&!$definition['canSetDown'])dsError('Ce bouclier doit rester porté.',400);
    return $mode;
}
function dsDeployment(array &$state,array &$store,array $actor,array $owner,array $token,array $ability,array $args,array $context): array {
    $definition=dsDefinition($ability['device']); $extra=match($definition['kind']) {
        'battery'=>['mana'=>0], 'dome'=>['mode'=>dsDomeMode($args,$definition),'channelCount'=>0,'activeTurns'=>0,'lastChannelTurn'=>null],
        'beam'=>['mana'=>0,'participants'=>array_map(static fn($id)=>['characterId'=>$id,'status'=>'pending'],$args['participantCharacterIds']),'mark'=>$args['mark'],'waitingForConsent'=>true],default=>[] };
    return dsAppend($store,['id'=>($context['id'])('device'),'revision'=>1,'status'=>'active','creatorCharacterId'=>$owner['id'],'controllerAccountId'=>$owner['ownerPlayerId']?:$actor['id'],
        'sourceTokenId'=>$token['id'],'abilityId'=>$ability['id'],'ability'=>$ability,'name'=>$ability['name'],'image'=>dsImage($ability['image']??''),'sceneId'=>$state['activeSceneId'],'x'=>$token['x'],'y'=>$token['y'],'layerId'=>$token['layerId']??'ground','definition'=>$definition,
        'activatedRound'=>(int)($state['initiative']['round']??1),'activatedTurn'=>(int)($state['initiative']['turnSerial']??0),...$extra,...($definition['kind']==='dome'&&$extra['mode']==='carried'?['x'=>$token['x'],'y'=>$token['y'],'layerId'=>$token['layerId']??'ground']:dsPlacement($state,$actor,$token,$definition,$args,$context))]);
}
function dsCompleteBomb(array &$device,array $state,array $token,array $args,bool $success): void {
    $device=array_replace($device,['status'=>$success?'active':'failed','revision'=>$device['revision']+1,'sourceTokenId'=>$token['id'],'sceneId'=>$state['activeSceneId'],'layerId'=>$token['layerId']??'ground',
        'operatorCharacterId'=>unitIdForToken($token),'controllerAccountId'=>(unitById($state,unitIdForToken($token),false)['ownerPlayerId']??'')?:$device['controllerAccountId'],
        'x'=>$args['x'],'y'=>$args['y'],'remainingTurns'=>$args['delay'],'activatedRound'=>(int)($state['initiative']['round']??1),'lastTickRound'=>(int)($state['initiative']['round']??1)]);
}
function dsDeviceSource(array $state,array $device):?array {
    $fallback=null;foreach($state['map']['tokens']??[] as $token)if(unitIdForToken($token)===$device['creatorCharacterId']){
        if($token['id']===$device['sourceTokenId'])return $token;
        if(($token['layerId']??'ground')===$device['layerId'])$fallback??=$token;
    }return $fallback;
}
function dsCanonical(mixed $value): mixed {
    if(!is_array($value)) return $value;
    if(!array_is_list($value)) ksort($value,SORT_STRING);
    foreach($value as $key=>$entry) $value[$key]=dsCanonical($entry); return $value;
}
function assertPhysicalDeviceItemAction(array $state,array $actor,string $command,array $args,array $context=[]):void {
    if(!in_array($command,['item.drop','item.pickup'],true))return;
    $device=null;foreach($state['devices']['devices']??[] as $entry)if(($entry['itemId']??'')===($args['itemId']??'')&&physicalDevice($entry)){$device=$entry;break;}
    if(!$device)return;if(!$device['definition']['canPack'])dsError('Ce dispositif ne peut pas être transporté.');
    $owner=ownedUnit($state,$actor,$args['characterId']??'');$token=dsToken($state,$owner,['sourceTokenId'=>$args['tokenId']??'']);dsTurn($state,$actor,$token);
    if($command==='item.pickup')dsInteraction($state,$actor,$token,$device,$context);
}
function applicationApplyDeviceCommand(array $state,array $actor,string $command,array $args,array $context): array {
    $state['devices']=dsStore($state['devices']??null); $store=&$state['devices']; $effects=[]; $result=[];
    if(preg_match('/^[A-Za-z0-9_-]{16,80}$/D',(string)($args['requestId']??''))!==1) dsError('Référence d’action invalide.',400);
    $signature=json_encode(dsCanonical(['command'=>$command,'args'=>$args]),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    foreach($store['receipts'] as $receipt) if($receipt['requestId']===$args['requestId']) {
        if($receipt['actorId']!==$actor['id'] || $receipt['role']!==$actor['role']) dsError('Cette action appartient à une autre session effective.',403);
        if($receipt['signature']!==$signature) dsError('Cette référence correspond à une autre action.');
        return ['state'=>$state,'effects'=>[],'result'=>[...$receipt['result'],'deduplicated'=>true]];
    }
    if(count($store['receipts'])>=2048) dsError('Le journal de sécurité des dispositifs est plein.',429);
    if(($state['tacticalSync']['paused']??false) && $actor['role']!=='gm') dsError('La table est verrouillée.',423);
    if(!empty($args['sceneId']) && $args['sceneId']!==$state['activeSceneId']) dsError('La scène a changé.');
    $sceneId=$state['activeSceneId']; $id=$context['id']; $now=$context['now'];
    ensureDeviceItems($state,$id);
    if($command==='craft.request') {
        $owner=ownedUnit($state,$actor,$args['characterId']??''); $ability=dsAbility($owner,$args['abilityId']??''); $definition=dsDefinition($ability['device']);
        if($definition['kind']!=='explosive') dsError('Cette compétence ne fabrique pas de bombes.',400);
        $quantity=dsAmount($args['quantity']??null,1); $reserved=0; foreach($store['craftRequests'] as $entry) if($entry['status']==='pending' && $entry['characterId']===$owner['id']) $reserved+=$entry['quantity'];
        if(dsBombCount($state,$store,$owner['id'])+$reserved+$quantity>$definition['maximumInventory']) dsError('Le nombre maximal de bombes dans cet inventaire est atteint.');
        $token=!empty($args['sourceTokenId'])?dsToken($state,$owner,$args):null;
        if($state['initiative']['active']??false){if(!$token)dsError('Placez votre personnage avant une fabrication en combat.');dsTurn($state,$actor,$token);}
        $cost=$quantity*$definition['craftManaCost'];$trial=$state;$trialStore=$store;$trialOwner=$owner;dsPayMana($trial,$trialStore,$trialOwner,$token,$cost,$context);
        $request=['id'=>$id('craft'),'status'=>'pending','characterId'=>$owner['id'],'controllerAccountId'=>$owner['ownerPlayerId']?:$actor['id'],'ability'=>$ability,'sourceTokenId'=>$token['id']??'','sceneId'=>$sceneId,'quantity'=>$quantity,'cost'=>$cost,'requestedAt'=>$now];
        $store['craftRequests'][]=$request; $result=['craftRequestId'=>$request['id'],'pendingGmApproval'=>true,'quantity'=>$quantity,'manaCost'=>$cost];
    } elseif($command==='craft.resolve') {
        if($actor['role']!=='gm') dsError('Seul le MJ peut valider cette fabrication.',403);
        $i=dsIndex($store['craftRequests'],$args['craftRequestId']??''); if($i<0 || $store['craftRequests'][$i]['status']!=='pending') dsError('Cette fabrication a déjà été traitée.'); $request=&$store['craftRequests'][$i];
        if(($args['approve']??false)!==true) { $request['status']='rejected'; $result=['rejected'=>true]; }
        else {
            $owner=ownedUnit($state,$actor,$request['characterId']); $ability=$request['ability']; $definition=dsDefinition($ability['device']);
            if(dsBombCount($state,$store,$owner['id'])+$request['quantity']>$definition['maximumInventory']) dsError('L’inventaire a changé : le nombre maximal de bombes serait dépassé.');
            if(($request['sceneId']??$sceneId)!==$sceneId)dsError('La scène de cette fabrication a changé.');
            $token=!empty($request['sourceTokenId'])?dsToken($state,$owner,['sourceTokenId'=>$request['sourceTokenId']]):null;
            dsPayMana($state,$store,$owner,$token,$request['cost'],$context); $state['items']=normalizeItemStore($state['items']??null); $existingTemplate=null;
            foreach($state['items']['templates'] as $template) if($template['kind']==='object' && ($template['sourceAbilityId']??'')===$ability['id'] && ($template['creatorCharacterId']??'')===$owner['id']) {$existingTemplate=$template;break;}
            $templateId=$existingTemplate['id']??$id('template');
            if($existingTemplate===null) $state['items']['templates'][]=['id'=>$templateId,'kind'=>'object','name'=>$ability['name'],'description'=>'Bombe fabriquée et validée par le MJ.','image'=>'','sourceAbilityId'=>$ability['id'],'creatorCharacterId'=>$owner['id']]; $itemIds=[];
            for($n=0;$n<$request['quantity'];$n++) {
                $itemId=$id('item'); $itemIds[]=$itemId; $state['items']['instances'][]=['id'=>$itemId,'templateId'=>$templateId,'revision'=>1,'location'=>['kind'=>'inventory','characterId'=>$owner['id']]];
                dsAppend($store,['id'=>$id('device'),'revision'=>1,'status'=>'inventory','image'=>dsImage($ability['image']??''),'itemId'=>$itemId,'creatorCharacterId'=>$owner['id'],'controllerAccountId'=>$owner['ownerPlayerId'],
                    'sourceTokenId'=>'','abilityId'=>$ability['id'],'ability'=>$ability,'name'=>$ability['name'],'sceneId'=>$sceneId,'layerId'=>'ground','x'=>0,'y'=>0,'definition'=>$definition]);
            }
            $request['status']='approved'; $request['approvedAt']=$now; $result=['itemIds'=>$itemIds,'manaSpent'=>$request['cost']];
        }
    } elseif($command==='device.deploy') {
        $owner=ownedUnit($state,$actor,$args['characterId']??''); $token=dsToken($state,$owner,$args); dsTurn($state,$actor,$token);
        $ability=dsAbility($owner,$args['abilityId']??''); $definition=dsDefinition($ability['device']);
        if($definition['kind']==='explosive') dsError('Utilisez la fabrication puis le lancement de la bombe.',400);
        foreach($store['pendingRolls'] as $entry) if(($entry['ownerId']??'')===$owner['id'] && ($entry['ability']['id']??'')===$ability['id']) dsError('Ce lancement attend déjà le MJ.');
        if($definition['kind']==='beam') {
            foreach($store['devices'] as $entry) if($entry['creatorCharacterId']===$owner['id'] && $entry['abilityId']===$ability['id'] && in_array($entry['status'],['active','broken'],true)) dsError('Ce dispositif est déjà actif ou attend une réparation du MJ.');
            $participants=$args['participantCharacterIds']??[$owner['id']];
            if(!is_array($participants) || count($participants)<1 || count($participants)>30 || count(array_unique($participants))!==count($participants)) dsError('Choisissez des participants valides.',400);
            foreach($participants as $participant) ownedUnit($state,['role'=>'gm','id'=>$actor['id']],$participant);
            $mark=$args['mark']??[]; if(!dsNumber($mark['x']??null)||!dsNumber($mark['y']??null)||$mark['x']<0||$mark['x']>100||$mark['y']<0||$mark['y']>100||dsDistance($token,$mark,$state['map'])===0.0) dsError('Placez une marque valide pour le rayon.',400);
            $args['participantCharacterIds']=$participants;
        }
        if($definition['kind']!=='dome'||dsDomeMode($args,$definition)==='placed')dsPlacement($state,$actor,$token,$definition,$args,$context);
        $cast=($context['cast'])($state,$store,$owner,$token,$ability,$args); persistUnitResources($state,$owner); $result=['cast'=>$cast];
        if($cast['pendingGmApproval']??false) $store['pendingRolls'][]=['id'=>$id('validation'),'type'=>'deploy','cast'=>$cast,'ownerId'=>$owner['id'],'tokenId'=>$token['id'],'ability'=>$ability,'args'=>$args,'sceneId'=>$sceneId];
        elseif($cast['success']) $result['deviceId']=dsDeployment($state,$store,$actor,$owner,$token,$ability,$args,$context)['id'];
    } elseif($command==='device.image') {
        $device=&$store['devices'][dsDevice($store,$args['deviceId']??'',false)];$holder=$device['ownerUnitId']??$device['creatorCharacterId'];
        if($device['definition']['kind']==='explosive') {
            if(in_array($device['status'],['inventory','awaiting-launch'],true)) {
                foreach($state['items']['instances']??[] as $item)if($item['id']===$device['itemId']&&$item['location']['kind']==='inventory'){$holder=$item['location']['characterId'];break;}
            } else $holder=$device['operatorCharacterId']??$device['creatorCharacterId'];
        }
        ownedUnit($state,$actor,$holder);
        if(!is_int($args['expectedRevision']??null)||$args['expectedRevision']!==$device['revision'])dsError('Ce dispositif a changé. Actualisez ses actions.');
        if($actor['role']!=='gm'&&($state['tacticalSync']['paused']??false))dsError('La table est temporairement verrouillée.',423);
        if(!is_string($args['image']??null)||$args['image']!==''&&dsImage($args['image'])==='')dsError('Importez une image du pion valide.',400);
        $device['image']=dsImage($args['image']);$device['revision']++;$result=['deviceId'=>$device['id'],'imageUpdated'=>true];
    } elseif($command==='device.pack'||$command==='device.place') {
        $device=&$store['devices'][dsDevice($store,$args['deviceId']??'',false)];$definition=dsDefinition($device['definition']);
        $itemIndex=dsIndex($state['items']['instances'],$device['itemId']??'');if($itemIndex<0)dsError('Cet objet n’existe plus.',404);
        $sourceIndex=dsIndex($state['map']['tokens'],$args['sourceTokenId']??'');
        $holder=$command==='device.place'?($state['items']['instances'][$itemIndex]['location']['characterId']??''):($sourceIndex>=0?unitIdForToken($state['map']['tokens'][$sourceIndex]):'');
        $owner=ownedUnit($state,$actor,$holder?:($device['ownerUnitId']??$device['creatorCharacterId']));$token=dsToken($state,$owner,$args);
        if(!in_array($definition['kind'],['battery','launcher'],true)||!$definition['canPack'])dsError('Ce dispositif ne peut pas être replié.',400);
        if(!is_int($args['expectedRevision']??null)||$args['expectedRevision']!==$device['revision'])dsError('Ce dispositif a changé. Actualisez ses actions.');
        if($actor['role']!=='gm'&&($state['tacticalSync']['paused']??false))dsError('La table est temporairement verrouillée.',423);
        dsTurn($state,$actor,$token);
        if($command==='device.pack') {
            if($device['status']!=='active')dsError('Ce dispositif est déjà replié ou indisponible.');
            dsReach($state,$token,$device);
            if(isset($context['direct'])&&!($context['direct'])($token,$device,$state['map']))dsError('Un mur empêche de récupérer le dispositif.');
            if($actor['role']!=='gm'&&isset($context['visible'])&&!($context['visible'])($device))dsError('Ce dispositif n’est pas visible.',403);
            $state['items']['instances'][$itemIndex]['location']=['kind'=>'inventory','characterId'=>$owner['id']];$state['items']['instances'][$itemIndex]['revision']++;
            synchronizeDeviceItemLocations($state,$device['itemId'],['unitId'=>$owner['id'],'tokenId'=>$token['id']]);$result=['deviceId'=>$device['id'],'packed'=>true];
        } else {
            if(!in_array($device['status'],['packed','inventory'],true)||$state['items']['instances'][$itemIndex]['location']['kind']!=='inventory'||$state['items']['instances'][$itemIndex]['location']['characterId']!==$owner['id'])dsError('Ce dispositif n’est plus dans cet inventaire.');
            $device=array_replace($device,dsPlacement($state,$actor,$token,$definition,$args,$context),['status'=>'active','sceneId'=>$sceneId,'sourceTokenId'=>$token['id']]);
            $state['items']['instances'][$itemIndex]['location']=['kind'=>'ground','sceneId'=>$sceneId,'layerId'=>$device['layerId'],'x'=>$device['x'],'y'=>$device['y']];$state['items']['instances'][$itemIndex]['revision']++;
            synchronizeDeviceItemLocations($state,$device['itemId'],['unitId'=>$owner['id'],'tokenId'=>$token['id']]);
            $result=['deviceId'=>$device['id'],'placed'=>true,'reused'=>true];
        }
    } elseif($command==='dome.mode') {
        $device=&$store['devices'][dsDevice($store,$args['deviceId']??'')];$definition=dsDefinition($device['definition']);
        $owner=ownedUnit($state,$actor,$device['creatorCharacterId']);$token=dsToken($state,$owner,$args);
        if($definition['kind']!=='dome'||!$definition['canSetDown'])dsError('Ce bouclier doit rester porté.',400);
        if(!is_int($args['expectedRevision']??null)||$args['expectedRevision']!==$device['revision'])dsError('Ce bouclier a changé. Actualisez ses actions.');
        if($device['sceneId']!==$sceneId)dsError('Ce bouclier appartient à une autre scène.');
        $mode=dsDomeMode($args,$definition);
        if($mode===($device['mode']??'carried'))dsError('Le bouclier est déjà dans cet état.');
        if($mode==='carried'){
            dsReach($state,$token,$device);
            if(isset($context['direct'])&&!($context['direct'])($token,$device,$state['map']))dsError('Un mur empêche de reprendre le bouclier.');
            if($actor['role']!=='gm'&&isset($context['visible'])&&!($context['visible'])($device))dsError('Ce bouclier n’est pas visible.',403);
        }
        $position=$mode==='carried'?['x'=>$token['x'],'y'=>$token['y'],'layerId'=>$token['layerId']??'ground']:dsPlacement($state,$actor,$token,$definition,$args,$context);
        $device=array_replace($device,$position,['mode'=>$mode,'sourceTokenId'=>$token['id']]);$device['revision']++;
        $result=['deviceId'=>$device['id'],'mode'=>$mode,'reused'=>true];
    } elseif($command==='battery.charge') {
        $device=&$store['devices'][dsDevice($store,$args['deviceId']??'')]; $owner=ownedUnit($state,$actor,$device['ownerUnitId']??$device['creatorCharacterId']); $token=dsToken($state,$owner,$args);
        if($device['definition']['kind']!=='battery') dsError('Ce dispositif ne possède pas de batterie.',400); dsTurn($state,$actor,$token); dsInteraction($state,$actor,$token,$device,$context);
        $spent=dsAmount($args['mana']??null,1); if($device['mana']+$spent*$device['definition']['chargeRatio']>1000000000) dsError('La batterie atteindrait sa limite.');
        dsResource($state,$owner,'mana',-$spent); $device['mana']+=$spent*$device['definition']['chargeRatio']; $device['revision']++; $result=['manaSpent'=>$spent,'batteryMana'=>$device['mana']];
    } elseif($command==='launcher.fire') {
        $device=&$store['devices'][dsDevice($store,$args['deviceId']??'')]; $owner=ownedUnit($state,$actor,$args['characterId']??''); $token=dsToken($state,$owner,$args);
        if($device['definition']['kind']!=='launcher') dsError('Ce dispositif ne peut pas tirer.',400); dsTurn($state,$actor,$token); dsInteraction($state,$actor,$token,$device,$context);
        if(!in_array($args['statId']??'', $device['definition']['firingStats'],true)) dsError('Choisissez une statistique de tir valide.',400);
        $spent=dsAmount($args['mana']??null,$device['definition']['infusion']['minimumMana']); $ammo=-1;
        if(($owner['resources']['maxMana']??0)<=0)dsError('Ce personnage doit posséder du mana pour utiliser le lanceur.');
        foreach($state['items']['instances']??[] as $i=>$item) if($item['templateId']===$device['definition']['ammoTemplateId'] && $item['location']['kind']==='inventory' && $item['location']['characterId']===$device['creatorCharacterId']) {$ammo=$i;break;}
        if($ammo<0) dsError('Le créateur ne possède plus de carreau dans son inventaire.');
        dsPayMana($state,$store,$owner,$token,$spent,$context);
        rememberLinkedItemStock($state['items'],unitById($state,$device['creatorCharacterId'],false),$state['items']['instances'][$ammo]['templateId']);
        $item=&$state['items']['instances'][$ammo]; $item['location']=['kind'=>'consumed','characterId'=>$device['creatorCharacterId']]; $item['revision']++;
        $effects[]=['type'=>'attack','sourceCharacterId'=>$owner['id'],'sourceTokenId'=>$token['id'],'targetTokenId'=>$args['targetTokenId']??'','statId'=>$args['statId'],'name'=>$device['name'],'projectile'=>$device['definition']['projectile'],'opposed'=>($args['opposed']??true)!==false,
            'rollMode'=>$args['rollMode']??'normal',
            'damageComponents'=>[['type'=>$device['definition']['damageType'],'formula'=>$device['definition']['damageFormula']],['type'=>'ignore','formula'=>(string)($spent*$device['definition']['infusion']['ratio'])]],
            'manaSpent'=>$spent,'ammoItemId'=>$item['id'],'refundOnMiss'=>$device['definition']['infusion']['refundOnMiss'],'deviceId'=>$device['id']]; $result=['manaSpent'=>$spent,'ammoItemId'=>$item['id']];
    } elseif($command==='bomb.launch') {
        $device=&$store['devices'][dsDevice($store,$args['deviceId']??'',false)]; $owner=ownedUnit($state,$actor,$args['characterId']??''); $token=dsToken($state,$owner,$args);
        if($device['definition']['kind']!=='explosive'||$device['status']!=='inventory') dsError('Cette bombe n’est plus disponible.');
        $itemIndex=dsIndex($state['items']['instances']??[],$device['itemId']); $item=&$state['items']['instances'][$itemIndex];
        if($itemIndex<0 || $item['location']['kind']!=='inventory'||$item['location']['characterId']!==$owner['id']) dsError('Cet exemplaire n’est plus dans votre inventaire.'); dsTurn($state,$actor,$token);
        $delay=dsAmount($args['delay']??null,$device['definition']['minimumDelay']); if($delay>$device['definition']['maximumDelay']||!dsNumber($args['x']??null)||!dsNumber($args['y']??null)||$args['x']<0||$args['x']>100||$args['y']<0||$args['y']>100) dsError('Choisissez une position et un délai valides.',400);
        $cast=($context['cast'])($state,$store,$owner,$token,$device['ability'],$args); persistUnitResources($state,$owner); $result=['cast'=>$cast]; $item['location']=['kind'=>'consumed','characterId'=>$owner['id']]; $item['revision']++;
        if($cast['pendingGmApproval']??false) {$device['status']='awaiting-launch';$device['revision']++;$store['pendingRolls'][]=['id'=>$id('validation'),'type'=>'bomb','cast'=>$cast,'ownerId'=>$owner['id'],'tokenId'=>$token['id'],'deviceId'=>$device['id'],'ability'=>$device['ability'],'args'=>$args,'sceneId'=>$sceneId];}
        else dsCompleteBomb($device,$state,$token,$args,$cast['success']);
    } elseif($command==='dome.stop') {
        $device=&$store['devices'][dsDevice($store,$args['deviceId']??'')]; ownedUnit($state,$actor,$device['creatorCharacterId']); if($device['definition']['kind']!=='dome') dsError('Ce dispositif n’est pas un champ de protection.');
        $device['status']='stopped';$device['revision']++;$result=['stopped'=>true];
    } elseif($command==='beam.consent') {
        $device=&$store['devices'][dsDevice($store,$args['deviceId']??'')];$owner=ownedUnit($state,$actor,$args['characterId']??'');
        if($actor['role']==='gm'&&!empty($owner['ownerPlayerId'])&&$owner['ownerPlayerId']!==$actor['id']) dsError('Le propriétaire de cette fiche doit consentir lui-même.',403);
        $p=-1;foreach($device['participants']??[] as $i=>$entry) if($entry['characterId']===$owner['id']&&$entry['status']==='pending') {$p=$i;break;}
        if($p<0||$device['definition']['kind']!=='beam') dsError('Cette contribution n’est plus en attente.'); $participant=&$device['participants'][$p];
        if(($args['approve']??false)!==true) $participant['status']='rejected';
        else { $mana=max(0,min($owner['resources']['maxMana']??0,$owner['resources']['mana']));if(!dsNumber($mana)||$device['mana']+$mana>1000000000)dsError('La réserve de ce rayon dépasserait sa limite.');if(!dsNumber($args['expectedMana']??null)||(float)$args['expectedMana']!==(float)$mana) dsError('Le mana de la fiche a changé. Relisez la contribution avant de confirmer.'); dsResource($state,$owner,'mana',-$mana);$device['mana']+=$mana;$participant['status']='approved';$participant['mana']=$mana; }
        $device['waitingForConsent']=count(array_filter($device['participants'],static fn($entry)=>$entry['status']==='pending'))>0;$device['revision']++;
        if(!$device['waitingForConsent']) $device['readyRound']=(int)($state['initiative']['round']??1)+$device['definition']['delayTurns'];$result=['consented'=>$participant['status']==='approved'];
    } elseif($command==='device.repair') {
        if($actor['role']!=='gm') dsError('Seul le MJ peut réactiver cet appareil.',403);$device=&$store['devices'][dsDevice($store,$args['deviceId']??'',false)];
        if($device['status']!=='broken') dsError('Cet appareil n’attend pas de réparation.');$device['status']='repaired';$device['revision']++;$result=['repaired'=>true];
    } elseif($command==='device.trigger') {
        $device=&$store['devices'][dsDevice($store,$args['deviceId']??'')];ownedUnit($state,$actor,$device['operatorCharacterId']??$device['creatorCharacterId']);
        if($state['initiative']['active']??false) dsError('Le déclenchement manuel est réservé au hors combat.');if($device['definition']['kind']!=='explosive') dsError('Ce dispositif n’est pas une bombe.');
        $effects[]=['type'=>'explosion','device'=>$device];$device['status']='spent';$device['revision']++;$result=['triggered'=>true];
    } elseif($command==='scene.advance') {
        if($actor['role']!=='gm')dsError('Seul le MJ peut avancer un tour hors combat.',403);
        if($state['initiative']['active']??false)dsError('Utilisez l’initiative pour avancer un tour en combat.');
        $advanced=applicationAdvanceDevices($state,$state['initiative'],[...$context,'manual'=>true]);$state=$advanced['state'];$store=&$state['devices'];$effects=[...$effects,...$advanced['effects']];$result=['advanced'=>true];
        foreach($state['actionTimers']??[] as $index=>$timer)if(($timer['sceneId']??'')===$sceneId&&($timer['readyRound']??0)>($state['initiative']['round']??1))$state['actionTimers'][$index]['readyRound']=max($state['initiative']['round']??1,$timer['readyRound']-1);
    } elseif($command==='roll.resolve') {
        if($actor['role']!=='gm') dsError('Seul le MJ peut traiter ce résultat remarquable.',403);$i=dsIndex($store['pendingRolls'],$args['validationId']??'');if($i<0) dsError('Ce résultat a déjà été traité.');
        $p=$store['pendingRolls'][$i];array_splice($store['pendingRolls'],$i,1);$owner=null;
        if($p['type']!=='condition'){$owner=ownedUnit($state,$actor,$p['ownerId']);}
        $ti=dsIndex($state['map']['tokens'],$p['tokenId']);if($ti<0) dsError('Le pion de ce résultat n’existe plus.');$token=$state['map']['tokens'][$ti];
        $success=($args['approve']??false)===true && $p['cast']['success'];
        if(in_array($p['type'],['deploy','bomb'],true)) {
            if(($args['approve']??false)===true)($context['finishCast'])($state,$owner,$token,$p['ability'],$p['cast'],$p['sceneId']);
            if($p['type']==='deploy'&&$success) $result['deviceId']=dsDeployment($state,$store,$actor,$owner,$token,$p['ability'],$p['args'],$context)['id'];
            if($p['type']==='bomb') dsCompleteBomb($store['devices'][dsDevice($store,$p['deviceId'],false)],$state,$token,$p['args'],$success);
        } elseif(in_array($p['type'],['channel','luck'],true)) { $device=&$store['devices'][dsDevice($store,$p['deviceId'],false)];if($p['type']==='luck')$device['status']=$success?'spent':'broken';elseif(!$success)$device['status']='stopped';$device['revision']++; }
        elseif($p['type']==='condition'){if(($args['approve']??false)===true)$effects[]=['type'=>'condition',...$p['effect'],'resisted'=>$p['cast']['success']];}else dsError('Ce résultat n’est pas reconnu.');$result['resolved']=true;
    } else dsError('Commande de dispositif inconnue.',400);
    ensureDeviceItems($state,$id);
    if(isset($state['items'])) $state['items']=normalizeItemStore($state['items']);
    dsAssertItemLimits($state);
    $store['receipts'][]=['requestId'=>$args['requestId'],'actorId'=>$actor['id'],'role'=>$actor['role'],'signature'=>$signature,'result'=>$result,'at'=>$now];
    return ['state'=>$state,'effects'=>$effects,'result'=>$result];
}

function applicationAdvanceDevices(array $state,array $previous,array $context): array {
    $state['devices']=dsStore($state['devices']??null);$store=&$state['devices'];$current=$state['initiative'];$sceneId=$state['activeSceneId'];$effects=[];
    $manual=($context['manual']??false)===true;
    if(!$manual&&(!($current['active']??false)||!($previous['active']??false)||($current['combatId']??'')!==($previous['combatId']??'')||($current['turnSerial']??0)<=($previous['turnSerial']??0)))return ['state'=>$state,'effects'=>[]];
    foreach($store['timedConditions'] as &$condition) {
        if($condition['sceneId']===$sceneId&&($condition['clockCombatId']??'')!==($current['combatId']??'')){$condition['clockCombatId']=$current['combatId']??'';$condition['lastTurnSerial']=-1;}
        if($condition['sceneId']!==$sceneId||!$manual&&($condition['targetTokenId']!==($previous['order'][$previous['currentIndex']??0]??'')||$condition['lastTurnSerial']>=($current['turnSerial']??0)))continue;
        $condition['remainingTurns']--;$condition['lastTurnSerial']=$current['turnSerial']??0;if($condition['remainingTurns']<=0)$effects[]=['type'=>'condition.expire','condition'=>$condition];
    }unset($condition);$store['timedConditions']=array_values(array_filter($store['timedConditions'],static fn($entry)=>$entry['remainingTurns']>0));
    foreach($store['devices'] as &$device) {
        if($device['status']!=='active'||$device['sceneId']!==$sceneId)continue;
        $owner=unitById($state,$device['creatorCharacterId'],false);$token=dsDeviceSource($state,$device);
        if($token)$device['sourceTokenId']=$token['id'];
        if($device['definition']['kind']==='dome'&&(!$owner||!$token||($owner['resources']['hp']??0)<=0||($owner['healthOverride']??'')==='dead'||($token['healthOverride']??'')==='dead')){$device['status']='stopped';$device['revision']++;continue;}
        if($device['definition']['kind']==='dome'&&$token&&($manual||($current['order'][$current['currentIndex']??0]??'')===$token['id']&&($device['lastChannelTurn']!==$current['turnSerial']||($device['lastChannelCombatId']??'')!==($current['combatId']??'')))) {
            $device['activeTurns']++;$device['lastChannelTurn']=$current['turnSerial'];
            if(($device['mode']??'carried')!=='placed'){$device['x']=$token['x'];$device['y']=$token['y'];$device['layerId']=$token['layerId']??'ground';}
            $device['lastChannelCombatId']=$current['combatId']??'';
            $pending=false;foreach($store['pendingRolls'] as $entry)if(($entry['deviceId']??'')===$device['id']&&$entry['type']==='channel')$pending=true;
            if($device['activeTurns']+1>=$device['definition']['channelStartTurn']&&!$pending) {
                try {
                    dsPayMana($state,$store,$owner,$token,$device['definition']['channelManaCost'],$context);$device['channelCount']++;
                    $cast=($context['roll'])($state,$owner,$token,$device['definition']['channelStatId'],-$device['channelCount']*$device['definition']['penaltyIncrement'],$device['name'].' · Canalisation');
                    if($cast['pendingGmApproval'])$store['pendingRolls'][]=['id'=>($context['id'])('validation'),'type'=>'channel','ownerId'=>$owner['id'],'tokenId'=>$token['id'],'sceneId'=>$sceneId,'deviceId'=>$device['id'],'cast'=>$cast];
                    elseif(!$cast['success'])$device['status']='stopped';
                }catch(RuntimeException $error){if($error->getCode()!==409)throw $error;$device['status']='stopped';}
            }$device['revision']++;
        }
        if(in_array($device['definition']['kind'],['explosive','beam'],true)){
            if($device['definition']['kind']==='beam'&&$device['waitingForConsent'])continue;
            $cycle=max(1,count($current['order']??[]));$device['elapsedTicks']=($device['elapsedTicks']??0)+($manual?$cycle:1);
            if($device['elapsedTicks']<$cycle)continue;$device['elapsedTicks']-=$cycle;$device['elapsedTurns']=($device['elapsedTurns']??0)+1;
        }else continue;
        if($device['definition']['kind']==='explosive') {
            $device['remainingTurns']--;$device['lastTickRound']=$current['round']??1;$device['revision']++;
            if($device['remainingTurns']<=0){$effects[]=['type'=>'explosion','device'=>$device];$device['status']='spent';}
        }
        if($device['definition']['kind']==='beam'&&!$device['waitingForConsent']&&$device['elapsedTurns']>=$device['definition']['delayTurns']) {
            $effects[]=['type'=>'beam','device'=>$device];$device['status']='spent';$device['revision']++;
            $effects[]=['type'=>'beam.finish','deviceId'=>$device['id']];
        }
    }unset($device);return ['state'=>$state,'effects'=>$effects];
}

function applicationFinishBeam(array &$state,string $deviceId,array $context):void {
    $store=&$state['devices'];$device=&$store['devices'][dsDevice($store,$deviceId,false)];
    $owner=unitById($state,$device['creatorCharacterId'],false);$token=dsDeviceSource($state,$device);
    if(!$owner||!$token){$device['status']='broken';$device['revision']++;return;}
    $device['sourceTokenId']=$token['id'];$cast=($context['roll'])($state,$owner,$token,'luck',0,$device['name'].' · Lentille');
    if($cast['pendingGmApproval']||($cast['outcome']['threshold']??null)===null)$store['pendingRolls'][]=['id'=>($context['id'])('validation'),'type'=>'luck','ownerId'=>$owner['id'],'tokenId'=>$token['id'],'sceneId'=>$device['sceneId'],'deviceId'=>$device['id'],'cast'=>$cast];
    elseif(!$cast['success'])$device['status']='broken';$device['revision']++;
}
