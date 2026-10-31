<?php
// Enom 444f31c/d1392a8 Services/Refunds.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Enums\InvoiceTransactionStatus;
use App\Models\{Credit, InvoiceTransaction, User};
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Operation};
/** Account credit only. No provider I/O, gateway refund, reservation release or notification delivery. */
final readonly class Refunds
{
    public function __construct(private Clock $clock) {}
    public function refundHeld(User $actor, int $id, string $reason, ?StaffOperationReview $review = null): ?array
    {
        OperationClaims::outside(); $seed = Operation::findOrFail($id)->domain()->firstOrFail();
        DB::transaction(function () use ($seed, $actor, $id, $reason, $review): void {
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
            if (in_array($op->action,['register','transfer'],true)&&(($op->attempt??[])===[]||($op->action==='transfer'&&($testUnsent||$claimedUnsent)))) {
                $op->update(['attempt'=>OperationTerminalProof::rejectedAttempt($op,$l['domain'])]);
                if($claimedUnsent&&!OperationTerminalProof::safe($op,$l['domain'])){throw new \DomainException('Terminal identity mismatch.');}
            }
            AcquisitionRetirement::retainClosure($op,$l['transactions']->where('invoice_id',$op->invoice_id),
                $l['invoices']->firstWhere('id',$op->invoice_id)?->status,$this->clock->now());
            $review?->record($l);
        });
        return $this->creditRejected($id);
    }
    public function creditRejected(int $id, ?StaffOperationReview $review = null): ?array
    {
        OperationClaims::outside(); $seed = Operation::findOrFail($id)->domain()->firstOrFail();
        return DB::transaction(function () use ($seed, $id, $review): ?array {
            $l = HoldingLocks::take($seed, $review?->actorId); $review?->check($l); $op = $l['operations']->firstWhere('id', $id);
            if ($op?->state === 'rejected' && RenewalCycles::closedEarly($op)) { return $this->creditLate($op, $l, $review); }
            if (!$op || $op->state !== 'rejected' || array_key_exists('refund', $op->funding ?? [])) { if($review){throw new \DomainException('No pending account credit. Reload this page.');} return null; }
            RefundProof::requireUnsent($op, $l['domain']);
            $approved = $op->funding['refund_authorization'] ?? null;
            if (array_key_exists('refund_authorization', $op->funding ?? []) && (!is_array($approved)
                || array_keys($approved) !== ['actor_id', 'reason', 'at', 'operation_key', 'generation']
                || !is_int($approved['actor_id']) || $approved['actor_id'] < 1 || !is_string($approved['at'])
                || !is_string($approved['reason']) || !preg_match('/^[a-z][a-z0-9_]{4,63}$/D', $approved['reason'])
                || $approved['operation_key'] !== $op->operation_key || $approved['generation'] !== $op->authority_generation)) {
                throw new \DomainException('Refund authorization identity changed.');
            }
            if ($op->action === 'transfer' && !RefundProof::transferTimeout($op, $l['domain']) && !OperationTerminalProof::safe($op, $l['domain'])) { throw new \DomainException('Transfer timeout proof required.'); }
            if (!$approved && !RefundProof::transferTimeout($op, $l['domain']) && !RefundProof::retired($op, $l['domain']) && !PrivacyRetirement::safe($op, $l['domain']) && !OperationTerminalProof::safe($op, $l['domain'])) {
                throw new \DomainException('No retained refund disposition.');
            }
            $payment = RefundPayment::requireCurrent($op, $l);
            $credit = $l['credit'] ?? new Credit(['user_id' => $l['user']->id, 'currency_code' => 'USD', 'amount' => '0.00']);
            $credit->amount = PricingRule::decimal(PricingRule::minor((string) $credit->amount) + PricingRule::minor($payment['amount']));
            $credit->save();
            $refund = [...$payment, 'to' => 'account_credit', 'credit_id' => (int) $credit->id,
                'at' => $this->clock->now()->format(DATE_ATOM), 'actor_id' => $approved['actor_id'] ?? null,
                'reason' => $approved['reason'] ?? (RefundProof::transferTimeout($op, $l['domain']) ? 'transfer_code_timeout' : (RefundProof::retired($op, $l['domain']) || PrivacyRetirement::safe($op, $l['domain']) ? 'late_retired_payment' : 'terminal_unsent'))];
            $op->update(['funding' => [...($op->funding ?? []), 'refund' => $refund]]);
            if (in_array($op->action, ['register', 'transfer'], true)) {
                $l['domain']->update(['status' => $op->action === 'transfer' ? 'transfer_rejected' : 'registration_rejected']);
                NativeDomainGuard::terminal($l, $op);
            }
            $review?->record($l);
            return ['amount' => $payment['amount'], 'currency' => 'USD'];
        });
    }
    /** Money that reaches an order after we closed it becomes credit at its own amount, once per payment, however little or much arrives. */
    private function creditLate(Operation $op, array $l, ?StaffOperationReview $review): ?array
    {
        RefundProof::requireUnsent($op, $l['domain']); RefundPayment::requireLine($op, $l);
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
        OperationClaims::outside();
        foreach (Operation::where('server_id', $account->server_id)->where('action', 'register')->where('state', 'held')->whereIn('safe_code', WriteHeld::FINAL_REFUSALS)->orderBy('id')->limit(25)->pluck('id') as $id) {
            try { OperationTerminalProof::encode(app(OperationClaims::class), (int) $id); $this->creditRejected((int) $id); }
            catch (\DomainException $e) { logger()->warning('Domains refused order stays with staff.', ['operation_id' => (int) $id, 'exception_class' => $e::class]); }
        }
    }
    /** A stale pay form can pay an order we closed. Nothing was sent, so its money becomes credit until card refunds exist.
     * One order takes the whole invoice, and RefundPayment::lateCredited stops any other order on it, on any account, crediting the same payment.
     * Funding is encrypted, so SQL cannot skip settled orders. The cursor gives every order a turn; losing it only restarts the pass.
     * Whatever is not credited goes to staff.
     * @return list<int> Domains that got credit, for the caller's notice. */
    public function creditRetired(Account $account): array
    {
        OperationClaims::outside(); $credited = []; $cursor = 'domains.closed-credit-cursor.'.$account->server_id;
        $ids = Operation::where('server_id', $account->server_id)->where('state', 'rejected')->where('safe_code', UnpaidAcquisitionProof::CODE)->where('id', '>', (int) Cache::get($cursor, 0))
            ->whereIn('invoice_id', InvoiceTransaction::select('invoice_id')->where('status', InvoiceTransactionStatus::Succeeded))
            ->selectRaw('min(id) as id')->groupBy('invoice_id')->orderBy('id')->limit(100)->pluck('id');
        Cache::forever($cursor, $ids->count() === 100 ? $ids->last() : 0);
        foreach (Operation::whereIn('id', $ids)->orderBy('id')->get() as $op) {
            $paid = InvoiceTransaction::where('invoice_id', $op->invoice_id)->where('status', InvoiceTransactionStatus::Succeeded)->pluck('id')->all();
            if (array_diff($paid, array_keys(RefundPayment::lateCredited($op))) === []) { continue; }
            try { if ($this->creditClosed($op) !== null) { $credited[] = (int) $op->domain_id; continue; } }
            catch (\Throwable $e) { logger()->warning('Domains late payment stays with staff.', ['operation_id' => (int) $op->id, 'exception_class' => $e::class]); }
            app(AcquisitionRetirement::class)->inspect((int) $op->domain_id);
        }
        return $credited;
    }
    private function creditClosed(Operation $seen): ?array
    {
        return DB::transaction(function () use ($seen): ?array {
            $l = HoldingLocks::take($seen->domain()->firstOrFail(), released: true); $op = $l['operations']->firstWhere('id', $seen->id);
            if (!$op || !UnpaidAcquisitionProof::terminal($op, $l['domain'])) { throw new \DomainException('Closed order proof is unavailable.'); }
            // Paid already started this invoice's services. Credit on top would give the money back and keep them, so a person decides.
            if ($l['invoices']->firstWhere('id', $op->invoice_id)?->status !== 'cancelled') { return null; }
            return $this->creditLate($op, $l, null);
        });
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
