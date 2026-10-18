<?php
declare(strict_types=1);
define('XAR_TACTICAL_FIXTURE_ONLY',true);
require __DIR__.'/tactical-lifecycle.php';
require __DIR__.'/celestial-serpent-cases.php';
fwrite(STDOUT,json_encode(['ok'=>true,'checks'=>$GLOBALS['checks']??0,'scope'=>'Bottes: trajet, grande cible, effet Cible, attaque, défense imposée, reçus et quotas'])."\n");
