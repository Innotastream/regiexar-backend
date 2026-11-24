<?php
declare(strict_types=1);

function groupedReceiptAbility():array {
    return ['id'=>'group-ledger-area','name'=>'Zone de recette','effect'=>'damage','formula'=>'7','damageType'=>'ignore','castingStatId'=>'','manaCost'=>3,'fatigueCost'=>2,
        'damageTargeting'=>['mode'=>'area','origin'=>'caster','radiusCells'=>4,'affectCaster'=>false,'affectAllies'=>true,'affectEnemies'=>true]];
}
function groupedReceiptArguments(string $requestId='group-ledger-launch-seed'):array {
    return ['sceneId'=>'scene-one','sourceTokenId'=>'token-player','targetTokenId'=>'token-player','attackKind'=>'ability','attackId'=>'group-ledger-area','requestId'=>$requestId,'opposed'=>false];
}
function groupedReceiptFixture():MemoryConnection {
    $fixture=specialFixture();
    foreach(['token-player'=>20,'token-monster'=>25,'token-monster-two'=>27]as$id=>$x){$key='token:scene-one:'.$id;$fixture->put($key,[...$fixture->payload($key),'x'=>$x,'y'=>50,'hp'=>1000,'maxHp'=>1000,'magicArmor'=>0]);}
    $character=$fixture->payload('character:character-player');$character['abilities']=[groupedReceiptAbility()];$character['resources']['mana']=10000;$character['resources']['maxMana']=10000;$fixture->put('character:character-player',$character);return $fixture;
}
function groupedReceiptSample():array {
    $fixture=groupedReceiptFixture();$opened=runCommand($fixture,'token.attack',groupedReceiptArguments());$closed=fixtureApproveDefenseGate($fixture,$opened);
    if($closed->status!==200)throw new RuntimeException('Actual grouped defense preparation failed: '.json_encode($closed->body));
    $rows=$fixture->payload('activity')['attackReceipts'];
    if(count($rows)<3||!array_filter($rows,static fn($row)=>!empty($row['areaAttackIds'])))throw new RuntimeException('A true area parent and its child outcomes are required.');
    return ['receipts'=>$rows,'fixture'=>$fixture,'opened'=>$opened,'closed'=>$closed];
}
function groupedReceiptHistory(array $sample,int $groups,string $prefix='terminal-history'):array {
    $result=[];
    for($group=0;$group<$groups;$group++){
        $ids=[];$requests=[];
        foreach($sample as$i=>$entry){$ids[$entry['attack']['id']]??=sprintf('attack-%s-%04d-%02d',$prefix,$group,$i);$requests[$entry['requestId']]??=sprintf('%s-%04d-%02d',$prefix,$group,$i);}
        $rewrite=static function(mixed $value)use(&$rewrite,$ids,$requests):mixed {
            if(is_array($value))return array_map($rewrite,$value);
            return is_string($value)?($ids[$value]??$requests[$value]??$value):$value;
        };
        foreach($sample as$entry){$copy=$rewrite($entry);$copy['expiresAt']=applicationResourceReceiptClock()+600000;$result[]=$copy;}
    }
    return $result;
}
