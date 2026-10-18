<?php
// Adapted from Enom 444f31c Services/Catalogue.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use Paymenter\Extensions\Servers\Domains\Data\MarginDecision;

final class MarginPolicy
{
    /** Per-year list retail and cost for the selected term, before tax/discount, in the same currency.
     * Publication only. Renewal false means retain prior price and alert, never stop billing/dispatch. */
    public static function assess(string $action, ?string $cost, ?string $listRetail): MarginDecision
    {
        if (!in_array($action, ['register', 'renew', 'transfer'], true)) { throw new \InvalidArgumentException('Unknown price action.'); }
        if ($cost === null || $listRetail === null) { return new MarginDecision(false, $action === 'renew' ? null : 'unknown_price', 'unknown_price'); }
        $positive = PricingRule::units($listRetail, 2) > 0;
        $below = !PricingRule::covers($listRetail, $cost);
        $hold = $action === 'renew' ? null : (!$positive ? 'nonpositive_price' : ($below ? 'below_cost' : null));
        return new MarginDecision($positive && $hold === null, $hold, $below ? 'below_cost' : (!$positive ? 'nonpositive_price' : null));
    }

}
