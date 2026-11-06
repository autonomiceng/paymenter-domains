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
$edit('Transfer lock','lock',['locked'=>'0'])->assertNotified(ManagementForms::REQUESTED);$finish($last('lock'));
// Registrar record forms need observed registrar delegation, including after a nameserver edit.
$registrarDns=static function()use($edit,$last,$finish):void {
    $edit('Nameservers','nameservers',['nameservers'=>"dns1.name-services.com\ndns2.name-services.com"])->assertNotified(ManagementForms::REQUESTED);$finish($last('nameservers'));
};
$registrarDns();
// DNS and SRV: the owner's replacement list, and a row keeps its place in it.
expect(!isset(DomainFacts::for($d->fresh())['DNS records']),'No DNS row while DNS tools are off');
App\Models\Server::findOrFail($d->server_id)->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['value'=>'1']);
expect(!isset(DomainEditActions::for($d->fresh())['rows']['DNS records']),'Unread records offer no replacement form');
same('Not read yet',DomainFacts::for($d->fresh())['DNS records']['value'],'Unread records are normal, not Missing');
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

echo "Staff native DNS and SRV record edits passed.\n";$nativeSuccessful=true;
