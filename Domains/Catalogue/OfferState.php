<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CostUnit};

/** Persistence codec. Facts have one independent age; normalized costs never carry fact proofs. */
final class OfferState
{
    public static function json(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR); }
    public static function decode(mixed $value): array { return UpgradeEvidence::json($value); }
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
        return new Cost($v['action'], $v['term'], $v['enabled'], $v['total'], CostUnit::TermTotal,
            $v['source'], new \DateTimeImmutable($v['observed_at']), new \DateTimeImmutable($v['fresh_until']), $v['currency']);
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
        return new Facts($v['terms'], $v['registryFields'], $v['renewalSafe'], $v['requiresNameservers'],
            $v['autoRenewOnly'], $v['source'], new \DateTimeImmutable($v['observedAt']), new \DateTimeImmutable($v['freshUntil']));
    }
    public static function encodeFacts(Facts $f): string
    { return self::json([...get_object_vars($f), 'observedAt' => $f->observedAt->format(DATE_ATOM), 'freshUntil' => $f->freshUntil->format(DATE_ATOM)]); }
    public static function revision(object $tld, array $defaults, ?object $policy): Revision
    { return new Revision((int) $tld->catalogue_revision, $defaults['_revision'] ?? 0, (int) ($policy?->revision ?? 0)); }
    public static function snapshot(AccountBinding $binding, object $tld, array $defaults, ?object $policy,
        iterable $prices, bool $ready, bool $publishing = false): Snapshot
    {
        $raw = self::decode($tld->catalogue_intent); $intent = [];
        foreach (get_object_vars(new Intent) as $key => $unused) { if (array_key_exists($key, $raw)) { $intent[$key] = $raw[$key]; } }
        foreach (['defaultSell', 'defaultLongest', 'defaultTransfers', 'maxAnnualCost'] as $key) {
            if (array_key_exists($key, $defaults)) { $intent[$key] = $defaults[$key]; }
        }
        $costs = $retail = [];
        foreach ($prices as $p) {
            if ($p->catalogue_observation === null || $p->currency !== 'USD') { continue; }
            $c = self::cost(self::decode($p->catalogue_observation)); $costs[] = $c;
            if ($p->retail !== null) { $retail[$c->key()] = UpgradeEvidence::money($p->retail, 2) ?? throw new \DomainException('Invalid published retail.'); }
        }
        $revision = self::revision($tld, $defaults, $policy);
        $published = $tld->catalogue_published_revision === null ? null : new Revision(...self::decode($tld->catalogue_published_revision));
        $selected = $policy && (int) $policy->server_id === $binding->serverId
            && $policy->registrar_key === $binding->registrarKey && $policy->account_fingerprint === $binding->fingerprint;
        $actions = [];
        foreach (['register', 'transfer'] as $action) { if ($policy?->{$action.'_enabled'}) { $actions[] = $action; } }
        return new Snapshot(new FeedObserved($binding, $tld->tld, $costs), self::facts($tld->catalogue_facts), new Intent(...$intent),
            $revision, $publishing ? $revision : $published, $retail, $selected ? $binding : null, $actions,
            $ready, retainedAnnualRetail: UpgradeEvidence::money($tld->catalogue_retained_annual_retail, 2));
    }
}
