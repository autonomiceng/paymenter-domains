<?php
// Enom d1392a8 late retired and unsuitable paid renewal refunds; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use App\Models\{Credit, InvoiceTransaction, Role};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{PricingRule, Refunds, RefundPayment, RenewalCycles, RenewalFunding, HoldingLocks, FinancialAdjustments};
$refunds=new Refunds($clock); $staff=commerceUser();
$role=Role::create(['name'=>'Renewal refunds','permissions'=>['admin.domains.manage','admin.invoices.update','admin.invoice_transactions.create']]); $staff->update(['role_id'=>$role->id]);
$credit=static fn()=>PricingRule::minor((string)(Credit::where('user_id',$user->id)->where('currency_code','USD')->value('amount')??'0.00'));
// Corrections do not constitute an attempt: restored signed funding may execute once with its audit retained.
[$corrected,$d,$b]=$makeRenewal('renew-corrected-funding.com'); $corrections=new FinancialAdjustments($clock);
$corrections->append($staff,$corrected->id,'renew_reverse','-1.00','payment_correction');
commerceReject(fn()=>DB::transaction(fn()=>RenewalFunding::requireCurrent($corrected->fresh(),HoldingLocks::take($d))));
$corrections->append($staff,$corrected->id,'renew_restore','1.00','payment_correction');
$history=$corrected->fresh()->funding['financial_adjustments']; $raw=$corrected->fresh()->getRawOriginal();
foreach(['null_history','unknown_fact','foreign_transaction'] as $case){
    $bad=['financial_adjustments'=>$history];
    if($case==='null_history'){$bad['financial_adjustments']=null;}
    elseif($case==='unknown_fact'){$bad['claim']=null;}
    else{$bad['financial_adjustments'][0]['transaction_id']=999999;}
    Operation::withoutEvents(fn()=>$corrected->fresh()->update(['funding'=>$bad]));
    if($case==='foreign_transaction'){commerceReject(fn()=>DB::transaction(fn()=>RenewalFunding::requireCurrent($corrected->fresh(),HoldingLocks::take($d))));}
    else{same(false,RenewalCycles::virgin($corrected->fresh()),'Unknown funding history cannot admit');}
    DB::table('domains_operations')->where('id',$corrected->id)->update($raw);
}
[$engine,$writer,$completion]=$compose($b); $writes=$state->writes;
same('pending',$engine->run($corrected->id,$writer)->state,'Restored renewal executes');
same($writes+1,$state->writes,'Corrected funding permits exactly one send'); same($history,$corrected->fresh()->funding['financial_adjustments'],'Seal preserves correction audit');
$engine->run($corrected->id,$writer); same($writes+1,$state->writes,'Corrected renewal never replays');
$state->expiry='2028-10-18'; $state->charge='8.00'; same('succeeded',$completion->run($corrected->id)->state,'Corrected renewal completes');
same($history,$corrected->fresh()->funding['financial_adjustments'],'Completion retains audit');
// Unsuitable fixed multiyear grace, with full native payment: refund retains holding and expiry.
[$op,$d,$b]=$makeRenewal('refund-grace-term.com',true,3); [$engine,$writer]=$compose($b);
$clock->instant=new DateTimeImmutable('2027-10-18T12:00:01Z'); $beforeWrites=$state->writes;
same('held',$engine->run($op->id,$writer)->state,'Unsuitable paid term held'); same($beforeWrites,$state->writes,'No provider write');
$expiry=$d->provider_expires_at->format('Y-m-d');$before=$credit();
$refunds->refundHeld($staff,$op->id,'unsuitable_term');
same($before+PricingRule::minor($op->amount),$credit(),'Unsuitable fixed obligation full credit');
same('active',$d->service->fresh()->status,'Renewal refund retains active holding'); same($expiry,$d->fresh()->provider_expires_at->format('Y-m-d'),'Refund never changes expiry');
commerceReject(fn()=>DB::transaction(fn()=>RenewalCycles::next(HoldingLocks::take($d)))); // Safe replacement of a refunded unsuitable cycle remains35/36.
// Slice25 sealed typed definitely-unsent prewire evidence is independently interpreted.
[$op,$d,$b,$bill]=$makeRenewal('refund-typed-prewire.com'); [$engine,$writer]=$compose($b); $beforeWrites=$state->writes;
Operation::updated(static function($row)use($op,$d){
    if($row->id===$op->id && $row->state==='running'){ DB::table('services')->where('id',$d->service_id)->update(['status'=>'cancelled']); }
});
same('held',$engine->run($op->id,$writer)->state,'Real local prewire refusal'); same($beforeWrites,$state->writes,'Typed refusal before exchange');
same('definitely_unsent',$op->fresh()->attempt['execution']['send_state'],'Retained typed summary');
$attempt=$op->fresh()->attempt; $original=$op->fresh()->getRawOriginal();
foreach(['execution_null','fallback','possible_send','extra_null_fact'] as $case){
    $bad=$attempt;
    if($case==='execution_null'){$bad['execution']=null;}
    elseif($case==='fallback'){$bad['execution']['reason']='fallback';}
    elseif($case==='possible_send'){$bad['execution']['send_state']='possibly_sent';}
    else{$bad['provider_reference']=null;}
    // Privileged fixture corruption probes independent interpretation of retained typed evidence.
    Operation::withoutEvents(fn()=>$op->fresh()->update(['attempt'=>$bad]));
    commerceReject(fn()=>$refunds->refundHeld($staff,$op->id,'service_cancelled'));
    DB::table('domains_operations')->where('id',$op->id)->update($original);
}
$before=$credit(); $refunds->refundHeld($staff,$op->id,'service_cancelled');
same($before+PricingRule::minor($op->amount),$credit(),'Proven prewire refusal credited'); same($attempt,$op->fresh()->attempt,'Sealed audit untouched');
// Retired cancelled debt is never dispatched on late payment. Credit once to resolve financial conflict.
[$retired,$d,$b,$bill]=$makeRenewal('refund-late-retired.com',false); $cycles->retireUnpaid($retired->id);
commerceReject(fn()=>(new FinancialAdjustments($clock))->append($staff,$retired->id,'mint_retired',$retired->amount,'payment_correction'));
same('cancelled',$bill->fresh()->status,'Correction cannot fabricate a late retired payment');
same(0,InvoiceTransaction::where('invoice_id',$bill->id)->count(),'Retired debt retains no fabricated transaction');
$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');
$replacementBill=$billing->invoice($d->id,$user); $replacement=Operation::where('invoice_id',$replacementBill->id)->sole();
App\Helpers\ExtensionHelper::addPayment($replacementBill,null,$replacement->amount);
InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>$retired->amount,'fee'=>'0.00','status'=>'succeeded']);
same('paid',$bill->fresh()->status,'Native late-payment listener retains payment and marks retired invoice paid');
commerceReject(fn()=>DB::transaction(function()use($d,$replacement){$l=HoldingLocks::take($d);RenewalFunding::requireCurrent($replacement->fresh(),$l);}));
$before=$credit(); $oldAttempt=$retired->fresh()->attempt; $paidStatus=$bill->fresh()->status;
same(['amount'=>$retired->amount,'currency'=>'USD'],$refunds->creditRejected($retired->id),'Late retired payment credited');
same($before+PricingRule::minor($retired->amount),$credit(),'Late payment refund exact'); same(null,$refunds->creditRejected($retired->id),'Late refund once');
same($oldAttempt,$retired->fresh()->attempt,'Original terminal unpaid proof unchanged'); same($paidStatus,$bill->fresh()->status,'Refund does not rewrite native paid status');
DB::transaction(function()use($d,$replacement,$retired){
    $l=HoldingLocks::take($d); expect(RefundPayment::retiredSettled($retired->fresh(),$l),'Exact refunded retired debt is resolved');
    same('paid_invoice',RenewalFunding::requireCurrent($replacement->fresh(),$l)['basis'],'Replacement rechecks its own funding after old debt credited');
    $cycle=$l; $cycle['operations']=$l['operations']->reject(fn($row)=>$row->id===$replacement->id);
    same(RenewalCycles::key($d).':replacement:1',RenewalCycles::next($cycle),'Settled old debt permits replacement key');
});
InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>'1.00','fee'=>'0.00','status'=>'processing']);
commerceReject(fn()=>DB::transaction(function()use($d,$replacement){$l=HoldingLocks::take($d);RenewalFunding::requireCurrent($replacement->fresh(),$l);}));
same(null,$refunds->creditRejected($retired->id),'Later financial activity cannot create a second credit');
// A retired invoice with only a partial/failed payment cannot borrow the replacement payment.
[$partial,$d,$b,$bill]=$makeRenewal('refund-retired-partial.com',false); $cycles->retireUnpaid($partial->id);
InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>'1.00','fee'=>'0.00','status'=>'succeeded']);
commerceReject(fn()=>$refunds->creditRejected($partial->id));
// Actual sends and unknown replies cannot be refunded, even for cancelled or stale paid obligations.
[$sent,$d,$b]=$makeRenewal('refund-renew-sent.com'); [$engine,$writer]=$compose($b); $state->lost=true;
same('uncertain',$engine->run($sent->id,$writer)->state,'Lost renewal reply remains uncertain');
$sent->refresh()->update(['state'=>'held','safe_code'=>'renewal_definitely_unsent']);
commerceReject(fn()=>$refunds->refundHeld($staff,$sent->id,'service_cancelled'));
echo "Unsuitable renewals, independent typed prewire proof and late retired payment credit passed\n"; $nativeSuccessful=true;
