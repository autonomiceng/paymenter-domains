<?php
// Adapted from Enom 444f31c transfer-codes; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/transfer-input-evidence-ready.php';
use App\Models\{Credit, InvoiceTransaction, Service};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission, CheckoutEligibility, ContactRecovery, ContactSetup, InvoiceBinding, NativeDomainGuard, HolderPricing, PricingRule, Rdap, Refunds, TransferInputs, TransferPrecheck};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
// Changed transaction times cannot invent paid_at; native audit can recover the original transition.
$ambiguous=$new(placeholderComFixtureName('transfer-time-unknown-placeholder.com')); $missingTransition($ambiguous); DB::table('invoice_transactions')->where('invoice_id',$ambiguous->invoice_id)->update(['updated_at'=>'2026-10-18 11:59:00']);
commerceReject(fn()=>$inputs->paymentAccepted($ambiguous->id));expect(!isset($ambiguous->fresh()->policy['paid_at']),'No fresh deadline on uncertainty');
DB::table('audits')->insert(['event'=>'updated','auditable_type'=>App\Models\Invoice::class,'auditable_id'=>$ambiguous->invoice_id,
    'old_values'=>'{"status":"pending"}','new_values'=>'{"status":"paid"}','created_at'=>$now,'updated_at'=>$now]);
$inputs->paymentAccepted($ambiguous->id);same($paid,$ambiguous->fresh()->policy['paid_at'],'Native paid audit recovery');
$partial=$new(placeholderComFixtureName('transfer-partial-placeholder.com'),false); DB::table('invoices')->where('id',$partial->invoice_id)->update(['status'=>'paid']);
InvoiceTransaction::create(['invoice_id'=>$partial->invoice_id,'amount'=>'1.00','status'=>'succeeded']);commerceReject(fn()=>$inputs->paymentAccepted($partial->id));
$selfCancel=$new(placeholderComFixtureName('transfer-self-cancel-placeholder.com'));App\Models\ServiceCancellation::create(['service_id'=>$selfCancel->service_id,'type'=>'end_of_period','reason'=>'Own service']);
commerceReject(fn()=>$inputs->paymentAccepted($selfCancel->id));$clock->instant=$now->modify('+30 days');commerceReject(fn()=>$inputs->expireMissingCode($selfCancel->id));$clock->instant=$now;
echo "Transfer payment evidence case passed.\n";$nativeSuccessful=true;
