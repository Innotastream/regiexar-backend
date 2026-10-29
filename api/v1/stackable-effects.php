<?php
declare(strict_types=1);
function applicationStackableEffects(): array {return ['protection'=>['id'=>'protection','label'=>'Protection','perStack'=>25,'damageTypes'=>['physical','magical'],'symbol'=>'🛡']];}
function applicationConfiguredStackableEffect(string $abilityId,string $kind): ?string {
    static $bindings=null;$bindings??=json_decode((string)file_get_contents(__DIR__.'/stackable-effect-bindings.json'),true)?:[];
    return $bindings['abilities'][$abilityId][$kind]??null;
}
function applicationMakeStackableEffect(string $effectId,string $sceneId,string $tokenId,string $id): array {
    $definition=applicationStackableEffects()[$effectId]??null;if($definition===null)throw new RuntimeException('Effet cumulable inconnu.');
    return ['id'=>$id,'sceneId'=>$sceneId,'targetTokenId'=>$tokenId,'effectId'=>$effectId,'label'=>$definition['label'],'percent'=>$definition['perStack'],
        'damageTypes'=>$definition['damageTypes'],'stackGroup'=>'effect:'.$effectId,'budget'=>true,'lifecycle'=>'status','persistent'=>true,'trigger'=>'damage','armorStacking'=>'add','combatId'=>'','sourceExecutionId'=>''];
}
function applicationAdjustStackableEffects(array $guards,string $effectId,string $sceneId,string $tokenId,int $delta,string $id): array {
    if(!isset(applicationStackableEffects()[$effectId])||!in_array($delta,[-1,1],true))throw new RuntimeException('Ajustement invalide.');
    if($delta===1){if(count($guards)>=60)throw new RuntimeException('La liste des protections est pleine.');$guards[]=applicationMakeStackableEffect($effectId,$sceneId,$tokenId,$id);}
    else for($i=count($guards)-1;$i>=0;$i--)if(($guards[$i]['effectId']??'')===$effectId&&($guards[$i]['sceneId']??'')===$sceneId&&($guards[$i]['targetTokenId']??'')===$tokenId){array_splice($guards,$i,1);break;}
    return array_values($guards);
}
function applicationConfiguredStackableAbility(array $ability): array {
    if(applicationConfiguredStackableEffect((string)($ability['id']??''),'guard')===null)return $ability;
    $definition=applicationStackableEffects()[applicationConfiguredStackableEffect((string)$ability['id'],'guard')];if(!isset($ability['workflow']['steps']))return $ability;
    foreach($ability['workflow']['steps'] as &$step)if(isset($step['spendOptions']))foreach($step['spendOptions'] as &$option)if(($option['effect']['kind']??'')==='guard'){$option['label']=$definition['label'];$option['effect']['percent']=$definition['perStack'];}unset($option,$step);
    return $ability;
}
