<?php
// Adapted from Enom 444f31c transfer-codes; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/transfer-input-evidence-ready.php';
require_once __DIR__.'/Support/fixture-cases.php';
use App\Models\InvoiceTransaction;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
// Each case starts from the one genuine paid native checkout above and restores rows, clock,
// timezone and fakes after it, so authority, payment and both timezone inputs share one fixture.
$cases['authority']=static function()use($new,$inputs,$clock,$now,$post,$user):void {
// Every authority row changes only retained proof. Prepare one genuine paid native
// checkout and restore all committed native rows, including money and cancellation,
// before each fault; each original refusal still runs outside a transaction.
$fixtureCandidate=$new(placeholderComFixtureName('transfer-refuse-placeholder.com'));
$restoreCandidate=nativeFixtureCheckpoint();
$recoverCandidate=static function()use($restoreCandidate,$fixtureCandidate,$clock,$now):Operation {
    $restoreCandidate();$clock->instant=$now;
    $candidate=$fixtureCandidate->fresh();
    same($fixtureCandidate->getRawOriginal(),$candidate->getRawOriginal(),'Each authority fault starts from the exact genuine native checkout');
    return $candidate;
};
foreach([['attempt'=>['execution'=>null]],['attempt'=>['seal'=>'sealed']],['attempt'=>['fallback'=>null]],['cost'=>['execution'=>null]],
    ['started_at'=>$now],['state'=>'uncertain'],['provider_order_id'=>'synthetic'],['funding'=>['claim'=>null]]] as $i=>$changes){
    $candidate=$recoverCandidate();$inputs->paymentAccepted($candidate->id);if(isset($changes['cost'])){$changes['cost']=[...$candidate->cost,...$changes['cost']];}$candidate->update($changes);$clock->instant=$now->modify('+30 days');
    commerceReject(fn()=>$inputs->expireMissingCode($candidate->id));commerceReject(fn()=>$inputs->submitCode($post($user,'replacement'),$candidate->id));$clock->instant=$now;
}
};
$cases['payment']=static function()use($new,$inputs,$clock,$now,$paid,$missingTransition):void {
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
};
// Epoch inputs stored by a negative and a half-hour positive offset normalize to the same paid instant.
foreach(['timezone-los-angeles'=>[0=>'America/Los_Angeles'],'offset-kolkata'=>[1=>'Asia/Kolkata']] as $case=>$transferEvidenceTimezones){
    $cases[$case]=static function()use($transferEvidenceTimezones,$new,$inputs,$now,$paid,$missingTransition):void {
        require __DIR__.'/Support/transfer-input-timezones.php';
    };
}
nativeFixtureCases($cases,compact('clock','user','other','baseline','unrelated','inputs','refunds','server'));
echo "Transfer payment evidence case passed.\n";$nativeSuccessful=true;
