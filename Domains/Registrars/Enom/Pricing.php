<?php
// Adapted from Enom 444f31c Services/Catalogue.php and RegistrarMoney.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;

use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CatalogueRate, CostUnit, Disposition, DriverResult, Money, Query, QuoteEvidence};
use Paymenter\Extensions\Servers\Domains\Services\PricingRule;

/** Exact source units; no markup, currency conversion, rounding or spending authority. */
final class Pricing
{
    /** Eight-decimal DB padding is accepted only if every excess digit is zero. */
    public static function annual(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^([0-9]{1,8})(?:\.([0-9]{1,8}))?$/D', $value, $parts)) { return null; }
        $fraction = $parts[2] ?? '';
        if (strlen($fraction) > 4 && trim(substr($fraction, 4), '0') !== '') { return null; }
        return PricingRule::decimal(PricingRule::units($parts[1].'.'.str_pad(substr($fraction, 0, 4), 4, '0')), 4);
    }
    /** Source GetExtendInfo/Check and actual charges accept cents only. */
    public static function cents(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/D', $value)) { return null; }
        return PricingRule::decimal(PricingRule::units($value, 2));
    }
    public static function total(string $annual, int $years): Money
    {
        if ($years < 1 || $years > 10) { throw new \InvalidArgumentException('Invalid quote term.'); }
        return new Money(PricingRule::decimal(PricingRule::units($annual) * $years, 4), 'USD');
    }
    public static function checkUsd(mixed $prices): bool
    {
        return is_array($prices) && (!array_key_exists('currency', $prices) || $prices['currency'] === ''
            || (is_string($prices['currency']) && strtoupper($prices['currency']) === 'USD'));
    }
    /** A null neutral quote is unknown, never positive no-price evidence. */
    public static function catalogue(DriverResult $result, AccountBinding $binding, Query $query, \DateTimeImmutable $now): CatalogueRate
    {
        $binding->requireMatch($result->binding); $quote = $result->evidence()->quote;
        if ($binding->registrarKey !== 'enom' || $result->disposition !== Disposition::Accepted || !$quote
            || !$query->matches($quote->query) || !$quote->cost || $quote->cost->currency !== 'USD'
            || $quote->observedAt > $now || ($quote->validUntil !== null && $quote->validUntil <= $now)) {
            throw new \DomainException('No current matching Enom quote.');
        }
        $amount = self::annual($quote->cost->amount);
        if ($amount === null) { throw new \DomainException('Unsupported Enom precision.'); }
        $units = PricingRule::units($amount);
        if ($quote->unit === CostUnit::TermTotal) {
            if ($units % $query->years !== 0) { throw new \DomainException('Inexact selected-term rate.'); }
            $amount = PricingRule::decimal(intdiv($units, $query->years), 4);
        }
        return new CatalogueRate($query->action->value, $query->years, $amount, $quote->source,
            $quote->observedAt, $quote->validUntil, $quote->cost->currency);
    }
}
