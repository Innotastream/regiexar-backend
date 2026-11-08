<?php
declare(strict_types=1);

// Count cold geometry results through the real private cache, not elapsed time.
// The old eager resolver is a positive control: identical output, excess work.
function privateConfigPath(): ?string { return $GLOBALS['budgetPrivateConfig'] ?? null; }
require_once __DIR__.'/../api/v1/domains.php';
require_once __DIR__.'/fixtures/vision-relays-reference.php';
set_error_handler(static function(int $severity, string $message): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new RuntimeException($message);
});

if (($argv[1] ?? '') === 'worker') {
    [$script, $mode, $root, $resolver, $scenario] = $argv;
    $_SERVER['DOCUMENT_ROOT'] = $root.'/public';
    $GLOBALS['budgetPrivateConfig'] = $root.'/private/config.php';
    $state = [
        'walls'=>['version'=>1,'width'=>48,'height'=>32,'mask'=>''],
        'fog'=>['version'=>1,'enabled'=>false,'width'=>48,'height'=>32,'mask'=>''],
        'vision'=>['version'=>1,'enabled'=>true,'distance'=>4,'shared'=>true],
        'naturalWidth'=>1600.0,'naturalHeight'=>900.0,'lightingMode'=>'normal','lights'=>[],
    ];
    if ($scenario === 'visible-light') {
        $state['lights'] = [['id'=>'synthetic-nearby','name'=>'Synthetic light','x'=>11.0,'y'=>50.0,
            'visionDistance'=>6,'enabled'=>true,'visibleFromAfar'=>true]];
    } elseif ($scenario !== 'no-lights') {
        throw new RuntimeException('Unknown workload fixture');
    }
    $origins = [['x'=>10.0,'y'=>50.0,'visionDistance'=>4,'darkVision'=>'none','source'=>'token']];
    $resolve = $resolver === 'current' ? 'applicationResolveVisionRelays' : 'referenceVisionRelays';
    $result = $resolve($state, $origins, 50);
    $directory = applicationVisionCacheDirectory();
    if (!is_string($directory)) throw new RuntimeException('A real private cache is required by this test');
    $entries = glob($directory.'/base-*.json');
    foreach ($entries as $entry) {
        $envelope = json_decode(file_get_contents($entry), true, 32, JSON_THROW_ON_ERROR);
        if (!applicationVisionCacheMaskValid($envelope['mask'] ?? null, 'base')) {
            throw new RuntimeException('Invalid geometry in workload fixture');
        }
    }
    echo json_encode(['geometryResults'=>count($entries),'outputHash'=>hash('sha256',json_encode($result)),
        'activeLights'=>count($result['activatedLightIds']),'ok'=>true], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}

$root = sys_get_temp_dir().'/xar-vision-work-budget-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
function budgetRun(string $root, string $resolver, string $scenario): array {
    $caseRoot = $root.'/'.$resolver.'-'.$scenario;
    mkdir($caseRoot,0700);mkdir($caseRoot.'/public',0700);mkdir($caseRoot.'/private',0700);
    $command = [PHP_BINARY];
    if (php_ini_loaded_file() === false) $command[] = '-n';
    array_push($command, __FILE__, 'worker', $caseRoot, $resolver, $scenario);
    $pipes = [];
    $process = proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
    if (!is_resource($process)) throw new RuntimeException('Cannot start workload fixture');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);$stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$status = proc_close($process);
    if ($status !== 0 || $stderr !== '') throw new RuntimeException('Workload worker failed: '.$stderr);
    $result = json_decode($stdout,true,32,JSON_THROW_ON_ERROR);
    if (($result['ok']??false)!==true) throw new RuntimeException('Workload worker did not finish');
    return $result;
}
function budgetCleanup(string $path, string $root): void {
    $normalized = str_replace('\\','/',$root);
    if (!str_starts_with($normalized,str_replace('\\','/',sys_get_temp_dir()).'/xar-vision-work-budget-')
        || !applicationVisionCacheWithin($path,$root)) throw new RuntimeException('Unsafe fixture cleanup');
    if (is_link($path)||is_file($path)) {unlink($path);return;}
    foreach(scandir($path) as $name) if($name!=='.'&&$name!=='..') budgetCleanup($path.'/'.$name,$root);
    rmdir($path);
}
try {
    $cases = [];
    foreach (['no-lights'=>1,'visible-light'=>2] as $scenario=>$budget) {
        $current = budgetRun($root,'current',$scenario);
        $reference = budgetRun($root,'reference',$scenario);
        if ($current['outputHash'] !== $reference['outputHash']) throw new RuntimeException('Work budget changed vision: '.$scenario);
        if ($current['activeLights'] !== ($scenario==='visible-light'?1:0)) throw new RuntimeException('Fixture did not exercise the expected light: '.$scenario);
        if ($current['geometryResults'] !== $budget) throw new RuntimeException('Cold geometry budget exceeded or required geometry missing: '.$scenario);
        if ($reference['geometryResults'] <= $budget) throw new RuntimeException('Positive control no longer detects the old unnecessary work: '.$scenario);
        $cases[] = ['scenario'=>$scenario,'budget'=>$budget,'current'=>$current['geometryResults'],'old'=>$reference['geometryResults']];
    }
    echo json_encode(['ok'=>true,'budgetChecks'=>8,'cases'=>$cases,'productionAccess'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    budgetCleanup($root,$root);
}
