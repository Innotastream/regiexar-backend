<?php
declare(strict_types=1);
require_once __DIR__.'/../api/v1/damage-guard.php';
$guards=[];for($i=0;$i<6;$i++)$guards[]=applicationMakeStackableEffect('protection','scene','target','guard-'.$i);
$checks=0;$check=static function(bool $value,string $label)use(&$checks){if(!$value)throw new RuntimeException($label);$checks++;};
$hit=static function(array $guards,string $type):array{
 $basis=['rawDamage'=>100,'finalDamage'=>100,'armorPercent'=>0,'damageType'=>$type];$indices=[];foreach($guards as $i=>$g)if(applicationGuardCoversDamage($g,$basis))$indices[]=$i;
 return [...applicationMitigateGuardedDamage(100,array_map(static fn($i)=>$guards[$i],$indices),$basis),'guards'=>applicationConsumeGuardIndices($guards,$indices)];
};
$ignored=$hit($guards,'ignore');$check($ignored['damage']===100&&$ignored['guards']===$guards,'Ignore armor bypasses and does not trigger Protection');
$first=$hit($ignored['guards'],'physical');$check($first['damage']===0&&count($first['guards'])===2,'Six stacks of 25 absorb 100 and keep 50');
$second=$hit($first['guards'],'magical');$check($second['damage']===50&&$second['guards']===[],'The next magical attack consumes the remaining 50');
$minus=applicationAdjustStackableEffects($guards,'protection','scene','target',-1,'unused');$check(count($minus)===5&&array_sum(array_column($minus,'percent'))===125,'Decrement one stack');
$plus=applicationAdjustStackableEffects($minus,'protection','scene','target',1,'new');$check(count($plus)===6&&array_sum(array_column($plus,'percent'))===150,'Increment one stack');
$other=applicationAdjustStackableEffects($guards,'protection','scene','clone',1,'clone');$check(count(array_filter($other,static fn($g)=>$g['targetTokenId']==='target'))===6,'Independent clone target');
$basis=['finalDamage'=>300,'components'=>[['type'=>'physical','rawDamage'=>100,'finalDamage'=>100,'armorPercent'=>0],['type'=>'magical','rawDamage'=>100,'finalDamage'=>100,'armorPercent'=>0],['type'=>'ignore','rawDamage'=>100,'finalDamage'=>100,'armorPercent'=>0]]];
$mixed=applicationMitigateGuardedDamage(300,$guards,$basis);$check($mixed['damage']===100&&count(applicationConsumeGuardIndices($guards,array_keys($guards)))===2,'Mixed damage leaves ignore component and spends reserve only once');
$original=['id'=>'ability-emosphere-6a5b9f30-448f-4096-9c4b-49e02941ead2','workflow'=>['steps'=>[['spendOptions'=>[['label'=>'Old','effect'=>['kind'=>'guard','percent'=>20]]]]]]];
$configured=applicationConfiguredStackableAbility($original);$check($configured['workflow']['steps'][0]['spendOptions'][0]['effect']['percent']===25&&$original['workflow']['steps'][0]['spendOptions'][0]['effect']['percent']===20,'Authoritative rule uses 25 without rewriting source sheet');
echo json_encode(['ok'=>true,'checks'=>$checks,'productionAccess'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
