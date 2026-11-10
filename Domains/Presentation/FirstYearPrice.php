<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/** The first year and renewals are priced apart, and often differ. Search results and the price list say so in the same words. */
final class FirstYearPrice
{
    public const WHY = 'Registrars price registration and renewal separately.';
    /** Under the price list, when any ending's first year differs from its renewal. */
    public const LIST = self::WHY.' Renew is the price of each year after registration.';
    /** A search result's note, or null when both prices are the same or one is unknown. */
    public static function note(?string $first, ?string $renew): ?string
    {
        return self::differs($first, $renew) ? self::WHY.' The first year is '.$first.'; each renewal is '.$renew.' a year.' : null;
    }
    public static function differs(?string $first, ?string $renew): bool
    {
        return $first !== null && $first !== '' && $renew !== null && $renew !== '' && $first !== $renew;
    }
}
