<?php
declare(strict_types=1);
require_once __DIR__.'/../api/v1/character-statistics.php';
if(in_array('--matrix',$argv,true)){
    $cases=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    echo json_encode(array_map(static fn($c)=>['valid'=>validApplicationHonorificTitles($c['honorificTitles']??null),'titles'=>normalizeApplicationHonorificTitles($c['honorificTitles']??null),'bonuses'=>applicationHonorificTitleBonuses($c),'rows'=>applicationEffectiveCharacterStats($c,$c['testBonuses']??[]),'cloneRows'=>applicationIndependentCharacterStats($c),'displayed'=>applicationDisplayedHonorificTitle($c),'fortune'=>normalizeApplicationFortune($c['fortune']??null)],$cases),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
}
require_once __DIR__.'/../api/v1/domains.php';
require_once __DIR__.'/../api/v1/online.php';
require_once __DIR__.'/../api/v1/tactical-rolls.php';
$checks=0;
// Isolated token issuer for the sheet-creation contract; no authentication or SQL.
function randomToken(int $bytes=32): string { return str_repeat('x',max(16,$bytes)); }
function honorCheck(bool $value,string $label):void{global $checks;if(!$value)throw new RuntimeException($label);$checks++;}
$titles=['version'=>1,'displayedId'=>'first','entries'=>[['id'=>'first','name'=>'Gardienne du seuil','bonuses'=>['force'=>10]],['id'=>'second','name'=>'Amie des bois','bonuses'=>['force'=>5,'spirit'=>8]]]];
$c=['id'=>'hero','name'=>'Fiche fictive','stats'=>['force'=>40,'spirit'=>30],'fatigue'=>['current'=>0],'resources'=>['hp'=>70,'maxHp'=>100,'mentalResistance'=>12],'honorificTitles'=>$titles,'fortune'=>4];
honorCheck(validApplicationHonorificTitles($titles),'Title configuration accepted');
honorCheck(applicationDisplayedHonorificTitle($c)==='Gardienne du seuil','One chosen label');
honorCheck(applicationHonorificTitleBonuses($c)===['force'=>15,'spirit'=>8],'Hidden title still contributes');
$rows=applicationEffectiveCharacterStats($c);honorCheck($rows[0]['value']==='55','Permanent cumulative score');
$c['temporaryStats']['force']=60;honorCheck(applicationCharacterStatTotal($c,'force')===75.0,'Temporary replaces base and keeps title bonus');
$c['temporaryStats']['force']=0;honorCheck(applicationCharacterStatTotal($c,'force')===15.0,'Explicit temporary zero retained');
$c['temporaryStats']['force']=95;$c['fatigue']['current']=70;$c['honorificTitles']['entries'][1]['bonuses']['force']=10;
$rows=applicationEffectiveCharacterStats($c);honorCheck($rows[0]['value']==='95','Titles applied before fatigue and threshold cap');
$details=onlineStatFatigueDetails(['characterId'=>'hero','followCharacter'=>true,'stats'=>$rows,'fatigue'=>['current'=>70]],'character-stat-force',$c);honorCheck($details['before']===115,'Audit keeps true pre-fatigue total above 100');
$clone=applicationIndependentCharacterStats($c);honorCheck($clone[0]['value']==='115','Clone snapshots a raw permanent total');
$enhanced=applicationEnhancedStatRows(['id'=>'clone','followCharacter'=>false,'stats'=>$clone,'fatigue'=>['current'=>70]],['attackEnhancements'=>[]],'scene');honorCheck($enhanced[0]['value']==='95','Clone pays its fatigue once');
$c['honorificTitles']['displayedId']='second';honorCheck(applicationDisplayedHonorificTitle($c)==='Amie des bois'&&applicationEffectiveCharacterStats($c)[0]['value']==='95','Changing visible title does not change cumulative bonuses');
$c['honorificTitles']['displayedId']='';honorCheck(applicationDisplayedHonorificTitle($c)===''&&applicationEffectiveCharacterStats($c)[0]['value']==='95','All bonuses remain while no title is displayed');
$before=$c;$patch=playerCharacterPatch($c,['honorificTitles'=>['version'=>1,'entries'=>[],'displayedId'=>''],'fortune'=>5]);honorCheck(canonicalApplicationDomainValue($patch['honorificTitles'])===canonicalApplicationDomainValue($before['honorificTitles'])&&$patch['fortune']===4,'Player patch cannot grant titles or wealth');
$new=cleanPlayerCharacter($c,'fixture-owner');honorCheck(!array_key_exists('honorificTitles',$new)&&!array_key_exists('fortune',$new),'Creating or recovering a player sheet cannot grant titles or wealth');
$contextual=applicationEnhancedStatRows(['id'=>'source','characterId'=>'hero','followCharacter'=>true,'stats'=>[['id'=>'weapon-skill','label'=>'Compétence d’arme','value'=>32]]],['attackEnhancements'=>[]],'scene',$c);honorCheck($contextual[array_key_last($contextual)]['id']==='weapon-skill'&&$contextual[array_key_last($contextual)]['value']===32,'Contextual weapon skill is kept alongside title-aware character scores');
foreach([-1,0,1,2,3,4,5,6,null,'5',false] as $level){$copy=$c;$copy['fortune']=$level;$expected=is_int($level)&&$level>=0&&$level<=5;honorCheck(validApplicationCharacterDomain($copy)===$expected,'Fortune domain bounds and types');}
foreach(['duplicate','missing-display','long-name','bad-stat','huge','fraction','string','extra','unknown-version','too-many'] as $kind){
 $copy=$titles;
 if($kind==='duplicate')$copy['entries'][1]['id']='first';if($kind==='missing-display')$copy['displayedId']='absent';if($kind==='long-name')$copy['entries'][0]['name']=str_repeat('é',121);if($kind==='bad-stat')$copy['entries'][0]['bonuses']['hp']=1;if($kind==='huge')$copy['entries'][0]['bonuses']['force']=101;if($kind==='fraction')$copy['entries'][0]['bonuses']['force']=1.5;if($kind==='string')$copy['entries'][0]['bonuses']['force']='10';if($kind==='extra')$copy['extra']='ignored';if($kind==='unknown-version')$copy['version']=2;if($kind==='too-many')$copy['entries']=array_fill(0,51,$copy['entries'][0]);
 honorCheck(!validApplicationHonorificTitles($copy),'Invalid configuration '.$kind.' rejected');
 $entity=$c;$entity['honorificTitles']=$copy;honorCheck(!validApplicationCharacterDomain($entity),'Domain rejects '.$kind.' without a partial write');
}
$future=false;try{normalizeApplicationHonorificTitles(['version'=>2]);}catch(RuntimeException){$future=true;}honorCheck($future,'Future titles preserved by refusing to downgrade');
echo json_encode(['ok'=>true,'checks'=>$checks],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
