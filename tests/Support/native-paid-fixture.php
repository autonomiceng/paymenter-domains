<?php
// Offline native payment matrix composition. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureActivation=static fn()=>null;
require_once dirname(__DIR__).'/native-app.php';
$entry=new Paymenter\Extensions\Servers\Domains\Domains;$entry->installed();
require __DIR__.'/operation-fixture.php';require __DIR__.'/installed.php';require_once __DIR__.'/staff-panel.php';
require_once __DIR__.'/offline-acquisition.php';
use Paymenter\Extensions\Servers\Domains\Services\{Runtime,RuntimePorts,RuntimeWork,AccountBindings,NativeCatalogue,PriceCatalogue,SellerPolicies,CartAdmission,InvoiceBinding,OperationReconciliation,RenewalAuthority,RenewalAcceptance,RenewalAdmission,RenewalExecution,RenewalWriter,RenewalCompletion,DomainManagement,WriteFeatures,CatalogueAcceptances};
use Paymenter\Extensions\Servers\Domains\Models\Account;
foreach([Paymenter\Extensions\Servers\Domains\Contracts\Clock::class=>$clock,AccountBindings::class=>$bindings,
    NativeCatalogue::class=>$native,PriceCatalogue::class=>$prices,SellerPolicies::class=>$sellers,CartAdmission::class=>$admission,InvoiceBinding::class=>$invoiceBinding]as$class=>$object){app()->instance($class,$object);}
$entry->boot();installedPanel();
expect(!app()->bound(App\Services\Invoice\ProcessPaidInvoiceService::class),'Native paid processor has no extension binding');
same(App\Services\Invoice\ProcessPaidInvoiceService::class,get_class(app(App\Services\Invoice\ProcessPaidInvoiceService::class)),'Resolve the native paid processor');
$ports=app(RuntimePorts::class);$work=app(RuntimeWork::class);$preflight->cost='6.00';
$a=Account::where('server_id',$tlds[0]->server_id)->sole();$b=CatalogueAcceptances::binding($a);
$features=new WriteFeatures(true,true,true,true);$authority=new RenewalAuthority($bindings,$clock);$accept=new RenewalAcceptance($b,true,true,25);
$renewal=new RenewalExecution($bindings,$authority,new RenewalAdmission($authority,$bindings,$accept,$clock),$accept,$features,$clock);
require __DIR__.'/native-paid-acquisition.php';
$composition=['acquisition'=>offlineAcquisition($b,$paidBindings,$clock,$features,$paidExchange,$paidPrecheck),'reconciliation'=>new OperationReconciliation($claims,$bindings,$clock),
    'renewal'=>$renewal,'renewal_writer'=>new RenewalWriter($b,$features,$accept,fn()=>$renewal,static fn()=>throw new LogicException('No renewal wire in payment matrix')),
    'renewal_completion'=>new RenewalCompletion($bindings,$clock),'management'=>new DomainManagement($bindings,$clock,$features,true),
    'management_protocol'=>new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Management($b,static fn()=>throw new LogicException('No management wire in payment matrix'))];
$ports->register($b->fingerprint,fn()=>$composition);expect($ports->accepted($a),'Complete offline composition accepted');
$paid=static function($op)use($clock):void {
    $clock->instant=$clock->now()->modify('+61 seconds');Illuminate\Support\Facades\Cache::flush();
    $invoice=App\Models\Invoice::findOrFail($op->invoice_id);
    App\Models\InvoiceTransaction::create(['invoice_id'=>$invoice->id,'amount'=>$invoice->total,'fee'=>'0.00','status'=>'succeeded']);
    Illuminate\Support\Facades\DB::transaction(fn()=>$invoice->fresh()->update(['status'=>'paid']));
};
$manual=static function($op)use($clock):void {$clock->instant=$clock->now()->modify('+61 seconds');Illuminate\Support\Facades\Cache::flush();(new App\Jobs\Server\CreateJob(App\Models\Service::findOrFail($op->service_id),false))->handle();};
$alerts=static fn($op)=>Illuminate\Support\Facades\DB::table('domains_obligation_alerts')->where('domain_id',$op->domain_id)->where('code','acquisition_late_money')->whereNull('resolved_at');
