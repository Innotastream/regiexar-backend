<?php
declare(strict_types=1);
function applicationTerrainBarrier(mixed $value): ?array {
    return is_array($value)&&($value['version']??0)===1&&is_int($value['hp']??null)&&$value['hp']>0&&$value['hp']<=1000000000
        &&is_int($value['size']??null)&&$value['size']>=10&&$value['size']<=220?['version'=>1,'hp'=>$value['hp'],'size'=>$value['size']]:null;
}
function applicationTerrainBarrierActive(array $token): bool {return ($token['terrainBarrier']['version']??0)===1&&($token['hp']??0)>0&&($token['healthOverride']??'')!=='dead';}
function applicationTerrainBarrierPositioned(array $token):bool {
    foreach(['x','y'] as $key){$value=$token[$key]??null;if((!is_int($value)&&!is_float($value))||!is_finite((float)$value)||$value<0||$value>100)return false;}return true;
}
function applicationTerrainBarrierPointVisible(array $token,array $map,callable $visible):bool {
    if(!applicationTerrainBarrierPositioned($token))return false;
    if($visible($token['x'],$token['y']))return true;if(!applicationTerrainBarrierActive($token))return false;
    $level=$map['layers'][$token['layerId']??$map['activeLayerId']??'ground']??$map;$rx=(($token['size']??40)/2+2)*100/($level['naturalWidth']??$map['naturalWidth']??1600);$ry=(($token['size']??40)/2+2)*100/($level['naturalHeight']??$map['naturalHeight']??900);
    for($i=0;$i<24;$i++){$a=$i*M_PI/12;$x=$token['x']+cos($a)*$rx;$y=$token['y']+sin($a)*$ry;if($x>=0&&$x<=100&&$y>=0&&$y<=100&&$visible($x,$y))return true;}return false;
}
function applicationTerrainBarrierWalls(array $walls,array $map): array {
    $layer=$map['activeLayerId']??'ground';$level=$map['layers'][$layer]??$map;
    $nw=max(1,(float)($level['naturalWidth']??$map['naturalWidth']??1600));$nh=max(1,(float)($level['naturalHeight']??$map['naturalHeight']??900));
    $bytes=applicationWallMaskBytes($walls)??str_repeat("\0",(int)ceil($walls['width']*$walls['height']/8));$changed=false;
    foreach($map['tokens']??[] as $token){
        if(!applicationTerrainBarrierActive($token)||!applicationTerrainBarrierPositioned($token)||($token['layerId']??'ground')!==$layer)continue;$changed=true;
        $cx=$token['x']/100*($walls['width']-1);$cy=$token['y']/100*($walls['height']-1);$rx=$token['size']/2/$nw*($walls['width']-1);$ry=$token['size']/2/$nh*($walls['height']-1);
        for($y=max(0,(int)floor($cy-$ry));$y<=min($walls['height']-1,(int)ceil($cy+$ry));$y++)for($x=max(0,(int)floor($cx-$rx));$x<=min($walls['width']-1,(int)ceil($cx+$rx));$x++)
            if((($x-$cx)/$rx)**2+(($y-$cy)/$ry)**2<=1)applicationSetMaskBit($bytes,$y*$walls['width']+$x,true);
    }
    if($changed)$walls['mask']=rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');return $walls;
}
function applicationCreateTerrainBarrierToken(array $ability,array $point,string $id): array {
    $d=applicationTerrainBarrier($ability['terrainBarrier']??null);if(!$d)return [];
    return ['id'=>$id,'name'=>$ability['name']??'Mur','terrainBarrier'=>['version'=>1,'abilityId'=>$ability['id'],'minimumSize'=>$d['size']],
        'image'=>null,'color'=>'#658b3f','frameVariant'=>'creature','x'=>$point['x'],'y'=>$point['y'],'layerId'=>$point['layerId']??'ground',
        'size'=>$d['size'],'hp'=>$d['hp'],'maxHp'=>$d['hp'],'mana'=>0,'maxMana'=>0,'armorCategory'=>'none','armor'=>0,'magicArmorCategory'=>'none','magicArmor'=>0,
        'followCharacter'=>false,'characterId'=>null,'controllerPlayerId'=>null,'immovable'=>true,'mountable'=>false,'mountControllable'=>false,
        'hidden'=>false,'revealDetailsToPlayers'=>true,'abilities'=>[],'weaponAttacks'=>[],'stats'=>[],'conditions'=>[],'condition'=>'','_updatedAt'=>(int)floor(microtime(true)*1000)];
}
function applicationProtectTerrainBarrier(array $previous,array $next): array {
    if(empty($previous['terrainBarrier']))return $next;
    foreach(['x','y','layerId'] as $k)if(($next[$k]??null)!==($previous[$k]??null))throw new RuntimeException('Ce mur reste à son emplacement jusqu’à sa destruction.',409);
    return [...$next,'terrainBarrier'=>$previous['terrainBarrier'],'size'=>max($previous['terrainBarrier']['minimumSize']??10,$next['size']??$previous['size']),
        'immovable'=>true,'controllerPlayerId'=>null,'characterId'=>null,'followCharacter'=>false,'mountable'=>false,'mountControllable'=>false,
        'armorCategory'=>'none','armor'=>0,'magicArmorCategory'=>'none','magicArmor'=>0,'abilities'=>[],'weaponAttacks'=>[],'stats'=>[]];
}
function onlineApplyTerrainBarrier(PDO $connection,array &$records,array &$pending,array $attack): array {
    if(empty($attack['terrainBarrier'])||!empty($attack['terrainCreated'])||!in_array($attack['status']??'',['applied','blocked'],true))return $attack;
    $token=applicationCreateTerrainBarrierToken(['id'=>$attack['attackId'],'name'=>$attack['attackName'],'terrainBarrier'=>$attack['terrainBarrier']],$attack['targetPoint'], 'wall-'.substr(hash('sha256',$attack['id']),0,32));
    if($token){$key=onlineTokenDomainKey($attack['sceneId'],$token['id']);queueOnlineDomainUpsert($pending,$records,$key,$token);$ik='token-index:'.$attack['sceneId'];$index=$pending[$ik]['payload']??applicationDomainPayload($records,$ik,['order'=>[]]);if(!in_array($token['id'],$index['order']??[],true))$index['order'][]=$token['id'];queueOnlineDomainUpsert($pending,$records,$ik,$index);$attack['terrainCreated']=$token['id'];}return $attack;
}
