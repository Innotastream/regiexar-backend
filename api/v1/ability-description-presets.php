<?php
declare(strict_types=1);
function applicationConfiguredAbilityText(array $source):array {
 static $presets=null;$presets??=json_decode((string)file_get_contents(__DIR__.'/ability-description-presets.json'),true,512,JSON_THROW_ON_ERROR);$p=$presets[$source['id']??'']??null;if($p===null)return $source;
 $nameKey=static fn($v)=>preg_replace('/[^a-z0-9]/','',strtolower(strtr((string)$v,['É'=>'e','é'=>'e','È'=>'e','è'=>'e','Ê'=>'e','ê'=>'e','À'=>'a','à'=>'a','Â'=>'a','â'=>'a','Î'=>'i','î'=>'i','Ï'=>'i','ï'=>'i','Ô'=>'o','ô'=>'o','Û'=>'u','û'=>'u','Ù'=>'u','ù'=>'u','Ç'=>'c','ç'=>'c'])));
 foreach($p['names']??[] as $old)if($nameKey($old)===$nameKey($source['name']??'')){$source['name']=$p['name'];break;}
 $text=static fn($v)=>trim((string)preg_replace('/\s+/u',' ',(string)$v));$matches=false;foreach($p['from']as$old)if($text($old)===$text($source['description']??''))$matches=true;
 if(!$matches)return $source;
 $source['description']=preg_replace_callback('/\{([A-Za-z0-9_.]+)\}/',static function($m)use($source,$p){$v=$source;foreach(explode('.',$m[1])as$k)$v=is_array($v)?($v[$k]??null):null;return (string)($v??$p['defaults'][$m[1]]??'');},$p['template']);return $source;
}
