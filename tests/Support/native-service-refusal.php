<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic native page acceptance only.
declare(strict_types=1);
require_once dirname(__DIR__).'/native-app.php';
// Native auditing defaults on; include CLI events for this isolated acceptance matrix.
config(['audit.enabled'=>true,'audit.console'=>true]);
require __DIR__.'/operation-fixture.php';
require __DIR__.'/staff-panel.php';
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Models\{Coupon,Currency,Order,Service};
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\{DB,Queue};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Services\{HolderPricing,NativeDomainGuard,NativeIdentity,NativeServiceRefusal};
Queue::fake();
$guard=new NativeDomainGuard(new HolderPricing($prices),$clock);
app()->instance(Paymenter\Extensions\Servers\Domains\Services\InvoiceBinding::class,$invoiceBinding);
if($guardFirst){$guard->boot();NativeIdentity::boot();}else{NativeIdentity::boot();$guard->boot();}
staffPanel();
$staff=staffUser(['admin.domains.view','admin.services.viewAny','admin.services.view','admin.services.update','admin.services.delete']);
$op=$new(placeholderComFixtureName('native-service-refusal-placeholder.com'));$service=$op->domain->service;
$before=static function(): array {
    $rows=[];foreach(['services','orders','properties','domains_domains','domains_operations','domains_accounts','settings','invoices','invoice_items','invoice_transactions','jobs','audits'] as $table){$rows[$table]=DB::table($table)->orderBy('id')->get()->toJson();}return $rows;
};
$mount=static function()use($staff,$service){session()->forget('filament.notifications');return Livewire::actingAs($staff)->test(EditService::class,['record'=>$service->id])->assertSuccessful();};
$priceNotice=Notification::make()->danger()->title('Price change refused')->body('Domain prices cannot be changed here. They follow TLD prices, and the next renewal invoice uses the price on its issue date. This service was not saved.');
$identityNotice=Notification::make()->danger()->title('Service change refused')->body('Product, plan, user, quantity, currency and coupon are fixed for managed domains. This service was not saved.');
$deleteNotice=Notification::make()->danger()->title('Service could not be deleted')->body('Managed domain services are retained. To stop renewal or retire an unpaid order, use the Domains page.');
$capture=static function($page,string $name)use($refusalCapture):void {if($refusalCapture!==null){$refusalCapture($page,$name);}};
$noticeTitles=static fn():array=>array_column(session()->get('filament.claimed_notifications')??session()->get('filament.notifications')??[],'title');
$noSuccess=static function(string $title)use($noticeTitles):void {expect(!in_array($title,$noticeTitles(),true),'Refused operation must not send '.$title);};
$snapshot=$before();
$page=$mount()->fillForm(['price'=>'99.00','label'=>'Must not save with price'])->call('save')->assertSuccessful()->assertHasNoFormErrors();
$page->assertSet('data.price','99.00')->assertSet('data.label','Must not save with price')->assertSet('mountedActions',[]);
$capture($page,'price');$noSuccess('Saved');$page->assertNotified($priceNotice);same($snapshot,$before(),'Price refusal saves none of the native or retained state');
// The native hint only changes draft form state and sends its own success notice. Save is the write boundary.
$page=$mount()->callAction(TestAction::make('Recalculate Price')->schemaComponent('price','form'))->assertSuccessful()->assertHasNoActionErrors();
expect(in_array('The price has been successfully recalculated',$noticeTitles(),true),'Native draft-only hint retains its success notice');
$recalculated=$service->fresh()->calculatePrice();same($recalculated,number_format((float)$page->get('data.price'),2,'.',''),'Real native hint updates draft to acquisition-plan price');
expect($recalculated!==number_format((float)$service->price,2,'.',''),'Fixture has distinct acquisition and holding prices');
same($snapshot,$before(),'Native Recalculate Price alone writes nothing');
$page->fillForm(['label'=>'Must not save after recalculate'])->call('save')->assertSuccessful()->assertHasNoFormErrors();
$capture($page,'recalculate-save');$noSuccess('Saved');$page->assertNotified($priceNotice);same($snapshot,$before(),'Saving recalculated price is handled and changes nothing');
$hostingProduct=commerceHosting();$otherUser=commerceUser();
$otherPlan=$service->product->plans()->where('billing_period',3)->sole();
$otherCurrency=Currency::create(['code'=>'EUR','name'=>'Euro','format'=>'1,000.00','prefix'=>'€','suffix'=>'']);
$coupon=Coupon::create(['code'=>'SYNTHETIC-REFUSAL','type'=>'fixed','value'=>1,'recurring'=>0]);
foreach(['product_id'=>$hostingProduct->id,'plan_id'=>$otherPlan->id,'user_id'=>$otherUser->id,'quantity'=>2,'currency_code'=>$otherCurrency->code,'coupon_id'=>$coupon->id] as $field=>$value){
    $snapshot=$before();$changes=[$field=>$value,'label'=>'Must not save with '.$field];if($field==='product_id'){$changes['plan_id']=$hostingProduct->plans()->first()->id;}
    $page=$mount()->fillForm($changes)->call('save')->assertSuccessful()->assertHasNoFormErrors();
    if($field==='product_id'){$capture($page,'identity');}
    $noSuccess('Saved');$page->assertNotified($identityNotice);same($snapshot,$before(),'Identity refusal changes nothing: '.$field);
}
$snapshot=$before();$page=$mount()->callAction('delete')->assertSuccessful()->assertActionNotMounted('delete')->assertSet('mountedActions',[]);
$capture($page,'delete');$noSuccess('Deleted');$page->assertNotified($deleteNotice);same($snapshot,$before(),'Native delete refusal retains all state and processing');
// Active native Delete first invokes the real Domains no-op and records its native audit.
DB::table('services')->where('id',$service->id)->update(['status'=>'active']);
$page=$mount();Queue::fake();$snapshot=$before();$auditCount=$service->audits()->count();
$page->callAction('delete',data:['deleteExtensionServer'=>true])->assertSuccessful()->assertActionNotMounted('delete')->assertSet('mountedActions',[]);
same(['Service could not be deleted'],$noticeTitles(),'Active Delete has only the handled refusal, with no extension error or success');
$page->assertNotified($deleteNotice);
// Livewire termination replays the fixture checkout's existing after-response callbacks.
// Permit only those exact native closures; refuse any new workflow/registrar job.
foreach(Queue::pushedJobs() as $class=>$jobs){
    same(\Illuminate\Queue\CallQueuedClosure::class,$class,'Delete queues no workflow job');
    foreach($jobs as $pushed){$source=(new ReflectionFunction($pushed['job']->closure->getClosure()))->getFileName();
        expect(in_array($source,[$fixture.'/app/Observers/OrderObserver.php',$fixture.'/app/Observers/InvoiceObserver.php'],true),'Only existing native checkout callbacks replay');}
}
$after=$before();unset($snapshot['audits'],$after['audits']);same($snapshot,$after,'Active Delete retains service, domain, finance, jobs and processing');
same($auditCount+1,$service->audits()->count(),'Native terminate audit is retained before refusal');
$audit=$service->audits()->latest('id')->first();same('extension_action',$audit->event,'Native extension audit event');
same(['action'=>'terminate_server'],$audit->new_values,'Native audit records the real terminate step');same($staff->id,(int)$audit->user_id,'Native audit retains staff actor');
DB::table('services')->where('id',$service->id)->update(['status'=>'pending']);
// Non-UI exceptions keep both their type and exact original message, for either observer order.
$original=$guardFirst?'Managed service binding is permanent.':'This change affects managed domain identity and was refused.';
$reject=static function(callable $write,string $message):void {
    try{$write();expect(false,'Non-UI writer must throw');}catch(DomainException $e){same(DomainException::class,get_class($e),'Original exception type');same($message,$e->getMessage(),'Original exception text');}
};
foreach(['product_id'=>$hostingProduct->id,'plan_id'=>$otherPlan->id,'user_id'=>$otherUser->id,'order_id'=>999999,'quantity'=>2,'currency_code'=>'EUR','coupon_id'=>$coupon->id,'price'=>'99.00'] as $field=>$value){
    $reject(fn()=>$service->fresh()->update([$field=>$value]),$field==='price'&&$guardFirst?'Managed pricing authority requires a dedicated audited transition.':$original);
}
$reject(fn()=>$service->fresh()->delete(),$guardFirst?'Managed holding services are retained.':$original);
// A different Livewire page must not convert the original exception to a handled Halt.
class OtherNativeServiceWriter extends \Livewire\Component
{
    public string $result='';
    public int $target=0;
    public string $operation='save';
    public function getRecord():Service {return Service::findOrFail($this->target);}
    public function getMountedAction():?\Filament\Actions\Action {return $this->operation==='delete'?\Filament\Actions\Action::make('delete'):null;}
    public function probe(int $id,string $operation):void
    {$this->target=$id;$this->operation=$operation;try{if($operation==='delete'){Service::findOrFail($id)->delete();}else{Service::findOrFail($id)->update(['price'=>'99.00']);}}catch(DomainException $e){$this->result=get_class($e).': '.$e->getMessage();}}
    public function render(){return '<div>Unrelated native writer fixture</div>';}
}
foreach(['save','delete'] as $operation){
    $message=$guardFirst?($operation==='delete'?'Managed holding services are retained.':'Managed pricing authority requires a dedicated audited transition.'):$original;
    Livewire::test(OtherNativeServiceWriter::class)->call('probe',$service->id,$operation)->assertSuccessful()->assertSet('result',DomainException::class.': '.$message)->assertNotNotified();
}
// Probe the real model writer while a native editor has a different record or action mounted.
class NativeServiceScopeProbe extends EditService
{
    public string $result='';
    public function probe(int $id,string $operation):void
    {try{if($operation==='delete'){Service::findOrFail($id)->delete();}else{Service::findOrFail($id)->update(['price'=>'99.00']);}}catch(DomainException $e){$this->result=get_class($e).': '.$e->getMessage();}}
}
$order=Order::findOrFail(DB::table('orders')->insertGetId(['user_id'=>$user->id,'currency_code'=>'USD','created_at'=>now(),'updated_at'=>now()]));
$hosting=Service::create(['order_id'=>$order->id,'user_id'=>$user->id,'product_id'=>$hostingProduct->id,'plan_id'=>$hostingProduct->plans()->first()->id,'quantity'=>1,'price'=>'10.00','currency_code'=>'USD','status'=>'pending']);
$priceError=$guardFirst?'Managed pricing authority requires a dedicated audited transition.':$original;
$deleteError=$guardFirst?'Managed holding services are retained.':$original;
foreach(['save'=>$priceError,'delete'=>$deleteError] as $operation=>$message){
    Livewire::actingAs($staff)->test(NativeServiceScopeProbe::class,['record'=>$hosting->id])->call('probe',$service->id,$operation)->assertSuccessful()->assertSet('result',DomainException::class.': '.$message)->assertNotNotified();
    Livewire::actingAs($staff)->test(NativeServiceScopeProbe::class,['record'=>$service->id])->mountAction('changeStatus')->call('probe',$service->id,$operation)->assertSuccessful()->assertSet('result',DomainException::class.': '.$message)->assertNotNotified();
}
// Save while Delete is mounted and delete without that action must also retain the exception.
Livewire::actingAs($staff)->test(NativeServiceScopeProbe::class,['record'=>$service->id])->mountAction('delete')->call('probe',$service->id,'save')->assertSuccessful()->assertSet('result',DomainException::class.': '.$priceError)->assertNotNotified();
Livewire::actingAs($staff)->test(NativeServiceScopeProbe::class,['record'=>$service->id])->call('probe',$service->id,'delete')->assertSuccessful()->assertSet('result',DomainException::class.': '.$deleteError)->assertNotNotified();
Livewire::actingAs($staff)->test(EditService::class,['record'=>$hosting->id])->fillForm(['quantity'=>2,'price'=>'12.00','label'=>'Ordinary hosting save'])->call('save')->assertSuccessful()->assertHasNoFormErrors()->assertNotified('Saved');
same(2,$hosting->fresh()->quantity,'Native hosting identity save continues');same('12.00',number_format((float)$hosting->fresh()->price,2,'.',''),'Native hosting price save continues');
Livewire::actingAs($staff)->test(EditService::class,['record'=>$hosting->id])->callAction('delete')->assertSuccessful();
expect(!Service::whereKey($hosting->id)->exists(),'Native hosting delete continues');
same(null,NativeServiceRefusal::missing(),'Required scoped feedback capabilities available');
echo 'Native service refusal matrix passed ('.($guardFirst?'holding':'identity').' guard first).'."\n";$nativeSuccessful=true;
