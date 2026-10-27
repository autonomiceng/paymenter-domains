<?php
// Adapted from Enom 444f31c Services/PricingRule.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Services;

use InvalidArgumentException;

/** Decimal pricing without floating-point money arithmetic. */
final class PricingRule
{
    public const DEFAULT = ['mode' => 'markup_percent', 'markup' => '30.00', 'round_up' => 'none',
        'fixed' => ['register' => null, 'renew' => null, 'transfer' => null]];

    public const ROUNDING = ['none', 'whole', 'half', 'ending_99'];

    public static function units(string $value, int $places = 4): int
    {
        if (!in_array($places, [2, 4], true)) { throw new InvalidArgumentException('Unsupported monetary scale.'); }
        $digits = $places === 2 ? 10 : 8;
        if (!preg_match('/^([0-9]{1,'.$digits.'})(?:\.([0-9]{1,'.$places.'}))?$/D', $value, $parts)) {
            throw new InvalidArgumentException('Enter a valid nonnegative amount.');
        }
        return (int) $parts[1] * (10 ** $places) + (int) str_pad($parts[2] ?? '', $places, '0');
    }

    /** Native amounts may be signed (successful payment adjustments). Reject lost precision. */
    public static function minor(mixed $amount): int
    {
        if (is_float($amount) && is_finite($amount) && abs($amount - round($amount, 2)) < 0.0000001) {
            $amount = number_format($amount, 2, '.', '');
        }
        if (!is_string($amount) && !is_int($amount)) { throw new \RuntimeException('Invalid internal money amount.'); }
        $value = (string) $amount;
        $negative = str_starts_with($value, '-');
        try { $minor = self::units($negative ? substr($value, 1) : $value, 2); }
        catch (InvalidArgumentException) { throw new \RuntimeException('Invalid internal money amount.'); }
        return $negative ? -$minor : $minor;
    }

    public static function decimal(int $units, int $places = 2): string
    {
        $sign = $units < 0 ? '-' : '';
        $units = abs($units);
        return $sign.intdiv($units, 10 ** $places).'.'.str_pad((string) ($units % (10 ** $places)), $places, '0', STR_PAD_LEFT);
    }

    public static function wholesale(mixed $value): ?string
    {
        if (!is_string($value)) { return null; }
        try { return self::decimal(self::units($value), 4); } catch (InvalidArgumentException) { return null; }
    }

    public static function validate(array $rule): array
    {
        if (!in_array($rule['mode'] ?? null, ['fixed', 'markup_amount', 'markup_percent', 'markup_fee_percent'], true)
            || !in_array($rule['round_up'] ?? null, self::ROUNDING, true)) { throw new InvalidArgumentException('Choose a pricing mode and rounding rule.'); }
        $markup = self::units((string) ($rule['markup'] ?? '0'), 2);
        if ($markup > 10000000) { throw new InvalidArgumentException('Enter a markup from 0 to 100000.'); }
        $fixed = [];
        foreach (['register', 'renew', 'transfer'] as $action) {
            $value = $rule['fixed'][$action] ?? null;
            $fixed[$action] = $value === null || $value === '' ? null : self::decimal(self::units((string) $value, 2));
            if ($rule['mode'] === 'fixed' && ($fixed[$action] === null || self::units($fixed[$action], 2) < 1)) {
                throw new InvalidArgumentException('Enter all three prices, at least $0.01 each.');
            }
        }
        $validated = ['mode' => $rule['mode'], 'markup' => self::decimal($markup), 'round_up' => $rule['round_up'], 'fixed' => $fixed];
        if ($rule['mode'] === 'markup_fee_percent') {
            $validated['card_fee'] = CardFee::validate($rule['card_fee'] ?? CardFee::DEFAULT);
            CardFee::minimum('0', $validated['markup'], $validated['card_fee']);
        }
        return $validated;
    }

    /** Per-year retail, rounded before multiplying by the purchased years. */
    public static function price(string $cost, array $rule, string $action): string
    {
        if (!in_array($action, ['register', 'renew', 'transfer'], true)) { throw new InvalidArgumentException('Unknown price action.'); }
        $rule = self::validate($rule);
        if ($rule['mode'] === 'markup_fee_percent') {
            $minor = CardFee::minimum($cost, $rule['markup'], $rule['card_fee']);
            $minor = match ($rule['round_up']) {
                'none' => $minor,
                'whole' => intdiv($minor + 99, 100) * 100,
                'half' => intdiv($minor + 49, 50) * 50,
                'ending_99' => intdiv($minor, 100) * 100 + 99,
            };
            if ($minor > 999999999999) { throw new InvalidArgumentException('The calculated price is too large.'); }
            return self::decimal($minor);
        }
        $units = self::units($cost);
        $markup = self::units($rule['markup'], 2);
        $remainder = 0;
        if ($rule['mode'] === 'fixed') { return $rule['fixed'][$action]; }
        if ($rule['mode'] === 'markup_amount') {
            $units += $markup * 100;
        } else {
            // Split before multiplying to avoid integer overflow at the schema's upper bound.
            $fraction = ($units % 10000) * $markup;
            $units += intdiv($units, 10000) * $markup + intdiv($fraction, 10000);
            $remainder = $fraction % 10000;
        }
        $minor = match ($rule['round_up']) {
            'none' => intdiv($units + 50, 100),
            'whole' => (intdiv($units, 10000) + (($units % 10000 || $remainder) ? 1 : 0)) * 100,
            'half' => (intdiv($units, 5000) + (($units % 5000 || $remainder) ? 1 : 0)) * 50,
            'ending_99' => intdiv($units, 10000) * 100 + 99 + (($units % 10000 > 9900 || $units % 10000 === 9900 && $remainder) ? 100 : 0),
        };
        if ($minor > 999999999999) { throw new InvalidArgumentException('The calculated price is too large.'); }
        return self::decimal($minor);
    }

    /** Round-up choices in plain words, in ROUNDING order. */
    public const ROUNDING_LABELS = ['none' => 'No rounding', 'whole' => 'Up to the next whole dollar', 'half' => 'Up to the next $0.50', 'ending_99' => 'Up to the next .99'];

    /** The rule as staff read it, e.g. "cost + $1.00, rounded up to the next $0.50" or "cost + 30%". */
    public static function describe(array $rule): string
    {
        $rule = self::validate($rule);
        $money = static fn (string $amount): string => '$'.$amount;
        if ($rule['mode'] === 'fixed') {
            return $money($rule['fixed']['register']).' a year to register, '.$money($rule['fixed']['renew']).' to renew and '.$money($rule['fixed']['transfer']).' to transfer';
        }
        $markup = $rule['mode'] === 'markup_amount' ? $money($rule['markup']) : rtrim(rtrim($rule['markup'], '0'), '.').'%';
        $round = ['none' => '', 'whole' => ', rounded up to the next whole dollar', 'half' => ', rounded up to the next $0.50', 'ending_99' => ', rounded up to the next .99'][$rule['round_up']];
        return ($rule['mode'] === 'markup_fee_percent' ? '(cost + card fee) + ' : 'cost + ').$markup.$round;
    }

    public static function covers(string $retail, string $cost): bool
    {
        return self::units($retail, 2) * 100 >= self::units($cost);
    }

    public static function total(string $perYear, int $years): string
    {
        if ($years < 1 || $years > 10) { throw new InvalidArgumentException('Terms are 1 to 10 years.'); }
        $minor = self::units($perYear, 2) * $years;
        if ($minor > 999999999999) { throw new InvalidArgumentException('The calculated price is too large.'); }
        return self::decimal($minor);
    }
}
