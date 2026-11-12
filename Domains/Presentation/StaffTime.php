<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/** The one staff time format for plain text (notices and alerts, where no script can show the viewer's zone):
 * Nov 4, 2026, 1:53 PM PST. Pages with markup use the moment partial, which starts from the same text.
 * The zone is domains.time_zone: the site's default in its Domains config, which the host app may set to the staff
 * member's own. Without one it is the app zone. The text always names the zone. */
final class StaffTime
{
    public static function at(int $at): string { return self::local($at)->format('M j, Y, g:i A T'); }
    /** $at in the same zone, so a date-only reading and its machine date name the same calendar day. */
    public static function local(int $at): \DateTimeImmutable
    { return (new \DateTimeImmutable('@'.$at))->setTimezone(new \DateTimeZone(self::zone())); }
    private static function zone(): string
    {
        $zone=config('domains.time_zone')?:config('app.timezone');
        return is_string($zone)&&in_array($zone,\DateTimeZone::listIdentifiers(),true)?$zone:'UTC';
    }
}
