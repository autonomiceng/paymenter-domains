<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
require __DIR__.'/Support/staff-panel.php';
use App\Models\{Service,ServiceCancellation};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement,DomainReservations,HoldingLocks,NativeCancellation,NativeIdentity,NativeIntentSchema,ReminderSchema,StaffHoldingReview,UnpaidAcquisitionProof};
use Paymenter\Extensions\Servers\Domains\Admin\Support\AcquisitionActions;
app()->instance(Paymenter\Extensions\Servers\Domains\Services\InvoiceBinding::class,$invoiceBinding);
NativeIntentSchema::install();ReminderSchema::install();NativeIdentity::boot();NativeCancellation::boot();
$retirement=new AcquisitionRetirement($clock);app()->instance(AcquisitionRetirement::class,$retirement);app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
$permissions=['admin.domains.view','admin.domains.manage',AcquisitionRetirement::PERMISSION];$staff=staffUser($permissions);
$ticket=static fn($op,$actor=null)=>StaffHoldingReview::ticket((int)($actor??$staff)->id,(int)$op->domain_id,'retire_unpaid',StaffHoldingReview::stamp(HoldingLocks::take($op->domain->fresh(),advisory:true)),$clock);
$cancel=static function($op):void {
    DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'cancelled']);
    DB::table('services')->where('id',$op->service_id)->update(['status'=>'cancelled']);
};
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
// No event, raw cancelled fixtures model missed wake-ups and privileged bulk changes.
foreach(['partial','processing','failed','reversed','sent','null_execution','claim','provider','sibling']as$case){
    $op=$new('unpaid-'.str_replace('_','-',$case).'.com',paid:false);
    if(in_array($case,['partial','processing','failed','reversed'],true)){
        App\Models\InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>$case==='reversed'?'-1.00':'1.00','fee'=>'0.00','status'=>$case==='processing'?'processing':($case==='failed'?'failed':'succeeded')]);
    }elseif($case==='sent'){$op->update(['attempt'=>['seal'=>'sealed','send_state'=>'possibly_sent']]);}
    elseif($case==='null_execution'){$op->update(['attempt'=>['execution'=>null]]);}
    elseif($case==='claim'){$op->update(['started_at'=>$clock->now(),'request_fingerprint'=>str_repeat('a',64),'attempt'=>['seal'=>'absent','send_state'=>'definitely_unsent','claim_key'=>str_repeat('a',32)]]);}
    elseif($case==='provider'){$op->domain->update(['provider_order_id'=>'synthetic-provider']);}
    else{Operation::create(['domain_id'=>$op->domain_id,'service_id'=>$op->service_id,'server_id'=>$op->server_id,'registrar_key'=>$op->registrar_key,'account_fingerprint'=>$op->account_fingerprint,'authority_generation'=>1,'operation_key'=>'other:'.$op->id,'action'=>'renew','term'=>1,'state'=>'queued','amount'=>'1.00','currency'=>'USD']);}
    $cancel($op);$retirement->inspect($op->domain_id);same($op->domain->domain,$op->domain->fresh()->reservation_name,$case.' retains name');
    same(1,DB::table('domains_obligation_alerts')->where('domain_id',$op->domain_id)->where('code','acquisition_retirement_review')->count(),$case.' durable review alert');
    commerceReject(fn()=>$retirement->retire($op->domain_id,$staff,$ticket($op),'Adversarial staff retirement'));
}
$oldPartial=$new('unpaid-old-partial.com',paid:false);
App\Models\InvoiceTransaction::create(['invoice_id'=>$oldPartial->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>'processing']);
DB::table('services')->where('id',$oldPartial->service_id)->update(['created_at'=>$clock->now()->modify('-8 days')]);
$retirement->inspect($oldPartial->domain_id);same(1,DB::table('domains_obligation_alerts')->where('domain_id',$oldPartial->domain_id)->where('code','acquisition_retirement_review')->count(),'Old partial pending acquisition gets durable review without cancellation');
$transferOp=$new('unpaid-transfer.com',paid:false,action:'transfer');$cancel($transferOp);$retirement->inspect($transferOp->domain_id);same('rejected',$transferOp->fresh()->state,'Unpaid transfer closes with same proof');same(null,$transferOp->domain->fresh()->reservation_name,'Unpaid transfer releases name');
$open=$new('unpaid-open-invoice.com',paid:false);DB::table('services')->where('id',$open->service_id)->update(['status'=>'cancelled']);$retirement->inspect($open->domain_id);
same('unpaid-open-invoice.com',$open->domain->fresh()->reservation_name,'Incomplete native cancellation retains name');same(1,DB::table('domains_obligation_alerts')->where('domain_id',$open->domain_id)->where('code','acquisition_retirement_review')->whereNull('resolved_at')->count(),'Open invoice cancellation alerts staff');
$retirement->retire($open->domain_id,$staff,$ticket($open),'Finish reviewed unpaid cancellation');same(0,DB::table('domains_obligation_alerts')->where('domain_id',$open->domain_id)->where('code','acquisition_retirement_review')->whereNull('resolved_at')->count(),'Proven retirement resolves its earlier incomplete review');
$configured=$new('unpaid-configured-age.com',paid:false);App\Models\InvoiceTransaction::create(['invoice_id'=>$configured->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>'processing']);DB::table('services')->where('id',$configured->service_id)->update(['created_at'=>$clock->now()->modify('-8 days')]);
$cancelDays=config('settings.cronjob_order_cancel',7);config(['settings.cronjob_order_cancel'=>10]);$retirement->inspect($configured->domain_id);same(0,DB::table('domains_obligation_alerts')->where('domain_id',$configured->domain_id)->count(),'Configured native cancellation delay is respected');config(['settings.cronjob_order_cancel'=>4]);$retirement->inspect($configured->domain_id);same(1,DB::table('domains_obligation_alerts')->where('domain_id',$configured->domain_id)->count(),'Configured shorter delay reviews old partial money');config(['settings.cronjob_order_cancel'=>$cancelDays]);
// Staff action, fresh identity/permission/reason snapshot and retained audit.
$staffOp=$new('unpaid-staff.com',paid:false);$key=$ticket($staffOp);
commerceReject(fn()=>$retirement->retire($staffOp->domain_id,$staff,$key,"Bad\nreason"));
$staffOp->domain->service->forceFill(['label'=>'Changed after review'])->save();commerceReject(fn()=>$retirement->retire($staffOp->domain_id,$staff,$key,'Stale review refuses'));
$key=$ticket($staffOp);$staff->role->update(['permissions'=>['admin.domains.view']]);commerceReject(fn()=>$retirement->retire($staffOp->domain_id,$staff,$key,'Revoked staff refuses'));$staff->role->update(['permissions'=>$permissions]);
$key=$ticket($staffOp);$other=staffUser($permissions);commerceReject(fn()=>$retirement->retire($staffOp->domain_id,$other,$key,'Wrong actor refuses'));
$retirement->retire($staffOp->domain_id,$staff,$ticket($staffOp),'Customer abandoned unpaid order');
same(null,$staffOp->domain->fresh()->reservation_name,'Staff action releases unpaid name');same('cancelled',App\Models\Invoice::find($staffOp->invoice_id)->status,'Staff action closes sole pending debt');
$audit=$staffOp->fresh()->policy['unpaid_retirement'];same($staff->id,$audit['actor_id'],'Staff audit actor');same('Customer abandoned unpaid order',$audit['reason'],'Staff audit reason');same('awaiting_payment',$audit['before']['operation'],'Staff audit prior state');
commerceReject(fn()=>$retirement->retire($staffOp->domain_id,$staff,$key,'Replay cannot retire again'));
commerceReject(fn()=>$staffOp->fresh()->update(['policy'=>array_diff_key($staffOp->fresh()->policy,['unpaid_retirement'=>true])]));
// Released names stay released after money arrives; terminal work remains nonspendable.
App\Models\InvoiceTransaction::create(['invoice_id'=>$staffOp->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>'processing']);
$retirement->inspect($staffOp->domain_id);same('rejected',$staffOp->fresh()->state,'Late money never reopens work');same(null,$staffOp->domain->fresh()->reservation_name,'Late money never steals back a reservation');
same(1,DB::table('domains_obligation_alerts')->where('domain_id',$staffOp->domain_id)->where('code','acquisition_late_money')->count(),'Late money durable review');
DB::table('invoices')->where('id',$staffOp->invoice_id)->update(['status'=>'paid']);
DB::transaction(fn()=>(new App\Services\Invoice\ProcessPaidInvoiceService)->handle(App\Models\Invoice::findOrFail($staffOp->invoice_id)));same('active',$staffOp->domain->service->fresh()->status,'Late native paid activation never throws');same(null,$staffOp->domain->service->fresh()->expires_at,'Late native paid activation keeps null expiry');$retirement->inspect($staffOp->domain_id);same('rejected',$staffOp->fresh()->state,'Late activation never reopens work');
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
// Real staff modal success and refusal use the same locked authority.
Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();staffPanel();Auth::login($staff);
$pageClass=Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class;
$modalOp=$new('unpaid-modal.com',paid:false);Auth::login($staff);
$page=Livewire\Livewire::actingAs($staff)->test($pageClass,['record'=>$modalOp->domain_id])->mountAction('retire_unpaid')->assertActionMounted('retire_unpaid');
$page->setActionData(['reason'=>'Retire from real staff modal'])->callMountedAction()->assertNotified('Unpaid order retired')->assertRedirect(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource::getUrl('view',['record'=>$modalOp->domain_id]));
Livewire\Livewire::actingAs($staff)->test($pageClass,['record'=>$modalOp->domain_id])->assertActionDoesNotExist('retire_unpaid');same(null,$modalOp->domain->fresh()->reservation_name,'Real modal invokes retirement');
$paid=$new('unpaid-refusal.com');Auth::login($staff);
$refusal=Livewire\Livewire::actingAs($staff)->test($pageClass,['record'=>$paid->domain_id])->mountAction('retire_unpaid');
$refusal->setActionData(['reason'=>'Paid modal must refuse'])->callMountedAction()->assertNotified('Order retirement refused');
same($paid->domain->domain,$paid->domain->fresh()->reservation_name,'Paid modal retains name');
$viewOnly=staffUser(['admin.domains.view']);Livewire\Livewire::actingAs($viewOnly)->test($pageClass,['record'=>$paid->domain_id])->assertActionDoesNotExist('retire_unpaid');
echo "Unpaid retirement, native shared cron, money/send refusal, audit, late money and staff modals passed.\n";$nativeSuccessful=true;
