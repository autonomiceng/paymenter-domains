<?php
// Enom 444f31c Refunds::refundablePayment; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Enums\InvoiceTransactionStatus;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** Exact issued line and complete signed native funding, under HoldingLocks. No lifecycle/price inference. */
final class RefundPayment
{
    public static function requireLine(Operation $op, array $l): array
    {
        $d = $l['domain']; $s = $l['service'];
        $invoice = $l['invoices']->firstWhere('id', $op->invoice_id); $item = $l['items']->firstWhere('id', $op->invoice_item_id);
        if (!in_array($op->action, ['register', 'renew', 'transfer', 'privacy_purchase', 'privacy_renew'], true) || $op->domain_id !== $d->id || $op->service_id !== $d->service_id
            || $op->server_id !== $d->server_id || $op->registrar_key !== $d->registrar_key || $op->account_fingerprint !== $d->account_fingerprint
            || $op->authority_generation < 1 || $op->authority_generation !== $d->authority_generation || $op->term < 1 || $op->term > 10
            || $op->funding_basis !== 'paid_invoice' || $op->currency !== 'USD' || !$invoice || !$item
            || (int) $invoice->user_id !== (int) $d->user_id || $invoice->currency_code !== 'USD'
            || (int) $item->invoice_id !== (int) $invoice->id || (string) $item->quantity !== '1'
            || PricingRule::minor($op->amount) <= 0 || PricingRule::minor($item->price) !== PricingRule::minor($op->amount)) {
            throw new OperationRefused(OperationFailure::NoPaidLine);
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
        } elseif (in_array($op->action, ['privacy_purchase','privacy_renew'],true)) { ManagementPrivacy::requireLine($op,$l,$invoice,$item); } else { throw new \DomainException('This action has no refund authority.'); }
        return [$invoice, $item];
    }
    /** Retired debt can stop blocking a replacement only while its exact full credit still covers all activity. */
    public static function retiredSettled(Operation $op, array $l): bool
    {
        try {
            if (!RefundProof::retired($op, $l['domain'])) { return false; }
            RefundProof::requireUnsent($op, $l['domain']);
            self::requireLine($op, $l);
            // Incremental returns do not grant replacement authority or resolve an unseen Auto Pay attempt.
            $net=$l['transactions']->where('invoice_id',$op->invoice_id)->where('status',InvoiceTransactionStatus::Succeeded)->sum(fn($row)=>PricingRule::minor($row->amount));
            if(!RenewalCycles::closedEarly($op)&&$net+ReceiptReturns::completedMinor($op,$l)!==PricingRule::minor($op->amount)){return false;}
            return ReceiptReturns::outstanding($op) === 0 && self::lateUncredited($op, $l) === []
                && !$l['transactions']->where('invoice_id', $op->invoice_id)->contains('status', InvoiceTransactionStatus::Processing);
        } catch (\Throwable) { return false; }
    }
    /** A closed new order shares its invoice's payments with other orders, maybe on another account, so it reads what any of them credited.
     * @return array<int,int> Payment id => minor amount already credited from a closed order. */
    public static function lateCredited(Operation $op): array
    {
        $ops = $op->safe_code === UnpaidAcquisitionProof::CODE
            ? Operation::where('invoice_id', $op->invoice_id)->orderBy('id')->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->get() : [$op];
        $done = [];
        foreach ($ops as $row) {
            $f = $row->funding ?? [];
            foreach ([...(isset($f['refund']) ? [$f['refund']] : []), ...($f['late_credits'] ?? [])] as $credit) {
                foreach ($credit['payments'] as $payment) { $done[$payment['id']] = ($done[$payment['id']] ?? 0) + $payment['amount_minor']; }
            }
        }
        return $done;
    }
    /** Succeeded payments not credited yet. A failed row is not money; a processing row counts once it succeeds. */
    public static function lateUncredited(Operation $op, array $l): array
    {
        $done = self::lateCredited($op); $new = [];
        $rows = $l['transactions']->where('invoice_id', $op->invoice_id)->where('status', InvoiceTransactionStatus::Succeeded);
        $net = $rows->sum(fn ($row) => PricingRule::minor($row->amount));
        $local=0;
        $peers=$op->safe_code===UnpaidAcquisitionProof::CODE?Operation::where('invoice_id',$op->invoice_id)->orderBy('id')->lockForUpdate()->get():[$op];
        $outstanding=0;
        foreach($peers as $peer){
            if(ReceiptReturns::malformed($peer)){throw new \DomainException('Retained return plan needs review.');}
            $outstanding+=ReceiptReturns::outstanding($peer);
            foreach([...(isset($peer->funding['refund'])?[$peer->funding['refund']]:[]),...($peer->funding['late_credits']??[])] as $p){
                $local+=($p['to']??null)==='original_payment'?ReceiptReturns::bridge($p):PricingRule::minor($p['amount']);
            }
        }
        $left = $net - $local - $outstanding;
        // A reversal after credit needs finance repair; never guess a debit, especially if credit was spent.
        if ($left < 0) { throw new \DomainException('Returned credit exceeds current settled funds. Review the reversal.'); }
        foreach ($rows as $row) {
            $minor = min($left, max(0, PricingRule::minor($row->amount) - ($done[$row->id] ?? 0)));
            if ($minor > 0) { $new[] = ['id' => (int) $row->id, 'amount_minor' => $minor]; $left -= $minor; }
        }
        if($left!==0){throw new \DomainException('Settled money lacks an unreturned receipt. Review the native return rows.');}
        return $new;
    }
    public static function requireCurrent(Operation $op, array $l): array
    {
        [$invoice, $item] = self::requireLine($op, $l);
        if ($invoice->status !== 'paid' && !($invoice->status === 'cancelled' && (RefundProof::retired($op, $l['domain']) || FundsWaits::closedRenewal($op,$l['domain']) || PrivacyRetirement::safe($op, $l['domain'])))) {
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
        $returned=0;
        foreach(Operation::where('invoice_id',$op->invoice_id)->orderBy('id')->lockForUpdate()->get() as $peer){$returned+=ReceiptReturns::completedMinor($peer,$l);}
        if ($total <= 0 || $net <= 0 || $net+$returned < $total) { throw new \DomainException('Full net succeeded native funding required.'); }
        return ['invoice_id' => (int) $invoice->id, 'invoice_item_id' => (int) $item->id, 'amount' => $op->amount, 'currency' => 'USD',
            'invoice_total_minor' => $total, 'succeeded_net_minor' => $net, 'payments' => $payments];
    }
}
