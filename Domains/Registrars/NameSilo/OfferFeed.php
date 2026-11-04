<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;

use Paymenter\Extensions\Servers\Domains\Catalogue\{Cost, FactsObserved, FeedObserved, PricedFeed};
use Paymenter\Extensions\Servers\Domains\Data\{CanonicalName, CatalogueFreshness, CostUnit, RequestContext};
use Paymenter\Extensions\Servers\Domains\Services\PricingRule;

/** One account getPrices, annual-only. No catalogue row establishes registry/renewal safety. */
final class OfferFeed implements PricedFeed
{
    public function __construct(private readonly Driver $reader, private readonly CatalogueFreshness $freshness = new CatalogueFreshness, private readonly ?\Closure $facts = null) {}
    public function factsReads(): int { return $this->facts === null ? 0 : 1; }
    public function priceReads(): int { return 1; }
    public function prices(RequestContext $context): iterable
    {
        $this->reader->binding()->requireMatch($context->binding);
        $at = $context->now(); $until = $at->modify('+'.$this->freshness->maxAge().' seconds'); $commands = [];
        foreach ($this->reader->prices($context) as $tld => $row) {
            CanonicalName::parse('example.'.$tld, [$tld]);
            $costs = []; $amounts = $this->amounts($row);
            // Quarantine retracts every annual cost; unknown amounts never become zero.
            foreach ($amounts ?? ['register' => null, 'renew' => null, 'transfer' => null] as $action => $amount) {
                $costs[] = new Cost($action, 1, $amount !== null, $amount, CostUnit::Annual, 'namesilo_account_annual', $at, $until);
            }
            $commands[] = new FeedObserved($context->binding, $tld, $costs);
        }
        return $commands;
    }
    public function facts(string $tld, RequestContext $context): FactsObserved
    {
        $this->reader->binding()->requireMatch($context->binding);
        CanonicalName::parse('example.'.$tld, [$tld]);
        if (!$this->facts) { throw new \DomainException('NameSilo offer facts are unverified.'); }
        $facts = ($this->facts)($tld);
        // The cold check requires a usable row; prices never supply or redate facts.
        if ($this->amounts($this->reader->prices($context)[$tld] ?? null) === null) { throw new \DomainException('NameSilo ending unavailable.'); }
        return new FactsObserved($context->binding,$tld,$facts);
    }
    /** All three prices are required; one unusable field quarantines the whole ending. */
    private function amounts(?array $row): ?array
    {
        $amounts = [];
        foreach (['register' => 'registration', 'renew' => 'renew', 'transfer' => 'transfer'] as $action => $field) {
            $amount = PricingRule::wholesale($row[$field] ?? null);
            if ($amount === null || PricingRule::units($amount) < 1) { return null; }
            $amounts[$action] = $amount;
        }
        return $amounts;
    }
}
