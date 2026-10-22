<?php
// Adapted from Enom 444f31c transfer-codes; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/transfer-input-fixture.php';
use App\Models\{Credit, InvoiceTransaction, Service};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission, CheckoutEligibility, ContactRecovery, ContactSetup, InvoiceBinding, NativeDomainGuard, HolderPricing, PricingRule, Rdap, Refunds, TransferInputs, TransferPrecheck};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
$baseline=$new(placeholderComFixtureName('transfer-paid-baseline-placeholder.com'));$inputs->paymentAccepted($baseline->id);
$paid=$baseline->fresh()->policy['paid_at'];
foreach([['attempt'=>['execution'=>null]],['attempt'=>['seal'=>'sealed']],['attempt'=>['fallback'=>null]],['cost'=>['execution'=>null]],
    ['started_at'=>$now],['state'=>'uncertain'],['provider_order_id'=>'synthetic'],['funding'=>['claim'=>null]]] as $i=>$changes){
    $candidate=$new('transfer-refuse-'.$i.'.com');$inputs->paymentAccepted($candidate->id);if(isset($changes['cost'])){$changes['cost']=[...$candidate->cost,...$changes['cost']];}$candidate->update($changes);$clock->instant=$now->modify('+30 days');
    commerceReject(fn()=>$inputs->expireMissingCode($candidate->id));commerceReject(fn()=>$inputs->submitCode($post($user,'replacement'),$candidate->id));$clock->instant=$now;
}
// Changed transaction times cannot invent paid_at; native audit can recover the original transition.
$ambiguous=$new(placeholderComFixtureName('transfer-time-unknown-placeholder.com')); DB::table('invoice_transactions')->where('invoice_id',$ambiguous->invoice_id)->update(['updated_at'=>'2026-10-18 11:59:00']);
commerceReject(fn()=>$inputs->paymentAccepted($ambiguous->id));expect(!isset($ambiguous->fresh()->policy['paid_at']),'No fresh deadline on uncertainty');
DB::table('audits')->insert(['event'=>'updated','auditable_type'=>App\Models\Invoice::class,'auditable_id'=>$ambiguous->invoice_id,
    'old_values'=>'{"status":"pending"}','new_values'=>'{"status":"paid"}','created_at'=>$now,'updated_at'=>$now]);
$inputs->paymentAccepted($ambiguous->id);same($paid,$ambiguous->fresh()->policy['paid_at'],'Native paid audit recovery');
$partial=$new(placeholderComFixtureName('transfer-partial-placeholder.com'),false); DB::table('invoices')->where('id',$partial->invoice_id)->update(['status'=>'paid']);
InvoiceTransaction::create(['invoice_id'=>$partial->invoice_id,'amount'=>'1.00','status'=>'succeeded']);commerceReject(fn()=>$inputs->paymentAccepted($partial->id));
$selfCancel=$new(placeholderComFixtureName('transfer-self-cancel-placeholder.com'));App\Models\ServiceCancellation::create(['service_id'=>$selfCancel->service_id,'type'=>'end_of_period','reason'=>'Own service']);
commerceReject(fn()=>$inputs->paymentAccepted($selfCancel->id));$clock->instant=$now->modify('+30 days');commerceReject(fn()=>$inputs->expireMissingCode($selfCancel->id));$clock->instant=$now;
// Native datetime objects retain their offsets; raw audits use the configured database timezone.
$timezone=date_default_timezone_get();
foreach(['America/Los_Angeles','Asia/Kolkata']as$i=>$zone){
    $local=$new('transfer-zone-'.$i.'.com');$audit=$new('transfer-audit-zone-'.$i.'.com');
    date_default_timezone_set($zone);$stamp=$now->setTimezone(new DateTimeZone($zone))->format('Y-m-d H:i:s');
    // Represent these native timestamps as stored by this timezone, without reinterpreting the UTC catalogue fixture.
    DB::table('domains_operations')->whereIn('id',[$local->id,$audit->id])->update(['created_at'=>$stamp]);
    DB::table('invoice_transactions')->whereIn('invoice_id',[$local->invoice_id,$audit->invoice_id])->update(['created_at'=>$stamp,'updated_at'=>$stamp]);
    $local->refresh();expect($local->created_at->getOffset()!==0,'Non-UTC native timestamp fixture');
    $inputs->paymentAccepted($local->id);same($paid,$local->fresh()->policy['paid_at'],'Transaction paid time normalized to original UTC instant');
    DB::table('invoice_transactions')->where('invoice_id',$audit->invoice_id)->update(['updated_at'=>'2026-10-17 00:00:00']);
    DB::table('audits')->insert(['event'=>'updated','auditable_type'=>App\Models\Invoice::class,'auditable_id'=>$audit->invoice_id,
        'old_values'=>'{"status":"pending"}','new_values'=>'{"status":"paid"}','created_at'=>$stamp,'updated_at'=>$stamp]);
    $inputs->paymentAccepted($audit->id);same($paid,$audit->fresh()->policy['paid_at'],'Local audit time normalized to original UTC instant');
    date_default_timezone_set($timezone);
}
echo "Transfer payment evidence, authority and timezone checks passed.\n";
$nativeSuccessful=true;
