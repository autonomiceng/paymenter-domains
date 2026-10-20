<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/acquisition-retirement.php';
use App\Models\{Service,ServiceCancellation};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement,DomainReservations,HoldingLocks,NativeCancellation,NativeIdentity,NativeIntentSchema,ReminderSchema,StaffHoldingReview,UnpaidAcquisitionProof};
use Paymenter\Extensions\Servers\Domains\Admin\Support\AcquisitionActions;
// The durable cursor does not need model events (the native cron invoice UPDATE bypasses them).
$solo=$new('unpaid-solo.com',paid:false);$cancel($solo);$retirement->inspect($solo->domain_id);
same('rejected',$solo->fresh()->state,'Solo cancelled unpaid request is terminal');same(UnpaidAcquisitionProof::CODE,$solo->fresh()->safe_code,'Dedicated unfunded terminal code');
same(null,$solo->domain->fresh()->reservation_name,'Unfunded retirement releases reservation atomically');same('cancelled',$solo->domain->fresh()->status,'Owned acquisition closed');
same('Unpaid order retired',Paymenter\Extensions\Servers\Domains\Admin\Support\Labels::reason($solo->fresh()->safe_code),'Retired request has a clear staff reason');same(null,$solo->fresh()->funding,'No account credit fabricated');$retirement->inspect($solo->domain_id);
$cart=commerceCart($user);commerceAdd($cart,$product,'unpaid-solo.com');commerceCheckout($user,$cart);
$again=Domain::where('reservation_name','unpaid-solo.com')->sole()->operations()->sole();expect($again->domain_id!==$solo->domain_id,'Fresh checkout can reserve retired name');
// Native full cron, shared hosting invoice, no invoice model events and all-null stock.
$cart=commerceCart($user);commerceAdd($cart,$product,'unpaid-mixed.com');$hosting=commerceHosting();
$cart->items()->create(['product_id'=>$hosting->id,'plan_id'=>$hosting->plans()->sole()->id,'quantity'=>1,'config_options'=>[],'checkout_config'=>[]]);commerceCheckout($user,$cart);
$mixed=Domain::where('domain','unpaid-mixed.com')->sole()->operations()->sole();
$shared=App\Models\Invoice::findOrFail($mixed->invoice_id);same(2,$shared->items()->count(),'Shared native invoice fixture');
commerceReject(fn()=>$retirement->retire($mixed->domain_id,$staff,$ticket($mixed),'Shared pending invoice refuses'));same('pending',$shared->fresh()->status,'Staff shared-invoice refusal changes no invoice');same('pending',$mixed->domain->service->fresh()->status,'Staff shared-invoice refusal changes no service');
$soloCron=$new('unpaid-solo-cron.com',paid:false);DB::table('services')->where('id',$soloCron->service_id)->update(['created_at'=>$clock->now()->modify('-8 days')]);
DB::table('services')->whereIn('id',$shared->items()->pluck('reference_id'))->update(['created_at'=>$clock->now()->modify('-8 days')]);
// Hide other intentionally adversarial fixtures from this real upstream cron qualification.
DB::table('services')->whereNotIn('id',[$mixed->service_id,$soloCron->service_id])->where('status','pending')->update(['created_at'=>$clock->now()]);
$invoiceEvents=0;App\Models\Invoice::updated(function()use(&$invoiceEvents):void {$invoiceEvents++;});
Illuminate\Support\Facades\Artisan::call('app:cron-job');
same('cancelled',$shared->fresh()->status,'Vanilla cron cancels the whole shared invoice');same('cancelled',$mixed->domain->service->fresh()->status,'Vanilla cron cancels managed service');
same(0,$invoiceEvents,'Vanilla cron bulk cancellation emits no Invoice update event');
same('cancelled',App\Models\Invoice::find($soloCron->invoice_id)->status,'Vanilla cron cancels solo invoice');same('cancelled',$soloCron->domain->service->fresh()->status,'Vanilla cron cancels solo service');$retirement->inspect($soloCron->domain_id);same(null,$soloCron->domain->fresh()->reservation_name,'Solo cron retirement releases name');
$retirement->inspect($mixed->domain_id);same(null,$mixed->domain->fresh()->reservation_name,'Sweep releases shared-invoice unpaid acquisition');same(null,$product->fresh()->stock,'Cron never replenishes managed stock');
// Wake-up runs after outer commit and is discarded on rollback.
AcquisitionRetirement::boot();$wake=$new('unpaid-wake.com',paid:false);
DB::transaction(function()use($wake):void {App\Models\Invoice::find($wake->invoice_id)->update(['status'=>'cancelled']);$wake->domain->service->update(['status'=>'cancelled']);same('awaiting_payment',$wake->fresh()->state,'No retirement before outer commit');});
same('rejected',$wake->fresh()->state,'After-commit wake retires');
$rollback=$new('unpaid-rollback.com',paid:false);try{DB::transaction(function()use($rollback):void {App\Models\Invoice::find($rollback->invoice_id)->update(['status'=>'cancelled']);$rollback->domain->service->update(['status'=>'cancelled']);throw new RuntimeException('Rollback');});}catch(RuntimeException){}
same('awaiting_payment',$rollback->fresh()->state,'Rollback drops retirement wake');
// End-of-period on an unpaid Pending managed service is normalized at the public write.
$eop=$new('unpaid-eop.com',paid:false);$eopCancel=ServiceCancellation::create(['service_id'=>$eop->service_id,'type'=>'end_of_period','reason'=>'Unpaid forged future stop']);same('immediate',$eopCancel->fresh()->type,'No unfunded period exists');
$closed=$solo->domain->service->fresh();$closed->update(['status'=>'pending']);$closedCancel=ServiceCancellation::create(['service_id'=>$closed->id,'type'=>'end_of_period','reason'=>'Closed ledger has no unpaid period']);same('immediate',$closedCancel->fresh()->type,'Pending unpaid closed ledger still normalizes cancellation');same('unpaid-solo.com',$again->domain->fresh()->reservation_name,'Closed ledger cancellation cannot reclaim a newer reservation');
// Actual queued native immediate listener cancels the whole shared invoice; its bulk update needs sweep recovery.
$cart=commerceCart($user);commerceAdd($cart,$product,'unpaid-immediate-shared.com');$cart->items()->create(['product_id'=>$hosting->id,'plan_id'=>$hosting->plans()->sole()->id,'quantity'=>1,'config_options'=>[],'checkout_config'=>[]]);commerceCheckout($user,$cart);
$immediate=Domain::where('domain','unpaid-immediate-shared.com')->sole()->operations()->sole();$immediateCancellation=ServiceCancellation::create(['service_id'=>$immediate->service_id,'type'=>'end_of_period','reason'=>'Unpaid shared immediate cancellation']);same('immediate',$immediateCancellation->fresh()->type,'Shared unpaid end-of-period normalizes');
(new App\Listeners\CancellationCreatedListener)->handle(new App\Events\ServiceCancellation\Created($immediateCancellation));same('cancelled',$immediate->domain->service->fresh()->status,'Native immediate listener closes service');same('cancelled',App\Models\Invoice::find($immediate->invoice_id)->status,'Native immediate listener bulk-cancels whole shared invoice');$retirement->inspect($immediate->domain_id);same(null,$immediate->domain->fresh()->reservation_name,'Sweep after native immediate bulk update releases name');
echo "Unpaid retirement, native cron, wake-up and cancellation passed.\n";$nativeSuccessful=true;
