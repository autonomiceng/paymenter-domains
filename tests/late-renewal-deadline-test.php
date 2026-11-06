<?php
// Native late-renewal frozen deadlines and partial receipts; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/scenario-inventory.php';
$scenarios=new NativeScenarioInventory(['switch:off','switch:resumed','deadline:on','deadline:off','deadline:unknown','replay:on','replay:off','partial:paid','partial:replay','partial:processing']);
require __DIR__.'/Support/late-renewal.php';
use App\Models\{BillingAgreement,Gateway,Invoice};
use Illuminate\Support\Facades\{Cache,Mail};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling,EnomLateRenewal,EnomTestRenewal,EnomTestSendLimitSchema,PricingRule,RenewalAutoPay,StaffSettingsReview};
// Review reproductions: switch pause/resume and the exact frozen funds deadline.
require __DIR__.'/Support/reminder-mail.php';
$cash=static fn()=>PricingRule::minor((string)(\App\Models\Credit::where('user_id',$user->id)->where('currency_code','USD')->value('amount')??'0.00'));
$refunds=new \Paymenter\Extensions\Servers\Domains\Services\Refunds($clock);
$account=\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$server->id)->sole();
$observed=[];$expected=[];
[$paused,$pbind]=$holder('review-switch-pause');$toggle(true);Cache::flush();$pinv=$lateBilling->invoice($paused->id,$user,1);$pop=Operation::where('invoice_id',$pinv->id)->sole();
\App\Helpers\ExtensionHelper::addPayment($pinv,null,$pop->amount);$toggle(false);[$pe,$pw]=$compose($pbind);$before=$state->writes;
$pe->run($pop->id,$pw);$observed['switch-off']=[$pop->fresh()->state,$pop->fresh()->safe_code,$state->writes-$before];$expected['switch-off']=['queued','late_renewal_paused',0];$scenarios->completed('switch:off');
$toggle(true);Cache::flush();$state->calls=[];$pe->run($pop->id,$pw);
$observed['resumed']=[$pop->fresh()->state,$state->writes-$before,count($state->calls)>0];$expected['resumed']=['pending',1,true];$scenarios->completed('switch:resumed');
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
    $scenarios->completed('deadline:'.$case);
    if($case==='unknown'){continue;}
    $refunds->closeRefused($account);$refunds->creditRejected($dop->id);$de->run($dop->id,$dw);
    $observed[$case.'-replay']=[$cash()-$cashBefore,$state->writes-$before,$dinv->fresh()->status];$expected[$case.'-replay']=[PricingRule::minor($dop->amount),0,'cancelled'];$scenarios->completed('replay:'.$case);
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
same(['cancelled','rejected',100,0],$actual,'Frozen cutoff must cancel collection and return a definitely-unsent native partial payment');$scenarios->completed('partial:paid');
$refunds->closeRefused($account);$refunds->creditRejected($partialOp->id);$partialEngine->run($partialOp->id,$partialWriter);
same([100,0,'1.00'],[$cash(),$state->writes-$sent,$partialOp->fresh()->funding['late_credits'][0]['amount']],'Repeated closure returns only the settled dollar once, never the unpaid balance');
\Paymenter\Extensions\Servers\Domains\Services\ReminderSchedules::enroll($partialOp->server_id);
$notices=new \Paymenter\Extensions\Servers\Domains\Services\Reminders($clock,$renewBindings);
expect($notices->remind($partialDomain->id),'Partial return sends the existing closed-renewal notice');same(false,$notices->remind($partialDomain->id),'Partial return notice sends once');
same('domains_domain_order_refunded',Mail::queued(\Paymenter\Extensions\Servers\Domains\Mail\DomainNotice::class)->last()->emailTemplate->key,'Partial payment gets the return notice');$scenarios->completed('partial:replay');

// A partial receipt still processing has no settled amount to return at the cutoff.
[$processingDomain,$processingBind]=$holder('recheck-partial-processing');Cache::flush();
$processingInvoice=$lateBilling->invoice($processingDomain->id,$user,1);$processingOp=Operation::where('invoice_id',$processingInvoice->id)->sole();
\App\Helpers\ExtensionHelper::addProcessingPayment($processingInvoice,null,'1.00','0.00','partial-processing-at-cutoff');
$clock->instant=new DateTimeImmutable($processingOp->policy['renewal']['late']['deadline']);$sent=$state->writes;
[$processingEngine,$processingWriter]=$compose($processingBind);$processingEngine->run($processingOp->id,$processingWriter);
$cycles->expireUnpaid($processingDomain->id);$refunds->closeRefused($account);$refunds->retryPending();
same(['pending','awaiting_payment','processing',100,0],[$processingInvoice->fresh()->status,$processingOp->fresh()->state,$processingInvoice->transactions()->sole()->status->value,$cash(),$state->writes-$sent],'An unsettled partial receipt stays held without a return or registrar send');
expect(\Illuminate\Support\Facades\DB::table('domains_obligation_alerts')->where('operation_id',$processingOp->id)->where('code','funds_wait_review')->whereNull('resolved_at')->exists(),'Unsettled partial payment uses the existing funds deadline review');$scenarios->completed('partial:processing');

$scenarios->finish();
echo "Late renewal account pause, exact deadline return, original admission and one debit passed\n";$nativeSuccessful=true;
