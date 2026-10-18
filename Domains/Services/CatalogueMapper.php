<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CanonicalName, CatalogueRate, CostUnit, Disposition, DriverResult, Query};

final class CatalogueMapper
{
    /** Advisory quotes cannot express confirmed no-price or the shared sync lifetime. */
    public static function quote(DriverResult $result, AccountBinding $binding, Query $query, string $tld, \DateTimeImmutable $now): CatalogueRate
    {
        $binding->requireMatch($result->binding); CanonicalName::parse($query->name, [$tld]); $quote = $result->evidence()->quote;
        $sources = $binding->registrarKey === 'enom' ? ['enom_product_price'] : ['namesilo_account_annual', 'namesilo_exact_term'];
        if ($result->disposition !== Disposition::Accepted || !$quote || !$query->matches($quote->query) || !$quote->cost
            || $quote->cost->currency !== 'USD' || !in_array($quote->source, $sources, true) || $quote->observedAt > $now
            || ($quote->validUntil !== null && $quote->validUntil <= $now)
            || ($quote->source === 'namesilo_account_annual' && ($query->years !== 1 || $quote->unit !== CostUnit::Annual))) {
            throw new \DomainException('Current matching advisory quote required.');
        }
        $amount = str_contains($quote->cost->amount, '.') ? rtrim(rtrim($quote->cost->amount, '0'), '.') : $quote->cost->amount;
        try { $units = PricingRule::units($amount); }
        catch (\InvalidArgumentException) { throw new \DomainException('Unsupported quote precision.'); }
        $divisor = $quote->unit === CostUnit::TermTotal ? $query->years : 1;
        if ($units % $divisor !== 0) { throw new \DomainException('Unsupported selected-term precision.'); }
        return new CatalogueRate($query->action->value, $query->years, PricingRule::decimal(intdiv($units, $divisor), 4),
            $quote->source, $quote->observedAt, $quote->validUntil, $quote->cost->currency, false);
    }
}
