<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CostUnit, Environment};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\TestCatalogueFacts;
use Paymenter\Extensions\Servers\Domains\Services\{EndingChoices, PricingRule};

/** Persistence codec. Facts have one independent age; normalized costs never carry fact proofs. */
final class OfferState
{
    /** Read fixed decimals as strings before PDO can turn SQLite amounts into floats. */
    public static function query(string $table): \Illuminate\Database\Query\Builder
    {
        $money = [
            'domains_prices' => ['wholesale', 'retail'],
            'domains_tlds' => ['markup', 'fixed_register', 'fixed_renew', 'fixed_transfer', 'catalogue_retained_annual_retail'],
        ];
        $query = \Illuminate\Support\Facades\DB::table($table)->select('*');
        $grammar = \Illuminate\Support\Facades\DB::connection()->getQueryGrammar();
        foreach ($money[$table] ?? [] as $column) {
            $name = $grammar->wrap($column);
            $query->selectRaw('CAST('.$name.' AS CHAR) AS '.$name);
        }
        return $query;
    }

    public static function json(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR); }
    public static function decode(mixed $value): array
    {
        if (is_array($value)) { return $value; }
        return $value === null ? [] : json_decode($value, true, 32, JSON_THROW_ON_ERROR);
    }
    public static function money(mixed $value, int $scale): ?string
    {
        if (!is_string($value) && !is_int($value)) { return null; }
        $value = str_contains((string) $value, '.') ? rtrim(rtrim((string) $value, '0'), '.') : (string) $value;
        try { return PricingRule::decimal(PricingRule::units($value, $scale), $scale); }
        catch (\InvalidArgumentException) { return null; }
    }
    public static function equivalent(array $a, array $b): bool
    { return self::canonical($a) === self::canonical($b); }
    private static function canonical(mixed $v): mixed
    {
        if (!is_array($v)) { return $v; }
        foreach ($v as &$item) { $item = self::canonical($item); } unset($item);
        if (!array_is_list($v)) { ksort($v); }
        return $v;
    }
    public static function cost(array $v): Cost
    {
        return new Cost($v['action'], $v['term'], $v['enabled'], $v['total'], CostUnit::from($v['source_unit'] ?? 'term_total'),
            $v['source'], new \DateTimeImmutable($v['observed_at']), new \DateTimeImmutable($v['fresh_until']), $v['currency'], normalized: true);
    }
    public static function encodeCost(Cost $c): array
    {
        return ['action' => $c->action, 'term' => $c->term, 'enabled' => $c->enabled, 'total' => $c->total,
            'currency' => $c->currency, 'source_unit' => $c->sourceUnit->value, 'source' => $c->source,
            'observed_at' => $c->observedAt->format(DATE_ATOM), 'fresh_until' => $c->freshUntil->format(DATE_ATOM)];
    }
    public static function facts(?string $json): ?Facts
    {
        if ($json === null) { return null; }
        $v = self::decode($json);
        // Unreadable optional evidence is dropped, never a reason to lose the ordinary facts.
        $expansion = array_filter(array_intersect_key($v, ['term_evidence' => 1, 'renewal_families' => 1]), 'is_array');
        return new Facts($v['terms'], $v['registryFields'], $v['renewalSafe'], $v['requiresNameservers'],
            $v['autoRenewOnly'], $v['source'], new \DateTimeImmutable($v['observedAt']), new \DateTimeImmutable($v['freshUntil']), $expansion);
    }
    /** TEST NameSilo evidence is an extra gate; callers still check ordinary freshness. */
    public static function testFactsAccepted(AccountBinding $binding, string $tld, ?Facts $facts, Clock $clock): bool
    {
        return $binding->registrarKey !== 'namesilo' || $binding->environment !== Environment::Test
            || TestCatalogueFacts::current($binding, $tld, $facts, $clock);
    }

    /** Optional expansion evidence is read separately; this does not change current supported terms. */
    public static function termEvidence(?string $json, string $action, int $year, \DateTimeImmutable $now): ?array
    {
        try { return ExpansionFacts::term(self::decode($json), $action, $year, $now); }
        catch (\Throwable) { return null; }
    }
    public static function familyEvidence(?string $json, string $family, \DateTimeImmutable $now): ?array
    {
        try { return ExpansionFacts::family(self::decode($json), $family, $now); }
        catch (\Throwable) { return null; }
    }
    /** Evidence is stored as top-level siblings; facts without it keep the old shape byte for byte. */
    public static function encodeFacts(Facts $f): string
    {
        $v = array_diff_key(get_object_vars($f), ['expansion' => true]);
        return self::json([...$v, 'observedAt' => $f->observedAt->format(DATE_ATOM), 'freshUntil' => $f->freshUntil->format(DATE_ATOM), ...$f->expansion]);
    }
    public static function revision(object $tld, array $defaults, ?object $policy): Revision
    { return new Revision((int) $tld->catalogue_revision, $defaults['_revision'] ?? 0, (int) ($policy?->revision ?? 0)); }
    public static function snapshot(AccountBinding $binding, object $tld, array $defaults, ?object $policy,
        iterable $prices, bool $ready, bool $publishing = false, ?\Paymenter\Extensions\Servers\Domains\Data\CatalogueFreshness $freshness = null): Snapshot
    {
        $raw = self::decode($tld->catalogue_intent); $intent = [];
        foreach (get_object_vars(new Intent) as $key => $unused) { if (array_key_exists($key, $raw)) { $intent[$key] = $raw[$key]; } }
        foreach (['defaultSell', 'defaultLongest', 'defaultTransfers', 'maxAnnualCost'] as $key) {
            if (array_key_exists($key, $defaults)) { $intent[$key] = $defaults[$key]; }
        }
        $costs = $retail = [];
        $freshness ??= \Paymenter\Extensions\Servers\Domains\Data\CatalogueFreshness::forAccount($binding->serverId);
        foreach ($prices as $p) {
            if ($p->catalogue_observation === null || $p->currency !== 'USD') { continue; }
            $c = $freshness->cost(self::cost(self::decode($p->catalogue_observation))); $costs[] = $c;
            if ($p->retail !== null) { $retail[$c->key()] = OfferState::money($p->retail, 2) ?? throw new \DomainException('Invalid published retail.'); }
        }
        $revision = self::revision($tld, $defaults, $policy);
        $published = $publishing || $tld->catalogue_published_revision === null ? null : new Revision(...self::decode($tld->catalogue_published_revision));
        $selected = $policy && (int) $policy->server_id === $binding->serverId
            && $policy->registrar_key === $binding->registrarKey && $policy->account_fingerprint === $binding->fingerprint;
        $actions = [];
        foreach (['register', 'transfer'] as $action) { if ($policy?->{$action.'_enabled'}) { $actions[] = $action; } }
        $facts = self::facts($tld->catalogue_facts);
        if (!self::testFactsAccepted($binding, $tld->tld, $facts, app(Clock::class))) { $facts = null; }
        return new Snapshot(new FeedObserved($binding, $tld->tld, $costs), $facts, new Intent(...$intent),
            $revision, $publishing ? $revision : $published, $retail, $selected ? $binding : null, $actions,
            $ready, hidden: EndingChoices::hidden($tld->tld),
            retainedAnnualRetail: OfferState::money($tld->catalogue_retained_annual_retail, 2));
    }
}
