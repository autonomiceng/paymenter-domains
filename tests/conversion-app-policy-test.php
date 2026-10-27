<?php
// Pure app policy: positive shapes and one independent refusal per altered input.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require dirname(__DIR__).'/scripts/conversion/AppHost.php';
require dirname(__DIR__).'/scripts/conversion/QuietWindow.php';
use DomainsConversion\{AppHost,QuietWindow};
$app='/synthetic/app';
$operator=['profile'=>'app','stopped'=>['subscription scheduler','queue supervisor programs','external trigger'],'external_schedulers_disabled'=>true,'web_workers_drained_after_maintenance'=>true,'standard_public_index_only'=>true,'web_auto_prepend_disabled'=>true];
$baseline=['maintenance'=>'{"time":123,"status":503,"template":"Maintenance"}','maintenance_active'=>true,'web_fence'=>true,'parked'=>true,
    'own_peers'=>1,'visibility'=>'visible','processes'=>[['command'=>'php-fpm: pool subscription','cwd'=>'/','own'=>true],
        ['command'=>'nginx: worker process','cwd'=>null,'own'=>false],['command'=>'supervisord -c /etc/supervisor.conf','cwd'=>'/','own'=>true]],
    'user_cron'=>'# stopped','system_cron'=>['* * * * * root /usr/bin/foreign-backup'],'queue_driver'=>'database','jobs'=>['{"data":{"commandName":"App\\\\Jobs\\\\NativeJob"}}']];
$check=static fn($i,$o=null)=>AppHost::quiescent($app,$o??$operator,$i);
function appRefuses(Closure $call,string $reason): void {
    try{$call();}catch(DomainException $e){expect(str_contains($e->getMessage(),$reason),'Specific app guard: '.$reason);return;}
    throw new LogicException('App guard accepted: '.$reason);
}
$check($baseline);$nativeDown=$baseline;$nativeDown['maintenance']='{"except":[],"redirect":null,"retry":null,"refresh":null,"secret":null,"status":503,"template":"Maintenance"}';$check($nativeDown);AppHost::seconds(60);AppHost::seconds(120);
$sync=$baseline;$sync['queue_driver']='sync';$sync['jobs']=[];$check($sync);
$hidden=$baseline;$hidden['own_peers']=0;$hidden['visibility']='own processes only (hidepid)';$hidden['processes']=[];$check($hidden);
foreach(['profile'=>null,'external_schedulers_disabled'=>false,'web_workers_drained_after_maintenance'=>false,'standard_public_index_only'=>false,'web_auto_prepend_disabled'=>false,'stopped'=>[]]as$key=>$v){$o=$operator;$o[$key]=$v;appRefuses(fn()=>$check($baseline,$o),'App operator inventory');}
foreach([[' '],[1],[str_repeat('x',1001)]]as$value){$o=$operator;$o['stopped']=$value;appRefuses(fn()=>$check($baseline,$o),'Stopped entry points');}
foreach([null,['x'=>'entry'],'entry']as$value){$o=$operator;$o['stopped']=$value;appRefuses(fn()=>$check($baseline,$o),'App operator inventory');}
foreach(['','[]','null','{"template":123}','{"template":"Maintenance","secret":false}','{"template":"Maintenance","redirect":false}','{"template":"Maintenance","secret":"bypass"}','{"template":"Maintenance","redirect":"/"}','{"template":"Maintenance","except":["webhook"]}','{"template":""}','{"template":"Maintenance","status":200}']as$down){$i=$baseline;$i['maintenance']=$down;appRefuses(fn()=>$check($i),'Prerendered file maintenance');}
foreach(['maintenance_active','web_fence','parked']as$key){$i=$baseline;$i[$key]=false;appRefuses(fn()=>$check($i),$key==='parked'?'Park both':'Prerendered file maintenance');}
$i=$baseline;$i['own_peers']=0;$i['processes']=[];appRefuses(fn()=>$check($i),'Process visibility');
foreach(['processes','own_peers','visibility']as$key){$i=$baseline;unset($i[$key]);appRefuses(fn()=>$check($i),'Process visibility');}
$i=$baseline;$i['processes']='unreadable';appRefuses(fn()=>$check($i),'Process visibility');
foreach([
    ['command'=>'php /synthetic/app/public/index.php','cwd'=>'/'],
    ['command'=>'php idle.php','cwd'=>$app],
    ['command'=>'php idle.php','cwd'=>$app.' (deleted)'],
    ['command'=>'php idle.php','cwd'=>$app.'/storage'],
    ['command'=>"php\0/other/artisan\0schedule:run",'cwd'=>'/'],
    ['command'=>'php /other/artisan schedule:work','cwd'=>'/'],
    ['command'=>'php /other/artisan schedule:wor','cwd'=>'/'],
    ['command'=>'php /other/artisan qu:wo','cwd'=>'/'],
    ['command'=>'php /alias/runner.php','cwd'=>'/','paths'=>[$app.'/runner.php']],
    ['command'=>'php /other/artisan queue:work','cwd'=>'/'],
    ['command'=>'php /other/artisan queue:listen','cwd'=>'/'],
    ['command'=>'php /other/artisan horizon','cwd'=>'/'],
    ['command'=>'php /other/artisan octane:start','cwd'=>'/'],
    ['command'=>'php /other/artisan octane','cwd'=>'/'],
    ['command'=>"sh -c php '/alias/artisan' 'queue:work'; sleep 300",'cwd'=>'/'],
]as$p){$i=$baseline;$i['processes']=[$p+['own'=>true]];appRefuses(fn()=>$check($i),'Application process');}
foreach([['command'=>null,'cwd'=>'/'],['command'=>'php idle'],['command'=>'nginx: worker','own'=>false],['command'=>'php idle','cwd'=>null,'own'=>true]]as$p){$i=$baseline;$i['processes']=[$p];appRefuses(fn()=>$check($i),'Required process');}
foreach(['user_cron','system_cron']as$key){foreach(['* * * * * php /synthetic/app/task.php','* * * * * php /other/artisan cron','* * * * * schedule:run']as$line){$i=$baseline;$i[$key]=$key==='system_cron'?[$line]:$line;appRefuses(fn()=>$check($i),'Application cron');}}
foreach(['user_cron','system_cron']as$key){$i=$baseline;$i[$key]=null;appRefuses(fn()=>$check($i),'Required cron');}
$i=$baseline;$i['system_cron']=[null];appRefuses(fn()=>$check($i),'Required cron');
$i=$baseline;$i['queue_driver']='redis';appRefuses(fn()=>$check($i),'Only database or sync');
foreach(['Enom','Domains']as$name){foreach([null,1]as$reserved){$i=$baseline;$i['jobs']=[json_encode(['reserved_at'=>$reserved,'data'=>['commandName'=>'Paymenter\\Extensions\\Servers\\'.$name.'\\Jobs\\ObserveDomain']])];appRefuses(fn()=>$check($i),'Registrar queue');}}
$i=$baseline;$i['jobs']=['{"data":{"commandName":"Paymenter\\\\Extensions\\\\Servers\\\\\\u0045nom\\\\Jobs\\\\ObserveDomain"}}'];appRefuses(fn()=>$check($i),'Registrar queue');
$i=$baseline;$i['jobs']=['{"displayName":"Closure (Enom.php:21)"}'];appRefuses(fn()=>$check($i),'Registrar queue');
foreach([null,'bad','null','[]']as$payload){$i=$baseline;$i['jobs']=[$payload];appRefuses(fn()=>$check($i),'Registrar queue payload');}
$i=$baseline;$i['jobs']=[$baseline['jobs'][0],'bad'];appRefuses(fn()=>$check($i),'Registrar queue payload');
$i=$baseline;unset($i['jobs']);appRefuses(fn()=>$check($i),'Required queue');
$before=['tables'=>['jobs'=>['max_id'=>1,'updated_at'=>'2026-10-18','digest'=>'a']],'mutexes'=>[]];QuietWindow::unchanged($before,$before);
$after=$before;$after['tables']['jobs']['max_id']=2;appRefuses(fn()=>QuietWindow::unchanged($before,$after),'Application changed');
$after=$before;$after['tables']['jobs']['updated_at']='2026-10-19';appRefuses(fn()=>QuietWindow::unchanged($before,$after),'Application changed');
$after=$before;$after['tables']['jobs']['digest']='b';appRefuses(fn()=>QuietWindow::unchanged($before,$after),'Application changed');
$after=$before;$after['mutexes']=['present'];appRefuses(fn()=>QuietWindow::unchanged($before,$after),'Schedule mutex');
appRefuses(fn()=>QuietWindow::unchanged($after,$before),'Schedule mutex');
appRefuses(fn()=>AppHost::seconds(59),'at least 60');
require dirname(__DIR__).'/scripts/conversion/EvidenceHost.php';
$incomplete = sys_get_temp_dir().'/domains-evidence-host-'.bin2hex(random_bytes(8));
mkdir($incomplete.'/bootstrap', 0700, true);
file_put_contents($incomplete.'/bootstrap/app.php', '<?php throw new LogicException("Bootstrap must not load.");');
set_error_handler(static function(int $severity, string $message): never { throw new ErrorException($message, 0, $severity); });
try {
    appRefuses(fn () => \DomainsConversion\EvidenceHost::boot($incomplete), 'Canonical app required.');
    unlink($incomplete.'/bootstrap/app.php');
    appRefuses(fn () => \DomainsConversion\EvidenceHost::boot($incomplete), 'Canonical app required.');
} finally {
    restore_error_handler();
    if (is_file($incomplete.'/bootstrap/app.php')) { unlink($incomplete.'/bootstrap/app.php'); }
    rmdir($incomplete.'/bootstrap'); rmdir($incomplete);
}
echo "App host policy allowed shapes and independent refusals passed.\n";
