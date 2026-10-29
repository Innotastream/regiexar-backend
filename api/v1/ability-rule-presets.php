<?php
declare(strict_types=1);
require_once __DIR__.'/ability-description-presets.php';
function applicationConfiguredOppositionAbility(array $source): array {
    static $presets=null;$presets??=json_decode((string)file_get_contents(__DIR__.'/ability-rule-presets.json'),true,512,JSON_THROW_ON_ERROR);
    $preset=$presets[$source['id']??'']??null;
    return $preset!==null&&($source['effect']??'')==='movement'&&(!isset($source['opposition'])||($source['opposition']['statId']??'')==='character-stat-dexterity')?[...$source,...$preset,'onHitConditions'=>[]]:$source;
}
function applicationConfiguredAbilityRules(array $source): array {
    static $presets=null;$presets??=json_decode((string)file_get_contents(__DIR__.'/ability-simple-presets.json'),true,512,JSON_THROW_ON_ERROR);
    $configured=applicationConfiguredOppositionAbility($source);$preset=$presets[$source['id']??'']??null;
    if(isset($preset['values'])&&($source['effect']??'damage')===($preset['effect']??'damage')){
        $applied=applicationAbilityRulePreset($source['rulePreset']??null);
        return $applied!==null&&$applied['id']===$preset['presetId']&&$applied['revision']>=$preset['revision']?$configured:[...$configured,...$preset['values'],'rulePreset'=>['id'=>$preset['presetId'],'revision'=>$preset['revision']]];
    }
    return $preset!==null&&!isset($preset['values'])&&($source['effect']??'damage')==='damage'&&!array_key_exists('rangeCells',$source)&&!array_key_exists('infusion',$source)?[...$configured,...$preset]:$configured;
}
function applicationAbilityRulePreset(mixed $value): ?array {
    return is_array($value)&&is_string($value['id']??null)&&preg_match('/^[A-Za-z0-9_-]{1,120}$/D',$value['id'])===1&&is_int($value['revision']??null)&&$value['revision']>=1&&$value['revision']<=1000000?['id'=>$value['id'],'revision'=>$value['revision']]:null;
}
