<?php
declare(strict_types=1);
function applicationKnockback(mixed $value): ?array {
    if ($value===null) return null;
    if (!is_array($value)||($value['version']??1)!==1) throw new RuntimeException('Cette poussée provient d’une version plus récente.',422);
    $result=['version'=>1];
    foreach(['dieSides'=>[2,1000],'bandSize'=>[1,1000],'cellsPerBand'=>[1,20],'maximumCells'=>[1,100]] as $key=>[$min,$max]){
        if(!is_int($value[$key]??null)||$value[$key]<$min||$value[$key]>$max)throw new RuntimeException('Renseignez un dé, des tranches et une distance de poussée valides.',422);
        $result[$key]=$value[$key];
    }
    if(($value['pushAside']??false)===true){$result['pushAside']=true;$result['sideStepCells']=is_int($value['sideStepCells']??null)&&$value['sideStepCells']>=1&&$value['sideStepCells']<=10?$value['sideStepCells']:1;}
    return $result;
}
function applicationDamageKnockbackRoll(mixed $value,array $rolled): ?array {
    $config=applicationKnockback($value);if($config===null)return null;
    foreach($rolled['dice']??[] as $die)if(($die['sides']??null)===$config['dieSides']&&($die['sign']??null)===1&&($die['count']??null)===1){
        $raw=$die['results'][0]??null;
        if(!is_int($raw)||$raw<1||$raw>$config['dieSides'])return null;
        return ['raw'=>$raw,'dieSides'=>$config['dieSides'],'requestedCells'=>min($config['maximumCells'],(int)ceil($raw/$config['bandSize'])*$config['cellsPerBand'])];
    }
    return null;
}
