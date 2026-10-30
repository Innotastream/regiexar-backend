<?php
declare(strict_types=1);
require_once __DIR__.'/complex-movement.php';
require_once __DIR__.'/unit-authority.php';
require_once __DIR__.'/effect-formula.php';

// Persistent contacts are authority-owned effects. Painted terrain remains
// presentation data and never becomes damage merely because it looks like fire.
function applicationContactZoneId(mixed $v):bool {return is_string($v)&&preg_match('/^[A-Za-z0-9_-]{1,180}$/D',$v)===1;}
function applicationContactZoneInt(mixed $v):bool {return is_int($v)&&$v>=0&&$v<=9007199254740991;}
function applicationContactZonePoint(mixed $v):?array {
    if(!is_array($v))return null;foreach(['x','y']as$k)if(!(is_int($v[$k]??null)||is_float($v[$k]??null))||!is_finite((float)$v[$k])||$v[$k]<0||$v[$k]>100)return null;
    return ['x'=>$v['x'],'y'=>$v['y']];
}
function applicationContactZoneDefinition(mixed $v):?array {
    if($v===null)return null;
    if(!is_array($v)||($v['version']??1)!==1||($v['geometry']??'')!=='path'||!(is_int($v['radiusCells']??null)||is_float($v['radiusCells']??null))||!is_finite((float)$v['radiusCells'])||$v['radiusCells']<0||$v['radiusCells']>20
        ||($v['trigger']??'')!=='contact-during-target-turn'||($v['maximumPerTargetTurn']??null)!==1||($v['expiry']??'')!=='source-next-turn-start')throw new RuntimeException('La zone de contact est invalide.',400);
    $formula=normalizeEffectFormula($v['damage']['formula']??null);try{parseEffectFormula($formula);}catch(Throwable){throw new RuntimeException('Les dégâts de la zone sont invalides.',400);}
    if(!in_array($v['damage']['damageType']??'', ['physical','magical','ignore'],true))throw new RuntimeException('Le type de dégâts de la zone est invalide.',400);
    foreach(['affectCaster','affectAllies','affectEnemies']as$k)if(array_key_exists($k,$v)&&!is_bool($v[$k]))throw new RuntimeException('Les cibles de la zone sont invalides.',400);
    if(array_key_exists('style',$v)&&!in_array($v['style'],['silver-filament','none'],true))throw new RuntimeException('Le dessin de cette zone est invalide.',400);
    return ['version'=>1,'geometry'=>'path','radiusCells'=>$v['radiusCells'],'damage'=>['formula'=>$formula,'damageType'=>$v['damage']['damageType']],
        'affectCaster'=>($v['affectCaster']??false)===true,'affectAllies'=>($v['affectAllies']??false)===true,'affectEnemies'=>($v['affectEnemies']??true)===true,
        'trigger'=>'contact-during-target-turn','maximumPerTargetTurn'=>1,'expiry'=>'source-next-turn-start',...(array_key_exists('style',$v)?['style'=>$v['style']]:[])];
}
function applicationContactZones(mixed $value=[]):array {
    if(!is_array($value)||!array_is_list($value)||count($value)>64)throw new RuntimeException('Le registre des zones de contact est invalide.',400);
    $seen=[];$out=[];
    foreach($value as$z){
        foreach(['id','sceneId','sourceTokenId','sourceUnitId','originExecutionId']as$k)if(!applicationContactZoneId($z[$k]??null))throw new RuntimeException('Une identité de zone de contact est invalide.',400);
        if(isset($seen[$z['id']])||array_key_exists('kind',$z)&&$z['kind']!=='contact-zone'||!in_array($z['layerId']??'', ['ground','upper','basement'],true)||!is_string($z['sourceControllerId']??null)||$z['sourceControllerId']!==''&&!applicationContactZoneId($z['sourceControllerId'])
            ||!is_string($z['createdCombatId']??null)||$z['createdCombatId']!==''&&!applicationContactZoneId($z['createdCombatId'])||!applicationContactZoneInt($z['createdTurnSerial']??null)||!applicationContactZoneInt($z['createdAt']??null)||!applicationContactZoneInt($z['createdRound']??0)
            ||array_key_exists('friendlyFire',$z)&&!is_bool($z['friendlyFire'])||!is_array($z['points']??null)||!array_is_list($z['points'])||count($z['points'])<1||count($z['points'])>256)throw new RuntimeException('Une zone de contact est invalide.',400);
        $definition=applicationContactZoneDefinition($z['definition']??null);if($definition===null)throw new RuntimeException('Une définition de zone manque.',400);
        $points=[];foreach($z['points']as$p){$point=applicationContactZonePoint($p);if($point===null)throw new RuntimeException('Le trajet de zone est invalide.',400);$points[]=$point;}
        $contacts=$z['contacts']??[];if(!is_array($contacts)||!array_is_list($contacts)||count($contacts)>1000)throw new RuntimeException('Le registre de contacts est invalide.',400);
        $contactKeys=[];$normalized=[];foreach($contacts as$c){
            if(!applicationContactZoneId($c['targetUnitId']??null)||!applicationContactZoneId($c['combatId']??null)||!applicationContactZoneInt($c['turnSerial']??null)||!is_string($c['requestId']??null)||preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$c['requestId'])!==1)throw new RuntimeException('Un reçu de contact est invalide.',400);
            $key=json_encode([$c['targetUnitId'],$c['combatId'],$c['turnSerial']],JSON_THROW_ON_ERROR);if(isset($contactKeys[$key]))throw new RuntimeException('Un contact a été compté deux fois.',400);$contactKeys[$key]=true;
            $normalized[]=array_intersect_key($c,array_flip(['targetUnitId','combatId','turnSerial','requestId']));
        }
        $seen[$z['id']]=true;$out[]=['kind'=>'contact-zone','id'=>$z['id'],'sceneId'=>$z['sceneId'],'layerId'=>$z['layerId'],'sourceTokenId'=>$z['sourceTokenId'],'sourceUnitId'=>$z['sourceUnitId'],'sourceControllerId'=>$z['sourceControllerId'],
            'originExecutionId'=>$z['originExecutionId'],'definition'=>$definition,'points'=>$points,'createdCombatId'=>$z['createdCombatId'],'createdTurnSerial'=>$z['createdTurnSerial'],'createdRound'=>$z['createdRound']??0,'createdAt'=>$z['createdAt'],'friendlyFire'=>($z['friendlyFire']??false)===true,'contacts'=>$normalized];
    }
    return $out;
}
function applicationCreateContactZone(array $definition,array $context):array {
    $clock=$context['initiative']??[];
    $z=['id'=>$context['id'],'sceneId'=>$context['sceneId'],'layerId'=>$context['layerId'],'sourceTokenId'=>$context['sourceTokenId'],'sourceUnitId'=>$context['sourceUnitId'],'sourceControllerId'=>$context['sourceControllerId']??'',
        'originExecutionId'=>$context['originExecutionId'],'definition'=>$definition,'points'=>[$context['origin']],'createdCombatId'=>$clock['combatId']??'','createdTurnSerial'=>$clock['turnSerial']??0,
        'createdRound'=>$clock['round']??0,'createdAt'=>$context['now']??(int)floor(microtime(true)*1000),'friendlyFire'=>($context['friendlyFire']??false)===true,'contacts'=>[]];
    return applicationContactZones([$z])[0];
}
function applicationAppendContactZonePath(array $zone,array $points):array {
    $zone=applicationContactZones([$zone])[0];$last=$zone['points'][array_key_last($zone['points'])];
    foreach($points as$i=>$p){$p=applicationContactZonePoint($p);if($p===null)throw new RuntimeException('Le trajet de contact est invalide.',400);
        if($i===0&&(abs($p['x']-$last['x'])>1e-5||abs($p['y']-$last['y'])>1e-5))throw new RuntimeException('Le trajet de contact a changé.',409);
        if(abs($p['x']-$last['x'])<=1e-5&&abs($p['y']-$last['y'])<=1e-5)continue;$zone['points'][]=$p;$last=$p;
    }
    return applicationContactZones([$zone])[0];
}
function applicationAdvanceContactZones(array $zones,string $sceneId,array $previous,array $initiative,array $context=[]):array {
    return array_values(array_filter(applicationContactZones($zones),static function($zone)use($sceneId,$previous,$initiative,$context){
        if($zone['sceneId']!==$sceneId)return true;
        if(($previous['active']??false)&&!($initiative['active']??false))return false;
        if(!($initiative['active']??false)||($initiative['combatId']??'')!==$zone['createdCombatId'])return !($initiative['active']??false);
        $current=$initiative['order'][$initiative['currentIndex']??0]??'';$unitFor=$context['tokenUnitId']??static function($id)use($context){foreach($context['tokens']??[]as$t)if(($t['id']??'')===$id)return unitIdForToken($t);return '';};
        if(($current===$zone['sourceTokenId']||$unitFor($current)===$zone['sourceUnitId'])&&($initiative['turnSerial']??0)>$zone['createdTurnSerial'])return false;
        $present=false;foreach($initiative['order']??[]as$id)if($id===$zone['sourceTokenId']||$unitFor($id)===$zone['sourceUnitId']){$present=true;break;}
        return $present||($initiative['round']??0)<=$zone['createdRound'];
    }));
}
function applicationContactZonePointDistance(array $p,array $a,array $b):float {
    $dx=$b['x']-$a['x'];$dy=$b['y']-$a['y'];$t=max(0,min(1,(($p['x']-$a['x'])*$dx+($p['y']-$a['y'])*$dy)/($dx*$dx+$dy*$dy?:1)));
    return hypot($p['x']-$a['x']-$dx*$t,$p['y']-$a['y']-$dy*$t);
}
function applicationContactZoneSegmentsDistance(array $a,array $b,array $c,array $d):float {
    $cross=static fn($p,$q,$r)=>($q['x']-$p['x'])*($r['y']-$p['y'])-($q['y']-$p['y'])*($r['x']-$p['x']);
    if($cross($a,$b,$c)*$cross($a,$b,$d)<0&&$cross($c,$d,$a)*$cross($c,$d,$b)<0)return 0;
    return min(applicationContactZonePointDistance($a,$c,$d),applicationContactZonePointDistance($b,$c,$d),applicationContactZonePointDistance($c,$a,$b),applicationContactZonePointDistance($d,$a,$b));
}
function applicationContactZoneTouchesPath(array $zone,array $token,array $map,array $path):bool {
    $body=applicationTokenSpatialFootprint($token,$map);$width=(float)($map['naturalWidth']??1600);$height=(float)($map['naturalHeight']??900);$grid=(float)($map['gridSize']??50);
    if($width<=0||$height<=0||$grid<=0||count($zone['points'])<2||$path===[])return false;
    $px=static fn($p)=>['x'=>$p['x']*$width/100,'y'=>$p['y']*$height/100];$radius=(float)($body['size']??40)/2+$zone['definition']['radiusCells']*$grid;
    if(count($path)===1)$path[]=$path[0];
    for($i=1;$i<count($path);$i++)for($j=1;$j<count($zone['points']);$j++)if(applicationContactZoneSegmentsDistance($px($path[$i-1]),$px($path[$i]),$px($zone['points'][$j-1]),$px($zone['points'][$j]))<=$radius+1e-7)return true;
    return false;
}
function applicationContactZoneRequestId(string $zoneId,string $unitId,string $combatId,int $turnSerial):string {
    return 'contact-'.hash('sha256',json_encode([$zoneId,$unitId,$combatId,$turnSerial],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
function applicationCollectContactZoneMovement(array &$state,array $mover,array $start,array $destination,array $path=[],array $options=[]):array {
    $clock=$state['initiative']??[];$scene=$state['activeSceneId']??'';$map=$state['map']??[];
    if(!($clock['active']??false)||($clock['order'][$clock['currentIndex']??0]??'')!==($mover['id']??'')||!applicationContactZoneId($clock['combatId']??null)||!applicationContactZoneInt($clock['turnSerial']??null))return [];
    $unit=unitById($state,unitIdForToken($mover),false);$hp=$unit['resources']['hp']??$mover['hp']??0;$maximum=$unit['resources']['maxHp']??$mover['maxHp']??0;
    if($hp<=0||$maximum<=0||in_array($mover['healthOverride']??'', ['dead','down'],true))return [];
    $points=[];foreach([$start,...$path,$destination]as$p){$p=applicationContactZonePoint($p);if($p===null)throw new RuntimeException('Le trajet de contact est invalide.',400);if($points===[]||$points[array_key_last($points)]!==$p)$points[]=$p;}
    $zones=applicationContactZones($state['devices']['contactZones']??[]);$events=[];$unitId=unitIdForToken($mover);
    foreach($zones as$i=>$zone){
        if(isset($options['zoneIds'])&&!in_array($zone['id'],$options['zoneIds'],true))continue;
        if($zone['sceneId']!==$scene||$zone['layerId']!==($mover['layerId']??'ground')||$zone['createdCombatId']!==$clock['combatId'])continue;
        if($unitId===$zone['sourceUnitId']&&!$zone['definition']['affectCaster'])continue;
        $def=$zone['definition'];$affects=[...$def,'affectAllies'=>$def['affectAllies']||$zone['friendlyFire']];
        if($unitId!==$zone['sourceUnitId']&&!applicationDamageAreaAffects($affects,$zone['sourceTokenId'],$zone['sourceControllerId'],$mover['id'],applicationUnitControllerId($mover,$state)))continue;
        if(array_filter($zone['contacts'],static fn($c)=>$c['targetUnitId']===$unitId&&$c['combatId']===$clock['combatId']&&$c['turnSerial']===$clock['turnSerial']))continue;
        if(!applicationContactZoneTouchesPath($zone,$mover,$map,$points))continue;
        $requestId=isset($options['requestIdForContact'])?($options['requestIdForContact'])($zone['id'],$unitId,$clock['combatId'],$clock['turnSerial']):applicationContactZoneRequestId($zone['id'],$unitId,$clock['combatId'],$clock['turnSerial']);
        $zones[$i]['contacts'][]=['targetUnitId'=>$unitId,'combatId'=>$clock['combatId'],'turnSerial'=>$clock['turnSerial'],'requestId'=>$requestId];
        $events[]=['zoneId'=>$zone['id'],'originExecutionId'=>$zone['originExecutionId'],'sourceTokenId'=>$zone['sourceTokenId'],'sceneId'=>$scene,'layerId'=>$zone['layerId'],'targetTokenId'=>$mover['id'],'targetUnitId'=>$unitId,'requestId'=>$requestId,'damage'=>$def['damage']];
    }
    if($events!==[])$state['devices']['contactZones']=applicationContactZones($zones);return $events;
}

function applicationPublicContactZones(array $zones,array $context):array {
    if(($context['paused']??false)||empty($context['sceneId']))return [];$result=[];$visible=$context['visible']??static fn()=>true;$map=$context['map']??[];$width=(float)($map['naturalWidth']??1600)?:1600;$height=(float)($map['naturalHeight']??900)?:900;$spacing=max(1,((float)($map['gridSize']??50)?:50)*.08);
    $same=static fn($a,$b)=>abs($a['x']-$b['x'])<=1e-5&&abs($a['y']-$b['y'])<=1e-5;
    foreach(applicationContactZones($zones)as$zone){
        if($zone['sceneId']!==$context['sceneId']||$zone['layerId']!==($context['layerId']??'ground')||($zone['definition']['style']??'')!=='silver-filament'||count($zone['points'])<2)continue;
        $paths=[];$fragment=[];$budget=8192;
        for($i=1;$i<count($zone['points'])&&$budget>0;$i++){
            $a=$zone['points'][$i-1];$b=$zone['points'][$i];$count=max(1,min(512,(int)ceil(hypot(($b['x']-$a['x'])*$width/100,($b['y']-$a['y'])*$height/100)/$spacing)));
            for($j=$i===1?0:1;$j<=$count&&$budget>0;$j++,$budget--){$p=['x'=>$a['x']+($b['x']-$a['x'])*$j/$count,'y'=>$a['y']+($b['y']-$a['y'])*$j/$count];
                if($visible($p['x'],$p['y'])){if($fragment===[]||!$same($fragment[array_key_last($fragment)],$p))$fragment[]=$p;}
                else{if(count($fragment)>1)$paths[]=$fragment;$fragment=[];}
            }
        }
        if(count($fragment)>1)$paths[]=$fragment;
        if($paths!==[])$result[]=['id'=>$zone['id'],'kind'=>'contact-zone','sceneId'=>$zone['sceneId'],'layerId'=>$zone['layerId'],'paths'=>$paths,'radiusCells'=>$zone['definition']['radiusCells'],'style'=>$zone['definition']['style']];
    }
    return $result;
}
