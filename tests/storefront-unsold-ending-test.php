<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real account leases, offline registrar wire.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/storefront.php';
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\StorefrontController;
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AccountReadBudget, Runtime, Storefront};
require dirname(__DIR__).'/Domains/routes/web.php';
extract(commerceFixture(true));
$eligibility = activeFixtureEligibility($bindings, $clock);
$store = new class($sellers, $prices, $native, $bindings, $eligibility, $clock) extends Storefront {
    public Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers $fixtureOffers;
    protected function catalogue(): ?Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers { return $this->fixtureOffers; }
    protected function waitForRead(): void {}
};
$store->fixtureOffers = activeFixtureOffers($sellers);
app()->instance(Runtime::class, new Runtime);
app()->instance(ReadLimiter::class, new AccountReadBudget($clock));
$b = AccountBindings::binding(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id', $tlds[0]->server_id)->sole());
$seconds = 0;
$registry = (new ReflectionProperty($bindings, 'registry'))->getValue($bindings);
$registry->registerBoundReader($b, static function ($binding, $credentials) use (&$seconds, $clock) {
    $driver = new Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar($binding,
        new Paymenter\Extensions\Servers\Domains\Data\Capabilities($binding, []),
        new Paymenter\Extensions\Servers\Domains\Data\DriverResult($binding,
            Paymenter\Extensions\Servers\Domains\Data\Disposition::Accepted,
            Paymenter\Extensions\Servers\Domains\Data\SendState::Sent,
            Paymenter\Extensions\Servers\Domains\Data\ResultReason::Acknowledged,
            new Paymenter\Extensions\Servers\Domains\Data\Evidence(premium: false, available: true)));
    $driver->beforeRead = static function ($method, $ctx) use (&$seconds, $clock) {
        $ctx->withinClaimedWire($ctx->binding, static function () use (&$seconds, $clock) { $clock->instant = $clock->instant->modify('+'.$seconds.' seconds'); });
    };
    return $driver;
});
storefrontViews(); $controller = new StorefrontController($store);
// The ending asked for is not sold here: the page names the other endings, and each row's button names its own name.
$seconds = 0;
$page = $controller->search(tap(Request::create('/domains/register', 'GET', ['name' => 'swap-placeholder', 'tld' => 'unsold']), fn ($q) => $q->setLaravelSession(app('request')->session())));
expect($page->getData()['notice'] !== null && str_contains($page->render(), 'data-instead="1"'), 'An unsold ending marks every row as another name');
$r = Request::create('/domains/check', 'POST', ['domain' => 'swap-placeholder.com', 'instead' => 1]);
$r->setLaravelSession(app('request')->session());
$r->request->set('_request_key', FormKeys::issue($r, 'search'));
$data = $controller->check($r)->getData(true);
same('available', $data['status'], 'The other ending is checked as usual');
$dom=storefrontDom($data['html']);$button=$dom->query('//button[@type="submit"]')->item(0);$form=$dom->query('//form')->item(0);
same('Add swap-placeholder.com instead',trim($button?->textContent??''),'The button names the other name');
expect(!str_contains($button->getAttribute('class'),'domains-btn--primary'),'Another name is never the primary button');
same(route('domains.domains.order'),$form?->getAttribute('action'),'Alternative names use the cart action');
same('swap-placeholder.com',$dom->query('//input[@name="domain"]')->item(0)?->getAttribute('value'),'Alternative names submit their own domain');
// A sold ending is the requested name, without alternative mode or copy.
$page=$controller->search(tap(Request::create('/domains/register','GET',['name'=>'swap-placeholder','tld'=>'com']),fn($q)=>$q->setLaravelSession(app('request')->session())));
expect(!str_contains($page->render(),'data-instead='),'A sold ending does not enable alternative-name mode');
$r=Request::create('/domains/check','POST',['domain'=>'primary-placeholder.com']);$r->setLaravelSession(app('request')->session());
$r->request->set('_request_key',FormKeys::issue($r,'search'));$data=$controller->check($r)->getData(true);$dom=storefrontDom($data['html']);
$button=$dom->query('//button[@type="submit"]')->item(0);
same('Add to cart',trim($button?->textContent??''),'A normal check has the primary Add to cart label');
expect(str_contains($button->getAttribute('class'),'domains-btn--primary'),'The requested name has the primary action');
same(route('domains.domains.order'),$dom->query('//form')->item(0)?->getAttribute('action'),'Primary names use the cart action');
same('primary-placeholder.com',$dom->query('//input[@name="domain"]')->item(0)?->getAttribute('value'),'Primary names submit their own domain');
$nativeSuccessful = true; echo "Storefront unsold ending rows passed.\n";
