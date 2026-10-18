<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\{CostUnit, Money, Query, QuoteEvidence};
/** Shared exact preflight checks; a quote or balance never grants spending authority. */
final class WriteMoney
{
    public static function cost(?QuoteEvidence $quote, Query $query, \DateTimeImmutable $started, \DateTimeImmutable $now): ?Money
    {
        if (!$quote || !$query->matches($quote->query) || !$quote->cost || $quote->cost->currency !== 'USD'
            || ($quote->unit === CostUnit::Annual && $query->years !== 1)
            || $quote->observedAt < $started || $quote->observedAt > $now || !$quote->validUntil || $quote->validUntil <= $now) { return null; }
        return $quote->cost;
    }
    public static function sufficient(?Money $balance, Money $cost): bool
    {
        return $balance?->currency === 'USD' && self::compare($balance->amount, $cost->amount) >= 0;
    }
    public static function compare(string $a, string $b): int { return strcmp(self::decimal($a), self::decimal($b)); }
    private static function decimal(string $amount): string
    {
        [$whole, $fraction] = explode('.', $amount); return str_pad($whole, 18, '0', STR_PAD_LEFT).str_pad($fraction, 6, '0');
    }
}
