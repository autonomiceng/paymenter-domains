<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/offer-activation-test.php';$nativeSuccessful=false;
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerPorts,Capabilities,Context,Edit,Facts,FactsObserved,Intent,OfferScheduler,Query,Revision,StaffCatalogue,StaffCommands,UpgradeGate};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Operation,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,CartAdmission,CatalogueAcceptances,CheckoutEligibility,EnomTestRenewal,InvoiceBinding,NativeCatalogue,ObservationRecords,PayCheck,PriceFill,PublicationEligibility,Runtime,RuntimePorts,StaffOperationReview,StaffOperations,StaffSettingsReview,StaffSettingsWrites,Storefront};
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
expect($storefront->endings()!==[],'Committed evidence invalidates the cached empty display');
$public=$storefront->offer('com','register',3);
same(['$23.40','$10.40'],[$public['price'],$public['renew']],'Installed public route uses the shared evidenced term and annual retail');
// The pricing notice resolves this reader directly, without the storefront's routed adapter.
$legacyPriceReads=0;$checkingLegacyReader=true;$legacyReaderRefused=false;
DB::listen(static function($query)use(&$legacyPriceReads,&$checkingLegacyReader):void {
    if($checkingLegacyReader&&str_contains($query->sql,'"domains_prices"')){$legacyPriceReads++;}
});
try{app(Paymenter\Extensions\Servers\Domains\Services\StorefrontCatalogueRead::class)->offer('com','register',1);}
catch(DomainException){$legacyReaderRefused=true;}
finally{$checkingLegacyReader=false;}
same(0,$legacyPriceReads,'Direct pricing-notice reader refuses before legacy price reads after cutover');
same(true,$legacyReaderRefused,'Direct legacy reader cannot return an offer after cutover');
same($public['selection']->catalogue,$sellers->selection('com','register',3,$now)->catalogue,'Direct seller selection uses the same fenced catalogue');
$page=StaffCatalogue::routed()->page(collect([$tlds[0]->fresh()]));expect($page!==[],'Installed staff table reads the shared offer');
$locked=DB::transaction(fn()=>$sellers->admit([$public['selection']],$now));same('23.40',$locked[0]->retail,'Installed locked admission uses current shared price');
$user=commerceUser();$cart=commerceCart($user);$line=commerceAdd($cart,$public['product'],'cutover-placeholder.com',3);
$admission->refresh();$before=$reads->calls;$checkout=commerceCheckout($user,$cart);
same(4,Operation::count(),'Real installed checkout adds exactly one obligation beside three frozen historical operations');
$op=Operation::where('operation_key','like','invoice-item:%')->sole();same('23.40',$op->policy['price']['retail'],'Native invoice freezes the admitted shared amount');
same($before,$reads->calls,'Locked checkout creates no second provider read');
$cost=DB::transaction(fn()=>$prices->current($binding->serverId,$binding->fingerprint,'com','register',3,'USD',$now,true,$tlds[0]->fresh()));same([3,'23.40','18.0000'],[$cost->term,$cost->retail,$cost->wholesale],'Locked normalized cost path preserves exact term-total evidence');
commerceReject(fn()=>DB::transaction(fn()=>$prices->current($binding->serverId,str_repeat('f',64),'com','register',3,'USD',$now,true,$tlds[0]->fresh())));
$invoice=App\Models\Invoice::findOrFail($op->invoice_id);same(null,app(PayCheck::class)->stop($invoice),'Installed payment check accepts current local offer evidence');
$financial=[$op->fresh()->getRawOriginal(),$invoice->fresh()->getRawOriginal()];
auth()->login($staff);
$review=new StaffSettingsReview((int)$staff->id,$binding->serverId,(int)$tlds[0]->id,'publish',StaffSettingsReview::ticket((int)$staff->id,$binding->serverId,(int)$tlds[0]->id,'publish',StaffCommands::stamp($binding->serverId,(int)$tlds[0]->id)),'Synthetic cutover review');
app(StaffSettingsWrites::class)->run($review,[],$now);
expect(str_contains(app(Paymenter\Extensions\Servers\Domains\Services\PriceList::class)->csv(3),'23.40'),'Installed staff CSV uses the same term total');
$native->publish($tlds[0]->id,$now);
same($financial,[$op->fresh()->getRawOriginal(),$invoice->fresh()->getRawOriginal()],'Native publication preserves existing invoice and frozen operation');
// A formerly valid payment/cart selection cannot survive withdrawn fresh facts or warm display caches.
app(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->instant=$now=$now->modify('+1 second');
$ports->apply(new FactsObserved($binding,'com',new Facts(['register'=>[],'renew'=>[1],'transfer'=>[]],[],true,false,false,'synthetic',$now,$now->modify('+1 day'))),$revision());
commerceReject(fn()=>DB::transaction(fn()=>$sellers->admit([$public['selection']],$now)));
same('unavailable',app(PayCheck::class)->stop($invoice)['why'],'Payment refuses a stale saved offer');
same([],$storefront->endings(),'Withdrawn evidence invalidates the previously warm display');
$claims=new Paymenter\Extensions\Servers\Domains\Services\OperationClaims($bindings,app(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class));
$acceptance=new Paymenter\Extensions\Servers\Domains\Services\WriteAcceptance($binding,true,true,25);$held=false;
try{$claims->locked($op->id,fn($locked)=>$acceptance->requireCurrent($binding,new Paymenter\Extensions\Servers\Domains\Data\Query($op->domain->domain,Paymenter\Extensions\Servers\Domains\Data\Action::Register,3),$locked,true,false));}
catch(Paymenter\Extensions\Servers\Domains\Services\WriteHeld $e){$held=$e->reason==='order_not_supported';}
same(true,$held,'Installed locked spending checks current facts rather than legacy accepted terms');
same('10.40',$prices->renewal($binding->serverId,$binding->fingerprint,'com','USD',$now)->retail,'Withdrawal retains holder retail');
same([Runtime::class,'initialManagedRecordObservations'],app(RuntimePorts::class)->initialManagedRecordProducer(),'Only the installed named initial-record producer is registered');
// Old jobs and the actual manual/callback routes cannot restart legacy catalogue reads.
$job=new Paymenter\Extensions\Servers\Domains\Jobs\SyncPrices((int)$staff->id,$binding->serverId,$binding->fingerprint);$job->upgradeGeneration=0;$before=$reads->calls;$job->handle();same($before,$reads->calls,'Old queued generation cannot read');
$manual=new Paymenter\Extensions\Servers\Domains\Jobs\SyncPrices((int)$staff->id,$binding->serverId,$binding->fingerprint);$manual->handle();
expect(is_array($scheduler->status($binding,'com')),'Actual status entry reads the shared consumer');
$frozen=DB::table('domains_operations')->orderBy('id')->get()->toJson();Runtime::initialManagedRecordObservations();same($frozen,DB::table('domains_operations')->orderBy('id')->get()->toJson(),'Initial-record callback preserves paid/pending/uncertain attempts');same($before,$reads->calls,'Sole active callback never invokes the legacy account reader');
// Synthetic completed holding on the real checkout graph; recovery must use installed staff routing.
DB::table('domains_operations')->where('id',$op->id)->update(['state'=>'succeeded']);
DB::table('domains_domains')->where('id',$op->domain_id)->update(['status'=>'active']);
$readDomain=$op->domain->fresh();$clock=app(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class);
DB::table('domains_observation_work')->where('domain_id',$readDomain->id)->delete();
$readJob=DB::table('domains_observation_work')->insertGetId(['server_id'=>$op->server_id,'account_fingerprint'=>$op->account_fingerprint,
    'domain_id'=>$readDomain->id,'generation'=>$op->authority_generation,'phase'=>'holding','safe_code'=>'read_unavailable','tries'=>5,'due_at'=>0]);
$ticket=static fn($actor)=>StaffOperationReview::ticket((int)$actor->id,(int)$readDomain->id,(int)$op->id,'resume_observation',StaffOperationReview::stamp($op->fresh(),$readDomain->fresh()),$clock);
$resume=static fn($actor,$ticket)=>app(StaffOperations::class)->run($actor,(int)$readDomain->id,(int)$op->id,'resume_observation',$ticket,'Synthetic holding sync recovery');
$frozenHolding=[$op->fresh()->attempt,$op->fresh()->funding,$op->fresh()->cost,$op->fresh()->amount];
$resume($staff,$ticket($staff));
$job=DB::table('domains_observation_work')->where('id',$readJob)->sole();
same([1,0,null,null,$clock->now()->getTimestamp()],[(int)$job->resumes,(int)$job->tries,$job->safe_code,$job->claim,(int)$job->due_at],'Active Resume sync resets stopped reads immediately');
ObservationRecords::hold($readDomain->fresh(),'records_unavailable',$clock->now());
$resume($staff,$ticket($staff));
same(false,ObservationRecords::held($readDomain->fresh()),'Active record-only recovery clears the record hold');
same(2,(int)DB::table('domains_observation_work')->where('id',$readJob)->value('resumes'),'Record-only recovery uses the same resume counter');
same(2,count(array_filter($op->fresh()->policy['staff_actions'],static fn($a)=>$a['action']==='resume_observation')),'Both active resumptions retain staff audit');
same(2,count($readDomain->fresh()->observation['resumptions']),'Both active resumptions retain holding audit');
same($frozenHolding,[$op->fresh()->attempt,$op->fresh()->funding,$op->fresh()->cost,$op->fresh()->amount],'Resume cannot change frozen financial evidence');
DB::table('domains_observation_work')->where('id',$readJob)->update(['safe_code'=>'read_unavailable','tries'=>5]);
$withoutPermission=commerceUser();commerceReject(fn()=>$resume($withoutPermission,$ticket($withoutPermission)));
$stale=$ticket($staff);$readDomain->fresh()->update(['observation'=>[...$readDomain->fresh()->observation,'synthetic_review_change'=>true]]);
commerceReject(fn()=>$resume($staff,$stale));
DB::table('domains_observation_work')->where('id',$readJob)->update(['lease_until'=>$clock->now()->getTimestamp()+60]);
commerceReject(fn()=>$resume($staff,$ticket($staff)));
same(2,(int)DB::table('domains_observation_work')->where('id',$readJob)->value('resumes'),'Permission, stale review and live lease refusals cannot consume a resume');
DB::table('domains_observation_work')->where('id',$readJob)->update(['lease_until'=>0,'resumes'=>20]);
commerceReject(fn()=>$resume($staff,$ticket($staff)));
same(20,(int)DB::table('domains_observation_work')->where('id',$readJob)->value('resumes'),'Active recovery preserves the resume cap');
// The installed global form ticket must match the routed Writer's all-account stamp.
\Illuminate\Support\Facades\Auth::setUser($staff);$clock->instant=$now=$now->modify('+1 second');
$ports->apply(new FactsObserved($binding,'com',new Facts(['register'=>[1,3],'renew'=>[1],'transfer'=>[]],[],true,false,false,'synthetic',$now,$now->modify('+1 day'))),$revision());
$review=new StaffSettingsReview($staff->id,StaffSettingsReview::ALL_ACCOUNTS,null,'default_pricing',StaffSettingsReview::ticket($staff->id,StaffSettingsReview::ALL_ACCOUNTS,null,'default_pricing',StaffSettingsReview::stamp(StaffSettingsReview::ALL_ACCOUNTS,null)),'Synthetic installed global review');
$result=app(StaffSettingsWrites::class)->run($review,['mode'=>'fixed','fixed_register'=>'40.00','fixed_renew'=>'41.00','fixed_transfer'=>'42.00','fee_percent'=>'3.00','fee_fixed'=>'0.30'],$now);
same('$40.00',$storefront->offer('com','register',1)['price'],'Installed global pricing save reaches active catalogue publication');
expect($result['published']>0,'Installed global command returns main publication result');
$nativeSuccessful=true;echo "Installed checked cutover caller matrix passed.\n";
