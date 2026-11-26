<?php
// Enom 444f31c reconciliation-registration/provider-disposition; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Transfer\Execution;

use Paymenter\Extensions\Servers\Domains\Data\TransferStatus;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Operations\Reconciliation\AcquisitionObservation;
use Paymenter\Extensions\Servers\Domains\Services\{TransferFollowup, TransferPolling};

final class TransferObservation
{
    /** Applies transfer-specific status without inferring finality or a settled failed fee. */
    public static function apply(Operation $op, AcquisitionObservation $observation, array &$policy, bool $readStarted, int $now): bool
    {
        $transfer = $observation->transfer();
        if ($readStarted) {
            $policy = TransferFollowup::record($op, $policy, $now, $transfer->value,
                $observation->facts->domain?->owned === true || $observation->facts->candidates !== []);
        }
        $policy['reconciliation']['transfer_privacy_blocked'] = $transfer === TransferStatus::NeedsAction && $observation->facts->transferPrivacyBlocked;
        $policy['reconciliation']['transfer_status'] = $transfer->value; $policy['reconciliation']['read_at'] = $now; unset($policy['reconciliation']['read_failed']);
        if ($op->state === 'held') { $op->update(['state' => 'uncertain', 'safe_code' => 'reconciliation_required']); }
        if ($observation->transferConflict) {
            $policy['reconciliation']['reason'] = $transfer === TransferStatus::Rejected ? 'transfer_rejection_contradiction' : 'transfer_status_contradiction';
            $op->update(['policy' => $policy]); return true;
        }
        if ($transfer === TransferStatus::NeedsAction) {
            $policy['reconciliation']['reason'] = TransferPolling::expired($op,$now) ? 'transfer_deadline_passed' : 'transfer_needs_action';
            $op->update(['state' => 'held', 'safe_code' => 'transfer_needs_action', 'policy' => $policy]); return true;
        }
        if ($transfer === TransferStatus::Rejected) {
            // Status and empty history do not establish irrevocable finality or a settled failed fee.
            $policy['reconciliation']['reason'] = 'transfer_rejection_unreconciled';
            $op->update(['state' => 'uncertain', 'policy' => $policy]); return true;
        }
        return false;
    }
}
