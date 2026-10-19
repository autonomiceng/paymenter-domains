<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{InvoiceTransaction, User};
use App\Enums\InvoiceTransactionStatus;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** Staff corrections append signed native evidence; never rewrite/delete an original payment. */
final readonly class FinancialAdjustments
{
    public function __construct(private Clock $clock) {}
    public function append(User $actor, int $operationId, string $key, string $amount, string $reason): InvoiceTransaction
    {
        OperationClaims::outside(); $seed = Operation::findOrFail($operationId)->domain()->firstOrFail();
        if (!preg_match('/^[a-z0-9_-]{8,64}$/D', $key) || !preg_match('/^-?(?:0|[1-9][0-9]{0,7})\.[0-9]{2}$/D', $amount)
            || PricingRule::minor($amount) === 0) { throw new \InvalidArgumentException('Exact nonzero adjustment and stable key required.'); }
        return DB::transaction(function () use ($seed, $actor, $operationId, $key, $amount, $reason): InvoiceTransaction {
            $l = HoldingLocks::take($seed, (int) $actor->id); $staffId = StaffFinancialRights::require($l, $reason);
            if (!$l['staff']->hasPermission('admin.invoice_transactions.create')) { throw new \DomainException('Native transaction creation permission required.'); }
            $op = $l['operations']->firstWhere('id', $operationId);
            if (!$op) { throw new \DomainException('Missing native financial obligation.'); }
            [$invoice] = RefundPayment::requireLine($op, $l);
            // Retired debt resolves only by exact full refund; a correction would leave it permanently unsettled.
            if ($invoice->status === 'cancelled' || RefundProof::retired($op, $l['domain'])) {
                throw new \DomainException('Cancelled or retired financial obligations cannot be adjusted.');
            }
            self::requireHistory($op, $l);
            $audit = $op->funding['financial_adjustments'] ?? [];
            foreach ($audit as $entry) {
                if ($entry['key'] === $key) {
                    if ($entry['amount'] !== $amount || $entry['reason'] !== $reason || $entry['actor_id'] !== $staffId) { throw new \DomainException('Adjustment key is already bound.'); }
                    return $l['transactions']->firstWhere('id', $entry['transaction_id']) ?? throw new \DomainException('Adjustment evidence missing.');
                }
            }
            $net = PricingRule::minor($amount);
            foreach ($audit as $entry) { $net += PricingRule::minor($entry['amount']); }
            if ($net > 0) { throw new \DomainException('Corrections may only reverse funding or restore their own earlier reversals.'); }
            // The common owner/invoice prefix serializes siblings; use current locking reads across the whole invoice.
            $invoiceOps = Operation::where('invoice_id', $invoice->id)->orderBy('id')->lockForUpdate()->get();
            if (count($audit) >= 20 || $invoiceOps->contains(fn ($other) => $other->invoice_id === $invoice->id
                && (array_key_exists('refund', $other->funding ?? []) || array_key_exists('refund_authorization', $other->funding ?? [])))) {
                throw new \DomainException('Refunded or closed financial evidence cannot be adjusted here.');
            }
            $payment = InvoiceTransaction::create(['invoice_id' => $invoice->id, 'amount' => $amount, 'fee' => '0.00',
                'status' => 'succeeded', 'is_credit_transaction' => false, 'transaction_id' => 'domains-adjustment:'.$op->id.':'.$key]);
            $audit[] = ['key' => $key, 'amount' => $amount, 'currency' => 'USD', 'actor_id' => $staffId, 'reason' => $reason,
                'transaction_id' => (int) $payment->id, 'at' => $this->clock->now()->format(DATE_ATOM)];
            $op->update(['funding' => [...($op->funding ?? []), 'financial_adjustments' => $audit]]);
            return $payment;
        });
    }
    /** An audit is retained history, never an attempt or funding grant. Unknown facts still refuse admission. */
    public static function unattemptedFunding(Operation $op): bool
    {
        if (($op->funding ?? []) === []) { return true; }
        try {
            if ($op->funding_basis !== 'paid_invoice' || array_keys($op->funding) !== ['financial_adjustments']) { return false; }
            self::history($op); return true;
        } catch (\Throwable) { return false; }
    }
    /** Called with current locked native transactions before accepting any correction history. */
    public static function requireHistory(Operation $op, array $l): void
    {
        foreach (self::history($op) as $entry) {
            $tx = $l['transactions']->firstWhere('id', $entry['transaction_id']);
            if (!$tx || (int) $tx->invoice_id !== $op->invoice_id || $tx->status !== InvoiceTransactionStatus::Succeeded
                || PricingRule::minor($tx->amount) !== PricingRule::minor($entry['amount'])
                || $tx->transaction_id !== 'domains-adjustment:'.$op->id.':'.$entry['key'] || $tx->is_credit_transaction) {
                throw new \DomainException('Correction native evidence changed.');
            }
        }
    }
    private static function history(Operation $op): array
    {
        if (!array_key_exists('financial_adjustments', $op->funding ?? [])) { return []; }
        $audit = $op->funding['financial_adjustments']; $net = 0; $keys = $ids = [];
        if (!is_array($audit) || !array_is_list($audit) || !$audit || count($audit) > 20) { throw new \DomainException('Invalid correction history.'); }
        foreach ($audit as $entry) {
            if (!is_array($entry) || array_keys($entry) !== ['key', 'amount', 'currency', 'actor_id', 'reason', 'transaction_id', 'at']
                || !is_string($entry['key']) || !preg_match('/^[a-z0-9_-]{8,64}$/D', $entry['key'])
                || !is_string($entry['amount']) || !preg_match('/^-?(?:0|[1-9][0-9]{0,7})\.[0-9]{2}$/D', $entry['amount'])
                || PricingRule::minor($entry['amount']) === 0 || $entry['currency'] !== 'USD'
                || !is_int($entry['actor_id']) || $entry['actor_id'] < 1 || !is_int($entry['transaction_id']) || $entry['transaction_id'] < 1
                || !is_string($entry['reason']) || !preg_match('/^[a-z][a-z0-9_]{4,63}$/D', $entry['reason']) || !is_string($entry['at'])
                || (new \DateTimeImmutable($entry['at']))->format(DATE_ATOM) !== $entry['at']) { throw new \DomainException('Invalid correction audit.'); }
            if (isset($keys[$entry['key']]) || isset($ids[$entry['transaction_id']])) { throw new \DomainException('Duplicate correction audit.'); }
            $keys[$entry['key']] = $ids[$entry['transaction_id']] = true;
            $net += PricingRule::minor($entry['amount']);
            if ($net > 0) { throw new \DomainException('Corrections cannot create net funds.'); }
        }
        return $audit;
    }
}
