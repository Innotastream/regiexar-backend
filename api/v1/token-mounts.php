<?php
declare(strict_types=1);
function applicationMountedRiders(array $tokens,array $mount):array {
 $riders=array_values(array_filter($tokens,static fn($t)=>($t['id']??'')!==($mount['id']??'')&&($t['mountedOnTokenId']??'')===($mount['id']??'')));
 usort($riders,static fn($a,$b)=>((int)($a['mountedAt']??0)<=>(int)($b['mountedAt']??0))?:strcmp((string)$a['id'],(string)$b['id']));
 return $riders;
}
function applicationMountPilot(array $tokens,array $mount):?array {
 return ($mount['mountControllable']??false)===true?(applicationMountedRiders($tokens,$mount)[0]??null):null;
}
