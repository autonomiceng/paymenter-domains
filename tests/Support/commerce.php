<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/FakeRegistrar.php';
use App\Models\{Category, Currency, Server};
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Capabilities, CatalogueEntry, CatalogueRate, Disposition, DriverResult, Environment, Evidence, ResultReason, SendState};
use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, CatalogueAcceptances, FoundationSchema, NativeCatalogue, PriceCatalogue, PricingRules, RegistrarRegistry, SalesPolicySchema, SellerPolicies};
use Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar;

function commerceFixture(bool $eligible = false, ?Closure $activation = null): array
{
    FoundationSchema::install(); SalesPolicySchema::install();
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'format' => '1,000.00', 'prefix' => '$', 'suffix' => '']);
    Category::create(['name' => 'Domains', 'slug' => 'domains', 'description' => 'Domains']);
    $registry = new RegistrarRegistry;
    $reads = (object) ['calls' => 0, 'premium' => false, 'available' => true];
    $verifier = new class implements AccountVerifier {
        public function verify(Environment $env, #[SensitiveParameter] array $credentials): string { return $credentials['token']; }
    };
    foreach (['enom', 'namesilo'] as $key) {
        $registry->register($key, ['token'], $verifier, static function (AccountBinding $b, array $c) use ($eligible, $reads): FakeRegistrar {
            expect(Illuminate\Support\Facades\DB::transactionLevel() === 0, 'No provider reads under locks'); $reads->calls++;
            return new FakeRegistrar($b, new Capabilities($b, []), $eligible
                ? new DriverResult($b, Disposition::Accepted, SendState::Sent, ResultReason::Acknowledged, new Evidence(premium: $reads->premium, available: $reads->available))
                : new DriverResult($b, Disposition::Unknown, SendState::PossiblySent, ResultReason::Unverified));
        });
    }
    $bindings = new AccountBindings($registry); $prices = new PriceCatalogue(new PricingRules);
    $sellers = new SellerPolicies($prices, $bindings); $native = new NativeCatalogue($prices);
    $now = new DateTimeImmutable('2026-10-18T12:00:00Z'); $tlds = [];
    foreach (['enom', 'namesilo'] as $key) {
        $server = Server::create(['name' => 'Synthetic', 'type' => 'server', 'extension' => 'Domains', 'enabled' => true]);
        $binding = $bindings->configure($server->id, $key, Environment::Test, ['token' => 'account-'.$key]);
        $bindings->setProcessing($server->id, true, $activation);
        $tld = Tld::create(['server_id' => $server->id, 'registrar_key' => $key, 'account_fingerprint' => $binding->fingerprint,
            'tld' => 'com', 'enabled' => true, 'min_years' => 1, 'max_years' => 3]);
        $terms = $key === 'enom' ? [1, 3] : [1];
        $accepted = CatalogueAcceptances::save($binding, 0, ['com' => ['register' => $terms, 'renew' => [1], 'transfer' => [1], 'registry' => true, 'renewal_safe' => true]]);
        $rates = [];
        foreach ([['renew', 1, '8.00'], ['transfer', 1, '7.00'], ...array_map(static fn ($n) => ['register', $n, '6.00'], $terms)] as [$a, $n, $cost]) {
            $rates[] = new CatalogueRate($a, $n, $cost, 'synthetic', $now, $now->modify('+1 day'));
        }
        $prices->import(new CatalogueEntry($binding, 'com', $rates, $terms, true, true), $accepted, static fn () => null, $now);
        $native->publish($tld->id, $now); $tlds[] = $tld->fresh();
    }
    $sellers->initialize('com'); $sellers->change('com', 1, $tlds[0]->server_id, true, true, $now);
    return compact('bindings', 'prices', 'sellers', 'native', 'now', 'tlds', 'reads');
}
function commerceReject(callable $call): void
{
    try { $call(); expect(false, 'Expected commerce refusal'); }
    catch (DomainException|InvalidArgumentException|LogicException|Illuminate\Database\Eloquent\ModelNotFoundException|Illuminate\Database\MultipleRecordsFoundException $e) { }
}
