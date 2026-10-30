<?php
declare(strict_types=1);
function applicationSharedReachPolicy():array{static $policy=null;return $policy??=json_decode((string)file_get_contents(__DIR__.'/combat-range-policy.json'),true,512,JSON_THROW_ON_ERROR);}
function applicationChargeRangeCells():int{return (int)applicationSharedReachPolicy()['ranged'];}
