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
// Same refusals as the owner, and the page says what to do.
$viewer=staffUser([Staff::VIEW]);Auth::setUser($viewer);same([],DomainEditActions::for($d->fresh())['rows'],'View-only staff get no edits');Auth::setUser($actor);
$processing=static fn(bool $on)=>DB::table('domains_accounts')->where('server_id',$d->server_id)->update(['processing_enabled'=>$on]);$processing(false);
$off=DomainEditActions::for($d->fresh());same([],$off['rows'],'Processing off hides edits, as it does for the owner');expect(str_contains((string)$off['held'],'Orders are off'),'Processing off says what to do');
$view()->assertSee($off['held'])->assertSeeHtml('domains-blocked')->assertSeeHtml('aria-disabled="true"');
// Email auth code is a row like the others: under Registration, with its words, and blocked with the reason when changes are on hold.
$blockedRow=$view();$blockedRow->assertSeeInOrder(['Registration','Transfer lock','Email auth code','Goes to the registrant email','Never shown here.','Email code','Registrar auto-renew','Contacts and DNS'])->assertSeeHtml('aria-label="Email code Email auth code. '.e($off['held']));
expect(in_array('Email auth code',DomainFacts::SECTIONS['Registration'],true)&&!in_array('Auth code',DomainFacts::SECTIONS['Contacts and DNS'],true),'The row sits under Registration');
$processing(true);
$view()->assertSeeInOrder(['Transfer lock','Email auth code','Never shown here.','Email code','Registrar auto-renew']);
same('Email code',DomainEditActions::for($d->fresh())['rows']['Email auth code'][0]->getLabel(),'The live button says what the mock says');
$d->update(['renewal_policy'=>[...($d->renewal_policy??[]),'automatic_spend_paused'=>true]]);
expect(str_contains((string)DomainEditActions::for($d->fresh())['held'],'orders are paused'),'Paused orders say what to do');

echo "Staff domain edit permissions and holds passed.\n";$nativeSuccessful=true;
