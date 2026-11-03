<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances;

/** Local loader. Authorization and readiness callbacks must never resolve a provider.
 * Locked batches join checkout AFTER all native item/product/plan/price locks. */
final class OfferReader
{
    public function __construct(private readonly Clock $clock, private readonly \Closure $authorize,
        private readonly \Closure $ready) {}

    public function readOffers(Query $query, Context $context): array
    { return $this->readBatch([$query], $context)[0]; }

    /** @param non-empty-list<Query> $queries @return list<list<Decision>> */
    public function readBatch(array $queries, Context $context, bool $lock = false): array
    {
        return array_map(static fn ($batch) => array_map(static fn (ReadOffer $row) => $row->decision, $batch),
            $this->loadBatch($queries, $context, $lock));
    }

    /** Server-only DTO mapping shares exactly the same loaded rows and evaluator as readBatch.
     * Lock mode is independent of Holder business context; it never turns it into public admission.
     * @param non-empty-list<Query> $queries @return list<list<ReadOffer>> */
    public function loadBatch(array $queries, Context $context, bool $lock = false): array
    {
        $lock = $lock || $context === Context::Locked;
        if ($lock && DB::transactionLevel() === 0) { throw new \LogicException('Locked reads require caller native locks and transaction.'); }
        if ($queries === [] || !array_is_list($queries) || count($queries) > 500) { throw new \InvalidArgumentException('Invalid offer batch.'); }
        $bindings = []; $names = []; $count = 0;
        foreach ($queries as $query) {
            if (!$query instanceof Query || ($count += count($query->tlds)) > 500) { throw new \InvalidArgumentException('Invalid offer batch.'); }
            ($this->authorize)($query, $context);
            $id = $query->binding->serverId;
            if (isset($bindings[$id])) { $bindings[$id]->requireMatch($query->binding); }
            $bindings[$id] = $query->binding;
            foreach ($query->tlds as $name) { $names[$name] = true; }
        }
        ksort($bindings, SORT_NUMERIC); $ids = array_keys($bindings); $names = array_keys($names); sort($names, SORT_STRING);
        $fetch = static fn ($q) => $lock ? $q->lockForUpdate() : $q;
        // Acquire the whole prefix once. Never lock another account after policies or offers.
        $servers = $fetch(Server::whereIn('id', $ids)->orderBy('id'))->get()->keyBy('id');
        $accounts = $fetch(Account::whereIn('server_id', $ids)->orderBy('server_id'))->get()->keyBy('server_id');
        $defaults = $readiness = [];
        foreach ($bindings as $id => $binding) {
            $server = $servers[$id] ?? throw new \DomainException('Offer server disappeared.');
            $account = $accounts[$id] ?? throw new \DomainException('Offer account disappeared.');
            $binding->requireMatch(CatalogueAcceptances::binding($account));
            $defaults[$id] = OfferState::decode($account->catalogue_defaults);
            foreach ($queries as $i => $query) {
                if ($query->binding->serverId !== $id) { continue; }
                ($this->authorize)($query, $context);
                $readiness[$i] = $server->enabled && $server->extension === 'Domains'
                    && ($this->ready)($binding, $server, $account, $query, $context);
            }
        }
        $policies = $fetch(DB::table('domains_sales_policies')->whereIn('tld', $names)->orderBy('tld'))->get()->keyBy('tld');
        // Exact account/ending pairs avoid loading another account's unrelated cart candidates.
        $scope = static function ($q) use ($queries): void {
            foreach ($queries as $query) {
                $q->orWhere(static fn ($q) => $q->where('server_id', $query->binding->serverId)->whereIn('tld', $query->tlds));
            }
        };
        $tlds = $fetch(UpgradeComparison::query('domains_tlds')->where($scope)->orderBy('id'))->get()->keyBy(static fn ($t) => $t->server_id.':'.$t->tld);
        $prices = $fetch(UpgradeComparison::query('domains_prices')->where($scope)->orderBy('id'))->get()->groupBy(static fn ($p) => $p->server_id.':'.$p->tld);
        $result = []; $evaluator = new Evaluator; $now = $this->clock->now();
        foreach ($queries as $i => $query) {
            $binding = $query->binding; $d = $defaults[$binding->serverId]; $batch = [];
            foreach ($query->tlds as $name) {
                $key = $binding->serverId.':'.$name; $tld = $tlds[$key] ?? null; $policy = $policies[$name] ?? null;
                $rows = $prices[$key] ?? collect();
                foreach ([$tld, ...$rows] as $row) {
                    if ($row !== null && ($row->account_fingerprint !== $binding->fingerprint || $row->registrar_key !== $binding->registrarKey)) {
                        throw new \DomainException('Offer or price binding changed.');
                    }
                }
                if ($tld === null) {
                    $decision = new Decision($name, $query->action, $query->term, false, null,
                        new Revision(0, $d['_revision'] ?? 0, (int) ($policy?->revision ?? 0)), 'unavailable');
                } else {
                    if (!$tld->catalogue_converted) { throw new \DomainException('Offer conversion changed.'); }
                    $snapshot = OfferState::snapshot($binding, $tld, $d, $policy, $rows, $readiness[$i]);
                    $decision = $evaluator->evaluate($snapshot, $query, $context, $now);
                }
                $price = $rows->first(static fn ($p) => $p->action === $query->action && (int) $p->term === $query->term && $p->currency === $query->currency);
                $batch[] = new ReadOffer($decision, $binding, $price, $context);
            }
            $result[] = $batch;
        }
        return $result;
    }
}
