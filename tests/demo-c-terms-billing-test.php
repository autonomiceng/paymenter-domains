<?php
// Native invoice refusal and annual continuity; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/reconciliation-fixture.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, HolderPricing, NativeDomainGuard};
[$original] = $prepare(placeholderComFixtureName('terms-billing-placeholder.com'));
same('succeeded', $reconcile->run($original->id)->state, 'Real completed holding anchors billing');
$d = $original->domain()->firstOrFail();
$server = $product->server;
foreach (['domains_billing_enabled', 'domains_manual_renewals_enabled'] as $key) {
    $server->settings()->updateOrCreate(['key'=>$key], ['value'=>true, 'type'=>'boolean']);
}
$clock->instant = new DateTimeImmutable('2027-09-27T12:00:00Z');
$guard = new NativeDomainGuard(new HolderPricing($prices), $clock); $guard->boot();
$billing = new DomainBilling($bindings, $guard, $clock, catalogue: $GLOBALS['caps']??null);
$debt = static fn () => serialize([DB::table('invoices')->get(), DB::table('invoice_items')->get(), DB::table('domains_operations')->get()]);
$before = $debt();
same([1], $billing->requestOptions($d)['terms'], 'No exact quote offers annual renewal only');
foreach ([$user, null] as $actor) {
    commerceReject(fn () => $billing->invoice($d->id, $actor, 2));
    same($before, $debt(), 'Longer owner or scheduled request creates no debt');
}
$invoice = $billing->invoice($d->id, $user, 1);
$op = Operation::where('invoice_id', $invoice->id)->sole();
same([1, '10.40', '2028-10-18'], [(int)$op->term, $op->amount, $op->expected_expires_at->format('Y-m-d')], 'Annual retained amount and expiry stay exact');
same(null, $op->policy['renewal']['cost_cap'], 'Stale annual cost never becomes a guessed cap');
same($invoice->id, $billing->invoice($d->id)->id, 'Scheduled billing reuses the issued annual debt');
same(null, $d->service->fresh()->expires_at, 'Annual billing leaves native expiry unchanged');
$nativeSuccessful = true;
echo "Native annual billing and longer-term no-debt refusal passed\n";
