<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Real Livewire snapshots and native invoice writes are needed to prove price acceptance and rollback.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
use App\Models\{CartItem, Coupon, Invoice, Order, Service};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission, CheckoutEligibility, RetailLine};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
extract(commerceFixture(true)); $clock = new FakeClock($now);
$diverge = false;
Illuminate\Support\Facades\Event::listen(App\Events\Order\Creating::class, static function () use (&$diverge): void {
    if ($diverge) { App\Classes\Cart::get()->items->first()->plan->prices->first()->price = '1.00'; }
});
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
$stock = $hosting->fresh()->stock;
// Keep the same mounted cart while signup/email verification outlasts the receipt.
$checkout = Livewire\Livewire::actingAs($user)->withCookies(['cart' => $cart->ulid])->test(App\Livewire\Cart::class)->set('use_credits', false);
$beforeReads = $reads->calls; $clock->instant = $now->modify('+6 minutes');
Illuminate\Support\Once::flush(); $checkout->call('checkout');
same(3, Order::count(), 'Expired eligibility refreshes without sending the customer back');
same($beforeReads + 1, $reads->calls, 'Expired check costs one unlocked registrar read');
same($stock - 1, $hosting->fresh()->stock, 'Mixed checkout continues normally');
$refreshed = (new CheckoutEligibility($bindings, $clock))->refresh($line->checkout_config['domains_check'], $line->checkout_config['domain'], $sellers->selection('com', 'register', 1, $clock->now()), [(int)$cart->id, (int)$line->product_id, (int)$line->plan_id]);
// Two requests can still hold the expired token. Both must join the already refreshed read.
foreach ([new CheckoutEligibility($bindings, $clock), new CheckoutEligibility($bindings, $clock)] as $otherTab) {
    same($refreshed, $otherTab->refresh($line->checkout_config['domains_check'], $line->checkout_config['domain'], $sellers->selection('com', 'register', 1, $clock->now()), [(int)$cart->id, (int)$line->product_id, (int)$line->plan_id]), 'Stale tabs coalesce on the same receipt');
}
same($beforeReads + 1, $reads->calls, 'Repeated requests cost no extra registrar reads');

$clock->instant = $now; $cart = commerceCart($user); $line = commerceAdd($cart, $product, placeholderComFixtureName('seller-change-placeholder.com'));
$sellers->change('com', 2, $tlds[1]->server_id, true, true, $now); commerceCheckout($user, $cart);
same(3, Order::count(), 'Seller switch invalidates old link and receipt');
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
commerceCheckout($user, $cart); same(3, Order::count(), 'Receipt cannot be reused for another name');
$cart = commerceCart($user); commerceAdd($cart, $product, placeholderComFixtureName('free-placeholder.com')); $coupon->update(['value' => 100]); $cart->update(['coupon_id' => $coupon->id]);
commerceCheckout($user, $cart); same(3, Order::count(), 'Zero domain net rejects checkout');
// An earlier native listener simulates a price relation cached before publication.
$diverge = true;
$cart = commerceCart($user); commerceAdd($cart, $product, placeholderComFixtureName('cached-price-placeholder.com')); commerceCheckout($user, $cart);
same(3, Order::count(), 'Cached native list mismatch rolls back all checkout writes');
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
commerceCheckout($user, $guest); same(4, Order::count(), 'Guest eligibility survives native login and checkout');
// A retained cart must not silently accept a synced price, even with freshly loaded native relations.
// Real checkout is needed to verify invoice amounts and rollback, rather than just price calculations.
$cart = commerceCart($user); $line = commerceAdd($cart, $product, placeholderComFixtureName('synced-price-placeholder.com'));
$history = [DB::table('invoices')->orderBy('id')->get()->toJson(),DB::table('invoice_items')->orderBy('id')->get()->toJson()];
$services = DB::table('services')->orderBy('id')->get()->toJson();
$binding = Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$tlds[0]->server_id)->sole());
$accepted = Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::load($binding);
Illuminate\Support\Once::flush();
$checkout = Livewire\Livewire::actingAs($user)->withCookies(['cart' => $cart->ulid])->test(App\Livewire\Cart::class)->set('use_credits', false);
Illuminate\Support\Once::flush();
$otherTab = Livewire\Livewire::actingAs($user)->withCookies(['cart' => $cart->ulid])->test(App\Livewire\Cart::class)->set('use_credits', false);
foreach (['8.00','5.00'] as $cost) {
    $rates = [];
    foreach ([['register',1],['register',3],['renew',1],['transfer',1]] as [$action,$term]) {
        $rates[] = new Paymenter\Extensions\Servers\Domains\Data\CatalogueRate($action,$term,$cost,'synthetic',$now,$now->modify('+1 day'));
    }
    $prices->import(new Paymenter\Extensions\Servers\Domains\Data\CatalogueEntry($binding,'com',$rates,[1,3],true,true),$accepted,static fn()=>null,$now,null,true);
    Illuminate\Support\Once::flush(); $checkout->call('checkout');
    same(4,Order::count(),'A retained cart cannot charge an unseen synced price');
    same($history,[DB::table('invoices')->orderBy('id')->get()->toJson(),DB::table('invoice_items')->orderBy('id')->get()->toJson()],'Sync and refused checkout preserve issued invoices');
    same($services,DB::table('services')->orderBy('id')->get()->toJson(),'Sync leaves existing service prices to the renewal billing projection');
    same(number_format((float)$cost * 1.3, 2, '.', ''), $checkout->get('total')->total, 'Changed total is shown before acceptance');
    same($line->id, $cart->items()->sole()->id, 'Price changes keep the cart line in place');
    $beforeReads = $reads->calls; Illuminate\Support\Once::flush(); $otherTab->call('checkout');
    same(4, Order::count(), 'Another tab must accept its own changed offer');
    same($checkout->get('total')->total, $otherTab->get('total')->total, 'Each tab shows the changed total');
    same($beforeReads, $reads->calls, 'Other tabs reuse the same fresh price check');
}
// An unchanged-price sync advances revisions without requiring another customer check.
$beforeReads = $reads->calls;
$prices->import(new Paymenter\Extensions\Servers\Domains\Data\CatalogueEntry($binding,'com',$rates,[1,3],true,true),$accepted,static fn()=>null,$now,null,true);
Illuminate\Support\Once::flush(); $checkout->call('checkout');
same(5,Order::count(),'A refreshed cart sells at the synced price');
same($beforeReads, $reads->calls, 'Unchanged retail reuses the fresh receipt across price revisions');
same('6.50',number_format((float)Invoice::latest('id')->first()->items()->sole()->price,2,'.',''),'Customer invoice equals the refreshed native offer after a falling cost');
// Native mount must also repair an evicted receipt; subsequent clicks reuse it.
$cart = commerceCart($user); $line = commerceAdd($cart, $product, placeholderComFixtureName('missing-check-placeholder.com'));
Illuminate\Support\Facades\Cache::forget('domains.checkout.'.$line->checkout_config['domains_check']);
$beforeReads = $reads->calls; Illuminate\Support\Once::flush();
$checkout = Livewire\Livewire::actingAs($user)->withCookies(['cart' => $cart->ulid])->test(App\Livewire\Cart::class)->set('use_credits', false);
same($beforeReads + 1, $reads->calls, 'Cart load repairs missing eligibility');
// A failed refresh keeps the cart and money untouched, and repeated clicks do not burn reads.
$clock->instant = $now->modify('+6 minutes'); $reads->available = false;
Illuminate\Support\Once::flush(); $checkout->call('checkout');
same(5, Order::count(), 'Failed eligibility cannot create an order');
same($line->id, $cart->items()->sole()->id, 'Failed check keeps the cart for retry or editing');
same($beforeReads + 2, $reads->calls, 'Expired receipt tries to repair itself before refusing');
Illuminate\Support\Once::flush(); $checkout->call('checkout');
same($beforeReads + 2, $reads->calls, 'Repeated failed checks share the short backoff');
$reads->available = true;
Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::now()->addSeconds(11));
Illuminate\Support\Once::flush(); $checkout->call('checkout');
same(6, Order::count(), 'Retry after a temporary check failure completes checkout');
Illuminate\Support\Carbon::setTestNow();
same(0, DB::transactionLevel(), 'Native checkout leaves no partial transaction');
$nativeSuccessful = true; echo "Native cart batch admission, mixed invoices, stale selections and eligibility refusals passed.\n";
