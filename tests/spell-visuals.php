<?php
declare(strict_types=1);
require_once __DIR__.'/../api/v1/domains.php';
require_once __DIR__.'/../api/v1/online.php';
if(($argv[1]??'')==='--json'){$cases=json_decode((string)stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);echo json_encode(array_map(static fn($value)=>applicationSpellVisual($value),$cases),JSON_THROW_ON_ERROR);exit;}
$checks=0;$check=static function(bool $value,string $message)use(&$checks){if(!$value)throw new RuntimeException($message);$checks++;};
$map=['activeLayerId'=>'ground','naturalWidth'=>1000,'naturalHeight'=>600,'gridSize'=>50,'tokens'=>[]];
$ability=['id'=>'ability-mania-elemental-breath','name'=>'Nom arbitraire','rangeCells'=>4];$context=['sceneId'=>'scene','source'=>['id'=>'source','x'=>20,'y'=>50,'size'=>40,'layerId'=>'ground'],'targetPoint'=>['x'=>80,'y'=>50],'variantId'=>'fire','map'=>$map];
$spell=applicationTrustedSpellVisual($ability,$context);$check($spell!==null&&$spell['presetId']==='elemental-fire','Liaison de données lue');
$check(applicationTrustedSpellVisual([...$ability,'name'=>'Autre nom'],$context)===$spell,'Aucune règle par nom');
$check(applicationTrustedSpellVisual([...$ability,'id'=>'unknown','completionCue'=>['spell'=>$spell]],$context)===null,'Un champ de fiche ne remplace pas le registre');
foreach(['fire'=>'elemental-fire','ice'=>'elemental-ice','lightning'=>'elemental-lightning','acid'=>'elemental-acid']as$variant=>$preset)$check(applicationTrustedSpellVisual($ability,[...$context,'variantId'=>$variant])['presetId']===$preset,'Variante du catalogue');
foreach([['presetId'=>'unknown'],['placement'=>'iframe'],['sceneId'=>'../private'],['layerId'=>'roof'],['source'=>['x'=>20,'y'=>50,'size'=>'40']],['targetPoint'=>['x'=>101,'y'=>50]],['rangeCells'=>4.5],['rangeCells'=>21],['version'=>2]]as$patch)$check(applicationSpellVisual([...$spell,...$patch])===null,'Géométrie invalide refusée');
$cue=normalizeApplicationAbilityCompletionCue(['spell'=>$spell]);$check(validApplicationAbilityCompletionCue($cue),'Événement spatial valide sans son');
$check(!isset(applicationAbilityCompletionCueFor([...$ability,'completionCue'=>['spell'=>$spell]])['spell']),'Animation arbitraire fournie par une fiche ignorée');
$activity=[];$id=appendApplicationAbilityCueEvent($activity,$cue,'scene','ground','source');$event=$activity['abilityCueEvents'][0];
$check($id!==''&&$event['expiresAt']-$event['createdAt']===2200,'Événement one-shot à durée bornée');$check(validApplicationAbilityCueEvents($activity['abilityCueEvents']),'Événement valide dans le journal autoritatif');
$public=['sceneId'=>'scene','layerId'=>'ground','sourceTokenId'=>'source','visibleTokenIds'=>['source'],'visible'=>static fn()=>true];
$check(applicationPublicSpellVisual($spell,$public)===$spell,'DTO public minimal');
foreach([['visibleTokenIds'=>[]],['sceneId'=>'elsewhere'],['layerId'=>'upper'],['paused'=>true],['visible'=>static fn()=>false]]as$patch)$check(applicationPublicSpellVisual($spell,[...$public,...$patch])===null,'Source ou scène privée cachée');
$mounted=applicationTrustedSpellVisual($ability,[...$context,'source'=>[...$context['source'],'mountedOnTokenId'=>'mount'],'map'=>[...$map,'tokens'=>[['id'=>'mount','x'=>30,'y'=>40,'size'=>100,'layerId'=>'ground']]]]);$check($mounted['source']===['x'=>30,'y'=>40,'size'=>100],'Empreinte de monture capturée');
echo json_encode(['ok'=>true,'checks'=>$checks,'productionAccess'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
