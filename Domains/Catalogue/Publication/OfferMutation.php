<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue\Publication;

use Paymenter\Extensions\Servers\Domains\Catalogue\{Command, Edit, FactsObserved, FeedObserved, OfferState};
use Paymenter\Extensions\Servers\Domains\Catalogue\Pricing\PriceWriter;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Models\Tld;

/** Applies one checked command to the locked offer row; publication follows only when something changed. */
final class OfferMutation
{
    public function __construct(private readonly Clock $clock, private readonly PriceWriter $prices) {}

    public function apply(Command $command, Tld $target, $prices, \DateTimeImmutable $now): bool
    {
        return match (true) {
            $command instanceof Edit => $this->edit($command, $target),
            $command instanceof FactsObserved => $this->facts($command, $target, $prices, $now),
            default => $this->costs($command, $target, $prices, $now),
        };
    }

    private function edit(Edit $command, Tld $target): bool
    {
        if ($command->kind === 'override' || in_array($command->kind, ['seller', 'sales_setup'], true) && $command->intent !== null) {
            $old = OfferState::decode($target->catalogue_intent);
            foreach (['sell', 'longest', 'transfers', 'stops', 'salesPaused'] as $key) { $old[$key] = $command->intent->{$key}; }
            $target->catalogue_intent = OfferState::json(($command->kind === 'override' || $command->kind === 'sales_setup' && $command->pricing !== 'keep') ? [...$old, 'pricing_rule' => $command->rule] : $old);
        }
        if ($command->kind === 'retail') { $target->catalogue_retained_annual_retail = $command->retail; }
        return true;
    }

    private function facts(FactsObserved $command, Tld $target, $prices, \DateTimeImmutable $now): bool
    {
        $old = OfferState::facts($target->catalogue_facts); $new = $command->facts;
        $costs = [];
        foreach ($prices as $p) { if (($cost = OfferState::savedCost($p)) !== null) { $costs[] = $cost; } }
        $new = (new FeedObserved($command->binding(), $command->tld(), $costs))->facts($new);
        if ($new->observedAt > $now) { throw new \DomainException('Future facts observation.'); }
        if ($old && $old->observedAt > $new->observedAt) { return false; }
        if ($old && $old->observedAt == $new->observedAt) {
            $before = OfferState::decode(OfferState::encodeFacts($old)); $after = OfferState::decode(OfferState::encodeFacts($new));
            if (OfferState::equivalent($before, $after)) { return false; }
            // Recover TEST rows whose optional evidence was stripped during reconstruction. Only the
            // current bound protocol can restore it; ordinary facts and their observation age stay fixed.
            unset($before['term_evidence'], $before['renewal_families'], $after['term_evidence'], $after['renewal_families']);
            if ($old->expansion !== [] || $new->expansion === [] || !OfferState::equivalent($before, $after)
                || $command->binding()->registrarKey !== 'namesilo'
                || $command->binding()->environment !== Environment::Test
                || !OfferState::testFactsAccepted($command->binding(), $command->tld(), $new, $this->clock)) {
                throw new \DomainException('Conflicting facts observation.');
            }
        }
        $target->catalogue_facts = OfferState::encodeFacts($new); return true;
    }

    private function costs(FeedObserved $command, Tld $target, $prices, \DateTimeImmutable $now): bool
    {
        $changed = $this->prices->observe($command, $target, $prices, $now);
        if ($changed && $target->catalogue_facts !== null) {
            $costs = [];
            // A damaged cost the feed left out stays damaged; it can't undo the costs the feed replaced.
            foreach (OfferState::query('domains_prices')->where('server_id', $target->server_id)->where('tld', $target->tld)->where('currency', 'USD')->get() as $p) {
                if (($cost = OfferState::savedCost($p)) !== null) { $costs[] = $cost; }
            }
            $target->catalogue_facts = OfferState::encodeFacts((new FeedObserved($command->binding(), $command->tld(), $costs))->facts(OfferState::facts($target->catalogue_facts)));
        }
        return $changed;
    }
}
