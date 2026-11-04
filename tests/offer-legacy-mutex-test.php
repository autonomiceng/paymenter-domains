<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/OfferFixture.php';
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/accounts.php';

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AccountReadBudget, CatalogueAcceptances, CatalogueSync, DomainObservation, Installation, ObservationWork, PriceSync, SellRule, SellTableSchema};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;

expect(is_file(dirname(__DIR__).'/Domains/Catalogue/CallerRouting.php'), 'Shared caller seam must retain the legacy body without an active target');
(new Installation)->install(); extract(commerceFixture());
// C1 routes the existing services through their unchanged legacy bodies. Converted offer data
// deliberately disagrees with legacy; no test/deployment flag can select it through these hooks.
\Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting::legacy(static function (): void {
    same('legacy', \Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting::legacy(static fn () => 'legacy'), 'Nested caller hooks remain legacy');
});
$legacy = $sellers->selection('com', 'register', 1, $now);
(new \Paymenter\Extensions\Servers\Domains\Tests\OfferFixture)->batch(CatalogueAcceptances::binding(Account::where('server_id', $tlds[0]->server_id)->sole()));
DB::table('domains_tlds')->where('id', $tlds[0]->id)->update(['catalogue_retained_annual_retail' => '99.00',
    'catalogue_intent' => '{"sell":false}', 'catalogue_facts' => null]);
same($legacy->jsonSerialize(), $sellers->selection('com', 'register', 1, $now)->jsonSerialize(), 'Seller hook keeps legacy selection behavior');
same($legacy->priceId, $sellers->selection('com', 'register', 1, $now)->priceId, 'Seller hook keeps the same legacy price identity');
same('7.80', $prices->current($tlds[0]->server_id, $tlds[0]->account_fingerprint, 'com', 'register', 1, 'USD', $now)->retail, 'Price hook keeps legacy current evidence');
$domain = new \Paymenter\Extensions\Servers\Domains\Models\Domain(['server_id' => $tlds[0]->server_id,
    'account_fingerprint' => $tlds[0]->account_fingerprint, 'tld' => 'com']);
same('10.40', (new \Paymenter\Extensions\Servers\Domains\Services\HolderPricing($prices))->renewal($domain, 'USD', $now)->retail, 'Holder hook ignores dormant retained retail');
$batch = DB::transaction(fn () => $sellers->admit([$legacy], $now));
same($legacy->priceId, $batch[0]->id, 'Locked seller hook keeps legacy admission');
same(['com'], array_keys($sellers->displayCatalogues(['com'])), 'Seller display hook keeps its legacy shape');

$account = Account::where('server_id', $tlds[0]->server_id)->sole();
$binding = CatalogueAcceptances::binding($account); $accepted = CatalogueAcceptances::load($binding);
$clock = new FakeClock($now); $budget = new AccountReadBudget($clock);
app()->instance(AccountBindings::class, $bindings);
$worker = new ObservationWork(new DomainObservation($bindings, $clock, $budget), $clock, $budget);
$worker->enroll($binding->serverId);
$sync = new PriceSync($bindings, new CatalogueSync($bindings, $prices), $clock);
$gate = new UpgradeGate; $armed = false; $paused = false;
DB::listen(static function ($query) use (&$armed, &$paused, $gate): void {
    if ($armed && DB::transactionLevel() === 0 && in_array(UpgradeGate::KEY, $query->bindings, true)) {
        $armed = false; $gate->pause(); $paused = true;
    }
});
$failures = [];
$probe = static function (string $name, Closure $action, Closure $unchanged) use (&$armed, &$paused, &$failures, $gate): void {
    $armed = true; $paused = false;
    try { $action(); } catch (DomainException) {}
    $armed = false;
    if (!$paused || !$unchanged()) { $failures[] = $name; }
    if ($paused) { $gate->resume(); }
};
$count = DB::table(SellTableSchema::ROWS)->count();
$probe('page ensure writes after pause', fn () => SellRule::ensure($binding, $accepted),
    fn () => DB::table(SellTableSchema::ROWS)->count() === $count);
$probe('seller initialization writes after pause', fn () => $sellers->initialize('freshsuffix'),
    fn () => !DB::table('domains_sales_policies')->where('tld', 'freshsuffix')->exists());
$before = (array) DB::table('domains_observation_queues')->where('server_id', $binding->serverId)->sole();
$probe('observation turn writes after pause', fn () => $worker->tick(1),
    fn () => (array) DB::table('domains_observation_queues')->where('server_id', $binding->serverId)->sole() === $before);
config(['domains.enom_identity.environment' => 'test', 'domains.enom_catalogue.enabled' => true]);
$before = (array) DB::table('domains_observation_queues')->where('server_id', $binding->serverId)->sole();
$probe('daily claim or outcome writes after pause', fn () => $sync->daily($account),
    fn () => (array) DB::table('domains_observation_queues')->where('server_id', $binding->serverId)->sole() === $before);

// Interleave a complete pause/resume after the first transaction commits, rather than at entry.
$boundary = null; $lateWrites = []; $trace = []; $tracing = false;
DB::listen(static function ($query) use (&$boundary, &$lateWrites, &$trace, &$tracing, $gate): void {
    if ($tracing) { $trace[] = $query->sql; }
    if ($boundary !== null && str_starts_with($query->sql, 'update "domains_observation_queues"') && str_contains($query->sql, $boundary)) {
        $boundary = null;
        DB::afterCommit(static function () use ($gate): void { $gate->pause(); $gate->resume(); });
    } elseif (str_starts_with($query->sql, 'update "domains_observation_queues"')) { $lateWrites[] = $query->sql; }
});
$boundary = '"turn"'; $lateWrites = [];
$worker->tick(1);
if ($boundary !== null || $lateWrites !== []) { $failures[] = 'observation discovery writes after a committed window transition'; }
$boundary = '"catalogue_cursor"'; $lateWrites = [];
try { $sync->daily($account); } catch (DomainException) {}
if ($boundary !== null || $lateWrites !== []) { $failures[] = 'daily outcome writes after a committed window transition'; }

// A stopped daily generation must not refresh acceptance before its later queue claim refuses.
$server = App\Models\Server::findOrFail($binding->serverId);
$server->settings()->updateOrCreate(['key' => SellRule::KEY], ['type' => 'array', 'encrypted' => false,
    'value' => ['mode' => 'except', 'max_cost' => null, 'years' => 1, 'transfers' => false]]);
\Paymenter\Extensions\Servers\Domains\Models\SellRow::updateOrCreate(
    ['server_id' => $binding->serverId, 'account_fingerprint' => $binding->fingerprint, 'tld' => 'com'],
    ['choice' => 'skip', 'register_cost' => '6.0000', 'renew_cost' => '8.0000', 'high_cost' => '8.0000',
        'prices' => [1 => ['register' => '6.0000', 'renew' => '8.0000']], 'read_at' => $clock->now()->getTimestamp()]);
$rotateEntry = false; $rotated = false;
DB::listen(static function ($query) use (&$rotateEntry, &$rotated, $gate): void {
    if ($rotateEntry && DB::transactionLevel() === 0 && in_array(UpgradeGate::KEY, $query->bindings, true)) {
        $rotateEntry = false; $gate->pause(); $gate->resume(); $rotated = true;
    }
});
foreach (['daily', 'run'] as $entry) {
    $beforeAcceptance = CatalogueAcceptances::load($binding);
    $beforeAudit = $server->settings()->where('key', \Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::KEY)->first()?->getRawOriginal();
    $beforeQueue = (array) DB::table('domains_observation_queues')->where('server_id', $binding->serverId)->sole();
    $rotateEntry = true; $rotated = false;
    try { $entry === 'daily' ? $sync->daily($account) : $sync->run($account, $clock, null); } catch (DomainException) {}
    $rotateEntry = false;
    same(true, $rotated, 'Pause/resume interrupts the starting '.$entry.' generation before refresh');
    same($beforeAcceptance->revision, CatalogueAcceptances::load($binding)->revision, 'Stopped '.$entry.' generation cannot narrow acceptance before its queue guard');
    same($beforeAcceptance->endings, CatalogueAcceptances::load($binding)->endings, 'Stopped '.$entry.' preserves accepted endings');
    same($beforeAudit, $server->settings()->where('key', \Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview::KEY)->first()?->getRawOriginal(), 'Stopped '.$entry.' adds no acceptance audit');
    same($beforeQueue, (array) DB::table('domains_observation_queues')->where('server_id', $binding->serverId)->sole(), 'Stopped '.$entry.' leaves its queue unchanged');
}

// SQLite does not implement row locks. Check the actual read order for the formerly mutex-free clear.
$tracing = true;
$policy = DB::table('domains_sales_policies')->where('tld', 'com')->sole(); $trace = [];
$sellers->change('com', (int) $policy->revision, null, false, false, $now); $tracing = false;
$policyAt = $serverAt = $accountAt = null;
$serverTable = (new App\Models\Server)->getTable();
foreach ($trace as $i => $sql) {
    if (str_contains($sql, 'from "domains_sales_policies"') && $policyAt === null) { $policyAt = $i; }
    if (str_contains($sql, 'from "'.$serverTable.'"') && $serverAt === null) { $serverAt = $i; }
    if (str_contains($sql, 'from "domains_accounts"') && $accountAt === null) { $accountAt = $i; }
}
if ($serverAt === null || $accountAt === null || $policyAt === null || !($serverAt < $accountAt && $accountAt < $policyAt)) {
    $failures[] = 'seller clear lacks the shared server/account prefix';
}
same([], $failures, implode('; ', $failures));
$nativeSuccessful = true;
echo "Legacy write mutexes: ok\n";
