<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
require __DIR__.'/Support/money-receipt.php';

use App\Models\{Credit, Gateway, Invoice, InvoiceTransaction};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingLocks, InvoiceMoney, PricingRule, RefundPayment, Refunds, RenewalCycles};

$refunds = new Refunds($clock); $failures = [];
$check = static function(bool $ok, string $message) use (&$failures): void {
    if (!$ok) { $failures[] = $message; echo 'SETTLEMENT_FAIL '.$message."\n"; }
};
$balance = static fn()=>PricingRule::minor((string)(Credit::where('user_id',$user->id)->where('currency_code','USD')->value('amount') ?? '0.00'));
$rows = static fn()=>[Invoice::count(), InvoiceTransaction::count(), $balance()];

// Fully returned retired debt leaves the owner free to choose a new renewal before one is billed.
[$credited, $creditedDomain, , $creditedInvoice] = $makeRenewal('retired-credit-choices-placeholder.com', false);
$cycles->retireUnpaid($credited->id);
$clock->instant = new DateTimeImmutable('2027-10-17T12:00:00Z');
InvoiceTransaction::create(['invoice_id'=>$creditedInvoice->id, 'amount'=>$credited->amount, 'fee'=>'0.00', 'status'=>'succeeded']);
same(['amount'=>$credited->amount,'currency'=>'USD'], $refunds->creditRejected($credited->id), 'Late credit is actually returned');
$before = $rows();
try {
    $options = $billing->requestOptions($creditedDomain->fresh());
    $choices = $billing->renewalChoices($creditedDomain->fresh());
    $check(in_array(1, $options['terms'], true) && isset($choices['lines'][1]), 'Fully credited retired renewal keeps its owner renewal choices');
} catch (DomainException $e) {
    $check(false, 'Fully credited retired renewal keeps its owner renewal choices');
}
same($before, $rows(), 'Renewal choices create no debt or money writes');
same(0, DB::transactionLevel(), 'Renewal choices keep their advisory read contract');
$refused = false;
try { InvoiceMoney::load(HoldingLocks::take($creditedDomain->fresh(), advisory:true), (int)$creditedInvoice->id); }
catch (LogicException) { $refused = true; }
expect($refused, 'Authoritative money reads still refuse advisory locks');
$replacement = $billing->invoice($creditedDomain->id, $user);
expect($replacement->id !== $creditedInvoice->id, 'Returned credit permits a replacement invoice under current locks');
same($replacement->id, $billing->invoice($creditedDomain->id, $user)->id, 'Replacement billing remains once-only');

// A gateway has reserved the full receipt but Domains has not observed its return plan yet.
[$pending, $pendingDomain, , $pendingInvoice] = $makeRenewal('retired-pending-replacement-placeholder.com', false);
$cycles->retireUnpaid($pending->id);
$clock->instant = new DateTimeImmutable('2027-10-17T12:00:00Z');
$gateway = Gateway::create(['extension'=>'MoneyReceipt','name'=>'Offline settlement receipts','type'=>'gateway','enabled'=>true]);
InvoiceTransaction::create(['invoice_id'=>$pendingInvoice->id, 'amount'=>$pending->amount, 'fee'=>'0.00', 'status'=>'succeeded',
    'gateway_id'=>$gateway->id, 'transaction_id'=>'synthetic_pending_retirement']);
MoneyReceiptGateway::automatic($pendingInvoice, 'synthetic_pending_retirement', PricingRule::minor($pending->amount), 'pending');
expect(empty($pending->fresh()->funding['late_credits']), 'The pending gateway return has not been adopted');
DB::transaction(function() use ($pending, $pendingDomain, $pendingInvoice, $check): void {
    $l = HoldingLocks::take($pendingDomain->fresh()); $money = InvoiceMoney::load($l, (int)$pendingInvoice->id);
    same(0, InvoiceMoney::held($money), 'Gateway reservation leaves no money available for a new payout');
    same(false, InvoiceMoney::settled($money), 'Gateway reservation is not completed settlement');
    $check(!RefundPayment::retiredSettled($pending->fresh(), $l), 'Unobserved pending gateway return blocks retired settlement');
});
$before = $rows(); $blocked = false;
try { $billing->invoice($pendingDomain->id, $user); } catch (DomainException) { $blocked = true; }
$check($blocked && $rows() === $before, 'Unobserved pending gateway return blocks replacement billing without new debt');
// Only the fixed refusal path can safely continue through completion without a premature replacement.
if ($blocked) {
    $sends = MoneyReceiptGateway::$sends;
    same(null, $refunds->creditRejected($pending->id), 'Adopting the pending return does not complete it');
    commerceReject(fn()=>$billing->invoice($pendingDomain->id, $user));
    MoneyReceiptGateway::complete($pendingInvoice, 'synthetic_pending_retirement:excess');
    same(['amount'=>$pending->amount,'currency'=>'USD'], $refunds->creditRejected($pending->id), 'Gateway completion is observed once');
    $replacement = $billing->invoice($pendingDomain->id, $user);
    expect($replacement->id !== $pendingInvoice->id, 'Completed return releases replacement billing');
    same($replacement->id, $billing->invoice($pendingDomain->id, $user)->id, 'Completed return permits only one replacement');
    same($sends, MoneyReceiptGateway::$sends, 'Settlement checks never send another gateway return');
}
same([], $failures, 'Retired renewal choices and replacement billing respect completed settlement');
$nativeSuccessful = true;
