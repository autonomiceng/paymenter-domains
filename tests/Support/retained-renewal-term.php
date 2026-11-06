<?php
// Historical paid debt fixture; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\PricingRule;
/** Privileged setup of pre-annual-only debt, using the real encrypted model and native invoice. */
function retainedRenewalTerm(Operation $op, int $years): Operation
{
    $invoice=App\Models\Invoice::findOrFail($op->invoice_id);
    same('awaiting_payment',$op->state,'Historical term is arranged before native payment');
    same(0,$invoice->transactions()->count(),'Historical term has no prior payment');
    $amount=PricingRule::total($op->amount,$years);$policy=$op->policy;
    $policy['renewal']['term']=$years;
    $policy['renewal']['cost_cap']['amount']=PricingRule::decimal(PricingRule::units($policy['renewal']['cost_cap']['amount'])*$years,4);
    $op->policy=$policy;
    DB::table('domains_operations')->where('id',$op->id)->update(['term'=>$years,'amount'=>$amount,
        'expected_expires_at'=>$op->base_expires_at->copy()->addYearsNoOverflow($years)->format('Y-m-d'),
        'policy'=>$op->getAttributes()['policy']]);
    DB::table('invoice_items')->where('id',$op->invoice_item_id)->update(['price'=>$amount]);
    $op=$op->fresh();same($years,(int)$op->term,'Retained debt keeps its original longer term');
    App\Helpers\ExtensionHelper::addPayment($invoice,null,$amount);
    same('paid',$invoice->fresh()->status,'Historical native invoice is fully paid');
    return $op->fresh();
}
