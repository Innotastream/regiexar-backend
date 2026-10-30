<?php
declare(strict_types=1);
require_once __DIR__.'/unit-authority.php';
require_once __DIR__.'/item-catalog.php';
require_once __DIR__.'/weapon-template-targeting.php';

function equipmentFailure(string $message,int $status=409):never{throw new RuntimeException($message,$status);}
function equipmentId(mixed $value):bool{return is_string($value)&&preg_match('/^[A-Za-z0-9_-]{1,180}$/D',$value)===1;}
function reconcileEquipmentEnchantments(array &$state):void {
    $activeId=$state['activeSceneId']??$state['activeScene']['id']??'';
    $scenes=[['id'=>$activeId,'map'=>$state['map']??[],'initiative'=>$state['initiative']??[]]];
    foreach($state['scenes']??[] as $scene)if(($scene['id']??'')!==$activeId)$scenes[]=['id'=>$scene['id'],'map'=>$scene['combat']['map']??[],'initiative'=>$scene['combat']['initiative']??[]];
    $effects=[];
    foreach($state['attackEnhancements']??[] as $effect){
        if(($effect['trigger']??'')!=='enchanted-weapon'){$effects[]=$effect;continue;}
        $item=null;foreach($state['items']['instances']??[] as $entry)if($entry['id']===($effect['enchantedItemId']??'')){$item=$entry;break;}
        if(!$item||in_array($item['location']['kind'],['destroyed','consumed'],true))continue;
        if($item['location']['kind']!=='inventory'){$effects[]=$effect;continue;}
        $matches=[];foreach($scenes as $scene)foreach($scene['map']['tokens']??[] as $token)if(equipmentUnitId($token)===$item['location']['characterId'])$matches[]=['scene'=>$scene,'token'=>$token];
        $match=null;foreach($matches as $candidate)if($candidate['scene']['id']===$effect['sceneId']&&$candidate['token']['id']===$effect['targetTokenId']){$match=$candidate;break;}
        if(!$match)foreach($matches as $candidate)if($candidate['scene']['id']===$effect['sceneId']){$match=$candidate;break;}
        $match??=$matches[0]??null;
        if($match){$scene=$match['scene'];$token=$match['token'];if($scene['id']!==$effect['sceneId']||$token['id']!==$effect['targetTokenId']){
            $effect['holder']=unitUsesCharacterSheet($token)?'character:'.$token['characterId']:'token:'.$token['id'];
            $effect['targetTokenId']=$token['id'];$effect['sceneId']=$scene['id'];$effect['combatId']=!empty($scene['initiative']['active'])?($scene['initiative']['combatId']??''):'';$effect['lastTurnSerial']=(int)($scene['initiative']['turnSerial']??0);
        }}$effects[]=$effect;
    }$state['attackEnhancements']=$effects;
}
function equipmentUnitId(array $unit):string{return ($unit['unitKind']??'')==='token'?(string)$unit['id']:(unitUsesCharacterSheet($unit)?(string)$unit['characterId']:(isset($unit['resources'])?(string)$unit['id']:unitIdForToken($unit)));}
function normalizeEquipment(mixed $value):?array {
    if($value===null)return null;
    if(!is_array($value)||($value['version']??1)!==1||!in_array($value['kind']??'', ['weapon','shield'],true)||!in_array($value['hands']??null,[1,2],true)||($value['kind']==='shield'&&$value['hands']!==1))equipmentFailure('Choisissez une arme à une ou deux mains, ou un bouclier à une main.',400);
    if($value['kind']==='shield')return ['version'=>1,'kind'=>'shield','hands'=>1];
    $formula=strtolower(preg_replace('/\s+/','',(string)($value['formula']??'')));
    if(!validApplicationAbilityFormula($formula)||!in_array($value['damageType']??'', ['physical','magical','ignore'],true)||!in_array($value['reach']??'', ['melee','reach','ranged'],true))equipmentFailure('Réglez les dégâts et la portée de cette arme.',400);
    return ['version'=>1,'kind'=>'weapon','hands'=>$value['hands'],'formula'=>$formula,'damageType'=>$value['damageType'],'reach'=>$value['reach'],'projectile'=>($value['projectile']??false)===true,'finesse'=>($value['finesse']??false)===true,...(array_key_exists('targetCount',$value)?['targetCount'=>normalizeEquipmentTargetCount($value['targetCount'])]:[])];
}
function normalizeEquipmentLoadouts(mixed $value=[]):array {
    if(!is_array($value)||count($value)>1000)equipmentFailure('Les équipements sont invalides.',400);
    $result=[];
    foreach($value as $unitId=>$loadout){
        if(!equipmentId((string)$unitId)||!is_array($loadout)||!is_array($loadout['sets']??null)||count($loadout['sets'])>30||!array_is_list($loadout['sets'])||!is_array($loadout['equippedItemIds']??null)||!array_is_list($loadout['equippedItemIds'])||count($loadout['equippedItemIds'])>2||count(array_unique($loadout['equippedItemIds']))!==count($loadout['equippedItemIds']))equipmentFailure('La configuration des mains est invalide.',400);
        foreach($loadout['equippedItemIds'] as $id)if(!equipmentId($id))equipmentFailure('La configuration des mains est invalide.',400);
        $sets=[];
        foreach($loadout['sets'] as $set){
            if(!is_array($set)||!equipmentId($set['id']??null)||!is_string($set['name']??null)||trim($set['name'])===''||strlen($set['name'])>480||!is_array($set['itemIds']??null)||!array_is_list($set['itemIds'])||count($set['itemIds'])!==2||count(array_unique($set['itemIds']))!==2)equipmentFailure('Un set réunit deux armes à une main distinctes.',400);
            foreach($set['itemIds'] as $id)if(!equipmentId($id))equipmentFailure('Un set réunit deux armes à une main distinctes.',400);
            if(in_array($set['id'],array_column($sets,'id'),true))equipmentFailure('Deux sets ont le même identifiant.',400);
            $sets[]=['id'=>$set['id'],'name'=>trim($set['name']),'itemIds'=>$set['itemIds']];
        }
        $active=$loadout['activeSetId']??'';
        if(!is_string($active)||($active!==''&&!in_array($active,array_column($sets,'id'),true)))equipmentFailure('Le set équipé n’existe plus.',400);
        $result[$unitId]=['sets'=>$sets,'equippedItemIds'=>$loadout['equippedItemIds'],'activeSetId'=>$active];
    }
    return $result;
}
function equipmentLoadout(array $items,string $unitId):array{return $items['equipmentLoadouts'][$unitId]??['sets'=>[],'equippedItemIds'=>[],'activeSetId'=>''];}
function equipmentLoadoutMatches(mixed $expected,array $current):bool {
    try{return normalizeEquipmentLoadouts(['comparison'=>$expected])['comparison']===normalizeEquipmentLoadouts(['comparison'=>$current])['comparison'];}
    catch(RuntimeException){return false;}
}
function equipmentInventory(array $items,string $unitId):array {
    $templates=array_column($items['templates']??[],null,'id');$result=[];
    foreach($items['instances']??[] as $item)if(($item['location']['kind']??'')==='inventory'&&($item['location']['characterId']??'')===$unitId){$template=$templates[$item['templateId']]??[];$equipment=$item['equipment']??$template['equipment']??null;if($equipment!==null){$normalized=normalizeEquipment($equipment);$targetCount=equipmentWeaponTargetCount($normalized,$template['equipment']??null,$item['templateId']);$result[]=['id'=>$item['id'],'name'=>$item['name']??$template['name'],...$normalized,...($targetCount!==null?['targetCount'=>$targetCount]:[])];}}
    return $result;
}
function carriedTorch(array $state,array $source):?array {
    $map=$state['map']??[];$layerId=$source['layerId']??$map['activeLayerId']??'ground';$layer=$map['layers'][$layerId]??$map;
    foreach($layer['lights']??$map['lights']??[] as $light)if(($light['enabled']??true)!==false&&($light['portable']??false)===true&&($light['carrierTokenId']??'')===($source['id']??'')&&($light['icon']??'torch')==='torch')return $light;
    return null;
}
function effectiveEquipment(array $items,string $unitId,bool $torch=false):array {
    $inventory=array_column(equipmentInventory($items,$unitId),null,'id');$loadout=equipmentLoadout($items,$unitId);$ids=$loadout['equippedItemIds'];
    foreach($loadout['sets'] as $set)if($set['id']===$loadout['activeSetId'])$ids=$set['itemIds'];
    $result=[];$remaining=2-($torch?1:0);foreach($ids as $index=>$id){$item=$inventory[$id]??null;if($item===null||$torch&&$index!==0||$item['hands']>$remaining)continue;$remaining-=$item['hands'];$result[]=$item;}
    return $result;
}
function validateEquipmentSlots(array $items,string $unitId,mixed $ids,bool $weaponsOnly=false):array {
    if(!is_array($ids)||!array_is_list($ids)||count($ids)>2||count(array_unique($ids))!==count($ids))equipmentFailure('Choisissez au maximum deux équipements distincts.',400);
    $inventory=array_column(equipmentInventory($items,$unitId),null,'id');$selected=[];$hands=0;
    foreach($ids as $id){$item=$inventory[$id]??null;if($item===null)equipmentFailure('Ces équipements doivent appartenir au porteur et tenir dans deux mains.');$selected[]=$item;$hands+=$item['hands'];}
    if($hands>2)equipmentFailure('Ces équipements doivent appartenir au porteur et tenir dans deux mains.');
    if($weaponsOnly&&(count($selected)!==2||array_filter($selected,static fn($item)=>$item['kind']!=='weapon'||$item['hands']!==1)!==[]))equipmentFailure('Un set réunit exactement deux armes à une main.');
    return $selected;
}
function normalizeEquipmentBinding(mixed $value):?array {
    if($value===null)return null;if(!is_array($value)||!in_array($value['kind']??'',['item','set'],true)||!equipmentId($value['id']??null))equipmentFailure('Choisissez une arme ou un set valide.',400);
    return ['kind'=>$value['kind'],'id'=>$value['id']];
}
function resolveEquipmentAttack(array $attack,array $source,array $state,bool $strict=true,bool $fromInventory=false):array {
    if(($attack['naturalWeapon']??false)===true)return [...$attack,'naturalWeapon'=>true,'equipmentItemIds'=>[]];
    $binding=normalizeEquipmentBinding($attack['equipmentBinding']??null);if($binding===null)return $attack;
    $unitId=equipmentUnitId($source);$items=$state['items']??[];$loadout=equipmentLoadout($items,$unitId);$torch=carriedTorch($state,$source)!==null;$ids=[];
    if($binding['kind']==='item')$ids=[$binding['id']];else foreach($loadout['sets'] as $set)if($set['id']===$binding['id'])$ids=$set['itemIds'];
    $available=array_column($fromInventory?equipmentInventory($items,$unitId):effectiveEquipment($items,$unitId,$torch),null,'id');$selected=[];foreach($ids as $id)if(($available[$id]['kind']??'')==='weapon')$selected[]=$available[$id];
    if($selected===[]||$fromInventory&&$binding['kind']==='set'&&(count($selected)!==2||array_filter($selected,static fn($item)=>$item['hands']!==1)!==[])){if(!$strict)return [...$attack,'disabled'=>true,'equipmentItemIds'=>[]];equipmentFailure($fromInventory?'L’arme ou le set requis doit être présent dans votre inventaire.':($torch?'Cette arme est suspendue pendant que vous portez la torche.':'L’arme ou le set de cette attaque doit être porté et équipé.'));}
    if(!$fromInventory&&$binding['kind']==='set'&&$loadout['activeSetId']!==$binding['id']){if(!$strict)return [...$attack,'disabled'=>true,'equipmentItemIds'=>[]];equipmentFailure('Équipez ce set avant de l’utiliser.');}
    $byType=[];foreach($selected as $item){$type=$item['damageType'];$byType[$type]=['type'=>$type,'formula'=>isset($byType[$type])?sumEffectFormulas([$byType[$type]['formula'],$item['formula']]):$item['formula']];}$parts=array_values($byType);$formula=sumEffectFormulas(array_column($parts,'formula'));
    if(!validApplicationAbilityFormula($formula))equipmentFailure('Les dégâts cumulés de ce set dépassent la formule autorisée.');
    $reach='ranged';foreach(['melee','reach','ranged'] as $kind)if(in_array($kind,array_column($selected,'reach'),true)){$reach=$kind;break;}
    $targetCount=!array_key_exists('targetCount',$attack)&&$binding['kind']==='item'&&count($selected)===1?($selected[0]['targetCount']??null):null;
    return [...$attack,'formula'=>$formula,'damageType'=>$parts[0]['type'],'damageComponents'=>$parts,'reach'=>$reach,'projectile'=>in_array(true,array_column($selected,'projectile'),true),'finesse'=>($selected[0]['finesse']??false)===true,'equipmentItemIds'=>array_column($selected,'id'),'equipmentNames'=>array_column($selected,'name'),'naturalWeapon'=>false,...($targetCount!==null?['targetCount'=>$targetCount]:[])];
}
function equipmentTorchAttack(array $state,array $source):?array {
    $light=carriedTorch($state,$source);return $light===null?null:['id'=>'torch-'.$light['id'],'name'=>$light['name']??'Torche','formula'=>'1d10+3','damageType'=>'magical','reach'=>'melee','projectile'=>false,'targetCount'=>1,'onHitConditions'=>[],'torchLightId'=>$light['id'],'equipmentItemIds'=>[],'naturalWeapon'=>false];
}
function equipmentAttackRows(array $attacks,array $source,array $state):array {
    $currentTorch=equipmentTorchAttack($state,$source);$rows=[];foreach($attacks as $attack){if(($attack['id']??'')===($currentTorch['id']??null))continue;$next=resolveEquipmentAttack($attack,$source,$state,false);if(empty($next['disabled']))$rows[]=$next;}
    $unitId=equipmentUnitId($source);$items=$state['items']??[];$loadout=equipmentLoadout($items,$unitId);
    foreach(effectiveEquipment($items,$unitId,carriedTorch($state,$source)!==null) as $item){
        if($item['kind']!=='weapon')continue;$found=false;foreach($rows as $row)if(($row['equipmentBinding']['kind']??'')==='item'&&($row['equipmentBinding']['id']??'')===$item['id'])$found=true;
        if(!$found)$rows[]=resolveEquipmentAttack(['id'=>'equipment-'.$item['id'],'name'=>$item['name'],'equipmentBinding'=>['kind'=>'item','id'=>$item['id']],'onHitConditions'=>[]],$source,$state);
    }
    foreach($loadout['sets'] as $set)if($set['id']===$loadout['activeSetId']){
        $found=false;foreach($rows as $row)if(($row['equipmentBinding']['kind']??'')==='set'&&($row['equipmentBinding']['id']??'')===$set['id'])$found=true;
        if(!$found){$next=resolveEquipmentAttack(['id'=>'equipment-'.$set['id'],'name'=>$set['name'],'equipmentBinding'=>['kind'=>'set','id'=>$set['id']],'onHitConditions'=>[]],$source,$state,false);if(empty($next['disabled']))$rows[]=$next;}
    }
    $torch=equipmentTorchAttack($state,$source);if($torch!==null&&!in_array($torch['id'],array_column($rows,'id'),true))$rows[]=$torch;
    return $rows;
}
function enchantableWeapons(array $state,array $source):array {return array_values(array_filter(effectiveEquipment($state['items']??[],equipmentUnitId($source),carriedTorch($state,$source)!==null),static fn($item)=>$item['kind']==='weapon'&&!$item['projectile']));}
function abilityEquipmentAttackRows(array $attacks,array $source,array $state):array {
    $unitId=equipmentUnitId($source);$items=$state['items']??[];$definitions=null;
    foreach($state['characters']??[] as $character)if(($character['id']??'')===$unitId){$definitions=$character['weaponAttacks']??null;break;}
    $definitions??=$source['abilityWeaponAttacks']??$attacks;$rows=[];
    $add=static function(array $attack)use(&$rows,$source,$state):void{$resolved=resolveEquipmentAttack($attack,$source,$state,false,true);if(empty($resolved['disabled'])&&!isset($rows[$resolved['id']]))$rows[$resolved['id']]=$resolved;};
    foreach(equipmentAttackRows($definitions,$source,$state) as $attack)$add($attack);
    foreach($definitions as $attack)$add($attack);
    foreach(equipmentInventory($items,$unitId) as $item){
        if($item['kind']!=='weapon')continue;$found=false;foreach($rows as $row)if(($row['equipmentBinding']['kind']??'')==='item'&&($row['equipmentBinding']['id']??'')===$item['id'])$found=true;
        if(!$found)$add(['id'=>'equipment-'.$item['id'],'name'=>$item['name'],'equipmentBinding'=>['kind'=>'item','id'=>$item['id']],'onHitConditions'=>[]]);
    }
    foreach(equipmentLoadout($items,$unitId)['sets'] as $set){
        $found=false;foreach($rows as $row)if(($row['equipmentBinding']['kind']??'')==='set'&&($row['equipmentBinding']['id']??'')===$set['id'])$found=true;
        if(!$found)$add(['id'=>'equipment-'.$set['id'],'name'=>$set['name'],'equipmentBinding'=>['kind'=>'set','id'=>$set['id']],'onHitConditions'=>[]]);
    }
    return array_values($rows);
}
function requireEnchantedWeapon(array $state,array $recipient,string $itemId):array {
    foreach(enchantableWeapons($state,$recipient) as $item)if($item['id']===$itemId)return ['enchantedItemId'=>$item['id'],'enchantedItemName'=>$item['name']];
    equipmentFailure('Choisissez une arme fabriquée portée et utilisable par le bénéficiaire. Les armes naturelles et les projectiles sont exclus.');
}
function requireEnchantmentInstance(array $state,array $enchantment):array {
    $item=null;foreach($state['items']['instances']??[] as $entry)if($entry['id']===($enchantment['enchantedItemId']??'')){$item=$entry;break;}
    $template=[];foreach($state['items']['templates']??[] as $entry)if($entry['id']===($item['templateId']??'')){$template=$entry;break;}
    $equipment=$item['equipment']??$template['equipment']??[];
    if(!$item||in_array($item['location']['kind'],['destroyed','consumed'],true)||($equipment['kind']??'')!=='weapon'||($equipment['projectile']??false)===true)
        equipmentFailure('L’arme choisie pour cet enchantement n’est plus disponible. Le MJ peut refuser la demande pour restituer son coût.');
    return $enchantment;
}
function enchantmentOnUnit(array $effect,array $source,array $state):bool {
    foreach($state['items']['instances']??[] as $item)if($item['id']===($effect['enchantedItemId']??'')&&$item['location']['kind']==='inventory'&&$item['location']['characterId']===equipmentUnitId($source))return true;
    return false;
}
function cloneEquipment(array &$items,string $sourceId,string $targetId,array $itemMap,array $attacks,callable $makeId):array {
    if(isset($items['inventoryCatalog'][$sourceId]))$items['inventoryCatalog'][$targetId]=$items['inventoryCatalog'][$sourceId];
    $loadout=equipmentLoadout($items,$sourceId);$sets=[];foreach($loadout['sets'] as $set)$sets[$set['id']]=$makeId('set');
    if(isset($items['equipmentLoadouts'][$sourceId])){
        $next=$loadout;$next['sets']=array_map(static fn($set)=>[...$set,'id'=>$sets[$set['id']],'itemIds'=>array_map(static fn($id)=>$itemMap[$id]??$id,$set['itemIds'])],$loadout['sets']);
        $next['equippedItemIds']=array_map(static fn($id)=>$itemMap[$id]??$id,$loadout['equippedItemIds']);$next['activeSetId']=$sets[$loadout['activeSetId']]??'';$items['equipmentLoadouts'][$targetId]=$next;
    }
    return array_map(static function($attack)use($sets,$itemMap){$binding=$attack['equipmentBinding']??null;if($binding===null)return $attack;$map=$binding['kind']==='set'?$sets:$itemMap;$attack['equipmentBinding']=[...$binding,'id'=>$map[$binding['id']]??$binding['id']];return $attack;},$attacks);
}
function onlineEquipmentState(PDO $connection,array &$records,array $pending,string $sceneId,?array $map=null):array {
    $records=array_replace($records,applicationDomainRecords($connection,['items','map:'.$sceneId]));
    return ['items'=>$pending['items']['payload']??applicationDomainPayload($records,'items'),'map'=>$map??$pending['map:'.$sceneId]['payload']??applicationDomainPayload($records,'map:'.$sceneId)];
}
function breakAttackTorch(array &$state,array &$attack,bool $approved=false):bool {
    $id=$attack['echoAttackContext']['torchLightId']??'';
    if($id===''||!empty($attack['torchBroken'])||($attack['hit']['outcome']['code']??'')!=='critical-failure'||in_array($attack['status']??'',['rejected','cancelled'],true)||(($attack['status']??'')==='pending'&&!$approved))return false;
    $found=false;
    foreach($state['map']['layers']??[] as $layerId=>$layer){$next=array_values(array_filter($layer['lights']??[],static fn($light)=>($light['id']??'')!==$id));if(count($next)!==count($layer['lights']??[])){$state['map']['layers'][$layerId]['lights']=$next;$found=true;}}
    $next=array_values(array_filter($state['map']['lights']??[],static fn($light)=>($light['id']??'')!==$id));if(count($next)!==count($state['map']['lights']??[])){$state['map']['lights']=$next;$found=true;}
    if($found)$attack['torchBroken']=true;return $found;
}
function onlineBreakAttackTorch(PDO $connection,array &$records,array &$pending,array &$attack,bool $approved=false):void {
    if(empty($attack['echoAttackContext']['torchLightId']))return;
    $sceneId=(string)$attack['sceneId'];$state=onlineEquipmentState($connection,$records,$pending,$sceneId);
    if(breakAttackTorch($state,$attack,$approved))queueOnlineDomainUpsert($pending,$records,'map:'.$sceneId,$state['map']);
}
function applyEquipmentCommand(array $state,array $items,array $identity,string $command,array $args,callable $owned):array {
    if(in_array($command,['item.equipment.create','item.equipment.update'],true)&&($identity['role']??'')!=='gm')equipmentFailure('La création et la modification des équipements sont réservées au MJ. Vous pouvez ajouter un objet rapide.',403);
    $unit=$owned($args['characterId']??'');$unitId=$unit['id'];$previous=equipmentLoadout($items,$unitId);
    if($command==='item.equipment.create'){
        if(count($items['templates'])>=200||count($items['instances'])>=2000)equipmentFailure('La collection d’objets est pleine.',429);
        if(!equipmentId($args['templateId']??null)||!equipmentId($args['itemId']??null)||in_array($args['templateId'],array_column($items['templates'],'id'),true)||in_array($args['itemId'],array_column($items['instances'],'id'),true))equipmentFailure('Cet équipement existe déjà.');
        $name=trim(itemText($args['name']??'',120));if($name==='')equipmentFailure('Donnez un nom à cet équipement.',400);
        if(namedItemTemplate($items,$name))equipmentFailure('Un modèle porte déjà ce nom. Ajoutez cet équipement depuis la bibliothèque ou précisez un nom différent.',409);
        $equipment=normalizeEquipment($args['equipment']??null);if($equipment===null)equipmentFailure('Définissez cet équipement.',400);
        $items['templates'][]=['id'=>$args['templateId'],'kind'=>'object','name'=>$name,'description'=>'','image'=>'','equipment'=>$equipment];
        $item=['id'=>$args['itemId'],'templateId'=>$args['templateId'],'revision'=>1,'equipment'=>$equipment,'location'=>['kind'=>'inventory','characterId'=>$unitId]];$items['instances'][]=$item;return ['items'=>$items,'result'=>['item'=>$item]];
    }
    if($command==='item.equipment.update'){
        $index=null;foreach($items['instances'] as $i=>$item)if($item['id']===($args['itemId']??'')&&$item['location']['kind']==='inventory'&&$item['location']['characterId']===$unitId)$index=$i;
        if($index===null)equipmentFailure('Cet équipement n’est plus dans cet inventaire.',404);
        $item=$items['instances'][$index];if($item['revision']!==($args['expectedItemRevision']??null))equipmentFailure('Cet équipement a été modifié. Rouvrez-le avant de continuer.');
        if(!in_array($item['id'],array_column(equipmentInventory($items,$unitId),'id'),true))equipmentFailure('Cet objet n’est pas un équipement.',400);
        $name=trim(itemText($args['name']??'',120));if($name==='')equipmentFailure('Donnez un nom à cet équipement.',400);
        $equipment=normalizeEquipment($args['equipment']??null);if($equipment===null)equipmentFailure('Définissez cet équipement.',400);
        $item['name']=$name;$item['equipment']=$equipment;$item['revision']++;$items['instances'][$index]=$item;return ['items'=>$items,'result'=>['item'=>$item]];
    }
    if(!equipmentLoadoutMatches($args['expectedLoadout']??null,$previous))equipmentFailure('Les équipements ont changé. Rouvrez leur configuration.');
    $next=$previous;
    if($command==='item.equipment.set'){
        validateEquipmentSlots($items,$unitId,$args['itemIds']??null,true);
        $name=trim(itemText($args['name']??'',120));if(!equipmentId($args['setId']??null)||$name==='')equipmentFailure('Donnez un nom et un identifiant à ce set.',400);
        $set=['id'=>$args['setId'],'name'=>$name,'itemIds'=>$args['itemIds']];$found=false;foreach($next['sets'] as &$entry)if($entry['id']===$set['id']){$entry=$set;$found=true;}unset($entry);if(!$found)$next['sets'][]=$set;
    }elseif($command==='item.equipment.set.delete'){
        if(!in_array($args['setId']??'',array_column($next['sets'],'id'),true))equipmentFailure('Ce set n’existe plus.',404);
        $next['sets']=array_values(array_filter($next['sets'],static fn($set)=>$set['id']!==$args['setId']));if($next['activeSetId']===$args['setId'])$next['activeSetId']='';
    }elseif($command==='item.equipment.equip'){
        $set=null;foreach($next['sets'] as $candidate)if($candidate['id']===($args['setId']??''))$set=$candidate;
        if(!empty($args['setId'])&&$set===null)equipmentFailure('Ce set n’existe plus.',404);
        $ids=$set['itemIds']??$args['itemIds']??null;validateEquipmentSlots($items,$unitId,$ids,$set!==null);$next['activeSetId']=$set['id']??'';$next['equippedItemIds']=$ids;
    }else equipmentFailure('Commande d’équipement inconnue.',400);
    $items['equipmentLoadouts']=normalizeEquipmentLoadouts([...($items['equipmentLoadouts']??[]),$unitId=>$next]);return ['items'=>$items,'result'=>['loadout'=>$next]];
}
