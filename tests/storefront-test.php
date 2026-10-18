<?php
// Enom 444f31c search/pricing scenarios; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/storefront.php';
require __DIR__.'/Support/storefront-reader.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{RateLimiter, Route};
use Paymenter\Extensions\Servers\Domains\Http\{FormKeys};
use Paymenter\Extensions\Servers\Domains\Http\Controllers\StorefrontController;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Price};
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission, CheckoutEligibility, Rdap, Storefront, TransferPrecheck};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;

expect(!Route::has('domains.domains.search'), 'Storefront remains dormant before explicit test registration');
require dirname(__DIR__).'/Domains/routes/web.php';
extract(commerceFixture(true)); $clock = new FakeClock($now);
$eligibility = new CheckoutEligibility($bindings, $clock, new TransferPrecheck(new Rdap(static fn () => ['status' => 503, 'body' => ''])));
$store = new Storefront($sellers, $prices, $native, $bindings, $eligibility, $clock);
$controller = new StorefrontController($store); app()->instance(Storefront::class, $store);
storefrontViews(); $session = app('request')->session();
$req = static function (array $input = [], string $method = 'POST', ?App\Models\User $user = null, string $ip = '192.0.2.32') use ($session): Request {
    $r = Request::create('/domains/check', $method, $input, server: ['REMOTE_ADDR' => $ip]);
    $r->setLaravelSession($session); $r->setUserResolver(static fn () => $user); return $r;
};
$key = FormKeys::issue($req(), 'search');
$check = static fn ($name) => $controller->check($req(['domain' => $name, '_request_key' => $key]));
same(['com'], array_column($store->endings(), 'tld'), 'Only selected seller ending is published');
same('$7.80', $store->endings()[0]['register'], 'Selected Enom retail, not cost or other account');
Illuminate\Support\Facades\DB::enableQueryLog(); Illuminate\Support\Facades\DB::flushQueryLog();
$store->endings();
expect(count(Illuminate\Support\Facades\DB::getQueryLog()) <= 15, 'Warm public projection has constant bounded queries');
Illuminate\Support\Facades\DB::disableQueryLog();
$displayRows = $store->endings(); Illuminate\Support\Facades\Cache::flush();
$slowRows = (new Paymenter\Extensions\Servers\Domains\Services\StorefrontCatalogueCache($clock))->remember(function () use ($clock, $now, $displayRows) {
    $clock->instant = $now->modify('+6 seconds'); return $displayRows;
});
same($displayRows, $slowRows, 'Slow public projection returns rows uncached instead of falsely closing'); $clock->instant = $now;
$before = $reads->calls; $bindings->setProcessing($tlds[0]->server_id, false);
same([], $store->endings(), 'Processing pause invalidates cached catalogue');
same('held', $store->check('paused.com')['status'], 'Engine processing guard already protects selection');
same($before, $reads->calls, 'Paused seller never reads availability'); $bindings->setProcessing($tlds[0]->server_id, true);
$product = $native->product($tlds[0], 'register'); $product->stock = 0; $product->save();
same(null, $store->endings()[0]['register'], 'Native stock withdrawal invalidates cached registration');
$product->stock = null; $product->save();
same('$7.80', $store->endings()[0]['register'], 'Native stock restoration is visible');
$tlds[1]->fill(['use_default_rule' => false, 'pricing_mode' => 'fixed', 'fixed_register' => '9.00', 'fixed_renew' => '11.00', 'fixed_transfer' => '10.00'])->save();
$prices->republish($tlds[1]); $native->publish($tlds[1]->id, $now);
$sellers->change('com', 2, $tlds[1]->server_id, true, true, $now);
same('$9.00', $store->endings()[0]['register'], 'Switched selected seller table');
$sellers->change('com', 3, $tlds[0]->server_id, true, true, $now);
$result = $check('EXAMPLE.COM.')->getData(true);
same('available', $result['status'], 'Canonical ordinary explicit false premium gets offer');
expect(str_contains($result['html'], 'name="action" value="register"'), 'Offer is a neutral CSRF/form-key POST');
foreach (['enom', 'namesilo', 'fingerprint', 'wholesale', 'server_id', 'account-'] as $secret) {
    expect(!str_contains(strtolower(json_encode($result)), $secret), 'No seller/financial identity in result: '.$secret);
}
foreach ([[true, true, 'premium'], [null, true, 'error'], [false, null, 'error'], [false, false, 'taken']] as [$premium, $available, $state]) {
    $reads->premium = $premium; $reads->available = $available;
    same($state, $check('example.com')->getData(true)['status'], 'Premium/unavailable/unknown retained');
}
$reads->premium = false; $reads->available = true;
foreach (['xn--example.com', 'éxample.com', str_repeat('a', 64).'.com', 'sub.example.com', '<script>.com', ['com']] as $invalid) {
    same(422, $check($invalid)->getStatusCode(), 'Unsupported/IDN/long/malformed input has neutral error');
}
same('available', $check(str_repeat('a', 63).'.com')->getData(true)['status'], '63-char label works');
foreach (['https://www.example.com/path', 'www.example.com', 'example.unoffered'] as $raw) {
    $data = $controller->search($req(['name' => $raw], 'GET'))->getData();
    same('example', $data['sld'], 'Approved pasted-name parsing'); expect(count($data['results']) === 1, 'Safe alternative ending');
}
same('The name can be at most 63 characters.', StorefrontController::parseName(str_repeat('a', 64))['error'], 'Approved long-label copy');
expect($controller->search($req([], 'GET'))->getData()['testEnvironment'] === true, 'Test mode flag without seller identity');
$transferState = (object) ['calls' => 0, 'fail' => false]; $transferBindings = storefrontTransferReader($transferState);
$transferEligibility = new CheckoutEligibility($transferBindings, $clock, new TransferPrecheck(new Rdap(static fn () => ['status' => 503, 'body' => ''])));
$transferController = new StorefrontController(new Storefront($sellers, $prices, $native, $transferBindings, $transferEligibility, $clock));
App\Models\Server::findOrFail($tlds[0]->server_id)->settings()->create(['key' => 'domains_transfer_endings', 'value' => 'com']);
$transfer = $transferController->check($req(['domain' => 'transfer.com', '_request_key' => $key]))->getData(true);
same('transferable', $transfer['status'], 'Controller requires independent ordinary transfer proof');
same(2, $transferState->calls, 'One register plus one transfer synthetic read');
expect(str_contains($transfer['html'], 'value="transfer"'), 'Verified transfer action offered');
$transferState->fail = true;
$failure = $transferController->check($req(['domain' => 'transfer.com', '_request_key' => $key]));
same(503, $failure->getStatusCode(), 'Provider exception becomes neutral failure');
same(['status' => 'error', 'message' => 'Couldn’t check this name.'], $failure->getData(true), 'No captured vendor/account exception detail');
// An expired session lock cannot replay an atomic one-use claim, even with the old session snapshot.
$intent = $req(); $intentKey = FormKeys::issue($intent, 'order:replay.com');
$stale = $session->get('domains.form_keys'); $intent->merge(['_request_key' => $intentKey]);
FormKeys::consume($intent, 'order:replay.com'); $session->put('domains.form_keys', $stale);
try { FormKeys::consume($intent, 'order:replay.com'); expect(false, 'Concurrent stale session must not replay'); }
catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { same(419, $e->getStatusCode(), 'Atomic claim remains after simulated slow read'); }
// Actual reserved holding: strangers, owners and guests get the exact same result and no read.
$user = commerceUser(); $other = commerceUser(); $product = $native->product($tlds[0], 'register');
$order = App\Models\Order::create(['user_id' => $user->id, 'currency_code' => 'USD', 'status' => 'pending']);
$service = App\Models\Service::create(['order_id' => $order->id, 'user_id' => $user->id, 'product_id' => $product->id,
    'plan_id' => $product->plans()->where('billing_period', 1)->sole()->id, 'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'status' => 'pending']);
$domain = Domain::create(['domain' => 'private.com', 'sld' => 'private', 'tld' => 'com', 'reservation_name' => 'private.com',
    'user_id' => $user->id, 'service_id' => $service->id, 'server_id' => $tlds[0]->server_id, 'registrar_key' => 'enom',
    'account_fingerprint' => $tlds[0]->account_fingerprint, 'authority_generation' => 1, 'status' => 'needs_review']);
$before = $reads->calls; $guest = $check('private.com')->getData(true);
foreach ([$user, $other] as $actor) {
    same($guest, $controller->check($req(['domain' => 'private.com', '_request_key' => $key], user: $actor))->getData(true), 'Cross-owner/held record privacy');
}
same($before, $reads->calls, 'Reservations never trigger provider lookup or disclose holding state');
// Actual native cart hook is explicitly booted only by this fixture.
$admission = new CartAdmission($native, $sellers, $eligibility, $clock); $admission->boot();
$cart = commerceCart($user); $orderKey = FormKeys::issue($req(), 'order:purchase.com');
$response = $controller->order($req(['domain' => 'purchase.com', 'action' => 'register', 'term' => '3', '_request_key' => $orderKey], user: $user));
same(route('cart'), $response->getTargetUrl(), 'Neutral handoff reaches native cart');
$cart = App\Models\Cart::where('user_id', $user->id)->latest('id')->firstOrFail();
$item = $cart->items()->sole(); same('purchase.com', $item->checkout_config['domain'], 'Native admitted name');
expect(isset($item->checkout_config['domains_check']), 'Native eligibility receipt retained');
same(3, $item->plan->billing_period, 'Only independently offered exact term selected');
try { $controller->order($req(['domain' => 'purchase.com', '_request_key' => $orderKey], user: $user)); expect(false, 'Order replay refused'); }
catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { same(419, $e->getStatusCode(), 'Order replay requires new intent'); }
$reads->premium = null; $failKey = FormKeys::issue($req(), 'order:unknown.com');
$response = $controller->order($req(['domain' => 'unknown.com', 'action' => 'register', 'term' => '1', '_request_key' => $failKey], user: $user));
same(302, $response->getStatusCode(), 'Native eligibility failure returns to search');
expect(str_contains($session->get('domains_notice'), 'Check the latest results'), 'Neutral native add refusal has recovery');
same(1, $cart->items()->count(), 'Unknown cannot add a line'); $reads->premium = false;
Illuminate\Support\Facades\Auth::logout(); Illuminate\Support\Once::flush();
$guestKey = FormKeys::issue($req(), 'order:guest.com');
$response = $controller->order($req(['domain' => 'guest.com', 'action' => 'register', 'term' => '1', '_request_key' => $guestKey], ip: '192.0.2.33'));
same(route('cart'), $response->getTargetUrl(), 'Guest reaches native cart before authentication');
$guestCart = App\Models\Cart::whereNull('user_id')->latest('id')->firstOrFail();
expect(isset($guestCart->items()->sole()->checkout_config['domains_check']), 'Guest native cart retains admission receipt');
$store->endings(); $clock->instant = Price::where('action', 'renew')->firstOrFail()->fresh_until->toDateTimeImmutable();
same([], $store->endings(), 'Display cache cannot outlive exact evidence expiry'); $clock->instant = $now;
Price::where('action', 'register')->update(['hold_reason' => 'below_cost']);
Price::where('action', 'transfer')->update(['hold_reason' => 'below_cost']);
same('held', $store->check('held.com')['status'], 'Engine held terms have no offer');
same([], $store->endings(), 'No held retail published');
$clock->instant = $now->modify('+2 days'); same([], $store->endings(), 'Stale prices never published'); $clock->instant = $now;
// Explicit boundary protection, middleware and limiter short circuit.
try { $controller->check($req(['domain' => 'example.com'])); expect(false, 'Missing form key refused'); }
catch (Symfony\Component\HttpKernel\Exception\HttpException $e) { same(419, $e->getStatusCode(), 'Session key required'); }
$ipKey = 'domains.search.ip:'.hash('sha256', '192.0.2.99');
for ($i = 0; $i < 30; $i++) { RateLimiter::hit($ipKey, 60); }
$before = $reads->calls;
$limited = $controller->check($req(['domain' => 'example.com', '_request_key' => $key], ip: '192.0.2.99'));
same(429, $limited->getStatusCode(), 'Per-IP rate limit'); expect($limited->headers->has('Retry-After'), 'Retry-After supplied');
same($before, $reads->calls, 'Rate-limited requests make zero reads');
for ($i = 0; $i < 60; $i++) { RateLimiter::hit('domains.search.user:'.$user->id, 60); }
same(429, $controller->check($req(['domain' => 'example.com', '_request_key' => $key], user: $user, ip: '192.0.2.100'))->getStatusCode(), 'User budget spans IPs');
$orderKey = FormKeys::issue($req(), 'order:example.com');
$limitedOrder = $req(['domain' => 'example.com', 'action' => 'register', 'term' => '1', '_request_key' => $orderKey], user: $user);
$response = $controller->order($limitedOrder);
same(302, $response->getStatusCode(), 'Limited HTML order returns to search');
same($orderKey, FormKeys::verify($limitedOrder, 'order:example.com'), 'Throttling preserves intent for retry');
expect(str_contains($response->getTargetUrl(), '/domains/register'), 'Search is the recovery path');
$orderRoute = Route::getRoutes()->getByName('domains.domains.order');
expect(!in_array('auth', $orderRoute->gatherMiddleware(), true) && in_array('web', $orderRoute->gatherMiddleware(), true), 'Guest native cart uses web CSRF, as source registration did');
expect(!Route::has('domains.domains.transfer'), 'Dedicated transfer route waits for its real owner view');
// Exercise the native HTTP boundary (CSRF is normally bypassed in the testing environment).
Illuminate\Support\Facades\Auth::logout(); app()->instance('env', 'production'); config(['app.debug' => false]);
$kernel = app(Illuminate\Contracts\Http\Kernel::class);
$csrf = $kernel->handle(Request::create('/domains/check', 'POST', ['domain' => 'example.com', '_request_key' => $key], server: ['HTTP_ACCEPT' => 'application/json']));
same(419, $csrf->getStatusCode(), 'Native HTTP rejects missing CSRF before provider read');
app()->instance('env', 'testing');
$data = storefrontData(); $html = view('domains::search', $data)->render(); $dom = storefrontDom($html);
same(7, $dom->query('//li[@data-domains-result]')->length, 'Real Blade renders every state');
same(2, $dom->query('//li[@data-domains-result]//form[@method="POST"]')->length, 'Only available/transferable states offer actions');
same(2, $dom->query('//input[@name="_token"]')->length, 'Offered forms contain native CSRF');
same(2, $dom->query('//input[@name="_request_key"]')->length, 'Offered forms contain session keys');
expect(str_contains($html, str_repeat('a', 63)), 'Real view keeps long names');
$data['results'][0]['sld'] = '<img src=x onerror=alert(1)>';
same(0, storefrontDom(view('domains::search', $data)->render())->query('//img')->length, 'Names are escaped');
$pricing = view('domains::pricing', ['rows' => $data['endings'], 'guest' => true, 'testEnvironment' => false])->render();
same(3, storefrontDom($pricing)->query('//tbody/tr')->length, 'Selected seller pricing table renders');
same(12, storefrontDom($pricing)->query('//td[@data-label]')->length, 'Narrow stacked pricing labels preserved');
foreach (['1 or 3 years', '1 to 10 years', '1 year'] as $terms) { expect(str_contains($pricing, $terms), 'Approved exact term list: '.$terms); }
same(1, $dom->query('//*[@aria-live="polite"]')->length, 'One aggregate search announcement');
same(0, $dom->query('//li[@data-domains-result]//*[@role="status"]')->length, 'No noisy per-result live regions');
$data['endings'] = []; $data['results'] = [];
expect(str_contains(view('domains::search', $data)->render(), 'Check back soon.'), 'Closed storefront view');
same('$9,999,999,999.99', Storefront::money('9999999999.99'), 'Exact large-money display');
$nativeSuccessful = true; echo "Dormant storefront native controller, DOM states, privacy and budget checks passed.\n";
