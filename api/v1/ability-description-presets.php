<?php
declare(strict_types=1);
function applicationConfiguredAbilityText(array $source):array {
 static $presets=null;$presets??=json_decode((string)file_get_contents(__DIR__.'/ability-description-presets.json'),true,512,JSON_THROW_ON_ERROR);$p=$presets[$source['id']??'']??null;if($p===null)return $source;
 if(in_array($source['name']??'', $p['names']??[],true))$source['name']=$p['name'];
 $text=static fn($v)=>trim((string)preg_replace('/\s+/u',' ',(string)$v));$matches=false;foreach($p['from']as$old)if($text($old)===$text($source['description']??''))$matches=true;
 if(!$matches)return $source;
 $source['description']=preg_replace_callback('/\{([A-Za-z0-9_.]+)\}/',static function($m)use($source,$p){$v=$source;foreach(explode('.',$m[1])as$k)$v=is_array($v)?($v[$k]??null):null;return (string)($v??$p['defaults'][$m[1]]??'');},$p['template']);return $source;
}
