<?php
// Enom 444f31c/d1392a8 Services/Refunds.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Credit, User};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
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
            if ($op->action === 'transfer' && (($op->attempt ?? []) !== [] || $op->safe_code === 'transfer_not_registered')) {
                throw new \DomainException('A fresh registry404 disposition is unavailable; retain this hold.');
            }
            $audit = ['actor_id' => $staffId, 'reason' => $reason, 'at' => $this->clock->now()->format(DATE_ATOM),
                'operation_key' => $op->operation_key, 'generation' => $op->authority_generation];
            // Retain the decision if credit fails; retryPending independently rechecks funding and negative proof.
            ContactRecovery::financialDisposition($op, ['state' => 'rejected', 'safe_code' => 'refund_approved',
                'funding' => [...($op->funding ?? []), 'refund_authorization' => $audit]]);
            if (in_array($op->action,['register','transfer'],true)&&($op->attempt??[])===[]) {
                $op->update(['attempt'=>OperationTerminalProof::rejectedAttempt($op,$l['domain'])]);
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
