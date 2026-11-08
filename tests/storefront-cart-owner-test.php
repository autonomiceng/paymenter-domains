<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Names already in the cart, and replacing a cart line from search.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/storefront.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\StorefrontController;
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission, CheckoutEligibility, CartLines, Storefront};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
require dirname(__DIR__).'/Domains/routes/web.php';
extract(commerceFixture(true)); $clock ??= new FakeClock($now); app()->instance(Clock::class, $clock);
$eligibility = activeFixtureEligibility($bindings, $clock);
$store = activeFixtureStorefront($sellers, $prices, $native, $bindings, $eligibility, $clock);
$controller = new StorefrontController($store, new CartLines($native)); storefrontViews();
(activeFixtureAdmission($native, $sellers, $eligibility, $clock))->boot();
$user=commerceUser();$cart=commerceCart($user);
app('request')->cookies->set('cart',$cart->ulid);
$session=app('request')->session();
$first=placeholderComFixtureName('retained-owner-placeholder.com');
$product=$native->product($tlds[0],'register');
commerceAdd($cart,$product,$first);
// Expired login or a different account can leave an account-owned native cart cookie behind.
// The native add helper trusts that cookie; storefront handoff must not write another owner's cart.
$original = $cart->items()->get()->map->getAttributes()->all();
foreach (['guest' => null, 'different account' => commerceUser()] as $who => $visitor) {
    Illuminate\Support\Facades\Auth::logout();
    if ($visitor) { Illuminate\Support\Facades\Auth::login($visitor); }
    Illuminate\Support\Once::flush();
    app('request')->cookies->set('cart', $cart->ulid);
    Illuminate\Support\Facades\Cookie::flushQueuedCookies();
    $newName = placeholderComFixtureName($visitor ? 'other-owner-placeholder.com' : 'guest-owner-placeholder.com');
    $req = Request::create('/domains/order', 'POST', ['domain' => $newName, 'action' => 'register', 'term' => '1'], server: ['REMOTE_ADDR' => '192.0.2.34']);
    $req->setLaravelSession($session); $req->setUserResolver(static fn () => $visitor);
    $req->merge(['_request_key' => FormKeys::issue($req, 'order:'.$newName)]);
    same(route('cart'), $controller->order($req)->getTargetUrl(), $who.' domain handoff succeeds');
    $newCookie = Illuminate\Support\Facades\Cookie::queued('cart')?->getValue();
    expect(is_string($newCookie) && $newCookie !== $cart->ulid, $who.' receives a cart for the current owner');
    $newCart = App\Models\Cart::where('ulid', $newCookie)->sole();
    same($visitor?->id, $newCart->user_id, $who.' cart belongs to the visitor');
    same($newName, $newCart->items()->sole()->checkout_config['domain'], $who.' domain is in the new cart');
    same($original, $cart->items()->get()->map->getAttributes()->all(), $who.' leaves the prior cart untouched');
}

echo "Storefront cart owner isolation checks passed.\n";
$nativeSuccessful=true;
