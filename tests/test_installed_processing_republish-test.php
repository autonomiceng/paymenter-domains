<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Replay the existing small retained database instead of rebuilding the two-account catalogue.
// Real native cart hooks, projection and checkout cannot be proved by a unit stub.
require __DIR__.'/offer-activation-test.php';
$nativeSuccessful = false;
require __DIR__.'/Support/offer-commerce.php';

use App\Models\{Invoice, Order};
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerPorts, Context, Edit, Facts, FactsObserved, Intent, OfferWork, Projection, Query};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AccountReadBudget, AcquisitionOffers, CartAdmission, CheckoutEligibility, InvoiceBinding};

$clock = app(Clock::class);
$binding = AccountBindings::binding(Account::where('server_id', $tlds[0]->server_id)->sole());
$ports = new CallerPorts(new Projection($native), $clock, $bindings, static fn () => null, static fn () => true);
$query = new Query($binding, ['com'], 'register');
$read = static fn () => $ports->readOffers($query, Context::Staff)[0];
$ports->apply(new FactsObserved($binding, 'com', new Facts(['register' => [1], 'renew' => [1], 'transfer' => []],
    [], true, false, false, 'synthetic', $now, $now->modify('+1 day'))), $read()->revision);
$ports->apply(Edit::override($binding, 'com', new Intent(sell: true, longest: 1)), $read()->revision);
$ports->apply(Edit::seller($binding, 'com', ['register']), $read()->revision);
$offers = new AcquisitionOffers($ports, $bindings, $native);
$eligibility = new class($bindings, $clock) extends CheckoutEligibility { use OfferCommerceRoute; };
$eligibility->fixtureOffers = $offers;
$admission = new class($native, $sellers, $eligibility, $clock) extends CartAdmission { use OfferCommerceRoute; };
$admission->fixtureOffers = $offers;
$admission->boot();
$invoiceBinding = new InvoiceBinding($admission, $native, $prices, $clock);
app()->instance(InvoiceBinding::class, $invoiceBinding);
$invoiceBinding->boot();
$public = $offers->offer('com', 'register', 1);
$product = $public['product'];
$plan = $product->plans()->where('billing_period', 1)->sole();
$price = $plan->prices()->where('currency_code', 'USD')->sole()->price;
$worker = new OfferWork($ports->writer(), $clock, new AccountReadBudget($clock, 25),
    static fn () => throw new LogicException('Republication must not resolve a provider.'),
    static fn () => throw new LogicException('Republication must not request provider allowance.'));
$worker->wake($binding);
same('worked', $worker->accountTurn($binding->serverId), 'Initial local publication completes for the selected account');
$user = commerceUser();
$cart = commerceCart($user);
$line = commerceAdd($cart, $product, 'resumed-placeholder.com');
$counts = [Order::count(), Invoice::count(), Operation::count()];
$readsBefore = $reads->calls;

// Closing an offer may retain native prices for holder renewals; customer admission must still stop.
$bindings->setProcessing($binding->serverId, false);
same('account_unavailable', $read()->reason, 'Paused account refuses new acquisition admission');
commerceReject(static fn () => $offers->offer('com', 'register', 1));
try {
    commerceAdd($cart, $product->fresh(), 'paused-placeholder.com');
    throw new RuntimeException('Paused account accepted a new native cart line');
} catch (DomainException $error) {
    same('No current offer price.', $error->getMessage(), 'Paused add is refused by account admission');
}
$cartIds = static fn () => $cart->items()->pluck('id')->map(static fn ($id) => (int) $id)->all();
same([(int) $line->id], $cartIds(), 'Paused add creates no cart line');
commerceCheckout($user, $cart)->assertDispatched('notify', static fn ($event, $params) =>
    $params[0]['message'] === Paymenter\Extensions\Servers\Domains\Services\CartCheckoutRefresh::unchecked(['resumed-placeholder.com']));
same($counts, [Order::count(), Invoice::count(), Operation::count()], 'Paused checkout creates no order, invoice or operation');
same([(int) $line->id], $cartIds(), 'Paused checkout preserves the existing cart line');
same($readsBefore, $reads->calls, 'Paused cart admission never resolves a provider');
echo "Paused customer add and native checkout refused.\n";

// Publication keeps cart lines for checkout to re-check; it may preserve or withdraw native holder prices.
$ports->apply(Edit::republish($binding, 'com'), $read()->revision);
commerceReject(static fn () => $offers->offer('com', 'register', 1));
$bindings->setProcessing($binding->serverId, true, syntheticActivation());
same('worked', $worker->accountTurn($binding->serverId), 'Resumed local publication completes for the selected account');
same(1, $plan->prices()->where('currency_code', 'USD')->count(), 'Resuming Orders republishes the native USD row');
same($price, $plan->prices()->where('currency_code', 'USD')->sole()->price, 'Resume restores the existing rule price');
same($product->id, $offers->offer('com', 'register', 1)['product']->id, 'Resume preserves the permanent product');
same(null, $worker->status($binding)['due_at'], 'Resume does not schedule a provider feed');
same($readsBefore, $reads->calls, 'Resume and republication are local only');
echo "Resume retains the native USD row and original product/price.\n";

same([(int) $line->id], $cartIds(), 'Publication keeps the existing cart line');
commerceCheckout($user, $cart)->assertHasNoErrors();
same(array_map(static fn ($count) => $count + 1, $counts), [Order::count(), Invoice::count(), Operation::count()], 'Resumed native checkout admits one obligation');

$nativeSuccessful = true;
echo "Processing resume republication: ok\n";
