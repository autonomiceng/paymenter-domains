<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/** The one staff time format for plain text (notices and alerts, where no script can show the viewer's zone):
 * Nov 4, 2026, 1:53 PM PST. Pages with markup use the moment partial, which starts from the same text.
 * The zone is domains.time_zone when the host app sets it (for example to the staff member's own), else the app zone. */
final class StaffTime
{
    public static function at(int $at): string
    { return (new \DateTimeImmutable('@'.$at))->setTimezone(new \DateTimeZone(self::zone()))->format('M j, Y, g:i A T'); }
    /** The calendar day of $at in the same zone, for readers who see dates only: Nov 4, 2026. */
    public static function day(int $at): string
    { return (new \DateTimeImmutable('@'.$at))->setTimezone(new \DateTimeZone(self::zone()))->format('M j, Y'); }
    private static function zone(): string
    {
        $zone=config('domains.time_zone')?:config('app.timezone');
        return is_string($zone)&&in_array($zone,\DateTimeZone::listIdentifiers(),true)?$zone:'UTC';
    }
}
