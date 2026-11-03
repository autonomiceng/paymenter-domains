<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$deferDomainsBootstrap = true;
require __DIR__.'/mariadb-bootstrap.php';
require __DIR__.'/FakeRegistrar.php';
use App\Models\{Role, Server, User};
use Illuminate\Support\Facades\{Artisan, DB, Schema};
use Paymenter\Extensions\Servers\Domains\Catalogue\{FactsObserved, OfferScheduler, OfferWork, PricedFeed, Projection, UpgradeConversion, UpgradeGate, Writer};
use Paymenter\Extensions\Servers\Domains\Contracts\AccountVerifier;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Jobs\SyncPrices;
use Paymenter\Extensions\Servers\Domains\Data\{Environment, RequestContext};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AccountReadBudget, CatalogueAcceptances, DomainObservation, HoldingObservationWork, Installation, NativeCatalogue, ObservationWork, PriceCatalogue, PricingRules, RegistrarRegistry};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
$phase = getenv('OFFER_PHASE'); $prefix = (string)getenv('SCHEMA_PREFIX');
if ($phase === 'seed') {
    Artisan::call('migrate', ['--force'=>true]);
    if ($prefix !== '') { foreach (Schema::getTableListing(DB::connection()->getDatabaseName(), false) as $table) { Schema::rename($table, $prefix.$table); } }
}
config(['database.connections.mysql.prefix'=>$prefix]); DB::purge('mysql'); Schema::clearResolvedInstance('db.schema');
expect(is_file(dirname(__DIR__,2).'/Domains/Catalogue/OfferScheduler.php'), 'Installed shared scheduler required');
$clock = new FakeClock(new DateTimeImmutable('2026-11-02T20:00:00Z'));
$registry = new RegistrarRegistry;
$registry->register('enom', ['token'], new class implements AccountVerifier {
    public function verify(Environment $e, array $c): string { return $c['token']; }
}, static fn () => throw new LogicException('No provider reader'));
$bindings = new AccountBindings($registry);
if ($phase === 'seed') {
    (new Installation)->install();
    $server = Server::create(['name'=>'Synthetic race', 'extension'=>'Domains', 'type'=>'server', 'enabled'=>true]);
    $b = $bindings->configure($server->id, 'enom', Environment::Test, ['token'=>'synthetic-account']);
    $role = Role::create(['name'=>'Synthetic import staff','permissions'=>[Staff::VIEW,Staff::POLICY,'admin.domains.settings.import']]);
    User::create(['first_name'=>'Synthetic','last_name'=>'Staff','email'=>'race-staff@example.test','password'=>'synthetic-password','role_id'=>$role->id]);
    (new UpgradeConversion)->batch($b); (new UpgradeGate)->pause();
    Schema::create('offer_fixture_reads', static function ($t): void { $t->id(); });
} else { $b = CatalogueAcceptances::binding(Account::sole()); }
$generation = UpgradeGate::generation();
$guard = static function (?int $server = null) use ($generation): void {
    if (DB::transactionLevel() > 0) {
        Server::where('extension','Domains')->orderBy('id')->lockForUpdate()->get();
        Account::orderBy('server_id')->lockForUpdate()->get(); UpgradeGate::paused($generation);
    } elseif (UpgradeGate::generation() !== $generation) { throw new DomainException('Synthetic stale generation'); }
};
$feed = new class implements PricedFeed {
    public function priceReads(): int { return 1; }
    public function factsReads(): int { return 0; }
    public function facts(string $tld, RequestContext $c): FactsObserved { throw new DomainException('No facts'); }
    public function prices(RequestContext $c): iterable {
        same(0, DB::transactionLevel(), 'Physical synthetic read is outside locks');
        DB::table('offer_fixture_reads')->insert(['id'=>null]);
        file_put_contents(getenv('OFFER_BARRIER').'.ready', 'ready');
        $until = microtime(true) + 30;
        while (!is_file(getenv('OFFER_BARRIER').'.release') && microtime(true) < $until) { usleep(1000); }
        expect(is_file(getenv('OFFER_BARRIER').'.release'), 'Second connection completed before release');
        return; yield;
    }
};
$budget = new AccountReadBudget($clock,25,25);
$writer = new Writer(new Projection(new NativeCatalogue(new PriceCatalogue(new PricingRules))), $clock, static fn () => null, static fn () => false, current:$guard);
$worker = new OfferWork($writer,$clock,$budget,static fn () => $feed,static fn () => 25,null,$generation,$guard);
$observer = new DomainObservation($bindings,$clock,$budget);
$scheduler = new OfferScheduler($worker,new HoldingObservationWork($observer,$clock),$clock,$guard);
if ($phase === 'seed') { $scheduler->sync($b); }
elseif ($phase === 'first') { $scheduler->tick(1); }
elseif ($phase === 'second') {
    $scheduler->sync($b); $out = $scheduler->tick(1);
    same('busy', $out[$b->serverId]['offer'], 'Independent connection cannot steal a live feed claim');
    $refused = false;
    try { (new ObservationWork($observer,$clock,$budget))->tick(1); } catch (DomainException) { $refused = true; }
    same(true,$refused,'Legacy consumer is fenced in the same installed database');
    $staff = User::sole();
    expect(Staff::allows($staff,'admin.domains.settings.import'),'Old queued job retains actual staff permission');
    $old = new SyncPrices($staff->id,$b->serverId,$b->fingerprint); $old->upgradeGeneration = 0; $old->handle();
    same("Couldn't sync prices",\App\Models\Notification::where('user_id',$staff->id)->latest('id')->value('title'),'Queued old job refuses inside the same installed race');
} elseif ($phase === 'verify') {
    same(1,DB::table('offer_fixture_reads')->count(),'Two connections start only one physical synthetic feed');
    $q = DB::table('domains_observation_queues')->sole();
    same(1,(int)$q->offer_feed_run,'One claim/run owns the shared feed');
    same(null,$q->offer_claim,'Completed worker releases its own lease');
    same(0,DB::table('domains_prices')->count(),'No legacy or invented price writes');
    echo json_encode(['complete'=>true,'prefix'=>$prefix,'two_connections'=>true,'one_feed'=>true,'real_provider_calls'=>0])."\n";
} else { throw new LogicException('Unknown phase'); }
$nativeSuccessful = true;
