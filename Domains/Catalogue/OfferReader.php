<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\CatalogueAcceptances;

/** Local batch loader. B6 supplies scoped authorization and local feature/processing readiness.
 * Locked reads join checkout AFTER its native locks. No callback may perform provider I/O. */
final class OfferReader
{
    public function __construct(private readonly Clock $clock, private readonly \Closure $authorize,
        private readonly \Closure $ready) {}

    public function readOffers(Query $query, Context $context): array
    {
        ($this->authorize)($query, $context);
        $lock = $context === Context::Locked;
        if ($lock && DB::transactionLevel() === 0) { throw new \LogicException('Locked reads require caller native locks and transaction.'); }
        $fetch = static function ($q) use ($lock) { return $lock ? $q->lockForUpdate() : $q; };
        $server = $fetch(Server::whereKey($query->binding->serverId))->firstOrFail();
        $account = $fetch(Account::where('server_id', $server->id))->sole();
        $query->binding->requireMatch(CatalogueAcceptances::binding($account));
        $defaults = OfferState::decode($account->catalogue_defaults);
        $policies = $fetch(DB::table('domains_sales_policies')->whereIn('tld', $query->tlds)->orderBy('tld'))->get()->keyBy('tld');
        $tlds = $fetch(UpgradeComparison::query('domains_tlds')->where('server_id', $server->id)->whereIn('tld', $query->tlds)->orderBy('id'))->get();
        $prices = $fetch(UpgradeComparison::query('domains_prices')->where('server_id', $server->id)->whereIn('tld', $query->tlds)->orderBy('id'))->get()->groupBy('tld');
        $ready = $server->enabled && $server->extension === 'Domains' && ($this->ready)($query->binding);
        $result = []; $evaluator = new Evaluator; $now = $this->clock->now();
        foreach ($tlds as $tld) {
            if ($tld->account_fingerprint !== $query->binding->fingerprint || $tld->registrar_key !== $query->binding->registrarKey
                || !$tld->catalogue_converted) { throw new \DomainException('Offer binding or conversion changed.'); }
            $rows = $prices[$tld->tld] ?? collect();
            foreach ($rows as $p) {
                if ($p->account_fingerprint !== $query->binding->fingerprint || $p->registrar_key !== $query->binding->registrarKey) {
                    throw new \DomainException('Price binding changed.');
                }
            }
            $snapshot = OfferState::snapshot($query->binding, $tld, $defaults, $policies[$tld->tld] ?? null, $rows, $ready);
            $result[$tld->tld] = $evaluator->evaluate($snapshot, $query, $context, $now);
        }
        // Preserve query order and fail closed when a candidate disappeared.
        foreach ($query->tlds as $name) {
            $result[$name] ??= new Decision($name, $query->action, $query->term, false, null,
                new Revision(0, $defaults['_revision'] ?? 0, (int) (($policies[$name] ?? null)?->revision ?? 0)), 'unavailable');
        }
        return array_map(static fn ($name) => $result[$name], $query->tlds);
    }
}
