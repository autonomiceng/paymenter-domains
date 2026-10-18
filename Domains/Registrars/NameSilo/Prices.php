<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;

use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CanonicalName, CatalogueRate, CostUnit, Disposition, DriverResult, Query};
use Paymenter\Extensions\Servers\Domains\Services\PricingRule;

final class Prices
{
    /** Verified quote -> pricing input only, never admission or a PriceSnapshot fabrication. */
    public static function rate(#[\SensitiveParameter] DriverResult $result, #[\SensitiveParameter] AccountBinding $binding,
        Query $query, string $tld, Clock $clock): CatalogueRate
    {
        $binding->requireMatch($result->binding); $quote = $result->evidence()->quote; $now = $clock->now();
        if (CanonicalName::parse($query->name, [$tld])['tld'] !== $tld || $binding->registrarKey !== 'namesilo' || $result->disposition !== Disposition::Accepted || !$quote
            || !$query->matches($quote->query) || !$quote->cost || $quote->cost->currency !== 'USD'
            || !in_array($quote->source, ['namesilo_account_annual', 'namesilo_exact_term'], true)
            || ($quote->source === 'namesilo_account_annual' && ($query->years !== 1 || $quote->unit !== CostUnit::Annual))
            || $quote->observedAt > $now || ($quote->validUntil !== null && $quote->validUntil <= $now)) {
            throw new \DomainException('Verified current account quote required.');
        }
        $amount = rtrim(rtrim($quote->cost->amount, '0'), '.');
        $units = PricingRule::units($amount);
        $divisor = $quote->unit === CostUnit::TermTotal ? $query->years : 1;
        if ($units % $divisor !== 0) { throw new \DomainException('Unsupported exact per-year precision.'); }
        return new CatalogueRate($query->action->value, $query->years, PricingRule::decimal(intdiv($units, $divisor), 4),
            $quote->source, $quote->observedAt, $quote->validUntil, $quote->cost->currency);
    }
}
