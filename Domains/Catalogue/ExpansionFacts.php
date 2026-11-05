<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;
use Paymenter\Extensions\Servers\Domains\Data\{CostUnit,ReviewedFacts};

/** Optional catalogue_facts siblings; existing terms and annual acceptance remain authoritative. */
final class ExpansionFacts
{
    public static function term(array $facts, string $action, int $year, \DateTimeImmutable $now): ?array
    {
        if (!in_array($action, ['register','renew'], true) || $year < 1 || $year > 10) { return null; }
        $entries = ReviewedFacts::entries($facts['term_evidence'] ?? null);
        $entry = $entries[$action][$year] ?? null;
        return is_array($entry) && self::priced($entry, $now) && in_array($year, $entry['terms'], true) ? $entry : null;
    }
    public static function family(array $facts, string $family, \DateTimeImmutable $now): ?array
    {
        if (!in_array($family, ['ordinary','grace'], true)) { return null; }
        $entry = ReviewedFacts::entries($facts['renewal_families'] ?? null)[$family] ?? null;
        if (!is_array($entry) || !self::priced($entry, $now) || !is_array($entry['states'] ?? null)
            || !array_is_list($entry['states']) || !$entry['states'] || count($entry['states']) > 50
            || !in_array($entry['expiry_precision'] ?? null, ['date','instant'], true)
            || !is_int($entry['horizon_years'] ?? null) || $entry['horizon_years'] < 1 || $entry['horizon_years'] > 10
            || !ReviewedFacts::text($entry['safe_boundary'] ?? null)
            || ($family === 'grace' && ($entry['restoration_disabled'] ?? null) !== true)) { return null; }
        foreach ($entry['states'] as $state) { if (!ReviewedFacts::text($state)) { return null; } }
        return $entry;
    }
    private static function priced(array $entry, \DateTimeImmutable $now): bool
    {
        return ReviewedFacts::current($entry, $now) && ReviewedFacts::integers($entry['terms'] ?? null, 1, 10)
            && in_array($entry['price_unit'] ?? null, [CostUnit::TermTotal->value,CostUnit::Annual->value], true)
            && ReviewedFacts::text($entry['rule'] ?? null);
    }
}
