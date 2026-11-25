<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/acquisition-retirement.php';
use App\Models\{Service,ServiceCancellation};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement,DomainReservations,HoldingLocks,NativeCancellation,NativeIdentity,NativeIntentSchema,ReminderSchema,StaffHoldingReview};
use Paymenter\Extensions\Servers\Domains\Registration\Checkout\UnpaidAcquisitionProof;
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
// An unreadable retained operation must not abort expiry of independent unpaid invoices.
$corrupt=$new(placeholderComFixtureName('review-decrypt-placeholder.com'));
\Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts::record($corrupt->domain,'acquisition_retirement_review',$clock->now()->getTimestamp());
DB::table('domains_operations')->where('id',$corrupt->id)->update(['funding'=>'undecryptable synthetic ciphertext']);
$expiring=$new(placeholderComFixtureName('review-decrypt-expiring-placeholder.com'),paid:false);
DB::table('domains_operations')->where('id',$expiring->id)->update(['created_at'=>$clock->now()->modify('-2 hours')]);
$account=\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$corrupt->server_id)->sole();
$escaped=false;
try{$retirement->expireUnpaid($account);}catch(\Illuminate\Contracts\Encryption\DecryptException){$escaped=true;}
same('cancelled',App\Models\Invoice::findOrFail($expiring->invoice_id)->status,'An unreadable reviewed operation does not skip independent invoice expiry');
same(null,$expiring->domain->fresh()->reservation_name,'Independent unpaid expiry releases its reservation');
expect(!$escaped,'Review decryption failure stays inside the sweep');
expect(DB::table('domains_obligation_alerts')->where('domain_id',$corrupt->domain_id)->where('code','acquisition_retirement_review')->whereNull('resolved_at')->exists(),'Unreadable evidence retains the unresolved review');
same('undecryptable synthetic ciphertext',DB::table('domains_operations')->where('id',$corrupt->id)->value('funding'),'The sweep preserves unreadable retained evidence');
$nativeSuccessful=true;
