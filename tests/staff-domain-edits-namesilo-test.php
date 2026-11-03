<?php
// One staff contact save uses the same durable sequence as the owner.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/management-namesilo.php';
require __DIR__.'/Support/owner-pages.php';
require __DIR__.'/Support/staff-panel.php';
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\{Auth,Cache};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\{DomainManagement,ManagementForms,OwnedDomains,OwnerPorts};
staffPanel();storefrontViews();
[$d,$driver,$original]=$holding('contacts');$contact=$original->payload['contacts']['registrant'];
$ports=new OwnerPorts(fn($b)=>$driver);
app()->instance(OwnerPorts::class,$ports);app()->instance(OwnedDomains::class,new OwnedDomains($clock,$ports,$management));app()->instance(DomainManagement::class,$management);
$staff=staffUser([Staff::VIEW,'admin.domains.manage']);Auth::setUser($staff);
$edit=static fn(string $action,array $data=[])=>Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id])
    ->callAction(TestAction::make('edit_'.$action)->schemaComponent('fact_'.md5('Contacts'),'infolist'),$data);
$last=static fn(string $action)=>$d->operations()->where('action',$action)->orderByDesc('id')->first();

$edit('contacts',$contact)->assertNotified(ManagementForms::REQUESTED);
$op=$last('contacts');same((int)$staff->id,(int)$op->authorized_by,'One staff save owns the contact change');
$stage=$last('contact_profile');same((int)$staff->id,(int)$stage->authorized_by,'The automatic prerequisite retains the staff actor');
same(['all',$contact],[$op->payload['role'],$op->payload['contact']],'The request freezes the owner contact shape');
same('queued',$management->run($op->id,$driver)->state,'Creation finishes before association');Cache::flush();
same('succeeded',$management->run($op->id,$driver)->state,'The same queue completes the staff change');
same(['contactAdd','contactDomainAssociate'],array_column($writes(),0),'One grant per automatic step');
// A modal opened against older stored contacts cannot silently overwrite a later save.
$open=Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id])->mountAction(TestAction::make('edit_contacts')->schemaComponent('fact_'.md5('Contacts'),'infolist'));
$edit('contacts',[...$contact,'first_name'=>'Bea'])->assertNotified(ManagementForms::REQUESTED);Cache::flush();
$new=$last('contacts');$management->run($new->id,$driver);Cache::flush();same('succeeded',$management->run($new->id,$driver)->state,'Newer saved contacts complete');
$before=$d->operations()->count();$open->callMountedAction()->assertNotified(Filament\Notifications\Notification::make()->danger()->title("Couldn't save this change")->body('This changed after you opened it. Reload the page and try again.'));
same($before,$d->operations()->count(),'A stale contact modal queues no sequence');
echo "Staff automatic contacts passed.\n";$nativeSuccessful=true;
