<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
require __DIR__.'/Support/money-receipt.php';
use App\Helpers\ExtensionHelper;
use App\Models\{Gateway, Invoice, InvoiceTransaction, Role, Service};
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryAccounts, HolderPricing, NativeDomainGuard, PricingRule, Refunds};

(new NativeDomainGuard(new HolderPricing($prices), $clock))->boot();
app()->instance(Clock::class, $clock);
$refunds = new Refunds($clock); app()->instance(Refunds::class, $refunds);
Gateway::create(['extension' => 'MoneyReceipt', 'name' => 'Money receipt fixture', 'type' => 'gateway', 'enabled' => true]);
MoneyReceiptGateway::$lost = false;
$staff = commerceUser();
$staff->update(['role_id' => Role::create(['name' => 'Complimentary refund review', 'permissions' => ['admin.domains.fund']])->id]);
ComplimentaryAccounts::set($user->id, $staff, true, 'Synthetic complimentary refund recovery', $clock);
$cash = static fn () => PricingRule::minor((string) ($user->credits()->where('currency_code', 'USD')->value('amount') ?? '0.00'));
$stateOf = static fn ($op, $invoice) => [$op->fresh()->state, $op->fresh()->funding_basis,
    Service::findOrFail($op->service_id)->status, $op->domain()->firstOrFail()->status,
    $invoice->fresh()->status, PricingRule::minor((string) $invoice->fresh()->total)];

// Case: recovery credits only the part of a complimentary receipt not already refunded by its gateway.
$actual = []; $expected = [];
foreach ([400, 1000] as $returned) {
    $op = $new(placeholderComFixtureName('cli-comp-refund-'.$returned.'-placeholder.com'), false);
    $invoice = Invoice::findOrFail($op->invoice_id);
    $account = Account::where('server_id', $op->server_id)->sole();
    same(['complimentary', 'paid', 0], [$op->funding_basis, $invoice->status, PricingRule::minor((string) $invoice->total)],
        'Checkout creates a paid complimentary invoice with no money due');
    $prior = $stateOf($op, $invoice); $before = $cash();
    $reference = 'cli-comp-refund-'.$returned;
    // Core retained the payment but the Domains after-commit handler never ran.
    $receipt = InvoiceTransaction::withoutEvents(static fn () => ExtensionHelper::addPayment($invoice, 'MoneyReceipt', '10.00', '0.00', $reference));
    InvoiceTransaction::withoutEvents(static fn () => MoneyReceiptGateway::automatic($invoice, $reference, $returned));
    same($before, $cash(), 'The missed handler and external card refund add no account credit');
    $transactions = $invoice->transactions()->orderBy('id')->get()->map(static fn ($row) => $row->getRawOriginal())->all();
    $sends = MoneyReceiptGateway::$sends;
    Cache::flush(); $refunds->creditRetired($account);
    $first = $cash() - $before;
    $refunds->creditRetired($account); $refunds->creditRenewalReceipts((int) $invoice->id); $refunds->creditRetired($account);
    $credited = 0;
    foreach ($op->fresh()->funding['late_credits'] ?? [] as $credit) {
        foreach ($credit['payments'] ?? [] as $payment) {
            if ((int) $payment['id'] === (int) $receipt->id) { $credited += $payment['amount_minor']; }
        }
    }
    $actual[$returned] = [$first, $cash() - $before, $credited, $stateOf($op, $invoice),
        $invoice->transactions()->orderBy('id')->get()->map(static fn ($row) => $row->getRawOriginal())->all(), MoneyReceiptGateway::$sends - $sends];
    $expected[$returned] = [1000 - $returned, 1000 - $returned, 1000 - $returned, $prior, $transactions, 0];
}
same($expected, $actual, 'Sweep credits only unrefunded complimentary money once and preserves receipt and acquisition state: '.json_encode($actual));
echo "Complimentary refund recovery credits only the unreturned remainder once.\n";
$nativeSuccessful = true;
