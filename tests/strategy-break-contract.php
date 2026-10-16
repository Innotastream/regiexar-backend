<?php
declare(strict_types=1);
require __DIR__.'/tactical-lifecycle.php';
$db=fixture();$args=['requestId'=>'strategy-first-000001','action'=>'start'];
requireTactical(runCommand($db,'strategy-break',$args)->status===403,'Players cannot start a strategy break');
$start=runCommand($db,'strategy-break',$args,true);requireTactical($start->status===200,'Native strategy break: '.$start->getMessage());
$pause=$start->body['strategyBreak'];requireTactical($pause['endsAt']-$pause['startedAt']===300000,'Five minutes are fixed server-side');
requireTactical(is_int($start->body['serverNow']??null)&&$start->body['serverNow']>=$pause['startedAt'],'The GM command carries authoritative server time');
requireTactical(runCommand($db,'strategy-break',$args,true)->body['deduplicated']===true,'Start retry is idempotent');
$clockRetry=runCommand($db,'strategy-break',$args,true);
requireTactical(is_int($clockRetry->body['serverNow']??null)&&$clockRetry->body['serverNow']>=$start->body['serverNow'],'A replay returns fresh server time instead of the receipt timestamp');
foreach(['token.move','token.roll','roll','token.attack','token.attack.oppose','ability.use','ability.complex','character.patch','character.create','character.delete',
    'item.pickup','item.transfer','item.destroy','passage.use','light.carry','mechanics.command','timer.create','token.resource.adjust','action.undo','rest.announce','ping'] as $command)
    requireTactical(runCommand($db,$command,['requestId'=>'blocked-strategy-00001'])->status===423,'Pause blocks '.$command);
$stopArgs=['requestId'=>'strategy-stop-000001','action'=>'stop','id'=>$pause['id']];
$stop=runCommand($db,'strategy-break',$stopArgs,true);requireTactical($stop->status===200&&isset($stop->body['strategyBreak']['stoppedAt']),'GM can stop early');
requireTactical(runCommand($db,'strategy-break',$args,true)->body['deduplicated']===true,'A retry after stop cannot restart the break');
$next=runCommand($db,'strategy-break',['requestId'=>'strategy-second-0001','action'=>'start'],true);
requireTactical(runCommand($db,'strategy-break',[...$stopArgs,'requestId'=>'strategy-stale-00001'],true)->status===409,'Stale stop cannot stop the next break');
$table=$db->payload('table');$table['tacticalSync']['paused']=false;$table['strategyBreak']['startedAt']=(int)floor(microtime(true)*1000)-300001;
$table['strategyBreak']['endsAt']=$table['strategyBreak']['startedAt']+300000;$db->put('table',$table);
requireTactical(!applicationStrategyBreakActive($table['strategyBreak']),'Expired timer releases server lock');
$roll=runCommand($db,'token.roll',['requestId'=>'strategy-resume-0001','sceneId'=>'scene-one','layerId'=>'ground','tokenId'=>'token-player','kind'=>'stat','statId'=>'character-stat-force']);
requireTactical($roll->status===200,'Player actions resume automatically: '.$roll->getMessage());
requireTactical(!applicationStrategyBreakActive(['id'=>'strategy-boundary-01','startedAt'=>1,'endsAt'=>300001],300001),'Exact zero is unlocked');
echo "strategy-break-contract: ok\n";
