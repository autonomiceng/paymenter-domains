<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Offline customer transfer results.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/storefront.php';
require __DIR__.'/Support/storefront-reader.php';
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedTransferController, StorefrontController};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Services\{Rdap, TransferPrecheck};
extract(commerceFixture(true));
require dirname(__DIR__).'/Domains/routes/web.php';
require dirname(__DIR__).'/Domains/routes/owned.php';
storefrontViews();
$session = app('request')->session();
$state = (object) ['calls' => 0, 'fail' => false];
$transferBindings = storefrontTransferReader($state);
activeFixtureOffers($transferBindings, activeFixtureOffers($bindings));
$public = (object) ['status' => 200, 'states' => ['client transfer prohibited'], 'created' => '2020-01-01T00:00:00Z', 'transferred' => null];
$rdap = new Rdap(static function ($url) use ($public): array {
    $events = [['eventAction' => 'registration', 'eventDate' => $public->created], ['eventAction' => 'expiration', 'eventDate' => '2027-01-01T00:00:00Z']];
    if ($public->transferred) { $events[] = ['eventAction' => 'transfer', 'eventDate' => $public->transferred]; }
    return ['status' => str_contains($url, 'dns.json') ? 200 : $public->status,
        'body' => json_encode(str_contains($url, 'dns.json') ? ['services' => [[['com'], ['https://rdap.example.com/']]]]
            : ['objectClassName' => 'domain', 'ldhName' => 'transfer-placeholder.com', 'status' => $public->states, 'events' => $events])];
});
$eligibility = activeFixtureEligibility($transferBindings, $clock, new TransferPrecheck($rdap));
$store = activeFixtureStorefront($sellers, $prices, $native, $transferBindings, $eligibility, $clock);
$controller = new StorefrontController($store);
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\Storefront::class, $store);
// W14: reach the real HTTP route as a guest, before spending any read budget.
\Illuminate\Support\Facades\Auth::logout();
$before = $state->calls;
try {
    $pageResponse = app('router')->dispatch(Request::create('/domains/transfer?name=transfer-placeholder.com', 'GET'));
    $pageStatus = $pageResponse->getStatusCode();
} catch (\Illuminate\Auth\AuthenticationException) { $pageStatus = 401; }
same(200, $pageStatus, 'Guest transfer page opens before login (actual '.$pageStatus.')');
expect(str_contains($pageResponse->getContent(), 'data-mode="transfer"'), 'Guest page offers the existing transfer check');
same($before, $state->calls, 'Guest transfer page does not read the registrar');
$session = app('session')->driver();
$server = App\Models\Server::findOrFail($tlds[0]->server_id);
$setting = $server->settings()->create(['key' => 'domains_transfer_endings', 'value' => 'com']);
$req = static function (array $input = [], string $method = 'POST') use ($session): Request {
    $r = Request::create('/domains/check', $method, $input, server: ['REMOTE_ADDR' => '192.0.2.77']);
    $r->setLaravelSession($session); return $r;
};
$key = FormKeys::issue($req(), 'search');
$check = static fn (bool $transfer = true) => $controller->check($req(['domain' => 'transfer-placeholder.com', '_request_key' => $key, 'mode' => $transfer ? 'transfer' : 'register']))->getData(true);
$assertRefusal = static function (array $result, string $reason) : void {
    expect(str_contains($result['html'], $reason), 'Transfer result explains: '.$reason.'; actual: '.strip_tags($result['html']));
    expect(!str_contains($result['html'], 'name="action"'), 'Refused transfer has no purchase action');
    expect(!str_contains($result['html'], '>Taken<'), 'Transfer refusal does not say Taken');
};
$assertRefusal($check(), 'Unlock this domain at your current registrar, then try again.');
same('taken', $check(false)['status'], 'Registration search keeps its Taken result');
$public->states = ['active']; $public->created = $now->modify('-30 days')->format(DATE_ATOM);
$assertRefusal($check(), 'registered less than 60 days ago');
$public->created = '2020-01-01T00:00:00Z'; $public->transferred = $now->modify('-30 days')->format(DATE_ATOM);
$assertRefusal($check(), 'transferred less than 60 days ago');
$public->transferred = null; $public->status = 404;
$assertRefusal($check(), 'isn’t registered. Register it first');
$public->status = 200; $setting->update(['value' => 'net']);
$assertRefusal($check(), 'We don’t offer transfers for this ending. Contact support');
$setting->update(['value' => 'com']);
$result = $check();
same('transferable', $result['status'], 'Eligible name offers a transfer');
expect(str_contains($result['html'], 'value="transfer"'), 'Eligible name has the Transfer action');
$public->states = ['pending transfer'];
$assertRefusal($check(), 'already in progress');
$page = (new OwnedTransferController($store))->show($req(['name' => 'transfer-placeholder.com'], 'GET'))->render();
expect(str_contains($page, 'data-mode="transfer"'), 'Transfer page requests transfer results');
// The eligible guest result uses the same neutral POST and real native admission as Register.
$public->states = ['active'];
$key = FormKeys::issue($req(), 'search');
$result = $check(); $dom = storefrontDom($result['html']);
same('transferable', $result['status'], 'Guest sees transfer eligibility and price');
same(1, $dom->query('//form[@method="POST" and @action="'.route('domains.domains.order').'"]')->length, 'Guest transfer posts to the cart');
expect(str_contains($result['html'], $store->offer('com', 'transfer', 1)['price']), 'Guest sees the current transfer price');
same(0, $dom->query('//input[@name="transfer_code" or @name="epp"]')->length, 'Guest never enters a transfer code');
(activeFixtureAdmission($native, $sellers, $eligibility, $clock))->boot();
\Illuminate\Support\Once::flush();
$post = [];
foreach ($dom->query('//form//input') as $input) { $post[$input->getAttribute('name')] = $input->getAttribute('value'); }
$post['transfer_code'] = $post['epp'] = 'synthetic-guest-code';
$before = $state->calls;
$response = $controller->order($req($post));
same(route('cart'), $response->getTargetUrl(), 'Guest transfer is added to native cart');
same($before + 1, $state->calls, 'Cart admission keeps its one existing transfer proof read');
$cart = App\Models\Cart::whereNull('user_id')->sole(); $item = $cart->items()->sole();
same('transfer-placeholder.com', $item->checkout_config['domain'], 'Native guest cart keeps the selected domain');
same('transfer', $native->mapping($item->product, $item->plan)['action'], 'Guest cart line is a transfer');
same(1, $item->plan->billing_period, 'Transfer remains one year');
same(['domain', 'domains_check'], array_keys($item->checkout_config), 'Only domain and eligibility receipt are stored');
expect(!str_contains(json_encode($session->all()), 'synthetic-guest-code'), 'Guest code is not stored in the session');
$before = $state->calls;
app('request')->cookies->set('cart', $cart->ulid); \Illuminate\Support\Once::flush();
same('in_cart', $check()['status'], 'Guest sees the existing cart line instead of a duplicate action');
same($before, $state->calls, 'Already-in-cart result makes no registrar reads');
$ipKey = 'domains.search.ip:'.hash('sha256', '192.0.2.77');
for ($i = 0; $i < 30; $i++) { \Illuminate\Support\Facades\RateLimiter::hit($ipKey, 60); }
$limited = $controller->check($req(['domain' => 'transfer-placeholder.com', '_request_key' => $key, 'mode' => 'transfer']));
same(429, $limited->getStatusCode(), 'Guest transfer checks keep the shared IP limit');
same($before, $state->calls, 'Throttled guest makes no registrar reads');
foreach (['domains.domains.code', 'domains.domains.code-save', 'domains.domains.show'] as $route) {
    expect(in_array('auth', \Illuminate\Support\Facades\Route::getRoutes()->getByName($route)->gatherMiddleware(), true), 'Owned domain/code routes still require login');
}
$nativeSuccessful = true;
echo "Customer transfer reasons, next steps and registration isolation passed.\n";
