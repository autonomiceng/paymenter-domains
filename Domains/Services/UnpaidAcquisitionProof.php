<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** Caller owns HoldingLocks. No transaction, status or absence of provider reads proves a send failed. */
final class UnpaidAcquisitionProof
{
    public const CODE='acquisition_cancelled_unpaid';
    public static function require(array $l,bool $terminal=false): Operation
    {
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
        if($op->started_at!==null||$op->request_fingerprint!==null||$op->lease_expires_at!==null
            ||$op->provider_order_id!==null||$op->confirmed_expires_at!==null
            ||array_diff(array_keys($op->cost??[]),['estimated','currency','unit'])
            ||array_key_exists('dispatch_attempted_at',$op->policy??[])){
            throw new \DomainException('A registrar request may have started. Review its outcome; the name stays reserved.');
        }
        if($terminal){
            if(!self::terminal($op,$d)){throw new \DomainException('Unpaid terminal proof is unavailable.');}
        }else{
            // 'cancelled' is an old cleanup state that left the order open; nothing was sent, which the rest of this proof checks.
            if(!in_array($op->state,['awaiting_payment','awaiting_code','queued','held','cancelled'],true)||($op->attempt??[])!==[]){
                throw new \DomainException('A registrar request may have started. Review its outcome; the name stays reserved.');
            }
            RefundProof::requireUnsent($op,$d);
        }
        return $op;
    }
    /** A declined card is not money, so it never keeps a name. */
    private static function money(array $l,iterable $invoices): bool
    {return $l['transactions']->whereIn('invoice_id',$invoices)->contains(fn($t)=>$t->status!==\App\Enums\InvoiceTransactionStatus::Failed);}
    public static function terminal(Operation $op,\Paymenter\Extensions\Servers\Domains\Models\Domain $d): bool
    {
        return in_array($op->action,['register','transfer'],true)&&$op->funding_basis==='paid_invoice'
            &&$op->started_at===null&&$op->request_fingerprint===null&&$op->lease_expires_at===null
            &&$op->provider_order_id===null&&$op->confirmed_expires_at===null&&!array_diff(array_keys($op->funding??[]),['late_credits'])
            &&!array_diff(array_keys($op->cost??[]),['estimated','currency','unit'])&&!array_key_exists('dispatch_attempted_at',$op->policy??[])
            &&$d->status==='cancelled'&&$d->provider_expires_at===null&&$d->provider_domain_id===null&&$d->provider_order_id===null
            &&$op->safe_code===self::CODE&&$op->state==='rejected'&&$op->completed_at!==null
            &&$op->attempt===self::attempt($op)&&$op->domain_id===$d->id&&$op->service_id===$d->service_id
            &&$op->server_id===$d->server_id&&$op->registrar_key===$d->registrar_key
            &&$op->account_fingerprint===$d->account_fingerprint&&$op->authority_generation>0
            &&$op->authority_generation===$d->authority_generation;
    }
    public static function attempt(Operation $op): array
    {
        return ['seal'=>'absent','send_state'=>'definitely_unsent','disposition'=>'rejected','rejection_basis'=>'definitely_unsent',
            'terminal'=>['operation_key'=>$op->operation_key,'generation'=>$op->authority_generation,'invoice_id'=>$op->invoice_id,'transactions'=>[],'unfunded'=>true]];
    }
}
