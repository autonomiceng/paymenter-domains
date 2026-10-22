<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

use App\Helpers\{ExtensionHelper, NotificationHelper};
use App\Models\{BillingAgreement, Gateway, Invoice};
use Illuminate\Support\Facades\{DB, Log};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Operation;

/** One saved-method attempt per newly issued debt. No retry entry point or funding authority. */
final readonly class RenewalAutoPay
{
    public function __construct(private Clock $clock) {}

    /** Caller holds HoldingLocks in the invoice-issuing transaction, after native credit settlement. */
    public function issued(Operation $op, Invoice $invoice, array $l, bool $scheduled): void
    {
        if (array_key_exists('auto_pay', $op->funding ?? [])) { return; }
        [$reason, $agreement, $gateway] = $this->eligibility($op, $invoice, $l, $scheduled);
        // Committed BEFORE any gateway call. A crash leaves an uncertain attempt, never permission to retry.
        $marker = ['at'=>$this->clock->now()->format(DATE_ATOM), 'invoice_id'=>(int)$invoice->id,
            'agreement_id'=>$agreement?->id, 'gateway_id'=>$gateway?->id,
            'status'=>$reason === null ? 'uncertain' : 'skipped', 'reason'=>$reason];
        $op->update(['funding'=>[...($op->funding ?? []), 'auto_pay'=>$marker]]);
        if ($reason === null) { DB::afterCommit(fn () => $this->charge($op->id, $marker)); }
    }

    /** Only definite non-collection permits unpaid retirement or replacement. */
    public static function unattempted(Operation $op): bool
    {
        $f = $op->funding ?? [];
        if (!array_key_exists('auto_pay', $f)) { return !array_key_exists('auto_pay_result', $f); }
        $result = $f['auto_pay_result'] ?? $f['auto_pay'];
        return is_array($result) && in_array($result['status'] ?? null, ['skipped', 'declined'], true);
    }

    private function eligibility(Operation $op, Invoice $invoice, array $l, bool $scheduled): array
    {
        $s = $l['service']; $d = $l['domain'];
        $reason = match (true) {
            !$scheduled => 'manual_renewal',
            $op->currency !== 'USD' || $invoice->currency_code !== 'USD' || $s->currency_code !== 'USD' => 'currency',
            $s->status !== 'active' => 'service_inactive',
            RenewalStop::stopped($l) => 'renewal_off',
            $op->service_id !== $d->service_id || $op->authority_generation !== $d->authority_generation
                || (int)$invoice->user_id !== (int)$d->user_id || (int)$s->user_id !== (int)$d->user_id => 'authority_changed',
            $invoice->status !== Invoice::STATUS_PENDING || PricingRule::minor($invoice->remaining) <= 0 => 'invoice_settled',
            !$s->billing_agreement_id => 'no_agreement',
            default => null,
        };
        if ($reason !== null) { return [$reason, null, null]; }
        $agreement = BillingAgreement::whereKey($s->billing_agreement_id)->lockForUpdate()->first();
        if (!$agreement) { return ['no_agreement', null, null]; }
        if ((int)$agreement->user_id !== (int)$s->user_id) { return ['foreign_agreement', $agreement, null]; }
        $gateway = Gateway::whereKey($agreement->gateway_id)->lockForUpdate()->first();
        return [!$gateway ? 'gateway_missing' : (!(bool)$gateway->enabled ? 'gateway_disabled' : null), $agreement, $gateway];
    }

    private function charge(int $id, array $marker): void
    {
        // Refresh authority after commit, without holding locks during gateway I/O.
        $op = Operation::findOrFail($id); $seed = $op->domain()->firstOrFail();
        try {
            $ready = DB::transaction(function () use ($id, $seed, $marker): ?array {
                $l = HoldingLocks::take($seed); $op = $l['operations']->firstWhere('id', $id);
                if (!$op || ($op->funding['auto_pay'] ?? null) !== $marker || array_key_exists('auto_pay_result', $op->funding ?? [])) { return null; }
                $invoice = $l['invoices']->firstWhere('id', $op->invoice_id);
                if (!$invoice) { throw new \DomainException('Renewal invoice unavailable.'); }
                [$reason, $agreement, $gateway] = $this->eligibility($op, $invoice, $l, true);
                if ($reason === null && ($agreement->id !== $marker['agreement_id'] || $gateway->id !== $marker['gateway_id'])) { $reason = 'agreement_changed'; }
                if ($reason !== null) { $this->resultLocked($op, ['status'=>'skipped', 'reason'=>$reason]); return null; }
                return [$invoice, $agreement, $gateway];
            });
        } catch (\Throwable) {
            $this->result($id, ['status'=>'skipped', 'reason'=>'authority_changed']); return;
        }
        if ($ready === null) { return; }
        [$invoice, $agreement, $gateway] = $ready;
        try {
            $submitted = ExtensionHelper::charge($gateway, $invoice, $agreement);
        } catch (\Throwable) {
            $this->result($id, ['status'=>'unknown']);
            Log::warning('Domains renewal Auto Pay outcome unknown; staff review required.');
            return;
        }
        if (!$submitted) {
            $notification = 'requested';
            try { NotificationHelper::invoicePaymentFailedNotification($invoice->user, $invoice); }
            catch (\Throwable) { $notification = 'failed'; }
            $this->result($id, ['status'=>'declined', 'notification'=>$notification]); return;
        }
        // Persistence failure here leaves the original uncertain marker. It must never repeat the charge.
        $this->result($id, ['status'=>'submitted']);
    }

    private function result(int $id, array $result): void
    {
        DB::transaction(function () use ($id, $result): void {
            $this->resultLocked(Operation::whereKey($id)->lockForUpdate()->firstOrFail(), $result);
        });
    }
    private function resultLocked(Operation $op, array $result): void
    {
        if (array_key_exists('auto_pay_result', $op->funding ?? [])) { return; }
        $op->update(['funding'=>[...($op->funding ?? []), 'auto_pay_result'=>[...$result, 'at'=>$this->clock->now()->format(DATE_ATOM)]]]);
    }
}
