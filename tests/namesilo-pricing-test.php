<?php
// Synthetic native fixture only. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\{AccountVerifier, Clock};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, Environment, Query, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Price, Tld};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{Client, Driver, Prices};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api\{DeadlineTransport, Environment as WireEnvironment, RegistrarRequest, RequestContext as WireContext, TransportResponse};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, FoundationSchema, PriceCatalogue, PricingRules, RegistrarRegistry};
FoundationSchema::install();
$clock = new class implements Clock { public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-10-18T12:00:00Z'); } };
$wire = new class implements DeadlineTransport {
    public string $price = '12.34';
    public function send(WireEnvironment $environment, WireContext $context, RegistrarRequest $request, string $apiKey): TransportResponse { throw new LogicException; }
    public function sendWithin(WireEnvironment $environment, WireContext $context, RegistrarRequest $request, string $apiKey, int $timeoutMs): TransportResponse
    {
        same(0, DB::transactionLevel(), 'Price read outside locks');
        return new TransportResponse(200, json_encode(['request' => ['operation' => 'getPrices'], 'reply' => ['code' => 300, 'detail' => 'synthetic',
            'com' => ['registration' => $this->price, 'renew' => '15.00', 'transfer' => '10.00']]]));
    }
};
$registry = new RegistrarRegistry;
$registry->register('namesilo', ['key'], new class implements AccountVerifier {
    public function verify(Environment $environment, #[SensitiveParameter] array $credentials): string
    {
        if (!in_array($credentials['key'], ['synthetic-key-a', 'synthetic-key-b'], true)) { throw new RuntimeException('Unknown identity'); }
        return 'synthetic-stable-id';
    }
}, fn (AccountBinding $binding, array $credentials) => new Driver(new Client($binding, $credentials['key'], $wire), ['register', 'renew'], $clock));
$bindings = new AccountBindings($registry);
Server::create(['id' => 1, 'name' => 'Synthetic', 'type' => 'server', 'extension' => 'Domains', 'enabled' => true]);
try { $bindings->configure(1, 'namesilo', Environment::Test, ['key' => 'unverified-label-or-key']); throw new LogicException('Unknown identity accepted'); }
catch (RuntimeException) {}
same(0, Account::count(), 'Unverified identity cannot configure a real reader');
$binding = $bindings->configure(1, 'namesilo', Environment::Test, ['key' => 'synthetic-key-a']);
$driver = $bindings->reader(1);
$rotated = $bindings->configure(1, 'namesilo', Environment::Test, ['key' => 'synthetic-key-b']);
$binding->requireMatch($rotated); same(false, Account::firstOrFail()->processing_enabled, 'Rotation keeps stable account and processing off');
Tld::create(['server_id' => 1, 'registrar_key' => 'namesilo', 'account_fingerprint' => $binding->fingerprint, 'tld' => 'com', 'enabled' => false, 'renew_enabled' => true]);
$catalogue = new PriceCatalogue(new PricingRules);
$query = new Query('example.com', Action::Register, 1);
$read = static function (Tld $tld) use ($driver, $binding, $query, $clock): array {
    $context = new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+1 minute'), new RequestBudget(1), $clock);
    return [Prices::rate($driver->quote($query, $context), $binding, $query, $tld->tld, $clock)];
};
same(['synced' => 1, 'failed' => []], $catalogue->sync(1, $read), 'Accepted annual account quote persists');
$snapshot = $catalogue->current(1, $binding->fingerprint, 'com', 'register', 1, 'USD', $clock->now());
same('12.3400', $snapshot->wholesale, 'PriceSnapshot exact cost from pricing lane'); same('namesilo_account_annual', $snapshot->source, 'Source retained');
$before = Price::firstOrFail()->toJson(); $wire->price = 'bad';
same(['synced' => 0, 'failed' => ['.com']], $catalogue->sync(1, $read), 'Unknown quote fails whole refresh');
same($before, Price::firstOrFail()->toJson(), 'Unknown preserves price/revision/freshness');
$otherTld = Tld::create(['server_id' => 1, 'registrar_key' => 'namesilo', 'account_fingerprint' => $binding->fingerprint, 'tld' => 'net', 'enabled' => false]);
$wire->price = '12.34';
same(['synced' => 1, 'failed' => ['.net']], $catalogue->sync(1, $read), 'Wrong-TLD quote cannot populate another catalogue row');
same(0, Price::where('tld', 'net')->count(), 'No cross-TLD wholesale persisted');
$otherTld->delete();
$wire->price = '1.00'; $catalogue->sync(1, $read);
same('12.3400', $snapshot->wholesale, 'Old snapshot remains frozen');
same(3, Price::firstOrFail()->revision, 'Fresh observation increments revision');
$nativeSuccessful = true;
echo "NameSilo pricing: accepted binding, stable key rotation, quote-to-rate-to-snapshot and failed-refresh retention passed.\n";
