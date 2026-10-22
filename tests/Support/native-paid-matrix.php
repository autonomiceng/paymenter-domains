<?php
// Native paid processing executes without an extension binding. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-paid-fixture.php';
require_once __DIR__.'/reserved-fixtures.php';
use App\Models\{Credit,Invoice,InvoiceTransaction,Service};
use Illuminate\Support\Facades\{DB,Event,Queue};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement,ContactRecovery,ContactSetup,NativeActivationInformation,NativePaidHooks,Refunds,RuntimeHealth,UnpaidAcquisitionProof};
use Paymenter\Extensions\Servers\Domains\Admin\Support\AcquisitionPaymentReview;
// Product mail initializes once, preserves custom text and deliberately cleared text on republish.
same(NativeActivationInformation::TEXT,$product->fresh()->email_template,'Approved product information installed');
// A real mixed checkout; Credit is native and must receive its ordinary one-time contribution.
$cart=commerceCart($user);commerceAdd($cart,$product,placeholderComFixtureName('paid-mixed-placeholder.com'));$hosting=commerceHosting();
$cart->items()->create(['product_id'=>$hosting->id,'plan_id'=>$hosting->plans()->sole()->id,'quantity'=>1,'config_options'=>[],'checkout_config'=>[]]);commerceCheckout($user,$cart)->assertHasNoErrors();
$mixed=Domain::where('domain','paid-mixed-placeholder.com')->sole()->operations()->sole();$invoice=Invoice::findOrFail($mixed->invoice_id);
$template=new App\Models\NotificationTemplate;$template->forceFill(Database\Seeders\EmailTemplateSeeder::mapping['new_server_created']);
$mail=(new App\Mail\Mail($template,['service'=>$mixed->domain->service,'user'=>$user]))->render();
expect(str_contains($mail,'Your domain registration or transfer is in progress.')&&str_contains($mail,'Transfers can take several days.'),'Native activation mail renders approved product information');
expect(str_contains($mail,'href="'.e(route('services.show',$mixed->service_id)).'"'),'Native activation mail links to the service page');
foreach(['Staff-owned wording','',null]as$text){$product->fresh()->update(['email_template'=>$text]);$native->publish($tlds[0]->id,$clock->now());same($text,$product->fresh()->email_template,'Publication preserves staff edit including blank/null');}
$transferProduct=$native->product($tlds[0],'transfer');$transferProduct->update(['email_template'=>'Existing staff information']);
$transferProduct->settings()->where('key','domains_activation_information_initialized')->delete();$native->publish($tlds[0]->id,$clock->now());same('Existing staff information',$transferProduct->fresh()->email_template,'First publication preserves existing custom text');
$host=Service::where('product_id',$hosting->id)->sole();
$invoice->items()->create(['reference_type'=>Credit::class,'reference_id'=>null,'description'=>'Account credit','price'=>'3.00','quantity'=>1]);
$early=[];Service::updating(static function($s)use(&$early,$mixed,$writer):void {if($s->id===$mixed->service_id&&$s->status==='active'){$early[]=['stored'=>Service::find($s->id)->status,'writes'=>$writer->calls];}});
// An early manual/sync job before payment can only wake the retained unpaid operation.
(new App\Jobs\Server\UnsuspendJob($mixed->domain->service))->handle();$manual($mixed);same(0,$writer->calls,'Unpaid early job cannot send');$paid($mixed);
same('active',$host->fresh()->status,'Hosting sibling activates');expect($host->fresh()->expires_at!==null,'Hosting keeps native expiry');same('3.00',number_format((float)$user->credits()->sole()->amount,2,'.',''),'Native Credit sibling processed');
same(1,$writer->calls,'Native sync/Paid wakes send once');same('uncertain',$mixed->fresh()->state,'Owned status does not become active on payment');same(null,$mixed->domain->service->fresh()->expires_at,'Managed expiry stays null');same(null,$mixed->domain->fresh()->provider_expires_at,'No fabricated registrar expiry');
expect(count($early)>0&&$early[0]===['stored'=>'pending','writes'=>1],'Synchronous CreateJob sends before native Active save');
$count=Operation::count();$attempt=$mixed->fresh()->attempt;$nativeBeforeReplay=nativeFinancialSnapshot($mixed);
for($n=0;$n<2;$n++){event(new App\Events\Invoice\Paid($invoice->fresh()));$manual($mixed);$work->operation($mixed->id);}
same(1,$writer->calls,'Duplicate Paid/manual CreateJob never resend sealed work');same($count,Operation::count(),'No duplicate operation admission');same($attempt,$mixed->fresh()->attempt,'Sealed evidence retained');same('3.00',number_format((float)$user->credits()->sole()->amount,2,'.',''),'Duplicate Paid wake never processes Credit again');
same($nativeBeforeReplay,nativeFinancialSnapshot($mixed),'Duplicate Paid/manual/worker replay preserves native/financial state (reminder alerts and deliveries excluded)');
// Queue/wake failure cannot interrupt unrelated native siblings.
$wakeFailure=$new(placeholderComFixtureName('paid-wake-failure-placeholder.com'),paid:false);
$wakeInvoice=Invoice::find($wakeFailure->invoice_id);
$wakeInvoice->items()->create(['reference_type'=>Service::class,'reference_id'=>$host->id,'description'=>'Hosting','price'=>'5.00','quantity'=>1]);
$wakeInvoice->items()->create(['reference_type'=>Credit::class,'description'=>'Account credit','price'=>'2.00','quantity'=>1]);
$dispatch=app(Paymenter\Extensions\Servers\Domains\Services\RuntimeDispatch::class);
app()->bind(Paymenter\Extensions\Servers\Domains\Services\RuntimeDispatch::class,static fn()=>throw new RuntimeException('Synthetic unavailable queue'));
$manual($wakeFailure);$paid($wakeFailure);
same('active',$host->fresh()->status,'Hosting continues when domain wake fails');same('5.00',number_format((float)$user->credits()->sole()->amount,2,'.',''),'Credit continues when domain wake fails');
same('awaiting_payment',$wakeFailure->fresh()->state,'Failed wake remains recoverable');
app()->instance(Paymenter\Extensions\Servers\Domains\Services\RuntimeDispatch::class,$dispatch);
// Missing contacts can arrive after payment; native callbacks never readmit a hold.
$late=$new(placeholderComFixtureName('paid-late-contact-placeholder.com'),paid:false,setup:false);$before=$writer->calls;$paid($late);
same($before,$writer->calls,'Incomplete contacts never send');same('held',$late->fresh()->state,'Contact setup holds');
$nativeBeforeReplay=nativeFinancialSnapshot($late);$heldBeforeReplay=$late->fresh()->getRawOriginal();
$manual($late);event(new App\Events\Invoice\Paid(Invoice::find($late->invoice_id)));same('held',$late->fresh()->state,'Native wake never readmits contact hold');
same($heldBeforeReplay,$late->fresh()->getRawOriginal(),'Native contact-hold replay preserves operation bytes');
same($nativeBeforeReplay,nativeFinancialSnapshot($late),'Native contact-hold replay preserves native/financial state (reminder alerts and deliveries excluded)');
ContactRecovery::replace($user,$late->id,'Owner supplied late contacts');$manual($late);same($before+1,$writer->calls,'Audited contact recovery permits one send');
// Explicit failure before send can be credited once; original payment history is quiet.
$failed=$new(placeholderComFixtureName('paid-failed-placeholder.com'),paid:false);$preflight->supported=false;$paid($failed);$preflight->supported=true;
same('held',$failed->fresh()->state,'Unsupported registration remains held');$staff=staffUser(['admin.domains.view','admin.domains.manage','admin.invoices.update','admin.invoices.view']);
$delayedCreate=serialize(new App\Jobs\Server\CreateJob($failed->domain->service,false));$delayedUnsuspend=serialize(new App\Jobs\Server\UnsuspendJob($failed->domain->service));$staleCreate=new App\Jobs\Server\CreateJob($failed->domain->service,false);
$refunds=new Refunds($clock);$creditBefore=number_format((float)$user->credits()->sole()->amount,2,'.','');$refunds->refundHeld($staff,$failed->id,'customer_cancelled');
same('rejected',$failed->fresh()->state,'Refund terminalizes request');same('cancelled',$failed->domain->service->fresh()->status,'Refund cancels native service');
$retirement=app(AcquisitionRetirement::class);$retirement->inspect($failed->domain_id);same(0,$alerts($failed)->count(),'Retained original refunded payment is not late money');
$credit=number_format((float)$user->credits()->sole()->amount,2,'.','');$before=$writer->calls;$manual($failed);unserialize($delayedCreate)->handle();unserialize($delayedUnsuspend)->handle();$staleCreate->handle();event(new App\Events\Invoice\Paid(Invoice::find($failed->invoice_id)));$work->operation($failed->id);$refunds->creditRejected($failed->id);
same($before,$writer->calls,'Late job after refund cannot spend');same($credit,number_format((float)$user->credits()->sole()->amount,2,'.',''),'Late wake never credits twice');
// Re-paying the same invoice reactivates native Service, never the owned request.
DB::table('invoices')->where('id',$failed->invoice_id)->update(['status'=>'pending']);$paid($failed);
same('rejected',$failed->fresh()->state,'Re-paid refunded acquisition stays terminal');same($credit,number_format((float)$user->credits()->sole()->amount,2,'.',''),'Re-payment is not auto-credited');expect($alerts($failed)->count()>=1,'New refunded-invoice money is durable review');
$versions=$alerts($failed)->count();$retirement->inspect($failed->domain_id);same($versions,$alerts($failed)->count(),'Repeated sweep is one activity');
InvoiceTransaction::create(['invoice_id'=>$failed->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>'processing']);$retirement->inspect($failed->domain_id);same($versions+1,$alerts($failed)->count(),'Second late payment creates visible activity');
same('active',$failed->domain->service->fresh()->status,'Native unconditional reactivation is tolerated');
// A proven unpaid retirement releases its name; late money and native Active never reclaim it.
$retired=$new(placeholderComFixtureName('paid-retired-placeholder.com'),paid:false);DB::table('invoices')->where('id',$retired->invoice_id)->update(['status'=>'cancelled']);DB::table('services')->where('id',$retired->service_id)->update(['status'=>'cancelled']);$retirement->inspect($retired->domain_id);
same(UnpaidAcquisitionProof::CODE,$retired->fresh()->safe_code,'Use existing unpaid proof');$cart=commerceCart($user);commerceAdd($cart,$product,placeholderComFixtureName('paid-retired-placeholder.com'));commerceCheckout($user,$cart)->assertHasNoErrors();$replacement=Domain::where('reservation_name','paid-retired-placeholder.com')->sole()->operations()->sole();$paid($retired);$manual($retired);
same(null,$retired->domain->fresh()->reservation_name,'Released name stays released');same('paid-retired-placeholder.com',$replacement->domain->fresh()->reservation_name,'New owner reservation retained');expect($alerts($retired)->count()>=1,'Retired late payment is durable review');
// Cancelled partial-money acquisition cannot retire; retained cancellation intent survives Active.
$cancelled=$new(placeholderComFixtureName('paid-cancelled-partial-placeholder.com'),paid:false);InvoiceTransaction::create(['invoice_id'=>$cancelled->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>'failed']);
$cancelled->domain->service->update(['status'=>'cancelled']);DB::table('invoices')->where('id',$cancelled->invoice_id)->update(['status'=>'cancelled']);$retirement->inspect($cancelled->domain_id);$before=$writer->calls;$paid($cancelled);$manual($cancelled);$work->operation($cancelled->id);
same($before,$writer->calls,'Cancelled acquisition re-payment cannot spend');
expect($alerts($cancelled)->count()>=1,'Cancelled acquisition money remains visible after native reactivation');same('paid-cancelled-partial-placeholder.com',$cancelled->domain->fresh()->reservation_name,'Uncertain cancellation keeps reservation');
// Service reactivation alone is a discrepancy even with the original refund payment set.
$activeOnly=$new(placeholderComFixtureName('paid-active-only-placeholder.com'));$declined=InvoiceTransaction::create(['invoice_id'=>$activeOnly->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>'failed']);$activeOnly->update(['state'=>'held','safe_code'=>'staff_review']);$refunds->refundHeld($staff,$activeOnly->id,'customer_cancelled');same(0,$alerts($activeOnly)->count(),'Refund itself stays quiet');
$activeOnly->domain->service->update(['status'=>'active']);same(1,$alerts($activeOnly)->count(),'Native Active without new money triggers retained discrepancy');
// Completed holdings and rejected-but-not-refunded orders are not closed acquisitions.
foreach(['succeeded','rejected']as$state){
    $quiet=$new(placeholderComFixtureName('paid-quiet-'.$state.'-placeholder.com'));
    DB::table('domains_operations')->where('id',$quiet->id)->update(['state'=>$state]);
    DB::table('domains_domains')->where('id',$quiet->domain_id)->update(['status'=>$state==='succeeded'?'active':'registration_rejected']);
    DB::table('services')->where('id',$quiet->service_id)->update(['status'=>$state==='succeeded'?'cancelled':'active']);
    $retirement->inspect($quiet->domain_id);same(0,$alerts($quiet)->count(),'Ordinary completed cancellation or refund gap stays quiet');
    if($state==='succeeded'){$quiet->domain->service->fresh()->update(['status'=>'active']);same(1,$alerts($quiet)->count(),'Cancelled completed holding reactivation is reviewed');}
}
// A real transfer timeout commits closure before credit; failure must not hide later money.
$cart=commerceCart($user);commerceAdd($cart,$native->product($tlds[0],'transfer'),'paid-refund-gap-placeholder.com');commerceCheckout($user,$cart)->assertHasNoErrors();
$gap=Domain::where('domain','paid-refund-gap-placeholder.com')->sole()->operations()->sole();$paid($gap);$clock->instant=$now->modify('+31 days');
$failCredit=true;Credit::saving(static function()use(&$failCredit):void {if($failCredit){$failCredit=false;throw new RuntimeException('Synthetic credit interruption');}});
app()->bind(AcquisitionRetirement::class,static fn()=>throw new RuntimeException('Synthetic lost timeout wake'));
try{$transfers->expireMissingCode($gap->id);expect(false,'Credit failure expected');}catch(RuntimeException $e){same('Synthetic credit interruption',$e->getMessage(),'Credit interruption is after closure');}
app()->instance(AcquisitionRetirement::class,$retirement);
expect(isset($gap->fresh()->policy['native_acquisition_closure']),'Timeout snapshot survives both lost wake and credit failure');
same('rejected',$gap->fresh()->state,'Timeout closure committed before credit');same(null,$gap->fresh()->funding['refund']??null,'Credit not yet issued');same(0,$alerts($gap)->count(),'Original payment in credit gap stays quiet without any inspection');
InvoiceTransaction::create(['invoice_id'=>$gap->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>'processing']);$retirement->inspect($gap->domain_id);$retirement->inspect($gap->domain_id);expect($alerts($gap)->count()>0,'Credit failure and lost wake cannot hide late payment');$clock->instant=$now;
// Staff refund authorization is durable before credit, even if Service is not yet Cancelled.
$staffGap=$new(placeholderComFixtureName('paid-staff-refund-gap-placeholder.com'),paid:false,setup:false);$paid($staffGap);$failCredit=true;
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
// Lost Paid listener: native siblings continue; fair scheduler recovers only retained work.
Event::forget(App\Events\Invoice\Paid::class);expect(str_contains(NativePaidHooks::notices()['Start after payment'],'Paused'),'Missing exact Paid listener is visible');
$lost=$new(placeholderComFixtureName('paid-lost-event-placeholder.com'),paid:false);$queue=Queue::getFacadeRoot();Queue::fake();$paid($lost);same('awaiting_payment',$lost->fresh()->state,'Missing event leaves recoverable operation');
Queue::assertNotPushed(Paymenter\Extensions\Servers\Domains\Jobs\RunOperation::class);Queue::swap($queue);$work->tick();$clock->instant=$clock->now()->modify('+61 seconds');Illuminate\Support\Facades\Cache::flush();$work->operation($lost->id);same('uncertain',$lost->fresh()->state,'Scheduler recovers lost Paid wake');NativePaidHooks::boot();
// Multi-day transfer reconciliation cannot turn into a second acquisition send.
$transfer=$new(placeholderComFixtureName('paid-long-transfer-placeholder.com'),paid:false,action:'transfer');$paid($transfer);expect(in_array($transfer->fresh()->state,['pending','uncertain'],true),'Transfer entered processing on first sync job: '.$transfer->fresh()->state.' / '.$transfer->fresh()->safe_code);$sent=$writer->calls;
$clock->instant=$now->modify('+4 days');$work->operation($transfer->id);$manual($transfer);event(new App\Events\Invoice\Paid(Invoice::find($transfer->invoice_id)));
same($sent,$writer->calls,'Days-long transfer reads retained attempt only');expect(in_array($transfer->fresh()->state,['pending','uncertain'],true),'Transfer remains processing until positive registry evidence');
// Public capability loss pauses acquisition sends without blocking native billing or reconciliation.
$clock->instant=$now;$missing=$new(placeholderComFixtureName('paid-no-service-hook-placeholder.com'),paid:false);Service::flushEventListeners();$before=$writer->calls;$paid($missing);
same($before,$writer->calls,'Missing Service hooks pause acquisition send');same('awaiting_payment',$missing->fresh()->state,'Missing hooks preserve unsent work without a permanent hold');expect(str_contains(NativePaidHooks::notices()['Paymenter payment events'],'Service saving'),'Staff sees precise missing Service event');
// Actual staff queue and detail surfaces, including invoice permission narrowing.
Livewire\Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ListDomains::class)->filterTable('late_payment')->assertCanSeeTableRecords([$failed->domain,$retired->domain])->assertCanNotSeeTableRecords([$mixed->domain]);
Livewire\Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$failed->domain_id])->assertSee('Paid after order closed')->assertSee('Invoice #'.$failed->invoice_id)->assertSee('Registrar request #'.$failed->id);
$viewOnly=staffUser(['admin.domains.view']);Livewire\Livewire::actingAs($viewOnly)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$failed->domain_id])->assertSee('invoice edit access required');
echo "Native paid matrix: mixed/Credit, early sync, duplicates, contacts, transfer, refund, late money, reactivation, recovery and staff review passed.\n";$nativeSuccessful=true;
