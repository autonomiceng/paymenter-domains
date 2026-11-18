<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic completed acquisitions and native receipt paths.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
use App\Helpers\ExtensionHelper;
use App\Models\{Credit, Invoice, InvoiceTransaction, Role, Service};
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryAccounts, ComplimentaryInvoices, DepartureProof, HolderPricing, HoldingDeparture, HoldingLocks, MoneyOutcome, NativeDomainGuard, ObservationRecords, PricingRule, Refunds, StaffHoldingReview};

(new NativeDomainGuard(new HolderPricing($prices), $clock))->boot();
app()->instance(Clock::class, $clock); app()->instance(Refunds::class, new Refunds($clock));
$staff = commerceUser();
$staff->update(['role_id' => Role::create(['name' => 'Departed complimentary receipts', 'permissions' => [
    'admin.domains.fund', 'admin.domains.view', 'admin.domains.manage', HoldingDeparture::PERMISSION,
]])->id]);
ComplimentaryAccounts::set($user->id, $staff, true, 'Synthetic complimentary acquisitions', $clock);
$cash = static fn (int $owner) => PricingRule::minor((string) (Credit::where('user_id', $owner)->where('currency_code', 'USD')->value('amount') ?? '0.00'));
$other = commerceUser(); $otherBefore = $cash((int) $other->id);
$results = []; $expected = [];

// Case: departed complimentary acquisitions return a late receipt once to the retained owner.
foreach (['register', 'transfer'] as $action) {
    foreach (['after_commit', 'sweep'] as $path) {
        Cache::flush();
        $op = $new(placeholderComFixtureName('comp-departed-'.$action.'-'.str_replace('_', '-', $path).'-placeholder.com'), false, true, $action);
        $d = $op->domain()->firstOrFail();
        // Retained completed acquisition fixture; departure and both receipt paths run their real services.
        $op->update(['state' => 'succeeded', 'cost' => [...($op->cost ?? []), 'actual' => ['amount' => '8.95', 'currency' => 'USD']],
            'confirmed_expires_at' => '2027-10-18', 'completed_at' => $clock->now()]);
        ComplimentaryInvoices::complete($op);
        Service::withoutEvents(fn () => Service::findOrFail($op->service_id)->update(['status' => 'active']));
        $d->update(['status' => 'active', 'provider_expires_at' => '2027-10-18', 'observation' => [
            'renewal_ownership' => ['identity' => ObservationRecords::identity($d), 'owned' => false, 'at' => $clock->now()->format(DATE_ATOM)],
        ]]);
        $ticket = StaffHoldingReview::ticket((int) $staff->id, (int) $d->id, HoldingDeparture::ACTION,
            StaffHoldingReview::stamp(HoldingLocks::take($d->fresh(), advisory: true)), $clock);
        $departure = (new HoldingDeparture($clock))->confirm($staff, (int) $d->id, $ticket, 'other_registrar', 'Checked the synthetic registrar account.');
        same(MoneyOutcome::Applied, $departure->outcome, 'The completed '.$action.' confirms departure: '.$departure->error?->getMessage());
        same(true, DepartureProof::verified($d->fresh()), 'Departure releases the name with verified retained ownership');
        $record = $d->fresh()->renewal_policy['departure'];
        $invoice = Invoice::findOrFail($op->invoice_id); $items = $invoice->items()->orderBy('id')->get()->map->getAttributes()->all();
        same(['paid', 0.0, 0, 2], [$invoice->status, (float) $invoice->total, $invoice->transactions()->count(), count($items)], 'Completed complimentary receipt retains its charge and offset');
        $account = Account::where('server_id', $op->server_id)->sole(); $before = $cash((int) $user->id);
        $reference = 'departed-comp-'.$action.'-'.$path;
        $pay = static fn () => ExtensionHelper::addPayment($invoice, null, '1.00', '0.00', $reference);
        if ($path === 'sweep') {
            InvoiceTransaction::withoutEvents($pay);
            same($before, $cash((int) $user->id), 'An interrupted receipt handler has not yet credited money');
            app(Refunds::class)->creditRetired($account);
        } else {
            DB::transaction(static function () use ($pay, $cash, $user, $before): void {
                $pay(); same($before, $cash((int) $user->id), 'Credit waits for the outer receipt commit');
            });
        }
        $first = $cash((int) $user->id) - $before;
        ExtensionHelper::addPayment($invoice->fresh(), null, '1.00', '0.00', $reference);
        app(Refunds::class)->creditRetired($account); app(Refunds::class)->creditRetired($account);
        $key = $action.':'.$path;
        $results[$key] = [$first, $cash((int) $user->id) - $before, $cash((int) $other->id) - $otherBefore];
        $expected[$key] = [100, 100, 0];
        same(['succeeded', 'departed', null, 'cancelled', 'paid', 0.0, 1, (int) $user->id],
            [$op->fresh()->state, $d->fresh()->status, $d->fresh()->reservation_name, Service::findOrFail($op->service_id)->status,
                $invoice->fresh()->status, (float) $invoice->fresh()->total, $invoice->transactions()->count(), (int) $invoice->fresh()->user_id],
            'Late receipt preserves completed acquisition, departure and native invoice state: '.$key);
        same($record, $d->fresh()->renewal_policy['departure'], 'Receipt recovery preserves the confirmed departure');
        same($items, $invoice->items()->orderBy('id')->get()->map->getAttributes()->all(), 'Receipt recovery preserves invoice items');
    }
}
same($expected, $results, 'Departed complimentary receipt credits original owner exactly once: '.json_encode($results));
echo "Departed complimentary receipts return once to the original owner.\n";
$nativeSuccessful = true;
