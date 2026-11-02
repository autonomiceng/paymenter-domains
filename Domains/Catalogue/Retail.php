<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Paymenter\Extensions\Servers\Domains\Services\PricingRule;

/** Preserve per-year rounding, including exact totals not divisible into four-place annual rates.
 * Rational numerators use decimal integers throughout, avoiding overflow and floating point. */
final class Retail
{
    public static function quote(Cost $cost, array $rule): ?string
    {
        if (!$cost->enabled) { return null; }
        $rule = PricingRule::validate($rule); $term = $cost->term;
        if ($rule['mode'] === 'fixed') { return PricingRule::total($rule['fixed'][$cost->action], $term); }
        $units = (string) PricingRule::units($cost->total); $markup = (string) PricingRule::units($rule['markup'], 2);
        $n = $units; $d = (string) ($term * 100); $ceil = false;
        if ($rule['mode'] === 'markup_amount') { $n = bcadd($n, bcmul($markup, (string) ($term * 100), 0), 0); }
        else {
            $factor = bcadd('10000', $markup, 0);
            if ($rule['mode'] === 'markup_fee_percent') {
                $fee = $rule['card_fee'];
                $n = bcmul('100', bcadd($units, bcmul((string) PricingRule::units($fee['fixed'], 2), (string) ($term * 100), 0), 0), 0);
                $d = bcmul((string) $term, bcsub('100000000', bcmul((string) PricingRule::units($fee['percent'], 2), $factor, 0), 0), 0);
                $ceil = true;
            } else { $d = bcmul($d, '10000', 0); }
            $n = bcmul($n, $factor, 0);
        }
        if ($ceil) { $n = self::ceil($n, $d); $d = '1'; }
        $minor = match ($rule['round_up']) {
            'none' => $ceil ? $n : bcdiv(bcadd(bcmul($n, '2', 0), $d, 0), bcmul($d, '2', 0), 0),
            'whole' => bcmul(self::ceil($n, bcmul($d, '100', 0)), '100', 0),
            'half' => bcmul(self::ceil($n, bcmul($d, '50', 0)), '50', 0),
            'ending_99' => self::ending99($n, $d),
        };
        if (bccomp($minor, '999999999999', 0) > 0) { throw new \InvalidArgumentException('The calculated price is too large.'); }
        return PricingRule::total(PricingRule::decimal((int) $minor), $term);
    }
    private static function ceil(string $n, string $d): string
    { return bcadd(bcdiv($n, $d, 0), bcmod($n, $d, 0) === '0' ? '0' : '1', 0); }
    private static function ending99(string $n, string $d): string
    {
        $base = bcadd(bcmul(bcdiv($n, bcmul($d, '100', 0), 0), '100', 0), '99', 0);
        return bccomp($n, bcmul($base, $d, 0), 0) > 0 ? bcadd($base, '100', 0) : $base;
    }
}
