<?php
// Native renewal refunds; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$renewalFixtureNames=['renew-unsent-retry','renew-credit-replace'];
require __DIR__.'/Support/renewal-refund.php';
use App\Models\{Credit, InvoiceTransaction, Role};
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{PricingRule, Refunds, RefundPayment, RenewalCycles, RenewalFunding, HoldingLocks, FinancialAdjustments};
// Three delayed retries share the same paid invoice; each consumes only exact prewire proof.
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::class,$bindings);
[$retry,$retryDomain,$retryBinding]=$makeRenewal(placeholderComFixtureName('renew-unsent-retry-placeholder.com'));$clock->instant=new DateTimeImmutable('2027-10-17T12:00:00Z');
$corrections->append($staff,$retry->id,'retry_reverse','-1.00','payment_correction');$corrections->append($staff,$retry->id,'retry_restore','1.00','payment_correction');
[$engine,$writer]=$compose($retryBinding);$retryTarget=$retry->id;
Operation::updated(static function($row)use(&$retryTarget,$retryDomain){if($row->id===$retryTarget&&$row->state==='running'){DB::table('services')->where('id',$retryDomain->service_id)->update(['status'=>'cancelled']);}});
$writes=$state->writes;$root=$retry;
for($number=0;$number<=3;$number++){
    $retryTarget=$retry->id;same('held',$engine->run($retry->id,$writer)->state,'Typed refusal held before send');$sealed=$retry->fresh()->attempt;
    DB::table('services')->where('id',$retryDomain->service_id)->update(['status'=>'active']);
    same($retry->id,\Paymenter\Extensions\Servers\Domains\Services\RenewalRetries::recover($retry->id,$clock)->id,'Retry delay is enforced');
    $clock->instant=$clock->now()->modify('+16 minutes');$next=\Paymenter\Extensions\Servers\Domains\Services\RenewalRetries::recover($retry->id,$clock);
    same($sealed,$retry->fresh()->attempt,'Retry never resets its sealed predecessor');
    if($number===3){same($retry->id,$next->id,'Our three-retry bound ends unsent automation');break;}
    expect($next->id!==$retry->id,'Fresh attempt created only from proved no send');same($root->invoice_id,$next->invoice_id,'Retry retains original paid debt');
    same($next->id,\Paymenter\Extensions\Servers\Domains\Services\RenewalRetries::recover($retry->id,$clock)->id,'Duplicate recovery converges on same attempt');
    $beforeRefundSweep=nativeFinancialSnapshot($retry);
    \Paymenter\Extensions\Servers\Domains\Services\RefundRepairs::failed($retry->id,$clock->now()->getTimestamp());
    same(null,$refunds->creditRejected($retry->id),'Proved retry predecessor has no refund obligation');
    same($beforeRefundSweep,nativeFinancialSnapshot($retry->fresh()),'Refund sweep leaves the retry payment untouched');
    same(0,DB::table('domains_obligation_alerts')->where('operation_id',$retry->id)->where('code','refund_repair')->whereNull('resolved_at')->count(),'Retry predecessor creates no false refund repair alert');
    commerceReject(fn()=>$next->update(['retry_number'=>0]));
    $duplicate=$next->getRawOriginal();unset($duplicate['id']);$duplicate['operation_key'].=':duplicate';
    try{DB::table('domains_operations')->insert($duplicate);expect(false,'Duplicate debt attempt must remain unique');}catch(\Illuminate\Database\QueryException){}
    $retry=$next;Cache::flush();
}
same($writes,$state->writes,'All local refusals leave provider writes unchanged');
// Exact old credit settlement automatically authorizes an ordinary replacement invoice, with no staff step.
[$returned,$returnedDomain]=$makeRenewal(placeholderComFixtureName('renew-credit-replace-placeholder.com'));$clock->instant=new DateTimeImmutable('2027-10-17T12:00:00Z');$returned->update(['state'=>'held','safe_code'=>'renewal_funding_or_authority']);
$refunds->refundHeld($staff,$returned->id,'service_cancelled');
\Paymenter\Extensions\Servers\Domains\Services\RenewalReplacements::recoverDomain($returnedDomain,$clock);
expect($billing->requestOptions($returnedDomain)['terms']!==[],'Settlement recovery unblocks the owner renewal preview without issuing debt');
$replacement=$billing->invoice($returnedDomain->id,$user);
expect($replacement->id!==$returned->invoice_id,'Exact full returned credit releases ordinary replacement');
same(null,$returned->fresh()->policy['renewal_replacement']['actor_id'],'System replacement audit has no invented staff actor');
same($replacement->id,$billing->invoice($returnedDomain->id,$user)->id,'Repeated invoice recovery cannot duplicate debt');
same([],array_keys($registrations),'Every independent refund scenario ran');
echo "Renewal delayed retries and paid replacement passed\n"; $nativeSuccessful=true;
