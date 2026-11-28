<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registration\Checkout;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{AcquisitionClosureKind, AcquisitionLifecycle, AcquisitionPhase, OperationAttempts};
use Paymenter\Extensions\Servers\Domains\Services\{InvoiceBinding, InvoiceScope, RefundProof};
/** Caller owns HoldingLocks. No transaction, status or absence of provider reads proves a send failed. */
final class UnpaidAcquisitionProof
{
    public const CODE='acquisition_cancelled_unpaid';
    public static function require(array $l,bool $terminal=false): Operation
    {
        InvoiceScope::requireFull($l);
        $d=$l['domain'];$s=$l['service'];$ops=$l['operations'];$op=$ops->first();
        if($ops->count()!==1||!$op||!in_array($op->action,['register','transfer'],true)||$op->funding_basis!=='paid_invoice'
            ||$d->provider_expires_at!==null||$d->provider_domain_id!==null||$d->provider_order_id!==null
            ||!in_array($d->status,$terminal?['cancelled']:['registration_pending','transfer_pending'],true)
            ||!in_array($s->status,['pending','cancelled'],true)){
            throw new \DomainException('Only an unpaid order with no other requests or registrar evidence can be retired.');
        }
        $invoice=$l['invoices']->firstWhere('id',$op->invoice_id);$item=$l['items']->firstWhere('id',$op->invoice_item_id);
        if(!$invoice||!$item||!in_array($invoice->status,['pending','cancelled'],true)
            ||self::money($l,[$invoice->id])||($op->funding??[])!==[]){
            throw new \DomainException('This order has a payment or payment attempt, so it cannot be retired. The name stays reserved.');
        }
        // Retained billing can tie other invoices to this service, and closing it would strand their money.
        $billed=$l['items']->where('reference_type',$s->getMorphClass())->where('reference_id',$s->id)->pluck('invoice_id');
        if($l['invoices']->whereIn('id',$billed)->contains('status','paid')||self::money($l,$billed)){
            throw new \DomainException('This order has a payment or payment attempt, so it cannot be retired. The name stays reserved.');
        }
        InvoiceBinding::requireIdentity($op,$d,$s,$invoice,$item,$l['product']);
        $now = app(Clock::class)->now();
        $proof = OperationAttempts::purchaseProof($op, $now, lock: true);
        if ($terminal) {
            if (!self::terminal($op, $d, $proof)) { throw new \DomainException('Unpaid terminal proof is unavailable.'); }
        } else {
            // 'cancelled' is an old cleanup state that left the order open.
            if (!in_array($op->state, ['awaiting_payment', 'awaiting_code', 'queued', 'held', 'cancelled'], true)
                || $proof->phase !== AcquisitionPhase::Virgin) {
                throw new \DomainException('A registrar request may have started. Review its outcome; the name stays reserved.');
            }
            RefundProof::requirePurchase($op, $d, $now, proof: $proof);
        }
        return $op;
    }
    /** The payment half of require(), so a page can hide what the writer would refuse. */
    public static function paid(array $l): bool
    {
        InvoiceScope::requireFull($l);
        $invoices=$l['operations']->pluck('invoice_id')->filter();
        return $l['invoices']->whereIn('id',$invoices)->contains('status','paid')||self::money($l,$invoices);
    }
    /** A declined card is not money, so it never keeps a name. */
    private static function money(array $l,iterable $invoices): bool
    {return $l['transactions']->whereIn('invoice_id',$invoices)->contains(fn($t)=>$t->status!==\App\Enums\InvoiceTransactionStatus::Failed);}
    public static function terminal(Operation $op, Domain $d, ?AcquisitionLifecycle $proof = null): bool
    {
        return in_array($op->action, ['register', 'transfer'], true) && $op->funding_basis === 'paid_invoice'
            && !array_diff(array_keys($op->receiptFunding()), ['late_credits', 'receipt_return_results'])
            && $d->status === 'cancelled' && $d->provider_expires_at === null && $d->provider_domain_id === null && $d->provider_order_id === null
            && $op->safe_code === self::CODE && $op->state === 'rejected' && $op->completed_at !== null
            && $op->domain_id === $d->id && $op->service_id === $d->service_id && $op->server_id === $d->server_id
            && $op->registrar_key === $d->registrar_key && $op->account_fingerprint === $d->account_fingerprint
            && $op->authority_generation === $d->authority_generation
            && ($proof ?? OperationAttempts::purchaseProof($op, app(Clock::class)->now(), DB::transactionLevel() > 0))->closure === AcquisitionClosureKind::Unpaid;
    }
    public static function attempt(Operation $op): array
    {
        return ['seal'=>'absent','send_state'=>'definitely_unsent','disposition'=>'rejected','rejection_basis'=>'definitely_unsent',
            'terminal'=>['operation_key'=>$op->operation_key,'generation'=>$op->authority_generation,'invoice_id'=>$op->invoice_id,'transactions'=>[],'unfunded'=>true]];
    }
}
