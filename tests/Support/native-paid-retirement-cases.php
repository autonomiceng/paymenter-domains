<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-paid-cases.php';
use App\Models\{Credit,Invoice,InvoiceTransaction,Service};
use Illuminate\Support\Facades\{DB,Event,Queue};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement,ContactRecovery,ContactSetup,NativeActivationInformation,NativePaidHooks,Refunds,UnpaidAcquisitionProof};
use Paymenter\Extensions\Servers\Domains\Admin\Support\AcquisitionPaymentReview;
// A proven unpaid retirement releases its name; late money and native Active never reclaim it.
$retired=$new(placeholderComFixtureName('paid-retired-placeholder.com'),paid:false);DB::table('invoices')->where('id',$retired->invoice_id)->update(['status'=>'cancelled']);DB::table('services')->where('id',$retired->service_id)->update(['status'=>'cancelled']);$retirement->inspect($retired->domain_id);
same(UnpaidAcquisitionProof::CODE,$retired->fresh()->safe_code,'Use existing unpaid proof');$cart=commerceCart($user);commerceAdd($cart,$product,placeholderComFixtureName('paid-retired-placeholder.com'));commerceCheckout($user,$cart)->assertHasNoErrors();$replacement=Domain::where('reservation_name','paid-retired-placeholder.com')->sole()->operations()->sole();$paid($retired);$manual($retired);$refunds->creditRetired(\Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$retired->server_id)->sole());
same(null,$retired->domain->fresh()->reservation_name,'Released name stays released');same('paid-retired-placeholder.com',$replacement->domain->fresh()->reservation_name,'New owner reservation retained');expect(count($retired->fresh()->funding['late_credits']??[])===1&&$alerts($retired)->count()===0,'Retired late payment is durable review');
// Cancelled partial-money acquisition cannot retire; retained cancellation intent survives Active.
$cancelled=$new(placeholderComFixtureName('paid-cancelled-partial-placeholder.com'),paid:false);
$cancelled->domain->service->update(['status'=>'cancelled']);InvoiceTransaction::create(['invoice_id'=>$cancelled->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>'processing']);DB::table('invoices')->where('id',$cancelled->invoice_id)->update(['status'=>'cancelled']);$retirement->inspect($cancelled->domain_id);$before=$writer->calls;$paid($cancelled);$manual($cancelled);$work->operation($cancelled->id);
same($before,$writer->calls,'Cancelled acquisition re-payment cannot spend');
expect($alerts($cancelled)->count()>=1,'Cancelled acquisition money remains visible after native reactivation');same('paid-cancelled-partial-placeholder.com',$cancelled->domain->fresh()->reservation_name,'Uncertain cancellation keeps reservation');
// Completed holdings and rejected-but-not-refunded orders are not closed acquisitions.
foreach(['succeeded','rejected']as$state){
    $quiet=$new(placeholderComFixtureName('paid-quiet-'.$state.'-placeholder.com'));
    DB::table('domains_operations')->where('id',$quiet->id)->update(['state'=>$state]);
    DB::table('domains_domains')->where('id',$quiet->domain_id)->update(['status'=>$state==='succeeded'?'active':'registration_rejected']);
    DB::table('services')->where('id',$quiet->service_id)->update(['status'=>$state==='succeeded'?'cancelled':'active']);
    $retirement->inspect($quiet->domain_id);same(0,$alerts($quiet)->count(),'Ordinary completed cancellation or refund gap stays quiet');
    if($state==='succeeded'){$quiet->domain->service->fresh()->update(['status'=>'active']);same(1,$alerts($quiet)->count(),'Cancelled completed holding reactivation is reviewed');}
}

$nativeSuccessful=true;
