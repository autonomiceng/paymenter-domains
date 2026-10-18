<?php
// Enom444f31c renewed-cycle review, stricter retained credit; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
/** Explicit replacement authority for an exactly refunded unsent renewal. No new invoice or send here. */
final class RenewalReplacements
{
    public static function authorize(Operation $op,array $l,StaffOperationReview $review,Clock $clock): void
    {
        if($op->action!=='renew'||$op->state!=='rejected'||$op->safe_code!=='refund_approved'
            ||$op->base_expires_at?->format('Y-m-d')!==$l['domain']->provider_expires_at?->format('Y-m-d')
            ||array_key_exists('renewal_replacement',$op->policy??[])||!self::settled($op,$l)) { throw new \DomainException('Exact unsent refund for the current cycle required.'); }
        $op->update(['policy'=>[...($op->policy??[]),'renewal_replacement'=>['actor_id'=>$review->actorId,'operation_key'=>$op->operation_key,
            'generation'=>$op->authority_generation,'expiry'=>$op->base_expires_at->format('Y-m-d'),
            'refund_hash'=>hash('sha256',json_encode($op->funding['refund'],JSON_THROW_ON_ERROR)),'at'=>$clock->now()->format(DATE_ATOM)]]]);
    }
    public static function reviewed(Operation $op,Domain $d): bool
    {
        $p=$op->policy['renewal_replacement']??null;
        try { RefundProof::requireUnsent($op,$d); }
        catch(\Throwable){return false;}
        return $op->action==='renew'&&$op->state==='rejected'&&$op->safe_code==='refund_approved'&&is_array($p)
            &&array_keys($p)===['actor_id','operation_key','generation','expiry','refund_hash','at']&&is_int($p['actor_id'])&&$p['actor_id']>0
            &&$p['operation_key']===$op->operation_key&&$p['generation']===$d->authority_generation
            &&$p['expiry']===$op->base_expires_at?->format('Y-m-d')&&($op->funding['refund']['to']??null)==='account_credit'
            &&$p['refund_hash']===hash('sha256',json_encode($op->funding['refund'],JSON_THROW_ON_ERROR));
    }
    public static function requireSettled(Operation $op,array $l): void
    {
        if(!self::reviewed($op,$l['domain'])||!self::settled($op,$l)){throw new \DomainException('Replacement refund financial history changed.');}
    }
    /** Read-only warning; changed old debt never blocks reconciliation of an admitted replacement. */
    public static function activity(Domain $d): array
    {
        $ops=$d->operations()->get()->filter(fn($op)=>array_key_exists('renewal_replacement',$op->policy??[]));
        if($ops->isEmpty()){return [];}
        $ids=$ops->pluck('invoice_id');$service=$d->service;
        $l=['domain'=>$d,'service'=>$service,'invoices'=>\App\Models\Invoice::whereIn('id',$ids)->get(),
            'items'=>\App\Models\InvoiceItem::whereIn('invoice_id',$ids)->get(),
            'transactions'=>\App\Models\InvoiceTransaction::whereIn('invoice_id',$ids)->get(),
            'credit'=>$service?->user?->credits()->where('currency_code','USD')->first()];
        return $ops->filter(fn($op)=>!self::settled($op,$l))->map(fn($op)=>'Request #'.$op->id.' · Late financial or billing activity on refunded debt; review the ledger.')->values()->all();
    }
    private static function settled(Operation $op,array $l): bool
    {
        try { RefundProof::requireUnsent($op,$l['domain']); $current=RefundPayment::requireCurrent($op,$l);$refund=$op->funding['refund']??[];
            if(($refund['to']??null)!=='account_credit'||!$l['credit']||($refund['credit_id']??null)!==(int)$l['credit']->id
                ||$current['invoice_total_minor']!==PricingRule::minor($op->amount)||$current['succeeded_net_minor']!==$current['invoice_total_minor']
                ||count($current['payments'])!==$l['transactions']->where('invoice_id',$op->invoice_id)->count()){return false;}
            foreach($current as $key=>$value){if(!array_key_exists($key,$refund)||$refund[$key]!==$value){return false;}}return true;
        }catch(\Throwable){return false;}
    }
}
