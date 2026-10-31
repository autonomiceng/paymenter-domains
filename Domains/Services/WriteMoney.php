<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CostUnit, Disposition, DriverResult, Evidence, Money, Query, QuoteEvidence, ResultReason};
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
    /** Every read answered for this account, or the hold says which way it failed. */
    public static function requireRead(AccountBinding $binding, DriverResult ...$results): void
    {
        foreach ($results as $result) {
            $binding->requireMatch($result->binding);
            if ($result->disposition !== Disposition::Accepted) { throw new WriteHeld($result->reason === ResultReason::Unsupported ? 'order_not_supported' : 'registrar_read_failed'); }
        }
    }
    public static function requireAvailable(Evidence $facts): void
    {
        if ($facts->available === null || $facts->premium === null) { throw new WriteHeld('registrar_read_failed'); }
        if (!$facts->available) { throw new WriteHeld('name_unavailable'); }
        if ($facts->premium) { throw new WriteHeld('premium_name'); }
    }
    public static function requireFunds(?QuoteEvidence $quote, ?Money $balance, Query $query, \DateTimeImmutable $started, \DateTimeImmutable $now): void
    {
        $cost = self::cost($quote, $query, $started, $now);
        if (!$cost) { throw new WriteHeld('registrar_price_missing'); }
        if (!$balance) { throw new WriteHeld('registrar_read_failed'); }
        if ($balance->currency !== 'USD') { throw new WriteHeld('registrar_read_failed'); }
        if (!self::sufficient($balance, $cost)) { throw new WriteHeld('registrar_balance_insufficient',$cost); }
    }
    public static function compare(string $a, string $b): int { return strcmp(self::decimal($a), self::decimal($b)); }
    private static function decimal(string $amount): string
    {
        [$whole, $fraction] = explode('.', $amount); return str_pad($whole, 18, '0', STR_PAD_LEFT).str_pad($fraction, 6, '0');
    }
}
