<?php
// Enom 444f31c/d1392a8 Services/Refunds.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Enums\InvoiceTransactionStatus;
use App\Models\{Credit, InvoiceTransaction, User};
use Illuminate\Support\Facades\{Cache, DB, Schema};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Registration\Checkout\UnpaidAcquisitionProof;
use Paymenter\Extensions\Servers\Domains\Money\Returns\{ReceiptReturns, RefundRepairs};
/** Proved no-send returns. Commit credit or a receipt plan atomically, then let the gateway return its receipts. */
final readonly class Refunds
{
    private const REJECTED='refund_rejected', CLOSED='refund_closed';
    /** Renewal states that could still be sent. */
    public const OPEN = ['awaiting_payment', 'queued', 'held'];
    public function __construct(private Clock $clock) {}
    public function refundHeld(User $actor, int $id, string $reason, ?StaffOperationReview $review = null): ?array
    {
        OperationClaims::outside(); $seed = Operation::findOrFail($id)->domain()->firstOrFail();
        FreshTransaction::run(function () use ($seed, $actor, $id, $reason, $review): void {
            $l = HoldingLocks::take($seed, (int) $actor->id); $review?->check($l); $staffId = StaffFinancialRights::require($l, $reason);
            $op = $l['operations']->firstWhere('id', $id);
            if (!$op) { throw new \DomainException('Missing refund obligation.'); }
            if (array_key_exists('refund_authorization', $op->funding ?? [])) {
                if ($review || $op->state !== 'rejected') { throw new \DomainException('Invalid retained refund disposition.'); }
                return;
            }
            if ($op->state !== 'held' || array_key_exists('refund', $op->funding ?? [])) { throw new \DomainException('Only held unpaid-to-provider requests may be closed.'); }
            RefundProof::requireUnsent($op, $l['domain']); RefundPayment::requireCurrent($op, $l);
            $testUnsent=OperationClaims::testCallRefusalUnsent($op);
            $claimedUnsent=$op->action==='transfer'&&OperationTerminalProof::unsentClaim($op,$l['domain'],$l['operations']);
            if ($op->action === 'transfer' && (($op->attempt ?? []) !== [] || $op->safe_code === 'transfer_not_registered')&&!$testUnsent&&!$claimedUnsent) {
                throw new \DomainException('A fresh registry404 disposition is unavailable; retain this hold.');
            }
            $audit = ['actor_id' => $staffId, 'reason' => $reason, 'at' => $this->clock->now()->format(DATE_ATOM),
                'operation_key' => $op->operation_key, 'generation' => $op->authority_generation];
            // Retain the decision if credit fails; retryPending independently rechecks funding and negative proof.
            ContactRecovery::financialDisposition($op, ['state' => 'rejected', 'safe_code' => 'refund_approved',
                'funding' => [...($op->funding ?? []), 'refund_authorization' => $audit]]);
            // The customer pending-refund step belongs only to this paid held-order cancellation, never a zero receipt or registrar refusal.
            if(in_array($op->action,['register','transfer'],true)&&$op->funding_basis==='paid_invoice'){
                $op->update(['policy'=>[...($op->policy??[]),'held_order_refund'=>true]]);
            }
            if (in_array($op->action,['register','transfer'],true)&&(($op->attempt??[])===[]||($op->action==='transfer'&&($testUnsent||$claimedUnsent)))) {
                $op->update(['attempt'=>OperationTerminalProof::rejectedAttempt($op,$l['domain'])]);
                if($claimedUnsent&&!OperationTerminalProof::safe($op,$l['domain'])){throw new \DomainException('Terminal identity mismatch.');}
            }
            AcquisitionRetirement::retainClosure($op,$l['transactions']->where('invoice_id',$op->invoice_id),
                $l['invoices']->firstWhere('id',$op->invoice_id)?->status,$this->clock->now());
            // The order and its service end in this commit, before any money moves: a refund that fails later leaves
            // a cancelled service and a retryable refund, never a refund for a live service.
            if (in_array($op->action, ['register', 'transfer'], true)) {
                $l['domain']->update(['status' => $op->action === 'transfer' ? 'transfer_rejected' : 'registration_rejected']);
                NativeDomainGuard::terminal($l, $op);
            }
            $review?->record($l);
        });
        return $this->creditRejected($id);
    }
    public function creditRejected(int $id, ?StaffOperationReview $review = null): ?array
    {
        OperationClaims::outside(); $seen = Operation::findOrFail($id); $staff = $review !== null;
        // A departed holding released its name: its late money is credited on the released row, never a new holding.
        if ($seen->domain?->status === 'departed') { return $this->creditDeparted($seen, $review); }
        if (ReceiptReturns::plans($seen)) {
            // Consume the bound staff review before gateway recovery. Deferred work stays queued as automatic work, and staff
            // are told to try again.
            $review?->local(static fn()=>null);$review=null;
            $returned = ReceiptReturns::resume($id, $this->clock->now(), self::REJECTED, $deferred);
            if ($deferred && $staff) { throw new StaffReviewRefused(MoneyDecisions::BUSY); }
            $seen->refresh();
            if (ReceiptReturns::malformed($seen) || ReceiptReturns::outstanding($seen)>0 || (!RefundProof::retired($seen, $seen->domain)&&!FundsWaits::closedRenewal($seen,$seen->domain))) { return $returned; }
        }
        // A staff review is answered now on the full scope and never queued; every other caller is automatic, locks only
        // the order's invoice and queues a deferral.
        $decision = MoneyDecisions::run(self::request($seen, $review ? null : self::REJECTED, $review?->actorId),
            function (array $l) use ($id, $review): ?array {
            $review?->check($l); $op = $l['operations']->firstWhere('id', $id);
            // A cancelled in-flight receipt and later money use incremental returns; neither assumes full settlement.
            if ($op?->state === 'rejected' && (RefundProof::retired($op, $l['domain'])
                || ((isset($op->funding['refund'])||isset($op->attempt['terminal']['transactions']))&&FundsWaits::closedRenewal($op,$l['domain'])))) { return $this->creditLate($op, $l, $review); }
            if (!$op || $op->state !== 'rejected' || array_key_exists('refund', $op->funding ?? [])) { if($review){throw new \DomainException('No pending account credit. Reload this page.');} return null; }
            if(RenewalRetries::resolved($op,$l['domain'])){$review?->record($l);return null;} // Its paid debt belongs to the proved retry, not a refund.
            RefundProof::requireReturnable($op, $l['domain']);
            $approved = $op->funding['refund_authorization'] ?? null;
            if (array_key_exists('refund_authorization', $op->funding ?? []) && (!is_array($approved)
                || array_keys($approved) !== ['actor_id', 'reason', 'at', 'operation_key', 'generation']
                || !is_int($approved['actor_id']) || $approved['actor_id'] < 1 || !is_string($approved['at'])
                || !is_string($approved['reason']) || !preg_match('/^[a-z][a-z0-9_]{4,63}$/D', $approved['reason'])
                || $approved['operation_key'] !== $op->operation_key || $approved['generation'] !== $op->authority_generation)) {
                throw new \DomainException('Refund authorization identity changed.');
            }
            if ($op->action === 'transfer' && !TransferRejections::proved($op,$l['domain']) && !RefundProof::transferTimeout($op, $l['domain']) && !OperationTerminalProof::safe($op, $l['domain'])) { throw new \DomainException('Transfer timeout proof required.'); }
            if (!$approved && !RefundProof::transferTimeout($op, $l['domain']) && !RefundProof::retired($op, $l['domain']) && !PrivacyRetirement::safe($op, $l['domain']) && !OperationTerminalProof::safe($op, $l['domain'])) {
                throw new \DomainException('No retained refund disposition.');
            }
            if($op->funding_basis==='complimentary'){
                ComplimentaryInvoices::require($op,$l);
                if(in_array($op->action,['register','transfer'],true)){
                    $l['domain']->update(['status'=>$op->action==='transfer'?'transfer_rejected':'registration_rejected']);
                    NativeDomainGuard::terminal($l,$op);
                }
                $review?->record($l);return null;
            }
            $payment = RefundPayment::requireCurrent($op, $l);
            if (in_array($op->action, ['register', 'transfer'], true)) {
                $l['domain']->update(['status' => $op->action === 'transfer' ? 'transfer_rejected' : 'registration_rejected']);
                NativeDomainGuard::terminal($l, $op);
            }
            $money = InvoiceMoney::load($l, (int)$op->invoice_id);
            $left = min(PricingRule::minor($payment['amount']), InvoiceMoney::held($money));
            if ($left === 0) { $review?->record($l); return null; }
            $payment['amount'] = PricingRule::decimal($left); $payment['payments'] = [];
            foreach ($money['receipts'] as $receiptId => $receipt) {
                $minor = min($left, array_sum(InvoiceMoney::heldFor($money, $receiptId)));
                if ($minor > 0) { $payment['payments'][] = ['id'=>$receiptId, 'amount_minor'=>$minor]; $left -= $minor; }
                if ($left === 0) { break; }
            }
            // Staff chose account credit for this answer, so the card return is skipped and the credit below applies.
            $chosen = RegistrarAnswers::creditOnly($op, $l['domain']);
            if (!$chosen && ReceiptReturns::reserve($op, $l, $payment, $money, $this->clock->now())) { $review?->record($l); return null; }
            $credit = $l['credit'] ?? new Credit(['user_id' => $l['user']->id, 'currency_code' => 'USD', 'amount' => '0.00']);
            $credit->amount = PricingRule::decimal(PricingRule::minor((string) $credit->amount) + PricingRule::minor($payment['amount']));
            $credit->save();
            $refund = [...$payment, 'to' => 'account_credit', 'bridge_reason' => $chosen ? 'account_credit_chosen' : 'gateway_return_unavailable', 'credit_id' => (int) $credit->id,
                'at' => $this->clock->now()->format(DATE_ATOM), 'actor_id' => $approved['actor_id'] ?? null,
                'reason' => $approved['reason'] ?? (RefundProof::transferTimeout($op, $l['domain']) ? 'transfer_code_timeout' : (RefundProof::retired($op, $l['domain']) || PrivacyRetirement::safe($op, $l['domain']) ? 'late_retired_payment' : (RegistrationRefusals::proved($op,$l['domain'])?RegistrationRefusals::CODE:'terminal_unsent')))];
            $op->update(['funding' => [...($op->funding ?? []), 'refund' => $refund]]);
            $review?->record($l);
            return ['amount' => $payment['amount'], 'currency' => 'USD'];
        });
        if ($decision->outcome === MoneyOutcome::Deferred) { if ($staff) { throw new StaffReviewRefused(MoneyDecisions::BUSY); } return $returned ?? null; }
        try {
            if ($decision->error) { throw $decision->error; }
            $result = $decision->value;
            if (!ReceiptReturns::plans(Operation::findOrFail($id))) { RefundRepairs::resolved($id, $this->clock->now()->getTimestamp()); return $result; }
            $resumed = ReceiptReturns::resume($id, $this->clock->now(), self::REJECTED, $deferred);
        } catch (\Throwable $e) { RefundRepairs::failed($id, $this->clock->now()->getTimestamp()); throw $e; }
        // Outside the repair catch: a deferral is queued work, not a failure.
        if ($deferred && $staff) { throw new StaffReviewRefused(MoneyDecisions::BUSY); }
        return $resumed ?? $result ?? ($returned ?? null);
    }
    /** Departure confirmation only: the caller's money decision holds the full locks and has just closed this paid renewal
     * unsent. Its remaining receipt shares become account credit through the late-credit record, in the same decision.
     * @return int the new account credit in minor units */
    public function departLocked(array $l, Operation $op, int &$released = 0): int
    {
        if (!MoneyDecisions::running() || ($l['advisory'] ?? true) || !$l['operations']->contains(static fn ($row) => $row === $op)
            || $op->state !== 'rejected' || !isset($op->attempt['terminal']['transactions']) || !FundsWaits::closedRenewal($op, $l['domain'])) {
            throw new \DomainException('Closed renewal proof unavailable.');
        }
        $released = 0;
        $l['credit'] ??= Credit::where('user_id', $l['user']->id)->where('currency_code', 'USD')->first();
        foreach (ReceiptReturns::localAllocations($op, InvoiceMoney::load($l, (int) $op->invoice_id)) as $a) {
            // Exclude just this reservation to measure its currently available share, as release() does.
            $money = InvoiceMoney::load($l, (int) $op->invoice_id, $a['key']);
            $available = min($a['amount_minor'], array_sum(InvoiceMoney::heldFor($money, $a['transaction_id'])));
            ReceiptReturns::release($op, $a['key'], 'domain_departed', $l);
            $result = $op->funding['receipt_return_results'][$a['key']] ?? [];
            if (($result['state'] ?? null) !== 'released' || ($result['credit_minor'] ?? null) !== $available) {
                throw new \DomainException('Departure return coverage changed.');
            }
            $released += $available;
            // An absent credit row may have been created by the first release; reuse it for later shares.
            $l['credit'] ??= Credit::where('user_id', $l['user']->id)->where('currency_code', 'USD')->first();
        }
        $credited = $this->creditLate($op, $l, null, true);
        return $released + ($credited === null ? 0 : PricingRule::minor($credited['amount']));
    }
    /** Return incremental net funds received after retirement, however little or much arrives. Departure credits locally available shares directly;
     * other callers first adopt the original-payment return. Gateway-owned shares remain reserved in the shared ledger. */
    private function creditLate(Operation $op, array $l, ?StaffOperationReview $review, bool $departure = false): ?array
    {
        RefundProof::requireUnsent($op, $l['domain']); RefundPayment::requireLine($op, $l);
        if (ReceiptReturns::adoptClosed($op, $l, $this->clock->now(), existingOnly: $departure)) { $review?->record($l); return null; }
        $payments = RefundPayment::lateUncredited($op, $l); $minor = array_sum(array_column($payments, 'amount_minor'));
        if ($minor <= 0) { if($review){throw new \DomainException('No pending account credit. Reload this page.');} return null; }
        $credit = $l['credit'] ?? new Credit(['user_id' => $l['user']->id, 'currency_code' => 'USD', 'amount' => '0.00']);
        $credit->amount = PricingRule::decimal(PricingRule::minor((string) $credit->amount) + $minor); $credit->save();
        $late = ['payments' => $payments, 'amount' => PricingRule::decimal($minor), 'currency' => 'USD', 'to' => 'account_credit',
            'credit_id' => (int) $credit->id, 'at' => $this->clock->now()->format(DATE_ATOM)];
        $op->update(['funding' => [...($op->funding ?? []), 'late_credits' => [...($op->funding['late_credits'] ?? []), $late]]]);
        $review?->record($l);
        return ['amount' => $late['amount'], 'currency' => 'USD'];
    }
    /** A registration the registrar refused for good cannot succeed on a retry, so its money returns as credit with no staff step.
     * A proof that refuses leaves the hold and the staff refund button in place. */
    public function closeRefused(Account $account): void
    {
        OperationClaims::outside(); FundsWaits::sweep($account, $this->clock->now());
        foreach (Operation::where('server_id', $account->server_id)->where('action', 'register')->where('state', 'held')->whereIn('safe_code', WriteHeld::FINAL_REFUSALS)->orderBy('id')->limit(25)->pluck('id') as $id) {
            try { OperationTerminalProof::encode(app(OperationClaims::class), (int) $id); $this->creditRejected((int) $id); }
            catch (\DomainException $e) { logger()->warning('Domains refused order stays with staff.', ['operation_id' => (int) $id, 'exception_class' => $e::class]); }
        }
    }
    /** A stale pay form can pay an order we closed. Return its funds only with the retained no-send proof.
     * One order takes the held invoice pool, with every peer return counted under the invoice locks.
     * Funding is encrypted, so SQL cannot skip settled orders. The cursor gives every order a turn and moves past an order
     * only once its outcome is durable: credited, unchanged, queued after contention or with staff. Losing it restarts the pass.
     * Deferred refund work runs first within its own budget, so new orders keep theirs.
     * @return list<int> Domains that got credit, for the caller's notice. */
    public function creditRetired(Account $account): array
    {
        OperationClaims::outside(); $credited = []; $inspect = []; $cursor = 'domains.closed-credit-cursor.'.$account->server_id;
        $failure = null; $from = (int) Cache::get($cursor, 0); $reached = $from; $complete = $kept = false;
        try {
            foreach (MoneyDecisions::queued((int) $account->server_id, 25, [self::CLOSED, self::REJECTED]) as $work) {
                $op = Operation::find($work->subject_id);
                try {
                    if ($op && $work->kind === self::CLOSED) {
                        if (!ReceiptReturns::plans($op) && ReceiptReturns::malformed($op)) { throw new \DomainException('Retained return plan needs review.'); }
                        if ($this->creditClosed($op) !== null) { $credited[] = (int) $op->domain_id; }
                        $inspect[(int) $op->domain_id] = true;
                    } elseif ($op && $work->kind === self::REJECTED && $this->creditRejected((int) $op->id) !== null) {
                        $credited[] = (int) $op->domain_id; $inspect[(int) $op->domain_id] = true;
                    }
                } catch (MoneyWorkUnqueued $e) { throw $e; }
                catch (\Throwable $e) {
                    try { RefundRepairs::failed((int) $work->subject_id, $this->clock->now()->getTimestamp()); }
                    catch (\Throwable $repair) { $failure ??= $repair; continue; } // Kept queued without its repair.
                }
                MoneyDecisions::done($work);
            }
            $ids = Operation::where('server_id', $account->server_id)->where('state', 'rejected')->where('safe_code', UnpaidAcquisitionProof::CODE)->where('id', '>', $from)
                ->whereIn('invoice_id', InvoiceTransaction::select('invoice_id')->where('status', InvoiceTransactionStatus::Succeeded))
                ->selectRaw('min(id) as id')->groupBy('invoice_id')->orderBy('id')->limit(100)->pluck('id');
            foreach (Operation::whereIn('id', $ids)->orderBy('id')->get() as $op) {
                try {
                    if (!ReceiptReturns::plans($op) && ReceiptReturns::malformed($op)) { throw new \DomainException('Retained return plan needs review.'); }
                    if ($this->creditClosed($op) !== null) { $credited[] = (int) $op->domain_id; $reached = $kept ? $reached : (int) $op->id; continue; }
                }
                catch (MoneyWorkUnqueued $e) { throw $e; }
                catch (\Throwable $e) {
                    try { RefundRepairs::failed((int)$op->id, $this->clock->now()->getTimestamp()); }
                    catch (\Throwable $repair) { $failure ??= $repair; $kept = true; } // Later orders still run; the cursor stays here.
                    try { logger()->warning('Domains late payment stays with staff.', ['operation_id' => (int) $op->id, 'exception_class' => $e::class]); }
                    catch (\Throwable $logging) { $failure ??= $logging; }
                }
                $inspect[(int)$op->domain_id] = true; $reached = $kept ? $reached : (int) $op->id;
            }
            $complete = true;
        } catch (\Throwable $e) { $failure ??= $e; }
        finally {
            Cache::forever($cursor, $complete && !$kept ? ($ids->count() === 100 ? $ids->last() : 0) : $reached);
            // Inspect final evidence once per domain, even if a later invoice or its diagnostics failed.
            if($inspect){try{app(AcquisitionRetirement::class)->inspectMany(array_keys($inspect));}
                catch(\Throwable $e){$failure??=$e;}}
        }
        // Renewal receipts run even when a closed order failed above.
        try { $credited = [...$credited, ...$this->creditRenewalSweep($account)]; } catch (\Throwable $e) { $failure ??= $e; }
        if ($failure !== null) { throw $failure; }
        return $credited;
    }
    /** After core commits a receipt on a renewal or complimentary invoice: credit a cancelled renewal's or a complimentary receipt's money. */
    public function creditRenewalReceipts(int $invoice): array
    {
        $credited = []; $failure = null;
        // A retried renewal shares its invoice: one row needing staff does not stop the next from crediting.
        foreach (Operation::where('invoice_id', $invoice)->where(static fn ($q) => $q->where('action', 'renew')->orWhere('funding_basis', 'complimentary'))->orderBy('id')->pluck('id') as $id) {
            try { if ($this->creditCancelledRenewal((int) $id) !== null) { $credited[] = (int) $id; } }
            catch (\Throwable $e) { $failure ??= $e; }
        }
        if ($failure !== null) { throw $failure; }
        return $credited;
    }
    /** A receipt on a cancelled renewal invoice is recorded and the invoice stays cancelled (NativeDomainGuard). Close the
     * renewal unsent with its receipts, then return them once as account credit, keyed by payment row (creditLate). */
    public function creditCancelledRenewal(int $id): ?array
    {
        OperationClaims::outside(); $seen = Operation::findOrFail($id);
        // Complimentary registrations, transfers and renewals all take payments on their zero paid receipt.
        if ($seen->funding_basis === 'complimentary') { return $this->creditComplimentary($seen); }
        if ($seen->action !== 'renew') { return null; }
        if (in_array($seen->state, self::OPEN, true)) {
            $seed = $seen->domain()->firstOrFail();
            FreshTransaction::run(function () use ($seed, $id): void {
                $l = HoldingLocks::take($seed); $op = $l['operations']->firstWhere('id', $id);
                $receipts = $l['transactions']->where('invoice_id', $op?->invoice_id)->filter(static fn ($row) => $row->status !== InvoiceTransactionStatus::Failed);
                if (!$op || !in_array($op->state, self::OPEN, true) || $l['invoices']->firstWhere('id', $op->invoice_id)?->status !== 'cancelled' || $receipts->isEmpty()) { return; }
                $now = $this->clock->now();
                $early = RenewalCycles::early($op, $now) !== null && !isset($op->policy['renewal']['late']);
                try { FundsWaits::closeRenewal($op, $l, $now, $receipts->pluck('id')->values()->all()); }
                // A renewal that may have reached the registrar keeps its money with staff.
                catch (\DomainException) { $op->update(['state' => 'held', 'safe_code' => 'renewal_funding_or_authority']); return; }
                if ($early) {
                    // Callback and sweep save the same locked observation with the actual terminal receipt list.
                    $op->update(['policy' => [...$op->policy, 'early_receipt_retirement' => [
                        'issue' => $op->policy['calendar']['issue'], 'at' => $now->format(DATE_ATOM),
                    ]]]);
                }
            });
        }
        // Only a renewal closed with its receipts is credited here; one closed before any payment keeps the native late path.
        $seen->refresh();
        if ($seen->state !== 'rejected' || ($seen->attempt['terminal']['transactions'] ?? []) === []
            || \App\Models\Invoice::whereKey($seen->invoice_id)->value('status') !== 'cancelled') { return null; }
        return $this->creditRejected($id);
    }
    /** A complimentary order's receipt is a zero paid invoice; a payment on it returns as account credit, keyed by payment row.
     * A departed holding's receipt returns to its old owner only on the verified released row, as departed renewals do. */
    private function creditComplimentary(Operation $seen): ?array
    {
        $seed = $seen->domain()->firstOrFail(); $released = $seed->status === 'departed';
        return FreshTransaction::run(function () use ($seed, $seen, $released): ?array {
            $l = HoldingLocks::take($seed, released: $released); $op = $l['operations']->firstWhere('id', $seen->id);
            if ($released && !DepartureProof::verified($l['domain'])) { throw new \DomainException('Departed complimentary proof is unavailable.'); }
            if (!$op || $op->funding_basis !== 'complimentary' || $op->invoice_id === null) { return null; }
            // The invoice money ledger nets out earlier credit and gateway refunds or lost disputes, per receipt.
            $payments = RefundPayment::lateUncredited($op, $l);
            $minor = array_sum(array_column($payments, 'amount_minor'));
            if ($minor <= 0) { return null; }
            $credit = $l['credit'] ?? new Credit(['user_id' => $l['user']->id, 'currency_code' => 'USD', 'amount' => '0.00']);
            $credit->amount = PricingRule::decimal(PricingRule::minor((string) $credit->amount) + $minor); $credit->save();
            $late = ['payments' => $payments, 'amount' => PricingRule::decimal($minor), 'currency' => 'USD', 'to' => 'account_credit',
                'credit_id' => (int) $credit->id, 'at' => $this->clock->now()->format(DATE_ATOM)];
            $op->update(['funding' => [...($op->funding ?? []), 'late_credits' => [...($op->funding['late_credits'] ?? []), $late]]]);
            // A failed earlier attempt raised the money-return check; this credit settles it.
            if (Schema::hasTable('domains_obligation_alerts')) {
                DB::table('domains_obligation_alerts')->where('operation_id', $op->id)->where('code', 'refund_repair')->whereNull('resolved_at')
                    ->update(['resolved_at' => $this->clock->now()->getTimestamp()]);
            }
            return ['amount' => $late['amount'], 'currency' => 'USD'];
        });
    }
    /** The sweep for receipts the after-commit credit missed. A processing receipt can establish the time of an
     * early cancellation while it waits to settle. Credit is keyed by payment row.
     * @return list<int> Domains that got credit. */
    private function creditRenewalSweep(Account $account): array
    {
        $cursor = 'domains.renewal-receipt-cursor.'.$account->server_id; $from = (int) Cache::get($cursor, 0); $credited = [];
        $rows = Operation::where('server_id', $account->server_id)->where('id', '>', $from)
            ->where(fn ($q) => $q->where('funding_basis', 'complimentary')
                ->orWhere(fn ($q) => $q->where('action', 'renew')->whereIn('invoice_id', \App\Models\Invoice::select('id')->where('status', 'cancelled'))))
            ->whereIn('invoice_id', InvoiceTransaction::select('invoice_id')->where(fn ($q) => $q
                ->where('status', InvoiceTransactionStatus::Succeeded)
                ->orWhere(fn ($q) => $q->where('status', InvoiceTransactionStatus::Processing)
                    ->where('domains_operations.action', 'renew')->where('domains_operations.state', 'awaiting_payment'))))
            ->orderBy('id')->limit(100)->get(['id', 'domain_id']);
        foreach ($rows as $row) {
            try { if ($this->creditCancelledRenewal((int) $row->id) !== null) { $credited[] = (int) $row->domain_id; } }
            catch (\Throwable $e) {
                // The payment stays recorded; staff see the existing money-return check, and later sweeps keep trying.
                try { $this->receiptNeedsStaff((int) $row->id); }
                catch (\Throwable) { /* An alert that cannot be written cannot stop the remaining receipts. */ }
                try { logger()->warning('Domains renewal receipt credit stays with staff.', ['operation_id' => (int) $row->id, 'exception_class' => $e::class]); }
                catch (\Throwable) { /* Logging cannot stop the remaining renewals. */ }
            }
        }
        Cache::forever($cursor, $rows->count() === 100 ? (int) $rows->last()->id : 0);
        return $credited;
    }
    private function receiptNeedsStaff(int $id): void
    {
        if (!Schema::hasTable('domains_obligation_alerts')) { return; }
        DB::transaction(function () use ($id): void {
            $op = Operation::findOrFail($id); $d = Domain::whereKey($op->domain_id)->lockForUpdate()->firstOrFail();
            ObligationAlerts::record($d, 'refund_repair', $this->clock->now()->getTimestamp(), $id);
        });
    }
    private function creditClosed(Operation $seen): ?array
    {
        $returned=ReceiptReturns::plans($seen)?ReceiptReturns::resume((int)$seen->id, $this->clock->now(), self::CLOSED):null;
        $fresh=$seen->fresh();
        // An unreadable return stays with staff before any allocation arithmetic; resume() records a plan's repair itself.
        if (ReceiptReturns::malformed($fresh)) { RefundRepairs::failed((int)$seen->id, $this->clock->now()->getTimestamp()); return null; }
        if (ReceiptReturns::outstanding($fresh)>0) { return null; }
        $decision = MoneyDecisions::run(self::request($seen, self::CLOSED, released: true), function (array $l) use ($seen): ?array {
            $op = $l['operations']->firstWhere('id', $seen->id);
            if (!$op || !UnpaidAcquisitionProof::terminal($op, $l['domain'])) { throw new \DomainException('Closed order proof is unavailable.'); }
            // Paid already started this invoice's services. Credit on top would give the money back and keep them, so a person decides.
            // A scope without this invoice reruns on the full scope; it never reads as an invoice that is not cancelled.
            InvoiceScope::cover($l, (int) $op->invoice_id);
            if ($l['invoices']->firstWhere('id', $op->invoice_id)?->status !== 'cancelled') { return null; }
            return $this->creditLate($op, $l, null);
        });
        if ($decision->outcome === MoneyOutcome::Deferred) { return $returned; }
        if ($decision->error) { throw $decision->error; }
        $result = $decision->value;
        if (ReceiptReturns::plans($seen->fresh())) { return ReceiptReturns::resume((int)$seen->id, $this->clock->now(), self::CLOSED) ?? $result ?? $returned; }
        return $result;
    }
    /** Late money on a departed holding's closed renewal returns to its old owner as account credit. Existing return plans
     * resume first on their own released decisions; this decision reuses the released row's locks only. */
    private function creditDeparted(Operation $seen, ?StaffOperationReview $review): ?array
    {
        $staff = $review !== null;
        if (ReceiptReturns::plans($seen)) {
            if ($review) {
                $checked = MoneyDecisions::run(self::request($seen, null, $review->actorId, true), static function (array $l) use ($seen, $review): void {
                    $review->check($l); self::departedOperation($l, (int) $seen->id); $review->record($l);
                });
                if ($checked->outcome === MoneyOutcome::Deferred) { throw new StaffReviewRefused(MoneyDecisions::BUSY); }
                if ($checked->error) { throw $checked->error; }
                $review = null;
            }
            $returned = ReceiptReturns::resume((int) $seen->id, $this->clock->now(), self::REJECTED, $deferred);
            if ($deferred && $staff) { throw new StaffReviewRefused(MoneyDecisions::BUSY); }
            $fresh = $seen->fresh();
            if (ReceiptReturns::malformed($fresh) || ReceiptReturns::outstanding($fresh) > 0) { return $returned; }
        }
        $decision = MoneyDecisions::run(self::request($seen, $review ? null : self::REJECTED, $review?->actorId, true), function (array $l) use ($seen, $review): ?array {
            $review?->check($l); $op = self::departedOperation($l, (int) $seen->id);
            return $this->creditLate($op, $l, $review, departure: true);
        });
        if ($decision->outcome === MoneyOutcome::Deferred) { if ($staff) { throw new StaffReviewRefused(MoneyDecisions::BUSY); } return $returned ?? null; }
        try {
            if ($decision->error) { throw $decision->error; }
            $result = $decision->value;
            if (!ReceiptReturns::plans(Operation::findOrFail($seen->id))) { RefundRepairs::resolved((int) $seen->id, $this->clock->now()->getTimestamp()); return $result; }
            $resumed = ReceiptReturns::resume((int) $seen->id, $this->clock->now(), self::REJECTED, $deferred);
        } catch (\Throwable $e) { RefundRepairs::failed((int) $seen->id, $this->clock->now()->getTimestamp()); throw $e; }
        // Contention leaves durable retry work, rather than a failed refund.
        if ($deferred && $staff) { throw new StaffReviewRefused(MoneyDecisions::BUSY); }
        return $resumed ?? $result ?? ($returned ?? null);
    }
    private static function departedOperation(array $l, int $id): Operation
    {
        $op = $l['operations']->firstWhere('id', $id);
        if (!DepartureProof::verified($l['domain']) || !$op || $op->action !== 'renew' || $op->state !== 'rejected'
            || (!RefundProof::retired($op, $l['domain']) && !FundsWaits::closedRenewal($op, $l['domain']))) {
            throw new \DomainException('Departed renewal proof is unavailable.');
        }
        return $op;
    }
    /** Automatic work locks only the order's invoice; a miss reruns on the full scope. Staff and orders with no invoice use the full scope. */
    private static function request(Operation $seen, ?string $kind, ?int $staffId = null, bool $released = false): MoneyRequest
    {
        $scoped = $kind !== null && $seen->invoice_id !== null;
        // Reuse a domain the caller already read; the locks check this seed against the locked row.
        $seed = $seen->relationLoaded('domain') && $seen->domain !== null ? $seen->domain : $seen->domain()->firstOrFail();
        return new MoneyRequest($seed, $kind, (int) $seen->id, $staffId, $released,
            $scoped ? MoneyScope::Invoice : MoneyScope::Full, $scoped ? (int) $seen->invoice_id : null);
    }
    /** Bounded caller-selected retry batch; scheduler/fair cursors remain31/39. */
    public function retryPending(int $afterId = 0, int $limit = 100): int
    {
        OperationClaims::outside();
        if ($afterId < 0 || $limit < 1 || $limit > 100) { throw new \InvalidArgumentException('Invalid refund batch.'); }
        $done = 0;
        $ids = RefundCandidates::query(Operation::where('id', '>', $afterId))->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            try { $done += $this->creditRejected((int) $id) !== null ? 1 : 0; }
            catch (\Throwable $e) {
                try { logger()->warning('Domains refund retry failed.', ['operation_id' => (int) $id, 'exception_class' => $e::class]); }
                catch (\Throwable) { /* Logging cannot interrupt the remaining retries. */ }
            }
        }
        return $done;
    }
}
