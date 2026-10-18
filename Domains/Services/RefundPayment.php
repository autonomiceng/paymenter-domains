<?php
// Enom 444f31c Refunds::refundablePayment; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Enums\InvoiceTransactionStatus;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** Exact issued line and complete signed native funding, under HoldingLocks. No lifecycle/price inference. */
final class RefundPayment
{
    public static function requireLine(Operation $op, array $l): array
    {
        $d = $l['domain']; $s = $l['service'];
        $invoice = $l['invoices']->firstWhere('id', $op->invoice_id); $item = $l['items']->firstWhere('id', $op->invoice_item_id);
        if (!in_array($op->action, ['register', 'renew', 'transfer'], true) || $op->domain_id !== $d->id || $op->service_id !== $d->service_id
            || $op->server_id !== $d->server_id || $op->registrar_key !== $d->registrar_key || $op->account_fingerprint !== $d->account_fingerprint
            || $op->authority_generation < 1 || $op->authority_generation !== $d->authority_generation || $op->term < 1 || $op->term > 10
            || $op->funding_basis !== 'paid_invoice' || $op->currency !== 'USD' || !$invoice || !$item
            || (int) $invoice->user_id !== (int) $d->user_id || $invoice->currency_code !== 'USD'
            || (int) $item->invoice_id !== (int) $invoice->id || (string) $item->quantity !== '1'
            || PricingRule::minor($op->amount) <= 0 || PricingRule::minor($item->price) !== PricingRule::minor($op->amount)) {
            throw new \DomainException('No exact eligible paid native line.');
        }
        if (in_array($op->action, ['register', 'transfer'], true)) { InvoiceBinding::requireIdentity($op, $d, $s, $invoice, $item, $l['product']); }
        elseif ($op->action === 'renew') {
            $r = $op->policy['renewal'] ?? [];
            foreach (['user_id', 'order_id', 'product_id'] as $key) {
                if (($r[$key] ?? null) !== (int) $s->$key) { throw new \DomainException('Renewal route changed.'); }
            }
            if (($r['annual_plan_id'] ?? null) !== (int) $s->plan_id || ($r['term'] ?? null) !== (int) $op->term
                || !$op->base_expires_at || !$op->expected_expires_at
                || $op->expected_expires_at->format('Y-m-d') !== $op->base_expires_at->addYearsNoOverflow((int) $op->term)->format('Y-m-d')
                || $invoice->due_at?->format('Y-m-d') !== ($op->policy['calendar']['due'] ?? null)
                || $item->reference_type !== null || $item->reference_id !== null || $l['items']->where('invoice_id', $invoice->id)->count() !== 1) {
                throw new \DomainException('Renewal line changed.');
            }
        } else { throw new \DomainException('This action has no refund authority.'); }
        return [$invoice, $item];
    }
    /** Retired debt can stop blocking a replacement only while its exact full credit still covers all activity. */
    public static function retiredSettled(Operation $op, array $l): bool
    {
        try {
            if (!RefundProof::retired($op, $l['domain'])) { return false; }
            RefundProof::requireUnsent($op, $l['domain']); $current = self::requireCurrent($op, $l);
            $refund = $op->funding['refund'] ?? [];
            if (($refund['to'] ?? null) !== 'account_credit' || ($refund['credit_id'] ?? null) !== $l['credit']?->id
                || !$l['credit'] || $current['invoice_total_minor'] !== PricingRule::minor($op->amount)
                || $current['succeeded_net_minor'] !== $current['invoice_total_minor']
                || count($current['payments']) !== $l['transactions']->where('invoice_id', $op->invoice_id)->count()) { return false; }
            foreach ($current as $key => $value) { if (!array_key_exists($key, $refund) || $refund[$key] !== $value) { return false; } }
            return true;
        } catch (\Throwable) { return false; }
    }
    public static function requireCurrent(Operation $op, array $l): array
    {
        [$invoice, $item] = self::requireLine($op, $l);
        if ($invoice->status !== 'paid' && !($invoice->status === 'cancelled' && RefundProof::retired($op, $l['domain']))) {
            throw new \DomainException('Native invoice must be paid or proven retired.');
        }
        $total = $net = 0; $payments = [];
        foreach ($l['items']->where('invoice_id', $invoice->id) as $row) {
            if (!ctype_digit((string) $row->quantity) || (int) $row->quantity < 1) { throw new \DomainException('Invalid native line.'); }
            $total += PricingRule::minor($row->price) * (int) $row->quantity;
        }
        foreach ($l['transactions']->where('invoice_id', $invoice->id) as $row) {
            if ($row->status === InvoiceTransactionStatus::Succeeded) {
                $minor = PricingRule::minor($row->amount); $net += $minor;
                $payments[] = ['id' => (int) $row->id, 'amount_minor' => $minor];
            }
        }
        if ($total <= 0 || $net <= 0 || $net < $total) { throw new \DomainException('Full net succeeded native funding required.'); }
        return ['invoice_id' => (int) $invoice->id, 'invoice_item_id' => (int) $item->id, 'amount' => $op->amount, 'currency' => 'USD',
            'invoice_total_minor' => $total, 'succeeded_net_minor' => $net, 'payments' => $payments];
    }
}
