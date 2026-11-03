<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Paymenter\Extensions\Servers\Domains\Data\CostUnit;
use Paymenter\Extensions\Servers\Domains\Services\PricingRule;

/** Pure one-time mapping of legacy intent and independently dated costs. */
final class UpgradeEvidence
{
    public static function intent(?array $rule, ?array $accepted, ?object $row, ?object $tld): Intent
    {
        $hasList = $accepted !== null && $accepted !== [];
        $defaultSell = $rule !== null ? $rule['mode'] !== 'only' : !$hasList;
        $choice = $row?->choice;
        $sell = match ($choice) { 'sell' => true, 'skip' => ($rule['mode'] ?? null) === 'all' ? null : false, default => null };
        $longest = $row?->longest === null ? null : (int) $row->longest;
        $transfers = $row?->transfer === null ? null : (bool) $row->transfer;
        // Acceptance supplies only missing overrides when there is no saved account default.
        if ($rule === null) {
            $old = $accepted[$tld?->tld ?? $row?->tld ?? ''] ?? null;
            $sell ??= $hasList ? !empty($old['register']) : ($tld === null ? null : (bool) $tld->enabled);
            if (!empty($old['register'])) { $longest ??= max($old['register']); }
            if ($old !== null) { $transfers ??= !empty($old['transfer']); }
        }
        // Only a retained master switch is staff intent; created candidates start disabled for staging.
        $stops = $tld !== null && !($tld->catalogue_created ?? false) && !$tld->enabled
            ? ['register', 'renew', 'transfer'] : [];
        return new Intent($defaultSell, $rule['years'] ?? 1, $rule['transfers'] ?? false,
            $rule['max_cost'] ?? null, $sell, $longest, $transfers, $stops);
    }

    public static function money(mixed $value, int $scale): ?string
    {
        if (!is_string($value) && !is_int($value)) { return null; }
        $value = str_contains((string) $value, '.') ? rtrim(rtrim((string) $value, '0'), '.') : (string) $value;
        try { return PricingRule::decimal(PricingRule::units($value, $scale), $scale); }
        catch (\InvalidArgumentException) { return null; }
    }

    /** @return array{costs:array,retained:?string,issues:array} */
    public static function prices(array $prices, ?object $row, ?object $tld): array
    {
        $observations = []; $issues = ['unknown_facts']; $retained = null;
        foreach ($prices as $p) {
            $key = $p->action.':'.$p->term;
            if ($p->action === 'renew' && (int) $p->term === 1 && $p->currency === 'USD' && $p->unit === 'term_total') {
                $retail = self::money($p->retail, 2);
                if ($retail !== null && PricingRule::units($retail, 2) > 0) { $retained = $retail; }
            }
            try {
                if ($p->currency !== 'USD') { throw new \InvalidArgumentException; }
                $unit = CostUnit::from($p->unit);
                $amount = $p->wholesale === null ? null : self::money($p->wholesale, 4);
                $proof = self::json($p->eligibility);
                if ($proof['price_unavailable'] ?? false) { throw new \InvalidArgumentException; }
                // Null without a valid explicit no-price observation is unknown, not disabled.
                if ($p->wholesale !== null && $amount === null || $p->fresh_until === null) { throw new \InvalidArgumentException; }
                $cost = new Cost($p->action, (int) $p->term, $amount !== null, $amount, $unit,
                    $p->source, new \DateTimeImmutable($p->observed_at, new \DateTimeZone('UTC')),
                    new \DateTimeImmutable($p->fresh_until, new \DateTimeZone('UTC')));
                $observations[$key][] = $cost;
            } catch (\ValueError|\Exception $error) { $issues[] = 'invalid_price:'.$key; }
        }
        // Annual fill cells have known units. Null cells conflate disabled/missing: never withdraw.
        if ($row?->read_at !== null) {
            foreach (['register', 'renew', 'transfer'] as $action) {
                $amount = self::money($row->{$action.'_cost'}, 4);
                if ($amount === null || PricingRule::units($amount) === 0) { continue; }
                $at = new \DateTimeImmutable('@'.$row->read_at);
                $observations[$action.':1'][] = new Cost($action, 1, true, $amount, CostUnit::Annual,
                    'legacy_annual_fill', $at, $at->modify('+172800 seconds'));
            }
            try {
                if (array_diff(array_keys(self::json($row->prices)), [1]) !== []) { $issues[] = 'unproven_bucket_terms'; }
            } catch (\JsonException) { $issues[] = 'malformed_fill_prices'; }
        }
        // These obsolete columns have no retained unit/source contract. Do not guess their units or age.
        if ($tld !== null && ($tld->cost_register_terms !== null || $tld->cost_renew !== null || $tld->cost_transfer !== null)) {
            $issues[] = 'unproven_tld_costs';
        }
        $costs = [];
        foreach ($observations as $key => $values) {
            usort($values, static fn (Cost $a, Cost $b): int => $b->observedAt <=> $a->observedAt);
            $newest = $values[0];
            foreach ($values as $value) {
                if ($value->observedAt == $newest->observedAt && [$value->enabled, $value->total] !== [$newest->enabled, $newest->total]) {
                    $issues[] = 'conflicting_price:'.$key; continue 2;
                }
            }
            $costs[$key] = self::encodeCost($newest);
        }
        ksort($costs); $issues = array_values(array_unique($issues)); sort($issues);
        return ['costs' => $costs, 'retained' => $retained, 'issues' => $issues];
    }

    private static function encodeCost(Cost $cost): array
    {
        return ['action' => $cost->action, 'term' => $cost->term, 'enabled' => $cost->enabled,
            'total' => $cost->total, 'currency' => $cost->currency, 'source_unit' => $cost->sourceUnit->value,
            'source' => $cost->source, 'observed_at' => $cost->observedAt->format(DATE_ATOM),
            'fresh_until' => $cost->freshUntil->format(DATE_ATOM)];
    }

    public static function json(mixed $value): array
    {
        if (is_array($value)) { return $value; }
        return $value === null ? [] : json_decode($value, true, 32, JSON_THROW_ON_ERROR);
    }
}
