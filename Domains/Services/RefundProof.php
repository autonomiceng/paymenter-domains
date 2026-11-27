<?php
// Enom 444f31c/d1392a8 Refunds, stricter retained evidence; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\SendState;
use Paymenter\Extensions\Servers\Domains\Money\Allocations\RenewalFundingLedger;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionAttempt, AcquisitionRetryProof, AttemptFacts, OperationAttempts};
use Paymenter\Extensions\Servers\Domains\Renewal\Execution\RenewalAttempt;
use Paymenter\Extensions\Servers\Domains\Registration\Checkout\UnpaidAcquisitionProof;
/** Payment-return proof. Sent finality never grants definitely-unsent proof or retry authority. */
final class RefundProof
{
    /** Sent transfer settlement is separate from definitely-unsent proof and grants no retry authority. */
    public static function requireReturnable(Operation $op,Domain $d): ?AttemptFacts
    {
        if(TransferRejections::proved($op,$d)||RegistrationRefusals::proved($op,$d)){return null;}
        // An audited not-processed answer returns the payment. It is not local-refusal proof, so it grants no same-debt retry.
        if(EnomAnswers::unprocessed($op,$d)){self::requireOwned($op,$d);self::requireAnsweredAttempt($op);return null;}
        return self::requireUnsent($op,$d);
    }

    /** Readmission uses purchase ledger facts and current funding rather than the return history projection. */
    public static function requireUnsentForAdmission(Operation $op, Domain $domain): void
    {
        $key = AcquisitionAttempt::key($op->action) ?? throw new \LogicException('Acquisition required.');
        $lock = DB::transactionLevel() > 0;
        $unsent = OperationAttempts::unsentPurchase((int) $op->id, $key, $lock);
        self::requireOwned($op, $domain, ledgerTransfer: $unsent !== null && $op->action === 'transfer' && $op->funding_basis === 'paid_invoice');
        if (AcquisitionAttempt::conflicts($op) || !FinancialAdjustments::registrarUnattemptedFunding($op, allowClaim: true)
            || !($unsent ?? AcquisitionRetryProof::virgin($op))) {
            throw new OperationRefused(OperationFailure::ProviderEvidence);
        }
        if ($unsent === true && !ContactProfileHold::allowsPurchaseDisposition($op, $lock)) {
            throw new OperationRefused(OperationFailure::ProviderEvidence);
        }
    }
    /** $renewal: this renewal's attempt facts when the caller already read them in a batch. */
    public static function requireUnsent(Operation $op, Domain $d, ?RenewalFundingLedger $ledger = null,
        ?RenewalAttempt $renewal = null): ?AttemptFacts
    {
        $lock = DB::transactionLevel() > 0;
        if (AcquisitionAttempt::dispositionCandidate($op, $lock)) {
            $refused = AcquisitionAttempt::purchaseRefusal($op, $lock);
            if ($refused) {
                self::requireOwned($op, $d, ledgerTransfer: $op->action === 'transfer' && $op->funding_basis === 'paid_invoice');
                return $refused;
            }
            // Fresh TEST counter refusals keep the separate cap proof and no retained spend grant.
            if (AcquisitionAttempt::key($op->action) !== null && $op->acquisitionAttemptFacts()?->released
                && !OperationClaims::testCallRefusalUnsent($op) && !OperationClaims::testCallRefusalTerminal($op)) {
                throw new OperationRefused(OperationFailure::ProviderEvidence);
            }
        }
        $purchaseUnsent = self::paidTransferPurchaseUnsent($op);
        $f = self::requireOwned($op, $d, ledgerTransfer: $purchaseUnsent !== null);
        if ($purchaseUnsent !== null) {
            if (!$purchaseUnsent || !ContactProfileHold::allowsPurchaseDisposition($op, $lock)) { throw new \DomainException('Unresolved attempt facts prevent refund.'); }
            return null;
        }
        if ($op->action === 'renew') { self::requireRenewalUnsent($op, $f, $ledger, $renewal ?? RenewalAttempt::of($op)); return null; }
        if (PrivacyRetirement::safe($op, $d)) { return null; }
        $a = $op->attempt ?? [];
        $purchase = AcquisitionAttempt::key($op->action);
        $sealed = ($a['seal'] ?? null) === 'sealed';
        // HoldingLocks already read step presence under the holding lock in Domains-owned transactions.
        if ($purchase && $op->exists && !(FreshTransaction::active() && $op->acquisitionStepAbsent())) {
            $lock = DB::transactionLevel() > 0;
            $prior = $op->acquisitionAttemptFacts();
            if ($prior ? (AcquisitionAttempt::conflicts($op) || OperationAttempts::unresolvedRead($prior)
                    || ($sealed ? !$prior->current || $prior->released : !OperationAttempts::releasedUnsent($prior, $lock)))
                : OperationAttempts::step((int) $op->id, $purchase, $lock)?->current_attempt_id !== null) {
                throw new \DomainException('Unresolved attempt facts prevent refund.');
            }
        }
        if (OperationClaims::testCallRefusalUnsent($op) || OperationClaims::testCallRefusalTerminal($op)) { return null; }
        if ($op->action === 'transfer' && array_key_exists('transfer_timeout', $a)) {
            if (!self::transferTimeout($op, $d)) { throw new \DomainException('Transfer is not a definitely-unsent timeout.'); }
            return null;
        }
        if ($a === []) {
            if ($op->started_at !== null || $op->request_fingerprint !== null || array_key_exists('claim', $f) || array_key_exists('seal', $f)) { throw new \DomainException('Unknown attempt identity.'); }
            return null;
        }
        $acquisition = $sealed && $op->registrar_key === 'namesilo' && $purchase !== null;
        $history = $purchase ? AcquisitionRetryProof::LOCAL_REFUSAL_HISTORY : ['capacity_deferrals'];
        $allowed = $sealed ? ['claim_key', 'seal', 'dispatch_attempted_at', 'send_state', 'authorized_cost', ...($acquisition?[$op->action==='transfer'?'transfer_admission':'registration_admission']:[]), 'execution', ...$history]
            : ['claim_key', 'seal', 'send_state', 'disposition', 'rejection_basis', 'terminal', ...$history];
        if(!$sealed&&in_array($op->action,['privacy_purchase','privacy_renew'],true)&&array_key_exists('management_claim',$a)){
            $allowed[]='management_claim';$allowed[]='management_deferrals';if(!ManagementEvidence::deferrals($a)){throw new \DomainException('Unknown management deferral.');}
            if(!is_string($a['management_claim'])||!preg_match('/^[a-f0-9]{32}$/D',$a['management_claim'])||!$op->started_at||$op->request_fingerprint!==null
                ||!in_array($op->safe_code,['management_unconfirmed','refund_approved'],true)){throw new \DomainException('Unknown privacy claim.');}
        }
        if (!AcquisitionRetryProof::localDeferrals($a)) { throw new \DomainException('Unknown capacity deferral.'); }
        if (AcquisitionRetryProof::testCallHistoryOnly($a)
            || (array_key_exists('test_call_refusals', $a) && !AcquisitionRetryProof::testCallHistory($a['test_call_refusals']))
            || array_diff(array_keys($a), $allowed)) {
            throw new \DomainException('Unresolved attempt facts prevent refund.');
        }
        if ($sealed) {
            $funded = isset($f['claim'], $f['seal']) && $f['claim'] === $f['seal'];
            $claimKey = $a['claim_key'] ?? null;
            if (!in_array($op->safe_code, ['acquisition_definitely_unsent', 'refund_approved'], true) || !$acquisition
                || !in_array($a['send_state'] ?? null, ['possibly_sent', 'definitely_unsent'], true)
                || ($a['execution'] ?? null) !== self::REFUSAL
                || !is_string($claimKey) || !preg_match('/^[a-f0-9]{32}$/D', $claimKey)
                || !$op->started_at || !$op->request_fingerprint || !isset($a['dispatch_attempted_at'], $a['authorized_cost'])
                || !isset($a[$op->action==='transfer'?'transfer_admission':'registration_admission']) || !$funded) {
                throw new \DomainException('Sealed attempt is not proven prewire refusal.');
            }
            $attempt = AcquisitionAttempt::of((int) $op->id, $op->action, lock: DB::transactionLevel() > 0);
            if ($attempt && !$attempt->locallyRefused(self::REFUSAL)) {
                throw new \DomainException('Sealed attempt is not proven prewire refusal.');
            }
        } elseif (($a['seal'] ?? null) !== 'absent' || ($a['send_state'] ?? null) !== 'definitely_unsent'
            || (array_key_exists('disposition', $a) && $a['disposition'] !== 'rejected')
            || (array_key_exists('rejection_basis', $a) && $a['rejection_basis'] !== 'definitely_unsent')
            || (isset($a['claim_key']) && (!$op->started_at || !$op->request_fingerprint))) {
            throw new \DomainException('Unsealed attempt is not proven unsent.');
        }
        if (!$sealed && (array_key_exists('seal', $f) || (array_key_exists('claim', $f) && (!isset($a['claim_key']) || !is_array($f['claim']))))) {
            throw new \DomainException('Unsealed financial seal or unknown claim prevents refund.');
        }
        if (!$sealed && array_key_exists('claim_key', $a) && (!is_string($a['claim_key']) || !preg_match('/^[a-f0-9]{32}$/D', $a['claim_key'])
            || !in_array($op->safe_code, [...BalanceRetries::REVIEW_CODES,'namesilo_account_guard', 'pre_send_interrupted', 'seal_authority_invalid', 'nameservers_required', 'write_acceptance_required', 'write_rate_limited', 'refund_approved', 'usd_balance_required', 'enom_test_setup_required', ...ContactProfileHold::HOLDS, ...WriteHeld::UNSENT_REFUSALS], true))) {
            throw new \DomainException('Unresolved local claim.');
        }
        // A contact step hold is unsent only while the order's own contact row shows nothing went to NameSilo.
        if (!$sealed && ($op->safe_code === ContactProfileHold::CODE || $op->safe_code === 'processing' && isset($a['claim_key'])) && !ContactProfileHold::unsent($op)) { throw new OperationRefused(OperationFailure::ProviderEvidence); }
        if (array_key_exists('terminal', $a) && (($a['terminal']['operation_key'] ?? null) !== $op->operation_key
            || ($a['terminal']['generation'] ?? null) !== $op->authority_generation)) { throw new \DomainException('Foreign terminal proof.'); }
        return null;
    }
    /** Null delegates to the strict pre-ledger path. A present ledger cannot be overridden by a hold or refund audit. */
    public static function paidTransferPurchaseUnsent(Operation $op): ?bool
    {
        if ($op->action !== 'transfer' || $op->funding_basis !== 'paid_invoice' || !$op->exists || !$op->id) { return null; }
        $unsent = OperationAttempts::unsentPurchase((int) $op->id, AcquisitionAttempt::key('transfer'), DB::transactionLevel() > 0);
        return $unsent === true ? !AcquisitionAttempt::conflicts($op) : $unsent;
    }

    /** A fresh registry 404 before cutoff is the documented exception to hold-code independence (docs/design/transfers.md). */
    public static function transferDispositionAvailable(Operation $op): bool
    {
        return $op->safe_code !== 'transfer_not_registered' || ExecutionWindow::pastTransferCutoff($op, app(Clock::class)->now());
    }

    /** Slice25 commits possibly_sent at seal; ONLY its exact typed local prewire result can override it. */
    private const REFUSAL = ['disposition' => 'rejected', 'send_state' => 'definitely_unsent', 'reason' => 'unsent', 'discrepancies' => []];
    /** Identity and money shape every payment return needs: the request still belongs to this holding and its funding is known. */
    private static function requireOwned(Operation $op, Domain $d, bool $ledgerTransfer = false): array
    {
        $f = $ledgerTransfer ? $op->receiptFunding() : FinancialAdjustments::registrarFunding($op);
        $policy = $ledgerTransfer ? $op->operationPolicy() : ($op->policy ?? []);
        $c = $op->cost ?? []; $closed = $op->safe_code === UnpaidAcquisitionProof::CODE;
        $lateCredits = $op->funding_basis !== 'complimentary' && ($op->action === 'renew' || $closed);
        if (!in_array($op->action, ['register', 'renew', 'transfer', 'privacy_purchase', 'privacy_renew'], true) || $op->domain_id !== $d->id || $op->service_id !== $d->service_id
            || $op->server_id !== $d->server_id || $op->registrar_key !== $d->registrar_key || $op->account_fingerprint !== $d->account_fingerprint
            || $op->authority_generation < 1 || $op->authority_generation !== $d->authority_generation
            || $op->provider_order_id !== null || $op->confirmed_expires_at !== null || $op->lease_expires_at !== null
            || array_diff(array_keys($c), ['estimated', 'currency', 'unit'])
            || array_diff(array_keys($f), [...($ledgerTransfer ? [] : ['claim', 'seal']), 'refund_authorization', 'refund', 'dispute_holds', 'receipt_return_results', 'financial_adjustments', ...($op->action === 'renew' ? ['auto_pay', 'auto_pay_result'] : []), ...($lateCredits ? ['late_credits'] : [])])
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
    public static function transferTimeout(Operation $op, Domain $d): bool
    {
        $a = $op->attempt ?? []; $at = $op->completed_at;
        $deadline = $at ? TransferInputs::deadline($op, $at->toDateTimeImmutable()) : null;
        return $op->action === 'transfer' && $op->state === 'rejected' && $op->safe_code === 'transfer_code_timeout'
            && $op->term === 1 && $at !== null && $deadline !== null && $at >= $deadline
            && $op->started_at === null && $op->request_fingerprint === null
            && !array_intersect(['claim', 'seal'], array_keys($op->funding ?? []))
            && (!array_key_exists('epp', $op->payload ?? []) || $op->payload['epp'] === null)
            && $a === ['seal' => 'absent', 'send_state' => 'definitely_unsent', 'disposition' => 'rejected', 'rejection_basis' => 'definitely_unsent',
                'transfer_timeout' => ['operation_key' => $op->operation_key, 'generation' => $d->authority_generation, 'deadline' => $deadline->format(DATE_ATOM)]];
    }
    public static function retired(Operation $op, Domain $d): bool
    {
        return $op->action === 'renew' && $op->safe_code === 'renewal_cancelled_unpaid' && OperationTerminalProof::safe($op, $d)
            && RenewalAttempt::of($op)->closedOnlyWith(['operation_key' => $op->operation_key, 'generation' => $op->authority_generation,
                'invoice_id' => $op->invoice_id, 'transactions' => []]);
    }
}
