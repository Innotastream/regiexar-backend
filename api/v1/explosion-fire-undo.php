<?php
declare(strict_types=1);
function applicationRemoveActionExplosionFire(array &$domains,array $action):void {
    foreach($action['patches']??[] as $patch){
        if(($patch['domain']??'')!=='activity'||($patch['path'][0]??'')!=='abilityCueEvents'||!empty($patch['hasBefore'])||empty($patch['hasAfter']))continue;
        if(!is_array($patch['after']??null))continue;
        $events=array_is_list($patch['after']??[])?$patch['after']:[$patch['after']];
        foreach($events as $event){
            if(!is_array($event))continue;
            if(!preg_match('/^cue-flux-blast-([a-f0-9]{48})$/D',$event['id']??'',$match)||($event['completionCue']['spell']['presetId']??'')!=='timed-flux-explosion')continue;
            $key='map:'.$event['sceneId'];$layerId=$event['layerId'];$id='flux-fire-'.$match[1];if(!isset($domains[$key]))continue;
            $remove=static fn($values)=>array_values(array_filter($values,static fn($stroke)=>($stroke['id']??'')!==$id));
            if(isset($domains[$key]['terrainEffects']))$domains[$key]['terrainEffects']=$remove($domains[$key]['terrainEffects']);
            if(isset($domains[$key]['layers'][$layerId]['terrainEffects']))$domains[$key]['layers'][$layerId]['terrainEffects']=$remove($domains[$key]['layers'][$layerId]['terrainEffects']);
        }
    }
}
