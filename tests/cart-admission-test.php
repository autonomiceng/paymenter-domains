<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
use App\Models\{CartItem, Coupon, Invoice, Order, Service};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission, CheckoutEligibility, RetailLine};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
extract(commerceFixture(true)); $clock = new FakeClock($now);
$adapter = new CartAdmission($native, $sellers, new CheckoutEligibility($bindings, $clock), $clock); $adapter->boot(); $adapter->boot();
$user = commerceUser(); $cart = commerceCart($user); $product = $native->product($tlds[0], 'register');
same('domain', $adapter->fields($product)[0]['name'], 'Neutral native domain checkout field');
$line = commerceAdd($cart, $product, ' First-placeholder.COM. ', 3);
same(['domain', 'domains_check'], array_keys($line->checkout_config), 'Only canonical name and opaque receipt retained');
same('first-placeholder.com', $line->checkout_config['domain'], 'Canonical cart name');
commerceReject(static fn () => commerceAdd($cart, $product, placeholderComFixtureName('first-placeholder.com')));
commerceAdd($cart, $product, placeholderComFixtureName('second-placeholder.com')); $hosting = commerceHosting(); commerceAdd($cart, $hosting, 'hosting');
$beforeReads = $reads->calls;
commerceCheckout($user, $cart);
same(1, Order::count(), 'Real native checkout admitted a mixed order');
same(1, Invoice::count(), 'Hosting and distinct domains share one invoice');
same(3, Service::count(), 'Separated domain products preserve hosting semantics');
same($beforeReads, $reads->calls, 'No provider reads during locked checkout');
same(9, $hosting->fresh()->stock, 'Native hosting stock behavior retained');
// Native coupon values are already removed from total. Positive below-cost discounts stay eligible.
$cart = commerceCart($user); commerceAdd($cart, $product, placeholderComFixtureName('discount-placeholder.com'));
$coupon = Coupon::create(['code' => 'HALF', 'type' => 'percentage', 'value' => 50, 'applies_to' => 'price']);
$cart->update(['coupon_id' => $coupon->id]); commerceCheckout($user, $cart);
same(2, Order::count(), 'Positive discounted below-cost domain accepted');
same('3.90', number_format((float) Invoice::latest('id')->first()->items()->sole()->price, 2, '.', ''), 'Native discount applied exactly once');
$cart = commerceCart($user); $line = commerceAdd($cart, $product, placeholderComFixtureName('stale-placeholder.com')); commerceAdd($cart, $hosting, 'hosting');
$stock = $hosting->fresh()->stock; $clock->instant = $now->modify('+6 minutes'); commerceCheckout($user, $cart);
same(2, Order::count(), 'Expired eligibility rejects entire checkout'); same($stock, $hosting->fresh()->stock, 'Native stock rolled back');
$clock->instant = $now; $line->save();
$sellers->change('com', 2, $tlds[1]->server_id, true, true, $now); commerceCheckout($user, $cart);
same(2, Order::count(), 'Seller switch invalidates old link and receipt');
commerceReject(static fn () => commerceAdd(commerceCart($user), $product, placeholderComFixtureName('old-link-placeholder.com')));
$sellers->change('com', 3, $tlds[0]->server_id, true, true, $now);
$cart = commerceCart($user); $line = commerceAdd($cart, $product, placeholderComFixtureName('quantity-placeholder.com'));
foreach ([['quantity' => 2], ['quantity' => 1.5], ['checkout_config' => ['domain' => ['bad']]], ['config_options' => [['option_id' => 1]]]] as $bad) {
    commerceReject(static function () use ($line, $bad): void { $copy = $line->fresh(); $copy->fill($bad)->save(); });
}
$reads->premium = null; commerceReject(static fn () => commerceAdd(commerceCart($user), $product, placeholderComFixtureName('unknown-premium-placeholder.com')));
$reads->premium = true; commerceReject(static fn () => commerceAdd(commerceCart($user), $product, placeholderComFixtureName('premium-placeholder.com')));
$reads->premium = false; $reads->available = false; commerceReject(static fn () => commerceAdd(commerceCart($user), $product, placeholderComFixtureName('taken-placeholder.com'))); $reads->available = true;
commerceReject(static fn () => commerceAdd(commerceCart($user), $native->product($tlds[0], 'transfer'), 'transfer-placeholder.com'));
// Tampering persisted cart data cannot manufacture a trusted receipt or locked admission.
$cart = commerceCart($user); $line = commerceAdd($cart, $product, placeholderComFixtureName('tamper-placeholder.com'));
DB::table('cart_items')->where('id', $line->id)->update(['checkout_config' => json_encode(['domain' => 'other-placeholder.com', 'domains_check' => $line->checkout_config['domains_check']])]);
commerceCheckout($user, $cart); same(2, Order::count(), 'Receipt cannot be reused for another name');
$cart = commerceCart($user); commerceAdd($cart, $product, placeholderComFixtureName('free-placeholder.com')); $coupon->update(['value' => 100]); $cart->update(['coupon_id' => $coupon->id]);
commerceCheckout($user, $cart); same(2, Order::count(), 'Zero domain net rejects checkout');
// An earlier native listener simulates a price relation cached before publication.
$diverge = false;
Illuminate\Support\Facades\Event::listen(App\Events\Order\Creating::class, static function () use (&$diverge): void {
    if ($diverge) { App\Classes\Cart::get()->items->first()->plan->prices->first()->price = '1.00'; }
});
// This separate adapter registers after the mutation listener; its batch must reject cached input.
$guard = new CartAdmission($native, $sellers, new CheckoutEligibility($bindings, $clock), $clock);
$guard->boot(); $diverge = true;
$cart = commerceCart($user); commerceAdd($cart, $product, placeholderComFixtureName('cached-price-placeholder.com')); commerceCheckout($user, $cart);
same(2, Order::count(), 'Cached native list mismatch rolls back all checkout writes');
$diverge = false;
$nativePrice = new App\Classes\Price(['price' => 12, 'currency' => App\Models\Currency::find('USD')]);
$nativePrice->tax = 2; $nativePrice->price = 8; $nativePrice->setDiscount(4);
same('6.00', RetailLine::native($nativePrice)['net'], 'Tax-exclusive positive net uses final native total, no double discount');
$nativePrice->price = 2; commerceReject(static fn () => RetailLine::native($nativePrice));
commerceReject(static fn () => $adapter->take(Service::firstOrFail()));
// Vanilla login attaches the guest cart; receipt authority remains cart-bound across that handoff.
Illuminate\Support\Facades\Auth::logout(); Illuminate\Support\Once::flush();
$guest = App\Models\Cart::create(['currency_code' => 'USD']); commerceAdd($guest, $product, placeholderComFixtureName('guest-login-placeholder.com'));
app('request')->cookies->set('cart', $guest->ulid); Illuminate\Support\Facades\Auth::login($user);
(new App\Listeners\UserAuthListener)->handle(new App\Events\Auth\Login($user));
same($user->id, $guest->fresh()->user_id, 'Native login adopts guest cart');
commerceCheckout($user, $guest); same(3, Order::count(), 'Guest eligibility survives native login and checkout');
same(0, DB::transactionLevel(), 'Native checkout leaves no partial transaction');
$nativeSuccessful = true; echo "Native cart batch admission, mixed invoices, stale selections and eligibility refusals passed.\n";
