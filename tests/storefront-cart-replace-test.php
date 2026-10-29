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
extract(commerceFixture(true)); $clock = new FakeClock($now); app()->instance(Clock::class, $clock);
$eligibility = new CheckoutEligibility($bindings, $clock);
$store = new Storefront($sellers, $prices, $native, $bindings, $eligibility, $clock);
$controller = new StorefrontController($store, new CartLines($native)); storefrontViews();
(new CartAdmission($native, $sellers, $eligibility, $clock))->boot();
$user = commerceUser(); $cart = commerceCart($user);
app('request')->cookies->set('cart', $cart->ulid);
$session = app('request')->session();
$request = static function (string $path, array $input = [], string $method = 'POST') use ($session, $user): Request {
    RateLimiter::clear('cart-add:user:'.$user->id);
    RateLimiter::clear('domains.search.ip:'.hash('sha256', '192.0.2.33'));
    RateLimiter::clear('domains.search.user:'.$user->id);
    Illuminate\Support\Once::flush();
    $request = Request::create($path, $method, $input, server: ['REMOTE_ADDR' => '192.0.2.33']);
    $request->setLaravelSession($session); $request->setUserResolver(static fn () => $user); return $request;
};
$order = static function (string $name, string $term = '1', array $extra = []) use ($controller, $request) {
    $key = FormKeys::issue($request('/domains/order'), 'order:'.$name);
    return $controller->order($request('/domains/order', ['domain' => $name, 'action' => 'register', 'term' => $term, '_request_key' => $key, ...$extra]));
};
$checkKey = FormKeys::issue($request('/domains/check'), 'search');
$check = static fn (string $name, array $extra = []) => $controller->check($request('/domains/check', ['domain' => $name, '_request_key' => $checkKey, ...$extra]));
$first = placeholderComFixtureName('first-placeholder.com'); $second = placeholderComFixtureName('second-placeholder.com');
same(route('cart'), $order($first, '3')->getTargetUrl(), 'A 3-year line goes into the cart');
$line = $cart->items()->sole();

// A name already in the cart reads "In your cart" with a way there, and never asks the registrar.
$calls = $reads->calls;
$result = $check($first)->getData(true);
same('in_cart', $result['status'], 'A name in the cart has its own state');
$dom = storefrontDom($result['html']);
same('In your cart', trim($dom->query('//*[contains(@class,"domains-result__status")]')->item(0)->textContent), 'Badge says In your cart');
same(1, $dom->query('//a[@href="'.route('cart').'"][normalize-space()="Go to cart"]')->length, 'Row links to the cart');
same(0, $dom->query('//form')->length, 'No Register form for a name already in the cart');
same($calls, $reads->calls, 'In-cart state is decided before any registrar read');
same('available', $check($second)->getData(true)['status'], 'Other names still check normally');

// Change on Review opens search in replace mode: a notice with Cancel, and the mode survives a new search.
$html = $controller->search($request('/domains/register', ['name' => $first, 'replace' => (string) $line->id], 'GET'))->render();
$dom = storefrontDom($html);
same('Replacing '.$first.'.', trim($dom->query('//*[@data-domains-replacing]')->item(0)?->firstChild?->textContent ?? ''), 'Replace notice names the line');
same(1, $dom->query('//*[@data-domains-replacing]//a[@href="'.route('cart').'"][normalize-space()="Cancel"]')->length, 'Cancel goes back to the cart');
same(1, $dom->query('//form[@role="search"]//input[@type="hidden"][@name="replace"][@value="'.$line->id.'"]')->length, 'A new search stays in replace mode');
same((string) $line->id, $dom->query('//*[@data-domains-results]')->item(0)?->getAttribute('data-replace'), 'Checks carry the line being replaced');
foreach (['999999', 'x', ''] as $bogus) {
    $html = $controller->search($request('/domains/register', ['name' => $first, 'replace' => $bogus], 'GET'))->render();
    expect(!str_contains($html, 'data-domains-replacing') && !str_contains($html, 'name="replace"'), 'Unknown line is plain search: '.$bogus);
}

// In replace mode an available name offers "Use this name" with the line's term picked.
$dom = storefrontDom($check($second, ['replace' => (string) $line->id])->getData(true)['html']);
same(1, $dom->query('//form//button[normalize-space()="Use this name"]')->length, 'Replace mode says Use this name');
same(1, $dom->query('//form//input[@type="hidden"][@name="replace"][@value="'.$line->id.'"]')->length, 'The form names the line');
same('3', $dom->query('//form//select[@name="term"]/option[@selected]')->item(0)?->getAttribute('value'), 'The line keeps its 3-year term');
// A replace page whose line left the cart (another tab) stays in replace mode, so the order is refused below.
$dom = storefrontDom($check($second, ['replace' => '999999'])->getData(true)['html']);
same(1, $dom->query('//form//input[@type="hidden"][@name="replace"][@value="999999"]')->length, 'A line that left the cart still replaces, never adds');
$dom = storefrontDom($check($second, ['replace' => 'x'])->getData(true)['html']);
same(1, $dom->query('//form//button[normalize-space()="Register"]')->length, 'A junk replace value is plain Register');

// Picking it swaps the line in place: same line, new name, same term. The old name leaves the cart.
same(route('cart'), $order($second, '3', ['replace' => (string) $line->id])->getTargetUrl(), 'Use this name returns to the cart');
$swapped = $cart->items()->sole();
same([$line->id, $second, 3], [$swapped->id, $swapped->checkout_config['domain'], (int) $swapped->plan->billing_period], 'The line was replaced in place');
same('in_cart', $check($second)->getData(true)['status'], 'The new name is in the cart');
same('available', $check($first)->getData(true)['status'], 'The old name left the cart');
// A line that is not this cart's plugin line is never replaced, and nothing is written.
$hosting = commerceHosting();
$other = $cart->items()->create(['product_id' => $hosting->id, 'plan_id' => $hosting->plans()->firstOrFail()->id, 'quantity' => 1, 'config_options' => [], 'checkout_config' => []]);
foreach (['999999', (string) $other->id, 'x'] as $bogus) {
    $response = $order($first, '1', ['replace' => $bogus]);
    same(route('domains.domains.search', ['name' => $first]), $response->getTargetUrl(), 'Unknown line returns to search: '.$bogus);
    same('That cart line changed. Check your cart and try again.', $session->get('domains_notice'), 'Unknown line says so');
    same([$second], $cart->items()->get()->pluck('checkout_config.domain')->filter()->values()->all(), 'Unknown line wrote nothing: '.$bogus);
}
echo "Storefront in-cart and replace checks passed.\n";
$nativeSuccessful = true;
