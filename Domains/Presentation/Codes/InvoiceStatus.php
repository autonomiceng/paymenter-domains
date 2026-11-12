<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation\Codes;
use Paymenter\Extensions\Servers\Domains\Presentation\State;
/** A Paymenter invoice's status. A pending invoice is Awaiting payment until its due date passes, then Unpaid; dates
 * are Y-m-d in UTC. Paymenter adds statuses without our release, so anything else reads Not recognized. */
final class InvoiceStatus
{
    public const TABLE = ['paid' => State::Paid, 'cancelled' => State::Cancelled];
    public static function state(string $status, ?string $due, string $today): Coded
    {
        if ($status === 'pending') { return Coded::state($due !== null && substr($due, 0, 10) < $today ? State::Unpaid : State::AwaitingPayment); }
        return Coded::from(self::TABLE, $status);
    }
}
