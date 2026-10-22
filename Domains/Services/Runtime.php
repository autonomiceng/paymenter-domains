<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\{Event,Gate,Schedule,View};
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock,ReadLimiter};
use Paymenter\Extensions\Servers\Domains\Data\SystemClock;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
final class Runtime
{
    private bool $booted=false;
    public static function register(): void
    {
        ConversionGate::requireComplete();NativeIdentity::boot();$app=app();if($app->bound(self::class)){return;}
        $app->singleton(self::class);
        foreach([RegistrarRegistry::class,AccountBindings::class,PricingRules::class,PriceCatalogue::class,SellerPolicies::class,NativeCatalogue::class,
            CheckoutEligibility::class,CartAdmission::class,InvoiceBinding::class,HolderPricing::class,NativeDomainGuard::class,NativeServerGuard::class,
            Storefront::class,OwnedDomains::class,Installation::class,AccountReadBudget::class,ObservationWork::class,
            RegistrarBalances::class,PublicSuffixList::class,PslTransport::class,PslValidator::class,CatalogueSync::class,Reminders::class,Refunds::class,RuntimeWork::class,RuntimePorts::class,NativePresenter::class,WatchWork::class,WatchDiscoveryWork::class] as $class){
            if(!$app->bound($class)){$app->singleton($class);}
        }
        if (!$app->bound(\Paymenter\Extensions\Servers\Domains\Contracts\DnsReader::class)) {
            $app->bind(\Paymenter\Extensions\Servers\Domains\Contracts\DnsReader::class,
                fn($a) => $a->make(DnsResolverSettings::class)->reader());
        }
        if(!$app->bound(Clock::class)){$app->singleton(Clock::class,SystemClock::class);}
        if(!$app->bound(ReadLimiter::class)){$app->singleton(ReadLimiter::class,fn($a)=>new AccountReadBudget($a->make(Clock::class),48));}
        if(!$app->bound(OwnerPorts::class)){$app->singleton(OwnerPorts::class,fn($a)=>new OwnerPorts(fn($binding)=>$a->make(RuntimePorts::class)->management($binding)));}
        // Record discovery shares the accepted account/processing authority; ordinary domain reads remain independent.
        $app->singleton(DomainObservation::class,fn($a)=>new DomainObservation($a->make(AccountBindings::class),$a->make(Clock::class),
            $a->make(ReadLimiter::class),$a->make(OwnerPorts::class),static function(array $l)use($a):bool {
                try {
                    $a->make(AccountBindings::class)->requireProcessingLocked($l['server'],$l['account']);
                    return $a->make(RuntimePorts::class)->recordDiscoveryAccepted($l['account']);
                } catch (\Throwable) { return false; }
            }));
        $app->make(RuntimePorts::class)->registerInitialManagedRecordObservations(fn()=>$app->make(ObservationWork::class)->tick(1));
        if (RegistrarFactories::bootstrap($app->make(RegistrarRegistry::class))) {
            $app->singleton(StaffSettingsWrites::class, fn($a)=>new StaffSettingsWrites($a->make(NativeCatalogue::class),
                $a->make(SellerPolicies::class), $a->make(AccountBindings::class), true));
        }
        // Identity reads do not install accepted runtime ports or write senders.
        app(NativeServerGuard::class)->boot();
        $app->singleton(DomainManagement::class,fn($a)=>new DomainManagement($a->make(AccountBindings::class),$a->make(Clock::class)));
    }
    public static function limiter(): ?ReadLimiter {return app()->bound(self::class)?app(ReadLimiter::class):null;}
    public static function management(\Paymenter\Extensions\Servers\Domains\Models\Domain $d,?DomainManagement $fallback): ?DomainManagement
    {if(!app()->bound(self::class)){return $fallback;}return app(RuntimePorts::class)->managementEngine(CatalogueAcceptances::binding(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$d->server_id)->firstOrFail()));}
    public function boot(): void
    {
        ConversionGate::requireComplete();if($this->booted){return;}$this->booted=true;
        View::addNamespace('domains',dirname(__DIR__).'/resources/views');HostingDomainRow::boot();
        require dirname(__DIR__).'/routes/web.php';require dirname(__DIR__).'/routes/owned.php';
        app(NativeServerGuard::class)->boot();app(NativeDomainGuard::class)->boot();
        NativeCancellation::boot();AcquisitionRetirement::boot();\Paymenter\Extensions\Servers\Domains\Admin\Support\NativeEditorNotice::boot();
        \Illuminate\Console\Application::starting(static fn($console)=>$console->resolve(\Paymenter\Extensions\Servers\Domains\Console\ClearNativeExpiry::class));
        app(CartAdmission::class)->boot();app(InvoiceBinding::class)->boot();
        Event::listen('permissions',fn()=>Staff::permissions());
        Event::listen('navigation',fn()=>['name'=>'Domains','url'=>route('domains.domains.search'),'icon'=>'ri-global','priority'=>12,'spa'=>false,
            'children'=>[['name'=>'Register a domain','url'=>route('domains.domains.search'),'spa'=>false],['name'=>'Domain pricing','url'=>route('domains.domains.pricing'),'spa'=>false]]]);
        Event::listen('navigation.dashboard',fn()=>['name'=>'Domains','url'=>route('domains.domains.index'),'icon'=>'ri-global','priority'=>12,'spa'=>false]);
        foreach(['Domain','Operation'] as $model){Gate::policy('Paymenter\\Extensions\\Servers\\Domains\\Models\\'.$model,'Paymenter\\Extensions\\Servers\\Domains\\Policies\\'.$model.'Policy');}
        NativePaidHooks::boot();
        app()->booted(static function():void {foreach(NativePaidHooks::notices()as$feature=>$notice){if($notice!=='Available'){logger()->warning('Domains hook unavailable.',['feature'=>$feature,'notice'=>$notice]);}}});
        foreach(['saved','deleted']as$event) {
            Event::listen('eloquent.'.$event.': '.\App\Models\Service::class,static function($service):void {
                $product=(int)$service->product_id;
                \Illuminate\Support\Facades\DB::afterCommit(fn()=>app(WatchDiscoveryWork::class)->hint($product));
            });
            Event::listen('eloquent.'.$event.': '.\App\Models\Property::class,static function($property):void {
                if ($property->model_type!==(new \App\Models\Service())->getMorphClass()) { return; }
                $service=(int)$property->model_id;
                \Illuminate\Support\Facades\DB::afterCommit(static function()use($service):void {
                    if (!app(WatchDiscoveryWork::class)->ready()) { return; }
                    try {
                        $product=\App\Models\Service::whereKey($service)->value('product_id');
                        if ($product!==null) { app(WatchDiscoveryWork::class)->hint((int)$product); }
                    } catch (\Throwable) { \Illuminate\Support\Facades\Log::warning('Public domain property hint deferred.'); }
                });
            });
        }
        Event::listen('eloquent.saved: '.\Paymenter\Extensions\Servers\Domains\Models\WatchProductMap::class,static function($map):void {
            $product=(int)$map->product_id;
            \Illuminate\Support\Facades\DB::afterCommit(fn()=>app(WatchDiscoveryWork::class)->hint($product));
        });
        Schedule::call(fn()=>\Paymenter\Extensions\Servers\Domains\Jobs\RunRuntime::dispatch())->name('domains-runtime')->everyMinute()->withoutOverlapping(15)->onOneServer();
    }
}
