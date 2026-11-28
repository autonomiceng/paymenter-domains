<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Jobs\ExecuteOperation;
use Paymenter\Extensions\Servers\Domains\Models\{Operation, ProfileContactOperation};
use Paymenter\Extensions\Servers\Domains\Services\{ContactProfileHold, ContactRecovery, ContactStepRetries, TransferInputs};

/** The admission state shared by successive workers of one acquisition. Writes hold the operation lock. */
final class AcquisitionResult
{
    public static function continuation(Operation $operation, bool $lock = false): ?AcquisitionContinuation
    {
        if (!$operation->exists || $operation->id === null || AcquisitionAttempt::key($operation->action) === null) { return null; }
        return self::readContinuation(self::row($operation, $lock));
    }

    private static function readContinuation(object $row): AcquisitionContinuation
    {
        return new AcquisitionContinuation(
            (int) $row->revision,
            $row->retry_lane === null ? null : AcquisitionLane::from($row->retry_lane),
            $row->retry_due_at === null ? null : (int) $row->retry_due_at,
            $row->confirmed_shortfall_at === null ? null : AcquisitionWindow::time($row->confirmed_shortfall_at),
            self::readWindow($row),
        );
    }

    public static function window(Operation $operation, bool $lock = false): ?AcquisitionWindow
    {
        return self::continuation($operation, $lock)?->window;
    }

    /** The offer reads the same decision without retiring a worker. */
    public static function admissionOpen(Operation $operation, \DateTimeImmutable $now): bool
    {
        $continuation = self::continuation($operation);
        return $continuation === null
            || AcquisitionAuthority::fundsCutoff($operation, $continuation, $now) === AcquisitionAuthority::FUNDS_PROCEED;
    }

    /** Retry outcomes never change the existing admission or the attempt's sealed bounds. */
    public static function schedule(Operation $operation, ?AcquisitionLane $lane, ?int $dueAt,
        ?\DateTimeImmutable $confirmedShortfall = null): void
    {
        if (($lane === null) !== ($dueAt === null)
            || $confirmedShortfall !== null && !in_array($lane, [AcquisitionLane::BalanceWait, AcquisitionLane::FundsWait], true)) {
            throw new \LogicException('Invalid acquisition retry outcome.');
        }
        $row = self::row($operation, true);
        $start = $row->confirmed_shortfall_at;
        if ($start === null && $confirmedShortfall !== null) {
            $start = OperationAttempts::at($confirmedShortfall->setTimezone(new \DateTimeZone('UTC')));
        }
        if ($row->retry_lane === $lane?->value && ($row->retry_due_at === null ? null : (int) $row->retry_due_at) === $dueAt
            && $row->confirmed_shortfall_at === $start) { return; }
        self::update($operation, (int) $row->revision, [
            'retry_lane' => $lane?->value, 'retry_due_at' => $dueAt, 'confirmed_shortfall_at' => $start,
        ]);
    }

    public static function clearShortfall(Operation $operation, AcquisitionContinuation $continuation): void
    {
        if ($continuation->shortfallAt !== null) {
            self::update($operation, $continuation->revision, ['confirmed_shortfall_at' => null]);
        }
    }

    /** The model closes the schedule in the same transaction as any terminal purchase update. */
    public static function close(Operation $operation): void
    {
        self::query($operation)->where(fn ($q) => $q->whereNotNull('retry_lane')->orWhereNotNull('confirmed_shortfall_at'))
            ->increment('revision', 1, [
            'retry_lane' => null, 'retry_due_at' => null, 'confirmed_shortfall_at' => null,
        ]);
        $operation->clearAcquisitionResultSnapshot();
    }

    public static function revision(Operation $operation, bool $lock = true): int
    {
        return (int) self::row($operation, $lock)->revision;
    }

    public static function unclaimed(Operation $operation): bool
    {
        return self::row($operation, true)->first_claim_at === null && self::neverAttempted($operation);
    }

    private static function neverAttempted(Operation $operation, ?\DateTimeImmutable $now = null): bool
    {
        $id = (int) $operation->id;
        return AcquisitionRetryProof::virgin($operation, $now)
            && ($operation->registrar_key !== 'namesilo' || !ProfileContactOperation::where('operation_id', $id)->lockForUpdate()->first(['id']));
    }

    /** A queued worker can use the window's exact held attempt or a deferred worker on that same admission. */
    public static function continues(Operation $operation, AcquisitionWindow $window, AttemptFacts $prior, bool $lock = true, ?\DateTimeImmutable $now = null): bool
    {
        if (!AcquisitionRetryProof::releasedForReadmission($operation, $prior, $lock, $now)
            || !self::ownsAttempt($operation, $window, $prior, $lock)) { return false; }
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
     * The cutoff, the transition and the new bounds share an instant sampled inside the writer.
     */
    public static function admit(Operation $operation, AcquisitionAdmission $admission, int $expectedRevision,
        ?AttemptToken $expectedAttempt, Clock $clock, \Closure $transition, ?\Closure $approve = null,
        ?int $expectedContact = null): AcquisitionAdmissionOutcome
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
        $continuation = self::readContinuation($row);
        $now = $clock->now()->setTimezone(new \DateTimeZone('UTC'));
        if ($latest?->current && $latest->sealed()) {
            $state = AcquisitionLifecycle::settle($operation, $operation->domain, $now);
            if ($state->retired) { return AcquisitionAdmissionOutcome::Unavailable; }
        }
        if (AcquisitionAuthority::fundsCutoff($operation, $continuation, $now, $latest) !== AcquisitionAuthority::FUNDS_PROCEED) {
            return AcquisitionAdmissionOutcome::RetireForCredit;
        }
        $hold = $operation->safe_code;
        $oldLane = $continuation->lane;
        if ($oldLane?->ownsBalance() && $admission->startsWorker() && $admission !== AcquisitionAdmission::FirstClaim) {
            return AcquisitionAdmissionOutcome::Unavailable;
        }
        if ($oldLane?->ownsBalance() && !in_array($admission, [AcquisitionAdmission::Balance, AcquisitionAdmission::FirstClaim], true)) {
            throw new \DomainException('The automatic balance retry owns this request.');
        }
        $proof = ['operation_revision' => $operation->transition_revision, 'result_revision' => $expectedRevision,
            'state' => $operation->state, 'reason' => $hold, 'prior_attempt_id' => $latest?->token->attemptId];
        if ($admission === AcquisitionAdmission::FirstClaim) {
            if ($row->first_claim_at !== null || !self::neverAttempted($operation, $now)
                || !in_array($operation->state, ['queued', 'awaiting_payment', 'awaiting_code'], true)) {
                throw new \DomainException('Acquisition has already claimed a worker.');
            }
        } elseif ($admission->startsWorker()) {
            $window = $continuation->window;
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
            if (!$ordinaryOrigin && !$contactOrigin && !AcquisitionRetryProof::catalogueRetry($operation)) { return AcquisitionAdmissionOutcome::Unavailable; }
            $proof['contact_operation_id'] = $contact?->id;
        } elseif ($admission === AcquisitionAdmission::ContactCorrection) {
            if ($operation->state !== 'held' || $hold !== 'contact_details_required' || $row->first_claim_at !== null
                || !self::neverAttempted($operation, $now) || !ContactRecovery::provenUnsent($operation, $now)) {
                throw new \DomainException('The acquisition hold no longer permits contact correction.');
            }
        } else {
            $purchase = OperationAttempts::purchaseProof($operation, $now, lock: true);
            $closeCurrent = $admission === AcquisitionAdmission::StaffRelease
                && $purchase->canCloseUnsent() && $purchase->facts?->current && $purchase->facts->sealed();
            if ($operation->state !== 'held' || $operation->lease_expires_at !== null
                || (!$closeCurrent && !AcquisitionRetryProof::releasedForReadmission($operation, $latest, true, $now, $purchase))) {
                throw new \DomainException('The acquisition hold no longer permits release.');
            }
            $refusal = $admission === AcquisitionAdmission::TestCapacity
                ? AcquisitionRetryProof::currentTestRefusal($operation, true) : null;
            $valid = match ($admission) {
                AcquisitionAdmission::TestCapacity => $refusal !== null,
                AcquisitionAdmission::Balance => $oldLane === AcquisitionLane::BalanceWait
                    && (int) $row->retry_due_at <= $now->getTimestamp()
                    && AcquisitionRetryProof::balanceRefusalRetryable($operation),
                AcquisitionAdmission::Price => $hold === 'registrar_price_increased',
                AcquisitionAdmission::CostApproval => $hold === 'registrar_price_increased' && $approve !== null,
                AcquisitionAdmission::ContactRetry => ContactStepRetries::candidate($operation)
                    && ContactProfileHold::releaseBlocked($operation) === null,
                AcquisitionAdmission::StaffRelease => !in_array($hold, AcquisitionRetryProof::TEST_CAPACITY_CODES, true),
            };
            if (!$valid) { throw new \DomainException('Acquisition admission proof changed.'); }
            $proof['refusal_evidence_id'] = $refusal;
            $proof['approval_id'] = null;
            if ($admission === AcquisitionAdmission::ContactRetry) {
                $proof['contact_operation_id'] = ProfileContactOperation::where('operation_id', $id)->lockForUpdate()->value('id');
            }
        }

        $saveWindow = $admission === AcquisitionAdmission::FirstClaim || $row->first_claim_at !== null;
        $requiresCutoff = $operation->action === 'transfer' && $admission !== AcquisitionAdmission::ContactCorrection;
        $cutoff = $requiresCutoff ? TransferInputs::deadline($operation, $now) : null;
        if ($requiresCutoff && ($cutoff === null || $cutoff <= $now)) {
            throw new \DomainException('Transfer input deadline is unavailable.');
        }
        if ($admission === AcquisitionAdmission::CostApproval) {
            $approvalId = $approve($now);
            if (!DB::table('domains_complimentary_approvals')->where(['id' => $approvalId, 'operation_id' => $id,
                'amount' => $operation->policy['complimentary_cost_review']['amount']])->exists()) {
                throw new \DomainException('Acquisition cost approval changed.');
            }
            $proof['approval_id'] = $approvalId;
        }
        if ($closeCurrent ?? false) {
            if (OperationWriter::at($now)->releaseUnsentAcquisition($purchase) !== Transition::Applied) {
                throw new \DomainException('The refused purchase has changed.');
            }
            $operation->reloadAcquisitionAttempt();
        }
        $issued = $transition($now);
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
        $balance = $admission === AcquisitionAdmission::Balance
            || ($admission === AcquisitionAdmission::FirstClaim && $oldLane === AcquisitionLane::BalancePurchase);
        $changes = [
            'retry_lane' => $balance ? AcquisitionLane::BalancePurchase->value : AcquisitionLane::Purchase->value,
            'retry_due_at' => $now->getTimestamp(),
        ];
        if ($operation->state === 'held') {
            $changes['retry_lane'] = $changes['retry_due_at'] = null;
        }
        if ($saveWindow) {
            $changes += [
                'window_revision' => $revision,
                'window_origin' => $admission->value,
                'window_reason' => $hold,
                'window_authority_id' => $authorityId,
                'window_started_at' => OperationAttempts::at($now),
                'window_ends_at' => OperationAttempts::at($now->modify('+'.ExecuteOperation::EXECUTION_SECONDS.' seconds')),
                'transfer_input_until' => $cutoff === null ? null : OperationAttempts::at($cutoff),
                'window_expiry_reason' => match (true) {
                    isset($refused) => 'acquisition_definitely_unsent',
                    in_array($hold, ['namesilo_account_guard', 'registrar_price_increased'], true) => $hold,
                    $admission === AcquisitionAdmission::StaffRelease && $hold !== null && $hold !== 'processing' => $hold,
                    default => 'write_rate_limited',
                },
            ];
        }
        if ($admission === AcquisitionAdmission::FirstClaim) { $changes['first_claim_at'] = OperationAttempts::at($now); }
        self::update($operation, $expectedRevision, $changes);
        return AcquisitionAdmissionOutcome::Applied;
    }

    private static function update(Operation $operation, int $revision, array $changes): void
    {
        if (self::query($operation)->where('revision', $revision)->update(['revision' => $revision + 1, ...$changes]) !== 1) {
            throw new \DomainException('Acquisition admission revision changed.');
        }
        $operation->clearAcquisitionResultSnapshot();
    }

    private static function readWindow(object $row): ?AcquisitionWindow
    {
        if ($row->window_started_at === null) { return null; }
        return new AcquisitionWindow(
            (int) $row->window_revision,
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
        $inTransaction = DB::transactionLevel() > 0;
        if ($lock && !$inTransaction) {
            throw new \LogicException('Acquisition result writes require the operation lock.');
        }
        $row = $inTransaction ? null : $operation->acquisitionResultSnapshot();
        if ($row === null) {
            $query = self::query($operation);
            $row = ($lock ? $query->lockForUpdate() : $query)->first();
        }
        return $row ?: throw new \DomainException('Acquisition result unavailable.');
    }

    private static function query(Operation $operation): \Illuminate\Database\Query\Builder
    {
        return DB::table(AcquisitionResultSchema::TABLE)->where('operation_id', $operation->id);
    }
}
