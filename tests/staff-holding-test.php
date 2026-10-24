<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
require __DIR__.'/Support/staff-panel.php';
require_once __DIR__.'/Support/converted-holding.php';
use App\Models\ServiceCancellation;
use Illuminate\Support\Facades\{Auth,DB,View};
use Paymenter\Extensions\Servers\Domains\Services\{HoldingLocks,NativeCancellation,NativeIdentity,NativeIntentSchema,StaffHoldingReview,RetailLine};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{HoldingActions,DomainFacts};
app()->instance(Paymenter\Extensions\Servers\Domains\Services\InvoiceBinding::class,$invoiceBinding);
NativeIntentSchema::install();NativeIdentity::boot();NativeCancellation::boot();
View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
app()->instance(Paymenter\Extensions\Servers\Domains\Services\NativeDomainGuard::class,$guard);
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
$review=new StaffHoldingReview($guard,$clock);app()->instance(StaffHoldingReview::class,$review);
$permissions=['admin.domains.view','admin.domains.manage','admin.domains.fund',...array_map(StaffHoldingReview::permission(...),array_keys(StaffHoldingReview::ACTIONS))];
$staff=staffUser($permissions);Auth::login($staff);
$ticket=static fn($d,$action,$actor=null)=>StaffHoldingReview::ticket((int)($actor??$staff)->id,(int)$d->id,$action,StaffHoldingReview::stamp(HoldingLocks::take($d->fresh(),advisory:true)),$clock);
[$original]=$prepare('holding-review.com');same('succeeded',$reconcile->run($original->id)->state,'Holding has original proof');$d=$original->domain->fresh();$s=$d->service;
DB::table('services')->where('id',$s->id)->update(['price'=>'9.00']);$money=$nativeMoney();$catalogue=serialize(DB::table('domains_prices')->get());
$key=$ticket($d,'reprice');$review->run($staff,$d->id,'reprice',$key,'Apply published renewal retail');
same('10.40',RetailLine::decimal($s->fresh()->price),'Owned action applies renewal retail');same(null,$s->fresh()->expires_at,'Owned projection keeps native expiry null');
same($money,$nativeMoney(),'Reprice preserves all debt and payments');same($catalogue,serialize(DB::table('domains_prices')->get()),'Reprice does not publish catalogue');
$a=$d->fresh()->renewal_policy['staff_holdings'][0];same($staff->id,$a['actor_id'],'Audit actor');same('9.00',$a['before']['annual_gross'],'Audit before');same('10.40',$a['after']['annual_gross'],'Audit after');same('Apply published renewal retail',$a['reason'],'Audit reason');
commerceReject(fn()=>$review->run($staff,$d->id,'reprice',$key,'Replay old review key'));
commerceReject(fn()=>DB::transaction(fn()=>$s->fresh()->update(['price'=>'9.00'])));
// Catalogue price changes after the preview invalidate the reviewed gross amount.
$priceRow=DB::table('domains_prices')->where('action','renew')->first();$key=$ticket($d,'reprice');
DB::table('domains_prices')->where('id',$priceRow->id)->update(['retail'=>'11.00']);
commerceReject(fn()=>$review->run($staff,$d->id,'reprice',$key,'Stale published price review'));same('10.40',RetailLine::decimal($s->fresh()->price),'Stale price review cannot apply a different amount');
DB::table('domains_prices')->where('id',$priceRow->id)->update(['retail'=>$priceRow->retail]);
// Stale modal, wrong action/actor, expired key and revoked role all fail before writes.
$key=$ticket($d,'stop');$s->fresh()->forceFill(['label'=>'Concurrent native label'])->save();
commerceReject(fn()=>$review->run($staff,$d->id,'stop',$key,'Stale review refused'));
$key=$ticket($d,'stop');commerceReject(fn()=>$review->run($staff,$d->id,'resume',$key,'Wrong action refused'));
$other=staffUser($permissions);commerceReject(fn()=>$review->run($other,$d->id,'stop',$key,'Wrong actor refused'));
$clock->instant=$clock->now()->modify('+901 seconds');commerceReject(fn()=>$review->run($staff,$d->id,'stop',$key,'Expired review refused'));
$key=$ticket($d,'stop');$staff->role->update(['permissions'=>['admin.domains.view']]);commerceReject(fn()=>$review->run($staff,$d->id,'stop',$key,'Revoked role refused'));
$staff->role->update(['permissions'=>$permissions]);
$key=$ticket($d,'stop');commerceReject(fn()=>$review->run($staff,$d->id,'stop',$key,"Bad\nreason"));
foreach([str_repeat('é',4),str_repeat('é',501),"valid\xff"] as $bad){commerceReject(fn()=>$review->run($staff,$d->id,'stop',$ticket($d,'stop'),$bad));}
$review->run($staff,$d->id,'stop',$ticket($d,'stop'),str_repeat('🦦',500));same(str_repeat('🦦',500),$d->fresh()->renewal_policy['staff_holdings'][1]['reason'],'Multibyte reason uses characters consistently');
$review->run($staff,$d->id,'stop',$ticket($d,'stop'),'Customer stops future invoices');same(true,$d->fresh()->renewal_policy['renewals_stopped'],'Stop records future billing policy');
same(null,$billing->invoice($d->id),'Scheduled stop skips quietly');commerceReject(fn()=>$billing->invoice($d->id,$user));
commerceReject(fn()=>$cycles->complimentary($d->id,$staff,1,new Paymenter\Extensions\Servers\Domains\Data\Money('8.95','USD'),'stopped_cycle'));
same($money,$nativeMoney(),'Stop creates no invoice or financial mutation');
$review->run($staff,$d->id,'resume',$ticket($d,'resume'),'Customer resumes future invoices');same(false,$d->fresh()->renewal_policy['renewals_stopped'],'Resume records future billing policy');same('active',$s->fresh()->status,'Stop/resume never cancel registrar or native holding');
$cancel=ServiceCancellation::create(['service_id'=>$s->id,'type'=>'end_of_period','reason'=>'Native future cancellation']);
$review->run($staff,$d->id,'resume',$ticket($d,'resume'),'Resume native end-period cancellation');expect(!$cancel->fresh(),'Owned resume clears only reviewed end-period row');
// A changed cancellation after review invalidates the resume snapshot.
$key=$ticket($d,'resume');$cancel=ServiceCancellation::create(['service_id'=>$s->id,'type'=>'end_of_period','reason'=>'Concurrent stop']);
commerceReject(fn()=>$review->run($staff,$d->id,'resume',$key,'Stale cancellation snapshot'));expect($cancel->fresh()!==null,'Stale review retains cancellation');
$review->run($staff,$d->id,'resume',$ticket($d,'resume'),'Resume reviewed native cancellation');
// A retained initial intent cannot be acknowledged away, even when no native row exists.
$pending=$new('holding-intent.com',paid:false);ServiceCancellation::create(['service_id'=>$pending->service_id,'type'=>'end_of_period','reason'=>'Unpaid native cancellation']);
commerceReject(fn()=>$review->run($staff,$pending->domain_id,'resume',$ticket($pending->domain,'resume'),'Cannot erase retained intent'));
same(1,DB::table('domains_native_intents')->where('service_id',$pending->service_id)->count(),'Initial intent remains retained');
// Converter-sealed current anchors support both directions and owned repricing.
[$historical]=$prepare('holding-converted.com');same('succeeded',$reconcile->run($historical->id)->state,'Historical holding completed');
[$historical,$convertedDomain]=convertedHoldingFixture($historical);
DB::table('domains_operations')->where('id',$historical->id)->update(['state'=>'pending']);
$refused=false;try{ServiceCancellation::create(['service_id'=>$convertedDomain->service_id,'type'=>'end_of_period','reason'=>'Uncompleted converted anchor control']);}catch(Illuminate\Validation\ValidationException){$refused=true;}expect($refused,'Uncompleted converted anchor is refused');
same(0,ServiceCancellation::where('service_id',$convertedDomain->service_id)->count(),'Uncompleted converted anchor refuses before native row');
DB::table('domains_operations')->where('id',$historical->id)->update(['state'=>'succeeded']);
foreach(['stop','resume','reprice']as$action){$review->run($staff,$convertedDomain->id,$action,$ticket($convertedDomain,$action),'Review converted holding action');}
same(false,$convertedDomain->fresh()->renewal_policy['renewals_stopped'],'Converted stop can be undone');
$immediate=ServiceCancellation::create(['service_id'=>$convertedDomain->service_id,'type'=>'immediate','reason'=>'Immediate cancellation control']);
commerceReject(fn()=>$review->run($staff,$convertedDomain->id,'resume',$ticket($convertedDomain,'resume'),'Cannot resume immediate cancellation'));expect($immediate->fresh()!==null,'Immediate cancellation retained');
// Once a converted holding renews normally, its new authenticated anchor permits native EOP.
[$convertedRenew,$advancedConverted,$advancedBinding]=$makeRenewal('holding-converted-renewed.com',beforeBill:static function($holding):void {convertedHoldingFixture($holding->operations()->where('action','register')->sole());});
[$advancedEngine,$advancedWriter,$advancedCompletion]=$compose($advancedBinding);same('pending',$advancedEngine->run($convertedRenew->id,$advancedWriter)->state,'Converted holding renewal sent');
$state->expiry='2028-10-18';same('succeeded',$advancedCompletion->run($convertedRenew->id)->state,'Converted holding advances to ordinary renewal proof');
ServiceCancellation::create(['service_id'=>$advancedConverted->service_id,'type'=>'end_of_period','reason'=>'Stop converted holding after renewal']);
$review->run($staff,$advancedConverted->id,'resume',$ticket($advancedConverted,'resume'),'Resume converted holding after renewed anchor');
// Stop preserves already issued complimentary spend authority, as the modal states explicitly.
[$unpaidComp,$compDomain,$compBinding]=$makeRenewal('holding-issued-complimentary.com',paid:false);$cycles->retireUnpaid($unpaidComp->id);
$comp=$cycles->complimentary($compDomain->id,$staff,1,new Paymenter\Extensions\Servers\Domains\Data\Money('8.95','USD'),'reviewed_gift');
$review->run($staff,$compDomain->id,'stop',$ticket($compDomain,'stop'),'Stop future cycles after issued gift');
[$compEngine,$compWriter,$compCompletion]=$compose($compBinding);$compWrites=$state->writes;
same('pending',$compEngine->run($comp->id,$compWriter)->state,'Previously issued complimentary renewal still spends under stop');same($compWrites+1,$state->writes,'Exactly one synthetic complimentary write');
$state->expiry='2028-10-18';same('succeeded',$compCompletion->run($comp->id)->state,'Issued complimentary outcome completes under stop');
// Issued unpaid debt and later paid execution survive owned stop and native end-period cancellation.
[$renew,$debtDomain,$binding,$invoice]=$makeRenewal('holding-existing-debt.com',paid:false);
$issued=$nativeMoney();$review->run($staff,$debtDomain->id,'stop',$ticket($debtDomain,'stop'),'Stop only future obligations');
ServiceCancellation::create(['service_id'=>$debtDomain->service_id,'type'=>'end_of_period','reason'=>'Native end-period stop']);
// Owner requests end at the confirmed expiry, so make the manual request inside the request window.
$atExpiry=$clock->instant;$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');same($invoice->id,$billing->invoice($debtDomain->id,$user)->id,'Manual request returns existing debt while stopped');$clock->instant=$atExpiry;same($issued,$nativeMoney(),'Stop leaves issued debt byte-identical');
App\Helpers\ExtensionHelper::addPayment($invoice,null,$renew->amount);[$renewEngine,$renewWriter,$completion]=$compose($binding);
$debtWrites=$state->writes;same('pending',$renewEngine->run($renew->id,$renewWriter)->state,'Paid issued obligation still executes while future renewals stopped');same($debtWrites+1,$state->writes,'Exactly one fake renewal write');
$state->expiry='2028-10-18';same('succeeded',$completion->run($renew->id)->state,'Paid renewal completes while stopped');same(true,$debtDomain->fresh()->renewal_policy['renewals_stopped'],'Completion preserves stop flag');
$review->run($staff,$debtDomain->id,'resume',$ticket($debtDomain,'resume'),'Resume after completed renewal');same(false,$debtDomain->fresh()->renewal_policy['renewals_stopped'],'Historical acquisition proof remains usable after anchor advances');
// Original sent renewal reconciliation survives native immediate cancellation and suspension.
foreach(['immediate','suspended']as$mutation){
    [$sent,$sentDomain,$sentBinding]=$makeRenewal('holding-sent-'.$mutation.'.com');[$sentEngine,$sentWriter,$sentCompletion]=$compose($sentBinding);
    same('pending',$sentEngine->run($sent->id,$sentWriter)->state,'Original renewal sent once before native lifecycle change');$sentWrites=$state->writes;$state->expiry='2028-10-18';
    if($mutation==='immediate'){$row=ServiceCancellation::create(['service_id'=>$sentDomain->service_id,'type'=>'immediate','reason'=>'Native cancellation after send']);(new App\Listeners\CancellationCreatedListener)->handle(new App\Events\ServiceCancellation\Created($row));}
    else{$sentDomain->service->update(['status'=>'suspended']);}
    if($mutation==='immediate'){$tx=DB::table('invoice_transactions')->where('invoice_id',$sent->invoice_id)->first();DB::table('invoice_transactions')->where('id',$tx->id)->update(['amount'=>'0.00']);same('pending',$sentCompletion->run($sent->id)->state,'Post-send reconciliation still requires full signed funds');DB::table('invoice_transactions')->where('id',$tx->id)->update(['amount'=>$tx->amount]);}
    $sentDomain->fresh()->update(['status'=>'redemption']);same('pending',$sentCompletion->run($sent->id)->state,'Sent reconciliation retains registrar status eligibility');$sentDomain->fresh()->update(['status'=>'active']);
    $state->expiry='2028-10-18';same('succeeded',$sentCompletion->run($sent->id)->state,'Post-send original proof reconciles despite cancellation or suspension');
    same($sentWrites,$state->writes,'Reconciliation sends nothing');same($mutation==='immediate'?'cancelled':'suspended',$sentDomain->service->fresh()->status,'Registrar completion never rewrites native billing status');
}
// Customer renewal labels use the same stop policy, including native end-of-period rows.
require_once __DIR__.'/Support/storefront.php';require dirname(__DIR__).'/Domains/routes/owned.php';app('router')->getRoutes()->refreshNameLookups();
$ownerPresenter=new Paymenter\Extensions\Servers\Domains\Services\OwnedDomains($clock,new Paymenter\Extensions\Servers\Domains\Services\OwnerPorts);
same('Off',$ownerPresenter->present($compDomain->fresh(),false)->renewal,'Owned stop is Off on customer page');
ServiceCancellation::create(['service_id'=>$d->service_id,'type'=>'end_of_period','reason'=>'Customer renewal display control']);same('Off',$ownerPresenter->present($d->fresh(),false)->renewal,'Native future cancellation is Off on customer page');
Auth::login($staff);Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();same('Stops at end of current period (service cancellation).',DomainFacts::for($d->fresh())['Future renewals'],'Staff facts reflect native customer stop');
$review->run($staff,$d->id,'resume',$ticket($d,'resume'),'Resume customer display control');same('On',$ownerPresenter->present($d->fresh(),false)->renewal,'Resume is On on customer page');
same('On',DomainFacts::for($d->fresh())['Future renewals'],'Staff facts reflect resumed state');same('Stopped: service cancellation pending review.',DomainFacts::for($pending->domain->fresh())['Future renewals'],'Staff facts retain intent review');
// Real owned action modals and refusal/success copy, with no sensitive model form fill.
Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();
staffPanel();Auth::login($staff);$pageClass=Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class;
$page=Livewire\Livewire::actingAs($staff)->test($pageClass,['record'=>$d->id])->assertSuccessful();
foreach(['reprice','stop']as$action){$page->assertActionVisible($action)->mountAction($action)->assertActionMounted($action);$page->unmountAction();}
$stopPage=Livewire\Livewire::actingAs($staff)->test($pageClass,['record'=>$d->id])->mountAction('stop');
$stopPage->setActionData(['reason'=>'Stop through owned staff modal'])->callMountedAction()->assertNotified('Holding updated');same(true,$d->fresh()->renewal_policy['renewals_stopped'],'Actual modal invokes owned action');
$resumePage=Livewire\Livewire::actingAs($staff)->test($pageClass,['record'=>$d->id])->mountAction('resume')->assertActionMounted('resume');
$stalePage=Livewire\Livewire::actingAs($staff)->test($pageClass,['record'=>$d->id])->mountAction('resume');
$s->fresh()->forceFill(['label'=>'Changed after modal opened'])->save();$stalePage->setActionData(['reason'=>'Stale modal refusal control'])->callMountedAction()->assertNotified(Filament\Notifications\Notification::make()->danger()->title('Holding change refused')->body('This domain changed after you opened this form. Nothing was changed. Reload the page, check the current values, and try again.'))->assertSet('mountedActions',[]);
$permissionPage=Livewire\Livewire::actingAs($staff)->test($pageClass,['record'=>$d->id])->mountAction('resume')->setActionData(['reason'=>'Permission changed after review']);$beforeRevocation=$d->fresh()->getRawOriginal('renewal_policy');$staff->role->update(['permissions'=>['admin.domains.view']]);
$permissionPage->call('callMountedAction')->assertActionDoesNotExist('resume');same($beforeRevocation,$d->fresh()->getRawOriginal('renewal_policy'),'Revoked role cannot execute mounted action');$staff->role->update(['permissions'=>$permissions]);
$policy=$d->fresh()->renewal_policy;$d->fresh()->update(['renewal_policy'=>[...$policy,'staff_holdings'=>array_fill(0,100,$a)]]);
$fullPage=Livewire\Livewire::actingAs($staff)->test($pageClass,['record'=>$d->id])->mountAction('resume');$fullPage->setActionData(['reason'=>'Audit retention limit control'])->callMountedAction()->assertNotified(Filament\Notifications\Notification::make()->danger()->title('Holding change refused')->body('This domain’s holding history is full (maximum 100 changes) or unavailable. Nothing was changed. Contact the maintainer.'))->assertSet('mountedActions',[]);
$d->fresh()->update(['renewal_policy'=>$policy]);same(true,$d->fresh()->renewal_policy['renewals_stopped'],'Full history refusal changes no holding policy');
DB::table('domains_domains')->where('id',$compDomain->id)->update(['price_mode'=>'complimentary']);Livewire\Livewire::actingAs($staff)->test($pageClass,['record'=>$compDomain->id])->assertActionDoesNotExist('reprice');DB::table('domains_domains')->where('id',$compDomain->id)->update(['price_mode'=>'standard']);
$viewOnly=staffUser(['admin.domains.view']);$readonly=Livewire\Livewire::actingAs($viewOnly)->test($pageClass,['record'=>$d->id]);$readonly->assertActionDoesNotExist('stop');
Auth::login($staff);expect(str_contains(implode(' ',DomainFacts::for($d->fresh())['Change history']),'Stop through owned staff modal'),'Staff sees escaped audit history');
echo "Owned holding review, snapshots, audit, debt preservation, paid execution and real modals passed.\n";$nativeSuccessful=true;
