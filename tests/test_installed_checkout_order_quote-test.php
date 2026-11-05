<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Models\Operation;
$quoteAction = 'register';
require __DIR__.'/Support/checkout-order-quote.php';

$nativeSuccessful = false;
// Same-plan lines need distinct transient quotes through native totals and invoice binding.
$clock->instant = $now; $wire->mode = 'same';
foreach ($original as $row) { DB::table('domains_prices')->where('id', $row['id'])->update($row); }
$cart = commerceCart($user);
$first = commerceAdd($cart, $product, 'first-line-placeholder.com');
$second = commerceAdd($cart, $product, 'second-line-placeholder.com');
foreach ($original as $row) {
    $cost = json_decode($row['catalogue_observation'], true);
    $cost['source'] = 'enom_bulk_bucket_'.($row['term'] === 3 ? 2 : 1);
    $cost['observed_at'] = $now->modify('-49 hours')->format(DATE_ATOM);
    $cost['fresh_until'] = $now->modify('-1 hour')->format(DATE_ATOM);
    DB::table('domains_prices')->where('id', $row['id'])->update(['catalogue_observation' => json_encode($cost)]);
}
$batch = new \Paymenter\Extensions\Servers\Domains\Services\CheckoutRefreshBudget;
$offers->checkoutSelection('first-line-placeholder.com', 'com', 'register', 1, null, $batch, $binding->serverId, $binding->fingerprint);
$wire->mode = 'changed';
$offers->checkoutSelection('second-line-placeholder.com', 'com', 'register', 1, null, $batch, $binding->serverId, $binding->fingerprint);
app('request')->cookies->set('cart', $cart->ulid);
Illuminate\Support\Once::flush();
$admission->refresh();
same(['7.80', '11.70'], array_column($admission->offers(), 3), 'Same-plan cart lines retain their own quoted retail');
$before = Operation::count();
commerceCheckout($user, $cart)->assertHasNoErrors();
same($before + 2, Operation::count(), 'Both differently quoted same-plan lines complete checkout');
same(['7.80', '11.70'], Operation::orderBy('id')->get()->slice(-2)->map(fn ($op) => $op->policy['price']['retail'])->values()->all(), 'Each invoice line freezes its own quoted retail');
$nativeSuccessful = true;
echo "Distinct same-plan order quotes: ok\n";
