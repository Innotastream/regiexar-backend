<?php
declare(strict_types=1);
require_once __DIR__.'/../api/v1/domains.php';
require_once __DIR__.'/../api/v1/online.php';
require_once __DIR__.'/../api/v1/ability-assistant.php';
function randomToken(int $bytes):string{return rtrim(strtr(base64_encode(random_bytes($bytes)),'+/','-_'),'=');}
function planCheck(bool $condition,string $label):void{if(!$condition)throw new RuntimeException($label);echo "PASS $label\n";}
function planRejected(callable $action,int $status,string $label):void{try{$action();}catch(RuntimeException|InvalidArgumentException $error){planCheck($status===$error->getCode()||($status===400&&$error instanceof InvalidArgumentException),$label);return;}throw new RuntimeException('Accepted: '.$label);}
$gm=['id'=>'gm','permanent_role'=>'gm','effective_mode'=>'gm'];$player=['id'=>'owner','permanent_role'=>'player','effective_mode'=>'player'];
$character=['id'=>'hero','ownerPlayerId'=>'owner','name'=>'Fictif','abilities'=>[],'resources'=>['hp'=>30,'maxHp'=>40,'mana'=>20,'maxMana'=>30],'privateNotes'=>'NEVER SEND','stats'=>['spirit'=>30]];
$bolt=normalizeItemTemplate(['id'=>'bolt','kind'=>'object','name'=>'Projectile','stockBindings'=>[['characterId'=>'hero','abilityId'=>'known']],'document'=>['blocks'=>[['type'=>'paragraph','text'=>'PRIVATE DOCUMENT']]]]);
$foreign=normalizeItemTemplate(['id'=>'private','kind'=>'object','name'=>'OTHER INVENTORY']);
$items=['version'=>1,'templates'=>[$bolt,$foreign],'instances'=>[['id'=>'spent','templateId'=>'bolt','revision'=>4,'location'=>['kind'=>'consumed','characterId'=>'hero']],['id'=>'other','templateId'=>'private','revision'=>1,'location'=>['kind'=>'inventory','characterId'=>'other']]]];
$record=static fn($key,$payload,$revision=7)=>['key'=>$key,'schemaVersion'=>1,'revision'=>$revision,'payload'=>$payload];
$records=['character:hero'=>$record('character:hero',$character),'items'=>$record('items',$items),'devices'=>$record('devices',['devices'=>[]])];
$safe=abilityAssistantObjectContext($records,$character,$player);$encoded=json_encode($safe,JSON_THROW_ON_ERROR);
planCheck(count($safe['objectTemplates'])===1&&$safe['objectTemplates'][0]['quantity']===0,'Player sees own known zero stock');
planCheck(!str_contains($encoded,'OTHER INVENTORY')&&!str_contains($encoded,'PRIVATE DOCUMENT')&&!str_contains($encoded,'NEVER SEND'),'Context contains no foreign inventory, documents or private notes');
planCheck(!$safe['canApplyObjectPlan']&&!abilityAssistantObjectContext($records,$character,[...$gm,'effective_mode'=>'player'])['canApplyObjectPlan'],'Effective player mode never obtains dynamic creation rights');
$context=['character'=>['id'=>'hero'],'existingAbility'=>null,'linkedTokens'=>[],...abilityAssistantObjectContext($records,$character,$gm)];
$conversation=['context_json'=>json_encode($context,JSON_THROW_ON_ERROR)];
$plan=['version'=>1,'objects'=>[['ref'=>'ammo','templateId'=>'bolt']],'links'=>[['kind'=>'launcher-ammunition','objectRef'=>'ammo']]];
$draft=normalizeAbilityAssistantDraft($conversation,['name'=>'Lanceur inédit','effect'=>'device','device'=>['version'=>1,'kind'=>'launcher'],'objectPlan'=>$plan]);
$before=serialize($records);$prepared=prepareAbilityAssistantObjectApplication($records,$conversation,$draft,$gm);
planCheck(serialize($records)===$before,'Preparation does not mutate input or persist partial work');
planCheck(count($prepared['items']['instances'])===2&&$prepared['items']['instances']===$items['instances'],'Linking a zero stock never creates or rewrites instances');
planCheck($prepared['character']['resources']===$character['resources']&&$prepared['character']['privateNotes']==='NEVER SEND','Resources and private sheet fields are preserved');
planCheck($prepared['character']['abilities'][0]['device']['ammoTemplateId']==='bolt','Real launcher ammo port is linked');
planCheck($prepared['items']['templates'][0]['document']===$bolt['document'],'Reused model document and appearance are unchanged');
foreach([$player,[...$gm,'effective_mode'=>'player'],[...$gm,'permanent_role'=>'player']] as $actor)planRejected(fn()=>prepareAbilityAssistantObjectApplication($records,$conversation,$draft,$actor),403,'Dynamic plan requires permanent and effective MJ');
$changed=$records;$changed['character:hero']['revision']++;
planRejected(fn()=>prepareAbilityAssistantObjectApplication($changed,$conversation,$draft,$gm),409,'Concurrent sheet update is refused');
$changed=$records;$changed['items']['payload']['templates'][0]['name']='Changed';
planRejected(fn()=>prepareAbilityAssistantObjectApplication($changed,$conversation,$draft,$gm),409,'Concurrent template change is refused');
$newPlan=['version'=>1,'objects'=>[['ref'=>'ammo','template'=>['kind'=>'object','name'=>'Modèle neuf','active'=>['version'=>1,'effects'=>[['kind'=>'message','text'=>'Visible','theme'=>'neutral','durationMs'=>1000]]]]]],'links'=>$plan['links']];
$newDraft=normalizeAbilityAssistantDraft($conversation,['name'=>'Lanceur neuf','effect'=>'device','device'=>['kind'=>'launcher'],'objectPlan'=>$newPlan]);
$new=prepareAbilityAssistantObjectApplication($records,$conversation,$newDraft,$gm,static fn()=>'new-model');
planCheck(count($new['items']['templates'])===3&&count($new['items']['instances'])===2,'New dynamic model is added without a free item');
planCheck($new['character']['abilities'][0]['device']['ammoTemplateId']==='new-model'&&$new['items']['templates'][2]['active']['effects'][0]['text']==='Visible','New model effects and real ammo identity persist together');
foreach(['quantity'=>20,'image'=>'/media/private','permissions'=>['destroy'=>true],'sourceAbilityId'=>'foreign','command'=>'item.spawn'] as $key=>$value){$bad=$newPlan;$bad['objects'][0]['template'][$key]=$value;planRejected(fn()=>normalizeAssistantObjectPlan($bad,$newDraft,$context),400,'Unsupported template field refused: '.$key);}
$bad=$newPlan;$bad['links'][0]['kind']='consume-any-item';planRejected(fn()=>normalizeAssistantObjectPlan($bad,$newDraft,$context),400,'No invented generic item consumption');
$bad=$newPlan;$bad['objects'][]=['ref'=>'orphan','template'=>['kind'=>'object','name'=>'Unlinked']];planRejected(fn()=>normalizeAssistantObjectPlan($bad,$newDraft,$context),400,'Unlinked orphan model refused');
foreach([[],['nested'=>'value'],null,2,true] as $badRef){$bad=$newPlan;$bad['links'][0]['objectRef']=$badRef;planRejected(fn()=>normalizeAssistantObjectPlan($bad,$newDraft,$context),400,'Malformed link reference yields validation error, never TypeError');}
foreach([null,false,2,'text',[]] as $badObject){$bad=$newPlan;$bad['objects'][0]=$badObject;planRejected(fn()=>normalizeAssistantObjectPlan($bad,$newDraft,$context),400,'Malformed object row is refused');}
$bad=$newPlan;$bad['objects'][0]['template']['active']=['version'=>1,'effects'=>[['kind'=>'light','distance'=>2,'color'=>[]]]];planRejected(fn()=>normalizeAssistantObjectPlan($bad,$newDraft,$context),400,'Nested malformed active effect is normalized into a safe validation error');
$bad=$newPlan;$bad['objects']=['named'=>$newPlan['objects'][0]];planRejected(fn()=>normalizeAssistantObjectPlan($bad,$newDraft,$context),400,'Object list must remain a JSON list');
$coatingPlan=['version'=>1,'objects'=>[['ref'=>'vial','template'=>['kind'=>'object','name'=>'Fiole fabriquée']]],'links'=>[['kind'=>'coating-vial','objectRef'=>'vial']]];
$coating=normalizeAbilityAssistantDraft($conversation,['name'=>'Enduction inédite','effect'=>'enhancement','attackEnhancement'=>['mode'=>'echo','formula'=>'1d5','damageType'=>'ignore','turns'=>2],'tacticalEffect'=>['version'=>1,'kind'=>'coating'],'objectPlan'=>$coatingPlan]);
$coated=prepareAbilityAssistantObjectApplication($records,$conversation,$coating,$gm,static fn()=>'new-vial');
planCheck($coated['items']['templates'][2]['sourceAbilityId']===$coated['character']['abilities'][0]['id']&&$coated['items']['templates'][2]['creatorCharacterId']==='hero','New coating template uses the existing craft port and effective owner');
planCheck($coated['items']['instances']===$items['instances'],'Crafting is not invoked by applying a proposal');
$coatingAbility=$coated['character']['abilities'][0];
$repairRecords=['character:hero'=>$record('character:hero',$coated['character'],8),'items'=>$record('items',$coated['items'],8),'devices'=>$records['devices']];
$repairContext=['character'=>['id'=>'hero'],'existingAbility'=>$coatingAbility,'linkedTokens'=>[],...abilityAssistantObjectContext($repairRecords,$coated['character'],$gm)];
$repairConversation=['context_json'=>json_encode($repairContext,JSON_THROW_ON_ERROR)];
$repairDraft=normalizeAbilityAssistantDraft($repairConversation,[...$coatingAbility,'objectPlan'=>$coatingPlan]);
planRejected(fn()=>prepareAbilityAssistantObjectApplication($repairRecords,$repairConversation,$repairDraft,$gm,static fn()=>'duplicate-vial'),409,'Repair refuses a second crafted template for the same coating');
$reuseCoatingPlan=['version'=>1,'objects'=>[['ref'=>'vial','templateId'=>'new-vial']],'links'=>$coatingPlan['links']];
$repairDraft=normalizeAbilityAssistantDraft($repairConversation,[...$coatingAbility,'objectPlan'=>$reuseCoatingPlan]);
$repaired=prepareAbilityAssistantObjectApplication($repairRecords,$repairConversation,$repairDraft,$gm);
planCheck(count($repaired['items']['templates'])===3,'Repair explicitly reuses the original coating template');
require_once __DIR__.'/../api/v1/consumable-crafting.php';
$craftState=['activeSceneId'=>'scene','items'=>$repaired['items']];$craftStore=['craftRequests'=>[['status'=>'pending','cast'=>['success'=>true],'sceneId'=>'scene','ability'=>$coatingAbility,'characterId'=>'hero']]];
$craft=applicationResolveConsumableCraft($craftState,$craftStore,0,true,['now'=>1,'id'=>static fn($prefix)=>$prefix.'-fictif']);
planCheck(count($craftState['items']['templates'])===3&&$craftState['items']['instances'][2]['templateId']==='new-vial','A real approved craft uses the repaired existing template without a duplicate');
planCheck($craftState['items']['instances'][2]['location']===['kind'=>'inventory','characterId'=>'hero']&&count($craft['itemIds'])===1,'Only the later approved craft grants one item to its effective owner');
$simple=normalizeAbilityAssistantDraft($conversation,['name'=>'Frappe simple inédite','effect'=>'damage','useWeaponDamage'=>true,'mainWeaponOnly'=>true,'maximumWeaponDamage'=>true,'weaponDamageMultiplier'=>2,'castingStatId'=>'character-stat-agility','reach'=>'reach','projectile'=>false]);
planCheck($simple['useWeaponDamage']&&$simple['weaponDamageMultiplier']===2&&$simple['mainWeaponOnly']&&$simple['maximumWeaponDamage']&&$simple['reach']==='reach'&&!$simple['projectile']&&!isset($simple['workflow']),'A generic simple weapon ability needs no fixed damage and no duplicate complex workflow');
$genericCharacter=[...$character,'weaponAttacks'=>[['id'=>'natural','formula'=>'1d8','naturalWeapon'=>true,'reach'=>'melee','targetCount'=>2,'finesse'=>true],['id'=>'bound','formula'=>'999d999','equipmentBinding'=>['kind'=>'item','id'=>'real-item'],'reach'=>'reach']]];
$safeCharacter=abilityAssistantSafeCharacterContext($genericCharacter)['character'];
planCheck(count($safeCharacter['weaponAttacks'])===2&&$safeCharacter['weaponAttacks'][0]['naturalWeapon']&&$safeCharacter['weaponAttacks'][0]['finesse']&&$safeCharacter['weaponAttacks'][0]['targetCount']===2,'Safe character context exposes actual weapon semantics without guessing a class');
planCheck($safeCharacter['weaponAttacks'][1]['dynamicEquipment']&&!isset($safeCharacter['weaponAttacks'][1]['formula'])&&!isset($safeCharacter['privateNotes']),'Dynamic equipment never feeds stale saved damage or private notes to the assistant');
foreach(['character-stat-spirit','character-stat-spiritSocial'] as $stat){$generic=normalizeAbilityAssistantDraft($conversation,['name'=>'Effet inédit','effect'=>'movement','castingStatId'=>$stat,'tacticalEffect'=>['version'=>1,'kind'=>'mark','attackBonus'=>20,'dodgeBonus'=>20]]);planCheck($generic['castingStatId']===$stat&&$generic['tacticalEffect']['kind']==='mark','Spirit and Social remain independent for a generic simple effect: '.$stat);}
$hybridPlan=['version'=>1,'objects'=>[['ref'=>'ammo','templateId'=>'bolt'],['ref'=>'vial','template'=>['kind'=>'object','name'=>'Fiole']]],'links'=>[['kind'=>'launcher-ammunition','objectRef'=>'ammo'],['kind'=>'coating-vial','objectRef'=>'vial']]];
planRejected(fn()=>normalizeAssistantObjectPlan($hybridPlan,[...$draft,'tacticalEffect'=>['version'=>1,'kind'=>'coating']],$context),400,'A hybrid launcher cannot bypass the coating-only Buff port');
foreach([
 ['name'=>'Invalid mark','effect'=>'damage','damageComponents'=>[['type'=>'physical','formula'=>'1d6']],'tacticalEffect'=>['version'=>1,'kind'=>'mark']],
 ['name'=>'Invalid arbiter','effect'=>'device','device'=>['kind'=>'battery'],'manualD100'=>true],
 ['name'=>'Invalid maximum','effect'=>'damage','damageComponents'=>[['type'=>'physical','formula'=>'1d6']],'maximumWeaponDamage'=>true]
] as $invalid)planRejected(fn()=>normalizeAbilityAssistantDraft($conversation,$invalid),400,'Assistant respects existing simple mechanics combinations');
