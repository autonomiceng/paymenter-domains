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
use Paymenter\Extensions\Servers\Domains\Presentation\State;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainManagement,DomainObservation,ManagementForms,ManagementProgress,ObservationSchema,OwnedDomains,OwnerPorts};
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
same('Not read',DomainFacts::for($d->fresh())['DNS records']['value'],'Unread records are normal, not Missing');
ObservationSchema::install();
same('refreshed',(new DomainObservation($readBindings,$clock,new AccountReadBudget($clock),$ports))->run($d->id),'Complete empty records');
same('0 records',DomainFacts::for($d->fresh())['DNS records']['value'],'Registration card counts DNS records');
same('Turn on',DomainEditActions::for($d->fresh())['rows']['Transfer lock'][0]->getLabel(),'A known lock state offers its one next step');
$view()->callAction(TestAction::make('edit_lock')->schemaComponent('relationManagerTabs.sheet','content'))->assertNotified(ManagementForms::REQUESTED);$op=$last('lock');same(true,$op->payload['locked'],'Turn on asks for the lock');$finish($op);
$add=['name'=>'www','type'=>'A','address'=>'8.8.4.4','mx_pref'=>''];
$edit('DNS records','dns',['records'=>[['name'=>'www','type'=>'A','address'=>'not-an-ip','mx_pref'=>'']]])->assertNotified("Couldn't save this change");
$edit('DNS records','dns',['records'=>[$add]])->assertNotified(ManagementForms::REQUESTED);
$op=$last('dns');same(['www','A','8.8.4.4'],[$op->payload['records'][0]['name'],$op->payload['records'][0]['type'],$op->payload['records'][0]['address']],'Staff DNS row reaches the request');same((int)$actor->id,(int)$op->authorized_by,'DNS credited to staff');$finish($op);$dnsId=(int)$op->id;
$edit('DNS records','srv',['records'=>[['service'=>'sip','protocol'=>'tcp','priority'=>'1','weight'=>'2','port'=>'5060','target'=>'sip.example.com.','_source'=>'']]])->assertNotified(ManagementForms::REQUESTED);
$op=$last('srv');same('sip',$op->payload['records'][0]['service'],'Staff SRV row reaches the request');$finish($op);
// DNS and SRV share one zone write: each row keeps its own finished change, and a waiting one shows on both rows.
$row=static fn(string $html,string $label):string=>(string)panelRowText($html,$label);
same(['srv',false],[ManagementProgress::for($d->fresh())['dns']['section'],ManagementProgress::for($d->fresh())['dns']['pending']],'The finished SRV change is the latest on the shared DNS setting');
$html=$view()->html();
expect(str_contains($row($html,'SRV records'),'Saved.'),'A finished SRV change shows on the SRV row');
$sections=ManagementProgress::for($d->fresh())['dns']['sections']??[];
same([$dnsId,(int)$op->id],[$sections['dns']['id']??null,$sections['srv']['id']??null],'The DNS row keeps the finished DNS change, not the later SRV change');
expect(str_contains($row($html,'DNS records'),'Saved.'),'The finished DNS change still shows on the DNS row');
DB::table('domains_operations')->where('id',$op->id)->update(['state'=>'queued']);
try {
    $waiting=ManagementProgress::for($d->fresh())['dns'];same([true,'srv'],[$waiting['pending'],$waiting['section']],'The SRV change waits on the shared DNS setting');
    $html=$view()->html();
    expect(str_contains($row($html,'DNS records'),$waiting['text'])&&str_contains($row($html,'SRV records'),$waiting['text']),'A waiting SRV change shows on both record rows');
    expect(!isset(DomainEditActions::for($d->fresh())['rows']['DNS records']),'A waiting SRV change holds DNS and SRV edits');
} finally { DB::table('domains_operations')->where('id',$op->id)->update(['state'=>'succeeded']); }
// A stopped SRV change reads as failed, never as done.
$sealed=DB::table('domains_operations')->where('id',$op->id)->value('attempt');
DB::table('domains_operations')->where('id',$op->id)->update(['state'=>'rejected','attempt'=>\Illuminate\Support\Facades\Crypt::encryptString(json_encode([...$op->fresh()->attempt,'management_terminal'=>['actor_id'=>$actor->id]]))]);
try {
    $srvRow=$row($view()->html(),'SRV records');
    expect(str_contains($srvRow,State::Failed->label())&&!str_contains($srvRow,State::Completed->label()),'A stopped SRV change reads Failed, not Completed: '.$srvRow);
} finally { DB::table('domains_operations')->where('id',$op->id)->update(['state'=>'succeeded','attempt'=>$sealed]); }
same(['Edit','Edit SRV'],array_map(fn($action)=>$action->getLabel(),DomainEditActions::for($d->fresh())['rows']['DNS records']),'Both record edits return after the change finishes');

echo "Staff native DNS and SRV record edits passed.\n";$nativeSuccessful=true;
