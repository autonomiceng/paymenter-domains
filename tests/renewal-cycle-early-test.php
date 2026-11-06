<?php
// Enom d1392a8 complimentary/cancelled-unpaid cycles; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
$cycleFixtureGroup='early';
require __DIR__.'/Support/renewal-cycle-fixture.php';
use App\Models\{InvoiceTransaction, Role};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\Money;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingLocks, PricingRule, RenewalFunding};
// An early renewal nobody pays closes after a week with nothing sent, and the scheduled invoice still comes.
[$early,$earlyDomain,,$earlyInvoice]=$earlyRenewal('renew-early-placeholder.com','2027-08-01T12:00:00Z');$sent=[$state->calls,$state->writes];
$clock->instant=$at('2027-08-08T11:59:00Z');$cycles->closeEarlyUnpaid($earlyDomain->id);same('pending',$earlyInvoice->fresh()->status,'The week is not up yet');
$clock->instant=$at('2027-08-08T12:00:00Z');$cycles->closeEarlyUnpaid($earlyDomain->id);$early=$early->fresh();
same(['cancelled','rejected','renewal_cancelled_unpaid','Early renewal not paid'],[$earlyInvoice->fresh()->status,$early->state,$early->safe_code,$early->policy['unpaid_retirement']['reason']],'Unpaid early renewal closes');
same($sent,[$state->calls,$state->writes],'Closing an early renewal sends nothing to the registrar');
$invoicePresenter=new \Paymenter\Extensions\Servers\Domains\Services\NativePresenter(new \Paymenter\Extensions\Servers\Domains\Services\OwnedDomains($clock,new \Paymenter\Extensions\Servers\Domains\Services\OwnerPorts),$native,new \Paymenter\Extensions\Servers\Domains\Services\HolderPricing($prices));
same('renewal_cancelled_unpaid',$invoicePresenter->invoiceOutcome($user,(int)$earlyInvoice->id)->kind,'Closed early renewal is available without a late-credit note');
same(null,\Paymenter\Extensions\Servers\Domains\Services\OwnedDomains::renewalCycle($earlyDomain->fresh(),true),'The customer sees no closed invoice and can renew again');
[$dropped,$droppedDomain,,$droppedInvoice]=$earlyRenewal('renew-dropped-placeholder.com','2027-08-09T12:00:00Z');$clock->instant=$at('2027-08-09T12:00:00Z');
$droppedInvoice->update(['status'=>'cancelled']);$cycles->closeEarlyUnpaid($droppedDomain->id);
same(['rejected','renewal_cancelled_unpaid'],[$dropped->fresh()->state,$dropped->fresh()->safe_code],'An early invoice staff cancel closes its renewal at once');
$clock->instant=$at('2027-08-10T12:00:00Z');$again=$billing->invoice($earlyDomain->id,$user);$againOp=Operation::where('invoice_id',$again->id)->sole();
expect(str_ends_with($againOp->operation_key,':replacement:1'),'Renew now after closing makes a new invoice');
DB::table('domains_operations')->where('id',$againOp->id)->update(['created_at'=>'2027-08-10 12:00:00']);$clock->instant=$at('2027-08-17T12:00:00Z');$cycles->closeEarlyUnpaid($earlyDomain->id);same('cancelled',$again->fresh()->status,'A second unpaid early renewal closes too');
$product->server->settings()->updateOrCreate(['key'=>'domains_billing_enabled'],['value'=>true,'type'=>'boolean']);
$clock->instant=$at('2027-09-27T12:00:00Z');$scheduled=$billing->invoice($earlyDomain->id);$scheduledOp=Operation::where('invoice_id',$scheduled->id)->sole();
same(['2027-10-11','pending',true],[$scheduled->due_at->format('Y-m-d'),$scheduled->status,str_ends_with($scheduledOp->operation_key,':replacement:2')],'The scheduled invoice issues on its usual day');
$clock->instant=$at('2027-10-05T12:00:00Z');$cycles->closeEarlyUnpaid($earlyDomain->id);same('pending',$scheduled->fresh()->status,'The scheduled invoice is never closed as early');
// Once the billing window opens the invoice is the normal one, and money on the way keeps it open.
[,$windowDomain,,$windowInvoice]=$earlyRenewal('renew-early-window-placeholder.com','2027-09-25T12:00:00Z');
$clock->instant=$at('2027-10-03T12:00:00Z');$cycles->closeEarlyUnpaid($windowDomain->id);same('pending',$windowInvoice->fresh()->status,'An invoice that reached the billing window stays');
foreach (['processing','succeeded'] as $status) {
    [$paying,$payingDomain,,$payingInvoice]=$earlyRenewal('renew-early-'.$status.'-placeholder.com','2027-08-01T12:00:00Z');
    InvoiceTransaction::create(['invoice_id'=>$payingInvoice->id,'amount'=>'1.00','fee'=>'0.00','status'=>$status]);
    same('pending',$payingInvoice->fresh()->status,'Partial '.$status.' receipt has not paid the invoice');
    $clock->instant=$at('2027-08-09T12:00:00Z');$cycles->closeEarlyUnpaid($payingDomain->id);
    same(['pending','awaiting_payment'],[$payingInvoice->fresh()->status,$paying->fresh()->state],$status.' receipt keeps renewal authority open');
}
// A declined card leaves evidence but no money: close the early invoice and issue its scheduled replacement.
[$failed,$failedDomain,,$failedInvoice]=$earlyRenewal('renew-early-failed-placeholder.com','2027-08-01T12:00:00Z');
$failedReceipt=InvoiceTransaction::create(['invoice_id'=>$failedInvoice->id,'amount'=>$failed->amount,'fee'=>'0.00','status'=>'failed']);$failedEvidence=$failedReceipt->fresh()->getRawOriginal();
$clock->instant=$at('2027-08-08T12:00:00Z');$cycles->closeEarlyUnpaid($failedDomain->id);
same(['cancelled','rejected','renewal_cancelled_unpaid'],[$failedInvoice->fresh()->status,$failed->fresh()->state,$failed->fresh()->safe_code],'Failed-only early renewal closes after seven days');
same($failedEvidence,$failedReceipt->fresh()->getRawOriginal(),'Retain the failed receipt unchanged');
$clock->instant=$at('2027-09-27T12:00:00Z');$failedReplacement=$billing->invoice($failedDomain->id);$failedNext=Operation::where('invoice_id',$failedReplacement->id)->sole();
expect($failedReplacement->id!==$failedInvoice->id,'Scheduled billing replaces the cancelled failed-only invoice');
same(['pending',$failed->operation_key.':replacement:1'],[$failedReplacement->status,$failedNext->operation_key],'Scheduled replacement has one new cycle obligation');
same($failedReplacement->id,$billing->invoice($failedDomain->id)->id,'Repeated scheduled billing retains one replacement');
// Staff and customer read the same billing date: at the issue date the invoice is the normal one for both.
[$edge]=$earlyRenewal('renew-early-edge-placeholder.com','2027-09-20T12:00:00Z');app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);$edgeLabel=static fn()=>\Paymenter\Extensions\Servers\Domains\Admin\Support\Labels::requestStatus($edge->fresh())['reason'];
$clock->instant=$at('2027-09-20T12:00:00Z');same(RequestStatus::earlyRenewal($edge->policy['calendar']['due']),$edgeLabel(),'Before the issue date staff read the early words');
$clock->instant=$at($edge->policy['calendar']['issue'].'T00:00:00Z');same(null,\Paymenter\Extensions\Servers\Domains\Services\RenewalCycles::early($edge->fresh(),$clock->now()),'On the issue date it is the normal invoice');
expect($edgeLabel()!==RequestStatus::earlyRenewal($edge->policy['calendar']['due']),'Staff stop saying early on the same date');
same([],array_keys($registrations),'All independent acquisition scenarios ran');
echo "Native renewal cycle matrix passed\n"; $nativeSuccessful=true;
