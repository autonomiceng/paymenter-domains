<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registration\Checkout;

final class AcquisitionClosure
{
    public static function retain(\Paymenter\Extensions\Servers\Domains\Models\Operation $op,\Illuminate\Support\Collection $transactions,?string $invoice,\DateTimeInterface $at,bool $cancelled=false): void
    {
        if(!in_array($op->action,['register','transfer'],true)||$op->safe_code===UnpaidAcquisitionProof::CODE
            ||isset($op->funding['refund'])){return;}
        $policy=$op->policy??[];
        if(!isset($policy['native_acquisition_closure'])){$policy['native_acquisition_closure']=[
            'at'=>$at->format(DATE_ATOM),'payments'=>self::payments($transactions),'invoice_status'=>$invoice];}
        if($cancelled&&!isset($policy['native_acquisition_cancelled_at'])){$policy['native_acquisition_cancelled_at']=$at->format(DATE_ATOM);}
        if($policy!==($op->policy??[])){$op->update(['policy'=>$policy]);}
    }
    public static function payments(\Illuminate\Support\Collection $transactions): array
    {return $transactions->map(fn($t)=>[(int)$t->id,$t->status->value,(string)$t->amount])->values()->all();}
}
