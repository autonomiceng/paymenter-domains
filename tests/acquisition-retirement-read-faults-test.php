<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/acquisition-retirement.php';
use App\Models\{Service,ServiceCancellation};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement,DomainReservations,HoldingLocks,NativeCancellation,NativeIdentity,NativeIntentSchema,ReminderSchema,StaffHoldingReview,UnpaidAcquisitionProof};
use Paymenter\Extensions\Servers\Domains\Admin\Support\AcquisitionActions;
// Late-money read faults cannot suppress either unpaid closure or the review fallback.
$lateFault=false;$lateFaults=0;$lateLogs=[];
DB::connection()->beforeExecuting(static function($sql)use(&$lateFault,&$lateFaults):void {
    if($lateFault&&str_contains($sql,'from "service_cancellations"')){$lateFault=false;$lateFaults++;throw new RuntimeException('PRIVATE-late-money-detail');}
});
\Illuminate\Support\Facades\Log::listen(static function($event)use(&$lateLogs):void {
    if($event->message==='Domains acquisition late-money inspection deferred.'){$lateLogs[]=$event->context;}
});
foreach([false,true] as $paidFixture){
    $isolated=$new(placeholderComFixtureName('late-fault-'.($paidFixture?'paid':'unpaid').'-placeholder.com'),paid:$paidFixture);$cancel($isolated);
    $lateFault=true;$retirement->inspect($isolated->domain_id);expect(!$lateFault,'Late-money fault reached');
    if(!$paidFixture){same('rejected',$isolated->fresh()->state,'Late-money failure still retires unpaid acquisition');same(null,$isolated->domain->fresh()->reservation_name,'Late-money failure releases unfunded reservation');}
    else{expect(DB::table('domains_obligation_alerts')->where('domain_id',$isolated->domain_id)->where('code','acquisition_retirement_review')->whereNull('resolved_at')->exists(),'Late-money failure still records retirement proof refusal');expect($isolated->domain->fresh()->reservation_name!==null,'Paid acquisition stays reserved');}
    $retirement->inspect($isolated->domain_id);
    same(['domain_id'=>(int)$isolated->domain_id,'exception_class'=>RuntimeException::class],$lateLogs[array_key_last($lateLogs)],'Late-money diagnostic exposes class and owned ID only');
}
same(2,$lateFaults,'Both late-money paths tested');same(2,count($lateLogs),'One diagnostic per failed inspection');
$nativeSuccessful=true;
