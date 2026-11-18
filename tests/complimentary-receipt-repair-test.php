<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native credit failure and sweep recovery, no provider calls.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
use App\Helpers\ExtensionHelper;
use App\Models\{Credit, Invoice, InvoiceTransaction, Role, Service};
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryAccounts, HolderPricing, NativeDomainGuard, PricingRule, Refunds};

(new NativeDomainGuard(new HolderPricing($prices), $clock))->boot();
app()->instance(Clock::class, $clock); $refunds = new Refunds($clock); app()->instance(Refunds::class, $refunds);
$staff = commerceUser();
$staff->update(['role_id' => Role::create(['name' => 'Complimentary repair', 'permissions' => ['admin.domains.fund']])->id]);
ComplimentaryAccounts::set($user->id, $staff, true, 'Synthetic complimentary receipt repair', $clock);
$op = $new(placeholderComFixtureName('comp-receipt-repair-placeholder.com'), false);
$invoice = Invoice::findOrFail($op->invoice_id); $account = Account::where('server_id', $op->server_id)->sole();
$cash = static fn () => PricingRule::minor((string) ($user->credits()->where('currency_code', 'USD')->value('amount') ?? '0.00'));
$state = static fn () => [$op->fresh()->state, $op->fresh()->funding_basis, Service::findOrFail($op->service_id)->status,
    $op->domain()->firstOrFail()->status, $invoice->fresh()->status, (float) $invoice->fresh()->total];
$open = static fn () => DB::table('domains_obligation_alerts')->where('domain_id', $op->domain_id)->where('operation_id', $op->id)
    ->where('code', 'refund_repair')->whereNull('resolved_at')->count();
$before = $cash(); $priorState = $state();
$failCredit = true;
Credit::saving(static function (Credit $credit) use (&$failCredit, $user): void {
    if ($failCredit && (int) $credit->user_id === (int) $user->id) { throw new DomainException('Synthetic credit store temporarily unavailable.'); }
});

// Case: a successful retry resolves the repair raised by a failed complimentary receipt credit.
InvoiceTransaction::withoutEvents(static fn () => ExtensionHelper::addPayment($invoice, null, '1.20', '0.00', 'complimentary-repair-receipt'));
Cache::flush(); $refunds->creditRetired($account);
same([1, $before, 1, $priorState], [$open(), $cash(), $invoice->transactions()->count(), $state()], 'Failed credit raises repair and preserves the receipt, money and acquisition state');
$failCredit = false; $clock->instant = $clock->now()->modify('+1 minute');
$refunds->creditRetired($account);
same($before + 120, $cash(), 'Recovered complimentary receipt credits its exact amount');
$refunds->creditRetired($account); $refunds->creditRenewalReceipts((int) $invoice->id);
same([$before + 120, 1, $priorState], [$cash(), $invoice->transactions()->count(), $state()], 'Repeated recovery cannot duplicate money or change acquisition state');
same(0, $open(), 'Successful complimentary receipt credit resolves refund_repair');
echo "Successful complimentary receipt recovery resolves its repair alert.\n";
$nativeSuccessful = true;
