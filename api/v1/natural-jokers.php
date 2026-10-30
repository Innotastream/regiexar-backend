<?php
declare(strict_types=1);
// Only dice produced in this PHP request can supply credit evidence. Stored
// receipts and client JSON cannot register their old identifiers again.
function applicationMarkNaturalRoll(array $roll):array {
 if(($roll['rawD100']??null)===55){$id=randomToken(16);$GLOBALS['naturalJokerProofs'][$id]=false;$roll['_naturalJokerProof']=$id;}return $roll;
}
function applicationCopyNaturalRoll(array $from,array $entry):array {
 $id=$from['_naturalJokerProof']??null;if(is_string($id)&&array_key_exists($id,$GLOBALS['naturalJokerProofs']??[]))$GLOBALS['naturalJokerEntries'][$entry['id']]=$id;return $entry;
}
function onlineAwardNaturalJoker(PDO $db,array &$records,array &$pending,array $source,array &$roll,?array $outcome,string $sceneId):bool {
 $proof=$GLOBALS['naturalJokerEntries'][$roll['id']??'']??$roll['_naturalJokerProof']??null;
 if(!is_string($proof)||!array_key_exists($proof,$GLOBALS['naturalJokerProofs']??[])||$GLOBALS['naturalJokerProofs'][$proof]||($outcome['raw']??null)!==55||($outcome['code']??'')!=='special-success'||($outcome['joker']??false)||($outcome['resultModifierApplied']??false)||($outcome['result']??$outcome['raw']??null)!==55)return false;
 $characterId=unitUsesCharacterSheet($source)?(string)$source['characterId']:'';
 if($characterId===''&&!isset($source['x'])&&($source['unitKind']??'')!=='token'&&!empty($source['id'])){$candidate='character:'.(string)$source['id'];if(validApplicationDomainKey($candidate)){$records=array_replace($records,applicationDomainRecords($db,[$candidate]));if(($pending[$candidate]['payload']??applicationDomainPayload($records,$candidate))!==[])$characterId=(string)$source['id'];}}$key=$characterId!==''?'character:'.$characterId:onlineTokenDomainKey($sceneId,(string)($source['id']??''));
 if(!validApplicationDomainKey($key))return false;
 $records=array_replace($records,applicationDomainRecords($db,[$key,'roster']));$holder=$pending[$key]['payload']??applicationDomainPayload($records,$key);if($holder===[])return false;
 $controller=(string)($source['controlEffect']['controllerId']??($characterId!==''?($holder['ownerPlayerId']??''):($holder['controllerPlayerId']??$holder['ownerPlayerId']??'')));
 $roster=$pending['roster']['payload']??applicationDomainPayload($records,'roster');$eligible=false;foreach($roster['players']??[] as $player)if(($player['id']??$player['accountId']??'')===$controller&&($player['role']??'')!=='gm'&&$controller!=='')$eligible=true;
 if(!$eligible)return false;$GLOBALS['naturalJokerProofs'][$proof]=true;$before=normalizeSpecialSuccessStock($holder['specialSuccessStock']??0);$holder['specialSuccessStock']=min(9999,$before+1);$holder['_updatedAt']=(int)floor(microtime(true)*1000);queueOnlineDomainUpsert($pending,$records,$key,$holder);
 if($characterId!==''){$related=applicationCharacterTokenDomainRecords($db,$characterId);$records=array_replace($records,$related);foreach($related as$tokenKey=>$record){$token=$pending[$tokenKey]['payload']??$record['payload'];if(!unitUsesCharacterSheet($token)||($token['characterId']??'')!==$characterId)continue;$token['specialSuccessStock']=$holder['specialSuccessStock'];$token['_updatedAt']=$holder['_updatedAt'];queueOnlineDomainUpsert($pending,$records,$tokenKey,$token);}}
 if($holder['specialSuccessStock']>$before){$roll['specialSuccessAwarded']=true;return true;}return false;
}
