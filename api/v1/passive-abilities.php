<?php
declare(strict_types=1);
function applicationPassiveKinds():array {return ['opportunity-attack'=>['name'=>'Attaque d’opportunité','description'=>'Lorsqu’un ennemi quitte une case adjacente, propose au MJ une attaque avec l’arme principale.'],
    'mental-control-resistance'=>['name'=>'Boss : résistance au contrôle mental','description'=>'Un contrôle mental réussi devient une simple influence de même durée. Le contrôleur du pion ne change pas.']];}
function applicationPassiveAbilities(mixed $v):array {
    if($v===null)return [];if(!is_array($v)||!array_is_list($v)||count($v)>100)throw new RuntimeException('La liste des compétences passives est invalide.',400);
    $kinds=applicationPassiveKinds();$seen=[];$r=[];
    foreach($v as $e){if(!is_array($e)||($e['version']??0)!==1||!isset($kinds[$e['kind']??'']))throw new RuntimeException('Ce passif utilise une version ou une mécanique non prise en charge.',400);
        $id=(string)($e['id']??$e['kind']);if(!preg_match('/^[A-Za-z0-9_-]{1,120}$/D',$id)||isset($seen[$id]))throw new RuntimeException('Identité de passif invalide.',400);$seen[$id]=true;
        $p=['version'=>1,'id'=>$id,'kind'=>$e['kind'],'name'=>substr((string)($e['name']??$kinds[$e['kind']]['name']),0,120),'description'=>substr((string)($e['description']??$kinds[$e['kind']]['description']),0,4000),'enabled'=>($e['enabled']??true)!==false];
        if($e['kind']==='opportunity-attack'){$p['reachCells']=is_int($e['reachCells']??null)&&$e['reachCells']>=1&&$e['reachCells']<=20?$e['reachCells']:1;$p['statId']=in_array($e['statId']??'',array_map(static fn($k)=>'character-stat-'.$k,['force','dexterity','agility','spiritSocial','intelligence','instinct']),true)?$e['statId']:'character-stat-force';}$r[]=$p;
    }return $r;
}
function applicationHasPassive(array $source,string $kind):bool {foreach(applicationPassiveAbilities($source['passiveAbilities']??null) as $p)if($p['enabled']&&$p['kind']===$kind)return true;return false;}
function applicationMovementAvoidsOpportunity(array $executions,string $scene,string $token):bool {foreach($executions as $e){$step=$e['workflow']['steps'][$e['currentStepIndex']??-1]??[];if(($e['status']??'')==='active'&&($e['sceneId']??'')===$scene&&($e['sourceTokenId']??'')===$token&&($step['type']??'')==='movement'&&($step['avoidsOpportunity']??false)===true)return true;}return false;}
function applicationOpportunityRequests(array $map,array $mover,array $start,array $destination,mixed $path,string $sceneId,callable $controller,callable $passives):array {
    if(($mover['hidden']??false)||!empty($mover['terrainBarrier'])||($mover['hp']??0)<=0||($mover['healthOverride']??'')==='dead')return [];
    $layer=$mover['layerId']??'ground';$level=$map['layers'][$layer]??$map;$nw=$level['naturalWidth']??$map['naturalWidth']??1600;$nh=$level['naturalHeight']??$map['naturalHeight']??900;$grid=$map['gridSize']??50;
    $route=is_array($path)&&$path!==[]?$path:[$start,$destination];$contact=static fn($a,$b,$range)=>max(abs($a['x']-$b['x'])*$nw/(100*$grid),abs($a['y']-$b['y'])*$nh/(100*$grid))<=$range+1e-7;$r=[];
    foreach($map['tokens']??[] as $enemy){
        if($enemy['id']===$mover['id']||($enemy['hidden']??false)||!empty($enemy['terrainBarrier'])||($enemy['layerId']??'ground')!==$layer||($enemy['hp']??0)<=0||($enemy['healthOverride']??'')==='dead'||($controller($enemy)!=='')===($controller($mover)!==''))continue;
        $passive=null;foreach(applicationPassiveAbilities($passives($enemy)) as $p)if($p['enabled']&&$p['kind']==='opportunity-attack'){$passive=$p;break;}
        if(!$passive||empty($enemy['weaponAttacks'][0])||!$contact($enemy,$start,$passive['reachCells']))continue;$leaves=false;foreach($route as $p)if(!$contact($enemy,$p,$passive['reachCells'])){$leaves=true;break;}if(!$leaves)continue;
        $r[]=['id'=>'opportunity-'.randomToken(16),'type'=>'opportunity','sceneId'=>$sceneId,'layerId'=>$layer,'sourceTokenId'=>$enemy['id'],'tokenId'=>$enemy['id'],'targetTokenId'=>$mover['id'],'passiveId'=>$passive['id'],'label'=>$passive['name'],'statId'=>$passive['statId'],
            'sourcePosition'=>['x'=>$enemy['x'],'y'=>$enemy['y']],'targetPosition'=>['x'=>$destination['x'],'y'=>$destination['y']],'strikeTargetPosition'=>['x'=>$start['x'],'y'=>$start['y']],'createdAt'=>(int)floor(microtime(true)*1000)];
    }return $r;
}
