<?php
declare(strict_types=1);
require_once __DIR__.'/attack-enhancements.php';

const XAR_PERFIDIOUS_POISON_ABILITY_ID = 'ability-93fa3849-3fbf-43fa-84cb-0c0c9d7f4189';
function isVarskaPawAttack(array $weapon, array $source): bool {
 if (($weapon['id'] ?? '') !== 'weapon-2') return false;
 $identities = [$source['id']??'', 'unit_'.($source['id']??''), $source['characterId']??'',
     $source['baseCharacterId']??'', $source['clonedFromCharacterId']??''];
 return array_intersect($identities, ['character-X4kmpvnbF9rQgNGc', 'unit_token-P-CK3ZtFhBidBd0rf3mlyA']) !== [];
}

function normalizeWeaponAttackTurnLimit(mixed $value):int {return (!is_string($value)||preg_match('/^(0|[1-9]\d*)$/D',$value)===1)&&is_numeric($value)&&is_finite((float)$value)&&(float)$value===floor((float)$value)&&(float)$value>0&&(float)$value<=9007199254740991?min(100,(int)$value):0;}
function weaponAttackTurnLimit(array $weapon,array $source=[]):int {
 if (isVarskaPawAttack($weapon, $source)) return 1;
 return normalizeWeaponAttackTurnLimit($weapon['maxAttacksPerTurn']??null);
}
function configuredWeaponTurnLimits(array $weapons,array $source=[]):array {return array_map(static function($weapon)use($source){$limit=weaponAttackTurnLimit($weapon,$source);return $limit?[...$weapon,'maxAttacksPerTurn'=>$limit]:$weapon;},$weapons);}
function weaponTurnContext(array $state,array $source,array $options):array {
 $activeSceneId=$state['activeSceneId']??$state['activeScene']['id']??'';$sceneId=$options['sceneId']??$activeSceneId;$initiative=$state['initiative']??[];
 if($sceneId!==$activeSceneId){$initiative=[];foreach($state['scenes']??[]as$scene)if(($scene['id']??'')===$sceneId){$initiative=$scene['combat']['initiative']??[];break;}}
 $linked=!empty($source['characterId'])&&($source['followCharacter']??true)!==false&&($source['usesCharacterSheet']??true)!==false&&($source['rollUsesCharacterSheet']??true)!==false&&empty($source['independent'])&&empty($source['independentClone'])&&empty($source['linkedTokenId']);
 $unitId=$options['unitId']??(($source['unitKind']??'')==='token'||str_starts_with($source['id']??'','character-')?($source['id']??''):($linked?$source['characterId']:(!empty($source['id'])?'unit_'.$source['id']:'')));
 $active=($initiative['active']??false)===true&&$unitId!=='';
 $turnKey=$active?json_encode([$initiative['combatId']??'',max(0,(int)($initiative['turnSerial']??0)),max(1,(int)($initiative['round']??1))],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):'';
 return ['sceneId'=>$sceneId,'unitId'=>$unitId,'active'=>$active,'turnKey'=>$turnKey,'combatId'=>$initiative['combatId']??''];
}
function perfidiousPoisonSuspendsPawTurnLimit(array $state, array $source, array $weapon, string $sceneId): bool {
 if (($weapon['id'] ?? '') !== 'weapon-2') return false;
 $holder = applicationEnhancementHolder($source);
 foreach ($state['attackEnhancements'] ?? [] as $effect) {
  if (($effect['abilityId'] ?? '') !== XAR_PERFIDIOUS_POISON_ABILITY_ID || ($effect['mode'] ?? '') !== 'on-attack-dot'
    || ($effect['remainingTurns'] ?? 0) <= 0 || ($effect['sceneId'] ?? '') !== $sceneId || ($effect['holder'] ?? '') !== $holder) continue;
  $configured = applicationConfiguredAttackEnhancement(XAR_PERFIDIOUS_POISON_ABILITY_ID, $effect);
  if (in_array($weapon['id'], $configured['weaponIds'] ?? [], true)) return true;
 }
 return false;
}
function weaponTurnUsage(array $state,array $source,array $weapon,array $options=[]):array {
 $clock=weaponTurnContext($state,$source,$options);$limit=weaponAttackTurnLimit($weapon,$source);$timer=null;
 if($limit&&$clock['active']&&perfidiousPoisonSuspendsPawTurnLimit($state,$source,$weapon,$clock['sceneId']))$limit=0;
 foreach($state['actionTimers']??[]as$entry)if(($entry['kind']??'')==='weapon-turn-limit'&&($entry['unitId']??'')===$clock['unitId']&&($entry['weaponId']??'')===($weapon['id']??null)&&($entry['sceneId']??'')===$clock['sceneId']){$timer=$entry;break;}
 $current=$clock['active']&&($timer['turnKey']??null)===$clock['turnKey'];$used=$current?max(0,(int)($timer['useCount']??0)):0;
 return [...$clock,'limit'=>$limit,'used'=>$used,'remaining'=>$limit?max(0,$limit-$used):null,'timerId'=>$timer['id']??'','requestIds'=>$current?array_values($timer['requestIds']??[]):[]];
}
function weaponAttackLimitReason(array $state,array $source,array $weapon,array $options=[]):string {
 $usage=weaponTurnUsage($state,$source,$weapon,$options);
 if(!$usage['active']||!$usage['limit']||isset($options['requestId'])&&in_array($options['requestId'],$usage['requestIds'],true))return '';
 $planned=max(0,(int)($options['plannedCount']??0));$limit=$usage['limit'];
 return $usage['used']+$planned>=$limit?($weapon['name']??'Cette arme').' : '.$limit.' attaque'.($limit>1?'s':'').' maximum par tour. '.($usage['used']?'Déjà utilisée ce tour.':'Limite atteinte dans cette série.'):'';
}
function assertWeaponAttackLimit(array $state,array $source,array $weapon,array $options=[]):array {
 $reason=weaponAttackLimitReason($state,$source,$weapon,$options);if($reason!=='')throw new RuntimeException($reason,409);$usage=weaponTurnUsage($state,$source,$weapon,$options);
 if($usage['active']&&$usage['limit']&&$usage['timerId']===''&&count($state['actionTimers']??[])>=300)throw new RuntimeException('Le suivi des utilisations est plein.',409);return $usage;
}
function consumeWeaponAttackUse(array &$state,array $source,array $weapon,array $options=[]):?array {
 $usage=assertWeaponAttackLimit($state,$source,$weapon,$options);if(!$usage['active']||!$usage['limit'])return null;$requestId=$options['requestId']??'';
 if(!is_string($requestId)||$requestId===''||strlen($requestId)>180)throw new RuntimeException('Identifiant de tentative requis.',400);
 $previous=null;foreach($state['actionTimers']??[]as$entry)if(($entry['id']??'')===$usage['timerId']){$previous=$entry;break;}
 if(in_array($requestId,$usage['requestIds'],true))return $previous;
 $now=(int)floor(microtime(true)*1000);$timer=[...($previous??[]),'id'=>$previous['id']??'weapon-turn-'.bin2hex(random_bytes(16)),'kind'=>'weapon-turn-limit','system'=>true,
 'abilityId'=>'weapon-turn-'.$weapon['id'],'weaponId'=>$weapon['id'],'unitId'=>$usage['unitId'],'sceneId'=>$usage['sceneId'],
 'characterId'=>str_starts_with($usage['unitId'],'unit_')?'':$usage['unitId'],'tokenId'=>$source['unitTokenId']??$source['id']??'','label'=>$weapon['name']??'Arme','ownerLabel'=>$source['name']??'','ownerPlayerId'=>$options['ownerPlayerId']??$source['ownerPlayerId']??$source['controllerPlayerId']??'',
 'combatId'=>$usage['combatId'],'turnKey'=>$usage['turnKey'],'useCount'=>$usage['used']+1,'requestIds'=>[...$usage['requestIds'],$requestId],'weaponUseLimit'=>$usage['limit'],
 'cooldown'=>0,'cooldownActive'=>false,'restRecharge'=>'none','reusableInTurn'=>false,'visibility'=>'private','createdByRole'=>'gm','createdAt'=>$previous['createdAt']??$now,'updatedAt'=>$now];
 $state['actionTimers']=[$timer,...array_values(array_filter($state['actionTimers']??[],static fn($entry)=>($entry['id']??'')!==$timer['id']))];return $timer;
}
function weaponAttackPlanReason(array $state,array $source,array $plan,array $options=[]):string {
 $weapons=$source['abilityWeaponAttacks']??$source['weaponAttacks']??[];$counts=[];
 foreach($plan as$entry){$id=$entry['weaponAttackId']??$entry['attackId']??'';$weapon=null;foreach($weapons as$candidate)if(($candidate['id']??'')===$id){$weapon=$candidate;break;}if($weapon===null)continue;
 $count=$entry['count']??1;if(is_string($count)&&preg_match('/^[1-9]\d*$/D',$count)!==1||!is_numeric($count)||(float)$count!==floor((float)$count)||$count<1||$count>100)return 'Nombre de frappes invalide.';$planned=$counts[$id]??0;
 for($index=0;$index<(int)$count;$index++){$reason=weaponAttackLimitReason($state,$source,$weapon,[...$options,'plannedCount'=>$planned+$index]);if($reason!=='')return $reason;}$counts[$id]=$planned+(int)$count;
 }return '';
}
function resetWeaponAttackUse(array &$state,array $source,array $weapon,array $options=[]):?array {
 if(($options['isGm']??false)!==true)throw new RuntimeException('Seul le MJ peut réinitialiser cette arme.',403);$usage=weaponTurnUsage($state,$source,$weapon,$options);$timer=null;
 foreach($state['actionTimers']??[]as$entry)if(($entry['id']??'')===$usage['timerId']){$timer=$entry;break;}
 $state['actionTimers']=array_values(array_filter($state['actionTimers']??[],static fn($entry)=>($entry['id']??'')!==$usage['timerId']));return $timer;
}
