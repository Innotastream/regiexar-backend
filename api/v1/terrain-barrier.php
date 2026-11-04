<?php
declare(strict_types=1);
require_once __DIR__.'/door-keyhole.php';
require_once __DIR__.'/door-pick.php';
const XAR_THORN_WALL_WIDTH = 5;
const XAR_THORN_WALL_HEIGHT = 1;
const XAR_THORN_WALL_REACH = 2;
const XAR_OBSTACLE_DOOR_WIDTH = 1.25;
const XAR_OBSTACLE_DOOR_HEIGHT = .26;
const XAR_OBSTACLE_DOOR_BLOCK_DEPTH = .13;
const XAR_PASS_PARTOUT_TEMPLATE = 'utility-passe-partout';

function applicationIsThornWall(array $token): bool {
    return ($token['terrainBarrier']['shape'] ?? '') === 'thorn-wall';
}

function applicationIsObstacleDoor(array $token): bool {
    return ($token['terrainBarrier']['shape'] ?? '') === 'door';
}
function applicationObstacleDoorWidth(array $token): float {
    $width=$token['terrainBarrier']['widthCells']??null;
    return (is_int($width)||is_float($width))&&is_finite((float)$width)&&$width>=.25&&$width<=12?(float)$width:XAR_OBSTACLE_DOOR_WIDTH;
}
function applicationObstacleDoorHeight(array $token): float {
    return applicationObstacleDoorWidth($token)*XAR_OBSTACLE_DOOR_HEIGHT/XAR_OBSTACLE_DOOR_WIDTH;
}
function applicationIsRectangularObstacle(array $token): bool {
    return applicationIsThornWall($token)||applicationIsObstacleDoor($token);
}
function applicationObstacleRectOffset(float $dx,float $dy,int $rotation,float $width,float $height,bool $diagonalAsOne=false):float {
    $angle=deg2rad($rotation);$cos=cos($angle);$sin=sin($angle);
    $x=max(0,abs($dx*$cos+$dy*$sin)-$width/2);
    $y=max(0,abs(-$dx*$sin+$dy*$cos)-$height/2);
    return $diagonalAsOne?max($x,$y):hypot($x,$y);
}

function applicationThornWallAreaTargets(array $tokens, array $point, array $map): array {
    $map['tokens']=$tokens;
    $wall=[...$point,'terrainBarrier'=>['shape'=>'thorn-wall','rotation'=>$point['rotation']??0]];
    return array_values(array_filter($tokens,static fn($t)=>empty($t['hidden'])&&applicationMapDistanceInCells($wall,$t,$map,false,true)<=XAR_THORN_WALL_REACH+1e-9));
}

function applicationTerrainBarrier(mixed $value): ?array {
    return is_array($value)&&($value['version']??0)===1&&is_int($value['hp']??null)&&$value['hp']>0&&$value['hp']<=1000000000
        &&is_int($value['size']??null)&&$value['size']>=10&&$value['size']<=220?['version'=>1,'hp'=>$value['hp'],'size'=>$value['size'],...(in_array($value['shape']??'',['thorn-wall','door'],true)?['shape'=>$value['shape']]:[])]:null;
}
function applicationTerrainBarrierActive(array $token): bool {return ($token['terrainBarrier']['version']??0)===1&&($token['hp']??0)>0&&($token['healthOverride']??'')!=='dead';}
function applicationTerrainBarrierPositioned(array $token):bool {
    foreach(['x','y'] as $key){$value=$token[$key]??null;if((!is_int($value)&&!is_float($value))||!is_finite((float)$value)||$value<0||$value>100)return false;}return true;
}
function applicationTerrainBarrierPointVisible(array $token,array $map,callable $visible):bool {
    if(!applicationTerrainBarrierPositioned($token))return false;
    if($visible($token['x'],$token['y']))return true;if(!applicationTerrainBarrierActive($token))return false;
    $level=$map['layers'][$token['layerId']??$map['activeLayerId']??'ground']??$map;
    if(applicationIsRectangularObstacle($token)){
        $door=applicationIsObstacleDoor($token);
        $halfWidth=($door?applicationObstacleDoorWidth($token):XAR_THORN_WALL_WIDTH)/2+.06;
        $halfHeight=($door?applicationObstacleDoorHeight($token):XAR_THORN_WALL_HEIGHT)/2+.06;
        $grid=(float)(($map['gridSize']??0)?:50);$angle=deg2rad($token['terrainBarrier']['rotation']);$cos=cos($angle);$sin=sin($angle);
        $width=(float)(($level['naturalWidth']??0)?:($map['naturalWidth']??1600));$height=(float)(($level['naturalHeight']??0)?:($map['naturalHeight']??900));
        for($i=0;$i<=20;$i++){
            $t=$i/10-1;
            foreach([[$t*$halfWidth,-$halfHeight],[$t*$halfWidth,$halfHeight],[-$halfWidth,$t*$halfHeight],[$halfWidth,$t*$halfHeight]] as [$u,$v]){
                $a=$u*$grid;$b=$v*$grid;
                $x=$token['x']+($a*$cos-$b*$sin)*100/$width;$y=$token['y']+($a*$sin+$b*$cos)*100/$height;
                if($x>=0&&$x<=100&&$y>=0&&$y<=100&&$visible($x,$y))return true;
            }
        }
        return false;
    }
    $rx=(($token['size']??40)/2+2)*100/($level['naturalWidth']??$map['naturalWidth']??1600);$ry=(($token['size']??40)/2+2)*100/($level['naturalHeight']??$map['naturalHeight']??900);
    for($i=0;$i<24;$i++){$a=$i*M_PI/12;$x=$token['x']+cos($a)*$rx;$y=$token['y']+sin($a)*$ry;if($x>=0&&$x<=100&&$y>=0&&$y<=100&&$visible($x,$y))return true;}return false;
}
function applicationTerrainBarrierWalls(array $walls,array $map): array {
    $layer=$map['activeLayerId']??'ground';$level=$map['layers'][$layer]??$map;
    $nw=max(1,(float)($level['naturalWidth']??$map['naturalWidth']??1600));$nh=max(1,(float)($level['naturalHeight']??$map['naturalHeight']??900));
    $bytes=applicationWallMaskBytes($walls)??str_repeat("\0",(int)ceil($walls['width']*$walls['height']/8));$changed=false;
    foreach($map['tokens']??[] as $token){
        if(!applicationTerrainBarrierActive($token)||!applicationTerrainBarrierPositioned($token)||($token['layerId']??'ground')!==$layer)continue;$changed=true;
        $rect=applicationIsRectangularObstacle($token);$door=applicationIsObstacleDoor($token);$grid=(float)(($map['gridSize']??0)?:50);
        $open=$door&&!empty($token['terrainBarrier']['open']);
        $doorWidth=applicationObstacleDoorWidth($token);$across=$door?$doorWidth*($open ? .8 : 1):XAR_THORN_WALL_WIDTH;
        $depth=$door?max(XAR_OBSTACLE_DOOR_BLOCK_DEPTH,1.5*max($nw/$walls['width'],$nh/$walls['height'])/$grid):XAR_THORN_WALL_HEIGHT;
        $rotation=$token['terrainBarrier']['rotation']??0;$angle=deg2rad($rotation);$shift=$open?-.4*$doorWidth*$grid:0;
        $px=$token['x']*$nw/100+$shift*(cos($angle)-sin($angle));
        $py=$token['y']*$nh/100+$shift*(sin($angle)+cos($angle));
        $bounds=$rect?hypot($across,$depth)*$grid/2:$token['size']/2;
        $cx=$px/$nw*($walls['width']-1);$cy=$py/$nh*($walls['height']-1);$rx=$bounds/$nw*($walls['width']-1);$ry=$bounds/$nh*($walls['height']-1);
        for($y=max(0,(int)floor($cy-$ry));$y<=min($walls['height']-1,(int)ceil($cy+$ry));$y++)for($x=max(0,(int)floor($cx-$rx));$x<=min($walls['width']-1,(int)ceil($cx+$rx));$x++)
            if($rect
                ? applicationObstacleRectOffset(($x-$cx)*$nw/(max(1,$walls['width']-1)*$grid),($y-$cy)*$nh/(max(1,$walls['height']-1)*$grid),$rotation-($open?90:0),$across,$depth)===0.0
                : (($x-$cx)/$rx)**2+(($y-$cy)/$ry)**2<=1)applicationSetMaskBit($bytes,$y*$walls['width']+$x,true);
    }
    if($changed)$walls['mask']=rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');return $walls;
}
function applicationCreateTerrainBarrierToken(array $ability,array $point,string $id,bool $playerSide=false): array {
    $d=applicationTerrainBarrier($ability['terrainBarrier']??null);if(!$d)return [];
    $rectangle=in_array($d['shape']??'',['thorn-wall','door'],true);
    $barrier=['version'=>1,'abilityId'=>$ability['id'],'minimumSize'=>$d['size'],...($rectangle?['shape'=>$d['shape'],'rotation'=>$point['rotation']??0,...($d['shape']==='door'?['open'=>false,'locked'=>false]:['playerSide'=>$playerSide,'raisedAt'=>(int)floor(microtime(true)*1000)])]:[])];
    return ['id'=>$id,'name'=>$ability['name']??'Mur','terrainBarrier'=>$barrier,
        'image'=>null,'color'=>'#658b3f','frameVariant'=>'creature','x'=>$point['x'],'y'=>$point['y'],'layerId'=>$point['layerId']??'ground',
        'size'=>$d['size'],'hp'=>$d['hp'],'maxHp'=>$d['hp'],'mana'=>0,'maxMana'=>0,'armorCategory'=>($d['shape']??'')==='door'?'medium':'none','armor'=>($d['shape']??'')==='door'?20:0,'magicArmorCategory'=>'none','magicArmor'=>0,
        'followCharacter'=>false,'characterId'=>null,'controllerPlayerId'=>null,'immovable'=>!$rectangle,'mountable'=>false,'mountControllable'=>false,
        'hidden'=>false,'revealDetailsToPlayers'=>true,'abilities'=>[],'weaponAttacks'=>[],'stats'=>[],'conditions'=>[],'condition'=>'','_updatedAt'=>(int)floor(microtime(true)*1000)];
}
function applicationProtectTerrainBarrier(array $previous,array $next,bool $allowDoorChanges=true): array {
    if(empty($previous['terrainBarrier']))return $next;
    $rectangle=applicationIsRectangularObstacle($previous);
    if(!$rectangle)foreach(['x','y','layerId'] as $k)if(($next[$k]??null)!==($previous[$k]??null))throw new RuntimeException('Ce mur reste à son emplacement jusqu’à sa destruction.',409);
    $barrier=$previous['terrainBarrier'];
    $door=applicationIsObstacleDoor($previous);
    if($door&&$allowDoorChanges){
        foreach(['open','locked','allowPeek'] as $field)if(is_bool($next['terrainBarrier'][$field]??null))$barrier[$field]=$next['terrainBarrier'][$field];
        foreach(['swungAt','pickDifficulty','pickBrokeAt','pickReceipts','peekPermissionAt'] as $field)
            if(isset($next['terrainBarrier'][$field]))$barrier[$field]=$next['terrainBarrier'][$field];
        if(isset($next['terrainBarrier']['widthCells']))$barrier['widthCells']=$next['terrainBarrier']['widthCells'];
        if(!empty($next['terrainBarrier']['keyItemId']))$barrier['keyItemId']=$next['terrainBarrier']['keyItemId'];
        elseif(isset($next['terrainBarrier']))unset($barrier['keyItemId']);
    }
    return [...$next,'terrainBarrier'=>$barrier,'size'=>max($previous['terrainBarrier']['minimumSize']??10,$next['size']??$previous['size']),
        'immovable'=>!$rectangle,'controllerPlayerId'=>null,'characterId'=>null,'followCharacter'=>false,'mountable'=>false,'mountControllable'=>false,
        ...($door?[]:['armorCategory'=>'none','armor'=>0,'magicArmorCategory'=>'none','magicArmor'=>0]),'abilities'=>[],'weaponAttacks'=>[],'stats'=>[]];
}
function onlineApplyTerrainBarrier(PDO $connection,array &$records,array &$pending,array $attack): array {
    if(empty($attack['terrainBarrier'])||!empty($attack['terrainCreated'])||!in_array($attack['status']??'',['applied','blocked'],true))return $attack;
    $token=applicationCreateTerrainBarrierToken(['id'=>$attack['attackId'],'name'=>$attack['attackName'],'terrainBarrier'=>$attack['terrainBarrier']],$attack['targetPoint'], 'wall-'.substr(hash('sha256',$attack['id']),0,32),($attack['terrainBarrierPlayerSide']??false)===true);
    if(!$token)return $attack;
    $scene=$attack['sceneId'];$key=onlineTokenDomainKey($scene,$token['id']);
    queueOnlineDomainUpsert($pending,$records,$key,$token);
    $ik='token-index:'.$scene;$index=$pending[$ik]['payload']??applicationDomainPayload($records,$ik,['order'=>[]]);
    if(!in_array($token['id'],$index['order']??[],true))$index['order'][]=$token['id'];
    queueOnlineDomainUpsert($pending,$records,$ik,$index);
    $attack['terrainCreated']=$token['id'];
    if(applicationIsThornWall($token)){
        $tokens=onlineSceneTokensForLights($connection,$records,$pending,$scene);
        $source=array_column($tokens,null,'id')[$attack['sourceTokenId']]??null;
        if($source){
            $footprint=applicationTokenSpatialFootprint($source,['tokens'=>$tokens]);
            $spell=['version'=>1,'presetId'=>'thorn-wall-growth','sceneId'=>$scene,'layerId'=>$token['layerId'],'placement'=>'target','radiusCells'=>2,
                'source'=>['x'=>$footprint['x'],'y'=>$footprint['y'],'size'=>$footprint['size']??40],
                'targetPoint'=>['x'=>$token['x'],'y'=>$token['y'],'rotation'=>$token['terrainBarrier']['rotation']],
                'followAnchor'=>true,'anchorTokenId'=>$token['id']];
            $activity=$pending['activity']['payload']??applicationDomainPayload($records,'activity');
            appendApplicationAbilityCueEvent($activity,normalizeApplicationAbilityCompletionCue(['spell'=>$spell]),$scene,$token['layerId'],$source['id'],$attack['visibility']??'public','grow-'.$token['id']);
            queueOnlineDomainUpsert($pending,$records,'activity',$activity);
        }
    }
    return $attack;
}

function applicationPublicTerrainBarrier(array $token): array {
    if(applicationIsObstacleDoor($token))return ['version'=>1,'shape'=>'door','rotation'=>$token['terrainBarrier']['rotation'],'open'=>($token['terrainBarrier']['open']??false)===true,'locked'=>($token['terrainBarrier']['locked']??false)===true,'allowPeek'=>($token['terrainBarrier']['allowPeek']??false)===true,'widthCells'=>applicationObstacleDoorWidth($token),...(isset($token['terrainBarrier']['swungAt'])?['swungAt'=>$token['terrainBarrier']['swungAt']]:[]),...(isset($token['terrainBarrier']['pickBrokeAt'])?['pickBrokeAt'=>$token['terrainBarrier']['pickBrokeAt']]:[])];
    return ['version'=>1,...(applicationIsThornWall($token)?['shape'=>'thorn-wall','rotation'=>$token['terrainBarrier']['rotation'],...isset($token['terrainBarrier']['raisedAt'])?['raisedAt'=>$token['terrainBarrier']['raisedAt']]:[]]:[])];
}

function applicationThornWallPunishesAttack(array $wall, bool $sourcePlayerSide): bool {
    return applicationIsThornWall($wall) && applicationTerrainBarrierActive($wall)
        && ($wall['terrainBarrier']['playerSide']??false)!==$sourcePlayerSide;
}

function onlineInteractObstacleDoor(PDO $db,array &$records,array &$pending,array $table,array $identity,array $args,bool $isGm):array {
    $scene=$args['sceneId']??'';$doorId=$args['tokenId']??'';$layer=$args['layerId']??'';$action=$args['action']??'';
    if(!equipmentId($scene)||!in_array($layer,['ground','upper','basement'],true))throw new RuntimeException('Porte invalide.',400);
    if(!$isGm&&($scene!==onlineActiveSceneId($table)||($table['tacticalSync']['paused']??false)))throw new RuntimeException('La table ou la scène a changé.',409);
    if($action==='peek-end'){
        if($isGm)throw new RuntimeException('Cette vue est réservée au joueur.',403);
        foreach(onlineSceneTokensForLights($db,$records,$pending,$scene) as $token)if(($token['doorPeek']['accountId']??null)===$identity['id']){
            unset($token['doorPeek']);queueOnlineDomainUpsert($pending,$records,onlineTokenDomainKey($scene,$token['id']),$token);
        }
        return ['peeking'=>false];
    }
    if(!equipmentId($doorId))throw new RuntimeException('Porte invalide.',400);
    $mapKey='map:'.$scene;$tokenKey=onlineTokenDomainKey($scene,$doorId);
    $records=array_replace($records,applicationDomainRecords($db,[$mapKey,$tokenKey,'items']));
    $map=applicationDomainPayload($records,$mapKey);$door=applicationDomainPayload($records,$tokenKey);
    if(!$map||!applicationIsObstacleDoor($door)||!applicationTerrainBarrierActive($door)||onlineTokenLayerId($door,$map)!==$layer)throw new RuntimeException('Cette porte est indisponible.',404);
    $barrier=$door['terrainBarrier'];$key=null;$attempt=null;
    $items=$pending['items']['payload']??applicationDomainPayload($records,'items');
    $findKey=static function($keyId)use($items){
        foreach($items['instances']??[] as $item)if($item['id']===$keyId&&in_array($item['location']['kind']??'',['ground','inventory'],true))return $item;
        return null;
    };
    if($isGm&&$action==='peek')throw new RuntimeException('Cette vue est réservée au joueur.',403);
    if($isGm&&$action==='pick')throw new RuntimeException('Le crochetage est effectué par un pion joueur.',403);
    if(!$isGm){
        $sourceId=$args['sourceTokenId']??'';
        if(!equipmentId($sourceId)||!in_array($action,['open','close','pick','peek'],true)||($map['activeLayerId']??'ground')!==$layer||!empty($door['hidden']))throw new RuntimeException('Cette porte est hors d’accès.',403);
        $tokens=onlineSceneTokensForLights($db,$records,$pending,$scene);$map['tokens']=$tokens;
        $source=array_column($tokens,null,'id')[$sourceId]??[];
        if(!$source||!empty($source['hidden'])||($source['hp']??0)<=0||onlineTokenIsDead($source,true)
            ||onlineTokenControllerIdFromRecords($db,$records,$source)!==$identity['id']
            ||!onlineAttackTargetVisible($db,$records,$map,$door,$identity['id'],$scene))throw new RuntimeException('Cette porte est hors d’accès.',403);
        if(applicationMapDistanceInCells($source,$door,$map,true,true)>2+1e-9)throw new RuntimeException('Approchez un pion contrôlé à deux cases de la porte.',409);
        if($action==='open'&&empty($barrier['open'])&&!empty($barrier['locked'])){
            $key=$findKey($barrier['keyItemId']??null);
            if(($key['location']['kind']??'')!=='inventory'||($key['location']['characterId']??'')!==unitIdForToken($source))throw new RuntimeException('La porte est verrouillée. Ce pion doit porter la clé associée.',403);
        }
    }
    if($action==='peek'){
        if(!applicationKeyholeDoorAvailable($door))throw new RuntimeException('Cette porte ne permet pas de regarder par la serrure.',403);
        $occlusion=applicationActiveMapOcclusionState($map,$tokens);
        if(!applicationKeyholeGeometry($source,$door,$map,$occlusion))throw new RuntimeException('Placez votre pion d’un côté de la porte, à deux cases maximum.',409);
        foreach($tokens as $token)if($token['id']!==$source['id']&&($token['doorPeek']['accountId']??null)===$identity['id']){
            unset($token['doorPeek']);queueOnlineDomainUpsert($pending,$records,onlineTokenDomainKey($scene,$token['id']),$token);
        }
        $source['doorPeek']=['accountId'=>$identity['id'],'doorId'=>$doorId,'movement'=>applicationKeyholeMovementCheckpoint($source,$map),'door'=>applicationKeyholeDoorCheckpoint($door)];
        queueOnlineDomainUpsert($pending,$records,onlineTokenDomainKey($scene,$source['id']),$source);
        return ['peeking'=>true];
    }elseif($action==='pick'){
        $requestId=$args['requestId']??'';
        if(!is_string($requestId)||!preg_match('/^[A-Za-z0-9_-]{16,100}$/D',$requestId))throw new RuntimeException('Référence de crochetage invalide.',400);
        $receipts=$barrier['pickReceipts']??[];
        foreach($receipts as $receipt)if($receipt['id']===$requestId){
            if($receipt['sourceId']!==$source['id'])throw new RuntimeException('Cette tentative appartient à un autre pion.',409);
            return ['attempt'=>$receipt['attempt'],'deduplicated'=>true,'open'=>!empty($barrier['open']),'locked'=>!empty($barrier['locked'])];
        }
        if(empty($barrier['locked'])||!empty($barrier['open']))throw new RuntimeException('La porte doit être fermée et verrouillée pour être crochetée.',409);
        $store=$pending['devices']['payload']??applicationDomainPayload($records,'devices',dsEmpty());
        foreach($store['pendingRolls'] as $entry)if(($entry['type']??'')==='door-pick'&&$entry['sceneId']===$scene&&$entry['doorId']===$doorId)
            throw new RuntimeException('Ce crochetage attend la décision du MJ.',409);
        if(count($store['pendingRolls'])>=1000)throw new RuntimeException('Le MJ doit traiter les résultats en attente.',429);
        $reserved=array_column(array_filter($store['pendingRolls'],static fn($entry)=>($entry['type']??'')==='door-pick'),'toolId');
        $toolIndex=null;$unitId=unitIdForToken($source);
        foreach($items['instances']??[] as $index=>$item)if($item['templateId']===XAR_PASS_PARTOUT_TEMPLATE
            &&($item['location']['kind']??'')==='inventory'&&($item['location']['characterId']??'')===$unitId&&!in_array($item['id'],$reserved,true)){$toolIndex=$index;break;}
        if($toolIndex===null)throw new RuntimeException('Le pion doit avoir un Passe partout dans son inventaire.',409);
        $state=onlineDeviceState($db,$records,$pending);$owner=unitById($state,$unitId);
        $context=onlineDeviceContext($db,$records,$pending,$identity);
        $attempt=($context['roll'])($state,$owner,$source,'character-stat-dexterity',-($barrier['pickDifficulty']??0),'Crochetage · Dextérité');
        $roll=$attempt['roll'];
        if($attempt['pendingGmApproval']){
            $validationId='validation-'.randomToken(16);
            $store=$pending['devices']['payload']??applicationDomainPayload($records,'devices',dsEmpty());
            $store['pendingRolls'][]=['id'=>$validationId,'type'=>'door-pick','sceneId'=>$scene,'tokenId'=>$source['id'],'ownerId'=>$unitId,
                'accountId'=>$identity['id'],'requestId'=>$requestId,'doorId'=>$doorId,'toolId'=>$items['instances'][$toolIndex]['id'],
                'toolRevision'=>$items['instances'][$toolIndex]['revision'],'doorCheckpoint'=>applicationDoorPickCheckpoint($door),
                'sourceCheckpoint'=>applicationKeyholeMovementCheckpoint($source,$map),'cast'=>$attempt];
            queueOnlineDomainUpsert($pending,$records,'devices',$store);
            $attempt=[...$attempt,'pendingValidation'=>true,'validationId'=>$validationId];
        }elseif($attempt['success'])$barrier['locked']=false;
        else{
            $items['instances'][$toolIndex]['revision']++;
            $items['instances'][$toolIndex]['location']=['kind'=>'destroyed'];
            queueOnlineDomainUpsert($pending,$records,'items',normalizeItemStore($items));
            $barrier['pickBrokeAt']=max((int)floor(microtime(true)*1000),($barrier['pickBrokeAt']??0)+1);
        }
        $barrier['pickReceipts']=[...array_slice($receipts,-31),['id'=>$requestId,'sourceId'=>$source['id'],'attempt'=>$attempt]];
        onlineAppendAbilityRollActions($db,$records,$pending,$identity,$scene,[$roll]);
    }elseif(in_array($action,['open','close'],true)){
        $open=$action==='open';
        if(($barrier['open']??false)!==$open){$barrier['open']=$open;$barrier['swungAt']=max((int)floor(microtime(true)*1000),($barrier['swungAt']??0)+1);}
    }
    elseif(in_array($action,['lock','unlock'],true))$barrier['locked']=$action==='lock';
    elseif($action==='peek-permission'){
        $allow=$args['allowPeek']??null;
        if(!is_bool($allow))throw new RuntimeException('Choisissez si la serrure peut servir de judas.',400);
        if(($barrier['allowPeek']??false)!==$allow){
            $barrier['allowPeek']=$allow;
            $barrier['peekPermissionAt']=max((int)floor(microtime(true)*1000),($barrier['peekPermissionAt']??0)+1);
        }
    }elseif($action==='difficulty'){
        $difficulty=$args['difficulty']??null;
        if(!is_int($difficulty)||$difficulty<0||$difficulty>100)throw new RuntimeException('Choisissez un malus de 0 à 100.',400);
        $barrier['pickDifficulty']=$difficulty;
    }elseif($action==='resize'){
        $width=$args['widthCells']??null;
        if((!is_int($width)&&!is_float($width))||!is_finite((float)$width)||$width<.25||$width>12)throw new RuntimeException('Choisissez une largeur de 0,25 à 12 cases.',400);
        $barrier['widthCells']=$width;
    }elseif($action==='associate'){
        $id=$args['keyItemId']??'';
        if($id!==''&&!$findKey($id))throw new RuntimeException('Cet objet est introuvable ou a été consommé.',404);
        if($id==='')unset($barrier['keyItemId']);else $barrier['keyItemId']=$id;
    }elseif($action==='generate'||$action==='spawn-pick'){
        $pick=$action==='spawn-pick';
        $key=$pick?null:$findKey($barrier['keyItemId']??null);
        if(!$key){
            $recipient=$args['recipientCharacterId']??'';
            if($recipient!==''){
                if(!equipmentId($recipient))throw new RuntimeException('Cet inventaire est introuvable.',404);
                $characterKey='character:'.$recipient;
                $records=array_replace($records,applicationDomainRecords($db,[$characterKey]));
                if(!applicationDomainPayload($records,$characterKey))throw new RuntimeException('Cet inventaire est introuvable.',404);
            }
            $items=normalizeItemStore($items);
            $templateId=$pick?XAR_PASS_PARTOUT_TEMPLATE:'door-key-model-'.randomToken(15);
            $hasTemplate=in_array($templateId,array_column($items['templates'],'id'),true);
            if((!$hasTemplate&&count($items['templates'])>=200)||count($items['instances'])>=2000)throw new RuntimeException('La collection d’objets est pleine.',429);
            $itemId=($pick?'pass-partout-':'door-key-').randomToken(15);
            $level=$map['layers'][$layer]??$map;$grid=(float)($map['gridSize']??50);$angle=deg2rad($barrier['rotation']);
            $location=$recipient!==''?['kind'=>'inventory','characterId'=>$recipient]:['kind'=>'ground','sceneId'=>$scene,'layerId'=>$layer,
                'x'=>max(0,min(100,$door['x']+sin($angle)*$grid*100/max(1,$level['naturalWidth']??1600))),
                'y'=>max(0,min(100,$door['y']-cos($angle)*$grid*100/max(1,$level['naturalHeight']??900)))];
            $key=['id'=>$itemId,'templateId'=>$templateId,'revision'=>1,'location'=>$location];
            if(!$hasTemplate)$items['templates'][]=['id'=>$templateId,'kind'=>'object','name'=>$pick?'Passe partout':itemText('Clé · '.$door['name'],120),'category'=>'utility',
                ...($pick?['description'=>'Outil de crochetage. Sur un échec de Dextérité, il casse.']:[])];
            $items['instances'][]=$key;
            if(!$pick)$barrier['keyItemId']=$itemId;
            queueOnlineDomainUpsert($pending,$records,'items',normalizeItemStore($items));
        }
    }else throw new RuntimeException('Action de porte inconnue.',400);
    if($door['terrainBarrier']!==$barrier){
        $door['terrainBarrier']=$barrier;$door['_updatedAt']=(int)floor(microtime(true)*1000);
        queueOnlineDomainUpsert($pending,$records,$tokenKey,$door);
    }
    return ['open'=>($barrier['open']??false)===true,'locked'=>($barrier['locked']??false)===true,...($isGm&&$key?['key'=>$key]:[]),...($attempt?['attempt'=>$attempt]:[])];
}
