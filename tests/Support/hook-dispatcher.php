<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use App\Models\Service;
use Illuminate\Support\Facades\{DB,Event,Log,View};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement,CartAdmission,HoldingIdentityLock,HostingDomainRow,InvoiceBinding,InvoiceFunding,NativeCancellation,NativeDomainGuard,NativeExpiry,NativeIdentity,NativeIdentityDiscrepancies,NativeIdentityParents,NativePaidHooks,NativeServerGuard,NativeStock,NativeUserDeletion};
use Paymenter\Extensions\Servers\Domains\Admin\Support\NativeEditorNotice;

// Count raw owned callbacks, including closures, rather than Laravel's wrapper callbacks.
$counts=static function(object $dispatcher):array {
    $counts=[];
    foreach($dispatcher->getRawListeners()as$event=>$listeners){foreach($listeners as$listener){
        $owner=null;$line='';
        if($listener instanceof Closure){
            $file=(new ReflectionFunction($listener))->getFileName();
            if($file&&str_contains($file,'/Domains/Services/')){$owner=basename($file,'.php');$line=' @'.(new ReflectionFunction($listener))->getStartLine();}
        }elseif(is_array($listener)&&is_string($listener[0])&&str_starts_with($listener[0],'Paymenter\\Extensions\\Servers\\Domains\\')){
            $owner=substr(strrchr($listener[0],'\\'),1);
        }
        if($owner!==null){$key=$owner.' '.$event.$line;$counts[$key]=($counts[$key]??0)+1;}
    }}
    ksort($counts);return $counts;
};
// Transaction-bound evidence must expire on both sides of an Application rebuild.
$contexts=static function():void {
    $op=Paymenter\Extensions\Servers\Domains\Models\Operation::orderBy('id')->firstOrFail();$domain=$op->domain;
    foreach(['commit','rollBack']as$finish){
        DB::beginTransaction();
        $funding=InvoiceFunding::lock((int)$op->invoice_id);$funding->requirePaid($op,$domain);
        $service=Service::findOrFail($op->service_id);
        $identity=HoldingIdentityLock::lockForAdoption((int)$service->order_id,(int)$service->id);
        $identity->owners((int)$service->user_id);DB::$finish();
        DB::transaction(static function()use($funding,$identity,$op,$domain,$service,$finish):void {
            foreach(['funding'=>static fn()=>$funding->requirePaid($op,$domain),
                'identity'=>static fn()=>$identity->owners((int)$service->user_id)]as$kind=>$read){
                try{$read();}catch(LogicException $error){
                    same($kind==='funding'?'Funding snapshot expired.':'Identity lock expired.',$error->getMessage(),$kind.' expires after '.$finish);continue;
                }
                throw new RuntimeException($kind.' context survived '.$finish.' on the current dispatcher');
            }
        });
    }
};
$contexts();
// Exercise the lazy exact-object projection callbacks on each dispatcher too.
$project=static function():void {
    $service=Service::orderBy('id')->firstOrFail();
    DB::transaction(static fn()=>(new ReflectionMethod(NativeDomainGuard::class,'persist'))->invoke(null,$service,$service->getRawOriginal(),[]));
};
$project();$firstDispatcher=Service::getEventDispatcher();$before=$counts($firstDispatcher);
$viewBefore=count(View::getDispatcher()->getListeners('composing: services.show'));
expect($viewBefore>0,'Native boot registers the hosting composer');
$editorAvailable=static fn()=>Filament\Support\Facades\FilamentView::hasRenderHook(Filament\View\PanelsRenderHook::PAGE_START,App\Admin\Resources\ServiceResource\Pages\EditService::class);
expect($editorAvailable(),'Native boot registers the editor notice');
$distinct=static function(string $class):object {
    $reflection=new ReflectionClass($class);$constructor=$reflection->getConstructor();$arguments=[];
    foreach($constructor?->getParameters()??[]as$parameter){$type=$parameter->getType();
        expect($type instanceof ReflectionNamedType&&!$type->isBuiltin(),$class.' constructor uses injectable dependencies');$arguments[]=app($type->getName());}
    return $reflection->newInstanceArgs($arguments);
};
foreach([NativeExpiry::class,NativeIdentity::class,NativeStock::class,NativeIdentityParents::class,
    NativeIdentityDiscrepancies::class,NativeUserDeletion::class,NativeCancellation::class,
    NativeDomainGuard::class,NativeServerGuard::class,NativePaidHooks::class,AcquisitionRetirement::class,
    CartAdmission::class,InvoiceBinding::class,InvoiceFunding::class,HoldingIdentityLock::class]as$class){
    expect((bool)array_filter(array_keys($before),static fn($key)=>str_starts_with($key,class_basename($class).' ')),
        $class.' has owned listeners after native boot');
}
foreach($before as$key=>$count){same(1,$count,$key.' is registered exactly once');}

if($phase==='rebuilt'){
    // Match ConfigCacheCommand::getFreshConfiguration without writing a cache file.
    $fresh=require $fixture.'/bootstrap/app.php';$fresh->useStoragePath($app->storagePath());$freshLogs=[];
    $fresh->afterBootstrapping(Illuminate\Foundation\Bootstrap\RegisterFacades::class,static function()use(&$freshLogs):void {
        set_exception_handler(static function(Throwable $error):never{fwrite(STDERR,$error::class.': '.$error->getMessage()."\n");exit(1);});
        Log::listen(static function($event)use(&$freshLogs):void{$freshLogs[]=$event->message;});
        Illuminate\Support\Facades\Http::preventStrayRequests();
    });
    $fresh->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    expect($app!==$fresh&&$firstDispatcher!==Service::getEventDispatcher(),'Fresh Application uses a new dispatcher in the same process');
    same([],NativePaidHooks::serviceMissing(),'Rebuilt Application has all required Service listeners');
    same('Available',NativePaidHooks::notices()['Start after payment'],'Rebuilt Application has the paid listener');
    same([],array_values(array_filter($freshLogs,static fn($message)=>$message==='Domains hook unavailable.')),'Rebuilt Application emits no hook warning');
    $contexts();$project();
    same($before,$counts(Service::getEventDispatcher()),'Rebuilt Application registers every owned callback exactly once');
    same($viewBefore,count(View::getDispatcher()->getListeners('composing: services.show')),'Rebuilt Application gets the hosting composer');
    expect($editorAvailable(),'Rebuilt Application gets the editor notice');
    echo "Rebuilt Application: all owned listeners registered once, no hook warning.\n";
}else{
    $unrelated=static fn()=>null;Event::listen('domains.test.unrelated',$unrelated);
    $manager=Filament\Support\Facades\FilamentView::getFacadeRoot();
    $hooks=new ReflectionProperty($manager,'renderHooks');$renderBefore=$hooks->getValue($manager);
    for($i=0;$i<2;$i++){
        $contexts();$project();
        foreach([NativeExpiry::class,NativeIdentity::class,NativeStock::class,NativeIdentityParents::class,
            NativeIdentityDiscrepancies::class,NativeUserDeletion::class,NativeCancellation::class,
            NativePaidHooks::class,AcquisitionRetirement::class,HostingDomainRow::class,NativeEditorNotice::class]as$class){$class::boot();}
        foreach([NativeDomainGuard::class,NativeServerGuard::class,CartAdmission::class,InvoiceBinding::class]as$class){
            app($class)->boot();$distinct($class)->boot();
        }
    }
    same($before,$counts($firstDispatcher),'Repeated boot does not duplicate any owned callback');
    same($viewBefore,count(Event::getListeners('composing: services.show')),'Repeated boot does not duplicate the hosting composer');
    same($renderBefore,$hooks->getValue($manager),'Repeated boot does not duplicate the editor render hook');
    same([$unrelated],Event::getRawListeners()['domains.test.unrelated'],'Unrelated listener is preserved');
    echo "Repeated boot: every owned callback registered once, unrelated listener retained.\n";
}
