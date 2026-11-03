<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
expect(is_file(dirname(__DIR__).'/Domains/Catalogue/OfferScheduler.php'), 'One shared account scheduler is required');
(new Paymenter\Extensions\Servers\Domains\Services\Installation)->install();
$installedFixture = true;
require __DIR__.'/Support/management.php';

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{FactsObserved, OfferScheduler, OfferWork, PricedFeed, Projection, UpgradeConversion, UpgradeGate, Writer};
use Paymenter\Extensions\Servers\Domains\Data\RequestContext;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget, CatalogueAcceptances, DomainObservation, HoldingObservationWork, ObservationWork};

[$d,,$original] = $holding(placeholderComFixtureName('shared-holder-placeholder.com'));
$b = CatalogueAcceptances::binding(Account::where('server_id', $d->server_id)->sole());
foreach (Account::orderBy('server_id')->get() as $a) { (new UpgradeConversion)->batch(CatalogueAcceptances::binding($a)); }
$bindings->setProcessing($d->server_id, false);
$frozen = serialize($original->fresh()->getAttributes());
$generation = (new UpgradeGate)->pause();
$guard = static function (?int $server = null) use ($generation): void {
    if (DB::transactionLevel() > 0) {
        Server::where('extension', 'Domains')->when($server !== null, fn ($q) => $q->whereKey($server))->orderBy('id')->lockForUpdate()->get();
        Account::when($server !== null, fn ($q) => $q->where('server_id', $server))->orderBy('server_id')->lockForUpdate()->get();
        UpgradeGate::paused($generation);
    } elseif (UpgradeGate::generation() !== $generation) { throw new DomainException('Synthetic generation changed.'); }
};
$feed = new class($observation) implements PricedFeed {
    public int $calls = 0;
    public array $accounts = [];
    public function __construct(private object $observation) {}
    public function priceReads(): int { return 1; }
    public function factsReads(): int { return 0; }
    public function prices(RequestContext $c): iterable {
        same(0, DB::transactionLevel(), 'Feed runs outside locks');
        expect(count($this->observation->calls) > 0, 'Due holder read precedes a failing feed');
        $this->calls++; $id = $c->binding->serverId; $this->accounts[$id] = ($this->accounts[$id] ?? 0) + 1;
        throw new RuntimeException('Synthetic feed unavailable'); yield;
    }
    public function facts(string $tld, RequestContext $c): FactsObserved { throw new DomainException('No facts'); }
};
$budget = new AccountReadBudget($clock, 25, 25);
$worker = new OfferWork(new Writer(new Projection($native), $clock, static fn () => null, static fn () => false, current: $guard),
    $clock, $budget, static fn () => $feed, static fn () => 25, null, $generation, $guard);
$observer = new DomainObservation($readBindings, $clock, $budget);
$scheduler = new OfferScheduler($worker, new HoldingObservationWork($observer, $clock), $clock, $guard);
same(null, OfferScheduler::routed(), 'Production remains legacy until the whole C6 cutover');
$scheduler->enroll($b->serverId); $scheduler->enroll($b->serverId);
$scheduler->sync($b); $scheduler->sync($b);
$out = $scheduler->tick(1);
same('refreshed', $out[$b->serverId]['holding'], 'Holder reads continue with processing paused');
same(1, $feed->calls, 'Coalesced sync starts one failing feed');
same('retrying', $scheduler->status($b)['state'], 'Feed failure is durable and honest');
same($frozen, serialize($original->fresh()->getAttributes()), 'Shared scheduler preserves financial authority bytes');
$reads = count($observation->calls); $scheduler->tick(2);
same($reads, count($observation->calls), 'Durable due times prevent a duplicate holder read');
same(1, $feed->accounts[$b->serverId], 'Backoff survives repeated scheduler starts while other accounts get turns');
commerceReject(fn () => (new ObservationWork($observer, $clock, $budget))->tick(), 'Legacy consumer cannot run in the composed fixture window');
$q = DB::table('domains_observation_work')->where('domain_id', $d->id);
$q->update(['due_at' => $clock->now()->getTimestamp()]);
$before = $d->fresh()->observation;
$physical = count($observation->calls);
$observation->during = static fn () => (new UpgradeGate)->resume();
$scheduler->tick(2);
same($physical + 1, count($observation->calls), 'Generation change stops the remaining physical holding reads');
same($before, $d->fresh()->observation, 'Generation change between read and commit cannot save a holding projection');
same(null, $q->value('claim'), 'Superseded holding releases its own claim');
$nativeSuccessful = true;
echo "Shared holding/offer scheduler checks passed.\n";
