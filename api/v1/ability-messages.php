<?php
declare(strict_types=1);
function applicationMessageOutcomes():array{return ['critical-success'=>'Réussite critique','success'=>'Réussite','failure'=>'Échec','critical-failure'=>'Échec critique'];}
function applicationVisualD75Choices():array {
 return ['fantastic'=>'critical-success','good'=>'success','bad'=>'critical-failure'];
}
function applicationConfiguredPrayerAbility(array $ability):array {
 if(($ability['id']??'')!=='ability-2cd21fa7-4e03-4167-b6e0-ccd87c3f4f43'||($ability['effect']??'')!=='movement'
    ||($ability['manualD100']??false)!==true)return $ability;
 unset($ability['manualD100']);
 $ability['castingStatId']='';
 $ability['visualD75']=true;
 return $ability;
}
function applicationAbilityMessage(mixed $v):array {
 if(!is_array($v)||($v['kind']??'')!=='message')return ['version'=>1,'kind'=>'none'];
 if(($v['version']??1)!==1)throw new RuntimeException('Ce message provient d’une version plus récente.',400);
 $text=trim(preg_replace('/[\x00\r\n]/',' ',(string)($v['text']??'')));$duration=$v['durationMs']??5000;$theme=$v['theme']??'neutral';
 if($text===''||!is_int($duration)||$duration<500||$duration>30000||!in_array($theme,['neutral','poison','divine','verdant-metal','infernal',
  'prayer-parchment','prayer-divine','prayer-serene','prayer-dread'],true))throw new RuntimeException('Message, thème ou durée invalides.',400);
 if($theme==='prayer-parchment'&&preg_match('/^(?:[1-9]|[1-6][0-9]|7[0-5])$/D',$text)!==1)throw new RuntimeException('Résultat du parchemin invalide.',400);
 $afterPrayerRollId=$v['afterPrayerRollId']??null;
 if($afterPrayerRollId!==null&&(!is_string($afterPrayerRollId)||$theme==='prayer-parchment'
    ||preg_match('/^[A-Za-z0-9_-]{1,200}$/D',$afterPrayerRollId)!==1))throw new RuntimeException('Référence du parchemin invalide.',400);
 $text=function_exists('mb_substr')?mb_substr($text,0,240,'UTF-8'):implode('',array_slice(preg_split('//u',$text,-1,PREG_SPLIT_NO_EMPTY),0,240));return ['version'=>1,'kind'=>'message','text'=>$text,'theme'=>$theme,'durationMs'=>$duration,...($afterPrayerRollId!==null?['afterPrayerRollId'=>$afterPrayerRollId]:[])];
}
function applicationOutcomeMessages(mixed $v):?array {
 if(!$v)return null;if(!is_array($v)||($v['version']??1)!==1)throw new RuntimeException('Messages de compétence invalides.',400);$entries=[];
 foreach(applicationMessageOutcomes() as $k=>$label)if(($v['entries'][$k]['kind']??'')==='message')$entries[$k]=applicationAbilityMessage($v['entries'][$k]);return ['version'=>1,'entries'=>$entries];
}
function applicationManualD100Outcome(int $raw,string $code='pending',bool $criticalFailureAsFailure=false):array {
 if($code!=='pending'&&!isset(applicationMessageOutcomes()[$code]))throw new RuntimeException('Choisissez le résultat arbitré par le MJ.',400);
 if($criticalFailureAsFailure&&$code==='critical-failure')$code='failure';
 return [...($criticalFailureAsFailure?['criticalFailureAsFailure'=>true]:[]),'code'=>$code,'label'=>$code==='pending'?'Arbitrage MJ requis':applicationMessageOutcomes()[$code],'raw'=>$raw,'result'=>$raw,'modifier'=>0,'resultModifier'=>0,'baseThreshold'=>null,'threshold'=>null,'effect'=>false,'success'=>in_array($code,['success','critical-success'],true),'requiresGmValidation'=>$code==='pending','manual'=>true];
}
function applicationOutcomeCompletionCue(array $plan,array $cast):array {
 $base=$plan['completionCue']??['version'=>1,'trigger'=>'completed','sound'=>null,'visual'=>['version'=>1,'kind'=>'none']];$message=$plan['outcomeMessages']['entries'][$cast['outcome']['code']??'']??null;
 if(($cast['visualD75']??false)===true){
  $choice=applicationVisualD75Choices()[$cast['visualChoice']??'']??null;
  $configured=$plan['outcomeMessages']['entries'][$choice]??null;
  [$historicalTheme,$theme,$text]=match($choice){
   'critical-success'=>['divine','prayer-divine','Magie sauvage angélique'],
   'success'=>['verdant-metal','prayer-serene','Magie sauvage'],
   'critical-failure'=>['infernal','prayer-dread','Magie sauvage démoniaque'],
   default=>[null,null,null],
  };
  $useDedicatedTheme=$configured===null||(($configured['theme']??null)===$historicalTheme&&strcasecmp($configured['text'],$text??'')===0);
  $visual=$theme===null?['version'=>1,'kind'=>'none']:applicationAbilityMessage([
   'version'=>1,'kind'=>'message','theme'=>$useDedicatedTheme?$theme:$configured['theme'],'text'=>$configured['text']??$text,'durationMs'=>$configured['durationMs']??5000,
   'afterPrayerRollId'=>$cast['prayerRollCueEventId']??null,
  ]);
  return ['version'=>1,'trigger'=>'completed','sound'=>null,'visual'=>$visual];
 }
 return $message?[...$base,'visual'=>$message]:(!empty($cast['success'])?$base:['version'=>1,'trigger'=>'completed','sound'=>null,'visual'=>['version'=>1,'kind'=>'none'],...(isset($base['icon'])?['icon'=>$base['icon']]:[])]);
}
function applicationPrayerRollCue(int $value):array {
 return ['version'=>1,'trigger'=>'completed','visual'=>applicationAbilityMessage([
  'version'=>1,'kind'=>'message','theme'=>'prayer-parchment','text'=>(string)$value,'durationMs'=>3000,
 ])];
}
