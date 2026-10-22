<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/reconciliation-fixture.php';
require __DIR__.'/Support/staff-panel.php';
use App\Models\{InvoiceTransaction,Service,ServiceCancellation};
use Illuminate\Support\Facades\{Auth,DB,Queue,View};
use Paymenter\Extensions\Servers\Domains\Services\{HolderPricing,HoldingLocks,InvoiceFunding,NativeCancellation,NativeDomainGuard,NativeExpiry,NativeIdentity,NativeIntentSchema,RenewalAuthority};
use Paymenter\Extensions\Servers\Domains\Admin\Support\NativeEditorNotice;
Queue::fake();NativeIntentSchema::install();
// Guard-first boot used to clear numeric model attrs while the SQL extras stayed non-null.
$guard=new NativeDomainGuard(new HolderPricing($prices),$clock);$guard->boot();
app()->instance(Paymenter\Extensions\Servers\Domains\Services\InvoiceBinding::class,$invoiceBinding);
NativeIdentity::boot();NativeCancellation::boot();NativeCancellation::boot();
function lifecycleReject(callable $call,string $message=NativeCancellation::MESSAGE): void
{
    $caught=false;try{$call();}catch(Illuminate\Validation\ValidationException $e){same($message,$e->errors()['reason'][0],'Safe customer and staff refusal');$caught=true;}
    expect($caught,'Expected scoped lifecycle refusal');
}
$paid=$new(placeholderComFixtureName('lifecycle-paid-placeholder.com'));$s=$paid->domain->service;
foreach(['immediate','end_of_period']as$type){
    $before=ServiceCancellation::count();lifecycleReject(fn()=>ServiceCancellation::create(['service_id'=>$s->id,'type'=>$type,'reason'=>'Synthetic request']));
    same($before,ServiceCancellation::count(),'Refused before native created/listener side effects');
}
foreach(['suspended','cancelled']as$status){lifecycleReject(fn()=>$s->fresh()->update(['status'=>$status]));}
lifecycleReject(fn()=>$s->fresh()->increment('id',0,['STATUS'=>'cancelled']));
$statusAlias=Service::selectRaw('id, ? as STATUS, status',['cancelled'])->findOrFail($s->id);
lifecycleReject(fn()=>$statusAlias->increment('id',0,['STATUS'=>'cancelled']));
$statusAlias=Service::selectRaw("id, 'cancelled' as status, status as STATUS")->findOrFail($s->id);
lifecycleReject(fn()=>$statusAlias->increment('id',0,['status'=>'cancelled']));
lifecycleReject(fn()=>(new ServiceCancellation)->forceFill(['SERVICE_ID'=>$s->id,'type'=>'immediate','reason'=>'Alias control'])->save());
same('pending',$s->fresh()->status,'Unsafe statuses did not persist');
same(0,DB::table('domains_native_intents')->count(),'Refusal records no cancellation intent');
$s->fresh()->increment('id',0,['label'=>'Numeric control']);
same('Numeric control',$s->fresh()->label,'Numeric managed update without expiry extras succeeds');
$s->fresh()->decrement('id',0);same(null,$s->fresh()->expires_at,'Bare numeric update succeeds');
foreach(['increment','decrement']as$method){
    $caught=false;try{$s->fresh()->$method('id',0,['expires_at'=>'2030-01-01']);}catch(DomainException $e){same(NativeExpiry::NUMERIC_MESSAGE,$e->getMessage(),'Explicit numeric expiry refusal');$caught=true;}
    expect($caught,'Numeric extra refused even with billing guard booted first');same(null,$s->fresh()->expires_at,'Numeric date never stored');
}
$alias=Service::selectRaw('id, expires_at as EXPIRES_AT, expires_at')->findOrFail($s->id);
$caught=false;try{$alias->increment('id',0,['EXPIRES_AT'=>'2030-01-01']);}catch(DomainException $e){$caught=$e->getMessage()===NativeExpiry::NUMERIC_MESSAGE;}
expect($caught,'Canonical null cannot hide an expiry alias in numeric SQL extras');
$alias=Service::selectRaw("id, '2030-01-01 00:00:00' as expires_at, expires_at as EXPIRES_AT")->findOrFail($s->id);
$caught=false;try{$alias->increment('id',0,['expires_at'=>'2030-01-01 00:00:00']);}catch(DomainException $e){$caught=$e->getMessage()===NativeExpiry::NUMERIC_MESSAGE;}
expect($caught,'Clean projected canonical expiry cannot hide behind a null alias');
$s->fresh()->update(['expires_at'=>'2030-01-01','label'=>'Staff save']);same(null,$s->fresh()->expires_at,'Ordinary staff-equivalent save clears expiry');
(new App\Services\Service\RenewServiceService)->handle($s->fresh());
same('active',$s->fresh()->status,'Vanilla paid Active write succeeds with paid in-flight acquisition');
same(null,$s->fresh()->expires_at,'Vanilla paid loop date discarded');
// Unpaid native cancellation and cron-style status remain allowed, with durable intent.
$unpaid=$new(placeholderComFixtureName('lifecycle-unpaid-placeholder.com'),paid:false);
$c=ServiceCancellation::create(['service_id'=>$unpaid->service_id,'type'=>'immediate','reason'=>'Unpaid request']);
(new App\Listeners\CancellationCreatedListener)->handle(new App\Events\ServiceCancellation\Created($c));
same('cancelled',$unpaid->domain->service->fresh()->status,'Unpaid native listener succeeds');
$cron=$new(placeholderComFixtureName('lifecycle-cron-placeholder.com'),paid:false);
DB::transaction(fn()=>$cron->domain->service->update(['status'=>'cancelled']));
same('cancelled',$cron->domain->service->fresh()->status,'Unpaid status inside native outer transaction succeeds');
// A terminal label alone is insufficient, while original funded reconciliation proof is enough.
$fake=$new(placeholderComFixtureName('lifecycle-fake-terminal-placeholder.com'));DB::table('domains_operations')->where('id',$fake->id)->update(['state'=>'succeeded']);
lifecycleReject(fn()=>ServiceCancellation::create(['service_id'=>$fake->service_id,'type'=>'end_of_period','reason'=>'Forged terminal label']));
[$complete]=$prepare(placeholderComFixtureName('lifecycle-completed-placeholder.com'));same('succeeded',$reconcile->run($complete->id)->state,'Original completion proof established');
ServiceCancellation::create(['service_id'=>$complete->service_id,'type'=>'end_of_period','reason'=>'Stop future renewal']);
same('active',$complete->domain->service->fresh()->status,'Terminal end-of-period cancellation does not delete or cancel registrar/native holding');
require_once __DIR__.'/Support/converted-holding.php';
[$converted]=$prepare(placeholderComFixtureName('lifecycle-converted-placeholder.com'));same('succeeded',$reconcile->run($converted->id)->state,'Historical source completed');
[$converted,$convertedDomain,$convertedBinding]=convertedHoldingFixture($converted);
expect(RenewalAuthority::completed($converted,$convertedDomain,$convertedBinding),'Converted renewal has full sealed terminal proof');
ServiceCancellation::create(['service_id'=>$converted->service_id,'type'=>'end_of_period','reason'=>'Converted holding future stop']);
same(0,DB::table('domains_native_intents')->where('service_id',$converted->service_id)->count(),'Proven converted holding needs no acquisition intent');
$attempt=$converted->attempt;$attempt['converted_completion']['mac']=str_repeat('0',64);$converted->attempt=$attempt;
DB::table('domains_operations')->where('id',$converted->id)->update(['attempt'=>$converted->getAttributes()['attempt']]);
lifecycleReject(fn()=>ServiceCancellation::create(['service_id'=>$converted->service_id,'type'=>'end_of_period','reason'=>'Forged converted proof']));
// Pause after creating guard, before SQL. Payment and real engine execution see the committed
// owned intent even though no native cancellation row exists yet. Transport is synthetic only.
$race=$new(placeholderComFixtureName('lifecycle-race-placeholder.com'),paid:false);$armed=true;$calls=$writer->calls;
ServiceCancellation::creating(static function(ServiceCancellation $row)use($race,&$armed,$engine,$writer,$calls):void {
    if(!$armed||(int)$row->service_id!==(int)$race->service_id){return;}$armed=false;
    same(0,DB::transactionLevel(),'No observer-owned transaction spans creating/created');
    same(0,ServiceCancellation::where('service_id',$race->service_id)->count(),'Native SQL has not run');
    DB::table('invoices')->where('id',$race->invoice_id)->update(['status'=>'paid']);
    InvoiceTransaction::create(['invoice_id'=>$race->invoice_id,'amount'=>$race->amount,'fee'=>'0.00','status'=>'succeeded']);
    (new App\Services\Service\RenewServiceService)->handle(Service::findOrFail($race->service_id));
    $engine->execute($race->id);same($calls,$writer->calls,'Intent blocks claim/seal/consume before native INSERT');
});
ServiceCancellation::create(['service_id'=>$race->service_id,'type'=>'end_of_period','reason'=>'Race control']);
// Failed native INSERT retains intent. No timeout or staff acknowledgement can erase it.
$failed=$new(placeholderComFixtureName('lifecycle-failed-insert-placeholder.com'),paid:false);$fail=true;
ServiceCancellation::creating(static function(ServiceCancellation $row)use($failed,&$fail):void {
    if($fail&&(int)$row->service_id===(int)$failed->service_id){$fail=false;throw new RuntimeException('Synthetic later callback failure');}
});
try{ServiceCancellation::create(['service_id'=>$failed->service_id,'type'=>'end_of_period','reason'=>'Failure control']);}catch(RuntimeException $e){same('Synthetic later callback failure',$e->getMessage(),'Expected diagnostic failure');}
same(0,DB::transactionLevel(),'Exception leaks no transaction');
same(1,DB::table('domains_native_intents')->where('service_id',$failed->service_id)->count(),'Failure retains durable protection');
DB::transaction(function()use($failed):void {$l=HoldingLocks::take($failed->domain);expect($l['cancel']->contains('service_id',$failed->service_id),'All owned holding authority sees intent');});
// Unrelated hosting stays native, including expiry and cancellation.
$host=commerceHosting();$order=App\Models\Order::findOrFail(DB::table('orders')->insertGetId(['user_id'=>$user->id,'currency_code'=>'USD','created_at'=>now(),'updated_at'=>now()]));
$hosting=Service::create(['order_id'=>$order->id,'user_id'=>$user->id,'product_id'=>$host->id,'plan_id'=>$host->plans()->first()->id,'quantity'=>1,'price'=>'10.00','currency_code'=>'USD','status'=>'pending']);
$hosting->update(['expires_at'=>'2030-01-01','status'=>'suspended']);expect($hosting->fresh()->expires_at!==null,'Hosting date unchanged');
$hostingCancel=ServiceCancellation::create(['service_id'=>$hosting->id,'type'=>'end_of_period','reason'=>'Hosting remains native']);
lifecycleReject(fn()=>$hostingCancel->update(['service_id'=>$s->id]));
same($hosting->id,$hostingCancel->fresh()->service_id,'Ordinary hosting cancellation reassignment onto paid domain refused');
$hostingCancel->fresh()->update(['reason'=>'Ordinary hosting update']);
same('Ordinary hosting update',$hostingCancel->fresh()->reason,'Unrelated ordinary cancellation update succeeds');
// Explicit idempotent cleanup command; validation changes nothing and never clears hosting.
DB::table('services')->where('id',$s->id)->update(['expires_at'=>'2030-01-01']);
$command=new Paymenter\Extensions\Servers\Domains\Console\ClearNativeExpiry;
$console=new Symfony\Component\Console\Application;$console->add($command);$command->setLaravel(app());
$tester=new Symfony\Component\Console\Tester\CommandTester($command);
same(1,$tester->execute([]),'Validation reports retained dates');expect($s->fresh()->expires_at!==null,'Validation is read-only');
same(0,$tester->execute(['--clear'=>true]),'Explicit cleanup succeeds');same(null,$s->fresh()->expires_at,'Cleanup clears routed date');
same(0,$tester->execute(['--clear'=>true]),'Cleanup idempotent');expect($hosting->fresh()->expires_at!==null,'Cleanup excludes hosting');
staffPanel();Auth::login(staffUser(['admin.domains.view']));View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
$html=NativeEditorNotice::render($s->fresh());expect(str_contains(strip_tags($html),'This is a domain; manage it on the Domains page.'),'Scoped notice exact copy');
expect(str_contains($html,'/domains/'.$paid->domain_id),'Notice links owned domain');same('',NativeEditorNotice::render($hosting),'No hosting notice');
NativeEditorNotice::boot();expect(Filament\Support\Facades\FilamentView::hasRenderHook(Filament\View\PanelsRenderHook::PAGE_START,[App\Admin\Resources\ServiceResource\Pages\EditService::class]),'Public native Edit Service render hook registered');
$staff=staffUser(['admin.domains.view','admin.services.view','admin.services.viewAny','admin.services.update']);
$edit=Livewire\Livewire::actingAs($staff)->test(App\Admin\Resources\ServiceResource\Pages\EditService::class,['record'=>$s->id])->assertSuccessful();
expect(str_contains(strip_tags($edit->html()),'This is a domain; manage it on the Domains page.'),'Actual native editor renders notice text and link');
$edit->call('save')->assertHasFormErrors(['expires_at']); // Native Active form validation remains unchanged.
expect(str_contains($edit->html(),'Paymenter asks for an expiry date before it saves. Enter any date: Domains clears it on save and shows the expiry reported by the registrar on the Domains page.'),'Native notice explains expiry behavior');
$edit->fillForm(['expires_at'=>'2030-01-01','label'=>'Actual staff save'])->call('save')->assertHasNoFormErrors();
same(null,$s->fresh()->expires_at,'Actual native staff form clears expiry');
$edit->fillForm(['status'=>'cancelled'])->call('save')->assertHasFormErrors(['status'])->assertSee(NativeCancellation::MESSAGE);
same('active',$s->fresh()->status,'Actual staff refusal preserves paid status');
$hostPage=Livewire\Livewire::actingAs($staff)->test(App\Admin\Resources\ServiceResource\Pages\EditService::class,['record'=>$hosting->id]);
expect(!str_contains(strip_tags($hostPage->html()),'This is a domain; manage it on the Domains page.'),'Native hosting editor has no domain notice');
echo "Native lifecycle expiry/cancellation/intent ordering/terminal proof/hosting/console/notice controls passed.\n";
$nativeSuccessful=true;
