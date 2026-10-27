<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
use App\Models\{Invoice, Order, Service};
use Illuminate\Support\Facades\{Cache, DB, Event};
use Paymenter\Extensions\Servers\Domains\Models\{Price, SalesPolicy};
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission, CheckoutEligibility, StaffSettingsReview, StaffSettingsWrites, Storefront, StorefrontCatalogueCache};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;

extract(commerceFixture(true)); $clock = new FakeClock($now);
$eligibility = new CheckoutEligibility($bindings, $clock);
$store = new Storefront($sellers, $prices, $native, $bindings, $eligibility, $clock);
$measure = static function (Closure $read): array {
    DB::enableQueryLog(); DB::flushQueryLog();
    try { $result = $read(); return [$result, array_column(DB::getQueryLog(), 'query')]; }
    finally { DB::disableQueryLog(); }
};
Cache::flush(); DB::beginTransaction();
[$direct, $renderQueries] = $measure(fn () => $store->endings()); DB::rollBack();
[$cold, $coldQueries] = $measure(fn () => $store->endings());
same($direct, $cold, 'Miss returns the uncached render projection');
same($renderQueries, $coldQueries, 'Miss executes only the existing render queries');
[$warm, $hitQueries] = $measure(fn () => [$store->endings(), $store->testEnvironment($cold)]);
same([$cold, true], $warm, 'Warm display includes cached selected-seller environment');
$tables = ['domains_sales_policies', 'domains_accounts', 'domains_tlds', 'domains_prices', 'extensions', 'settings', 'products', 'plans', 'prices', 'product_upgrades', 'config_option_products'];
foreach ($hitQueries as $sql) {
    foreach ($tables as $table) { expect(!preg_match('/\b'.preg_quote($table, '/').'\b/i', $sql), 'Hit never queries '.$table); }
}
same([], $hitQueries, 'Array-cache storefront hit is query-free');
echo 'Storefront cache queries: render '.count($renderQueries).', miss '.count($coldQueries).', hit '.count($hitQueries).".\n";

// Actual staff price publication (native writer republishes the reviewed owned prices).
$staff = commerceUser(); $role = App\Models\Role::create(['name' => 'Catalogue editor', 'permissions' => ['admin.domains.view', 'admin.domains.manage-policy', StaffSettingsReview::permission('publish')]]);
$staff->update(['role_id' => $role->id]);
$writer = new StaffSettingsWrites($native, $sellers, $bindings);
$tld = $tlds[0];
$tld->update(['use_default_rule' => false, 'pricing_mode' => 'fixed', 'fixed_register' => '20.00', 'fixed_renew' => '21.00', 'fixed_transfer' => '22.00']);
// Cache an unavailable unpublished rule before the writer. Only publication may restore it.
$store->endings();
$review = new StaffSettingsReview($staff->id, $tld->server_id, $tld->id, 'publish',
    StaffSettingsReview::ticket($staff->id, $tld->server_id, $tld->id, 'publish', StaffSettingsReview::stamp($tld->server_id, $tld->id)), 'Publish reviewed catalogue prices');
$forgotten = [];
Event::listen(Illuminate\Cache\Events\KeyForgotten::class, static function ($event) use (&$forgotten): void {
    if (str_starts_with($event->key, 'domains.storefront:')) { $forgotten[] = $event->key; }
});
$writer->run($review, [], $now);
expect($forgotten !== [], 'Publication explicitly forgets the previous display key');
same('$20.00', $store->endings()[0]['register'], 'Staff publication is visible on the next request');

// Own writes take effect after the OUTER commit, never on a savepoint or rollback.
$stable = $store->endings(); $forgetCount = count($forgotten); DB::beginTransaction();
$tld->update(['register_enabled' => false]);
DB::beginTransaction(); $tld->update(['transfer_enabled' => false]); DB::commit();
DB::rollBack();
[$rolledBack, $rollbackQueries] = $measure(fn () => $store->endings());
same($stable, $rolledBack, 'Rolled-back owned writes preserve cached rows');
same([], $rollbackQueries, 'Rollback retains a warm zero-query display');
same($forgetCount, count($forgotten), 'Rollback does not forget a committed display');
DB::beginTransaction(); DB::beginTransaction(); $tld->refresh()->update(['register_enabled' => false]);
DB::rollBack(); DB::commit();
same($forgetCount, count($forgotten), 'Rolling back a savepoint discards its invalidation callback');
DB::beginTransaction(); $tld->refresh()->update(['register_enabled' => false]); DB::commit();
same([], $store->endings()[0]['terms'], 'Committed owned TLD withdrawal forgets cached display');
$tld->refresh()->update(['register_enabled' => true]);
$bindings->setProcessing($tld->server_id, false); same([], $store->endings(), 'Account writer invalidates immediately');
$bindings->setProcessing($tld->server_id, true, syntheticActivation());
$policy = SalesPolicy::where('tld', 'com')->sole();
$sellers->change('com', $policy->revision, null, false, false, $now); same([], $store->endings(), 'Seller writer invalidates immediately');
$sellers->change('com', $policy->revision + 1, $tld->server_id, true, true, $now);

// Native edits are deliberately TTL-bound. Fake time, including the exact expiry boundary.
$product = $native->product($tld->fresh(), 'register');
$nativePrice = $product->plans()->where('billing_period', 1)->sole()->prices()->sole();
same('$20.00', $store->endings()[0]['register'], 'Warm valid price');
$nativePrice->update(['price' => '19.00']);
$clock->instant = $now->modify('+29 seconds'); same('$20.00', $store->endings()[0]['register'], 'Native edit may lag for less than thirty seconds');
commerceReject(fn () => $store->offer('com', 'register', 1));
same(3, $store->check('current-placeholder.com')['term'], 'Interactive availability excludes the edited annual price within the TTL');
$clock->instant = $now->modify('+30 seconds'); same(null, $store->endings()[0]['register'], 'Exact TTL boundary reflects native price edit');
$nativePrice->update(['price' => '20.00']); $clock->instant = $now->modify('+60 seconds');
same('$20.00', $store->endings()[0]['register'], 'Restored native price appears after the TTL');

// Native cart admission and checkout independently refuse stale or withdrawn prices while display stays warm.
$adapter = new CartAdmission($native, $sellers, $eligibility, $clock); $adapter->boot();
$user = commerceUser(); $cart = commerceCart($user); commerceAdd($cart, $product, placeholderComFixtureName('cached-checkout-placeholder.com'));
$nativePrice->update(['price' => '19.00']);
same('$20.00', $store->endings()[0]['register'], 'Admission refusal is exercised against a warm stale display');
commerceReject(fn () => $store->offer('com', 'register', 1));
commerceCheckout($user, $cart);
same(0, Order::count(), 'Stale-price checkout rolls back native order');
same(0, Invoice::count(), 'Stale-price checkout creates no invoice'); same(0, Service::count(), 'Stale-price checkout creates no service');
$nativePrice->update(['price' => '20.00']);
$cart = commerceCart($user); commerceAdd($cart, $product, placeholderComFixtureName('expired-checkout-placeholder.com'));
DB::table('domains_prices')->where('server_id', $tld->server_id)->whereIn('action', ['register', 'transfer'])->update(['fresh_until' => $now->modify('+59 seconds')]);
same('$20.00', $store->endings()[0]['register'], 'Expired evidence still has a warm display');
commerceReject(fn () => commerceAdd(commerceCart($user), $product, placeholderComFixtureName('expired-admission-placeholder.com')));
commerceCheckout($user, $cart); same(0, Order::count(), 'Expired-price checkout cannot trust cached display rows');
DB::table('domains_prices')->where('server_id', $tld->server_id)->update(['fresh_until' => $now->modify('+1 day')]);
$cart = commerceCart($user); commerceAdd($cart, $product, placeholderComFixtureName('withdrawn-checkout-placeholder.com'));
DB::table('domains_prices')->where('server_id', $tld->server_id)->whereIn('action', ['register', 'transfer'])->update(['hold_reason' => 'below_cost']);
same('$20.00', $store->endings()[0]['register'], 'Raw maintenance withdrawal may lag only in display');
same('held', $store->check('withdrawn-placeholder.com')['status'], 'Interactive check refuses withdrawn prices within TTL');
commerceReject(fn () => commerceAdd(commerceCart($user), $product, placeholderComFixtureName('withdrawn-admission-placeholder.com')));
commerceCheckout($user, $cart); same(0, Order::count(), 'Withdrawn checkout cannot trust cached display rows');

// Price model saves invalidate too; transaction-local renders must never escape rollback.
$price = Price::where('server_id', $tld->server_id)->where('action', 'register')->firstOrFail();
$price->update(['revision' => $price->revision + 1, 'hold_reason' => 'below_cost']);
same([], $store->endings(), 'Price writer forgets rows and rechecks native projection');
$display = new StorefrontCatalogueCache($clock); Cache::flush(); $calls = 0;
$render = static function () use (&$calls): array { return [++$calls]; };
same([1], $display->remember($render), 'Initial display fill');
DB::beginTransaction(); same([2], $display->remember($render), 'Transaction renders its own projection'); DB::rollBack();
same([1], $display->remember($render), 'Transaction-local projection was never cached');

// Standalone writers must invalidate even when no owned model event fires.
$rules = new Paymenter\Extensions\Servers\Domains\Services\PricingRules;
DB::transaction(fn () => $rules->saveDefaultRuleLocked($tld->server_id, $rules->defaultRule($tld->server_id)));
same([3], $display->remember($render), 'Standalone default-rule save forgets warm display');
$binding = Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::binding(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id', $tld->server_id)->sole());
$accepted = Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::load($binding);
Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances::save($binding, $accepted->revision, $accepted->endings);
same([4], $display->remember($render), 'Standalone acceptance save forgets warm display');
$native->publish($tld->id, $now);
same([5], $display->remember($render), 'Native publication without review forgets warm display');

// A cache outage after commit cannot turn a committed writer into an error or suppress later callbacks.
$cacheRoot = Cache::getFacadeRoot(); $afterCommit = false;
Cache::swap(new class(new Illuminate\Cache\ArrayStore) extends Illuminate\Cache\Repository {
    public function forever($key, $value) { throw new RuntimeException('Synthetic display cache outage'); }
});
try {
    DB::transaction(static function () use ($tld, &$afterCommit): void {
        $tld->refresh()->update(['transfer_enabled' => false]);
        DB::afterCommit(static function () use (&$afterCommit): void { $afterCommit = true; });
    });
} finally { Cache::swap($cacheRoot); }
expect(!$tld->fresh()->transfer_enabled, 'Writer remains committed despite a cache outage');
expect($afterCommit, 'Cache outage does not suppress later after-commit callbacks');

// An owned commit during render changes the namespace. A mixed response can return once but cannot be cached.
Cache::flush(); $calls = 0;
$mixed = $display->remember(static function () use (&$calls): array { $calls++; StorefrontCatalogueCache::invalidateAfterCommit(); return ['mixed']; });
same(['mixed'], $mixed, 'Racing caller still receives its rendered rows');
same([2], $display->remember($render), 'Post-publication request rerenders instead of caching mixed rows');
$clock->instant = $clock->instant->modify('-1 second'); same([3], $display->remember($render), 'Clock reversal rerenders future-dated rows');
$configured = config('app.url'); config(['app.url' => 'https://other.example.test']);
same([4], $display->remember($render), 'Another application URL cannot reuse these rows'); config(['app.url' => $configured]);
expect(!class_exists('Paymenter\\Extensions\\Servers\\Domains\\Domains', false), 'Display cache never autoloads the dormant native entry');
$nativeSuccessful = true; echo "Display TTL, owned invalidation, publication races and uncached admission passed.\n";
