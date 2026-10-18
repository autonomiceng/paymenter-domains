<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

/** Native amounts are PER LINE UNIT, quantity exactly one. Native total ALREADY includes coupons.
 * Native tax stays based on the original gross. Never subtract coupon discount twice. Publication
 * compares the untaxed native list row separately; positive discounted net is not a margin floor.
 */
final class RetailLine
{
    public static function decimal(mixed $native): string
    {
        if (!is_numeric($native) || !is_finite((float) $native) || (float) $native < 0) { throw new \DomainException('Invalid native money.'); }
        return number_format((float) $native, 2, '.', '');
    }
    public static function native(\App\Classes\Price $price): array
    {
        $total = self::decimal($price->total); $tax = self::decimal($price->total_tax);
        if (!$price->available || $price->currency?->code !== 'USD' || !self::positiveNet($total, $tax, '0.00')) {
            throw new \DomainException('Domain price must remain positive after tax and discounts.');
        }
        return ['total' => $total, 'tax' => $tax, 'discount' => self::decimal($price->discount),
            'net' => PricingRule::decimal(PricingRule::units($total, 2) - PricingRule::units($tax, 2))];
    }
    public static function positiveNet(string $gross, string $tax, string $discount): bool
    {
        return PricingRule::units($gross, 2) - PricingRule::units($tax, 2) - PricingRule::units($discount, 2) > 0;
    }
}
