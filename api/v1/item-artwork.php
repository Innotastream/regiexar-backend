<?php
declare(strict_types=1);
// Cosmetic defaults for identified legacy models; never creates stock or objects.
function applicationItemArtworkPresetMatches(array $template,?array $preset):bool {
    return $preset!==null&&($template['kind']??'')===$preset['kind']
        &&(($template['name']??'')===$preset['name']||in_array($template['name']??'', $preset['aliases']??[], true));
}
function bundledItemArtwork(array $template):string {
    if(isset($template['image'])&&$template['image']!=='')return '';
    static $presets=null;
    $presets??=json_decode((string)file_get_contents(__DIR__.'/item-artwork-presets.json'),true,512,JSON_THROW_ON_ERROR);
    $preset=$presets[$template['id']??'']??null;
    return applicationItemArtworkPresetMatches($template,$preset)?$preset['image']:'';
}
function bundledPotionColor(array $template):string {
    static $presets=null;$presets??=json_decode((string)file_get_contents(__DIR__.'/item-artwork-presets.json'),true,512,JSON_THROW_ON_ERROR);$preset=$presets[$template['id']??'']??null;
    return applicationItemArtworkPresetMatches($template,$preset)?($preset['potionColor']??''):'';
}
