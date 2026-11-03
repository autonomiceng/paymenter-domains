<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/offer-activation-test.php';$nativeSuccessful=false;
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerPorts,Capabilities,Context,Edit,Facts,FactsObserved,Intent,OfferScheduler,Query,Revision,StaffCatalogue,StaffCommands,UpgradeGate};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Operation,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,CartAdmission,CatalogueAcceptances,CheckoutEligibility,EnomTestRenewal,InvoiceBinding,NativeCatalogue,PayCheck,PriceFill,PublicationEligibility,Runtime,RuntimePorts,StaffSettingsReview,StaffSettingsWrites,Storefront};
use Illuminate\Support\Facades\DB;
// No test routing overrides: native events and long-lived installed services must select together.
$caps=Capabilities::routed();same(true,$caps instanceof Capabilities,'The checked switch must route the installed holder adapter');
$ports=CallerPorts::routed();$scheduler=OfferScheduler::routed();
same($scheduler,Runtime::observations(),'Runtime selects the sole shared consumer');
same($scheduler,OfferScheduler::routed(),'One generation shares one consumer');
$binding=CatalogueAcceptances::binding(Account::where('server_id',$tlds[0]->server_id)->sole());
$storefront=app(Storefront::class);$admission=app(CartAdmission::class);$admission->boot();
app(InvoiceBinding::class)->boot();
commerceReject(fn()=>$storefront->offer('com','register',1));
commerceReject(fn()=>$sellers->selection('com','register',1,$now));
same([],app(Storefront::class)->endings(),'Unknown facts withdraw all installed public endings');
same('10.40',$prices->renewal($binding->serverId,$binding->fingerprint,'com','USD',$now)->retail,'Installed holder route keeps retained annual retail with unknown facts');
commerceReject(fn()=>$caps->requireRenewal($binding,'com'));
commerceReject(fn()=>PublicationEligibility::require($tlds[0],'register',1));
commerceReject(fn()=>(new PriceFill($bindings,app(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)))->run(Account::where('server_id',$binding->serverId)->sole(),0));
commerceReject(fn()=>CatalogueAcceptances::save($binding,1,[]));
// Explicit synthetic TEST deployment gates; protocol proof is independent of new offer facts.
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_writes.registration'=>true,'domains.enom_writes.renewal'=>true]);
App\Models\Server::findOrFail($binding->serverId)->settings()->updateOrCreate(['key'=>EnomTestRenewal::KEY],['type'=>'string','encrypted'=>true,'value'=>json_encode(['fingerprint'=>$binding->fingerprint,...EnomTestRenewal::protocol($binding)])]);
$q=new Query($binding,['com'],'register');$revision=fn()=>$ports->readOffers($q,Context::Staff)[0]->revision;
$ports->apply(new FactsObserved($binding,'com',new Facts(['register'=>[1,3],'renew'=>[1],'transfer'=>[]],[],true,false,false,'synthetic',$now,$now->modify('+1 day'))),$revision());
$ports->apply(Edit::override($binding,'com',new Intent(sell:true,longest:3)),$revision());
$ports->apply(Edit::seller($binding,'com',['register']),$revision());
$public=$storefront->offer('com','register',3);
same(['$23.40','$10.40'],[$public['price'],$public['renew']],'Installed public route uses the shared evidenced term and annual retail');
same($public['selection']->catalogue,$sellers->selection('com','register',3,$now)->catalogue,'Direct seller selection uses the same fenced catalogue');
$page=StaffCatalogue::routed()->page(collect([$tlds[0]->fresh()]));expect($page!==[],'Installed staff table reads the shared offer');
$locked=DB::transaction(fn()=>$sellers->admit([$public['selection']],$now));same('23.40',$locked[0]->retail,'Installed locked admission uses current shared price');
$user=commerceUser();$cart=commerceCart($user);$line=commerceAdd($cart,$public['product'],'cutover-placeholder.com',3);
$admission->refresh();$before=$reads->calls;$checkout=commerceCheckout($user,$cart);
same(1,Operation::count(),'Real installed checkout binds exactly one obligation');
$op=Operation::firstOrFail();same('23.40',$op->policy['price']['retail'],'Native invoice freezes the admitted shared amount');
same($before,$reads->calls,'Locked checkout creates no second provider read');
$invoice=$op->invoice()->firstOrFail();same(null,app(PayCheck::class)->stop($invoice),'Installed payment check accepts current local offer evidence');
$financial=[$op->fresh()->getRawOriginal(),$invoice->fresh()->getRawOriginal()];
auth()->login($staff);$native->publish($tlds[0]->id,$now);
same($financial,[$op->fresh()->getRawOriginal(),$invoice->fresh()->getRawOriginal()],'Native publication preserves existing invoice and frozen operation');
// A formerly valid payment/cart selection cannot survive withdrawn fresh facts or warm display caches.
$ports->apply(new FactsObserved($binding,'com',new Facts(['register'=>[],'renew'=>[1],'transfer'=>[]],[],true,false,false,'synthetic',$now,$now->modify('+1 day'))),$revision());
commerceReject(fn()=>DB::transaction(fn()=>$sellers->admit([$public['selection']],$now)));
same('unavailable',app(PayCheck::class)->stop($invoice)['why'],'Payment refuses a stale saved offer');
same('10.40',$prices->renewal($binding->serverId,$binding->fingerprint,'com','USD',$now)->retail,'Withdrawal retains holder retail');
same([Runtime::class,'initialManagedRecordObservations'],app(RuntimePorts::class)->initialManagedRecordProducer(),'Only the installed named initial-record producer is registered');
$nativeSuccessful=true;echo "Installed checked cutover caller matrix passed.\n";
