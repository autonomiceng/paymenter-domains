<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/accounts.php';

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AccountReadBudget, CatalogueAcceptances, CatalogueSync, DomainObservation, Installation, ObservationWork, PriceSync, SellRule, SellTableSchema};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;

(new Installation)->install(); extract(commerceFixture());
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
