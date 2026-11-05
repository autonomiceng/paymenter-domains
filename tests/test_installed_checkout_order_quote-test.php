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

$nativeSuccessful = false;
// A fresh daily publication can replace an expired cart quote without changing the issued debt.
$op = Operation::whereHas('domain', fn ($q) => $q->where('domain', 'quote-register-same-placeholder.com'))->sole();
$invoice = App\Models\Invoice::findOrFail($op->invoice_id);
$frozen = [$op->policy, $op->cost, $op->amount];
$clock->instant = $now->modify('+1 second');
$publication = new \Paymenter\Extensions\Servers\Domains\Catalogue\CallerPorts(new \Paymenter\Extensions\Servers\Domains\Catalogue\Projection($native), $clock, $bindings, static fn () => null, static fn () => true);
$q = new \Paymenter\Extensions\Servers\Domains\Catalogue\Query($binding, ['com'], 'register');
$revision = fn () => $publication->readOffers($q, \Paymenter\Extensions\Servers\Domains\Catalogue\Context::Staff)[0]->revision;
$costs = [];
foreach ($original as $row) {
    $cost = json_decode($row['catalogue_observation'], true);
    $cost['source'] = 'enom_bulk_bucket_'.($row['term'] === 3 ? 2 : 1);
    $cost['observed_at'] = $clock->now()->format(DATE_ATOM);
    $cost['fresh_until'] = $clock->now()->modify('+48 hours')->format(DATE_ATOM);
    $costs[] = \Paymenter\Extensions\Servers\Domains\Catalogue\OfferState::cost($cost);
}
$publication->apply(new \Paymenter\Extensions\Servers\Domains\Catalogue\FeedObserved($binding, 'com', $costs), $revision());
$clock->instant = $now->modify('+6 minutes'); $wire->mode = 'same';
$pay = new class($bindings, $clock) extends \Paymenter\Extensions\Servers\Domains\Services\PayCheck { use OfferCommerceRoute; };
$pay->fixtureOffers = $offers;
same(null, $pay->stop($invoice), 'Unchanged published prices keep an expired-quote invoice payable');
same($frozen, [$op->fresh()->policy, $op->fresh()->cost, $op->fresh()->amount], 'Payment refresh leaves frozen invoice authority intact');
$clock->instant = $now->modify('+7 minutes');
$publication->apply(\Paymenter\Extensions\Servers\Domains\Catalogue\Edit::override($binding, 'com', new \Paymenter\Extensions\Servers\Domains\Catalogue\Intent(sell: true, longest: 3, transfers: true), [...\Paymenter\Extensions\Servers\Domains\Services\PricingRule::DEFAULT, 'mode' => 'fixed', 'fixed' => ['register' => '7.80', 'renew' => '12.00', 'transfer' => '9.10']]), $revision());
same('unavailable', $pay->stop($invoice)['why'] ?? null, 'A changed follow-on renewal still blocks payment');
same($frozen, [$op->fresh()->policy, $op->fresh()->cost, $op->fresh()->amount], 'Refused payment preserves the issued debt');
$nativeSuccessful = true;
echo "Payment after unchanged daily publication: ok\n";
