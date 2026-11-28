<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

/** The transition that can open acquisition bounds under the operation lock. */
enum AcquisitionAdmission: string
{
    case FirstClaim = 'claim';
    case StaffRelease = 'release';
    case TestCapacity = 'test_capacity';
    case Balance = 'balance';
    case Price = 'price';
    case CostApproval = 'cost_approval';
    case ContactCorrection = 'contact_repair';
    case ContactRetry = 'contact_retry';
    case WorkerRecovery = 'worker_recovery';
    case SavedContactRecovery = 'saved_contact_recovery';

    public function startsWorker(): bool
    {
        return in_array($this, [self::FirstClaim, self::WorkerRecovery, self::SavedContactRecovery], true);
    }
}
