<?php
declare(strict_types=1);
function applicationStreamCombatDetails(array $state,array $map,array $initiative,array $visibleTokens,string $sceneId):array {
 $empty=['streamTurnPreview'=>null,'streamPersonalAdvantage'=>null];
 if(($initiative['active']??false)!==true||($state['tacticalSync']['paused']??false)===true||empty($map['background'])||$sceneId==='')return $empty;
 $order=$initiative['order']??[];$index=$initiative['currentIndex']??0;if(!is_int($index)||$index<0||$index>=count($order))return $empty;
 $visible=[];foreach($visibleTokens as$t)if(empty($t['hidden'])&&empty($t['dimSilhouette']))$visible[(string)$t['id']]=$t;
 $onlyVisible=static fn($id)=>isset($visible[(string)$id])?(string)$id:null;
 $current=null;foreach($map['tokens']??[]as$t)if(($t['id']??'')===$order[$index]){$current=$t;break;}
 $characters=[];foreach($state['characters']??[]as$c)$characters[$c['id']]=$c;
 $controller=$current?onlineEffectiveTokenControllerId($current,onlineCharacterOwnerIndex($state['characters']??[])):'';$player=false;foreach($state['players']??[]as$p)if(($p['id']??$p['accountId']??'')===$controller&&($p['role']??'')!=='gm')$player=true;
 $holder=$current&&unitUsesCharacterSheet($current)?($characters[$current['characterId']]??[]):($current??[]);$stock=$holder['personalAdvantageStock']??0;$shown=$current?$visible[(string)$current['id']]??null:null;
 return ['streamTurnPreview'=>['version'=>1,'nextTokenId'=>$onlyVisible($order[($index+1)%count($order)]),'roundEndTokenId'=>$onlyVisible($order[array_key_last($order)]),'endOfRoundAfterCurrent'=>$index===count($order)-1],
 'streamPersonalAdvantage'=>$shown&&$player&&$controller!==''&&is_int($stock)&&$stock>=0&&$stock<=9007199254740991?['tokenId'=>$shown['id'],'name'=>$shown['name'],'stock'=>$stock]:null];
}
