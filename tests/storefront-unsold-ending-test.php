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
expect(str_contains($data['html'], '>Add swap-placeholder.com instead</button>'), 'The button names the other name');
expect(!str_contains($data['html'], 'domains-btn--primary'), 'Another name is never the primary button');
expect(str_contains(file_get_contents(dirname(__DIR__).'/Domains/resources/views/partials/client-script.blade.php'), '{instead: 1}'), 'The page asks for those rows');
$nativeSuccessful = true; echo "Storefront unsold ending rows passed.\n";
