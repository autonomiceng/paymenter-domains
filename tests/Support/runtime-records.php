<?php
// Installed native runtime with synthetic account-bound ports. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require dirname(__DIR__).'/native-app.php';
// Prepare retained C6 catalogue behavior before exercising the extension lifecycle.
(new \Paymenter\Extensions\Servers\Domains\Services\Installation)->install();
(new \Paymenter\Extensions\Servers\Domains\Domains)->installed();
$fixtureActivation=static fn()=>null;
$fixtureRegistrars=['enom'];
require __DIR__.'/owner-records.php';
require __DIR__.'/installed.php';
require_once __DIR__.'/staff-panel.php';
require_once __DIR__.'/offline-acquisition.php';
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, RuntimePorts, NativeCatalogue, PriceCatalogue, SellerPolicies, RenewalAuthority, RenewalAcceptance, RenewalAdmission, RenewalExecution, RenewalWriter, RenewalCompletion, WriteFeatures};
use Paymenter\Extensions\Servers\Domains\Models\Account;
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
// Installation may already have resolved the limiter with the previous app clock. Rebuild it with the same scoped fixture clock and unchanged native background ceiling.
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter::class,new \Paymenter\Extensions\Servers\Domains\Services\AccountReadBudget($clock,48));
installedPanel();
[$d,$driver,$original]=$holding(placeholderComFixtureName('record-runtime-placeholder.com'));
// Prepare the Enom fixture before installing its runtime read composition.
// The installed contract must inspect normal adapters, not the arithmetic fixture's injected reader.
$runtimePrices=new PriceCatalogue(new \Paymenter\Extensions\Servers\Domains\Services\PricingRules);
$runtimeNative=new NativeCatalogue($runtimePrices);$runtimeSellers=new SellerPolicies($runtimePrices,$readBindings);
foreach([AccountBindings::class=>$readBindings,NativeCatalogue::class=>$runtimeNative,PriceCatalogue::class=>$runtimePrices,SellerPolicies::class=>$runtimeSellers] as $type=>$value){app()->instance($type,$value);}
// Install the real producer and rebuild services after changing the scoped account reader/clock.
app()->instance(RuntimePorts::class,new RuntimePorts);
app()->offsetUnset(\Paymenter\Extensions\Servers\Domains\Services\Runtime::class);
app()->offsetUnset(\Paymenter\Extensions\Servers\Domains\Services\RuntimeWork::class);
\Paymenter\Extensions\Servers\Domains\Services\Runtime::register();
\Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting::reset();
App\Models\Server::findOrFail($d->server_id)->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['value'=>'1']);
$a=Account::where('server_id',$d->server_id)->sole();$b=\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($a);
$nativePage=$page;$page=static function()use($nativePage):string { storefrontViews();app('view')->getFinder()->flush();return $nativePage(); };
$runtimePorts=app(RuntimePorts::class);$compositionReady=true;
$registerRecords=static function()use($runtimePorts,$b,$bindings,$clock,$engine,$reconcile,$management,&$driver,&$compositionReady):void {
    $features=new WriteFeatures(true,true,true,true);$authority=new RenewalAuthority($bindings,$clock,offers: $GLOBALS['caps']??null);$accept=new RenewalAcceptance($b,true,true,25);
    $renewal=new RenewalExecution($bindings,$authority,new RenewalAdmission($authority,$bindings,$accept,$clock),$accept,$features,$clock);
    $composition=['acquisition'=>offlineAcquisition($b,$bindings,$clock,$features,static fn()=>throw new LogicException('No acquisition exchange')),'reconciliation'=>$reconcile,'renewal'=>$renewal,
        'renewal_writer'=>new RenewalWriter($b,$features,$accept,fn()=>$renewal,static fn()=>throw new LogicException('No renewal transport')),
        'renewal_completion'=>new RenewalCompletion($bindings,$clock),'management'=>$management,'management_protocol'=>$driver];
    $runtimePorts->register($b->fingerprint,static function()use($composition,&$compositionReady){return $compositionReady?$composition:[];});
};
$dueRecords=static function()use($clock,$d):void {
    $clock->instant=$clock->now()->modify('+61 seconds');
    Illuminate\Support\Facades\DB::table('domains_observation_queues')->where('server_id',$d->server_id)->update(['turn'=>-1]);
    Illuminate\Support\Facades\DB::table('domains_observation_work')->where('domain_id',$d->id)->update(['due_at'=>$clock->now()->getTimestamp(),'safe_code'=>null]);
};
