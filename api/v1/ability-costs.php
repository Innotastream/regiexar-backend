<?php
declare(strict_types=1);
require_once __DIR__.'/passive-abilities.php';
function applicationAbilityFatigueCost(array $ability,array $source=[]):int {
    $base=max(0,(int)($ability['fatigueCost']??0));if(($ability['fatigueCostIncludesPassive']??false)===true)return $base;
    $surcharge=0;foreach(applicationConfiguredPassiveAbilities($source) as $passive)if($passive['enabled']&&$passive['kind']==='ability-fatigue-surcharge')$surcharge+=$passive['amount'];
    return min(1000000000,$base+$surcharge);
}
