<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-paid-cases.php';
use App\Models\{Credit,Invoice,InvoiceTransaction,Service};
use Illuminate\Support\Facades\{DB,Event,Queue};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement,ContactRecovery,ContactSetup,NativeActivationInformation,NativePaidHooks,Refunds,UnpaidAcquisitionProof};
use Paymenter\Extensions\Servers\Domains\Admin\Support\AcquisitionPaymentReview;
// Product mail initializes once, preserves custom text and deliberately cleared text on republish.
same(NativeActivationInformation::TEXT,$product->fresh()->email_template,'Approved product information installed');
// A real mixed checkout; Credit is native and must receive its ordinary one-time contribution.
$cart=commerceCart($user);commerceAdd($cart,$product,placeholderComFixtureName('paid-mixed-placeholder.com'));$hosting=commerceHosting();
$cart->items()->create(['product_id'=>$hosting->id,'plan_id'=>$hosting->plans()->sole()->id,'quantity'=>1,'config_options'=>[],'checkout_config'=>[]]);commerceCheckout($user,$cart)->assertHasNoErrors();
$mixed=Domain::where('domain','paid-mixed-placeholder.com')->sole()->operations()->sole();$invoice=Invoice::findOrFail($mixed->invoice_id);
$template=new App\Models\NotificationTemplate;$template->forceFill(Database\Seeders\EmailTemplateSeeder::mapping['new_server_created']);
$mail=(new App\Mail\Mail($template,['service'=>$mixed->domain->service,'user'=>$user]))->render();
expect(str_contains($mail,'Your domain registration is in progress.')&&!str_contains($mail,'transfer')&&!str_contains($mail,'auth code'),'Native registration mail renders approved product information without transfer instructions');
expect(str_contains($mail,'href="'.e(route('services.show',$mixed->service_id)).'"'),'Native activation mail links to the service page');
foreach(['Staff-owned wording','',null]as$text){$product->fresh()->update(['email_template'=>$text]);activeFixtureRepublish($native,$tlds[0]->id,$clock->now());same($text,$product->fresh()->email_template,'Publication preserves staff edit including blank/null');}
$transferProduct=$native->product($tlds[0],'transfer');$transferProduct->update(['email_template'=>'Existing staff information']);
$transferProduct->settings()->where('key','domains_activation_information_initialized')->delete();activeFixtureRepublish($native,$tlds[0]->id,$clock->now());same('Existing staff information',$transferProduct->fresh()->email_template,'First publication preserves existing custom text');
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

$nativeSuccessful=true;
