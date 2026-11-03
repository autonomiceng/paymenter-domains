<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native commits, cart rows and audit history are needed to exercise generation changes between writers.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/enom-catalogue-wire.php';

use App\Models\{CartItem, Server};
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\{DB, Event};
use Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate;
use Paymenter\Extensions\Servers\Domains\Contracts\{AccountVerifier, Clock};
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Tld};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\{CataloguePreview, Driver};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\Client;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AccountReadBudget, CatalogueAcceptances, CatalogueSync, DomainObservation, NativeCatalogue, ObservationSchema, ObservationWork, PriceCatalogue, PriceSync, PricingRules, RegistrarRegistry, SellerPolicies, StaffSettingsReview, TestCatalogue};

extract(commerceFixture());
ObservationSchema::install();
Paymenter\Extensions\Servers\Domains\Services\EnomTestSendLimitSchema::install();
config(['domains.enom_test_sends_per_day' => 10000, 'domains.enom_test_sends_per_minute' => 100000]);
$gate = new UpgradeGate;
$clock = new class($now) implements Clock {
    public ?Closure $during = null;
    public function __construct(public DateTimeImmutable $instant) {}
    public function now(): DateTimeImmutable
    {
        if ($this->during !== null && DB::transactionLevel() === 0) { ($this->during)(); }
        return $this->instant;
    }
};
app()->instance(Clock::class, $clock);
app()->instance(NativeCatalogue::class, $native);
app()->instance(PriceCatalogue::class, $prices);
app()->instance(SellerPolicies::class, $sellers);
$registry = new RegistrarRegistry;
$registry->register('enom', ['token'], new class implements AccountVerifier {
    public function verify(Environment $e, array $c): string { return $c['token']; }
}, static fn($b, $c) => new CataloguePreview(new Driver(new Client($b, $c['token'], $c['token']), ['com'])));
$readBindings = new AccountBindings($registry);
$sync = new CatalogueSync($readBindings, $prices);
$account = Account::where('server_id', $tlds[0]->server_id)->sole();
$binding = CatalogueAcceptances::binding($account);
$GLOBALS['catalogueList'] = ['com'];
$GLOBALS['catalogueMaximum'] = ['com' => 3];

// A claimed observation must not adopt a later generation before its refresh starts.
$budget = new AccountReadBudget($clock);
$work = new ObservationWork(new DomainObservation($readBindings, $clock, $budget), $clock, $budget, $sync);
$work->enroll($binding->serverId);
$before = DB::table('domains_prices')->orderBy('id')->get()->toJson();
$crossed = false;
$clock->during = static function() use ($clock, $gate, $binding, &$crossed): void {
    if (DB::table('domains_observation_queues')->where('server_id', $binding->serverId)->value('catalogue_claim') === null) { return; }
    $clock->during = null; $crossed = true; $gate->pause(); $gate->resume();
};
$work->tick(1);
$checks = ['claimed refresh' => $crossed && $before === DB::table('domains_prices')->orderBy('id')->get()->toJson()];
$clock->during = null;

config(['domains.enom_identity.environment' => 'test', 'domains.enom_catalogue.enabled' => true]);
$clock->instant = $now->modify('+2 minutes');
$run = new PriceSync($readBindings, $sync, $clock);
$arm = null; $changed = false;
DB::listen(static function($query) use (&$arm, &$changed): void {
    if ($arm !== null && str_starts_with($query->sql, $arm)) { $changed = true; }
});
Event::listen(TransactionCommitted::class, static function() use ($gate, &$arm, &$changed): void {
    if ($changed && DB::transactionLevel() === 0) { $arm = null; $changed = false; $gate->pause(); $gate->resume(); }
});
$refused = static function(callable $call): bool {
    try { $call(); return false; }
    catch (DomainException $error) { return str_contains($error->getMessage(), 'Catalogue upgrade'); }
};
// Pause/resume after the import commits, before the run initializes its seller policy.
DB::table('domains_sales_policies')->where('tld', 'com')->delete();
$arm = 'update "domains_prices"';
$checks['post-import policy'] = $refused(fn() => $run->run($account, $clock, null))
    && !DB::table('domains_sales_policies')->where('tld', 'com')->exists();
$arm = null; $changed = false;

// Pause/resume after initialization commits, before invalid cart lines are withdrawn.
$clock->instant = $now->modify('+4 minutes');
DB::table('domains_sales_policies')->where('tld', 'com')->delete();
$cart = commerceCart(commerceUser());
$item = commerceAdd($cart, $native->product($tlds[0], 'register'), 'generation.example.com');
$arm = 'insert into "domains_sales_policies"';
$checks['post-policy cart'] = $refused(fn() => $run->run($account, $clock, null)) && CartItem::whereKey($item->id)->exists();
$arm = null; $changed = false;

// Deferred withdrawals must recheck at execution, after the scheduling transaction commits.
$generation = UpgradeGate::generation();
$checks['deferred cart'] = $refused(static function() use ($binding, $generation, $gate): void {
    DB::transaction(static function() use ($binding, $generation, $gate): void {
        DB::afterCommit(static function() use ($gate): void { $gate->pause(); $gate->resume(); });
        TestCatalogue::withdrawCarts($binding, 'com', false, $generation);
    });
});
// An already superseded token is refused before an immediate withdrawal too.
$checks['stale cart'] = $refused(fn() => TestCatalogue::withdrawCarts($binding, 'com', false, $generation));

// A publisher that wins after the advisory read makes the locked sweep's audit unnecessary.
config(['domains.enom_catalogue.enabled' => false]);
DB::transaction(fn() => (new PricingRules)->saveDefaultRuleLocked([...Paymenter\Extensions\Servers\Domains\Services\PricingRule::DEFAULT, 'mode' => 'fixed', 'fixed' => ['register' => '20.00', 'renew' => '20.00', 'transfer' => '20.00']]));
$caughtUp = false;
Tld::retrieved(static function($locked) use ($prices, &$caughtUp): void {
    if (!$caughtUp && DB::transactionLevel() === 1) { $caughtUp = true; $prices->republishLocked($locked); }
});
$server = Server::findOrFail($binding->serverId);
$count = static fn() => count(array_filter(StaffSettingsReview::historyRows($server), static fn($r) => ($r['reason'] ?? null) === 'Saved prices brought up to date.'));
$before = $count();
$native->keepCurrent($tlds[0]->fresh(), $clock->now(), true);
$checks['locked audit'] = $caughtUp && $before === $count();
echo json_encode($checks, JSON_THROW_ON_ERROR)."\n";
same(array_fill_keys(array_keys($checks), true), $checks, 'Claimed work, post-import writes and locked audit preserve their original generation/current state');
$nativeSuccessful = true;
echo "Upgrade generation boundaries passed.\n";
