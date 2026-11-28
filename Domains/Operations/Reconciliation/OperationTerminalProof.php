<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Reconciliation;

use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionAttempt, AcquisitionClosureKind, AcquisitionLifecycle,
    OperationAttempts, OperationWriter, Transition};
use Paymenter\Extensions\Servers\Domains\Registration\Checkout\UnpaidAcquisitionProof;
use Paymenter\Extensions\Servers\Domains\Renewal\Execution\RenewalAttempt;
use Paymenter\Extensions\Servers\Domains\Services\{EnomAnswers, InvoiceFunding, OperationClaims, RefundProof, RegistrationRefusals};

final class OperationTerminalProof
{
    public static function encode(OperationClaims $claims, int $id): Operation
    {
        return $claims->locked($id, static function ($op, $domain, $all, InvoiceFunding $funding): Operation {
            $now = app(Clock::class)->now();
            $state = AcquisitionLifecycle::settle($op, $domain, $now);
            if ($state->retired || $state->fenced) { return $op; }
            if ($op->state !== 'held') { throw new \DomainException('No definitely-unsent terminal proof.'); }
            $proof = self::requirePurchaseClosure($op, $domain, $all, $now);
            if ($op->funding_basis === 'complimentary' && isset($op->funding['late_credits'])) { $funding->requireInitial($op, $domain); }
            self::recordUnsent($op, $proof, $now);
            $op->update(['state' => 'rejected']);
            if (!self::safe($op, $domain, $now)) { throw new \DomainException('Terminal identity mismatch.'); }
            $domain->update(['status' => $op->action === 'transfer' ? 'transfer_rejected' : 'registration_rejected']);
            return $op;
        });
    }

    public static function requirePurchaseClosure(Operation $op, Domain $domain, iterable $all, \DateTimeImmutable $now): AcquisitionLifecycle
    {
        if ($op->completed_at !== null || $domain->status !== ($op->action === 'transfer' ? 'transfer_pending' : 'registration_pending')
            || ($op->action === 'transfer' && !RefundProof::transferDispositionAvailable($op, $now))
            || array_key_exists('refund', $op->receiptFunding())) {
            throw new \DomainException('No definitely-unsent terminal proof.');
        }
        $proof = RefundProof::requirePurchase($op, $domain, $now);
        if ($proof->facts !== null && collect($all)->contains(static fn ($other) => $other->id !== $op->id && !self::safe($other, $domain, $now))) {
            throw new \DomainException('Another order needs review.');
        }
        return $proof;
    }

    /** Close the attempt and append its operation-level proof in the same transaction. */
    public static function recordUnsent(Operation $op, AcquisitionLifecycle $proof, \DateTimeImmutable $at): void
    {
        $writer = OperationWriter::at($at);
        if (!$proof->canCloseUnsent()
            || ($proof->facts?->current && $writer->releaseUnsentAcquisition($proof) !== Transition::Applied)) {
            throw new \DomainException('Purchase attempt changed.');
        }
        if ($writer->recordTerminal((int) $op->id, AcquisitionClosureKind::Unsent->value, [
            'operation_key' => $op->operation_key, 'generation' => $op->authority_generation,
            'attempt_id' => $proof->facts?->token->attemptId,
        ]) !== Transition::Applied) {
            throw new \DomainException('Terminal proof already recorded.');
        }
        $op->reloadAcquisitionAttempt();
    }

    public static function safe(Operation $op, Domain $domain, ?\DateTimeImmutable $now = null): bool
    {
        if (RegistrationRefusals::proved($op, $domain)) { return true; }
        if ($op->state === 'rejected' && EnomAnswers::unprocessed($op, $domain) && RefundProof::answeredAttempt($op)) { return true; }
        if ($op->authority_generation < 1 || !is_string($op->account_fingerprint) || $op->state !== 'rejected'
            || $op->domain_id !== $domain->id || $op->account_fingerprint !== $domain->account_fingerprint
            || $op->registrar_key !== $domain->registrar_key || $op->server_id !== $domain->server_id || $op->service_id !== $domain->service_id
            || $op->authority_generation !== $domain->authority_generation || $op->confirmed_expires_at !== null || $op->provider_order_id !== null
            || array_intersect(array_keys($op->cost ?? []), ['actual', 'execution'])) { return false; }
        if ($op->action === 'renew') {
            return !array_key_exists('dispatch_attempted_at', $op->policy ?? []) && RenewalAttempt::of($op)->closedBeforeSeal($op);
        }
        if (!AcquisitionAttempt::key($op->action)) { return false; }
        $proof = OperationAttempts::purchaseProof($op, $now ?? app(Clock::class)->now(), DB::transactionLevel() > 0);
        return match ($proof->closure) {
            AcquisitionClosureKind::Unpaid => UnpaidAcquisitionProof::terminal($op, $domain, $proof),
            AcquisitionClosureKind::Unsent => true,
            // The missing-code closure authorizes a payment return while retaining the reservation.
            AcquisitionClosureKind::TransferTimeout, null => false,
        };
    }
}
