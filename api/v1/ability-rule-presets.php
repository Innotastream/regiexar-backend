<?php
declare(strict_types=1);
require_once __DIR__.'/fixed-structure-size.php';
require_once __DIR__.'/ability-description-presets.php';
require_once __DIR__.'/path-strike-presets.php';
const XAR_PRECISION_SERPENTAIRE_ID='ability-b7c57dd7-7a09-45aa-b5a0-7e1928a06066';
function applicationSimpleAbilityPresets(): array
{
    static $presets = null;
    return $presets ??= json_decode((string)file_get_contents(__DIR__.'/ability-simple-presets.json'),true,512,JSON_THROW_ON_ERROR);
}
function applicationConfiguredOppositionAbility(array $source): array {
    static $presets=null;$presets??=json_decode((string)file_get_contents(__DIR__.'/ability-rule-presets.json'),true,512,JSON_THROW_ON_ERROR);
    $preset=$presets[$source['id']??'']??null;
    return $preset!==null&&($source['effect']??'')==='movement'&&(!isset($source['opposition'])||($source['opposition']['statId']??'')==='character-stat-dexterity')?[...$source,...$preset,'onHitConditions'=>[]]:$source;
}
function applicationUpgradeAbilityWorkflow(mixed $workflow, array $upgrade): ?array
{
    if (!is_array($workflow) || !is_array($workflow['steps'] ?? null)) return null;
    $changed = false;
    foreach ($workflow['steps'] as $index => $step) {
        if (!is_array($step)) continue;
        foreach ($upgrade['steps'] ?? [] as $patch) {
            $matches = true;
            foreach ($patch['match'] as $key => $value) if (($step[$key] ?? null) !== $value) { $matches = false; break; }
            if (!$matches) continue;
            $step = array_replace($step, $patch['values']);
            if (isset($patch['damageSpendFormula'])) {
                foreach ($step['spendOptions'] ?? [] as $optionIndex => $option) {
                    if (($option['effect']['kind'] ?? '') === 'damage') $step['spendOptions'][$optionIndex]['effect']['formula'] = $patch['damageSpendFormula'];
                }
            }
            $workflow['steps'][$index] = $step;
            $changed = true;
        }
    }
    return $changed ? $workflow : null;
}
function applicationConfiguredActiveAbilityWorkflow(array $execution, array $workflow): array
{
    $rulePreset = applicationAbilityRulePreset($execution['rulePreset'] ?? null);
    $preset = applicationSimpleAbilityPresets()[$execution['abilityId'] ?? ''] ?? null;
    if (($execution['status'] ?? '') === 'active' && !empty($preset['workflowUpgrade']['activeSnapshot'])
        && !(($rulePreset['id'] ?? '') === $preset['presetId'] && $rulePreset['revision'] >= $preset['revision'])) {
        $patched = applicationUpgradeAbilityWorkflow($workflow, $preset['workflowUpgrade']);
        if ($patched !== null) return [$patched, ['id'=>$preset['presetId'], 'revision'=>$preset['revision']]];
    }
    return [$workflow, $rulePreset];
}
function applicationSacredCircleDescription(array $effect): string
{
    $aura = $effect['aura'];
    $bonuses = $effect['bonuses'] ?? [];
    $turns = (int)($effect['turns'] ?? 0);
    $duration = $turns === 1 ? '1 tour' : "$turns tours";
    $recipients = [];
    foreach (['affectCaster'=>'au lanceur','affectAllies'=>'aux alliés','affectEnemies'=>'aux ennemis'] as $key=>$label) {
        if (!empty($aura[$key])) $recipients[] = $label;
    }
    $points = [];
    foreach (['physicalResistance'=>'physique','magicalResistance'=>'magique','fireResistance'=>'feu',
        'iceResistance'=>'glace','poisonResistance'=>'poison','bleedingResistance'=>'saignement'] as $key=>$label) {
        $amount = $bonuses[$key] ?? 0;
        if ($amount) $points[] = $label.' '.($amount > 0 ? '+' : '').$amount;
    }
    $attacks = ($effect['trigger'] ?? '') === 'all-attacks' ? 'les attaques et les compétences offensives' : 'les attaques concernées';
    $resultBonus = (int)($bonuses['attackResultBonus'] ?? 0);
    $damage = (int)($effect['formula'] ?? 0);
    return implode("\n\n", array_filter([
        "Pendant $duration du lanceur, un cercle de {$aura['radiusCells']} cases de rayon suit ses déplacements.",
        $recipients ? 'Les bonus profitent '.implode(' et ', $recipients).' dans le cercle, sur le même étage. Sortir du cercle suspend les bonus. Y revenir les réactive.' : '',
        $resultBonus !== 0 ? 'Le résultat du d100 est '.($resultBonus > 0 ? 'réduit' : 'augmenté').' de '.abs($resultBonus)." pour $attacks." : '',
        $damage > 0 ? '+'.$damage.' dégâts'.(($effect['damageType'] ?? '') === 'inherit' ? ' du même type que l’attaque' : '').' sur une touche.' : '',
        $points ? 'Résistances en points de pourcentage — '.implode(', ', $points).'.' : '',
    ]));
}
function applicationConfiguredAbilityRules(array $source): array {
    $presets = applicationSimpleAbilityPresets();
    $configured=applicationConfiguredStructureSizing(applicationConfiguredPathStrikeAbility(applicationConfiguredInclusiveFatigue(applicationConfiguredOppositionAbility($source))));$preset=$presets[$source['id']??'']??null;
    if (($preset['presetId'] ?? '') === 'rotatable-thorn-wall' && ($configured['effect'] ?? 'damage') === 'damage') {
        $applied=applicationAbilityRulePreset($source['rulePreset']??null);
        $alreadyRotatable=($applied['id']??'')===$preset['presetId'];
        if($alreadyRotatable&&$applied['revision']>=$preset['revision'])return $configured;
        $parts=is_array($configured['damageComponents']??null)&&$configured['damageComponents']!==[]
            ?$configured['damageComponents']:$preset['values']['damageComponents'];
        foreach($parts as $index=>$part){
            if(!is_array($part)||!empty($part['element'])||!empty($part['damageElement']))continue;
            if($index===0&&($part['type']??'')==='magical')$parts[$index]['type']='physical';
            if($index===1&&($part['type']??'')==='ignore')$parts[$index]['type']='poison';
        }
        $dot=($alreadyRotatable?($configured['damageOverTime']??null):null)??$preset['values']['damageOverTime'];
        if(($dot['damageType']??'')==='ignore'&&empty($dot['damageElement']))$dot['damageType']='poison';
        $description=$alreadyRotatable&&is_string($configured['description']??null)&&$configured['description']!==''
            ?str_replace('1d30+10 dégâts magiques','1d30+10 dégâts physiques',$configured['description']):$preset['values']['description'];
        return [...$configured,...($alreadyRotatable?[]:$preset['values']),'damageType'=>'physical','damageComponents'=>$parts,
            'damageOverTime'=>$dot,'description'=>$description,'rulePreset'=>['id'=>$preset['presetId'],'revision'=>$preset['revision']]];
    }
    if($preset===null)foreach($presets as $candidate){$match=$candidate['match']??null;if(is_array($match)&&($source['effect']??null)===($match['effect']??null)&&($source['tacticalEffect']['version']??null)===($match['tacticalEffect']['version']??null)&&($source['tacticalEffect']['kind']??null)===($match['tacticalEffect']['kind']??null)){$preset=$candidate;break;}}
    if(($preset['retired']??false)===true)return $configured;
    if(isset($preset['enhancementAuraUpgrade'])&&($source['effect']??null)===($preset['effect']??null)&&($configured['attackEnhancement']['mode']??null)===($preset['enhancementMode']??null)&&is_array($configured['attackEnhancement']['aura']??null)){
        $applied=applicationAbilityRulePreset($source['rulePreset']??null);
        $includedCaster=($applied['id']??null)===$preset['presetId'];
        if($includedCaster&&$applied['revision']>=$preset['revision'])return $configured;
        $enhancement=$configured['attackEnhancement'];
        $upgraded=$includedCaster&&$applied['revision']>=$preset['enhancementBonusRevision']?$enhancement:[...$enhancement,
            ...($includedCaster?[]:['aura'=>[...$enhancement['aura'],...$preset['enhancementAuraUpgrade']]]),
            'bonuses'=>[...$preset['enhancementBonusUpgrade'],...(is_array($enhancement['bonuses']??null)?$enhancement['bonuses']:[])]];
        $previous=(string)($configured['description']??'');
        if(!$includedCaster)foreach($preset['descriptionReplacements'] as [$from,$to])$previous=str_replace($from,$to,$previous);
        $previous=trim(str_replace([$preset['enhancementLegacyDescriptionNote'],'Le lanceur et les alliés présents dans le cercle gagnent les bonus.','Le lanceur bénéficie lui aussi de ces bonus.'],'',$previous));
        $description=implode("\n\n",array_filter([applicationSacredCircleDescription($upgraded),$previous]));
        return [...$configured,'attackEnhancement'=>$upgraded,'description'=>$description,'rulePreset'=>['id'=>$preset['presetId'],'revision'=>$preset['revision']]];
    }
    if(isset($preset['workflowUpgrade'])&&($source['effect']??null)===($preset['effect']??null)){
        $applied=applicationAbilityRulePreset($source['rulePreset']??null);
        if($applied!==null&&$applied['id']===$preset['presetId']&&$applied['revision']>=$preset['revision'])return $configured;
        $workflow=applicationUpgradeAbilityWorkflow($configured['workflow']??null,$preset['workflowUpgrade']);
        if($workflow===null)return $configured;
        $description=$configured['description']??null;
        if(is_string($description))foreach($preset['workflowUpgrade']['descriptionReplacements']??[] as [$from,$to])$description=str_replace($from,$to,$description);
        return [...$configured,'workflow'=>$workflow,...(is_string($description)?['description'=>$description]:[]),'rulePreset'=>['id'=>$preset['presetId'],'revision'=>$preset['revision']]];
    }

    if(isset($preset['attackEnhancementTrigger'])&&($source['effect']??'')===($preset['effect']??'')&&($source['attackEnhancement']['mode']??'')==='on-attack-dot')return [...$configured,'attackEnhancement'=>applicationConfiguredAttackEnhancement((string)$source['id'],$source['attackEnhancement'])];
    if(isset($preset['values'])&&($source['effect']??'damage')===($preset['effect']??'damage')){
        $applied=applicationAbilityRulePreset($source['rulePreset']??null);
        if(!empty($preset['deviceUpgradeOnly'])&&($configured['device']['kind']??'')==='beam'){return [...$configured,'device'=>[...$preset['values']['device'],...$configured['device']],...(!empty($preset['values']['description'])&&($configured['device']['unlimitedRange']??true)&&($configured['device']['affectUnseen']??true)&&!($applied!==null&&$applied['id']===$preset['presetId']&&$applied['revision']>=$preset['revision'])?['description'=>$preset['values']['description']]:[]),...(!($applied!==null&&$applied['id']===$preset['presetId']&&$applied['revision']>=$preset['revision'])?['rulePreset'=>['id'=>$preset['presetId'],'revision'=>$preset['revision']]]:[])];}
        if(!empty($preset['upgradeOnly'])&&$applied!==null&&$applied['id']===$preset['presetId']&&$applied['revision']<$preset['revision'])return [...$configured,...$preset['upgradeValues'],'rulePreset'=>['id'=>$preset['presetId'],'revision'=>$preset['revision']]];
        if(!empty($preset['targetingUpgradeOnly'])&&!($applied!==null&&$applied['id']===$preset['presetId']&&$applied['revision']>=$preset['revision']))return [...$configured,'damageTargeting'=>['affectCaster'=>true,'affectAllies'=>true,'affectEnemies'=>true,...($preset['targetingDefaults']??[]),...($configured['damageTargeting']??[]),...$preset['values']['damageTargeting']],...(!empty($preset['values']['description'])?['description'=>$preset['values']['description']]:[]),'rulePreset'=>['id'=>$preset['presetId'],'revision'=>$preset['revision']]];
        if($applied!==null&&$applied['id']===$preset['presetId']&&$applied['revision']<$preset['revision']&&!empty($preset['rangeUpgradeOnly'])){
            $upgraded=[...$configured,...($preset['upgradeValues']??[]),'rulePreset'=>['id'=>$preset['presetId'],'revision'=>$preset['revision']]];
            if(!isset($preset['rangeUpgradeRevision'])||$applied['revision']<$preset['rangeUpgradeRevision']){
                $upgraded['rangeCells']=$preset['values']['rangeCells'];
                if(isset($source['workflow'])){$upgraded['workflow']=$source['workflow'];foreach($upgraded['workflow']['steps'] as &$movement)if(!empty($movement['charge'])){$movement['maximumCells']=$preset['values']['rangeCells'];foreach($upgraded['workflow']['steps'] as &$target)if($target['id']===$movement['charge']['targetStepId']){$target['rangeCells']=$preset['values']['rangeCells'];unset($target['rangeMeters']);}unset($target);}unset($movement);}
                if(!empty($preset['rangeDescriptionFrom'])&&str_starts_with($upgraded['description']??'',$preset['rangeDescriptionFrom']))$upgraded['description']=$preset['rangeDescriptionTo'].substr($upgraded['description'],strlen($preset['rangeDescriptionFrom']));
            }
            return $upgraded;
        }
        return $applied!==null&&$applied['id']===$preset['presetId']&&$applied['revision']>=$preset['revision']?$configured:[...$configured,...$preset['values'],'rulePreset'=>['id'=>$preset['presetId'],'revision'=>$preset['revision']]];
    }
    return $preset!==null&&!isset($preset['values'])&&!isset($preset['attackEnhancementTrigger'])&&!isset($preset['enhancementAuraUpgrade'])&&($source['effect']??'damage')==='damage'&&!array_key_exists('rangeCells',$source)&&!array_key_exists('infusion',$source)?[...$configured,...$preset]:$configured;
}
function applicationConfiguredInclusiveFatigue(array $source):array{static $presets=null;$presets??=json_decode((string)file_get_contents(__DIR__.'/ability-inclusive-fatigue-presets.json'),true,512,JSON_THROW_ON_ERROR);$preset=$presets[$source['id']??'']??null;if($preset===null)return $source;if(is_string($source['description']??null))$source['description']=str_replace($preset['inclusive'].' fatigue (passif compris)',$preset['base'].' fatigue de base',$source['description']);if(($source['fatigueCostIncludesPassive']??null)===false||!is_int($source['fatigueCost']??null))return $source;$cost=($source['fatigueCostIncludesPassive']??false)===true?max(0,$source['fatigueCost']-($preset['inclusive']-$preset['base'])):($source['fatigueCost']===$preset['inclusive']?$preset['base']:$source['fatigueCost']);return [...$source,'fatigueCost'=>$cost,'fatigueCostIncludesPassive'=>false];}
function applicationAbilityRulePreset(mixed $value): ?array {
    return is_array($value)&&is_string($value['id']??null)&&preg_match('/^[A-Za-z0-9_-]{1,120}$/D',$value['id'])===1&&is_int($value['revision']??null)&&$value['revision']>=1&&$value['revision']<=1000000?['id'=>$value['id'],'revision'=>$value['revision']]:null;
}

function applicationConfiguredAttackEnhancement(string $abilityId,array $enhancement):array {
    $preset = applicationSimpleAbilityPresets()[$abilityId] ?? null;
    if(isset($preset['enhancementBonusUpgrade'],$enhancement['aura'])&&($enhancement['mode']??'')===$preset['enhancementMode']
        &&!(($enhancement['rulePreset']['id']??null)===$preset['presetId']&&($enhancement['rulePreset']['revision']??0)>=$preset['enhancementBonusRevision']))
        return [...$enhancement,
            ...(($enhancement['rulePreset']['id']??null)===$preset['presetId']||!is_array($enhancement['aura'])?[]:['aura'=>[...$enhancement['aura'],...$preset['enhancementAuraUpgrade']]]),
            'bonuses'=>[...$preset['enhancementBonusUpgrade'],...(is_array($enhancement['bonuses']??null)?$enhancement['bonuses']:[])]];
    if(!isset($preset['attackEnhancementTrigger'])||($enhancement['mode']??'')!=='on-attack-dot')return $enhancement;
    $patch=[];if(!array_key_exists('trigger',$enhancement))$patch['trigger']=$preset['attackEnhancementTrigger'];if(!array_key_exists('weaponIds',$enhancement)&&isset($preset['attackEnhancementWeaponIds']))$patch['weaponIds']=$preset['attackEnhancementWeaponIds'];
    return $patch!==[]?[...$enhancement,...$patch]:$enhancement;
}

function applicationIsRetiredAbility(mixed $id): bool {
    return is_string($id)&&(applicationSimpleAbilityPresets()[$id]['retired']??false)===true;
}

function applicationAbilityWeaponDrawRule(array $ability): ?array
{
    $preset = applicationSimpleAbilityPresets()[$ability['id'] ?? ''] ?? [];
    return isset($preset['weaponDraw']) && $preset['effect'] === ($ability['effect'] ?? '') ? $preset['weaponDraw'] : null;
}
