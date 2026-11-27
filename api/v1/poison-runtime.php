<?php
declare(strict_types=1);

const XAR_DROW_POISON_STAGES = ['Fatigue musculaire','Relâchement nerveux','Paralysie partielle','Insensibilité des membres','Paralysie complète'];

function applicationDrowCoatings(mixed $entries): array {
    if (!is_array($entries) || !array_is_list($entries) || count($entries) > 1000) dsError('Registre des enduits drows invalide.',400);
    $ids=[];
    foreach ($entries as $entry) {
        if (!is_array($entry) || !is_array($entry['equipmentItemIds']??null) || count($entry['equipmentItemIds'])>1
            || !array_is_list($entry['equipmentItemIds'])) dsError('Enduit drow invalide.',400);
        foreach (['id','unitId','weaponAttackId','sourceTokenId','sceneId'] as $field) {
            if (!is_string($entry[$field]??null) || preg_match('/^[A-Za-z0-9_-]{1,200}$/D',$entry[$field])!==1) dsError('Enduit drow invalide.',400);
        }
        foreach ($entry['equipmentItemIds'] as $id) if (!is_string($id)||preg_match('/^[A-Za-z0-9_-]{1,200}$/D',$id)!==1) dsError('Identité d’arme invalide.',400);
        if (isset($ids[$entry['id']])) dsError('Identité d’enduit drow en double.',400);
        $ids[$entry['id']]=true;
    }
    return $entries;
}

function applicationPoisonPotionTemplate(array $template): array {
    if (!in_array($template['kind']??'',['potion','object'],true) || !empty($template['equipment']) || !empty($template['sourceAbilityId'])) return $template;
    $active=$template['active']??[];
    if (!is_array($active) || !is_array($active['effects']??[])) return $template;
    $known=in_array($template['id']??'',['preset-poison-vial',APPLICATION_POISON_TEMPLATE_ID,APPLICATION_POISON_ALIAS_ID],true)
        || preg_match('/^(?:potion|fiole) (?:de |du )?poison(?: |$)/',onlineConditionKey($template['name']??''))===1;
    $effects=[];
    $converted=false;
    foreach ($active['effects']??[] as $effect) {
        if (($effect['timing']??'')==='on-hit' || !in_array($effect['kind']??'',['damage','dot'],true)) { $effects[]=$effect; continue; }
        if (($effect['kind']??'')==='dot' && applicationPeriodicDamageElement($effect)==='poison') {$converted=true;continue;}
        if (($effect['kind']??'')==='damage') {
            $remaining=[];
            foreach ($effect['components']??[] as $part) {
                if (applicationDamageElement($part['element']??$part['type']??null)==='poison') $converted=true;
                else $remaining[]=$part;
            }
            if ($known && $remaining===($effect['components']??[])) { $converted=true; continue; }
            if ($remaining!==[]) $effects[]=[...$effect,'components'=>$remaining];
            continue;
        }
        $effects[]=$effect;
    }
    if (!$known && (!$converted || ($template['kind']??'')!=='potion')) return $template;
    $effects[]=['kind'=>'dot','label'=>'Poison','family'=>'poison','damageType'=>'poison','formula'=>'1d20','turns'=>5];
    $template['active']=['version'=>1,'useLabel'=>'Utiliser',...$active,'effects'=>$effects];
    if (($template['potion']['hp']??0)<0) $template['potion']['hp']=0;
    return $template;
}

function applicationDrowCoatWeapon(array &$state, array $actor, array $args, array $context): array {
    $map=$state['map'];
    $scene=$state['activeSceneId'];
    $layer=$map['activeLayerId']??'ground';
    if (($args['sceneId']??'')!==$scene || ($args['layerId']??'')!==$layer) dsError('La scène ou le niveau a changé.',409);
    foreach (['sourceTokenId','targetTokenId','weaponAttackId'] as $key)
        if (!is_string($args[$key]??null)) dsError('Choisissez Varska et l’arme à enduire.',400);
    $source=$map['tokens'][dsIndex($map['tokens'],$args['sourceTokenId'])]??[];
    $target=$map['tokens'][dsIndex($map['tokens'],$args['targetTokenId'])]??[];
    if ($source===[] || $target===[] || ($source['layerId']??'ground')!==$layer || ($target['layerId']??'ground')!==$layer)
        dsError('Les deux pions doivent se trouver sur ce niveau.',409);
    $unitId=unitIdForToken($source);
    $owner=ownedUnit($state,$actor,$unitId);
    $source=dsToken($state,$owner,$args);
    dsTurn($state,$actor,$source);
    $donor=unitById($state,unitIdForToken($target),false)??$target;
    if ($unitId===unitIdForToken($target) || ($donor['resources']['hp']??$target['hp']??0)<=0 || onlineManualDeath($donor)
        || ($target['characterId']??'') !== 'character-X4kmpvnbF9rQgNGc' && ($target['id']??'') !== 'token-P-CK3ZtFhBidBd0rf3mlyA'
        || !applicationPoisonHeals($target,$state)) dsError('Choisissez Varska consciente sur la carte.',409);
    if ($actor['role']!=='gm' && (!empty($target['hidden']) || !(($context['visible']??static fn(array $point):bool=>true)($target))))
        dsError('Varska doit être visible pour préparer le poison drow.',403);
    if (dsDistance($source,$target,$map,false,true)>applicationSharedReachPolicy()['melee'])
        dsError('Approchez-vous à portée de mêlée de Varska.',409);
    $weapons=equipmentAttackRows($owner['weaponAttacks']??$source['weaponAttacks']??[],$source,$state);
    $weapon=$weapons[dsIndex($weapons,$args['weaponAttackId'])]??[];
    if ($weapon===[] || !empty($weapon['naturalWeapon']) || !empty($weapon['torchLightId'])
        || ($weapon['equipmentBinding']['kind']??'')==='set')
        dsError('Choisissez une arme utilisable.',409);
    $items=$weapon['equipmentItemIds']??[];
    $coatings=$state['devices']['drowCoatings']??[];
    foreach ($coatings as $entry) if ($entry['unitId']===$unitId &&
        ($items!==[] ? $entry['equipmentItemIds']===$items : $entry['equipmentItemIds']===[]&&$entry['weaponAttackId']===$weapon['id']))
        dsError('Cette arme est déjà enduite de poison drow.',409);
    if (count($coatings)>=1000) dsError('Le registre des enduits drows est plein.',429);
    $entry=['id'=>($context['id'])('drow-coat'),'unitId'=>$unitId,'weaponAttackId'=>$weapon['id'],
        'equipmentItemIds'=>$items,'sourceTokenId'=>$source['id'],'sceneId'=>$scene];
    $state['devices']['drowCoatings']=[...$coatings,$entry];
    return ['coated'=>true,'weaponName'=>$weapon['name']??'Arme','drowCoating'=>$entry];
}

function onlineConsumeDrowCoating(PDO $connection, array &$records, array &$pending, array $source, array $weapon): ?array {
    if ($weapon===[] || !empty($weapon['naturalWeapon']) || !empty($weapon['torchLightId'])
        || ($weapon['equipmentBinding']['kind']??'')==='set') return null;
    if (!isset($records['devices']) && !isset($pending['devices']))
        $records=array_replace($records,applicationDomainRecords($connection,['devices']));
    $store=$pending['devices']['payload']??applicationDomainPayload($records,'devices');
    $unitId=unitIdForToken($source);
    $itemIds=$weapon['equipmentItemIds']??[];
    foreach ($store['drowCoatings']??[] as $index=>$entry) {
        if ($entry['unitId']!==$unitId || ($itemIds!==[] ? $entry['equipmentItemIds']!==$itemIds
                : $entry['equipmentItemIds']!==[] || $entry['weaponAttackId']!==($weapon['id']??''))) continue;
        array_splice($store['drowCoatings'],$index,1);
        queueOnlineDomainUpsert($pending,$records,'devices',$store);
        return $entry;
    }
    return null;
}

function applicationDrowStatPenalty(array $source, array $store, string $scene): int {
    $characterId=applicationAbilityCastingOwner($source)['characterId'];
    $stacks=0;
    foreach ($store['timedConditions']??[] as $entry) {
        if (empty($entry['drowPoisonStacks']) || ($entry['remainingTurns']??0)<=0 || $entry['sceneId']!==$scene) continue;
        if ($entry['targetTokenId']===($source['id']??'') || $characterId!=='' && $entry['targetCharacterId']===$characterId)
            $stacks=max($stacks,$entry['drowPoisonStacks']);
    }
    return min(4,$stacks)*5;
}

function applicationPoisonSave(array &$state, array $target, string $scene, string $label): bool {
    $resist=applicationElementalResistanceTarget($target,$state,$scene);
    $threshold=applicationEffectiveElementalResistances($resist,[],'')['poison'];
    if ($threshold <= 0) return false;
    $rolled=$threshold>=100?null:random_int(1,100);
    $success=$rolled===null || $rolled<=$threshold;
    applicationRecordElementalConditionSave($state,$target,$scene,$label,['threshold'=>$threshold,'rolled'=>$rolled,'success'=>$success],$target);
    return $success;
}

function applicationPoisonGroup(array $effect): string {
    if (!empty($effect['drowPoisonStacks'])) return 'drow-poison';
    if (applicationPeriodicDamageElement($effect)!=='poison') return '';
    if (!empty($effect['sourceAbilityId'])) return 'ability:'.$effect['sourceAbilityId'];
    if (!empty($effect['sourceItemTemplateId'])) return 'item:'.$effect['sourceItemTemplateId'];
    $label=onlineConditionKey((string)($effect['label']??''));
    return 'poison:'.$label;
}

function applicationRemovePoisonGroup(array &$state, array $target, string $scene, string $group): void {
    $matches=static fn(array $entry): bool=>($entry['sceneId']??'')===$scene&&($entry['targetTokenId']??'')===$target['id']
        && applicationPoisonGroup($entry)===$group;
    $state['damageOverTime']=array_values(array_filter($state['damageOverTime']??[],static fn(array $entry):bool=>!$matches($entry)));
    $removed=[];
    $kept=[];
    foreach ($state['devices']['timedConditions']??[] as $entry) {
        if ($matches($entry) && applicationElementalSavingConditionLabel($entry['label']??null)===null) $removed[]=$entry;
        else $kept[]=$entry;
    }
    if ($removed===[]) return;
    $state['devices']['timedConditions']=$kept;
    foreach ($removed as $entry) {
        if (empty($entry['introduced'])) continue;
        $stillActive=false;
        foreach ($kept as $other) if ($other['sceneId']===$scene && $other['targetTokenId']===$target['id']
            && $other['label']===$entry['label'] && $other['remainingTurns']>0) {$stillActive=true;break;}
        if (!$stillActive) onlineDeviceCondition($state,$target['id'],$entry['label'],false);
    }
}

function applicationPoisonMayLand(array &$state, array $target, string $scene, array $effect): bool {
    if (applicationHasAllHarmImmunity($target, $state, $scene)) return false;
    $group=applicationPoisonGroup($effect);
    if ($group==='' || applicationPoisonHeals($target,$state)&&$group!=='drow-poison'&&($effect['kind']??'')!=='condition') return true;
    $threshold=applicationEffectiveElementalResistances(applicationElementalResistanceTarget($target,$state,$scene),[],'')['poison'];
    if ($threshold>=100) return false;
    if (!empty($state['initiative']['active']) || $threshold<=0 || !applicationPoisonSave($state,$target,$scene,$effect['label']??'Poison')) return true;
    applicationRemovePoisonGroup($state,$target,$scene,$group);
    return false;
}

function onlinePoisonTurnSaves(PDO $connection, array &$records, array &$pending, array $turns): void {
    if ($turns===[]) return;
    if (!isset($pending['devices'])&&!isset($records['devices'])) $records=array_replace($records,applicationDomainRecords($connection,['devices']));
    $activity=$pending['activity']['payload']??applicationDomainPayload($records,'activity');
    $store=$pending['devices']['payload']??applicationDomainPayload($records,'devices');
    $groupsByScene=[];
    $timed=array_filter($store['timedConditions']??[],static fn(array $entry):bool=>
        applicationElementalSavingConditionLabel($entry['label']??null)===null);
    foreach ([...$timed,...($activity['damageOverTime']??[])] as $entry) {
        $group=applicationPoisonGroup($entry);
        if ($group==='' || ($entry['remainingTurns']??0)<=0) continue;
        $groupsByScene[$entry['sceneId']][$entry['targetTokenId']][$group]=$group==='drow-poison'?'Poison drow':($entry['label']??'Poison');
    }
    foreach ($turns as [$scene,$tokenId,$initiative,$manualTurn]) {
        if ($manualTurn <= 0 && (($initiative['turnsStarted']??true)!==true
            || ($initiative['rewoundTurnSerial']??null)===($initiative['turnSerial']??0))) continue;
        $targets=$manualTurn>0?($groupsByScene[$scene]??[]):(isset($groupsByScene[$scene][$tokenId])?[$tokenId=>$groupsByScene[$scene][$tokenId]]:[]);
        if ($targets===[]) continue;
        $state=onlineDeviceSelectScene(onlineDeviceState($connection,$records,$pending),$scene);
        $tokens=array_column($state['map']['tokens']??[],null,'id');
        $combatId=$manualTurn>0?'':($initiative['combatId']??'');
        $round=$manualTurn>0?$manualTurn:max(1,(int)($initiative['round']??1));
        $clock=$state['devices']['poisonSaveTurns'][$scene]??[];
        if (($clock['combatId']??null)!==$combatId) $clock=['combatId'=>$combatId,'roundByTarget'=>[]];
        $changed=false;
        foreach ($targets as $id=>$groups) {
            $target=$tokens[$id]??null;
            if ($target===null || ($clock['roundByTarget'][$id]??0)>=$round
                || onlineInitiativeTokenIsDead($connection,$records,$target,$pending)
                || applicationEffectiveElementalResistances(applicationElementalResistanceTarget($target,$state,$scene),[],'')['poison']<=0) continue;
            $toxicBody=applicationPoisonHeals($target,$state);
            foreach ($groups as $group=>$label) {
                if ($toxicBody&&$group!=='drow-poison') continue;
                if (applicationPoisonSave($state,$target,$scene,$label)) applicationRemovePoisonGroup($state,$target,$scene,$group);
            }
            $clock['roundByTarget'][$id]=$round;
            $changed=true;
        }
        if (!$changed) continue;
        $clock['roundByTarget']=array_intersect_key($clock['roundByTarget'],$tokens);
        $state['devices']['poisonSaveTurns'][$scene]=$clock;
        onlineDeviceFlush($state,$records,$pending);
    }
}

function onlineApplyDrowCoatingHit(PDO $connection, array &$records, array &$pending, array $attack, array $target): void {
    if (empty($attack['drowCoating']) || empty($attack['hit']['outcome']['success']) || !empty($attack['echoParentId'])
        || applicationIsNonCombatant($target) || onlineInitiativeTokenIsDead($connection,$records,$target,$pending)) return;
    $scene=$attack['sceneId'];
    $state=onlineDeviceSelectScene(onlineDeviceState($connection,$records,$pending),$scene,$attack['layerId']??null);
    $timed=&$state['devices']['timedConditions'];
    $previous=null; $index=null;
    foreach ($timed as $i=>$condition) {
        if (($condition['sceneId']??'')===$scene && ($condition['targetTokenId']??'')===$target['id'] && !empty($condition['drowPoisonStacks'])) {
            $previous=$condition; $index=$i; break;
        }
    }
    if (!applicationPoisonMayLand($state,$target,$scene,['drowPoisonStacks'=>1,'label'=>'Poison drow'])) {
        onlineDeviceFlush($state,$records,$pending);
        return;
    }
    $max=($target['bestiaryProfile']??'')==='elite' || in_array($target['frameVariant']??'',['elite','boss'],true)?4:5;
    $stacks=min($max,1+($previous['drowPoisonStacks']??0));
    $label=XAR_DROW_POISON_STAGES[$stacks-1];
    $introduced=($previous['introduced']??false) && ($previous['label']??'')===$label;
    if (!$introduced) {
        if (!empty($previous['introduced'])) onlineDeviceCondition($state,$target['id'],$previous['label'],false);
        $introduced=onlineDeviceCondition($state,$target['id'],$label,true);
    }
    if ($index===null && count($timed)>=1000) dsError('Trop d’états temporisés actifs.',429);
    $turns=applicationPassiveEffectTurns($target,5,['family'=>'poison','harmful'=>true],$state);
    $condition=['id'=>$previous['id']??('condition-drow-'.randomToken(16)),'sceneId'=>$scene,'targetTokenId'=>$target['id'],
        'targetCharacterId'=>applicationAbilityCastingOwner($target)['characterId'],'label'=>$label,'family'=>'poison',
        'drowPoisonStacks'=>$stacks,'remainingTurns'=>$turns,'blocksActions'=>$stacks===5,'blocksMovement'=>$stacks===5,
        'clockCombatId'=>$state['initiative']['combatId']??'','lastTurnSerial'=>(int)($state['initiative']['turnSerial']??0),'introduced'=>$introduced];
    if ($index===null) $timed[]=$condition; else $timed[$index]=$condition;
    onlineDeviceFlush($state,$records,$pending);
}
