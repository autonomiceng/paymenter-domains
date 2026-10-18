<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

/** Cart arithmetic is independent of publication margin. Slice 16 wires the positive-net check. */
final class RetailLine
{
    public static function positiveNet(string $gross, string $tax, string $discount): bool
    {
        return PricingRule::units($gross, 2) - PricingRule::units($tax, 2) - PricingRule::units($discount, 2) > 0;
    }
}
