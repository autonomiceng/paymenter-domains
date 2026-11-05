<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Staff publication never empties a customer's cart: a new price is shown once, a TLD off sale drops only its line.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/offer-commerce.php';
require_once __DIR__.'/Support/storefront.php';
use App\Models\{Invoice, Order};
use Paymenter\Extensions\Servers\Domains\Catalogue\{Context, Edit, Intent, Query};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\PricingRule;

$fixture = offerCommerceFixture(); extract($fixture);
[$secondBinding, $second] = offerCommerceSecond($fixture,'example.net');
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
// Livewire test calls reuse the last request's cookies (Testable::update), so name the page's own cart.
$checkout = static function ($page, $cart) use ($user) {
    Illuminate\Support\Once::flush(); app('request')->cookies->set('cart', $cart->ulid);
    return $page->call('checkout', ['contact' => commerceOrderContact($user), 'save_account' => false]);
};
$changed = 'Your cart changed. Review the total, then check out again.';

// Save and publish with the same price: the line stays and one click checks out silently.
$cart = commerceCart($user); $line = commerceAdd($cart, $com, 'same-price-placeholder.com', 3);
$page = $open($cart); $before = $counts();
$ports->apply(Edit::republish($binding, 'com'), $rev());
expect($cart->items()->whereKey($line->id)->exists(), 'Same-price publish keeps the cart line');
$checkout($page, $cart);
expect(!collect($page->effects['dispatches'] ?? [])->contains('name', 'notify'), 'Same price shows no notice');
same($before[2] + 1, Operation::count(), 'Same price checks out on the first click');
same('23.40', Operation::latest('id')->first()->policy['price']['retail'], 'Same price keeps the shown total');

// A published markup change keeps the line, shows the new total once, and the next click buys at that total.
$cart = commerceCart($user); $line = commerceAdd($cart, $com, 'changed-price-placeholder.com', 3);
$page = $open($cart); same('23.40', $total($page), 'Held page shows the old total');
$ports->apply(Edit::override($binding, 'com', new Intent(sell: true, longest: 3, transfers: true), [...PricingRule::DEFAULT, 'markup' => '50.00']), $rev());
expect($cart->items()->whereKey($line->id)->exists(), 'Published price change keeps the cart line');
$before = $counts(); $checkout($page, $cart);
same($before, $counts(), 'First click after a price change places no order');
same($changed, $notice($page), 'Changed price shows the changed-cart notice');
same('27.00', $total($page), 'Changed price shows the new total');
$checkout($page, $cart);
same($before[2] + 1, Operation::count(), 'Second click checks out');
same('27.00', Operation::latest('id')->first()->policy['price']['retail'], 'Second click buys at the new total');

// Staff take .com off sale: only that line goes, in plain words, on page load and at checkout.
$cart = commerceCart($user); $closed = commerceAdd($cart, $com, 'closed-sale-placeholder.com', 3);
$kept = commerceAdd($cart, $second['product'], 'kept.example.net');
$page = $open($cart);
$loaded = commerceCart($user); $loadedLine = commerceAdd($loaded, $com, 'closed-load-placeholder.com', 3);
// Mixed carts: .com goes off sale and the .net account is paused later; the removal notice must still arrive.
$mixedLoad = commerceCart($user); $mixedLoadCom = commerceAdd($mixedLoad, $com, 'mixed-load-placeholder.com', 3);
$mixedLoadNet = commerceAdd($mixedLoad, $second['product'], 'mixed-load-placeholder.example.net');
$mixedCart = commerceCart($user); $mixedCom = commerceAdd($mixedCart, $com, 'mixed-checkout-placeholder.com', 3);
$mixedNet = commerceAdd($mixedCart, $second['product'], 'mixed-checkout-placeholder.example.net');
$mixedPage = $open($mixedCart);
$ports->apply(Edit::override($binding, 'com', new Intent(sell: false, longest: 3, transfers: true)), $rev());
$before = $counts(); $checkout($page, $cart);
same($before, $counts(), 'Removing a line places no order');
expect(!$cart->items()->whereKey($closed->id)->exists(), 'The TLD off sale loses only its own line');
expect($cart->items()->whereKey($kept->id)->exists(), 'Other cart lines stay');
same('closed-sale-placeholder.com is no longer for sale, so we removed it from your cart. Search again to find another domain.',
    $notice($page), 'Off-sale checkout says what happened and what to do next');
same(ltrim($second['price'], '$'), $total($page), 'Total now covers only the remaining line');
$checkout($page, $cart);
same($before[2] + 1, Operation::count(), 'Remaining line checks out on the next click');
same('example.net', Operation::latest('id')->first()->domain->tld, 'Only the remaining line is bought');
Illuminate\Support\Once::flush();
$page = Livewire\Livewire::actingAs($user)->withCookies(['cart' => $loaded->ulid])->test(App\Livewire\Cart::class);
expect(!$loaded->items()->whereKey($loadedLine->id)->exists(), 'Page load also removes the off-sale line');
same('closed-load-placeholder.com is no longer for sale, so we removed it from your cart. Search again to find another domain.',
    $notice($page), 'Page load uses the same words');
same(null, $total($page), 'Empty cart after removal has no total');
$bindings->setProcessing($secondBinding->serverId, false);
Illuminate\Support\Once::flush();
$page = Livewire\Livewire::actingAs($user)->withCookies(['cart' => $mixedLoad->ulid])->test(App\Livewire\Cart::class);
expect(!$mixedLoad->items()->whereKey($mixedLoadCom->id)->exists() && $mixedLoad->items()->whereKey($mixedLoadNet->id)->exists(),
    'Mixed page load removes only the off-sale line');
same('mixed-load-placeholder.com is no longer for sale, so we removed it from your cart. Search again to find another domain.',
    $notice($page), 'A paused line cannot swallow the page-load removal notice');
$before = $counts(); $checkout($mixedPage, $mixedCart);
same($before, $counts(), 'Mixed checkout with a removal places no order');
expect(!$mixedCart->items()->whereKey($mixedCom->id)->exists() && $mixedCart->items()->whereKey($mixedNet->id)->exists(),
    'Mixed checkout removes only the off-sale line');
same('mixed-checkout-placeholder.com is no longer for sale, so we removed it from your cart. Search again to find another domain.',
    $notice($mixedPage), 'A paused line cannot swallow the checkout removal notice');

// A theme that includes the notice shows it at the top of the cart, titled, with the old line price struck through.
$views = getenv('PAYMENTER_TEST_FIXTURE').'/resources/views/cart-notice-harness'; if (!is_dir($views)) { mkdir($views, 0700, true); }
// Same branching as the Landing cart: the notice shows above the lines and above an empty cart.
file_put_contents($views.'/cart.blade.php', '<div>@includeIf(\'domains::cart-notice\')@if (\App\Classes\Cart::items()->count() === 0)<p class="empty">Empty</p>@else '
    .'@foreach (\App\Classes\Cart::items() as $item)<div class="line">@includeIf(\'domains::cart-was\', [\'item\' => $item])'
    .'{{ $item->price->format($item->price->total * $item->quantity) }}</div>@endforeach @endif</div>');
Illuminate\Support\Facades\View::getFinder()->prependLocation($views); Illuminate\Support\Facades\View::getFinder()->flush();
Illuminate\Support\Facades\View::addNamespace('domains', dirname(__DIR__).'/Domains/resources/views');
$ports->apply(Edit::override($binding, 'com', new Intent(sell: true, longest: 3, transfers: true), [...PricingRule::DEFAULT, 'markup' => '50.00']), $rev());
$cart = commerceCart($user); commerceAdd($cart, $com, 'themed-notice-placeholder.com', 3);
$page = $open($cart); same('27.00', $total($page), 'Themed page shows the held total');
$ports->apply(Edit::override($binding, 'com', new Intent(sell: true, longest: 3, transfers: true), [...PricingRule::DEFAULT, 'markup' => '70.00']), $rev());
$before = $counts(); $checkout($page, $cart); $html = $page->html();
same($before, $counts(), 'Themed price change places no order');
expect(!collect($page->effects['dispatches'] ?? [])->contains('name', 'notify'), 'A theme that shows the notice gets no toast too');
$dom = storefrontDom($html); $box = $dom->query('//*[@data-domains-cart-notice]//*[@role="status"]');
same(1, $box->length, 'One cart notice');
same('Your cart changed', trim($dom->query('.//strong', $box->item(0))->item(0)?->textContent ?? ''), 'Notice has its title');
same('Review the total, then check out again.', trim($dom->query('.//p', $box->item(0))->item(0)?->textContent ?? ''), 'Notice keeps the sentence');
same('Was $27.00', trim($dom->query('//*[@class="line"]/s')->item(0)?->textContent ?? ''), 'Changed line strikes the old price');
expect($total($page) !== '27.00' && str_contains($dom->query('//*[@class="line"]')->item(0)->textContent, '$'.$total($page)), 'Line shows the new price beside it');

// With a coupon, the struck amount is the discounted line the buyer saw, not the list price.
$coupon = App\Models\Coupon::create(['code' => 'NOTICETEN', 'type' => 'percentage', 'value' => 10, 'applies_to' => 'price', 'recurring' => 0]);
$cart = commerceCart($user); commerceAdd($cart, $com, 'coupon-notice-placeholder.com', 3); $cart->update(['coupon_id' => $coupon->id]);
$page = $open($cart); $seen = $total($page);
expect(str_contains(storefrontDom($page->html())->query('//*[@class="line"]')->item(0)->textContent, '$'.$seen), 'Coupon line shows the discounted amount');
$ports->apply(Edit::override($binding, 'com', new Intent(sell: true, longest: 3, transfers: true), [...PricingRule::DEFAULT, 'markup' => '50.00']), $rev());
$checkout($page, $cart); $dom = storefrontDom($page->html());
same('Your cart changed', trim($dom->query('//*[@data-domains-cart-notice]//strong')->item(0)?->textContent ?? ''), 'Coupon price change shows the notice');
same('Was $'.$seen, trim($dom->query('//*[@class="line"]/s')->item(0)?->textContent ?? ''), 'Coupon line strikes the discounted amount it showed');

// Removing the last line leaves an empty cart that still shows the titled notice, not a toast.
$cart = commerceCart($user); $last = commerceAdd($cart, $com, 'last-line-placeholder.com', 3);
$page = $open($cart);
$ports->apply(Edit::override($binding, 'com', new Intent(sell: false, longest: 3, transfers: true)), $rev());
$checkout($page, $cart); $dom = storefrontDom($page->html());
expect(!$cart->items()->whereKey($last->id)->exists() && $dom->query('//*[@class="empty"]')->length === 1, 'The last line goes and the cart is empty');
same('Domain removed', trim($dom->query('//*[@data-domains-cart-notice]//strong')->item(0)?->textContent ?? ''), 'Empty cart shows the removed notice');
expect(collect($page->effects['dispatches'] ?? [])->contains('name', 'cartUpdated'), 'Removing the last domain refreshes the header cart badge');
Illuminate\Support\Once::flush();
$badge = new App\Livewire\Components\Cart; $badge->cartCount = 1;
$badge->onCartUpdated(); same(0, $badge->cartCount, 'The native header listener reads the empty cart');
same('last-line-placeholder.com is no longer for sale, so we removed it from your cart. Search again to find another domain.',
    trim($dom->query('//*[@data-domains-cart-notice]//p')->item(0)?->textContent ?? ''), 'Removed notice keeps the sentence');
expect(!collect($page->effects['dispatches'] ?? [])->contains('name', 'notify'), 'Empty cart with the notice gets no toast too');
$nativeSuccessful = true;
echo "Cart price change: ok\n";
