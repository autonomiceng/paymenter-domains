<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native Livewire/SQLite are needed to prove real pacing, receipt rollback and invoice atomicity together.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
use Illuminate\Support\Facades\{Cache, DB, Event};
use Paymenter\Extensions\Servers\Domains\Contracts\{AccountVerifier, Clock, ReadLimiter};
use Paymenter\Extensions\Servers\Domains\Data\{Environment, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Client, Driver};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DeadlineTransport, RegistrarRequest, TransportResponse};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AccountReadBudget, CartAdmission, CatalogueAcceptances, CheckoutEligibility, ObservationSchema, ReadDeferred, RegistrarRegistry, Runtime, SellerPolicies};
extract(commerceFixture(true)); ObservationSchema::install();
$clock = new class($now) implements Clock {
    public int $offset = 0;
    private int $started;
    public function __construct(private DateTimeImmutable $base) { $this->started = hrtime(true); }
    public function now(): DateTimeImmutable { return $this->base->modify('+'.($this->offset + intdiv(hrtime(true) - $this->started, 1000)).' microseconds'); }
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
            usleep(min($this->delayMs, $timeoutMs) * 1000);
            if ($timeoutMs < $this->delayMs) { return new TransportResponse(200, '{}'); }
        }
        if ($name === $this->slowName) { $this->clock->advance(2); }
        return new TransportResponse(200, json_encode(['request' => ['operation' => $request->command], 'reply' => ['code' => 300, 'detail' => 'success', 'available' => [['domain' => $name, 'price' => 6, 'premium' => 0, 'duration' => 1]]]], JSON_THROW_ON_ERROR));
    }
};
$registry = new RegistrarRegistry;
$registry->register('namesilo', ['token'], new class implements AccountVerifier {
    public function verify(Environment $environment, array $credentials): string { return $credentials['token']; }
}, fn($b, $credentials) => new Driver(new Client($b, $credentials['token'], $wire), [], $clock));
$bindings = new AccountBindings($registry); $sellers = new SellerPolicies($prices, $bindings);
$sellers->change('com', 2, $tlds[1]->server_id, true, true, $clock->now());
$limiter = new AccountReadBudget($clock);
app()->instance(Runtime::class, new Runtime); app()->instance(ReadLimiter::class, $limiter);
$expireDuringAdmission = false;
Event::listen(App\Events\Order\Creating::class, static function () use (&$expireDuringAdmission, $clock): void {
    if ($expireDuringAdmission) { $clock->advance(301); }
});
$adapter = new CartAdmission($native, $sellers, new CheckoutEligibility($bindings, $clock), $clock); $adapter->boot();
$user = commerceUser(); $product = $native->product($tlds[1], 'register');
$mount = static function ($cart) use ($user) {
    Illuminate\Support\Once::flush();
    return Livewire\Livewire::actingAs($user)->withCookies(['cart' => $cart->ulid])->test(App\Livewire\Cart::class)->set('use_credits', false);
};
$click = static function ($page): void { Illuminate\Support\Once::flush(); $page->call('checkout'); };
$cart = commerceCart($user);
for ($i = 0; $i < 3; $i++) { commerceAdd($cart, $product, placeholderComFixtureName('paced-'.$i.'-placeholder.com')); $clock->advance(1); }
$page = $mount($cart); $before = count($wire->calls); $clock->advance(301); $click($page);
same(1, App\Models\Order::count(), 'One checkout click automatically refreshes all expired NameSilo lines');
same($before + 3, count($wire->calls), 'One guarded physical read per expired line');
for ($i = $before + 1; $i < count($wire->calls); $i++) { expect($wire->calls[$i] - $wire->calls[$i - 1] >= 1000000, 'One-second pacing remains enforced between real reservations'); }
same(3, (int)DB::table('domains_read_budgets')->value('used'), 'Real limiter charges every refresh');
// A valid line with one second left must not expire behind a slower sibling.
$clock->advance(1); $cart = commerceCart($user);
$a = commerceAdd($cart, $product, placeholderComFixtureName('headroom-a-placeholder.com')); $clock->advance(1);
$b = commerceAdd($cart, $product, placeholderComFixtureName('headroom-b-placeholder.com')); $page = $mount($cart);
$clock->advance(Cache::get('domains.checkout.'.$a->checkout_config['domains_check'])['expires'] - $clock->now()->getTimestamp() - 1);
Cache::forget('domains.checkout.'.$b->checkout_config['domains_check']); $wire->slowName = $b->checkout_config['domain'];
$click($page);
same(2, App\Models\Order::count(), 'Near-expiry proof is refreshed before a slower sibling and locked checkout');
$wire->slowName = null;
// Lock waits can still exceed headroom: reject expired proof, keep the cart, offer a truthful retry.
$clock->advance(1); $cart = commerceCart($user); $line = commerceAdd($cart, $product, placeholderComFixtureName('lock-wait-placeholder.com')); $page = $mount($cart);
$expireDuringAdmission = true; $before = count($wire->calls); $click($page); $expireDuringAdmission = false;
same(2, App\Models\Order::count(), 'Five-minute rule rejects proof expiring during admission');
same($before, count($wire->calls), 'TTL rollback never reads under native locks');
same($line->id, $cart->items()->sole()->id, 'TTL rollback keeps the unchanged cart');
$page->assertDispatched('notify', static fn($event, $params) => str_contains(strtolower($params[0]['message']), 'try checkout again') && !str_contains(strtolower($params[0]['message']), 'remove'));
$click($page); same(3, App\Models\Order::count(), 'Next checkout repairs TTL rollback without a new search');
// A deferred interval exceeding the request wait budget must stop without dispatch or cart loss.
$clock->advance(1); $cart = commerceCart($user); $line = commerceAdd($cart, $product, placeholderComFixtureName('wait-budget-placeholder.com')); $page = $mount($cart); $clock->advance(301);
$micros = (int)$clock->now()->format('U') * 1000000 + (int)$clock->now()->format('u');
DB::table('domains_read_budgets')->update(['next_api_at' => $micros + 20000000]);
$before = count($wire->calls); $click($page);
same($before, count($wire->calls), 'Wait budget exhaustion does not bypass the pacing guard');
same(3, App\Models\Order::count(), 'Wait budget exhaustion cannot create an order');
same($line->id, $cart->items()->sole()->id, 'Wait budget exhaustion keeps the cart');
$page->assertDispatched('notify', static fn($event, $params) => str_contains(strtolower($params[0]['message']), 'taking too long'));
// Pacing must never make a minute-cap refusal retryable or charge a refused wire.
DB::table('domains_read_budgets')->update(['window_at' => $micros, 'used' => 60]);
$binding = CatalogueAcceptances::binding(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id', $tlds[1]->server_id)->sole());
try { $limiter->acquire($binding, RequestOrigin::Interactive); expect(false, 'Minute cap must refuse'); }
catch (ReadDeferred $e) { same(null, $e->retryAfterMicros, 'Minute cap retains a hard refusal even while pacing is active'); }
same(60, (int)DB::table('domains_read_budgets')->value('used'), 'Refused wire never changes the minute reservation');
// Real elapsed time: successful reads must share the batch deadline, then resume saved progress.
$clock->advance(61); $cart = commerceCart($user);
for ($i = 0; $i < 3; $i++) { commerceAdd($cart, $product, placeholderComFixtureName('slow-batch-'.$i.'-placeholder.com')); $clock->advance(1); }
$lines = $cart->items()->orderBy('id')->get(); $page = $mount($cart); $clock->advance(301);
$wire->delayMs = 6000; $started = hrtime(true); $click($page); $elapsed = (hrtime(true) - $started) / 1000000000;
expect($elapsed < 16.5, 'Successful reads must share the 15-second batch deadline; elapsed '.$elapsed);
same(3, App\Models\Order::count(), 'Batch deadline leaves payment and orders untouched');
same(3, $cart->items()->count(), 'Batch deadline keeps all cart lines');
foreach ($lines->take(2) as $line) { expect($line->fresh()->checkout_config['domains_check'] !== $line->checkout_config['domains_check'], 'Completed refresh persists before the batch stops'); }
same($lines->last()->checkout_config, $lines->last()->fresh()->checkout_config, 'Timed-out line keeps its previous receipt for retry');
expect(end($wire->timeouts) < 4000, 'Last physical read receives only the remaining batch timeout');
$page->assertDispatched('notify', static fn($event, $params) => str_contains(strtolower($params[0]['message']), 'taking too long'));
$wire->delayMs = 0; $clock->advance(1); $before = count($wire->calls); $click($page);
same(4, App\Models\Order::count(), 'Retry completes the remaining line without starting over');
same($before + 1, count($wire->calls), 'Retry reuses both completed receipts');
same(4, App\Models\Invoice::count(), 'Only completed checkouts create invoices');
$nativeSuccessful = true; echo "Real-limiter checkout pacing, receipt headroom and TTL rollback passed.\n";
