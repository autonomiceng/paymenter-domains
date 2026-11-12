<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation\Codes;
use Paymenter\Extensions\Servers\Domains\Presentation\{Availability, Read, State};
/** The state a registrar read leaves on a record family (nameservers, contacts, DNS, SRV, DS and glue records), from
 * the readers' own codes (ObservationProjection). "unknown" and "partial" both mean no current complete read, so
 * Availability decides from the read's evidence; the reason under it says what came back. */
final class RecordRead
{
    public const TABLE = ['complete' => State::Ok, 'unsupported' => State::NotSupported];
    public const UNREAD = ['unknown', 'partial'];
    public static function state(string $code, Read $read): Coded
    { return in_array($code, self::UNREAD, true) ? Coded::state(Availability::unread($read)) : Coded::from(self::TABLE, $code); }
}
