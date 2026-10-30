<?php
declare(strict_types=1);
function applicationPersonalAdvantageStock(mixed $value):int{return is_int($value)&&$value>=0&&$value<=9007199254740991?$value:0;}
function onlinePersonalAdvantage(PDO $db,array &$records,array &$pending,array $identity,array $args,bool $consume=false):array {
 $key='character:'.(string)($args['characterId']??'');if(!validApplicationDomainKey($key))throw new RuntimeException('Cette réserve personnelle ne vous appartient pas.',403);
 $records=array_replace($records,applicationDomainRecords($db,[$key]));$character=$pending[$key]['payload']??applicationDomainPayload($records,$key);$gm=($identity['effective_mode']??'')==='gm'&&($identity['permanent_role']??'')==='gm';
 if($character===[]||!$gm&&($character['ownerPlayerId']??'')!==($identity['id']??''))throw new RuntimeException('Cette réserve personnelle ne vous appartient pas.',403);
 $stock=applicationPersonalAdvantageStock($character['personalAdvantageStock']??0);
 if($consume){$expected=$args['expectedStock']??null;if(!is_int($expected)||$expected<0||$expected>9007199254740991)throw new RuntimeException('Le stock personnel attendu est invalide.',400);if($expected!==$stock)throw new RuntimeException('Cette réserve a changé. Actualisez avant de consommer un avantage.',409);if($stock<1)throw new RuntimeException('Aucun avantage personnel disponible.',409);$stock--;$character['personalAdvantageStock']=$stock;$character['_updatedAt']=(int)floor(microtime(true)*1000);queueOnlineDomainUpsert($pending,$records,$key,$character);}
 return ['characterId'=>$character['id'],'current'=>$stock];
}
