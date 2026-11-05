<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Staff publication never empties a customer's cart: a new price is shown once, a TLD off sale drops only its line.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/offer-commerce.php';
use App\Models\{Invoice, Order};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Edit, Intent, Query};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\PricingRule;

$fixture = offerCommerceFixture(); extract($fixture);
[$secondBinding, $second] = offerCommerceSecond($fixture);
$user = commerceUser(); $com = $storefront->offer('com', 'register', 3)['product'];
$q = new Query($binding, ['com'], 'register', 3);
$rev = static fn () => $ports->readOffers($q, Context::Staff)[0]->revision;
$counts = static fn (): array => [Order::count(), Invoice::count(), Operation::count()];
$notice = static fn ($page): string => collect($page->effects['dispatches'] ?? [])->firstWhere('name', 'notify')['params'][0]['message'] ?? '';
$total = static fn ($page): ?string => $page->get('total') === null ? null : number_format((float) $page->get('total')->total, 2, '.', '');
$open = static function ($cart) use ($user) {
    Illuminate\Support\Once::flush();
    return Livewire\Livewire::actingAs($user)->withCookies(['cart' => $cart->ulid])->test(App\Livewire\Cart::class)->set('use_credits', false);
};
$checkout = static function ($page) use ($user) {
    Illuminate\Support\Once::flush();
    return $page->call('checkout', ['contact' => commerceOrderContact($user), 'save_account' => false]);
};
$changed = 'Your cart changed. Review the total, then check out again.';

// Save and publish with the same price: the line stays and one click checks out silently.
$cart = commerceCart($user); $line = commerceAdd($cart, $com, 'same-price-placeholder.com', 3);
$page = $open($cart); $before = $counts();
$ports->apply(Edit::republish($binding, 'com'), $rev());
expect($cart->items()->whereKey($line->id)->exists(), 'Same-price publish keeps the cart line');
$checkout($page);
expect(!collect($page->effects['dispatches'] ?? [])->contains('name', 'notify'), 'Same price shows no notice');
same($before[2] + 1, Operation::count(), 'Same price checks out on the first click');
same('23.40', Operation::latest('id')->first()->policy['price']['retail'], 'Same price keeps the shown total');

// A published markup change keeps the line, shows the new total once, and the next click buys at that total.
$cart = commerceCart($user); $line = commerceAdd($cart, $com, 'changed-price-placeholder.com', 3);
$page = $open($cart); same('23.40', $total($page), 'Held page shows the old total');
$ports->apply(Edit::override($binding, 'com', new Intent(sell: true, longest: 3, transfers: true), [...PricingRule::DEFAULT, 'markup' => '50.00']), $rev());
expect($cart->items()->whereKey($line->id)->exists(), 'Published price change keeps the cart line');
$before = $counts(); $checkout($page);
same($before, $counts(), 'First click after a price change places no order');
same($changed, $notice($page), 'Changed price shows the changed-cart notice');
same('27.00', $total($page), 'Changed price shows the new total');
$checkout($page);
same($before[2] + 1, Operation::count(), 'Second click checks out');
same('27.00', Operation::latest('id')->first()->policy['price']['retail'], 'Second click buys at the new total');

// Staff take .com off sale: only that line goes, in plain words, on page load and at checkout.
$cart = commerceCart($user); $closed = commerceAdd($cart, $com, 'closed-sale-placeholder.com', 3);
$kept = commerceAdd($cart, $second['product'], 'example.net');
$page = $open($cart);
$loaded = commerceCart($user); $loadedLine = commerceAdd($loaded, $com, 'closed-load-placeholder.com', 3);
$ports->apply(Edit::override($binding, 'com', new Intent(sell: false, longest: 3, transfers: true)), $rev());
$before = $counts(); $checkout($page);
same($before, $counts(), 'Removing a line places no order');
expect(!$cart->items()->whereKey($closed->id)->exists(), 'The TLD off sale loses only its own line');
expect($cart->items()->whereKey($kept->id)->exists(), 'Other cart lines stay');
same('closed-sale-placeholder.com is no longer for sale, so we removed it from your cart. Search again to find another domain.',
    $notice($page), 'Off-sale checkout says what happened and what to do next');
same(ltrim($second['price'], '$'), $total($page), 'Total now covers only the remaining line');
$checkout($page);
same($before[2] + 1, Operation::count(), 'Remaining line checks out on the next click');
same('net', Operation::latest('id')->first()->domain->tld, 'Only the remaining line is bought');
Illuminate\Support\Once::flush();
$page = Livewire\Livewire::actingAs($user)->withCookies(['cart' => $loaded->ulid])->test(App\Livewire\Cart::class);
expect(!$loaded->items()->whereKey($loadedLine->id)->exists(), 'Page load also removes the off-sale line');
same('closed-load-placeholder.com is no longer for sale, so we removed it from your cart. Search again to find another domain.',
    $notice($page), 'Page load uses the same words');
same(null, $total($page), 'Empty cart after removal has no total');
$nativeSuccessful = true;
echo "Cart price change: ok\n";
