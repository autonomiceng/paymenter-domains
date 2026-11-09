<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;
use Paymenter\Extensions\Servers\Domains\Services\AccountBindings;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Account;

/** Local loader. Authorization and readiness callbacks must never resolve a provider.
 * Locked batches join checkout AFTER all native item/product/plan/price locks. */
final class OfferReader
{
    public function __construct(private readonly Clock $clock, private readonly \Closure $authorize,
        private readonly \Closure $ready, private readonly ?\Closure $current = null) {}

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
    { return $this->load($queries, $context, $lock); }

    /** One bounded public display invocation; never receipt reuse or locked admission. */
    public function displayBatch(array $queries, Context $context = Context::Public): array
    { return $this->load($queries, $context, false, true); }

    private function load(array $queries, Context $context, bool $lock, bool $display = false): array
    {
        if ($display && DB::transactionLevel() !== 0) { throw new \LogicException('Display requires unlocked reads.'); }
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
        if ($this->current) { ($this->current)(); }
        $defaults = $readiness = [];
        foreach ($bindings as $id => $binding) {
            $server = $servers[$id] ?? throw new \DomainException('Offer server disappeared.');
            $account = $accounts[$id] ?? throw new \DomainException('Offer account disappeared.');
            $binding->requireMatch(AccountBindings::binding($account));
            // Display leaves defaults it can't decode to each ending's own refusal, so a staff page still opens to repair them.
            try { $defaults[$id] = OfferState::decode($account->catalogue_defaults); }
            catch (\JsonException|\TypeError $error) { if (!$display) { throw $error; } $defaults[$id] = null; }
            foreach ($queries as $i => $query) {
                if ($query->binding->serverId !== $id) { continue; }
                ($this->authorize)($query, $context);
                $readiness[$i] = !$display && $server->enabled && $server->extension === 'Domains'
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
        $tlds = $fetch(OfferState::query('domains_tlds')->where($scope)->orderBy('id'))->get()->keyBy(static fn ($t) => $t->server_id.':'.$t->tld);
        $prices = $fetch(OfferState::query('domains_prices')->where($scope)->orderBy('id'))->get()->groupBy(static fn ($p) => $p->server_id.':'.$p->tld);
        $freshness = \Paymenter\Extensions\Servers\Domains\Data\CatalogueFreshness::forAccounts($ids, $lock);
        if ($display) { return $this->displayRows($queries, $servers, $accounts, $defaults, $policies, $tlds, $prices, $context, $freshness); }
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
                    $snapshot = null;
                    $decision = new Decision($name, $query->action, $query->term, false, null,
                        new Revision(0, $d['_revision'] ?? 0, (int) ($policy?->revision ?? 0)), 'unavailable');
                } else {
                    if (!$tld->catalogue_converted) { throw new \DomainException('Offer conversion changed.'); }
                    $snapshot = OfferState::snapshot($binding, $tld, $d, $policy, $rows, $readiness[$i], freshness: $freshness[$binding->serverId]);
                    $decision = $evaluator->evaluate($snapshot, $query, $context, $now);
                }
                $price = $rows->first(static fn ($p) => $p->action === $query->action && (int) $p->term === $query->term && $p->currency === $query->currency);
                $batch[] = new ReadOffer($decision, $binding, $price, $context, $freshness[$binding->serverId], $snapshot ?? null, $query);
            }
            $result[] = $batch;
        }
        return $result;
    }

    private function displayRows(array $queries, $servers, $accounts, array $defaults, $policies, $tlds, $prices, Context $context, array $freshness): array
    {
        $result = []; $ready = []; $evaluator = new Evaluator; $now = $this->clock->now();
        foreach ($queries as $query) {
            $b = $query->binding; $id = $b->serverId; $batch = [];
            foreach ($query->tlds as $name) {
                $batch[$name] = [];
                try {
                    $tld = $tlds[$id.':'.$name] ?? null; $rows = $prices[$id.':'.$name] ?? collect();
                    if (!$tld || !$tld->catalogue_converted) { continue; }
                    foreach ([$tld, ...$rows] as $row) {
                        if ($row->account_fingerprint !== $b->fingerprint || $row->registrar_key !== $b->registrarKey) { throw new \DomainException('Offer binding changed.'); }
                    }
                    foreach (['register', 'renew', 'transfer'] as $action) {
                        $seed = new Query($b, [$name], $action);
                        $key = $id.':'.$action;
                        $ready[$key] ??= $servers[$id]->enabled && $servers[$id]->extension === 'Domains'
                            && ($this->ready)($b, $servers[$id], $accounts[$id], $seed, $context);
                        $snapshot = OfferState::snapshot($b, $tld, $defaults[$id] ?? throw new \DomainException('Account defaults unreadable.'), $policies[$name] ?? null, $rows, $ready[$key], freshness: $freshness[$id]);
                        foreach ($action === 'register' ? range(1, 10) : [1] as $term) {
                            // load() authorized this query's binding and context once; per-term reads add no new grant.
                            $q = new Query($b, [$name], $action, $term);
                            $d = $evaluator->evaluate($snapshot, $q, $context, $now);
                            $row = $rows->first(static fn ($p) => $p->action === $action && (int)$p->term === $term && $p->currency === 'USD');
                            $batch[$name][$action.':'.$term] = new ReadOffer($d, $b, $row, $context, $freshness[$id]);
                        }
                    }
                } catch (\Throwable) { $batch[$name] = []; } // One malformed ending cannot poison its siblings.
            }
            $result[] = $batch;
        }
        return $result;
    }
}
