<?php
// Retained pre-NS17 debt for notification compatibility only; no send authority or new longer-term admission.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
/** Keep genuine native invoice/item rows and the completed holding anchor. Current billing stays annual-only. */
function retainedReminderInvoice($domain,$invoice,$operation,int $years): array
{
    $policy=$operation->policy;
    $amount=\Paymenter\Extensions\Servers\Domains\Services\PricingRule::total($policy['price']['annual_gross'],$years);
    $item=$invoice->items()->whereKey($operation->invoice_item_id)->sole();
    $item->forceFill(['price'=>$amount,'description'=>\Paymenter\Extensions\Servers\Domains\Services\InvoiceDescription::renewal($domain->domain,$domain->provider_expires_at,$years)]);
    // Native guards correctly freeze issued items too; this fixture replays retained native bytes.
    \Illuminate\Support\Facades\DB::table('invoice_items')->where('id',$item->id)->update($item->getAttributes());
    $policy['renewal']['term']=$years;
    // No current exact-term proof is invented for this retained, unpaid reminder obligation.
    $policy['renewal']['cost_cap']=null;unset($policy['renewal']['term_rule']);
    $operation->forceFill(['term'=>$years,'amount'=>$amount,'expected_expires_at'=>$domain->provider_expires_at->addYearsNoOverflow($years)->format('Y-m-d'),'policy'=>$policy]);
    // A historical fixture bypasses immutable-operation edit guards, but uses the actual encrypted model casts.
    \Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$operation->id)->update($operation->getAttributes());
    $operation=$operation->fresh();$invoice=$invoice->fresh();
    same([$years,$amount,$amount],[(int)$operation->term,$operation->amount,$item->fresh()->price],'Retained multi-year debt keeps matching native item and operation amounts');
    return [$invoice,$operation];
}
