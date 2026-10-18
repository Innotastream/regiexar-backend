<?php
declare(strict_types=1);
function applicationMessageOutcomes():array{return ['critical-success'=>'Réussite critique','success'=>'Réussite','failure'=>'Échec','critical-failure'=>'Échec critique'];}
function applicationAbilityMessage(mixed $v):array {
 if(!is_array($v)||($v['kind']??'')!=='message')return ['version'=>1,'kind'=>'none'];
 if(($v['version']??1)!==1)throw new RuntimeException('Ce message provient d’une version plus récente.',400);
 $text=trim(preg_replace('/[\x00\r\n]/',' ',(string)($v['text']??'')));$duration=$v['durationMs']??5000;$theme=$v['theme']??'neutral';
 if($text===''||!is_int($duration)||$duration<500||$duration>30000||!in_array($theme,['neutral','poison','divine','verdant-metal','infernal'],true))throw new RuntimeException('Message, thème ou durée invalides.',400);
 $text=function_exists('mb_substr')?mb_substr($text,0,240,'UTF-8'):implode('',array_slice(preg_split('//u',$text,-1,PREG_SPLIT_NO_EMPTY),0,240));return ['version'=>1,'kind'=>'message','text'=>$text,'theme'=>$theme,'durationMs'=>$duration];
}
function applicationOutcomeMessages(mixed $v):?array {
 if(!$v)return null;if(!is_array($v)||($v['version']??1)!==1)throw new RuntimeException('Messages de compétence invalides.',400);$entries=[];
 foreach(applicationMessageOutcomes() as $k=>$label)if(($v['entries'][$k]['kind']??'')==='message')$entries[$k]=applicationAbilityMessage($v['entries'][$k]);return ['version'=>1,'entries'=>$entries];
}
function applicationManualD100Outcome(int $raw,string $code='pending'):array {
 if($code!=='pending'&&!isset(applicationMessageOutcomes()[$code]))throw new RuntimeException('Choisissez le résultat arbitré par le MJ.',400);
 return ['code'=>$code,'label'=>$code==='pending'?'Arbitrage MJ requis':applicationMessageOutcomes()[$code],'raw'=>$raw,'result'=>$raw,'modifier'=>0,'resultModifier'=>0,'baseThreshold'=>null,'threshold'=>null,'effect'=>false,'success'=>in_array($code,['success','critical-success'],true),'requiresGmValidation'=>$code==='pending','manual'=>true];
}
function applicationOutcomeCompletionCue(array $plan,array $cast):array {
 $base=$plan['completionCue']??['version'=>1,'trigger'=>'completed','sound'=>null,'visual'=>['version'=>1,'kind'=>'none']];$message=$plan['outcomeMessages']['entries'][$cast['outcome']['code']??'']??null;
 return $message?[...$base,'visual'=>$message]:(!empty($cast['success'])?$base:['version'=>1,'trigger'=>'completed','sound'=>null,'visual'=>['version'=>1,'kind'=>'none']]);
}
