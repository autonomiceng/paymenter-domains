<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Paymenter\Extensions\Servers\Domains\Data\CostUnit;
use Paymenter\Extensions\Servers\Domains\Services\PricingRule;

/** NS17 term and renewal-family rules. Pure: no database, provider or clock access.
 * Annual renewal and provider registration terms keep their existing authority. A longer renewal needs a
 * first-pass suffix, a registrar whose pre-send read quotes exact multi-year totals (none yet), current facts, current reviewed
 * ordinary-family and term evidence in `term_total` units and a fresh exact-term cost. Anything else is held. */
final class TermSupport
{
    /** Reviewed first-pass suffixes. Every other suffix keeps today's annual renewal. */
    public const FIRST_PASS = ['com', 'net', 'org'];
    /** Registrars whose pre-send admission read quotes the exact multi-year renewal total. None yet:
     * Enom GetExtendInfo/Check give one annual price (the admission quote is annual x years) and PE_GetProductPrice
     * is a selected-term annual rate; NameSilo getPrices has no years parameter. Longer terms stay held
     * (`no_exact_quote`) until NS18 adds a reviewed exact-term read. */
    public const EXACT_QUOTES = [];
    /** Reviewed `term_evidence`/`renewal_families` for producers without their own dated source, by registrar then
     * suffix. Empty: no saved evidence qualifies yet. Only deployment review may add an entry. NameSilo TEST evidence
     * rides with its dated protocol facts instead. */
    public const REVIEWED_EVIDENCE = [];
    public const HORIZON_YEARS = 10;

    /** Producers attach reviewed evidence. Other suffixes carry none, so a refresh drops it. */
    public static function attach(string $tld, Facts $facts, mixed $evidence): Facts
    {
        $expansion = [];
        if (in_array($tld, self::FIRST_PASS, true) && is_array($evidence)) {
            foreach (['term_evidence', 'renewal_families'] as $key) {
                if (is_array($evidence[$key] ?? null)) { $expansion[$key] = $evidence[$key]; }
            }
        }
        return new Facts($facts->terms, $facts->registryFields, $facts->renewalSafe, $facts->requiresNameservers,
            $facts->autoRenewOnly, $facts->source, $facts->observedAt, $facts->freshUntil, $expansion);
    }

    /** Renewal years for one holding, keyed by years, inside the registry horizon from today.
     * Year 1 is today's annual renewal. Each entry names its new expiry, exact cost and frozen rule, or why it is held. */
    public static function renewal(string $registrar, string $tld, ?Facts $facts, array $costs,
        \DateTimeImmutable $expiry, \DateTimeImmutable $now, array $exactQuotes = self::EXACT_QUOTES): array
    {
        $registry = $now->modify('+'.self::HORIZON_YEARS.' years'); $out = [];
        $annual = self::current($costs['renew:1'] ?? null, $now);
        foreach (range(1, self::HORIZON_YEARS) as $years) {
            $until = self::addYears($expiry, $years);
            if ($until > $registry) { break; }
            [$reason, $rule] = $years === 1 ? [null, null] : self::held($registrar, $tld, $facts, $costs, $years, $until, $now, $exactQuotes);
            $out[$years] = ['years' => $years, 'available' => $reason === null, 'reason' => $reason,
                'expiry' => $until->format('Y-m-d'), 'cost' => $years === 1 ? $annual?->total : ($rule['cost']['amount'] ?? null), 'rule' => $rule];
        }
        return $out;
    }

    /** Staff readiness per family. Grace and redemption stay closed here: neither is ordinary renewal. */
    public static function families(?Facts $facts, \DateTimeImmutable $now): array
    {
        $ordinary = $facts ? ExpansionFacts::family($facts->expansion, 'ordinary', $now) : null;
        $grace = $facts ? ExpansionFacts::family($facts->expansion, 'grace', $now) : null;
        return [
            'ordinary' => $ordinary === null ? ['state' => 'annual_only', 'reason' => 'family_unreviewed', 'terms' => [1]]
                : ['state' => 'reviewed', 'reason' => null, 'terms' => array_values(array_unique([1, ...$ordinary['terms']])),
                    'horizon_years' => $ordinary['horizon_years'], 'version' => $ordinary['version'], 'valid_until' => $ordinary['valid_until']],
            // Reviewed grace evidence is readable, but turning it on needs paid production proof (NS18).
            'grace' => ['state' => 'held', 'reason' => $grace === null ? 'family_unreviewed' : 'live_proof_required'],
            'redemption' => ['state' => 'separate', 'reason' => 'restoration_request'],
        ];
    }

    /** Staff readiness for one ending's renewal years, from today's date as the expiry. */
    public static function readiness(string $registrar, string $tld, ?Facts $facts, array $costs, \DateTimeImmutable $now,
        array $exactQuotes = self::EXACT_QUOTES): array
    {
        $today = new \DateTimeImmutable($now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'));
        return ['renew' => self::renewal($registrar, $tld, $facts, $costs, $today, $now, $exactQuotes), 'families' => self::families($facts, $now)];
    }

    /** Exact cap for one new renewal debt: the annual cost for 1 year, the frozen exact-term cost otherwise. */
    public static function cap(array $terms, int $years): ?array
    {
        $term = $terms[$years] ?? null;
        if ($term === null || !$term['available']) { throw new \DomainException('Renewal term unavailable.'); }
        return $term['cost'] === null ? null : ['amount' => $term['cost'], 'currency' => 'USD'];
    }

    /** Admission accepts a longer term only for a registrar whose pre-send read quotes the exact multi-year total
     * (none yet), and only when its debt froze an NS17 rule for exactly that term, with a cap covering the frozen
     * exact cost (a staff complimentary cap may be higher). A frozen multi-year debt is otherwise held: `no_exact_quote`. */
    public static function admits(string $registrar, int $years, ?array $renewal, array $exactQuotes = self::EXACT_QUOTES): bool
    {
        if ($years === 1) { return true; }
        if (!in_array($registrar, $exactQuotes, true)) { return false; }
        $rule = $renewal['term_rule'] ?? null;
        if (!is_array($rule) || ($rule['years'] ?? null) !== $years || ($renewal['term'] ?? null) !== $years) { return false; }
        try { return PricingRule::units($renewal['cost_cap']['amount'] ?? '') >= PricingRule::units($rule['cost']['amount'] ?? ''); }
        catch (\InvalidArgumentException|\TypeError) { return false; }
    }

    /** One price line: first year plus the renewal price for each later year, 2-decimal USD. */
    public static function lines(string $firstYear, string $renewal, int $years): array
    {
        if ($years < 1 || $years > self::HORIZON_YEARS) { throw new \InvalidArgumentException('Terms are 1 to 10 years.'); }
        $total = PricingRule::units($firstYear, 2) + PricingRule::units($renewal, 2) * ($years - 1);
        return ['years' => $years, 'first_year' => PricingRule::decimal(PricingRule::units($firstYear, 2)),
            'renewal' => PricingRule::decimal(PricingRule::units($renewal, 2)), 'renewal_years' => $years - 1, 'total' => PricingRule::decimal($total)];
    }

    /** Cart recheck of one line against current lines keyed by years; a null total is Missing. */
    public static function recheck(int $years, string $was, array $current): array
    {
        $line = $current[$years] ?? null;
        if ($line === null) { return ['state' => 'removed', 'was' => $was, 'now' => null]; }
        $now = $line['total'] ?? null;
        if ($now === null) { return ['state' => 'missing', 'was' => $was, 'now' => null]; }
        return ['state' => PricingRule::units($now, 2) === PricingRule::units($was, 2) ? 'same' : 'changed', 'was' => $was, 'now' => $now];
    }

    private static function held(string $registrar, string $tld, ?Facts $facts, array $costs, int $years,
        \DateTimeImmutable $until, \DateTimeImmutable $now, array $exactQuotes): array
    {
        if (!in_array($tld, self::FIRST_PASS, true)) { return ['not_reviewed', null]; }
        if (!in_array($registrar, $exactQuotes, true)) { return ['no_exact_quote', null]; }
        if ($facts === null) { return ['unknown_facts', null]; }
        if (!$facts->fresh($now)) { return ['stale_facts', null]; }
        if ($facts->renewalSafe !== true || $facts->autoRenewOnly !== false || !in_array(1, $facts->terms['renew'] ?? [], true)) { return ['unsupported_facts', null]; }
        $family = ExpansionFacts::family($facts->expansion, 'ordinary', $now);
        if ($family === null) { return ['family_unreviewed', null]; }
        if ($until > $now->modify('+'.$family['horizon_years'].' years')) { return ['horizon', null]; }
        $term = ExpansionFacts::term($facts->expansion, 'renew', $years, $now);
        if ($term === null || !in_array($years, $family['terms'], true)) { return ['term_unreviewed', null]; }
        // Annual units are the guessed annual-times-years total this replaces, never an exact cap.
        if ($term['price_unit'] !== CostUnit::TermTotal->value || $family['price_unit'] !== CostUnit::TermTotal->value) { return ['annual_unit', null]; }
        $cost = $costs['renew:'.$years] ?? null;
        if ($cost === null) { return ['unknown_price', null]; }
        if (!$cost->enabled) { return ['provider_disabled', null]; }
        if (!$cost->fresh($now)) { return ['stale_price', null]; }
        if ($cost->sourceUnit !== CostUnit::TermTotal) { return ['annual_unit', null]; }
        $pick = static fn (array $e, array $keys) => array_intersect_key($e, array_flip($keys));
        return [null, ['years' => $years,
            'family' => ['name' => 'ordinary'] + $pick($family, ['source', 'version', 'rule', 'reviewed_at', 'valid_until', 'states', 'expiry_precision', 'horizon_years', 'safe_boundary']),
            'term' => $pick($term, ['source', 'version', 'rule', 'reviewed_at', 'valid_until', 'price_unit']),
            'cost' => ['amount' => $cost->total, 'currency' => 'USD', 'source' => $cost->source, 'observed_at' => $cost->observedAt->format(DATE_ATOM)],
            'boundary' => ['expiry' => $until->format('Y-m-d'), 'horizon' => $now->modify('+'.$family['horizon_years'].' years')->format(DATE_ATOM)]]];
    }

    /** Carbon addYearsNoOverflow without the framework: Feb 29 becomes Feb 28 in a common year. */
    private static function addYears(\DateTimeImmutable $date, int $years): \DateTimeImmutable
    {
        [$y, $m, $d] = array_map('intval', explode('-', $date->format('Y-m-d'))); $y += $years;
        return $date->setDate($y, $m, min($d, (int) $date->setDate($y, $m, 1)->format('t')));
    }

    private static function current(?Cost $cost, \DateTimeImmutable $now): ?Cost
    { return $cost && $cost->enabled && $cost->fresh($now) ? $cost : null; }
}
