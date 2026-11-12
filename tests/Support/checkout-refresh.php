<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native Livewire/SQLite are needed to prove real pacing, receipt rollback and invoice atomicity together.
require dirname(__DIR__).'/native-app.php';
require __DIR__.'/commerce.php';
require __DIR__.'/native-commerce.php';
use Illuminate\Support\Facades\{Cache, DB, Event};
use Paymenter\Extensions\Servers\Domains\Contracts\{AccountVerifier, Clock, ReadLimiter};
use Paymenter\Extensions\Servers\Domains\Data\{Environment, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Client, Driver};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DeadlineTransport, RegistrarRequest, TransportResponse};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AccountReadBudget, CartAdmission, CheckoutEligibility, ObservationSchema, ReadDeferred, RegistrarRegistry, Runtime, SellerPolicies};
extract(commerceFixture(true,registrars:['namesilo'])); ObservationSchema::install();
$clock = new class($now) implements Clock {
    public int $offset = 0;
    private int $started;
    public function __construct(private DateTimeImmutable $base) { $this->started = $GLOBALS['checkout_fake_tick'] ?? hrtime(true); }
    public function now(): DateTimeImmutable { return $this->base->modify('+'.($this->offset + intdiv(($GLOBALS['checkout_fake_tick'] ?? hrtime(true)) - $this->started, 1000)).' microseconds'); }
    public function advance(int $seconds): void { $this->offset += $seconds * 1000000; }
};
$wire = new class($clock) implements DeadlineTransport {
    public array $calls = [];
    public array $timeouts = [];
    public int $delayMs = 0;
    public ?string $slowName = null;
    public function __construct(private Clock $clock) {}
    public function send($environment, $context, RegistrarRequest $request, string $apiKey): TransportResponse { throw new LogicException('Use deadline transport'); }
    public function sendWithin($environment, $context, RegistrarRequest $request, string $apiKey, int $timeoutMs): TransportResponse {
        same(0, DB::transactionLevel(), 'Every physical read runs outside database locks');
        same('checkRegisterAvailability', $request->command, 'Only registration checks dispatched');
        $name = $request->parameters()['domains'];
        $this->calls[] = (int)DB::table('domains_read_budgets')->value('next_api_at');
        $this->timeouts[] = $timeoutMs;
        if ($this->delayMs > 0) {
            $delay = min($this->delayMs, $timeoutMs) * 1000;
            if (isset($GLOBALS['checkout_fake_tick'])) { $GLOBALS['checkout_fake_tick'] += $delay * 1000; }
            else { usleep($delay); }
            if ($timeoutMs < $this->delayMs) { return new TransportResponse(200, '{}'); }
        }
        if ($name === $this->slowName) { $this->clock->advance(2); }
        return new TransportResponse(200, json_encode(['request' => ['operation' => $request->command], 'reply' => ['code' => 300, 'detail' => 'success', 'available' => [['domain' => $name, 'price' => 6, 'premium' => 0, 'duration' => 1]]]], JSON_THROW_ON_ERROR));
    }
};
$registry = new RegistrarRegistry;
$registry->register('namesilo', ['token'], new class implements AccountVerifier {
    public function verify(Environment $environment, array $credentials): string { return $credentials['token']; }
}, fn($b, $credentials) => new Driver(new Client($b, $credentials['token'], $wire), [], $clock),
    authenticator: new class implements \Paymenter\Extensions\Servers\Domains\Contracts\CredentialAuthenticator {
        public function authenticate(Environment $environment, array $credentials): void { /* Synthetic local enrollment. */ }
    });
$bindings = new AccountBindings($registry);
// The custom deadline driver and moving clock need their own explicit active composition.
$ports = new \Paymenter\Extensions\Servers\Domains\Catalogue\CallerPorts(
    new \Paymenter\Extensions\Servers\Domains\Catalogue\Projection($native), $clock, $bindings,
    static fn () => null, static fn () => true);
$offers = new \Paymenter\Extensions\Servers\Domains\Services\AcquisitionOffers($ports, $bindings, $native);
$sellers = new SellerPolicies($prices, $bindings, $offers);
foreach ([$sellers, $bindings] as $owner) {
    activeFixturePorts($owner, $ports);
    activeFixtureOffers($owner, $offers);
}
activeFixtureSeller($sellers,'com',(int)\Paymenter\Extensions\Servers\Domains\Models\SalesPolicy::where('tld','com')->value('revision'), $tlds[0]->server_id, true, true, $clock->now());
$limiter = new AccountReadBudget($clock);
app()->instance(Runtime::class, new Runtime); app()->instance(ReadLimiter::class, $limiter);
$expireDuringAdmission = false;
Event::listen(App\Events\Order\Creating::class, static function () use (&$expireDuringAdmission, $clock): void {
    if ($expireDuringAdmission) { $clock->advance(301); }
});
$adapter = activeFixtureAdmission($native, $sellers, activeFixtureEligibility($bindings, $clock), $clock); $adapter->boot();
// Native identity guards require the same admitted lines to reach services and invoice items.
$invoiceBinding = new \Paymenter\Extensions\Servers\Domains\Services\InvoiceBinding($adapter, $native, $prices, $clock);
$invoiceBinding->boot(); app()->instance(\Paymenter\Extensions\Servers\Domains\Services\InvoiceBinding::class, $invoiceBinding);
$user = commerceUser(); $product = $native->product($tlds[0], 'register');
$mount = static function ($cart) use ($user) {
    Illuminate\Support\Once::flush();
    return Livewire\Livewire::actingAs($user)->withCookies(['cart' => $cart->ulid])->test(App\Livewire\Cart::class)->set('use_credits', false);
};
$click = static function ($page) use ($user): void { Illuminate\Support\Once::flush(); $page->call('checkout',['contact'=>commerceOrderContact($user),'save_account'=>false]); };
