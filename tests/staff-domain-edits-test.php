<?php
// Staff edits on the domain page: the owner's form path and checks, credited to the staff member.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management.php';
require __DIR__.'/Support/owner-pages.php';
require __DIR__.'/Support/staff-panel.php';
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers\RequestsRelationManager;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainEditActions,DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainManagement,DomainObservation,ManagementForms,ObservationSchema,OwnedDomains,OwnerPorts};
staffPanel();storefrontViews();
[$d,$driver]=$holding(placeholderComFixtureName('staff-edit-placeholder.com'));
$ports=new OwnerPorts(fn($b)=>$driver);$owners=new OwnedDomains($clock,$ports,$management);
app()->instance(OwnerPorts::class,$ports);app()->instance(OwnedDomains::class,$owners);app()->instance(DomainManagement::class,$management);
$staff=staffUser([Staff::VIEW,'admin.domains.manage']);$staff->update(['first_name'=>'Sam','last_name'=>'Support']);$actor=$staff->fresh();Auth::setUser($actor);
$view=static fn()=>Livewire::actingAs($actor)->test(ViewDomain::class,['record'=>$d->id]);
$view()->assertSee('Email auth code');
$open=static fn(string $row,string $action)=>$view()->mountAction(TestAction::make('edit_'.$action)->schemaComponent('fact_'.md5($row),'infolist'));
$edit=static fn(string $row,string $action,array $data=[])=>$open($row,$action)->setActionData($data)->callMountedAction();
$last=static fn(string $action)=>$d->operations()->where('action',$action)->orderByDesc('id')->first();
$finish=static function(Operation $op)use($management,$driver):void{Illuminate\Support\Facades\Cache::flush();same('succeeded',$management->run($op->id,$driver)->state,'Staff '.$op->action.' uses the same queue and readback');};

// Corrupt encrypted storage must not take down staff pages or either customer projection.
// These real native models/Livewire pages exercise casts and action construction, not a mocked read.
$pages=[\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup::class,\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class,\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarBalances::class,\Paymenter\Extensions\Servers\Domains\Admin\Pages\ActivationStatus::class,\Paymenter\Extensions\Servers\Domains\Admin\Pages\PublicSuffixList::class];
$panel=\Filament\Facades\Filament::getCurrentPanel();$panel->pages($pages);
\Illuminate\Support\Facades\Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($panel,$pages){foreach($pages as $page){$page::registerRoutes($panel);}});app('router')->getRoutes()->refreshNameLookups();
\Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::install();\Paymenter\Extensions\Servers\Domains\Services\BalanceAlertSchema::install();ObservationSchema::install();
$hot=storage_path('framework/domains-assets-hot');file_put_contents($hot,'http://assets.example.test');app(\Illuminate\Foundation\Vite::class)->useHotFile($hot);
$unreadable=[];$check=static function(string $label,\Closure $run)use(&$unreadable):void{try{$run();}catch(\Throwable $e){$unreadable[$label]=$e::class.': '.$e->getMessage();fwrite(STDERR,$label.': '.$unreadable[$label]."\n");}};
$stored=$d->fresh()->getRawOriginal('renewal_policy');DB::table('domains_domains')->where('id',$d->id)->update(['renewal_policy'=>'batch6-explicit-unreadable-fixture']);
$check('staff domain',function()use($view,$d):void{$view()->assertSuccessful()->assertSee(DomainFacts::UNREADABLE);same([],\Paymenter\Extensions\Servers\Domains\Admin\Support\HoldingActions::make($d->fresh()),'Unreadable policy offers no stop or resume');});
$check('registrar account',fn()=>Livewire::actingAs($actor)->test(\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class,['account'=>$d->server_id])->assertSuccessful()->assertSee(DomainFacts::UNREADABLE_RENEWAL));
$customer=$d->service->user;$presenter=new \Paymenter\Extensions\Servers\Domains\Services\NativePresenter($owners,$native,new \Paymenter\Extensions\Servers\Domains\Services\HolderPricing($prices));
// /dashboard calls this exact native service DTO adapter before rendering its renewal strip.
$check('customer dashboard',function()use($presenter,$customer,$d):void{$dto=$presenter->services($customer,[$d->service_id])[$d->service_id];expect($dto->renewal!=='On'&&$dto->renewNow===[]&&$dto->autoRenew===[]&&!$dto->autoPay,'Unreadable policy cannot offer renewal');});
$check('customer /domains',function()use($customer,$d):void{Auth::setUser($customer);config(['settings.default_currency'=>'USD']);$request=\Illuminate\Http\Request::create('/domains');$request->setUserResolver(fn()=>$customer);expect(str_contains(app(\Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController::class)->index($request)->render(),$d->domain),'Customer list retains unreadable domain');});
DB::table('domains_domains')->where('id',$d->id)->update(['renewal_policy'=>$stored]);Auth::setUser($actor);
$observation=$d->fresh()->getRawOriginal('observation');DB::table('domains_domains')->where('id',$d->id)->update(['observation'=>'batch6-explicit-unreadable-fixture']);
$check('staff unreadable observation',fn()=>$view()->assertSuccessful()->assertSee(DomainFacts::UNREADABLE));DB::table('domains_domains')->where('id',$d->id)->update(['observation'=>$observation]);
$acquisition=$d->operations()->whereIn('action',['register','transfer'])->orderByDesc('id')->firstOrFail();$payload=$acquisition->getRawOriginal('payload');DB::table('domains_operations')->where('id',$acquisition->id)->update(['payload'=>'batch6-explicit-unreadable-fixture']);
$check('staff unreadable contacts',function()use($view,$d):void{$view()->assertSuccessful()->assertSee(DomainFacts::UNREADABLE);expect(!isset(DomainEditActions::for($d->fresh())['rows']['Contacts']),'No contact edit without a current digest');});DB::table('domains_operations')->where('id',$acquisition->id)->update(['payload'=>$payload]);
same([],$unreadable,'Unreadable saved data renders without granting an edit or renewal');

// Nameservers: the owner's validation and messages, then one credited request.
$before=Operation::count();$edit('Nameservers','nameservers',['nameservers'=>'ns1.example.com.'])->assertNotified("Couldn't save this change");
same($before,Operation::count(),'Rejected nameservers queue nothing');
$edit('Nameservers','nameservers',['nameservers'=>"NS5.example.com.\nns6.example.com"])->assertNotified(ManagementForms::REQUESTED);
$op=$last('nameservers');same('succeeded',$op->state,'Staff response termination starts and confirms the saved edit');same((int)$actor->id,(int)$op->authorized_by,'Credited to the staff member');
same(['ns5.example.com','ns6.example.com'],$op->payload['nameservers'],'Same normalization as the owner form');
// Real queued free changes must not offer purchase controls or an inapplicable status read.
// Native Filament visibility is the boundary; a pure predicate test would miss permission/action wiring.
$op=$management->request($actor,$d->id,'nameservers',$op->payload,bin2hex(random_bytes(32)),$driver);
$permissions=$actor->role->permissions;
$actor->role->update(['permissions'=>[...$permissions,'admin.invoices.update',...array_map(
    \Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::permission(...),
    array_keys(\Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::ACTIONS))]]);
commerceReject(fn()=>\Paymenter\Extensions\Servers\Domains\Services\StaffOperationWrites::hold($op,[]));
foreach(['queued','held','rejected'] as $stateName){
    $op->update(['state'=>$stateName]);
    $request=Livewire::actingAs($actor)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation::class,['record'=>$op->id]);
    foreach(['hold','release','refund','credit','replacement','recheck','resume_reads'] as $action){$request->assertActionHidden($action);}
    if($stateName==='queued'){$request->assertDontSee('More');}
}
$op->update(['state'=>'queued']);$actor->role->update(['permissions'=>$permissions]);
// A sent request with no answer yet blocks the next edit, as it does for the owner, and the page says so.
$op->update(['state'=>'uncertain']);$busy=DomainEditActions::for($d->fresh());same([],$busy['rows'],'An unresolved request blocks the next edit');$view()->assertSee(ManagementForms::REQUESTED)->assertSee('wire:poll.2s',false);$op->update(['state'=>'queued']);
$finish($op);

// History names the staff member, and the customer sees Staff.
Livewire::actingAs($actor)->test(RequestsRelationManager::class,['ownerRecord'=>$d->fresh(),'pageClass'=>ViewDomain::class])->assertSee('Sam Support');
$row=collect($owners->present($d->fresh())->progress)->firstWhere('label','Nameservers');same('Staff',$row['by']??null,'Customer history says Staff');

// Lock: the owner's warning and the same request.
$open('Transfer lock','lock')->assertMountedActionModalSee(ManagementForms::lockRisk($d->domain));
$edit('Transfer lock','lock',['locked'=>'0'])->assertNotified(ManagementForms::REQUESTED);$op=$last('lock');same(false,$op->payload['locked'],'Staff lock change reaches the request');$finish($op);

// Contacts: one contact for every role, with the owner's warning and messages.
$open('Contacts','contacts')->assertMountedActionModalSee(ManagementForms::CONTACTS_RISK);
$saved=$owners->present($d->fresh())->management['contacts']['saved'];
$count=$d->operations()->where('action','contacts')->count();
$edit('Contacts','contacts',[...$saved,'email_address'=>'not-an-email'])->assertNotified("Couldn't save this change");
same($count,$d->operations()->where('action','contacts')->count(),'Rejected contact queues nothing');
// A modal opened before a completed contact change must not restore the old owner email.
$staleContacts=$open('Contacts','contacts');
$newContact=[...$saved,'first_name'=>'Changed','email_address'=>'new-owner@example.com'];
$freshContacts=$open('Contacts','contacts')->setActionData($newContact);$contactReplay=clone $freshContacts;
$freshContacts->callMountedAction()->assertNotified(ManagementForms::REQUESTED);
$op=$last('contacts');same(['all',$newContact],[$op->payload['role'],$op->payload['contact']],'Only editable contact fields reach the request');$finish($op);
$before=Operation::count();$contactReplay->callMountedAction()->assertNotified(ManagementForms::REQUESTED);
same($before,Operation::count(),'A finished contact save stays idempotent after saved contacts change');
$staleContacts->callMountedAction();same($before,Operation::count(),'An old contact modal cannot queue the former owner email');
$staleContacts->assertNotified(Filament\Notifications\Notification::make()->danger()->title("Couldn't save this change")->body('This changed after you opened it. Reload the page and try again.'));

// Registrar record forms need observed registrar delegation, including after a nameserver edit.
$registrarDns=static function()use($edit,$last,$finish):void {
    $edit('Nameservers','nameservers',['nameservers'=>"dns1.name-services.com\ndns2.name-services.com"])->assertNotified(ManagementForms::REQUESTED);$finish($last('nameservers'));
};
$registrarDns();
// DNS and SRV: the owner's replacement list, and a row keeps its place in it.
expect(!isset(DomainFacts::for($d->fresh())['DNS records']),'No DNS row while DNS tools are off');
App\Models\Server::findOrFail($d->server_id)->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['value'=>'1']);
expect(!isset(DomainEditActions::for($d->fresh())['rows']['DNS records']),'Unread records offer no replacement form');
same(null,DomainFacts::for($d->fresh())['DNS records']['value'],'Unread records are Missing');
ObservationSchema::install();
same('refreshed',(new DomainObservation($readBindings,$clock,new AccountReadBudget($clock),$ports))->run($d->id),'Complete empty records');
same('0 records',DomainFacts::for($d->fresh())['DNS records']['value'],'Registration card counts DNS records');
same('Turn on',DomainEditActions::for($d->fresh())['rows']['Transfer lock'][0]->getLabel(),'A known lock state offers its one next step');
$view()->callAction(TestAction::make('edit_lock')->schemaComponent('fact_'.md5('Transfer lock'),'infolist'))->assertNotified(ManagementForms::REQUESTED);$op=$last('lock');same(true,$op->payload['locked'],'Turn on asks for the lock');$finish($op);
$add=['name'=>'www','type'=>'A','address'=>'8.8.4.4','mx_pref'=>''];
$edit('DNS records','dns',['records'=>[['name'=>'www','type'=>'A','address'=>'not-an-ip','mx_pref'=>'']]])->assertNotified("Couldn't save this change");
$edit('DNS records','dns',['records'=>[$add]])->assertNotified(ManagementForms::REQUESTED);
$op=$last('dns');same(['www','A','8.8.4.4'],[$op->payload['records'][0]['name'],$op->payload['records'][0]['type'],$op->payload['records'][0]['address']],'Staff DNS row reaches the request');same((int)$actor->id,(int)$op->authorized_by,'DNS credited to staff');$finish($op);
$edit('DNS records','srv',['records'=>[['service'=>'sip','protocol'=>'tcp','priority'=>'1','weight'=>'2','port'=>'5060','target'=>'sip.example.com.','_source'=>'']]])->assertNotified(ManagementForms::REQUESTED);
$op=$last('srv');same('sip',$op->payload['records'][0]['service'],'Staff SRV row reaches the request');$finish($op);

// A modal keeps what it opened on, though the action rebuilds from current state at save.
$changed=Filament\Notifications\Notification::make()->danger()->title("Couldn't save this change")->body('This changed after you opened it. Reload the page and try again.');
foreach(['dns'=>[[...$add,'address'=>'8.8.8.8'],['name'=>'mail','type'=>'A','address'=>'8.8.8.8','mx_pref'=>10]],
    'srv'=>[['service'=>'sip','protocol'=>'tcp','priority'=>'1','weight'=>'3','port'=>'5060','target'=>'sip.example.com.','_source'=>''],['service'=>'xmpp','protocol'=>'tcp','target'=>'x.example.com.','priority'=>'5','weight'=>'1','port'=>'5222']]] as $kind=>[$edited,$found]){
    $page=$open('DNS records',$kind)->setActionData(['records'=>[$edited]]);$state->{$kind}[]=$found;Illuminate\Support\Facades\Cache::flush();
    same('refreshed',(new DomainObservation($readBindings,$clock,new AccountReadBudget($clock),$ports))->run($d->id),'A new '.$kind.' record is observed');$before=Operation::count();
    $page->callMountedAction()->assertNotified($changed);same($before,Operation::count(),'A stale '.$kind.' list queues nothing');
}
$stale=$open('Transfer lock','lock');$view()->callAction(TestAction::make('edit_lock')->schemaComponent('fact_'.md5('Transfer lock'),'infolist'));$op=$last('lock');same(false,$op->payload['locked'],'Turn off asks for no lock');$finish($op);
same('Turn on',DomainEditActions::for($d->fresh())['rows']['Transfer lock'][0]->getLabel(),'The page now offers the opposite step');
$before=Operation::count();$stale->callMountedAction()->assertNotified($changed);same($before,Operation::count(),'A stale Turn off queues nothing');
$stale=$open('Transfer lock','lock');$seen=$d->fresh()->observation;unset($seen['sections']['lock'],$seen['facts']['locked']);$d->fresh()->update(['observation'=>$seen]);
same('Change',DomainEditActions::for($d->fresh())['rows']['Transfer lock'][0]->getLabel(),'An unreadable lock offers a choice');
$stale->callMountedAction()->assertNotified($changed);same($before,Operation::count(),'A form opened on a known lock refuses once the lock is unreadable');
// A replayed save is one request; a new open is a new one.
$ns=['nameservers'=>"ns7.example.com\nns8.example.com"];$page=$open('Nameservers','nameservers')->setActionData($ns);$replay=clone $page;$before=Operation::count();
$page->callMountedAction()->assertNotified(ManagementForms::REQUESTED);$op=$last('nameservers');
$replay->callMountedAction();same([$before+1,$op->id],[Operation::count(),$last('nameservers')->id],'A replay shows the existing pending request');$finish($op);
$edit('Nameservers','nameservers',$ns)->assertNotified(ManagementForms::REQUESTED);expect($last('nameservers')->id!==$op->id,'A new open after a finished edit is a new request');$finish($last('nameservers'));
$registrarDns();
// Retrying a finished DNS form finds its request, though the list has moved on since.
$page=$open('DNS records','dns')->setActionData(['records'=>[[...$add,'address'=>'8.8.4.8']]]);$replay=clone $page;$before=Operation::count();
$page->callMountedAction()->assertNotified(ManagementForms::REQUESTED);$op=$last('dns');$finish($op);$state->dns[]=['name'=>'late','type'=>'A','address'=>'8.8.8.8','mx_pref'=>10];Illuminate\Support\Facades\Cache::flush();
same('refreshed',(new DomainObservation($readBindings,$clock,new AccountReadBudget($clock),$ports))->run($d->id),'The finished list is read back');
$replay->callMountedAction()->assertNotified(ManagementForms::REQUESTED);same([$before+1,$op->id],[Operation::count(),$last('dns')->id],'The finished form returns its request');

// Same refusals as the owner, and the page says what to do.
$viewer=staffUser([Staff::VIEW]);Auth::setUser($viewer);same([],DomainEditActions::for($d->fresh())['rows'],'View-only staff get no edits');Auth::setUser($actor);
$processing=static fn(bool $on)=>DB::table('domains_accounts')->where('server_id',$d->server_id)->update(['processing_enabled'=>$on]);$processing(false);
$off=DomainEditActions::for($d->fresh());same([],$off['rows'],'Processing off hides edits, as it does for the owner');expect(str_contains((string)$off['held'],'Orders are off'),'Processing off says what to do');
$view()->assertSee($off['held'])->assertSeeHtml('domains-blocked')->assertSeeHtml('aria-disabled="true"');$processing(true);
$d->update(['renewal_policy'=>[...($d->renewal_policy??[]),'automatic_spend_paused'=>true]]);
expect(str_contains((string)DomainEditActions::for($d->fresh())['held'],'orders are paused'),'Paused orders say what to do');
echo "Staff domain edits passed.\n";$nativeSuccessful=true;
