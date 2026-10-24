<?php
declare(strict_types=1);
set_error_handler(static function (int $severity, string $message): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new RuntimeException($message);
});
function privateConfigPath(): ?string {
    return $GLOBALS['visionTestPrivate'] ?? null;
}
require_once __DIR__.'/../api/v1/domains.php';
function storageRequire(bool $ok, string $message): void {
    ++$GLOBALS['checks'];
    if (!$ok) throw new RuntimeException($message);
}
function storageMask(int $value=255): array {
    return ['version'=>1,'enabled'=>true,'width'=>32,'height'=>32,'mask'=>rtrim(strtr(base64_encode(str_repeat(chr($value),128)),'+/','-_'),'=')];
}
$worker=$argv[1]??null;
if ($worker==='worker') {
    [$unused,$unused2,$root,$mode,$seed]=array_pad($argv,5,'default');
    $_SERVER['DOCUMENT_ROOT']=$root.'/public';
    $GLOBALS['visionTestPrivate']=$root.($mode==='public'?'/public':'/private').'/config.php';
    if ($mode==='no-root') unset($_SERVER['DOCUMENT_ROOT']);
    $calls=0;
    $compute=static function () use (&$calls):array {++$calls;return storageMask();};
    $mask=applicationMemoizedVisionMask('base',['test'=>$seed],$compute);
    $mask['mask']='modified copy';
    if (applicationMemoizedVisionMask('base',['test'=>$seed],$compute)!==storageMask()) throw new RuntimeException('Returned mask was shared by reference');
    if ($mode==='fill') {
        for($i=0;$i<600;++$i)applicationMemoizedVisionMask('base',['fill'=>$i],static fn():array=>storageMask($i%256));
        for($i=0;$i<80;++$i)applicationMemoizedVisionMask('render',['fill'=>$i],static fn():array=>storageMask($i%256));
    }
    echo json_encode(['calls'=>$calls,'directory'=>applicationVisionCacheDirectory(),'ok'=>true]).PHP_EOL;
    exit;
}
$checks=0;
$root=sys_get_temp_dir().'/xar-vision-cache-'.bin2hex(random_bytes(8));
mkdir($root,0700);mkdir($root.'/public',0700);mkdir($root.'/private',0700);
function storageStart(string $root,string $mode='normal',string $seed='reusable'):array {
    $pipes=[];
    $process=proc_open([PHP_BINARY,__FILE__,'worker',$root,$mode,$seed],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
    if (!is_resource($process)) throw new RuntimeException('Cannot start isolated cache process');
    fclose($pipes[0]);
    return [$process,$pipes];
}
function storageFinish(array $running):array {
    [$process,$pipes]=$running;
    $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);
    storageRequire($status===0 && $error==='', 'Worker failed: '.$error);
    $result=json_decode($out,true,32,JSON_THROW_ON_ERROR);
    storageRequire(($result['ok']??false)===true,'Worker did not confirm geometry');
    return $result;
}
function storageRun(string $root,string $mode='normal',string $seed='reusable'):array {
    return storageFinish(storageStart($root,$mode,$seed));
}
function storageCleanup(string $path,string $root):void {
    if (!str_starts_with(str_replace('\\','/',$path),str_replace('\\','/',sys_get_temp_dir()).'/xar-vision-cache-')
        || !applicationVisionCacheWithin($path,$root)) throw new RuntimeException('Unsafe test cleanup');
    if (is_link($path) || is_file($path)) {unlink($path);return;}
    foreach(scandir($path) as $entry)if($entry!=='.'&&$entry!=='..')storageCleanup($path.'/'.$entry,$root);
    rmdir($path);
}
try {
    $cold=storageRun($root);
    storageRequire($cold['calls']===1,'First process computes once');
    $directory=$cold['directory'];
    storageRequire(is_string($directory)&&!applicationVisionCacheWithin($directory,$root.'/public'),'Cache must be outside public root');
    storageRequire(storageRun($root)['calls']===0,'Fresh PHP process must reuse geometry');
    $file=glob($directory.'/base-*.json')[0];
    $valid=file_get_contents($file);$envelope=json_decode($valid,true,32,JSON_THROW_ON_ERROR);
    $damage=[
        'truncated'=>'{"version":1',
        'future-version'=>json_encode([...$envelope,'version'=>2]),
        'other-geometry'=>json_encode([...$envelope,'key'=>str_repeat('0',64)]),
        'damaged-hash'=>json_encode([...$envelope,'hash'=>str_repeat('0',64)]),
        'oversized'=>str_repeat('x',65537),
    ];
    $futureMask=[...$envelope['mask'],'version'=>2];
    $damage['future-mask']=json_encode([...$envelope,'mask'=>$futureMask,'hash'=>hash('sha256',json_encode($futureMask))]);
    $privateMask=[...$envelope['mask'],'account'=>'must-not-be-cached'];
    $damage['private-field']=json_encode([...$envelope,'mask'=>$privateMask,'hash'=>hash('sha256',json_encode($privateMask))]);
    foreach($damage as $case=>$bytes){
        file_put_contents($file,$bytes);touch($file,time());
        storageRequire(storageRun($root)['calls']===1,'Damaged cache must recompute: '.$case);
    }
    file_put_contents($file,$valid);touch($file,time()-601);
    storageRequire(storageRun($root)['calls']===1,'Expired cache must recompute');
    file_put_contents($file,$valid);touch($file,time());
    storageRequire(storageRun($root)['calls']===0,'Restored cache may be reused');
    foreach(['public','no-root'] as $mode){
        $a=storageRun($root,$mode);$b=storageRun($root,$mode);
        storageRequire($a['directory']===null&&$b['directory']===null&&$a['calls']===1&&$b['calls']===1,'Unsafe path must fall back to computing');
    }
    $blocked=$root.'/blocked';mkdir($blocked);mkdir($blocked.'/public');mkdir($blocked.'/private');
    file_put_contents($blocked.'/private/cache','cannot be a directory');
    $refused=storageRun($blocked);
    storageRequire($refused['directory']===null&&$refused['calls']===1,'Refused directory must not break the request');
    $parallel=[];
    for($i=0;$i<8;++$i)$parallel[]=storageStart($root,'normal','concurrent');
    foreach($parallel as $running)storageRequire(storageFinish($running)['calls']<=1,'Concurrent request computes at most once');
    storageRequire(storageRun($root,'normal','concurrent')['calls']===0,'Concurrent writes must produce a reusable complete file');
    storageRequire(glob($directory.'/.vision-*')===[],'Atomic writes must leave no temporary files');
    storageRun($root,'fill','bounded');
    storageRequire(count(glob($directory.'/base-*.json'))<=256,'Base cache slots must be bounded');
    storageRequire(count(glob($directory.'/render-*.json'))<=32,'Render cache slots must be bounded');
    storageRequire(storageRun($root)['calls']<=1,'Eviction or slot collision must recompute the correct mask');
    $memoryCalls=0;
    $largeMask=['version'=>1,'enabled'=>true,'width'=>512,'height'=>512,
        'mask'=>rtrim(strtr(base64_encode(str_repeat("\xff",32768)),'+/','-_'),'='),
        'opacity'=>rtrim(strtr(base64_encode(str_repeat("\xff",262144)),'+/','-_'),'=')];
    $largeCompute=static function()use(&$memoryCalls,$largeMask):array{++$memoryCalls;return $largeMask;};
    for($i=0;$i<30;++$i)applicationMemoizedVisionMask('render',['memory-limit'=>$i],$largeCompute);
    applicationMemoizedVisionMask('render',['memory-limit'=>0],$largeCompute);
    storageRequire($memoryCalls===31,'Memory byte limit must evict old large render masks');
    $invalidCalls=0;
    $invalidCompute=static function()use(&$invalidCalls):array{++$invalidCalls;return [...storageMask(),'private'=>'not-cacheable'];};
    for($i=0;$i<2;++$i)applicationMemoizedVisionMask('base',['invalid-result'=>true],$invalidCompute);
    storageRequire($invalidCalls===2,'Invalid masks and extra fields cannot enter the cache');
    $bypassed=0;
    $bypassCompute=static function()use(&$bypassed):array{++$bypassed;return storageMask();};
    for($i=0;$i<2;++$i)applicationMemoizedVisionMask('unknown',['key'=>1],$bypassCompute);
    for($i=0;$i<2;++$i)applicationMemoizedVisionMask('base',['not-json'=>NAN],$bypassCompute);
    storageRequire($bypassed===4,'Unsupported kinds or inputs fall back to computing');
    $caught=false;
    try {applicationMemoizedVisionMask('base',['computation-failure'=>true],static function():array{throw new LogicException('Expected');});}
    catch(LogicException $error){$caught=$error->getMessage()==='Expected';}
    storageRequire($caught,'Cache must propagate an actual computation error');
    $linkRoot=$root.'/link-test';mkdir($linkRoot);mkdir($linkRoot.'/public');mkdir($linkRoot.'/private');mkdir($linkRoot.'/private/cache');
    $symlinkSupported=@symlink($linkRoot.'/public',$linkRoot.'/private/cache/vision-v1');
    if($symlinkSupported) {
        $linked=storageRun($linkRoot);
        storageRequire($linked['directory']===null&&$linked['calls']===1,'A symlink to public files must be refused');
    }
    echo json_encode(['ok'=>true,'storageChecks'=>$checks,'freshProcesses'=>true,'parallelWriters'=>8,'symlinkTested'=>$symlinkSupported,'productionAccess'=>false]).PHP_EOL;
} finally {
    storageCleanup($root,$root);
}
