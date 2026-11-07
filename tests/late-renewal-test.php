<?php
// Native late-renewal admission and collection; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/late-renewal.php';
use App\Models\{BillingAgreement,Gateway,Invoice};
use Illuminate\Support\Facades\{Cache,Mail};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling,EnomLateRenewal,EnomTestRenewal,EnomTestSendLimitSchema,PricingRule,RenewalAutoPay,StaffSettingsReview};
// The native payment hook must not read or lock unrelated invoice rows (SQLite has no row locks).
$plainInvoice=new Invoice(['user_id'=>$user->id,'currency_code'=>'USD','status'=>'pending']);$plainInvoice->send_create_email=false;$plainInvoice->save();
\Illuminate\Support\Facades\DB::enableQueryLog();\Illuminate\Support\Facades\DB::flushQueryLog();
\App\Models\InvoiceTransaction::getEventDispatcher()->dispatch('eloquent.creating: '.\App\Models\InvoiceTransaction::class,new \App\Models\InvoiceTransaction(['invoice_id'=>$plainInvoice->id,'amount'=>'1.00','status'=>'succeeded']));
$plainQueries=\Illuminate\Support\Facades\DB::getQueryLog();\Illuminate\Support\Facades\DB::disableQueryLog();
same([],array_values(array_filter($plainQueries,static fn($q)=>preg_match('/\bfrom\s+["`]?invoices\b/i',$q['query']))),'A non-domain payment hook does not read or lock its invoice');
[$d,$b]=$holder('quote');
expect(!EnomLateRenewal::accepted($b),'Late renewal starts Off');$coldAcceptance=EnomTestRenewal::acceptance($b);$state->calls=[];
commerceReject(fn()=>$lateBilling->invoice($d->id,$user,1));same([],$state->calls,'Off refuses before provider reads');
$lateAction=setupAction('late_renewal',$server->id);
$page=\Livewire\Livewire::actingAs($actor)->test(\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class,['account'=>$server->id])->mountAction($lateAction)->assertActionMounted($lateAction);
$page->callMountedAction()->assertHasNoActionErrors();expect(EnomLateRenewal::accepted($b),'Account switch confirmation enables TEST late renewal');
$audit=collect(StaffSettingsReview::historyRows($server))->where('action',StaffSettingsReview::ACTIONS['late_renewal'])->sole();same([false,true],[$audit['before']['late_renewal'],$audit['after']['late_renewal']],'Audit records switch before and after');
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
echo "Late renewal admission and frozen quote passed\n";$nativeSuccessful=true;
