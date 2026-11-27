<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Reconciliation;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{BalanceRetries, EnomAnswers, InvoiceFunding,
    OperationClaims, RefundProof, RegistrationRefusals, WriteHeld};
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionAttempt, AcquisitionRetryProof, OperationAttempts, OperationWriter, Transition};
use Paymenter\Extensions\Servers\Domains\Registration\Checkout\UnpaidAcquisitionProof;
use Paymenter\Extensions\Servers\Domains\Renewal\Execution\RenewalAttempt;
/** Closure records the acquisition’s own negative send proof. */
final class OperationTerminalProof
{
    public static function encode(OperationClaims $claims, int $id): Operation
    {
        return $claims->locked($id, static function ($op, $domain, $all, InvoiceFunding $funding): Operation {
            if (!self::unsentClaim($op, $domain, $all)) { throw new \DomainException('No definitely-unsent terminal proof.'); }
            if ($op->funding_basis === 'complimentary' && isset($op->funding['late_credits'])) { $funding->requireInitial($op, $domain); }
            self::recordUnsent($op, $domain, app(Clock::class)->now());
            $op->update(['state' => 'rejected']);
            if (!self::safe($op, $domain)) { throw new \DomainException('Terminal identity mismatch.'); }
            $domain->update(['status' => $op->action === 'transfer' ? 'transfer_rejected' : 'registration_rejected']); return $op;
        });
    }
    public static function unsentClaim(Operation $op, Domain $domain, iterable $all): bool
    {
        if ($op->state !== 'held' || $op->lease_expires_at !== null || $op->provider_order_id !== null
            || $op->confirmed_expires_at !== null || array_intersect(array_keys($op->cost ?? []), ['actual', 'execution'])
            || $domain->status !== ($op->action === 'transfer' ? 'transfer_pending' : 'registration_pending')
            || $domain->provider_expires_at !== null || $domain->provider_domain_id !== null || $domain->provider_order_id !== null
            || ($op->action === 'transfer' && !RefundProof::transferDispositionAvailable($op))
            || collect($all)->contains(static fn ($other) => $other->id !== $op->id && !self::safe($other, $domain))) {
            return false;
        }
        $lock = DB::transactionLevel() > 0;
        if (AcquisitionAttempt::dispositionCandidate($op, $lock)
            && AcquisitionAttempt::purchaseRefusal($op, $lock) !== null) {
            return !array_key_exists('refund', $op->receiptFunding());
        }
        $purchaseUnsent = RefundProof::paidTransferPurchaseUnsent($op);
        if ($purchaseUnsent !== null) {
            return $purchaseUnsent && !array_key_exists('refund', $op->receiptFunding())
                && !array_key_exists('dispatch_attempted_at', $op->operationPolicy());
        }
        $a = $op->attempt ?? [];
        return in_array($op->safe_code, [...BalanceRetries::REVIEW_CODES, 'pre_send_interrupted', 'seal_authority_invalid', 'nameservers_required',
                'write_acceptance_required', 'enom_test_call_cap', 'usd_balance_required', 'enom_test_setup_required', ...WriteHeld::UNSENT_REFUSALS], true)
            && isset($a['claim_key']) && ($a['seal'] ?? null) === 'absent' && ($a['send_state'] ?? null) === 'definitely_unsent'
            && !array_diff(array_keys($a), ['claim_key', 'seal', 'send_state', ...AcquisitionRetryProof::LOCAL_REFUSAL_HISTORY])
            && AcquisitionRetryProof::settledRefusalHistory($a) && self::purchaseUnsent($op)
            && !array_key_exists('refund', $op->funding ?? []) && !array_key_exists('dispatch_attempted_at', $op->policy ?? []);
    }

    /** Record the audited local closure under the caller's operation and money locks. */
    public static function recordUnsent(Operation $op, Domain $domain, \DateTimeImmutable $at): void
    {
        $refused = RefundProof::requireUnsent($op, $domain);
        $writer = OperationWriter::at($at);
        $current = $refused?->current ? $refused : null;
        if ($current === null && $op->action === 'transfer' && $op->funding_basis === 'paid_invoice') {
            $current = OperationAttempts::current((int) $op->id, AcquisitionAttempt::key('transfer'), lock: true);
        }
        if ($current && $writer->releaseUnsentAcquisition($current->token, requireUnusedGrant: false) !== Transition::Applied) {
            throw new \DomainException('Purchase attempt changed.');
        }
        if ($writer->recordTerminal((int) $op->id, 'definitely_unsent', [
            'operation_key' => $op->operation_key, 'generation' => $op->authority_generation,
        ]) !== Transition::Applied) {
            throw new \DomainException('Terminal proof already recorded.');
        }
        $op->reloadAcquisitionAttempt();
    }
    /** Retained terminal records can predate purchase steps. Check any recorded step for conflicting evidence. */
    private static function purchaseUnsent(Operation $op): bool
    {
        $prior = $op->acquisitionAttemptFacts();
        return $prior === null || (!AcquisitionAttempt::conflicts($op) && OperationAttempts::releasedUnsent($prior));
    }

    public static function safe(Operation $op, Domain $domain): bool
    {
        if (RegistrationRefusals::proved($op, $domain)) { return true; }
        if ($op->state === 'rejected' && EnomAnswers::unprocessed($op, $domain) && RefundProof::answeredAttempt($op)) { return true; }
        if ($op->safe_code === UnpaidAcquisitionProof::CODE) { return UnpaidAcquisitionProof::terminal($op, $domain); }
        if ($op->authority_generation < 1 || !is_string($op->account_fingerprint) || $op->state !== 'rejected'
            || !in_array($op->action, ['register', 'renew', 'transfer'], true) || $op->account_fingerprint !== $domain->account_fingerprint
            || $op->registrar_key !== $domain->registrar_key || $op->server_id !== $domain->server_id || $op->service_id !== $domain->service_id
            || $op->authority_generation !== $domain->authority_generation || $op->confirmed_expires_at !== null || $op->provider_order_id !== null
            || array_intersect(array_keys($op->cost ?? []), ['actual', 'execution'])) {
            return false;
        }
        $terminal = ['operation_key' => $op->operation_key, 'generation' => $op->authority_generation];
        $lock = DB::transactionLevel() > 0;
        $refused = AcquisitionAttempt::dispositionCandidate($op, $lock) ? AcquisitionAttempt::purchaseRefusal($op, $lock) : null;
        $purchaseUnsent = $refused !== null ? !$refused->current : RefundProof::paidTransferPurchaseUnsent($op);
        if ($purchaseUnsent !== null) {
            $proof = OperationAttempts::evidence((int) $op->id, null, 'terminal', $lock);
            return $purchaseUnsent && ($proof['source'] ?? null) === 'local' && ($proof['outcome'] ?? null) === 'definitely_unsent'
                && $proof['evidence'] === $terminal && !array_key_exists('dispatch_attempted_at', $op->operationPolicy());
        }
        $a = $op->attempt ?? [];
        return !array_key_exists('dispatch_attempted_at', $op->policy ?? [])
            && (!array_key_exists('terminal', $a) || $a['terminal'] === $terminal)
            && (
                ($op->action === 'transfer' && ($a['terminal'] ?? null) === $terminal
                    && !array_diff(array_keys($a), ['claim_key', 'seal', 'send_state', 'terminal', 'disposition', 'rejection_basis', ...AcquisitionRetryProof::LOCAL_REFUSAL_HISTORY]))
                || $op->action === 'register' || ($op->action === 'renew' && RenewalAttempt::of($op)->closedBeforeSeal($op)))
            && ($op->action === 'renew' || (AcquisitionRetryProof::settledRefusalHistory($a)
                && self::purchaseUnsent($op)
                && (!AcquisitionRetryProof::testCallHistoryOnly($a) || OperationClaims::testCallRefusalUnsent($op)
                    || OperationClaims::testCallRefusalTerminal($op))))
            && ($op->action === 'renew' || (($a['disposition'] ?? null) === 'rejected' && ($a['rejection_basis'] ?? null) === 'definitely_unsent'
            && ($a['send_state'] ?? null) === 'definitely_unsent' && ($a['seal'] ?? null) === 'absent'
            && !array_intersect(array_keys($a), ['execution', 'dispatch_attempted_at', 'provider_reference', 'evidence', 'registration'])
            && ($a['charge'] ?? null) === null && ($a['order_candidates'] ?? []) === []));
    }
}
