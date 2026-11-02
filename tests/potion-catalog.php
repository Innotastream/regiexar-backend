<?php
declare(strict_types=1);
define('XAR_SPECIAL_FIXTURE_ONLY',true);require __DIR__.'/tactical-special-effects.php';$specialChecks=0;
$catalog=json_decode((string)file_get_contents(__DIR__.'/../api/v1/potion-catalog-presets.json'),true,512,JSON_THROW_ON_ERROR);
function catalogRule(string $family,int $level=1):array {global $catalog;foreach($catalog as $rule)if($rule['family']===$family&&$rule['level']===$level)return $rule;throw new RuntimeException('Missing recipe');}
function catalogCommand(MemoryConnection $db,string $command,array $args=[],bool $gm=true):TestResponse {return runCommand($db,$command,['requestId'=>'catalog-'.randomToken(18),'sceneId'=>'scene-one','characterId'=>'character-player','tokenId'=>'token-player',...$args],$gm,$gm?'account-gm':'account-player');}
function catalogGive(MemoryConnection $db,string $family,int $level=1):string {
    $rule=catalogRule($family,$level);$id='bottle-'.$family;
    specialCheck(catalogCommand($db,'item.template.create',['template'=>['id'=>$rule['id'],'name'=>$rule['names'][0],'kind'=>$rule['kind'],'active'=>$rule['active']]])->status===200,'Native create '.$family);
    specialCheck(catalogCommand($db,'item.spawn',['itemId'=>$id,'templateId'=>$rule['id'],'location'=>['kind'=>'inventory','characterId'=>'character-player']])->status===200,'Native give '.$family);
    return $id;
}
function catalogPrepare(MemoryConnection $db):void {
    $activity=$db->payload('activity');
    $activity['attackEnhancements'][]=['id'=>'catalog-prepared','mode'=>'next-item-use','itemKind'=>'potion','multipliers'=>['healing'=>2,'damage'=>2,'area'=>2,'duration'=>2],'turns'=>1,'remainingTurns'=>1,'target'=>'self','expiry'=>'combat-or-rest','holder'=>'character:character-player','label'=>'Préparation fictive','abilityId'=>'synthetic-super','sceneId'=>'scene-one','targetTokenId'=>'token-player','casterTokenId'=>'token-player'];
    $db->put('activity',$activity);
}
$db=specialFixture();$bottle=catalogGive($db,'resistance');catalogPrepare($db);
$args=['requestId'=>'catalog-resistance-once','itemId'=>$bottle,'expectedItemRevision'=>1];$used=catalogCommand($db,'item.consume',$args,false);
specialCheck($used->status===200,'Native protection drink: '.json_encode($used->body));
$guards=$db->payload('activity')['nextAttackGuards']??[];
specialCheck(count($guards)===2&&array_sum(array_column($guards,'percent'))===50,'Two protection stacks persisted through real authority');
specialCheck($db->payload('activity')['attackEnhancements']===[],'Preparation consumed once');
specialCheck(!empty(catalogCommand($db,'item.consume',$args,false)->body['deduplicated']),'Potion replay returns same receipt');
specialCheck(count($db->payload('activity')['nextAttackGuards'])===2,'Replay never grants more stacks');
$action=null;foreach($db->payload('activity')['combatActions']??[] as $candidate)if(in_array('request:catalog-resistance-once',$candidate['aliases']??[],true))$action=$candidate;
specialCheck($action!==null,'Potion has complete action recap');
$undone=catalogCommand($db,'action.undo',['actionId'=>$action['actionIds'][0]]);specialCheck($undone->status===200,'Undo protection potion: '.json_encode($undone->body));
specialCheck(($db->payload('activity')['nextAttackGuards']??[])===[],'Undo removes granted stacks');
specialCheck($db->payload('items')['instances'][0]['location']['kind']==='inventory','Undo restores the same bottle');
specialCheck(count($db->payload('activity')['attackEnhancements'])===1,'Undo restores same preparation');
$db=specialFixture();$bottle=catalogGive($db,'entanglement');
$r=catalogCommand($db,'item.activate',['itemId'=>$bottle,'expectedItemRevision'=>1,'targetPoint'=>['x'=>50,'y'=>50],'opposed'=>false],false);specialCheck($r->status===200,'Native entanglement preview commit: '.json_encode($r->body));
$use=$db->payload('items')['pendingUses'][0];specialCheck($use['phase']==='defense-selection','AoE choice belongs to GM');
specialCheck(catalogCommand($db,'item.defenses.select',['useId'=>$use['id'],'targetTokenIds'=>[]],false)->status===403,'Player cannot bypass defenses');
specialCheck(catalogCommand($db,'item.defenses.select',['useId'=>$use['id'],'targetTokenIds'=>['unrelated-token']])->status===409,'Unrelated unit cannot be selected');
$r=catalogCommand($db,'item.defenses.select',['useId'=>$use['id'],'targetTokenIds'=>[]]);specialCheck($r->status===200,'Native entanglement resolve: '.json_encode($r->body));
$conditions=$db->payload('devices')['timedConditions']??[];specialCheck(count($conditions)>0&&count(array_filter($conditions,static fn($effect)=>$effect['remainingTurns']!==2))===0,'Two-turn entanglement persisted on actual recipients');
$db=specialFixture();$bottle=catalogGive($db,'stench');catalogPrepare($db);$before=$db->payload('token:scene-one:token-monster');
$r=catalogCommand($db,'item.activate',['itemId'=>$bottle,'expectedItemRevision'=>1,'targetPoint'=>['x'=>25,'y'=>25],'opposed'=>false],false);specialCheck($r->status===200,'Native empty stench zone: '.json_encode($r->body));
$zones=$db->payload('activity')['attackEnhancements']??[];specialCheck(count($zones)===1&&$zones[0]['mode']==='arbitration-zone'&&$zones[0]['remainingTurns']===10&&$zones[0]['fixedArea']['radiusCells']===4,'Zone width and duration doubled once and persisted');
specialCheck($db->payload('token:scene-one:token-monster')===$before,'Stench invents no damage, conditions or stat penalty');
$public=applicationPublicArmorReductionZones($zones,'scene-one','ground',static fn()=>true);specialCheck(count($public)===1&&$public[0]['label']==='Puanteur','Visible manual zone has public label');specialCheck(applicationPublicArmorReductionZones($zones,'scene-one','ground',static fn()=>false)===[],'Invisible zone is not revealed');
fwrite(STDOUT,json_encode(['ok'=>true,'checks'=>$specialChecks],JSON_UNESCAPED_UNICODE)."\n");
