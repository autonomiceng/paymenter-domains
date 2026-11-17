<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
use App\Helpers\ExtensionHelper;
use App\Models\{Invoice, InvoiceTransaction, Role, Service};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryAccounts, HolderPricing, NativeDomainGuard, PricingRule, Refunds};
(new NativeDomainGuard(new HolderPricing($prices), $clock))->boot();
app()->instance(Clock::class, $clock); app()->instance(Refunds::class, new Refunds($clock));
$staff = commerceUser();
$staff->update(['role_id' => Role::create(['name' => 'Complimentary receipts', 'permissions' => ['admin.domains.fund']])->id]);
ComplimentaryAccounts::set($user->id, $staff, true, 'Complimentary acquisition fixture', $clock);
$credit = static fn () => PricingRule::minor((string) ($user->credits()->where('currency_code', 'USD')->value('amount') ?? '0.00'));
$results = []; $expected = [];
foreach (['register', 'transfer'] as $action) {
    foreach (['after_commit', 'sweep'] as $path) {
        $op = $new(placeholderComFixtureName('comp-receipt-'.$action.'-'.str_replace('_', '-', $path).'-placeholder.com'), false, true, $action);
        $invoice = Invoice::findOrFail($op->invoice_id); $account = Account::where('server_id', $op->server_id)->sole();
        same(['complimentary', 'paid', 0.0, 0], [$op->funding_basis, $invoice->status, (float) $invoice->total, $invoice->transactions()->count()], 'Acquisition starts with a paid zero invoice');
        $before = $credit(); $priorState = $op->state; $priorService = Service::findOrFail($op->service_id)->status;
        $reference = 'comp-acquisition-'.$action.'-'.$path;
        $pay = static fn () => ExtensionHelper::addPayment($invoice, null, '1.00', '0.00', $reference);
        if ($path === 'sweep') {
            // Retained succeeded receipt whose event handler did not run, as after a post-commit interruption.
            InvoiceTransaction::withoutEvents($pay);
            same($before, $credit(), 'The missed receipt handler has not credited money');
            app(Refunds::class)->creditRetired($account);
        } else {
            DB::transaction(static function () use ($pay, $credit, $before): void {
                $pay(); same($before, $credit(), 'Receipt credit waits for the outer payment commit');
            });
        }
        $first = $credit() - $before;
        ExtensionHelper::addPayment($invoice->fresh(), null, '1.00', '0.00', $reference);
        app(Refunds::class)->creditRetired($account); app(Refunds::class)->creditRetired($account);
        $fresh = $op->fresh(); $key = $action.':'.$path;
        $results[$key] = [$first, $credit() - $before, $invoice->transactions()->count(), $invoice->fresh()->status,
            $fresh->state, Service::findOrFail($fresh->service_id)->status, $fresh->funding_basis, (float) $invoice->fresh()->total];
        $expected[$key] = [100, 100, 1, 'paid', $priorState, $priorService, 'complimentary', 0.0];
    }
}
same($expected, $results, 'Every complimentary acquisition receipt returns once as account credit through commit or sweep: '.json_encode($results));
echo "Complimentary acquisition receipts return once as credit.\n"; $nativeSuccessful = true;
