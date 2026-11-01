<?php
// Offline late-renewal boundaries; MIT, Copyright (c) 2026 Autonomic Engineering.
// Native invoice events, financial freeze and after-commit collection require real Paymenter models and transactions.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
require_once __DIR__.'/Support/staff-tlds.php';
use App\Models\{BillingAgreement,Gateway,Invoice};
use Illuminate\Support\Facades\{Cache,Mail};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling,EnomLateRenewal,EnomTestRenewal,EnomTestSendLimitSchema,PricingRule,RenewalAutoPay,StaffSettingsReview};
set_exception_handler(static function(Throwable $e):never{fwrite(STDERR,$e::class.': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);EnomTestSendLimitSchema::install();Mail::fake();
// The native payment hook must not read or lock unrelated invoice rows (SQLite has no row locks).
$plainInvoice=new Invoice(['user_id'=>$user->id,'currency_code'=>'USD','status'=>'pending']);$plainInvoice->send_create_email=false;$plainInvoice->save();
\Illuminate\Support\Facades\DB::enableQueryLog();\Illuminate\Support\Facades\DB::flushQueryLog();
\App\Models\InvoiceTransaction::getEventDispatcher()->dispatch('eloquent.creating: '.\App\Models\InvoiceTransaction::class,new \App\Models\InvoiceTransaction(['invoice_id'=>$plainInvoice->id,'amount'=>'1.00','status'=>'succeeded']));
$plainQueries=\Illuminate\Support\Facades\DB::getQueryLog();\Illuminate\Support\Facades\DB::disableQueryLog();
same([],array_values(array_filter($plainQueries,static fn($q)=>preg_match('/\bfrom\s+["`]?invoices\b/i',$q['query']))),'A non-domain payment hook does not read or lock its invoice');
$server=$product->server;$checkout=['domains.enom_identity.environment'=>null,'domains.enom_catalogue.enabled'=>false,'domains.enom_writes.renewal'=>false];
$on=['domains.enom_identity.environment'=>'test','domains.enom_catalogue.enabled'=>true,'domains.enom_writes.renewal'=>true,'domains.enom_test_sends_per_day'=>10000,'domains.enom_test_sends_per_minute'=>10000];
$lateBilling=new DomainBilling($renewBindings,$guard,$clock);
$holder=static function(string $suffix)use($makeRenewal,$checkout,$on,$clock,$cycles,$server):array{
    config($checkout);[$old,$d,$b]=$makeRenewal(placeholderComFixtureName('late-'.$suffix.'-placeholder.com'),false);
    $clock->instant=new DateTimeImmutable('2027-10-19T12:00:00Z');$cycles->expireUnpaid($d->id);
    same('renewal_cancelled_unpaid',$old->fresh()->safe_code,'Untouched ordinary debt closes at expiry');
    config($on);$s=$server->settings()->firstOrNew(['key'=>EnomTestRenewal::KEY]);$s->encrypted=true;$s->type='string';$s->value=json_encode(['fingerprint'=>$b->fingerprint,...EnomTestRenewal::protocol($b)]);$s->save();
    return [$d,$b];
};
[$d,$b]=$holder('quote');
expect(!EnomLateRenewal::accepted($b),'Late renewal starts Off');$coldAcceptance=EnomTestRenewal::acceptance($b);$state->calls=[];
commerceReject(fn()=>$lateBilling->invoice($d->id,$user,1));same([],$state->calls,'Off refuses before provider reads');
$actor=staffUser(['admin.domains.view','admin.domains.manage-policy',StaffSettingsReview::permission('late_renewal')]);
$toggle=static function(bool $on)use($actor,$server):void{
    $ticket=StaffSettingsReview::ticket($actor->id,$server->id,null,'late_renewal',StaffSettingsReview::stamp($server->id,null));
    $r=new StaffSettingsReview($actor->id,$server->id,null,'late_renewal',$ticket,'Account renewal review');$r->input(['accept'=>$on]);EnomLateRenewal::review($r,['accept'=>$on]);
};
tldPanel();app()->instance(\Paymenter\Extensions\Servers\Domains\Services\StaffSettingsWrites::class,new \Paymenter\Extensions\Servers\Domains\Services\StaffSettingsWrites($native,$sellers,$renewBindings));
$lateAction=setupAction('late_renewal',$server->id);
$page=\Livewire\Livewire::actingAs($actor)->test(\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class,['account'=>$server->id])->mountAction($lateAction)->assertActionMounted($lateAction);
$page->callMountedAction()->assertHasNoActionErrors();expect(EnomLateRenewal::accepted($b),'Account switch confirmation enables TEST late renewal');
$audit=StaffSettingsReview::historyRows($server);same([false,true],[$audit[0]['before']['late_renewal'],$audit[0]['after']['late_renewal']],'Audit records switch before and after');
// Retain the same admission object across an account switch, as a queue worker's factory does.
$coldAdmission=new \Paymenter\Extensions\Servers\Domains\Services\RenewalAdmission($authority,$renewBindings,$coldAcceptance,$clock);
$lateRequest=new \Paymenter\Extensions\Servers\Domains\Data\RenewalRequest($d->id,$d->authority_generation,$d->confirmed_revision,$d->provider_expires_at->format('Y-m-d'),$d->provider_domain_id,manual:true);
Cache::flush();expect($coldAdmission->check($lateRequest)->allowed(),'Retained worker observes late renewal switched On');
$toggle(false);Cache::flush();expect(!$coldAdmission->check($lateRequest)->allowed(),'Retained worker observes late renewal switched Off');$toggle(true);
$foreign=new AccountBinding($b->registrarKey,$b->serverId,$b->environment,str_repeat('f',64));expect(!EnomLateRenewal::accepted($foreign),'Switch cannot authorize a foreign account fingerprint');
$live=new AccountBinding($b->registrarKey,$b->serverId,Environment::Live,$b->fingerprint);expect(!EnomLateRenewal::available($live),'Missing Live evidence refuses even with TEST switch on');
require __DIR__.'/Support/storefront.php';require __DIR__.'/../Domains/routes/owned.php';storefrontViews();
$preview=new \Paymenter\Extensions\Servers\Domains\Presentation\OwnedDomain($d->domain,'Expired','warning','2027-10-18','On','$10.00',['show'=>'/domains/1'],[],[],[],[],true,renewNow:['url'=>'/domains/1/renew','terms'=>[1],'late'=>true]);
$html=view('domains::partials.owner-renew',['domain'=>$preview,'support'=>'/support','keys'=>['renew'=>str_repeat('a',64)]])->render();
expect(str_contains($html,'Late renewal')&&str_contains($html,EnomLateRenewal::HELP),'Customer sees the shared Late renewal helper');expect(!str_contains($html,'$10.00/yr'),'Late quote does not promise regular price');
$state->price='12.00';
foreach([['deleteDay',''],['deleteDay','0'],['deleteDay','unknown'],['reactivate','False'],['lateTld','net'],['expiry','10/18/2027'],['expired','RGP']] as [$key,$bad]){
    $saved=$state->$key??null;$state->$key=$bad;Cache::flush();commerceReject(fn()=>$lateBilling->invoice($d->id,$user,1));
    if($saved===null){unset($state->$key);}else{$state->$key=$saved;}
    same(1,Operation::where('domain_id',$d->id)->where('action','renew')->count(),'Missing or conflicting fresh lifecycle creates no invoice');
}
// A refused scheduled quote retries after fifteen minutes; explicit owner requests remain available.
$server->settings()->updateOrCreate(['key'=>'domains_billing_enabled'],['value'=>true,'type'=>'boolean']);
Cache::flush();$state->expired='RGP';$state->calls=[];
commerceReject(fn()=>$lateBilling->invoice($d->id));$readCount=count($state->calls);expect($readCount>0,'First scheduled late quote reads the registrar');
Cache::forget('domains-write-slot:'.$b->fingerprint); // Simulate expiry of the independent short account slot.
commerceReject(fn()=>$lateBilling->invoice($d->id));same($readCount,count($state->calls),'Refused scheduled quote does not repeat every tick');
commerceReject(fn()=>$lateBilling->invoice($d->id,$user,1));expect(count($state->calls)>$readCount,'Explicit owner request bypasses the scheduled retry marker');
Cache::forget('domains-write-slot:'.$b->fingerprint);$readCount=count($state->calls);$beforeRetry=$clock->instant;$clock->instant=$clock->instant->modify('+901 seconds');
commerceReject(fn()=>$lateBilling->invoice($d->id));expect(count($state->calls)>$readCount,'Scheduled quote retries when its marker expires');
$clock->instant=$beforeRetry;$state->expired='Expired';
$state->graceDays='1'; // Cancellation/refund grace must not set a post-expiry renewal deadline.
Cache::flush();$i=$lateBilling->invoice($d->id,$user,1);$op=Operation::where('invoice_id',$i->id)->sole();
same(120000,PricingRule::units($op->policy['renewal']['cost_cap']['amount']),'Fresh total provider cost becomes immutable ceiling');
same(['expiry'=>'2027-10-18T12:00:00+00:00','deadline'=>'2027-11-12T12:00:00+00:00'],$op->policy['renewal']['late'],'Precise authenticated expiry and fresh deletion bound are frozen');
$regular=$d->operations()->where('action','renew')->whereKeyNot($op->id)->firstOrFail();
same(PricingRule::units($regular->policy['price']['annual_net'])+40000,PricingRule::units($op->policy['price']['annual_net']),'Invoice preserves regular dollar margin over current registrar cost');
commerceReject(fn()=>$i->items()->firstOrFail()->update(['price'=>'1.00']));commerceReject(fn()=>$op->update(['policy'=>[]]));
$cycles->expireUnpaid($d->id);same('pending',$i->fresh()->status,'Late invoice stays open before its own deadline');
$state->price='99.00';$state->calls=[];same($i->id,$lateBilling->invoice($d->id,$user,1)->id,'Repeated request returns frozen debt');same([],$state->calls,'Frozen invoice is not requoted');
\App\Helpers\ExtensionHelper::addPayment($i,null,$op->amount);Cache::flush();[$engine,$writer]=$compose($b);$before=$state->writes;
same('held',$engine->run($op->id,$writer)->state,'Cost over ceiling holds paid renewal');same('renewal_cost_overrun',$op->fresh()->safe_code,'Cost hold explains why');same($before,$state->writes,'Cost increase sends nothing');
// One lost grace write stays uncertain and never replays.
[$lost,$lb]=$holder('lost');$state->price='12.00';Cache::flush();$li=$lateBilling->invoice($lost->id,$user,1);$lo=Operation::where('invoice_id',$li->id)->sole();
\App\Helpers\ExtensionHelper::addPayment($li,null,$lo->amount);$state->lost=true;Cache::flush();[$le,$lw]=$compose($lb);$before=$state->writes;
same('uncertain',$le->run($lo->id,$lw)->state,'Lost grace response retains uncertainty');same('UpdateExpiredDomains',$state->lastWrite[0],'Existing grace writer used');$le->run($lo->id,$lw);same($before+1,$state->writes,'Uncertain overdue renewal is sent once');
// A same-day timestamp change cannot reuse the invoice's precise expiry.
[$drift,$db]=$holder('expiry');Cache::flush();$di=$lateBilling->invoice($drift->id,$user,1);$do=Operation::where('invoice_id',$di->id)->sole();
\App\Helpers\ExtensionHelper::addPayment($di,null,$do->amount);$state->expiry='10/18/2027 6:00:00 AM';Cache::flush();[$de,$dw]=$compose($db);$before=$state->writes;same('held',$de->run($do->id,$dw)->state,'Precise expiry drift holds the paid invoice');same($before,$state->writes,'Same calendar day does not authorize a changed expiry');
// Consent and one-attempt collection use the existing native gateway route.
class LateRenewalGateway {
    public static int $calls=0;
    public function __construct(array $settings){}
    public function charge(Invoice $invoice,$amount,BillingAgreement $agreement):bool {
        same(0,\Illuminate\Support\Facades\DB::transactionLevel(),'Collection is outside locks');self::$calls++;return false;
    }
}
class_alias(LateRenewalGateway::class,'Paymenter\\Extensions\\Gateways\\LateDouble\\LateDouble');
$gateway=Gateway::create(['name'=>'Synthetic late card','extension'=>'LateDouble','type'=>'gateway','enabled'=>true]);
$agreement=BillingAgreement::create(['user_id'=>$user->id,'gateway_id'=>$gateway->id,'name'=>'Synthetic card','external_reference'=>'synthetic-only']);
$server->settings()->updateOrCreate(['key'=>'domains_billing_enabled'],['value'=>true,'type'=>'boolean']);
[$auto,$ab]=$holder('auto');$auto->service->update(['billing_agreement_id'=>$agreement->id]);$state->price='12.00';Cache::flush();
$ai=$lateBilling->invoice($auto->id);$ao=Operation::where('invoice_id',$ai->id)->sole();same(1,LateRenewalGateway::$calls,'Scheduled late invoice tries saved method once');same('declined',$ao->funding['auto_pay_result']['status'],'Definite decline retained');
$lateBilling->invoice($auto->id);same(1,LateRenewalGateway::$calls,'Scheduler rerun never retries a decline');same(null,(new RenewalAutoPay($clock))->renewalAutoPayContext($ai->id),'Overdue invoice grants no gateway retry context');
$clock->instant=new DateTimeImmutable($ao->policy['renewal']['late']['deadline']);$cycles->expireUnpaid($auto->id);same('cancelled',$ai->fresh()->status,'Untouched declined invoice closes at precise deadline');
commerceReject(fn()=>$lateBilling->invoice($auto->id));same(1,LateRenewalGateway::$calls,'Closed late cycle cannot produce a second debit');
[$off,$ob]=$holder('consent');$off->service->update(['billing_agreement_id'=>$agreement->id]);$off->update(['renewal_policy'=>[...$off->renewal_policy,'owner_auto_renew'=>false]]);Cache::flush();$state->calls=[];same(null,$lateBilling->invoice($off->id),'Owner Off stops scheduled late billing');same([],$state->calls,'Owner Off sends no fresh reads');same(1,LateRenewalGateway::$calls,'Owner Off never debits');$oi=$lateBilling->invoice($off->id,$user,1);$oo=Operation::where('invoice_id',$oi->id)->sole();
\App\Helpers\ExtensionHelper::addPayment($oi,null,$oo->amount);$clock->instant=new DateTimeImmutable($oo->policy['renewal']['late']['deadline']);Cache::flush();[$oe,$ow]=$compose($ob);$before=$state->writes;$oe->run($oo->id,$ow);same($before,$state->writes,'No deadline-late provider write');
// Expiry cannot turn an earlier ordinary Auto Pay decline into a new debit attempt.
config($checkout);[$ordinary,$prior,$pb]=$makeRenewal(placeholderComFixtureName('late-prior-decline-placeholder.com'),false);$cycles->retireUnpaid($ordinary->id);
$prior->service->update(['billing_agreement_id'=>$agreement->id]);$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');$before=LateRenewalGateway::$calls;$pi=$lateBilling->invoice($prior->id);same($before+1,LateRenewalGateway::$calls,'Ordinary cycle makes its one saved-method attempt');
$clock->instant=new DateTimeImmutable('2027-10-19T12:00:00Z');$cycles->expireUnpaid($prior->id);same('cancelled',$pi->fresh()->status,'Definite ordinary decline closes at expiry');config($on);Cache::flush();$state->calls=[];
commerceReject(fn()=>$lateBilling->invoice($prior->id));same([],$state->calls,'Prior debit stops scheduled late replacement before reads');same($before+1,LateRenewalGateway::$calls,'Late renewal cannot retry an ordinary decline');
$lateBilling->invoice($prior->id,$user,1);same($before+1,LateRenewalGateway::$calls,'Owner can request a late invoice without another automatic debit');
$toggle(false);expect(!EnomLateRenewal::accepted($b),'Account switch can be turned Off');
// Review reproductions: switch pause/resume and the exact frozen funds deadline.
require __DIR__.'/Support/reminder-mail.php';
$cash=static fn()=>PricingRule::minor((string)(\App\Models\Credit::where('user_id',$user->id)->where('currency_code','USD')->value('amount')??'0.00'));
$refunds=new \Paymenter\Extensions\Servers\Domains\Services\Refunds($clock);
$account=\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$server->id)->sole();
$observed=[];$expected=[];$toggle(true);
[$paused,$pbind]=$holder('review-switch-pause');Cache::flush();$pinv=$lateBilling->invoice($paused->id,$user,1);$pop=Operation::where('invoice_id',$pinv->id)->sole();
\App\Helpers\ExtensionHelper::addPayment($pinv,null,$pop->amount);$toggle(false);[$pe,$pw]=$compose($pbind);$before=$state->writes;
$pe->run($pop->id,$pw);$observed['off']=[$pop->fresh()->state,$pop->fresh()->safe_code,$state->writes-$before];$expected['off']=['queued','late_renewal_paused',0];
$toggle(true);Cache::flush();$state->calls=[];$pe->run($pop->id,$pw);
$observed['resumed']=[$pop->fresh()->state,$state->writes-$before,count($state->calls)>0];$expected['resumed']=['pending',1,true];
foreach(['on','off','unknown'] as $case){
    // Settle older due fixtures before measuring this receipt's once-only return.
    $clock->instant=new DateTimeImmutable('2028-01-01T00:00:00Z');$refunds->closeRefused($account);$toggle(true);
    [$dead,$dbind]=$holder('review-deadline-'.$case);Cache::flush();$dinv=$lateBilling->invoice($dead->id,$user,1);$dop=Operation::where('invoice_id',$dinv->id)->sole();
    \App\Helpers\ExtensionHelper::addPayment($dinv,null,$dop->amount);[$de,$dw]=$compose($dbind);
    if($case==='off'){$toggle(false);$de->run($dop->id,$dw);}
    if($case==='unknown'){$dop->update(['attempt'=>['seal'=>'sealed','send_state'=>'possibly_sent']]);}
    $clock->instant=new DateTimeImmutable($dop->policy['renewal']['late']['deadline']);$before=$state->writes;$cashBefore=$cash();$de->run($dop->id,$dw);
    $cycles->expireUnpaid($dead->id);$refunds->closeRefused($account);$refunds->retryPending();
    $observed[$case]=[$dop->fresh()->state,$dop->fresh()->safe_code,$cash()-$cashBefore,$state->writes-$before];
    $expected[$case]=$case==='unknown'?['awaiting_payment',null,0,0]:['rejected','renewal_funds_expired',PricingRule::minor($dop->amount),0];
    if($case==='unknown'){continue;}
    $refunds->closeRefused($account);$refunds->creditRejected($dop->id);$de->run($dop->id,$dw);
    $observed[$case.'-replay']=[$cash()-$cashBefore,$state->writes-$before,$dinv->fresh()->status];$expected[$case.'-replay']=[PricingRule::minor($dop->amount),0,'cancelled'];
    if($dop->fresh()->state==='rejected'){
        \Paymenter\Extensions\Servers\Domains\Services\ReminderSchedules::enroll($dop->server_id);
        $notices=new \Paymenter\Extensions\Servers\Domains\Services\Reminders($clock,$renewBindings);
        expect($notices->remind($dead->id),'Deadline closure sends the existing return notice');same(false,$notices->remind($dead->id),'Deadline return notice sends once');
        same('domains_domain_order_refunded',Mail::queued(\Paymenter\Extensions\Servers\Domains\Mail\DomainNotice::class)->last()->emailTemplate->key,'Deadline closure uses the return notice');
    }
}
if($expected!==$observed){fwrite(STDERR,json_encode(['expected'=>$expected,'observed'=>$observed])."\n");}
same($expected,$observed,'Switch resumes paid work; frozen cutoff returns only definitely-unsent funds once');
$toggle(false);

// Independent regression: native partial credit must stop being collected and return at the frozen cutoff.
$clock->instant=new DateTimeImmutable('2028-01-01T00:00:00Z');$refunds->closeRefused($account);$refunds->retryPending();$toggle(true);
[$partialDomain,$partialBind]=$holder('recheck-native-partial-credit');Cache::flush();
$partialInvoice=$lateBilling->invoice($partialDomain->id,$user,1);$partialOp=Operation::where('invoice_id',$partialInvoice->id)->sole();
\App\Models\Credit::updateOrCreate(['user_id'=>$user->id,'currency_code'=>'USD'],['amount'=>'1.00']);
Livewire\Livewire::actingAs($user)->test(App\Livewire\Invoices\Show::class,['invoice'=>$partialInvoice->fresh()])->set('selectedMethod','credit')->call('processPayment');
same(['pending',100,0],[$partialInvoice->fresh()->status,PricingRule::minor($partialInvoice->transactions()->sole()->amount),$cash()],'Native customer flow spends the available one-dollar credit on this late invoice');
$clock->instant=new DateTimeImmutable($partialOp->policy['renewal']['late']['deadline']);$sent=$state->writes;
[$partialEngine,$partialWriter]=$compose($partialBind);$partialEngine->run($partialOp->id,$partialWriter);
$cycles->expireUnpaid($partialDomain->id);$refunds->closeRefused($account);$refunds->retryPending();
$actual=[$partialInvoice->fresh()->status,$partialOp->fresh()->state,$cash(),$state->writes-$sent];
same(['cancelled','rejected',100,0],$actual,'Frozen cutoff must cancel collection and return a definitely-unsent native partial payment');
$refunds->closeRefused($account);$refunds->creditRejected($partialOp->id);$partialEngine->run($partialOp->id,$partialWriter);
same([100,0,'1.00'],[$cash(),$state->writes-$sent,$partialOp->fresh()->funding['late_credits'][0]['amount']],'Repeated closure returns only the settled dollar once, never the unpaid balance');
\Paymenter\Extensions\Servers\Domains\Services\ReminderSchedules::enroll($partialOp->server_id);
$notices=new \Paymenter\Extensions\Servers\Domains\Services\Reminders($clock,$renewBindings);
expect($notices->remind($partialDomain->id),'Partial return sends the existing closed-renewal notice');same(false,$notices->remind($partialDomain->id),'Partial return notice sends once');
same('domains_domain_order_refunded',Mail::queued(\Paymenter\Extensions\Servers\Domains\Mail\DomainNotice::class)->last()->emailTemplate->key,'Partial payment gets the return notice');

// A partial receipt still processing has no settled amount to return at the cutoff.
[$processingDomain,$processingBind]=$holder('recheck-partial-processing');Cache::flush();
$processingInvoice=$lateBilling->invoice($processingDomain->id,$user,1);$processingOp=Operation::where('invoice_id',$processingInvoice->id)->sole();
\App\Helpers\ExtensionHelper::addProcessingPayment($processingInvoice,null,'1.00','0.00','partial-processing-at-cutoff');
$clock->instant=new DateTimeImmutable($processingOp->policy['renewal']['late']['deadline']);$sent=$state->writes;
[$processingEngine,$processingWriter]=$compose($processingBind);$processingEngine->run($processingOp->id,$processingWriter);
$cycles->expireUnpaid($processingDomain->id);$refunds->closeRefused($account);$refunds->retryPending();
same(['pending','awaiting_payment','processing',100,0],[$processingInvoice->fresh()->status,$processingOp->fresh()->state,$processingInvoice->transactions()->sole()->status->value,$cash(),$state->writes-$sent],'An unsettled partial receipt stays held without a return or registrar send');
expect(\Illuminate\Support\Facades\DB::table('domains_obligation_alerts')->where('operation_id',$processingOp->id)->where('code','funds_wait_review')->whereNull('resolved_at')->exists(),'Unsettled partial payment uses the existing funds deadline review');

echo "Late renewal account pause, exact deadline return, original admission and one debit passed\n";$nativeSuccessful=true;
