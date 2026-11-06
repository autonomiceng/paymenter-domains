<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Paymenter\Extensions\Servers\Domains\Data\{CostUnit, Environment};
use Paymenter\Extensions\Servers\Domains\Services\PricingRule;

/** Reviewed longer renewal terms. Annual renewal retains its existing authority. */
final class TermSupport
{
    public const FIRST_PASS = ['com', 'net', 'org'];
    /** Reviewed pre-send total: Enom's agreed per-year renewal cost multiplied by years.
     * TEST only; Live needs NS18 proof. NameSilo has no reviewed longer-term quote. */
    public const EXACT_QUOTES = ['enom'];
    private const ENOM_TEST_PROOF = ['source' => 'enom_test_annual_renewal_approval', 'version' => '2026-11-05',
        'reviewed_at' => '2026-11-05T00:00:00+00:00', 'valid_until' => '2027-11-05T00:00:00+00:00',
        'terms' => [2], 'price_unit' => 'term_total', 'rule' => 'annual_cost_times_years'];
    /** Approved TEST scope. Producers still require fresh annual cost and ordinary renewal facts. */
    public const REVIEWED_EVIDENCE = ['enom' => ['com' => [
        'term_evidence' => ['format' => 1, 'entries' => ['renew' => [2 => self::ENOM_TEST_PROOF]]],
        'renewal_families' => ['format' => 1, 'entries' => ['ordinary' => self::ENOM_TEST_PROOF + [
            'states' => ['Active'], 'expiry_precision' => 'instant', 'horizon_years' => 10,
            'safe_boundary' => 'before_confirmed_expiry']]],
    ]]];
    public const HORIZON_YEARS = 10;

    /** The carried expansion is the reviewed authority version. A changed/removed approval
     * needs fresh provider facts, even when the old independent observation has not expired. */
    public static function currentFacts(\Paymenter\Extensions\Servers\Domains\Data\AccountBinding $binding, string $tld, Facts $facts): bool
    {
        if ($binding->registrarKey !== 'enom') { return true; }
        return OfferState::equivalent($facts->expansion, self::evidence($binding, $tld));
    }

    public static function evidence(\Paymenter\Extensions\Servers\Domains\Data\AccountBinding $binding, string $tld): array
    {
        return $binding->environment === Environment::Test && in_array($tld, self::FIRST_PASS, true)
            && in_array($binding->registrarKey, self::EXACT_QUOTES, true)
            ? (self::REVIEWED_EVIDENCE[$binding->registrarKey][$tld] ?? []) : [];
    }

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
        \DateTimeImmutable $expiry, \DateTimeImmutable $now, array $exactQuotes = self::EXACT_QUOTES, Environment $environment = Environment::Live): array
    {
        $registry = $now->modify('+'.self::HORIZON_YEARS.' years'); $out = [];
        $annual = self::current($costs['renew:1'] ?? null, $now);
        foreach (range(1, self::HORIZON_YEARS) as $years) {
            $until = self::addYears($expiry, $years);
            if ($until > $registry) { break; }
            [$reason, $rule] = $years === 1 ? [null, null] : self::held($registrar, $tld, $facts, $costs, $years, $until, $now, $exactQuotes, $environment);
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
        array $exactQuotes = self::EXACT_QUOTES, Environment $environment = Environment::Live): array
    {
        $today = new \DateTimeImmutable($now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'));
        return ['renew' => self::renewal($registrar, $tld, $facts, $costs, $today, $now, $exactQuotes, $environment), 'families' => self::families($facts, $now)];
    }

    /** Exact cap for one new renewal debt: the annual cost for 1 year, the frozen exact-term cost otherwise. */
    public static function cap(array $terms, int $years): ?array
    {
        $term = $terms[$years] ?? null;
        if ($term === null || !$term['available']) { throw new \DomainException('Renewal term unavailable.'); }
        return $term['cost'] === null ? null : ['amount' => $term['cost'], 'currency' => 'USD'];
    }

    /** Longer debt needs a reviewed TEST quote and its frozen rule/cap. No Live multi-year authority. */
    public static function admits(string $registrar, int $years, ?array $renewal, array $exactQuotes = self::EXACT_QUOTES, Environment $environment = Environment::Live): bool
    {
        if ($years === 1) { return true; }
        if (!in_array($registrar, $exactQuotes, true) || $environment !== Environment::Test) { return false; }
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
        \DateTimeImmutable $until, \DateTimeImmutable $now, array $exactQuotes, Environment $environment): array
    {
        if (!in_array($tld, self::FIRST_PASS, true)) { return ['not_reviewed', null]; }
        if (!in_array($registrar, $exactQuotes, true)) { return ['no_exact_quote', null]; }
        if ($environment !== Environment::Test) { return ['live_proof_required', null]; }
        if ($facts === null) { return ['unknown_facts', null]; }
        if (!$facts->fresh($now)) { return ['stale_facts', null]; }
        if ($facts->renewalSafe !== true || $facts->autoRenewOnly !== false || !in_array(1, $facts->terms['renew'] ?? [], true)) { return ['unsupported_facts', null]; }
        $family = ExpansionFacts::family($facts->expansion, 'ordinary', $now);
        if ($family === null) { return ['family_unreviewed', null]; }
        if ($until > $now->modify('+'.$family['horizon_years'].' years')) { return ['horizon', null]; }
        $term = ExpansionFacts::term($facts->expansion, 'renew', $years, $now);
        if ($term === null || !in_array($years, $family['terms'], true)) { return ['term_unreviewed', null]; }
        // Reviewed totals are stored in term units; annual observations cannot masquerade as a longer total.
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
