<?php
// Enom d1392a8 stale/overrun/ambiguous renewal scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
$renewalFixtureNames=['funds-end-topup','funds-end-return','funds-end-unknown'];
require __DIR__.'/Support/renewal-batch-fixture.php';
use App\Models\{InvoiceTransaction, ServiceCancellation};
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Data\{Action, AdmittedOperation, DateEvidence, ExpiryPrecision, Money, Query, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
\Paymenter\Extensions\Servers\Domains\Services\ReminderSchema::install();
$invoicePresenter=new \Paymenter\Extensions\Servers\Domains\Services\NativePresenter(new \Paymenter\Extensions\Servers\Domains\Services\OwnedDomains($clock,new \Paymenter\Extensions\Servers\Domains\Services\OwnerPorts),$native,new \Paymenter\Extensions\Servers\Domains\Services\HolderPricing($prices));
$admissionReads = [ReadCommand::Info, ReadCommand::RenewalInfo, ReadCommand::Check, ReadCommand::Info, ReadCommand::Renewal, ReadCommand::Balance];
$confirmationReads = [ReadCommand::Info, ReadCommand::Renewal, ReadCommand::Info, ReadCommand::Expiry, ReadCommand::Order];
// Funds can arrive days after the first hold, up to the existing provider-safe boundary.
require __DIR__.'/Support/reminder-mail.php';
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
foreach(['topup','return','unknown'] as $case){
    [$op,$d,$b,$invoice]=$makeRenewal(placeholderComFixtureName('funds-end-'.$case.'-placeholder.com'));[$engine,$writer,$completion]=$compose($b,true,false);
    $account=\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$op->server_id)->sole();
    $clock->instant=new DateTimeImmutable('2027-10-12T12:00:00Z');$state->balance='0.00';$writes=$state->writes;
    same('queued',$engine->run($op->id,$writer)->state,'Paid funds-short renewal waits without sending');
    $until=new DateTimeImmutable($op->fresh()->policy['funds_wait_until']);same('2027-10-18T12:00:00+00:00',$until->format(DATE_ATOM),'Funds wait retains the exact existing ordinary expiry');
    $clock->instant=$clock->now()->modify('+1 day');Cache::flush();same('queued',$engine->run($op->id,$writer)->state,'Funds-short renewal retries on the same obligation');
    $clock->instant=new DateTimeImmutable('2027-10-16T12:00:00Z');Cache::flush(); // Native cache TTL uses wall time; these jumps stand for separate daily ticks.
    \Paymenter\Extensions\Servers\Domains\Services\FundsWaits::sweep($account,$clock->now());same('queued',$op->fresh()->state,'No three-day renewal cutoff');
    if($case==='topup'){
        $state->balance='100.00';$topped=$engine->run($op->id,$writer);same('pending',$topped->state,'Top-up two days before expiry still renews: '.($topped->safe_code??'none'));
        $state->expiry='2028-10-18';$state->charge='8.00';$state->billDate='10/16/2027 5:00:01 AM';same('succeeded',$completion->run($op->id)->state,'Topped-up renewal finishes');
        same($writes+1,$state->writes,'Top-up sends once');continue;
    }
    $clock->instant=$until->modify('-1 second');\Paymenter\Extensions\Servers\Domains\Services\FundsWaits::sweep($account,$clock->now());same('queued',$op->fresh()->state,'Wait remains open immediately before the boundary');
    if($case==='unknown'){$op->refresh()->update(['attempt'=>['seal'=>'sealed','send_state'=>'possibly_sent']]);}
    $credit=fn()=>\Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor((string)(App\Models\Credit::where('user_id',$d->user_id)->where('currency_code','USD')->value('amount')??'0.00'));$before=$credit();
    $clock->instant=$until;$reads=count($state->calls);$engine->run($op->id,$writer);same($reads,count($state->calls),'Elapsed funds wait cannot start another admission');
    \Paymenter\Extensions\Servers\Domains\Services\FundsWaits::sweep($account,$clock->now());
    if($case==='unknown'){
        same('queued',$op->fresh()->state,'Possible send remains unresolved');same($before,$credit(),'Uncertain attempt receives no return');
        expect(DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','funds_wait_review')->whereNull('resolved_at')->exists(),'Uncertain elapsed renewal retains review');continue;
    }
    same(['rejected','renewal_funds_expired'],[$op->fresh()->state,$op->fresh()->safe_code],'Definitely-unsent paid renewal closes at the boundary');
    same('cancelled',$invoice->fresh()->status,'Closed cycle invoice fences further collection');
    same('renewal_funds_expired',$invoicePresenter->invoiceOutcome($user,(int)$invoice->id)->kind,'Funds-expired closure is distinct from unpaid cancellation');
    same($before+\Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor($op->amount),$credit(),'Unsupported gateway returns the payment once as bridge credit');
    \Paymenter\Extensions\Servers\Domains\Services\FundsWaits::sweep($account,$clock->now());app(\Paymenter\Extensions\Servers\Domains\Services\Refunds::class)->creditRejected($op->id);$engine->run($op->id,$writer);
    same($before+\Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor($op->amount),$credit(),'Repeated sweeps and return retries cannot duplicate money');same($writes,$state->writes,'Closed renewal never sends');
    commerceReject(fn()=>$billing->invoice($d->id,$d->service->user,1));
    commerceReject(fn()=>DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Services\RenewalCycles::next(\Paymenter\Extensions\Servers\Domains\Services\HoldingLocks::take($d))));
    \Paymenter\Extensions\Servers\Domains\Services\ReminderSchedules::enroll($op->server_id);$notices=new \Paymenter\Extensions\Servers\Domains\Services\Reminders($clock,$renewBindings);
    expect($notices->remind($d->id),'Customer receives the actual payment-return notice');same(false,$notices->remind($d->id),'Return notice keeps its one delivery identity');
    same('domains_domain_order_refunded',Illuminate\Support\Facades\Mail::queued(\Paymenter\Extensions\Servers\Domains\Mail\DomainNotice::class)->last()->emailTemplate->key,'Boundary uses the existing return notice');
    // A stale payment form can settle this cancelled invoice; reuse incremental returns after the first refund.
    $refunds=app(\Paymenter\Extensions\Servers\Domains\Services\Refunds::class);$before=$credit();
    InvoiceTransaction::create(['invoice_id'=>$invoice->id,'amount'=>'0.50','fee'=>'0.00','status'=>'succeeded']);
    same('paid',$invoice->fresh()->status,'Native late settlement can mark the closed renewal invoice paid');
    same(1,$refunds->retryPending(),'Ordinary refund sweep returns late money on the funds-closed renewal');
    same($before+50,$credit(),'Only the new unsupported payment reaches account credit');
    same(null,$refunds->creditRejected($op->id),'Late bridge return retries once');
    expect($notices->remind($d->id),'Late bridge return receives its own customer notice');
    same(false,$notices->remind($d->id),'Late notice preserves its original delivery identity');
    require __DIR__.'/Support/money-receipt.php';MoneyReceiptGateway::$lost=false;
    $gateway=\App\Models\Gateway::create(['extension'=>'MoneyReceipt','name'=>'Money receipt fixture','type'=>'gateway','enabled'=>true]);
    InvoiceTransaction::create(['invoice_id'=>$invoice->id,'amount'=>'0.75','fee'=>'0.00','status'=>'succeeded','gateway_id'=>$gateway->id,'transaction_id'=>'receipt_funds_closed']);
    same(null,$refunds->creditRejected($op->id),'Missing automatic gateway return needs review');
    expect(DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','refund_repair')->whereNull('resolved_at')->exists(),'Missing closed-invoice handoff has a durable repair alert');
    same(0,MoneyReceiptGateway::$sends,'Domains never allocates a second closed-invoice return');
    MoneyReceiptGateway::automatic($invoice,'receipt_funds_closed',75,'pending');
    same(null,$refunds->creditRejected($op->id),'Pending automatic return is unfinished');
    same($before+50,$credit(),'A pending gateway return cannot become bridge credit');
    MoneyReceiptGateway::complete($invoice,'receipt_funds_closed:excess');
    same(['amount'=>'0.75','currency'=>'USD'],$refunds->creditRejected($op->id),'Same gateway-owned return is recovered');
    same(null,$refunds->creditRejected($op->id),'Recovered gateway completion is observed once');
    same(1,MoneyReceiptGateway::$sends,'Only the gateway returned this late receipt');same($before+50,$credit(),'Gateway return cannot add a second account credit');
    expect(!DB::table('domains_obligation_alerts')->where('operation_id',$op->id)->where('code','refund_repair')->whereNull('resolved_at')->exists(),'Automatic recovery resolves the repair alert');

}
same([],array_keys($registrations),'Every independent renewal scenario ran');
echo "Renewal funds boundary and exact returns passed\n"; $nativeSuccessful=true;
