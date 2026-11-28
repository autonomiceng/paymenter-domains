<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Holding\Observation;

use Paymenter\Extensions\Servers\Domains\Models\Domain;

/** Purchase reconciliation owns pending generations. Holding reads start after confirmed activation. */
final class ObservationEligibility
{
    public static function allows(Domain $d): bool
    {
        return in_array($d->status, ['active', 'grace', 'expired'], true) && !$d->adoptionPending();
    }
}
