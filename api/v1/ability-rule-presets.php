<?php
declare(strict_types=1);
require_once __DIR__.'/ability-description-presets.php';
require_once __DIR__.'/path-strike-presets.php';
function applicationConfiguredOppositionAbility(array $source): array {
    static $presets=null;$presets??=json_decode((string)file_get_contents(__DIR__.'/ability-rule-presets.json'),true,512,JSON_THROW_ON_ERROR);
    $preset=$presets[$source['id']??'']??null;
    return $preset!==null&&($source['effect']??'')==='movement'&&(!isset($source['opposition'])||($source['opposition']['statId']??'')==='character-stat-dexterity')?[...$source,...$preset,'onHitConditions'=>[]]:$source;
}
function applicationConfiguredAbilityRules(array $source): array {
    static $presets=null;$presets??=json_decode((string)file_get_contents(__DIR__.'/ability-simple-presets.json'),true,512,JSON_THROW_ON_ERROR);
    $configured=applicationConfiguredPathStrikeAbility(applicationConfiguredInclusiveFatigue(applicationConfiguredOppositionAbility($source)));$preset=$presets[$source['id']??'']??null;
    if(isset($preset['attackEnhancementTrigger'])&&($source['effect']??'')===($preset['effect']??'')&&($source['attackEnhancement']['mode']??'')==='on-attack-dot')return [...$configured,'attackEnhancement'=>applicationConfiguredAttackEnhancement((string)$source['id'],$source['attackEnhancement'])];
    if(isset($preset['values'])&&($source['effect']??'damage')===($preset['effect']??'damage')){
        $applied=applicationAbilityRulePreset($source['rulePreset']??null);
        if(!empty($preset['deviceUpgradeOnly'])&&($configured['device']['kind']??'')==='beam'){return [...$configured,'device'=>[...$preset['values']['device'],...$configured['device']],...(!empty($preset['values']['description'])&&($configured['device']['unlimitedRange']??true)&&($configured['device']['affectUnseen']??true)&&!($applied!==null&&$applied['id']===$preset['presetId']&&$applied['revision']>=$preset['revision'])?['description'=>$preset['values']['description']]:[]),...(!($applied!==null&&$applied['id']===$preset['presetId']&&$applied['revision']>=$preset['revision'])?['rulePreset'=>['id'=>$preset['presetId'],'revision'=>$preset['revision']]]:[])];}
        if(!empty($preset['targetingUpgradeOnly'])&&!($applied!==null&&$applied['id']===$preset['presetId']&&$applied['revision']>=$preset['revision']))return [...$configured,'damageTargeting'=>['affectCaster'=>true,'affectAllies'=>true,'affectEnemies'=>true,...($configured['damageTargeting']??[]),...$preset['values']['damageTargeting']],'rulePreset'=>['id'=>$preset['presetId'],'revision'=>$preset['revision']]];
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
    return $preset!==null&&!isset($preset['values'])&&!isset($preset['attackEnhancementTrigger'])&&($source['effect']??'damage')==='damage'&&!array_key_exists('rangeCells',$source)&&!array_key_exists('infusion',$source)?[...$configured,...$preset]:$configured;
}
function applicationConfiguredInclusiveFatigue(array $source):array{static $presets=null;$presets??=json_decode((string)file_get_contents(__DIR__.'/ability-inclusive-fatigue-presets.json'),true,512,JSON_THROW_ON_ERROR);$preset=$presets[$source['id']??'']??null;return $preset!==null&&!array_key_exists('fatigueCostIncludesPassive',$source)&&in_array($source['fatigueCost']??null,[$preset['base'],$preset['inclusive']],true)?[...$source,'fatigueCost'=>$preset['inclusive'],'fatigueCostIncludesPassive'=>true]:$source;}
function applicationAbilityRulePreset(mixed $value): ?array {
    return is_array($value)&&is_string($value['id']??null)&&preg_match('/^[A-Za-z0-9_-]{1,120}$/D',$value['id'])===1&&is_int($value['revision']??null)&&$value['revision']>=1&&$value['revision']<=1000000?['id'=>$value['id'],'revision'=>$value['revision']]:null;
}

function applicationConfiguredAttackEnhancement(string $abilityId,array $enhancement):array {
    static $presets=null;$presets??=json_decode((string)file_get_contents(__DIR__.'/ability-simple-presets.json'),true,512,JSON_THROW_ON_ERROR);$preset=$presets[$abilityId]??null;
    if(!isset($preset['attackEnhancementTrigger'])||($enhancement['mode']??'')!=='on-attack-dot')return $enhancement;
    $patch=[];if(!array_key_exists('trigger',$enhancement))$patch['trigger']=$preset['attackEnhancementTrigger'];if(!array_key_exists('weaponIds',$enhancement)&&isset($preset['attackEnhancementWeaponIds']))$patch['weaponIds']=$preset['attackEnhancementWeaponIds'];
    return $patch!==[]?[...$enhancement,...$patch]:$enhancement;
}
