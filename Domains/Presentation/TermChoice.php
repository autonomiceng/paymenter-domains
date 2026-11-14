<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;

use App\Models\CartItem;
use App\Models\Plan;
use Paymenter\Extensions\Servers\Domains\Services\ComplimentaryAccounts;

/**
 * One domain term choice, worded the same wherever a term is picked: search results (register), the cart line
 * and its edit page, Configure, and renewal. "5 years · $54.95"; without a known total, "5 years".
 * Host themes call this too, so the cart reads as /domains/register does.
 */
final class TermChoice
{
    public static function years(int $years): string
    {
        return $years.' '.($years === 1 ? 'year' : 'years');
    }

    public static function label(int $years, ?string $total = null): string
    {
        return self::years($years).($total !== null && $total !== '' ? ' for '.$total : '');
    }

    /** A cart line's total on another term, priced like the line itself (tax and coupon included); complimentary pays $0.00. */
    public static function cartTotal(CartItem $item, Plan $plan): string
    {
        if (ComplimentaryAccounts::authorization((int) auth()->id())) {
            return $item->price->format(0);
        }
        $probe = $item;
        if ($plan->id !== $item->plan_id) {
            $probe = $item->replicate();
            $probe->plan_id = $plan->id;
            $probe->setRelation('plan', $plan);
        }
        $price = $probe->price;

        return $price->format($price->total * $item->quantity);
    }
}
