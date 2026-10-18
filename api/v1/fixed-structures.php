<?php
declare(strict_types=1);
function applicationIsNonCombatant(array $t):bool{return ($t['nonCombatant']??false)===true||!empty($t['terrainBarrier'])||!empty($t['fixedStructure']);}
function applicationFilterNonCombatantInitiative(array $clock,array $tokens):array {
 $order=$clock['order']??[];$current=$order[$clock['currentIndex']??0]??null;$byId=array_column($tokens,null,'id');
 $clock['order']=array_values(array_filter($order,static fn($id)=>!isset($byId[$id])||!applicationIsNonCombatant($byId[$id])));
 $clock['currentIndex']=$current!==null&&in_array($current,$clock['order'],true)?array_search($current,$clock['order'],true):min(max(0,(int)($clock['currentIndex']??0)),max(0,count($clock['order'])-1));
 if(!$clock['order']){$clock['active']=false;$clock['turnsStarted']=false;}return $clock;
}
function applicationFilterPendingFixedInitiative(PDO $db,array &$pending):void {
 foreach($pending as &$change){
  if(!str_starts_with($change['key']??'','initiative:')||($change['operation']??'upsert')!=='upsert')continue;
  $scene=substr($change['key'],11);$clock=$change['payload'];$keys=array_map(static fn($id)=>'token:'.$scene.':'.$id,$clock['order']??[]);$records=$keys?applicationDomainRecords($db,$keys):[];
  foreach($pending as $candidate)if(in_array($candidate['key']??'',$keys,true)){if(($candidate['operation']??'upsert')==='delete')unset($records[$candidate['key']]);else $records[$candidate['key']]=$candidate;}
  $change['payload']=applicationFilterNonCombatantInitiative($clock,array_values(array_map(static fn($entry)=>$entry['payload'],$records)));
 }unset($change);
}
function applicationFixedStructureDefinition(array $v):array {
 if(($v['version']??1)!==1)throw new RuntimeException('Cet élément fixe provient d’une version plus récente.',400);
 $variants=$v['variants']??[['id'=>'medium','name'=>'Moyenne','size'=>50,'hp'=>30,'uses'=>1],['id'=>'large','name'=>'Grosse','size'=>70,'hp'=>60,'uses'=>2],['id'=>'huge','name'=>'Très grosse','size'=>90,'hp'=>90,'uses'=>3]];
 if(!is_array($variants)||!array_is_list($variants)||count($variants)<1||count($variants)>8||count(array_unique(array_column($variants,'id')))!==count($variants))throw new RuntimeException('Variantes de l’élément fixe invalides.',400);
 foreach($variants as &$e){if(!is_array($e)||preg_match('/^[-\w]{1,80}$/D',(string)($e['id']??''))!==1||!is_int($e['size']??null)||$e['size']<10||$e['size']>220||!is_int($e['hp']??null)||$e['hp']<1||$e['hp']>1000000000||!is_int($e['uses']??null)||$e['uses']<1||$e['uses']>100)throw new RuntimeException('Tailles, PV ou utilisations invalides.',400);$e=['id'=>$e['id'],'name'=>dsTextSlice((string)($e['name']??$e['id']),0,100),'size'=>$e['size'],'hp'=>$e['hp'],'uses'=>$e['uses']];}unset($e);
 return ['version'=>1,'kind'=>'structure','interactionRangeCells'=>dsInt($v['interactionRangeCells']??null,0,100,0),'preparationTurns'=>dsInt($v['preparationTurns']??null,1,20,1),'variants'=>$variants,'contactRadiusCells'=>dsInt($v['contactRadiusCells']??null,0,20,1),
 'resistanceStatId'=>in_array($v['resistanceStatId']??'',dsStats(),true)?$v['resistanceStatId']:'character-stat-agility','affectCreator'=>($v['affectCreator']??false)===true,
 'conditionRider'=>dsRider($v['conditionRider']??['version'=>1,'label'=>'Immobilisé','turns'=>1,'resistance'=>false,'blocksMovement'=>true,'expiry'=>'end-next-turn','escape'=>['statId'=>'character-stat-force','penalties'=>[-20],'atTurnStart'=>true]])];
}
function applicationStructureVariant(array $definition,string $id=''):array {foreach($definition['variants'] as $v)if($v['id']===($id?:$definition['variants'][0]['id']))return $v;throw new RuntimeException('Choisissez une taille de l’élément fixe.',400);}
function applicationCreateFixedStructureToken(array $d,string $id):array {
 $v=applicationStructureVariant($d['definition'],$d['variantId']??'');return ['id'=>$id,'name'=>$d['name'],'image'=>$d['image']?:null,'color'=>'#c0bdd1','frameVariant'=>'creature','x'=>$d['x'],'y'=>$d['y'],'layerId'=>$d['layerId']??'ground','size'=>$v['size'],'hp'=>$v['hp'],'maxHp'=>$v['hp'],'mana'=>0,'maxMana'=>0,
 'fixedStructure'=>['version'=>1,'deviceId'=>$d['id'],'abilityId'=>$d['abilityId'],'creatorTokenId'=>$d['sourceTokenId'],'definition'=>$d['definition']],'nonCombatant'=>true,'immovable'=>true,'followCharacter'=>false,'characterId'=>null,'controllerPlayerId'=>null,
 'armorCategory'=>'none','armor'=>0,'magicArmorCategory'=>'none','magicArmor'=>0,'mountable'=>false,'mountControllable'=>false,'hidden'=>false,'revealDetailsToPlayers'=>true,'abilities'=>[],'weaponAttacks'=>[],'stats'=>[],'conditions'=>[],'condition'=>'','_updatedAt'=>(int)floor(microtime(true)*1000)];
}
function applicationProtectFixedStructure(array $before,array $next):array {
 if(empty($before['fixedStructure']))return $next;foreach(['x','y','layerId'] as $k)if(($next[$k]??null)!==($before[$k]??null))throw new RuntimeException('Cet élément fixe reste à son emplacement.',409);
 return [...$next,'fixedStructure'=>$before['fixedStructure'],'nonCombatant'=>true,'immovable'=>true,'characterId'=>null,'controllerPlayerId'=>null,'followCharacter'=>false,'mountable'=>false,'mountControllable'=>false,'armorCategory'=>'none','armor'=>0,'magicArmorCategory'=>'none','magicArmor'=>0,'abilities'=>[],'weaponAttacks'=>[],'stats'=>[]];
}
function applicationCompleteFixedStructure(array &$state,array &$device,array &$effects,array $context):void {
 $id='structure-'.$device['id'];$existing=dsIndex($state['map']['tokens'],$id)>=0;if(!$existing)$state['map']['tokens'][]=applicationCreateFixedStructureToken($device,$id);
 $device['status']='spent';$device['structureTokenId']=$id;$device['revision']++;if(!$existing)$effects[]=['type'=>'structure.created','tokenId'=>$id,'sceneId'=>$device['sceneId']];
}
function applicationAdvanceFixedStructures(array &$state,array $previous,array &$effects,array $context,bool $manual=false):void {
 $clock=$state['initiative'];foreach($state['devices']['devices'] as &$d){if($d['definition']['kind']!=='structure'||$d['status']!=='preparing'||$d['sceneId']!==$state['activeSceneId'])continue;
 if(!$manual&&(!($previous['active']??false)||!($clock['active']??false)||($clock['turnSerial']??0)<=($d['activatedTurn']??0)||($clock['order'][$clock['currentIndex']??0]??'')!==$d['sourceTokenId']))continue;
 $d['preparationRemainingTurns']=max(0,($d['preparationRemainingTurns']??1)-1);$d['revision']++;if(!$d['preparationRemainingTurns'])applicationCompleteFixedStructure($state,$d,$effects,$context);
 }unset($d);
}
function applicationCloseFixedEscapeWindows(array &$state,string $sourceId,string $sceneId):void {
 $clock=$state['initiative']??[];if(!($clock['active']??false)||($clock['order'][$clock['currentIndex']??0]??'')!==$sourceId)return;
 foreach($state['devices']['timedConditions'] as &$c)if($c['sceneId']===$sceneId&&$c['targetTokenId']===$sourceId&&!empty($c['escape']['atTurnStart']))$c['escapeWindowClosed']=true;unset($c);
}
function onlineCloseFixedEscapeWindows(PDO $db,array &$records,array &$pending,string $sourceId,string $sceneId):void {
 if($sourceId===''||$sceneId==='')return;
 $records=array_replace($records,applicationDomainRecords($db,['devices','initiative:'.$sceneId]));
 $store=$pending['devices']['payload']??applicationDomainPayload($records,'devices');if(empty($store['timedConditions']))return;
 $state=['activeSceneId'=>$sceneId,'devices'=>$store,'initiative'=>$pending['initiative:'.$sceneId]['payload']??applicationDomainPayload($records,'initiative:'.$sceneId)];
 applicationCloseFixedEscapeWindows($state,$sourceId,$sceneId);
 if($state['devices']!==$store)queueOnlineDomainUpsert($pending,$records,'devices',$state['devices']);
}
