<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/offer-activation-test.php';$nativeSuccessful=false;
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerPorts,Capabilities,Context,Edit,Facts,FactsObserved,Intent,OfferScheduler,Query,Revision,StaffCatalogue,StaffCommands,UpgradeGate};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Operation,Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, CartAdmission, CheckoutEligibility, EnomTestRenewal, InvoiceBinding, NativeCatalogue, ObservationRecords, PayCheck, Runtime, RuntimePorts, StaffOperationReview, StaffOperations, StaffSettingsReview, StaffSettingsWrites, Storefront};
use Illuminate\Support\Facades\DB;
// No test routing overrides: native events and long-lived installed services must select together.
$caps=Capabilities::routed();same(true,$caps instanceof Capabilities,'The checked switch must route the installed holder adapter');
$ports=CallerPorts::routed();$scheduler=OfferScheduler::routed();
same($scheduler,Runtime::observations(),'Runtime selects the sole shared consumer');
same($scheduler,OfferScheduler::routed(),'One generation shares one consumer');
$binding=\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding(Account::where('server_id',$tlds[0]->server_id)->sole());
$storefront=app(Storefront::class);$admission=app(CartAdmission::class);$admission->boot();
app(InvoiceBinding::class)->boot();
commerceReject(fn()=>$storefront->offer('com','register',1));
commerceReject(fn()=>$sellers->selection('com','register',1,$now));
same([],app(Storefront::class)->endings(),'Unknown facts withdraw all installed public endings');
same('10.40',$prices->renewal($binding->serverId,$binding->fingerprint,'com','USD',$now)->retail,'Installed holder route keeps retained annual retail with unknown facts');
commerceReject(fn()=>$caps->requireRenewal($binding,'com'));
expect(!is_file(dirname(__DIR__).'/Domains/Services/PublicationEligibility.php'),'Legacy publication policy is deleted');
// Explicit synthetic TEST deployment gates; protocol proof is independent of new offer facts.
config(['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_writes.registration'=>true,'domains.enom_writes.renewal'=>true]);
App\Models\Server::findOrFail($binding->serverId)->settings()->updateOrCreate(['key'=>EnomTestRenewal::KEY],['type'=>'string','encrypted'=>true,'value'=>json_encode(['fingerprint'=>$binding->fingerprint,...EnomTestRenewal::protocol($binding)])]);
$q=new Query($binding,['com'],'register');$revision=fn()=>$ports->readOffers($q,Context::Staff)[0]->revision;

// Real bridge replay starts with unknown facts and retained native rows. One bounded account feed
// plus metadata for its selected sold set must reopen at the original retail, without deleting IDs.
$nativePricesBefore = DB::table('prices')->orderBy('id')->get()->keyBy('id')->map(fn($p) => [$p->plan_id, $p->currency_code, $p->price])->all();
$bulkClock = app(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class);
$bulkClock->instant = $now = $now->modify('+1 second');
$bulkFeed = new class implements Paymenter\Extensions\Servers\Domains\Catalogue\PricedFeed {
    public int $prices = 0; public array $facts = [];
    public function priceReads(): int { return 5; }
    public function factsReads(): int { return 2; }
    public function prices(Paymenter\Extensions\Servers\Domains\Data\RequestContext $c): iterable {
        $this->prices++; $costs = [];
        foreach ([['register',1,'6.0000'],['register',3,'18.0000'],['renew',1,'8.0000'],['transfer',1,'7.0000']] as [$a,$n,$cost]) {
            $costs[] = new Paymenter\Extensions\Servers\Domains\Catalogue\Cost($a,$n,true,$cost,Paymenter\Extensions\Servers\Domains\Data\CostUnit::TermTotal,
                'enom_bulk_bucket_'.($n === 3 ? 2 : 1),$c->now(),$c->now()->modify('+48 hours'));
        }
        return [new Paymenter\Extensions\Servers\Domains\Catalogue\FeedObserved($c->binding,'com',$costs),new Paymenter\Extensions\Servers\Domains\Catalogue\FeedObserved($c->binding,'org',[])];
    }
    public function facts(string $tld,Paymenter\Extensions\Servers\Domains\Data\RequestContext $c): FactsObserved {
        $this->facts[] = $tld;
        return new FactsObserved($c->binding,$tld,new Facts(['register'=>[1,3]],[],true,false,false,'enom_tld_facts',$c->now(),$c->now()->modify('+30 days')));
    }
};

// Staff can enable a selected ending before its first metadata read; intent alone cannot open it.
$pendingIntent=json_decode($tlds[0]->fresh()->catalogue_intent,true);$pendingIntent['sell']=false;
DB::table('domains_tlds')->where('id',$tlds[0]->id)->update(['catalogue_intent'=>json_encode($pendingIntent)]);
$enableReview=new StaffSettingsReview($staff->id,$binding->serverId,null,'acceptance',StaffSettingsReview::ticket($staff->id,$binding->serverId,null,'acceptance',StaffSettingsReview::stamp($binding->serverId,null)),'');
app(StaffSettingsWrites::class)->run($enableReview,['sell'=>['choices'=>['com'=>'sell']]]);
same(null,$tlds[0]->fresh()->catalogue_facts,'Enable records intent without fabricating registry metadata');
same(false,$ports->readOffers($q,Context::Public)[0]->available,'Pending staff intent remains closed');
same('queued',$scheduler->status($binding,'com')['state'],'Actual staff enable queues selected-set facts');
$bulkWork = new Paymenter\Extensions\Servers\Domains\Catalogue\OfferWork($ports->writer(),$bulkClock,
    new Paymenter\Extensions\Servers\Domains\Services\AccountReadBudget($bulkClock,25),fn()=>$bulkFeed,fn()=>25);
$bulkWork->sync($binding); $bulkWork->accountTurn($binding->serverId); $bulkWork->accountTurn($binding->serverId);
// Newly queued hints survive the claim and are consumed on the following bounded turn.
$bulkWork->accountTurn($binding->serverId);
same([1,['com']],[$bulkFeed->prices,$bulkFeed->facts],'One bulk read warms only the sold set, never discovered org');
same(['$7.80','$10.40'],[$storefront->offer('com','register',1)['price'],$storefront->offer('com','register',1)['renew']],'Moved bulk prices restore the same annual retail');
foreach ($nativePricesBefore as $id=>$values) {
    $p=DB::table('prices')->where('id',$id)->first();
    expect($p!==null,'Every bridge native price identity survives publication');
    same(array_slice($values,0,2),[$p->plan_id,$p->currency_code],'Bridge price keeps its plan and currency');
    $years=(int)DB::table('plans')->where('id',$p->plan_id)->value('billing_period');
    // Current multi-year retail is the first registration year plus annual renewals.
    same($years===3?'28.60':number_format((float)$values[2],2,'.',''),number_format((float)$p->price,2,'.',''),'Current retail republishes without replacing bridge price rows');
}
// The real account row reuses the neighbouring permissions, one-use revision ticket and audit path.
require_once __DIR__.'/Support/staff-tlds.php'; tldPanel();
$accountPage = Livewire\Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class, ['account'=>$binding->serverId]);
$accountPage->assertSee('Prices stay current for')->assertSee('48 hours');
$bulkClock->instant = $now->modify('+49 hours');
same('stale_price',$ports->readOffers($q,Context::Staff)[0]->reason,'Default 48-hour bulk observation expires');
$accountPage->mountAction(Filament\Actions\Testing\TestAction::make('price_freshness_'.$binding->serverId)->schemaComponent(true,'content'))->setActionData(['hours'=>72,'reason'=>'Allow a missed daily catalogue read'])->callMountedAction()->assertHasNoActionErrors();
// The saved policy invalidates publication; finish the local republish before checking offers.
$ports->apply(Edit::republish($binding,'com'),$revision());
same(true,$ports->readOffers($q,Context::Public)[0]->available,'Saving 72 hours changes freshness of the same original observation');
same(72,Paymenter\Extensions\Servers\Domains\Data\CatalogueFreshness::forAccount($binding->serverId)->hours,'Account setting is saved');
$bulkClock->instant = $now;
$ageHistory = StaffSettingsReview::historyRows(App\Models\Server::findOrFail($binding->serverId));
expect(count(array_filter($ageHistory,fn($h)=>($h['action']??null)===StaffSettingsReview::ACTIONS['price_freshness']))===1,'Setting save records its audited action');
foreach ([23,169] as $hours) {
    $review=new StaffSettingsReview($staff->id,$binding->serverId,null,'price_freshness',StaffSettingsReview::ticket($staff->id,$binding->serverId,null,'price_freshness',StaffSettingsReview::stamp($binding->serverId,null)),'Synthetic invalid freshness');
    commerceReject(fn()=>app(StaffSettingsWrites::class)->run($review,['hours'=>$hours]));
}


$ports->apply(Edit::override($binding,'com',new Intent(sell:true,longest:3)),$revision());
$ports->apply(Edit::seller($binding,'com',['register']),$revision());
expect($storefront->endings()!==[],'Committed evidence invalidates the cached empty display');
$public=$storefront->offer('com','register',3);
same(['$28.60','$10.40'],[$public['price'],$public['renew']],'Installed public route uses the shared evidenced term and annual retail');
// The pricing notice resolves this reader directly and must share active publication policy.
$direct=app(Paymenter\Extensions\Servers\Domains\Services\StorefrontCatalogueRead::class)->offer('com','register',3);
same($public['price'],$direct['price'],'Direct pricing-notice reader uses the same active offer');
same($public['selection']->catalogue,$direct['selection']->catalogue,'Direct display has the same generation and revisions');
same($public['selection']->catalogue,$sellers->selection('com','register',3,$now)->catalogue,'Direct seller selection uses the same fenced catalogue');
$page=StaffCatalogue::routed()->page(collect([$tlds[0]->fresh()]));expect($page!==[],'Installed staff table reads the shared offer');
$locked=DB::transaction(fn()=>$sellers->admit([$public['selection']],$now));same('28.60',$locked[0]->retail,'Installed locked admission uses current shared price');
$user=commerceUser();$cart=commerceCart($user);$line=commerceAdd($cart,$public['product'],'cutover-placeholder.com',3);
$admission->refresh();$before=$reads->calls;$checkout=commerceCheckout($user,$cart);
same(4,Operation::count(),'Real installed checkout adds exactly one obligation beside three frozen historical operations');
$op=Operation::where('operation_key','like','invoice-item:%')->sole();same('28.60',$op->policy['price']['retail'],'Native invoice freezes the admitted shared amount');
same($before,$reads->calls,'Locked checkout creates no second provider read');
// Active workers use deployed protocol policy, independent of the retired ending list.
same(false,App\Models\Server::findOrFail($binding->serverId)->settings()->where('key','domains_catalogue_acceptance')->exists(),'B8 removes current legacy acceptance');
same(null,Paymenter\Extensions\Servers\Domains\Services\EnomTestRegistration::localHoldReason($binding,$op),'Active worker accepts evidenced order without legacy catalogue acceptance');
config(['domains.enom_writes.registration'=>false]);
expect(Paymenter\Extensions\Servers\Domains\Services\EnomTestRegistration::localHoldReason($binding,$op)!==null,'Removing write opt-in still holds active order');
config(['domains.enom_writes.registration'=>true]);

$cost=DB::transaction(fn()=>$prices->current($binding->serverId,$binding->fingerprint,'com','register',3,'USD',$now,true,$tlds[0]->fresh()));same([3,'28.60','18.0000'],[$cost->term,$cost->retail,$cost->wholesale],'Locked normalized cost path preserves exact term-total evidence');
commerceReject(fn()=>DB::transaction(fn()=>$prices->current($binding->serverId,str_repeat('f',64),'com','register',3,'USD',$now,true,$tlds[0]->fresh())));
$invoice=App\Models\Invoice::findOrFail($op->invoice_id);same(null,app(PayCheck::class)->stop($invoice),'Installed payment check accepts current local offer evidence');
$financial=[$op->fresh()->getRawOriginal(),$invoice->fresh()->getRawOriginal()];
auth()->login($staff);
$review=new StaffSettingsReview((int)$staff->id,$binding->serverId,(int)$tlds[0]->id,'publish',StaffSettingsReview::ticket((int)$staff->id,$binding->serverId,(int)$tlds[0]->id,'publish',StaffCommands::stamp($binding->serverId,(int)$tlds[0]->id)),'Synthetic cutover review');
app(StaffSettingsWrites::class)->run($review,[],$now);
expect(str_contains(app(Paymenter\Extensions\Servers\Domains\Services\PriceList::class)->csv(3),'28.60'),'Installed staff CSV uses the same term total');
$native->publish($tlds[0]->id,$now);
same($financial,[$op->fresh()->getRawOriginal(),$invoice->fresh()->getRawOriginal()],'Native publication preserves existing invoice and frozen operation');
// A formerly valid payment/cart selection cannot survive withdrawn fresh facts or warm display caches.
app(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->instant=$now=$now->modify('+1 second');
$ports->apply(new FactsObserved($binding,'com',new Facts(['register'=>[],'renew'=>[1],'transfer'=>[]],[],true,false,false,'synthetic',$now,$now->modify('+1 day'))),$revision());
commerceReject(fn()=>DB::transaction(fn()=>$sellers->admit([$public['selection']],$now)));
same('unavailable',app(PayCheck::class)->stop($invoice)['why'],'Payment refuses a stale saved offer');
same([],$storefront->endings(),'Withdrawn evidence invalidates the previously warm display');
$claims=new Paymenter\Extensions\Servers\Domains\Services\OperationClaims($bindings,app(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class));
$acceptance=new Paymenter\Extensions\Servers\Domains\Services\WriteAcceptance($binding,true,true,25,catalogue: $GLOBALS['caps']??null);$held=false;
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
commerceReject(fn()=>$resume($staff,$ticket($staff)));
expect(ObservationRecords::held($readDomain->fresh()),'Record hold stays visible until the queued read confirms recovery');
same($clock->now()->getTimestamp(),Paymenter\Extensions\Servers\Domains\Services\HoldingReadRetry::status($readDomain->fresh(),$clock->now())['next_read'],'The existing automatic read owns record-only recovery');
same(1,(int)DB::table('domains_observation_work')->where('id',$readJob)->value('resumes'),'An already queued read needs no second staff resume');
same(1,count(array_filter($op->fresh()->policy['staff_actions'],static fn($a)=>$a['action']==='resume_observation')),'The accepted resume retains staff audit');
same(1,count($readDomain->fresh()->observation['resumptions']),'The accepted resume retains holding audit');
same($frozenHolding,[$op->fresh()->attempt,$op->fresh()->funding,$op->fresh()->cost,$op->fresh()->amount],'Resume cannot change frozen financial evidence');
DB::table('domains_observation_work')->where('id',$readJob)->update(['safe_code'=>'read_unavailable','tries'=>5]);
$withoutPermission=commerceUser();commerceReject(fn()=>$resume($withoutPermission,$ticket($withoutPermission)));
$stale=$ticket($staff);$readDomain->fresh()->update(['observation'=>[...$readDomain->fresh()->observation,'synthetic_review_change'=>true]]);
commerceReject(fn()=>$resume($staff,$stale));
DB::table('domains_observation_work')->where('id',$readJob)->update(['lease_until'=>$clock->now()->getTimestamp()+60]);
commerceReject(fn()=>$resume($staff,$ticket($staff)));
same(1,(int)DB::table('domains_observation_work')->where('id',$readJob)->value('resumes'),'Permission, stale review and live lease refusals cannot consume a resume');
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

// The earlier intentional feed failure is not part of this missing-cost scenario.
DB::table('domains_observation_queues')->where('server_id',$binding->serverId)->update(['offer_feed_failure'=>null,'offer_feed_failed_at'=>null]);
// Discovered .org has metadata but no annual renewal price. It stays closed with the existing staff next step.
$orgQuery=new Query($binding,['org'],'register');$orgRevision=fn()=>$ports->readOffers($orgQuery,Context::Staff)[0]->revision;
$ports->apply(new FactsObserved($binding,'org',new Facts(['register'=>[1],'renew'=>[1]],[],true,false,false,'enom_tld_facts',$now,$now->modify('+30 days'))),$orgRevision());
$ports->apply(Edit::override($binding,'org',new Intent(sell:true)),$orgRevision());
$ports->apply(Edit::seller($binding,'org',['register']),$orgRevision());
same(false,$ports->readOffers($orgQuery,Context::Public)[0]->available,'Missing renewal price never enables an offer');
$org=Tld::where('server_id',$binding->serverId)->where('tld','org')->sole();
same('unknown_price',$ports->readOffers($orgQuery,Context::Staff)[0]->reason,'Supported annual renewal without its cost stays a missing-price hold');
same('Cost missing',StaffCatalogue::routed()->page(collect([$org]))[$org->id]['sales']['reason'],'Closed unknown renewal names the missing registrar cost');
$nativeSuccessful=true;echo "Installed checked cutover caller matrix passed.\n";
