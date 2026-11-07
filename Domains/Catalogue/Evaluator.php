<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Paymenter\Extensions\Servers\Domains\Services\{PricingRule, RegistryInputs};

/** Pure evaluator. Dormant until the checked catalogue cutover. */
final class Evaluator
{
    public function evaluate(Snapshot $offer, Query $query, Context $context, \DateTimeImmutable $now): Decision
    {
        $scope = $offer->feed->binding()->matches($query->binding) && in_array($offer->feed->tld(), $query->tlds, true);
        $cost = $offer->costs[$query->action.':'.$query->term] ?? null;
        $reason = !$scope ? 'binding_changed' : $this->reason($offer, $query, $now);
        if ($scope && $context === Context::Locked) {
            $expected = $query->expected[$offer->feed->tld()] ?? null;
            if ($expected === null || !$offer->revision->matches($expected)) { $reason = 'revision_changed'; }
        }
        $holder = $context === Context::Holder;
        $retail = $reason === null ? $offer->retail[$query->action.':'.$query->term] : null;
        $warning = null;
        if ($holder) {
            // Contractual holder retail survives current cost/facts/staff/seller/publication changes.
            $retail = $scope && $query->action === 'renew' && $query->term === 1 ? $offer->retainedAnnualRetail : null;
            if ($retail === null || PricingRule::units($retail, 2) < 1) { $retail = null; }
            if ($retail !== null) {
                $warning = $reason !== null ? 'retained_renewal' :
                    (PricingRule::units($retail, 2) * 100 < PricingRule::units($cost->total) ? 'below_cost' : null);
            }
        }
        return new Decision($offer->feed->tld(), $query->action, $query->term, $retail !== null, $retail, $offer->revision,
            $context === Context::Staff ? $reason : ($retail === null ? 'unavailable' : null), $warning,
            $scope && $context === Context::Staff ? $cost?->total : null);
    }

    /** Staff or seller decisions only. Missing, stale or unready data is never "off sale". */
    public function closed(Snapshot $offer, Query $query): bool
    { return $this->staffReason($offer, $query) !== null; }

    private function staffReason(Snapshot $offer, Query $query): ?string
    {
        $intent = $offer->intent; $action = $query->action; $term = $query->term;
        if ($offer->hidden || !($intent->sell ?? $intent->defaultSell) || in_array($action, $intent->stops, true)) { return 'staff_stopped'; }
        if ($action !== 'renew' && in_array('renew', $intent->stops, true)) { return 'renewal_stopped'; }
        if ($action === 'register' && $term > ($intent->longest ?? $intent->defaultLongest)
            || $action === 'transfer' && !($intent->transfers ?? $intent->defaultTransfers)) { return 'staff_term_stopped'; }
        if ($offer->seller === null || !$offer->feed->binding()->matches($offer->seller)
            || $action !== 'renew' && !in_array($action, $offer->sellerActions, true)) { return 'seller_changed'; }
        return null;
    }

    private function reason(Snapshot $offer, Query $query, \DateTimeImmutable $now): ?string
    {
        $intent = $offer->intent; $action = $query->action; $term = $query->term;
        if (!$offer->accountReady) { return 'account_unavailable'; }
        if (($staff = $this->staffReason($offer, $query)) !== null) { return $staff; }
        if ($offer->publishedRevision === null || !$offer->revision->matches($offer->publishedRevision)) { return 'publication_pending'; }
        $facts = $offer->facts;
        if ($facts === null) { return 'unknown_facts'; }
        if (!$facts->fresh($now)) { return 'stale_facts'; }
        if ($facts->renewalSafe !== true || $facts->requiresNameservers !== false || $facts->autoRenewOnly !== false
            || !RegistryInputs::supported($offer->feed->binding()->registrarKey, $offer->feed->tld(), $facts->registryFields)) { return 'unsupported_facts'; }
        if (!in_array($term, $facts->terms[$action] ?? [], true) || !in_array(1, $facts->terms['renew'] ?? [], true)) { return 'unsupported_term'; }
        // A new acquisition always has a current annual renewal path, on this same account.
        foreach (Retail::terms($action, $term) as [$needed, $years]) {
            $key = $needed.':'.$years;
            $cost = $offer->costs[$key] ?? null;
            if ($cost === null) { return 'unknown_price'; }
            if (!$cost->enabled) { return 'provider_disabled'; }
            if (!$cost->fresh($now)) { return 'stale_price'; }
            $retail = $offer->retail[$key] ?? null;
            if ($retail === null || PricingRule::units($retail, 2) < 1) { return 'unpublished_price'; }
            if ($action !== 'renew' && $key === $action.':'.$term
                && PricingRule::units($retail, 2) * 100 < PricingRule::units($cost->total)) { return 'below_cost'; }
        }
        if ($intent->maxAnnualCost !== null) {
            foreach ($offer->costs as $cost) {
                $offered = in_array($cost->term, $facts->terms[$cost->action] ?? [], true)
                    && ($cost->action !== 'register' || $cost->term <= ($intent->longest ?? $intent->defaultLongest))
                    && ($cost->action !== 'transfer' || ($intent->transfers ?? $intent->defaultTransfers))
                    && ($cost->action === 'renew' || !in_array($cost->action, $intent->stops, true));
                if ($cost->enabled && $offered && PricingRule::units($cost->total) > PricingRule::units($intent->maxAnnualCost) * $cost->term) { return 'cost_limit'; }
            }
        }
        return null;
    }
}
