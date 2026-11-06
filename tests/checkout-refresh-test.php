<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Full native checkout refresh contracts.
declare(strict_types=1);
require __DIR__.'/Support/checkout-refresh.php';
use Illuminate\Support\Facades\{Cache, DB, Event};
use Paymenter\Extensions\Servers\Domains\Contracts\{AccountVerifier, Clock, ReadLimiter};
use Paymenter\Extensions\Servers\Domains\Data\{Environment, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Client, Driver};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DeadlineTransport, RegistrarRequest, TransportResponse};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AccountReadBudget, CartAdmission, CheckoutEligibility, ObservationSchema, ReadDeferred, RegistrarRegistry, Runtime, SellerPolicies};
$cart = commerceCart($user);
for ($i = 0; $i < 3; $i++) { commerceAdd($cart, $product, placeholderComFixtureName('paced-'.$i.'-placeholder.com')); $clock->advance(1); }
$page = $mount($cart); $before = count($wire->calls); $clock->advance(301); $click($page);
same(1, App\Models\Order::count(), 'One checkout click automatically refreshes all expired NameSilo lines');
same($before + 3, count($wire->calls), 'One guarded physical read per expired line');
for ($i = $before + 1; $i < count($wire->calls); $i++) { expect($wire->calls[$i] - $wire->calls[$i - 1] >= 1000000, 'One-second pacing remains enforced between real reservations'); }
same(3, (int)DB::table('domains_read_budgets')->value('used'), 'Real limiter charges every refresh');
// A valid line with one second left must not expire behind a slower sibling.
$clock->advance(1); $cart = commerceCart($user);
$a = commerceAdd($cart, $product, placeholderComFixtureName('headroom-a-placeholder.com')); $clock->advance(1);
$b = commerceAdd($cart, $product, placeholderComFixtureName('headroom-b-placeholder.com')); $page = $mount($cart);
$clock->advance(Cache::get('domains.checkout.'.$a->checkout_config['domains_check'])['expires'] - $clock->now()->getTimestamp() - 1);
Cache::forget('domains.checkout.'.$b->checkout_config['domains_check']); $wire->slowName = $b->checkout_config['domain'];
$click($page);
same(2, App\Models\Order::count(), 'Near-expiry proof is refreshed before a slower sibling and locked checkout');
$wire->slowName = null;
// Lock waits can still exceed headroom: reject expired proof, keep the cart, offer a truthful retry.
$clock->advance(1); $cart = commerceCart($user); $line = commerceAdd($cart, $product, placeholderComFixtureName('lock-wait-placeholder.com')); $page = $mount($cart);
$expireDuringAdmission = true; $before = count($wire->calls); $click($page); $expireDuringAdmission = false;
same(2, App\Models\Order::count(), 'Five-minute rule rejects proof expiring during admission');
same($before, count($wire->calls), 'TTL rollback never reads under native locks');
same($line->id, $cart->items()->sole()->id, 'TTL rollback keeps the unchanged cart');
$page->assertDispatched('notify', static fn($event, $params) => str_contains(strtolower($params[0]['message']), 'try checkout again') && !str_contains(strtolower($params[0]['message']), 'remove'));
$click($page); same(3, App\Models\Order::count(), 'Next checkout repairs TTL rollback without a new search');
$nativeSuccessful = true; echo "Real-limiter checkout pacing, receipt headroom and TTL rollback passed.\n";
