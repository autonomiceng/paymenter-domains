<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
use App\Helpers\ExtensionHelper;
use App\Models\{Invoice, InvoiceTransaction, Role};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryAccounts, DepartureProof, HolderPricing, NativeDomainGuard, PricingRule, Refunds};
(new NativeDomainGuard(new HolderPricing($prices), $clock))->boot();
app()->instance(Clock::class, $clock); app()->instance(Refunds::class, new Refunds($clock));
$staff = commerceUser();
$staff->update(['role_id' => Role::create(['name' => 'Complimentary departed receipts', 'permissions' => ['admin.domains.fund']])->id]);
$credit = static fn () => PricingRule::minor((string) ($user->credits()->where('currency_code', 'USD')->value('amount') ?? '0.00'));
$sweep = static fn (Operation $op) => app(Refunds::class)->creditRetired(Account::where('server_id', $op->server_id)->sole());
$openRepair = static fn (Operation $op) => DB::table('domains_obligation_alerts')->where('operation_id', $op->id)->where('code', 'refund_repair')->whereNull('resolved_at')->count();
// Fixture for a confirmed departure: the frozen record HoldingDeparture writes, then the released reservation.
$depart = static function (Operation $op, bool $release = true) use ($staff, $clock): void {
    $d = Domain::findOrFail($op->domain_id);
    $d->renewal_policy = [...($d->renewal_policy ?? []), 'departure' => ['identity' => DepartureProof::identity($d), 'reason' => 'other_registrar',
        'actor_id' => (int) $staff->id, 'confirmed_at' => $clock->now()->format(DATE_ATOM), 'invoices' => []]];
    $d->status = 'departed'; Domain::withoutEvents(static fn () => $d->save());
    if ($release) { DB::table($d->getTable())->where('id', $d->id)->update(['reservation_name' => null]); }
};

// A paid registration was never complimentary: a stray payment on its departed holding is not complimentary credit.
$paid = $new(placeholderComFixtureName('departed-paid-receipt-placeholder.com'));
$depart($paid); $before = $credit();
DB::transaction(static fn () => ExtensionHelper::addPayment(Invoice::findOrFail($paid->invoice_id), null, '1.00', '0.00', 'departed-paid-stray'));
$sweep($paid); $sweep($paid);
same([$before, []], [$credit(), $paid->fresh()->funding['late_credits'] ?? []], 'A departed paid registration is never credited as complimentary');

ComplimentaryAccounts::set($user->id, $staff, true, 'Complimentary departed fixture', $clock);
$results = []; $expected = [];
foreach (['register', 'transfer'] as $action) {
    foreach (['after_commit', 'sweep'] as $path) {
        $op = $new(placeholderComFixtureName('comp-departed-'.$action.'-'.str_replace('_', '-', $path).'-placeholder.com'), false, true, $action);
        $invoice = Invoice::findOrFail($op->invoice_id);
        same(['complimentary', 'paid', 0], [$op->funding_basis, $invoice->status, $invoice->transactions()->count()], 'Acquisition starts with a paid zero invoice');
        $depart($op); $before = $credit(); $reference = 'comp-departed-'.$action.'-'.$path;
        $pay = static fn () => ExtensionHelper::addPayment($invoice, null, '1.00', '0.00', $reference);
        if ($path === 'sweep') { InvoiceTransaction::withoutEvents($pay); same($before, $credit(), 'The missed receipt handler has not credited money'); $sweep($op); }
        else { DB::transaction($pay); }
        $first = $credit() - $before;
        $sweep($op); $sweep($op);
        $d = Domain::findOrFail($op->domain_id);
        $results[$action.':'.$path] = [$first, $credit() - $before, $invoice->transactions()->count(), $d->status, DepartureProof::verified($d), $openRepair($op)];
        $expected[$action.':'.$path] = [100, 100, 1, 'departed', true, 0];
    }
}
same($expected, $results, 'A departed complimentary receipt returns once to its old owner through commit or sweep: '.json_encode($results));

// A departed row that kept its reservation is unverified: the sweep leaves the money with staff, then a later credit clears the check.
$op = $new(placeholderComFixtureName('comp-departed-repair-placeholder.com'), false, true, 'register');
$depart($op, false); $before = $credit();
InvoiceTransaction::withoutEvents(static fn () => ExtensionHelper::addPayment(Invoice::findOrFail($op->invoice_id), null, '1.00', '0.00', 'comp-departed-repair'));
$sweep($op);
same([$before, 1], [$credit(), $openRepair($op)], 'An unverified departed receipt moves no money and raises the money-return check');
DB::table((new Domain())->getTable())->where('id', $op->domain_id)->update(['reservation_name' => null]);
$sweep($op); $sweep($op);
same([$before + 100, 0], [$credit(), $openRepair($op)], 'The later credit returns the receipt once and resolves the money-return check');
echo "Departed complimentary receipts return once to the old owner and clear their repair.\n"; $nativeSuccessful = true;
