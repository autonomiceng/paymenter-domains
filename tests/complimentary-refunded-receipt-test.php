<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic receipt evidence only.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
use App\Helpers\ExtensionHelper;
use App\Models\{Gateway, Invoice, InvoiceItem, InvoiceTransaction, Role};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryAccounts, ComplimentaryInvoices, HolderPricing, NativeDomainGuard, PricingRule, Refunds};
class ComplimentaryRefundGateway extends \App\Classes\Extension\Gateway {
    public static array $ledger = [];
    public function pay(Invoice $invoice, $total) { return ''; }
    public function receiptLedger(Invoice $invoice): array { return self::$ledger[$invoice->id] ?? []; }
}
class_alias(ComplimentaryRefundGateway::class, 'Paymenter\\Extensions\\Gateways\\ComplimentaryRefund\\ComplimentaryRefund');
Gateway::create(['extension' => 'ComplimentaryRefund', 'name' => 'Synthetic refunded receipt', 'type' => 'gateway', 'enabled' => true]);
(new NativeDomainGuard(new HolderPricing($prices), $clock))->boot();
app()->instance(Clock::class, $clock); app()->instance(Refunds::class, new Refunds($clock));
$staff = commerceUser();
$staff->update(['role_id' => Role::create(['name' => 'Complimentary refunded receipts', 'permissions' => ['admin.domains.fund']])->id]);
ComplimentaryAccounts::set($user->id, $staff, true, 'Complimentary refunded fixture', $clock);
$credit = static fn () => PricingRule::minor((string) ($user->credits()->where('currency_code', 'USD')->value('amount') ?? '0.00'));
$results = []; $expected = [];
// The gateway refunded part or all of a 10.00 payment on the zero receipt before the missed handler's sweep.
foreach ([0 => 1000, 400 => 600, 1000 => 0] as $refunded => $owed) {
    $op = $new(placeholderComFixtureName('comp-refunded-'.$refunded.'-placeholder.com'), false, true, 'register');
    $invoice = Invoice::findOrFail($op->invoice_id); $reference = 'comp-refunded-'.$refunded;
    InvoiceTransaction::withoutEvents(static fn () => ExtensionHelper::addPayment($invoice, 'ComplimentaryRefund', '10.00', '0.00', $reference));
    ComplimentaryRefundGateway::$ledger[$invoice->id] = $refunded === 0 ? [] : [['invoice_id' => (int) $invoice->id, 'user_id' => (int) $user->id,
        'currency' => 'USD', 'payment_intent_id' => $reference, 'allocation_key' => 'stripe:refund:re_'.$refunded, 'state' => 'refunded', 'amount_minor' => $refunded]];
    $before = $credit();
    $refunds = app(Refunds::class); $account = Account::where('server_id', $op->server_id)->sole();
    $refunds->creditRetired($account); $first = $credit() - $before;
    $refunds->creditRetired($account); $refunds->creditRenewalReceipts((int) $invoice->id);
    $fresh = $op->fresh(); $invoice = $invoice->fresh();
    $results[$refunded] = [$first, $credit() - $before, $invoice->status,
        ComplimentaryInvoices::receipt($fresh, $invoice, InvoiceItem::findOrFail($fresh->invoice_item_id), $invoice->items, $invoice->transactions)];
    $expected[$refunded] = [$owed, $owed, 'paid', true];
}
same($expected, $results, 'A refunded complimentary receipt credits only its unrefunded remainder, once, and the receipt stays valid: '.json_encode($results));
echo "Refunded complimentary receipts credit only the remainder.\n"; $nativeSuccessful = true;
