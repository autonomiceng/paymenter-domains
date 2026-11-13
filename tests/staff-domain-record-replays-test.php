<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native staff domain page and action boundaries.
declare(strict_types=1);
require __DIR__.'/Support/staff-domain-edits.php';
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers\RequestsRelationManager;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainEditActions,DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainManagement,DomainObservation,ManagementForms,ObservationSchema,OwnedDomains,OwnerPorts};
use Paymenter\Extensions\Servers\Domains\Services\{StaffOperationReview,StaffOperations};
// Arrange the independently observed provider state left by successful record edits.
$state->ns=['dns1.name-services.com','dns2.name-services.com'];
$observation->nameservers=$state->ns; // The native domain reader has its own independent wire fixture.
$add=['name'=>'www','type'=>'A','address'=>'8.8.4.4','mx_pref'=>''];
$state->dns=[[...$add,'mx_pref'=>10]];
$state->srv=[['service'=>'sip','protocol'=>'tcp','priority'=>'1','weight'=>'2','port'=>'5060','target'=>'sip.example.com.']];
App\Models\Server::findOrFail($d->server_id)->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['value'=>'1']);
Illuminate\Support\Facades\Cache::flush();
same('refreshed',(new DomainObservation($readBindings,$clock,new AccountReadBudget($clock),$ports))->run($d->id),'Observe the independent record fixture');
$registrarDns=static function()use($edit,$last,$finish):void {
    $edit('Nameservers','nameservers',['nameservers'=>"dns1.name-services.com\ndns2.name-services.com"])->assertNotified(ManagementForms::REQUESTED);$finish($last('nameservers'));
};
// A modal keeps what it opened on, though the action rebuilds from current state at save.
$changed=Filament\Notifications\Notification::make()->danger()->title("Couldn't save this change")->body('This changed after you opened it. Reload the page and try again.');
foreach(['dns'=>[[...$add,'address'=>'8.8.8.8'],['name'=>'mail','type'=>'A','address'=>'8.8.8.8','mx_pref'=>10]],
    'srv'=>[['service'=>'sip','protocol'=>'tcp','priority'=>'1','weight'=>'3','port'=>'5060','target'=>'sip.example.com.','_source'=>''],['service'=>'xmpp','protocol'=>'tcp','target'=>'x.example.com.','priority'=>'5','weight'=>'1','port'=>'5222']]] as $kind=>[$edited,$found]){
    $page=$open('DNS records',$kind)->setActionData(['records'=>[$edited]]);$state->{$kind}[]=$found;Illuminate\Support\Facades\Cache::flush();
    same('refreshed',(new DomainObservation($readBindings,$clock,new AccountReadBudget($clock),$ports))->run($d->id),'A new '.$kind.' record is observed');$before=Operation::count();
    $page->callMountedAction()->assertNotified($changed);same($before,Operation::count(),'A stale '.$kind.' list queues nothing');
}
$stale=$open('Transfer lock','lock');$view()->callAction(TestAction::make('edit_lock')->schemaComponent('relationManagerTabs.sheet','content'));$op=$last('lock');same(false,$op->payload['locked'],'Turn off asks for no lock');$finish($op);
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

echo "Staff stale record forms and replay boundaries passed.\n";$nativeSuccessful=true;
