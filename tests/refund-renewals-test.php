<?php
// Native renewal refunds; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$renewalFixtureNames=['renew-corrected-funding','refund-grace-term','refund-typed-prewire'];
require __DIR__.'/Support/renewal-refund.php';
use App\Models\{Credit, InvoiceTransaction, Role};
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{PricingRule, Refunds, RefundPayment, RenewalCycles, RenewalFunding, HoldingLocks, FinancialAdjustments};
// Corrections do not constitute an attempt: restored signed funding may execute once with its audit retained.
[$corrected,$d,$b]=$makeRenewal(placeholderComFixtureName('renew-corrected-funding-placeholder.com'));
$corrections->append($staff,$corrected->id,'renew_reverse','-1.00','payment_correction');
commerceReject(fn()=>DB::transaction(fn()=>RenewalFunding::requireCurrent($corrected->fresh(),HoldingLocks::take($d))));
$corrections->append($staff,$corrected->id,'renew_restore','1.00','payment_correction');
$history=$corrected->fresh()->funding['financial_adjustments']; $raw=$corrected->fresh()->getRawOriginal();
foreach(['null_history','unknown_fact','foreign_transaction'] as $case){
    $bad=['financial_adjustments'=>$history];
    if($case==='null_history'){$bad['financial_adjustments']=null;}
    elseif($case==='unknown_fact'){$bad['claim']=null;}
    else{$bad['financial_adjustments'][0]['transaction_id']=999999;}
    Operation::withoutEvents(fn()=>$corrected->fresh()->update(['funding'=>$bad]));
    if($case==='foreign_transaction'){commerceReject(fn()=>DB::transaction(fn()=>RenewalFunding::requireCurrent($corrected->fresh(),HoldingLocks::take($d))));}
    else{same(false,RenewalCycles::virgin($corrected->fresh()),'Unknown funding history cannot admit');}
    DB::table('domains_operations')->where('id',$corrected->id)->update($raw);
}
[$engine,$writer,$completion]=$compose($b); $writes=$state->writes;
same('pending',$engine->run($corrected->id,$writer)->state,'Restored renewal executes');
same($writes+1,$state->writes,'Corrected funding permits exactly one send'); same($history,$corrected->fresh()->funding['financial_adjustments'],'Seal preserves correction audit');
$nativeBeforeReplay = nativeFinancialSnapshot($corrected);
$engine->run($corrected->id,$writer); same($writes+1,$state->writes,'Corrected renewal never replays');
same($nativeBeforeReplay, nativeFinancialSnapshot($corrected), 'Replay preserves native/financial state');
$state->expiry='2028-10-18'; $state->charge='8.00'; same('succeeded',$completion->run($corrected->id)->state,'Corrected renewal completes');
same($history,$corrected->fresh()->funding['financial_adjustments'],'Completion retains audit');
// Unsuitable fixed multiyear grace, with full native payment: refund retains holding and expiry.
[$op,$d,$b]=$makeRenewal(placeholderComFixtureName('refund-grace-term-placeholder.com'),false);
$op=retainedRenewalTerm($op,3); [$engine,$writer]=$compose($b);
$clock->instant=new DateTimeImmutable('2027-10-18T12:00:01Z'); $beforeWrites=$state->writes;
same('held',$engine->run($op->id,$writer)->state,'Unsuitable paid term held'); same($beforeWrites,$state->writes,'No provider write');
$expiry=$d->provider_expires_at->format('Y-m-d');$before=$credit();
$refunds->refundHeld($staff,$op->id,'unsuitable_term');
same($before+PricingRule::minor($op->amount),$credit(),'Unsuitable fixed obligation full credit');
same('active',$d->service->fresh()->status,'Renewal refund retains active holding'); same($expiry,$d->fresh()->provider_expires_at->format('Y-m-d'),'Refund never changes expiry');
commerceReject(fn()=>DB::transaction(fn()=>RenewalCycles::next(HoldingLocks::take($d)))); // Safe replacement of a refunded unsuitable cycle remains35/36.
// Slice25 sealed typed definitely-unsent prewire evidence is independently interpreted.
[$op,$d,$b,$bill]=$makeRenewal(placeholderComFixtureName('refund-typed-prewire-placeholder.com')); [$engine,$writer]=$compose($b); $beforeWrites=$state->writes;
Operation::updated(static function($row)use($op,$d){
    if($row->id===$op->id && $row->state==='running'){ DB::table('services')->where('id',$d->service_id)->update(['status'=>'cancelled']); }
});
same('held',$engine->run($op->id,$writer)->state,'Real local prewire refusal'); same($beforeWrites,$state->writes,'Typed refusal before exchange');
same('definitely_unsent',$op->fresh()->attempt['execution']['send_state'],'Retained typed summary');
$attempt=$op->fresh()->attempt; $original=$op->fresh()->getRawOriginal();
foreach(['execution_null','fallback','possible_send','extra_null_fact'] as $case){
    $bad=$attempt;
    if($case==='execution_null'){$bad['execution']=null;}
    elseif($case==='fallback'){$bad['execution']['reason']='fallback';}
    elseif($case==='possible_send'){$bad['execution']['send_state']='possibly_sent';}
    else{$bad['provider_reference']=null;}
    // Privileged fixture corruption probes independent interpretation of retained typed evidence.
    Operation::withoutEvents(fn()=>$op->fresh()->update(['attempt'=>$bad]));
    commerceReject(fn()=>$refunds->refundHeld($staff,$op->id,'service_cancelled'));
    DB::table('domains_operations')->where('id',$op->id)->update($original);
}
$before=$credit(); $refunds->refundHeld($staff,$op->id,'service_cancelled');
same($before+PricingRule::minor($op->amount),$credit(),'Proven prewire refusal credited'); same($attempt,$op->fresh()->attempt,'Sealed audit untouched');
same([],array_keys($registrations),'Every independent refund scenario ran');
echo "Held renewal refund and signed funding checks passed\n"; $nativeSuccessful=true;
