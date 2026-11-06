<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real owner/staff rendering and synthetic registrar transport.
declare(strict_types=1);
require __DIR__.'/Support/namesilo-dns-form.php';
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,Cache};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedDomainController,OwnerActionController};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\DnsManagement;
use Paymenter\Extensions\Servers\Domains\Data\NameserverEvidence;
use Paymenter\Extensions\Servers\Domains\Services\{DomainManagement,ObservationProjection,OwnedDomains,OwnerPorts,OwnerSetup,Refunds,TransferInputs};
// The installed adapter keeps DNS closed, and the page says so instead of hiding the row.
$setup('dns-form-off',[$a],false);
same([],$section()['actions']??null,'Dormant NameSilo DNS offers no change');
$row=$page()->query('//li[@id="dns"]')->item(0);
expect(str_contains($text($row),'Not available You can’t change DNS records here yet. Contact support for a change.'),'Owner row says DNS is not available and what to do');
same(0,$page()->query('//li[@id="dns"]//form')->length,'No records form while the capability is off');
expect(str_contains(html_entity_decode($staffPage()->html(),ENT_QUOTES|ENT_HTML5),$off),'Staff sees the same reason and next step');
// The opt-in editor needs observed default delegation, even with a complete saved inventory.
$setup('dns-form-external',[],true,['ns1.provider.example.net','ns2.provider.example.net']);
$seed=$submit($d,$driver,'dns',['records'=>[array_diff_key($a,['record_id'=>true])]]);Cache::flush();same('succeeded',$management->run($seed->id,$driver)->state,'Synthetic external-delegation inventory is saved');
same(null,$section(),'External nameservers hide the NameSilo DNS editor');
same(0,$page()->query('//li[@id="dns"]//form')->length,'External nameservers offer no DNS form');
foreach(['mixed'=>NameserverEvidence::observed(['ns1.dnsowl.com','ns2.provider.example.net'],'namesilo_domain_info',$clock->now()),
    'unconfirmed'=>NameserverEvidence::requested(['ns1.dnsowl.com','ns2.dnsowl.com','ns3.dnsowl.com'],'registration_request',$clock->now())] as $delegation=>$proof){
    $d->observation=ObservationProjection::recordNameservers($d,$proof);$d->save();
    same(null,$section(),'NameSilo DNS stays hidden on '.$delegation.' delegation');
}
$nativeSuccessful=true;echo "NameSilo DNS capability forms passed\n";
