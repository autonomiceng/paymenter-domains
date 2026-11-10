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
// Native cancellation and Paid/afterCommit need the existing native fixture: payment can beat retirement.
App\Helpers\ExtensionHelper::addPayment($rollback->invoice_id,null,$rollback->amount);
AcquisitionRetirement::retainClosure($rollback->fresh(),collect(),'paid',$clock->now());
DB::transaction(fn()=>Paymenter\Extensions\Servers\Domains\Services\InvoiceFunding::lock((int)$rollback->invoice_id)->requireInitial($rollback->fresh(),$rollback->domain->fresh()));
same(null,$invoicePresenter->invoiceOutcome($user,(int)$rollback->invoice_id),'A paid acquisition that can still run is not closed by a snapshot alone');
$lateNative=$new(placeholderComFixtureName('native-cancel-paid-placeholder.com'),paid:false);$lateBill=App\Models\Invoice::findOrFail($lateNative->invoice_id);
// Keep this real cancellation/late-payment case between native closure and ledger
// retirement, as its presenter assertion requires. Automatic retirement now normally
// completes during cancellation, so explicitly exercise its recoverable refusal.
$lateRetirementRefused=false;
Operation::updating(static function(Operation $op)use($lateNative,&$lateRetirementRefused):void {
    if((int)$op->id===(int)$lateNative->id&&$op->state==='rejected'){$lateRetirementRefused=true;throw new DomainException('Synthetic delayed retirement');}
});
$lateCancel=ServiceCancellation::create(['service_id'=>$lateNative->service_id,'type'=>'immediate','reason'=>'Cancel before payment']);
(new App\Listeners\CancellationCreatedListener)->handle(new App\Events\ServiceCancellation\Created($lateCancel));
expect($lateRetirementRefused,'The native cancellation attempted automatic retirement');
same('awaiting_payment',$lateNative->fresh()->state,'A refused retirement retains the native cancellation window');
$lateOutcome=null;
Illuminate\Support\Facades\Event::listen(App\Events\Invoice\Paid::class,static function($event)use($lateBill,$invoicePresenter,$user,&$lateOutcome):void {
    if($event->invoice->id===$lateBill->id){DB::afterCommit(static function()use($lateBill,$invoicePresenter,$user,&$lateOutcome):void {$lateOutcome=$invoicePresenter->invoiceOutcome($user,(int)$lateBill->id);});}
});
DB::transaction(fn()=>App\Models\InvoiceTransaction::create(['invoice_id'=>$lateBill->id,'amount'=>$lateNative->amount,'fee'=>'0.00','status'=>'succeeded']));
same(['acquisition_cancelled_unpaid','pending',null,[$lateNative->domain->domain]],[$lateOutcome?->kind,$lateOutcome?->money,$lateOutcome?->amount,$lateOutcome?->domains],'Paid mail sees native acquisition cancellation before terminal retirement');
same('awaiting_payment',$lateNative->fresh()->state,'The money read does not finish refused retirement');
// A cancelled service with its invoice still open stays pending for staff. Paying can't start it, so no page asks for the money.
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
// Native gateway reversals are expected closure activity, not new late money or a second Domains return.
require __DIR__.'/Support/money-receipt.php';MoneyReceiptGateway::$lost=false;
$gateway=App\Models\Gateway::create(['extension'=>'MoneyReceipt','name'=>'Money receipt fixture','type'=>'gateway','enabled'=>true]);
$automatic=$new(placeholderComFixtureName('closed-auto-return-placeholder.com'),paid:false);$cancel($automatic);$retirement->inspect($automatic->domain_id);
$bill=App\Models\Invoice::findOrFail($automatic->invoice_id);
App\Models\InvoiceTransaction::withoutEvents(fn()=>App\Models\InvoiceTransaction::create(['invoice_id'=>$bill->id,'amount'=>'1.00','fee'=>'0.00','status'=>'succeeded','gateway_id'=>$gateway->id,'transaction_id'=>'receipt_closed_auto']));
MoneyReceiptGateway::automatic($bill,'receipt_closed_auto',100);
$returns=app(Paymenter\Extensions\Servers\Domains\Services\Refunds::class);$account=Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$automatic->server_id)->firstOrFail();
$observed=$returns->creditRetired($account);
expect(in_array($automatic->domain_id,$observed,true),'Closed acquisition observes the gateway return');
same([],$returns->creditRetired($account),'Closed acquisition repeats without another return');
$returns->creditRetired($account);$retirement->inspect($automatic->domain_id);
expect(UnpaidAcquisitionProof::terminal($automatic->fresh(),$automatic->domain->fresh()),'Return results preserve retained no-send proof');
same(0,DB::table('domains_obligation_alerts')->where('domain_id',$automatic->domain_id)->whereIn('code',['refund_repair','acquisition_late_money'])->whereNull('resolved_at')->count(),'Expected gateway reversal creates no new repair step');
same(1,MoneyReceiptGateway::$sends,'Gateway owns the only closed acquisition return');
// The closed-order sweep checks a malformed retained plan before outstanding allocations: staff repair, no error, money or gateway call.
$staysLogs=[];\Illuminate\Support\Facades\Log::listen(static function($event)use(&$staysLogs,$automatic):void {if($event->message==='Domains late payment stays with staff.'&&($event->context['operation_id']??null)===(int)$automatic->id){$staysLogs[]=$event->context;}});
$validRaw=$automatic->fresh()->getRawOriginal('funding');$bad=$automatic->fresh()->funding;$bad['late_credits'][0]['allocations'][0]=[...$bad['late_credits'][0]['allocations'][0],'amount_minor'=>'not-an-amount','key'=>'domains:closed:unobserved'];
DB::table('domains_operations')->where('id',$automatic->id)->update(['funding'=>Illuminate\Support\Facades\Crypt::encryptString(json_encode($bad))]);
$rows=static fn(string $table)=>DB::table($table)->orderBy('id')->get()->map(static fn($row)=>(array)$row)->all();$money=[$rows('credits'),$rows('invoice_transactions')];$calls=[MoneyReceiptGateway::$statusCalls,MoneyReceiptGateway::$sends];
same([],$returns->creditRetired($account),'A malformed closed-order plan is not reported as credited');
same([],$staysLogs,'A malformed closed-order plan is checked before allocation arithmetic');
expect(DB::table('domains_obligation_alerts')->where('operation_id',$automatic->id)->where('code','refund_repair')->whereNull('resolved_at')->exists(),'A malformed closed-order plan keeps a staff repair');
same($money[0],$rows('credits'),'A malformed closed-order plan adds no credit');
same($money[1],$rows('invoice_transactions'),'A malformed closed-order plan writes no payment row');
same($calls,[MoneyReceiptGateway::$statusCalls,MoneyReceiptGateway::$sends],'A malformed closed-order plan calls no gateway');
// An account-credit return with unreadable coverage is checked before the sweep reads what it credited: staff repair, no error.
Paymenter\Extensions\Servers\Domains\Services\RefundRepairs::resolved((int)$automatic->id,$clock->now()->getTimestamp());$creditOnly=$automatic->fresh()->funding;
$creditOnly['late_credits']=[['payments'=>[['id'=>(int)App\Models\InvoiceTransaction::where('transaction_id','receipt_closed_auto')->value('id'),'amount_minor'=>[100]]],
    'amount'=>'1.00','currency'=>'USD','to'=>'account_credit','credit_id'=>null,'at'=>$clock->now()->format(DATE_ATOM)]];
DB::table('domains_operations')->where('id',$automatic->id)->update(['funding'=>Illuminate\Support\Facades\Crypt::encryptString(json_encode($creditOnly))]);
same([],$returns->creditRetired($account),'Unreadable account-credit coverage is not reported as credited');
same([DomainException::class],array_column($staysLogs,'exception_class'),'Unreadable account-credit coverage is a review refusal, not an error');
expect(DB::table('domains_obligation_alerts')->where('operation_id',$automatic->id)->where('code','refund_repair')->whereNull('resolved_at')->exists(),'Unreadable account-credit coverage keeps a staff repair');
same([$money[0],$money[1]],[$rows('credits'),$rows('invoice_transactions')],'Unreadable account-credit coverage moves no money');
same($calls,[MoneyReceiptGateway::$statusCalls,MoneyReceiptGateway::$sends],'Unreadable account-credit coverage calls no gateway');
DB::table('domains_operations')->where('id',$automatic->id)->update(['funding'=>$validRaw]);
// A refused close rolls back the replacement invoice too; never return a payment link to it.
// Native rows and the retirement transaction are required to reproduce the rolled-back invoice ID.
$cart=commerceCart($user);commerceAdd($cart,$product,placeholderComFixtureName('unpaid-reissue-rollback-placeholder.com'));
$hosting=commerceHosting();$cart->items()->create(['product_id'=>$hosting->id,'plan_id'=>$hosting->plans()->sole()->id,'quantity'=>1,'config_options'=>[],'checkout_config'=>[]]);commerceCheckout($user,$cart);
$refusedReissue=Domain::where('domain','unpaid-reissue-rollback-placeholder.com')->sole()->operations()->sole();
$invoiceCount=App\Models\Invoice::count();$refusedOnce=false;
Operation::updating(static function(Operation $op)use($refusedReissue,&$refusedOnce):void {
    if((int)$op->id===(int)$refusedReissue->id&&$op->state==='rejected'){$refusedOnce=true;throw new DomainException('Synthetic retirement refusal after reissue');}
});
$closed=$retirement->closeUnpaid((int)$refusedReissue->invoice_id,Paymenter\Extensions\Servers\Domains\Services\PayCheck::notice('expired'));
expect($refusedOnce,'The close refused after creating its replacement invoice');
same(null,$closed['invoice'],'A rolled-back replacement invoice never becomes a payment link');
same($invoiceCount,App\Models\Invoice::count(),'The refused close rolls back the replacement invoice');
same(['pending','awaiting_payment',$refusedReissue->domain->domain],[App\Models\Invoice::findOrFail($refusedReissue->invoice_id)->status,$refusedReissue->fresh()->state,$refusedReissue->domain->fresh()->reservation_name],'A refused close retains the unpaid order and reserved name');

echo "Unpaid retirement, native cron, wake-up and cancellation passed.\n";$nativeSuccessful=true;
