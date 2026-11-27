<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
use App\Helpers\ExtensionHelper;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use App\Models\{Gateway,Invoice,InvoiceTransaction};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Money\Allocations\{PostingSource, RenewalAllocation, RenewalFundingLedger, RenewalReservation};
use Paymenter\Extensions\Servers\Domains\Services\{HoldingLocks,PricingRule};
use Paymenter\Extensions\Servers\Domains\Money\Returns\RefundPayment;
class RetiredLossGateway extends \App\Classes\Extension\Gateway
{
    public static array $ledger=[];
    public function pay(Invoice $invoice,$total){return '';}
    public function receiptLedger(Invoice $invoice):array{return self::$ledger;}
}
class_alias(RetiredLossGateway::class,'Paymenter\\Extensions\\Gateways\\RetiredLoss\\RetiredLoss');

[$reservedOp, , , $paidInvoice] = $makeRenewal(placeholderComFixtureName('allocation-rollback-placeholder.com'));
$reservation = RenewalReservation::forOperation($reservedOp, [
    new RenewalAllocation(PostingSource::Item, (int) $reservedOp->invoice_item_id, PricingRule::minor($reservedOp->amount)),
    new RenewalAllocation(PostingSource::Payment, (int) $paidInvoice->transactions()->sole()->id, PricingRule::minor($reservedOp->amount)),
]);
DB::beginTransaction();
DB::beginTransaction();
$refused = false;
try {
    // A database rollback discards both the outer transaction and its savepoint.
    DB::connection()->getPdo()->rollBack();
    same(2, DB::transactionLevel(), 'Laravel still reports the discarded savepoint');
    try { RenewalFundingLedger::reserve($reservation); }
    catch (LogicException) { $refused = true; }
} finally {
    DB::rollBack(0);
}
same(null, RenewalFundingLedger::reservation($reservedOp), 'No renewal postings can autocommit after an engine rollback');
expect($refused, 'The allocation writer refuses the discarded savepoint');

DB::beginTransaction();
try {
    RenewalFundingLedger::reserve($reservation);
    $ledger = RenewalFundingLedger::forInvoiceItems([$reservedOp->invoice_item_id]);
    $partial = Operation::findOrFail($reservedOp->id, ['id', 'action', 'funding_basis', 'invoice_id', 'invoice_item_id']);
    expect(RenewalFundingLedger::reservation($reservedOp, $ledger) == $reservation, 'The batch retains the original attempt postings');
    same(null, RenewalFundingLedger::reservation($partial), 'A lookup without the attempt number cannot claim the original postings');
    same(null, RenewalFundingLedger::reservation($partial, $ledger), 'A batch lookup without the attempt number cannot claim the original postings');
} finally {
    DB::rollBack();
}

[$op,$d,,$invoice]=$makeRenewal('retired-loss-guard-placeholder.com',false);
$cycles->retireUnpaid($op->id);
$gateway=Gateway::create(['extension'=>'RetiredLoss','name'=>'Synthetic loss','type'=>'gateway','enabled'=>true]);
$payment=InvoiceTransaction::create(['invoice_id'=>$invoice->id,'amount'=>$op->amount,'fee'=>'0.00','status'=>'succeeded','gateway_id'=>$gateway->id,'transaction_id'=>'synthetic_retired_loss']);
$op->update(['funding'=>[...$op->funding,'late_credits'=>[['to'=>'account_credit','amount'=>$op->amount,'payments'=>[['id'=>(int)$payment->id,'amount_minor'=>PricingRule::minor($op->amount)]]]],
    'dispute_holds'=>[['dispute_id'=>'synthetic_retired_loss','payment_id'=>(int)$payment->id,'owed_minor'=>300,'removed_minor'=>100,'remainder_minor'=>200]]]]);
RetiredLossGateway::$ledger=[['invoice_id'=>(int)$invoice->id,'user_id'=>(int)$user->id,'currency'=>'USD','payment_intent_id'=>$payment->transaction_id,
    'allocation_key'=>'stripe:dispute:synthetic_retired_loss','state'=>'lost','amount_minor'=>300]];
foreach([false,true] as $advisory){
    $read=fn()=>RefundPayment::retiredSettled($op->fresh(),HoldingLocks::take($d->fresh(),advisory:$advisory));
    same(true,$advisory?$read():DB::transaction($read),'Dispute remainder does not block its retired renewal cycle');
}
// The Auto Pay equality still refuses an extra receipt, regardless of the dispute deficit.
InvoiceTransaction::create(['invoice_id'=>$invoice->id,'amount'=>'0.01','fee'=>'0.00','status'=>'succeeded']);
same(false,DB::transaction(fn()=>RefundPayment::retiredSettled($op->fresh(),HoldingLocks::take($d->fresh()))),'An unexplained extra payment cannot grant replacement authority');
// Refunds can arrive before the worker starts or after its native funding reservation commits.
$results = [];
foreach (['admission', 'reserved'] as $when) {
    RetiredLossGateway::$ledger = [];
    [$op, , $binding, $invoice] = $makeRenewal(placeholderComFixtureName("refunded-$when-placeholder.com"), false);
    $receipt = "synthetic_refunded_$when";
    ExtensionHelper::addPayment($invoice, 'RetiredLoss', $op->amount, '0.00', $receipt);
    $payment = $invoice->transactions()->sole()->getRawOriginal();
    $refund = static function () use ($op, $invoice, $receipt): void {
        RetiredLossGateway::$ledger = [['invoice_id' => $invoice->id, 'user_id' => $invoice->user_id, 'currency' => 'USD',
            'payment_intent_id' => $receipt, 'allocation_key' => 'stripe:refund:synthetic', 'state' => 'refunded',
            'amount_minor' => PricingRule::minor($op->amount)]];
    };
    if ($when === 'admission') {
        $refund();
    } else {
        Operation::updated(static function (Operation $row) use ($op, $refund): void {
            if ($row->id === $op->id && $row->wasChanged('state') && $row->state === 'running') { DB::afterCommit($refund); }
        });
    }
    $before = $state->writes;
    $result = $runRenewal($op, $binding);
    same($payment, $invoice->transactions()->sole()->getRawOriginal(), 'The native receipt stays unchanged');
    $results[$when] = [$result->safe_code, $state->writes - $before];
}
same(['admission' => ['renewal_invoice_unpaid', 0], 'reserved' => ['renewal_definitely_unsent', 0]], $results,
    'A fully refunded card payment cannot fund a registrar send: '.json_encode($results));
$nativeSuccessful=true;
