<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\RenewalCode;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** A paid Enom renewal waiting out Enom's 24-hour repeat-renewal refusal. The time it may go is fixed when the wait
 * starts: the last finished renewal of the same domain plus 24 hours. Staff and customers read the same words. */
final class RenewalRepeatWait
{
    public const HOURS = 24;
    /** Ends with the time, which each page draws in the viewer's time zone. */
    public const GOES = 'The renewal goes to the registrar automatically at';
    public const NOTHING = 'Nothing more to pay or do.';
    /** Staff only: why it waits. Lists show it as the request's reason. */
    public const STAFF = 'Waiting: Enom refuses a second renewal of the same domain within 24 hours';
    /** The fixed time a waiting renewal may go, or null when it is not waiting on this. */
    public static function until(Operation $op): ?\DateTimeImmutable
    {
        if ($op->action !== 'renew' || $op->state !== 'queued' || $op->safe_code !== RenewalCode::Repeat->value) { return null; }
        $at = $op->policy['renewal_execution']['eligible_at'] ?? null;
        if (!is_string($at)) { return null; }
        try { return new \DateTimeImmutable($at); } catch (\Throwable) { return null; }
    }
}
