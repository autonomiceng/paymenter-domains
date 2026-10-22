<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/reconciliation-fixture.php';
use App\Models\{Order, Role, Service};
use Illuminate\Support\Facades\{DB, Queue};
use Paymenter\Extensions\Servers\Domains\Services\{HolderPricing, HoldingLocks, NativeDomainGuard, NativeIdentity, Refunds, RetailLine};
Queue::fake();
$guard = new NativeDomainGuard(new HolderPricing($prices), $clock); $guard->boot();
app()->instance(Paymenter\Extensions\Servers\Domains\Services\InvoiceBinding::class, $invoiceBinding);
NativeIdentity::boot();
$initial = $new(placeholderComFixtureName('projection-initial-placeholder.com'), term: 3);
$s = $initial->domain->service;
same(1, (int) $s->plan->billing_period, 'Admitted checkout projects the annual plan');
same('year', $s->plan->billing_unit, 'Initial annual plan remains native');
same('10.40', RetailLine::decimal($s->price), 'Initial owned retail projection');
same(null, $s->expires_at, 'Initial projection keeps native expiry null');
expect(!NativeDomainGuard::identityProjection($s), 'Initial grant removed after save');

// A real funded original-attempt reconciliation exercises the owned completion capability.
[$original] = $prepare(placeholderComFixtureName('projection-completed-placeholder.com'));
same('succeeded', $reconcile->run($original->id)->state, 'Completion preserves full original-attempt proof');
$d = $original->domain; $s = $d->service;
same('active', $s->status, 'Owned initial completion projects native paid status');
$other = commerceUser();
$reprice = static fn () => DB::transaction(fn () => $guard->project(HoldingLocks::take($d)));
$money = $nativeMoney();
DB::table('services')->where('id', $s->id)->update(['price' => '9.99']);
$projection = $reprice();
same('10.40', $projection['annual_gross'], 'Owned pricing returns exact annual gross');
same('10.40', RetailLine::decimal($s->fresh()->price), 'Owned pricing saves the exact projection');
same($money, $nativeMoney(), 'Projection never reprices issued financial history');

// Mutations run after saving consumes the capability, before updating validates it again.
$attack = null; $busy = false; $owned = null;
Service::saving(static function (Service $row) use (&$attack, &$busy, &$owned): void {
    if (!$attack || $busy || !NativeDomainGuard::identityProjection($row)) { return; }
    $busy = true; $owned = $row;
    try { $attack($row); } finally { $busy = false; }
});
$cases = [
    'row id' => static fn ($row) => $row->forceFill(['id' => 999999]),
    'extra field' => static fn ($row) => $row->forceFill(['label' => 'Forged projection']),
    'missing field' => static fn ($row) => $row->forceFill(['price' => '9.99']),
    'new value' => static fn ($row) => $row->forceFill(['price' => '0.01']),
    'owner' => static fn ($row) => $row->forceFill(['user_id' => $other->id]),
    'product' => static fn ($row) => $row->forceFill(['product_id' => 999999]),
    'expected state' => static function ($row): void {
        $planned = $row->getAttributes();
        $row->setRawAttributes([...$row->getRawOriginal(), 'status' => 'pending'], true);
        $row->setRawAttributes($planned); // Change only the preimage, preserving planned timestamps.
    },
    'table' => static fn ($row) => $row->setTable('missing_services'),
    'key column' => static fn ($row) => $row->setKeyName('user_id'),
    'same object reuse' => static fn ($row) => $row->save(),
];
foreach ($cases as $label => $change) {
    DB::table('services')->where('id', $s->id)->update(['price' => '9.99']);
    $before = $s->fresh()->getAttributes(); $attack = $change;
    commerceReject($reprice); $attack = null;
    same($before, $s->fresh()->getAttributes(), $label.' refused atomically');
    expect(!NativeDomainGuard::identityProjection($owned), $label.' leaves no reusable grant');
    $reprice();
    same('10.40', RetailLine::decimal($s->fresh()->price), $label.' does not poison the next owned save');
}

// A later updating callback runs after the scoped guard; the savepoint must contain it.
$late = false;
Service::updating(static function (Service $row) use (&$late): void {
    if ($late && NativeDomainGuard::identityProjection($row)) { $row->price = '0.01'; }
});
DB::table('services')->where('id', $s->id)->update(['price' => '9.99']);
DB::transaction(static function () use ($d, $guard, &$late): void {
    $late = true;
    $locked = HoldingLocks::take($d); $before = $locked['service']->getAttributes();
    commerceReject(static fn () => $guard->project($locked));
    same($before, $locked['service']->getAttributes(), 'Caught refusal restores the locked in-memory preimage');
    expect($locked['service']->timestamps, 'Timestamp mode restored after refusal');
    $late = false; // Deliberately catch inside the outer transaction, then commit it.
});
same('9.99', RetailLine::decimal($s->fresh()->price), 'Late callback SQL rolls back even when caller catches refusal');
$reprice();

// A fresh instance, clone or a different Service/Order never inherits the active grant.
$attacks = 0;
$attack = static function (Service $row) use ($s, $other, &$attacks): void {
    foreach ([$row->fresh(), clone $row] as $forged) {
        expect(!NativeDomainGuard::identityProjection($forged), 'Exact PHP object is required');
        commerceReject(static fn () => $forged->forceFill(['price' => '0.01'])->save()); $attacks++;
    }
    commerceReject(static fn () => $s->order->fresh()->update(['user_id' => $other->id])); $attacks++;
};
DB::table('services')->where('id', $s->id)->update(['price' => '9.99']); $reprice(); $attack = null;
same(3, $attacks, 'Native writes cannot ride on the owned pricing grant');
same($user->id, (int) $s->order->fresh()->user_id, 'No Order projection authority exists');

// Re-entry also refuses a no-dirty save, where the preimage equals the desired projection.
$attack = static fn ($row) => $row->save();
commerceReject($reprice); $attack = null;
expect(!NativeDomainGuard::identityProjection($owned), 'No-dirty nested save cannot reuse consumption');

// Failures close grants, including saves with no SQL update.
$attack = static fn () => throw new DomainException('Synthetic projection failure');
commerceReject($reprice); $attack = null;
expect(!NativeDomainGuard::identityProjection($owned), 'Exception always closes the grant');
$reprice();
$reprice(); // A no-dirty save still consumes one saving event and closes its grant.
expect(!NativeDomainGuard::identityProjection($owned), 'No-dirty grant cannot escape');

// Native paid loop is independent of owned capabilities, even during a suspended projection.
$attack = static fn () => Fiber::suspend('owned-before-update');
DB::table('services')->where('id', $s->id)->update(['price' => '9.99', 'status' => 'pending']);
$fiber = new Fiber($reprice);
same('owned-before-update', $fiber->start(), 'Owned writer paused after its grant is consumed');
$attack = null;
$native = $s->fresh();
(new App\Services\Service\RenewServiceService)->handle($native);
same('active', $s->fresh()->status, 'Real native paid loop continues during owned projection');
same(null, $s->fresh()->expires_at, 'Native paid-loop expiry is silently neutralized');
foreach (['price' => '10.40', 'user_id' => $other->id, 'product_id' => 999999] as $field => $value) {
    commerceReject(static fn () => $s->fresh()->update([$field => $value]));
}
$fiber->resume(); expect($fiber->isTerminated(), 'Owned pricing finishes after native save');
same('10.40', RetailLine::decimal($s->fresh()->price), 'Concurrent native status save preserves owned price');
same('active', $s->fresh()->status, 'Owned price update preserves concurrent native paid status');
same(null, $s->fresh()->expires_at, 'Both paths retain null native expiry');
expect(!NativeDomainGuard::identityProjection($owned), 'Concurrent schedule leaves no grant');

// Native first, then owned: the owned prefix observes the native paid state before projection.
$s->fresh()->update(['status' => 'suspended']);
(new App\Services\Service\RenewServiceService)->handle($s->fresh());
$reprice(); same('active', $s->fresh()->status, 'Native-first schedule remains native Active');
Queue::assertPushed(App\Jobs\Server\CreateJob::class);
Queue::assertPushed(App\Jobs\Server\UnsuspendJob::class);

// Same-second preimage: a later updating listener moves time across the second boundary.
$rollover = true;
Service::updating(static function (Service $row) use (&$rollover): void {
    if ($rollover && NativeDomainGuard::identityProjection($row)) {
        Carbon\Carbon::setTestNow(Carbon\Carbon::getTestNow()->copy()->addSecond());
        Carbon\CarbonImmutable::setTestNow(Carbon\Carbon::getTestNow());
    }
});
$roll = $new(placeholderComFixtureName('projection-rollover-placeholder.com')); $rollover = false;
same('10.40', RetailLine::decimal($roll->domain->service->price), 'Clock rollover cannot reject admitted checkout');
expect($roll->domain->service->timestamps, 'Timestamp mode remains native after success');
Carbon\Carbon::setTestNow($clock->now()); Carbon\CarbonImmutable::setTestNow($clock->now());

// Native model loaded first, owned reprice commits, then the real paid loop saves stale state.
DB::table('services')->where('id', $s->id)->update(['price' => '9.99', 'status' => 'pending']);
$staleNative = $s->fresh(); $reprice();
(new App\Services\Service\RenewServiceService)->handle($staleNative);
same('active', $s->fresh()->status, 'Stale clean native price cannot block paid activation');
same('10.40', RetailLine::decimal($s->fresh()->price), 'Stale native paid loop preserves committed owned retail');
same(null, $s->fresh()->expires_at, 'Stale native paid loop still normalizes expiry');

// Owned refund decisions retain exact funded definitely-unsent evidence; native status remains writable.
$held = $new(placeholderComFixtureName('projection-refund-placeholder.com')); $held->update(['state' => 'held', 'safe_code' => 'staff_review']);
$role = Role::create(['name' => 'Projection reviewer', 'permissions' => ['admin.domains.manage', 'admin.invoices.update']]);
$staff = commerceUser(); $staff->update(['role_id' => $role->id]);
$refunds = new Refunds($clock);
same(['amount' => $held->amount, 'currency' => 'USD'], $refunds->refundHeld($staff, $held->id, 'customer_cancelled'), 'Exact unsent refund round trip');
same('cancelled', $held->domain->service->status, 'Refund projects only the original native Service');
same(null, $held->domain->service->expires_at, 'Terminal projection keeps null expiry');
same(null, $refunds->creditRejected($held->id), 'Refund capability cannot cause a second credit');
expect(!NativeDomainGuard::identityProjection($held->domain->service), 'Refund grant removed');

// No completion capability can be minted from paid status without current full funding.
$unpaid = $new(placeholderComFixtureName('projection-unfunded-placeholder.com'), false);
DB::table('invoices')->where('id', $unpaid->invoice_id)->update(['status' => 'paid']);
commerceReject(static fn () => DB::transaction(function () use ($unpaid): void {
    $funding = Paymenter\Extensions\Servers\Domains\Services\InvoiceFunding::lock($unpaid->invoice_id);
    $domain = $unpaid->domain()->lockForUpdate()->firstOrFail();
    $op = $domain->operations()->lockForUpdate()->sole();
    $funding->completeInitial($op, $domain);
}));
same('pending', $unpaid->domain->service->status, 'Unfunded completion cannot project Active');
same(0, DB::transactionLevel(), 'All owned transactions close');
echo "Exact native projection capabilities, forgery/refusal, single use and paid-loop schedules passed\n";
$nativeSuccessful = true;
