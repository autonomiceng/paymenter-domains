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
$solo=$new(placeholderComFixtureName('unpaid-solo-placeholder.com'),paid:false);$cancel($solo);$retirement->inspect($solo->domain_id);
same('rejected',$solo->fresh()->state,'Solo cancelled unpaid request is terminal');same(UnpaidAcquisitionProof::CODE,$solo->fresh()->safe_code,'Dedicated unfunded terminal code');
same(null,$solo->domain->fresh()->reservation_name,'Unfunded retirement releases reservation atomically');same('cancelled',$solo->domain->fresh()->status,'Owned acquisition closed');
same('Order cancelled: not paid',Paymenter\Extensions\Servers\Domains\Admin\Support\Labels::reason($solo->fresh()->safe_code),'Retired request has a clear staff reason');same(null,$solo->fresh()->funding,'No account credit fabricated');$retirement->inspect($solo->domain_id);
// The customer's list leaves out a name that was never paid for, under every chip. Staff keep the record.
$keptCancelled=$new(placeholderComFixtureName('paid-cancelled-placeholder.com'));DB::table('services')->where('id',$keptCancelled->service_id)->update(['status'=>'cancelled']);DB::table('domains_domains')->where('id',$keptCancelled->domain_id)->update(['status'=>'cancelled']);
foreach(['PslSchema','PslReviewSchema','WatchSchema','WatchDnsSchema','ManagedPublicWorkSchema','ManagedPublicSchema','WatchScheduleSchema','WatchNoticeSchema','AdoptionSchema','NativeIntentSchema'] as $schema){('Paymenter\\Extensions\\Servers\\Domains\\Services\\'.$schema)::install();}
require dirname(__DIR__).'/Domains/routes/web.php';require dirname(__DIR__).'/Domains/routes/owned.php';app('router')->getRoutes()->refreshNameLookups();view()->addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
$listed=static function(string $query='')use($user):array {
    $request=Illuminate\Http\Request::create('/domains'.$query);$request->setUserResolver(static fn()=>$user);
    return array_map(static fn($row)=>$row->name,app(Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController::class)->index($request)->getData()['domains']->items());};
same(false,in_array('unpaid-solo-placeholder.com',[...$listed(),...$listed('?status=cancelled')],true),'A never-paid order is never listed');
same([false,true],[in_array('paid-cancelled-placeholder.com',$listed(),true),in_array('paid-cancelled-placeholder.com',$listed('?status=cancelled'),true)],'A cancelled domain waits behind the Cancelled chip');
$second=commerceUser();$cart=commerceCart($second);commerceAdd($cart,$product,placeholderComFixtureName('unpaid-solo-placeholder.com'));commerceCheckout($second,$cart);
$again=Domain::where('reservation_name','unpaid-solo-placeholder.com')->sole()->operations()->sole();expect($again->domain_id!==$solo->domain_id,'Another customer can order a retired name');
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
// Wake-up runs after outer commit and is discarded on rollback.
AcquisitionRetirement::boot();$wake=$new(placeholderComFixtureName('unpaid-wake-placeholder.com'),paid:false);
DB::transaction(function()use($wake):void {App\Models\Invoice::find($wake->invoice_id)->update(['status'=>'cancelled']);$wake->domain->service->update(['status'=>'cancelled']);same('awaiting_payment',$wake->fresh()->state,'No retirement before outer commit');});
same('rejected',$wake->fresh()->state,'After-commit wake retires');
$rollback=$new(placeholderComFixtureName('unpaid-rollback-placeholder.com'),paid:false);try{DB::transaction(function()use($rollback):void {App\Models\Invoice::find($rollback->invoice_id)->update(['status'=>'cancelled']);$rollback->domain->service->update(['status'=>'cancelled']);throw new RuntimeException('Rollback');});}catch(RuntimeException){}
same('awaiting_payment',$rollback->fresh()->state,'Rollback drops retirement wake');
// End-of-period on an unpaid Pending managed service is normalized at the public write.
$eop=$new(placeholderComFixtureName('unpaid-eop-placeholder.com'),paid:false);$eopCancel=ServiceCancellation::create(['service_id'=>$eop->service_id,'type'=>'end_of_period','reason'=>'Unpaid forged future stop']);same('immediate',$eopCancel->fresh()->type,'No unfunded period exists');
$closed=$solo->domain->service->fresh();$closed->update(['status'=>'pending']);$closedCancel=ServiceCancellation::create(['service_id'=>$closed->id,'type'=>'end_of_period','reason'=>'Closed ledger has no unpaid period']);same('immediate',$closedCancel->fresh()->type,'Pending unpaid closed ledger still normalizes cancellation');same('unpaid-solo-placeholder.com',$again->domain->fresh()->reservation_name,'Closed ledger cancellation cannot reclaim a newer reservation');
// Actual queued native immediate listener cancels the whole shared invoice; its bulk update needs sweep recovery.
$cart=commerceCart($user);commerceAdd($cart,$product,placeholderComFixtureName('unpaid-immediate-shared-placeholder.com'));$cart->items()->create(['product_id'=>$hosting->id,'plan_id'=>$hosting->plans()->sole()->id,'quantity'=>1,'config_options'=>[],'checkout_config'=>[]]);commerceCheckout($user,$cart);
$immediate=Domain::where('domain','unpaid-immediate-shared-placeholder.com')->sole()->operations()->sole();$immediateCancellation=ServiceCancellation::create(['service_id'=>$immediate->service_id,'type'=>'end_of_period','reason'=>'Unpaid shared immediate cancellation']);same('immediate',$immediateCancellation->fresh()->type,'Shared unpaid end-of-period normalizes');
(new App\Listeners\CancellationCreatedListener)->handle(new App\Events\ServiceCancellation\Created($immediateCancellation));same('cancelled',$immediate->domain->service->fresh()->status,'Native immediate listener closes service');same('cancelled',App\Models\Invoice::find($immediate->invoice_id)->status,'Native immediate listener bulk-cancels whole shared invoice');$retirement->inspect($immediate->domain_id);same(null,$immediate->domain->fresh()->reservation_name,'Sweep after native immediate bulk update releases name');
// A cancelled service with its invoice still open stays pending for staff. Paying can't start it, so no page asks for the money.
require dirname(__DIR__).'/Domains/routes/owned.php';require dirname(__DIR__).'/Domains/routes/web.php';app('router')->getRoutes()->refreshNameLookups();
$open=$new(placeholderComFixtureName('unpaid-open-invoice-placeholder.com'),paid:false);
$owned=new Paymenter\Extensions\Servers\Domains\Services\OwnedDomains($clock,new Paymenter\Extensions\Servers\Domains\Services\OwnerPorts);
$orderWords=static function()use($open,$owned):array {$page=$owned->present($open->domain->fresh());return [$page->order['state']??null,$page->status,$page->statusReason];};
same(['unpaid','Action required','Invoice not paid'],$orderWords(),'An open order on a live service offers payment');
DB::table('services')->where('id',$open->service_id)->update(['status'=>'cancelled']);
same(['cancelled','Under review','Order cancelled'],$orderWords(),'A cancelled service with an open invoice offers no payment');
DB::table('services')->where('id',$open->service_id)->update(['status'=>'pending']);DB::table('domains_native_intents')->insert(['service_id'=>$open->service_id,'kind'=>'cancellation','created_at'=>now()]);
same(['cancelled','Under review','Order cancelled'],$orderWords(),'A retained cancellation intent counts as cancelled');
echo "Unpaid retirement, native cron, wake-up and cancellation passed.\n";$nativeSuccessful=true;
