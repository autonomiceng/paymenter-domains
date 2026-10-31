<?php
// Enom 444f31c/d1392a8 renewal funding; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Enums\InvoiceTransactionStatus;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** Caller owns HoldingLocks. No snapshot or status label grants spending authority. */
final class RenewalFunding
{
    /** Read-only obligation eligibility; does not require payment or grant execution. */
    public static function requireAuthority(Operation $op,array $l,bool $sent=false):void
    {
        FinancialAdjustments::requireHistory($op, $l);
        $d = $l['domain']; $s = $l['service']; $r = $op->policy['renewal'] ?? [];
        $binding = CatalogueAcceptances::binding($l['account']);
        $anchor = HoldingAnchor::resolve($l);
        if ($op->action !== 'renew' || $op->domain_id !== $d->id || $op->service_id !== $d->service_id
            || $op->server_id !== $d->server_id || $op->registrar_key !== $d->registrar_key
            || $op->account_fingerprint !== $d->account_fingerprint || $op->authority_generation !== $d->authority_generation
            || $op->currency !== 'USD' || (int) $op->term < 1 || (int) $op->term > 10
            || $op->base_expires_at?->format('Y-m-d') !== $d->provider_expires_at?->format('Y-m-d')
            || $op->expected_expires_at?->format('Y-m-d') !== $op->base_expires_at?->addYearsNoOverflow((int) $op->term)->format('Y-m-d')
            || ($r['confirmed_revision'] ?? null) !== $d->confirmed_revision || ($r['provider_domain_id'] ?? null) !== $d->provider_domain_id
            || ($r['term'] ?? null) !== (int) $op->term || ($r['product_id'] ?? null) !== (int) $s->product_id
            || ($r['annual_plan_id'] ?? null) !== (int) $s->plan_id || ($r['user_id'] ?? null) !== (int) $s->user_id
            || ($r['order_id'] ?? null) !== (int) $s->order_id
            // Original sent-attempt reconciliation retains identity/funding checks, not send eligibility.
            || !in_array($d->status, ['active', 'expired', 'grace'], true)
            || (!$sent && ($s->status !== 'active' || RenewalStop::blocksRenewal($l)))
            || ($d->renewal_policy['billing_anchor']['expiry'] ?? null) !== $d->provider_expires_at?->format('Y-m-d')
            || $l['operations']->contains(fn ($other) => $other->id !== $op->id && !HoldingAnchor::resolved($other, $l))
            || array_key_exists('refund', $op->funding ?? [])) { throw new \DomainException('Renewal obligation authority changed.'); }
        foreach ($l['operations'] as $other) {
            if ($other->safe_code === 'renewal_cancelled_unpaid' && ($l['invoices']->firstWhere('id', $other->invoice_id)?->status !== 'cancelled'
                || RenewalCycles::moneyMoved($l, $other->invoice_id)) && !RefundPayment::retiredSettled($other, $l)) { throw new \DomainException('Retired renewal received financial activity.'); }
        }
    }
    public static function requireCurrent(Operation $op, array $l, bool $sent = false): array
    {
        self::requireAuthority($op,$l,$sent);$d=$l['domain'];$r=$op->policy['renewal']??[];
        if ($op->funding_basis === 'complimentary') {
            $a = $op->policy['complimentary'] ?? []; $current = $d->renewal_policy['complimentary'] ?? [];
            if ($op->invoice_id !== null || $op->invoice_item_id !== null || PricingRule::minor($op->amount) !== 0
                || (!$sent && $current !== $a)
                || ($a['generation'] ?? null) !== $d->authority_generation || ($a['expiry'] ?? null) !== $op->base_expires_at->format('Y-m-d')
                || ($a['years'] ?? null) !== (int) $op->term || ($a['actor'] ?? null) !== $op->authorized_by
                || ($r['cost_cap'] ?? null) !== ($a['cap'] ?? null) || !isset($a['cap'], $a['at'])
                || !is_int($a['actor'] ?? null) || ($a['reason'] ?? null) !== $op->funding_reason) { throw new \DomainException('Complimentary authority unavailable.'); }
            return ['basis' => 'complimentary', 'authorization' => $a];
        }
        $invoice = $l['invoices']->firstWhere('id', $op->invoice_id); $item = $l['items']->firstWhere('id', $op->invoice_item_id);
        if ($op->funding_basis !== 'paid_invoice' || !$invoice || !$item
            || (int) $invoice->user_id !== (int) $d->user_id || $invoice->currency_code !== 'USD'
            || (int) $item->invoice_id !== (int) $invoice->id || $item->reference_type !== null || $item->reference_id !== null
            || (string) $item->quantity !== '1' || PricingRule::minor($op->amount) <= 0
            || PricingRule::minor($item->price) !== PricingRule::minor($op->amount)
            || $invoice->due_at === null || $invoice->due_at->format('Y-m-d') !== ($op->policy['calendar']['due'] ?? null)) { throw new \DomainException('Renewal invoice authority changed.'); }
        if ($invoice->status !== 'paid') { throw new RenewalHold('renewal_invoice_unpaid', 'Renewal invoice is not funded.'); }
        $total = $paid = 0; $ids = [];
        foreach ($l['items']->where('invoice_id', $invoice->id) as $row) {
            if (!ctype_digit((string) $row->quantity) || (int) $row->quantity < 1) { throw new \DomainException('Invalid renewal invoice.'); }
            $total += PricingRule::minor($row->price) * (int) $row->quantity;
        }
        foreach ($l['transactions']->where('invoice_id', $invoice->id) as $row) {
            if ($row->status === InvoiceTransactionStatus::Succeeded) { $paid += PricingRule::minor($row->amount); $ids[] = $row->id; }
        }
        if ($total <= 0) { throw new \DomainException('Invalid renewal invoice total.'); }
        if ($paid < $total) { throw new RenewalHold('renewal_invoice_unpaid', 'The full invoice must remain paid.'); }
        return ['basis' => 'paid_invoice', 'invoice_id' => $invoice->id, 'invoice_item_id' => $item->id,
            'invoice_total_minor' => $total, 'succeeded_net_minor' => $paid, 'transaction_ids' => $ids, 'currency' => 'USD'];
    }
}
