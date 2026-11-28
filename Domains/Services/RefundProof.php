<?php
// Enom 444f31c/d1392a8 Refunds, stricter retained evidence; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\SendState;
use Paymenter\Extensions\Servers\Domains\Money\Allocations\RenewalFundingLedger;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionAttempt, AcquisitionClosureKind, AcquisitionLifecycle, AcquisitionRetryProof, AttemptFacts, OperationAttempts};
use Paymenter\Extensions\Servers\Domains\Renewal\Execution\RenewalAttempt;
use Paymenter\Extensions\Servers\Domains\Registration\Checkout\UnpaidAcquisitionProof;
/** Payment-return proof. Sent finality never grants definitely-unsent proof or retry authority. */
final class RefundProof
{
    /** Sent transfer settlement is separate from definitely-unsent proof and grants no retry authority. */
    public static function requireReturnable(Operation $op,Domain $d, ?\DateTimeImmutable $now = null): ?AcquisitionClosureKind
    {
        if(TransferRejections::proved($op,$d)||RegistrationRefusals::proved($op,$d)){return null;}
        // An audited not-processed answer returns the payment. It is not local-refusal proof, so it grants no same-debt retry.
        if(EnomAnswers::unprocessed($op,$d)){self::requireOwned($op,$d);self::requireAnsweredAttempt($op);return null;}
        if (AcquisitionAttempt::key($op->action)) { return self::requirePurchase($op, $d, $now ?? app(Clock::class)->now())->closure; }
        self::requireUnsent($op,$d);
        return null;
    }

    /** Return and Release share the purchase phase. Each still needs its own funding and contact checks. */
    public static function requirePurchase(Operation $op, Domain $domain, \DateTimeImmutable $now,
        bool $admission = false, ?AcquisitionLifecycle $proof = null): AcquisitionLifecycle
    {
        $lock = DB::transactionLevel() > 0;
        $proof ??= OperationAttempts::purchaseProof($op, $now, $lock);
        self::requireOwned($op, $domain, ledgerFunding: $proof->facts !== null && $op->funding_basis === 'paid_invoice');
        if ($op->state === 'rejected' ? $admission || $proof->closure === null : !$proof->canCloseUnsent()) {
            throw new OperationRefused(OperationFailure::ProviderEvidence);
        }
        if ($proof->closure === AcquisitionClosureKind::Unpaid && !UnpaidAcquisitionProof::terminal($op, $domain, $proof)) {
            throw new OperationRefused(OperationFailure::ProviderEvidence);
        }
        if (!ContactProfileHold::allowsPurchaseDisposition($op, $lock,
                allowSaved: $op->action === 'transfer' && $op->funding_basis === 'paid_invoice',
                requireRow: $op->safe_code === ContactProfileHold::CODE || ($op->safe_code === 'processing' && $proof->facts !== null))
            || ($admission && !$proof->facts?->sealed() && !FinancialAdjustments::registrarUnattemptedFunding($op, allowClaim: true))) {
            throw new OperationRefused(OperationFailure::ProviderEvidence);
        }
        return $proof;
    }

    /** $renewal: this renewal's attempt facts when the caller already read them in a batch. */
    public static function requireUnsent(Operation $op, Domain $d, ?RenewalFundingLedger $ledger = null,
        ?RenewalAttempt $renewal = null): ?AttemptFacts
    {
        if (AcquisitionAttempt::key($op->action)) { return self::requirePurchase($op, $d, app(Clock::class)->now())->facts; }
        $f = self::requireOwned($op, $d);
        if ($op->action === 'renew') { self::requireRenewalUnsent($op, $f, $ledger, $renewal ?? RenewalAttempt::of($op)); return null; }
        if (PrivacyRetirement::safe($op, $d)) { return null; }
        $a = $op->attempt ?? [];
        if ($a === []) {
            if ($op->started_at !== null || $op->request_fingerprint !== null || array_key_exists('claim', $f) || array_key_exists('seal', $f)) {
                throw new \DomainException('Unknown attempt identity.');
            }
            return null;
        }
        $allowed = ['claim_key', 'seal', 'send_state', 'disposition', 'rejection_basis', 'terminal', 'capacity_deferrals'];
        if (array_key_exists('management_claim', $a)) {
            $allowed[] = 'management_claim';
            $allowed[] = 'management_deferrals';
            if (!ManagementEvidence::deferrals($a) || !is_string($a['management_claim']) || !preg_match('/^[a-f0-9]{32}$/D', $a['management_claim'])
                || !$op->started_at || $op->request_fingerprint !== null || !in_array($op->safe_code, ['management_unconfirmed', 'refund_approved'], true)) {
                throw new \DomainException('Unknown privacy claim.');
            }
        }
        if (!AcquisitionRetryProof::localDeferrals($a) || array_diff(array_keys($a), $allowed)
            || ($a['seal'] ?? null) !== 'absent' || ($a['send_state'] ?? null) !== 'definitely_unsent'
            || (array_key_exists('disposition', $a) && $a['disposition'] !== 'rejected')
            || (array_key_exists('rejection_basis', $a) && $a['rejection_basis'] !== 'definitely_unsent')
            || (isset($a['claim_key']) && (!$op->started_at || !$op->request_fingerprint))
            || array_key_exists('seal', $f) || (array_key_exists('claim', $f) && (!isset($a['claim_key']) || !is_array($f['claim'])))) {
            throw new \DomainException('Unresolved attempt facts prevent refund.');
        }
        if (array_key_exists('claim_key', $a) && (!is_string($a['claim_key']) || !preg_match('/^[a-f0-9]{32}$/D', $a['claim_key'])
            || !in_array($op->safe_code, [...BalanceRetries::REVIEW_CODES, 'namesilo_account_guard', 'pre_send_interrupted',
                'seal_authority_invalid', 'nameservers_required', 'write_acceptance_required', 'write_rate_limited', 'refund_approved',
                'usd_balance_required', 'enom_test_setup_required', ...ContactProfileHold::HOLDS, ...WriteHeld::UNSENT_REFUSALS], true))) {
            throw new \DomainException('Unresolved local claim.');
        }
        if (array_key_exists('terminal', $a) && (($a['terminal']['operation_key'] ?? null) !== $op->operation_key
            || ($a['terminal']['generation'] ?? null) !== $op->authority_generation)) { throw new \DomainException('Foreign terminal proof.'); }
        return null;
    }

    /** A fresh registry 404 before cutoff is the documented exception to hold-code independence (docs/design/transfers.md). */
    public static function transferDispositionAvailable(Operation $op, \DateTimeImmutable $now): bool
    {
        return $op->safe_code !== 'transfer_not_registered' || ExecutionWindow::pastTransferCutoff($op, $now);
    }

    /** Slice25 commits possibly_sent at seal; ONLY its exact typed local prewire result can override it. */
    private const REFUSAL = ['disposition' => 'rejected', 'send_state' => 'definitely_unsent', 'reason' => 'unsent', 'discrepancies' => []];
    /** Identity and money shape every payment return needs: the request still belongs to this holding and its funding is known. */
    private static function requireOwned(Operation $op, Domain $d, bool $ledgerFunding = false): array
    {
        $f = $ledgerFunding ? $op->receiptFunding() : FinancialAdjustments::registrarFunding($op);
        $policy = $ledgerFunding ? $op->operationPolicy() : ($op->policy ?? []);
        $c = $op->cost ?? []; $closed = $op->safe_code === UnpaidAcquisitionProof::CODE;
        $lateCredits = $op->funding_basis !== 'complimentary' && ($op->action === 'renew' || $closed);
        if (!in_array($op->action, ['register', 'renew', 'transfer', 'privacy_purchase', 'privacy_renew'], true) || $op->domain_id !== $d->id || $op->service_id !== $d->service_id
            || $op->server_id !== $d->server_id || $op->registrar_key !== $d->registrar_key || $op->account_fingerprint !== $d->account_fingerprint
            || $op->authority_generation < 1 || $op->authority_generation !== $d->authority_generation
            || $op->provider_order_id !== null || $op->confirmed_expires_at !== null || $op->lease_expires_at !== null
            || array_diff(array_keys($c), ['estimated', 'currency', 'unit'])
            || array_diff(array_keys($f), [...($ledgerFunding ? [] : ['claim', 'seal']), 'refund_authorization', 'refund', 'dispute_holds', 'receipt_return_results', 'financial_adjustments', ...($op->action === 'renew' ? ['auto_pay', 'auto_pay_result'] : []), ...($lateCredits ? ['late_credits'] : [])])
            || array_key_exists('dispatch_attempted_at', $policy)) { throw new \DomainException('Refund lacks definitely-unsent proof.'); }
        if (in_array($op->action, ['register', 'transfer'], true) && ($d->provider_domain_id !== null || $d->provider_order_id !== null || $d->provider_expires_at !== null
            || !in_array($d->status, [...($op->action === 'transfer' ? ['transfer_pending', 'transfer_rejected'] : ['registration_pending', 'registration_rejected']), ...($closed ? ['cancelled'] : [])], true))) { throw new OperationRefused(OperationFailure::ProviderEvidence); }
        if (array_key_exists('financial_adjustments', $f) && (!is_array($f['financial_adjustments']) || !array_is_list($f['financial_adjustments']))) {
            throw new \DomainException('Unknown adjustment evidence.');
        }
        return $f;
    }
    /**
     * A renewal is unsent only through its rows; its JSON copy is not read. Never sealed means no send, so its only
     * other proof is its own terminal row. A sealed attempt is unsent only through the final transport's local refusal.
     */
    private static function requireRenewalUnsent(Operation $op, array $f, ?RenewalFundingLedger $ledger, RenewalAttempt $r): void
    {
        $terminal = $r->terminal()['evidence'] ?? null;
        if ($terminal !== null && (($terminal['operation_key'] ?? null) !== $op->operation_key || ($terminal['generation'] ?? null) !== $op->authority_generation)) {
            throw new \DomainException('Foreign terminal proof.');
        }
        if ($r->unsealed()) {
            if ($op->started_at !== null || $op->request_fingerprint !== null || array_key_exists('claim', $f) || array_key_exists('seal', $f)) { throw new \DomainException('Unknown attempt identity.'); }
            return;
        }
        $funded = $op->funding_basis === 'paid_invoice'
            ? !$r->observed() && !array_key_exists('claim', $f) && !array_key_exists('seal', $f) && RenewalFundingLedger::funded($op, $ledger)
            : isset($f['claim'], $f['seal']) && $f['claim'] === $f['seal'];
        $claimKey = $r->claimKey();
        if (!in_array($op->safe_code, ['renewal_definitely_unsent', 'refund_approved'], true) || !$r->locallyRefused() || $r->reply() !== self::REFUSAL
            || !is_string($claimKey) || !preg_match('/^[a-f0-9]{32}$/D', $claimKey) || $r->claimedAt() === null || $r->requestDigest() === null
            || $r->sentAt() === null || $r->authorizedCost() === null || !in_array($r->mode(), ['ordinary', 'grace'], true) || !$funded) {
            throw new \DomainException('Sealed attempt is not proven prewire refusal.');
        }
    }
    /** Positive proof retained on the sealed attempt voids a not-processed answer for payment returns. */
    public static function answeredAttempt(Operation $op): bool
    {
        if ($step = AcquisitionAttempt::key($op->action)) {
            $id = (int) $op->id;
            $lock = DB::transactionLevel() > 0;
            $attempt = OperationAttempts::sealed($id, $step, $lock);
            if (!$attempt) { return true; }
            $evidence = OperationAttempts::evidenceMany([$id], ['observation'], $lock);
            return !isset($evidence[$id][$attempt->token->attemptId]['observation']);
        }
        if ($op->action !== 'renew') { return true; }
        $answer = RenewalAttempt::of($op)->unprocessedAnswer();
        return $answer !== null && $answer === ($op->policy['enom_answer'] ?? null);
    }
    private static function requireAnsweredAttempt(Operation $op): void
    { if (!self::answeredAttempt($op)) { throw new \DomainException('Not processed needs a sealed attempt without positive proof.'); } }
    public static function transferTimeout(Operation $op, Domain $d, ?\DateTimeImmutable $now = null): bool
    {
        return $op->action === 'transfer' && $op->authority_generation === $d->authority_generation
            && OperationAttempts::purchaseProof($op, $now ?? app(Clock::class)->now(), DB::transactionLevel() > 0)->closure === AcquisitionClosureKind::TransferTimeout;
    }
    public static function retired(Operation $op, Domain $d): bool
    {
        return $op->action === 'renew' && $op->safe_code === 'renewal_cancelled_unpaid' && OperationTerminalProof::safe($op, $d)
            && RenewalAttempt::of($op)->closedOnlyWith(['operation_key' => $op->operation_key, 'generation' => $op->authority_generation,
                'invoice_id' => $op->invoice_id, 'transactions' => []]);
    }
}
