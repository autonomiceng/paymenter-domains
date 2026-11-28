<?php
// Enom 444f31c/d1392a8 Services/Refunds.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Money\Returns;

use App\Models\{Credit, User};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Operations\Reconciliation\OperationTerminalProof;
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement, ComplimentaryInvoices, ContactRecovery,
    FreshTransaction, FundsWaits, HoldingLocks, InvoiceMoney, MoneyDecisions, MoneyOutcome, NativeDomainGuard,
    OperationClaims, PricingRule, PrivacyRetirement, RefundProof, RegistrarAnswers, RegistrationRefusals, RenewalRetries,
    StaffFinancialRights, StaffOperationReview, StaffReviewRefused, TransferRejections};

trait CreditsRejectedRequests
{
    public function refundHeld(User $actor, int $id, string $reason, ?StaffOperationReview $review = null): ?array
    {
        OperationClaims::outside(); $seed = Operation::findOrFail($id)->domain()->firstOrFail();
        $accepted = FreshTransaction::run(function () use ($seed, $actor, $id, $reason, $review): bool {
            $l = HoldingLocks::take($seed, (int) $actor->id); $review?->check($l); $staffId = StaffFinancialRights::require($l, $reason);
            $op = $l['operations']->firstWhere('id', $id);
            if (!$op) { throw new \DomainException('Missing refund obligation.'); }
            $now = $this->clock->now();
            $state = \Paymenter\Extensions\Servers\Domains\Operations\Authority\AcquisitionLifecycle::settle($op, $l['domain'], $now);
            if ($state->retired || $state->fenced) { return false; }
            if (array_key_exists('refund_authorization', $op->funding ?? [])) {
                if ($review || $op->state !== 'rejected') { throw new \DomainException('Invalid retained refund disposition.'); }
                return true;
            }
            if ($op->state !== 'held' || array_key_exists('refund', $op->funding ?? [])) { throw new \DomainException('Only held unpaid-to-provider requests may be closed.'); }
            $refused = RefundProof::requireUnsent($op, $l['domain']); RefundPayment::requireCurrent($op, $l);
            $testUnsent=OperationClaims::testCallRefusalUnsent($op);
            $claimedUnsent=($refused !== null || $op->action==='transfer')
                && OperationTerminalProof::unsentClaim($op,$l['domain'],$l['operations']);
            if ($op->action === 'transfer' && (($op->attempt ?? []) !== [] || $op->safe_code === 'transfer_not_registered')&&!$testUnsent&&!$claimedUnsent) {
                throw new \DomainException('A fresh registry404 disposition is unavailable; retain this hold.');
            }
            $audit = ['actor_id' => $staffId, 'reason' => $reason, 'at' => $now->format(DATE_ATOM),
                'operation_key' => $op->operation_key, 'generation' => $op->authority_generation];
            // Retain the decision if credit fails; retryPending independently rechecks funding and negative proof.
            ContactRecovery::financialDisposition($op, ['state' => 'rejected', 'safe_code' => 'refund_approved',
                'funding' => [...($op->funding ?? []), 'refund_authorization' => $audit]]);
            // The customer pending-refund step belongs only to this paid held-order cancellation, never a zero receipt or registrar refusal.
            if(in_array($op->action,['register','transfer'],true)&&$op->funding_basis==='paid_invoice'){
                $op->update(['policy'=>[...($op->policy??[]),'held_order_refund'=>true]]);
            }
            if (in_array($op->action,['register','transfer'],true)&&(($op->attempt??[])===[]||$claimedUnsent||($op->action==='transfer'&&$testUnsent))) {
                OperationTerminalProof::recordUnsent($op, $l['domain'], $now);
                if($claimedUnsent&&!OperationTerminalProof::safe($op,$l['domain'])){throw new \DomainException('Terminal identity mismatch.');}
            }
            AcquisitionRetirement::retainClosure($op,$l['transactions']->where('invoice_id',$op->invoice_id),
                $l['invoices']->firstWhere('id',$op->invoice_id)?->status,$now);
            // The order and its service end in this commit, before any money moves: a refund that fails later leaves
            // a cancelled service and a retryable refund, never a refund for a live service.
            if (in_array($op->action, ['register', 'transfer'], true)) {
                $l['domain']->update(['status' => $op->action === 'transfer' ? 'transfer_rejected' : 'registration_rejected']);
                NativeDomainGuard::terminal($l, $op);
            }
            $review?->record($l);
            return true;
        });
        if (!$accepted) { throw new StaffReviewRefused(StaffOperationReview::CHANGED); }
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
            fn (array $l): ?array => $this->creditRejectedLocked($l, $id, $review));
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

    private function creditRejectedLocked(array $l, int $id, ?StaffOperationReview $review): ?array
    {
        $review?->check($l); $op = $l['operations']->firstWhere('id', $id);
        // A cancelled in-flight receipt and later money use incremental returns; neither assumes full settlement.
        if ($op?->state === 'rejected' && (RefundProof::retired($op, $l['domain'])
            || ((isset($op->funding['refund'])||self::closedReceipts($op)!==null)&&FundsWaits::closedRenewal($op,$l['domain'])))) { return $this->creditLate($op, $l, $review); }
        if (!$op || $op->state !== 'rejected' || array_key_exists('refund', $op->funding ?? [])) { if($review){throw new \DomainException('No pending account credit. Reload this page.');} return null; }
        if(RenewalRetries::resolved($op,$l['domain'])){$review?->record($l);return null;} // Its paid debt belongs to the proved retry, not a refund.
        $refused = RefundProof::requireReturnable($op, $l['domain']);
        if ($refused !== null && !OperationTerminalProof::safe($op, $l['domain'])) {
            throw new \DomainException('No retained purchase disposition.');
        }
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
    }
}
