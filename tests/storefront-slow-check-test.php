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
// Each synthetic Check takes $seconds on the shared clock, as a slow registrar answer does.
$seconds = 0;
$registry = (new ReflectionProperty($bindings, 'registry'))->getValue($bindings);
$disposition = Paymenter\Extensions\Servers\Domains\Data\Disposition::Accepted;
$registry->registerBoundReader($b, static function ($binding, $credentials) use (&$seconds, &$disposition, $clock) {
    $driver = new Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar($binding,
        new Paymenter\Extensions\Servers\Domains\Data\Capabilities($binding, []),
        new Paymenter\Extensions\Servers\Domains\Data\DriverResult($binding,
            $disposition,
            Paymenter\Extensions\Servers\Domains\Data\SendState::Sent,
            $disposition === Paymenter\Extensions\Servers\Domains\Data\Disposition::Accepted
                ? Paymenter\Extensions\Servers\Domains\Data\ResultReason::Acknowledged : Paymenter\Extensions\Servers\Domains\Data\ResultReason::Unresolved,
            new Paymenter\Extensions\Servers\Domains\Data\Evidence(premium: false, available: true)));
    $driver->beforeRead = static function ($method, $ctx) use (&$seconds, $clock) {
        $ctx->withinClaimedWire($ctx->binding, static function () use (&$seconds, $clock) { $clock->instant = $clock->instant->modify('+'.$seconds.' seconds'); });
    };
    return $driver;
});
// The demo's Enom TEST Checks that failed were cut off 10 seconds after sending; good ones took 3 to 4 seconds.
$seconds = 12;
try { $status = $store->check('slow-placeholder.com')['status']; } catch (Throwable $e) { $status = 'refused: '.$e->getMessage(); }
same('available', $status, 'A Check that answers after 12 seconds still finds the name available');
// A Check cut off by the deadline comes back unknown from the transport; the row says why and what to do next.
$seconds = 0; $disposition = Paymenter\Extensions\Servers\Domains\Data\Disposition::Unknown;
config(['settings.tickets_disabled' => true, 'domains.support_url' => 'https://support.example.test/help']);
storefrontViews(); $controller = new StorefrontController($store);
$r = Request::create('/domains/check', 'POST', ['domain' => 'late-placeholder.com']);
$r->setLaravelSession(app('request')->session());
$r->request->set('_request_key', FormKeys::issue($r, 'search'));
$data = $controller->check($r)->getData(true);
same('error', $data['status'], 'A late answer is a failed check');
expect(str_contains($data['html'], 'Couldn’t check'), 'The row says it could not check');
expect(str_contains($data['html'], 'We couldn’t get an answer from the registrar for this name. Retry in a minute.'), 'The row gives the reason and the next step');
expect(str_contains($data['html'], 'https://support.example.test/help'), 'The row links to support');
expect(str_contains($data['html'], 'data-domains-retry'), 'The row keeps Retry');
// When the check request itself fails, the page gives the same reason and support link.
$script = file_get_contents(dirname(__DIR__).'/Domains/resources/views/partials/client-script.blade.php');
expect(str_contains($script, ": 'We couldn’t check this name right now. Retry in a minute.'"), 'A failed request names the next step');
expect(str_contains(file_get_contents(dirname(__DIR__).'/Domains/resources/views/search.blade.php'), 'data-support-url='), 'The search list carries the support link');
$nativeSuccessful = true; echo "Storefront slow registrar Check passed.\n";
