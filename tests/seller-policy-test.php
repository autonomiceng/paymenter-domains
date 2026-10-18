<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/FakeRegistrar.php';
use App\Models\Server;
use Illuminate\Support\Facades\{DB, Schema};
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Capabilities, CatalogueRate, Disposition, DriverResult, Environment, ResultReason, SaleSelection, SendState};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, SalesPolicy, Tld};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, DomainReservations, FoundationSchema, HolderPricing, PriceCatalogue, PricingRules, RegistrarRegistry, SalesPolicySchema, SellerPolicies};
use Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar;
FoundationSchema::install(); SalesPolicySchema::install();
$registry = new RegistrarRegistry;
$verifier = new class implements AccountVerifier {
    public function verify(Environment $env, #[SensitiveParameter] array $credentials): string { return $credentials['token']; }
};
$factory = static fn (AccountBinding $b, array $c): FakeRegistrar => new FakeRegistrar($b, new Capabilities($b, []),
    new DriverResult($b, Disposition::Unknown, SendState::PossiblySent, ResultReason::Unverified));
foreach (['enom', 'namesilo'] as $key) { $registry->register($key, ['token'], $verifier, $factory); }
$bindings = new AccountBindings($registry); $accounts = [];
foreach ([1 => 'enom', 2 => 'namesilo'] as $id => $key) {
    Server::create(['id' => $id, 'name' => 'Synthetic', 'type' => 'server', 'extension' => 'Domains', 'enabled' => true]);
    $b = $bindings->configure($id, $key, Environment::Test, ['token' => 'account-'.$id]);
    $bindings->setProcessing($id, true);
    $accounts[$id] = ['server_id' => $id, 'registrar_key' => $key, 'account_fingerprint' => $b->fingerprint];
    Tld::create([...$accounts[$id], 'tld' => 'com', 'enabled' => true, 'register_enabled' => true, 'transfer_enabled' => true, 'renew_enabled' => true, 'min_years' => 1, 'max_years' => 3]);
}
Tld::create([...$accounts[2], 'tld' => 'net', 'enabled' => true, 'register_enabled' => true, 'transfer_enabled' => true, 'renew_enabled' => true, 'min_years' => 1, 'max_years' => 3]);
$now = new DateTimeImmutable('2026-10-18T12:00:00Z');
$rate = static fn (string $action, int $term, string $cost): CatalogueRate => new CatalogueRate($action, $term, $cost, 'synthetic', $now, $now->modify('+1 day'));
$prices = new PriceCatalogue(new PricingRules); $holder = new HolderPricing($prices);
foreach ([1, 2] as $id) { $prices->sync($id, static fn (): array => [$rate('register', 1, (string) ($id * 5)), $rate('register', 3, '4.00'), $rate('renew', 1, (string) ($id * 6)), $rate('transfer', 1, '5.00')]); }
$sellers = new SellerPolicies($prices, $bindings);
$admit = static function (SaleSelection $line, ?Closure $after = null) use ($sellers, $now): mixed {
    return DB::transaction(static function () use ($sellers, $line, $now, $after): mixed {
        $price = $sellers->admit([$line], $now)[0];
        return $after ? $after($price) : $price;
    });
};
$p = $sellers->initialize('com'); same(1, $p->revision, 'Initialized policy revision');
$reject = static function (callable $call): void {
    try { $call(); expect(false, 'Seller guard escaped'); } catch (DomainException|InvalidArgumentException|LogicException|Illuminate\Database\Eloquent\ModelNotFoundException|Illuminate\Database\QueryException $e) { /* expected */ }
};
$reject(static fn () => $sellers->selection('com', 'register', 1, $now));
$p = $sellers->change('com', 1, 1, true, true, $now);
same(2, $p->revision, 'Enabled seller advances revision');
$oldCart = $sellers->selection('com', 'register', 1, $now);
$reject(static fn () => $sellers->admit([$oldCart], $now));
$sellers->initialize('net'); $sellers->change('net', 1, 2, true, false, $now);
$netCart = $sellers->selection('net', 'register', 1, $now);
$batch = DB::transaction(static fn (): array => $sellers->admit([$netCart, $oldCart], $now));
same([2, 1], array_map(static fn ($price): int => $price->serverId, $batch), 'Batch returns input order after ordered locks');
$reject(static fn () => DB::transaction(static fn () => $sellers->admit([], $now)));
$reject(static fn () => $sellers->change('net', 2, 1, true, false, $now)); // no matching TLD
$staleNet = new SaleSelection('net', 2, $netCart->registrar, $netCart->fingerprint, $netCart->policyRevision,
    'register', 1, 'USD', $netCart->priceId, $netCart->priceRevision - 1);
$reject(static fn () => DB::transaction(static function () use ($sellers, $oldCart, $staleNet, $now): void {
    App\Models\User::create(['email' => 'stale-cart@example.invalid', 'password' => bcrypt('synthetic')]);
    $sellers->admit([$oldCart, $staleNet], $now);
}));
same(0, App\Models\User::count(), 'One stale line rolls back whole native cart');
$acquire = static function ($price) use ($accounts): Domain {
    expect(DB::transactionLevel() > 0, 'Order writes remain inside admission transaction');
    return DomainReservations::reserve([...$accounts[$price->serverId], 'service_id' => 11, 'user_id' => 21, 'domain' => 'example.com', 'status' => 'uncertain'], ['com']);
};
$domain = $admit($oldCart, $acquire);
same(1, (int) $domain->server_id, 'First admitted holding uses original account');
Server::findOrFail(1)->update(['enabled' => false]);
$p = $sellers->change('com', 2, 2, true, false, $now);
Server::findOrFail(1)->update(['enabled' => true]);
$called = (object) ['value' => false];
$reject(static fn () => $admit($oldCart, static function () use ($called): void { $called->value = true; }));
same(false, $called->value, 'Stale checkout refused before native writes');
same(1, (int) $domain->fresh()->server_id, 'Switch leaves uncertain holding bound');
same('7.80', $holder->renewal($domain, 'USD', $now)->retail, 'Non-default holder uses original annual renewal');
$newCart = $sellers->selection('com', 'register', 1, $now);
$reject(static fn () => $admit($newCart, $acquire));
same(1, Domain::count(), 'Cross-account duplicate name stays reserved');
$reject(static fn () => $sellers->selection('com', 'transfer', 1, $now));
$bindings->setProcessing(2, false);
$p = $sellers->change('com', 3, 1, true, true, $now);
$bindings->setProcessing(2, true);
$reject(static fn () => $admit($oldCart, $acquire));
same(4, $p->revision, 'Switch-back does not revive old tokens');
$current = $sellers->selection('com', 'register', 1, $now);
$prices->sync(1, static fn (): array => [$rate('register', 1, '6.00')]);
$reject(static fn () => $admit($current, static fn () => null));
$reject(static fn () => $sellers->change('com', 3, 2, true, true, $now));
$liveCart = $sellers->selection('com', 'register', 1, $now);
Server::findOrFail(1)->update(['enabled' => false]);
$reject(static fn () => $sellers->selection('com', 'register', 1, $now));
$reject(static fn () => $admit($liveCart, static fn () => null));
Server::findOrFail(1)->update(['enabled' => true]);
$bindings->setProcessing(1, false);
$reject(static fn () => $sellers->selection('com', 'register', 1, $now));
$reject(static fn () => $admit($liveCart, static fn () => null));
$bindings->setProcessing(1, true);
Tld::where('server_id', 1)->firstOrFail()->update(['min_years' => 2]);
$reject(static fn () => $sellers->selection('com', 'register', 1, $now));
$reject(static fn () => $admit($liveCart, static fn () => null));
Tld::where('server_id', 1)->firstOrFail()->update(['min_years' => 1]);
Tld::where('server_id', 1)->firstOrFail()->update(['use_default_rule' => false, 'pricing_mode' => 'markup_percent', 'markup' => '50.00']);
$reject(static fn () => $admit($liveCart, static fn () => null));
Tld::where('server_id', 1)->firstOrFail()->update(['use_default_rule' => true]);
expect(!str_contains(json_encode($liveCart, JSON_THROW_ON_ERROR), $accounts[1]['account_fingerprint']), 'Cart JSON hides account binding');
$reject(static fn () => serialize($liveCart));
Server::findOrFail(2)->update(['enabled' => false]);
$reject(static fn () => $sellers->change('com', 4, 2, true, true, $now));
Server::findOrFail(2)->update(['enabled' => true]);
$bindings->setProcessing(2, false);
$reject(static fn () => $sellers->change('com', 4, 2, true, true, $now));
$bindings->setProcessing(2, true);
Tld::where('server_id', 2)->firstOrFail()->update(['transfer_enabled' => false]);
$reject(static fn () => $sellers->change('com', 4, 2, true, true, $now));
Tld::where('server_id', 2)->where('tld', 'com')->firstOrFail()->update(['min_years' => 11, 'max_years' => 12]);
$reject(static fn () => $sellers->change('com', 4, 2, true, false, $now));
Tld::where('server_id', 2)->where('tld', 'com')->firstOrFail()->update(['min_years' => 1, 'max_years' => 3]);
Tld::where('server_id', 2)->firstOrFail()->update(['renew_enabled' => false]);
$reject(static fn () => $sellers->change('com', 4, 2, true, false, $now));
$reject(static fn () => $sellers->change('com', 4, null, true, false, $now));
same(4, SalesPolicy::where('tld', 'com')->firstOrFail()->revision, 'Failed seller changes roll back revision');
// Both extension and native writes disappear when checkout aborts.
$current = $sellers->selection('com', 'register', 1, $now);
try {
    $admit($current, static function ($price) use ($accounts): void {
        App\Models\User::create(['email' => 'rollback@example.invalid', 'password' => bcrypt('synthetic')]);
        DomainReservations::reserve([...$accounts[1], 'service_id' => 12, 'user_id' => 22, 'domain' => 'rollback.com', 'status' => 'pending'], ['com']);
        throw new RuntimeException('checkout rollback');
    });
} catch (RuntimeException $e) { same('checkout rollback', $e->getMessage(), 'Complete checkout abort'); }
same(0, App\Models\User::count(), 'Native checkout rolled back');
same(null, Domain::where('domain', 'rollback.com')->first(), 'Reservation write rolled back');
$p = $sellers->change('com', 4, null, false, false, $now);
same(5, $p->revision, 'Disabling seller advances policy');
$reject(static fn () => $sellers->selection('com', 'register', 1, $now));
same('7.80', $holder->renewal($domain, 'USD', $now)->retail, 'No seller does not alter holder renewal');
// Below-cost renewal remains eligible; one held register term does not hide another valid term.
$rules = new PricingRules;
$rules->saveDefaultRule(1, ['mode' => 'fixed', 'markup' => '0', 'round_up' => 'none',
    'fixed' => ['register' => '7.00', 'renew' => '1.00', 'transfer' => '7.00']], $prices->republish(...));
$prices->sync(1, static fn (): array => [$rate('register', 1, '10.00')]);
$p = $sellers->change('com', 5, 1, true, false, $now);
$reject(static fn () => $sellers->selection('com', 'register', 1, $now));
$eligible = $sellers->selection('com', 'register', 3, $now);
same('21.00', $admit($eligible)->retail, 'Eligible term survives held annual acquisition');
same('below_cost', $holder->renewal($domain, 'USD', $now)->staffAlert, 'Renewal below cost is alert only');
$prices->sync(1, static fn (): array => [new CatalogueRate('register', 3, '4.00', 'synthetic', $now, $now->modify('+3 days'))]);
$eligible = $sellers->selection('com', 'register', 3, $now);
$future = $now->modify('+2 days');
$prices->current(1, $accounts[1]['account_fingerprint'], 'com', 'register', 3, 'USD', $future);
$reject(static fn () => DB::transaction(static fn () => $sellers->admit([$eligible], $future)));
$reject(static fn () => $p->update(['revision' => 6]));
$reject(static fn () => $p->delete());
$before = DB::table('domains_sales_policies')->get()->toJson();
$migration = require dirname(__DIR__).'/Domains/database/migrations/2026_10_18_000002_create_domains_sales_policies.php';
$migration->down(); $migration->up(); SalesPolicySchema::verify();
same($before, DB::table('domains_sales_policies')->get()->toJson(), 'Reinstall retains seller revision');
Schema::table('domains_sales_policies', static fn ($table) => $table->dropUnique(['tld']));
try { SalesPolicySchema::verify(); expect(false, 'Weakened seller uniqueness accepted'); }
catch (RuntimeException $e) { expect(str_contains($e->getMessage(), 'schema mismatch'), 'Seller schema fails closed'); }
$nativeSuccessful = true;
echo "Seller revisions, locked admission, switch-back and native rollback checks passed.\n";
