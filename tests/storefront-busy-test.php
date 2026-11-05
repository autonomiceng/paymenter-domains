<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real account leases, offline registrar wire.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/storefront.php';
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Domains\Contracts\ReadLimiter;
use Paymenter\Extensions\Servers\Domains\Data\RequestOrigin;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\StorefrontController;
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AccountReadBudget, Runtime, Storefront};
extract(commerceFixture(true));
$eligibility = activeFixtureEligibility($bindings, $clock);
$store = new class($sellers, $prices, $native, $bindings, $eligibility, $clock) extends Storefront {
    public Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers $fixtureOffers;
    protected function catalogue(): ?Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers { return $this->fixtureOffers; }
    public ?Closure $onWait = null;
    public int $waits = 0;
    protected function waitForRead(): void { $this->waits++; if ($this->onWait) { ($this->onWait)(); } }
};
$store->fixtureOffers = activeFixtureOffers($sellers);
$budget = new AccountReadBudget($clock);
app()->instance(Runtime::class, new Runtime);
app()->instance(ReadLimiter::class, $budget);
$b = AccountBindings::binding(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id', $tlds[0]->server_id)->sole());
// The synthetic driver uses the real limiter before its single synthetic exchange.
$wire = 0;
$registry = (new ReflectionProperty($bindings, 'registry'))->getValue($bindings);
// AccountBindings obtains a fresh reader per check; its factory wraps the real lease boundary.
$registry->registerBoundReader($b, static function ($binding, $credentials) use (&$wire) {
    $driver = new Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar($binding,
        new Paymenter\Extensions\Servers\Domains\Data\Capabilities($binding, []),
        new Paymenter\Extensions\Servers\Domains\Data\DriverResult($binding,
            Paymenter\Extensions\Servers\Domains\Data\Disposition::Accepted,
            Paymenter\Extensions\Servers\Domains\Data\SendState::Sent,
            Paymenter\Extensions\Servers\Domains\Data\ResultReason::Acknowledged,
            new Paymenter\Extensions\Servers\Domains\Data\Evidence(premium: false, available: true)));
    $driver->beforeRead = static function ($method, $ctx) use (&$wire) {
        $ctx->withinClaimedWire($ctx->binding, static function () use (&$wire) { $wire++; });
    };
    return $driver;
});
$lease = $budget->acquire($b, RequestOrigin::Background);
$store->onWait = static fn () => $budget->release($b, RequestOrigin::Background, $lease);
try { $result = $store->check('busy-placeholder.com'); }
catch (Throwable $e) { throw new RuntimeException('A brief busy slot must recover instead of failing the customer search', 0, $e); }
same('available', $result['status'], 'Search succeeds after the worker releases its slot');
same(1, $store->waits, 'One bounded wait'); same(1, $wire, 'Only one admitted exchange');
$store->onWait = null; $store->waits = 0;
$lease = $budget->acquire($b, RequestOrigin::Background);
storefrontViews(); $controller = new StorefrontController($store);
foreach (['register', 'transfer'] as $entry) {
    $r = Request::create('/domains/check', 'POST', ['domain' => $entry.'-busy-placeholder.com']);
    $r->setLaravelSession(app('request')->session());
    $r->request->set('_request_key', FormKeys::issue($r, 'search'));
    $response = $controller->check($r); $data = $response->getData(true);
    same(200, $response->getStatusCode(), 'Busy '.$entry.' check stays on the page');
    same('busy', $data['status'], 'Busy is distinct from a failed provider check');
    expect(str_contains($data['html'], 'Try again in a moment'), 'Plain next step for '.$entry);
    expect(!str_contains($data['html'], 'Couldn’t check'), 'Busy is not reported as a failed check');
    expect(str_contains($data['html'], 'data-domains-retry'), 'Customer can retry '.$entry);
}
same(2, $store->waits, 'Each check retries at most once'); same(1, $wire, 'Held slot admits no sends');
$budget->release($b, RequestOrigin::Background, $lease);
$nativeSuccessful = true; echo "Storefront busy-slot recovery and plain retry results passed.\n";
