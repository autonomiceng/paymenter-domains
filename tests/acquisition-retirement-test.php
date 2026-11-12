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
$invoicePresenter=new \Paymenter\Extensions\Servers\Domains\Services\NativePresenter(new \Paymenter\Extensions\Servers\Domains\Services\OwnedDomains($clock,new \Paymenter\Extensions\Servers\Domains\Services\OwnerPorts),$native,new \Paymenter\Extensions\Servers\Domains\Services\HolderPricing($prices));
$closed=$invoicePresenter->invoiceOutcome($user,(int)$solo->invoice_id);same(['acquisition_cancelled_unpaid','pending',[$solo->domain->domain]],[$closed->kind,$closed->money,$closed->domains],'Retired acquisition is visible before any late payment or return note');
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
// Native cancellation and Paid/afterCommit need the existing native fixture: payment can beat retirement.
App\Helpers\ExtensionHelper::addPayment($rollback->invoice_id,null,$rollback->amount);
AcquisitionRetirement::retainClosure($rollback->fresh(),collect(),'paid',$clock->now());
DB::transaction(fn()=>Paymenter\Extensions\Servers\Domains\Services\InvoiceFunding::lock((int)$rollback->invoice_id)->requireInitial($rollback->fresh(),$rollback->domain->fresh()));
same(null,$invoicePresenter->invoiceOutcome($user,(int)$rollback->invoice_id),'A paid acquisition that can still run is not closed by a snapshot alone');
$lateNative=$new(placeholderComFixtureName('native-cancel-paid-placeholder.com'),paid:false);$lateBill=App\Models\Invoice::findOrFail($lateNative->invoice_id);
// Keep this real cancellation/late-payment case between native closure and ledger
// retirement, as its presenter assertion requires. The native listener updates the
// service before bulk-cancelling the invoice, so sweep recovery attempts retirement.
$lateRetirementRefused=false;
Operation::updating(static function(Operation $op)use($lateNative,&$lateRetirementRefused):void {
    if((int)$op->id===(int)$lateNative->id&&$op->state==='rejected'){$lateRetirementRefused=true;throw new DomainException('Synthetic delayed retirement');}
});
// Listener discovery has no sort order. Run cancellation before its notification,
// so its notification caches the already-cancelled service.
$cancelEvent=App\Events\ServiceCancellation\Created::class;
$cancelListeners=app('events')->getRawListeners()[$cancelEvent];
app('events')->forget($cancelEvent);
app('events')->listen($cancelEvent,App\Listeners\CancellationCreatedListener::class.'@handle');
app('events')->listen($cancelEvent,App\Listeners\SendMailListener::class.'@handle');
try{$lateCancel=ServiceCancellation::create(['service_id'=>$lateNative->service_id,'type'=>'immediate','reason'=>'Cancel before payment']);}
finally{app('events')->forget($cancelEvent);foreach($cancelListeners as $listener){app('events')->listen($cancelEvent,$listener);}}
same('cancelled',$lateCancel->service->status,'Notification caches the completed native cancellation');
same('cancelled',$lateBill->fresh()->status,'Native cancellation has closed the invoice before sweep recovery');
// A repeated native status save need not emit another update. Recover from durable state.
$retirement->inspect((int)$lateNative->domain_id);
expect($lateRetirementRefused,'Sweep recovery attempted automatic retirement after native cancellation');
same('awaiting_payment',$lateNative->fresh()->state,'A refused retirement retains the native cancellation window');
$lateOutcome=null;
Illuminate\Support\Facades\Event::listen(App\Events\Invoice\Paid::class,static function($event)use($lateBill,$invoicePresenter,$user,&$lateOutcome):void {
    if($event->invoice->id===$lateBill->id){DB::afterCommit(static function()use($lateBill,$invoicePresenter,$user,&$lateOutcome):void {$lateOutcome=$invoicePresenter->invoiceOutcome($user,(int)$lateBill->id);});}
});
DB::transaction(fn()=>App\Models\InvoiceTransaction::create(['invoice_id'=>$lateBill->id,'amount'=>$lateNative->amount,'fee'=>'0.00','status'=>'succeeded']));
same(['acquisition_cancelled_unpaid','pending',null,[$lateNative->domain->domain]],[$lateOutcome?->kind,$lateOutcome?->money,$lateOutcome?->amount,$lateOutcome?->domains],'Paid mail sees native acquisition cancellation before terminal retirement');
same('awaiting_payment',$lateNative->fresh()->state,'The money read does not finish refused retirement');
// A cancelled service cannot start an unpaid order, so no page asks for payment.
// Nothing was paid, so the customer reads a plain cancel: Cancelled, no failure and no Contact support.
require dirname(__DIR__).'/Domains/routes/owned.php';require dirname(__DIR__).'/Domains/routes/web.php';app('router')->getRoutes()->refreshNameLookups();
$open=$new(placeholderComFixtureName('unpaid-open-invoice-placeholder.com'),paid:false);
$owned=new Paymenter\Extensions\Servers\Domains\Services\OwnedDomains($clock,new Paymenter\Extensions\Servers\Domains\Services\OwnerPorts);
$orderWords=static function()use($open,$owned):array {$page=$owned->present($open->domain->fresh());return [$page->order['state']??null,$page->status,$page->statusReason];};
same(['unpaid','Awaiting payment','Invoice not paid'],$orderWords(),'An open order on a live service offers payment');
DB::table('services')->where('id',$open->service_id)->update(['status'=>'cancelled']);
same(['cancelled','Cancelled','Order cancelled'],$orderWords(),'A cancelled service with an open invoice offers no payment and reads as a plain cancel');
$calm=$owned->present($open->domain->fresh());same(true,$calm->order['unpaid']??null,'The unpaid cancel is marked as nothing charged');
$note=Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::notice($calm);same(['info','This order was cancelled. Nothing was charged.',null],[$note['tone']??null,$note['text']??null,$note['action']??null],'The page says nothing was charged and asks for nothing');
DB::table('services')->where('id',$open->service_id)->update(['status'=>'pending']);DB::table('domains_native_intents')->insert(['service_id'=>$open->service_id,'kind'=>'cancellation','created_at'=>now()]);
same(['cancelled','Cancelled','Order cancelled'],$orderWords(),'A retained cancellation intent counts as cancelled');
// A successful or in-flight payment prevents the unpaid-cancel message even before invoice status catches up.
// Each status runs alone, so either one on its own keeps the review.
foreach(['processing','succeeded'] as $status){
    $payment=App\Models\InvoiceTransaction::withoutEvents(fn()=>App\Models\InvoiceTransaction::create(['invoice_id'=>$open->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>$status]));
    same([1,$status],[App\Models\InvoiceTransaction::where('invoice_id',$open->invoice_id)->count(),$payment->fresh()->status->value],'Fixture: only the '.$status.' payment is on the invoice');
    same(['cancelled','Under review','Order cancelled'],$orderWords(),'A '.$status.' payment on a cancelled service needs review');
    $paidCancel=$owned->present($open->domain->fresh());
    same(false,$paidCancel->order['unpaid']??false,'Payment evidence never says nothing was charged');
    App\Models\InvoiceTransaction::withoutEvents(fn()=>$payment->delete());
}
same(['cancelled','Cancelled','Order cancelled'],$orderWords(),'Without the payments the cancelled order reads as a plain cancel again');
echo "Unpaid retirement, native cron, wake-up and cancellation passed.\n";$nativeSuccessful=true;
