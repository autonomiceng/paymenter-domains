<?php
// Fresh processes and synthetic exchanges only. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use App\Models\{Invoice,Service};
use Illuminate\Support\Facades\{Cache,DB,Event,Log};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,CatalogueAcceptances,DomainManagement,HookDiagnostics,NativePaidHooks,OperationClaims,OperationReconciliation,RenewalAcceptance,RenewalAdmission,RenewalAuthority,RenewalCompletion,RenewalExecution,RenewalWriter,Runtime,RuntimePorts,RuntimeWork,WriteFeatures};
$phase=getenv('HOOK_PHASE');$root=getenv('HOOK_ROOT');$database=getenv('HOOK_DATABASE');$mode=getenv('HOOK_MODE');
if($phase==='seed'){
    require __DIR__.'/native-paid-fixture.php';
    $ops=[];$queue=Illuminate\Support\Facades\Queue::getFacadeRoot();Illuminate\Support\Facades\Queue::fake();
    foreach(['register','transfer']as$action){
        $op=$new(placeholderComFixtureName('hook-'.$action.'-placeholder.com'),paid:false,action:$action);$paid($op);$ops[]=(int)$op->id;
        same('paid',Invoice::findOrFail($op->invoice_id)->status,'Fixture has native settled payment');
    }
    Illuminate\Support\Facades\Queue::swap($queue);
    same(0,$writer->calls,'Seed sends nothing');
    file_put_contents($root.'/operations.json',json_encode($ops,JSON_THROW_ON_ERROR));
    DB::statement("VACUUM INTO '".str_replace("'","''",$database)."'");
    echo "Seed: retained paid registration and transfer, zero sends.\n";$nativeSuccessful=true;return;
}
require dirname(__DIR__).'/bootstrap.php';
$nativeSuccessful=false;
register_shutdown_function(static function()use(&$nativeSuccessful):void{if(!$nativeSuccessful){exit(1);}});
$fixture=(string)getenv('PAYMENTER_TEST_FIXTURE');
expect(is_file($fixture.'/.domains-fixture.json')&&!file_exists($fixture.'/.env'),'Private runner fixture required');
foreach(['APP_ENV'=>'testing','APP_KEY'=>'base64:'.base64_encode(str_repeat('t',32)),
    'APP_URL'=>'http://domains-tests.invalid','APP_RUNNING_IN_CONSOLE'=>$mode==='console'?'true':'false',
    'DB_CONNECTION'=>'sqlite','DB_DATABASE'=>$database,'CACHE_STORE'=>'array','QUEUE_CONNECTION'=>'sync',
    'SESSION_DRIVER'=>'array','MAIL_MAILER'=>'array','TELEMETRY_ENABLED'=>'false']as$key=>$value){
    putenv($key.'='.$value);$_ENV[$key]=$_SERVER[$key]=$value;
}
$loader=require $fixture.'/vendor/autoload.php';
$loader->addPsr4('App\\',$fixture.'/app/',true);
$loader->addPsr4('Paymenter\\Extensions\\',$fixture.'/extensions/',true);
$app=require $fixture.'/bootstrap/app.php';$logs=[];$artisanStarts=0;
$_SERVER['argv']=['artisan','--env','staging','schedule:work','--token=PRIVATE-ARGUMENT','PRIVATE-CUSTOMER'];
$request=Illuminate\Http\Request::create('/domains/pricing?token=PRIVATE-QUERY');
if($mode==='http'){$app->instance('request',$request);}
$app->afterBootstrapping(Illuminate\Foundation\Bootstrap\LoadConfiguration::class,static function():void{
    config(['app.release'=>'hook-test-f1e670c','hashing.bcrypt.rounds'=>4,'audit.enabled'=>false]);
});
$app->afterBootstrapping(Illuminate\Foundation\Bootstrap\RegisterFacades::class,static function()use(&$logs,&$artisanStarts):void{
    set_exception_handler(static function(Throwable $error):never{fwrite(STDERR,$error::class.': '.$error->getMessage()."\n".$error->getTraceAsString()."\n");exit(1);});
    Event::listen(Illuminate\Console\Events\ArtisanStarting::class,static function()use(&$artisanStarts):void{$artisanStarts++;});
    Illuminate\Console\Application::starting(static function()use(&$artisanStarts):void{$artisanStarts++;});
    Log::listen(static function($event)use(&$logs):void{$logs[]=['message'=>$event->message,'context'=>$event->context];});
    Illuminate\Support\Facades\Http::preventStrayRequests();
});
$app->booted(static function():void{
    config(['settings.mail_disabled'=>true,'settings.default_currency'=>'USD','settings.tax_enabled'=>false]);
    // Upstream has no compiled assets. These inert URLs are never fetched.
    $hot=storage_path('framework/domains-hook-assets');file_put_contents($hot,'http://assets.example.test');
    app(Illuminate\Foundation\Vite::class)->useHotFile($hot);
});
if($phase==='missing'){
    $app->booted(static function():void{
        same([],NativePaidHooks::serviceMissing(),'Full provider boot is healthy before deliberate removal');
        Event::forget('eloquent.saving: '.Service::class);
    });
}
if($mode==='http'){
    $kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);$response=$kernel->handle($request);
    same(200,$response->getStatusCode(),'Fresh HTTP kernel serves Domains pricing: '.($response->exception?->getMessage()??'no exception'));$kernel->terminate($request,$response);
}else{$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();}
expect($app->bound(Runtime::class),'Native provider discovers installed Domains before any manual boot');
same('Available',NativePaidHooks::notices()['Start after payment'],'Native boot registers the paid listener');
same(1,count(Event::getListeners('navigation')),'Native provider has one navigation authority');
$scheduled=app(Illuminate\Console\Scheduling\Schedule::class)->events();
same(1,count(array_filter($scheduled,static fn($event)=>$event->description==='domains-runtime')),'Native boot completes scheduler registration');
same($mode==='console',$app->runningInConsole(),'Boot mode matches the kernel');
$warnings=array_values(array_filter($logs,static fn($log)=>$log['message']==='Domains hook unavailable.'));
$expectedContext=['missing_events'=>['eloquent.saving: '.Service::class],'mode'=>$mode,'pid'=>getmypid(),
    ...($mode==='console'?['command'=>'schedule:work']:['request_path'=>'/domains/pricing']),'release'=>'hook-test-f1e670c'];
$notice='Required Service listeners are missing in this process: Service saving model event. New registrations and transfers run here wait until a healthy process picks them up.';
if($phase==='missing'){
    same(['Service saving model event'],NativePaidHooks::serviceMissing(),'Only saving removed after provider boot');
    same([['message'=>'Domains hook unavailable.','context'=>['feature'=>'Paymenter payment events','notice'=>$notice,...$expectedContext]]],$warnings,'Exact attributed boot warning');
    echo 'Candidate warning: '.json_encode($warnings[0],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}else{
    same([],NativePaidHooks::serviceMissing(),'Healthy full boot has required Service hooks');
    same([],$warnings,'Healthy full boot emits no hook warning');
}
if($mode==='console'){
    $argv=$_SERVER['argv'];
    $cases=[
        [[],null],
        [['artisan'],null],
        [['artisan','--env','staging','schedule:work','--token','private-argument','private-customer'],'schedule:work'],
        [['artisan','--env=private-environment','schedule:work','private-customer'],'schedule:work'],
        [['artisan','-qvvv','--no-ansi','--env','private-environment','schedule:work'],'schedule:work'],
        [['artisan','--env','first-environment','--env','private-environment','schedule:work'],'schedule:work'],
        [['artisan','--env','','schedule:work'],'schedule:work'],
        [['artisan','--env=','schedule:work'],'schedule:work'],
        [['artisan','--env','--no-interaction','schedule:work'],'schedule:work'],
        [['artisan','--','schedule:work','private-customer'],'schedule:work'],
        [['artisan','--env','staging','--','schedule:work'],'schedule:work'],
        [['artisan','--env','private-environment'],null],
        [['artisan','--env'],null],
        [['artisan','--unknown','private-option','schedule:work'],null],
        [['artisan','--unknown=private-option','schedule:work'],null],
        [['artisan','-x','private-option','schedule:work'],null],
        [['artisan','--quiet=private-option','schedule:work'],null],
        [['artisan','--env','--unknown','private-option','schedule:work'],null],
        [['artisan','','private-customer'],null],
        [['artisan','private/customer'],null],
        [['artisan',null,'private-customer'],null],
    ];
    try{
        foreach($cases as$i=>[$tokens,$command]){
            $_SERVER['argv']=$tokens;
            same(['missing_events'=>[],'mode'=>'console','pid'=>getmypid(),'command'=>$command,'release'=>'hook-test-f1e670c'],
                HookDiagnostics::context([]),'Exact safe command context case '.$i);
        }
    }finally{$_SERVER['argv']=$argv;}
    echo "Console command context: global option values and trailing arguments excluded.\n";
}
same(0,$artisanStarts,'Diagnostics do not start Artisan or command registration callbacks');
if($mode==='http'){
    $app->instance('request',Illuminate\Http\Request::create('/services/PRIVATE-CUSTOMER?token=PRIVATE-QUERY'));
    same('/services/{service}',HookDiagnostics::context([])['request_path'],'Parameter values excluded from path');
    $app->instance('request',Illuminate\Http\Request::create('/PRIVATE-UNMATCHED?token=PRIVATE-QUERY'));
    same('[unmatched]',HookDiagnostics::context([])['request_path'],'Unmatched customer path excluded');
    $app->instance('request',$request);
}
config(['app.release'=>null]);putenv('RELEASE_ID=release-fallback');
same('release-fallback',HookDiagnostics::context([])['release'],'Optional RELEASE_ID fallback');
putenv('RELEASE_ID');expect(!isset(HookDiagnostics::context([])['release']),'Absent release omitted');
config(['app.release'=>'hook-test-f1e670c']);
if(in_array($phase,['rebuilt','duplicate'],true)){require __DIR__.'/hook-dispatcher.php';$nativeSuccessful=true;return;}
if($phase==='healthy'){echo "Healthy full $mode provider boot: no warning.\n";$nativeSuccessful=true;return;}

// Use the same genuine action bridges as both native-paid matrices, with no network sender.
require_once __DIR__.'/commerce.php';require_once __DIR__.'/offline-acquisition.php';require_once __DIR__.'/native-financial-snapshot.php';
$ops=json_decode(file_get_contents($root.'/operations.json'),true,8,JSON_THROW_ON_ERROR);
$clock=new Paymenter\Extensions\Servers\Domains\Tests\FakeClock(new DateTimeImmutable('2026-10-18T12:10:00Z'));
Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
$preflight=(object)['supported'=>true];require __DIR__.'/native-paid-acquisition.php';
$bindings=$paidBindings;$claims=new OperationClaims($bindings,$clock);$features=new WriteFeatures(true,true,true,true);
$a=Account::where('server_id',Operation::findOrFail($ops[0])->server_id)->sole();$b=CatalogueAcceptances::binding($a);
$authority=new RenewalAuthority($bindings,$clock);$accept=new RenewalAcceptance($b,true,true,25);
$renewal=new RenewalExecution($bindings,$authority,new RenewalAdmission($authority,$bindings,$accept,$clock),$accept,$features,$clock);
$composition=['acquisition'=>offlineAcquisition($b,$paidBindings,$clock,$features,$paidExchange,$paidPrecheck),
    'reconciliation'=>new OperationReconciliation($claims,$bindings,$clock),'renewal'=>$renewal,
    'renewal_writer'=>new RenewalWriter($b,$features,$accept,fn()=>$renewal,static fn()=>throw new LogicException('No renewal wire')),
    'renewal_completion'=>new RenewalCompletion($bindings,$clock),'management'=>new DomainManagement($bindings,$clock,$features,true),
    'management_protocol'=>new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Management($b,static fn()=>throw new LogicException('No management wire'))];
$ports=app(RuntimePorts::class);$ports->register($b->fingerprint,fn()=>$composition);
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);app()->instance(AccountBindings::class,$bindings);
$work=app(RuntimeWork::class);
$snapshot=static fn()=>array_map(static fn($id)=>[Operation::findOrFail($id)->getRawOriginal(),nativeFinancialSnapshot(Operation::findOrFail($id))],$ops);
$before=$snapshot();
if($phase==='missing'){
    for($tick=0;$tick<3;$tick++){
        $clock->instant=$clock->now()->modify('+61 seconds');Cache::flush();$work->tick();
        foreach($ops as$id){$work->operation($id);}
    }
    $other=new RuntimePorts;$other->register($b->fingerprint,fn()=>$composition);
    foreach($ops as$id){$other->operation(Operation::findOrFail($id));}
    same(0,$writer->calls,'Missing saving makes zero acquisition sends across ticks and instances');
    same($before,$snapshot(),'Paid deferral preserves operation and financial bytes');
    $deferred=array_values(array_filter($logs,static fn($log)=>$log['message']==='Domains paid operation deferred: required Service listeners missing.'));
    same(2,count($deferred),'Exactly one diagnostic per operation per process across ticks and instances');
    foreach($ops as$i=>$id){
        same(['message'=>'Domains paid operation deferred: required Service listeners missing.',
            'context'=>['operation_id'=>$id,...$expectedContext]],$deferred[$i],'Exact operation diagnostic context');
        $op=Operation::findOrFail($id);same('paid',Invoice::findOrFail($op->invoice_id)->status,'Paid state retained');
        same('awaiting_payment',$op->state,'Unsent work retained without a permanent hold');
    }
}else{
    foreach($ops as$id){
        $clock->instant=$clock->now()->modify('+61 seconds');Cache::flush();
        Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
        $work->operation($id);$work->operation($id);
    }
    same(2,$writer->calls,'Healthy fresh process recovers one registration and one transfer send: '.json_encode(Operation::whereIn('id',$ops)->get(['id','state','safe_code'])->toArray()));
    foreach($ops as$id){
        $op=Operation::findOrFail($id);same('uncertain',$op->state,'Recovered genuine bridge retains uncertain send');
        $service=$op->domain->service;$service->forceFill(['expires_at'=>'2030-01-01'])->save();
        same(null,$service->fresh()->expires_at,'Real saving expiry guard is installed in recovered process');
        same('paid',Invoice::findOrFail($op->invoice_id)->status,'Recovery keeps settled native payment');
    }
}
expect(!str_contains(json_encode($logs,JSON_THROW_ON_ERROR),'PRIVATE-'),'Logs exclude arguments, query and customer paths');
echo "$phase/$mode: paid registration and transfer checks passed.\n";$nativeSuccessful=true;
