<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Domains\Models\{Operation, ProfileContactOperation};
use Paymenter\Extensions\Servers\Domains\Services\{ContactProfileHold, ContactRecovery, ContactStepRetries, TransferInputs};

/** The admission state shared by successive workers of one acquisition. Writes hold the operation lock. */
final class AcquisitionResult
{
    public static function create(Operation $operation): void
    {
        DB::table(AcquisitionResultSchema::TABLE)->insert(['operation_id' => $operation->id]);
    }

    public static function window(Operation $operation, bool $lock = false): ?AcquisitionWindow
    {
        if (!$operation->exists || $operation->id === null || AcquisitionAttempt::key($operation->action) === null) { return null; }
        return self::readWindow(self::row($operation, $lock));
    }

    public static function revision(Operation $operation, bool $lock = true): int
    {
        return (int) self::row($operation, $lock)->revision;
    }

    public static function unclaimed(Operation $operation): bool
    {
        return self::row($operation, true)->first_claim_at === null && self::neverAttempted($operation);
    }

    private static function neverAttempted(Operation $operation): bool
    {
        $id = (int) $operation->id;
        return !OperationAttempts::hasAttempts($id, AcquisitionAttempt::key($operation->action), true)
            && !ProfileContactOperation::where('operation_id', $id)->lockForUpdate()->first(['id']);
    }

    /** A queued worker can use the window's exact held attempt or a deferred worker on that same admission. */
    public static function continues(Operation $operation, AcquisitionWindow $window, AttemptFacts $prior, bool $lock = true): bool
    {
        if (!AcquisitionRetryProof::releasedForReadmission($operation, $prior, $lock)
            || !self::ownsAttempt($operation, $window, $prior, $lock)
            || ($window->expiryReason === 'acquisition_definitely_unsent'
                && AcquisitionAttempt::purchaseRefusal($operation, $lock) === null)) { return false; }
        return $operation->safe_code === null
            || $operation->safe_code === 'processing'
            || ($operation->safe_code === 'write_rate_limited' && AcquisitionRetryProof::deferred($prior, $lock));
    }

    /** Fences every continuation by the saved admission, including a worker retired after a later check. */
    public static function ownsAttempt(Operation $operation, AcquisitionWindow $window, AttemptFacts $attempt, bool $lock): bool
    {
        $authority = DB::table(OperationSchema::EVIDENCE)->where([
            'id' => $window->authorityId, 'operation_id' => $operation->id, 'kind' => 'admission_window',
            'source' => 'local', 'outcome' => $window->origin,
        ])->when($lock, fn ($q) => $q->lockForUpdate())->first(['attempt_id']);
        if ($authority === null) { return false; }
        $token = $attempt->token;
        if ((int) $authority->attempt_id === $token->attemptId) { return true; }
        $claim = OperationAttempts::evidence($token->operationId, $token->attemptId, 'claim', $lock);
        return ($claim['evidence']['identity'] ?? null) === $token->claimKey
            && ($claim['evidence']['admission_id'] ?? null) === $window->authorityId;
    }

    /**
     * The sole writer of send bounds. The caller holds the operation and its native authority locks.
     * The transition saves corrected details, releases the exact hold or claims the worker in this same transaction.
     * Its worker transitions return the newly written token. Other transitions queue the request (or keep corrected transfers held).
     * Returns false if an expired window does not allow worker recovery.
     */
    public static function admit(Operation $operation, AcquisitionAdmission $admission, int $expectedRevision,
        ?AttemptToken $expectedAttempt, \DateTimeImmutable $now, \Closure $transition, ?int $approvalId = null,
        ?int $expectedContact = null): bool
    {
        $key = AcquisitionAttempt::key($operation->action) ?? throw new \LogicException('Acquisition required.');
        $id = (int) $operation->id;
        $current = Operation::whereKey($id)->lockForUpdate()->firstOrFail();
        $row = self::row($operation, true);
        $latest = OperationAttempts::latest($id, $key, true);
        if ((int) $row->revision !== $expectedRevision || $latest?->token != $expectedAttempt
            || $current->transition_revision !== $operation->transition_revision || $current->state !== $operation->state
            || $current->safe_code !== $operation->safe_code || $current->completed_at !== null) {
            throw new \DomainException('Acquisition admission changed.');
        }
        $hold = $operation->safe_code;
        $proof = ['operation_revision' => $operation->transition_revision, 'result_revision' => $expectedRevision,
            'state' => $operation->state, 'reason' => $hold, 'prior_attempt_id' => $latest?->token->attemptId];
        if ($admission === AcquisitionAdmission::FirstClaim) {
            if ($row->first_claim_at !== null || !self::neverAttempted($operation) || !AcquisitionRetryProof::virgin($operation)
                || !in_array($operation->state, ['queued', 'awaiting_payment', 'awaiting_code'], true)) {
                throw new \DomainException('Acquisition has already claimed a worker.');
            }
        } elseif ($admission->startsWorker()) {
            $window = self::readWindow($row);
            $recoveringContact = $admission === AcquisitionAdmission::SavedContactRecovery;
            $contact = $recoveringContact && $expectedContact !== null
                ? ContactProfileHold::forReadmission($operation, $now, true) : null;
            $eligible = $recoveringContact
                ? $contact !== null && (int) $contact->id === $expectedContact
                : $expectedContact === null && AcquisitionRetryProof::localRecovery($operation, $now);
            if ($row->first_claim_at === null || !$latest || !$window || $window->fits($now) || $operation->state === 'queued'
                || !OperationAttempts::replaceable($latest, $now, lock: true) || !$eligible) {
                throw new \DomainException('The acquisition worker cannot be recovered.');
            }
            $origin = AcquisitionAdmission::tryFrom($window->origin);
            $ordinaryOrigin = in_array($origin, [AcquisitionAdmission::FirstClaim, AcquisitionAdmission::WorkerRecovery], true);
            $contactOrigin = $recoveringContact && in_array($origin, [AcquisitionAdmission::ContactRetry, AcquisitionAdmission::SavedContactRecovery], true);
            if (!$ordinaryOrigin && !$contactOrigin && !AcquisitionRetryProof::catalogueRetry($operation)) { return false; }
            $proof['contact_operation_id'] = $contact?->id;
        } elseif ($admission === AcquisitionAdmission::ContactCorrection) {
            if ($operation->state !== 'held' || $hold !== 'contact_details_required' || $row->first_claim_at !== null
                || !self::neverAttempted($operation) || !ContactRecovery::provenUnsent($operation)) {
                throw new \DomainException('The acquisition hold no longer permits contact correction.');
            }
        } else {
            $refused = $admission === AcquisitionAdmission::StaffRelease
                ? AcquisitionAttempt::purchaseRefusal($operation, true) : null;
            if ($operation->state !== 'held' || $operation->lease_expires_at !== null
                || ($refused === null && !AcquisitionRetryProof::releasedForReadmission($operation, $latest, true))) {
                throw new \DomainException('The acquisition hold no longer permits release.');
            }
            $refusal = $admission === AcquisitionAdmission::TestCapacity
                ? AcquisitionRetryProof::currentTestRefusal($operation, true) : null;
            $valid = match ($admission) {
                AcquisitionAdmission::TestCapacity => $refusal !== null,
                AcquisitionAdmission::Balance => AcquisitionRetryProof::balanceRefusalRetryable($operation),
                AcquisitionAdmission::Price => $hold === 'registrar_price_increased',
                AcquisitionAdmission::CostApproval => $hold === 'registrar_price_increased' && $approvalId !== null
                    && DB::table('domains_complimentary_approvals')->where(['id' => $approvalId, 'operation_id' => $id,
                        'amount' => $operation->policy['complimentary_cost_review']['amount']])->exists(),
                AcquisitionAdmission::ContactRetry => ContactStepRetries::candidate($operation)
                    && ContactProfileHold::releaseBlocked($operation) === null,
                AcquisitionAdmission::StaffRelease => !in_array($hold, AcquisitionRetryProof::TEST_CAPACITY_CODES, true),
            };
            if (!$valid) { throw new \DomainException('Acquisition admission proof changed.'); }
            $proof['refusal_evidence_id'] = $refusal;
            $proof['approval_id'] = $approvalId;
            if ($admission === AcquisitionAdmission::ContactRetry) {
                $proof['contact_operation_id'] = ProfileContactOperation::where('operation_id', $id)->lockForUpdate()->value('id');
            }
        }

        $saveWindow = $admission === AcquisitionAdmission::FirstClaim || $row->first_claim_at !== null;
        $now = $now->setTimezone(new \DateTimeZone('UTC'));
        $requiresCutoff = $operation->action === 'transfer' && $admission !== AcquisitionAdmission::ContactCorrection;
        $cutoff = $requiresCutoff ? TransferInputs::deadline($operation, $now) : null;
        if ($requiresCutoff && ($cutoff === null || $cutoff <= $now)) {
            throw new \DomainException('Transfer input deadline is unavailable.');
        }
        if (isset($refused) && $refused->current) {
            if (OperationWriter::at($now)->releaseUnsentAcquisition($refused->token, requireUnusedGrant: false) !== Transition::Applied) {
                throw new \DomainException('The refused purchase has changed.');
            }
            $operation->reloadAcquisitionAttempt();
        }
        $issued = $transition();
        if ($admission->startsWorker()) {
            if ($operation->state !== 'running' || !$issued instanceof AttemptToken
                || OperationAttempts::current($id, $key, true)?->token != $issued || $issued == $expectedAttempt) {
                throw new \DomainException('Acquisition worker transition changed.');
            }
        } elseif ($issued !== true
            || $operation->state !== ($operation->action === 'transfer' && $admission === AcquisitionAdmission::ContactCorrection ? 'held' : 'queued')) {
            throw new \DomainException('Acquisition hold transition changed.');
        }
        $revision = $expectedRevision + 1;
        $authorityId = OperationWriter::at($now)->recordAcquisitionAdmission($id, $admission, $revision,
            $admission->startsWorker() ? $issued : $expectedAttempt, $proof);
        $changes = ['revision' => $revision];
        if ($saveWindow) {
            $changes += [
                'window_origin' => $admission->value,
                'window_reason' => $hold,
                'window_authority_id' => $authorityId,
                'window_started_at' => OperationAttempts::at($now),
                'window_ends_at' => OperationAttempts::at($now->modify('+'.ExecuteOperation::EXECUTION_SECONDS.' seconds')),
                'transfer_input_until' => $cutoff === null ? null : OperationAttempts::at($cutoff),
                'window_expiry_reason' => isset($refused) ? 'acquisition_definitely_unsent'
                    : (in_array($hold, ['namesilo_account_guard', 'registrar_price_increased'], true) ? $hold : 'write_rate_limited'),
            ];
        }
        if ($admission === AcquisitionAdmission::FirstClaim) { $changes['first_claim_at'] = OperationAttempts::at($now); }
        if (self::query($operation)->where('revision', $expectedRevision)->update($changes) !== 1) {
            throw new \DomainException('Acquisition admission revision changed.');
        }
        return true;
    }

    private static function readWindow(object $row): ?AcquisitionWindow
    {
        if ($row->window_started_at === null) { return null; }
        return new AcquisitionWindow(
            (int) $row->revision,
            $row->window_origin,
            $row->window_reason,
            (int) $row->window_authority_id,
            AcquisitionWindow::time($row->window_started_at),
            AcquisitionWindow::time($row->window_ends_at),
            $row->transfer_input_until === null ? null : AcquisitionWindow::time($row->transfer_input_until),
            $row->window_expiry_reason,
        );
    }

    private static function row(Operation $operation, bool $lock): object
    {
        if ($lock && DB::transactionLevel() < 1) {
            throw new \LogicException('Acquisition result writes require the operation lock.');
        }
        $query = self::query($operation);
        return ($lock ? $query->lockForUpdate() : $query)->first()
            ?? throw new \DomainException('Acquisition result unavailable.');
    }

    private static function query(Operation $operation): \Illuminate\Database\Query\Builder
    {
        return DB::table(AcquisitionResultSchema::TABLE)->where('operation_id', $operation->id);
    }
}
