<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-paid-cases.php';
use App\Models\{Credit,Invoice,InvoiceTransaction,Service};
use Illuminate\Support\Facades\{DB,Event,Queue};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement,ContactRecovery,ContactSetup,NativeActivationInformation,NativePaidHooks,Refunds,UnpaidAcquisitionProof};
use Paymenter\Extensions\Servers\Domains\Admin\Support\AcquisitionPaymentReview;
// The original mixed checkout set native time before these closure cases.
Carbon\Carbon::setTestNow($clock->now());Carbon\CarbonImmutable::setTestNow($clock->now());
// A real transfer timeout commits closure before credit; failure must not hide later money.
$cart=commerceCart($user);commerceAdd($cart,$native->product($tlds[0],'transfer'),'paid-refund-gap-placeholder.com');commerceCheckout($user,$cart)->assertHasNoErrors();
$gap=Domain::where('domain','paid-refund-gap-placeholder.com')->sole()->operations()->sole();$paid($gap);$clock->instant=$now->modify('+31 days');
$failCredit=true;Credit::saving(static function()use(&$failCredit):void {if($failCredit){$failCredit=false;throw new RuntimeException('Synthetic credit interruption');}});
app()->bind(AcquisitionRetirement::class,static fn()=>throw new RuntimeException('Synthetic lost timeout wake'));
try{$transfers->expireMissingCode($gap->id);expect(false,'Credit failure expected');}catch(RuntimeException $e){same('Synthetic credit interruption',$e->getMessage(),'Credit interruption is after closure: '.$e->getMessage());}
app()->instance(AcquisitionRetirement::class,$retirement);
expect(isset($gap->fresh()->policy['native_acquisition_closure']),'Timeout snapshot survives both lost wake and credit failure');
same('rejected',$gap->fresh()->state,'Timeout closure committed before credit');same(null,$gap->fresh()->funding['refund']??null,'Credit not yet issued');same(0,$alerts($gap)->count(),'Original payment in credit gap stays quiet without any inspection');
InvoiceTransaction::create(['invoice_id'=>$gap->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>'processing']);$retirement->inspect($gap->domain_id);$retirement->inspect($gap->domain_id);expect($alerts($gap)->count()>0,'Credit failure and lost wake cannot hide late payment');$clock->instant=$now;
// Staff refund authorization is durable before credit, even if Service is not yet Cancelled.
$phone->update(['value'=>'']);
$staffGap=$new(placeholderComFixtureName('paid-staff-refund-gap-placeholder.com'),paid:false,setup:false);$paid($staffGap);$failCredit=true;
$phone->update(['value'=>$savedPhone]);
try{$refunds->refundHeld($staff,$staffGap->id,'customer_cancelled');expect(false,'Staff credit failure expected');}catch(RuntimeException $e){same('Synthetic credit interruption',$e->getMessage(),'Staff credit interrupted after authorization');}
same('rejected',$staffGap->fresh()->state,'Staff closure survives credit interruption');same(null,$staffGap->fresh()->funding['refund']??null,'Staff credit still pending');
$retirement->inspect($staffGap->domain_id);same(0,$alerts($staffGap)->count(),'Original authorized payment stays quiet');
$staffGap->domain->service->fresh()->update(['status'=>'cancelled']);$staffGap->domain->service->fresh()->update(['status'=>'active']);
$retirement->inspect($staffGap->domain_id);same(1,$alerts($staffGap)->count(),'Reactivation after authorization but before credit is visible');
InvoiceTransaction::create(['invoice_id'=>$staffGap->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>'processing']);$retirement->inspect($staffGap->domain_id);expect($alerts($staffGap)->count()>0,'Staff credit failure cannot hide late money');
// A guarded completed cancellation retains evidence even when every wake fails until reactivation.
require __DIR__.'/reconciliation-fixture.php';
[$completed]=$prepare(placeholderComFixtureName('paid-completed-missed-wake-placeholder.com'));same('succeeded',$reconcile->run($completed->id)->state,'Real completed holding for cancellation recovery');
App\Models\ServiceCancellation::create(['service_id'=>$completed->service_id,'type'=>'end_of_period','reason'=>'Stop future renewal']);
app()->bind(AcquisitionRetirement::class,static fn()=>throw new RuntimeException('Synthetic lost cancellation wake'));
$completed->domain->service->fresh()->update(['status'=>'cancelled']);
expect(isset($completed->fresh()->policy['native_acquisition_closure']),'Guarded cancellation retains immutable evidence before callback');
$completed->domain->service->fresh()->update(['status'=>'active']);
app()->instance(AcquisitionRetirement::class,$retirement);$retirement->inspect($completed->domain_id);same(1,$alerts($completed)->count(),'Sweep recovers reactivation after missed cancellation wake');
$clock->instant=$now;

$nativeSuccessful=true;
