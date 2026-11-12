<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/acquisition-retirement.php';
use App\Models\{Service,ServiceCancellation};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement,DomainReservations,HoldingLocks,NativeCancellation,NativeIdentity,NativeIntentSchema,ReminderSchema,StaffHoldingReview,UnpaidAcquisitionProof};
use Paymenter\Extensions\Servers\Domains\Admin\Support\AcquisitionActions;
// Native full cron, shared hosting invoice, no invoice model events and all-null stock.
$cart=commerceCart($user);commerceAdd($cart,$product,placeholderComFixtureName('unpaid-mixed-placeholder.com'));$hosting=commerceHosting();
$cart->items()->create(['product_id'=>$hosting->id,'plan_id'=>$hosting->plans()->sole()->id,'quantity'=>1,'config_options'=>[],'checkout_config'=>[]]);commerceCheckout($user,$cart);
$mixed=Domain::where('domain','unpaid-mixed-placeholder.com')->sole()->operations()->sole();
$shared=App\Models\Invoice::findOrFail($mixed->invoice_id);same(2,$shared->items()->count(),'Shared native invoice fixture');
commerceReject(fn()=>$retirement->retire($mixed->domain_id,$staff,$ticket($mixed),'Shared pending invoice refuses'));same('pending',$shared->fresh()->status,'Staff shared-invoice refusal changes no invoice');same('pending',$mixed->domain->service->fresh()->status,'Staff shared-invoice refusal changes no service');
$soloCron=$new(placeholderComFixtureName('unpaid-solo-cron-placeholder.com'),paid:false);DB::table('services')->where('id',$soloCron->service_id)->update(['created_at'=>$clock->now()->modify('-8 days')]);
DB::table('services')->whereIn('id',$shared->items()->pluck('reference_id'))->update(['created_at'=>$clock->now()->modify('-8 days')]);
// Hide other intentionally adversarial fixtures from this real upstream cron qualification.
DB::table('services')->whereNotIn('id',[$mixed->service_id,$soloCron->service_id])->where('status','pending')->update(['created_at'=>$clock->now()]);
$invoiceEvents=0;App\Models\Invoice::updated(function()use(&$invoiceEvents):void {$invoiceEvents++;});
Illuminate\Support\Facades\Artisan::call('app:cron-job');
same('cancelled',$shared->fresh()->status,'Vanilla cron cancels the whole shared invoice');same('cancelled',$mixed->domain->service->fresh()->status,'Vanilla cron cancels managed service');
same(0,$invoiceEvents,'Vanilla cron bulk cancellation emits no Invoice update event');
same('cancelled',App\Models\Invoice::find($soloCron->invoice_id)->status,'Vanilla cron cancels solo invoice');same('cancelled',$soloCron->domain->service->fresh()->status,'Vanilla cron cancels solo service');$retirement->inspect($soloCron->domain_id);same(null,$soloCron->domain->fresh()->reservation_name,'Solo cron retirement releases name');
$retirement->inspect($mixed->domain_id);same(null,$mixed->domain->fresh()->reservation_name,'Sweep releases shared-invoice unpaid acquisition');same(null,$product->fresh()->stock,'Cron never replenishes managed stock');
echo "Unpaid acquisition retirement case passed.\n";$nativeSuccessful=true;
