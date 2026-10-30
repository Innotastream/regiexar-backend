<?php
declare(strict_types=1);
function applicationNormalizeComplexDefenseParty(mixed $value):array{
 if(!is_array($value))throw new RuntimeException('L’identité des participants à la décision MJ est invalide.',409);
 foreach(['unitId','controllerId','layerId']as$key)if(!is_string($value[$key]??null))throw new RuntimeException('L’identité des participants à la décision MJ est invalide.',409);
 foreach(['x','y','size']as$key)if((!is_int($value[$key]??null)&&!is_float($value[$key]??null))||!is_finite((float)$value[$key]))throw new RuntimeException('L’identité des participants à la décision MJ est invalide.',409);
 if($value['size']<=0)throw new RuntimeException('L’identité des participants à la décision MJ est invalide.',409);
 return array_intersect_key($value,array_flip(['unitId','controllerId','layerId','x','y','size']));
}
function applicationNormalizeComplexDefenseGate(mixed $value):?array{
 if($value===null)return null;
 if(!is_array($value))throw new RuntimeException('La décision MJ des défenses est invalide.',409);
 $ids=$value['targetTokenIds']??null;$valid=is_array($value)&&($value['version']??null)===1&&in_array($value['status']??null,['pending','approved'],true)&&in_array($value['kind']??null,['allocated','charge'],true)&&is_string($value['stepId']??null)&&preg_match('/^[A-Za-z0-9_-]{1,120}$/D',$value['stepId'])===1&&is_array($ids)&&array_is_list($ids)&&count($ids)>0&&count($ids)<=100&&count(array_unique($ids,SORT_REGULAR))===count($ids)&&is_int($value['requestedAt']??null)&&$value['requestedAt']>=0&&$value['requestedAt']<=9007199254740991;
 if($valid)foreach($ids as$id)if(!is_string($id)||preg_match('/^[A-Za-z0-9_-]{1,120}$/D',$id)!==1){$valid=false;break;}
 if(!$valid)throw new RuntimeException('La décision MJ des défenses est invalide.',409);
 $result=['version'=>1,'status'=>$value['status'],'stepId'=>$value['stepId'],'kind'=>$value['kind'],'targetTokenIds'=>$ids,'requestedAt'=>$value['requestedAt']];
 $eligible=$value['defenseEligibleTokenIds']??$ids;if(!is_array($eligible)||!array_is_list($eligible)||count(array_unique($eligible,SORT_REGULAR))!==count($eligible))throw new RuntimeException('Les cibles pouvant se défendre sont invalides.',409);foreach($eligible as$id)if(!in_array($id,$ids,true))throw new RuntimeException('Les cibles pouvant se défendre sont invalides.',409);$result['defenseEligibleTokenIds']=$eligible;
 if(isset($value['parties'])){
  $targets=$value['parties']['targets']??null;if(!is_array($targets)||count($targets)!==count($ids))throw new RuntimeException('L’identité des participants à la décision MJ est invalide.',409);
  $parties=[];foreach($ids as$id){if(!array_key_exists($id,$targets))throw new RuntimeException('L’identité des participants à la décision MJ est invalide.',409);$parties[$id]=applicationNormalizeComplexDefenseParty($targets[$id]);}
  $result['parties']=['source'=>applicationNormalizeComplexDefenseParty($value['parties']['source']??null),'targets'=>$parties];
 }
 if($value['status']==='approved')$result['defenseTokenIds']=applicationComplexDefenseSelection($result,$value['defenseTokenIds']??null);
 return $result;
}
function applicationComplexDefenseEligibleTokenIds(array $execution,array $ids,array $tokens):array{if(($execution['noDefensePossible']??false)===true)return [];$eligible=[];foreach($ids as$id)foreach($tokens as$token)if(($token['id']??'')===$id&&(float)($token['hp']??$token['resources']['hp']??0)>0){$eligible[]=$id;break;}return $eligible;}
function applicationCreateComplexDefenseGate(string $stepId,string $kind,array $targetTokenIds,int $now,?array $defenseEligibleTokenIds=null):array{return applicationNormalizeComplexDefenseGate(['version'=>1,'status'=>'pending','stepId'=>$stepId,'kind'=>$kind,'targetTokenIds'=>array_values(array_unique($targetTokenIds)),'defenseEligibleTokenIds'=>$defenseEligibleTokenIds??$targetTokenIds,'requestedAt'=>$now]);}
function applicationComplexDefenseSelection(array $gate,mixed $ids):array{
 if(!is_array($ids)||!array_is_list($ids)||count(array_unique($ids,SORT_REGULAR))!==count($ids))throw new RuntimeException('Choisissez les défenses parmi les cibles de cette action.',409);
 foreach($ids as$id)if(!in_array($id,$gate['defenseEligibleTokenIds']??$gate['targetTokenIds'],true))throw new RuntimeException('Choisissez les défenses parmi les cibles de cette action.',409);
 return $ids;
}
function applicationComplexAbilityDefenseAllowed(array $execution,string $targetTokenId,string $stepId=''):?bool{
 if(($execution['noDefensePossible']??false)===true)return false;
 $gate=applicationNormalizeComplexDefenseGate($execution['defenseGate']??null);if(($gate['status']??'')==='pending')return null;
 $step=null;$id=$stepId!==''?$stepId:($execution['workflow']['steps'][$execution['currentStepIndex']]['id']??'');foreach($execution['workflow']['steps']??[]as$entry)if($entry['id']===$id)$step=$entry;
 if(($step['type']??'')==='allocated-attacks'){$source=$execution['stepStates'][$step['sourceStepId']]??[];return is_array($source['defenseTokenIds']??null)?in_array($targetTokenId,$source['defenseTokenIds'],true):null;}
 if(($step['type']??'')==='movement'&&!empty($step['charge'])){if(($gate['status']??'')==='approved'&&$gate['stepId']===$step['id'])return in_array($targetTokenId,$gate['defenseTokenIds'],true);$state=$execution['stepStates'][$step['id']]??[];return ($state['defenseDecisionByGm']??false)===true?($state['chargeDefenses'][$targetTokenId]??false)===true:null;}
 return null;
}
