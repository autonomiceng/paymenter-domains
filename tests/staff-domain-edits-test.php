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
$view()->assertSee('Email auth code');
require __DIR__.'/Support/staff-site-nameservers.php';
// Nameservers: the owner's validation and messages, then one credited request.
$before=Operation::count();$edit('Nameservers','nameservers',['nameservers'=>'ns1.example.com.'])->assertNotified("Couldn't save this change");
same($before,Operation::count(),'Rejected nameservers queue nothing');
$edit('Nameservers','nameservers',['nameservers'=>"NS5.example.com.\nns6.example.com"])->assertNotified(ManagementForms::REQUESTED);
$op=$last('nameservers');same('succeeded',$op->state,'Staff response termination starts and confirms the saved edit');same((int)$actor->id,(int)$op->authorized_by,'Credited to the staff member');
same(['ns5.example.com','ns6.example.com'],$op->payload['nameservers'],'Same normalization as the owner form');
// Real queued free changes must not offer purchase controls or an inapplicable status read.
// Native Filament visibility is the boundary; a pure predicate test would miss permission/action wiring.
// The saved edit already completed after the response; build separate unsent work for these checks.
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

echo "Staff nameserver, lock and contact edits passed.\n";$nativeSuccessful=true;
