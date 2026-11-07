<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/** The one staff time format for plain text (notices and alerts, where no script can show the viewer's zone):
 * Nov 4, 2026, 1:53 PM PST. Pages with markup use the moment partial, which starts from the same text. */
final class StaffTime
{
    public const ZONE = 'America/Los_Angeles';
    public static function at(int $at): string
    { return (new \DateTimeImmutable('@'.$at))->setTimezone(new \DateTimeZone(self::ZONE))->format('M j, Y, g:i A T'); }
}
