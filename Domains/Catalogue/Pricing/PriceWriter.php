<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue\Pricing;

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{FeedObserved, OfferState, Pricing, Retail};
use Paymenter\Extensions\Servers\Domains\Catalogue\Pricing\CurrentCost;
use Paymenter\Extensions\Servers\Domains\Data\CatalogueFreshness;
use Paymenter\Extensions\Servers\Domains\Models\Tld;
use Paymenter\Extensions\Servers\Domains\Services\{CardFee, PricingRules};

/** Registrar cost observations and the retail they publish, inside the offer writer's locks. */
final class PriceWriter
{
    /** Save newer costs from a feed; an equal observation must match the saved one exactly. */
    public function observe(FeedObserved $command, Tld $target, $prices, \DateTimeImmutable $now): bool
    {
        $changed = false;
        foreach ($command->costs as $cost) {
            if ($cost->observedAt > $now) { throw new \DomainException('Future cost observation.'); }
            $p = $prices->first(static fn ($p) => $p->action === $cost->action && (int) $p->term === $cost->term && $p->currency === 'USD');
            // A saved cost that can't be read has no time or value to compare, so this observation replaces it.
            try { $old = $p?->cost_enabled === null ? null : CurrentCost::read($p); }
            catch (\Throwable) { $old = null; }
            if ($old && $old->observedAt > $cost->observedAt) { continue; }
            if ($old && $old->observedAt == $cost->observedAt) {
                $a = OfferState::encodeCost($old); $b = OfferState::encodeCost($cost);
                unset($a['source_unit'], $b['source_unit'], $a['observed_at'], $b['observed_at'], $a['fresh_until'], $b['fresh_until']);
                if ($a !== $b || $old->freshUntil != $cost->freshUntil) {
                    throw new \DomainException('Conflicting cost observation.');
                }
                continue;
            }
            $values = CurrentCost::values($cost);
            if ($p) { DB::table('domains_prices')->where('id', $p->id)->update($values); }
            else {
                DB::table('domains_prices')->insert([...$values, 'server_id' => $target->server_id, 'registrar_key' => $target->registrar_key,
                    'account_fingerprint' => $target->account_fingerprint, 'tld' => $target->tld, 'action' => $cost->action,
                    'term' => $cost->term, 'currency' => 'USD', 'catalogue_created' => true]);
            }
            $changed = true;
        }
        return $changed;
    }

    /** Shared settings fail the account. Only an unreadable TLD override is isolated. */
    public function rule(Tld $tld): ?array
    {
        $fee = CardFee::read();
        $default = (new PricingRules)->defaultRule(false, $fee);
        return Pricing::publicationRule($tld, $default, $fee);
    }

    /** Write retail for every readable saved cost; false keeps the offer closed for an unreadable own rule. */
    public function publish(Tld $tld, \DateTimeImmutable $now): bool
    {
        $rule = $this->rule($tld);
        if ($rule === null) { return false; }
        $freshness = CatalogueFreshness::forAccount((int) $tld->server_id);
        $prices = OfferState::query('domains_prices')->where('server_id', $tld->server_id)->where('tld', $tld->tld)->where('currency', 'USD')->orderBy('id')->get();
        $costs = $readable = [];
        foreach ($prices as $p) {
            if (($cost = OfferState::savedCost($p)) !== null) {
                $cost = $freshness->cost($cost); $costs[$cost->key()] = $cost; $readable[$p->id] = true;
            }
        }
        foreach ($prices as $p) {
            // A damaged cost keeps its row unchanged and its offer closed until a price sync replaces it.
            if (!isset($readable[$p->id])) { continue; }
            $cost = $costs[$p->action.':'.$p->term];
            [$retail, $annual] = Retail::publication($cost, $rule, $costs, $now, OfferState::money($tld->catalogue_retained_annual_retail, 2));
            if ($cost->key() === 'renew:1') { $tld->catalogue_retained_annual_retail = $annual; }
            DB::table('domains_prices')->where('id', $p->id)->update(['retail' => $retail, 'revision' => (int) $p->revision + 1]);
        }
        return true;
    }
}
