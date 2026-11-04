<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native projection, scheduler revisions and checkout need the existing SQLite commerce fixture.
// A unit stub cannot prove that resumed offers regain their permanent plans' USD rows.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/offer-commerce.php';

use App\Models\{Invoice, Order};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Edit, OfferWork, Query};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\AccountReadBudget;

extract(offerCommerceFixture());
$query = new Query($binding, ['com'], 'register');
$read = static fn () => $ports->readOffers($query, Context::Staff)[0];
$public = $storefront->offer('com', 'register', 1);
$product = $public['product'];
$plan = $product->plans()->where('billing_period', 1)->sole();
$price = $plan->prices()->where('currency_code', 'USD')->sole()->price;
$worker = new OfferWork($ports->writer(), $clock, new AccountReadBudget($clock, 25),
    static fn () => throw new LogicException('Republication must not resolve a provider.'),
    static fn () => throw new LogicException('Republication must not request provider allowance.'));
$worker->wake($binding);
$worker->tick(1);
$readsBefore = $reads->calls;

// A normal local publication while Orders is off withdraws native acquisition prices.
$bindings->setProcessing($binding->serverId, false);
$ports->apply(Edit::republish($binding, 'com'), $read()->revision);
same(0, $plan->prices()->where('currency_code', 'USD')->count(), 'Paused publication removes the native USD row');
commerceReject(static fn () => $storefront->offer('com', 'register', 1));

$bindings->setProcessing($binding->serverId, true, syntheticActivation());
$worker->tick(1);
same(1, $plan->prices()->where('currency_code', 'USD')->count(), 'Resuming Orders republishes the native USD row');
same($price, $plan->prices()->where('currency_code', 'USD')->sole()->price, 'Resume restores the existing rule price');
same($product->id, $storefront->offer('com', 'register', 1)['product']->id, 'Resume preserves the permanent product');
same(null, $worker->status($binding)['due_at'], 'Resume does not schedule a provider feed');
same($readsBefore, $reads->calls, 'Resume and republication are local only');

$user = commerceUser();
$cart = commerceCart($user);
commerceAdd($cart, $product->fresh(), 'resumed-placeholder.com');
commerceCheckout($user, $cart)->assertHasNoErrors();
same([1, 1, 1], [Order::count(), Invoice::count(), Operation::count()], 'Resumed native checkout admits one obligation');

$nativeSuccessful = true;
echo "Processing resume republication: ok\n";
