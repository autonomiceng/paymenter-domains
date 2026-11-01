<?php
// Staged registrars: staff send contact details for approval, then use them, as the owner does.
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
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\ManagementProfiles;
use Paymenter\Extensions\Servers\Domains\Services\{DomainManagement,ManagementForms,OwnedDomains,OwnerPorts};
staffPanel();storefrontViews();
[$d,$driver,$original]=$holding('contacts');$contact=$original->payload['contacts']['registrant'];
$ports=new OwnerPorts(fn($b)=>$driver);
app()->instance(OwnerPorts::class,$ports);app()->instance(OwnedDomains::class,new OwnedDomains($clock,$ports,$management));app()->instance(DomainManagement::class,$management);
$staff=staffUser([Staff::VIEW,'admin.domains.manage']);Auth::setUser($staff);
$edit=static fn(string $action,array $data=[])=>Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id])
    ->callAction(TestAction::make('edit_'.$action)->schemaComponent('fact_'.md5('Contacts'),'infolist'),$data);
$last=static fn(string $action)=>$d->operations()->where('action',$action)->orderByDesc('id')->first();

$edit('contact_profile',$contact)->assertNotified(ManagementForms::REQUESTED);
$stage=$last('contact_profile');same((int)$staff->id,(int)$stage->authorized_by,'Step 1 is credited to staff');same(['all',$contact],[$stage->payload['role'],$stage->payload['contact']],'Step 1 uses the owner shape');
same('succeeded',$management->run($stage->id,$driver)->state,'Step 1 runs on the same queue');
same(['role'=>'all','contact'=>$contact],ManagementProfiles::approved($d),'Step 2 is ready');
Cache::flush();
// The modal names one person, so it can't apply a newer approval at save, or an older one.
$open=Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id])->mountAction(TestAction::make('edit_contacts')->schemaComponent('fact_'.md5('Contacts'),'infolist'));
$edit('contact_profile',[...$contact,'first_name'=>'Bea'])->assertNotified(ManagementForms::REQUESTED);same('succeeded',$management->run($last('contact_profile')->id,$driver)->state,'Newer details approved');Cache::flush();
same('Bea',ManagementProfiles::approved($d)['contact']['first_name'],'The page now offers the newer details');
$before=$d->operations()->count();$open->callMountedAction()->assertNotified(Filament\Notifications\Notification::make()->danger()->title("Couldn't save this change")->body('This changed after you opened it. Reload the page and try again.'));same($before,$d->operations()->count(),'An approval that moved on queues nothing');
Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id])->mountAction(TestAction::make('edit_contacts')->schemaComponent('fact_'.md5('Contacts'),'infolist'))->callMountedAction()->assertNotified(ManagementForms::REQUESTED);
$op=$last('contacts');same((int)$staff->id,(int)$op->authorized_by,'Step 2 is credited to staff');same([...$contact,'first_name'=>'Bea'],$op->payload['contact'],'A fresh open applies the newer approval');
same('succeeded',$management->run($op->id,$driver)->state,'Step 2 runs on the same queue');
echo "Staff staged contacts passed.\n";$nativeSuccessful=true;
