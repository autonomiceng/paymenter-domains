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
$hosting=commerceHosting();$cart=commerceCart($user);commerceAdd($cart,$product,placeholderComFixtureName('unpaid-immediate-shared-placeholder.com'));$cart->items()->create(['product_id'=>$hosting->id,'plan_id'=>$hosting->plans()->sole()->id,'quantity'=>1,'config_options'=>[],'checkout_config'=>[]]);commerceCheckout($user,$cart);
$immediate=Domain::where('domain','unpaid-immediate-shared-placeholder.com')->sole()->operations()->sole();$immediateCancellation=ServiceCancellation::create(['service_id'=>$immediate->service_id,'type'=>'end_of_period','reason'=>'Unpaid shared immediate cancellation']);same('immediate',$immediateCancellation->fresh()->type,'Shared unpaid end-of-period normalizes');
(new App\Listeners\CancellationCreatedListener)->handle(new App\Events\ServiceCancellation\Created($immediateCancellation));same('cancelled',$immediate->domain->service->fresh()->status,'Native immediate listener closes service');same('cancelled',App\Models\Invoice::find($immediate->invoice_id)->status,'Native immediate listener bulk-cancels whole shared invoice');$retirement->inspect($immediate->domain_id);same(null,$immediate->domain->fresh()->reservation_name,'Sweep after native immediate bulk update releases name');
 need the existing native fixture: payment can beat retirement.
App\Helpers\ExtensionHelper::addPayment($rollback->invoice_id,null,$rollback->amount);
AcquisitionRetirement::retainClosure($rollback->fresh(),collect(),'paid',$clock->now());
DB::transaction(fn()=>Paymenter\Extensions\Servers\Domains\Services\InvoiceFunding::lock((int)$rollback->invoice_id)->requireInitial($rollback->fresh(),$rollback->domain->fresh()));
same(null,$invoicePresenter->invoiceOutcome($user,(int)$rollback->invoice_id),'A paid acquisition that can still run is not closed by a snapshot alone');
echo "Unpaid acquisition retirement case passed.\n";$nativeSuccessful=true;
